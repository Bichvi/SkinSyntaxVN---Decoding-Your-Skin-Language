# E2E luồng ghi vừa nối route (commit 8531657) — 2026-10-26

Môi trường: http://localhost:8080, Playwright MCP, tài khoản test `e2e.customer@skinsyntax.test` (seed bằng `php scripts/e2e/seed_test_accounts.php`, upsert thành công; không dùng admin, không xóa gì, không đặt hàng). Ảnh bằng chứng cùng thư mục.

| # | Kịch bản | Kết quả | Bằng chứng |
|---|----------|---------|-----------|
| 1 | Hồ sơ: lưu SĐT, tải lại, hoàn tác | PASS | SĐT cũ rỗng -> 0900000001, thông báo "Đã cập nhật thông tin tài khoản.", reload còn 0900000001; hoàn tác về rỗng, reload rỗng. `01-hoso-saved.png` |
| 2a | Voucher sai | PASS | "Mã giảm giá không tồn tại." `02a-voucher-wrong.png` |
| 2b | Voucher hợp lệ (FREESHIP) áp/gỡ | PASS | Tổng 193.000 -> 163.000 ("Áp dụng mã giảm giá thành công."), Gỡ -> 193.000 ("Đã gỡ mã giảm giá khỏi đơn hàng."). `02c-voucher-applied.png` |
| 2c | Điểm khi 0 điểm | PASS (nhánh lỗi) | "Tài khoản của bạn hiện chưa có điểm tích lũy để sử dụng." `02b-points-zero.png` |
| 2d | Đổi điểm thật (1 điểm = 1.000đ) / Gỡ điểm | NOT RUN | Tài khoản test 0 điểm; không cấp điểm vì phải ghi DB ngoài phạm vi |
| 3 | Đánh giá | PASS (nhánh từ chối) | Tài khoản không có đơn nào (0 hóa đơn): UI hiện "Bạn chỉ có thể đánh giá sản phẩm sau khi đã mua", POST `guidanhgia` trực tiếp -> chuyển về chitiet tab đánh giá với flash "...Hoàn thành mới được đánh giá."; không có bản ghi đánh giá nào chứa "[E2E TEST]". `03-review-ui.png`. Gửi thành công + gửi lần 2 bị chặn: NOT RUN (cần đơn Hoàn thành) |
| 4 | Hỏi đáp | PASS | Gửi "[E2E TEST] câu hỏi kiểm thử" ở sp 1000 -> redirect, câu hỏi hiện ở tab Hỏi đáp (trang_thai `hien_thi`, hiện ngay không qua duyệt). `04-qa-pane.png` |
| 5 | Chat nhân viên `lichsuchat` | PASS | Tin "[E2E TEST] tin kiểm thử" hiện trong luồng "Hộp thư hỗ trợ". `05-chat.png` |
| 6 | Chưa đăng nhập | PASS | curl GET/POST 12 route (capnhatthongtin, capnhathoso, apdung/bo_voucher, apdung/bo_diem, guidanhgia, danhgia_sanpham, guicauhoi, hoidap_sanpham, lichsuchat, chat_send): đều 302 về `dangnhap` hoặc `thanhtoan` (rồi `auth=login`); 0 lỗi |

## Quan sát (không phải lỗi chặn)
- GET `apdung_voucher`/`apdung_diem`/`bo_*` và POST `bo_*` khi chưa đăng nhập redirect về `thanhtoan#...` (không thẳng `dangnhap`), `thanhtoan` mới chuyển tiếp sang đăng nhập. Không rò rỉ, chỉ thêm 1 hop.
- Sau lưu hồ sơ rồi hoàn tác, `khach_hang.so_dien_thoai` hiện là `null` (trước đó trường chưa tồn tại). Khác biệt vô hại.
- Khi xác nhận hỏi đáp, không bắt được flash trong lần chạy (đã đọc lại qua DB và tab); câu hỏi hiển thị ngay công khai (`hien_thi`) — lưu ý nếu muốn kiểm duyệt trước.

## Hoàn tác
- Hồ sơ: đã trả SĐT về rỗng. Giỏ hàng: đã xóa sản phẩm (xác nhận giỏ trống). Voucher đã gỡ. Không đặt hàng.
- Tài khoản test đã tồn tại từ trước (seed chỉ upsert); giữ nguyên, không chạy remove.

## Bản ghi test còn lại trong DB (chờ bạn quyết, chưa xóa)
- `hoi_dap_san_pham`: `ma_hoi_dap = 3`, sp 1000, `ma_khach_hang = 900163`, nội dung "[E2E TEST] câu hỏi kiểm thử".
- `lich_su_chat`: `_id = 6adf84d3f642b1e2020dacb2`, `ma_kh = 900163`, nội dung "[E2E TEST] tin kiểm thử".
- Đánh giá: không tạo bản ghi nào.
- Có thể kèm 1 thông báo cho nhân viên do `createQuestionNotification` tạo (chưa kiểm tên collection).
