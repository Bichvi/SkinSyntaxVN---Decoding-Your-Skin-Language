# Chatbot đặt hàng: chặn bot bịa đơn + OTP email khi xác nhận đơn

Ngày: 2026-11-16 · Nhánh: `syna_10_11` · Trạng thái: **đã code, unit test PASS, chưa commit, E2E chưa chạy**.
Kế hoạch: [`docs/plans/2026-11-16-chat-order-otp.md`](docs/plans/2026-11-16-chat-order-otp.md). Không chứa bí mật.

## 1. Lỗi gặp phải

Khách hỏi mua sữa rửa mặt và kem chống nắng, gõ "ok cho tôi đặt hàng" rồi "tất cả", rồi "ok". Bot trả lời
"Mình đã ghi nhận đơn hàng của bạn nhé! … sẽ gửi tin xác nhận cùng mã vận đơn", nhưng **không có đơn, không có thẻ đơn nháp**.

Nguyên nhân (đối chiếu log `skinsyntax-ai` lúc 10:22 và code):
- Agent đã gọi `get_checkout_defaults` và hỏi "giữ địa chỉ / dùng COD không?", nhưng trạng thái hội thoại chỉ có `none`
  hoặc `draft_open:*`. Câu "ok" vì vậy được bộ phân loại LLM xếp vào nhãn đi luồng cũ (`legacy_node` → `xu_ly_cau_hoi`).
- Chatbot cũ không có tool đặt hàng, đọc lịch sử chat rồi bịa ra câu xác nhận.
- Bộ lọc câu sai sự thật (`_guard_text`) chỉ chạy trên câu trả lời của agent, và mẫu `_ORDER_CLAIM_RE` không bắt "ghi nhận đơn".
- Cùng lúc, Groq bị giới hạn tốc độ và model dự phòng trả `InvalidRequestError`.

## 2. Đã sửa (Flask, `ai-service-flask/agent_graph/`)

| File | Thay đổi |
|---|---|
| `tools.py` | `get_checkout_defaults` chạy xong mà chưa có đơn nháp → `pending = "checkout_collecting"` |
| `router.py` | `fast_route`: đang `checkout_collecting` + câu ngắn (≤ 6 từ) kiểu ok/đúng/giữ nguyên/đồng ý/cod → `BUY` không gọi LLM; "tất cả/cả giỏ/toàn bộ/lấy hết" → `BUY` + `scope=whole_cart`. Câu phủ định, "đổi địa chỉ" vẫn đi LLM |
| `graph.py` | `route_node` trả `checkout_collecting` về `none` khi lượt mới không phải BUY/CART; `legacy_node` lọc câu sai sự thật như agent; `_ORDER_CLAIM_RE` thêm "đã ghi nhận đơn (hàng) (của bạn)" (trừ "đơn nháp"), "đơn hàng (của bạn) đã được ghi nhận / sẽ được xử lý", "đơn (hàng) (của bạn) đã được xác nhận". Câu chính sách như "shop gửi mã vận đơn khi giao hàng" không bị chặn |

## 3. Bước xác nhận danh tính bằng OTP email (PHP + JS)

Luồng mới: thẻ đơn nháp → bấm **Xác nhận đặt hàng** → PHP gửi mã 6 số tới email tài khoản đang đăng nhập → thẻ hiện ô nhập mã
(“Mã xác nhận đã gửi tới b***@gmail.com”, nút **Xác nhận**, **Gửi lại mã** có đếm ngược) → PHP kiểm mã rồi mới tạo đơn.

| Thành phần | Nội dung |
|---|---|
| `backend/app/services/ChatOrderOtp.php` (mới) | Mã lưu dạng SHA-256 trong session theo khách, gắn với đúng đơn nháp; hết hạn 5 phút; sai 5 lần thì khóa, phải lấy mã mới; gửi lại sau 60 giây (tính theo cùng đơn nháp); dùng một lần; giá đổi (`changed`) thì mã chuyển sang đơn nháp mới |
| `backend/app/services/Mailer.php` (mới) | SMTP tối giản (STARTTLS/SSL, AUTH LOGIN) dùng `SMTP_HOST/PORT/USER/PASS/ENCRYPTION`; không có SMTP thì `mail()`; `MAIL_DEMO_MODE=1` thì không gửi; chặn CR/LF trong người nhận/tiêu đề; log không chứa mật khẩu hay nội dung thư |
| `ChatAgentController` | Action mới `sendOtp()` (route `chat_order_otp`): đăng nhập, không phải nhân viên, CSRF, Origin, giới hạn 3 lần/10 phút, đơn nháp phải thuộc khách và còn hiệu lực. `confirm()` bắt buộc `otp` hợp lệ trước khi gọi `ChatOrderConfirmService::confirm` |
| `ChatOrderConfirmService` | Thêm hàm chỉ đọc `isDraftOpenFor()` |
| `chat-agent-stream.js` (cả `frontend/public/assets/js/` và bản sao `assets/js/`) | Trạng thái `otp_sending`, `otp`; mã không bao giờ nằm trên URL |
| `ai_chat_widget.php` | `otpEndpoint` + CSS ô nhập mã |
| `docker-compose.yml` | `MAIL_DEMO_MODE: "${MAIL_DEMO_MODE:-1}"` (giống bản prod) |

Mã lỗi trả về: `otp_required` (428), `otp_invalid` (422, kèm số lần còn lại), `otp_expired` (410), `otp_locked` / `otp_cooldown` (429), `mail_failed` (502).

### Bật gửi mail thật

`.env` hiện có `MAIL_DEMO_MODE=1`: PHP **không gửi mail**, chỉ ghi mã vào log (`docker logs skinsyntax-php`, dòng `[chat_order_otp demo] code=…`).
Muốn khách nhận mail thật: đặt `MAIL_DEMO_MODE=0` trong `.env` rồi `docker compose up -d php` (tạo lại container để nhận biến).
Nhánh SMTP chưa được thử với máy chủ thật.

## 4. Kiểm thử

- Python: `pytest tests/agent_graph` (biến kết nối rỗng, bỏ `test_prompts_llm.py`) → 297 passed.
- PHP: `php -l` sạch; `ChatOrderOtpTest` 20/0, `MailerTest` 14/0, `ChatOrderConfirmTest` 41/0, `ChatAgentStreamTest` 37/0,
  `ChatAgentInternalControllerTest` 9/0, `ChatSseRelayTest` 24/0, `BrokenRoutesWiringTest` 87 assertion PASS.
- JS: `node --test frontend/public/assets/js/tests/chat-agent-stream.test.js` → 19 pass; hai bản JS giống hệt; `git diff --check` sạch.
- Smoke trên Docker: `POST ?r=chat_order_otp` khi chưa đăng nhập → 403 JSON. Container `skinsyntax-ai` đã khởi động lại để nạp code Flask mới.
- **E2E PASS (2026-11-16 19:09)**: `E2E_TURN_GAP_MS=30000 node e2e/chat-agent-order.e2e.cjs` (Docker `http://127.0.0.1:8080`, Edge headless,
  `NODE_PATH` trỏ tới Playwright của codex runtime) → 14/14 PASS:
  - Khách vãng lai được mời đăng nhập. Khách đã đăng nhập được tư vấn và nhận thẻ đơn nháp. Bot không nói "đã đặt/ghi nhận đơn" trước khi bấm.
  - Xác nhận không kèm OTP → 428 `otp_required`.
  - Bấm đúp "Xác nhận đặt hàng" chỉ gửi **1** OTP, hiện email đã che.
  - Mã sai → `otp_invalid`, không tạo đơn.
  - Mã đúng + bấm đúp → đúng **1** đơn (#502322).
  - Dùng lại mã đã dùng → không tạo đơn. Đơn hiện trong "Đơn hàng của tôi" và đã hủy (hoàn kho).
- E2E sửa thêm:
  - Helper chung `e2e/lib/chat-otp.cjs` đọc mã từ `docker logs skinsyntax-php` (cần `MAIL_DEMO_MODE=1`) và tự đóng popup chào mừng mới ở trang chủ (popup này che nút chat làm E2E cũ hỏng).
  - `e2e/chat-agent-cases.e2e.cjs` đã chuyển sang bước OTP nhưng **chưa chạy lại** (bộ dài, tạo nhiều đơn thật, tốn nhiều hạn mức Groq).
- Lượt "ok" trong kịch bản E2E không được chạy tới, vì thẻ đơn nháp đã xuất hiện ở lượt 3. Lỗi "ok" chỉ được kiểm bằng test Python.

## 5. Rủi ro / việc tiếp theo

- Chạy lại `e2e/chat-agent-cases.e2e.cjs` khi có hạn mức LLM.
- ✅ Đã sửa (2026-11-16): thông báo lỗi OTP giờ nằm ngay dưới ô nhập mã (`role="alert"`, chữ đỏ), chỉ hiện một lần. Ô nhập được focus khi vừa mở và khi mã bị từ chối; vẽ lại thông thường không cướp focus. JS test 20/20. Kiểm trên trình duyệt thật (chỉ đơn nháp, không tạo đơn): thông báo nằm trong vùng nhìn thấy, ô nhập đang focus, màu `rgb(180,35,24)`.
- Với tài khoản chưa có địa chỉ, bot nói "Địa chỉ giao hàng gần nhất của bạn là: ." (địa chỉ rỗng). Đây là lỗi lời văn riêng, chưa sửa.
- OTP đăng ký (`AuthController::sendHtmlEmail`) vẫn dùng `sendmail`/`mail()`; trong Docker `sendmail` là busybox chưa cấu hình nên có thể không gửi được. Có thể chuyển sang `Mailer`.
- Groq miễn phí dễ bị giới hạn tốc độ, làm agent chập chờn.
