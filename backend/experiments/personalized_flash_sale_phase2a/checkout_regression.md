# BÁO CÁO KIỂM THỬ HỒI QUY GIỎ HÀNG VÀ THANH TOÁN (CHECKOUT REGRESSION TESTS)

## 1. Mục tiêu kiểm thử
Xác minh rằng việc tích hợp Flash Sale vào quy trình Giỏ hàng và Thanh toán:
1. Phản ánh chính xác đơn giá Flash Sale khi xem trước thanh toán (Checkout Preview).
2. Hoàn toàn không làm ảnh hưởng đến các sản phẩm thông thường khác.
3. Miễn nhiễm với các kỹ thuật can thiệp giá (Price Tampering) qua tham số HTTP POST từ client.
4. Đảm bảo toàn vẹn dữ liệu đơn hàng và chi tiết đơn hàng (`chi_tiet_hoa_don`) trong cơ sở dữ liệu MongoDB Atlas.

---

## 2. Kết quả kiểm thử chi tiết (Test Cases N, P, Q, R)

### A. TEST_N: Xem trước thanh toán trong thời gian Flash Sale đang hoạt động
- **Kịch bản**: Giỏ hàng chứa 2 sản phẩm SKU `#1` trong lúc chiến dịch Flash Sale đang `ACTIVE` (giá Flash Sale: 285.600 đ/sản phẩm; giá gốc: 357.000 đ).
- **Thực thi**: Gọi `HomeController::buildCheckoutPreview(['1' => 2])`.
- **Kết quả quan sát được**:
  - `unit_price`: `285.600 đ`.
  - `subtotal`: `571.200 đ` ($285.600 \times 2$).
- **Đánh giá**: **PASS**. Đơn giá và tổng tiền tạm tính được tính toán chính xác theo giá Flash Sale.

### B. TEST_P: Sản phẩm thông thường không bị ảnh hưởng
- **Kịch bản**: Giỏ hàng chứa 1 sản phẩm SKU `#93` (không nằm trong bất kỳ chiến dịch Flash Sale nào đang hoạt động).
- **Thực thi**: Gọi `HomeController::buildCheckoutPreview(['93' => 1])`.
- **Kết quả quan sát được**:
  - `unit_price`: `245.000 đ` (đúng giá niêm yết gốc).
  - `subtotal`: `245.000 đ`.
- **Đánh giá**: **PASS**. Quy trình thanh toán sản phẩm thông thường không bị xáo trộn.

### C. TEST_Q: Thử nghiệm can thiệp giá từ Client (Price Tampering Regression)
- **Kịch bản**: Kẻ tấn công cố tình chèn các trường giá giả mạo vào payload HTTP POST như `price = 1`, `gia_ban = 500`, `unit_price = 10` nhằm mua sản phẩm SKU `#93` với giá 1 đồng.
- **Thực thi**: Giả lập payload:
  ```php
  $maliciousPayload = [
      'product_id' => '93',
      'quantity' => 2,
      'price' => 1,
      'gia_ban' => 500,
      'unit_price' => 10
  ];
  ```
- **Kết quả quan sát được**:
  - Server hoàn toàn bỏ qua các trường `price`, `gia_ban`, `unit_price` do client gửi.
  - Server tự động truy xuất và phân giải đơn giá từ catalog canonical: `245.000 đ`.
  - Tổng tiền tính toán: `490.000 đ` ($245.000 \times 2$).
- **Kết luận báo cáo**:
  > *"Trong luồng được kiểm toán, backend không tin giá do client gửi; không phát hiện client-side price trust trong các đường dẫn đã kiểm tra."*
- **Đánh giá**: **PASS**.

### D. TEST_R: Tạo đơn hàng thực tế và lưu trữ vào `chi_tiet_hoa_don`
- **Kịch bản**: Thực hiện một giao dịch mua hàng kiểm soát có chứa sản phẩm Flash Sale SKU `#1`.
- **Thực thi**: Gọi `HoaDon::taoDonHang()` với địa chỉ nhận hàng và hình thức COD.
- **Xác minh trực tiếp trong MongoDB Atlas**:
  - Document `chi_tiet_hoa_don` được tạo với `ma_san_pham = '1'`.
  - Giá trị lưu tại trường `don_gia`: `285.600 đ` (trùng khớp 100% với đơn giá Flash Sale server phân giải).
  - Tổng tiền `hoa_don.tam_tinh`: `285.600 đ`.
- **Dọn dẹp (Cleanup)**: Đơn hàng test và khách hàng test được xóa sạch ngay sau khi kiểm tra xong, hoàn trả tồn kho đầy đủ.
- **Đánh giá**: **PASS**.

---

## 3. Xác minh tính toàn vẹn cơ sở dữ liệu sau kiểm thử (Catalog Safety Verification)

| Bộ sưu tập (Collection) | Số lượng trước test | Số lượng sau test | Kết quả đối soát |
| :--- | :--- | :--- | :--- |
| `san_pham` | `2473` | `2473` | **KHỚP 100% — Không có bất kỳ đột biến (mutation) nào** |
| `danh_muc` | `28` | `28` | **KHỚP 100% — Toàn vẹn danh mục** |
| `flash_sale_campaigns` | `0` | `0` | **SẠCH 100% — Không còn campaign test nào lưu lại trên Atlas** |
| `hoa_don` (test records) | `0` | `0` | **Đã xóa sạch bản ghi test** |
