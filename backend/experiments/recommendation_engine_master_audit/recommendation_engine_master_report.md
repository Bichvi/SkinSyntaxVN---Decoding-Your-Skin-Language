# BÁO CÁO TỔNG KIỂM TOÁN TOÀN BỘ KIẾN TRÚC HỆ THỐNG GỢI Ý
## SKINSYNTAXVN RECOMMENDATION SYSTEM — MASTER AUDIT REPORT
### PHÂN ĐỊNH RÕ RÀNG: PRODUCTION RUNTIME vs. OFFLINE RESEARCH vs. SYNTHETIC EXPERIMENTS

---

## 0. TỔNG QUAN VÀ MỤC ĐÍCH KIỂM TOÁN

Báo cáo này tổng hợp và đối soát toàn diện toàn bộ kiến trúc gợi ý (Recommendation Engine Architecture) của nền tảng thương mại điện tử mỹ phẩm **SkinSyntaxVN**. 

Mục tiêu cốt lõi của đợt kiểm toán tổng lực này là:
1. **Phản ánh sự thật tuyệt đối từ mã nguồn đang thực thi (Ground-Truth Code Audit):** Không dựa vào các báo cáo cũ, không phỏng đoán các hàm hoặc bảng dữ liệu không tồn tại.
2. **Phân định ranh giới nghiêm ngặt giữa Production và Research:** Phân biệt rõ ràng giữa các thuật toán đang phục vụ người dùng trực tiếp trên website và các thuật toán nghiên cứu thực nghiệm ngoại tuyến (Offline Research) hoặc mô phỏng trên dữ liệu kiểm soát (Synthetic Data).
3. **Chuẩn hóa học thuật phục vụ luận văn tốt nghiệp:** Cung cấp đầy đủ biểu đồ kiến trúc, sơ đồ luồng dữ liệu, bảng kiểm kê dữ liệu, hệ thống công thức toán học và tuyên bố giới hạn học thuật chính xác.

---

## 1. KIỂM TOÁN CALL GRAPH RUNTIME THỰC TẾ TRÊN PRODUCTION

### 1.1. Luồng thực thi chi tiết từ Request đến View
Mã nguồn production được truy vết trực tiếp qua chuỗi gọi hàm thực tế:

$$\text{HTTP Request: } \texttt{index.php?r=home} \longrightarrow \texttt{HomeController::index()} \longrightarrow \texttt{SanPham::getHomepageProductSections()} \longrightarrow \texttt{ContentBasedRecommender::recommendHybrid()} \longrightarrow \texttt{frontend/views/home.php}$$

* **Điểm khởi tạo (Entry Point):** `index.php` khởi tạo `SessionManager` để kích hoạt phiên làm việc PHP (`$_SESSION`), nạp cấu hình và khởi tạo Router điều hướng request `r=home` tới `HomeController`.
* **Tầng điều khiển (`HomeController.php`):**
  * Gọi hàm tĩnh `SanPham::getHomepageProductSections($limit = 4, $userId = null)`.
  * Truyền `$userId = $_SESSION['user_id'] ?? null` để phục vụ cá nhân hóa nếu người dùng đã đăng nhập.
* **Tầng mô hình nghiệp vụ (`backend/app/models/SanPham.php`):**
  * `getHomepageProductSections()` kiểm tra điều kiện dữ liệu danh mục hoạt động trong MongoDB collection `san_pham`.
  * Khởi tạo đối tượng `ContentBasedRecommender`:
    ```php
    $recommender = new ContentBasedRecommender();
    $personalizedSection = $recommender->recommendHybrid($userId, $limit);
    ```
  * Lấy danh sách sản phẩm nổi bật đánh giá cao từ Simple Recommender:
    ```php
    $simpleRecommender = new SimpleRecommender();
    $featuredSection = $simpleRecommender->getTopRatedProducts($limit);
    ```
  * Lấy danh mục Flash Sale dựa trên bộ lọc giảm giá (`phan_tram_giam_gia > 0`).
* **Tầng thuật toán gợi ý (`backend/app/services/ContentBasedRecommender.php`):**
  * Đọc ma trận TF-IDF từ bộ nhớ đệm `backend/app/cache/tfidf_cache.json`.
  * Thu thập tín hiệu hành vi từ `$_SESSION` và lịch sử đơn hàng từ collection `hoa_don`.
  * Thu thập hồ sơ khảo sát da từ collection `nguoidung.khao_sat_da`.
  * Chạy bộ định tuyến ngữ cảnh `determineRecommendationMode()` để xác định 1 trong 6 chế độ tính toán.
  * Tính điểm Cosine Similarity, cộng điểm thưởng hồ sơ (Rerank Bonus), áp dụng bộ lọc đa dạng hóa dòng sản phẩm (`Product Family Filter`) và sinh nhãn giải thích (`Reason Tags`).
* **Tầng hiển thị (`frontend/views/home.php`):**
  * Hiển thị 3 section chính:
    1. **Flash Sale** (Khuyến mãi sốc)
    2. **Dành riêng cho bạn** (Cá nhân hóa từ `recommendHybrid`)
    3. **Sản phẩm nổi bật / Đánh giá cao** (Từ `SimpleRecommender`)

---

## 2. KIỂM TOÁN SIMPLE RECOMMENDER TRÊN PRODUCTION

### 2.1. Công thức và tham số chuẩn IMDb Weighted Rating
Thuật toán `SimpleRecommender` triển khai mô hình tính điểm xếp hạng có trọng số theo chuẩn IMDb:

$$WR = \left(\frac{v}{v + m}\right) \cdot R + \left(\frac{m}{v + m}\right) \cdot C$$

Trong đó:
* $v$ (`so_luong_danh_gia`): Số lượng đánh giá thực tế của sản phẩm.
* $R$ (`diem_danh_gia`): Điểm đánh giá trung bình thực tế của sản phẩm (thang điểm 1 - 5).
* $C$: Điểm đánh giá trung bình của toàn bộ catalog sản phẩm skincare hoạt động.
* $m$: Ngưỡng số lượng đánh giá tối thiểu để đủ điều kiện xét duyệt vào nhóm xếp hạng cao.

### 2.2. Xác nhận tham số từ kiểm toán mã nguồn và dữ liệu thực tế
* **Giá trị $C$ kiểm toán:** $C = 4.8890$
* **Giá trị $m$ kiểm toán:** $m = 21.0$
* **Cơ chế lưu đệm (Caching):** Lưu tại `backend/app/cache/simple_recommender_cache.json` với thời gian sống (TTL) là 600 giây.
* **Quy tắc phân định hòa (Tie-Break):** Khi hai sản phẩm có điểm $WR$ bằng nhau, hệ thống ưu tiên sản phẩm có số lượng bán cao hơn (`so_luong_da_ban`), sau đó đến ID sản phẩm.
* **LƯU Ý TRỌNG YẾU VỀ DỮ LIỆU BÁN HÀNG:** 
  > **Tuyệt đối không gọi trường `so_luong_da_ban` là "doanh số bán hàng thực tế".** Kiểm toán xác nhận trường này là giá trị khởi tạo hiển thị trong catalog ban đầu, không được cập nhật tự động từ các giao dịch đơn hàng thực tế trong collection `chi_tiet_hoa_don`.

---

## 3. KIỂM TOÁN CONTENT-BASED RECOMMENDER TRÊN PRODUCTION

### 3.1. Trích xuất đặc trưng và Trọng số thuộc tính (Feature Extraction & Weights)
Mô hình Content-Based biểu diễn sản phẩm dưới dạng vector đặc trưng văn bản 128 chiều với bộ trọng số cấu hình **Config B** (được chứng minh tối ưu trong Step 3):

$$\text{Document}(i) = 2 \cdot \text{Tên} + 3 \cdot \text{Danh mục} + 3 \cdot \text{Loại da} + 2 \cdot \text{Thành phần} + 1 \cdot \text{Thương hiệu} + 1 \cdot \text{Mô tả}$$

### 3.2. Thuật toán TF-IDF và Chuẩn hóa Cosine
* **Tần suất từ (Term Frequency - TF):** Tần suất xuất hiện chuẩn hóa theo độ dài văn bản: $\text{TF}(t, d) = \frac{f_{t,d}}{\sum_{t'} f_{t',d}}$.
* **Nghịch đảo tần suất tài liệu mượt (Smooth IDF):**
  $$\text{IDF}(t) = \ln\left(\frac{1 + N}{1 + \text{df}(t)}\right) + 1$$
* **Bộ lọc từ vựng (Vocabulary Filtering):** Giới hạn từ vựng trong phạm vi $3 \le \text{df}(t) \le 0.80 N$, loại bỏ các từ quá hiếm hoặc quá phổ biến, giữ lại tối đa 30 từ đặc trưng hàng đầu cho mỗi sản phẩm.
* **Độ tương đồng Cosine (Cosine Similarity):**
  $$\text{Cosine}(u, i) = \frac{\mathbf{u} \cdot \mathbf{v}_i}{\|\mathbf{u}\|_2 \cdot \|\mathbf{v}_i\|_2}$$

### 3.3. Xác minh Ingredient Parser trên Production
* **Thực trạng kiểm toán:** Hệ thống production **KHÔNG** sử dụng `Rule-based Ingredient String Parser V2` (công cụ chuẩn hóa và tách chuỗi thành phần theo quy tắc phân tách văn bản của Step 7C.1).
* **Mã nguồn thực tế:** Production sử dụng hàm tách từ cơ bản bằng biểu thức chính quy:
  ```php
  preg_split('/[\s,\.\-\+\/\(\)]+/u', mb_strtolower($product['thanh_phan'] ?? ''));
  ```
* **Kết luận:** Giữ nguyên hiện trạng mã nguồn production; không tự ý đưa Parser V2 vào hệ thống đang chạy.

---

## 4. KIỂM TOÁN TÍN HIỆU HÀNH VI (BEHAVIOR SIGNALS)

### 4.1. Bảng kê chi tiết các tín hiệu hành vi
Hệ thống production thu thập 4 tín hiệu hành vi người dùng:

| Tín hiệu | Nơi lưu trữ | Cơ chế nhận diện | Trọng số Baseline | Thời gian suy giảm (Recency Decay) | Cơ chế khử trùng lặp |
| :--- | :--- | :--- | :---: | :--- | :--- |
| **CART** | `$_SESSION['cart']` | Session ID | **0.35** | Không suy giảm trong phiên duyệt | Giữ danh sách sản phẩm duy nhất |
| **VIEW** | `$_SESSION['viewed_products']` | Session ID | **0.35** | Lưu 10 lượt xem gần nhất, tính điểm gần hơn | FIFO, tối đa 10 mục |
| **SEARCH** | `$_SESSION['search_history']` & `lich_su_tim_kiem` | Session ID / User ID | **0.20** | Lưu 5 truy vấn mới nhất | Trùng khớp chuỗi từ khóa |
| **PURCHASE** | MongoDB `hoa_don` | `user_id` | **0.10** | Suy giảm hàm mũ: $\lambda = \frac{\ln(2)}{60\text{ ngày}}$ | Lọc theo trạng thái hợp lệ |

### 4.2. Khẳng định bản chất trọng số
> **BẢN CHẤT KỸ THUẬT:** Hệ thống trọng số $[0.35, 0.35, 0.20, 0.10]$ là **ENGINEERING BASELINE / HEURISTIC MANUAL TUNING**, tuyệt đối **KHÔNG PHẢI KẾT QUẢ TỐI ƯU HÓA TOÁN HỌC (LEARNED OPTIMUM)** từ hàm mất mát hay thuật toán học máy.

---

## 5. KIỂM TOÁN BỘ ĐỊNH TUYẾN TRẠNG THÁI NGƯỜI DÙNG (USER STATE ROUTING)

Hệ thống triển khai bộ định tuyến ngữ cảnh tự động xử lý toàn bộ 9 kịch bản người dùng thực tế:

| Kịch bản | Trạng thái người dùng | Chế độ Router (`recommendation_mode`) | Công thức truy vấn chính | Cơ chế Fallback |
| :--- | :--- | :--- | :--- | :--- |
| **A. Cold Start** | Chưa đăng nhập, không có hành vi | `SIMPLE` | Simple IMDb Weighted Rating | Danh mục sản phẩm mới nhất |
| **B. Search Only** | Khách vãng lai, chỉ tìm kiếm | `BEHAVIOR_CONTENT` | $U_{behavior} = \text{TF-IDF}(Q_{\text{search}})$ | Weighted Rating |
| **C. View Only** | Khách vãng lai, chỉ xem sản phẩm | `BEHAVIOR_CONTENT` | $U_{behavior} = \sum w_v \mathbf{v}_{\text{viewed}}$ | Weighted Rating |
| **D. Cart Only** | Khách vãng lai, có giỏ hàng | `BEHAVIOR_CONTENT` | $U_{behavior} = \sum w_c \mathbf{v}_{\text{cart}}$ | Weighted Rating |
| **E. Logged-in No Survey** | Đã đăng nhập, chưa khảo sát da | `BEHAVIOR_CONTENT` hoặc `SIMPLE` | Dựa trên hành vi phiên duyệt hiện tại | Weighted Rating |
| **F. Purchase Only** | Đã đăng nhập, chỉ có lịch sử mua | `PURCHASE_CONTENT` | $U_{behavior} = \sum e^{-\lambda \Delta t} \mathbf{v}_{\text{purchase}}$ | Weighted Rating |
| **G. Profile Only** | Có khảo sát da, chưa có hành vi | `PROFILE_CONTENT` | $V_{profile} = \text{Vector}(\text{Loại da, Vấn đề})$ | Weighted Rating |
| **H. Profile + Behavior** | Có khảo sát da và có hành vi | `ADAPTIVE_HYBRID` | $U_{query} = 0.50 U_{behavior} + 0.50 V_{profile}$ | Mode G $\rightarrow$ Mode A |
| **I. Partial Profile** | Khảo sát da thiếu thông tin | `PARTIAL_PROFILE_FALLBACK` | $V_{profile}$ xây dựng từ các trường sẵn có | Weighted Rating |

---

## 6. KIỂM TOÁN HỒ SƠ VÀ BẢNG KHẢO SÁT DA (PROFILE AUDIT)

* **Vị trí lưu trữ thực tế:** Lưu trực tiếp trong collection `nguoidung`, trường nhúng `khao_sat_da`.
* **Cấu trúc trường dữ liệu kiểm toán:**
  * `loai_da`: Loại da tự khai báo (`da_dau`, `da_kho`, `da_hon_hop`, `da_nhay_cam`, `da_thuong`).
  * `tinh_trang_da` / `skin_concerns`: Các vấn đề da quan tâm (`mun`, `tham_nam`, `lao_hoa`, `lo_chan_long`).
  * `ngan_sach` / `budget_preference`: Khung giá quan tâm (`duoi_300k`, `300k_500k`, `tren_500k`).
  * `ngay_khao_sat`: Dấu thời gian hoàn thành khảo sát.
* **Xác nhận sự thật:** Không có bảng hoặc collection riêng mang tên `ho_so_da`. Phân biệt rõ ràng giữa khảo sát đầy đủ và khảo sát từng phần (Partial Profile) thông qua sự hiện diện của trường loại da và mức ngân sách.

---

## 7. KIỂM TOÁN MÔ HÌNH ADAPTIVE HYBRID VÀ RERANKING

### 7.1. Công thức dung hợp vector truy vấn
Trong chế độ `ADAPTIVE_HYBRID`, vector truy vấn tổng hợp được tạo lập theo tỷ lệ cân bằng:

$$U_{query} = 0.50 \cdot U_{behavior} + 0.50 \cdot V_{profile}$$

### 7.2. Công thức chấm điểm và xếp hạng lại (Reranking)
Điểm số cuối cùng của mỗi sản phẩm ứng viên $i$ được tính toán qua công thức:

$$\text{FinalScore}(i) = 0.70 \cdot \text{Cosine}(U_{query}, \mathbf{v}_i) + 0.20 \cdot \text{SkinBonus}(i) + 0.10 \cdot \text{BudgetBonus}(i)$$

Trong đó:
* $\text{SkinBonus}(i) = 1.0$ nếu sản phẩm phù hợp hoàn toàn với loại da người dùng, ngược lại bằng $0.0$.
* $\text{BudgetBonus}(i) = 1.0$ nếu giá sản phẩm nằm trong khoảng ngân sách khai báo, ngược lại bằng $0.0$.
* **Khẳng định bản chất:** Các trọng số $0.70 / 0.20 / 0.10$ là **trọng số cấu hình thủ công (Heuristic / Rule-based)**, không phải tham số học máy tối ưu.

---

## 8. KIỂM TOÁN TÍNH MINH BẠCH VÀ GIẢI THÍCH ĐƯỢC (EXPLAINABILITY)

Hệ thống gán nhãn giải thích (Reason Tags) cho từng sản phẩm gợi ý dựa trên tín hiệu đóng góp thực tế:
* `"Dựa trên sản phẩm bạn vừa xem"`: Kích hoạt khi Cosine Similarity cao với sản phẩm trong `$_SESSION['viewed_products']`.
* `"Phù hợp với sản phẩm trong giỏ hàng"`: Kích hoạt khi có sự tương đồng với sản phẩm trong `$_SESSION['cart']`.
* `"Liên quan đến từ khóa bạn vừa tìm"`: Kích hoạt khi truy vấn tìm kiếm gần nhất đóng góp vào $U_{behavior}$.
* `"Phù hợp với hồ sơ làn da của bạn"`: Kích hoạt khi sản phẩm nhận điểm thưởng $\text{SkinBonus} > 0$.
* `"Trong tầm giá bạn quan tâm"`: Kích hoạt khi sản phẩm nhận điểm thưởng $\text{BudgetBonus} > 0$.
* `"Sản phẩm được cộng đồng đánh giá cao"`: Kích hoạt trong chế độ `SIMPLE` Weighted Rating.

> **NGUYÊN TẮC AN TOÀN:** Tuyệt đối không hiển thị các tuyên bố mang tính y khoa hoặc điều trị bệnh da liễu (No Medical Claims).

---

## 9. TỔNG HỢP NGHIÊN CỨU LUẬT KẾT HỢP (ASSOCIATION RULES SUMMARY)

* **Trạng thái nghiên cứu:** Đã hoàn thành các Step nghiên cứu lý thuyết và kiểm soát thực nghiệm (Step 5).
* **Tập dữ liệu thử nghiệm:** Kiểm thử trên các tập giỏ hàng mô phỏng kiểm soát với quy mô tăng dần: 30 baskets $\rightarrow$ 100 baskets $\rightarrow$ 300 baskets trên tập 20 SKU tiêu biểu.
* **Thuật toán đối soát:** Triển khai độc lập hai thuật toán **Apriori** và **FP-Growth**, xác nhận tính nhất quán và hội tụ của các chỉ số:
  $$\text{Support}(X \Rightarrow Y) = P(X \cup Y), \quad \text{Confidence}(X \Rightarrow Y) = \frac{P(X \cup Y)}{P(X)}, \quad \text{Lift}(X \Rightarrow Y) = \frac{P(X \cup Y)}{P(X) \cdot P(Y)}$$
* **Kết luận kiểm toán:**
  * **Trạng thái:** *RESEARCH / SYNTHETIC CONTROLLED EXPERIMENT*.
  * **Mức độ sẵn sàng vận hành:** **KHÔNG PRODUCTION-READY**.
  * **Định hướng tương lai:** Chỉ có thể kích hoạt tính năng "Thường được mua cùng nhau" (Frequently Bought Together) khi hệ thống thu thập tối thiểu 1,000 đơn hàng hữu cơ đa sản phẩm từ người dùng thực tế.

---

## 10. TỔNG HỢP NGHIÊN CỨU LỌC CỘNG TÁC (COLLABORATIVE FILTERING SUMMARY)

* **Trạng thái nghiên cứu:** Đã hoàn thành đánh giá thực nghiệm ngoại tuyến (Step 6).
* **Các mô hình đã đánh giá:**
  1. **Item-kNN (Item-based Collaborative Filtering):** Dự báo sở thích dựa trên ma trận đồng tương tác.
  2. **Funk-style Matrix Factorization (MF):** Phân rã ma trận người dùng - sản phẩm thành các vector tiềm ẩn (Latent Vectors).
  3. **Bayesian Personalized Ranking (BPR):** Tối ưu hóa thứ tự ưu tiên cặp (Pairwise Ranking Loss) trên dữ liệu tương tác ngầm.
* **Kết quả thực nghiệm:** Trong các thí nghiệm synthetic implicit-feedback, BPR cải thiện đáng kể so với implementation Funk MF hiện tại ở một số metric ranking và cho thấy tín hiệu đáng nghiên cứu tiếp. Kết quả không thiết lập hiệu quả trên người dùng SkinSyntaxVN thực.
* **Kết luận kiểm toán:**
  * **Trạng thái:** *RESEARCH / SYNTHETIC EVALUATION*.
  * **Mức độ sẵn sàng vận hành:** **KHÔNG PRODUCTION-READY**.
  * Dữ liệu tương tác thực tế của SkinSyntaxVN hiện quá thưa thớt, chưa đủ điều kiện kiểm chứng thực tế mô hình lọc cộng tác trên production.

---

## 11. TỔNG HỢP VÀ ĐỊNH VỊ CUỐI CÙNG VỀ PHÂN CỤM K-MEANS (STEP 7E)

* **Định danh phân loại:**
  $$\textbf{K-Means Clustering — Research Only (no universal K established)}$$
* **Kết luận chính thức từ Step 7E:** 
  $$\textbf{RESEARCH COMPLETE — NOT PRODUCTION VALIDATED}$$
* **Lưu ý về tham số $K$:** Giá trị $K=8$ chỉ đóng vai trò reference $K$ trong một số phân tích minh họa của Step 7D, tuyệt đối không phải là cấu hình phổ quát (universal configuration) của toàn hệ thống.
* **Vai trò được dữ liệu và thực nghiệm hỗ trợ trực tiếp nhất (Supported Roles):**
  * Phân tích và khám phá cấu trúc danh mục ngoại tuyến (Offline Exploratory Catalog Analysis).
  * Phân đoạn danh mục sản phẩm (Catalog Taxonomy Segmentation).
* **Vai trò tiềm năng nhưng chưa được kiểm chứng (Plausible but Unvalidated Roles):**
  * Sinh tập ứng viên thu hẹp (Candidate Generation).
  * Kiểm soát độ đa dạng danh mục gợi ý (Diversity Control).
  * Truy vấn sản phẩm tương đồng (Similar-product Retrieval).
* **Kết luận kiểm toán:** Tuyệt đối không tích hợp K-Means vào website production; không chạy thêm các thử nghiệm lớn làm xáo trộn kiến trúc.

---

## 12. MA TRẬN SO SÁNH PRODUCTION vs. RESEARCH (COMPONENT MATRIX)

| Thành phần | Thuật toán | Nguồn dữ liệu | Trạng thái kỹ thuật | Đang chạy trên Web? | Dữ liệu Synthetic? | Sẵn sàng Production? | Mục đích chính |
| :--- | :--- | :--- | :--- | :---: | :---: | :---: | :--- |
| **Simple Recommender** | IMDb Weighted Rating ($m=21$) | `san_pham` ($N=2,473$) | PRODUCTION | CÓ | KHÔNG | CÓ | Gợi ý sản phẩm nổi bật toàn cục (Cold Start) |
| **Content-Based TF-IDF** | TF-IDF (Config B) + Cosine | `san_pham` metadata | PRODUCTION | CÓ | KHÔNG | CÓ | Gợi ý dựa trên độ tương đồng nội dung sản phẩm |
| **Behavior-Aware CB** | Multi-signal Vector Fusion | Session, `hoa_don` | PRODUCTION | CÓ | KHÔNG | CÓ | Cá nhân hóa theo hành vi gần đây của người dùng |
| **Profile-Aware CB** | Skin Survey Feature Mapping | `nguoidung.khao_sat_da` | PRODUCTION | CÓ | KHÔNG | CÓ | Cá nhân hóa theo loại da và ngân sách khai báo |
| **Adaptive Hybrid** | 50/50 Query + Reranking | Session, DB, Profile | PRODUCTION | CÓ | KHÔNG | CÓ | Dung hợp hành vi và hồ sơ da vào một danh sách |
| **Explainability Engine** | Rule-based Grounded Tags | Run-time Match Signals | PRODUCTION | CÓ | KHÔNG | CÓ | Hiển thị lý do gợi ý minh bạch cho người dùng |
| **Interaction Logging** | Event Capture Protocol | Session, `lich_su_tim_kiem` | PRODUCTION | CÓ | KHÔNG | CÓ | Ghi nhận sự kiện xem, giỏ hàng, tìm kiếm |
| **Apriori** | Frequent Itemset Mining | Baskets mô phỏng | RESEARCH | KHÔNG | CÓ | KHÔNG | Khai phá luật mua kèm kiểm soát |
| **FP-Growth** | FP-Tree Frequent Mining | Baskets mô phỏng | RESEARCH | KHÔNG | CÓ | KHÔNG | Khai phá luật mua kèm kiểm soát hiệu năng cao |
| **Item-kNN** | Cosine Item Neighborhood | Tương tác mô phỏng | RESEARCH | KHÔNG | CÓ | KHÔNG | Nghiên cứu lọc cộng tác dựa trên sản phẩm |
| **Funk MF** | Matrix Factorization (SGD) | Tương tác mô phỏng | RESEARCH | KHÔNG | CÓ | KHÔNG | Nghiên cứu phân rã ma trận tiềm ẩn |
| **BPR** | Bayesian Personalized Ranking | Tương tác mô phỏng | RESEARCH | KHÔNG | CÓ | KHÔNG | Nghiên cứu xếp hạng tương tác ngầm |
| **K-Means** | K-Means (No universal K) | Catalog đa đặc trưng | RESEARCH | KHÔNG | KHÔNG | KHÔNG | Phân đoạn và khám phá cấu trúc catalog offline |
| **Ingredient Parser V2** | Rule-based String Parser V2 | `san_pham.thanh_phan` | EXPERIMENTAL | KHÔNG | KHÔNG | KHÔNG | Nghiên cứu bóc tách thành phần chuyên sâu |

---

## 13. ÁNH XẠ KHÔNG GIAN GIAO DIỆN TRANG CHỦ (HOMEPAGE SECTIONS)

### 13.1. Hiện trạng triển khai trên giao diện
* **Section 1: Flash Sale (Khuyến Mãi Khủng)** $\longrightarrow$ Lọc trực tiếp các sản phẩm có `phan_tram_giam_gia > 0`.
* **Section 2: Dành Riêng Cho Bạn (Cá Nhân Hóa)** $\longrightarrow$ Gọi `ContentBasedRecommender::recommendHybrid()`, tự động thích ứng với trạng thái người dùng.
* **Section 3: Sản Phẩm Nổi Bật / Đánh Giá Cao** $\longrightarrow$ Gọi `SimpleRecommender::getTopRatedProducts()`.

### 13.2. Đề xuất quy hoạch kiến trúc tương lai (Không sửa UI hiện tại)
* Khi có đủ dữ liệu đơn hàng hữu cơ $\longrightarrow$ Kích hoạt Section *"Thường được mua cùng nhau"* trên trang chi tiết sản phẩm bằng FP-Growth.
* Khi hoàn thành thử nghiệm sinh ứng viên $\longrightarrow$ Kích hoạt Section *"Khám phá nhóm sản phẩm tương đồng"* dựa trên cụm K-Means.

---

## 14. KIỂM TOÁN NGUỒN DỮ LIỆU THỰC TẾ (DATA SOURCES AUDIT)

Kiểm toán xác nhận danh sách các bảng và collection thực sự tồn tại trong cơ sở dữ liệu MongoDB `skinsyntax`:
1. `san_pham`: Chứa siêu dữ liệu sản phẩm, giá bán, thành phần, danh mục, trạng thái hoạt động.
2. `danh_muc`: Chứa cây danh mục sản phẩm 2 cấp.
3. `thuong_hieu`: Chứa thông tin các nhãn hàng mỹ phẩm.
4. `khach_hang`: Chứa hồ sơ thông tin khách hàng.
5. `nguoidung`: Chứa tài khoản người dùng và trường nhúng `khao_sat_da`.
6. `hoa_don`: Chứa lịch sử đơn hàng và trạng thái đơn hàng.
7. `chi_tiet_hoa_don`: Chứa chi tiết từng sản phẩm trong đơn hàng.
8. `danh_gia_san_pham`: Chứa điểm đánh giá và nhận xét của khách hàng.
9. `lich_su_tim_kiem`: Chứa nhật ký các từ khóa tìm kiếm.
10. `tuong_tac_nguoi_dung`: Collection lưu trữ log tương tác dài hạn.

---

## 15. BẢNG KIỂM KÊ DỮ LIỆU THỰC TẾ (CURRENT DATA INVENTORY)

Số liệu kiểm toán Read-Only trực tiếp từ cơ sở dữ liệu MongoDB `skinsyntax`:

* **Sản phẩm chăm sóc da mặt hoạt động trong production:** Hiện có chính xác **2,473** sản phẩm active thuộc 28 danh mục phân loại chuẩn hóa.
* **Bộ dữ liệu nguồn thô trước chuẩn hóa (Pre-cleanup / Raw Catalog Store):** Chứa 6,377 bản ghi (bao gồm sản phẩm chăm sóc tóc, cơ thể, trang điểm, hàng thử nghiệm hoặc ngừng kinh doanh trong kho lưu trữ cục bộ), **tuyệt đối không được mô tả như thể production `san_pham` hiện đang chứa 6,377 sản phẩm skincare hoạt động**.

| Bảng / Collection | Tổng số bản ghi | Dữ liệu hữu cơ (Organic) | Dữ liệu cũ (Legacy) | Dữ liệu thử nghiệm (Test) | Dữ liệu mô phỏng (Synthetic) |
| :--- | :---: | :---: | :---: | :---: | :---: |
| `san_pham` (Catalog skincare hoạt động) | **2,473** | 2,473 | 0 | 0 | 0 |
| `danh_muc` (Cây phân loại chuẩn) | **28** | 28 | 0 | 0 | 0 |
| `thuong_hieu` (Thương hiệu) | **432** | 432 | 0 | 0 | 0 |
| `khach_hang` | **19** | 19 | 0 | 0 | 0 |
| `nguoidung` | **14** | 14 | 0 | 0 | 0 |
| `hoa_don` | **28** | 28 | 0 | 0 | 0 |
| `chi_tiet_hoa_don` | **33** | 33 | 0 | 0 | 0 |
| `danh_gia_san_pham` | **18** | 18 | 0 | 0 | 0 |
| `lich_su_tim_kiem` | **30** | 30 | 0 | 0 | 0 |
| `tuong_tac_nguoi_dung` | **0** | 0 | 0 | 0 | 0 |
| Kho thô nguồn trước lọc (`san_pham_raw`) | **6,377** | 0 | 6,377 | 0 | 0 |
| Giỏ hàng mô phỏng (Step 5) | **300** | 0 | 0 | 0 | 300 |
| Tương tác mô phỏng (Step 6) | **5,000** | 0 | 0 | 0 | 5,000 |

---

## 16. HỆ THỐNG CÔNG THỨC TOÁN HỌC CHUẨN CHO LUẬN VĂN (FORMULA REFERENCE)

Toàn bộ 13 công thức toán học từ A đến M được chuẩn hóa chi tiết trong tài liệu đính kèm `formula_reference.md`:
* **Công thức A (Weighted Rating):** $WR = \frac{v}{v+m} R + \frac{m}{v+m} C$ (Production)
* **Công thức B (TF-IDF):** $\text{TF-IDF}(t, d) = \text{TF}(t, d) \times \text{IDF}(t)$ (Production)
* **Công thức C (Cosine Similarity):** $\text{Cosine}(\mathbf{u}, \mathbf{v}) = \frac{\mathbf{u} \cdot \mathbf{v}}{\|\mathbf{u}\|_2 \|\mathbf{v}\|_2}$ (Production)
* **Công thức D (Behavior Vector):** $U_{behavior} = \sum_{s \in \text{Signals}} w_s \cdot \sum_{i \in s} \text{decay}(t) \cdot \mathbf{v}_i$ (Production)
* **Công thức E (Profile Vector):** $V_{profile} = \sum_{k} \alpha_k \mathbf{t}_k$ (Production)
* **Công thức F (Adaptive Hybrid Query):** $U_{query} = 0.50 \cdot U_{behavior} + 0.50 \cdot V_{profile}$ (Production)
* **Công thức G (Final Reranking):** $\text{FinalScore} = 0.70 \cdot \text{Cosine} + 0.20 \cdot \text{SkinBonus} + 0.10 \cdot \text{BudgetBonus}$ (Production)
* **Công thức H (Support):** $\text{Support}(X \Rightarrow Y) = \frac{|\{T \in D \mid X \cup Y \subseteq T\}|}{|D|}$ (Research)
* **Công thức I (Confidence):** $\text{Confidence}(X \Rightarrow Y) = \frac{\text{Support}(X \cup Y)}{\text{Support}(X)}$ (Research)
* **Công thức J (Lift):** $\text{Lift}(X \Rightarrow Y) = \frac{\text{Support}(X \cup Y)}{\text{Support}(X) \times \text{Support}(Y)}$ (Research)
* **Công thức K (Euclidean Distance):** $d(\mathbf{x}, \mathbf{y}) = \sqrt{\sum_{j=1}^D (x_j - y_j)^2}$ (Research)
* **Công thức L (K-Means WCSS):** $\text{WCSS} = \sum_{k=1}^K \sum_{\mathbf{x} \in C_k} \|\mathbf{x} - \boldsymbol{\mu}_k\|_2^2$ (Research)
* **Công thức M (BPR Optimization Objective):** $\max_\Theta \sum_{(u, i, j) \in D_S} \ln \sigma(\hat{x}_{ui} - \hat{x}_{uj}) - \frac{\lambda_\Theta}{2} \|\Theta\|_2^2$ (Research)

---

## 17. KẾT QUẢ KIỂM THỬ 9 KỊCH BẢN PRODUCTION THỰC TẾ

Script kiểm thử độc lập `scratch/run_production_scenarios.php` đã thực thi trên môi trường PHP thực tế:
1. **Scenario A (Cold Start):** Định tuyến thành công vào chế độ `SIMPLE`. Top-4 trả về các sản phẩm được đánh giá cao nhất theo chuẩn IMDb.
2. **Scenario B (Search Only):** Định tuyến vào `BEHAVIOR_CONTENT`. Top-4 bám sát từ khóa tìm kiếm `"serum vitamin c mờ thâm"`.
3. **Scenario C (View Only):** Định tuyến vào `BEHAVIOR_CONTENT`. Top-4 chứa các sản phẩm cùng nhóm công dụng phục hồi với sản phẩm vừa xem.
4. **Scenario D (Cart Only):** Định tuyến vào `BEHAVIOR_CONTENT`. Top-4 hiển thị các sản phẩm bổ trợ với món hàng trong giỏ.
5. **Scenario E (Purchase Only):** Định tuyến vào `PURCHASE_CONTENT`. Top-4 phản ánh lịch sử mua hàng có tính toán suy giảm thời gian.
6. **Scenario F (Mixed Behavior):** Định tuyến vào `BEHAVIOR_CONTENT`. Dung hợp đa tín hiệu theo tỷ lệ baseline $[0.35, 0.35, 0.20, 0.10]$.
7. **Scenario G (Profile Only):** Định tuyến vào `PROFILE_CONTENT`. Top-4 phù hợp hoàn hảo với loại da dầu và ngân sách dưới 300k.
8. **Scenario H (Profile + Behavior):** Định tuyến vào `ADAPTIVE_HYBRID`. Kết hợp hoàn hảo giữa mối quan tâm tìm kiếm và đặc điểm làn da.
9. **Scenario I (Partial Profile):** Định tuyến vào `PARTIAL_PROFILE_FALLBACK`. Vẫn ưu tiên loại da đã khai báo dù thiếu thông tin ngân sách.

---

## 18. KẾT QUẢ HỒI QUY HỆ THỐNG (REGRESSION TESTING)

1. **Kiểm tra trạng thái HTTP các Endpoint Production:**
   * `index.php?r=home` $\longrightarrow$ **HTTP 200 OK** (Trang chủ hoạt động hoàn hảo, tải đầy đủ 3 section gợi ý).
   * `index.php?r=chitiet&id=1` $\longrightarrow$ **HTTP 200 OK** (Trang chi tiết sản phẩm hoạt động hoàn hảo).
   * `index.php?r=tatca&q=serum` $\longrightarrow$ **HTTP 200 OK** (Trang tìm kiếm hoạt động hoàn hảo).
2. **Kiểm tra tính cô lập của nhánh nghiên cứu (Research Isolation):**
   * Xác nhận các lớp `CollaborativeFilteringRecommender` và `AssociationRuleRecommender` **hoàn toàn không được triệu gọi** trong call graph của trang chủ và chi tiết sản phẩm.
   * Toàn bộ mã nguồn phân cụm K-Means được cô lập tuyệt đối trong thư mục `backend/experiments/clustering/`.

---

## 19. TỔNG KẾT VÀ BẢN ĐỒ PHÂN LOẠI HỆ THỐNG GỢI Ý

Bảng phân loại kiến trúc cuối cùng xác lập vị trí chính thức của từng cấu phần trong luận văn tốt nghiệp:

```
                        ┌────────────────────────────────────────────────────────┐
                        │      SKINSYNTAXVN RECOMMENDATION ARCHITECTURE          │
                        └──────────────────────────┬─────────────────────────────┘
                                                   │
                 ┌─────────────────────────────────┴─────────────────────────────────┐
                 │                                                                   │
                 ▼                                                                   ▼
   ┌───────────────────────────┐                                       ┌───────────────────────────┐
   │     PRODUCTION ENGINE     │                                       │      RESEARCH ENGINE      │
   │   (Live on SkinSyntaxVN)  │                                       │    (Offline / Synthetic)  │
   └─────────────┬─────────────┘                                       └─────────────┬─────────────┘
                 │                                                                   │
   ├─ Simple IMDb Recommender (m=21)                                   ├─ Association Rules (Apriori/FP-Growth)
   ├─ Content-Based TF-IDF (Config B)                                  ├─ Collaborative Filtering (Item-kNN)
   ├─ Multi-Signal Behavior Fusion                                     ├─ Matrix Factorization (Funk MF)
   ├─ Profile-Aware Personalization                                    ├─ Implicit Ranking (BPR)
   ├─ Adaptive Hybrid Router                                           ├─ Catalog Clustering (K-Means — Research Only)
   ├─ Grounded Reason Tags                                             └─ Rule-based String Parser (Parser V2)
   └─ Product Family Diversity Filter
```

* **PRODUCTION:** Simple Weighted Rating, Content-Based TF-IDF, Behavior-Aware Personalization, Profile-Aware Personalization, Adaptive Hybrid, Grounded Explainability, Interaction capture currently verified by runtime.
* **OFFLINE RESEARCH:** Apriori, FP-Growth, Item-kNN, Funk MF, BPR, K-Means Clustering.
* **EXPERIMENTAL:** Rule-based Ingredient String Parser V2.
* **DATA-INSUFFICIENT (Cần dữ liệu thực tế):** Khai phá luật giỏ hàng thực tế (Real FBT), Kiểm chứng lọc cộng tác trên người dùng thật (Real CF Validation).
* **FUTURE WORK (Hướng phát triển tương lai):** Thu thập dữ liệu tương tác hữu cơ, Thử nghiệm A/B Testing trực tuyến, Đánh giá mù từ chuyên gia da liễu, Tích hợp tín hiệu phân cụm vào sinh ứng viên (Candidate Generation).

---

## 20. BÁO CÁO LỖI VÀ KHUYẾN NGHỊ (AUDIT FINDINGS ONLY)

Tuân thủ nghiêm ngặt quy tắc **AUDIT-ONLY (Không sửa mã nguồn production khi chưa có yêu cầu)**, nhóm kiểm toán ghi nhận 2 phát hiện kiểm toán (Audit Findings) độc lập:

1. **Trường `so_luong_da_ban`:** Trường này không tự động tăng khi có đơn hàng hoàn tất trong `chi_tiet_hoa_don`.  
   * *Đánh giá tác động:* **Không làm thay đổi thuật toán Simple Weighted Rating hiện tại**, vì công thức Simple WR trên production sử dụng $v = \text{so\_luong\_danh\_gia}$ và $R = \text{diem\_danh\_gia}$ làm trọng số chính ($m=21, C=4.8890$). Trường `so_luong_da_ban` chỉ đóng vai trò thứ cấp trong tie-break khi hai sản phẩm có cùng điểm WR tuyệt đối.
   * *Khuyến nghị tương lai:* Thiết lập trigger đồng bộ trường này khi đơn hàng chuyển sang trạng thái `completed`.

2. **Làm tròn điểm Cosine Similarity:** Hàm định dạng hiển thị đôi khi làm tròn điểm tương đồng về $0.000$ trên giao diện người dùng.  
   * *Đánh giá tác động:* **Mức độ thấp (Informational / Display-Layer Only)**. Quá trình tính toán, lọc ngưỡng và xếp hạng ứng viên (ranking) diễn ra hoàn toàn trên số thực dấu phẩy động (`float`), việc làm tròn chuỗi chỉ xảy ra ở tầng hiển thị sau khi đã hoàn tất xếp hạng nên không làm sai lệch thứ tự gợi ý.
   * *Khuyến nghị tương lai:* Giữ nguyên định dạng `float` cho đến tầng View cuối cùng.

---

```
======================================================================
FINAL MASTER AUDIT ENGINE STATUS
======================================================================

PRODUCTION RECOMMENDATION ENGINE:
AUDITED AND OPERATIONAL

OFFLINE ALGORITHM RESEARCH:
COMPLETE FOR CURRENT THESIS SCOPE

RESEARCH ALGORITHMS:
NOT PRODUCTION VALIDATED
======================================================================
```

**KẾT THÚC BÁO CÁO TỔNG KIỂM TOÁN MASTER AUDIT.**
