# Chatbot đặt hàng: chặn bot bịa đơn + OTP email khi xác nhận đơn

Ngày: 2026-11-16 → 2026-11-17 · Nhánh: `syna_10_11` · Trạng thái: **đã commit (`10eddba` + commit thẻ hóa đơn mới/số liệu chuẩn), unit test PASS, E2E `chat-agent-order` 15/15 + `chat-agent-changes` 27/27 PASS**.
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
**Đính chính (2026-11-16 23:20):** `.env` có khai báo `SMTP_HOST`, `SMTP_USER`, `SMTP_PASS` nhưng **giá trị rỗng** (chỉ `SMTP_PORT=587`, `SMTP_ENCRYPTION=tls`, `MAIL_FROM_*` có giá trị). Không có SMTP thì Mailer dùng `mail()` → busybox `sendmail` chưa cấu hình → không gửi được → `sendOtp` trả 502 `mail_failed`, khách không đặt được hàng qua chat. Đã thử `MAIL_DEMO_MODE=0` rồi **trả về `1`** vì lý do này.

Muốn khách nhận mail thật:
1. Điền `SMTP_HOST`, `SMTP_USER`, `SMTP_PASS` trong `.env`. Với Gmail: `smtp.gmail.com`, cổng 587, `tls`; `SMTP_PASS` là *App Password* 16 ký tự (bật xác minh 2 bước), không phải mật khẩu đăng nhập. `MAIL_FROM_ADDRESS` nên trùng tài khoản gửi.
2. Đặt `MAIL_DEMO_MODE=0`.
3. `docker compose up -d --no-deps php-backend` (tạo lại container để nhận biến).
4. Thử gửi một mã. Nhánh SMTP chưa được thử với máy chủ thật.

E2E (`e2e/lib/chat-otp.cjs`) đọc mã từ log nên chỉ chạy được khi `MAIL_DEMO_MODE=1`.

**Cập nhật 2026-11-17:** người dùng đã điền `SMTP_HOST/USER/PASS` (Gmail App Password). Đăng nhập SMTP thử (EHLO → STARTTLS → AUTH → QUIT, không gửi thư) trả **235**. `MAIL_DEMO_MODE=0` đang bật. Chưa gửi một mã thật nào. Khi chạy E2E phải tạm đặt `1`, chạy xong đặt lại `0` (`docker compose up -d --no-deps php-backend` mỗi lần đổi).

## 3b. Thẻ hóa đơn mới + số liệu chuẩn khi khách đổi ý (2026-11-16/17)

**Thiết kế lại thẻ** (`renderDraftCard`, CSS trong `ai_chat_widget.php`):
- Đầu thẻ "Xác nhận đơn hàng" + đếm ngược mm:ss.
- Mỗi món có ảnh (`image_url` mới từ `ChatDraftService`), "SL: n × đơn giá" và thành tiền.
- Khối tổng: tạm tính, giảm giá, phí vận chuyển, **Tổng thanh toán**.
- Người nhận / giao tới / thanh toán có icon. Cảnh báo xung đột trong khung vàng.
- Nút "Xác nhận đặt hàng" xanh, rộng hết thẻ. Panel OTP. Màn hình thành công có link "Xem đơn hàng của tôi".
- Trang xem trước: `e2e/preview/draft-card-preview.cjs` → `report/draft-card-preview/*.png` (giả lập fetch, không tạo đơn).

**Lỗi số liệu đã sửa:**

| # | Lỗi | Sửa |
|---|---|---|
| 1 | Thẻ chỉ hiện phần sau dấu phẩy cuối của địa chỉ và SĐT bị che: khách không kiểm tra được | `ChatSseRelay` nhận `transformUi`. `ChatAgentController::stream` gắn SĐT/địa chỉ đầy đủ vào `draft_card` cho đúng chủ đơn nháp (`ChatDraftService::withFullReceiver`). Flask/LLM vẫn chỉ thấy bản che |
| 2 | "Đổi số điện thoại" lỗi vì phải gửi lại đủ 3 trường | `address_choice:"new"` chỉ cần trường đổi; phần còn lại lấy từ đơn nháp đang mở, không có thì lấy mặc định của tài khoản |
| 3 | SĐT nhận 9–11 số bất kỳ | Chuẩn hóa (+84, dấu cách, chấm, gạch); chỉ nhận `0[35789]xxxxxxxx` hoặc `02xxxxxxxxx`. Lỗi riêng `receiver_phone_invalid` / `receiver_address_invalid` / `receiver_name_invalid` |
| 4 | SL ngoài 1–20 bị âm thầm kẹp; đổi thanh toán/địa chỉ làm SL về 1 | Báo `qty_invalid`. SL lấy theo thứ tự: khách nói → đơn nháp cũ → giỏ → 1. `product_ids` rỗng thì giữ món của đơn nháp cũ |
| 5 | Lỗi phương thức thanh toán mơ hồ | `payment_unsupported` (QR chưa bật), `payment_invalid` |
| 6 | Tài khoản không có địa chỉ: bot đọc "địa chỉ … là: ." | `checkout_defaults` trả `receiver: null` + `missing: [...]` |
| 7 | Đặt 1 trong 3 chai thì cả 3 bị xóa khỏi giỏ | Trừ đúng số đã đặt |
| 8 | Bot không biết vì sao PHP từ chối | Kết quả tool có `code` + `message` tiếng Việt; prompt hướng dẫn sửa từng thứ (`PROMPT_VERSION 2026-11-17.1`) |
| 9 | "Đổi sang COD/VNPay", "còn 1 chai": model gửi lại `address_choice:"default"` → mất người nhận khách đã đưa (E2E 2026-11-17 bắt được) | Thêm `address_choice:"keep"` (mặc định). Flask đổi `default` → `keep` khi đang có đơn nháp mà câu của khách không nhắc địa chỉ/SĐT/người nhận. PHP: `default` mà địa chỉ mặc định của tài khoản không đủ thì giữ người nhận của đơn nháp |

GitNexus: `ChatDraftService::upsert`, `resolveReceiver`, `maskReceiver` **CRITICAL**, `validateReceiver` HIGH (nhiều nơi gọi). `maskReceiver` không đổi đầu ra. Các thay đổi còn lại chỉ thêm khóa hoặc tham số tùy chọn; test bao phủ ở dưới.

**Kiểm thử 2026-11-17:**
- PHP (đều pass): `ChatDraftServiceTest` 81, `ChatOrderConfirmTest` 48, `ChatSseRelayTest` 32, `ChatAgentInternalControllerTest` 14, `ChatAgentStreamTest` 37, `ChatOrderOtpTest` 20, `CheckoutCoreTest` 6, `MailerTest` 14, `BrokenRoutesWiringTest` 87.
- JS 22/22. Python `tests/agent_graph` 321 (có cả `test_prompts_llm.py`, biến kết nối rỗng).
- **E2E `e2e/chat-agent-changes.e2e.cjs` PASS 27/27** (LLM thật). Kịch bản: lập đơn nháp SL 2, người nhận mới, COD → đổi SĐT → đổi VNPay → còn 1 chai → về COD → OTP → tạo đơn #502323. Mỗi thẻ đều đúng phép tính: dòng = đơn giá × SL, tạm tính = Σ dòng, tổng = tạm tính − giảm + ship. Đơn lưu trong DB khớp thẻ cuối: món, SL, đơn giá, tạm tính, giảm, ship, tổng, COD, tên/SĐT/địa chỉ. Đơn đã hủy.
- **E2E `e2e/chat-agent-order.e2e.cjs` PASS 15/15** trên thẻ mới (đơn #502324, đã hủy).
- Trong lúc chạy, Groq/Gemini đôi khi trả lỗi giới hạn tốc độ; bot báo "Mình gặp sự cố…" và lượt sau chạy lại được.

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
- ✅ Đã sửa (2026-11-17, mục 3b số 6): tài khoản chưa có địa chỉ không còn bị đọc "địa chỉ … là: .".
- Khách có SĐT/địa chỉ mặc định không đạt chuẩn mới (ví dụ SĐT 11 số cũ) giờ nhận `receiver_phone_invalid` ở nhánh "default" thay vì đi qua; bot sẽ hỏi lại.
- OTP đăng ký (`AuthController::sendHtmlEmail`) vẫn dùng `sendmail`/`mail()`; trong Docker `sendmail` là busybox chưa cấu hình nên có thể không gửi được. Có thể chuyển sang `Mailer`.
- Groq miễn phí dễ bị giới hạn tốc độ, làm agent chập chờn.
