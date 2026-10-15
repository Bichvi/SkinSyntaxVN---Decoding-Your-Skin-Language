# PRODUCTION RECOMMENDER SIGNALS AUDIT FOR PERSONALIZED FLASH SALE

**Dự án:** SkinSyntaxVN — Decoding Your Skin Language  
**Mục tiêu:** Kiểm toán chi tiết mã nguồn bộ gợi ý sản xuất (`ContentBasedRecommender.php`, `SanPham::getHybridRecommendations`), xác định các tín hiệu (signals) có thể tái sử dụng trực tiếp cho việc xếp hạng Flash Sale cá nhân hóa mà KHÔNG tạo ra bộ recommender thứ hai.  
**Môi trường:** Production Codebase & MongoDB Atlas Canonical  
**Thời gian thực hiện:** Tháng 10/2026  

---

## 1. Nguồn Mã Nguồn Sản Xuất (Production Source of Truth)

Toàn bộ logic tính toán độ phù hợp cá nhân hóa trong hệ thống SkinSyntaxVN hiện được vận hành bởi:
* **Controller:** `backend/app/controllers/HomeController.php` (dòng 134–212, 723–806, 870–924).
* **Model:** `backend/app/models/SanPham.php` (dòng 828–1003).
* **Core Engine Service:** `backend/app/services/ContentBasedRecommender.php` (1.069 dòng mã).

---

## 2. Kiểm Toán Chi Tiết 6 Tín Hiệu Cốt Lõi Của Production Recommender

| Tín hiệu | Nguồn dữ liệu (Source) | Lưu trữ & Thời hạn (Persistence) | Cơ chế suy giảm (Decay / Recency) | Không gian vector / Chuẩn hóa | Điểm số & Trọng số nội tại | Fallback khi thiếu |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **1. SEARCH** | `$_SESSION['search_history']` hoặc `userProfile['recent_keywords']` | Session (Guest) hoặc DB `lich_su_tim_kiem` (User) | Position decay cho 3 từ khóa gần nhất: `[1.0, 0.6, 0.3]` | TF-IDF vector trên từ điển `filtered_doc_freq` | Trọng số fusion: `0.20` | Bỏ qua vector search, tự động tái chuẩn hóa giữa các tín hiệu còn lại |
| **2. VIEW** | `$_SESSION['recent_viewed_products']` | Session PHP (Tối đa 5 sản phẩm gần nhất) | Lấy theo thứ tự MRU (Most Recently Used) | Centroid của các TF-IDF document vectors đã xem | Trọng số fusion: `0.35` | Bỏ qua vector view |
| **3. CART** | `$_SESSION['gio_hang']` | Session PHP (mảng `[product_id => quantity]`) | Trọng số tuyến tính theo số lượng mua (`qty`) | Centroid có trọng số số lượng của các document vectors | Trọng số fusion: `0.35` | Bỏ qua vector cart |
| **4. PURCHASE** | `chi_tiet_hoa_don` kết hợp `hoa_don` | MongoDB Atlas (Các đơn có trạng thái hợp lệ) | Exponential time decay: $w(t) = \exp(-\lambda \cdot \Delta t)$, $\lambda = \frac{\ln(2)}{60\text{ ngày}}$ | Vector có trọng số thời gian và số lượng | Trọng số fusion: `0.10` | Bỏ qua vector purchase |
| **5. PROFILE** | Khảo sát da trong `khach_hang['tinh_trang_dac_biet']`, `van_de_da`, `ngan_sach` | Bền vững trong DB `khach_hang` | Không suy giảm theo thời gian (cập nhật khi làm lại khảo sát) | TF-IDF trên chuỗi ghép các vấn đề da, mục tiêu chăm sóc | Kết hợp Categorical Skin Match (`0.20`) + Budget (`0.10`) | Nếu không có profile: chuyển sang chế độ thuần hành vi |
| **6. HYBRID** | Kết hợp vector Hành vi tổng hợp ($U_{\text{behavior}}$) và vector Hồ sơ da ($V_{\text{profile}}$) | Động trong phiên làm việc của user | Kết hợp cố định $\alpha = 0.50$: $U_{\text{query}} = 0.50 U_{\text{behavior}} + 0.50 V_{\text{profile}}$ | Chuẩn hóa Euclid $L_2$: $\frac{U}{\|U\|_2}$ | Đo bằng Cosine Similarity trong khoảng $[0.0, 1.0]$ | Chuyển chế độ Router (Profile-only hoặc Behavior-only) |

---

## 3. Phân Biệt Tuyệt Đối: Phase A Fusion Weights vs CF Interaction Weights

Một yêu cầu an toàn kiến trúc tối quan trọng là **KHÔNG ĐƯỢC TRỘN LẪN** hai hệ thống trọng số độc lập trong hệ thống:

```
┌────────────────────────────────────────────────────────────────────────┐
│ HỆ THỐNG 1: PHASE A MULTI-SIGNAL BEHAVIOR FUSION WEIGHTS               │
│ Vị trí: ContentBasedRecommender::BEHAVIOR_WEIGHTS_BASELINE            │
│ Mục đích: Trộn các vector hành vi thành 1 vector truy vấn chung       │
│           U_behavior = sum( w_i * V_i )                                │
├──────────────────────────┬─────────────────────────────────────────────┤
│ Cart                     │ 0.35 (35%)                                  │
│ View                     │ 0.35 (35%)                                  │
│ Search                   │ 0.20 (20%)                                  │
│ Purchase                 │ 0.10 (10%)                                  │
│ Quy tắc chuẩn hóa:       │ Tự chuẩn hóa: w_i / sum(active_w)           │
└──────────────────────────┴─────────────────────────────────────────────┘

                                VS

┌────────────────────────────────────────────────────────────────────────┐
│ HỆ THỐNG 2: COLLABORATIVE FILTERING / INTERACTION EVENT WEIGHTS        │
│ Vị trí: TuongTac::WEIGHTS (backend/app/models/TuongTac.php:17-24)      │
│ Mục đích: Đo cường độ phản hồi ngầm (implicit feedback) để log tương  │
│           tác và huấn luyện các mô hình lọc cộng tác (Matrix Factor)  │
├──────────────────────────┬─────────────────────────────────────────────┤
│ View                     │ 1.0                                         │
│ Search                   │ 0.0 (không log SKU)                         │
│ Search Click             │ 1.5                                         │
│ Add to Cart              │ 3.0                                         │
│ Cart Remove              │ 0.0                                         │
│ Purchase                 │ 5.0                                         │
└──────────────────────────┴─────────────────────────────────────────────┘
```

* **Cảnh báo kiến trúc:** Hệ thống 1 (Fusion Weights) thuộc về biểu diễn nội dung (Content-Based Query Vector). Hệ thống 2 (Interaction Weights) thuộc về ma trận tương tác (Collaborative Filtering Matrix). Trong thiết kế Flash Sale Phase 1, **chỉ tái sử dụng điểm phù hợp nội dung từ Hệ thống 1**, tuyệt đối không lấy các hệ số $1.0, 1.5, 3.0, 5.0$ nhân vào điểm của Flash Sale.

---

## 4. Công Thức Tính "Profile Relevance" Trong Production

Production Recommender xác định độ tương thích hồ sơ da theo nguyên tắc phân loại rõ ràng:
1. **Categorical Skin Type Score (`computeSkinTypeScore`):**
   * Khớp hoàn toàn loại da người dùng ($p = c$): **1.0**
   * Sản phẩm dành cho mọi loại da (`da thường/mọi loại da`): **0.70**
   * Khách chưa khai báo loại da: **0.50** (trung tính)
   * Sản phẩm không rõ loại da (`unknown`): **0.30**
   * Không tương thích: **0.0**
   * *Lưu ý an toàn y khoa:* Điểm này hoàn toàn là so khớp danh mục (categorical attribute matching). **Tuyệt đối không được gọi là "clinical compatibility", "medical suitability", hay "safety score".**

2. **Soft Budget Match Score (`computeBudgetScore`):**
   * Giá sản phẩm $\le$ ngân sách khách chọn: **1.0**
   * Giá sản phẩm $>$ ngân sách: Giảm mềm tuyến tính: $\max\left(0.10, 1.0 - \frac{\text{vượt}}{\text{ngân sách}}\right)$.

3. **Tổng hợp Final Score trong Production:**
   $$\text{FinalScore} = 0.70 \cdot \text{ContentSim} + 0.20 \cdot \text{SkinScore} + 0.10 \cdot \text{BudgetScore}$$

---

## 5. Ranh Giới Thuật Toán (Algorithm Boundaries)

Tuân thủ chỉ thị của đồ án và hiện trạng kiểm toán:
* **Các thuật toán nghiên cứu / thử nghiệm:** BPR (Bayesian Personalized Ranking), Funk MF, Item-kNN, K-Means Clustering, Apriori, FP-Growth.
* **Quy tắc ranh giới:** Tất cả các mô hình trên hiện chỉ nằm ở trạng thái nghiên cứu thực nghiệm độc lập (research/offline notebooks). **TUYỆT ĐỐI KHÔNG GHÉP NỐI (DO NOT WIRE) BẤT KỲ MÔ HÌNH NÀO TRONG SỐ NÀY VÀO FLASH SALE TRONG GIAI ĐOẠN NÀY.**
* Flash Sale chỉ tái sử dụng trực tiếp `ContentBasedRecommender` sản xuất hiện có để đảm bảo tính ổn định, độ trễ thấp (< 15ms), và tính giải thích minh bạch (explainability).
