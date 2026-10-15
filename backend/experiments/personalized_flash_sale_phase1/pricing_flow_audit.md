# END-TO-END PRICING FLOW AUDIT & TAMPERING RISK ANALYSIS

**Dự án:** SkinSyntaxVN — Decoding Your Skin Language  
**Mục tiêu:** Kiểm toán đường đi của giá (Price Flow) từ Giao diện qua Giỏ hàng, Thanh toán tới Đơn hàng; Đánh giá nguy cơ can thiệp giá (Price Tampering) và Tính khả thi cho Flash Sale.  
**Môi trường:** Production Codebase & MongoDB Atlas Canonical  
**Thời gian thực hiện:** Tháng 10/2026  

---

## 1. Trace Luồng Giá Từng Bước (End-to-End Price Flow)

```
[1. Product Listing / Homepage / Card]
  │  View: frontend/views/home.php:221
  │  Display: vnd($p['gia_ban']) & vnd($p['gia_thi_truong'])
  │  Form POST: ma_san_pham, quantity (KHÔNG GỬI GIÁ)
  ▼
[2. Product Detail Page]
  │  Controller: SanPhamController::chitiet() (backend/app/controllers/SanPhamController.php:407)
  │  Data source: $p = $this->model->find($id) -> $p['gia_ban']
  │  Form POST: ma_san_pham, quantity (KHÔNG GỬI GIÁ)
  ▼
[3. Add to Cart Processing]
  │  Controller: SanPhamController::addToCartResult() (SanPhamController.php:115-212)
  │  Input: reads only product_id, qty. Ignores any client price field.
  │  Validation: checks stock in MongoDB (so_luong_ton_kho).
  │  Session Storage: $_SESSION['gio_hang'][$cartProductId] = $currentQty + $qty
  │  CRITICAL: Giỏ hàng chỉ lưu SKU ID và Số lượng! KHÔNG LƯU SNAPSHOT GIÁ!
  ▼
[4. Cart View Page]
  │  Controller: HomeController::giohang() (HomeController.php:479-520)
  │  Processing: loops $_SESSION['gio_hang'], calls SanPham::findById($product_id)
  │  Display: Lấy trực tiếp $product['gia_ban'] từ database MongoDB.
  ▼
[5. Checkout Preview Calculation]
  │  Controller: HomeController::buildCheckoutPreview() (HomeController.php:960-1000)
  │  Input: $_SESSION['checkout_items'] (mảng SKU ID => qty)
  │  Processing: 
  │     $product = $this->model->findById((string)$productId);
  │     $unitPrice = (int)($product['gia_ban'] ?? 0);
  │     if ($unitPrice <= 0) $unitPrice = (int)($product['gia_thi_truong'] ?? 0);
  │     $lineTotal = $unitPrice * $qty;
  │     $subtotal += $lineTotal;
  │  Subtotal: Tính toán động trên server từ MongoDB san_pham.
  ▼
[6. Order Submission (Checkout Action)]
  │  Controller: HomeController::xulydathang() (HomeController.php:4377-4520)
  │  Backend re-runs buildCheckoutPreview() để xác thực tồn kho và tính lại subtotal.
  │  Tính toán Voucher ($voucherDiscountAmount) và Điểm thưởng ($pointsDiscountAmount).
  │  Calls: $this->hoaDonModel->taoDonHang([...])
  ▼
[7. Order & Order Detail Persistence]
  │  Model: HoaDon::taoDonHang() (backend/app/models/HoaDon.php:550-680)
  │  Fetch unit price:
  │     $donGia = $this->fetchProductPrice($maSanPham); // HoaDon.php:116-130
  │     (Query MongoDB san_pham: lấy gia_ban, nếu = 0 lấy gia_thi_truong)
  │  Insert hoa_don: tam_tinh, so_tien_giam, tien_giam_diem, tong_tien
  │  Insert chi_tiet_hoa_don: ma_hoa_don, ma_san_pham, so_luong, don_gia
```

---

## 2. Bảng Phân Tích Chi Tiết Từng Bước

| Bước | Price lấy từ đâu? | Browser có gửi price không? | Backend có trust client price không? | Dữ liệu lưu trong Cart / Session | Cơ chế tính toán |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **1. Listing / Home** | `san_pham.gia_ban` | Không | Không áp dụng | Chưa có | Render thẻ HTML |
| **2. Detail** | `san_pham.gia_ban` từ DB | Không | Không áp dụng | Chưa có | Render chi tiết HTML |
| **3. Add to Cart** | Không đọc giá | Trình duyệt chỉ gửi `ma_san_pham`, `quantity` | Không đọc bất kỳ field giá nào từ POST | `$_SESSION['gio_hang'][$id] = $qty` | Tăng số lượng trong session |
| **4. Cart View** | Truy vấn lại `san_pham` theo ID | Không | Không | `$_SESSION['gio_hang']` chỉ giữ ID & số lượng | Đọc `gia_ban` từ DB tại thời điểm render |
| **5. Checkout Preview**| Truy vấn `san_pham.gia_ban` | Không | Không | `$_SESSION['checkout_items'][$id] = $qty` | Server nhân `gia_ban * qty` |
| **6. Checkout Submit** | Server tính lại từ `buildCheckoutPreview` | Không (POST chỉ gửi thông tin nhận hàng & phương thức TT) | Hoàn toàn KHÔNG tin client | Dọn dẹp session sau khi tạo đơn thành công | Trừ voucher, trừ điểm, cộng phí ship 30.000đ |
| **7. Order Persistence**| `HoaDon::fetchProductPrice()` query DB | Không | Không | Ghi vào MongoDB: `hoa_don` & `chi_tiet_hoa_don` | `don_gia` lưu chính xác giá snapshot từ DB lúc đặt |

---

## 3. Đánh Giá Nguy Cơ Can Thiệp Giá (Client Price Tampering)

* **Kết luận an ninh:** Trong luồng được kiểm toán, backend không tin giá do client gửi; không phát hiện client-side price trust trong các đường dẫn đã kiểm tra.
* **Căn cứ kỹ thuật:**
  1. Trình duyệt không bao giờ truyền giá lên server trong bất kỳ request nào (`them_gio_hang_ajax`, `giohang`, `xulydathang`).
  2. Dù kẻ tấn công có cố tình sửa mã nguồn HTML hoặc dùng Postman/cURL để chèn thêm `price: 1000` hoặc `gia_ban: 0` vào form POST, backend tại `SanPhamController::addToCartResult` và `HomeController::xulydathang` hoàn toàn không đọc trường này.
  3. Giá của từng đơn vị sản phẩm (`don_gia`) khi ghi vào bảng `chi_tiet_hoa_don` được triệu gọi độc lập bởi hàm `HoaDon::fetchProductPrice($maSanPham)` trực tiếp từ database MongoDB `san_pham`.

---

## 4. Phát Hiện Kiến Trúc Trọng Yếu Đối Với Flash Sale (Architectural Blocker)

Mặc dù kiến trúc hiện tại miễn nhiễm với Price Tampering, việc kiểm toán đã làm lộ diện một **rào cản cốt lõi (Architectural Blocker)** nếu muốn triển khai Flash Sale:

1. **Hiện tượng gián đoạn giá (Price Disconnect):**
   * Nếu Admin cấu hình một chiến dịch Flash Sale với giá ưu đãi (`flash_price = 150.000đ`), trong khi giá gốc trong `san_pham` là `gia_ban = 250.000đ`.
   * Khách hàng thấy thẻ Flash Sale trên Homepage ghi 150.000đ.
   * Nhưng khi bấm "Thêm vào giỏ" -> Vào Giỏ hàng -> Thanh toán -> Tạo đơn:
     Hàm `buildCheckoutPreview()` và `HoaDon::fetchProductPrice()` **chỉ đọc duy nhất `san_pham.gia_ban` (250.000đ)**.
   * **Hậu quả nghiêm trọng:** Đơn hàng được tạo sẽ bị tính giá cũ (250.000đ)! Khách hàng bị tính tiền sai lệch so với giá Flash Sale được quảng bá.

2. **Tại sao không thể giải quyết bằng cách sửa thẳng `san_pham.gia_ban`?**
   * Nếu khi chiến dịch bắt đầu, Admin ghi đè `san_pham.gia_ban = 150.000đ`:
     * Khi chiến dịch kết thúc hoặc bị hủy, nếu server gặp sự cố hoặc cron job không chạy, giá sẽ bị giảm vĩnh viễn (data corruption).
     * Mất lịch sử giá ban đầu.
     * Xung đột khi có nhiều chiến dịch hoặc chương trình khác nhau.
   * Vi phạm nguyên tắc bất biến: Dữ liệu catalog (`san_pham`) phải độc lập với dữ liệu khuyến mãi theo thời gian (`flash_sale_campaigns`).

3. **Giải pháp kiến trúc bắt buộc (Architectural Requirement):**
   * Cần xây dựng một **Effective Pricing Engine** (hoặc helper tập trung `getEffectiveProductPrice($productId)`).
   * Engine này kiểm tra:
     * Sản phẩm có nằm trong một Flash Sale Campaign đang ở trạng thái **ACTIVE** (theo giờ server) không?
     * Nếu CÓ: Trả về `flash_price`.
     * Nếu KHÔNG: Trả về `gia_ban` thông thường của sản phẩm.
   * Engine này phải được cắm đồng bộ vào cả 4 điểm chốt chặn:
     1. Product Card / Detail View (hiển thị).
     2. `HomeController::giohang()` (hiển thị giỏ hàng).
     3. `HomeController::buildCheckoutPreview()` (xem trước thanh toán).
     4. `HoaDon::fetchProductPrice()` (tạo hóa đơn và chi tiết hóa đơn).
