# KIỂM TOÁN CÁC KHỐI SẢN PHẨM TRÊN TRANG CHỦ (CURRENT HOMEPAGE SECTIONS)
## SKINSYNTAXVN HOMEPAGE RECOMMENDATION UX AUDIT — PHASE 1

Tài liệu này ghi nhận kết quả kiểm toán mã nguồn thực tế của toàn bộ các section sản phẩm và gợi ý hiện đang render trên trang chủ (`index.php?r=home`).

---

### 1. LUỒNG DỮ LIỆU TỪ CONTROLLER ĐẾN VIEW

$$\text{Request: } \texttt{index.php?r=home} \longrightarrow \texttt{HomeController::index()} \longrightarrow \texttt{SanPham::getHomepageProductSections()} \longrightarrow \texttt{frontend/views/home.php}$$

1. **`index.php`**: Điều hướng `case 'home'` $\rightarrow$ `(new HomeController($pdo))->index();`.
2. **`HomeController::index()`**:
   * Kiểm tra đăng nhập qua `$_SESSION['user']`, nạp hồ sơ da qua `buildRecommendationProfile()`, kiểm tra khảo sát qua `hasCompletedSurvey()`.
   * Thu thập 4 tín hiệu hành vi: `search` (session/account keywords), `view` (`$_SESSION['recent_viewed_products']`), `cart` (`$_SESSION['gio_hang']`), `purchases` (`getValidPurchasedProductsForCustomer()`).
   * Gọi hàm `SanPham::getHomepageProductSections(8, $recentViewed, $userProfile, $behaviorSignals)`.
   * Truyền mảng `$homepageSections`, `$userProfile`, `$hasSurvey`, `$isLoggedIn` sang view `frontend/views/home.php`.
3. **`SanPham::getHomepageProductSections()`**:
   * Lấy danh sách Flash Sale: `$flashDeals = $this->getFlashSaleProducts($limitEach)`.
   * Lấy danh sách Simple Recommender: `$topRatedWeighted = $this->getSimpleRecommenderProducts($limitEach)`.
   * Lấy danh sách Adaptive Recommender: `$adaptiveRecs = $this->getHybridRecommendations(...)`.
   * Nếu `$adaptiveRecs` rỗng, tự động fallback về `$fallbackSimple = $this->getSimpleRecommenderProducts(4)` kèm tem `recommender_meta`.
4. **`frontend/views/home.php`**:
   * Phân tách `$homepageSections['forYou']`, `$homepageSections['flashDeals']`, `$homepageSections['topRatedWeighted']`.

---

### 2. DANH MỤC CHI TIẾT CÁC SECTION TRÊN HOMEPAGE HIỆN TẠI

Kiểm toán mã nguồn xác nhận trang chủ hiện đang kết xuất **10 section** theo thứ tự từ trên xuống dưới:

| STT | Mã Section (Key) | Tiêu đề hiển thị (Title) | Phụ đề (Subtitle) | Nguồn dữ liệu (Product Source) | Thuật toán / Chế độ | Số lượng SP | Nút kêu gọi (CTA) | Nhãn giải thích (Reason Tag) | Xử lý khi rỗng (Empty State) | Yêu cầu đăng nhập | Yêu cầu khảo sát |
| :---: | :--- | :--- | :--- | :--- | :--- | :---: | :--- | :--- | :--- | :---: | :---: |
| **1** | `hero` | "Skincare hiểu làn da của bạn." | "Khám phá mỹ phẩm phù hợp dựa trên hồ sơ da..." | Không có (Banner tĩnh) | Không áp dụng | 0 | "Làm trắc nghiệm da ngay" | Không | Luôn hiển thị | Không | Không |
| **2** | `skin_concern` | "Chọn Nhu Cầu Chăm Sóc Da" | "Phân loại theo thành phần & công dụng nổi bật" | Danh mục 8 nhóm vấn đề da | Filter URL | 0 (8 card nhóm) | "Xem sản phẩm →" | "Vì sao phù hợp" | Luôn hiển thị | Không | Không |
| **3** | `forYou` | Dynamic: switch theo `dominant_signal` (Mặc định: "Gợi ý dành cho bạn") | Dynamic: switch theo `dominant_signal` | `ContentBasedRecommender::recommendHybrid` | Adaptive Hybrid / Behavior / Profile / Simple Fallback | **4** | Link khảo sát da (nếu chưa làm) | "Dành riêng cho bạn" / Tag từ recommender | Fallback về 4 sản phẩm Simple WR | Không | Không |
| **4** | `flashDeals` | "Flash Sale — Khuyến Mãi Khủng" | "Ưu đãi có hạn theo phiên mua sắm hôm nay" | `SanPham::getFlashSaleProducts()` | Filter `phan_tram_giam_gia > 0` | **4** | "Xem tất cả deal hot →" | "-XX%" sale badge | Ẩn section (`<?php if (!empty): ?>`) | Không | Không |
| **5** | `livestream` | "Syna AI Livestream — Trực Tiếp" | "Chuyên gia AI tư vấn skincare 24/7" | Module Livestream Syna | Không áp dụng | 0 (Widget) | "Xem Live ngay" | "LIVE" badge | Luôn hiển thị | Không | Không |
| **5.5**| `topRatedWeighted` | "Được Yêu Thích Nhất" | "Những sản phẩm được cộng đồng đánh giá cao" | `SanPham::getSimpleRecommenderProducts()` | Simple Recommender: IMDb Weighted Rating ($m=21, C=4.8890$) | **4** | "Xem tất cả →" | "Yêu thích nhất" | Ẩn section nếu rỗng | Không | Không |
| **6** | `newProducts` | "Mỹ Phẩm Vừa Lên Kệ" | Không có phụ đề | `$latest` (từ `SanPham::latest()`) | Sắp xếp theo ngày tạo mới nhất | **4** | "Xem tất cả →" | "Mới lên kệ" | Ẩn section nếu rỗng | Không | Không |
| **7** | `personal_routine` | "Routine của bạn" | "DAILY SKINCARE REGIMEN" | Tĩnh 4 bước (Làm sạch, Đặc trị, Dưỡng ẩm, Bảo vệ) | Heuristic danh mục theo bước | 0 (4 bước shortcut) | "Xem routine đầy đủ →" hoặc "Làm khảo sát ngay" | Step number | Hiển thị banner mời khảo sát nếu chưa làm | Không | Tùy biến theo khảo sát |
| **8** | `brands` | "Thương Hiệu Nổi Bật Được Yêu Thích" | Không có phụ đề | `$brandNames` từ `$latest` | Trích xuất tên nhãn hàng duy nhất | 0 (8 logo brand) | Click vào logo brand | Tên thương hiệu | Ẩn nếu rỗng | Không | Không |
| **9** | `final_cta` | "Chưa biết sản phẩm nào thực sự dành cho bạn?" | "Chỉ mất 1 phút làm trắc nghiệm da..." | Không có (Banner tĩnh cuối trang) | Không áp dụng | 0 | "Bắt đầu làm khảo sát da ngay →" | Không | Luôn hiển thị | Không | Không |

---

### 3. NHẬN XÉT KIỂM TOÁN CHÍNH

1. **Section 3 (`forYou`) là trung tâm cá nhân hóa chính:** Section này đã có logic đổi tiêu đề động dựa trên `$dominantSignal`, tuy nhiên đang gặp lỗi mismatch khi ở chế độ `PARTIAL_PROFILE_FALLBACK` (sẽ phân tích sâu ở file sau).
2. **Section 5.5 (`topRatedWeighted`) là baseline chuẩn:** Triển khai độc lập thuật toán IMDb Weighted Rating ($m=21, C=4.8890$), phục vụ khách hàng vãng lai và người dùng muốn xem xu hướng cộng đồng.
3. **Không có thuật toán nghiên cứu nào xuất hiện:** Các mô hình Collaborative Filtering, Matrix Factorization, BPR, K-Means Clustering và Apriori/FP-Growth **hoàn toàn không có mặt** trong bất kỳ section nào trên trang chủ.
