# Kế Hoạch Kiểm Định Tương Lai (Future Validation Plan)
## Đề Xuất Quy Trình Đánh Giá Chuyên Gia Da Liễu & Thử Nghiệm Hệ Thống

Tài liệu này đề xuất các quy trình thực nghiệm tương lai nhằm khỏa lấp khoảng trống đánh giá (Human Evaluation Gap) và kiểm định mức độ sẵn sàng sản xuất. Toàn bộ nội dung là đề xuất phương pháp luận, **tuyệt đối không tự tạo điểm số giả định**.

---

### 1. Quy Trình Đánh Giá Mức Độ Liên Quan Từ Con Người (Human Relevance Evaluation Protocol)
- **Mục tiêu:** Kiểm định xem độ tương đồng trong không gian phân cụm K-Means có thực sự liên quan và hữu ích theo cảm nhận của người dùng hay không.
- **Phạm vi áp dụng:** Quy trình đánh giá mức độ liên quan từ con người theo quy trình định trước (human relevance evaluation with a predefined protocol) là điều kiện cần cho việc hiển thị sản phẩm tương tự. Đánh giá từ bác sĩ da liễu / chuyên gia chuyên môn (dermatologist/domain-expert review) chỉ cần thiết nếu hệ thống muốn đưa ra các khẳng định về mức độ phù hợp lâm sàng / da liễu (dermatological/clinical suitability claims).
- **Quy mô mẫu đề xuất:** 50 sản phẩm điểm neo (Anchor Products) đại diện cho 21 danh mục lá và các phân khúc giá khác nhau.
- **Thiết kế thử nghiệm (Double-Blind Evaluation):**
  - Mời tối thiểu 3 bác sĩ da liễu / chuyên viên hoạt chất độc lập.
  - Mỗi chuyên gia được cung cấp sản phẩm gốc và 3 danh sách láng giềng ẩn danh (randomized order) sinh ra từ:
    1. Cấu hình phân cụm Taxonomy + Price + Skin + Ingredient V2 (`CONFIG_TAX_PRICE_SKIN_ING`),
    2. Cấu hình phân cụm chỉ dùng Taxonomy + Price (`CONFIG_TAX_PRICE`),
    3. Bộ gợi ý Content-Based hiện tại của SkinSyntaxVN.
- **Thang đo chuẩn hóa (3 tiêu chí, thang điểm 0 – 2):**
  1. *Khả năng thay thế chức năng (Functional Substitutability):* Sản phẩm được gợi ý có thể thay thế bước dưỡng da của sản phẩm gốc hay không? (0: Hoàn toàn không, 1: Tương đối, 2: Rất phù hợp).
  2. *Tương thích công thức & an toàn da liễu (Safety & Formulation Compatibility):* Sản phẩm có chứa hoạt chất gây xung đột hoặc kích ứng chéo cho nhóm da chỉ định hay không? (0: Có xung đột, 1: Trung tính, 2: Tương thích cao).
  3. *Mức độ phân khúc người dùng (User Persona Fit):* Phân khúc giá và kết cấu có phù hợp với cùng một nhóm đối tượng hay không? (0: Lệch hoàn toàn, 1: Chấp nhận được, 2: Rất đồng nhất).
- **Độ tin cậy đo lường:** Tính toán hệ số đồng thuận liên chuyên gia (Inter-Rater Agreement) bằng Cohen's Kappa hoặc Fleiss' Kappa. Chỉ xem xét ứng dụng nếu Kappa $\ge 0.70$.

---

### 2. Thử Nghiệm Mô Phỏng Ngoại Tuyến (Offline Candidate Generation Simulation)
- **Mục tiêu:** Đo lường hiệu quả của cụm K-Means khi đóng vai trò tầng lọc ứng viên (Candidate Retrieval Pool).
- **Phương pháp:**
  - Lấy dữ liệu sản phẩm người dùng đã xem/thích trong cùng phiên.
  - So sánh tỷ lệ bao phủ (Recall@K) và độ chính xác (Precision@K) giữa:
    - *Chiến lược 1 (Baseline):* Lọc theo cùng danh mục lá và khoảng giá.
    - *Chiến lược 2 (Clustering):* Lấy ứng viên từ cùng cụm K-Means.
    - *Chiến lược 3 (Clustering + Variant Collapsed):* Lấy ứng viên từ cụm K-Means sau khi đã lọc bỏ sản phẩm trùng dòng biến thể.

---

### 3. Nghiên Cứu Chính Sách Xử Lý Dòng Biến Thể (Product-Family Policy Framework)
- Nghiên cứu so sánh 2 chiến lược:
  1. **Pre-clustering Collapsing:** Gom nhóm các sản phẩm cùng thương hiệu và tên cơ sở (`clean_base_product_name`) trước khi huấn luyện K-Means. Sau khi phân cụm xong, map nhãn cụm ngược lại cho các biến thể.
  2. **Post-clustering Diversity Filter:** Giữ nguyên toàn bộ catalog khi huấn luyện K-Means, nhưng áp dụng bộ lọc đa dạng hóa (Maximum 1 variant per brand in Top-N recommendations) tại bước sinh kết quả.

---

### 4. Quy Trình Thử Nghiệm Trực Tuyến (Online A/B Testing Protocol)
- Chỉ được triển khai sau khi đã vượt qua Quy trình Chuyên Gia Da Liễu (Kappa $\ge 0.70$).
- Chia 10% lưu lượng truy cập thực tế (traffic) của SkinSyntaxVN:
  - *Nhánh A (Control):* Hệ thống gợi ý Content-Based hiện tại.
  - *Nhánh B (Experiment):* Content-Based kết hợp bộ lọc ứng viên cụm K-Means đã xử lý biến thể.
- Đo lường các chỉ số kinh doanh và kỹ thuật:
  - Click-Through Rate (CTR) trên khối "Sản phẩm tương tự",
  - Tỷ lệ thêm vào giỏ hàng (Add-to-Cart Rate),
  - Độ đa dạng danh mục hiển thị (Intra-List Diversity),
  - Độ trễ phản hồi API (Latency P95/P99).
