# Handoff cho Codex: AI Idol Studio / Syna và bộ thay đổi "self-healing script"

Nhánh: `syna`. HEAD lúc viết: `585cffc`. Phạm vi thay đổi được mô tả: `df5d941..585cffc` (4 commit, 44 file, +2105/-226).
Ngôn ngữ tài liệu: tiếng Việt. Mọi khẳng định bên dưới được đối chiếu với code ở HEAD; chỗ nào chưa kiểm chứng được ghi rõ "CHƯA XÁC MINH" hoặc "suy ra từ đọc code".

> Lưu ý về file này: trước đó `update.md` ở gốc repo là một nhật ký cập nhật dự án cũ (1379 dòng, đã commit ở `9c04f59`). File này đã thay thế nội dung đó. Nếu cần bản cũ: `git show HEAD:update.md`.

Không có bí mật nào trong tài liệu này. Không đọc, không ghi giá trị `.env`. Tên biến môi trường được nêu, giá trị thì không.

---

## 1. Tổng quan dự án và mục tiêu Syna

SkinSyntaxVN là sàn thương mại điện tử mỹ phẩm chăm sóc da (PHP MVC + MongoDB Atlas) có trợ lý AI. Phần liên quan tài liệu này là **AI Idol Studio**: admin giao việc, hệ thống tự viết kịch bản bán hàng cho mascot **Syna** (AI Idol 3D), kiểm duyệt kịch bản, đọc giọng (TTS), dựng video, rồi hoặc phát livestream, hoặc đưa video lên trang sản phẩm.

Mục tiêu của đợt thay đổi này:

1. Kịch bản bị validator chê thì **tự sửa đúng đoạn lỗi** (tối đa 3 lần) thay vì viết lại cả bài rồi fail.
2. Sửa không xong thì **không fail job**: job được "đỗ" (park) ở trạng thái `AUTO_REPAIR_EXHAUSTED` để nhân viên xử lý (thử lại, nhờ AI viết lại, sửa tay, hủy).
3. Admin chọn rõ **AUTO** (tự duyệt, tự đăng) hay **MANUAL** (dừng chờ duyệt kịch bản) qua 2 nút ở bước 4 của tab Thủ công và 2 radio ở tab Tự động.
4. Kiểm tra giá/khuyến mãi/khan hiếm dựa trên dữ liệu CSDL (grounded), bỏ quy tắc cũ "giá chỉ được nói trước [Price]".
5. TTS biết đọc đúng tên thực thể (T150, SPF, tên hoạt chất).
6. Đăng lên trang sản phẩm có khóa idempotent theo job + sản phẩm + phiên bản kịch bản.

### 1.1 Stack và các service

| Thành phần | Công nghệ | Vai trò |
|---|---|---|
| `frontend/` | PHP views + JS/CSS thuần (không bundler) | Giao diện admin Studio: `frontend/views/admin/ai_idol/index.php`, `frontend/public/assets/js/studio-*.js`, `css/ai-idol-studio.css` |
| `backend/` | PHP 8.2 MVC | Controller admin, route, proxy cURL sang Flask: `backend/app/controllers/AdminAiIdolController.php`, `backend/app/routes/ai_idol.php` |
| `ai-idol-service/` | Flask + Celery + pymongo | API, pipeline, validator, repair, TTS, render. Cổng nội bộ 5003 |
| `ai-idol-agent-service/` | CrewAI (Script Council: writer + editor) | Nháp kịch bản thay thế; không có quyền Mongo/Chroma/media (theo `ai-idol-service/README.md`) |
| `ai-idol-avatar-service/` | Python, chạy trên host Windows (SadTalker, cổng 7861) | Avatar legacy (không dùng cho `avatar_mode=syna_3d`) |
| Syna renderer | Chạy trên host (biến `SYNA_RENDER_URL`, cổng 7862 trong compose) | Dựng video Syna 3D native (`engines/syna_engine.py`) |
| MongoDB | **MongoDB Atlas được giữ** (quyết định của người dùng); compose có thêm Mongo local cổng 27018 nhưng không phải hướng chính | Trạng thái campaign/job/script/video |
| Redis | Celery broker/backend (`AI_IDOL_REDIS_URL`, db 2) và cache PHP (db 0) | Hàng đợi, cache |
| ChromaDB | RAG kiến thức sản phẩm | `engines/knowledge_retriever.py` |
| Nginx | Reverse proxy + static | Cổng host `WEB_PORT`; trong `.env` cục bộ đặt `8080` (đã xác minh tên biến và giá trị cổng, không đọc gì khác) |
| Lưu trữ file | Volume `ai_idol_storage` (audio, output, programs) và R2 (upload video cho trang sản phẩm, qua `engines/media_publisher.py`) | File media |

Docker compose (`docker-compose.yml`, file duy nhất): `ai-idol-service` (gunicorn 1 worker 4 thread, bind `127.0.0.1:5003`), `ai-idol-worker` (queue `media,celery`, concurrency 1), `ai-idol-control-worker` (queue `control,agent`, concurrency 2), `ai-idol-broadcast-worker` (queue `broadcast`), `ai-idol-beat`, `php-backend` (`AI_IDOL_ENDPOINT=http://ai-idol-service:5003`), `nginx`, `redis`, `chromadb`, `mongodb`.

### 1.2 Cách chạy local và kiểm thử

- Web admin: `http://localhost:8080/index.php?r=admin_ai_idol` (đăng nhập admin; có `scripts/seed_admin_staff.php` để tạo admin local).
- Khởi chạy mẫu (theo `ai-idol-service/README.md`): `docker compose up -d --build ai-idol-agent-service ai-idol-service ai-idol-worker ai-idol-control-worker ai-idol-broadcast-worker ai-idol-beat php-backend nginx`. Avatar host: `scripts/start-ai-idol-avatar.ps1`; Syna renderer: `scripts/start-ai-idol-syna.ps1`.
- Python tests (chạy trong thư mục `ai-idol-service`, dùng venv ở gốc repo): `../.venv/Scripts/python.exe -m pytest tests -q`.
- JS tests: `node --test frontend/public/assets/js/tests/*.test.js` (cần dùng glob; truyền thư mục trần sẽ fail). Kết quả tôi chạy: 109/109 pass.
- PHP: `php -l <file>`. Đã chạy cho `AdminAiIdolController.php`, `routes/ai_idol.php`, `views/admin/ai_idol/index.php`: không lỗi cú pháp.
- **Celery KHÔNG được cài trong `.venv` cục bộ.** Hệ quả: mọi test import `app`/`tasks`/`celery_app` fail với `ModuleNotFoundError: No module named 'celery'` (xem mục 12.3).
- **`backend/tests/*` ghi vào DB thật (Atlas). Không chạy hàng loạt.** Test của `ai-idol-service` dùng `tests/fakes.py::FakeDb` (Mongo giả trong bộ nhớ), nên an toàn.
- Quy ước từ AGENTS.md: GitNexus impact trước khi sửa symbol; không dùng `git stash` ở cây làm việc dùng chung; không commit/push/deploy nếu không được yêu cầu (chi tiết mục 15).

---

## 2. Kiến trúc và ranh giới

```
 Trình duyệt (admin, studio-*.js)
   |  fetch  index.php?r=admin_ai_idol_...   header X-CSRF-Token (studio-core.js dòng ~111)
   v
 Nginx (host :8080) --> PHP-FPM
   routes/ai_idol.php  -->  AdminAiIdolController
        checkAdmin()  : session user, role admin hoặc is_admin=1, nếu không -> 403
        verifyCsrf()  : so hash_equals với $_SESSION['admin_ai_idol_csrf'];
                        token lấy từ body.csrf hoặc header X-CSRF-Token; fail-closed
        callFlaskAPI(): cURL timeout 30s tới AI_IDOL_ENDPOINT (mặc định http://ai-idol-service:5003)
                        trả lại nguyên body + HTTP status của Flask; Flask không tới được -> 502 JSON
   v
 Flask  ai-idol-service/app.py  (/api/ai-idol/...)       [không có auth riêng; chỉ nội bộ mạng docker/127.0.0.1]
   |  .delay()
   v
 Celery (Redis)   queue control: prepare_campaign, process_agent_run, beat tasks
                  queue media  : generate_job, assemble_campaign, publish_*
                  queue broadcast: broadcast_campaign
   v
 core/pipeline.py::run_ai_idol_pipeline  -> engines/* , providers/*
   v
 MongoDB (ai_idol_* , ai_product_videos)   Redis   storage (volume / R2)
   v
 Trang sản phẩm (PHP ProductVideo đọc ai_product_videos)
```

Ranh giới cần giữ:

- PHP **không** gọi trực tiếp nội bộ AI; chỉ qua các route `/api/ai-idol/*` của Flask (đúng quy tắc AGENTS.md). Controller chỉ là proxy mỏng (CSRF + admin + chuyển tiếp).
- **Server là nơi duy nhất quyết định kịch bản PASS.** Mọi trường validation client gửi lên đều bị bỏ qua (`app.py::_recovery_response` docstring).
- Script Council (CrewAI) là service riêng, chỉ tạo nháp. Validator + repair luôn chạy lại ở `ai-idol-service`.
- Lỗi dịch vụ ngoài (Council, Syna) không làm job chết: Council fail thì fallback single-agent; Syna down thì job đỗ `WAITING_RENDERER`.

---

## 3. Pipeline end-to-end

Điểm vào: Admin tạo campaign (tab Thủ công) hoặc giao agent run (tab Tự động).

```
create_campaign (core/campaigns.py)          agent run (agents/service.py::process_agent_run)
        \                                     /  -> create_campaign(...)  idempotency_key "agent:<run_id>"
         v
 prepare_campaign_task -> prepare_campaign(): tạo job cho từng segment, snapshot sản phẩm
         v  (mỗi job)
 generate_job_task -> run_ai_idol_pipeline(job_id)      [core/pipeline.py]
```

Chi tiết `run_ai_idol_pipeline` (mỗi bước ghi checkpoint nên retry chạy tiếp, không làm lại):

| # | Bước | `current_stage` | Hàm / file |
|---|---|---|---|
| 1 | Thu thập sản phẩm + giá | `COLLECTING_PRODUCT` | `engines/product_collector.py::collect_product_and_snapshot` (campaign đã chụp sẵn `product_snapshot` lúc `prepare_campaign`, nên bước này thường bị bỏ qua), `core/pricing.py::resolve_pricing` |
| 2 | RAG kiến thức | `RETRIEVING_KNOWLEDGE` | `engines/knowledge_retriever.py::retrieve_knowledge` -> `artifacts.knowledge` |
| 3 | Sinh nháp | `GENERATING_CONTENT` | `engines/content_agent.py::generate_content_and_script` (nếu `agent_managed` và `SCRIPT_COUNCIL_ENABLED` thì thử Script Council, lỗi thì fallback `LLMProvider`); prompt theo `purpose` (`build_script_prompts`) |
| 4 | Validate + repair | `VALIDATING_SCRIPT` / `REPAIRING_SCRIPT` | `engines/script_validator.py::validate_and_repair_script` gọi `validate_script` rồi `engines/script_repair.py::repair_script`; callback `on_event` cập nhật stage |
| 4a | Qua | | Ghi `ai_idol_scripts` version 1 `PENDING_APPROVAL`; checkpoint `artifacts.script_text` + tracking |
| 4b | Không qua (hết lượt sửa hoặc lỗi kỹ thuật) | `AUTO_REPAIR_EXHAUSTED` | Pipeline checkpoint `status=AUTO_REPAIR_EXHAUSTED`, `artifacts.rejected_script_text`, KHÔNG ghi `artifacts.script_text`; `return False` (không `fail_job`) |
| 5 | Cổng duyệt | `SCRIPT_AUTO_APPROVED` hoặc `AWAITING_SCRIPT_APPROVAL` | `should_auto_approve_script(config)` + biến `product_video_auto` (xem 3.1). AUTO: ghi script `APPROVED`, `approved_by` = `AUTO_APPROVAL_MODE` / `PRODUCT_VIDEO_AUTO` / `AI_IDOL_AGENT_POLICY`. MANUAL: job `AWAITING_APPROVAL`, segment `AWAITING_APPROVAL`, `return False` |
| 6 | TTS | `GENERATING_VOICE` | `engines/voice_engine.py::generate_voice_audio`: tách `split_labeled_sections`, mỗi đoạn `normalize_for_tts` rồi `providers/tts_provider.py::TTSProvider.generate`, chèn khoảng lặng ngẫu nhiên giữa các đoạn (seed theo job), ghi `<wav>.timings.json` với **text gốc**. Chỉ chạy lại khi `audio_pipeline_version != AUDIO_PIPELINE_VERSION` ("3") hoặc thiếu file |
| 7 | Render | `RENDERING` | `avatar_mode == "syna_3d"`: `engines/syna_engine.py::render_syna_video` (renderer host; `RendererUnavailable` -> job `WAITING_RENDERER`, không phải lỗi). Ngược lại `_render_legacy_media`: `GENERATING_AVATAR` rồi `engines/video_composer.py::compose_final_video` |
| 8 | Master audio + clip trang sản phẩm | | `engines/audio_mixer.py::mix_program_audio`; `engines/body_clip.py::product_page_clip` (cắt clip không giá/CTA cho trang sản phẩm) |
| 9 | Kiểm tra máy lần cuối | `FINAL_CHECK` khi lỗi | `core/pipeline.py::final_machine_check` trong `_complete_video_job`: product_id khớp, file tồn tại, duration > 0, và `validate_script` lại toàn văn (chỉ lỗi `severity=="error"`); có vấn đề -> `fail_job(job, "FINAL_CHECK", ...)` |
| 10 | READY | `READY` | `_complete_video_job` lưu `ai_idol_videos` (kèm `price_claim`, `product_page_file_path`), job `READY`, `video_id` |
| 11 | Hậu xử lý | | Trong `tasks.py::generate_job_task`: `update_campaign_from_job`; nếu campaign sẵn sàng thì `assemble_campaign_task`; nếu `purpose==product_video` và có `video_id`: `publish_product_video_task.delay` (upload R2) rồi `core/publications.py::auto_publish_video` (ghi `ai_product_videos`, best-effort, không bao giờ raise) |

### 3.1 Cổng duyệt: logic chính xác

```python
# core/pipeline.py
def should_auto_approve_script(config):
    mode = config.approval_mode.upper()
    if mode == "AUTO":   return True
    if mode == "MANUAL": return False
    return agent_managed and script_approval_mode == "auto" and agent_autonomy_policy in {auto_preview, auto_publish}

mode = config.approval_mode.upper()
product_video_auto = purpose == PRODUCT_VIDEO and mode != "MANUAL"
if job.script_status != "APPROVED" and (product_video_auto or should_auto_approve_script(config)): auto-approve
```

Hệ quả: `approval_mode` vắng mặt giữ hành vi cũ (`product_video` luôn tự duyệt; `live_only`/`live_and_product` thủ công chờ duyệt). Có test `test_absent_mode_keeps_old_behaviour`.

**Đã sửa (sau bàn giao):** nhánh Agent (`agents/service.py::process_agent_run`) nay ghi cả `configuration.approval_mode` (`AUTO`|`MANUAL`) lẫn `script_approval_mode`, nên MANUAL + `product_video` từ tab Tự động dừng ở `AWAITING_APPROVAL`; AUTO tiếp tục. `script_recovery._is_auto` đã dùng cùng quy tắc `approval_mode` nên không cần đổi. Có test phủ (mục Sửa sau bàn giao).

---

## 4. Máy trạng thái

### 4.1 Job (`ai_idol_jobs.status` / `current_stage`)

| status | current_stage thường gặp | Ý nghĩa | Ai đặt |
|---|---|---|---|
| `CREATED` | `CREATED` | Mới tạo | `state_machine.create_job` |
| `QUEUED` | `QUEUED`, `SCRIPT_APPROVED` | Chờ worker | `retry_campaign`, `watchdog.resume_waiting_renders`, `script_recovery._approve_and_resume` |
| `IN_PROGRESS` | `COLLECTING_PRODUCT`, `RETRIEVING_KNOWLEDGE`, `GENERATING_CONTENT`, `VALIDATING_SCRIPT`, `REPAIRING_SCRIPT`, `SCRIPT_AUTO_APPROVED`, `GENERATING_VOICE`, `GENERATING_AVATAR`, `RENDERING` | Đang chạy | `update_job_stage` (pipeline); `script_recovery._claim` đặt `IN_PROGRESS`+`VALIDATING_SCRIPT` làm "khóa" |
| `AWAITING_APPROVAL` | `AWAITING_SCRIPT_APPROVAL` | Chờ người duyệt (MANUAL) | pipeline hoặc `script_recovery._park_awaiting`; `script_status=PENDING_APPROVAL` |
| `AUTO_REPAIR_EXHAUSTED` | `AUTO_REPAIR_EXHAUSTED` | Đã tự sửa tối đa vẫn lỗi hoặc validator không chạy được; chờ nhân viên | pipeline hoặc `script_recovery._park_exhausted` |
| `WAITING_RENDERER` | `WAITING_RENDERER` | Syna renderer tắt | pipeline; `error_message="Đang chờ Syna: ..."` |
| `READY` | `READY` | Video xong | `_complete_video_job` |
| `APPROVED`, `SCHEDULED` | | Luồng legacy một job lẻ (`/jobs/<id>/approve`, `/schedule`) | `app.py` |
| `FAILED` | stage lỗi, `failed_stage` | Lỗi | `fail_job`, watchdog |
| `CANCELLED` | `CANCELLED` | Hủy | `script_recovery.cancel`, `generate_job_task` khi campaign đã hủy (qua `fail_job` với stage `CANCELLED` nên thực chất `status=FAILED`) |
| (legacy) `FAILED` + `script_status=REJECTED_BY_VALIDATOR` | `VALIDATING_SCRIPT` | Định dạng cũ trước commit này; vẫn được hiểu là "đã hết lượt sửa" (`_is_exhausted`) | dữ liệu cũ |

`script_status` (trên job): `PENDING_APPROVAL` | `APPROVED` | (legacy) `REJECTED_BY_VALIDATOR`.

### 4.2 Segment (`ai_idol_segments.status`)

`QUEUED` -> `GENERATING` -> `AWAITING_APPROVAL` | `READY` | `FAILED` | `SKIPPED` | `CANCELLED` | `COMPLETED` (sau phát/hủy).
Ánh xạ từ job (`core/campaigns.py::update_campaign_from_job`): `READY`->READY; `AWAITING_APPROVAL`->AWAITING_APPROVAL; `AUTO_REPAIR_EXHAUSTED` hoặc `FAILED`->FAILED; còn lại->GENERATING. Riêng khi job `AUTO_REPAIR_EXHAUSTED` (hoặc legacy rejected) **và** campaign `configuration.agent_mode == "autopilot"` thì segment thành `SKIPPED` (autopilot bỏ sản phẩm, ghi decision `SCRIPT_SKIPPED`). Campaign Thủ công thì giữ `FAILED` để nhân viên xử lý.

### 4.3 Campaign (`ai_idol_campaigns.status`)

`VALIDATING` -> `GENERATING` -> (`AWAITING_APPROVAL` | `NEEDS_ATTENTION`) -> `ASSEMBLING` (chỉ khi có livestream) -> `SCHEDULED` -> `STARTING` -> `LIVE` -> `COMPLETED`; nhánh `VIDEOS_READY` (campaign `product_video` không phát, đóng ngay khi mọi clip xong); `CANCELLED`; kết thúc phát là `COMPLETED` hoặc `PARTIAL_FAILURE` (đặt ở `engines/broadcast_engine.py`).
`update_campaign_from_job` chỉ kéo campaign về `NEEDS_ATTENTION`/`AWAITING_APPROVAL` khi nó đang ở `GENERATION_PHASE_STATUSES` = (`VALIDATING`, `GENERATING`, `AWAITING_APPROVAL`, `NEEDS_ATTENTION`).
Hủy được ở: `VALIDATING, GENERATING, AWAITING_APPROVAL, NEEDS_ATTENTION, SCHEDULED`.

### 4.4 Agent run (`ai_idol_agent_runs.status`)

`RECEIVED` -> `INTERPRETING` -> `PLAN_READY` -> `CAMPAIGN_CREATED`; rẽ: `NEEDS_INPUT`, `NEEDS_ATTENTION` (lỗi), `CANCELLED`, và các trạng thái kết quả truy vấn (`RESULTS_READY`, `DATA_UNAVAILABLE`, `EMPTY_RESULTS`).

### 4.5 Bảng chuyển trạng thái quan trọng và người kích hoạt

| Từ | Sang | Ai / cái gì | Điều kiện |
|---|---|---|---|
| (pipeline, validate xong) | `AWAITING_APPROVAL` | `run_ai_idol_pipeline` | MANUAL, hoặc `live_only`/`live_and_product` khi không có mode |
| (pipeline) | `AUTO_REPAIR_EXHAUSTED` | `run_ai_idol_pipeline` | `result.passed == False` (hết `MAX_SCRIPT_REPAIR_ATTEMPTS` hoặc lỗi kỹ thuật `VALIDATOR_UNAVAILABLE` hoặc repair ném lỗi) |
| `AWAITING_APPROVAL` / `AUTO_REPAIR_EXHAUSTED` / `FAILED` | `QUEUED` (+ `generate_job_task.delay`) | `script_recovery.approve` (POST `/script/approve`) | Văn bản gửi lên (hoặc bản đang lưu) validate PASS và **không bị repair thay đổi** |
| cùng | `AWAITING_APPROVAL` | `approve` | PASS nhưng repair đã sửa văn bản (`repaired=true`): ghi script version mới `PENDING_APPROVAL`, để người xem lại |
| cùng | `AUTO_REPAIR_EXHAUSTED` | `approve` / `retry_repair` / `rewrite` | Validate vẫn fail (trả HTTP 200, xem 6.4) |
| `AUTO_REPAIR_EXHAUSTED` | `QUEUED` | `retry_repair`/`rewrite` | PASS **và** `_is_auto(job)` (hoàn tác bằng `AUTO_REPAIR_RECOVERY`) |
| `AUTO_REPAIR_EXHAUSTED` | `AWAITING_APPROVAL` | `retry_repair`/`rewrite` | PASS nhưng không auto |
| `AWAITING_APPROVAL` | idem | `rewrite` | cho phép viết lại cả khi chưa lỗi |
| `AWAITING_APPROVAL`/`AUTO_REPAIR_EXHAUSTED` | `CANCELLED` | `script_recovery.cancel` (POST `/jobs/<id>/cancel`) | claim bằng precondition status |
| `IN_PROGRESS` quá `STALE_JOB_MINUTES` (45) | `FAILED` | `core/watchdog.py::reap_stale_work` | **Bỏ qua** job `AUTO_REPAIR_EXHAUSTED` (commit này thêm guard) |
| `FAILED` | `CREATED` + retry | POST `/jobs/<id>/retry` | chỉ job `FAILED`; **không** áp dụng cho `AUTO_REPAIR_EXHAUSTED` |
| `FAILED` (qua campaign) | `QUEUED` | `retry_campaign` | chỉ reset job đang `FAILED`; job `AUTO_REPAIR_EXHAUSTED` bị đưa vào danh sách "skipped" rồi đồng bộ lại (không được queue) |
| `WAITING_RENDERER` | `QUEUED` / `FAILED` | `watchdog.resume_waiting_renders` (beat 60s) | Syna sống lại / còn dưới `RENDER_WAIT_CUTOFF_MINUTES` trước giờ phát |

---

## 5. Mô hình dữ liệu (MongoDB, DB tên `MONGO_DB`, mặc định `skinsyntax`)

Collection do `ai-idol-service` đọc/ghi: `ai_idol_campaigns`, `ai_idol_segments`, `ai_idol_jobs`, `ai_idol_scripts`, `ai_idol_videos`, `ai_idol_agent_runs`, `ai_idol_broadcasts`, `ai_idol_schedules`, `ai_product_videos` (bản đăng trang sản phẩm, PHP `ProductVideo` đọc), `product_metrics_runs`, `product_metrics_snapshots`, `product_events`; đọc: `san_pham`, `flash_sale_campaigns`.

Index (`core/state_machine.py::ensure_indexes`): jobs `(status, updated_at)`, `campaign_id`; campaigns `(status, scheduled_at)`, `idempotency_key` unique sparse; segments `(campaign_id, position)` unique; broadcasts `(campaign_id, platform)` unique; agent_runs `(status, updated_at)`, `campaign_id` sparse, `idempotency_key` unique sparse; videos `(product_id, product_page_ready_at desc)` partial. Thêm trong commit `ca3a4a6`: `ai_product_videos.publish_key` unique, tên `uniq_publish_key`, `partialFilterExpression {publish_key: {$type: "string"}}` (tạo lười, một lần mỗi process, `_ensure_publish_key_index`; lỗi chỉ cảnh báo).

### 5.1 `ai_idol_jobs` (các trường liên quan)

```jsonc
{
  "_id": "ObjectId",
  "product_id": "1024", "campaign_id": "<str>", "segment_id": "<str>",
  "content_mode": "Product Intro", "purpose": "product_video",       // product_video | live_and_product | live_only
  "configuration": {
    "format": "16:9", "length_mode": "Long (90-150s)", "avatar_mode": "syna_3d",
    "approval_mode": "AUTO",            // MỚI: "AUTO" | "MANUAL" (do create_campaign chuẩn hóa chữ hoa)
    "script_approval_mode": "auto",     // "auto" | "human" (create_campaign suy ra từ approval_mode; Agent ghi trực tiếp)
    "agent_managed": true, "agent_run_id": "<str>", "agent_autonomy_policy": "auto_preview",
    "agent_mode": "autopilot", "narration_styles": ["..."]
  },
  "status": "AUTO_REPAIR_EXHAUSTED", "current_stage": "AUTO_REPAIR_EXHAUSTED",
  "script_status": "PENDING_APPROVAL",   // hoặc APPROVED
  "script_version": 2,                   // version bản ghi ai_idol_scripts đã duyệt (chỉ có sau khi duyệt)
  "retry_count": 0, "error_message": null, "failed_stage": null,
  "product_snapshot": { "name": "...", "pricing": { /* 5.4 */ } },
  "artifacts": {
    "knowledge": "...", "script_text": "[Hook]\n...",             // chỉ chứa bản "dùng được"
    "rejected_script_text": "<=20000 ký tự",                      // MỚI: nháp cuối khi hết lượt sửa
    "validation_feedback": "<=4000 ký tự",                        // MỚI
    "script_versions":   [{ "version": 1, "text": "...", "created_at": "...", "source": "generated|auto_repair|admin_edit|approve_revalidate|retry_repair|rewrite_<enum>" }],
    "validation_history":[{ "version": 1, "passed": false, "issues": [ /* ValidationIssue.to_dict() */ ] }],
    "repair_history":    [{ "from_version": 1, "to_version": 2, "issue_codes": ["PRICE_MISMATCH"] }],
    "repair_attempt": 1,        // số lần repair của lần chạy gần nhất
    "script_version": 2,        // = len(script_versions)  (MỚI; khác với job.script_version ở trên)
    "audio_path": "...", "audio_pipeline_version": "3",
    "final_video_path": "...", "video_renderer": "syna-native-v4"
  },
  "video_id": "<str>", "final_video_path": "...", "created_at": "...", "updated_at": "..."
}
```

Ghi chú: có **hai** trường tên `script_version`: `job.script_version` (version trong `ai_idol_scripts` khi duyệt) và `job.artifacts.script_version` (số phiên bản nháp đã sinh). `core/publications.py::_script_version` ưu tiên `artifacts.script_version`, rồi `job.script_version`, tối thiểu 1.

`script_recovery._tracking` **nối tiếp** lịch sử cũ và cộng `offset` vào số version, nên version trong `script_versions` là toàn cục theo job, không bắt đầu lại từ 1 sau mỗi lần staff chạy repair.

### 5.2 `ai_idol_scripts`

`{job_id, product_id, content_mode, script_text, version, status: PENDING_APPROVAL|APPROVED, approved_by: ADMIN|AUTO_APPROVAL_MODE|PRODUCT_VIDEO_AUTO|AI_IDOL_AGENT_POLICY|AUTO_REPAIR_RECOVERY, approved_at, created_at, updated_at}`. Pipeline ghi version 1 bằng upsert; recovery ghi version kế tiếp bằng `_script_record` (insert).

### 5.3 Segments, campaigns, videos, ai_product_videos

- Segment: `{campaign_id, product_id, position, status, job_id, video_id, video_path, error_message}`.
- Campaign: `{name, product_ids, platforms, pending_external_platforms, scheduled_at, purpose, configuration, idempotency_key, status, progress{total,ready,failed,skipped}, master_video_path, error_message, created_by}`.
- `ai_idol_videos`: `{job_id, product_id, file_path, duration, format, resolution, purpose, campaign_id, product_page_file_path, product_page_block_reason, price_claim, created_at, media{video_url,duration} (sau upload R2), hidden_from_product_page}`.
- `ai_product_videos` (bản đăng): `{video_id, job_id, campaign_id, segment_id, title, duration, format, resolution, avatar_mode, associated_product_ids, primary_product_id, display_priority:10, is_published, source:"auto", created_at, updated_at, published_at, publish_key}`. **`publish_key = "<job_id>:<product_id>:<script_version>"`** (MỚI).

### 5.4 `product_snapshot.pricing` (nguồn: `core/pricing.py::resolve_pricing`, spec 2026-10-16)

```json
{
  "regular_price": 299000,
  "market_price": 350000,
  "flash": {"campaign_id": 12, "campaign_name": "Sale 16/10", "flash_price": 249000, "discount_percent": 17, "end_ts": 1792256340},
  "captured_ts": 1792160000
}
```

`flash` chỉ có khi chiến dịch đang chạy, `0 < flash_price < regular_price` và còn >= `FLASH_MIN_REMAINING_SECONDS` (7200 giây); `pricing = null` nếu không có giá hợp lệ (video không được nói giá). `ai_idol_videos.price_claim` = `{product_id, regular_price, market_price, flash{campaign_id, flash_price, end_ts}, valid_until_ts}` (`claim_from_pricing`); PHP ẩn video khi giá đổi (`price_claim_state` / `ProductVideo::priceClaimHolds`).

---

## 6. Hợp đồng API

Tất cả route PHP dạng `index.php?r=<tên>&<tham số>`; POST cần admin + CSRF (header `X-CSRF-Token` hoặc `csrf` trong JSON body). Controller trả đúng body và HTTP status của Flask.

### 6.1 Route PHP -> Flask liên quan (`backend/app/routes/ai_idol.php`)

| `r=` | Method | Action controller | Flask đích |
|---|---|---|---|
| `admin_ai_idol_create_campaign` | POST | `createCampaign` | `POST /api/ai-idol/campaigns` |
| `admin_ai_idol_agent_create` | POST | `createAgentRun` (gắn `created_by` từ session) | `POST /api/ai-idol/agent/runs` |
| `admin_ai_idol_agent_runs` / `_agent_run` | GET | | `GET /agent/runs`, `/agent/runs/<id>` |
| `admin_ai_idol_agent_confirm` / `_retry` / `_cancel` | POST | `proxyAgentMutation`, tham số `run_id` | `POST /agent/runs/<id>/{confirm,retry,cancel}` |
| `admin_ai_idol_approve_script` | POST | `approveScript`, `job_id`; body phải là JSON object, thiếu -> 400 | `POST /jobs/<id>/script/approve` |
| **`admin_ai_idol_job_retry_repair`** (MỚI) | POST | `retryRepairJobScript` -> `proxyJobScriptAction('/script/retry-repair')` | `POST /jobs/<id>/script/retry-repair` |
| **`admin_ai_idol_job_rewrite`** (MỚI) | POST | `rewriteJobScript` | `POST /jobs/<id>/script/rewrite` |
| **`admin_ai_idol_job_cancel`** (MỚI) | POST | `cancelJob` | `POST /jobs/<id>/cancel` |
| `admin_ai_idol_campaigns`, `_campaign`, `_retry_campaign`, `_cancel_campaign`, `_reschedule_campaign`, `_approve_platform`, `_health`, `_products`, `_products_query`, ... | | | các route campaign/health/sản phẩm khác (không đổi trong đợt này) |

Cả 3 route mới: `checkAdmin()` -> đọc `job_id` từ `$_GET` -> `verifyCsrf` -> `job_id` rỗng trả 400 `{ok:false, message:"Thiếu mã job."}` -> `callFlaskAPI('POST', '/api/ai-idol/jobs/<rawurlencode(job_id)><suffix>', body)`. Lưu ý `callFlaskAPI` chỉ gửi body khi `!empty($data)`; body rỗng `{}` thì Flask thấy không có JSON (đã xử lý bằng `request.get_json(silent=True) or {}`).

### 6.2 Flask: tạo campaign / agent

`POST /api/ai-idol/campaigns` -> 202 `{ok, campaign_id, created:true}`; 200 nếu trùng `idempotency_key` (`created:false`); 400 `{ok:false,message}` khi `ValueError`.
Payload tối thiểu (Thủ công): 

```json
{"name":"...","product_ids":["1024"],"purpose":"product_video","platforms":["internal"],
 "configuration":{"format":"16:9","avatar_mode":"syna_3d","narration_styles":["concise"],"approval_mode":"MANUAL"}}
```

Validation mới trong `create_campaign`: nếu `configuration` có khóa `approval_mode` thì phải thuộc {AUTO, MANUAL} sau `.upper()`, sai -> `ValueError("approval_mode must be AUTO or MANUAL")` (400). Kết quả lưu: `approval_mode` chữ hoa + `script_approval_mode` = `"auto"` hoặc `"human"`. Không gửi `approval_mode` -> không ghi gì (hành vi cũ). `avatar_mode == "syna_3d"` ép `format="16:9"` và `avatar_asset=""`.

`POST /api/ai-idol/agent/runs` body: `{brief (12..4000 ký tự), mode?:"autopilot", approval_mode?:"AUTO"|"MANUAL", purpose?, autonomy_policy?, scheduled_at?, duration_minutes?, platforms?, content_mode?, product_ids?, idempotency_key?, created_by?}` -> 202 `{ok, run_id, created:true}` / 200 `created:false` / 503 nếu `AGENT_ENABLED` tắt / 400 `ValueError`. `approval_mode` đi vào `overrides.approval_mode`, rồi `AgentBrief.approval_mode` (giá trị lạ -> `None`). Trong `process_agent_run`: `approval_mode = brief.approval_mode or ("MANUAL" if policy == "assisted" else "AUTO")`; `script_approval = "human"|"auto"` đi vào `plan.approval_mode`; `campaign.configuration` ghi cả `approval_mode` (AUTO|MANUAL) và `script_approval_mode`.
Autopilot vẫn ép `autonomy_policy="auto_preview"`. `auto_publish` bị chặn thành `NEEDS_INPUT` nếu `AGENT_ALLOW_AUTO_PUBLISH` tắt.

### 6.3 Script recovery (Flask, `core/script_recovery.py`, `app.py::_recovery_response`)

Tất cả POST, JSON body tùy chọn. Chung: `Outcome.body(job_id)` trả

```json
{"ok": true, "job_id": "<id>", "status": "QUEUED|AWAITING_APPROVAL|AUTO_REPAIR_EXHAUSTED|CANCELLED",
 "script_version": 3, "repair_attempt": 1,
 "issues": [ /* ValidationIssue dict của lần validate cuối */ ],
 "script_text": "...", "repaired": true, "message": "..."}
```

`repaired` và `message` chỉ xuất hiện khi có. HTTP: **202** nếu `outcome.resume` (đã `generate_job_task.delay`), còn lại **200**. Lỗi nghiệp vụ: `RecoveryError(message, status)` -> `{ok:false,message}` + status; `ValueError` -> 400.

| Endpoint | Body | Điều kiện trạng thái | Hành vi |
|---|---|---|---|
| `POST /jobs/<id>/script/approve` | `{"script_text"?: "..."}` | job ở `AWAITING_APPROVAL`, `AUTO_REPAIR_EXHAUSTED`, `REJECTED_BY_VALIDATOR`, hoặc `FAILED` (cho dữ liệu cũ); khác -> 409 "Kịch bản không ở trạng thái chờ duyệt." | Có `script_text` không rỗng: làm sạch retailer ngoài, `source="admin_edit"`; không có: lấy `artifacts.script_text` hoặc `rejected_script_text`, `source="approve_revalidate"`. Độ dài 40..20000 (sai -> 400). Claim job (409 nếu đang xử lý) -> `validate_and_repair_script` -> nếu fail: đỗ `AUTO_REPAIR_EXHAUSTED` (**HTTP 200, `ok:true`**) ; nếu PASS và repair không đổi chữ: ghi script `APPROVED` (`approved_by="ADMIN"`), xóa file render cũ (và audio nếu chữ khác bản cũ), job `QUEUED`, 202; nếu PASS nhưng repair đổi chữ: `AWAITING_APPROVAL`, `repaired:true`, 200 |
| `POST /jobs/<id>/script/retry-repair` | không | job phải `_is_exhausted` (status `AUTO_REPAIR_EXHAUSTED`/legacy), sai -> 409; không có nháp -> 400 | chạy lại validate+repair trên nháp (`rejected_script_text` hoặc `script_text`). PASS: nếu `_is_auto(job)` thì `APPROVED` + resume (202, `approved_by=AUTO_REPAIR_RECOVERY`), ngược lại `AWAITING_APPROVAL` (200) |
| `POST /jobs/<id>/script/rewrite` | `{"instruction": "NATURAL|SHORTER|STRONGER_SALES|PROFESSIONAL|HOOK_STRONGER|LESS_JARGON|CUSTOM", "custom_text"?: "3..1000 ký tự"}` | `AWAITING_APPROVAL` hoặc exhausted | enum sai -> 400 "Yêu cầu viết lại không hợp lệ."; `CUSTOM` thiếu/ngoài 3..1000 -> 400; LLM lỗi -> 502 "Không viết lại được..."; bản mới < 40 ký tự -> 502; sau đó đi qua `_run` như retry-repair (validate + repair) |
| `POST /jobs/<id>/cancel` | không | `AWAITING_APPROVAL` hoặc exhausted, sai -> 409 | job -> `CANCELLED`, `error_message="Đã hủy bởi quản trị viên."`, segment `CANCELLED` |

Cơ chế chống bấm đúp: `_claim` là `find_one_and_update({_id, status: <status đang đọc>}, {$set: IN_PROGRESS/VALIDATING_SCRIPT})`; bấm lần hai thấy status đã đổi -> `RecoveryError(409, "Kịch bản đang được xử lý hoặc đã đổi trạng thái.")`. Lỗi bất ngờ -> `_release` trả job về trạng thái cũ, rồi raise.
Ví dụ thực tế: đáp ứng `approve` thành công và resume:

```json
{"ok": true, "job_id": "64b64b64b64b64b64b64b64b", "status": "QUEUED", "script_version": 2,
 "repair_attempt": 0, "issues": [], "script_text": "[Hook]\n..."}
```

(HTTP 202). Và một lần fail đỗ lại: cùng cấu trúc nhưng `"status":"AUTO_REPAIR_EXHAUSTED"`, `issues` khác rỗng, HTTP 200.

### 6.4 Quirk `ok:true` + `AUTO_REPAIR_EXHAUSTED`

Cố ý (test `test_approve_failing_edit_parks_exhausted_not_422`): "lệnh được xử lý" nhưng kịch bản vẫn chưa đạt trả `ok:true`, HTTP 200, `status:"AUTO_REPAIR_EXHAUSTED"`. Client **phải rẽ nhánh theo `status`, không theo `ok`**. Frontend làm đúng (`applyScriptResult` ở `studio-activity.js`); client mới viết thêm phải theo cùng quy tắc.

### 6.5 Các endpoint Flask khác (không đổi hoặc ngoài phạm vi)

`GET /api/health`, `GET /api/ai-idol/health/dependencies`; agent: `GET /agent/runs`, `GET /agent/runs/<id>`, `POST /confirm`, `/retry` (chỉ khi `NEEDS_ATTENTION`/`NEEDS_INPUT`), `/cancel`; campaign: `GET/POST /campaigns`, `GET /campaigns/<id>`, `POST /retry|/cancel|/reschedule|/platforms/approve`, `GET /campaigns/<id>/video`; job legacy: `POST /jobs`, `GET /jobs/<id>`, `POST /jobs/<id>/retry` (chỉ `FAILED`, 400 nếu khác), `/approve` (chỉ `READY`, 409), `/schedule`, `GET /schedules`; `POST /products/query`; `GET /products/<id>/videos`; `POST /videos/<id>/visibility`; `GET /videos/<id>[?rendition=product_page]`; `POST /assets`.
**Đã gỡ khỏi `app.py` trong commit này:** route approve script cũ viết inline (147 dòng đổi); logic nay ở `core/script_recovery.py`. `app.py` không còn import trực tiếp `check_blacklist/check_purpose_rules` để duyệt ở đó (vẫn import dòng đầu, xem lại nếu dọn dẹp).

---

## 7. Hợp đồng validation

File: `ai-idol-service/engines/script_validator.py`, `core/script_text.py`.

### 7.1 `ValidationIssue`

```python
@dataclass
class ValidationIssue:
    code: str          # xem bảng 7.2
    category: str      # "HARD_FACT_ERROR" | "SOFT_QUALITY" | "TECHNICAL"
    severity: str      # "error" | "warning"   (script PASS khi không có issue severity=="error")
    section: str|None  # nhãn chuẩn hóa (hook, price, ingredients...) hoặc None
    original_text: str # đoạn vi phạm (để citation trong UI)
    reason: str        # tiếng Việt
    auto_fixable: bool
    def to_dict() -> dict
```

### 7.2 Mã lỗi

| code | category | severity | auto_fixable | Nguồn | Điều kiện |
|---|---|---|---|---|---|
| `PRICE_MISMATCH` | HARD_FACT_ERROR | error | true | `_scan_price_text` | Có số tiền/phần trăm giảm giá còn lại sau khi bỏ các cụm giá hợp lệ do backend sinh (`vi_money.price_phrases`). Không có `pricing` thì mọi số tiền đều sai |
| `PROMO_UNVERIFIED` | HARD_FACT_ERROR | error | true | idem | Không có `pricing.flash` mà dùng từ khuyến mãi: `sale, khuyen mai, uu dai, giam gia, co han, ket thuc, voucher, freeship, ma giam` |
| `SCARCITY_UNVERIFIED` | HARD_FACT_ERROR | error | true | idem | Không có flash **kèm `end_ts`** mà dùng từ khan hiếm: `chi hom nay, hom nay, toi nay, ngay mai, chot don, chi con (vai), duy nhat, sap het, flash sale, so luong co han, con it, het hang` |
| `EXTERNAL_RETAILER` | HARD_FACT_ERROR | error | true | `find_external_retailer_mentions` | Nhắc TikTok Shop, Hasaki, Watsons, Guardian, Shopee, Lazada, Tiki, Sendo, Chiaki (các tên này cũng bị `sanitize_external_retailer_mentions` thay bằng "SkinSyntax") |
| `BLACKLIST_CLAIM` | HARD_FACT_ERROR | error | true | `FORBIDDEN_PATTERNS` | Ví dụ `100%`, "trị khỏi hoàn toàn", "điều trị", "chuyên trị", "giảm ngay/liền"... (16 pattern) |
| `PERSONAL_EXPERIENCE` | SOFT_QUALITY | error | true | `_PERSONAL_EXPERIENCE` | "mình đã dùng/xài/thử", "da mình", "mình thấy ... sau khi dùng". Lý do: Syna là AI Idol, không có trải nghiệm cá nhân |
| `MISSING_SECTION` | SOFT_QUALITY | error | true | `_missing_ingredients` | `purpose==product_video`, sản phẩm có `ingredients` nhưng thiếu phần `[Ingredients]` có chữ. `section="ingredients"` |
| `LLM_AUDIT` | SOFT_QUALITY | error | theo LLM (mặc định true) | `_llm_audit_issues` | Kiểm duyệt viên LLM trả mảng JSON; không parse được -> 1 issue `LLM_AUDIT` với `reason` = 500 ký tự đầu của phản hồi |
| `VALIDATOR_UNAVAILABLE` | **TECHNICAL** | error | **false** | idem | `LLMProvider.invoke` ném lỗi: **fail closed**, không bao giờ coi là đạt |

Thứ tự: chạy bộ kiểm tra **tất định** trước; **chỉ khi không có lỗi tất định nào** mới gọi LLM audit (tiết kiệm token, tránh sửa chồng).
LLM audit JSON mong đợi: `[]` nếu sạch, hoặc `[{"section": "tên hoặc null", "original_text": "...", "reason": "...", "auto_fixable": true}]`. Parser cắt từ `[` đầu đến `]` cuối, bỏ rào ```json.
Lỗi kỹ thuật: `_validate_with_retries` thử lại tối đa `VALIDATOR_RETRIES = 3` lần khi có `VALIDATOR_UNAVAILABLE`. Nếu vẫn kỹ thuật: `result.technical_failure=True`, **không** đưa vào repair, đỗ `AUTO_REPAIR_EXHAUSTED`.

### 7.3 Quy tắc giá (grounded)

- Giá hợp lệ chỉ là **nguyên văn cụm chữ** từ `vi_money.price_phrases(pricing)` (ví dụ "hai trăm chín mươi chín nghìn đồng"); `_remove_valid_phrases` cắt các cụm đó khỏi văn bản đã bỏ dấu (`_fold_speech`), phần còn lại mà vẫn khớp `_MONEY_PATTERNS`/`_EXTENDED_AMOUNT` thì là `PRICE_MISMATCH`. Một cụm hợp lệ dính thêm từ chỉ số lượng thì tính là số khác.
- Phần trăm chỉ là "giá" khi đi với ngữ cảnh giảm ("giảm 20 phần trăm"); "Niacinamide mười phần trăm" không bị tính.
- `find_price_issues(text, pricing)` quét **từng section** (`split_labeled_sections`) nên có thể báo `section` chính xác để repair đúng đoạn. `find_price_violations` còn giữ làm wrapper trả danh sách thông điệp không trùng.
- **Quy tắc cũ đã bị XÓA:** "giá chỉ được nói ở [Product Intro]/[Wrap-up]" và danh sách cấm cứng `_ALWAYS_BANNED`/`_FLASH_ONLY`. Nay giá có thể nói ở bất kỳ phần nào (kể cả `[Hook]`), nhưng chỉ bằng cụm chữ cho sẵn. Prompt (`content_agent.py`, `script_validator._PRICE_RULES`) đã đồng bộ. Từ khan hiếm/khuyến mãi tách thành 2 nhóm với điều kiện riêng (khuyến mãi cần flash; khan hiếm cần flash có hạn kết thúc).
- `check_purpose_rules` (đường legacy, dùng khi `SELF_HEALING_ENABLED=false`) vẫn gọi `find_price_violations` trên toàn văn; trước kia là bước kiểm tra riêng cho vùng trước `[Price]`.

---

## 8. Auto-repair

`validate_and_repair_script(script, snapshot, purpose, max_attempts=None, on_event=None) -> ScriptValidationResult`

```
current = sanitize_external_retailer_mentions(script); version = 1
loop:
  issues = _validate_with_retries(current)         # tối đa 3 lần nếu VALIDATOR_UNAVAILABLE
  ghi history[{version, text, passed, issues}]
  không có error            -> passed=True, return
  có lỗi TECHNICAL          -> technical_failure=True, return (KHÔNG repair)
  repair_attempts >= limit  -> return (passed=False)
  repair_attempts += 1
  repaired = repair_script(current, [issue auto_fixable] or tất cả errors, snapshot, purpose)
  repair ném lỗi            -> entry["repair"]={"error": ...}, return (passed=False)
  entry["repair"]={"to_version": version+1, "issue_codes": [...]}
  current = sanitize(repaired); version += 1
```

- `limit = config.MAX_SCRIPT_REPAIR_ATTEMPTS` (env `AI_IDOL_MAX_SCRIPT_REPAIR_ATTEMPTS`, mặc định 3). Lần chạy tối đa = 1 lần validate đầu + 3 lần (sửa rồi validate lại).
- `engines/script_repair.py::repair_script(script, issues, snapshot, purpose)`:
  1. Chỉ lấy issue `severity=="error"`; rỗng thì trả nguyên văn.
  2. `_split_raw` tách script thành các block `{label, header, body}` **giữ nguyên văn từng dòng** (block đầu có thể không có header).
  3. Mỗi issue có `section` (qua `_canonical_label`) và block đó tồn tại -> nhóm vào section đó; **nếu bất kỳ issue nào không định vị được section** -> sửa **cả bài** bằng 1 lần gọi LLM (`_ask`, giữ dòng tiêu đề).
  4. Ngược lại: chỉ gọi LLM cho các section lỗi (prompt chứa lỗi + `_product_facts` gồm tên, thương hiệu, các dòng giá nguyên văn, thành phần, công dụng, cách dùng); các section khác **giữ nguyên byte**. Ghép lại bằng `_join`.
  5. Đầu ra LLM luôn đi qua `sanitize_external_retailer_mentions`.
- System prompt repair yêu cầu: chỉ nói giá bằng nguyên văn cụm giá, không nhắc sàn ngoài, không cam kết trị khỏi, không kể trải nghiệm cá nhân, không ký hiệu/emoji/số viết tắt.
- Ghi lịch sử: `pipeline._version_records` (lần đầu) và `script_recovery._tracking` (các lần sau) tạo `script_versions`, `validation_history`, `repair_history`, `repair_attempt`, `artifacts.script_version`.
- **Cờ rollback `SELF_HEALING_ENABLED`** (env `AI_IDOL_SELF_HEALING_ENABLED`, mặc định bật; tắt khi giá trị thuộc `0,false,no,off`): chỉ ảnh hưởng wrapper `validate_and_revise_script` (tuple `(passed, script, feedback)`) -> dùng `_legacy_validate_and_revise` (viết lại cả bài, 3 vòng). **Pipeline hiện tại gọi thẳng `validate_and_repair_script` nên cờ này KHÔNG tắt được self-healing trong `run_ai_idol_pipeline` hay `script_recovery`.** Cờ chỉ có hiệu lực với người gọi `validate_and_revise_script`. Muốn rollback thật cần thêm nhánh trong pipeline (xem mục 14).

---

## 9. Chuẩn hóa TTS

Commit `9978d04`. `core/tts_normalize.py::normalize_for_tts(text)` chỉ áp dụng cho **chuỗi đưa vào TTS** (`voice_engine.generate_voice_audio` gọi `tts.generate(normalize_for_tts(section), ...)`). `<wav>.timings.json` (dùng cho phụ đề và cắt clip) **giữ nguyên văn bản gốc**; có test `test_timings_keep_original_text`.

Thứ tự xử lý (các đoạn đã đọc được bị "bảo vệ" bằng ký tự private-use U+E100+ để các bước sau không đụng vào):

1. **Từ điển** `core/pronunciation.json` (load một lần khi import; thiếu/hỏng file -> `{}`): khóa dài trước, so khớp không phân biệt hoa thường, không dính chữ liền kề `(?<![\w])...(?![\w])`, khoảng trắng trong khóa khớp `\s+`. Ví dụ: `Uvinul T150 -> "U-vi-nun T một trăm năm mươi"`, `Mexoryl XS/SX`, `La Roche-Posay`, `CeraVe`, `Torriden`, `Niacinamide`, `Hyaluronic Acid`, `Avobenzone`, `Airlicium`, `UVA`, `UVB`, `TiO2`, `ZnO`, `B3`, `B5`, `BHA`, `AHA`, `PHA`.
2. `SPF\s?(\d+)(\+?)` -> "ét pi ép <số> [cộng]".
3. `PA(\+{1,4})` -> "pi ây <số> cộng".
4. Mã chữ + số bất kỳ `([A-Z]{1,3})(\d+)` (ví dụ `T150`, `B12`) -> đọc từng chữ cái theo bảng `_LETTERS` rồi số bằng `vi_money.number_words`.
5. Số: `_NUMBER` hỗ trợ `1.000.000` (dấu chấm nghìn), thập phân `0,5`/`0.5` ("không phẩy năm"), khoảng `5–10` ("năm đến mười"), kèm đơn vị: `%` "phần trăm", `đ/₫/VND` "đồng", `ml/l/g/mg/kg/mm/cm/m` -> "mi-li-lít", "lít", "gam", "mi-li-gam", "ki-lô-gam", "mi-li-mét", "xen-ti-mét", "mét".
6. Giải phóng ký tự bảo vệ thành cách đọc.

`pronunciation.json` mở rộng bằng cách thêm cặp `"Tên": "cách đọc"` (không cần sửa code). `providers/tts_provider.py` KHÔNG đổi trong đợt này: thứ tự provider `[TTS_PROVIDER, "piper", "edge", "gtts"]` (khử trùng), `TTS_PROVIDER` mặc định `piper`; Piper cần model `/opt/piper/vi_VN-vais1000-medium.onnx` (hoặc `AI_IDOL_PIPER_MODEL_PATH`), edge dùng giọng `vi-VN-HoaiMyNeural`.

Prompt kịch bản cũng đổi: không còn yêu cầu viết tách chữ ("A-H-A") mà "viết tên hoạt chất bình thường như trong dữ liệu" và ưu tiên 2-4 hoạt chất (`content_agent._TTS_RULES`), vì chuẩn hóa nay do `normalize_for_tts` đảm nhiệm.

---

## 10. Frontend (Studio)

Trang: `frontend/views/admin/ai_idol/index.php`. JS: `studio-core.js` (nhãn trạng thái, `api()` gắn CSRF), `studio-auto.js`, `studio-manual.js`, `studio-activity.js`, `studio-logic.js`. CSS: `ai-idol-studio.css`.

### 10.1 Tab Thủ công, bước 4 (hai nút)

| id | `data-approval` | Nhãn | Ý nghĩa |
|---|---|---|---|
| `manualCreateReview` | `MANUAL` | "👤 Tạo & duyệt kịch bản" (+ `<small>` mô tả; class `idol-btn secondary wz-create`) | AI viết + tự sửa xong thì dừng chờ duyệt |
| `manualCreateAuto` | `AUTO` | "⚡ Tạo & tự đăng" (class `primary`) | Tự duyệt, dựng, đăng |

Cả hai là `type="submit"` và `hidden` cho tới bước cuối. Handler submit: `event.submitter` thuộc `createButtons` và `data-approval === 'AUTO'` -> `AUTO`, **mọi trường hợp khác (kể cả nhấn Enter trong ô văn bản, `submitter` null) -> `MANUAL`**. Tức Enter mặc định là phương án an toàn (chờ duyệt). `buildCampaignPayload` thêm `configuration.approval_mode` chỉ khi thuộc `['AUTO','MANUAL']`. `setBusy(true, activeButton)` khóa cả hai nút, chỉ nút được bấm đổi chữ thành "Đang chuẩn bị…" và `dataset.label` lưu chữ gốc để khôi phục.

### 10.2 Tab Tự động

`fieldset#autoApproval` với 2 radio `name="autoApprovalMode"`: `AUTO` (mặc định `checked`, "⚡ Tự đăng luôn") và `MANUAL` ("👤 Duyệt kịch bản trước"). `studio-auto.js::approvalMode()` chỉ trả `AUTO|MANUAL`, giá trị khác -> `AUTO`. Body tạo run: `{brief, mode:'autopilot', approval_mode, idempotency_key:'studio:<ts>'}`. `process_agent_run` ghi `configuration.approval_mode` nên MANUAL có hiệu lực kể cả với `product_video`.

### 10.3 Thẻ trong cột hoạt động (`studio-activity.js`)

- `scriptReviewState(segment)`: kiểm tra `status`, `current_stage`, `script_status`. `AUTO_REPAIR_EXHAUSTED` hoặc `REJECTED_BY_VALIDATOR` -> `'recovery'`; `AWAITING_APPROVAL`, `AWAITING_SCRIPT_APPROVAL`, `PENDING_APPROVAL` -> `'approval'`; còn lại `null`.
- **Thẻ duyệt** (`.script-card.is-approval`): tiêu đề "Kịch bản V{n} — Đã đạt kiểm tra ✓", checklist 5 mục (Thông tin sản phẩm, Giá đã xác minh, Khuyến mãi hợp lệ, Claims đạt kiểm tra, Sẵn sàng đọc), dòng "Crew AI đã tự sửa N vấn đề" nếu có `repair_history`, preview, 4 nút: "✓ Chấp nhận & tạo video" (`data-approve-script`), "↻ AI viết lại ▾" (`data-script-toggle="rw"`), "✎ Chỉnh sửa" (`data-script-toggle="ed"`), "Hủy" (`data-script-cancel`).
- **Thẻ khôi phục** (`.script-card.is-recovery`): "⚠ Crew AI chưa thể hoàn tất kịch bản", "Đã tự sửa: min(attempts,3)/3 lần", danh sách issue (`code`, `reason`, trích `original_text` trong `<q>`; lấy từ phần tử cuối của `validation_history`, fallback `segment.issues`), nút: "↻ Thử lại" (`data-script-retry` -> `job_retry_repair`), "AI viết lại", "Chỉnh sửa", "Hủy job" (`job_cancel`, có `confirm('Hủy job này?')`).
- Menu viết lại: 6 lựa chọn dán nhãn `NATURAL "Tự nhiên hơn"`, `SHORTER "Ngắn gọn hơn"`, `STRONGER_SALES "Bán hàng mạnh hơn"`, `PROFESSIONAL "Chuyên nghiệp hơn"`, `HOOK_STRONGER "Hook hấp dẫn hơn"`, `LESS_JARGON "Giảm thuật ngữ"`, cộng ô nhập `CUSTOM` (maxlength 300; server cho phép đến 1000). `REWRITE_ENUM` = 6 mã + `CUSTOM` và phải trùng khóa của `REWRITE_INSTRUCTIONS` ở server; `rewritePayload` đổi mã lạ thành `NATURAL`. `CUSTOM` rỗng bị chặn phía client.
- `applyScriptResult`: `status==='QUEUED'` -> "Đang tạo giọng & video…" (success); `AUTO_REPAIR_EXHAUSTED` -> `message` hoặc "Crew AI chưa thể hoàn tất kịch bản." (không success); `repaired` -> "Crew AI đã tự sửa thêm, xem lại V{n}"; còn lại "Đã cập nhật kịch bản.". Lỗi HTTP mang `error.data.status === 'AUTO_REPAIR_EXHAUSTED'` cũng được xử lý như trên.
- Trạng thái UI (khóa nút khi đang gửi, mở/đóng menu, bản nháp) giữ trong `approvingJobs`, `toggled`, `scriptDrafts` (khóa `custom:<job>` cho ô CUSTOM), `scriptNotes`. Phần "Kịch bản bị từ chối" cũ (`feedback`) đã bỏ (`const feedback = ''`).
- Nhãn trạng thái mới trong `studio-core.js`: `VALIDATING_SCRIPT` "Đang kiểm tra kịch bản", `REPAIRING_SCRIPT` "Crew AI đang tự sửa", `AUTO_REPAIR_EXHAUSTED` "Cần xử lý kịch bản", `FINAL_CHECK` "Kiểm tra video cuối".
- CSS: `.wz-create` (nút nhiều dòng), media `max-width: 600px` xếp `.wz-nav` thành cột (`flex-direction: column-reverse`, nút rộng 100%), `.au-approval` (lưới 2 cột, 1 cột dưới 520px), `.script-card`, `.script-card.is-recovery` (viền `#d97706`), `.script-checklist`, `.script-issues`, `.script-preview`, `.script-edit[hidden]`, `.script-rewrite-menu[hidden]`.

---

## 11. Thay đổi theo commit

### 11.1 `9978d04` feat(tts): chuẩn hóa nhận biết thực thể

| File | Thay đổi | Lý do |
|---|---|---|
| `core/tts_normalize.py` (mới) | `normalize_for_tts` như mục 9 | TTS đọc sai T150, SPF50+, tên hoạt chất, đơn vị |
| `core/pronunciation.json` (mới) | 21 mục từ điển cách đọc | Dữ liệu cách đọc, sửa không cần code |
| `engines/voice_engine.py` | gọi `normalize_for_tts(section)` trước `tts.generate` (+import) | Chỉ áp cho âm thanh, timings giữ text gốc |
| `tests/test_tts_normalize.py` (mới) | 5 test (từ điển, SPF/PA, Uvinul, số, mã chung/không đổi, timings giữ nguyên) | |

### 11.2 `ca3a4a6` feat(publish): publish idempotent

| File | Thay đổi | Lý do |
|---|---|---|
| `core/publications.py` | `publish_key = "<job_id>:<product_id>:<script_version>"`; `_script_version`; `_ensure_publish_key_index`; `_publish` kiểm tra các bản ghi đã có (`publish_key` None hoặc trùng thì bỏ qua) và upsert với filter `{video_id, publish_key: {$in:[key, None]}}` + `$setOnInsert` | Video render lại từ kịch bản mới (version khác) được đăng bản mới; chạy lặp cùng version thì không tạo trùng; bản ghi cũ không có key (admin/legacy) vẫn không bị đụng |
| `tests/test_publications.py` | +17 dòng test cho key/idempotency | (một phần test file này cần celery, xem 12.3) |

### 11.3 `20aba4f` feat(syna): self-healing script pipeline + AUTO/MANUAL

| File | Thay đổi | Lý do |
|---|---|---|
| `engines/script_validator.py` | `ValidationIssue`, `validate_script`, `_deterministic_issues`, `_llm_audit_issues` (JSON), `validate_and_repair_script`, `ScriptValidationResult`, `_validate_with_retries`; giữ `validate_and_revise_script` (wrapper) + `_legacy_validate_and_revise`; prompt `_PRICE_RULES` mới | Validator có cấu trúc, fail-closed, sửa theo đoạn |
| `engines/script_repair.py` (mới) | `repair_script` theo đoạn | Sửa đúng chỗ, giữ nguyên phần còn lại |
| `core/script_recovery.py` (mới) | `approve`, `retry_repair`, `rewrite`, `cancel`, `_claim/_release`, `_tracking`, `_park_*`, `_approve_and_resume` | Cho nhân viên xử lý script kẹt, atomic, chống bấm đúp |
| `core/script_text.py` | `find_price_issues` (có `section`, mã lỗi), `_scan_price_text`, `_SCARCITY`/`_PROMO` thay `_ALWAYS_BANNED`/`_FLASH_ONLY`; `find_price_violations` thành wrapper | Bỏ quy tắc "giá trước [Price]"; cho phép giá ở mọi phần nhưng phải đúng cụm |
| `core/pipeline.py` | `should_auto_approve_script`, nhánh `AUTO_REPAIR_EXHAUSTED` (không fail job), `product_video_auto`, `_version_records`, `final_machine_check` (+ gọi trong `_complete_video_job`), checkpoint tracking | Luồng self-healing, cổng duyệt AUTO/MANUAL, kiểm tra cuối |
| `app.py` | Bỏ route approve inline; thêm `/script/approve`, `/script/retry-repair`, `/script/rewrite`, `/jobs/<id>/cancel` qua `_recovery_response` | Gom logic vào `script_recovery` |
| `core/campaigns.py` | `APPROVAL_MODES`; validate/chuẩn hóa `configuration.approval_mode`; ánh xạ `AUTO_REPAIR_EXHAUSTED` -> segment FAILED/SKIPPED trong `update_campaign_from_job` | |
| `core/watchdog.py` | Bỏ qua job `AUTO_REPAIR_EXHAUSTED` | Job đỗ chờ người, không bị reaper fail |
| `core/agent_runs.py`, `agents/schemas.py`, `agents/service.py` | Truyền `approval_mode` từ UI qua `overrides` -> `AgentBrief.approval_mode` -> `script_approval_mode` | Tab Tự động chọn AUTO/MANUAL |
| `engines/content_agent.py` | Prompt: giá nói tự nhiên ở mọi phần bằng cụm cho sẵn; `_SYNA_RULE` (không kể trải nghiệm cá nhân); `_TTS_RULES` đổi (tên hoạt chất viết thường, 2-4 hoạt chất) | Khớp quy tắc validator mới |
| `config.py` | `MAX_SCRIPT_REPAIR_ATTEMPTS`, `SELF_HEALING_ENABLED` | |
| `backend/.../AdminAiIdolController.php`, `routes/ai_idol.php` | 3 action + 3 route mới (6.1) | Proxy từ trình duyệt |
| Tests | Mục 12 | |

### 11.4 `585cffc` feat(studio): UI

| File | Thay đổi |
|---|---|
| `frontend/views/admin/ai_idol/index.php` | `fieldset#autoApproval` (2 radio); thay `#manualCreate` bằng `#manualCreateReview` + `#manualCreateAuto` |
| `studio-auto.js` | `approvalMode()` + gửi `approval_mode` |
| `studio-manual.js` | `APPROVAL_MODES`; payload `configuration.approval_mode`; `createButtons`; submit theo `event.submitter`; `setBusy(busy, activeButton)` |
| `studio-core.js` | 4 nhãn trạng thái mới |
| `studio-activity.js` | Thẻ duyệt/khôi phục, `scriptAction`, `applyScriptResult`, xuất helpers cho Node test |
| `css/ai-idol-studio.css` | +36 dòng (mục 10.3) |
| `tests/studio-activity.test.js`, `tests/studio-manual.test.js` | +28 / +10 dòng |
| `e2e/syna-self-healing.e2e.js` (mới) | Spec Playwright route-mocked |

---

## 12. Kiểm thử

### 12.1 Test mới/đổi (đều trong `ai-idol-service/tests/` trừ ghi chú)

| File | Phủ |
|---|---|
| `test_approval_mode.py` (mới) | `should_auto_approve_script`; `create_campaign` chuẩn hóa/từ chối enum sai; pipeline: AUTO không bao giờ chờ, MANUAL dừng kể cả `product_video`, vắng mode giữ hành vi cũ |
| `test_repair_exhausted.py` (mới) | Hết lượt sửa -> đỗ không chạy media; watchdog bỏ qua; `final_machine_check` (thiếu video, `_complete_video_job` fail `FINAL_CHECK`) |
| `test_script_recovery_api.py` (mới) | approve (sửa tay PASS -> resume 1 lần, bấm đúp -> 409; repaired -> AWAITING; fail -> EXHAUSTED không 422), retry-repair (manual -> awaiting, auto -> resume, sai trạng thái -> 409), rewrite (enum, LLM lỗi -> đỗ), cancel |
| `test_script_repair.py` (mới) | Chỉ đoạn lỗi đổi; `section=None` sửa cả bài; dừng sau 3 lần; sửa rồi qua; lỗi kỹ thuật được retry chứ không repair |
| `test_validation_contract.py` (mới) | Mã lỗi (`PRICE_MISMATCH`, `SCARCITY`, `PROMO`, `PERSONAL_EXPERIENCE`, retailer/blacklist, LLM fail kỹ thuật, JSON audit, parse hỏng, `MISSING_SECTION`) |
| `test_tts_normalize.py` (mới) | Mục 9 |
| `test_price_rules.py` (đổi) | Cập nhật theo quy tắc mới, thêm ca "giá ở Hook/CTA", "phần trăm thành phần không phải giá" |
| `test_publications.py` (+17 dòng) | publish_key |
| Đổi nhỏ: `test_autopilot_service.py`, `test_body_clip.py`, `test_campaign_recovery.py`, `test_command_query_service.py`, `test_e2e_flow.py`, `test_script_purpose.py`, `test_syna.py`, `test_video_price_claim.py` | Điều chỉnh theo contract mới |
| `frontend/public/assets/js/tests/studio-activity.test.js`, `studio-manual.test.js` | Thẻ duyệt/khôi phục, `rewritePayload`, payload `approval_mode` |

### 12.2 Kết quả tôi tự chạy (2026-10-16, Windows, `.venv`)

- Các file test mới nêu trên (trừ phần cần celery): **91 pass**; 12 fail đều ở `test_publications.py` do thiếu celery.
- Toàn bộ `pytest tests` (bỏ qua `test_campaign_recovery.py` và `test_e2e_flow.py` vì lỗi collect do celery): **769 passed, 36 failed, 1 skipped**. 35/36 fail là `ModuleNotFoundError: No module named 'celery'`. 1 fail còn lại: `test_purpose.py::CreateCampaignPurposeTests::test_two_unscheduled_product_video_campaigns_are_distinct` (`created` là False). Test dùng `FakeDb`; nghi do idempotency key dựa trên `utcnow().isoformat()` bị trùng khi đồng hồ Windows độ phân giải thấp, CHƯA XÁC MINH. File này không thuộc 4 commit; nó có từ `9c04f59`.
- `node --test frontend/public/assets/js/tests/*.test.js`: **109/109 pass**.
- `php -l` 3 file PHP đã đổi: sạch.
- Ghi chú an toàn: tôi chạy toàn bộ `ai-idol-service/tests` một lần. Các test này dùng `FakeDb`; tôi không thấy test nào trong bộ này chạm Mongo thật, nhưng CHƯA kiểm toán từng file. **Không** chạy `backend/tests`.

### 12.3 Lỗi có sẵn / môi trường

- Thiếu `celery` trong `.venv` cục bộ khiến `test_campaign_recovery.py`, `test_e2e_flow.py` không collect được, và các test import `app`/`tasks` (publications, media_publisher, platform_approval, product_metrics, product_videos, structured_query endpoint...) fail. **Các test liên quan celery chưa được chạy ở máy này**, do đó phần thay đổi trong `test_campaign_recovery.py`, `test_e2e_flow.py` và `test_publications.py` (HookTests/RouteTests) chưa được xác nhận bằng chạy thật. Cách xử lý: cài celery vào venv (yêu cầu của người dùng khi nào được phép) hoặc chạy trong container `ai-idol-service`.

### 12.4 E2E `e2e/syna-self-healing.e2e.js`

- Cách chạy: Playwright MCP `browser_run_code_unsafe` với `{ filename: 'e2e/syna-self-healing.e2e.js' }`, trình duyệt đã đăng nhập admin local (seed bằng `scripts/seed_admin_staff.php`) tại `http://localhost:8080`. Hàm cần `window.confirm` được stub (làm inline trong spec). Trả về `{results, shots}`; ảnh chụp lưu ở `report/syna-self-healing-e2e/` (desktop-01..07, mobile-01..07).
- **Mock toàn bộ**: `page.route` cho `admin_ai_idol_create_campaign`, `_campaigns`, `_agent_runs`, và các route job (`approve_script`, `job_rewrite`, `job_retry_repair`, `job_cancel`). Pipeline Flask/Celery/Mongo thật **không** được chạy.
- Phủ: radio Tự động AUTO/MANUAL (mặc định AUTO, body `approval_mode`), bước 4 Thủ công có 2 nút, mô tả phụ, không bị cắt chữ, không tràn ngang, nút bị khóa khi pending, body `configuration.approval_mode` đúng cho cả hai nút; thẻ duyệt (tiêu đề V3, checklist, 4 nút, rewrite -> `instruction: SHORTER`, sửa -> `approve_script` có `script_text`); thẻ khôi phục (tiêu đề, "Đã tự sửa: 2/3", danh sách issue, 4 nút, Thử lại -> `job_retry_repair`, rewrite `STRONGER_SALES`, sửa -> `approve_script`, Hủy job -> `job_cancel`); không còn chữ "Kịch bản bị từ chối"; không có lỗi console. Chạy ở desktop và mobile.
- Kết quả: tôi **chưa chạy lại** spec trong phiên này (Playwright MCP hiện không kết nối được). Có sẵn ảnh chụp từ lần chạy trước của tác giả ở `report/syna-self-healing-e2e/`; đây là thư mục chưa được commit (untracked). Dữ liệu mock trong spec dùng mã issue `PRICE_UNVERIFIED` — mã này **không tồn tại** trong validator thật (mã thật là `PRICE_MISMATCH`...). Chỉ là dữ liệu giả cho UI.
- **Pipeline thật chưa được E2E** (xem mục 14).

---

## 13. Cấu hình / biến môi trường (chỉ tên và mặc định)

| Biến | Mặc định | Tác dụng |
|---|---|---|
| `AI_IDOL_MAX_SCRIPT_REPAIR_ATTEMPTS` | `3` | Số lần sửa tối đa mỗi lượt validate (`config.MAX_SCRIPT_REPAIR_ATTEMPTS`) |
| `AI_IDOL_SELF_HEALING_ENABLED` | `true` | `false/0/no/off` -> `validate_and_revise_script` dùng đường legacy (xem giới hạn ở mục 8) |
| `AI_IDOL_AGENT_ENABLED` | `true` | Tắt thì mọi route `/agent/*` trả 503 |
| `AI_IDOL_AGENT_ALLOW_AUTO_PUBLISH` | `false` | Nếu tắt, run có `autonomy_policy=auto_publish` bị chặn thành `NEEDS_INPUT` (compose cũng đặt `"false"`). Đây là cổng của Agent, khác với `auto_publish_video` lên trang sản phẩm (không phụ thuộc cờ này) |
| `AI_IDOL_SCRIPT_COUNCIL_ENABLED` / `_URL` / `_TIMEOUT` | `true` / `http://ai-idol-agent-service:5013` / `240` | Script Council |
| `AI_IDOL_TTS_PROVIDER` | `piper` | Provider ưu tiên; fallback `piper`, `edge`, `gtts` |
| `AI_IDOL_PIPER_MODEL_PATH`, `AI_IDOL_PIPER_CONFIG_PATH`, `AI_IDOL_PIPER_SENTENCE_SILENCE` (0.2), `AI_IDOL_SECTION_PAUSE_MIN/MAX` (0.35/0.8) | | Giọng và nhịp |
| `AUDIO_PIPELINE_VERSION` (hằng số `"3"` trong `config.py`, không phải env) | | Đổi để buộc render lại âm thanh |
| `AI_IDOL_STALE_JOB_MINUTES` (45), `_STALE_STARTING_MINUTES` (3), `_STALE_ASSEMBLING_MINUTES` (60), `AI_IDOL_RENDER_WAIT_CUTOFF_MINUTES` (10) | | Watchdog |
| `AVATAR_PROVIDER`, `AI_IDOL_AVATAR_SERVICE_URL`, `SYNA_RENDER_URL` | | Avatar/Syna renderer |
| `AI_IDOL_ENDPOINT` (phía PHP) | `http://ai-idol-service:5003` | Địa chỉ Flask |
| `MONGO_URI`, `MONGO_DB`, `AI_IDOL_REDIS_URL`, `AI_IDOL_INTERNAL_TOKEN`, `GROQ_API_KEY`, `VALKEY_URL` | (bí mật/kết nối, không nêu giá trị) | Không in ra log/báo cáo |
| `WEB_PORT` | `80` trong compose; `8080` ở `.env` cục bộ | Cổng nginx host |

Rollback nhanh khi nghi ngờ self-healing: đặt `AI_IDOL_MAX_SCRIPT_REPAIR_ATTEMPTS=0` (vòng lặp thoát ngay sau lần validate đầu, job lỗi sẽ đỗ `AUTO_REPAIR_EXHAUSTED` để người xử lý, không sửa tự động). Cờ `SELF_HEALING_ENABLED=false` không đủ như đã nêu.

---

## 14. Rủi ro, nợ kỹ thuật, việc còn dở

2. **Test celery chưa chạy** ở máy này (mục 12.3). Cần chạy trong môi trường có celery trước khi coi bộ thay đổi là xanh.
3. **Pipeline thật chưa E2E.** E2E hiện chỉ kiểm giao diện với route mock. Chưa có lần chạy thật nào: campaign -> Celery -> LLM -> repair -> TTS -> Syna -> READY -> đăng trang sản phẩm. Trạng thái: `E2E NOT RUN` cho đường backend thật.
5. **`ok:true` kèm `status:AUTO_REPAIR_EXHAUSTED`** (mục 6.4): quirk có chủ đích, mọi client phải rẽ theo `status`.
6. **`SELF_HEALING_ENABLED` không tắt được self-healing trong pipeline/recovery** (mục 8). Nợ: thêm nhánh rollback thật hoặc đổi tên cờ.
7. **`retry_campaign` và `/jobs/<id>/retry` không xử lý job `AUTO_REPAIR_EXHAUSTED`** (chỉ reset job `FAILED`). Đường xử lý đúng là 4 endpoint recovery; đừng gom vào retry chung mà không cập nhật.
8. **Hai trường `script_version`** (job và artifacts) dễ nhầm (mục 5.1).
9. **LF/CRLF.** Repo trên Windows, nhiều file có chênh lệch kết thúc dòng; commit chỉ nên chứa thay đổi nội dung. `git diff --check` nên chạy trước khi commit.
10. **GitNexus `detect_changes` mức HIGH** theo lời người bàn giao (tôi chưa chạy lại GitNexus trong phiên này, nên chưa xác minh). Chỉ số HIGH nhiều khả năng do `core/pipeline.py`, `core/campaigns.py`, `app.py` nằm trên nhiều execution flow. Trước khi sửa tiếp các file này phải chạy impact upstream và báo cáo.
11. **Hoãn có chủ đích (chưa làm, chỉ xác nhận chúng chưa tồn tại trong code):** `RISK_BASED` (chế độ duyệt dựa trên mức rủi ro; hiện chỉ có AUTO|MANUAL, `APPROVAL_MODES = ("AUTO","MANUAL")`); TTS đa ngôn ngữ / provider A-B (chỉ có `TTS_PROVIDER` đơn, danh sách fallback cố định); hook cho "script planner" (chưa có); vòng đời giá cũ (stale-price) mở rộng (hiện có `price_claim_state`/ẩn video khi giá đổi theo spec 2026-10-16 nhưng chưa có luồng tự render lại; spec ghi rõ "nút tạo lại video bằng một cú bấm" ngoài phạm vi). Tôi không tìm thấy chi tiết thiết kế cho từng mục trong repo; mô tả ở đây dựa trên ghi chú bàn giao và kiểm tra rằng code chưa có.
12. **Dọn dẹp:** `app.py` vẫn import `check_blacklist, check_purpose_rules` (không còn dùng sau khi gỡ route inline; xác nhận bằng grep trước khi xóa). `report/product-video-e2e/*.png` và `report/syna-self-healing-e2e/` đang untracked, đừng commit cùng code.
13. **Giới hạn `callFlaskAPI`:** body `{}` không được gửi; mọi endpoint nhận body tùy chọn phải chịu được "không có JSON".

### Kế hoạch rollback

- Mức cấu hình: `AI_IDOL_MAX_SCRIPT_REPAIR_ATTEMPTS=0` (mục 13) để dừng tự sửa; job lỗi đỗ `AUTO_REPAIR_EXHAUSTED` chờ người.
- Mức code: các commit độc lập theo chức năng: `git revert 585cffc` (UI), `git revert 20aba4f` (self-healing; kéo theo UI vì UI gọi route mới, nên revert UI trước), `git revert ca3a4a6` (publish key), `git revert 9978d04` (TTS). Dữ liệu đã ghi (`artifacts.script_versions`, `publish_key`, job `AUTO_REPAIR_EXHAUSTED`) là trường mới/giá trị mới: code cũ bỏ qua các trường lạ, nhưng job đang `AUTO_REPAIR_EXHAUSTED` sẽ không có đường xử lý trong code cũ, hãy chuyển chúng về `FAILED` thủ công trước khi revert. Index `uniq_publish_key` là partial nên bản ghi không có key không bị ảnh hưởng.
- Không chạy lệnh git phá hủy; không `git stash` ở cây dùng chung (xem mục 15).

---

## 15. Quy tắc khi sửa tiếp (theo AGENTS.md)

- Đọc `AGENTS.md`, `git status`, tài liệu liên quan trước khi sửa. Mã nguồn, manifest, compose là nguồn sự thật nếu lệch với tài liệu.
- **GitNexus:** trước khi sửa symbol có sẵn phải chạy `impact({target, direction:"upstream"})` (MCP) hoặc `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .`; báo caller/process/rủi ro. `risk: UNKNOWN` nghĩa là chưa giải được, phải xác nhận bằng tìm kiếm văn bản; **cảnh báo người dùng** khi HIGH/CRITICAL. Không thay symbol bằng find-replace, dùng `rename`. Trước commit chạy `detect_changes({scope:"all"})`; `partial/truncated` không phải kết quả sạch. Index cũ: `node .gitnexus/run.cjs analyze --index-only`.
- Giữ ranh giới: UI trong `frontend`, controller/proxy trong `backend`, logic AI trong `ai-idol-service`; không cho PHP gọi sâu vào nội bộ AI. Giữ tương thích API/auth/response.
- Không lộ bí mật: không đọc/ghi giá trị `.env`, token, chuỗi kết nối, dữ liệu cá nhân vào code/log/báo cáo/commit.
- Không commit dataset sinh ra, uploads, build, index cục bộ, file runtime tạm.
- Kiểm thử: PHP `php -l`; Python `pytest` trong venv của dự án; JS `node --test` + `node --check`; nếu đổi hợp đồng API thì thêm test tích hợp/contract; hành vi người dùng thấy được phải kiểm bằng E2E thật (Playwright) hoặc ghi `E2E NOT RUN` kèm lý do. Không nói pass nếu lệnh chưa chạy thành công.
- **`backend/tests/*` ghi DB thật: không chạy hàng loạt.** Giữ MongoDB Atlas, không quay về Mongo local.
- Công cụ: sửa file bằng công cụ sửa file, không dùng chuyển hướng shell để ghi đè mã nguồn; ưu tiên `rg`; không dùng lệnh git phá hủy; **không `git stash` trong cây làm việc dùng chung** (có phiên khác cùng thao tác; tài liệu `docs/superpowers/plans/2026-10-16-stash-merge-audit.md` ghi hậu quả của stash).
- Quy trình: chẩn đoán yêu cầu cụ thể, nêu phạm vi/tiêu chí/rủi ro/kiểm chứng trước; với tính năng nhiều bước dùng brainstorming + writing-plans; thay đổi nhỏ nhất đủ an toàn; chạy `git diff --check`; chỉ commit khi hoàn tất một mốc đã kiểm thử và khi được yêu cầu; không push/deploy/migrate dữ liệu production nếu không được yêu cầu.
- Báo cáo cuối: file đã đổi, lệnh và kết quả, bước bỏ qua, rủi ro, bước tiếp theo.

### Tài liệu tham chiếu trong repo

`docs/superpowers/specs/2026-10-14-product-video-design.md` (video lên trang sản phẩm, 3 chế độ `purpose`), `2026-10-15-ai-idol-studio-redesign-design.md` (Studio 2 chế độ), `2026-10-16-price-aware-product-video-design.md` (giá có hạn sử dụng, `product_snapshot.pricing`, `price_claim`); plans cùng tên trong `docs/superpowers/plans/`; `ai-idol-service/README.md` (vận hành).

## Sửa sau bàn giao

- Agent MANUAL + `product_video`: `ai-idol-service/agents/service.py` ghi thêm `configuration.approval_mode`. Frontend: dòng "Duyệt kịch bản" ở tóm tắt bước 4 (`studio-manual.js`) và câu ý định (`INTENT_TAILS` trong `studio-logic.js`) không còn khẳng định tự đăng, trung lập theo hai nút cuối.
- Test: `tests/test_autopilot_service.py` (assert `approval_mode` MANUAL/AUTO), `tests/test_approval_mode.py` (config do Agent ghi: MANUAL dừng, AUTO tiếp tục với product_video), `frontend/public/assets/js/tests/studio-manual.test.js`, `studio-logic.test.js`.
