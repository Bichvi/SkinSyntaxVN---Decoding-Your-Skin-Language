# BÁO CÁO KIỂM TOÁN QUY ƯỚC QUẢN TRỊ ADMIN (ADMIN ARCHITECTURE AUDIT)

## 1. Khảo sát hiện trạng Kiến trúc Admin SkinSyntaxVN

Kiểm toán hệ thống mã nguồn hiện hành trước khi viết code:

### A. Routing & Dispatching
- **Entry point**: [index.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/index.php) sử dụng switch-case theo tham số `$_GET['r']`.
- Các route quản trị hiện có tiền tố `admin_*` (ví dụ: `admin_sp`, `admin_categories`, `admin_vouchers`, `admin_orders`, `admin_lives`).
- Toàn bộ các route quản trị cốt lõi được chuyển tiếp tới [QuanTriController.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/QuanTriController.php).

### B. Kiểm soát phân quyền & Bảo vệ truy cập (Role Guards)
- Cơ chế bảo vệ trung tâm là phương thức nội bộ `QuanTriController::requireRole(array $roles): array`.
- **Logic**:
  - Nếu chưa đăng nhập (`!is_logged_in()`): thiết lập flash error và chuyển hướng ngay về `index.php?r=dangnhap`.
  - Nếu người dùng đã đăng nhập nhưng không có vai trò phù hợp (`!in_array($role, $roles, true)`): gọi `$this->denyAccess()`, trả về mã phản hồi HTTP 403 Forbidden và hiển thị trang cảnh báo `403.php`.
- **Flash Sale Admin Guard**: Bắt buộc chỉ cho phép vai trò `admin`:
  ```php
  $this->requireRole(['admin']);
  ```
  Nhân viên bán hàng (`nhanvien`) hay khách hàng thông thường (`khachhang`) đều bị chặn đứng 100%, kể cả khi gõ trực tiếp URL.

### C. Giao diện & Layouts (Admin Views & Sidebar)
- Hàm hiển thị layout chuẩn: `$this->renderAdmin(string $view, array $data = [])`:
  - Nạp [frontend/views/admin/layouts/header.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/admin/layouts/header.php).
  - Nạp view con tương ứng tại `frontend/views/admin/{$view}.php`.
  - Nạp [frontend/views/admin/layouts/footer.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/admin/layouts/footer.php).
- Sidebar: Khai báo theo mảng nhóm nghiệp vụ (`VẬN HÀNH BÁN HÀNG`, `AI STUDIO`, `Khách hàng`, `Hệ thống`).
- CSS/Styling: Sử dụng Bootstrap 5, Bootstrap Icons, các biến màu sắc thương hiệu (`--admin-text`, `--admin-border`, tone màu `#183B2B`).

### D. Cơ chế Flash Message & Redirect
- Hệ thống sử dụng:
  - `set_flash('success', 'Thông báo thành công')`
  - `set_flash('error', 'Thông báo lỗi')`
  - `redirect(BASE_URL . '/index.php?r=...')`
- Header layout tự động render các alert này ở đầu trang.

### E. Cơ chế CSRF
- **Kiểm toán thực tế**: Token CSRF chỉ xuất hiện cục bộ trong `ShopAssistantController` (cho chức năng chốt đơn qua chat AI). Các form Admin hiện tại (`QuanTriController`, `AdminController`) dựa trên cơ chế phiên đăng nhập phía máy chủ (`$_SESSION['user']`) kết hợp HTTP Cookie `httponly` và kiểm tra quyền vai trò nghiêm ngặt (`requireRole`). Không có hệ thống CSRF toàn cục cho toàn bộ Admin.
- **Cam kết**: Báo cáo sự vắng mặt của hệ thống CSRF toàn cục, không tự ý sáng chế một framework CSRF song song làm xáo trộn kiến trúc.

### F. Quy ước tìm kiếm & Phân trang
- Dùng MongoDB regex không phân biệt hoa thường và so khớp ID linh hoạt (`SanPham::buildProductIdQuery`).
- Đối với bộ chọn sản phẩm Flash Sale: Không nạp toàn bộ 2,473 sản phẩm vào một dropdown khổng lồ vì sẽ làm nghẽn DOM và tiêu tốn băng thông. Thay vào đó, xây dựng endpoint AJAX tìm kiếm trực tiếp theo mã sản phẩm hoặc tên sản phẩm với giới hạn kết quả (limit 15-20 sản phẩm/lần tìm).

---

## 2. Báo cáo các file và route bị ảnh hưởng (Affected Files & Routes)

### A. Danh sách Route mới đăng ký trong `index.php`:
1. `admin_flash_sales`: Danh sách chiến dịch Flash Sale.
2. `admin_flash_sale_create`: Form tạo mới chiến dịch Flash Sale.
3. `admin_flash_sale_edit`: Form chỉnh sửa chiến dịch Flash Sale.
4. `admin_flash_sale_save`: Xử lý lưu POST (Tạo mới / Cập nhật).
5. `admin_flash_sale_toggle`: Bật / Tắt nhanh chiến dịch (`is_active`).
6. `admin_flash_sale_delete`: Xử lý xóa chiến dịch theo chính sách an toàn.
7. `admin_flash_sale_detail`: Xem chi tiết / xem trước chiến dịch & đồng hồ đếm ngược.
8. `api_admin_flash_sale_search_products`: API tìm kiếm sản phẩm cho bộ chọn selector (AJAX).

### B. Danh sách File sẽ chỉnh sửa / tạo mới:
1. Chỉnh sửa [index.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/index.php) (Thêm các case route vào switch `$r`).
2. Chỉnh sửa [backend/app/controllers/QuanTriController.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/QuanTriController.php) (Thêm 8 methods phụ trách Flash Sale).
3. Chỉnh sửa [backend/app/models/FlashSaleCampaign.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/FlashSaleCampaign.php) (Bổ sung hàm hỗ trợ tìm kiếm sản phẩm và toggle/delete policy).
4. Chỉnh sửa [frontend/views/admin/layouts/header.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/admin/layouts/header.php) (Thêm mục "Flash Sale" vào sidebar).
5. Tạo mới `frontend/views/admin/flash_sales.php` (Trang danh sách chiến dịch).
6. Tạo mới `frontend/views/admin/flash_sale_form.php` (Form tạo/sửa chiến dịch kèm live discount preview và bộ chọn sản phẩm).
7. Tạo mới `frontend/views/admin/flash_sale_detail.php` (Trang chi tiết / xem trước chiến dịch).
