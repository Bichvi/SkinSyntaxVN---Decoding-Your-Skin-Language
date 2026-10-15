# AI Idol: Hoàn thiện livestream tự động end-to-end

Ngày: 13/10/2026. Người lập: Opus (planner). Người code: Sonnet (coder).
Phạm vi: AI Idol Studio. Không đổi chatbot, gợi ý sản phẩm hay voice chat.

Trước khi làm, cần biết hai điều:
- Container có thể đang chạy code cũ. Theo `docs/plans/2026-09-27-ai-idol-command-center.md` (dòng 110–120), image chưa được build lại. Vì vậy cần chẩn đoán trước (T0).
- `agents/service.py`, `agents/product_resolver.py` và `ai-idol-studio.js` đang dùng CRLF và có thay đổi chưa commit. Phải giữ nguyên EOL; không reformat cả file.

## 1. Luồng hiện tại

UI → `admin_ai_idol_agent_create` → `POST /api/ai-idol/agent/runs` → `process_agent_run_task` → `process_agent_run`. Các bước: `classify_intent` → nhánh truy vấn nghiệp vụ (nếu có) → `parse_agent_brief` (LLM + `fallback_parse`) → kiểm tra lịch → `resolve_products` → cổng chính sách → `_safe_schedule` → `create_campaign` (`agent:{run_id}`) → `prepare_campaign_task`.

Mỗi segment một job. `generate_job_task` → `run_ai_idol_pipeline`: collect → RAG → Council → validator → tự duyệt hoặc `AWAITING_APPROVAL` → TTS → Syna :7862 → `READY`. Tiếp theo `update_campaign_from_job` → `claim_campaign_for_assembly` → `assemble_campaign_program` → `SCHEDULED`. Beat dispatch (5s) chuyển `STARTING` → `broadcast_campaign_task` → `LIVE` → `COMPLETED` hoặc `PARTIAL_FAILURE`.

## 2. Lỗi

### Nhận diện sản phẩm (triệu chứng trong ảnh chụp)

- **D1.** `explicit_product_ids` do LLM trả không được làm sạch (ví dụ "mã 93"). Kết quả regex xác định chỉ được dùng cho lịch. Vị trí: `intent_parser.py:137`, `service.py:136,145-147`, `product_resolver.py:192-205`.
- **D2.** Resolver chỉ tra theo `ma_san_pham`. Catalog không lấy `id`, và giá trị `93.0` không khớp. Vị trí: `product_resolver.py:47`, `query_sources.py:13-20`.
- **D3.** Có mã tường minh nhưng không khớp thì resolver vẫn chọn fuzzy. Stopword ("ma", "an", "toan") đẩy độ khớp lên 0,98, nên chọn nhầm sản phẩm mã 745. Vị trí: `product_resolver.py:151-153, 245-259`.
- **D4.** Ngõ cụt trên UI: khi đã có `selected` thì `ambiguous = []`, nên không có nút xác nhận, không có ô nhập mã hay lịch, và không có retry ở `NEEDS_INPUT`. Vị trí: `ai-idol-studio.js:433-440`.
- **D5.** `confirm_agent_run` không kiểm tra trạng thái và không chuyển nguyên tử. Nhánh truy vấn nghiệp vụ bỏ qua `overrides.explicit_product_ids`. Vị trí: `service.py:285-303, 86-109`.
- **D6.** RTMP không được kiểm tra khi lập kế hoạch. `ValueError` từ `create_campaign` bị retry như lỗi hạ tầng.

### Trạng thái và độ bền

- **D7.** Một worker concurrency=1 phục vụ mọi queue, nên beat dồn ứ và agent bị chặn khi đang render.
- **D8.** Hết retry thì job treo mãi ở `IN_PROGRESS`. Không có watchdog.
- **D9.** `prepare_campaign` chạy lại cả job đã `READY`.
- **D10.** Campaign `NEEDS_ATTENTION` do ghép video hoặc preflight lỗi thì không retry được.
- **D11.** Kịch bản bị validator từ chối thì mất, không sửa và duyệt được.
- **D12.** Endpoint approve không chạy `check_blacklist`.
- **D13.** Syna tắt thì job `FAILED` ngay. Admin không thấy health của dịch vụ trước khi chạy.

### Lịch phát, phát sóng và quan sát

- **D14.** Không có cận trễ, không hủy, không đổi lịch.
- **D15.** Kênh internal `COMPLETED` ngay lập tức, không có phát nội bộ thật.
- **D16.** stderr của FFmpeg có thể chứa stream key và được lưu vào Mongo.
- **D17.** Panel Agent thiếu lịch sử các bước, tiến độ từng segment và trạng thái broadcast/preflight.
- **D18.** Thiếu test cho các luồng trên.

## 3. Task

Lệnh test: `docker compose run --rm --no-deps -v "${PWD}/ai-idol-service:/app" ai-idol-worker python -m unittest discover -s tests -v`. Test viết theo kiểu FakeDb/RecordingCollection như `tests/test_script_review.py`. Không thêm mongomock.

### T0. Build lại và chẩn đoán mã 93 (chỉ đọc)
- Build lại image.
- Tra `san_pham` theo `ma_san_pham` và `id` (cả chuỗi lẫn số).
- In `normalized_brief.explicit_product_ids` và `plan.unresolved_ids` của run mới nhất.

### T1. Làm sạch và hợp nhất mã sản phẩm (D1)
- **File:** `agents/intent_parser.py`, `agents/service.py`.
- **Thêm `clean_product_id(raw) -> str | None`:**
  - chạy `normalize_text`;
  - bỏ tiền tố `ma|id|sp|san pham|ma san pham|#|:|-`;
  - lấy token `[a-z0-9]{1,40}` đầu tiên;
  - `"93.0"` → `"93"`;
  - trả `None` nếu rỗng hoặc là stopword.
  - Dùng trong `_extract_explicit_ids` và `AgentBrief.from_mapping`.
- **Trong `process_agent_run`:**
  - `ids = overrides.explicit_product_ids`, nếu không có thì `deterministic.explicit_product_ids`;
  - cộng thêm các id do LLM trả mà token đã làm sạch có xuất hiện trong `normalize_text(brief)`;
  - id LLM bịa ra bị loại.
- **Test:** "mã 93" → "93"; "555" không có trong câu thì bị loại; "93.0" → "93".

### T2. Tra theo alias và chặn fuzzy khi có mã (D2, D3)
- **`query_sources.py`:** thêm `"id": 1` vào projection.
- **`normalize_catalog_product`:** thêm `aliases` gồm `ma_san_pham`, `id`, `_id` (chuẩn hóa số). `id` chuẩn vẫn là `ma_san_pham`. `by_id` lập chỉ mục theo mọi alias.
- **`resolve_products`:**
  - có mã tường minh thì **không** chọn bằng fuzzy hay khớp tên;
  - `ambiguous` = tối đa 5 ứng viên;
  - thêm `unresolved_reasons` với giá trị `NOT_FOUND` hoặc `INACTIVE`.
- **Stopwords:** thêm `_TOKEN_STOPWORDS` (ma, id, sp, san, pham, gioi, thieu, tao, livestream, live, an, toan, toi, mai, luc, gio, cho, va, cua, phut, noi, bo, xem, truoc, phat).
- **`service.py`:** với mã chưa khớp, tra thêm `db.san_pham.find_one` theo cùng `$or` như `product_collector`. Tìm thấy nhưng đang ẩn thì đánh `INACTIVE`.
- **Câu cảnh báo:**
  - "Không tìm thấy mã {id} trong catalog đang bán (đã đối chiếu mã sản phẩm, id và _id)."
  - "Mã {id} đang ẩn/ngừng bán."
- **Test:**
  - `{ma_san_pham:745, id:93}` với `["93"]` → chọn "745";
  - không có 93 → `selected=[]` và `ambiguous` không rỗng;
  - các test cũ vẫn PASS.

### T3. Xác nhận nguyên tử và thông tin cho UI (D4–D6)
- **`confirm_agent_run`:**
  - dùng `claim_transition(..., {"NEEDS_INPUT","NEEDS_ATTENTION"}, "RECEIVED", ...)`;
  - nếu thất bại thì raise `ConflictError(ValueError)`; Flask trả 409.
  - Payload nhận `product_ids` (đã làm sạch) và `scheduled_at` (ISO, hoặc datetime-local thì hiểu theo `TIMEZONE`).
- **`process_agent_run`:** có `overrides.explicit_product_ids` thì bỏ qua nhánh truy vấn nghiệp vụ.
- **Cổng chính sách:** nền tảng `p` không phải internal và không có trong `RTMP_TARGETS` → `NEEDS_INPUT` / `POLICY_GATE`, cảnh báo "Chưa cấu hình RTMP cho {p}. Chọn phát nội bộ hoặc cấu hình trước."
- **`create_campaign`:** bắt `ValueError` → `NEEDS_INPUT` / `SCHEDULE_REVIEW`. Task không retry khi gặp `ValueError`.
- **Bổ sung vào `plan`:** `unresolved_ids`, `unresolved_reasons`, `confirmable_products` (= resolved + candidate, loại trùng), `needs` ⊂ {product, schedule, platform, intent}.
- **Test:**
  - confirm khi đang `INTERPRETING` → 409;
  - confirm `["745"]` trên run nhánh truy vấn → campaign có `product_ids==["745"]`;
  - youtube thiếu RTMP → `NEEDS_INPUT`;
  - `ValueError` → `NEEDS_INPUT`, không retry.

### T4. UI xác nhận trong panel Agent (D4)
- **File:** `ai-idol-studio.js` (giữ CRLF), có thể chạm `ai-idol-studio.css`.
- Khi `NEEDS_INPUT` và chưa có `campaign_id`:
  - mỗi mục `confirmable_products` có nút "Dùng sản phẩm này";
  - hiện `unresolved_ids` kèm lý do;
  - `needs` có `product` → ô "Mã sản phẩm" và nút "Dùng mã này";
  - `needs` có `schedule` → `datetime-local` và nút "Dùng lịch này".
- Nút "Chạy lại" cho `NEEDS_INPUT`.
- HTTP 409 hiện câu "Agent đang xử lý, chờ vài giây rồi thử lại."
- **Kiểm chứng:** `node --check`.

### T5. Tách queue Celery (D7)
- **`task_routes`:**
  - agent, prepare, dispatch, preflight và các task beat mới → `control`;
  - generate, assemble → `media`;
  - broadcast → `broadcast`.
- Mỗi mục beat thêm `options.expires`.
- **Compose:** `ai-idol-worker --queues=media,celery`; thêm `ai-idol-control-worker` (`--queues=control,agent --concurrency=2`, dùng lại `*ai-idol-common`).
- **Test:** ánh xạ routes đúng.

### T6. Job idempotent và lỗi cuối không bị nuốt (D8, D9)
- **`generate_job_task`:**
  - job `READY` → chỉ cập nhật campaign và claim, không chạy pipeline;
  - campaign `CANCELLED` → `fail_job`;
  - hết retry → `fail_job` rồi `update_campaign_from_job`.
- **`prepare_campaign`:** chỉ trả job chưa `READY`; mọi segment lỗi thì ghi `error_message`.
- **Test:** cả hai trường hợp.

### T7. Watchdog (D8)
- **Config:** `STALE_JOB_MINUTES=45`, `STALE_STARTING_MINUTES=3`, `STALE_ASSEMBLING_MINUTES=60`.
- **`core/watchdog.py: reap_stale_work`**, task beat 60 giây, queue `control`:
  - job `IN_PROGRESS` quá hạn → `fail_job` (stage `GENERATING_AVATAR` dùng `AVATAR_SERVICE_TIMEOUT/60 + 10`);
  - campaign `STARTING` hoặc `ASSEMBLING` quá hạn → `NEEDS_ATTENTION`.
- Mọi filter đều có điều kiện trạng thái.

### T8. Retry campaign đủ mọi trường hợp (D10)
- **`core/campaigns.py: retry_campaign`** trả `{queued_segments, assembly_queued, prepare_queued}`:
  1. segment `FAILED` có job → reset và đưa vào queue;
  2. segment `FAILED` không có job → prepare;
  3. mọi segment `READY` và master thiếu → `NEEDS_ATTENTION` → `ASSEMBLING` → assemble;
  4. master hợp lệ nhưng preflight lỗi → 409 với câu hướng dẫn.

### T9. Lưu kịch bản bị từ chối và duyệt có kiểm soát (D11, D12)
- **Validator FAIL:** checkpoint `artifacts.rejected_script_text`, `validation_feedback`, `script_status=REJECTED_BY_VALIDATOR`. Không ghi vào `artifacts.script_text`.
- **`get_campaign`:** projection thêm các trường trên.
- **Approve:** chạy `check_blacklist`, vi phạm thì trả 422.
- **JS:** cho phép sửa và hiện feedback.

### T10. Health dịch vụ và chờ renderer (D13)
- **`core/health.py: dependency_status`:** cache 15 giây, chạy song song, timeout 2 giây.
- **`GET /api/ai-idol/health/dependencies`:** trả trạng thái mongo, redis, workers (`inspect.active_queues`), script_council, syna (`renderer == RENDER_VERSION`), avatar (`required` khi `AVATAR_PROVIDER != mock`), rtmp (**chỉ tên nền tảng**).
- **`RendererUnavailable(RuntimeError)`:** raise khi health hoặc kết nối Syna lỗi. Pipeline bắt lỗi này → job `WAITING_RENDERER`, không `fail_job`.
- **Beat `resume_waiting_renders` (60 giây):**
  - Syna khỏe → `WAITING_RENDERER` → `QUEUED` → `generate_job_task.delay`;
  - còn dưới 10 phút tới giờ phát → `fail_job`.
- **Agent:** Syna không khỏe thì thêm warning, không chặn.
- **PHP:** route `admin_ai_idol_health`.
- **UI:** dải trạng thái làm mới mỗi 30 giây, không khóa nút chạy.

### T11. Cận trễ, hủy, đổi lịch (D14)
- **Config:** `BROADCAST_LATE_GRACE_MINUTES=30`. Dispatch quá cận trễ → `NEEDS_ATTENTION`.
- **`cancel_campaign`:** cho phép từ VALIDATING, GENERATING, AWAITING_APPROVAL, NEEDS_ATTENTION, SCHEDULED; segment chưa READY → CANCELLED; agent run liên kết → COMPLETED.
- **`reschedule_campaign`:** lịch mới `>= now + 2 phút`; `"now"` chỉ cho campaign chỉ-nội-bộ.
- **Flask:** `POST /campaigns/<id>/cancel` và `/reschedule`. **PHP:** `admin_ai_idol_cancel_campaign` và `admin_ai_idol_reschedule_campaign`.
- **UI:** nút "Hủy chương trình", "Phát nội bộ ngay", "Đổi lịch".

### T12. Phát nội bộ thật và che stream key (D15, D16)
- **Kênh internal:** broadcast `LIVE` có `ends_at`. Campaign chỉ-nội-bộ giữ `LIVE`.
- **Beat `finalize_live_campaigns` (30 giây):** đóng sang `COMPLETED` hoặc `PARTIAL_FAILURE`.
- **`_redact`:** thay target và mọi chuỗi khớp `rtmps?://\S+` trước khi lưu `error_message`.
- **UI:** huy hiệu "Đang phát nội bộ", video seek theo giờ thực, bảng broadcast và preflight.

### T13. Panel "Agent đang làm gì?" (D17)
- **`update_agent_run`:** `$push stage_history` với `$slice -30`.
- **`_serialize_with_campaign`:** thêm `campaign_scheduled_at`, `campaign_platforms`, `campaign_segments`, `campaign_broadcasts` (đã che), `campaign_preflight`, và `status_effective`.
- **JS:** dòng thời gian 7 bước: Hiểu lệnh → Chọn sản phẩm → Kịch bản → Giọng nói → Render Syna → Ghép & lên lịch → Phát. Hiện "x/y sản phẩm sẵn sàng" và nhãn cho các trạng thái mới.

### T14. Test hồi quy toàn luồng (D18)
`tests/test_e2e_flow.py`, ba ca:
1. `auto_preview` mã 93 → COMPLETED;
2. `assisted` → duyệt → SCHEDULED;
3. Syna tắt → WAITING_RENDERER → resume → READY.

## 4. Nguyên tắc kiến trúc

1. **Logic nằm trong Python.** PHP chỉ `checkAdmin` rồi proxy qua `callFlaskAPI`. Route khai báo tường minh trong `routes/ai_idol.php` (và danh sách `$r` trong 3 file `index.php`). JS chỉ hiển thị.
2. **Trạng thái chỉ nằm trong Mongo.** Chuyển trạng thái có điều kiện qua `claim_transition` hoặc `find_one_and_update`. Danh sách trạng thái:
   - job: CREATED, QUEUED, IN_PROGRESS, AWAITING_APPROVAL, WAITING_RENDERER, READY, FAILED;
   - segment: QUEUED, GENERATING, AWAITING_APPROVAL, READY, FAILED, CANCELLED;
   - campaign: VALIDATING, GENERATING, AWAITING_APPROVAL, ASSEMBLING, SCHEDULED, STARTING, LIVE, COMPLETED, PARTIAL_FAILURE, NEEDS_ATTENTION, CANCELLED.
3. **Việc nặng chỉ chạy trong Celery**, theo queue media, broadcast, control.
4. **Không thêm dependency, DB hay image mới.**
5. **`auto_publish` vẫn khóa.** `auto_preview` ép kênh internal.
6. **Không bao giờ log hay trả stream key.**
7. **Idempotency:** giữ `agent:{run_id}`; task chạy lại phải an toàn.
8. **Validator fail-closed.** Approve phải chạy `check_blacklist`.
9. **UI tiếng Việt:** ngắn, nói rõ nguyên nhân và việc cần làm. Luôn `escapeHtml`.
10. **Không động vào:** `ai-service-flask`, gợi ý sản phẩm, voice, `command_query.py`, avatar provider, `ai-idol-agent-service`, `syna-render-server.cjs`. Giữ EOL. Không commit nếu chưa được yêu cầu.

## 5. Nghiệm thu

- **AC1:** Lệnh có mã 93 hoặc tạo đúng sản phẩm, hoặc ra `NEEDS_INPUT` kèm lý do, nút chọn và ô nhập mã. Không bao giờ tự chọn sản phẩm khác.
- **AC2:** Xác nhận xong thì campaign được tạo trong vòng 10 giây, kể cả khi đang render.
- **AC3:** `auto_preview` tự đi hết tới COMPLETED; dòng thời gian khớp thực tế.
- **AC4:** `assisted` dừng chờ duyệt; bộ đếm đúng.
- **AC5:** Syna tắt thì dải health báo đỏ, job ở `WAITING_RENDERER` và tự chạy tiếp khi bật lại.
- **AC6:** Mọi lỗi đều có nút xử lý; không trạng thái nào treo quá 1 giờ.
- **AC7:** Không có stream key ở đâu; unittest, `php -l` và `node --check` đều PASS.
