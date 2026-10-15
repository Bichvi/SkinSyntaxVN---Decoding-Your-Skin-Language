# PHASE 2 IMPLEMENTATION PLAN: PERSONALIZED FLASH SALE (PROPOSAL ONLY)

**Dự án:** SkinSyntaxVN — Decoding Your Skin Language  
**Mục tiêu:** Kế hoạch thực hiện từng bước (Step-by-Step Implementation Roadmap) cho Phase 2 khi được phê duyệt.  
**Cảnh báo quan trọng:** **ĐÂY LÀ KẾ HOẠCH ĐỀ XUẤT. TUYỆT ĐỐI KHÔNG THỰC THI (DO NOT EXECUTE) CÁC BƯỚC NÀY TRONG PHASE 1.**  

---

## 1. Lộ Trình Triển Khai 7 Giai Đoạn (7 Phased Stages)

```
[2A: Model & Lifecycle] ──► [2B: Admin CRUD] ──► [2C: Effective Pricing]
                                                       │
                                                       ▼
[2F: Homepage Top 4] ◄── [2E: Deal Ranking Engine] ◄── [2D: All Deals Page]
        │
        ▼
[2G: End-to-End Browser QA & Price Integrity Regression]
```

---

### Giai Đoạn 2A — Flash Sale Campaign Model & Server Lifecycle Engine
* **Nhiệm vụ:**
  1. Tạo model `backend/app/models/FlashSaleCampaign.php`.
  2. Định nghĩa cấu trúc document cho collection `flash_sale_campaigns`:
     * `ma_campaign` (int, auto-increment qua `getNextNumericId`).
     * `ten_campaign` (string).
     * `mo_ta` (string, nullable).
     * `start_at` (BSON UTCDateTime).
     * `end_at` (BSON UTCDateTime).
     * `is_active` (boolean, default true).
     * `items`: Mảng danh sách sản phẩm `[{ ma_san_pham, regular_price, flash_price, phan_tram_giam, so_luong_gioi_han }]`.
     * `created_at`, `updated_at`.
  3. Xây dựng các helper xác định trạng thái theo giờ server:
     * `getActiveCampaign(): ?array`
     * `getCampaignStatus(array $campaign): string` (`UPCOMING` | `ACTIVE` | `ENDED`).
  4. Quy tắc chống xung đột: Hàm `validateCampaignNoConflict($startAt, $endAt, array $productIds, ?int $excludeCampaignId = null): bool`.

---

### Giai Đoạn 2B — Admin CRUD Management Module
* **Nhiệm vụ:**
  1. Bổ sung các phương thức vào `backend/app/controllers/QuanTriController.php`:
     * `adminFlashSales()`: Hiển thị danh sách chiến dịch kèm trạng thái real-time.
     * `adminFlashSaleEdit()`: Giao diện tạo/sửa chiến dịch, nạp bảng chọn sản phẩm.
     * `adminFlashSaleSave()`: Xử lý POST lưu chiến dịch, bắt buộc xác thực:
       * Tên không rỗng.
       * $0 < \text{flash\_price} \le 0.95 \times \text{gia\_ban}$.
       * $\text{start\_at} < \text{end\_at}$.
       * Kiểm tra không xung đột sản phẩm với chiến dịch khác.
     * `adminFlashSaleDelete()`: Xóa chiến dịch.
     * `adminFlashSalePreview()`: Xem trước thứ tự deal theo từng trạng thái người dùng.
  2. Bổ sung view: `frontend/views/admin/flash_sales.php` và `frontend/views/admin/flash_sale_form.php`.
  3. Đăng ký các route tương ứng trong `index.php`.

---

### Giai Đoạn 2C — Effective Pricing Integration (Bảo Toàn Giá End-to-End)
* **Nhiệm vụ trọng yếu:** Xóa bỏ rào cản Price Disconnect đã phát hiện ở kiểm toán Phase 1.
  1. Xây dựng service hoặc helper tập trung: `get_effective_product_price(array $product, ?array $activeCampaign = null): array` trả về:
     * `effective_price`: Giá thực tế phải trả (Flash price nếu campaign active, ngược lại là regular price).
     * `is_flash_sale`: Boolean.
     * `discount_percent`: % giảm thực tế.
     * `original_price`: Giá gốc để gạch ngang.
  2. Tích hợp đồng bộ vào 4 điểm chốt chặn:
     * `frontend/views/helpers.php` & `ProductCard`: Hiển thị đúng giá ưu đãi và badge sale.
     * `backend/app/controllers/HomeController.php:giohang()`: Giỏ hàng hiển thị giá flash sale nếu campaign còn hiệu lực.
     * `backend/app/controllers/HomeController.php:buildCheckoutPreview()`: Tính tổng tiền tạm tính (`subtotal`) theo giá flash sale.
     * `backend/app/models/HoaDon.php:fetchProductPrice()`: Lưu chính xác `don_gia` là flash price vào `chi_tiet_hoa_don`.

---

### Giai Đoạn 2D — Dedicated All Deals Page (`index.php?r=flash_sale`)
* **Nhiệm vụ:**
  1. Đăng ký route mới `case 'flash_sale':` trong `index.php`.
  2. Bổ sung phương thức `SanPhamController::flashSale()` hoặc `HomeController::flashSale()`.
  3. Tạo view mới: `frontend/views/flash_sale.php`:
     * Banner chiến dịch với tên do Admin đặt + Đồng hồ đếm ngược server-driven.
     * Thanh công cụ sắp xếp: `Dành cho bạn`, `Giảm nhiều nhất`, `Đánh giá cao`, `Giá thấp → cao`, `Giá cao → thấp`.
     * Grid hiển thị đầy đủ 12–20 sản phẩm của chiến dịch.
     * Xử lý trường hợp không có campaign nào đang active: Render Empty State thân thiện ("Hiện chưa có đợt Flash Sale nào đang mở. Hãy đón chờ phiên ưu đãi tiếp theo!").

---

### Giai Đoạn 2E — Personalized Deal Ranking Engine (Thuật Toán Xếp Hạng Deal)
* **Nhiệm vụ:**
  1. Triển khai phương thức xếp hạng trong model:
     `FlashSaleCampaign::rankCampaignDeals(array $campaign, ?array $userProfile, array $behaviorSignals): array`
  2. Thực hiện kiến trúc Two-Stage (Option C):
     * Stage 1: Lấy tập candidate 12–20 items của campaign đang ACTIVE.
     * Stage 2: Nạp bộ gợi ý `ContentBasedRecommender` chạy trên tập candidate này theo User States A, B, C, D, E.
     * Stage 3: Chấm điểm kết hợp đa mục tiêu:
       $$\text{Score} = w_{\text{rel}} \cdot \text{PersonalRelevance} + w_{\text{disc}} \cdot \text{DiscountStrength} + w_{\text{pop}} \cdot \text{SocialProof}$$
     * Gắn Grounded Reason Tags tương ứng với tín hiệu kích hoạt thực tế.
  3. Thực hiện Diversity Filter: Đảm bảo không quá 2 sản phẩm cùng một dòng thương hiệu nằm liền kề trong top đầu.

---

### Giai Đoạn 2F — Homepage Top 4 Merchandising & Presentation
* **Nhiệm vụ:**
  1. Nâng cấp section Flash Sale trong `frontend/views/home.php`:
     * Tiêu đề chính lấy động từ `ten_campaign` của active campaign.
     * Subtitle thông minh: *"Ưu đãi chớp nhoáng được sắp xếp phù hợp với làn da của bạn"*.
     * Nút "Xem tất cả deal &rarr;" cập nhật trỏ về `index.php?r=flash_sale` (thay vì `index.php?r=tatca`).
  2. Hiển thị đúng Top 4 sản phẩm đứng đầu sau khi rank.
  3. Sửa mã JavaScript Countdown Timer:
     * Nhận timestamp kết thúc từ server.
     * Khi đếm về 0: Dừng đếm, hiển thị "Ưu đãi đã kết thúc", tự động làm mới giao diện, **tuyệt đối không tự reset về 23:59:59 của ngày hôm sau.**

---

### Giai Đoạn 2G — End-to-End QA, Browser Testing & Regression Verification
* **Nhiệm vụ:**
  1. **Kiểm thử phân quyền Admin:** Đảm bảo chỉ tài khoản Admin mới tạo/sửa được Flash Sale.
  2. **Kiểm thử vòng đời thời gian:**
     * Test campaign ở trạng thái `UPCOMING`: Chưa hiển thị trên Homepage, chưa giảm giá.
     * Test chuyển sang `ACTIVE`: Homepage tự động xuất hiện, giá giảm có hiệu lực.
     * Test khi đến `end_at`: Đồng hồ dừng lại, giá tự động trở về bình thường.
  3. **Kiểm thử cá nhân hóa:**
     * Kiểm thử với Guest (State A): Thứ tự ưu tiên deal giảm mạnh + đánh giá cao.
     * Kiểm thử với User da dầu (State C/D): Các deal kiềm dầu/BHA/Niacinamide vươn lên Top 4.
     * Kiểm thử với User da khô: Các deal cấp ẩm/B5/Ceramide vươn lên Top 4.
  4. **Kiểm thử toàn vẹn giá (Price Integrity Audit):**
     * Thêm sản phẩm Flash Sale vào giỏ hàng &rarr; Kiểm tra giá trong giỏ = `flash_price`.
     * Tiến hành thanh toán &rarr; Kiểm tra tổng tiền = `flash_price * qty`.
     * Kiểm tra cơ sở dữ liệu `chi_tiet_hoa_don.don_gia` = `flash_price`.
     * Kiểm tra can thiệp giá từ console trình duyệt bị chặn 100%.
