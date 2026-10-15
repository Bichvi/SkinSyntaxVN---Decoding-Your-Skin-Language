# DANH SÁCH FILE THAY ĐỔI VÀ TẠO MỚI (PHASE 2B CHANGED FILES)

Toàn bộ các thay đổi tuân thủ kiến trúc MVC hiện hành của SkinSyntaxVN, không can thiệp vào các thuật toán gợi ý, không thay đổi catalog sản phẩm và không thêm các thư viện bên ngoài không cần thiết.

---

## 1. File tạo mới (New Files)

### A. Giao diện Danh sách Chiến dịch: [frontend/views/admin/flash_sales.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/admin/flash_sales.php)
- Hiển thị bảng danh sách các chiến dịch Flash Sale với trạng thái suy diễn (Server-Derived Status), số lượng SKU, khoảng phần trăm giảm giá và các nút hành động (Xem chi tiết, Sửa, Bật/Tắt, Xóa).
- Hiển thị khuyến nghị kinh doanh mềm 12–20 sản phẩm.

### B. Giao diện Tạo và Sửa Chiến dịch: [frontend/views/admin/flash_sale_form.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/admin/flash_sale_form.php)
- Form nhập thông tin chiến dịch: Tên, Mô tả, Thời gian Bắt đầu, Kết thúc (VN), Switch kích hoạt (`is_active`).
- Bộ chọn sản phẩm AJAX (`productSearchInput`) tìm kiếm nhanh và thêm vào bảng không cần nạp 2,473 SKU vào HTML.
- Bảng danh sách SKU tham gia: Hiển thị ảnh, mã SP, tên, giá niêm yết, ô nhập giá Flash Sale hỗ trợ định dạng số tiền VND và badge phần trăm giảm giá tính tự động (Live Discount Preview).
- Banner cảnh báo an toàn khi chỉnh sửa chiến dịch đang ở trạng thái `ACTIVE`.

### C. Giao diện Chi tiết & Xem trước Chiến dịch: [frontend/views/admin/flash_sale_detail.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/admin/flash_sale_detail.php)
- Hiển thị thông tin tổng quan, trạng thái chiến dịch và đồng hồ đếm ngược (chỉ để trình chiếu, không tự reset khi về 0 mà chuyển sang nhãn "Đã kết thúc").
- Bảng chi tiết toàn bộ SKU tham gia kèm số tiền tiết kiệm và giá Flash Sale.

---

## 2. File chỉnh sửa (Modified Files)

### D. Điều phối viên trung tâm: [index.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/index.php)
- Đăng ký 8 routes quản trị Flash Sale:
  - `admin_flash_sales`
  - `admin_flash_sale_create`
  - `admin_flash_sale_edit`
  - `admin_flash_sale_save`
  - `admin_flash_sale_toggle`
  - `admin_flash_sale_delete`
  - `admin_flash_sale_detail`
  - `api_admin_flash_sale_search_products`

### E. Bộ điều khiển Quản trị: [backend/app/controllers/QuanTriController.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/QuanTriController.php)
- Khởi tạo `FlashSaleCampaign` trong constructor.
- Bổ sung 8 phương thức xử lý nghiệp vụ quản trị, tất cả đều được bảo vệ nghiêm ngặt bằng `$this->requireRole(['admin'])`.
- Làm sạch và chuẩn hóa dữ liệu đầu vào tiền tệ (`preg_replace('/[^\d]/', '', $price)`).

### F. Model Flash Sale: [backend/app/models/FlashSaleCampaign.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/FlashSaleCampaign.php)
- Bổ sung `searchProductsForSelector($query, $limit)` phục vụ tìm kiếm nhanh cho selector AJAX.
- Bổ sung `toggleCampaign($campaignId)` chuyển đổi trạng thái `is_active`.
- Bổ sung `canDeleteCampaign($campaignId, $now)` bảo đảm không thể xóa trực tiếp chiến dịch đang `ACTIVE`.

### G. Bản đồ phân quyền & Helper: [frontend/views/helpers.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/helpers.php)
- Cập nhật hàm `route_access_map()` ánh xạ toàn bộ 8 route Flash Sale tới vai trò `admin`.

### H. Menu Quản trị: [frontend/views/admin/layouts/header.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/admin/layouts/header.php)
- Bổ sung entry "Flash Sale" (`admin_flash_sales`) vào nhóm menu "VẬN HÀNH BÁN HÀNG" trong sidebar.
