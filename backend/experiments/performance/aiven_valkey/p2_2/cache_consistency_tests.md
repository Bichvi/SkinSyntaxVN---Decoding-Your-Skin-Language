# BÁO CÁO THỰC THI KIỂM THỬ TÍNH NHẤT QUÁN BỘ NHỚ ĐỆM (CACHE CONSISTENCY TESTS)

**Bộ kiểm thử:** `backend/tests/test_p2_2_cache_consistency.php`  
**Số kịch bản kiểm thử:** 12 kịch bản chính thức (26 assertions độc lập)  
**Tỷ lệ đạt chuẩn:** **26/26 PASS (100%)**  
**Chính sách dữ liệu kiểm thử:** Toàn bộ dữ liệu kiểm thử là QA Isolated Data (ID độc lập `999988`), hoàn toàn không chỉnh sửa hay ảnh hưởng tới các chiến dịch và sản phẩm thật trên hệ thống. Dữ liệu QA được dọn dẹp an toàn trong khối `finally`.

---

## 1. BẢNG TỔNG HỢP 12 KỊCH BẢN KIỂM THỬ

| STT | Kịch bản kiểm thử | Mô tả chi tiết & Phương pháp kiểm chứng | Số assertions | Kết quả |
| :---: | :--- | :--- | :---: | :---: |
| **1** | **Homepage Cache HIT** | Thực hiện gọi `getHomepagePublicData()` 2 lần liên tiếp. Lần 1 sinh dữ liệu và lưu vào Valkey `home:composite_public`. Lần 2 đọc trực tiếp từ Valkey Cache HIT, dữ liệu trả về đầy đủ và trùng khớp. | 3/3 | **PASS** |
| **2** | **Admin thay đổi Flash Sale** | Tạo chiến dịch QA bằng `saveCampaign()` với sản phẩm và giá hợp lệ. Hệ thống ngay lập tức kích hoạt `clearRequestCache()` và xóa khóa `home:composite_public` trên Valkey. | 3/3 | **PASS** |
| **3** | **Homepage phản ánh thay đổi** | Truy cập trang chủ ngay sau khi Admin tạo / toggle / xóa Flash Sale. Dữ liệu mới được nạp tức thì từ MongoDB Atlas, không bị giữ trạng thái cũ. | 3/3 | **PASS** |
| **4** | **Campaign đến thời điểm bắt đầu** | Giả lập cache chứa metadata ranh giới thời gian `__next_transition_ts` đã trôi qua. `getHomepagePublicData()` phát hiện mốc bắt đầu đã tới, chủ động evict cache và tái tạo authoritative. Hàm `getNextCampaignTransitionTimestamp()` tính toán chính xác mốc kế tiếp. | 3/3 | **PASS** |
| **5** | **Campaign đến thời điểm kết thúc** | Giả lập cache chứa chiến dịch có `end_at_ts` nhỏ hơn thời gian hiện tại (`time() - 5s`). Read Guard phát hiện vi phạm ranh giới, từ chối trả về chiến dịch hết hạn và xóa cache. | 1/1 | **PASS** |
| **6** | **Sản phẩm bị ẩn** | Tạo sản phẩm QA, populate cache, sau đó Admin đổi trạng thái thành `inactive`. Invalidation tự động xóa `home:*`, `catalog:*`, và `product:detail:*`. Hàm `find(id, true)` từ chối hiển thị sản phẩm trên Storefront. | 4/4 | **PASS** |
| **7** | **Giá sản phẩm thay đổi** | Cập nhật giá sản phẩm và kích hoạt `SanPham::invalidateProductCache($id)`. Hệ thống xóa sạch `home:composite_public` và `product:detail:$id`. | 2/2 | **PASS** |
| **8** | **Video được publish / unpublish** | Thay đổi trạng thái xuất bản video qua `ProductVideo::invalidateCache()`. Khóa pattern `product:videos:*` bị xóa sạch hoàn toàn trên Valkey. | 1/1 | **PASS** |
| **9** | **Valkey Unavailable** | Khởi tạo kết nối tới endpoint Valkey không thể kết nối (`rediss://127.0.0.1:29999`). Kiểm tra các thao tác `get()`, `set()`, `delete()` đều trả về fallback an toàn hoặc `false`, không ném Fatal Error. | 3/3 | **PASS** |
| **10** | **Checkout vẫn đọc giá authoritative** | Kiểm tra logic tính giá của đơn hàng (`HoaDon::fetchProductPrice` và `FlashSaleCampaign::resolveEffectivePrice`). Giá sản phẩm dùng cho Checkout được lấy trực tiếp từ MongoDB, hoàn toàn độc lập với Valkey. | 1/1 | **PASS** |
| **11** | **Recommendation không thay đổi** | Kiểm tra hệ thống gợi ý `SanPham::getSimpleRecommenderProducts(6)`. Thuật toán sinh danh sách sản phẩm hợp lệ, không bị can thiệp bởi cache public hay Flash Sale. | 1/1 | **PASS** |
| **12** | **Không rò dữ liệu cá nhân** | Quét toàn bộ payload JSON của `home:composite_public`. Đảm bảo không chứa bất kỳ trường dữ liệu người dùng nào (`customer_id`, `email`, `gio_hang`, `cart_items`, `search_history`). | 1/1 | **PASS** |

---

## 2. KẾT QUẢ THỰC THI TERMINAL TRỰC TIẾP

```
================================================================================
SKINSYNTAXVN — PHASE P2.2 CACHE CONSISTENCY & FLASH SALE SAFETY TEST SUITE
================================================================================

1. Kiểm tra Homepage Cache HIT...
  [PASS] Lần 1 getHomepagePublicData() sinh dữ liệu hợp lệ
  [PASS] Cache key home:composite_public đã tồn tại trên Valkey
  [PASS] Lần 2 getHomepagePublicData() đọc từ Cache HIT

2 & 3. Kiểm tra Admin thay đổi Flash Sale & Homepage phản ánh thay đổi...
  [PASS] Pre-condition: home:composite_public tồn tại trước mutation
  [PASS] Admin tạo QA Flash Sale Campaign thành công
  [PASS] Sau khi Admin lưu Flash Sale, composite cache home:composite_public bị xóa
  [PASS] Homepage tự nạp dữ liệu mới và phản ánh Flash Sale mới
  [PASS] Sau khi Admin tắt (toggle) Flash Sale, home:composite_public bị xóa
  [PASS] Sau khi Admin xóa Flash Sale, home:composite_public bị xóa

4. Kiểm tra Campaign đến thời điểm bắt đầu (Dynamic TTL & Read Guard)...
  [PASS] Giả lập cache có __next_transition_ts đã vượt qua mốc bắt đầu
  [PASS] getHomepagePublicData() phát hiện mốc bắt đầu đã tới, evict cache và tái tạo authoritative
  [PASS] getNextCampaignTransitionTimestamp() trả về kết quả hợp lệ (null hoặc timestamp tương lai)

5. Kiểm tra Campaign đến thời điểm kết thúc (Active -> Ended Guard)...
  [PASS] getHomepagePublicData() từ chối campaign hết hạn trong cache và recompute

6. Kiểm tra Sản phẩm bị ẩn (Product Visibility Invalidation)...
  [PASS] Sau khi ẩn sản phẩm, home:composite_public bị xóa
  [PASS] Sau khi ẩn sản phẩm, product:detail bị xóa
  [PASS] Sau khi ẩn sản phẩm, catalog:* bị xóa
  [PASS] find(id, true) từ chối trả về sản phẩm đã bị ẩn trên Storefront

7. Kiểm tra Giá sản phẩm thay đổi & Invalidation...
  [PASS] Sau khi thay đổi giá sản phẩm, home:composite_public bị xóa
  [PASS] Sau khi thay đổi giá sản phẩm, product:detail bị xóa

8. Kiểm tra Video Commerce Publish / Unpublish Invalidation...
  [PASS] ProductVideo::invalidateCache() xóa sạch product:videos:*

9. Kiểm tra Valkey Unavailable (Graceful Circuit Breaker)...
  [PASS] Valkey sập: get() trả về fallback an toàn không gây Fatal Error
  [PASS] Valkey sập: set() trả về false an toàn
  [PASS] Valkey sập: delete() trả về false an toàn

10. Kiểm tra Checkout luôn đọc giá Authoritative từ MongoDB...
  [PASS] Giá tính toán cho Checkout lấy trực tiếp từ MongoDB (không qua Valkey)

11. Kiểm tra Recommendation Engine không bị ảnh hưởng...
  [PASS] Recommendation Engine trả về danh sách sản phẩm hợp lệ

12. Kiểm tra An toàn Bảo mật: Không rò rỉ dữ liệu cá nhân trong Cache...
  [PASS] Composite cache home:composite_public hoàn toàn không chứa dữ liệu cá nhân/user/session

================================================================================
TỔNG KẾT STEP 4 (CACHE CONSISTENCY & FLASH SALE SAFETY): PASS: 26 | FAIL: 0
================================================================================
>>> TẤT CẢ 12 KỊCH BẢN KIỂM THỬ ĐỀU ĐẠT CHUẨN 100%! <<<
```
