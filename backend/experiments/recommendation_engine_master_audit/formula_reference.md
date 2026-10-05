# Bảng Tham Chiếu Công Thức Toán Học (Formula Reference)
## Danh Mục Công Thức Chuẩn Hóa Phục Vụ Báo Cáo Luận Văn & Kiểm Toán

Tài liệu này tổng hợp toàn bộ các công thức toán học được sử dụng trong hệ thống gợi ý và chuỗi nghiên cứu máy học của SkinSyntaxVN, phân định rõ trạng thái vận hành Production và Research.

---

### A. Công Thức IMDb Weighted Rating (Simple Recommender)
- **Trạng thái:** **PRODUCTION ACTIVE** (Chạy tại Section 5.5 và Fallback Section 3)
- **Công thức:**
  $$WR = \left(\frac{v}{v + m}\right) \times R + \left(\frac{m}{v + m}\right) \times C$$
- **Định nghĩa biến:**
  - $WR$: Điểm số xếp hạng có trọng số của sản phẩm (Weighted Rating).
  - $R$: Điểm đánh giá trung bình thực tế của sản phẩm (`diem_danh_gia` trong MongoDB).
  - $v$: Số lượt đánh giá thực tế của sản phẩm (`so_luong_danh_gia` trong MongoDB).
  - $m$: Ngưỡng số lượng đánh giá tối thiểu để đủ điều kiện xếp hạng ($m = 21.0$, phân vị P75).
  - $C$: Điểm đánh giá trung bình toàn sàn ($C = 4.8890$).

---

### B. Công Thức TF-IDF (Term Frequency – Inverse Document Frequency)
- **Trạng thái:** **PRODUCTION ACTIVE** (Xây dựng vector đặc trưng nội dung sản phẩm)
- **Công thức:**
  $$TF(t, d) = \frac{f_{t, d}}{\sum_{t' \in d} f_{t', d}}$$
  $$IDF(t) = \ln\left(\frac{1 + N}{1 + df(t)}\right) + 1$$
  $$TF\text{-}IDF(t, d) = TF(t, d) \times IDF(t)$$
- **Định nghĩa biến:**
  - $f_{t, d}$: Số lần xuất hiện của từ khóa $t$ trong tài liệu văn bản của sản phẩm $d$ (sau khi đã nhân trọng số Config B).
  - $N$: Tổng số sản phẩm trong catalog ($N = 2,473$).
  - $df(t)$: Số lượng sản phẩm trong catalog có chứa từ khóa $t$ (Document Frequency, lọc $3 \le df(t) \le 0.80 N$).

---

### C. Công Thức Độ Tương Đồng Cosine (Cosine Similarity)
- **Trạng thái:** **PRODUCTION ACTIVE** (Tính toán độ tương đồng nội dung sản phẩm)
- **Công thức:**
  $$CosineSim(Q, D) = \frac{Q \cdot D}{\|Q\|_2 \times \|D\|_2} = \frac{\sum_{i=1}^{k} q_i \times d_i}{\sqrt{\sum_{i=1}^{k} q_i^2} \times \sqrt{\sum_{i=1}^{k} d_i^2}}$$
- **Định nghĩa biến:**
  - $Q$: Vector truy vấn (Query Vector đại diện cho hành vi hoặc hồ sơ người dùng).
  - $D$: Vector đặc trưng TF-IDF của sản phẩm ứng viên.
  - $\|Q\|_2, \|D\|_2$: Chuẩn Euclidean (L2 Norm) của các vector.

---

### D. Công Thức Vector Hành Vi Đa Tín Hiệu (Behavior Query Vector)
- **Trạng thái:** **PRODUCTION ACTIVE** (Tổng hợp tín hiệu hành vi thời gian thực)
- **Công thức:**
  $$U_{behavior} = \text{L2Norm}\left(w_{cart} U_{cart} + w_{view} U_{view} + w_{search} U_{search} + w_{purchase} U_{purchase}\right)$$
- **Định nghĩa biến:**
  - $w_{cart} = 0.35, w_{view} = 0.35, w_{search} = 0.20, w_{purchase} = 0.10$: Các trọng số kết hợp baseline.
  - $U_{cart}, U_{view}, U_{search}, U_{purchase}$: Các vector đại diện từng loại hành vi đã được chuẩn hóa L2 riêng lẻ.

---

### E. Công Thức Vector Hồ Sơ Da (Profile Query Vector)
- **Trạng thái:** **PRODUCTION ACTIVE** (Mô hình hóa kết quả khảo sát người dùng)
- **Công thức:**
  $$V_{profile} = \text{L2Norm}\left(\sum_{t \in T_{profile}} IDF(t) \cdot e_t\right)$$
- **Định nghĩa biến:**
  - $T_{profile}$: Tập hợp các từ khóa ngữ nghĩa trích xuất từ loại da (`skin_type`), vấn đề da (`van_de_da`), mục tiêu (`muc_tieu_cham_soc`) của khách hàng.
  - $e_t$: Vector cơ sở đơn vị tương ứng với từ khóa $t$.
  - $IDF(t)$: Trọng số nghịch đảo tần suất tài liệu từ chỉ mục TF-IDF của catalog.

---

### F. Công Thức Truy Vấn Lai Thích Ứng (Adaptive Hybrid Query)
- **Trạng thái:** **PRODUCTION ACTIVE** (Chế độ `ADAPTIVE_HYBRID`)
- **Công thức:**
  $$U_{query} = \text{L2Norm}\left(\alpha \cdot U_{behavior} + (1 - \alpha) \cdot V_{profile}\right)$$
- **Định nghĩa biến:**
  - $\alpha$: Trọng số cân bằng giữa hành vi và hồ sơ da ($\alpha = 0.50$).

---

### G. Công Thức Tái Xếp Hạng Cuối Cùng (Final Reranking Score)
- **Trạng thái:** **PRODUCTION ACTIVE**
- **Công thức:**
  - **Chế độ có hồ sơ da (`ADAPTIVE_HYBRID`, `PROFILE_CONTENT`):**
    $$FinalScore = 0.70 \times ContentSim + 0.20 \times SkinScore + 0.10 \times BudgetScore$$
  - **Chế độ thuần hành vi (`BEHAVIOR_CONTENT`, `PURCHASE_CONTENT`):**
    $$FinalScore = 0.90 \times ContentSim + 0.10 \times PriceSim$$
- **Định nghĩa biến:**
  - $ContentSim$: Độ tương đồng Cosine giữa $U_{query}$ và sản phẩm ứng viên.
  - $SkinScore \in \{1.0, 0.70, 0.0\}$: Điểm tương thích loại da của sản phẩm với loại da người dùng.
  - $BudgetScore \in [0.0, 1.0]$: Điểm phạt nếu giá sản phẩm vượt quá ngân sách khai báo.
  - $PriceSim \in [0.0, 1.0]$: Độ gần gũi về giá so với giá trung bình các sản phẩm vừa tương tác.

---

### H, I, J. Các Công Thức Khai Phá Luật Kết Hợp (Association Rules: Support, Confidence, Lift)
- **Trạng thái:** **OFFLINE RESEARCH ONLY (SYNTHETIC CONTROLLED EXPERIMENTS)**
- **Công thức:**
  $$Support(X \Rightarrow Y) = P(X \cup Y) = \frac{\text{Số giao dịch chứa cả } X \text{ và } Y}{\text{Tổng số giao dịch } |D|}$$
  $$Confidence(X \Rightarrow Y) = P(Y | X) = \frac{Support(X \cup Y)}{Support(X)}$$
  $$Lift(X \Rightarrow Y) = \frac{Confidence(X \Rightarrow Y)}{Support(Y)} = \frac{P(X \cup Y)}{P(X) \times P(Y)}$$
- **Ý nghĩa biến:**
  - $X, Y$: Các tập sản phẩm (Itemsets).
  - $Lift > 1$: Sản phẩm $X$ và $Y$ có xu hướng xuất hiện cùng nhau vượt mức ngẫu nhiên độc lập (Bổ trợ lẫn nhau).

---

### K, L. Các Công Thức Khoảng Cách Euclidean & WCSS Trong K-Means
- **Trạng thái:** **OFFLINE RESEARCH ONLY (STEP 6A – STEP 7E)**
- **Khoảng cách Euclidean:**
  $$d(x_i, c_j) = \|x_i - c_j\|_2 = \sqrt{\sum_{k=1}^{D} (x_{ik} - c_{jk})^2}$$
- **Tổng bình phương sai số nội cụm (Within-Cluster Sum of Squares - WCSS):**
  $$WCSS = \sum_{j=1}^{K} \sum_{x_i \in C_j} \|x_i - c_j\|_2^2$$
- **Ý nghĩa biến:**
  - $x_i$: Vector đặc trưng của sản phẩm thứ $i$ ($D = 62$ chiều trong `CONFIG_TAX_PRICE_SKIN_ING`).
  - $c_j$: Tâm hình học (Centroid) của cụm thứ $j$.
  - $K$: Số lượng cụm phân hoạch.

---

### M. Hàm Mục Tiêu Bayesian Personalized Ranking (BPR Objective)
- **Trạng thái:** **OFFLINE RESEARCH ONLY (COLLABORATIVE FILTERING EXPERIMENTS)**
- **Công thức:**
  $$\mathcal{L}_{BPR} = \sum_{(u, i, j) \in D_S} \ln \sigma(\hat{x}_{ui} - \hat{x}_{uj}) - \lambda_\Theta \|\Theta\|^2$$
- **Định nghĩa biến:**
  - $(u, i, j)$: Bộ ba huấn luyện gồm người dùng $u$, sản phẩm tích cực $i$ (đã tương tác), và sản phẩm tiêu cực $j$ (chưa tương tác).
  - $\hat{x}_{ui} = p_u^T q_i$: Điểm tương tác dự đoán giữa vector tiềm ẩn người dùng $p_u$ và sản phẩm $q_i$.
  - $\sigma(z) = \frac{1}{1 + e^{-z}}$: Hàm sigmoid ánh xạ chênh lệch điểm số sang xác suất người dùng ưa thích $i$ hơn $j$.
  - $\lambda_\Theta \|\Theta\|^2$: Thành phần chính quy hóa L2 ngăn ngừa quá khớp (overfitting).
