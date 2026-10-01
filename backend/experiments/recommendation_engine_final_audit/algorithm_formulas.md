# CƠ SỞ TOÁN HỌC VÀ CÔNG THỨC THUẬT TOÁN (ALGORITHM FORMULAS)

Tài liệu này cung cấp toàn bộ các định nghĩa toán học và công thức chuẩn tắc được cài đặt trong mã nguồn SkinSyntaxVN phục vụ công tác đối chiếu và kiểm tra tính nhất quán học thuật.

---

## 1. SIMPLE RECOMMENDER: IMDB-STYLE BAYESIAN WEIGHTED RATING (WR)

Dùng cho phân hệ gợi ý không phụ thuộc ngữ cảnh (Cold-Start & Top-Rated Products).

### 1.1. Công thức toán học
$$\text{WR} = \left( \frac{v}{v + m} \right) \cdot R + \left( \frac{m}{v + m} \right) \cdot C$$

Trong đó:
- $v$: Số lượt đánh giá của sản phẩm đang xét (tương ứng trường `so_luong_danh_gia` hoặc đếm từ collection `danh_gia`).
- $R$: Điểm đánh giá trung bình thực tế của sản phẩm đang xét (tương ứng trường `diem_danh_gia`, giá trị thuộc thang $[1.0, 5.0]$).
- $C$: Điểm đánh giá trung bình của toàn bộ danh mục sản phẩm (Prior Global Mean Rating).  
  $$\mathbf{C = 4.889}$$ *(Giá trị hằng số cố định tính toán trên toàn bộ catalog)*.
- $m$: Ngưỡng số lượt đánh giá tối thiểu để tạo độ tin cậy thống kê (Prior Confidence Threshold).  
  $$\mathbf{m = 21}$$ *(Hằng số phân vị thống kê)*.

### 1.2. Quy tắc phá vỡ thế hòa (Tie-Breaking Rules)
Khi nhiều sản phẩm có điểm $\text{WR}$ xấp xỉ nhau, thứ tự sắp xếp được giải quyết theo 4 cấp ưu tiên giảm dần:
1. `weighted_rating` giảm dần ($\text{WR} \downarrow$).
2. `v` (số lượng đánh giá) giảm dần ($v \downarrow$).
3. `so_luong_da_ban` giảm dần *(Trường số đếm hiển thị giao diện, không đại diện cho lượng bán thực tế đã kiểm toán)*.
4. `id` tăng dần ($\text{id} \uparrow$) để đảm bảo kết quả tất định (deterministic).

### 1.3. Điều kiện hợp lệ của sản phẩm (Eligibility Rules)
- Sản phẩm phải ở trạng thái hiển thị (`trang_thai` $\neq$ inactive, hidden, disabled, 0).
- Sản phẩm phải có giá bán hợp lệ (`gia_ban` $> 0$).

---

## 2. CONTENT-BASED RECOMMENDER: KHÔNG GIAN VECTOR TF-IDF (TF-IDF VECTOR SPACE)

### 2.1. Cấu trúc trường văn bản và Trọng số thuộc tính (Feature Weights)
Mỗi sản phẩm được biểu diễn thành một tài liệu văn bản tổng hợp $D_i$ thông qua việc lặp lại các trường thuộc tính theo trọng số đã được kiểm chuẩn (Config B):

$$D_i = \text{TênSP} \times 2 + \text{ThươngHiệu} \times 1 + \text{DanhMụcĐầyĐủ} \times 3 + \text{LoạiDa} \times 3 + \text{ThànhPhần} \times 2 + \text{MôTảNgắn} \times 1$$

- **Nguồn thành phần (Ingredients):** Ưu tiên trường `thanh_phan`, fallback sang `thanh_phan_chinh`.
- **Nguồn mô tả (Description):** Ưu tiên trường `mo_ta_ngan`, fallback sang `mo_ta`, loại bỏ thẻ HTML bằng `strip_tags()` và cắt ngắn cố định ở **300 ký tự UTF-8** đầu tiên để chống nhiễu từ khóa rác.

### 2.2. Tokenization & Tiền xử lý
- Chuyển toàn bộ về chữ thường bằng `mb_strtolower($text, 'UTF-8')`.
- Tách từ dựa trên các ký tự phân tách (whitespace, dấu câu).
- Lọc bỏ các từ dừng tiếng Việt (Vietnamese stopwords) và các từ có độ dài $< 2$ ký tự.

### 2.3. Trọng số Term Frequency - Inverse Document Frequency (Smooth TF-IDF)
Với từ khóa $t$ trong sản phẩm $i$:
$$\text{TF}(t, i) = \frac{\text{count}(t, D_i)}{\sum_{t' \in D_i} \text{count}(t', D_i)}$$

Công thức nghịch đảo tần suất tài liệu mượt (Smooth IDF):
$$\text{IDF}(t) = \ln \left( \frac{1 + N}{1 + \text{DF}(t)} \right) + 1$$

Trong đó:
- $N$: Tổng số sản phẩm trong catalog ($N = 2,473$).
- $\text{DF}(t)$: Số lượng sản phẩm có chứa từ khóa $t$.
- **Bộ lọc tần suất tài liệu (Document Frequency Filter):** Chỉ các từ thỏa mãn:
  $$3 \le \text{DF}(t) \le 0.80 \cdot N$$
  mới được đưa vào không gian vector (loại bỏ từ cực hiếm $\text{DF} < 3$ và từ quá phổ biến xuất hiện trên 80% sản phẩm).
- **Giới hạn số chiều:** Mỗi sản phẩm chỉ giữ lại **Top 30** từ khóa có điểm $\text{TF} \times \text{IDF}$ cao nhất.

### 2.4. Chuẩn hóa L2 và Độ tương đồng Cosine (Cosine Similarity)
Vector của sản phẩm $i$ được chuẩn hóa Euclid (L2 normalization):
$$\mathbf{d}_i = \frac{\mathbf{v}_i}{\|\mathbf{v}_i\|_2} = \frac{\mathbf{v}_i}{\sqrt{\sum_t v_{it}^2}}$$

Độ tương đồng nội dung giữa truy vấn $\mathbf{q}$ và sản phẩm $\mathbf{d}_i$:
$$S_{\text{Content}}(\mathbf{q}, \mathbf{d}_i) = \cos(\mathbf{q}, \mathbf{d}_i) = \frac{\mathbf{q} \cdot \mathbf{d}_i}{\|\mathbf{q}\|_2 \cdot \|\mathbf{d}_i\|_2} = \sum_{t \in \mathbf{q} \cap \mathbf{d}_i} q_t \cdot d_{it}$$

---

## 3. MULTI-SIGNAL BEHAVIOR FUSION (HỢP NHẤT ĐA TÍN HIỆU HÀNH VI)

Hệ thống xây dựng 4 vector thành phần tương ứng với 4 hành vi trong phiên:

1. **Tìm kiếm gần đây ($\mathbf{V}_{\text{search}}$):**
   Lấy tối đa 3 truy vấn gần nhất theo thứ tự thời gian gần nhất (MRU). Áp dụng suy giảm vị trí $\mathbf{w}_{\text{pos}} = [1.0, 0.6, 0.3]$:
   $$\mathbf{V}_{\text{search}} = \text{Normalize}\left( \sum_{k=1}^{\min(3, |Q|)} w_{\text{pos}}[k] \cdot \text{TFIDF}(q_k) \right)$$

2. **Xem sản phẩm gần đây ($\mathbf{V}_{\text{view}}$):**
   Lấy tối đa 5 sản phẩm xem gần nhất (MRU). Áp dụng trọng số suy giảm:
   - 1 item: $[1.0]$
   - 2 items: $[0.65, 0.35]$
   - 3 items: $[0.55, 0.30, 0.15]$
   - 4 items: $[0.50, 0.25, 0.15, 0.10]$
   - 5 items: $[0.50, 0.25, 0.15, 0.07, 0.03]$

3. **Giỏ hàng hiện tại ($\mathbf{V}_{\text{cart}}$):**
   Hợp nhất vector các sản phẩm trong giỏ có nhân với số lượng $q \in [1, 10]$:
   $$\mathbf{V}_{\text{cart}} = \text{Normalize}\left( \sum_{p \in \text{Cart}} q_p \cdot \mathbf{d}_p \right)$$

4. **Lịch sử mua hàng ($\mathbf{V}_{\text{purchase}}$):**
   Áp dụng hàm suy giảm thời gian bán rã hàm mũ (Exponential Time Decay):
   $$w_{\text{decay}}(\Delta t) = \exp(-\lambda \cdot \Delta t), \quad \text{với } \lambda = \frac{\ln(2)}{T_{1/2}}$$
   - $T_{1/2} = 60$ ngày *(Engineering Baseline Heuristic)*.
   - $\Delta t$: Khoảng cách thời gian từ lúc đặt hàng đến hiện tại (ngày).

### 3.1. Vector hành vi tổng hợp (Behavior Query Vector)
Các tín hiệu có sẵn được hợp nhất theo trọng số chuẩn (Self-normalized over active signals):
$$\mathbf{U}_{\text{behavior}} = \text{Normalize}\left( \sum_{s \in \text{ActiveSignals}} \bar{w}_s \cdot \mathbf{V}_s \right)$$

Trong đó trọng số cơ sở kỹ thuật (Engineering Baseline Weights - Không phải trọng số học tối ưu):
- $\text{cart} = 0.35$
- $\text{view} = 0.35$
- $\text{search} = 0.20$
- $\text{purchase} = 0.10$

Trọng số chuẩn hóa theo các tín hiệu đang kích hoạt:
$$\bar{w}_s = \frac{w_s}{\sum_{s' \in \text{ActiveSignals}} w_{s'}}$$

---

## 4. PROFILE VECTOR & HYBRID QUERY FUSION

### 4.1. Vector Hồ sơ da ($\mathbf{V}_{\text{profile}}$)
Xây dựng từ văn bản khảo sát của người dùng:
$$T_{\text{profile}} = \text{skin\_type} + \text{van\_de\_da} + \text{muc\_tieu\_cham\_soc}$$
$$\mathbf{V}_{\text{profile}} = \text{Normalize}(\text{TFIDF}(T_{\text{profile}}))$$

### 4.2. Truy vấn Hỗn hợp (Hybrid Query Vector)
Trong chế độ `ADAPTIVE_HYBRID`, vector truy vấn kết hợp cân bằng giữa Hành vi và Hồ sơ da:
$$\mathbf{U}_{\text{query}} = \text{Normalize}\left( 0.50 \cdot \mathbf{U}_{\text{behavior}} + 0.50 \cdot \mathbf{V}_{\text{profile}} \right)$$
*(Hệ số $\alpha = 0.50$ là hằng số đóng băng thuộc Phase A)*.

---

## 5. RERANKING VÀ ĐIỂM SỐ GỢI Ý CUỐI CÙNG (FINAL SCORING EQUATIONS)

### 5.1. Chế độ Hành vi thuần túy (`BEHAVIOR_CONTENT` & `PURCHASE_CONTENT`)
$$\text{FinalScore} = 0.90 \cdot S_{\text{Content}} + 0.10 \cdot S_{\text{Price}}$$

Trong đó điểm khớp mức giá tham chiếu ($S_{\text{Price}}$):
$$S_{\text{Price}}(p, p_{\text{ref}}) = 1.0 - \frac{|p - p_{\text{ref}}|}{\max(p, p_{\text{ref}})}$$

### 5.2. Chế độ Hồ sơ và Hỗn hợp (`PROFILE_CONTENT` & `ADAPTIVE_HYBRID`)
$$\text{FinalScore} = 0.70 \cdot S_{\text{Content}} + 0.20 \cdot S_{\text{Skin}} + 0.10 \cdot S_{\text{Budget}}$$

- **Điểm tương thích loại da ($S_{\text{Skin}}$):**
  - Trùng khớp chính xác loại da sản phẩm và loại da người dùng: **$1.0$**
  - Sản phẩm dành cho "Da thường/Mọi loại da": **$0.70$**
  - Sản phẩm chưa rõ loại da (`Unknown`): **$0.30$**
  - Không tương thích loại da: **$0.0$**
  - Người dùng không khai báo loại da: **$0.50$** (Trung tính)

- **Điểm ngân sách mềm ($S_{\text{Budget}}$):**
  - Nếu giá sản phẩm $p \le \text{NgânSách}$: **$1.0$**
  - Nếu vượt ngân sách: $S_{\text{Budget}} = \max\left(0.10, 1.0 - \frac{p - \text{NgânSách}}{\text{NgânSách}}\right)$

> **LƯU Ý KHOA HỌC:**  
> Các trọng số $0.70 / 0.20 / 0.10$ và $0.90 / 0.10$ là **các tham số luật kinh nghiệm (Rule-based Engineering Baselines)**, không phải là các tham số được tối ưu hóa bằng học máy trên tập dữ liệu gắn nhãn.

---

## 6. OFFLINE RESEARCH: BAYESIAN PERSONALIZED RANKING (BPR)
*(Được đánh dấu rõ: **OFFLINE RESEARCH ONLY** — Không chạy trên Production)*

### 6.1. Hàm mất mát tối ưu hóa xếp hạng cặp (Pairwise Logistic Loss)
$$\mathcal{L}_{\text{BPR}} = - \sum_{(u, i, j) \in D_S} \ln \sigma(\hat{x}_{uij}) + \lambda_{\Theta} \sum_{\theta \in \Theta} \|\theta\|^2$$

Trong đó:
- $u$: Người dùng.
- $i$: Sản phẩm dương tính quan sát thấy trong tập huấn luyện ($\mathbf{R}_{ui} \ge 1.0$).
- $j$: Sản phẩm âm tính được lấy mẫu ngẫu nhiên đều từ danh mục chưa từng tương tác ($j \notin \text{Train}(u)$ và $j \neq \text{TestTarget}(u)$).
- $\hat{x}_{ui} = b_i + \mathbf{p}_u^\top \mathbf{q}_i$: Điểm ưu tiên của $u$ cho sản phẩm $i$.
- $\hat{x}_{uij} = \hat{x}_{ui} - \hat{x}_{uj} = (b_i - b_j) + \mathbf{p}_u^\top (\mathbf{q}_i - \mathbf{q}_j)$.
- $\sigma(z) = \frac{1}{1 + e^{-z}}$: Hàm Sigmoid.

### 6.2. Cập nhật Stochastic Gradient Descent (SGD)
Đặt $z = \frac{1}{1 + e^{\hat{x}_{uij}}}$ (giới hạn $\hat{x}_{uij} \in [-30.0, 30.0]$ để chống tràn số):
$$\mathbf{p}_u \leftarrow \mathbf{p}_u + \gamma \cdot \left( z \cdot (\mathbf{q}_i - \mathbf{q}_j) - \lambda \mathbf{p}_u \right)$$
$$\mathbf{q}_i \leftarrow \mathbf{q}_i + \gamma \cdot \left( z \cdot \mathbf{p}_u - \lambda \mathbf{q}_i \right)$$
$$\mathbf{q}_j \leftarrow \mathbf{q}_j + \gamma \cdot \left( -z \cdot \mathbf{p}_u - \lambda \mathbf{q}_j \right)$$
$$b_i \leftarrow b_i + \gamma \cdot \left( z - \lambda b_i \right)$$
$$b_j \leftarrow b_j + \gamma \cdot \left( -z - \lambda b_j \right)$$
*(Siêu tham số thực nghiệm: $k = 5$, $\gamma = 0.05$, $\lambda = 0.01$, $\text{epochs} = 25$)*.
