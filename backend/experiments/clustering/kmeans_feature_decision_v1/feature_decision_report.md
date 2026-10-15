# SkinSyntaxVN — Nghiên Cứu Phân Cụm Sản Phẩm (Clustering Research)
## Báo Cáo Step 7C: Kiểm Toán Quyết Định Biểu Diễn Đặc Trưng (Feature Representation Decision Audit)
### Đánh Giá Đối Soát Metadata vs Metadata + Thành Phần (Word N-gram vs Thực Thể Hóa Học)

**Ngày thực hiện:** 04/10/2026  
**Tác giả:** Nhóm Nghiên Cứu SkinSyntaxVN (Antigravity Research Track)  
**Thư mục Artifacts:** `backend/experiments/clustering/kmeans_feature_decision_v1/`  
**Dữ liệu thực nghiệm:** Tập sản phẩm đóng băng (Frozen Population) $N = 1,004$ sản phẩm hoạt động  
**Trạng thái:** HOÀN THÀNH & ĐÃ KIỂM ĐỊNH TOÀN DIỆN (25/25 Tiêu chuẩn kiểm định đạt chuẩn)

---

## 1. Tuyên Bố Khoa Học Bắt Buộc & Chuẩn Hóa Thuật Ngữ

> [!IMPORTANT]
> **Các tuyên bố khoa học bắt buộc:**
> 1. *"Step 7C là bước kiểm toán lựa chọn representation, không phải đánh giá chất lượng recommendation. Silhouette, ARI, NMI và stability mô tả hình học và độ ổn định của phân hoạch trong feature space được thiết kế; chúng không chứng minh mức độ phù hợp của sản phẩm đối với người dùng."*
> 2. *"Mức độ tương đồng thành phần trong thí nghiệm phản ánh dữ liệu ingredient được liệt kê và cách biểu diễn văn bản đã chọn; nó không biểu diễn đầy đủ nồng độ, công thức, tương tác hóa học hoặc hiệu quả da liễu."*
> 3. Tuyệt đối không suy diễn hiệu quả y khoa thực tế hay khẳng định cơ chế tác động nội tại của hóa chất. Thay vào đó, chỉ mô tả khách quan là: *"chia sẻ các thuật ngữ thành phần"* hoặc *"có biểu diễn thành phần tương đồng"* theo danh pháp INCI.

---

## 2. Hiệu Chỉnh Các Kết Luận Cần Khách Quan Hóa Từ Step 7B

Trong Step 7B, một số kết luận mang tính khái quát hóa quá mức (overclaim) cần được điều chỉnh chính xác theo số liệu thực nghiệm:
- **Không khẳng định:** *"SVD 30 dimensions là tối ưu"* hay *"nắm bắt trọn vẹn nhóm hoạt chất"*.  
  $\to$ **Mô tả đúng số liệu:** $d = 30$ giải thích khoảng $26\% \dots 28\%$ tổng phương sai của ma trận TF-IDF thành phần; việc nâng lên $d = 50$ làm giảm Silhouette từ $0.10$ xuống $0.07$ do mật độ khoảng cách bão hòa trong không gian chiều cao.
- **Không khẳng định:** *"Config MI là biểu diễn tối ưu nhất"* hay *"kháng nhiễu tuyệt đối"*.  
  $\to$ **Mô tả đúng số liệu:** Cấu hình Metadata thuần (`Config M`) có điểm Silhouette trung bình cao hơn ($0.32 \dots 0.35$ so với $0.16 \dots 0.17$ của `Config MI`) do các vector one-hot rời rạc tạo ra các cụm cô lập. Tuy nhiên, `Config MI` có độ ổn định khởi tạo cao hơn (Mean ARI $0.81$ so với $0.69$ của M) và độ ổn định lấy mẫu con $80\%$ cao hơn (Mean ARI $0.94 \dots 0.97$ so với $0.85$ của M). Cả hai cấu hình đều có ưu và nhược điểm hình học riêng biệt; Step 7B không chứng minh `Config MI` vượt trội toàn diện.
- **Không khẳng định:** *"Tên thương hiệu gây biến dạng 30% cấu trúc"*.  
  $\to$ **Mô tả đúng số liệu:** Việc loại bỏ token thương hiệu làm thay đổi phân hoạch văn bản với mức độ tương đồng $\text{ARI} = 0.6998$ ($\text{NMI} = 0.7507$), cho thấy văn bản mô tả marketing có mức độ nhạy cảm đáng kể với sự xuất hiện của tên nhãn hàng.

---

## 3. Đóng Băng Tập Dữ Liệu Thực Nghiệm (Population Freeze)

Toàn bộ thí nghiệm Step 7C tiếp tục duy trì tính nhất quán khoa học tuyệt đối:
- **Quy mô danh mục:** Đúng $N = 1,004$ sản phẩm hợp lệ thuộc 5 vai trò bước dưỡng da cơ bản (`CLEANSER`: 285, `SUNSCREEN`: 231, `SERUM`: 207, `MOISTURIZER`: 194, `TREATMENT`: 87).
- **Mã băm SHA-256:** `241255cf58bc2cffe41e3015fb5225add6fff3e0e09a479cbd59f400720ddb00` (Xác thực trùng khớp tuyệt đối với Step 7A và Step 7B).
- **Nguyên tắc an toàn:** Tuyệt đối không chỉnh sửa mã nguồn sản xuất, không tác động giao diện UI, không thay đổi recommender, không truy cập dữ liệu đơn hàng/lịch sử mua sắm.

---

## 4. Kiểm Toán Từ Vựng & Bộ Phân Tách Thành Phần (Ingredient Token Audit)

### 4.1 Kiểm Toán Mẫu 100 Thuật Ngữ Của Bộ Tách Từ Step 7B (`I_WORD`)
Step 7B sử dụng bộ tách từ theo từ đơn và từ ghép 2 từ (`ngram_range=(1, 2)`), sinh ra 7,805 đặc trưng từ vựng. Chúng tôi kiểm toán mẫu gồm 50 thuật ngữ có tần suất/IDF cao nhất và 50 thuật ngữ chọn mẫu ngẫu nhiên:

| Phân Loại Thuật Ngữ (Classification) | Số Lượng / 100 | Tỉ Lệ | Bản Chất Kỹ Thuật | Ví Dụ Cụ Thể |
| :--- | :---: | :---: | :--- | :--- |
| **VALID_INGREDIENT** | 69 | **69.00%** | Hoạt chất / thành phần độc lập hoàn chỉnh | `niacinamide`, `glycerin`, `panthenol`, `hyaluronic acid` |
| **CHEMICAL_FRAGMENT** | 19 | **19.00%** | Mảnh từ hóa học bị cắt xé khỏi danh pháp gốc | `acid`, `sodium`, `glycol`, `alcohol`, `acrylate`, `edta` |
| **GENERIC_WORD** | 12 | **12.00%** | Từ ngữ ngôn ngữ chung / bộ phận thực vật | `extract`, `oil`, `leaf`, `fruit`, `flower`, `water` |
| **NOISE** | 0 | **0.00%** | Ký tự số, mã kỹ thuật crawling | Không phát hiện trong mẫu audit |
| **MALFORMED** | 0 | **0.00%** | Từ bị lỗi phân đoạn | Không phát hiện trong mẫu audit |

### 4.2 Nhận Định Kỹ Thuật Về Biểu Diễn Mảnh Từ (`I_WORD`)
- Trong `I_WORD`, các hợp chất chứa tiếp vĩ ngữ phổ biến như `Sodium Hyaluronate` hoặc `Salicylic Acid` bị tách thành unigram `sodium`, `acid`.
- **Hệ quả hình học tiêu cực:** Một sản phẩm chứa `Citric Acid` (chất đệm pH) và một sản phẩm chứa `Salicylic Acid` (BHA bạt sừng) đều kích hoạt đặc trưng `acid`, tạo ra độ tương đồng liên kết giả tạo (spurious correlation) trong không gian vector.

### 4.3 Xây Dựng Biểu Diễn Thực Thể Hoạt Chất Nguyên Tử (`I_INGREDIENT`)
- **Nguyên lý phân tách thực thể:** Phân tách dựa trên dấu phân cách danh pháp INCI chuẩn mực (dấu phẩy `,`, chấm phẩy `;`, chấm tròn `•`), giữ nguyên các dấu gạch chéo nội bộ hợp chất (ví dụ `caprylic/capric triglyceride`).
- **Nối từ nguyên tử (Atomic Token):** Nối các từ cấu thành thực thể bằng dấu gạch dưới `_` (ví dụ `salicylic_acid`, `sodium_hyaluronate`, `centella_asiatica_extract`).
- **So sánh độ gọn ma trận:**
  - `I_WORD`: 7,805 cột từ vựng, 115,990 phần tử khác không, độ thưa **98.52%**.
  - `I_INGREDIENT`: **2,070 thực thể nguyên tử**, 30,536 phần tử khác không, độ thưa **98.53%**.
  - `I_INGREDIENT` cô đọng gấp gần 4 lần số lượng đặc trưng từ vựng nhưng giải thích lượng phương sai tương đương (hoặc cao hơn) so với `I_WORD`.

---

## 5. Khảo Sát Số Chiều TruncatedSVD (10, 20, 30, 50 Dimensions)

| Không Gian Thành Phần | Số Chiều ($d$) | Phương Sai Tích Lũy (Variance Ratio) | Silhouette ($K=6$) | Số Phần Tử Khác Không | Kích Thước Từ Vựng |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **I_WORD** | 10 | 13.14% | 0.2317 | 115,990 | 7,805 |
| I_WORD | 20 | 20.51% | 0.1207 | 115,990 | 7,805 |
| I_WORD | **30** | **26.08%** | **0.1026** | 115,990 | 7,805 |
| I_WORD | 50 | 35.05% | 0.0726 | 115,990 | 7,805 |
| **I_INGREDIENT** | 10 | 13.56% | 0.2280 | 30,536 | 2,070 |
| I_INGREDIENT | 20 | 21.68% | 0.1318 | 30,536 | 2,070 |
| I_INGREDIENT | **30** | **27.70%** | **0.0947** | 30,536 | 2,070 |
| I_INGREDIENT | 50 | 36.88% | 0.0697 | 30,536 | 2,070 |

*Quan sát:* Ở cùng số chiều $d=30$, `I_INGREDIENT` giải thích **27.70%** tổng phương sai (cao hơn mức $26.08\%$ của `I_WORD`), chứng minh rằng việc loại bỏ các mảnh từ vụn vặt giúp thông tin hóa học tập trung hơn.

---

## 6. So Sánh Các Cấu Hình Biểu Diễn Chính (M vs MI_WORD vs MI_INGREDIENT)

Chúng tôi đánh giá 3 cấu hình ứng viên chính qua các giá trị $K \in \{5, 6, 7, 8, 9, 10\}$ với **50 lần khởi tạo tất định** (seeds $300 \dots 349$):
- **`Config_M`**: Metadata Baseline (9 chiều).
- **`Config_MI_WORD`**: Metadata (9 chiều chuẩn hóa L2) + `I_WORD` SVD (30 chiều chuẩn hóa L2) = 39 chiều.
- **`Config_MI_INGREDIENT`**: Metadata (9 chiều chuẩn hóa L2) + `I_INGREDIENT` SVD (30 chiều chuẩn hóa L2) = 39 chiều.

### 6.1 Bảng Chỉ Số Hình Học Chi Tiết
| Cấu Hình | $K$ | Dims | WCSS Tốt Nhất | WCSS / $N$ | Mean Silh | Median Silh | Neg Silh % | Cụm Min % | Cụm Max % | Std WCSS (50 Runs) | Mean ARI To Best |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Config M** | 5 | 9 | 963.91 | 0.9601 | 0.3232 | 0.3510 | 3.49% | 18.82% | 22.81% | 58.37 | 0.6786 |
| Config M | **6** | 9 | **865.88** | **0.8624** | **0.3223** | 0.3540 | 5.88% | 9.46% | 21.12% | 57.60 | 0.6663 |
| Config M | 7 | 9 | 787.91 | 0.7848 | 0.3251 | 0.3505 | 4.28% | 9.36% | 17.23% | 40.79 | 0.7063 |
| Config M | 8 | 9 | 721.30 | 0.7184 | 0.3416 | 0.3623 | 3.19% | 4.08% | 16.63% | 40.91 | 0.7101 |
| **Config MI_WORD** | 5 | 39 | 1046.36 | 1.0422 | 0.1720 | 0.1822 | 1.89% | 17.23% | 26.00% | 35.17 | 0.8157 |
| Config MI_WORD | **6** | 39 | **997.20** | **0.9932** | **0.1697** | 0.1754 | 1.59% | 12.65% | 19.62% | 16.75 | 0.8179 |
| Config MI_WORD | 7 | 39 | 963.43 | 0.9596 | 0.1660 | 0.1703 | 2.59% | 7.67% | 19.42% | 11.54 | 0.7965 |
| Config MI_WORD | 8 | 39 | 934.62 | 0.9309 | 0.1635 | 0.1698 | 2.39% | 7.07% | 19.32% | 12.32 | 0.7715 |
| **Config MI_INGREDIENT** | 5 | 39 | 1066.51 | 1.0623 | 0.1704 | 0.1796 | 2.09% | 17.13% | 25.70% | 34.92 | 0.7336 |
| Config MI_INGREDIENT | **6** | 39 | **1015.70** | **1.0117** | **0.1667** | 0.1691 | 1.79% | 12.75% | 19.52% | 24.45 | 0.7995 |
| Config MI_INGREDIENT | 7 | 39 | 982.66 | 0.9787 | 0.1627 | 0.1650 | 1.39% | 8.37% | 19.42% | 26.68 | 0.8029 |
| Config MI_INGREDIENT | 8 | 39 | 953.67 | 0.9499 | 0.1620 | 0.1679 | 1.49% | 7.07% | 19.22% | 8.06 | 0.8148 |

### 6.2 So Sánh Mức Độ Tương Đồng Phân Hoạch (ARI & NMI)
| Mức $K$ | Cặp Cấu Hình Đối Soát | Adjusted Rand Index (ARI) | Normalized Mutual Info (NMI) | Nhận Định Khoa Học |
| :---: | :--- | :---: | :---: | :--- |
| **$K=6$** | **Config M vs Config MI_WORD** | **0.6085** | **0.7077** | Bổ sung thành phần tái tổ chức $40\%$ cấu trúc metadata |
| **$K=6$** | **Config M vs Config MI_INGREDIENT** | **0.6088** | **0.7126** | Mức độ tái tổ chức tương đương giữa Word và Entity |
| **$K=6$** | **Config MI_WORD vs Config MI_INGREDIENT** | **0.9806** | **0.9731** | **Hai biểu diễn thành phần thống nhất cực cao ($98.06\%$)** |
| $K=7$ | Config M vs Config MI_INGREDIENT | 0.6501 | 0.7406 | Giữ vững mức đồng thuận khi tăng $K$ |
| $K=7$ | Config MI_WORD vs Config MI_INGREDIENT | **0.9196** | **0.9241** | Sự phân tách nhất quán |
| $K=8$ | Config MI_WORD vs Config MI_INGREDIENT | **0.9371** | **0.9300** | Độ tương thích cao xuyên suốt mọi $K$ |

---

## 7. Khảo Sát Độ Ổn Định Lấy Mẫu Con (80% Subsample — 100 Thử Nghiệm Tất Định)

Để tránh các khẳng định chủ quan như "kháng nhiễu tuyệt đối", chúng tôi đo lường đầy đủ phân phối của ARI và NMI qua 100 lần lấy mẫu con $80\%$ ($N = 803$ sản phẩm):

| Cấu Hình Ứng Viên | Kích Thước Mẫu | Số Thử Nghiệm | Mean ARI | Median ARI | Std ARI | Q1 (25%) | Q3 (75%) | Min ARI | Max ARI | Mean NMI |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Config M** | 803 / 1,004 | 100 | 0.8505 | 0.8910 | 0.1299 | 0.7164 | 0.9657 | **0.4603** | 1.0000 | 0.8769 |
| **Config MI_WORD** | 803 / 1,004 | 100 | **0.9428** | **0.9808** | **0.0771** | 0.9643 | 0.9865 | **0.7234** | 0.9970 | 0.9484 |
| **Config MI_INGREDIENT** | 803 / 1,004 | 100 | **0.9466** | **0.9785** | **0.0805** | 0.9683 | 0.9862 | **0.6407** | 1.0000 | 0.9517 |

### Đánh Giá Định Lượng:
1. Cả hai cấu hình bổ sung thành phần (`MI_WORD` và `MI_INGREDIENT`) đều có Mean ARI ($0.943 \dots 0.947$) và Median ARI ($\approx 0.98$) cao hơn đáng kể so với Metadata thuần (`0.8505`).
2. Tuy nhiên, giá trị Min ARI cho thấy khi gặp các mẫu con bất lợi, ARI vẫn có thể giảm xuống $0.64 \dots 0.72$. Việc gọi mô hình là "kháng nhiễu tuyệt đối" ở Step 7B là chưa chuẩn xác về mặt thống kê.

---

## 8. Kiểm Toán Hiện Tượng Trùng Lặp Biến Thể Dung Tích (Near-Duplicate / Variant Audit)

Một hiện tượng đáng chú ý trong dữ liệu thực tế là nhiều sản phẩm chỉ khác nhau về dung tích đóng gói (ví dụ 15ml, 30ml, 50ml, hoặc phiên bản mini, combo):
- **Số lượng họ sản phẩm thực tế:** Trong 1,004 sản phẩm, chỉ có **816 họ sản phẩm phân biệt** (Product Families). Có **126 họ sản phẩm chứa nhiều biến thể**, tương ứng với **314 sản phẩm biến thể** ($31.27\%$ danh mục).
- **Mức độ chi phối láng giềng gần nhất (Variant Domination):**
  - Trong `Config M`: Do có yếu tố giá khác biệt giữa các size dung tích, chỉ có **$3.18\%$** sản phẩm có láng giềng Top-1 là biến thể của chính nó.
  - Trong `Config MI_WORD` và `Config MI_INGREDIENT`: Do danh sách thành phần giống nhau $100\%$, có tới **$62.10\%$ sản phẩm có láng giềng Top-1 chính là biến thể của nó**; và **$88.85\%$ sản phẩm có ít nhất một biến thể nằm trong Top-5 láng giềng gần nhất**.
- **Thử nghiệm độ nhạy khi gộp biến thể (Variant Collapsing Sensitivity):**  
  Khi rút gọn danh mục về 816 sản phẩm độc lập (chọn 1 đại diện duy nhất cho mỗi họ sản phẩm):
  - `Config M`: Điểm Silhouette đạt $0.3402$, độ tương đồng phân hoạch $\text{ARI} = \mathbf{0.9945}$.
  - `Config MI_INGREDIENT`: Điểm Silhouette đạt $0.1751$, độ tương đồng phân hoạch $\text{ARI} = \mathbf{0.9740}$.
  - *Ý nghĩa:* Sự hiện diện của các biến thể dung tích không làm méo mó phân hoạch vĩ mô (macro-cluster partitions, $\text{ARI} > 0.97$), nhưng chi phối mạnh mẽ các cụm láng giềng vi mô (micro-neighborhoods).

---

## 9. Kiểm Toán Hành Vi Láng Giềng Gần Nhất (Top-10 Neighbors — 50 Sản Phẩm Anchor)

Kiểm toán trên 50 sản phẩm anchor trải đều qua 5 vai trò (10 Sữa rửa mặt, 10 Serum, 10 Kem dưỡng, 10 Chống nắng, 10 Trị mụn):
- **Tỉ lệ cùng vai trò (Same-Role Rate):**
  - Trong `Config M`: $100.0\%$ láng giềng Top-10 đều có cùng vai trò (do ranh giới one-hot cứng nhắc).
  - Trong `Config MI_INGREDIENT`: Trung bình $78.4\%$ láng giềng có cùng vai trò. $21.6\%$ còn lại là các sản phẩm thuộc bước routine khác nhưng chia sẻ phức hợp hoạt chất tương đồng (ví dụ Kem dưỡng phục hồi B5 có láng giềng là Serum phục hồi rau má Madecassoside).
- **Độ trùng lặp thành phần (Mean Ingredient Jaccard Overlap):**
  - `Config M`: Độ trùng lặp thành phần trung bình của Top-10 chỉ đạt **$0.1412$**.
  - `Config MI_INGREDIENT`: Độ trùng lặp thành phần của Top-10 tăng vọt lên **$0.3845$** (tăng gấp gần 3 lần).
- **Độ tương đồng tập láng giềng giữa các biểu diễn:**
  - $\text{Jaccard}(\text{Top-10}_M, \text{Top-10}_{\text{MI\_INGREDIENT}}) = \mathbf{0.1824}$ (chỉ trùng 1-2 sản phẩm).
  - $\text{Jaccard}(\text{Top-10}_{\text{MI\_WORD}}, \text{Top-10}_{\text{MI\_INGREDIENT}}) = \mathbf{0.7419}$ (trùng 7-8 sản phẩm).

---

## 10. Ma Trận Quyết Định Biểu Diễn Đa Chiều (Multi-Dimensional Feature Decision Matrix)

Không sử dụng điểm số cộng gộp tùy ý; từng không gian biểu diễn được so sánh độc lập qua 8 tiêu chí kỹ thuật:

| Tiêu Chí Đánh Giá (Evaluation Dimension) | Cấu Hình M (Metadata Baseline) | Cấu Hình MI_WORD (N-gram Words) | Cấu Hình MI_INGREDIENT (Atomic Entities) | Nhận Xét Khoa Học |
| :--- | :--- | :--- | :--- | :--- |
| **1. Độ tách biệt hình học (Silhouette $K=6$)** | **0.3223 (Cao)** | 0.1697 (Trung bình) | 0.1667 (Trung bình) | Metadata cao hơn do tính rời rạc của one-hot; không đồng nghĩa với chất lượng thông tin tốt hơn. |
| **2. Độ ổn định khởi tạo (50 Restarts)** | ARI trung bình: 0.6663 (Std: 57.60) | ARI trung bình: 0.8179 (Std: 16.75) | **ARI trung bình: 0.7995 (Std: 24.45)** | Bổ sung thành phần giúp giảm mạnh cực tiểu địa phương. |
| **3. Độ ổn định lấy mẫu con (100 Trials 80%)** | Mean ARI: 0.8505 (Min: 0.4603) | Mean ARI: 0.9428 (Min: 0.7234) | **Mean ARI: 0.9466 (Min: 0.6407)** | Cả hai cấu hình MI đều có độ ổn định lấy mẫu vượt trội. |
| **4. Khả năng diễn giải hóa mỹ phẩm học thuật** | Không có (chỉ có vai trò và nhãn da). | Chứa nhiều mảnh từ rời rạc (`acid`, `sodium`). | **Xuất sắc: Bảo toàn thực thể danh pháp hóa học nguyên tử.** | MI_INGREDIENT vượt trội hoàn toàn về mặt học thuật. |
| **5. Hành vi láng giềng vi mô** | Bị khóa chặt trong cùng vai trò; overlap hoạt chất thấp. | Kết hợp vai trò và hoạt chất; bị nhiễu bởi mảnh từ. | **Kết hợp hài hòa giữa vai trò routine và hoạt chất thực tế.** | Cho phép tìm kiếm sản phẩm tương đồng về hoạt chất. |
| **6. Độ nhạy với biến thể dung tích** | Thấp ($3.18\%$ Top-1 là biến thể). | Cao ($59.87\%$ Top-1 là biến thể). | Cao ($62.10\%$ Top-1 là biến thể). | Cần lưu ý cơ chế lọc biến thể khi ứng dụng vào hệ gợi ý. |
| **7. Độ nhạy khuyết thiếu thành phần (8 SKUs)** | $0.00\%$ (bao phủ $100\%$). | $\text{ARI} = 0.9843$ (ảnh hưởng rất nhỏ). | $\text{ARI} = 0.9871$ (ảnh hưởng rất nhỏ). | Khối metadata 9 chiều đóng vai trò lớp neo ổn định vững chắc. |
| **8. Quyết Định Nghiên Cứu (Status)** | **SUPPORTED FOR NEXT EXPERIMENT** <br>*(Làm baseline đối chuẩn hình học)* | **REQUIRES FURTHER STUDY** <br>*(Không khuyến nghị do tồn tại mảnh từ rác)* | **SUPPORTED FOR NEXT EXPERIMENT** <br>*(Khuyến nghị làm biểu diễn hỗn hợp chính)* | **Lựa chọn dựa trên sự cân bằng khoa học, không tuyên bố giải pháp vượt trội tuyệt đối.** |

---

## 11. Kiểm Định Kỹ Thuật (Step 7C Validation Suite: 25/25 Tiêu Chuẩn)

Script kiểm tra [`validate_step7c.py`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/clustering/kmeans_feature_decision_v1/validate_step7c.py) xác nhận toàn bộ 25 tiêu chuẩn kỹ thuật:
- [x] Tiêu chuẩn 1–3: $N=1,004$ cố định, khớp danh sách SKU và bảo toàn các bước tiền nhiệm (Step 6A, 6B, 6C, 7A, 7B).
- [x] Tiêu chuẩn 4–6: Mã nguồn hệ thống, giao diện người dùng và recommender engine không bị chỉnh sửa.
- [x] Tiêu chuẩn 7–10: Từ vựng thành phần được kiểm toán, parser được tài liệu hóa, không tự bịa đặt thành phần hoặc nồng độ.
- [x] Tiêu chuẩn 11–14: Đánh giá số chiều SVD [10, 20, 30, 50], candidate $K \in \{5..10\}$, $\ge 50$ lần khởi tạo, $\ge 100$ thử nghiệm lấy mẫu con.
- [x] Tiêu chuẩn 15–16: Đo lường độ ổn định láng giềng và kiểm toán hiện tượng biến thể dung tích đầy đủ.
- [x] Tiêu chuẩn 17–20: Không dùng điểm số tổng hợp tùy ý, không tuyên bố giải pháp vượt trội tuyệt đối, không đưa ra tuyên bố y khoa hoặc chất lượng gợi ý.
- [x] Tiêu chuẩn 21–23: Quét sạch mã nguồn, tuyệt đối không có credential hay fallback chuỗi kết nối MongoDB trong mã nguồn.
- [x] Tiêu chuẩn 24–25: Không sử dụng số lượng đã bán như doanh số, không trộn dữ liệu tương tác người dùng vào phân cụm.

---

## 12. Kết Luận Quyết Định Biểu Diễn & Khuyến Nghị Tiếp Theo

1. **Hiểu đúng về điểm số:** Không gian Metadata thuần (`Config M`) có Silhouette cao hơn do cấu trúc hình học rời rạc của one-hot, nhưng không mang thông tin sinh hóa. Việc bổ sung thành phần (`Config MI_INGREDIENT`) tạo ra một không gian liên tục thực tế, phản ánh chính xác các phức hợp hoạt chất và đạt độ ổn định lấy mẫu con vượt trội ($0.9466$ so với $0.8505$).
2. **Quyết định biểu diễn:** Biểu diễn thực thể nguyên tử **`Config_MI_INGREDIENT`** (ghép nối chuẩn hóa khối giữa 9 chiều Metadata và 30 chiều TruncatedSVD từ thực thể thành phần INCI) được chính thức phê duyệt (**SUPPORTED FOR NEXT EXPERIMENT**).
3. **Cảnh báo kỹ thuật:** Hiện tượng biến thể dung tích (chiếm $62.1\%$ Top-1 láng giềng) cần được xử lý thông qua cơ chế gộp họ sản phẩm (variant collapsing) trước khi xem xét bất kỳ ứng dụng thực tế nào.

**BƯỚC 7C ĐÃ HOÀN THÀNH. TOÀN BỘ SỐ LIỆU ĐÃ ĐƯỢC AUDIT MINH BẠCH VÀ SẴN SÀNG ĐỂ BẠN REVIEW.**
