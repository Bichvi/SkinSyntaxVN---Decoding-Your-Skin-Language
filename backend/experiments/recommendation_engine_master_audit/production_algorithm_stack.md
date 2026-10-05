# Ngăn Xếp Thuật Toán Production (Production Algorithm Stack)
## Kiểm Toán Chi Tiết Các Công Thức & Quy Tắc Vận Hành Thực Tế

Tài liệu này phân tích chi tiết các thuật toán, công thức toán học, trọng số và cơ chế vận hành đang thực sự chạy trên hệ thống gợi ý của SkinSyntaxVN.

---

### 1. Thuật Toán Gợi Ý Toàn Cục: Simple Recommender (IMDb Weighted Rating)

#### A. Công thức toán học thực tế
Được triển khai trong `SanPham::getSimpleRecommenderProducts()` (`backend/app/models/SanPham.php:934`):
$$WR = \left(\frac{v}{v + m}\right) \times R + \left(\frac{m}{v + m}\right) \times C$$

Trong đó:
- $v$: Số lượng đánh giá của sản phẩm (`so_luong_danh_gia` trong MongoDB).
- $R$: Điểm đánh giá trung bình của sản phẩm (`diem_danh_gia` trong MongoDB).
- $m$: Ngưỡng số lượng đánh giá tối thiểu để đủ điều kiện xếp hạng. Giá trị canonical hiện tại: **$m = 21.0$** (tương ứng phân vị P75 của catalog).
- $C$: Điểm đánh giá trung bình toàn sàn (Global mean rating). Giá trị canonical hiện tại: **$C = 4.8890$**.

#### B. Điều kiện đủ điều kiện (Eligibility Filter)
Sản phẩm phải thỏa mãn đồng thời:
1. `so_luong_danh_gia >= m` ($v \ge 21$),
2. `diem_danh_gia > 0`,
3. `gia_ban > 0`,
4. Thuộc bộ lọc sản phẩm khả dụng (`availableProductFilter()`: trạng thái active, còn hàng trong kho).

#### C. Quy tắc sắp xếp & Phá vỡ hòa điểm (Sorting & Tie-Breaking)
Khi sắp xếp các sản phẩm ứng viên:
1. **Ưu tiên 1:** Điểm `weighted_rating` giảm dần (DESC).
2. **Ưu tiên 2 (Tie-break 1):** Số lượt đánh giá `v` (`so_luong_danh_gia`) giảm dần (DESC).
3. **Ưu tiên 3 (Tie-break 2):** Mã sản phẩm `ma_san_pham` tăng dần theo thứ tự từ điển (ASC).

#### D. Cơ chế bộ đệm (Caching Architecture)
- **Bộ nhớ đệm trong phiên (In-memory static cache):** `self::$simpleRecommenderMemoryCache` lưu kết quả trong vòng đời của request HTTP.
- **Tập tin bộ đệm cấp ứng dụng (File-level cache):** `backend/app/content/simple_recommender_cache.json` với thời gian sống **TTL = 600 giây (10 phút)**.

#### E. Lưu ý quan trọng về dữ liệu
- **Tuyệt đối không sử dụng trường `so_luong_da_ban` làm doanh số thực tế.** Thuật toán Simple Recommender chỉ sử dụng số lượng đánh giá thực tế ($v$) và điểm đánh giá ($R$).

---

### 2. Thuật Toán Gợi Ý Theo Nội Dung: Content-Based TF-IDF Recommender

Được triển khai trong `backend/app/services/ContentBasedRecommender.php`:

#### A. Trích xuất đặc trưng & Trọng số cấu hình B (Feature Extraction & Config B Weights)
Vector văn bản của mỗi sản phẩm được xây dựng bằng cách lặp lại các trường thuộc tính theo trọng số cấu hình chuẩn B:
- **Tên sản phẩm (`ten_san_pham`):** Trọng số **2x**
- **Thương hiệu (`thuong_hieu`):** Trọng số **1x**
- **Danh mục đầy đủ (`danh_muc_day_du`):** Trọng số **3x**
- **Loại da phù hợp (`loai_da`):** Trọng số **3x**
- **Thành phần hoạt chất (`thanh_phan` / `thanh_phan_chinh`):** Trọng số **2x**
- **Mô tả ngắn (`mo_ta_ngan` / `mo_ta`):** Lấy tối đa 300 ký tự đầu tiên, trọng số **1x**

#### B. Phân tích từ tố (Tokenization)
- Sử dụng hàm chuẩn hóa chuỗi và biểu thức chính quy Unicode (`preg_split('/\s+/u', ...)`), giữ lại các từ có độ dài từ 2 ký tự trở lên và không phải là số thuần túy.
- **LƯU Ý HỌC THUẬT QUAN TRỌNG:** **Bộ phân tích cú pháp thành phần Parser V2 (từ nghiên cứu Step 7C.1 / 7D) KHÔNG được tích hợp vào production.** Production hiện tại dùng bộ tách từ tự nhiên đa trường tiếng Việt.

#### C. Tính toán trọng số TF-IDF & Cắt tỉa (Pruning)
- **Tần suất từ trong tài liệu (Term Frequency - TF):**
  $$TF(t, d) = \frac{f_{t, d}}{\sum_{t' \in d} f_{t', d}}$$
- **Tần suất nghịch đảo làm mượt (Smooth IDF):** Áp dụng cho các từ có Document Frequency $df \ge 3$ và $df \le 0.80 N$:
  $$IDF(t) = \ln\left(\frac{1 + N}{1 + df(t)}\right) + 1$$
- **Cắt tỉa biểu diễn (Representation Truncation):** Mỗi sản phẩm chỉ lưu trữ **Top 30 từ khóa có điểm TF-IDF cao nhất**.
- **Chuẩn hóa vector:** Chuẩn hóa L2 về độ dài đơn vị:
  $$\|V\|_2 = \sqrt{\sum_{i=1}^{k} v_i^2}$$

#### D. Độ tương đồng Cosine (Cosine Similarity)
$$CosineSim(Q, D) = \frac{Q \cdot D}{\|Q\|_2 \times \|D\|_2}$$

---

### 3. Hợp Nhất Đa Tín Hiệu Hành Vi (Multi-Signal Behavior Fusion)

Hệ thống ghi nhận và kết hợp 4 tín hiệu hành vi thời gian thực.
**TUYÊN BỐ BẮT BUỘC:** Toàn bộ các trọng số dưới đây là **HEURISTIC KỸ THUẬT BAN ĐẦU (ENGINEERING BASELINE), TUYỆT ĐỐI KHÔNG PHẢI LÀ CÁC THAM SỐ TỐI ƯU TOÁN HỌC ĐƯỢC HỌC TỰ ĐỘNG (NOT LEARNED OPTIMUM)**:

| Tín Hiệu Hành Vi | Trọng Số Baseline | Nguồn Dữ Liệu | Cơ Chế Phân Rã & Giới Hạn (Decay & Scope) |
| :--- | :---: | :--- | :--- |
| **Giỏ hàng (`cart`)** | **0.35** | `$_SESSION['gio_hang']` | Trọng số theo số lượng SKU trong giỏ; SKU trong giỏ bị loại khỏi danh sách gợi ý |
| **Vừa xem (`view`)** | **0.35** | `$_SESSION['recent_viewed_products']` | Lấy tối đa 5 sản phẩm gần nhất; sản phẩm vừa xem bị loại khỏi danh sách gợi ý |
| **Tìm kiếm (`search`)** | **0.20** | `$_SESSION['search_history']` / DB | Lấy 3 truy vấn gần nhất với trọng số vị trí MRU: $w = [1.0, 0.6, 0.3]$ |
| **Đơn hàng (`purchase`)** | **0.10** | `hoa_don` (MongoDB) | Phân rã thời gian theo hàm mũ: $w(t) = e^{-\lambda \Delta t}$ với chu kỳ bán rã 60 ngày |

---

### 4. Bộ Điều Hướng Trạng Thái (Context Router) & 6 Chế Độ Hoạt Động

Dựa trên dữ liệu khả dụng tại thời điểm request, hệ thống định tuyến sang 1 trong 6 chế độ:
1. `SIMPLE`: Không có hành vi và không có hồ sơ da $\rightarrow$ Kích hoạt Simple Recommender Top-4.
2. `BEHAVIOR_CONTENT`: Có hành vi phiên (view/cart/search) nhưng chưa có hồ sơ da.
3. `PURCHASE_CONTENT`: Người dùng cũ đã có đơn hàng hợp lệ nhưng phiên hiện tại chưa có hành vi mới và chưa có hồ sơ da.
4. `PROFILE_CONTENT`: Người dùng đã hoàn thành khảo sát da nhưng phiên hiện tại chưa có hành vi tương tác.
5. `ADAPTIVE_HYBRID`: Người dùng có cả hồ sơ da và có hành vi phiên hiện tại.
6. `PARTIAL_PROFILE_FALLBACK`: Người dùng có thông tin hồ sơ nhưng không đủ để sinh vector đặc trưng $\rightarrow$ Fallback theo hành vi hoặc ngân sách.

---

### 5. Công Thức Gợi Ý Lai Thích Ứng (Adaptive Hybrid Scoring)

#### A. Kết hợp Vector Truy Vấn Lai ($U_{query}$)
Trong chế độ `ADAPTIVE_HYBRID`:
$$U_{query} = 0.50 \times U_{behavior} + 0.50 \times V_{profile}$$
Sau đó vector $U_{query}$ được chuẩn hóa L2.

#### B. Công thức Tái Xếp Hạng Cuối Cùng (Final Rerank)
- **Đối với chế độ `ADAPTIVE_HYBRID` và `PROFILE_CONTENT`:**
  $$FinalScore = 0.70 \times ContentSim + 0.20 \times SkinCompatibility + 0.10 \times BudgetScore$$
  Trong đó:
  - $SkinCompatibility$: Điểm tương thích loại da (1.0 nếu khớp tuyệt đối, 0.70 nếu sản phẩm dùng cho "Mọi loại da", 0.0 nếu xung đột).
  - $BudgetScore$: Điểm khớp ngân sách dựa trên khoảng cách giá so với ngân sách khai báo.
- **Đối với chế độ `BEHAVIOR_CONTENT` và `PURCHASE_CONTENT`:**
  $$FinalScore = 0.90 \times ContentSim + 0.10 \times PriceSimilarity$$
  Trong đó $PriceSimilarity$ so sánh giá sản phẩm ứng viên với giá tham chiếu trung bình của các sản phẩm người dùng vừa tương tác.

---

### 6. Bộ Lọc Đa Dạng Hóa Dòng Biến Thể (Product Family Diversity Filter)
- Để tránh hiện tượng danh sách gợi ý bị chiếm trọn bởi nhiều dung tích của cùng một dòng sản phẩm (ví dụ chai 100ml, 200ml, 400ml), hàm `extractProductFamily()` rút trích chuỗi tên cơ sở (Base Product Name).
- Thuật toán đảm bảo **mỗi dòng biến thể chỉ được xuất hiện tối đa 1 sản phẩm đại diện** trong Top-4 sản phẩm gợi ý cuối cùng.

---

### 7. Nhãn Giải Thích Minh Bạch (Grounded Explainability)
Nhãn giải thích (Reason Tags) được sinh ra dựa trên chính các tín hiệu thực tế đóng góp điểm số:
- Tín hiệu tìm kiếm hoạt động $\rightarrow$ *"Dựa trên tìm kiếm gần đây"*.
- Tín hiệu vừa xem hoạt động $\rightarrow$ *"Tương tự sản phẩm vừa xem"*.
- Tín hiệu giỏ hàng hoạt động $\rightarrow$ *"Phù hợp với giỏ hàng"*.
- Tín hiệu đơn hàng hoạt động $\rightarrow$ *"Tương thích lịch sử mua sắm"*.
- Điểm da liễu $\ge 1.0 \rightarrow$ *"Khớp loại da"*.
- Giá nằm trong ngân sách $\rightarrow$ *"Trong ngân sách"*.
- **Cam kết:** Tuyệt đối không đưa ra các lý do da liễu điều trị lâm sàng (medical claims) không có trong dữ liệu.
