# AI Idol: xem lại phiên live Syna đã kết thúc (video trên R2)

Ngày: 2026-11-11 · Nhánh: đã fast-forward vào `syna_10_11` (commit `4f61481..7bac047`) · Spec: [`docs/superpowers/specs/2026-11-10-ai-idol-live-replay-design.md`](docs/superpowers/specs/2026-11-10-ai-idol-live-replay-design.md) · Kế hoạch: [`docs/superpowers/plans/2026-11-10-ai-idol-live-replay.md`](docs/superpowers/plans/2026-11-10-ai-idol-live-replay.md)

## Vấn đề

Phiên Syna (chiến dịch AI Idol) không bao giờ hiện trong "Các phiên phát khác" trên `?r=live`, vì danh sách đó chỉ đọc `phien_live`. Hết giờ phát thì khung Syna biến mất, link video trả 404, và file video gốc bị xóa ngay khi các clip sản phẩm đã lên R2.

## Đã làm

- **ai-idol-service**
  - `core/purpose.campaign_wants_replay`: chiến dịch có phát (`live_only`, `live_and_product` hoặc kiểu cũ) và có kênh `internal`.
  - `engines/media_publisher.release_master_video`: điểm duy nhất được xóa file gốc. Chỉ xóa khi đã có bản xem lại trên R2 (nếu chiến dịch cần) **và** mọi clip trang sản phẩm đã lên R2. Hàm được gọi ở mọi đường hoàn tất: đăng clip, thử lại clip, tải bản xem lại.
  - `engines/replay_publisher.py` (mới): tải ảnh bìa rồi video gốc lên R2 ở `live-replays/<campaign_id>-<token>.jpg|.mp4`, ghi `replay.{video_url, poster_url, keys, duration, uploaded_at, attempts, last_error}`.
    - Có khóa nhận việc 30 phút để hai worker không tải trùng.
    - Thử lại tối đa 5 lần, lỗi được che khóa bí mật bằng `_scrub`. Hàm không bao giờ ném lỗi ra ngoài.
    - Lệnh chạy một lần: `python -m engines.replay_publisher backfill [--apply]`.
  - `broadcast_engine`: khi phiên kết thúc thì gửi task `ai_idol.publish_campaign_replay` (hàng `media`).
  - Task định kỳ `ai_idol.retry_product_video_publish` (10 phút) thử lại cả bản xem lại: bỏ qua phiên đã mất file gốc, và tính cả lần thử thất bại vào giới hạn mỗi lượt.
- **PHP / giao diện**
  - `backend/app/services/AiIdolReplaySessions.php` (mới, hàm thuần): đổi chiến dịch thành thẻ phiên `id = ai-<id>`, trạng thái `replay_ready`, chỉ nhận URL http(s).
  - `LiveController::findAiIdolReplays`: lấy tối đa 30 chiến dịch có `replay.video_url` (`$exists: true`), mới nhất trước, rồi nối vào danh sách phiên.
  - `live.php`: phiên `ai-…` không gọi `api_live_products` mỗi 5 giây.
  - `studio-activity.js`: xem trước trong Studio dùng `replay.video_url` khi đã có (file gốc trên máy đã bị xóa).

## Kết quả kiểm tra (2026-11-11)

- pytest ai-idol-service: **1029 passed** (gồm cả thay đổi chưa commit của phiên khác trong thư mục chính).
- `node --test frontend/public/assets/js/tests/*.test.js`: **155 pass**.
- `php backend/tests/ai_idol_replay_sessions_test.php`: tất cả PASS (test thuần, không chạm DB). `php -l` sạch trên 3 file PHP.
- GitNexus `detect-changes --scope compare --base-ref d7f21c9`: **Risk HIGH**, 6 luồng bị ảnh hưởng.
  - Số liệu này tính gộp cả thay đổi chưa commit của phiên khác.
  - 6 luồng đều đi từ `LiveController::index` (trang /live) sang các hàm tìm sản phẩm của `SanPham.php`. Phần thay đổi ở đây chỉ nối thêm phiên xem lại và gọi thêm một lần `findManyByIds`; E2E bên dưới đã kiểm luồng này.
- Build lại `ai-idol-service`, `ai-idol-worker`, `ai-idol-control-worker`, `ai-idol-broadcast-worker`, `ai-idol-beat` lúc không có phiên nào LIVE.
- `backfill --apply`: 3 phiên `6ace67e7…`, `6ace7dba…`, `6ace4166…` đã lên R2 (41–50 s), không lỗi, file gốc trên máy đã được dọn.
- **E2E (Playwright MCP, `http://localhost:8080/index.php?r=live`): PASS**
  - 3 thẻ Syna có nhãn "Xem lại", ảnh bìa từ R2. 9 phiên thường vẫn còn.
  - Lọc "Xem lại" ra đúng 3 thẻ.
  - Bấm "Xem lại bản ghi": `#replayVideoPlayer` tải video R2, tua tới giây 10 được. Chat khóa, nút mua tắt.
  - 40 giây trên trang không có lần gọi `api_live_products` nào. Console không có lỗi.
  - `?r=live&id=ai-000…` tự quay về phiên đầu tiên, không lỗi.

## Hạn chế đã biết

- Phiên `6af1f36e…` ("Syna Live routine thử", 2026-11-10) đã mất file gốc trước khi có tính năng này, nên không xem lại được.
- Bản xem lại lưu vĩnh viễn và mọi phiên nội bộ đã kết thúc đều hiện công khai. Chưa có nút ẩn/xóa trong admin.
- Studio chưa hiện `replay.last_error`. Lỗi tải bản xem lại hiện chỉ thấy trong log hoặc DB.
- Việc tải lên R2 chạy trên worker `media` (concurrency 1), nên video live dài có thể làm việc dựng video kế tiếp phải chờ.
- Python coi chiến dịch thiếu `platforms` là `internal`, còn PHP chỉ lọc `platforms: 'internal'`. Hiện cả 5 chiến dịch đã kết thúc đều có trường này.
- Huy hiệu kết nối "Chưa bắt đầu phát" vẫn hiện cạnh "Xem lại" trên trình phát. Đây là hành vi có sẵn, bản xem lại của phiên thường cũng vậy.

## Biến môi trường

Không thêm biến mới. Dùng bộ `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_PUBLIC_BASE_URL` sẵn có. Thiếu R2 thì bản xem lại không được tạo, file gốc được giữ lại và lỗi ghi vào `replay.last_error`.
