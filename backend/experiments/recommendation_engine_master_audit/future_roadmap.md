# LỘ TRÌNH PHÁT TRIỂN TƯƠNG LAI (FUTURE ROADMAP)
## HỆ THỐNG GỢI Ý SKINSYNTAXVN — FINAL MASTER AUDIT

Lộ trình phát triển được xây dựng dựa trên nguyên tắc: **Tập trung hoàn thiện chất lượng dữ liệu, đánh giá thực chứng và kiểm định hiệu quả thực tế thay vì chạy theo số lượng thuật toán lý thuyết.**

---

### GIAI ĐOẠN 1: THU THẬP VÀ CHUẨN HÓA DỮ LIỆU THỰC TẾ (DATA FOUNDATION)

#### 1. Thu thập dữ liệu tương tác hữu cơ dài hạn (Collect Organic Interaction Data)
* **Mục tiêu:** Kích hoạt hệ thống ghi nhận sự kiện tương tác đa kênh (Click, Hover, View, Add-to-cart, Purchase) đồng bộ từ Frontend vào collection `tuong_tac_nguoi_dung` với schema chuẩn ISO 8601.
* **Hành động:** Xây dựng endpoint tracking bất đồng bộ (Beacon API) nhẹ, không gây trễ tải trang web, liên kết phiên ẩn danh (`session_id`) với tài khoản người dùng (`user_id`) sau khi đăng nhập.

#### 2. Thiết lập quy chuẩn kiểm định cắt mẫu thời gian (Real Temporal Holdout Evaluation)
* **Mục tiêu:** Xây dựng pipeline đánh giá offline phản ánh đúng thực tế vận hành thương mại điện tử.
* **Hành động:** Thay thế việc chia train/test ngẫu nhiên (random split) bằng chia theo mốc thời gian thực tế ($T_{\text{train}} < T_{\text{split}} \le T_{\text{test}}$), đo lường khả năng dự đoán hành vi tương lai của người dùng mà không bị rò rỉ dữ liệu (data leakage).

---

### GIAI ĐOẠN 2: ĐÁNH GIÁ CHẤT LƯỢNG VÀ THỰC NGHIỆM CON NGƯỜI (HUMAN & DOMAIN VALIDATION)

#### 3. Đánh giá độ phù hợp bởi con người có quy thức (Human Relevance Evaluation Protocol)
* **Mục tiêu:** Kiểm chứng chất lượng gợi ý vượt ra khỏi các metric toán học trừu tượng.
* **Hành động:** Thiết lập giao thức đánh giá mù (Blind Test) với ít nhất 30 người dùng đại diện và chuyên viên chăm sóc da, chấm điểm mức độ liên quan (Relevant, Partially Relevant, Irrelevant) của Top-4 gợi ý trên các trường hợp hồ sơ da điển hình.

#### 4. Khai phá luật kết hợp trên dữ liệu giỏ hàng thực (Real Basket Association Mining)
* **Mục tiêu:** Kích hoạt tính năng "Thường được mua cùng nhau" (Frequently Bought Together) trên trang chi tiết sản phẩm.
* **Điều kiện tiên quyết:** Chỉ triển khai khi cơ sở dữ liệu tích lũy tối thiểu $N \ge 1,000$ đơn hàng hữu cơ đa sản phẩm.
* **Hành động:** Áp dụng thuật toán FP-Growth định kỳ hàng tuần trên tập giao dịch thực, lọc các luật có $\text{Support} \ge 0.01$ và $\text{Lift} > 1.5$.

---

### GIAI ĐOẠN 3: NÂNG CẤP MÔ HÌNH VÀ TỐI ƯU HỆ THỐNG (MODEL MATURATION)

#### 5. Kiểm chứng mô hình BPR trên dữ liệu người dùng thật (BPR Organic Validation)
* **Mục tiêu:** Xác định xem Bayesian Personalized Ranking có duy trì được cải thiện xếp hạng khi huấn luyện trên dữ liệu tương tác ngầm (implicit feedback) hữu cơ của người dùng SkinSyntaxVN hay không.
* **Hành động:** So sánh hiệu năng Recall@10 / Hit-Ratio giữa BPR và Content-Based baseline trên dữ liệu kiểm thử thời gian thực.

#### 6. Thử nghiệm sinh ứng viên dựa trên phân cụm (Clustering-Based Candidate Generation)
* **Mục tiêu:** Khảo sát tiềm năng thực tế của K-Means trong việc giảm không gian tìm kiếm (Candidate Retrieval).
* **Hành động:** Kiểm tra xem việc giới hạn phạm vi tìm kiếm trong cụm của K-Means có giúp tăng tốc độ phản hồi truy vấn trong khi vẫn duy trì độ phủ (Coverage) và độ tương đồng hay không.

#### 7. Hoàn thiện chính sách nhóm biến thể sản phẩm (Variant-Family Policy)
* **Mục tiêu:** Loại bỏ triệt để hiện tượng trùng lặp các biến thể dung tích/màu sắc trong danh sách gợi ý.
* **Hành động:** Chuẩn hóa trường `parent_product_id` hoặc cấu trúc nhóm sản phẩm (Product Family Clustering) trong cơ sở dữ liệu, đảm bảo một họ sản phẩm chỉ xuất hiện tối đa 1 đại diện tốt nhất trong Top-N gợi ý.

---

### GIAI ĐOẠN 4: ĐÁNH GIÁ TÁC ĐỘNG KINH DOANH TRỰC TUYẾN (BUSINESS IMPACT)

#### 8. Thử nghiệm trực tuyến A/B Testing (Online A/B Testing)
* **Mục tiêu:** Đo lường chính xác giá trị kinh tế của hệ thống gợi ý đối với nền tảng SkinSyntaxVN.
* **Hành động:** 
  * Nhánh A (Control): Hiển thị gợi ý ngẫu nhiên hoặc sản phẩm mới nhất.
  * Nhánh B (Treatment): Hiển thị gợi ý cá nhân hóa từ hệ thống `Adaptive Hybrid`.
  * Đo lường: CTR (Click-Through Rate), CVR (Conversion Rate), Doanh thu trung bình trên mỗi phiên (Revenue per Visit).
