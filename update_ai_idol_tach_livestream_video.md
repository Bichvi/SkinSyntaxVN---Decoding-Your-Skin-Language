# AI Idol: tách Livestream và Video sản phẩm

## Vì sao bỏ chế độ gộp
Chế độ "Livestream + video sản phẩm" (`live_and_product`) dùng một kịch bản live rồi cắt phần trước lời kêu gọi chốt đơn làm clip trang sản phẩm. Kịch bản live phải nói giá/khuyến mãi theo kiểu live nên clip cắt ra khó kiểm soát; video sản phẩm cần kịch bản riêng, độ dài dài và không phụ thuộc giờ live.

## Hai chế độ mới (bước 2 của wizard)
- **Video sản phẩm** (`product_video`, mặc định): không đổi.
- **Livestream** (`live_only`): phát trực tiếp, không tự đăng lên trang sản phẩm.
  - Hộp tick (mặc định tắt) "Tạo thêm video sản phẩm cho các sản phẩm này": backend tạo thêm một chiến dịch `product_video` độc lập (kịch bản riêng, độ dài Long, không chờ live, không lịch/kênh phát), cùng sản phẩm, `approval_mode` giữ nguyên, phong cách chỉ dành cho live (vd. `closing`) bị loại.

## API
- `POST /api/ai-idol/campaigns` nhận thêm `also_product_video` (chỉ tính khi đúng `true` và `purpose = live_only`; còn lại bỏ qua).
- Phản hồi có thêm `companion_campaign_id` (id hoặc `null`, cả khi tạo mới lẫn trùng lặp). Mã trạng thái giữ nguyên.
- Hai chiến dịch liên kết: live có `companion_campaign_id`, video có `companion_of`. Tạo video thất bại thì live vẫn được tạo (ghi log, không có liên kết).
- Gọi lặp cùng `idempotency_key` không tạo thêm video; khóa của video = sha256(`<khóa live>|product_video_companion`).
- Không có `purpose` nhưng có `scheduled_at` giờ mặc định là `live_only` (trước là `live_and_product`); `infer_purpose` của agent cũng vậy. Agent không đặt `also_product_video`.

## Tương thích cũ
`live_and_product` vẫn được API chấp nhận và các chiến dịch đã lưu vẫn chạy (purpose.py, pipeline, validator, body_clip, media_publisher, agent, `PURPOSE_LABELS`/`PURPOSE_STEPS` giữ nguyên). Chỉ là UI không còn đưa ra và không còn là mặc định. Nhãn cũ hiển thị "Livestream + video sản phẩm (kiểu cũ)". Wizard luôn gửi `product_video` hoặc `live_only`.

## File thay đổi
- ai-idol-service: `core/campaigns.py`, `app.py`, `agents/intent_parser.py`
- Test: `tests/test_campaign_companion.py` (mới), `test_agent.py`, `test_purpose.py`, `test_autopilot_service.py`, `test_autopilot_no_stop.py` (kỳ vọng mặc định `live_only`)
- Frontend: `views/admin/ai_idol/index.php`, `public/assets/js/studio-manual.js`, `studio-logic.js`, `public/assets/css/ai-idol-studio.css`, `tests/studio-manual.test.js`, `tests/studio-logic.test.js`

## Kiểm thử đã chạy
- `node --test frontend/public/assets/js/tests/*.test.js`: 154 pass, 0 fail.
- `php -l frontend/views/admin/ai_idol/index.php`: không lỗi.
- pytest ai-idol-service (env DB/khóa API đặt rỗng): toàn bộ 999 pass.
- Chưa chạy E2E trình duyệt.
