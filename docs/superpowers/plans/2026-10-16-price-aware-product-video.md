# Video sản phẩm nói giá có hạn sử dụng — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Syna được nói giá bán, giá tham khảo thị trường và flash sale trong video sản phẩm. Video tự biến mất khỏi trang sản phẩm khi các giá đó không còn đúng.

**Architecture:**
- Python lấy dữ liệu giá (`pricing`) khi chụp snapshot sản phẩm và biến chúng thành cụm chữ đọc sẵn đưa vào prompt.
- Một bộ kiểm tra cứng chỉ cho phép đúng các cụm đó.
- Video lưu `price_claim`.
- Trang sản phẩm (PHP) so `price_claim` với `FlashSaleCampaign::resolveEffectivePrice()` mỗi lần dựng danh sách. Studio hiện lý do khi video bị ẩn.

**Tech Stack:** Python 3.12 (Flask, pymongo, unittest), PHP 8.2 (MongoDB driver), JavaScript thuần (node:test), FFmpeg.

**Spec:** [docs/superpowers/specs/2026-10-16-price-aware-product-video-design.md](../specs/2026-10-16-price-aware-product-video-design.md). Đọc spec trước khi làm bất kỳ task nào; mục 3.3, 4.1, 4.3 và 7 là nguồn giá trị chính xác.

## Global Constraints

- Tuân thủ `AGENTS.md`. **Trước khi sửa một symbol có sẵn**, chạy `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .` và ghi lại caller cùng mức rủi ro. Nếu kết quả là HIGH hoặc CRITICAL thì dừng và báo lại. Nếu là UNKNOWN thì xác nhận bằng `rg` trước khi sửa.
- Commit chỉ khi người dùng yêu cầu. Mặc định mỗi task **chỉ để thay đổi trong working tree** và báo lại danh sách file. Không đụng các file đang sửa dở không thuộc task (`backend/app/config/db.php`, `HomeController.php`, `LiveController.php`, `QuanTri.php`, `SanPham.php`, `CacheService.php`, `categories.php`, `tatca.php`).
- Lệnh test Python dịch vụ chính (Git Bash, chạy ở thư mục gốc repo):
  `MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W)/ai-idol-service:/app" -w /app --entrypoint python skinsyntax-ai-idol:local -m unittest <module> -v`
- Lệnh test Python agent service: tương tự, nhưng mount `ai-idol-agent-service` và dùng image `skinsyntax-ai-idol-agent:local`.
- Lệnh test PHP: `MSYS_NO_PATHCONV=1 docker exec skinsyntax-php php /var/www/html/tests/<file>.php`. Thư mục `backend/` được mount vào `/var/www/html`.
- Lệnh test JS: `node --test frontend/public/assets/js/tests/studio-logic.test.js frontend/public/assets/js/tests/studio-activity.test.js`
- Hằng số giữ đúng như spec: flash sale phải còn ít nhất `7200` giây; phần trăm giảm `round((regular - flash) / regular * 100)`; múi giờ hiển thị `Asia/Ho_Chi_Minh`; cache danh sách video `1800` giây.
- Chế độ `live_only` không đổi hành vi. Fingerprint prompt trong `tests/test_script_purpose.py` phải giữ nguyên.
- Văn bản hiển thị cho người dùng chép **nguyên văn** từ spec mục 5.3.
- Không log giá trị `.env` hoặc chuỗi kết nối.

## Review Focus

1. **Sản phẩm có `gia_ban = 0`:** `pricing` lấy `gia_thi_truong` làm `regular_price`. Nếu cả hai đều 0 thì `pricing = None` và kịch bản vẫn không được có giá. Test nằm ở Task 2 (`test_zero_prices_give_no_pricing`).
2. **LLM viết "299 nghìn" bằng chữ số hoặc dùng "ngàn" thay "nghìn":** chữ số phải FAIL; "ngàn" với đúng con số phải PASS. Test ở Task 3.
3. **Mã sản phẩm kiểu int trong `flash_sale_campaigns.items`** (ví dụ `ma_san_pham: 1024` thay vì `"1024"`): vẫn phải khớp, vì so sánh bằng `str()`. Test ở Task 2.
4. **Item trong cache cũ (trước khi deploy) không có khóa `price_valid_until_ts`:** phải được giữ lại, không được lỗi. Test ở Task 7.
5. **Kịch bản `live_and_product` có `[Price]` nhưng thiếu cả `[CTA]` lẫn `[Closing]`:** livestream vẫn chạy và clip bị chặn với `missing_price_marker`. Test ở Task 5.

---

### Task 1: Đọc số tiền tiếng Việt

**Files:**
- Create: `ai-idol-service/core/vi_money.py`
- Test: `ai-idol-service/tests/test_vi_money.py`

**Interfaces:**
- Produces:
  - `number_words(n: int) -> str`
  - `money_words(n: int) -> str`
  - `percent_words(p: int) -> str`
  - `deadline_words(end_ts: int) -> str`
  - `price_phrases(pricing: dict | None) -> dict[str, str]`, với các khóa `regular`, `market`, `flash`, `discount`, `deadline`. Khóa nào không có dữ liệu thì không có trong dict; `pricing` là None thì trả `{}`.

- [ ] **Step 1: Viết test lỗi**

```python
class NumberWordsTests(unittest.TestCase):
    def test_spec_examples(self):
        cases = {299000: "hai trăm chín mươi chín nghìn", 105000: "một trăm linh năm nghìn",
                 215000: "hai trăm mười lăm nghìn", 21000: "hai mươi mốt nghìn",
                 1250000: "một triệu hai trăm năm mươi nghìn", 1005000: "một triệu không trăm linh năm nghìn",
                 249500: "hai trăm bốn mươi chín nghìn năm trăm", 0: "không", 10: "mười", 15: "mười lăm",
                 25: "hai mươi lăm", 1000000: "một triệu"}
        for n, words in cases.items():
            self.assertEqual(vi_money.number_words(n), words, n)
    def test_money_and_percent(self):
        self.assertEqual(vi_money.money_words(249000), "hai trăm bốn mươi chín nghìn đồng")
        self.assertEqual(vi_money.percent_words(17), "giảm mười bảy phần trăm")
    def test_deadline_end_of_day(self):   # 2026-10-17 23:59 giờ VN
        self.assertEqual(vi_money.deadline_words(ts("2026-10-17T16:59:00Z")), "đến hết ngày mười bảy tháng mười")
    def test_deadline_hour_minute_and_early_day(self):
        self.assertEqual(vi_money.deadline_words(ts("2026-04-05T13:00:00Z")), "đến hai mươi giờ ngày mùng năm tháng tư")
        self.assertEqual(vi_money.deadline_words(ts("2026-10-20T02:30:00Z")), "đến chín giờ ba mươi phút ngày hai mươi tháng mười")
    def test_price_phrases(self):
        pricing = {"regular_price": 299000, "market_price": 350000,
                   "flash": {"flash_price": 249000, "discount_percent": 17, "end_ts": ts("2026-10-17T16:59:00Z")}}
        self.assertEqual(vi_money.price_phrases(pricing), {
            "regular": "hai trăm chín mươi chín nghìn đồng", "market": "ba trăm năm mươi nghìn đồng",
            "flash": "hai trăm bốn mươi chín nghìn đồng", "discount": "giảm mười bảy phần trăm",
            "deadline": "đến hết ngày mười bảy tháng mười"})
        self.assertEqual(vi_money.price_phrases({"regular_price": 299000, "market_price": None, "flash": None}),
                         {"regular": "hai trăm chín mươi chín nghìn đồng"})
        self.assertEqual(vi_money.price_phrases(None), {})
```
(`ts()` là helper trong file test, đổi chuỗi ISO sang epoch int.)

- [ ] **Step 2: Chạy test và xác nhận nó FAIL** (`-m unittest tests.test_vi_money -v` → ModuleNotFoundError)
- [ ] **Step 3: Cài đặt `core/vi_money.py`.** Đọc số theo từng nhóm ba chữ số với các đơn vị "nghìn", "triệu", "tỷ". Nhóm nào đứng sau một nhóm khác 0 và có hàng trăm bằng 0 thì đọc "không trăm". Hàng chục bằng 0 mà hàng đơn vị khác 0 thì đọc "linh". Hàng đơn vị 1 sau hàng chục ≥ 2 đọc "mốt"; hàng đơn vị 5 sau hàng chục ≥ 1 đọc "lăm". Ngày 1–10 đọc là "mùng {n}". Tháng 4 đọc là "tư". Dùng `zoneinfo.ZoneInfo("Asia/Ho_Chi_Minh")`.
- [ ] **Step 4: Chạy test và xác nhận PASS**

### Task 2: Bộ giải giá và trạng thái claim (Python)

**Files:**
- Create: `ai-idol-service/core/pricing.py`
- Modify: `ai-idol-service/engines/product_collector.py` (thêm `snapshot["pricing"]`)
- Test: `ai-idol-service/tests/test_pricing.py`

**Interfaces:**
- Consumes: không có.
- Produces:
  - `FLASH_MIN_REMAINING_SECONDS = 7200`
  - `resolve_pricing(product_id: str, product: dict, now: datetime, db=None) -> dict | None`. Trả về theo shape ở spec mục 3.1, với `end_ts` và `captured_ts` là int epoch. `db` mặc định là `core.state_machine.get_db()`.
  - `current_pricing(product_id: str, now: datetime, db=None) -> dict | None`: đọc `san_pham` theo cùng cách tìm mã như `product_collector`, rồi gọi `resolve_pricing`.
  - `claim_from_pricing(product_id: str, pricing: dict | None) -> dict | None`: trả về theo shape ở spec mục 3.2.
  - `price_claim_state(claim: dict | None, current: dict | None, now_ts: int) -> str | None`: trả `None` hoặc một trong `"flash_ended"`, `"regular_changed"`, `"market_changed"`, `"flash_changed"`, xét theo thứ tự trong bảng ở spec mục 3.3. Nếu `current` là None và claim khác None thì trả `"regular_changed"`.
- **Khác biệt so với resolver PHP, có chủ đích:** `resolve_pricing` loại flash sale còn dưới 2 giờ, vì dùng để sinh kịch bản. Khi kiểm tra trạng thái (`current_pricing`, dùng cho `price_claim_state`) thì **không** áp ngưỡng 2 giờ, để khớp với PHP. Ngưỡng điều khiển bằng tham số `min_remaining: int = FLASH_MIN_REMAINING_SECONDS` của `resolve_pricing`; `current_pricing` truyền `0`.

- [ ] **Step 1: Viết test lỗi theo bảng ở spec mục 7.** Dùng `tests.fakes.FakeDb`. Đặt campaign vào `flash_sale_campaigns` với `start_at`/`end_at` kiểu datetime UTC và `items: [{"ma_san_pham": "1024", "flash_price": 249000}]`. Danh sách test:
  - `test_case_a_no_campaign`
  - `test_case_b_active_flash`, kiểm tra `flash == {"campaign_id": 12, "campaign_name": ..., "flash_price": 249000, "discount_percent": 17, "end_ts": <17/10 16:59Z>}`
  - `test_case_c_disabled`
  - `test_case_d_under_two_hours`, kiểm tra cả `resolve_pricing(..., min_remaining=0)` vẫn có flash
  - `test_case_e_flash_not_lower`
  - `test_case_f_other_product`
  - `test_int_product_id_in_items_matches`
  - `test_zero_prices_give_no_pricing`: `gia_ban=0, gia_thi_truong=350000` → `regular_price=350000, market_price=None`; cả hai bằng 0 → `None`
  - `test_market_not_above_regular_is_none`
  - Một test cho mỗi dòng của bảng claim ở spec mục 7, gom trong `PriceClaimStateTests`
  - `test_claim_none_is_ok`
- [ ] **Step 2: Chạy test và xác nhận FAIL**
- [ ] **Step 3: Cài đặt `core/pricing.py`.** Truy vấn `flash_sale_campaigns.find({"end_at": {"$gt": now}})`, rồi lọc `enabled`, `start_at` và item trong Python. `campaign_id` lấy từ `ma_campaign` (int), `campaign_name` lấy từ `ten_campaign` hoặc `name`. Datetime không có tzinfo thì coi là UTC.
- [ ] **Step 4: Sửa `collect_product_and_snapshot`.** Thêm `snapshot["pricing"] = resolve_pricing(snapshot["id"], product, utcnow())`, bọc trong `try/except`: lỗi thì log cảnh báo (không kèm chuỗi kết nối) và đặt `None`. Thêm test `test_collector_attaches_pricing` vào cùng file, patch `get_db` bằng FakeDb.
- [ ] **Step 5: Chạy `tests.test_pricing` và xác nhận PASS.**

### Task 3: Bộ kiểm tra cứng cho lời thoại có giá

**Files:**
- Modify: `ai-idol-service/core/script_text.py` (`_PRICE_PATTERNS`, `find_price_mentions`, thêm hằng nhãn cắt)
- Modify: `ai-idol-service/engines/script_validator.py` (`check_purpose_rules`, `validate_script_via_llm`, `_revision_structure`, phần prompt revision)
- Test: `ai-idol-service/tests/test_script_sections.py` (sửa các test cũ của `find_price_mentions`), tạo mới `ai-idol-service/tests/test_price_rules.py`

**Interfaces:**
- Consumes: `price_phrases(pricing)` (Task 1).
- Produces:
  - `core.script_text.PAGE_CUT_LABELS = frozenset({"cta", "closing"})`
  - `core.script_text.find_price_violations(text: str, pricing: dict | None) -> list[str]`: danh sách thông điệp lỗi tiếng Việt, rỗng nếu hợp lệ.
  - `check_purpose_rules(script, snapshot, purpose)` giữ nguyên chữ ký và đọc `snapshot.get("pricing")`.
- `find_price_mentions` được giữ lại vì còn được import ở nơi khác. Kiểm tra bằng `rg` trước khi đổi; nếu không còn caller thì xóa.

- [ ] **Step 1: Viết test lỗi** trong `tests/test_price_rules.py`. `PRICING` là dict ca B ở spec mục 7. `script(...)` dựng kịch bản bảy phần có tiêu đề.
  - `test_exact_phrases_pass`: kịch bản chứa cả năm cụm của `price_phrases(PRICING)`, `check_purpose_rules(..., "product_video") == []`
  - `test_phrase_without_dong_and_ngan_variant_pass`: "hai trăm chín mươi chín ngàn" → `[]`
  - `test_wrong_amount_fails`: "hai trăm tám mươi nghìn đồng" → có lỗi bắt đầu bằng `"Con số giá không khớp dữ liệu"`
  - `test_digits_fail`: "299.000đ" và "299k" → lỗi
  - `test_wrong_percent_fails`: "giảm hai mươi phần trăm" → lỗi
  - `test_promo_without_flash_fails`: pricing không có flash, kịch bản có "đang ưu đãi" → lỗi chứa `"Không có chương trình ưu đãi đang chạy"`
  - `test_always_banned_words_fail`: lần lượt "hôm nay", "tối nay", "livestream", "chốt đơn", "voucher" → lỗi
  - `test_no_pricing_any_amount_fails`: `snapshot` không có khóa `pricing` mà có "hai trăm nghìn đồng" → lỗi
  - `test_live_and_product_scope_stops_at_cta`: "hôm nay chốt đơn" trong `[CTA]` → `[]`; "hôm nay" trong `[Price]` → lỗi
  - `test_live_and_product_without_cta_or_closing_is_skipped` → `[]`
- [ ] **Step 2: Chạy test và xác nhận FAIL**
- [ ] **Step 3: Cài đặt `find_price_violations`.** Làm theo spec mục 4.3, bước 1–4. Tách `_PRICE_PATTERNS` thành `_MONEY_PATTERNS` (các mẫu số tiền cùng mẫu mới `(?:\d+|<số bằng chữ>(?:\s+<số bằng chữ>)*)\s+phan tram`), `_ALWAYS_BANNED` và `_FLASH_ONLY`. Mỗi loại vi phạm chỉ trả **một** thông điệp, kèm danh sách cụm hợp lệ để vòng sửa dùng lại.
- [ ] **Step 4: Sửa `check_purpose_rules`.** Đổi phạm vi `live_and_product` thành các phần trước nhãn đầu tiên trong `PAGE_CUT_LABELS`, và gọi `find_price_violations(scope, snapshot.get("pricing"))`.
- [ ] **Step 5: Sửa prompt kiểm duyệt LLM và prompt revision.** Thêm các dòng giá theo đúng định dạng khối DATA ở spec mục 4.2, lấy từ `price_phrases`. Đổi câu cấm trong `_revision_structure` cho `product_video` và `live_and_product` thành các luật ở spec mục 4.2. `price_line` cho `product_video` không còn rỗng nữa.
- [ ] **Step 6: Sửa các test cũ** trong `test_script_sections.py` đang khẳng định "product_video không được có giá" cho khớp quy tắc mới. Chạy `tests.test_price_rules tests.test_script_sections tests.test_script_review` và xác nhận PASS.

### Task 4: Prompt sinh kịch bản (Script Council và LLM trực tiếp)

**Files:**
- Modify: `ai-idol-service/engines/content_agent.py` (`_script_council_payload`, `_product_source_lines`, `build_script_prompts`)
- Modify: `ai-idol-agent-service/app.py` (chuẩn hóa payload), `ai-idol-agent-service/script_council.py` (`writer_description`, `editor_description`)
- Test: `ai-idol-service/tests/test_script_purpose.py`, `ai-idol-agent-service/tests/test_script_council_prompts.py`

**Interfaces:**
- Consumes: `price_phrases` (Task 1), `snapshot["pricing"]` (Task 2).
- Produces:
  - `content_agent.price_lines(pricing: dict | None) -> str`: các dòng giá đúng như spec mục 4.2. Không có pricing thì trả `"Giá: không có dữ liệu, không nhắc giá."`
  - Payload Script Council có thêm `source.price_lines: str`, dùng cho `product_video` và `live_and_product`. Với hai chế độ này, `price` và `market_price` là `""`. `live_only` giữ nguyên payload cũ.
  - Agent service: `_DATA_WITH_PRICE_LINES`, có dòng `{price_lines}` thay cho hai dòng "Giá:"/"Giá thị trường:". `app.py` cắt `price_lines` còn tối đa 600 ký tự.

- [ ] **Step 1: Viết test lỗi:**
  - `test_product_video_prompt_carries_price_lines`: prompt `product_video` có dòng "Giá bán tại SkinSyntax: hai trăm chín mươi chín nghìn đồng" và "Flash sale: …"; không còn câu "Tuyệt đối không nói giá"
  - `test_product_video_without_pricing_says_no_price_data`
  - `test_live_only_fingerprint_unchanged` (test cũ, phải vẫn PASS)
  - Ở agent service: `test_writer_product_video_uses_price_lines`, `test_editor_live_and_product_scope_before_cta`, `test_payload_clips_price_lines_to_600`
- [ ] **Step 2: Chạy test và xác nhận FAIL** (cả hai service)
- [ ] **Step 3: Cài đặt.** Chép nguyên văn các luật ở spec mục 4.2 vào writer, editor và nhánh LLM trực tiếp. Đổi phần `[Wrap-up]` của `product_video` thành "tóm ba ý chính và mời bấm vào sản phẩm để xem giá mới nhất và đặt hàng". Đổi câu "Năm phần đầu … mọi nội dung về giá chỉ từ [Price] trở đi" của `live_and_product` thành: phần `[Price]` chỉ dùng các cụm giá ở trên; ưu đãi riêng của livestream chỉ nói ở `[CTA]`; các phần trước `[CTA]` không nói hôm nay, livestream, chốt đơn.
- [ ] **Step 4: Chạy lại test của cả hai service và xác nhận PASS.**

### Task 5: Render, cắt clip và lưu `price_claim`

**Files:**
- Modify: `ai-idol-service/engines/body_clip.py` (`body_cut_seconds`, `price_from_for`, `product_page_clip`)
- Modify: `ai-idol-service/engines/syna_engine.py` (dựng manifest, khoảng dòng 99–104)
- Modify: `ai-idol-service/core/pipeline.py` (`mix_snapshot` ở dòng 227, `_complete_video_job`)
- Test: `ai-idol-service/tests/test_body_clip.py`, `ai-idol-service/tests/test_syna.py`; tạo mới `ai-idol-service/tests/test_video_price_claim.py`

**Interfaces:**
- Consumes: `PAGE_CUT_LABELS` (Task 3), `claim_from_pricing` (Task 2).
- Produces:
  - `body_cut_seconds(timings, intro, labels=SALE_LABELS) -> float | None`
  - `price_from_for(purpose, timings, intro, has_claim: bool = False) -> float | None`
  - `board_price(snapshot: dict, purpose: str) -> int`: với `live_only` giữ công thức cũ `sale_price or price`. Với hai chế độ còn lại: `pricing.flash.flash_price` nếu có flash, ngược lại `pricing.regular_price`; không có pricing thì `0`.
  - `ai_idol_videos.price_claim` (dict hoặc None) do `_complete_video_job` ghi.

- [ ] **Step 1: Viết test lỗi:**
  - `test_live_and_product_clip_cuts_at_cta`: timings có price ở giây 40 và cta ở giây 55 → cắt ở `intro + 55`
  - `test_live_and_product_clip_falls_back_to_closing`
  - `test_live_and_product_without_cta_or_closing_is_blocked`: chỉ có price → `(None, "missing_price_marker")`
  - `test_price_from_product_video_with_claim_is_zero` và `..._without_claim_is_none`
  - `test_price_from_live_and_product_is_price_section_start`
  - `test_board_price_prefers_flash`, `test_board_price_live_only_unchanged`
  - `test_complete_video_job_writes_claim` (FakeDb): `product_video` có pricing ca B → `price_claim == claim_from_pricing("1024", B)` và `price_claim["valid_until_ts"] == B.flash.end_ts`; `live_only` → `None`; snapshot không có pricing → `None`
- [ ] **Step 2: Chạy test và xác nhận FAIL**
- [ ] **Step 3: Cài đặt.**
  - `product_page_clip` dùng `body_cut_seconds(..., labels=PAGE_CUT_LABELS)`.
  - `syna_engine` truyền `has_claim=bool(snapshot.get("pricing"))` và dùng `board_price`.
  - `pipeline`: chỉ xóa giá khỏi `mix_snapshot` khi `purpose == PRODUCT_VIDEO and not snapshot.get("pricing")`; khi có pricing thì đặt `price` và `sale_price` bằng `board_price`.
  - `_complete_video_job` nhận thêm tham số `snapshot` (hoặc đọc từ job) để ghi `price_claim`.
- [ ] **Step 4: Chạy `tests.test_body_clip tests.test_syna tests.test_syna_lead_in tests.test_video_price_claim tests.test_media_publisher tests.test_publications` và xác nhận PASS.**

### Task 6: Trạng thái Studio và phong cách dẫn (Python)

**Files:**
- Modify: `ai-idol-service/core/product_videos.py` (`product_page_state`)
- Modify: `ai-idol-service/core/narration.py` (bộ ba `NARRATION_STYLES` → thêm cờ thứ tư `live_only`; thêm `LIVE_ONLY_STYLES`; `normalize_styles`)
- Modify: `ai-idol-service/agents/service.py` (`_autopilot_styles`, thông điệp `STYLES_PRICE_DROPPED`)
- Modify: `ai-idol-service/core/campaigns.py:113` (chỉ sửa comment)
- Test: `ai-idol-service/tests/test_product_videos.py`, `tests/test_narration.py`, `tests/test_autopilot_service.py`

**Interfaces:**
- Consumes: `current_pricing`, `price_claim_state` (Task 2).
- Produces:
  - `product_page_state(video)` có thêm `"price_state": str | None`.
  - `LIVE_ONLY_STYLES == frozenset({"closing"})`.
  - `PRICE_STYLES` giữ tên và ý nghĩa cũ (style nói giá) cho code khác; `normalize_styles` chuyển sang lọc theo `LIVE_ONLY_STYLES`.

- [ ] **Step 1: Viết test lỗi:**
  - `test_page_state_price_state_none_without_claim`
  - `test_page_state_reports_regular_changed`: patch `current_pricing` trả `regular_price` khác claim
  - `test_page_state_pricing_lookup_error_is_none_not_crash`: `current_pricing` raise thì `price_state` là None, có log cảnh báo
  - `test_product_video_keeps_pricing_drops_closing`: `normalize_styles(["pricing", "closing"], "product_video") == (["pricing"], ["closing"])`
  - Sửa test autopilot để mong đợi thông điệp mới, chép nguyên văn từ spec mục 5.3
- [ ] **Step 2: Chạy test và xác nhận FAIL**
- [ ] **Step 3: Cài đặt.** `product_page_state` chỉ tra giá khi `video.get("price_claim")` có giá trị.
- [ ] **Step 4: Chạy `tests.test_product_videos tests.test_narration tests.test_autopilot_service tests.test_campaigns` và xác nhận PASS.**

### Task 7: Bộ lọc giá trên trang sản phẩm (PHP)

**Files:**
- Modify: `backend/app/models/ProductVideo.php` (`loadVideoEligibility`, `getPublishedVideosByProductId`, thêm hai hàm static)
- Modify: `backend/app/controllers/SanPhamController.php:587-589` (nhánh đọc từ cache)
- Test: tạo `backend/tests/product_video_price_claim_test.php`, theo cùng kiểu `check()` như `product_video_client_test.php`

**Interfaces:**
- Consumes: `FlashSaleCampaign::resolveEffectivePrice(string|int $productId, ?int $nowTimestamp = null): array` (đã có sẵn).
- Produces:
  - `public static function priceClaimHolds($claim, array $pricing, int $now): bool`. `$claim` có thể là null, array hoặc BSONDocument; chuẩn hóa bằng `(array)` hoặc `FlashSaleCampaign::bsonToArray`.
  - `public static function dropExpiredPriceClaims(array $items, int $now): array`: bỏ item có `price_valid_until_ts` khác null và `<= $now`. Item không có khóa đó thì giữ lại. Kết quả được `array_values`.
  - Mỗi item do `getPublishedVideosByProductId` trả về có thêm `price_valid_until_ts` (`?int`).

- [ ] **Step 1: Viết test lỗi:**
  - Mỗi dòng của bảng claim ở spec mục 7. `$pricing` dựng tay theo shape trả về của `resolveEffectivePrice`: `regular_price`, `market_price`, `is_flash_sale`, `campaign_id`, `effective_price`.
  - `claim null → true`
  - `BSON-like object claim is normalized`
  - `cache items without price_valid_until_ts are kept`
  - `expired item dropped, future item kept, null kept`
- [ ] **Step 2: Chạy test và xác nhận FAIL**
- [ ] **Step 3: Cài đặt.**
  - `loadVideoEligibility` thêm `price_claim` vào projection và trả `array<string, array{eligible: bool, price_valid_until_ts: ?int}>`.
  - Với mỗi `product_id` khác nhau trong các claim, gọi `resolveEffectivePrice` một lần (tạo `FlashSaleCampaign` với chính `$this->db`).
  - Nếu resolver ném lỗi: log `error_log` và **giữ** video lại (fail-open có chủ đích, để lỗi tra giá không làm mất video). Ghi lựa chọn này trong comment.
  - Controller gọi `ProductVideo::dropExpiredPriceClaims($cachedBatch[$videoKey], time())` ở nhánh cache hit.
- [ ] **Step 4: Chạy test mới, `product_video_client_test.php` và `ai_idol_studio_proxy_test.php`, xác nhận PASS. Chạy `php -l` cho hai file PHP đã sửa.**

### Task 8: Giao diện Studio

**Files:**
- Modify: `frontend/public/assets/js/studio-logic.js` (`NARRATION_STYLES` thêm `liveOnly`, `allowedStyles`)
- Modify: `frontend/public/assets/js/studio-manual.js:717-720` (`lockedStylesSentence`)
- Modify: `frontend/public/assets/js/studio-activity.js` (`segmentPageState`)
- Modify: `frontend/views/admin/ai_idol/index.php:187-188` (chữ mô tả hai chế độ)
- Test: `frontend/public/assets/js/tests/studio-logic.test.js`, `frontend/public/assets/js/tests/studio-activity.test.js`

**Interfaces:**
- Consumes: `segment.product_page.price_state` (Task 6).
- Produces: `NARRATION_STYLES[i].liveOnly: boolean`, chỉ `closing` là `true`. Cờ `price` giữ nguyên.

- [ ] **Step 1: Viết test lỗi:**
  - `allowedStyles('product_video')` có `pricing` và không có `closing`
  - `NARRATION_STYLES liveOnly flags` → `['closing']`
  - `segmentPageState` với `product_page: {eligible: true, price_state: 'regular_changed'}` và publication đã đăng → `{text: 'Giá trong video không còn đúng, video đã tự ẩn khỏi trang sản phẩm. Hãy tạo video mới.', live: false, auto: <theo publication>}`
- [ ] **Step 2: Chạy test và xác nhận FAIL**
- [ ] **Step 3: Cài đặt.** Chép nguyên văn chữ ở spec mục 5.3 cho `lockedStylesSentence` và hai mô tả trong `index.php`. Trong `segmentPageState`, xét `price_state` ngay sau nhánh `eligible === false`.
- [ ] **Step 4: Chạy lệnh test JS ở Global Constraints và xác nhận PASS. Chạy `php -l frontend/views/admin/ai_idol/index.php`.**

### Task 9: Rebuild, E2E và kiểm tra tổng

**Files:**
- Modify: `scripts/e2e/seed-product-video-fixture.py` (thêm cờ `--price-claims`)
- Modify: `scripts/e2e/product-video.e2e.cjs` (thêm hai check)

**Interfaces:**
- Consumes: mọi task trước.

- [ ] **Step 1: Chạy E2E hiện có trước khi sửa** (lệnh ở đầu `product-video.e2e.cjs`) để có baseline. Ghi lại các lỗi có sẵn từ trước.
- [ ] **Step 2: Thêm cờ `--price-claims` cho seed.**
  - Đọc `gia_ban` và `gia_thi_truong` hiện tại của sản phẩm `1000`.
  - Chèn hai `ai_idol_videos` có `purpose: "product_video"`. Video "Video mẫu · Giá còn đúng" có claim khớp giá hiện tại. Video "Video mẫu · Giá đã đổi" có `regular_price` bằng giá hiện tại cộng 1000.
  - Chèn hai bản ghi `ai_product_videos` tương ứng với `is_published: true`, `associated_product_ids: ["1000"]` và `e2e_fixture: true`, theo schema của `core/publications.py`.
  - `--cleanup` xóa cả các bản ghi `ai_product_videos` có `e2e_fixture: true`.
- [ ] **Step 3: Thêm hai check E2E** trên trang `chitiet&id=1000`: có thẻ "Giá còn đúng" và không có thẻ "Giá đã đổi". Trước khi chạy, xóa cache `product:videos:1000` bằng `docker exec skinsyntax-redis` hoặc chờ cache hết hạn.
- [ ] **Step 4: Rebuild và khởi động lại các container AI Idol** để code Python mới có hiệu lực: `docker compose build ai-idol-service ai-idol-agent-service`, rồi `docker compose up -d ai-idol-service ai-idol-worker ai-idol-agent-service`. Tên service lấy theo `docker-compose.yml`.
- [ ] **Step 5: Seed `--apply --price-claims`, chạy E2E và xác nhận PASS. Sau đó chạy `--cleanup`.**
- [ ] **Step 6: Chạy lại toàn bộ test Python** (`-m unittest discover -s tests` cho cả hai service), các test PHP đã đụng tới, test JS và `git diff --check`.
- [ ] **Step 7: Chạy `node .gitnexus/run.cjs detect-changes --scope all --repo .`.** Báo lại các file đã đổi, kết quả từng lệnh và mọi bước bị bỏ qua. Không commit nếu người dùng chưa yêu cầu.
