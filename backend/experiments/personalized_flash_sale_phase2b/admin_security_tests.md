# BÁO CÁO KIỂM THỬ BẢO MẬT VÀ PHÂN QUYỀN (ADMIN SECURITY TESTS)

## 1. Mục tiêu kiểm thử
1. Đảm bảo người dùng vãng lai (khách chưa đăng nhập), khách hàng (`khach_hang`) và nhân viên (`nhanvien`) **hoàn toàn không thể truy cập** vào bất kỳ route quản trị Flash Sale nào kể cả khi gõ trực tiếp URL trên trình duyệt.
2. Kiểm tra tính chặt chẽ của cơ chế phòng thủ `QuanTriController::requireRole(['admin'])` và bản đồ quyền `route_access_map()`.
3. Kiểm tra tính toàn vẹn của API tìm kiếm sản phẩm `api_admin_flash_sale_search_products`.

---

## 2. Kết quả kiểm thử phân quyền truy cập (Test Cases A & B)

### A. TEST_A: Chặn người dùng không có vai trò Admin
- **Kịch bản 1: Khách vãng lai (Chưa đăng nhập)**
  - Gửi request tới `index.php?r=admin_flash_sales`.
  - Kết quả: Server phát hiện `!is_logged_in()`, thiết lập flash error và chuyển hướng ngay lập tức về trang đăng nhập `index.php?r=dangnhap`. Quyền truy cập: **BỊ CHẶN 100%**.
- **Kịch bản 2: Khách hàng thông thường (`role: khach_hang`)**
  - Session giả lập tài khoản khách hàng thông thường.
  - Gửi request tới `index.php?r=admin_flash_sales` và `admin_flash_sale_create`.
  - Kết quả: `user_can_access_route` trả về `false`; Controller gọi `denyAccess()`, trả về mã HTTP `403 Forbidden` và giao diện cảnh báo `Bạn không có quyền truy cập chức năng này`. Quyền truy cập: **BỊ CHẶN 100%**.
- **Kịch bản 3: Nhân viên quản lý đơn/chat (`role: nhanvien`)**
  - Session giả lập tài khoản nhân viên.
  - Gửi request tới `index.php?r=admin_flash_sales`.
  - Kết quả: `user_can_access_route` trả về `false`; Controller trả về HTTP `403 Forbidden`. Quyền truy cập: **BỊ CHẶN 100%**.

### B. TEST_B: Cấp quyền truy cập cho vai trò Quản trị viên (`role: admin`)
- Session đăng nhập với vai trò `admin`.
- Kiểm tra toàn bộ các route:
  - `admin_flash_sales`: Được phép (`true`).
  - `admin_flash_sale_create`: Được phép (`true`).
  - `admin_flash_sale_edit`: Được phép (`true`).
  - `admin_flash_sale_save`: Được phép (`true`).
  - `admin_flash_sale_toggle`: Được phép (`true`).
  - `admin_flash_sale_delete`: Được phép (`true`).
  - `admin_flash_sale_detail`: Được phép (`true`).
  - `api_admin_flash_sale_search_products`: Được phép (`true`).
- Kết quả: **PASS**. Admin toàn quyền quản lý chiến dịch.

---

## 3. Khảo sát Hiện trạng CSRF
- **Báo cáo trung thực**: Hệ thống SkinSyntaxVN hiện chưa có middleware CSRF toàn cục cho toàn bộ khu vực Admin. Các form quản trị viên hiện hoạt động dựa trên:
  1. Phiên làm việc máy chủ (`$_SESSION['user']`).
  2. Cookie `PHPSESSID` có cờ bảo vệ `HttpOnly` ngăn chặn XSS đánh cắp session.
  3. Kiểm soát phân quyền máy chủ nghiêm ngặt tại controller (`requireRole(['admin'])`).
- **Cam kết**: Tuân thủ quy định Phase 2B, không tự tiện tạo ra một hệ thống CSRF song song làm phân mảnh kiến trúc hiện có.
