# Ví Dụ Tính Toán Thủ Công TF-IDF, TruncatedSVD & Khoảng Cách Euclidean
## Dành Cho Báo Cáo Chuyên Đề & Bảo Vệ Khóa Luận (SkinSyntaxVN)

Ví dụ dưới đây mô phỏng trực quan và đầy đủ các bước toán học từ văn bản thành phần thô đến vector giảm chiều và khoảng cách hình học, giúp sinh viên có thể giải thích từng công thức trực tiếp trước hội đồng.

---

### 1. Tập Dữ Liệu Rút Gọn (Mini Corpus: 4 Sản Phẩm, 5 Thuật Ngữ)
Giả sử ta có 4 sản phẩm chăm sóc da với danh sách thành phần đã chuẩn hóa:
- **Sản phẩm 1 ($d_1$ - Serum HA):** `hyaluronic_acid`, `glycerin`, `niacinamide`
- **Sản phẩm 2 ($d_2$ - Kem dưỡng B5):** `panthenol`, `ceramide`, `glycerin`
- **Sản phẩm 3 ($d_3$ - Serum Niacinamide):** `niacinamide`, `glycerin`, `hyaluronic_acid`
- **Sản phẩm 4 ($d_4$ - Gel trị mụn BHA):** `salicylic_acid`, `niacinamide`

Tập từ vựng gồm 5 thuật ngữ chuyên ngành:
$$V = [t_1: 	ext{hyaluronic\_acid}, \, t_2: 	ext{glycerin}, \, t_3: 	ext{niacinamide}, \, t_4: 	ext{panthenol}, \, t_5: 	ext{salicylic\_acid}]$$

---

### 2. Bước 1: Tính Tần Suất Thuật Ngữ (Term Frequency - TF)
Tần suất thuật ngữ $t$ trong tài liệu $d$:
$$	ext{TF}(t, d) = rac{f_{t, d}}{\sum_{t' \in d} f_{t', d}}$$

Ma trận tần suất từ (Count Matrix):
| Sản Phẩm | $t_1$ (HA) | $t_2$ (Glycerin) | $t_3$ (Niacinamide) | $t_4$ (Panthenol) | $t_5$ (Salicylic) | Tổng số từ |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| $d_1$ | 1 | 1 | 1 | 0 | 0 | 3 |
| $d_2$ | 0 | 1 | 0 | 1 | 0 | 3 (gồm ceramide) |
| $d_3$ | 1 | 1 | 1 | 0 | 0 | 3 |
| $d_4$ | 0 | 0 | 1 | 0 | 1 | 2 |

---

### 3. Bước 2: Tính Trọng Số Nghịch Đảo Tài Liệu (Inverse Document Frequency - IDF)
Số tài liệu trong tập: $N = 4$.  
Công thức chuẩn mực (theo scikit-learn với smooth_idf=True):
$$	ext{IDF}(t) = \ln\left(rac{1 + N}{1 + 	ext{DF}(t)}ight) + 1$$

Trong đó $	ext{DF}(t)$ là số tài liệu chứa từ $t$:
- $	ext{DF}(t_1 = 	ext{HA}) = 2 \implies 	ext{IDF}(t_1) = \ln(5 / 3) + 1 pprox 0.5108 + 1 = 1.5108$
- $	ext{DF}(t_2 = 	ext{Glycerin}) = 3 \implies 	ext{IDF}(t_2) = \ln(5 / 4) + 1 pprox 0.2231 + 1 = 1.2231$
- $	ext{DF}(t_3 = 	ext{Niacinamide}) = 3 \implies 	ext{IDF}(t_3) = \ln(5 / 4) + 1 pprox 0.2231 + 1 = 1.2231$
- $	ext{DF}(t_4 = 	ext{Panthenol}) = 1 \implies 	ext{IDF}(t_4) = \ln(5 / 2) + 1 pprox 0.9163 + 1 = 1.9163$
- $	ext{DF}(t_5 = 	ext{Salicylic}) = 1 \implies 	ext{IDF}(t_5) = \ln(5 / 2) + 1 pprox 0.9163 + 1 = 1.9163$

*Ý nghĩa khoa học:* Các thành phần phổ biến như Glycerin nhận trọng số IDF thấp hơn ($1.2231$), trong khi thành phần đặc trị chuyên biệt như Salicylic Acid nhận trọng số cao hơn ($1.9163$).

---

### 4. Bước 3: Tính Giá Trị TF-IDF & Chuẩn Hóa Vector L2
Giá trị thô: $	ext{TF-IDF}(t, d) = 	ext{TF}(t, d) 	imes 	ext{IDF}(t)$.  
Sau đó chuẩn hóa độ dài vector theo chuẩn Euclidean (L2 Norm):
$$\mathbf{v}_{	ext{norm}} = rac{\mathbf{v}}{\|\mathbf{v}\|_2}$$

Ví dụ với Sản phẩm 4 ($d_4$):
- $t_3$ (Niacinamide): $1 	imes 1.2231 = 1.2231$
- $t_5$ (Salicylic): $1 	imes 1.9163 = 1.9163$
- Độ dài L2: $\|\mathbf{v}_4\| = \sqrt{1.2231^2 + 1.9163^2} = \sqrt{1.4960 + 3.6722} = \sqrt{5.1682} pprox 2.2734$
- Vector chuẩn hóa $d_4$:
$$\mathbf{x}_4 = [0, \, 0, \, rac{1.2231}{2.2734}, \, 0, \, rac{1.9163}{2.2734}] = [0, \, 0, \, 0.5380, \, 0, \, 0.8429]$$

---

### 5. Bước 4: Giảm Chiều Bằng TruncatedSVD (Rút gọn từ 5 chiều xuống 2 chiều)
Phân tích ma trận $X pprox U \Sigma V^T$. Chiếu ma trận dữ liệu lên 2 vector thành phần chính $V_2$:
$$\mathbf{z}_i = \mathbf{x}_i \cdot V_2$$

Giả sử phép chiếu thu được tọa độ 2 chiều rút gọn cho 4 sản phẩm:
- $\mathbf{z}_1 = [0.72, \, 0.15]$ (Nhóm dưỡng ẩm cấp nước)
- $\mathbf{z}_2 = [0.18, \, 0.65]$ (Nhóm phục hồi màng ẩm B5)
- $\mathbf{z}_3 = [0.70, \, 0.18]$ (Nhóm cấp ẩm & sáng da)
- $\mathbf{z}_4 = [0.25, \, -0.55]$ (Nhóm đặc trị mụn BHA)

---

### 6. Bước 5: Tính Khoảng Cách Euclidean
Khoảng cách Euclidean giữa Sản phẩm 1 ($d_1$) và Sản phẩm 3 ($d_3$):
$$d(\mathbf{z}_1, \mathbf{z}_3) = \sqrt{(0.72 - 0.70)^2 + (0.15 - 0.18)^2} = \sqrt{0.0004 + 0.0009} = \sqrt{0.0013} pprox \mathbf{0.0361}$$

Khoảng cách giữa Sản phẩm 1 ($d_1$ - Cấp ẩm) và Sản phẩm 4 ($d_4$ - Trị mụn):
$$d(\mathbf{z}_1, \mathbf{z}_4) = \sqrt{(0.72 - 0.25)^2 + (0.15 - (-0.55))^2} = \sqrt{0.47^2 + 0.70^2} = \sqrt{0.2209 + 0.4900} = \sqrt{0.7109} pprox \mathbf{0.8431}$$

### Kết Luận Giảng Dạy:
Hai sản phẩm có chung thành phần hoạt chất chính ($d_1$ và $d_3$) có khoảng cách hình học cực kỳ nhỏ ($0.0361$), trong khi sản phẩm cấp ẩm và sản phẩm trị mụn ($d_1$ và $d_4$) nằm ở hai phía đối lập trong không gian đặc trưng ($0.8431$). Điều này minh chứng toán học rõ ràng cho việc đưa thông tin thành phần vào phân cụm giúp tái cấu trúc không gian khoảng cách theo công thức sinh hóa học.
