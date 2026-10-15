# Video AI Idol trên trang sản phẩm — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Video AI Idol tự hiện trên trang chi tiết sản phẩm (video nhỏ ở góc, mục "Video về sản phẩm", khung phóng to kiểu Shopee), điều khiển bằng 3 chế độ phát sóng chọn ở AI Idol Studio.

**Architecture:**
- Pipeline Python gắn `purpose` vào chiến dịch và job, sinh kịch bản theo chế độ, render một lần rồi cắt bỏ phần bán hàng khi cần.
- Video sẵn sàng thì cắt ảnh bìa, upload lên R2, rồi đánh dấu `product_page_ready_at`.
- PHP gọi một API JSON gọn của Flask và render partial. Trình duyệt phát video thẳng từ R2.

**Tech Stack:** Python 3.10 (Flask, Celery, pymongo, unittest, ffmpeg, boto3), CrewAI (ai-idol-agent-service), PHP 8.2 MVC, JS/CSS thuần, Playwright (Node, qua NODE_PATH).

**Spec:** `docs/superpowers/specs/2026-10-14-product-video-design.md`. Đọc spec trước. Số mục trong plan như "§4.3" trỏ về spec.

## Global Constraints

- **GitNexus trước khi sửa** (AGENTS.md):
  - Trước khi sửa mỗi hàm hoặc class có sẵn, chạy `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .`.
  - Báo callers và risk. HIGH/CRITICAL thì dừng lại hỏi người dùng. `UNKNOWN` thì xác nhận lại bằng `rg`.
- **Không commit** trừ khi người dùng yêu cầu rõ. Bước "Checkpoint" cuối mỗi task chỉ chạy `git diff --check`. Nếu người dùng cho commit thì chạy `detect-changes --scope all` trước.
- **Lệnh test Python** (Git Bash, đứng ở thư mục gốc repo). Code trong image Docker được build sẵn, nên phải mount mã nguồn vào:
  - `IDOL_TEST <module>` nghĩa là `MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W)/ai-idol-service:/app" -w /app --entrypoint python skinsyntax-ai-idol:local -m unittest <module> -v`
  - `AGENT_TEST <module>` nghĩa là `MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W)/ai-idol-agent-service:/app" -w /app --entrypoint python skinsyntax-ai-idol-agent:local -m unittest <module> -v`
  - Test theo kiểu `unittest.TestCase`, dùng `tests/fakes.py` (`FakeDb`) và `patch.object(<module>, "get_db", return_value=db)` như `tests/test_broadcast_lifecycle.py`.
- **PHP:** dùng `php -l <file>`. Test PHP là script độc lập, chạy `php <file>`, exit 0 nghĩa là PASS. App chạy ở `http://localhost:8080`, PHP được bind-mount nên sửa là có hiệu lực ngay.
- **Giá trị `purpose`:** `product_video` (mặc định), `live_and_product`, `live_only`. Tài liệu có sẵn mà thiếu `purpose` thì coi là `live_only`.
- **Độ dài kịch bản:** `product_video` dùng `length_mode = "Long (90-150s)"`, tối đa 180 giây theo giới hạn Syna.
- **Âm thanh:** `AI_IDOL_MUSIC_BED_LUFS` mặc định `-19`, `AI_IDOL_MUSIC_DUCK_RATIO` mặc định `3`, `AUDIO_PIPELINE_VERSION = "3"`.
- **R2:** biến `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_PUBLIC_BASE_URL`.
  - Không bao giờ log khóa bí mật.
  - Thiếu cấu hình thì không upload và không xóa file trên máy.
  - Header upload: `CacheControl: public, max-age=31536000, immutable`.
- **Trang sản phẩm:**
  - Gọi Flask với connect timeout 0,5 giây, tổng 1,5 giây.
  - API trả tối đa 30 video, giao diện hiện trước 10 thẻ.
  - Chỉ nhận URL bắt đầu bằng `R2_PUBLIC_BASE_URL`.
- **Chữ hiển thị** (lấy nguyên văn từ mockup và spec): "Video về sản phẩm", "Mới nhất", "Phát sóng dd/mm", "Tạo dd/mm", "Video tạo ngày dd/mm", "Sản phẩm trong video", "Đang xem", "Cùng chương trình", "Xem chi tiết", "Xem thêm", "▶ Xem video", "LIVE", "Đang phát sóng", "Ẩn khỏi trang sản phẩm", "Hiện lại trên trang sản phẩm", "6 · Chế độ phát sóng", "Mặc định", "Video tạm thời không phát được".

## Review Focus

1. **Dữ liệu cũ** (chiến dịch, job, video không có `purpose`) không bao giờ lên trang sản phẩm. Test ở Task 9.
2. **R2 chưa cấu hình hoặc upload lỗi:** video không lên trang, file trên máy còn nguyên, trang chi tiết không lỗi. Test ở Task 8 và Task 12.
3. **Kịch bản `live_and_product` thiếu dấu `[Price]`:** livestream vẫn READY, video bị chặn kèm `missing_price_marker`. Test ở Task 6.
4. **Agent nhận `purpose=product_video` nhưng prompt có câu "tạo livestream 19h":** `purpose` tường minh thắng, không tạo lịch. Ngược lại, không có `purpose` mà prompt có lịch thì dùng `live_and_product`. Test ở Task 10.
5. **Khay sản phẩm có sản phẩm đã ẩn hoặc xóa, và video bị admin ẩn:** bỏ thẻ đó, khay trống thì ẩn khay, video ẩn không xuất hiện ở đâu. Test ở Task 9 và Task 12.

---

### Task 1: Trường `purpose` cho chiến dịch và job

**Files:**
- Create: `ai-idol-service/core/purpose.py`
- Modify: `ai-idol-service/core/campaigns.py` (`create_campaign`, `prepare_campaign`), `ai-idol-service/core/state_machine.py` (`create_job`)
- Test: `ai-idol-service/tests/test_purpose.py`

**Interfaces:**
- Produces:
  - `PURPOSES: frozenset[str]`, `DEFAULT_PURPOSE = "product_video"`, `LEGACY_PURPOSE = "live_only"`
  - `normalize_purpose(value) -> str`: giá trị lạ hoặc rỗng thì trả `DEFAULT_PURPOSE`.
  - `purpose_of(doc: dict | None) -> str`: thiếu key thì trả `LEGACY_PURPOSE`.
  - `has_broadcast(purpose: str) -> bool`, `publishes_to_product_page(purpose: str) -> bool`.
  - `create_job(..., purpose: str = LEGACY_PURPOSE)` ghi `job["purpose"]`.
  - Document chiến dịch có `purpose`.

- [ ] **Step 1: Write the failing tests** trong `tests/test_purpose.py`:
  - `normalize_purpose(None) == "product_video"`, `normalize_purpose(" LIVE_ONLY ") == "live_only"`, `normalize_purpose("x") == "product_video"`.
  - `purpose_of({}) == "live_only"`, `purpose_of({"purpose": "live_and_product"}) == "live_and_product"`.
  - `has_broadcast("product_video") is False`. `publishes_to_product_page("live_only") is False`.
  - Với `FakeDb` và `patch.object(campaigns, "collect_product_and_snapshot", return_value={})`:
    - `create_campaign({"product_ids": ["1"], "purpose": "product_video"})` thành công dù không có `scheduled_at`. Document lưu `scheduled_at is None`, `platforms == []`, `purpose == "product_video"`.
    - Gọi 2 lần với cùng payload (không có idempotency_key) thì ra 2 chiến dịch khác nhau. Không được ném lỗi `.isoformat()` trên `None`.
  - `create_campaign({"product_ids": ["1"], "purpose": "live_only"})` không có lịch thì ném `ValueError("scheduled_at is required")`, giữ hành vi cũ.
  - `prepare_campaign` trên chiến dịch `product_video`: job tạo ra có `purpose == "product_video"` và `configuration["length_mode"] == "Long (90-150s)"`, trừ khi configuration đã có `length_mode`.

- [ ] **Step 2: Run to verify it fails.** Run: `IDOL_TEST tests.test_purpose`. Expected: `ModuleNotFoundError: core.purpose`.

- [ ] **Step 3: Implement** `core/purpose.py`, rồi sửa các hàm sau. Chạy impact cho `create_campaign`, `prepare_campaign`, `create_job` trước khi sửa.
  - `create_campaign`: khóa idempotency mặc định khi không có lịch là `sha256(f"{name}|{purpose}|{utcnow().isoformat()}|{ids}")`.
  - `prepare_campaign`: truyền `purpose=purpose_of(campaign)` vào `create_job`.

- [ ] **Step 4: Run to verify it passes.** Run `IDOL_TEST tests.test_purpose tests.test_campaigns tests.test_campaign_recovery`. Expected: tất cả OK.

- [ ] **Step 5: Checkpoint.** Run `git diff --check`.

### Task 2: Âm lượng nhạc nền (§4.3b)

**Files:**
- Modify: `ai-idol-service/config.py:76`, `ai-idol-service/engines/audio_mixer.py` (`build_filter_graph`, `build_mix_command`), `ai-idol-service/config.py` (`AUDIO_PIPELINE_VERSION`), `.env.example`
- Test: `ai-idol-service/tests/test_audio_mixer.py`

**Interfaces:**
- Produces:
  - `config.MUSIC_DUCK_RATIO: float`, lấy từ env `AI_IDOL_MUSIC_DUCK_RATIO`, mặc định `3`.
  - `build_filter_graph(..., duck_ratio: float = MUSIC_DUCK_RATIO)`.

- [ ] **Step 1: Write the failing tests:**
  - `"sidechaincompress=threshold=0.04:ratio=3" in build_filter_graph(10, 2, 2)`.
  - `"ratio=5" in build_filter_graph(10, 2, 2, duck_ratio=5)`.
  - `config.MUSIC_BED_LUFS == -19.0` khi env không đặt (dùng `importlib.reload` dưới `patch.dict(os.environ, {}, clear=False)` và xóa key).
  - `config.AUDIO_PIPELINE_VERSION == "3"`.
- [ ] **Step 2: Run** `IDOL_TEST tests.test_audio_mixer`. Expected: FAIL vì đang ra `ratio=6`.
- [ ] **Step 3: Implement.**
  - Đổi mặc định `MUSIC_BED_LUFS` thành `"-19"`.
  - Thêm `MUSIC_DUCK_RATIO`, đưa vào chuỗi sidechain.
  - Đổi `AUDIO_PIPELINE_VERSION` thành `"3"`.
  - Thêm `AI_IDOL_MUSIC_BED_LUFS=-19` và `AI_IDOL_MUSIC_DUCK_RATIO=3` vào `.env.example`.
- [ ] **Step 4: Run** `IDOL_TEST tests.test_audio_mixer tests.test_syna_lead_in`. Expected: OK.
- [ ] **Step 5: Checkpoint.** Run `git diff --check`.

### Task 3: Tách kịch bản theo nhãn và kiểm tra giá

**Files:**
- Modify: `ai-idol-service/core/script_text.py`
- Test: `ai-idol-service/tests/test_script_sections.py`

**Interfaces:**
- Produces:
  - `SECTION_NAMES` thêm `problem|specs?|ingredients?|wrap[- ]?up`.
  - `SALE_LABELS = frozenset({"price", "cta", "closing"})`.
  - `split_labeled_sections(script: str) -> list[tuple[str, str]]`:
    - Nhãn chuẩn gồm `opening, hook, problem, product_intro, key_benefits, specs, ingredients, how_to_use, price, cta, closing, wrap_up`, hoặc `""` nếu không có tiêu đề.
    - Nhận cả dòng tiêu đề riêng (khớp `SECTION_LINE`) và dòng chỉ chứa `[Price]`.
    - Chia đoạn quá 110 từ thì giữ nguyên nhãn cho từng khúc.
  - `split_speech_sections(script)` trả `[text for _, text in split_labeled_sections(script)]`. Đầu ra **không đổi** với kịch bản hiện tại.
  - `find_price_mentions(text: str) -> list[str]`: dùng regex trong spec §4.2, khớp cả khi bỏ dấu (dùng `normalize_text` từ `agents.intent_parser` hoặc tự bỏ dấu cục bộ bằng `unicodedata`).

- [ ] **Step 1: Write the failing tests:**
  - `split_labeled_sections("[Hook]\nA b.\n\n[Price]\nGiá tốt.")` bằng `[("hook","A b."),("price","Giá tốt.")]`.
  - `split_labeled_sections("**Key Benefits**\nX")[0][0] == "key_benefits"`.
  - Văn xuôi không tiêu đề cho nhãn `""` và text giống `split_speech_sections`.
  - Đoạn `[Ingredients]` dài 230 từ ra 3 phần tử đều có nhãn `ingredients`.
  - `find_price_mentions("chỉ 199k thôi")` khác rỗng; `find_price_mentions("199.000 đồng")` khác rỗng; `find_price_mentions("chot don ngay")` khác rỗng; `find_price_mentions("Niacinamide 10 phần trăm")` bằng `[]`.
  - Giữ nguyên test cũ trong `tests/test_subtitles.py` và `tests/test_script_review.py`.
- [ ] **Step 2: Run** `IDOL_TEST tests.test_script_sections`. Expected: ImportError.
- [ ] **Step 3: Implement.** Chạy impact `split_speech_sections` trước (nó được dùng ở voice, subtitle, syna).
- [ ] **Step 4: Run** `IDOL_TEST tests.test_script_sections tests.test_subtitles tests.test_script_review tests.test_syna`. Expected: OK.
- [ ] **Step 5: Checkpoint.**

### Task 4: Prompt và bộ kiểm tra kịch bản theo chế độ (§4.2)

**Files:**
- Modify:
  - `ai-idol-service/engines/content_agent.py`
  - `ai-idol-service/engines/script_validator.py`
  - `ai-idol-service/core/pipeline.py` (chỗ gọi tại dòng 89–92)
- Test: `ai-idol-service/tests/test_script_purpose.py`

**Interfaces:**
- Consumes: `purpose_of` (Task 1), `split_labeled_sections`, `find_price_mentions`, `SALE_LABELS` (Task 3).
- Produces:
  - `build_script_prompts(snapshot, knowledge, content_mode, config, purpose) -> tuple[str, str]`: hàm thuần, tách ra từ `generate_content_and_script`.
  - `generate_content_and_script(snapshot, knowledge, content_mode, config, purpose="live_only")`.
  - `_script_council_payload(...)` thêm `source["purpose"]`. Với `product_video` thì `price` và `market_price` là `""`, `ingredients` dài 2000 ký tự, `knowledge` dài 2200 ký tự.
  - `check_purpose_rules(script: str, snapshot: dict, purpose: str) -> list[str]`, trả danh sách lý do FAIL bằng tiếng Việt.
  - `validate_and_revise_script(..., purpose="live_only")`: chạy `check_purpose_rules` trước và sau khi LLM sửa. Còn lỗi thì trả `(False, script, "; ".join(errors))`.
  - **Spec D12:** trong `pipeline.py`, điều kiện tự duyệt kịch bản trở thành `should_auto_approve_script(config) or purpose_of(job) == "product_video"`. Ghi `approved_by = "PRODUCT_VIDEO_AUTO"`. Test: job `product_video` với `script_approval_mode = "human"` vẫn đi tiếp sang bước giọng đọc, không dừng ở `AWAITING_APPROVAL`.

- [ ] **Step 1: Write the failing tests:**
  - `product_video`: system prompt chứa đủ 7 tiêu đề dạng dòng `[Hook]`, `[Problem]`, `[Product Intro]`, `[Specs]`, `[Ingredients]`, `[How to Use]`, `[Wrap-up]`. User prompt **không** chứa `"Giá bán"` và có `"Long (90-150s)"`.
  - `live_and_product`: có 8 tiêu đề dạng `[...]`, kèm câu cấm nhắc giá, livestream, hôm nay, chốt đơn ở phần trước `[Price]`.
  - `live_only`: prompt giữ nguyên như hiện tại. So khớp với chuỗi lấy từ code trước khi sửa (snapshot test).
  - `check_purpose_rules("[Ingredients]\nNiacinamide...\n[Wrap-up]\nChỉ 199k", snap, "product_video")` có ít nhất 1 lỗi.
  - Snapshot có ingredients mà script không có nhãn `ingredients` thì trả lỗi có chữ "Ingredients".
  - `live_and_product`: con số giá nằm **sau** `[Price]` thì không lỗi, nằm trước thì có lỗi.
  - `validate_and_revise_script` với LLM giả lập (patch `llm.invoke`) trả về kịch bản còn giá thì kết quả là `is_pass False`.
- [ ] **Step 2: Run** `IDOL_TEST tests.test_script_purpose`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - Viết prompt theo đúng bảng 7 phần và quy tắc giọng điệu ở spec §4.2.
  - Biến thể validator 5/7 phần nằm trong `script_validator.py`.
  - `pipeline.py` truyền `purpose=purpose_of(job)` cho cả hai hàm.
  - Chạy impact `generate_content_and_script`, `validate_and_revise_script`, `run_ai_idol_pipeline` trước khi sửa.
- [ ] **Step 4: Run** `IDOL_TEST tests.test_script_purpose tests.test_script_review tests.test_agent`. Expected: OK.
- [ ] **Step 5: Checkpoint.**

### Task 5: Script Council (CrewAI) theo chế độ

**Files:**
- Modify: `ai-idol-agent-service/app.py` (`_normalize_payload`), `ai-idol-agent-service/script_council.py`
- Create: `ai-idol-agent-service/tests/__init__.py`, `ai-idol-agent-service/tests/test_script_council_prompts.py`

**Interfaces:**
- Produces:
  - `writer_description(purpose: str) -> str` và `editor_description(purpose: str) -> str`: hàm thuần, không import crewai.
  - `max_tokens_for(purpose: str) -> int`:
    - `product_video` dùng env `AI_IDOL_CREW_MAX_TOKENS_LONG`, mặc định 1400, kẹp trong [600, 1800].
    - Chế độ khác giữ `[300, 900]` như cũ.
  - `_normalize_payload` giữ `source["purpose"]` đã chuẩn hóa (chế độ lạ thì là `live_only`).

- [ ] **Step 1: Write the failing tests:**
  - `writer_description("product_video")` chứa `"[Ingredients]"` và `"không nói giá"`, không chứa `"Giá: {price}"`.
  - `writer_description("live_only")` chứa `"tám nhịp"`.
  - `writer_description("live_and_product")` chứa `"[Price]"`.
  - `max_tokens_for("product_video") == 1400`. `max_tokens_for("live_only") == 650`.
  - `_normalize_payload({"source": {"purpose": "zzz"}})["source"]["purpose"] == "live_only"`.
- [ ] **Step 2: Run** `AGENT_TEST tests.test_script_council_prompts`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `build_script_council` gọi 3 hàm trên.
  - Với `product_video` và `live_and_product`, bỏ câu "Không ghi tiêu đề nhịp" và thay bằng yêu cầu ghi dòng tiêu đề dạng `[Tên]`.
- [ ] **Step 4: Run** `AGENT_TEST tests.test_script_council_prompts`. Expected: OK.
- [ ] **Step 5: Checkpoint.**

### Task 6: Mốc giá trên hình, cắt khúc nội dung (§4.3)

**Files:**
- Modify:
  - `ai-idol-service/engines/voice_engine.py` (timings có thêm `label`)
  - `ai-idol-service/engines/syna_engine.py` (`build_manifest`)
  - `ai-idol-service/engines/title_cards.py` (giá `None` khi chế độ là `product_video`)
  - `ai-idol-service/core/pipeline.py` (sau `mix_program_audio`, và trong `_complete_video_job`)
  - `frontend/public/ai-idol-mascot-3d/campaign-stage.js:113-114`
  - `scripts/ai-idol-mascot/syna-render-server.cjs:41-47`
  - `scripts/ai-idol-mascot/check-3d-browser.cjs`
- Create: `ai-idol-service/engines/body_clip.py`
- Test: `ai-idol-service/tests/test_body_clip.py`, `ai-idol-service/tests/test_syna.py`

**Interfaces:**
- Consumes: `split_labeled_sections`, `SALE_LABELS` (Task 3), `purpose_of` (Task 1).
- Produces:
  - Mỗi phần tử timings dạng `{"start", "end", "text", "label"}`.
  - `body_cut_seconds(timings: list[dict], intro: float) -> float | None`: trả `intro + start` của phần tử đầu tiên có `label in SALE_LABELS`. Không có thì trả `None`.
  - `price_from_for(purpose: str, timings: list[dict], intro: float) -> float | None`:
    - `live_only` trả `0.0`.
    - `product_video` trả `None`.
    - `live_and_product` trả `body_cut_seconds(...)`. Nếu `None` thì trả `0.0`, tức livestream vẫn hiện giá suốt video.
  - `build_manifest(..., purpose="live_only")` ghi `manifest["product"]["price_from"]`.
  - `cut_body_clip(src: str, dst: str, cut_seconds: float, fade: float = 0.6) -> str`: ffmpeg re-encode, dùng `afade` và `fade` ở cuối.
  - Document `ai_idol_videos` có thêm: `purpose`, `campaign_id`, `product_page_file_path` (đường dẫn body hoặc full), `product_page_block_reason`.

- [ ] **Step 1: Write the failing tests:**
  - `body_cut_seconds([{"start":0,"label":"hook"},{"start":41.2,"label":"price"}], 2.5) == 43.7`.
  - Không có nhãn bán hàng thì trả `None`.
  - `price_from_for` cho đủ 3 chế độ, kể cả trường hợp `live_and_product` thiếu dấu giá ra `0.0`.
  - `build_manifest(..., purpose="product_video")["product"]["price_from"] is None`.
  - `cut_body_clip` với `subprocess.run` được patch: lệnh chứa `-to 43.700` và `afade=t=out`.
  - Pipeline (patch các engine): job `live_and_product` thiếu dấu giá thì kết thúc READY, video doc có `product_page_block_reason == "missing_price_marker"` và `product_page_file_path is None`.
  - Pipeline với job `product_video` thì `product_page_file_path == final_video_path`.
- [ ] **Step 2: Run** `IDOL_TEST tests.test_body_clip tests.test_syna`. Expected: FAIL.
- [ ] **Step 3: Implement Python.** Chạy impact `generate_voice_audio`, `build_manifest`, `render_syna_video`, `_complete_video_job`, `build_title_cards` trước.
- [ ] **Step 4: Implement renderer.**
  - `campaign-stage.js`: `drawCampaign` chỉ vẽ dòng giá khi `price_from !== null && time >= price_from`, ngoài ra để trống ô giá.
  - `syna-render-server.cjs`: chấp nhận `price_from` là `null` hoặc số hữu hạn trong `[0, duration]`, nếu không thì báo `Invalid Syna manifest`.
  - `check-3d-browser.cjs`: thêm kiểm tra chụp khung hình trước và sau `price_from` (theo cách test hiện có).
- [ ] **Step 5: Run.**
  - `IDOL_TEST tests.test_body_clip tests.test_syna tests.test_syna_lead_in tests.test_title_cards tests.test_subtitles`
  - `node --check scripts/ai-idol-mascot/syna-render-server.cjs`
  - Expected: OK.
  - `check-3d-browser.cjs` cần Syna renderer đang chạy, sẽ chạy ở Task 13.
- [ ] **Step 6: Checkpoint.**

### Task 7: Vòng đời chiến dịch `product_video`

**Files:**
- Modify: `ai-idol-service/core/campaigns.py`, `ai-idol-service/tasks.py` (`generate_job_task`, `prepare_campaign_task`)
- Test: `ai-idol-service/tests/test_campaigns.py`

**Interfaces:**
- Consumes: `purpose_of`, `has_broadcast` (Task 1).
- Produces:
  - `mark_product_videos_ready(campaign_id: str) -> bool`: chuyển trạng thái có điều kiện từ `GENERATING` sang `VIDEOS_READY`.
  - Thêm `"VIDEOS_READY"` vào danh sách trạng thái kết thúc mà UI và watchdog hiểu. Rà các chỗ liệt kê trạng thái bằng `rg "PARTIAL_FAILURE" ai-idol-service frontend/public/assets/js/ai-idol-studio.js`.
  - Trong `generate_job_task`: khi `update_campaign_from_job` trả về `campaign_id`, nếu `not has_broadcast(purpose)` thì gọi `mark_product_videos_ready`, còn lại giữ luồng `claim_campaign_for_assembly` như cũ. `prepare_campaign_task` (nhánh `resume_ready_campaign`) xử lý tương tự.

- [ ] **Step 1: Write the failing tests:**
  - Chiến dịch `product_video` có mọi segment READY thì `status == "VIDEOS_READY"` và không bao giờ gọi `assemble_campaign_task` (patch, assert không được gọi).
  - Chiến dịch `live_and_product` vẫn đi qua assembly.
  - Chiến dịch không có `purpose` vẫn đi qua assembly.
- [ ] **Step 2: Run** `IDOL_TEST tests.test_campaigns`. Expected: FAIL.
- [ ] **Step 3: Implement.** Chạy impact `generate_job_task`, `update_campaign_from_job` trước.
- [ ] **Step 4: Run** `IDOL_TEST tests.test_campaigns tests.test_campaign_recovery tests.test_broadcast_lifecycle tests.test_e2e_flow`. Expected: OK.
- [ ] **Step 5: Checkpoint.**

### Task 8: Ảnh bìa, upload R2, xóa file trên máy (§4.4)

**Files:**
- Create: `ai-idol-service/engines/media_publisher.py`
- Modify:
  - `ai-idol-service/config.py` (5 biến `R2_*`)
  - `ai-idol-service/requirements.txt` (`boto3>=1.34,<2`)
  - `ai-idol-service/tasks.py`, `ai-idol-service/celery_app.py` (beat)
  - `ai-idol-service/engines/broadcast_engine.py:140`, `:161`
  - `.env.example`
- Test: `ai-idol-service/tests/test_media_publisher.py`

**Interfaces:**
- Consumes: `product_page_file_path`, `purpose`, `campaign_id` trên video doc (Task 6). `mark_product_videos_ready` (Task 7).
- Produces:
  - `r2_configured() -> bool`
  - `make_poster(video_path: str, out_path: str, duration: float) -> str`: ffmpeg lấy khung hình ở giây `min(1.5, duration/3)`, cạnh dài 640 px, `-q:v 4`.
  - `publish_video(video_id: str, *, client=None) -> bool`:
    - Idempotent: đã có `product_page_ready_at` thì trả `True` luôn.
    - Key: `product-videos/<video_id>-<secrets.token_hex(6)>.mp4` và `.jpg`.
    - Đặt `media.video_url = R2_PUBLIC_BASE_URL.rstrip('/') + '/' + key`.
    - Thành công: `$set` `media.*`, `product_page_ready_at`, `aired_at`, `campaign_name`.
    - Lỗi: `$inc media.attempts`, `$set media.last_error` (đã redact bằng `core.redact.redact_secrets`), giữ file.
  - `cleanup_local_files(video_id: str) -> None`: xóa body và poster. Với `product_video` xóa luôn clip đầy đủ. Không bao giờ xóa khi chưa có `product_page_ready_at`.
  - `campaign_publishable(campaign: dict, broadcasts: list[dict]) -> bool`: `COMPLETED`, hoặc `PARTIAL_FAILURE` mà có ít nhất 1 broadcast `COMPLETED`.
  - Celery tasks:
    - `ai_idol.publish_product_video(video_id)`
    - `ai_idol.publish_campaign_videos(campaign_id)`: publish mọi video có `product_page_file_path` của chiến dịch, rồi xóa clip đầy đủ và video tổng.
    - `ai_idol.retry_product_video_publish` (beat 600 giây, `media.attempts < 5`, chưa có `product_page_ready_at`, có `product_page_file_path`).
  - Điểm gọi:
    - `generate_job_task`: job thành công và `purpose_of(job) == "product_video"` thì `publish_product_video.delay(video_id)`.
    - `broadcast_engine`: cả 2 chỗ chuyển trạng thái gọi `celery_app.send_task("ai_idol.publish_campaign_videos", args=[campaign_id])` khi `has_broadcast` và `publishes_to_product_page`. Dùng `send_task` để tránh import vòng.

- [ ] **Step 1: Write the failing tests** (FakeDb, client S3 giả ghi lại các lần `upload_file`, `patch` env `R2_*`):
  - R2 chưa cấu hình: `publish_video` trả `False`, không gọi client, file còn nguyên, chỉ ghi log cảnh báo một lần.
  - Thành công: video doc có `media.video_url` bắt đầu bằng `R2_PUBLIC_BASE_URL` và `product_page_ready_at`; client nhận `ContentType "video/mp4"` và `CacheControl` đúng spec; gọi lần 2 không upload lại.
  - Client ném lỗi: `media.attempts == 1`, `last_error` không chứa giá trị secret, file còn nguyên.
  - `campaign_publishable` đúng ở 3 trường hợp: COMPLETED; PARTIAL có 1 broadcast COMPLETED; PARTIAL mà mọi broadcast FAILED.
  - `cleanup_local_files` không xóa gì khi thiếu `product_page_ready_at`.
  - `finalize_live_campaigns` (broadcast_engine) gửi `send_task` cho chiến dịch `live_and_product` vừa COMPLETED, không gửi cho `live_only`.
  - **Backfill một lần (spec D13):** `python -m engines.media_publisher backfill [--apply]`.
    - Chọn mọi `ai_idol_videos` có `file_path` còn tồn tại và chưa có `product_page_ready_at`, bất kể `purpose`.
    - Đặt `product_page_backfill: true`, `product_page_file_path = file_path`, rồi `publish_video`.
    - Không có `--apply` thì chỉ in danh sách (dry-run).
    - Task 9 bổ sung: `list_product_videos` nhận thêm `{"product_page_backfill": True}` qua `$or` với điều kiện `purpose`.
  - Test thêm:
    - backfill dry-run không ghi gì.
    - `--apply` đánh dấu và publish video cũ có file, bỏ qua video mất file.
    - `list_product_videos` trả về video có `product_page_backfill` dù không có `purpose`.
- [ ] **Step 2: Run** `IDOL_TEST tests.test_media_publisher`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `boto3` import lười trong hàm `_default_client()`, để test chạy được trên image cũ chưa có boto3.
  - Chạy impact `broadcast_campaign`, `finalize_live_campaigns`, `generate_job_task` trước.
- [ ] **Step 4: Run** `IDOL_TEST tests.test_media_publisher tests.test_broadcast tests.test_broadcast_lifecycle`. Expected: OK.
- [ ] **Step 5: Checkpoint.**

### Task 9: API danh sách video và ẩn/hiện (§4.6)

**Files:**
- Create: `ai-idol-service/core/product_videos.py`
- Modify:
  - `ai-idol-service/app.py` (2 route mới)
  - `ai-idol-service/core/state_machine.py` (`ensure_indexes`)
  - `ai-idol-service/core/campaigns.py` (`get_campaign` thêm `product_page` cho từng segment)
- Test: `ai-idol-service/tests/test_product_videos.py`

**Interfaces:**
- Produces:
  - `list_product_videos(product_id: str, limit: int = 30) -> dict`, trả `{"live": {...}|None, "videos": [...]}` đúng shape ở spec §4.6.
    - `product_ids` = sản phẩm của clip trước, rồi tối đa 3 sản phẩm khác cùng chiến dịch.
    - `date` = `aired_at` hoặc `created_at`. `date_kind` = `"aired"` hoặc `"created"`.
  - `set_video_visibility(video_id: str, hidden: bool, by: str) -> dict`: không tồn tại thì ném `LookupError`.
  - Route `GET /api/ai-idol/products/<product_id>/videos?limit=` trả `{"ok": true, "data": {...}}`.
  - Route `POST /api/ai-idol/videos/<video_id>/visibility` nhận body `{"hidden": bool, "by": str}` và trả `{"ok": true, "data": {...}}`. Trả 404 khi `LookupError`.
  - Index: `ai_idol_videos [("product_id",1),("product_page_ready_at",-1)]`, partial `{"product_page_ready_at": {"$exists": True}}`.
  - `get_campaign` thêm cho mỗi segment: `product_page: {video_id, ready, hidden, block_reason, last_error}`.

- [ ] **Step 1: Write the failing tests:**
  - Seed 4 video cho product `"1024"`: (a) ready + product_video, (b) ready + live_and_product có `aired_at`, (c) hidden, (d) không có `product_page_ready_at`. Thêm 1 video của sản phẩm khác.
  - Kết quả chỉ có b và a, theo thứ tự `product_page_ready_at` giảm dần. `date_kind` của b là `"aired"`.
  - Video cũ không có `purpose` dù có `product_page_ready_at` vẫn bị loại.
  - `live` khác `None` khi có chiến dịch `status:"LIVE"` chứa `"1024"` với `purpose` thuộc `live_*`. Chiến dịch `LIVE` không có `purpose` (dữ liệu cũ, coi là `live_only`) cũng tính.
  - `limit` lớn hơn 30 thì bị kẹp về 30.
  - `set_video_visibility(id, True, "admin")` làm video biến mất khỏi danh sách. Gọi `False` thì video xuất hiện lại.
- [ ] **Step 2: Run** `IDOL_TEST tests.test_product_videos`. Expected: FAIL.
- [ ] **Step 3: Implement.** Query lọc thêm `purpose ∈ {product_video, live_and_product}` để chặn dữ liệu cũ. Chạy impact `get_campaign`, `ensure_indexes` trước.
- [ ] **Step 4: Run** `IDOL_TEST tests.test_product_videos tests.test_campaigns`. Expected: OK.
- [ ] **Step 5: Checkpoint.**

### Task 10: Agent nhận và suy ra `purpose`

**Files:**
- Modify:
  - `ai-idol-service/agents/schemas.py`
  - `ai-idol-service/agents/intent_parser.py` (`fallback_parse`, `parse_agent_brief`)
  - `ai-idol-service/agents/service.py` (đoạn dòng 300–400, `confirm_agent_run`)
  - `ai-idol-service/core/agent_runs.py:97-104`
- Test: `ai-idol-service/tests/test_agent.py`

**Interfaces:**
- Consumes: `normalize_purpose`, `has_broadcast` (Task 1).
- Produces:
  - `AgentBrief.purpose: str | None = None`. JSON schema: `"purpose": {"type": ["string","null"], "enum": [...3 giá trị, null]}`, có trong `required`.
  - `infer_purpose(normalized_text: str, scheduled_at: str | None) -> str`: có `scheduled_at` hoặc từ `"livestream"`/`"live"` trong text thì trả `live_and_product`, ngược lại `product_video`.
  - `overrides["purpose"]` lấy từ payload tạo run. Override tường minh thắng kết quả LLM và kết quả suy ra.
  - Khi `purpose == "product_video"`:
    - Service bỏ qua `_safe_schedule`, cổng RTMP và nhánh SCHEDULE_REVIEW.
    - `campaign_payload` có `purpose`, không có `scheduled_at`, có `configuration.length_mode = "Long (90-150s)"`.
  - Chế độ khác giữ nguyên luồng cũ, cộng thêm `purpose` trong payload.
  - `confirm_agent_run` nhận thêm key `purpose`.

- [ ] **Step 1: Write the failing tests:**
  - `fallback_parse("Lọc 5 serum bán chạy").purpose` (sau khi suy ra) bằng `"product_video"`.
  - `fallback_parse("Lọc 5 serum, tạo livestream lúc 19h tối mai")` ra `"live_and_product"`.
  - Run có `overrides.purpose="product_video"` và goal chứa "tạo livestream 19h": chiến dịch tạo ra có `purpose == "product_video"` và `scheduled_at is None`. Patch `create_campaign` để bắt payload.
  - Run không có `purpose` và có lịch: payload có `purpose == "live_and_product"` và `scheduled_at` được giữ.
- [ ] **Step 2: Run** `IDOL_TEST tests.test_agent`. Expected: FAIL.
- [ ] **Step 3: Implement.** Chạy impact `AgentBrief.from_mapping`, `fallback_parse`, `parse_agent_brief`, `process_agent_run` (hàm chứa dòng 300–400), `confirm_agent_run` trước. Báo risk.
- [ ] **Step 4: Run** `IDOL_TEST tests.test_agent tests.test_intent_classifier_regression tests.test_prompt_builder_cases tests.test_command_query_service`. Expected: OK.
- [ ] **Step 5: Checkpoint.**

### Task 11: AI Idol Studio — mục 6 và nút ẩn (§7)

**Files:**
- Modify:
  - `frontend/views/admin/ai_idol/index.php:141-149` (và bước 01 của wizard thủ công)
  - `frontend/public/assets/js/ai-idol-studio.js:150-205`, `:568-580` (submit agent), phần render chi tiết chiến dịch
  - `frontend/public/assets/css/ai-idol-studio.css`
  - `backend/app/controllers/AdminAiIdolController.php` (action mới `videoVisibility`)
  - `backend/app/routes/ai_idol.php`, `backend/public/index.php:63`

**Interfaces:**
- Consumes: route Flask `POST /api/ai-idol/videos/<id>/visibility`, `get_campaign(...).segments[].product_page` (Task 9). Payload agent có `purpose` (Task 10).
- Produces:
  - Route PHP `admin_ai_idol_video_visibility` (POST, body `{video_id, hidden}`), gọi `callFlaskAPI('POST', "/api/ai-idol/videos/{id}/visibility", ['hidden'=>…, 'by'=>…])`. `video_id` phải khớp `/^[a-f0-9]{24}$/`, sai thì trả 400.
  - Radio `name="agentPurpose"` với các giá trị `product_video|live_and_product|live_only`, mặc định checked `product_video`.
  - JS hàm `selectedAgentPurpose(): string`.

- [ ] **Step 1: Implement view.** Thay fieldset `agent-live-group` bằng "6 · Chế độ phát sóng" gồm 3 thẻ radio, giữ nguyên `agentLiveFields` bên trong. Chữ hiển thị lấy nguyên văn từ mockup. Wizard thủ công thêm cùng nhóm radio, `name="manualPurpose"`.
- [ ] **Step 2: Implement JS.**
  - `buildAgentPrompt` dùng câu ghép theo chế độ (spec §7). `agentLiveFields.hidden` khi chọn `product_video`.
  - Chọn `product_video` thì disable và bỏ chọn 2 checkbox `agentStyle` có value "tập trung chốt sale" và "nhấn mạnh giá và ưu đãi", hiện ghi chú.
  - Submit agent thêm `purpose: selectedAgentPurpose()`. Submit wizard thêm `purpose` và chỉ gửi `scheduled_at` khi có livestream.
  - Chi tiết chiến dịch hiện "Đang hiện trên N trang sản phẩm", nút ẩn/hiện, và lý do chặn.
  - Chạy impact `buildAgentPrompt` trước (dùng `rg` vì GitNexus có thể không phân giải JS; ghi nhận `UNKNOWN` nếu vậy).
- [ ] **Step 3: Implement PHP.** Thêm route vào **cả hai** whitelist. Action gọi `checkAdmin()` giống các action khác. Chạy impact `AdminAiIdolController` trước.
- [ ] **Step 4: Verify.**
  - Run `php -l backend/app/controllers/AdminAiIdolController.php backend/app/routes/ai_idol.php backend/public/index.php frontend/views/admin/ai_idol/index.php` (từng file). Expected: "No syntax errors detected".
  - Run `node --check frontend/public/assets/js/ai-idol-studio.js`. Expected: không có lỗi.
- [ ] **Step 5: Checkpoint.**

### Task 12: Trang chi tiết sản phẩm (§5.1, §6)

**Files:**
- Create:
  - `backend/app/services/ProductVideoClient.php`
  - `frontend/views/partials/product_video.php`
  - `frontend/public/assets/css/product-video.css`
  - `frontend/public/assets/js/product-video.js`
  - `backend/tests/product_video_client_test.php`
- Modify:
  - `backend/app/controllers/SanPhamController.php` (`chitiet`, khoảng dòng 398–515)
  - `frontend/views/chitiet.php` (1 dòng include giữa khối `row g-4 mb-5` (dòng 535) và khối tab `#detailTabNav` (dòng 804))
  - `.env.example`

**Interfaces:**
- Consumes: `GET /api/ai-idol/products/<id>/videos` (Task 9).
- Produces:
  - `final class ProductVideoClient`:
    - `__construct(string $endpoint, string $publicBase, ?callable $transport = null)`. `$transport(string $url): ?string` dùng để test; mặc định là cURL với timeout theo Global Constraints.
    - `forProduct(string $productId): array` trả `['live' => ?array, 'videos' => array]`.
    - Lỗi hoặc JSON sai thì trả rỗng và gọi `error_log` một dòng (không có URL kèm query).
    - Lọc bỏ video có `video_url` hoặc `poster_url` không bắt đầu bằng `$publicBase`.
  - `SanPhamController::chitiet` truyền `'productVideos' => [...]`. Mỗi video có thêm `products`: danh sách `['id','name','price','image','current'=>bool]` lấy từ `SanPham::getProductBriefById`, đã bỏ sản phẩm không tồn tại hoặc bị ẩn.
  - Partial nhúng `<script type="application/json" id="productVideoData">` bằng `json_encode` với cờ HEX ghi trong spec.

- [ ] **Step 1: Write the failing PHP test** `backend/tests/product_video_client_test.php`:
  - transport trả `null` thì kết quả là `['live'=>null,'videos'=>[]]`.
  - transport trả chuỗi `"{bad"` thì kết quả cũng rỗng.
  - JSON hợp lệ có 2 video, 1 video có `video_url` thuộc host lạ: chỉ còn 1 video.
  - `live` được giữ nguyên.
  - Mỗi assert sai thì `exit(1)`.
- [ ] **Step 2: Run** `php backend/tests/product_video_client_test.php`. Expected: lỗi class not found.
- [ ] **Step 3: Implement `ProductVideoClient`.** Endpoint lấy từ `getenv('AI_IDOL_ENDPOINT') ?: 'http://ai-idol-service:5003'`. Public base lấy từ `getenv('R2_PUBLIC_BASE_URL')`; rỗng thì mọi video bị loại.
- [ ] **Step 4: Run** `php backend/tests/product_video_client_test.php`. Expected: in ra "PASS", exit 0.
- [ ] **Step 5: Wire controller và view.** Chạy impact `SanPhamController::chitiet` trước. Bọc lời gọi trong `try/catch (Throwable)`. Partial chỉ được require khi `videos` hoặc `live` khác rỗng.
- [ ] **Step 6: Implement partial, CSS, JS** theo spec §6.1–6.3 và mockup bản 2.
  - CSS dùng token có sẵn trong `style.css` (`--brand`, `--surface`, `--border`…).
  - JS là IIFE không phụ thuộc thư viện. `sessionStorage` bọc try/catch. Dùng Pointer Events cho thao tác vuốt.
  - Video 16:9 dùng class `is-landscape`.
  - Hàng thẻ dùng ảnh bìa `loading="lazy"`.
  - Chỉ tải trước video kế tiếp.
- [ ] **Step 7: Verify.**
  - Run `php -l` trên `SanPhamController.php`, `ProductVideoClient.php`, `product_video.php`, `chitiet.php`. Expected: không có lỗi.
  - Run `node --check frontend/public/assets/js/product-video.js`. Expected: không có lỗi.
  - Mở `http://localhost:8080/index.php?r=chitiet&id=<id không có video>`: HTML không chứa `productVideoData` và không có `product-video.css`.
- [ ] **Step 8: Checkpoint.**

### Task 13: Build lại, chạy toàn bộ test, E2E, nghe thử

**Files:**
- Create: `scripts/e2e/product-video.e2e.cjs`, `scripts/e2e/seed-product-video-fixture.py`
- Modify: `.gitignore` (thêm `frontend/public/e2e-media/`)

- [ ] **Step 1: Chạy toàn bộ test Python** `IDOL_TEST discover -s tests` và `AGENT_TEST discover -s tests`. Expected: OK, không có test lỗi mới. Test lỗi từ trước thì ghi lại riêng, kèm output.
- [ ] **Step 2: Build lại image.** **Hỏi người dùng trước**, vì lệnh này khởi động lại các service đang chạy:
  `docker compose build ai-idol-service ai-idol-control-worker ai-idol-agent-service && docker compose up -d`. Expected: các container healthy.
- [ ] **Step 3: Seed dữ liệu E2E.** **Hỏi người dùng trước**, vì Mongo có thể là Atlas thật.
  - `seed-product-video-fixture.py --product <id> --apply`:
    - Dùng ffmpeg tạo 3 file mp4 mẫu (2 khung dọc, 1 khung ngang) và ảnh bìa vào `frontend/public/e2e-media/`.
    - Ghi các video doc có `e2e_fixture: true`, `purpose`, `product_page_ready_at`.
    - Đặt `R2_PUBLIC_BASE_URL=http://localhost:8080/e2e-media` cho lần chạy (tạm sửa `.env`, chạy xong khôi phục).
  - `--cleanup` xóa đúng các doc có `e2e_fixture: true`.
- [ ] **Step 4: Chạy E2E.** Run `NODE_PATH="$USERPROFILE/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules" node scripts/e2e/product-video.e2e.cjs`. Script kiểm tra, ở viewport 1440×1000 và 390×844:
  - Có video thì có video nhỏ ở góc và mục "Video về sản phẩm" với 3 thẻ, thẻ đầu có "Mới nhất".
  - Bấm thẻ thì mở dialog, bộ đếm là "1/3". Phím ↓ thì thành "2/3". Esc thì dialog đóng và focus trở lại.
  - Thẻ sản phẩm khác dẫn tới `r=chitiet&id=`.
  - Video ngang có class `is-landscape`.
  - Sản phẩm không có video thì không có `#productVideoData`.
  - Seed một chiến dịch `LIVE` thì video nhỏ có nhãn "LIVE" và link `r=live`.
  - Studio (đăng nhập bằng tài khoản admin test có sẵn trong seed hoặc fixture của dự án, không tạo tài khoản mới): radio mặc định là `product_video`, ô lịch bị ẩn; chọn `live_and_product` thì ô lịch hiện ra; 2 phong cách nói giá bị disable khi chọn `product_video`.
  - Expected: mọi dòng `PASS`, exit 0. Sau đó chạy `--cleanup`.
- [ ] **Step 5: Kiểm tra renderer.** Chạy `scripts/start-ai-idol-syna.ps1`, rồi `node scripts/ai-idol-mascot/check-3d-browser.cjs`. Expected: PASS, bao gồm kiểm tra `price_from`.
- [ ] **Step 6: Nghe thử.** Tạo 1 chiến dịch `product_video` thật trong Studio, nhờ người dùng nghe video và xác nhận âm lượng nhạc nền. Không tự kết luận.
- [ ] **Step 7: Báo cáo.** Chạy `git diff --check` và `node .gitnexus/run.cjs detect-changes --scope all --repo .`. Báo cáo cho người dùng: file đã đổi, lệnh đã chạy và kết quả, các kiểm tra bị bỏ qua, rủi ro còn lại.
