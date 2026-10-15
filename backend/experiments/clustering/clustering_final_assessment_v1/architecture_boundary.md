# Phân Định Ranh Giới Kiến Trúc Hệ Thống (Architecture Boundary)
## Vị Trí Của K-Means Trong Hệ Thống Gợi Ý & Phân Tích Của SkinSyntaxVN

Tài liệu này xác định vị trí kỹ thuật chính xác, ranh giới trách nhiệm và sự phân tách nhiệm vụ giữa thuật toán phân cụm K-Means và các thành phần khác trong kiến trúc SkinSyntaxVN.

---

### 1. Phân Tách Nhiệm Vụ Giữa Các Thuật Toán (Task Boundary Separation)
SkinSyntaxVN kiên quyết bác bỏ quan điểm xem K-Means là thuật toán cạnh tranh hay thay thế cho các thuật toán gợi ý hiện tại. Mỗi thuật toán giải quyết một bài toán toán học hoàn toàn khác nhau:

| Thuật Toán / Module | Bản Chất Bài Toán | Dữ Liệu Đầu Vào | Không Gian Đầu Ra | Mục Tiêu Tối Ưu |
| :--- | :--- | :--- | :--- | :--- |
| **K-Means Clustering** | Unsupervised Catalog Grouping | Taxonomy, Giá log, Skin tags, Thành phần V2 SVD | Nhãn cụm rời rạc $c_i \in \{0..K-1\}$ | Tối thiểu hóa tổng phương sai nội cụm (WCSS) |
| **Simple Weighted Rating (WR)** | Global Quality & Popularity Baseline | Điểm đánh giá trung bình, số lượt đánh giá | Điểm số liên tục $S_{WR} \in [1, 5]$ | Bayesian shrinkage làm mượt điểm cho sản phẩm ít đánh giá |
| **Adaptive Content-Based** | Attribute-to-Profile Matching | Vector thuộc tính sản phẩm & Hồ sơ da người dùng | Điểm tương đồng $S_{CB} \in [0, 1]$ | Tối đa hóa độ phù hợp giữa nhu cầu da và công thức |
| **Collaborative Filtering (CF)** | User-Item Preference Prediction | Lịch sử tương tác, đánh giá của người dùng | Điểm dự đoán sở thích $\hat{r}_{u,i}$ | Tối thiểu hóa sai số dự đoán ma trận tương tác |
| **Association Rules (Apriori / FP-Growth)** | Market Basket Association Mining | Giỏ hàng thực tế, giao dịch cùng thời điểm | Luật kết hợp $X \Rightarrow Y$ (Support, Confidence, Lift) | Tìm kiếm các tập mục mua cùng nhau (Frequently Bought Together) |

---

### 2. Ranh Giới Kiến Trúc Bắt Buộc (Strict Architecture Boundaries)

1. **K-Means KHÔNG PHẢI là Recommender:**
   - K-Means chỉ tạo ra các phân hoạch rời rạc (partitions) và khoảng cách Euclidean tới tâm cụm.
   - Khoảng cách tới tâm cụm **tuyệt đối không được chuyển đổi thành điểm số gợi ý (recommendation score)** hay xác suất mua hàng. Một sản phẩm nằm gần tâm cụm chỉ có nghĩa là nó có đặc tính trung bình của cụm đó, không đồng nghĩa với việc nó "tốt hơn" hay "được yêu thích hơn".
2. **K-Means KHÔNG THAY THẾ Content-Based hay Hybrid:**
   - Bộ gợi ý Content-Based hiện tại tính toán độ phù hợp theo từng thành phần hoạt chất cụ thể (Active Ingredients) và loại da của từng cá nhân. K-Means gộp chung 2,473 sản phẩm vào một số ít cụm cố định ($K \in [6, 12]$), làm mất đi tính cá nhân hóa sâu.
3. **K-Means KHÔNG THAY THẾ Apriori / FP-Growth:**
   - Việc hai sản phẩm nằm cùng một cụm K-Means (ví dụ hai loại sữa rửa mặt tạo bọt) thường có nghĩa là chúng **thay thế lẫn nhau (substitutes)**.
   - Ngược lại, thuật toán khai phá luật kết hợp (Association Rules) tìm kiếm các sản phẩm **bổ trợ lẫn nhau trong quy trình skincare (complements)**, ví dụ mua Sữa Rửa Mặt kèm Nước Tẩy Trang hoặc Bông Tẩy Trang.
4. **Không phân cụm người dùng bằng mô hình sản phẩm:**
   - K-Means trong chuỗi Step 6–7D được huấn luyện thuần túy trên không gian sản phẩm ($N=2,473$). Tuyệt đối không suy diễn không gian này để phân cụm tài khoản người dùng khi chưa có mô hình và dữ liệu kiểm định riêng biệt.

---

### 3. Vị Trí Khả Thi Nhất Nếu Ứng Dụng Trong Tương Lai
Nếu SkinSyntaxVN tích hợp K-Means vào hệ thống trong tương lai, vị trí an toàn và có cơ sở khoa học nhất là:
- **Tầng tiền xử lý danh mục ngoại tuyến (Offline Catalog Pre-processing & Analysis):**
  - Tự động phát hiện các cụm sản phẩm bất thường về giá trong danh mục.
  - Kiểm toán độ phân bổ công thức và phát hiện lỗ hổng danh mục (catalog gaps).
- **Tầng lọc ứng viên thô hoặc kiểm soát độ đa dạng (Coarse Candidate Retrieval / Diversity Bucket):**
  - Đảm bảo danh sách gợi ý cuối cùng không bị áp đảo bởi các sản phẩm cùng một cụm vi mô, với điều kiện tiên quyết là đã có chính sách gộp biến thể (variant collapsing policy).
