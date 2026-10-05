# Lịch Sử Nghiên Cứu Phân Cụm SkinSyntaxVN (Clustering Research Timeline)
## Tổng Hợp Tiến Trình Thực Nghiệm Từ Step 6A Đến Step 7D

Tài liệu này tổng hợp toàn bộ lộ trình nghiên cứu học thuật về phân cụm K-Means cho danh mục sản phẩm của SkinSyntaxVN qua 8 giai đoạn liên tục.

---

### Giai Đoạn 1: Step 6A — Micro-Experiment & Khảo Sát Sơ Khởi
- **Mục tiêu:** Kiểm tra tính khả thi ban đầu của thuật toán K-Means trên tập dữ liệu đặc trưng cơ bản (giá bán, lượt mua, đánh giá, loại da nhị phân).
- **Phát hiện chính:**
  - K-Means có thể gom nhóm các điểm dữ liệu thô, nhưng các cụm ban đầu bị chi phối mạnh bởi thang đo không chuẩn hóa.
  - Phân tích PCA cho thấy dữ liệu không phân tách thành các cụm hình cầu rời rạc tự nhiên mà tạo thành dải mật độ liên tục.
- **Artifact:** `backend/experiments/clustering/kmeans_micro_v1/`

---

### Giai Đoạn 2: Step 6B — Khảo Sát Chỉ Số Chọn K (K-Selection Exploration)
- **Mục tiêu:** Khảo sát các chỉ số nội tại (Internal Cluster Validity Indices) gồm Elbow (WCSS), Silhouette Score, Davies-Bouldin Index (DBI), Calinski-Harabasz Index (CHI) trên dải $K \in [2, 15]$.
- **Phát hiện chính:**
  - Các chỉ số xung đột lẫn nhau: Silhouette đạt đỉnh ở $K=2$ hoặc $K=3$ do cấu trúc nhị phân của một số biến, trong khi Elbow không có điểm gập rõ ràng (smooth decay).
  - Khẳng định không thể chọn $K$ chỉ bằng một thước đo đơn lẻ.
- **Artifact:** `backend/experiments/clustering/kmeans_k_selection_v1/`

---

### Giai Đoạn 3: Step 6C — Quyết Định Lựa Chọn K & Độ Ổn Định
- **Mục tiêu:** Đánh giá độ ổn định của các phân hoạch cụm qua nhiều lần khởi tạo ngẫu nhiên k-means++ và lấy mẫu con (subsampling).
- **Phát hiện chính:**
  - Xác nhận chính thức: **Không tồn tại giá trị $K$ tối ưu phổ quát (No Universal K)**.
  - $K=5$ và $K=8$ được chọn làm các mốc khảo sát tham chiếu (reference points) cho các vai trò danh mục cụ thể, không phải là chân lý tối ưu toàn cục.
- **Artifact:** `backend/experiments/clustering/kmeans_k_decision_v1/`

---

### Giai Đoạn 4: Step 7A — Đóng Băng Tập Dữ Liệu & Quy Chuẩn Thang Đo (Scaling & Integrity)
- **Mục tiêu:** Xây dựng quy trình làm sạch dữ liệu nghiêm ngặt, đóng băng tập dữ liệu nghiên cứu chuẩn $N=1,004$ sản phẩm thuộc 5 vai trò dưỡng da cơ bản (Cleanser, Sunscreen, Moisturizer, Serum, Treatment).
- **Phát hiện chính:**
  - Băm vân tay toàn vẹn SHA-256 (`241255cf58bc2cffe41e3015fb5225add6fff3e0e09a479cbd59f400720ddb00`).
  - Chuẩn hóa RobustScaler / StandardScaler cho giá log để loại bỏ hiện tượng giá thống trị khoảng cách Euclidean.
- **Artifact:** `backend/experiments/clustering/kmeans_scaling_v1/`

---

### Giai Đoạn 5: Step 7B — Thử Nghiệm Không Gian Đặc Trưng (Feature Space Exploration)
- **Mục tiêu:** Mở rộng không gian đặc trưng từ metadata 9D (`M_9D`) sang kết hợp thành phần thô TF-IDF (`MI_109D`) và giảm chiều SVD (`MI_39D`).
- **Phát hiện chính:**
  - Không gian TF-IDF thưa 109D làm bùng nổ khoảng cách và gây loãng cụm (curse of dimensionality).
  - TruncatedSVD 30D giúp nén thông tin thành phần, giảm hiện tượng loãng khoảng cách, giữ được tính liên tục của dữ liệu.
- **Artifact:** `backend/experiments/clustering/kmeans_feature_space_v1/`

---

### Giai Đoạn 6: Step 7C — Quyết Định Đặc Trưng & Phát Hiện Lỗi Parser (Feature Decision)
- **Mục tiêu:** So sánh phân hoạch giữa không gian metadata thuần túy và không gian có bổ sung thành phần.
- **Phát hiện chính:**
  - Cấu hình có thành phần (`MI_INGREDIENT`) làm thay đổi hình học cụm và phân hóa công thức bên trong vai trò.
  - Phát hiện nghiêm trọng: Bộ phân tích thành phần ban đầu (Parser V1) bị lỗi cắt chuỗi tại dấu gạch chéo (`/`), làm gãy các hóa chất phổ biến như `propanediol / butylene glycol` thành các token rác (`diol`).
- **Artifact:** `backend/experiments/clustering/kmeans_feature_decision_v1/`

---

### Giai Đoạn 7: Step 7C.1 — Kiểm Toán Pháp Y Bộ Phân Tích Thành Phần (Ingredient Parser Audit)
- **Mục tiêu:** Kiểm toán toàn diện lỗi parser, xây dựng Parser V2 xử lý dấu phân cách ngữ cảnh, ngoặc đơn và ghép từ hóa học.
- **Phát hiện chính:**
  - Trong các pattern kiểm toán tại Step 7C.1, Parser V2 không còn ghi nhận lỗi tách sai token.
  - So sánh láng giềng Top-10 giữa V1 và V2 đạt `Mean Jaccard@10 = 0.8140`, chứng minh sự điều chỉnh rõ nét nhưng không phá vỡ cấu trúc lân cận.
  - Khẳng định: Biểu diễn thành phần phân tích cú pháp (parsed ingredient representation) **không đồng nghĩa với bản thể học hóa học (chemical ontology) hay tính tương đương lâm sàng (clinical equivalence)**.
- **Artifact:** `backend/experiments/clustering/ingredient_parser_audit_v1/`

---

### Giai Đoạn 8: Step 7D — Mở Rộng Toàn Bộ Catalog Qua Phân Cấp Danh Mục (Taxonomy Expansion)
- **Mục tiêu:** Chuyển đổi từ tập con 5 vai trò ($N=1,004$) sang toàn bộ catalog chăm sóc da mặt hoạt động ($N=2,473$) bằng cách tích hợp cây phả hệ 28 nút của `danh_muc` trong MongoDB.
- **Phát hiện chính:**
  - 100% catalog được bao phủ bởi 21 danh mục lá, 0 chu trình, 0 mồ côi. 27 sản phẩm khuyết thành phần được xử lý cờ minh bạch `INGREDIENT_MISSING = True`.
  - Khối mã hóa phân cấp `HIERARCHICAL_MULTI_HOT` bảo toàn quan hệ ngữ nghĩa nhánh cha.
  - Khối thành phần V2 SVD 30D làm tăng độ trùng lặp thuật ngữ thành phần (`parsed ingredient-term overlap`) của Top-10 láng giềng từ 0.1593 lên 0.2668 (+67.5%), trong khi duy trì tỷ lệ cùng danh mục lá 93.25% và cùng nhánh cha 100.0%.
  - Phân tích độ nhạy biến thể (`VARIANT_COLLAPSED`): ARI = 0.5655, NMI = 0.7458 cho thấy cấu trúc dòng biến thể sản phẩm tạo ra biến đổi thực chất (`material change`) đối với phân hoạch cụm.
- **Artifact:** `backend/experiments/clustering/taxonomy_expansion_v1/`
