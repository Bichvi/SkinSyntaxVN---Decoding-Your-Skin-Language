# ĐẶC TẢ ROUTING VÀ API QUẢN TRỊ FLASH SALE (ADMIN ROUTES SPECIFICATION)

## 1. Danh sách Routes trong Hệ thống

Toàn bộ các route được đăng ký tập trung trong [index.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/index.php) và giao quyền xử lý cho [QuanTriController.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/QuanTriController.php).

| Route (`?r=...`) | Phương thức HTTP | Phương thức Controller | Mô tả chức năng | Quyền truy cập |
| :--- | :--- | :--- | :--- | :--- |
| `admin_flash_sales` | `GET` | `QuanTriController::adminFlashSales()` | Hiển thị danh sách toàn bộ chiến dịch Flash Sale với trạng thái suy diễn (Server-Derived Status), số lượng sản phẩm và khoảng giảm giá. | Bắt buộc `admin` |
| `admin_flash_sale_create` | `GET` | `QuanTriController::adminFlashSaleCreate()` | Hiển thị giao diện tạo mới chiến dịch Flash Sale kèm bộ chọn sản phẩm AJAX. | Bắt buộc `admin` |
| `admin_flash_sale_edit` | `GET` | `QuanTriController::adminFlashSaleEdit()` | Hiển thị form cập nhật chiến dịch Flash Sale hiện có (yêu cầu tham số `id`). | Bắt buộc `admin` |
| `admin_flash_sale_save` | `POST` | `QuanTriController::adminFlashSaleSave()` | Tiếp nhận dữ liệu form, kiểm tra tính hợp lệ qua `FlashSaleCampaign::validateCampaign()` và lưu vào MongoDB Atlas. | Bắt buộc `admin` |
| `admin_flash_sale_toggle` | `POST` / `GET` | `QuanTriController::adminFlashSaleToggle()` | Bật / Tắt trạng thái kích hoạt (`is_active: true/false`) của một chiến dịch. | Bắt buộc `admin` |
| `admin_flash_sale_delete` | `POST` / `GET` | `QuanTriController::adminFlashSaleDelete()` | Thực hiện xóa an toàn hoặc thông báo từ chối nếu chiến dịch đang diễn ra hoặc đã kết thúc có dữ liệu liên kết. | Bắt buộc `admin` |
| `admin_flash_sale_detail` | `GET` | `QuanTriController::adminFlashSaleDetail()` | Xem chi tiết danh sách sản phẩm, giá bán, phần trăm giảm và đồng hồ đếm ngược nếu chiến dịch đang `ACTIVE`. | Bắt buộc `admin` |
| `api_admin_flash_sale_search_products` | `GET` | `QuanTriController::apiAdminFlashSaleSearchProducts()` | Endpoint trả về JSON phục vụ tìm kiếm động sản phẩm theo mã SP hoặc tên trong bộ chọn sản phẩm selector. | Bắt buộc `admin` |

---

## 2. Đặc tả Chi tiết API Tìm kiếm Sản phẩm (Product Selector API)

### Request:
- **URL**: `index.php?r=api_admin_flash_sale_search_products&q=serum&limit=15`
- **Method**: `GET`
- **Headers**: Yêu cầu Cookie phiên làm việc của tài khoản `admin`.

### Response Thành công (HTTP 200 JSON):
```json
{
  "ok": true,
  "items": [
    {
      "id": "93",
      "ma_san_pham": "93",
      "ten_san_pham": "Serum Phục Hồi Rau Má Skin1004 Madagascar Centella Ampoule 100ml",
      "danh_muc": "Serum & Tinh chất",
      "thuong_hieu": "Skin1004",
      "gia_ban": 245000,
      "gia_thi_truong": 320000,
      "hinh_anh": "https://skinsyntax.vn/uploads/products/sp_skin1004.jpg"
    }
  ],
  "total": 1
}
```

### Response Từ chối Quyền truy cập (HTTP 403 JSON):
```json
{
  "ok": false,
  "message": "Không có quyền truy cập chức năng này."
}
```
