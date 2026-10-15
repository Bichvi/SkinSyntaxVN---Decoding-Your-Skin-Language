# DANH SÁCH FILE THAY ĐỔI VÀ TẠO MỚI (CHANGED FILES)

Tất cả các thay đổi tuân thủ nghiêm ngặt nguyên tắc tối giản (minimal disruption), không phá vỡ kiến trúc MVC hiện hành và bảo vệ tính toàn vẹn của catalog.

---

## 1. File tạo mới (New Files)

### A. Model Flash Sale: [backend/app/models/FlashSaleCampaign.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/FlashSaleCampaign.php)
- **Vai trò**: Cung cấp toàn bộ logic nghiệp vụ về:
  - Máy trạng thái vòng đời chiến dịch (`DISABLED`, `UPCOMING`, `ACTIVE`, `ENDED`).
  - Chính sách múi giờ (UTC, Epoch, Asia/Ho_Chi_Minh).
  - Validation dữ liệu (SKU tồn tại, đang hoạt động, giá flash hợp lệ, kiểm tra trùng SKU, từ chối chiến dịch chồng lấn giờ cho cùng SKU).
  - Động cơ phân giải giá hiệu lực dùng chung (`resolveEffectivePrice`).
  - Xử lý tương thích kiểu dữ liệu `MongoDB\Model\BSONArray` và driver `mongodb/mongodb`.

---

## 2. File chỉnh sửa (Modified Files)

### B. Helper phân giải giá toàn cục: [frontend/views/helpers.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/helpers.php)
- **Mục đích**: Cung cấp hàm `get_effective_product_price($productId, ?array $preloadedProduct = null)` để các tầng view và controller có thể dễ dàng gọi mà không cần khởi tạo lại model nhiều lần.
- **Nội dung thay đổi**: Khởi tạo singleton instance của `FlashSaleCampaign` và ủy quyền tính toán cho `resolveEffectivePrice()`.

### C. Bộ điều khiển giỏ hàng & thanh toán: [backend/app/controllers/HomeController.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/HomeController.php)
- **Mục đích**: Tích hợp giá Flash Sale vào giỏ hàng và trang xem trước thanh toán, đồng thời ngăn chặn việc tạo đơn hàng với giá cũ khi Flash Sale hết hạn giữa chừng.
- **Nội dung thay đổi**:
  - `giohang()`: Gọi `FlashSaleCampaign::resolveEffectivePrice()` để hiển thị đúng đơn giá hiệu lực (flash price nếu đang active, hoặc regular price) và tính tổng tiền giỏ hàng từ đơn giá này.
  - `buildCheckoutPreview()`: Thay thế việc đọc trực tiếp `san_pham.gia_ban` bằng đơn giá hiệu lực từ `resolveEffectivePrice()`.
  - `thanhtoan()`: Lưu `$_SESSION['checkout_preview_subtotal'] = $preview['subtotal']` để làm mốc đối chiếu trước khi đặt hàng.
  - `xulydathang()`: So sánh `checkout_preview_subtotal` với tổng tiền mới tại thời điểm submit. Nếu giá trị thay đổi do Flash Sale kết thúc giữa chừng, chuyển hướng an toàn về trang thanh toán kèm thông báo cập nhật giá.

### D. Giao diện giỏ hàng: [frontend/views/giohang.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/giohang.php)
- **Mục đích**: Hiển thị đơn giá hiệu lực và nhãn Flash Sale nếu sản phẩm đang trong chương trình ưu đãi chớp nhoáng.
- **Nội dung thay đổi**: Cập nhật logic hiển thị đơn giá `effective_price` và gắn huy hiệu Flash Sale nổi bật nếu `pricing_source === 'FLASH_SALE'`.

### E. Model Hóa đơn & Chốt đơn: [backend/app/models/HoaDon.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/HoaDon.php)
- **Mục đích**: Đảm bảo tại thời điểm `taoDonHang()`, đơn giá lưu vào cơ sở dữ liệu `chi_tiet_hoa_don` là đơn giá hiệu lực do server phân giải lại, loại bỏ mọi khả năng tin tưởng giá từ client.
- **Nội dung thay đổi**:
  - `fetchProductPrice($maSanPham)`: Ủy quyền phân giải cho `FlashSaleCampaign::resolveEffectivePrice()`.
  - Khắc phục biến chưa định nghĩa `$maKh` tại dòng logging mua hàng `InteractionLogger::logPurchase`.

---

## 3. File kiểm thử và phụ trợ (Scratch Scripts)

### F. Suite kiểm thử tự động toàn diện: `scratch/test_phase2a_suite.php`
- **Mục đích**: Kịch bản chạy tự động 18 test cases (A đến R) để kiểm định 100% các yêu cầu của Phase 2A trên môi trường MongoDB Atlas thực tế, tự động dọn dẹp (cleanup) toàn bộ dữ liệu test sau khi hoàn tất.
