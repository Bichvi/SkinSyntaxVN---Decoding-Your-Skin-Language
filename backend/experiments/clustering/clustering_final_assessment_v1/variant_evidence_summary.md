# Tổng Hợp Chứng Cứ Về Biến Thể Sản Phẩm (Variant Evidence Summary)
## Phân Tích Ảnh Hưởng Của Dòng Biến Thể (Product-Family / Multi-Variant Structure)

Tài liệu này tổng hợp toàn diện các bằng chứng thực nghiệm về tác động của sản phẩm biến thể (variants: khác dung tích, màu sắc, mùi hương, bao bì) đối với thuật toán phân cụm K-Means xuyên suốt các bước nghiên cứu Step 7C, 7C.1 và 7D.

---

### 1. Bản Chất Của Vấn Đề Biến Thể Trong E-Commerce Skincare
- **Đặc thù dữ liệu sàn:** Một dòng sản phẩm thường có nhiều mã hàng (SKU) độc lập trong cơ sở dữ liệu:
  - Khác dung tích: Ví dụ CeraVe Foaming Cleanser các bản 88ml, 236ml, 473ml.
  - Khác sắc thái / bao bì: Kem chống nắng bản nâng tông và bản không màu, son dưỡng các mùi hương khác nhau.
- **Hệ quả hình học trong không gian đặc trưng:**
  - Các biến thể này chia sẻ cùng danh mục lá (`leaf_category_id`), cùng nhãn hiệu, cùng thuộc tính loại da, và gần như 100% danh sách thành phần.
  - Điểm khác biệt duy nhất thường là dung tích và giá bán tương ứng.
  - Trong không gian vector, các sản phẩm cùng dòng biến thể tạo thành những "siêu cụm vi mô" (micro-clusters) có khoảng cách Euclidean cực kỳ gần nhau.

---

### 2. Tổng Hợp Bằng Chứng Thực Nghiệm Xuyên Suốt Các Bước

#### A. Quan sát tại Step 7C & Step 7C.1 (Tập con 1,004 sản phẩm)
- Tại Step 7C, khi kiểm tra các cụm nhỏ, nhiều cụm thực chất chỉ gồm các biến thể dung tích của cùng một dòng sản phẩm (ví dụ cụm kem dưỡng ẩm hoặc nước tẩy trang Bioderma các size nắp hồng).
- Tại Step 7C.1, khi kiểm toán láng giềng Top-10, tỷ lệ láng giềng cùng dòng biến thể chiếm tỷ trọng đáng kể, làm "bão hòa" danh sách láng giềng gần nhất.

#### B. Phân tích định lượng toàn diện tại Step 7D ($N=2,473$ sản phẩm)
- **Quy mô biến thể trong catalog:**
  - Có **364 dòng sản phẩm đa biến thể** (Multi-Product Families), bao gồm tổng cộng **898 sản phẩm** (chiếm 36.3% toàn bộ catalog).
  - 1,575 sản phẩm còn lại là sản phẩm đơn lập (Single-Product Families). Tổng số dòng sản phẩm độc lập là 1,939 dòng.
- **Tác động lên truy xuất láng giềng (Neighbor Audit trên 120 Anchors):**
  - Tỷ lệ sản phẩm láng giềng gần nhất (Top-1 Neighbor) thuộc cùng dòng biến thể đạt trung bình **15.45%**.
  - Tỷ lệ này trong Top-5 đạt **7.51%** và trong Top-10 đạt **4.46%**.
- **Kiểm định độ nhạy gộp biến thể (Variant-Collapsing Sensitivity Analysis):**
  - Khi rút gọn catalog từ 2,473 sản phẩm xuống còn 1,939 sản phẩm đại diện (mỗi dòng biến thể chỉ giữ lại 1 sản phẩm đại diện có giá trung vị) và tái phân cụm tại $K=8$:
    - **`ARI = 0.5655`**
    - **`NMI = 0.7458`**
  - **Đính chính học thuật bắt buộc:**
    > *"Variant collapsing produced a material change in partition structure under this sensitivity analysis."*
  - Điểm ARI = 0.5655 chứng minh rằng việc có mặt hay không có mặt của các bản sao biến thể làm thay đổi đáng kể ranh giới phân cụm và vị trí của các tâm cụm (centroids). Cụm K-Means bị "kéo" lệch về phía các dòng sản phẩm có nhiều biến thể.

---

### 3. Khuyến Nghị Kiến Trúc Bắt Buộc Cho Tương Lai
- **Kết luận:** *Product-family / variant structure materially affects clustering.*
- **Yêu cầu chính sách trong tương lai:** Nếu phân cụm K-Means được xem xét ứng dụng trong tương lai cho các tác vụ:
  1. *Candidate Generation* (Sinh ứng viên),
  2. *Neighbor Retrieval* (Truy xuất sản phẩm tương tự),
  3. *Diversity Control* (Kiểm soát độ đa dạng danh mục gợi ý),
  thì **BẮT BUỘC PHẢI CÓ MỘT CHÍNH SÁCH XỬ LÝ BIẾN THỂ RÕ RÀNG (Explicit Product-Family Policy)**:
  - Hoặc gộp biến thể trước khi phân cụm (Variant deduplication/collapsing pre-clustering),
  - Hoặc áp dụng bộ lọc đa dạng hóa sau phân cụm (Variant diversification filter post-clustering) để tránh việc gợi ý cùng một loại kem dưỡng 3 lần chỉ khác dung tích 50ml, 100ml, 200ml.
- **Tuân thủ quy chế:** Không tự ý triển khai chính sách xử lý biến thể này trong phạm vi Step 7E.
