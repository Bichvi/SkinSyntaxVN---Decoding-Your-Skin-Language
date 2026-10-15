# CAMPAIGN LIFECYCLE & PRICING INTEGRITY DESIGN

**Dự án:** SkinSyntaxVN — Decoding Your Skin Language  
**Mục tiêu:** Thiết kế mô hình vòng đời chiến dịch Flash Sale (Campaign Lifecycle), quy tắc tính toán thời gian từ máy chủ, cơ chế xử lý đồng hồ đếm ngược, quy tắc toàn vẹn giá (Price Invariant) và kiểm soát tồn kho/hình ảnh.  
**Môi trường:** Production Architecture  
**Thời gian thực hiện:** Tháng 10/2026  

---

## 1. Trạng Thái Vòng Đời Chiến Dịch (Campaign Lifecycle States)

Vòng đời của một chiến dịch Flash Sale được định nghĩa nghiêm ngặt thành 3 trạng thái duy nhất, dựa trên **Thời gian thực của máy chủ (Server Time Source of Truth)**:

```
                  now < start_at
   [Tạo mới] ────────────────────────► [ UPCOMING ] (Sắp diễn ra)
                                              │
                              start_at <= now │
                                              ▼
                             now < end_at     │
                     ┌────────────────────────┘
                     ▼
               [ ACTIVE ] (Đang diễn ra)
                     │
       now >= end_at │ (hoặc Admin manual toggle)
                     ▼
               [ ENDED ] (Đã kết thúc)
```

### 1.1 Chi Tiết Các Trạng Thái:
1. **UPCOMING (Sắp diễn ra):**
   * Điều kiện: `is_active == true` và `ServerNow() < start_at`.
   * Hiển thị: Chỉ xuất hiện trong màn hình quản trị Admin hoặc banner thông báo trước "Flash Sale sắp mở vào lúc HH:mm". Chưa áp dụng giá Flash Sale.
2. **ACTIVE (Đang diễn ra):**
   * Điều kiện: `is_active == true` và `start_at <= ServerNow() < end_at`.
   * Hiển thị: Xuất hiện trên Homepage (Top 4 cá nhân hóa) và trang Dedicated Flash Sale (`index.php?r=flash_sale`).
   * Giá có hiệu lực: Áp dụng `flash_price` cho tất cả người dùng.
3. **ENDED (Đã kết thúc):**
   * Điều kiện: `ServerNow() >= end_at` hoặc `is_active == false`.
   * Hiển thị: Biến mất khỏi Homepage (hoặc ẩn section nếu không có campaign kế tiếp). Trang Flash Sale thông báo "Chiến dịch đã khép lại".
   * Giá có hiệu lực: Lập tức vô hiệu hóa `flash_price`, trả về giá niêm yết gốc `gia_ban`.

---

## 2. Server Time Là Nguồn Chân Lý — Đồng Hồ Client Chỉ Mang Tính Trình Diễn

* **Nguyên tắc cốt lõi:** Trình duyệt của người dùng có thể bị sai lệch múi giờ, cố tình chỉnh lùi giờ hệ thống, hoặc treo tab qua đêm. **Do đó, client clock KHÔNG BAO GIỜ được dùng làm căn cứ nghiệp vụ.**
* **Truyền dữ liệu từ Server xuống View:**
  Thay vì để JavaScript tự tạo `new Date()` như hiện tại, PHP Controller sẽ truyền timestamp Unix kết thúc của chiến dịch:
  ```html
  <section data-flash-sale-countdown 
           data-end-timestamp="<?= $activeCampaign['end_at_unix'] ?>" 
           data-server-time="<?= time() ?>">
  ```
* **Hành vi khi đếm ngược về 00:00:00 (Reaches Zero):**
  1. **TUYỆT ĐỐI KHÔNG TỰ RESET.**
  2. Dừng timer (`clearInterval`).
  3. Thay thế đồng hồ bằng thông báo nhẹ nhàng: `<span class="badge bg-secondary">Ưu đãi đã kết thúc</span>`.
  4. Sau 3 giây, tự động kích hoạt reload trang hoặc làm mới khối Flash Sale qua AJAX để lấy trạng thái mới nhất từ server.
* **Bảo vệ tại cổng thanh toán (Checkout Guard):**
  * Nếu người dùng mở tab lúc 23:59:58 (đang ACTIVE) nhưng đến 00:02:00 mới bấm "Đặt hàng":
  * Khi request gửi tới `xulydathang()`, backend gọi `getEffectivePrice()`: tại thời điểm đó `ServerNow() >= end_at` &rarr; Chiến dịch đã là `ENDED`.
  * Hệ thống thông báo rõ ràng: *"Ưu đãi Flash Sale cho sản phẩm X vừa kết thúc. Giỏ hàng đã được cập nhật về giá tiêu chuẩn."*
  * Không cho phép tạo đơn với giá flash sale quá hạn.

---

## 3. Quy Tắc Bất Biến Về Giá (Price Invariant)

```
========================================================================
NGUYÊN TẮC BẤT BIẾN:
CÙNG MỘT CHIẾN DỊCH, CÙNG MỘT SẢN PHẨM:
MỌI NGƯỜI DÙNG ĐỀU NHẬN CÙNG MỘT MỨC GIÁ FLASH SALE DUY NHẤT.
========================================================================
```

* **Cá nhân hóa là gì?**
  * Cá nhân hóa **CHỈ ÁP DỤNG CHO THỨ TỰ HIỂN THỊ (RANKING)** của 12–20 deals trong campaign.
  * User A (da dầu mụn) thấy Serum Niacinamide nằm ở vị trí số 1.
  * User B (da khô lão hóa) thấy Kem dưỡng Ceramide nằm ở vị trí số 1.
* **Cá nhân hóa KHÔNG PHẢI là gì?**
  * Tuyệt đối **KHÔNG CÁ NHÂN HÓA MỨC GIÁ** (No Dynamic Price Discrimination).
  * Chai Serum Niacinamide giá gốc 300.000đ, giá Flash Sale 195.000đ: Cả User A, User B và Khách vãng lai (Guest) đều mua đúng giá 195.000đ.
  * Điều này đảm bảo tính minh bạch, công bằng thương mại, bảo vệ thương hiệu và tuân thủ các quy định bảo vệ người tiêu dùng.

---

## 4. Kiểm Soát Tồn Kho & Chống Gian Lận Cấp Bách (Inventory & Scarcity Integrity)

* **Hiện trạng kiểm toán tồn kho:**
  * Toàn bộ 2.473 sản phẩm đều có trường `so_luong_ton_kho > 0` (hầu hết mặc định ban đầu là 300).
  * Khi có đơn hàng thực tế, hệ thống có hàm `HoaDon::reserveStockForItems()` trừ dần tồn kho thật.
* **Nguyên tắc hiển thị cấp bách:**
  * **CẤM HOÀN TOÀN** việc tạo khan hiếm giả mạo như: *"Chỉ còn 2 sản phẩm"*, *"Sắp hết hàng trong 5 phút"* bằng JavaScript ngẫu nhiên.
  * Chỉ khi trường `so_luong_ton_kho` trong cơ sở dữ liệu thực sự $\le 5$, hệ thống mới kích hoạt tem cảnh báo chân thực: *"Chỉ còn X sản phẩm"*.
  * Nếu sản phẩm hết hàng (`so_luong_ton_kho <= 0` hoặc `trang_thai_kho == 'het_hang'`), sản phẩm tự động bị vô hiệu hóa nút mua và rơi xuống cuối danh sách ranking.

---

## 5. Merchandising Heuristic: Chất Lượng Hình Ảnh Cho Vị Trí Top 4 Homepage

* **Bối cảnh:**
  Khối Flash Sale trên Homepage là "mặt tiền" thẩm mỹ của website. Trong một số trường hợp, sản phẩm có link ảnh CDN bên ngoài có thể bị lỗi tải (broken link) hoặc hiển thị ảnh giữ chỗ (`default_placeholder_image()`).
* **Quy tắc Merchandising cho Top 4 Homepage:**
  1. Nếu có hai hoặc nhiều deal có điểm xếp hạng cá nhân hóa xấp xỉ nhau ($\Delta \text{score} < 0.05$): Ưu tiên đưa sản phẩm có ảnh hợp lệ lên Top 4 Homepage.
  2. **Ranh giới:** Đây là một heuristic trình bày bổ trợ (presentation tie-breaker), **tuyệt đối không được ghi đè lên độ phù hợp cá nhân hóa cốt lõi.**
  3. **Không loại bỏ khỏi All Deals:** Tuyệt đối không được ẩn hoặc xóa sản phẩm khỏi trang "Xem tất cả deal" chỉ vì ảnh chất lượng thấp hoặc đang dùng placeholder.
