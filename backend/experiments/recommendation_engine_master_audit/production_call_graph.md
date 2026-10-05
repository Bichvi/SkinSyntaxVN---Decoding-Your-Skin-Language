# Đồ Thị Gọi Hàm & Luồng Thực Thi Production (Production Call Graph)
## Phân Tích Thực Nghiệm Trực Tiếp Từ Mã Nguồn Runtime Hiện Tại

Tài liệu này truy vết chính xác luồng thực thi từ điểm vào công khai (Public Web Entry Point) của SkinSyntaxVN khi người dùng truy cập trang chủ (`index.php?r=home`). Toàn bộ tên file, hàm, class và đường dẫn dữ liệu được trích xuất trực tiếp từ mã nguồn thực tế đang chạy.

---

### 1. Đồ Thị Gọi Hàm Tổng Thể (Runtime Call Graph)

```
[HTTP GET index.php?r=home]
  │
  ├── 1. index.php (Lines 82-85)
  │     └── Dispatch routing: case 'home' -> (new HomeController($pdo))->index()
  │
  ├── 2. HomeController::index() (backend/app/controllers/HomeController.php:134)
  │     │
  │     ├── A. Xác thực người dùng & Lấy hồ sơ da:
  │     │     ├── Kiểm tra $_SESSION['user']['email']
  │     │     └── HomeController::buildRecommendationProfile($email) (Line 690)
  │     │           ├── TaiKhoan::getKhachHangByEmail($email) (MongoDB: khach_hang)
  │     │           ├── TaiKhoan::getSkinProfileByEmail($email) (MongoDB: khach_hang.skin_profile)
  │     │           ├── TaiKhoan::getTuKhoaGanDay($email, 4) (MongoDB: lich_su_tim_kiem)
  │     │           └── TaiKhoan::getOrderHistory($ma_kh) (MongoDB: hoa_don)
  │     │
  │     ├── B. Thu thập tín hiệu hành vi thời gian thực:
  │     │     ├── Search: $_SESSION['search_history'] (fallback: recent_keywords từ DB)
  │     │     ├── View: $_SESSION['recent_viewed_products'] (tối đa 5 sản phẩm gần nhất)
  │     │     ├── Cart: $_SESSION['gio_hang'] (danh sách SKU và số lượng)
  │     │     └── Purchases: HomeController::getValidPurchasedProductsForCustomer($ma_kh)
  │     │           (Lọc hoa_don với trạng thái thuộc VALID_PURCHASE_STATUSES)
  │     │
  │     ├── C. Lấy dữ liệu sản phẩm hiển thị:
  │     │     └── SanPham::getHomepageProductSections(8, $recentViewed, $userProfile, $behaviorSignals)
  │     │           (backend/app/models/SanPham.php:756)
  │     │
  │     └── D. Chuyển giao dữ liệu sang View:
  │           └── HomeController::render('home', $data) -> frontend/views/home.php
```

---

### 2. Chi Tiết Thực Thi Bên Trong Model `SanPham::getHomepageProductSections()`

Tại `backend/app/models/SanPham.php:756`:
1. **Lấy Flash Sale:**
   `SanPham::getFlashSaleProducts($limitEach)` $\rightarrow$ Lọc sản phẩm có giảm giá, sắp xếp theo tỷ lệ giảm.
2. **Thực thi Simple Recommender (Được Yêu Thích Nhất):**
   `SanPham::getSimpleRecommenderProducts($limitEach)` $\rightarrow$ Áp dụng công thức IMDb Weighted Rating ($m=21, C=4.8890$) trên collection `san_pham`.
3. **Thực thi Adaptive Recommender V1 (Dành Cho Bạn):**
   `SanPham::getHybridRecommendations($recentViewedIds, $userProfile, $limitEach, $behaviorSignals)`:
   - Gọi `ContentBasedRecommender::recommendHybrid()` (`backend/app/services/ContentBasedRecommender.php:106`).
   - Nạp chỉ mục TF-IDF: `$this->loadIndex($db)` (file cache `content/tfidf_cache.json` hoặc xây dựng từ `san_pham`).
   - Chuẩn hóa các vector hành vi:
     - `buildSearchQueryVector()` (Search position decay weights: `[1.0, 0.6, 0.3]`).
     - `buildViewQueryVector()` (L2-normalized sum of viewed vectors).
     - `buildCartQueryVector()` (Weighted by item quantities).
     - `buildPurchaseQueryVector()` (Exponential time-decay with half-life = 60 days).
     - `buildBehaviorQueryVector()` (Fusion: cart 0.35, view 0.35, search 0.20, purchase 0.10).
   - Nạp vector hồ sơ da: `buildProfileQueryVector()` từ `skin_type`, `van_de_da`, `muc_tieu_cham_soc`.
   - **Context Router:** Điều hướng trạng thái người dùng sang 1 trong 6 chế độ:
     - `SIMPLE`: Khách vãng lai chưa có tín hiệu $\rightarrow$ trả về mảng rỗng $\rightarrow$ SanPham fallback sang Simple Recommender Top-4.
     - `BEHAVIOR_CONTENT`: Chỉ có hành vi phiên (view, cart, search).
     - `PURCHASE_CONTENT`: Chỉ có lịch sử đơn hàng hợp lệ.
     - `PROFILE_CONTENT`: Chỉ có hồ sơ khảo sát da.
     - `ADAPTIVE_HYBRID`: Kết hợp hành vi phiên và hồ sơ da ($U_{query} = 0.50 U_{behavior} + 0.50 V_{profile}$).
     - `PARTIAL_PROFILE_FALLBACK`: Hồ sơ da không sinh được vector đặc trưng.
   - **Tính toán điểm số & Xếp hạng (Rerank):**
     - Loại trừ các sản phẩm vừa xem (`validRecentLookup`) và sản phẩm đang có trong giỏ (`cartLookup`).
     - Tính Cosine Similarity giữa vector truy vấn kết hợp và vector từng sản phẩm.
     - Với `ADAPTIVE_HYBRID` & `PROFILE_CONTENT`:
       $$FinalScore = 0.70 \times ContentSim + 0.20 \times SkinCompatibility + 0.10 \times BudgetScore$$
     - Với `BEHAVIOR_CONTENT` & `PURCHASE_CONTENT`:
       $$FinalScore = 0.90 \times ContentSim + 0.10 \times PriceSimilarity$$
   - **Bộ lọc đa dạng hóa dòng biến thể (Product Family Diversity Filter):**
     - Rút trích tiền tố dòng sản phẩm (`extractProductFamily`).
     - Chỉ cho phép tối đa 1 sản phẩm đại diện cho mỗi dòng biến thể xuất hiện trong danh sách kết quả.
   - **Gắn nhãn giải thích minh bạch (Explainability Reason Tags):**
     - Tạo nhãn từ các tín hiệu thực tế đóng góp điểm số: *"Dựa trên tìm kiếm gần đây"*, *"Tương tự sản phẩm vừa xem"*, *"Phù hợp với giỏ hàng"*, *"Khớp loại da"*, *"Trong ngân sách"*.
   - **Fallback an toàn:** Nếu `ContentBasedRecommender` không trả về sản phẩm nào, hệ thống tự động fallback về `SanPham::getSimpleRecommenderProducts(4)` với cờ `algorithm_mode = 'SIMPLE'`.

---

### 3. Chi Tiết Thực Thi Tại Tầng Hiển Thị (View Layer: `frontend/views/home.php`)

Tại `frontend/views/home.php:479-525`:
1. Đọc metadata gợi ý từ sản phẩm đầu tiên (`forYouProducts[0]['recommender_meta']`).
2. Tự động chuyển đổi tiêu đề và mô tả của section theo tín hiệu chủ đạo (`dominant_signal`):
   - `CART`: Kicker *"DỰA TRÊN GIỎ HÀNG CỦA BẠN"* | Tiêu đề *"Phù hợp với giỏ hàng của bạn"*.
   - `VIEW`: Kicker *"DỰA TRÊN SẢN PHẨM VỪA XEM"* | Tiêu đề *"Dựa trên sản phẩm bạn vừa xem"*.
   - `SEARCH`: Kicker *"DỰA TRÊN TÌM KIẾM GẦN ĐÂY"* | Tiêu đề *"Dựa trên tìm kiếm gần đây"*.
   - `PURCHASE`: Kicker *"LỊCH SỬ MUA SẮM"* | Tiêu đề *"Gợi ý từ lịch sử mua hàng"*.
   - `PROFILE`: Kicker *"HỒ SƠ DA CÁ NHÂN"* | Tiêu đề *"Dành riêng cho làn da của bạn"*.
   - `HYBRID`: Kicker *"CÁ NHÂN HÓA ĐA TÍN HIỆU"* | Tiêu đề *"Dành riêng cho bạn (Hồ sơ da & Hành vi)"*.
   - `SIMPLE`: Kicker *"GỢI Ý HÔM NAY"* | Tiêu đề *"Gợi ý dành cho bạn"*.
3. Hiển thị badge lý do gợi ý trên từng card sản phẩm.
4. Section 5.5: Hiển thị bảng xếp hạng *"Được Yêu Thích Nhất"* (Simple Recommender: Weighted Rating Top-4).

---

### 4. Xác Nhận Ranh Giới: Các Thuật Toán Nghiên Cứu Không Có Mặt Trong Call Graph
- **Collaborative Filtering (`CollaborativeFilteringRecommender.php`, BPR, Funk MF, Item-kNN):** Hoàn toàn **không được gọi** bởi bất kỳ controller hay model nào trên trang chủ.
- **K-Means Clustering:** Hoàn toàn **không tồn tại** trong mã nguồn ứng dụng `backend/app/`.
- **Association Rules (`AssociationRuleRecommender.php`):** Chỉ được gọi riêng tại trang chi tiết sản phẩm (`SanPhamController::chitiet`) cho cụm "Mua kèm", **không tham gia** vào luồng trang chủ.
