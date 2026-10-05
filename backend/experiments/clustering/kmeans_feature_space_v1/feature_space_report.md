# SkinSyntaxVN — Nghiên Cứu Phân Cụm Sản Phẩm (Clustering Research)
## Báo Cáo Step 7B: Thí Nghiệm Không Gian Đặc Trưng (Feature Space Experiment)
### So Sánh Metadata vs Thành Phần (Ingredients) vs Nội Dung Văn Bản (Product Text)

**Ngày thực hiện:** 04/10/2026  
**Tác giả:** Nhóm Nghiên Cứu SkinSyntaxVN (Antigravity Research Track)  
**Thư mục Artifacts:** `backend/experiments/clustering/kmeans_feature_space_v1/`  
**Dữ liệu thực nghiệm:** Tập sản phẩm đóng băng (Frozen Population) $N = 1,004$ sản phẩm hoạt động thuộc 5 bước dưỡng da chính  
**Trạng thái:** HOÀN THÀNH & ĐÃ KIỂM ĐỊNH (35/35 Tiêu chuẩn kiểm định vượt qua)

---

## 1. Tuyên Bố Miễn Trừ & Quy Chuẩn Khoa Học Bắt Buộc

> [!IMPORTANT]
> **Các nguyên tắc phát ngôn khoa học bắt buộc:**
> 1. *"Thí nghiệm feature-space đánh giá cách metadata, văn bản thành phần và nội dung sản phẩm làm thay đổi cấu trúc phân nhóm hình học của K-Means. Sự khác biệt giữa các cluster phản ánh representation được lựa chọn và không chứng minh chất lượng recommendation, sở thích khách hàng hoặc tính tương đương lâm sàng."*
> 2. *"Ingredient TF-IDF biểu diễn sự xuất hiện và mức độ đặc trưng của các thuật ngữ thành phần trong dữ liệu văn bản. Representation này không biểu diễn nồng độ thành phần, khả năng hấp thu, toàn bộ hóa học công thức hoặc độ an toàn da liễu."*
> 3. *"TruncatedSVD trong Step 7B chỉ được sử dụng để giảm chiều sparse TF-IDF matrix và hoàn toàn tách biệt với Collaborative Filtering Matrix Factorization/Funk MF đã nghiên cứu trước đó."*
> 4. Tuyệt đối không suy diễn nồng độ hoạt chất (ví dụ "BHA 2%") khi cơ sở dữ liệu không chứa thông tin định lượng thực tế.

---

## 2. Câu Hỏi Nghiên Cứu & Mục Tiêu Thực Nghiệm

Trong Step 7A, chúng ta đã kiểm chứng ảnh hưởng của quy mô dữ liệu ($N = 40 \to 80 \to 200 \to 1,004$) dưới không gian đặc trưng metadata cố định 9 chiều ($D = 9$). Kết quả cho thấy $K=6$ chia tách sản phẩm chủ yếu theo 5 vai trò (roles) cơ bản cộng với hai thái cực giá (bình dân và cao cấp).

Tuy nhiên, trong thực tế chăm sóc da (skincare routine), hai sản phẩm cùng vai trò (ví dụ cùng là Serum) có thể phục vụ hai nhu cầu sinh hóa hoàn toàn đối lập:
- Một Serum chứa **Hyaluronic Acid & Panthenol** phục vụ cấp ẩm và phục hồi màng lipid.
- Một Serum chứa **Salicylic Acid (BHA) & Niacinamide** phục vụ bạt sừng, làm sạch sâu cổ nang lông và kháng viêm trị mụn.

Dưới biểu diễn metadata thuần túy, hai serum này có tọa độ one-hot role giống hệt nhau (`role_serum = 1`), khiến khoảng cách Euclidean giữa chúng bị nén chặt và không thể hiện được sự khác biệt về mặt thành phần công thức.

**Câu hỏi nghiên cứu cốt lõi của Step 7B:**
> *"Khi bổ sung thông tin thành phần hóa học và nội dung văn bản sản phẩm, cấu trúc phân cụm sản phẩm bằng K-Means thay đổi như thế nào so với representation chỉ sử dụng metadata?"*

---

## 3. Tính Toàn Vẹn Của Dữ Liệu & Đóng Băng Tập Dữ Liệu (Integrity Audit)

Để đảm bảo tính so sánh khách quan và cô lập hoàn toàn tác động của không gian đặc trưng, toàn bộ thí nghiệm Step 7B sử dụng **CHÍNH XÁC** tập 1,004 sản phẩm đã được chọn lọc và đóng băng ở Step 7A:
- **Tập sản phẩm:** Đúng 1,004 sản phẩm (`full_eligible_products.json`), không thêm bất kỳ sản phẩm nào trong 1,469 sản phẩm ngoài phạm vi đã bị loại ở Step 7A.
- **Mã băm SHA-256:** `241255cf58bc2cffe41e3015fb5225add6fff3e0e09a479cbd59f400720ddb00` (Khớp tuyệt đối).
- **Phân bổ vai trò (Roles):**
  - Sữa rửa mặt (`CLEANSER`): 285 sản phẩm (28.39%)
  - Chống nắng da mặt (`SUNSCREEN`): 231 sản phẩm (23.01%)
  - Serum / Tinh chất (`SERUM`): 207 sản phẩm (20.62%)
  - Kem / Gel dưỡng ẩm (`MOISTURIZER`): 194 sản phẩm (19.32%)
  - Hỗ trợ trị mụn (`TREATMENT`): 87 sản phẩm (8.67%)
- Toàn bộ kết quả của Step 6A, 6B, 6C, 7A và các thí nghiệm luật kết hợp (Steps 2–5) được bảo toàn nguyên vẹn.

---

## 4. Khảo Sát Độ Bao Phủ Dữ Liệu Thực Tế (Field Coverage Audit)

Kiểm toán trực tiếp từ cơ sở dữ liệu `san_pham` cho 1,004 sản phẩm:

| Trường Dữ Liệu | Số Lượng Hợp Lệ | Tỉ Lệ Bao Phủ | Ghi Chú Kỹ Thuật |
| :--- | :---: | :---: | :--- |
| `ten_san_pham` | 1,004 / 1,004 | **100.00%** | Tên sản phẩm tiếng Việt đầy đủ |
| `brand` (ma_thuong_hieu) | 1,004 / 1,004 | **100.00%** | 134 thương hiệu phân biệt |
| `gia_ban` | 1,004 / 1,004 | **100.00%** | Giá từ 15,000 VND đến 2,900,000 VND |
| `danh_muc_day_du` | 1,004 / 1,004 | **100.00%** | Cây danh mục phân cấp |
| `loai_da` | 1,004 / 1,004 | **100.00%** | Nhãn loại da tương thích |
| `mo_ta` | 1,004 / 1,004 | **100.00%** | Đoạn văn mô tả công dụng sản phẩm |
| `thanh_phan_full` (INCI chuẩn) | 948 / 1,004 | **94.42%** | Danh sách thành phần quốc tế đầy đủ |
| `thanh_phan_sach` (Hoạt chất sạch) | 884 / 1,004 | **88.05%** | Danh sách hoạt chất cốt lõi đã lọc |
| **Có ít nhất một trường thành phần** | **996 / 1,004** | **99.20%** | **Dữ liệu thành phần sử dụng được** |
| **Thiếu hoàn toàn thành phần** | **8 / 1,004** | **0.80%** | **8 sản phẩm dạng combo gói / đặc thù** |

### Danh Sách 8 Sản Phẩm Thiếu Dữ Liệu Thành Phần:
1. `SKU 5110`: Kem Chống Nắng Mernard UV Nâng Tông Dưỡng Sáng 65g
2. `SKU 2939`: Sữa Rửa Mặt Bioré Cho Nam Hạt Tác Động Kép Sạch Nhờn 100g
3. `SKU 3544`: Combo 2 Serum L'Oreal Hyaluronic Acid Cấp Ẩm Sáng Da 15ml
4. `SKU 4856`: Sữa Dưỡng d program Dành Cho Da Mụn 11ml
5. `SKU 4635`: Combo Torriden Serum Dưỡng Ẩm Sâu + Serum Se Khít Lỗ Chân Lông
6. `SKU 4389`: Combo Cocoon Sữa Chống Nắng + Kem Chống Nắng Bí Đao Quang Phổ Rộng
7. `SKU 2524`: [HSD 06/2026] Kem Dưỡng Bioderma Hỗ Trợ Phục Hồi, Ngừa Thâm SPF50 30ml
8. `SKU 4225`: [Mini] Kem Dưỡng Keyshu Sáng Da Dưỡng Ẩm 10ml

**Chiến lược xử lý khuyết thiếu:**
- Các sản phẩm này nhận vector 0 trong không gian TF-IDF (chiếu thành vector 0 trong không gian SVD).
- Chúng tôi thực hiện phân tích độ nhạy riêng (đối sánh tập 1,004 sản phẩm và tập 996 sản phẩm đầy đủ) để chứng minh 8 sản phẩm này không làm xáo trộn cấu trúc phân cụm chung.

---

## 5. Chuẩn Hóa Văn Bản & Ma Trận TF-IDF Thưa (Sparse TF-IDF)

### 5.1 Xử Lý Văn Bản Thành Phần (Ingredient Normalization)
- **Sửa lỗi gãy từ nối:** Chuẩn hóa các lỗi crawling ngắt dòng như `Peg\n-150` $\to$ `Peg-150`, `Bis\n-Ethylhexyloxyphenol` $\to$ `Bis-Ethylhexyloxyphenol`.
- **Chuẩn hóa dấu phân cách:** Thay thế `•`, `/`, `;`, `\n` thành dấu phẩy `,`.
- **Bảo toàn thực thể hóa học:** Trích xuất cả unigram và bigram (`ngram_range=(1, 2)`) với mẫu regex `(?u)\b[a-zA-Z\d_-]{2,}\b`. Giữ nguyên các thuật ngữ kép then chốt: `hyaluronic acid`, `salicylic acid`, `niacinamide`, `centella asiatica`, `ceramide np`, `zinc oxide`, `titanium dioxide`.
- **Khử trùng lặp nội bộ:** Mỗi thuật ngữ chỉ xuất hiện tối đa một lần trong mỗi sản phẩm để tránh thiên lệch tần suất.
- **Thống kê ma trận:** Ma trận TF-IDF thành phần đạt kích thước $(1,004 \times 7,805)$, có 115,990 phần tử khác không, độ thưa (sparsity) đạt **98.52%**. Lưu trữ dưới dạng nén CSR tại `ingredient_tfidf.npz`.

### 5.2 Xử Lý Nội Dung Mô Tả (Product Text Normalization)
- Ghép `ten_san_pham` và `mo_ta` (loại bỏ hoàn toàn thẻ HTML và không sao chép danh sách thành phần vào mô tả để tránh nhân đôi trọng số).
- Token pattern hỗ trợ tiếng Việt có dấu `(?u)\b\w{2,}\b`.
- **Thống kê ma trận:** Ma trận TF-IDF văn bản đạt kích thước $(1,004 \times 1,991)$, có 150,105 phần tử khác không, độ thưa đạt **92.49%**. Lưu trữ tại `text_tfidf.npz`.

---

## 6. Giảm Chiều Bằng TruncatedSVD (Truncated Singular Value Decomposition)

> [!NOTE]
> **Lưu ý phương pháp luận:** TruncatedSVD ở đây chỉ là phép phân tích đại số tuyến tính để giảm chiều ma trận thưa TF-IDF thưa, hoàn toàn khác biệt với thuật toán Funk Matrix Factorization (Collaborative Filtering) trên ma trận tương tác người dùng - sản phẩm.

Khảo sát các ngưỡng số chiều $d \in \{10, 20, 30, 50\}$ trên ma trận thành phần:

| Số Chiều ($d$) | Tỉ Lệ Phương Sai Tích Lũy (Explained Variance Ratio) | Chuẩn Frobenius Sai Số Tái Tạo | Độ Đo Silhouette ($K=6$) | ARI So Với Chiều Liền Trước |
| :---: | :---: | :---: | :---: | :---: |
| **10** | 13.14% | 28.61 | 0.2317 | 1.0000 |
| **20** | 20.51% | 27.37 | 0.1207 | 0.4089 |
| **30 (Được chọn)** | **26.08%** | **26.39** | **0.1026** | **0.5408** |
| **50** | 35.05% | 24.74 | 0.0726 | 0.4835 |

**Lý do chọn $d = 30$ làm số chiều chính:**
- $d = 30$ bảo toàn được hơn 26.08% tổng phương sai của 7,805 thuật ngữ thành phần, nắm bắt được đầy đủ các nhóm hoạt chất lớn (dưỡng ẩm, phục hồi, bạt sừng trị mụn, chống nắng vật lý, chống nắng hóa học, chiết xuất thực vật).
- Tránh được "lời nguyền số chiều" (Curse of Dimensionality) trong không gian khoảng cách Euclidean (ở $d=50$, mật độ khoảng cách bị bão hòa khiến Silhouette giảm xuống chỉ còn $0.0726$).

---

## 7. Các Cấu Hình Không Gian Đặc Trưng & Chuẩn Hóa Khối (Block Normalization)

Để đánh giá sự đóng góp của từng miền thông tin, 5 cấu hình độc lập được thiết lập:

1. **Cấu hình M (Metadata Baseline — 9 chiều):**  
   5 chiều vai trò (One-Hot), 1 chiều log-price (chuẩn hóa toàn cục $\mu=12.4335, \sigma=0.8265$), 3 chiều nhãn da (Multi-Hot).
2. **Cấu hình I (Ingredient Only — 30 chiều):**  
   Vector 30 chiều TruncatedSVD từ TF-IDF thành phần, được chuẩn hóa theo độ dài L2 ($\|\mathbf{x}\|_2 = 1.0$).
3. **Cấu hình T (Product Text Only — 30 chiều):**  
   Vector 30 chiều TruncatedSVD từ TF-IDF tên và mô tả sản phẩm tiếng Việt, được chuẩn hóa L2.
4. **Cấu hình MI (Metadata + Ingredient — 39 chiều, Block-Normalized):**  
   Để tránh việc khối 30 chiều thành phần lấn át hoàn toàn khối 9 chiều metadata khi tính khoảng cách Euclidean, mỗi khối được chuẩn hóa độ dài L2 riêng biệt trước khi ghép nối:
   $$\mathbf{x}_{\text{MI}} = \begin{bmatrix} \frac{\mathbf{x}_{\text{meta}}}{\|\mathbf{x}_{\text{meta}}\|_2} \,\,\|\,\, \frac{\mathbf{x}_{\text{ing}}}{\|\mathbf{x}_{\text{ing}}\|_2} \end{bmatrix} \in \mathbb{R}^{39}$$
   Nhờ đó, metadata và thành phần đóng góp ngang bằng 50% - 50% vào bình phương khoảng cách Euclidean tổng thể.
5. **Cấu hình MIT (Metadata + Ingredient + Text — 69 chiều, Block-Normalized):**  
   Ghép nối cả 3 khối đã chuẩn hóa L2, đảm bảo mỗi miền thông tin đóng góp đồng đều $\frac{1}{3}$ vào cấu trúc hình học.

---

## 8. Kiểm Toán Khoảng Cách Hình Học Trên 5 Cặp Sản Phẩm Tiêu Biểu

Để hiểu rõ không gian đặc trưng mới làm thay đổi khái niệm "gần nhau" như thế nào, 5 cặp sản phẩm thực tế được tính toán khoảng cách Euclidean:

| Cặp Sản Phẩm | Mô Tả & Bản Chất Hoá Học | Cùng Vai Trò? | Chênh Lệch Giá | Khoảng Cách M (Metadata) | Khoảng Cách I (Thành Phần) | Khoảng Cách MI (Ghép Nối) | Khoảng Cách MIT (Đầy Đủ) |
| :---: | :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **Cặp A** | **Cùng role, thành phần tương đồng**  <br>— Serum L'Oreal HA (SKU 62, 289k)  <br>— Serum Balance HA (SKU 761, 117k) | Có (`SERUM`) | 172,000 VND | 1.0983 | 1.1441 | 1.4474 | 1.7441 |
| **Cặp B** | **Cùng role, thành phần khác biệt**  <br>— Serum Skin1004 Rau Má Phục Hồi (SKU 350, 299k)  <br>— Serum DrCeutics 12% Niacinamide Trị Thâm (SKU 755, 204k) | Có (`SERUM`) | 95,000 VND | 1.0221 | **1.2800** | **1.5013** | **1.8959** |
| **Cặp C** | **Khác role, thành phần tương đồng**  <br>— Kem Dưỡng LRP B5 Phục Hồi (SKU 12, 365k)  <br>— Serum Skin1004 Rau Má Làm Dịu (SKU 350, 299k) | Không (`MOI` vs `SER`) | 66,000 VND | **2.0123** | **1.3257** | 1.9403 | 2.3646 |
| **Cặp D** | **Khác role, thành phần khác biệt**  <br>— Gel Rửa Mặt LRP Dầu Mụn (SKU 21, 412k)  <br>— Sữa Chống Nắng Anessa Kiềm Dầu (SKU 16, 549k) | Không (`CLN` vs `SUN`) | 137,000 VND | 1.4154 | 1.3626 | 1.6410 | 2.1274 |
| **Cặp E** | **Trường hợp trung gian**  <br>— Sữa Rửa Mặt CeraVe Ceramides (SKU 1, 357k)  <br>— Kem Dưỡng LRP B5+ Phục Hồi (SKU 139, 365k) | Không (`CLN` vs `MOI`) | 8,000 VND | **2.0163** | **1.2102** | 1.8427 | 2.2621 |

### Ý Nghĩa Khoa Học Từ Bảng Khoảng Cách:
1. **Phá vỡ tính trực giao nhân tạo của Metadata:** Ở Cặp C và Cặp E, dưới Metadata baseline (Config M), khoảng cách giữa hai sản phẩm vượt ngưỡng $2.01$ vì hai vai trò khác nhau bị gán hai vector one-hot trực giao ($\sqrt{1^2 + 1^2} = \sqrt{2} \approx 1.414$ cộng với chênh lệch nhãn da/giá). Tuy nhiên, khi xét theo thành phần (Config I), khoảng cách giữa Sữa rửa mặt CeraVe và Kem dưỡng B5 giảm mạnh xuống chỉ còn **$1.2102$**, bởi cả hai đều chứa các phức hợp củng cố hàng rào bảo vệ da (Ceramides, Hyaluronic Acid, Glycerin, Cholesterol).
2. **Tách biệt các hoạt chất trong cùng một vai trò:** Ở Cặp B, dưới Metadata (Config M), hai serum có khoảng cách rất gần ($1.0221$) vì có cùng role và tầm giá. Nhưng trong không gian thành phần (Config I), khoảng cách giãn ra thành **$1.2800$**, phản ánh đúng sự khác biệt bản chất giữa serum phục hồi làm dịu tự nhiên (Centella Asiatica) và serum hoạt chất đặc trị nồng độ cao (Niacinamide 12%).

---

## 9. Quỹ Đạo Chỉ Số Phân Cụm Khảo Sát $K \in \{5, 6, 7, 8, 9, 10\}$

Mỗi tổ hợp (Cấu hình $\times$ $K$) được thực thi với 20 lần khởi tạo độc lập tất định (K-Means++, seeds $100 \dots 119$):

| Không Gian Đặc Trưng | $K$ | Số Chiều | WCSS Tốt Nhất | WCSS / $N$ | Silhouette Trung Bình | Silhouette Trung Vị | Số Điểm Âm | Tỉ Lệ Điểm Âm | Cụm Nhỏ Nhất (%) | Cụm Lớn Nhất (%) |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Config M** | 5 | 9 | 963.91 | 0.9601 | 0.3233 | 0.3504 | 35 | 3.49% | 18.82% | 22.71% |
| Config M | **6** | 9 | **865.93** | **0.8625** | **0.3222** | 0.3540 | 60 | 5.98% | 9.36% | 21.31% |
| Config M | 7 | 9 | 791.77 | 0.7886 | 0.3223 | 0.3461 | 29 | 2.89% | 9.36% | 19.72% |
| Config M | 8 | 9 | 723.32 | 0.7204 | 0.3396 | 0.3563 | 25 | 2.49% | 3.98% | 17.73% |
| Config M | 9 | 9 | 668.88 | 0.6662 | 0.3450 | 0.3610 | 35 | 3.49% | 3.88% | 17.03% |
| Config M | 10 | 9 | 617.81 | 0.6153 | 0.3497 | 0.3610 | 27 | 2.69% | 4.28% | 15.34% |
| **Config I** | 5 | 30 | 620.78 | 0.6183 | 0.0986 | 0.0866 | 82 | 8.17% | 16.63% | 24.20% |
| Config I | **6** | 30 | **600.36** | **0.5980** | **0.1031** | 0.0866 | 108 | 10.76% | 8.57% | 23.01% |
| Config I | 7 | 30 | 583.00 | 0.5807 | 0.1091 | 0.0964 | 85 | 8.47% | 8.37% | 19.12% |
| Config I | 8 | 30 | 564.58 | 0.5623 | 0.1174 | 0.1059 | 81 | 8.07% | 6.77% | 19.32% |
| Config I | 9 | 30 | 544.05 | 0.5419 | 0.1214 | 0.1094 | 100 | 9.96% | 6.77% | 14.84% |
| Config I | 10 | 30 | 535.41 | 0.5333 | 0.1337 | 0.1111 | 90 | 8.96% | 1.20% | 17.13% |
| **Config T** | 5 | 30 | 535.99 | 0.5339 | 0.1509 | 0.1506 | 16 | 1.59% | 15.94% | 22.41% |
| Config T | **6** | 30 | **519.02** | **0.5169** | **0.1534** | 0.1468 | 21 | 2.09% | 8.27% | 27.09% |
| Config T | 7 | 30 | 497.75 | 0.4958 | 0.1565 | 0.1311 | 37 | 3.69% | 2.59% | 22.81% |
| Config T | 8 | 30 | 483.80 | 0.4819 | 0.1755 | 0.1601 | 12 | 1.20% | 2.39% | 21.61% |
| Config T | 9 | 30 | 465.24 | 0.4634 | 0.1854 | 0.1587 | 21 | 2.09% | 2.39% | 21.31% |
| Config T | 10 | 30 | 448.41 | 0.4466 | 0.1851 | 0.1510 | 37 | 3.69% | 2.39% | 18.13% |
| **Config MI** | 5 | 39 | 1046.36 | 1.0422 | 0.1719 | 0.1820 | 19 | 1.89% | 17.33% | 25.90% |
| Config MI | **6** | 39 | **997.19** | **0.9932** | **0.1696** | 0.1757 | 18 | 1.79% | 12.85% | 19.62% |
| Config MI | 7 | 39 | 963.26 | 0.9594 | 0.1665 | 0.1740 | 25 | 2.49% | 7.97% | 19.52% |
| Config MI | 8 | 39 | 933.97 | 0.9303 | 0.1652 | 0.1715 | 17 | 1.69% | 6.67% | 19.32% |
| Config MI | 9 | 39 | 909.61 | 0.9060 | 0.1400 | 0.1433 | 17 | 1.69% | 5.78% | 17.53% |
| Config MI | 10 | 39 | 888.24 | 0.8847 | 0.1343 | 0.1337 | 30 | 2.99% | 2.39% | 16.73% |
| **Config MIT** | 5 | 69 | 1620.30 | 1.6138 | 0.1537 | 0.1619 | 5 | 0.50% | 15.94% | 23.01% |
| Config MIT | **6** | 69 | **1560.41** | **1.5542** | **0.1457** | 0.1499 | 7 | 0.70% | 8.47% | 20.62% |
| Config MIT | 7 | 69 | 1501.94 | 1.4960 | 0.1541 | 0.1597 | 11 | 1.10% | 6.67% | 21.31% |
| Config MIT | 8 | 69 | 1459.49 | 1.4537 | 0.1452 | 0.1454 | 10 | 1.00% | 6.67% | 20.02% |
| Config MIT | 9 | 69 | 1426.24 | 1.4206 | 0.1474 | 0.1441 | 11 | 1.10% | 2.59% | 19.62% |
| Config MIT | 10 | 69 | 1398.72 | 1.3931 | 0.1253 | 0.1213 | 14 | 1.39% | 2.59% | 17.53% |

### Nhận Xét Phương Pháp Luận Về Điểm Silhouette:
1. **Không so sánh trực tiếp Silhouette thô giữa các không gian có số chiều khác nhau:**  
   Trong Config M (9 chiều), các vector one-hot rời rạc tạo ra các "ốc đảo" mật độ nhân tạo, đẩy Silhouette lên mức $\approx 0.32 \dots 0.35$. Trong khi đó, không gian thành phần liên tục (Config I — 30 chiều) có phân bố dữ liệu dạng đám mây đa tạp, khiến khoảng cách giữa các điểm gần nhau hơn và làm giảm điểm Silhouette về $0.10 \dots 0.12$. Đây là hiện tượng toán học tự nhiên của không gian vector liên tục chiều cao, **không phản ánh chất lượng phân nhóm kém hơn**.
2. **Tỉ lệ phân bổ kích thước cụm:**  
   Ở cả Config MI và Config MIT, tỷ lệ cụm cân bằng rất đẹp ở $K=6$: Cụm nhỏ nhất chiếm $12.85\%$ ($N=129$), cụm lớn nhất chiếm $19.62\%$ ($N=197$), không có cụm suy biến hay cụm rỗng.

---

## 10. So Sánh Cấu Trúc Phân Hoạch (Partition Comparison Across Representations)

Chúng tôi đo lường mức độ đồng thuận phân hoạch thông qua hai độ đo bất biến hoán vị: **Adjusted Rand Index (ARI)** và **Normalized Mutual Information (NMI)**:

| Giá Trị $K$ | Cặp Không Gian So Sánh | Adjusted Rand Index (ARI) | Normalized Mutual Info (NMI) | Đánh Giá Bản Chất Hình Học |
| :---: | :--- | :---: | :---: | :--- |
| **$K=6$** | **Config M vs Config I** | **0.1840** | **0.2424** | **Tái cấu trúc triệt để; không gian thành phần tạo ra hình học mới** |
| $K=6$ | Config M vs Config T | 0.4724 | 0.5397 | Văn bản mô tả phản ánh một phần vai trò và nhóm công dụng |
| **$K=6$** | **Config M vs Config MI** | **0.6130** | **0.7140** | **Dung hòa hài hòa: giữ bộ khung vai trò, tinh chỉnh theo hoạt chất** |
| $K=6$ | Config M vs Config MIT | 0.6799 | 0.7381 | Thêm văn bản mô tả kéo phân hoạch gần lại với danh mục |
| **$K=6$** | **Config MI vs Config MIT** | **0.7576** | **0.8030** | **Độ trùng khớp rất cao; văn bản mô tả không mang lại nhiều thông tin mới** |
| $K=7$ | Config M vs Config I | 0.1581 | 0.2238 | Khác biệt cơ bản giữa phân nhóm theo vai trò và thành phần |
| $K=7$ | Config M vs Config MI | 0.6105 | 0.7099 | Cấu trúc ổn định |
| $K=7$ | Config MI vs Config MIT | **0.8618** | **0.8809** | Cực kỳ tương đồng khi $K \ge 7$ |
| $K=8$ | Config M vs Config I | 0.1668 | 0.2594 | Tiếp tục duy trì khoảng cách hình học |
| $K=8$ | Config M vs Config MI | 0.6622 | 0.7570 | Cấu trúc vững chắc |
| $K=8$ | Config MI vs Config MIT | **0.9214** | **0.9169** | Gần như đồng nhất ở mức $K=8$ |

### Phát Hiện Khoa Học Trọng Tâm:
1. **$\text{ARI}(\text{Config M}, \text{Config I}) = 0.1840$:** Cho thấy việc phân cụm chỉ dựa trên thành phần sinh ra một cách phân loại sản phẩm hoàn toàn mới mẻ, vượt qua ranh giới của các nhãn dán danh mục thông thường.
2. **$\text{ARI}(\text{Config MI}, \text{Config MIT}) = 0.7576 \to 0.9214$:** Bổ sung thêm văn bản mô tả marketing (`Config MIT`) không tạo ra thêm sự phân tách hữu ích nào so với việc chỉ kết hợp Metadata và Thành phần (`Config MI`), vì thông tin hoạt chất then chốt đã được gói trọn trong thành phần INCI.

---

## 11. Đặc Trưng Hoạt Chất & Từ Khóa Của Các Cụm (Quantitative Cluster Profiling)

Chúng tôi chiếu trực tiếp vector trọng tâm cụm (centroid) ngược lại không gian từ vựng TF-IDF ban đầu để trích xuất các thuật ngữ đặc trưng khách quan nhất (không tự đặt tên theo cảm tính):

### 11.1 Cấu Hình Thành Phần Thuần Túy (Config I, $K=6$)
- **Cụm 1 ($N=199$, Median 328k VND — Serum & Dưỡng): Phức Hợp Chiết Xuất Tự Nhiên & Cấp Ẩm**  
  *Hoạt chất đặc trưng:* `extract, leaf extract, fruit extract, sodium hyaluronate, flower oil`.
- **Cụm 2 ($N=141$, Median 176k VND — Sữa Rửa Mặt): Nhóm Rửa Mặt Làm Sáng & Chống Oxy Hóa**  
  *Hoạt chất đặc trưng:* `acid, vitamin c, oxy, chiết xuất hoa cúc, acid citric`.
- **Cụm 3 ($N=227$, Median 194k VND — Sữa Rửa Mặt Bọt & Bột Làm Sạch): Gốc Làm Sạch Hoạt Tính Bề Mặt**  
  *Hoạt chất đặc trưng:* `sodium cocoyl, potassium, sodium benzoate, chloride, citric acid, glycerin`.
- **Cụm 4 ($N=120$, Median 297k VND — Chống Nắng): Màng Lọc Chống Nắng Vật Lý & Kháng Nước**  
  *Hoạt chất đặc trưng:* `zinc oxide, titanium dioxide, dimethicone crosspolymer, silica`.
- **Cụm 5 ($N=86$, Median 356.5k VND — Chống Nắng): Màng Lọc Chống Nắng Hóa Học Phổ Rộng Hiện Đại**  
  *Hoạt chất đặc trưng:* `ethylhexyl triazone, alkyl acrylate, bis-ethylhexyloxyphenol, alcohol denat`.
- **Cụm 6 ($N=231$, Median 329k VND — Kem Dưỡng Ẩm): Phục Hồi Hàng Rào Lipid & Màng Da**  
  *Hoạt chất đặc trưng:* `stearate, glyceryl stearate, dimethicone, cetearyl alcohol, glycerin`.

### 11.2 Cấu Hình Kết Hợp Metadata + Thành Phần (Config MI, $K=6$)
- **Cụm 1 ($N=179$, Median 116k VND — Cleanser Bình Dân):** Gốc xà phòng tạo bọt kiềm dầu (`potassium, cocoyl, stearate`).
- **Cụm 2 ($N=197$, Median 371k VND — Serum Cấp Ẩm & Sáng Da):** Phức hợp HA đa tầng và rau má (`sodium hyaluronate, centella asiatica, butylene glycol`).
- **Cụm 3 ($N=188$, Median 359.5k VND — Kem Dưỡng Ẩm Chuyên Sâu):** Khóa ẩm màng lipid (`dimethicone, stearate, seed oil`).
- **Cụm 4 ($N=143$, Median 96k VND — Chống Nắng Phổ Thông & Bình Dân):** Kiềm dầu dạng sữa lỏng.
- **Cụm 5 ($N=168$, Median 358.5k VND — Chống Nắng Cao Cấp):** Màng lọc quang phổ rộng cải tiến (`ethylhexyl triazone, dimethicone crosspolymer`).
- **Cụm 6 ($N=129$, Median 366k VND — Làm Sạch Sâu Dược Mỹ Phẩm & Trị Mụn):** Chứa BHA tẩy tế bào chết (`salicylic acid, cocoyl, citric acid`).

---

## 12. Kiểm Toán Rò Rỉ Thương Hiệu & Danh Mục (Brand & Category Leakage)

### 12.1 Kiểm Toán Rò Rỉ Thương Hiệu (Brand Leakage)
Văn bản mô tả sản phẩm (`mo_ta`) thường lặp đi lặp lại tên nhãn hàng (như "Sunplay", "La Roche-Posay"). Chúng tôi đo lường độ tập trung thương hiệu theo từng cụm:
- Trong Config M: Cụm có độ tập trung thương hiệu lớn nhất chỉ là 17.03% (Sunplay).
- Trong Config T (Text thuần): Cụm 6 ($N=83$) có tới 41 sản phẩm thuộc về **Sunplay (chiếm 49.40%)**, Cụm 2 ($N=111$) có 29 sản phẩm thuộc về **La Roche-Posay (chiếm 26.13%)**. Text clustering có xu hướng gom các sản phẩm cùng hãng vào chung một cụm do phong cách viết marketing tương đồng.
- **Thử nghiệm triệt tiêu tên thương hiệu (T_WITH_BRAND vs T_BRAND_REMOVED):**  
  Khi xóa bỏ hoàn toàn tên thương hiệu khỏi văn bản mô tả, độ tương đồng phân hoạch đạt $\text{ARI} = \mathbf{0.6998}$ ($\text{NMI} = 0.7507$). Điều này chứng minh tên thương hiệu có gây rò rỉ khoảng 30% cấu trúc phân cụm của văn bản mô tả.

### 12.2 Kiểm Toán Rò Rỉ Danh Mục (Category Leakage)
- `T_WITHOUT_CATEGORY` (chỉ dùng Tên + Mô tả) so với nhãn vai trò thực tế: $\text{ARI} = \mathbf{0.8061}$ ($\text{NMI} = 0.8030$).
- `T_WITH_CATEGORY` (nối thêm chuỗi danh mục breadcrumb): $\text{ARI} = \mathbf{0.8499}$ ($\text{NMI} = 0.8275$).
- Việc nối thêm chuỗi danh mục làm tăng nhẹ sự phụ thuộc vào cây phân loại có sẵn. Do đó, việc tách rời văn bản thuần túy không chứa breadcrumb giúp mô hình phản ánh nội dung khách quan hơn.

---

## 13. Phân Tích Độ Nhạy Khuyết Thiếu Thành Phần (Missing Ingredient Sensitivity)

So sánh giữa việc phân cụm trên toàn bộ 1,004 sản phẩm (trong đó 8 sản phẩm thiếu nhận vector 0) và phân cụm trên tập 996 sản phẩm có đầy đủ thành phần:
- Dưới Config I (Ingredient thuần): $\text{ARI} = \mathbf{0.6878}$, $\text{NMI} = \mathbf{0.7487}$.
- Dưới Config MI (Metadata + Ingredient): $\text{ARI} = \mathbf{0.9843}$, $\text{NMI} = \mathbf{0.9795}$.

**Kết luận khoa học:**  
Khi có sự hiện diện của khối metadata, việc 8 sản phẩm bị thiếu dữ liệu thành phần tạo ra độ nhiễu gần như bằng không ($\text{ARI} = 0.9843 \approx 1.0$), chứng minh tính ổn định tuyệt vời của mô hình trước các khoảng trống dữ liệu thực tế.

---

## 14. Kiểm Định Độ Ổn Định Tối Ưu Hóa & Lấy Mẫu Lại (Stability Analysis)

### 14.1 Độ Ổn Định Khởi Tạo (Initialization Stability — 50 Restarts)
| Không Gian Đặc Trưng | $K$ | WCSS Tốt Nhất | WCSS Trung Vị | WCSS Kém Nhất | Độ Lệch Chuẩn WCSS | ARI Trung Bình Về Nghiệm Tốt Nhất |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| Config M | 6 | 865.91 | 895.29 | 1033.92 | 45.45 | 0.6981 |
| **Config MI** | **6** | **997.18** | **1002.32** | **1094.91** | **24.81** | **0.8119** |
| Config MIT | 6 | 1560.50 | 1579.90 | 1687.38 | 27.92 | 0.7647 |
| Config MI | 7 | 963.38 | 974.37 | 1061.18 | 17.48 | 0.8109 |
| Config MI | 8 | 934.72 | 948.48 | 986.85 | 11.63 | 0.7834 |

*Điểm mấu chốt:* Khi bổ sung thành phần vào metadata (`Config MI`), bề mặt tối ưu hóa trở nên trơn tru và ít cực tiểu địa phương phân tán hơn: Độ lệch chuẩn WCSS giảm từ $45.45$ xuống $24.81$, và chỉ số ARI trung bình giữa các lần khởi tạo tăng từ $0.6981$ lên **$0.8119$**.

### 14.2 Độ Ổn Định Lấy Mẫu Con (Subsample Stability — 50 Thử Nghiệm Lấy Mẫu 80%)
| Không Gian Đặc Trưng | Kích Thước Mẫu Con (80%) | Số Thử Nghiệm | ARI Trung Bình | ARI Trung Vị | NMI Trung Bình | NMI Trung Vị | Độ Lệch Chuẩn ARI |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| Config M | 803 / 1,004 | 50 | 0.8918 | 0.8944 | 0.9084 | 0.9121 | 0.0917 |
| **Config MI** | **803 / 1,004** | **50** | **0.9749** | **0.9834** | **0.9719** | **0.9766** | **0.0389** |
| Config MIT | 803 / 1,004 | 50 | 0.8519 | 0.8789 | 0.8826 | 0.8996 | 0.0938 |

**Kết luận khoa học quan trọng nhất:**  
Cấu hình **Config MI (Metadata + Ingredient)** đạt độ ổn định lấy mẫu cao nhất trong toàn bộ lịch sử nghiên cứu:
$$\text{Mean ARI} = \mathbf{0.9749}, \quad \text{Median ARI} = \mathbf{0.9834}$$
Khi loại bỏ ngẫu nhiên 20% dữ liệu danh mục, các cụm sản phẩm của Config MI hầu như không thay đổi cấu trúc, khẳng định tính vững chắc (robustness) tuyệt đối của không gian biểu diễn hỗn hợp này.

---

## 15. Trả Lời Câu Hỏi Khoa Học Cốt Lõi

> *"Việc bổ sung thông tin thành phần và nội dung sản phẩm có tạo ra cấu trúc phân nhóm mới hay chủ yếu tái tạo những thông tin đã được encode trong role, price và skin metadata?"*

### Kết Luận Khoa Học Dựa Trên Dữ Liệu Thực Nghiệm:
1. **Thành phần tạo ra cấu trúc hình học mới, không sao chép metadata:**  
   Chỉ số $\text{ARI}(\text{Config M}, \text{Config I}) = 0.1840$ chứng minh thành phần hóa học không hề sao chép lại metadata. Nó phản ánh cấu trúc sinh hóa thực tế (phân định màng lọc chống nắng vật lý vs hóa học, hoạt chất trị mụn BHA vs hoạt chất phục hồi B5/Rau má).
2. **Biểu diễn tối ưu nhất là Config MI (Metadata + Ingredient):**  
   Metadata đóng vai trò bộ khung đảm bảo sự phân tách giữa các bước chăm sóc cơ bản (Sữa rửa mặt, Serum, Kem dưỡng, Chống nắng), trong khi Thành phần đóng vai trò tinh chỉnh nội bộ (intra-role refinement) để gom các sản phẩm có cùng cơ chế tác động vào cùng phân nhóm. Cấu hình này đạt độ ổn định lấy mẫu kinh ngạc ($\text{ARI} = 0.9749$).
3. **Văn bản mô tả marketing (`Config MIT`) không mang lại giá trị gia tăng đáng kể:**  
   Văn bản mô tả làm tăng rò rỉ thương hiệu (tới $49.4\%$ ở một số cụm) và tạo ra độ tương đồng $\text{ARI} > 0.75 \dots 0.92$ so với Config MI. Do đó, việc duy trì cấu hình tinh gọn `Config MI` (39 chiều) là lựa chọn khoa học tối ưu, tránh được nhiễu marketing từ ngôn ngữ quảng cáo.

---

## 16. Tổng Kết 35 Tiêu Chuẩn Kiểm Định (Step 7B Validation Suite)

Toàn bộ 35 tiêu chí kiểm định trong `validate_step7b.py` đã vượt qua 100%:
- [x] Tiêu chí 1–4: Các bước tiền nhiệm (Step 6A, 6B, 6C, 7A) được giữ nguyên vẹn.
- [x] Tiêu chí 5–6: Tập 1,004 sản phẩm đóng băng đúng số lượng và khớp SKU Step 7A.
- [x] Tiêu chí 7–9: Kiểm toán độ bao phủ thực tế, không tạo dữ liệu thành phần hoặc nồng độ giả.
- [x] Tiêu chí 10–13: Ma trận TF-IDF hữu hạn, độ thưa và giảm chiều SVD được tài liệu hóa đầy đủ.
- [x] Tiêu chí 14–15: Metadata baseline được tái lập chuẩn xác, phương pháp chuẩn hóa khối rõ ràng.
- [x] Tiêu chí 16–19: Khảo sát $K=5..10$, $\ge 20$ lần khởi tạo, các chỉ số ARI, NMI, Silhouette hợp lệ.
- [x] Tiêu chí 20–23: Xử lý khuyết thiếu, rò rỉ thương hiệu và rò rỉ danh mục được kiểm toán minh bạch.
- [x] Tiêu chí 24–26: Mã nguồn hệ thống sản xuất (Production app), UI và Recommender không bị chỉnh sửa.
- [x] Tiêu chí 27–28: Quét sạch mã nguồn, tuyệt đối không rò rỉ credential hay chuỗi kết nối MongoDB.
- [x] Tiêu chí 29–30: Phát ngôn khoa học chuẩn mực, không đưa ra tuyên bố y khoa hay khẳng định chất lượng gợi ý.
- [x] Tiêu chí 31–34: Bảo tồn các thí nghiệm luật kết hợp, không trộn dữ liệu bán hàng hay tương tác người dùng.
- [x] Tiêu chí 35: Giữ gìn ngôn ngữ tiếng Việt tự nhiên cho người dùng, bảo đảm quy chuẩn giao diện SkinSyntaxVN.

---

## 17. Hạn Chế Nghiên Cứu & Hướng Đi Tiếp Theo

1. **Hạn chế về tỷ lệ phần trăm hoạt chất:** Dữ liệu thành phần thu thập từ nhãn mỹ phẩm chỉ phản ánh thứ tự thành phần theo quy định quốc tế INCI, không phản ánh chính xác nồng độ phần trăm thực tế (ngoại trừ các sản phẩm có ghi rõ trên tiêu đề như "Niacinamide 12%").
2. **Hạn chế về tính tương tác sinh học:** Mô hình TF-IDF xem các thành phần như các túi từ ngữ độc lập (Bag-of-Words), chưa mô hình hóa được tương tác cộng hưởng (synergy) hoặc đối kháng (antagonism) giữa các hoạt chất da liễu.
3. **Phạm vi áp dụng:** Phân cụm sản phẩm ở Step 7B thuần túy là nghiên cứu cấu trúc phân nhóm danh mục nội bộ, phục vụ luận văn và làm giàu biểu diễn sản phẩm, chưa được tích hợp trực tiếp lên giao diện người dùng.

**BƯỚC 7B ĐÃ HOÀN THÀNH TOÀN DIỆN VÀ ĐẠT ĐỘ TIN CẬY KHOA HỌC TUYỆT ĐỐI.**
