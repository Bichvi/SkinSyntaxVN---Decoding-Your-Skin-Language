# Giới hạn thử đăng nhập / quên mật khẩu / OTP đăng ký

## Vấn đề
Các luồng xác thực không có giới hạn số lần thử:
- Đăng nhập: dò mật khẩu vô hạn, kể cả tài khoản admin/nhân viên.
- Quên mật khẩu: gửi mail đặt lại vô hạn (spam hộp thư nạn nhân).
- Gửi OTP đăng ký: chỉ có cooldown 60s theo phiên, đổi phiên là qua mặt được.
- Xác thực OTP đăng ký: sai bao nhiêu lần cũng được thử lại.

## Đã thay đổi
- Thêm `backend/app/services/AuthThrottle.php` (dùng `RateLimiter`, Redis, cửa sổ cố định).
- `AuthController`: `xulydangnhap`, `guiLienKetDatLai`, `guiOtpDangKy` gọi `AuthThrottle::check()` trước khi truy vấn DB; `xulydangky` đếm số lần nhập sai OTP.
- Bị chặn: đăng nhập/quên mật khẩu hiện flash lỗi và quay lại form; gửi OTP trả HTTP 429 kèm `retry_after`.

## Giới hạn
| Hành động | Theo email | Theo IP công khai | Theo phiên (khi không có IP) |
|---|---|---|---|
| `login` | 10 / 15 phút | 30 / 15 phút | 20 / 15 phút |
| `reset` | 3 / 15 phút | 10 / 15 phút | 5 / 15 phút |
| `signup_otp` | 3 / 10 phút | 10 / 10 phút | 5 / 10 phút |

- Ident luôn là hash cắt ngắn, không lưu email/IP thô; log chỉ ghi action và tên scope.
- Quên mật khẩu áp dụng dù email có tồn tại hay không (không lộ sự tồn tại tài khoản).
- OTP đăng ký: sai 5 lần thì xóa OTP, phải lấy mã mới. Bộ đếm nằm trong session cùng OTP nên đổi phiên không reset được; gửi OTP mới thì ghi đè và đếm lại từ 0.

## Fail-open
Redis chết thì `RateLimiter::hit()` trả `degraded=true, allowed=true`, đăng nhập vẫn hoạt động bình thường (chỉ mất giới hạn). Bộ đếm OTP không dùng Redis nên vẫn hoạt động.

## File thay đổi
- `backend/app/services/AuthThrottle.php` (mới)
- `backend/app/controllers/AuthController.php`
- `backend/tests/unit/AuthThrottleTest.php` (mới)
- `docs/redis/conventions.md` (thêm scope `rl:`)
- `update.md`, `update_auth_rate_limit.md`

## Cách test
```
php backend/tests/unit/AuthThrottleTest.php
php backend/tests/unit/RateLimiterTest.php
```
Chỉ chạy đúng hai file này (không chạy hàng loạt trong `backend/tests`, nhiều script ghi DB thật).

## Giới hạn đã biết
- Giới hạn theo email: nếu ai đó cố tình spam email của người khác, người thật có thể bị khóa tạm tối đa 15 phút.
- Scope IP chỉ hoạt động khi `REMOTE_ADDR` là IP công khai; sau nginx/Docker (IP private) sẽ rơi về giới hạn theo phiên, yếu hơn vì đổi phiên là reset.
