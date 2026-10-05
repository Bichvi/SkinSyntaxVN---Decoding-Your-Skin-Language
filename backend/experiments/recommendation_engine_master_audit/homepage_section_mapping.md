# Ánh Xạ Các Section Trang Chủ (Homepage Section Mapping)
## Kiểm Toán Thực Tế Giao Diện Trang Chủ & Định Hướng Tương Lai

Tài liệu này kiểm toán chi tiết các khối sản phẩm (sections) đang thực sự được render trên trang chủ (`frontend/views/home.php`) và đề xuất mô hình phân tầng khoa học cho tương lai mà **không sửa đổi giao diện hiện tại**.

---

### 1. Kiểm Toán Các Section Đang Vận Hành Trên Production

Trang chủ SkinSyntaxVN nhận mảng dữ liệu `$homepageSections` từ `HomeController::index()` và hiển thị các khối sản phẩm sau:

| Vị Trí / Mã Section | Tiêu Đề Hiển Thị (Display Title) | Nguồn Biến Dữ Liệu | Thuật Toán Đang Chạy | Trạng Thái Vận Hành |
| :--- | :--- | :--- | :--- | :--- |
| **Section 1: Flash Sale** | *Săn Deal Chớp Nhoáng* | `$homepageSections['flashDeals']` | Rule-based (Lọc sản phẩm giảm giá mạnh nhất) | **PRODUCTION ACTIVE** |
| **Section 2: New Arrivals** | *Sản Phẩm Mới Nhất* | `$latest` (từ `SanPham::latest()`) | Rule-based (Sắp xếp theo `created_at` giảm dần) | **PRODUCTION ACTIVE** |
| **Section 3: Primary Adaptive** | *Tiêu đề động theo ngữ cảnh* (Xem chi tiết bảng dưới) | `$homepageSections['forYou']` | **Adaptive Recommender V1** (`ContentBasedRecommender::recommendHybrid`) | **PRODUCTION ACTIVE** |
| **Section 5.5: Top Rated** | *Bảng Xếp Hạng Đánh Giá — Được Yêu Thích Nhất* | `$homepageSections['topRatedWeighted']` | **Simple Recommender** (`SanPham::getSimpleRecommenderProducts`, IMDb WR) | **PRODUCTION ACTIVE** |

---

### 2. Cơ Chế Thích Ứng Tiêu Đề Động Tại Section 3 ("Dành Riêng Cho Bạn")

Section 3 là khối gợi ý cá nhân hóa trung tâm. Tiêu đề và thông điệp phụ được hệ thống tự động thay đổi theo thời gian thực dựa trên trường `dominant_signal` của sản phẩm đầu tiên:

| Dominant Signal | Kicker (Dòng Tiêu Đề Phụ) | H1 / H3 Tiêu Đề Chính | Mô Tả Ý Nghĩa Ngữ Cảnh |
| :--- | :--- | :--- | :--- |
| **CART** | `DỰA TRÊN GIỎ HÀNG CỦA BẠN` | *Phù hợp với giỏ hàng của bạn* | Gợi ý sản phẩm tương thích bổ trợ cho các mục đang có trong giỏ hàng |
| **VIEW** | `DỰA TRÊN SẢN PHẨM VỪA XEM` | *Dựa trên sản phẩm bạn vừa xem* | Gợi ý sản phẩm tương đồng về loại da và thành phần với sản phẩm vừa duyệt |
| **SEARCH** | `DỰA TRÊN TÌM KIẾM GẦN ĐÂY` | *Dựa trên tìm kiếm gần đây* | Khớp thuộc tính sản phẩm với từ khóa người dùng vừa truy vấn |
| **PURCHASE** | `LỊCH SỬ MUA SẮM` | *Gợi ý từ lịch sử mua hàng* | Gợi ý sản phẩm tương thích với các đơn hàng đã giao thành công |
| **PROFILE** | `HỒ SƠ DA CÁ NHÂN` | *Dành riêng cho làn da của bạn* | Gợi ý sản phẩm khớp với loại da, vấn đề da và ngân sách trong khảo sát |
| **HYBRID** | `CÁ NHÂN HÓA ĐA TÍN HIỆU` | *Dành riêng cho bạn (Hồ sơ da & Hành vi)* | Kết hợp đồng thời hồ sơ khảo sát da và hành vi tương tác trong phiên |
| **SIMPLE** | `GỢI Ý HÔM NAY` | *Gợi ý dành cho bạn* | Khách vãng lai chưa có tín hiệu $\rightarrow$ Fallback về Simple Weighted Rating |

---

### 3. Đề Xuất Mô Hình Ánh Xạ Tương Lai (Proposed Future Section Mapping)

Khi hệ thống có đủ dữ liệu giao dịch thực tế và vượt qua các quy trình kiểm thử A/B, kiến trúc trang chủ có thể mở rộng theo lộ trình sau (**LƯU Ý: Hiện tại chưa triển khai lên UI, chỉ phục vụ đề xuất luận văn**):

1. **Section "Được Yêu Thích Nhất" (Global Baseline):**
   - Thuật toán: **Simple Weighted Rating** ($m=21, C=4.8890$).
   - Trạng thái: **ĐÃ VẬN HÀNH PRODUCTION**.
2. **Section "Dành Riêng Cho Bạn" (Personalized Main):**
   - Thuật toán: **Adaptive Hybrid Recommender** (Behavior + Skin Profile).
   - Trạng thái: **ĐÃ VẬN HÀNH PRODUCTION**.
3. **Section "Thường Được Mua Cùng Nhau" (Frequently Bought Together):**
   - Thuật toán đề xuất: **Association Rules (Apriori / FP-Growth)**.
   - Điều kiện kích hoạt: **CHỈ TRIỂN KHAI TRONG TƯƠNG LAI KHI CÓ ĐỦ DỮ LIỆU ĐƠN HÀNG THỰC TẾ (DATA-DEPENDENT / FUTURE ONLY)**.
4. **Section "Khám Phá Theo Nhóm Công Thức / Phong Cách Chăm Sóc Da":**
   - Thuật toán đề xuất: **K-Means Clustering Clusters**.
   - Điều kiện kích hoạt: **CHỈ TRIỂN KHAI TRONG TƯƠNG LAI NẾU VƯỢT QUA KIỂM TOÁN CHUYÊN GIA VÀ CÓ CHÍNH SÁCH GỘP BIẾN THỂ (RESEARCH / FUTURE ONLY)**.

---

### 4. Cam Kết Ranh Giới Học Thuật
- Tuyệt đối không để bất kỳ thuật toán nghiên cứu nào (K-Means, BPR, Apriori) xuất hiện trên giao diện người dùng như một tính năng sản xuất chính thức khi chưa được kiểm định chất lượng và dữ liệu.
