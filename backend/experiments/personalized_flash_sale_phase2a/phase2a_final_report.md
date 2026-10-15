# BÁO CÁO NGHIỆM THU CUỐI CÙNG PHASE 2A: NỀN TẢNG CHIẾN DỊCH VÀ ĐỘNG CƠ PHÂN GIẢI GIÁ HIỆU LỰC
(PHASE 2A FINAL REPORT)

**Hệ thống**: SkinSyntaxVN — Personalized Flash Sale  
**Giai đoạn**: Phase 2A — Campaign Domain + Effective Pricing Foundation  
**Môi trường thực thi**: MongoDB Atlas (`DB_MODE=atlas`, Database: `skinsyntax`)  
**Catalog metrics**: `san_pham = 2473`, `danh_muc = 28`  
**Ngày hoàn thành**: 2026-10-07  

---

## I. TỔNG QUAN KẾT QUẢ TRIỂN KHAI

Phase 2A đã xây dựng thành công 100% nền tảng backend cho tính năng Flash Sale mà không gây xáo trộn bất kỳ kiến trúc hiện có nào:
1. **Domain Model**: Thiết lập collection độc lập `flash_sale_campaigns` và model [FlashSaleCampaign.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/FlashSaleCampaign.php).
2. **Server-Derived Lifecycle**: Vòng đời chiến dịch (`DISABLED`, `UPCOMING`, `ACTIVE`, `ENDED`) được tính toán động từ server-time, loại bỏ triệt để rủi ro trạng thái lỗi thời (stale status).
3. **Chính sách 3 tầng múi giờ**: Lưu trữ UTC trong MongoDB, so sánh theo Unix timestamp số nguyên, hiển thị định dạng `Asia/Ho_Chi_Minh`.
4. **Validation nghiệp vụ chặt chẽ**: Từ chối giá âm, giá Flash Sale $\ge$ giá niêm yết, SKU không tồn tại, SKU trùng lặp nội bộ, và từ chối các chiến dịch có khung giờ giao thoa (overlap) cho cùng SKU.
5. **Động cơ phân giải giá duy nhất (`resolveEffectivePrice`)**: Tích hợp xuyên suốt từ Giỏ hàng (`giohang`), Xem trước thanh toán (`buildCheckoutPreview`), đến Chốt đơn hàng thực tế (`HoaDon::fetchProductPrice` / `taoDonHang`).
6. **Bảo toàn dữ liệu tuyệt đối**: Không thay đổi giá niêm yết trong `san_pham`, không thay đổi bất kỳ thuật toán gợi ý nào, không sửa đổi Homepage hay Admin UI.

---

## II. TRẢ LỜI ĐẦY ĐỦ 14 CÂU HỎI QUY ĐỊNH (Q1 - Q14)

### Q1. Collection và model nào đã được tạo?
- **Collection**: `flash_sale_campaigns` trên MongoDB Atlas database `skinsyntax`.
- **Model**: Class [FlashSaleCampaign](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/FlashSaleCampaign.php) tại đường dẫn `backend/app/models/FlashSaleCampaign.php`.

### Q2. Giá sản phẩm gốc có được giữ nguyên không?
- **CÓ, NGUYÊN VẸN 100%**. Tuyệt đối không ghi đè hay thay đổi bất kỳ trường nào (`gia_ban`, `gia_thi_truong`) trong 2,473 document của collection `san_pham`.

### Q3. Trạng thái chiến dịch được suy diễn như thế nào?
- Trạng thái **hoàn toàn suy diễn từ thời gian server** (Server-Derived Lifecycle) tại thời điểm gọi hàm `getCampaignStatus(array $campaign, ?int $nowTimestamp = null)`:
  - `DISABLED`: khi `is_active == false` hoặc `enabled == false`.
  - `UPCOMING`: khi `is_active == true && now < start_at`.
  - `ACTIVE`: khi `is_active == true && start_at <= now < end_at`.
  - `ENDED`: khi `is_active == true && now >= end_at`.
  - Không lưu cứng trạng thái `ACTIVE` hay `ENDED` vào cơ sở dữ liệu.

### Q4. Chính sách múi giờ chính xác là gì?
- **Storage Timezone**: UTC (+00:00) lưu dưới dạng `\MongoDB\BSON\UTCDateTime`.
- **Comparison Timezone**: Unix timestamp số nguyên (seconds since epoch in UTC). Tuyệt đối không so sánh chuỗi ngày tháng.
- **Display Timezone**: `Asia/Ho_Chi_Minh` (+07:00), định dạng hiển thị `Y-m-d H:i:s`.

### Q5. Giá hiệu lực được phân giải ở đâu?
- Tại một cổng logic duy nhất: phương thức `FlashSaleCampaign::resolveEffectivePrice(productId, now, preloadedProduct)` và được bọc qua helper `get_effective_product_price()` trong [frontend/views/helpers.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/helpers.php).

### Q6. Giỏ hàng có lưu giá không?
- **KHÔNG**. Giỏ hàng (`$_SESSION['gio_hang']`) chỉ lưu trữ cấu trúc nguyên bản: `[ ma_san_pham => so_luong ]`. Giá luôn được phân giải động từ server mỗi khi xem giỏ.

### Q7. Quy trình Thanh toán (Checkout) có tin giá do client gửi không?
- **KHÔNG**. Trong luồng được kiểm toán, backend không tin giá do client gửi; không phát hiện client-side price trust trong các đường dẫn đã kiểm tra. Mọi tham số giá như `price`, `gia_ban`, `unit_price` trong HTTP request đều bị bỏ qua; server tự tính toán lại toàn bộ.

### Q8. Điều gì xảy ra khi chiến dịch Flash Sale hết hạn ngay trước khi người dùng gửi đơn đặt hàng?
- Tại thời điểm nhấn nút đặt hàng (`xulydathang`), server phân giải lại giá hiệu lực.
- Nếu phát hiện tổng tiền tạm tính có sự chênh lệch so với giá trị lúc xem trước (`checkout_preview_subtotal`) do chiến dịch vừa kết thúc:
  - Server **từ chối tạo đơn hàng với giá cũ**.
  - Tự động chuyển hướng người dùng quay lại trang thanh toán kèm thông báo rõ ràng yêu cầu người dùng xác nhận lại đơn giá niêm yết hiện hành.

### Q9. Chi tiết đơn hàng (`chi_tiet_hoa_don`) có lưu giữ đúng đơn giá do server phân giải không?
- **CÓ**. Hàm `HoaDon::fetchProductPrice` và `taoDonHang` phân giải đơn giá trực tiếp từ `resolveEffectivePrice()`. Trường `chi_tiet_hoa_don.don_gia` lưu chính xác đơn giá Flash Sale tại thời điểm đặt hàng. Tổng tiền `tam_tinh` và `tong_tien` được tính toán lại từ đơn giá này.

### Q10. Các chiến dịch có khung giờ trùng lặp cho cùng một sản phẩm có bị từ chối không?
- **CÓ, BỊ TỪ CHỐI TUYỆT ĐỐI**. Hàm `checkOverlappingCampaigns` kiểm tra điều kiện giao thoa khoảng thời gian ($\max(start_1, start_2) < \min(end_1, end_2)$). Nếu phát hiện SKU đã tham gia chiến dịch khác đang bật trong cùng khung giờ, server lập tức trả về lỗi validation và không lưu dữ liệu.

### Q11. Có thuật toán gợi ý (recommender) nào bị thay đổi không?
- **KHÔNG**. Toàn bộ mã nguồn của: Simple Weighted Rating, ContentBasedRecommender, Adaptive Routing, Collaborative Filtering, BPR, KMeans, Apriori, FP-Growth được giữ nguyên 100%.

### Q12. Có giao diện người dùng nào của Trang chủ hoặc Admin Flash Sale bị thay đổi không?
- **KHÔNG**. Section Flash Sale trên Homepage giữ nguyên trạng (chưa thay đổi countdown, chưa gắn link `r=flash_sale`, chưa cá nhân hóa). Chưa xây dựng bất kỳ giao diện Admin UI nào trong Phase 2A.

### Q13. Toàn bộ các chiến dịch và bản ghi kiểm thử đã được dọn dẹp chưa?
- **CÓ, SẠCH HOÀN TOÀN**. Bộ test suite tự động xóa 100% các document có tiền tố `TEST_FLASHSALE_PHASE2A_*`, đơn hàng test `#39` và tài khoản test đã được dọn dẹp sạch sẽ khỏi Atlas, hoàn trả tồn kho đầy đủ.

### Q14. Số lượng `san_pham = 2473` và `danh_muc = 28` có được bảo toàn không?
- **CÓ, BẢO TOÀN CHÍNH XÁC**:
  - `san_pham`: `2473` documents (không tăng, không giảm, không đột biến).
  - `danh_muc`: `28` documents.

---

## III. BẢNG TỔNG HỢP KẾT QUẢ TEST SUITE TỰ ĐỘNG (18/18 PASS)

```
=========================================================
PHASE 2A: COMPREHENSIVE TEST SUITE FOR CAMPAIGN & PRICING
=========================================================
[PASS] TEST_A: No campaign produces REGULAR price
[PASS] TEST_B: Upcoming campaign produces REGULAR price
[PASS] TEST_C: Active campaign produces FLASH_SALE price (Discount: 20%)
[PASS] TEST_D: Ended campaign produces REGULAR price
[PASS] TEST_E: Disabled campaign produces REGULAR price
[PASS] TEST_F: Validation rejects flash_price >= regular_price
[PASS] TEST_G: Validation rejects flash_price <= 0
[PASS] TEST_H: Validation rejects nonexistent product SKU
[PASS] TEST_I: Validation rejects invalid/inactive product
[PASS] TEST_J: Validation rejects duplicate SKU inside campaign
[PASS] TEST_K: Validation rejects overlapping campaign interval for same product
[PASS] TEST_L: Active campaign for SKU B leaves SKU A at REGULAR price
[PASS] TEST_M: Invariant: Same product in campaign has exact same price for every user
[PASS] TEST_N: Checkout preview during active campaign calculates Flash price
[PASS] TEST_O: Expired campaign recalculates to canonical regular selling price
[PASS] TEST_P: Regular product checkout unaffected by flash sale system
[PASS] TEST_Q: Price tampering regression: Malicious extra POST fields ignored by server
[PASS] TEST_R: Order creation persists actual effective unit price in chi_tiet_hoa_don
=========================================================
OVERALL RESULT: ALL TESTS PASSED (18/18)
Catalog verification: san_pham=2473 | danh_muc=28 | active_test_campaigns=0
=========================================================
```

---

## IV. KẾT LUẬN NGHIỆM THU

PHASE 2A PASS — READY FOR PHASE 2B REVIEW
