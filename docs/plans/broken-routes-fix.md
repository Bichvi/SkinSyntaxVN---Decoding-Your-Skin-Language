# Sửa route hỏng (404/Fatal) của khách

Ba router sửa đồng bộ: `backend/public/index.php`, `frontend/public/index.php`, `index.php`. Không sửa symbol controller nào (chỉ nối route) nên không cần GitNexus impact; không đổi schema.

| # | Lỗi | Nguyên nhân gốc | Cách sửa |
|---|-----|-----------------|----------|
| 1 | Hồ sơ `capnhatthongtin` 404 | `hoso.php:1580` fetch route không có `case`; `capnhathoso` gọi `capnhatHoso()` không tồn tại (router ~398) | Cả 2 route -> `TaiKhoanController::capNhatThongTin` (có sẵn: requireLogin, POST-only, validate, JSON) |
| 1b | `diachi_macdinh`, `xoa_diachi` Fatal | Gọi `datDiaChiMacDinh()`/`xoaDiaChi()` không tồn tại; không view nào gọi; DB chỉ có 1 trường `dia_chi` (không có sổ địa chỉ) | Gỡ 2 case (từ Fatal thành 404 sạch) — CẦN NGƯỜI DÙNG QUYẾT |
| 2 | Voucher/điểm 404 | `thanhtoan.php:302-331` post 4 route không có `case` | `apdung_voucher`/`bo_voucher`/`apdung_diem`/`bo_diem` -> `HomeController::apDungVoucher/boVoucher/apDungDiem/boDiem` (có sẵn, kiểm hợp lệ phía server qua `validateForCheckout`, giới hạn điểm theo số dư) |
| 3 | Đánh giá | `chitiet.php:1846` post `guidanhgia` (không case); `danhgia_sanpham` gọi `danhgiaSanpham()` không tồn tại | Cả 2 -> `QuanTriController::customerReviewSave` (requireRole khach_hang, `DanhGia::addReview` kiểm đã mua đơn Hoàn thành + chưa đánh giá, PRG + flash) |
| 4 | Hỏi đáp | `chitiet.php:1938` post `guicauhoi`; `hoidap_sanpham` gọi `hoidapSanpham()` không tồn tại | Cả 2 -> `QuanTriController::customerQuestionSave` (requireRole khach_hang, PRG) |
| 5 | `lichsuchat` 404 | Footer/hoso link tới, router không có case | -> `QuanTriController::customerChat` (có sẵn, view `frontend/views/lichsuchat.php`) |

Phụ: `SupportContentTest.php:110` dùng `lichsuchat` làm ví dụ "route không có case" -> đổi sang `route_khong_ton_tai`.

## Impact
Chỉ thêm/đổi `case` trong router; không sửa controller/model. Rủi ro: thấp. Lưu ý: các form này hiện KHÔNG có token CSRF (cả form cũ lẫn controller); thêm CSRF là thay đổi riêng, chưa làm.

## Kiểm chứng
- `php -l` 3 router: OK. Test mới `backend/tests/unit/BrokenRoutesWiringTest.php`: 81 PASS (thuần PHP, không DB).
- SupportContentTest 38 PASS, SupportRankTest 54 PASS, store_assistance_test 156 assertions, check_store_cases failed=[].
- curl :8080 (GET/POST, chưa đăng nhập) 11 route mới: 302 về `dangnhap` hoặc `thanhtoan`, 0 Fatal/Warning. `diachi_macdinh`: 404.

## NOT RUN
Mọi thao tác ghi thật khi đã đăng nhập (lưu hồ sơ, áp voucher/điểm, gửi đánh giá/câu hỏi, chat), thử thiếu CSRF (không có CSRF), Playwright E2E, GitNexus detect_changes.

## Bổ sung: `chat_send` (Bước 09b)
Form `lichsuchat.php` post `chat_send` (404). Nối cả 3 router -> `QuanTriController::customerChatSend` (requireRole khach_hang, POST-only, PRG về `lichsuchat`). Test: BrokenRoutesWiringTest +6 assertion (87 PASS); curl chưa đăng nhập GET/POST -> 302 `dangnhap`. NOT RUN: gửi tin thật khi đăng nhập.
