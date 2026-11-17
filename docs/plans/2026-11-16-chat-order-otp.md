# Kế hoạch: chặn bot bịa đơn + OTP email khi xác nhận đơn qua chat

Ngày: 2026-11-16 · Nhánh: `syna_10_11` · Trạng thái: đang làm

## Bối cảnh (lỗi đã xác minh)

Khách gõ "ok" sau khi bot hỏi "giữ địa chỉ / dùng COD không?". Trạng thái agent chỉ có `none` hoặc `draft_open:*`,
nên câu "ok" được bộ phân loại xếp vào nhãn chuyển sang luồng cũ (`legacy_node` → `xu_ly_cau_hoi`). Chatbot cũ không có tool
và đã bịa "Mình đã ghi nhận đơn hàng…". Bộ lọc câu sai sự thật `_guard_text` chỉ chạy ở `agent_node`, và `_ORDER_CLAIM_RE`
không bắt được "ghi nhận đơn". Kết quả: không có đơn nháp, không có thẻ, không có đơn.

## Tiêu chí hoàn thành

1. Sau khi bot đã gọi `get_checkout_defaults` (đang hỏi thông tin giao hàng), câu trả lời ngắn kiểu "ok / đúng / giữ nguyên /
   tất cả / đồng ý" được xếp vào `BUY` (không gọi LLM) và agent lập đơn nháp.
2. Không luồng nào (agent hay legacy) được gửi tới khách câu "đã ghi nhận/đã tạo đơn" khi chưa có đơn.
3. Bấm "Xác nhận đặt hàng" → PHP gửi OTP 6 số tới email của tài khoản đang đăng nhập → khách nhập mã trên thẻ → PHP
   kiểm mã rồi mới tạo đơn. Thiếu mã hoặc sai mã thì không tạo đơn.
4. OTP: hết hạn 5 phút, tối đa 5 lần nhập sai, gửi lại sau 60 giây, tối đa 3 lần gửi / 10 phút / khách, dùng một lần,
   gắn với đúng khách và đúng đơn nháp; lưu dạng SHA-256 trong session, không bao giờ ghi mã vào log (trừ `MAIL_DEMO_MODE=1`).
5. Gửi mail qua SMTP (`SMTP_HOST/PORT/USER/PASS/ENCRYPTION`; đính chính: trong `.env` máy dev các biến host/user/pass đang rỗng), không có SMTP thì dùng `mail()`.
   `MAIL_DEMO_MODE=1` thì không gửi, ghi mã ra log PHP để demo.

## Phần A: Flask (`ai-service-flask/agent_graph/`)

- `tools.py`: `get_checkout_defaults` chạy thành công và chưa có đơn nháp → `ctx.state.pending = "checkout_collecting"`.
- `router.py` `fast_route`: `pending == "checkout_collecting"` + câu ngắn đồng ý/chọn phạm vi → `BUY`; "tất cả / cả giỏ / toàn bộ" → `scope = whole_cart`.
- `graph.py` `route_node`: đang `checkout_collecting` mà lượt này không có `BUY`/`CART` thì về `none`.
- `graph.py` `legacy_node`: chạy câu trả lời qua cùng bộ kiểm tra câu sai sự thật trước khi phát ra.
- `graph.py` `_ORDER_CLAIM_RE`: thêm các cách nói "đã ghi nhận đơn (hàng) (của bạn)", "đơn hàng của bạn đã được ghi nhận / sẽ được xử lý".
- Test: router, graph (đúng kịch bản lỗi), invariants.

## Phần B: PHP + JS

- `services/Mailer.php` (mới): SMTP tối giản (STARTTLS/SSL, AUTH LOGIN), dự phòng `mail()`, chế độ demo; transport tiêm được để test.
- `services/ChatOrderOtp.php` (mới): issue / verify / consume / rebind trên mảng session tiêm vào, đồng hồ tiêm vào.
- `ChatAgentController`: action mới `sendOtp()` (route `chat_order_otp`); `confirm()` bắt buộc `otp` hợp lệ trước khi gọi
  `ChatOrderConfirmService::confirm`; tạo đơn xong thì consume, giá đổi (`changed`) thì gắn mã sang đơn nháp mới.
- `routes/chat_agent.php`: thêm route.
- `chat-agent-stream.js` (cả `frontend/public/assets/js/` và bản sao `assets/js/`): thẻ đơn nháp có bước nhập OTP.
- `ai_chat_widget.php`: thêm `otpEndpoint` vào config.
- `docker-compose.yml`: `MAIL_DEMO_MODE: "${MAIL_DEMO_MODE:-1}"` (giống bản prod) để `.env` bật được mail thật.
- Test: `ChatOrderOtpTest.php`, `MailerTest.php` (mới), mở rộng `ChatOrderConfirmTest.php`, `chat-agent-stream.test.js`.

## Kiểm tra

`php -l` các file PHP đổi; các test `backend/tests/unit/Chat*Test.php` liên quan (không ghi DB); `node --test` cho JS;
`pytest tests/agent_graph` với biến kết nối rỗng; `git diff --check`; GitNexus `detect_changes` trước khi commit.
E2E `e2e/chat-agent-order.e2e.cjs` cần cập nhật bước OTP (đọc mã từ log khi demo) — tạo đơn thật trên Atlas, chạy khi người dùng đồng ý.
