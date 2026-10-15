# CÁC HẠN CHẾ CỦA HỆ THỐNG GỢI Ý (SYSTEM LIMITATIONS)
## HỆ THỐNG GỢI Ý SKINSYNTAXVN — FINAL MASTER AUDIT

Tài liệu kiểm toán này ghi nhận đầy đủ và trung thực các giới hạn kỹ thuật, dữ liệu và phương pháp luận hiện tại của hệ thống gợi ý SkinSyntaxVN. Đây là phần bắt buộc phải trình bày trong chương Thảo luận & Hạn chế (Discussion & Limitations) của luận văn tốt nghiệp.

---

### 1. DỮ LIỆU TƯƠNG TÁC HỮU CƠ CÒN HẠN CHẾ (SPARSE ORGANIC INTERACTION DATA)
* **Thực trạng kiểm toán:**
  * Cơ sở dữ liệu hiện tại chỉ ghi nhận: 19 khách hàng, 14 tài khoản người dùng, 28 hóa đơn mua hàng (33 chi tiết sản phẩm), 18 lượt đánh giá sản phẩm, 30 truy vấn tìm kiếm và 0 log tương tác dài hạn trong collection `tuong_tac_nguoi_dung`.
  * Toàn bộ 2,473 sản phẩm hoạt động có ma trận tương tác User-Item cực kỳ thưa thớt (sparsity > 99.9%).
* **Hệ quả:**
  * Các thuật toán học máy phụ thuộc dữ liệu tương tác người dùng quy mô lớn (CF, Matrix Factorization, Deep Learning) chưa thể huấn luyện và kiểm chứng trực tiếp trên dữ liệu thật của nền tảng.

---

### 2. MÔ HÌNH LỌC CỘNG TÁC CHỦ YẾU DỰA TRÊN DỮ LIỆU MÔ PHỎNG (SYNTHETIC-DOMINATED CF RESEARCH)
* **Thực trạng kiểm toán:**
  * Các mô hình Item-kNN, Funk-style Matrix Factorization và Bayesian Personalized Ranking (BPR) trong Step 6 được đánh giá trên tập dữ liệu tương tác mô phỏng kiểm soát (controlled synthetic implicit feedback datasets).
* **Hệ quả:**
  * Dù BPR chứng minh được tính ưu việt về mặt lý thuyết và trên dữ liệu giả lập, năng lực thực tế đối với hành vi người tiêu dùng mỹ phẩm tại Việt Nam vẫn chưa được kiểm chứng ở môi trường production.

---

### 3. THIẾU DỮ LIỆU GIỎ HÀNG THỰC TẾ CHO LUẬT KẾT HỢP (INSUFFICIENT REAL BASKET DATA)
* **Thực trạng kiểm toán:**
  * Trong 28 đơn hàng thực tế, đa số đơn hàng chỉ chứa duy nhất 1 sản phẩm. Số lượng giao dịch đồng thời (multi-item transactions) gần như bằng 0.
  * Các thí nghiệm khai phá luật kết hợp Apriori và FP-Growth (Step 5) hoàn toàn dựa trên các kịch bản giỏ hàng mô phỏng (30, 100, 300 synthetic baskets).
* **Hệ quả:**
  * Tính năng "Thường được mua cùng nhau" (Frequently Bought Together) chưa thể kích hoạt trên website do chưa có đủ luật kết hợp khai phá từ dữ liệu mua hàng hữu cơ có ý nghĩa thống kê.

---

### 4. TRỌNG SỐ THIẾT KẾ MANG TÍNH THỰC NGHIỆM THỦ CÔNG (MANUAL / ENGINEERING HEURISTIC WEIGHTS)
* **Thực trạng kiểm toán:**
  * Trọng số dung hợp hành vi: $\text{Cart: } 0.35, \text{View: } 0.35, \text{Search: } 0.20, \text{Purchase: } 0.10$.
  * Trọng số truy vấn lai (Adaptive Hybrid Query): $0.50 \times \text{Behavior} + 0.50 \times \text{Profile}$.
  * Trọng số xếp hạng lại (Reranking): $0.70 \times \text{Content} + 0.20 \times \text{Skin} + 0.10 \times \text{Budget}$.
* **Hệ quả:**
  * Toàn bộ hệ thống trọng số này được xây dựng dựa trên trực giác kỹ thuật (engineering heuristics) và thử nghiệm thủ công, không phải kết quả tối ưu hóa từ mô hình học máy (Learning to Rank) hay gradient descent.

---

### 5. THIẾU THỬ NGHIỆM TRỰC TUYẾN A/B TESTING (NO ONLINE A/B TESTING)
* **Thực trạng kiểm toán:**
  * Hệ thống chưa tích hợp hạ tầng phân luồng lưu lượng (traffic splitting), bucketing người dùng và theo dõi sự khác biệt về chuyển đổi giữa nhóm can thiệp (treatment group) và nhóm đối chứng (control group).
* **Hệ quả:**
  * Chưa thể đo lường tác động kinh doanh thực tế (Business Impact) của hệ thống gợi ý đối với các chỉ số như Click-Through Rate (CTR), Conversion Rate (CVR), Average Order Value (AOV) hay Time-on-Site.

---

### 6. THIẾU ĐÁNH GIÁ ĐỘ PHÙ HỢP TỪ CON NGƯỜI CÓ HỆ THỐNG (NO FORMAL HUMAN RELEVANCE STUDY)
* **Thực trạng kiểm toán:**
  * Các đánh giá chất lượng gợi ý hiện tại dựa trên các chỉ số toán học tự động (Cosine Similarity, Precision@K mô phỏng, WCSS, Silhouette).
* **Hệ quả:**
  * Chưa có cuộc khảo sát mù (blind user study) hoặc đánh giá định tính có quy thức (formal qualitative human protocol) từ nhóm người dùng mẫu hoặc chuyên gia da liễu để chấm điểm mức độ thỏa mãn thực tế của danh sách gợi ý.

---

### 7. BIỂU DIỄN THÀNH PHẦN CHƯA PHẢI BẢN THỂ HỌC HÓA HỌC (INGREDIENT REPRESENTATION LIMITATION)
* **Thực trạng kiểm toán:**
  * Hệ thống Content-Based trong production sử dụng TF-IDF trên chuỗi văn bản danh sách thành phần.
  * Parser V2 (mặc dù đã xử lý được các lỗi ngắt chuỗi hóa học như '1,2-hexanediol') vẫn chỉ là công cụ tách từ (tokenizer), chưa xây dựng được mạng lưới bản thể học hóa học (Chemical Ontology / INCI Knowledge Graph).
* **Hệ quả:**
  * Hệ thống chưa phân biệt được nồng độ hoạt chất (ví dụ Niacinamide 2% vs 10%), chưa hiểu được quan hệ đồng nghĩa/dẫn xuất sinh học (Retinol vs Retinal vs Tretinoin) và chưa phát hiện được các tương kỵ hoạt chất (ví dụ AHA/BHA kết hợp Retinol nồng độ cao).

---

### 8. VẤN ĐỀ TRÙNG LẶP BIẾN THỂ SẢN PHẨM (VARIANT-PRODUCT REDUNDANCY)
* **Thực trạng kiểm toán:**
  * Catalog có nhiều sản phẩm chỉ khác nhau về dung tích (30ml, 50ml, 100ml) hoặc sắc độ (tone màu son, kem nền) nhưng được lưu thành các bản ghi độc lập với tên gọi và thành phần gần như đồng nhất 100%.
* **Hệ quả:**
  * Nếu không có bộ lọc đa dạng hóa theo dòng sản phẩm (Product Family Filter), danh sách gợi ý Top-4 có nguy cơ bị chiếm lĩnh bởi 4 dung tích khác nhau của cùng một loại serum/kem dưỡng. Bộ lọc heuristic hiện tại (`stripVolumeFromName`) giúp giảm nhẹ nhưng chưa phải giải pháp cấu trúc dữ liệu triệt để.

---

### 9. GIỚI HẠN VỀ HỒ SƠ VÀ BẢNG KHẢO SÁT DA (PROFILE & SURVEY LIMITATIONS)
* **Thực trạng kiểm toán:**
  * Thông tin khảo sát da phụ thuộc vào sự tự nhận thức chủ quan của người dùng (tự đoán da dầu, da khô hoặc da nhạy cảm), dễ dẫn đến sai lệch thông tin đầu vào.
  * Khảo sát chỉ lưu 1 bản ghi mới nhất trong collection `nguoidung.khao_sat_da`, chưa lưu trữ lịch sử diễn biến tình trạng da theo mùa hoặc theo độ tuổi.

---

### 10. GIỚI HẠN LƯU TRỮ PHIÊN HÀNH VI (SESSION PERSISTENCE LIMITATIONS)
* **Thực trạng kiểm toán:**
  * Đối với khách vãng lai (guest user), các tín hiệu hành vi xem trang (`viewed_products`), giỏ hàng (`cart`) và tìm kiếm (`search_history`) được lưu trữ trong PHP Session (`$_SESSION`).
* **Hệ quả:**
  * Khi phiên duyệt web kết thúc (người dùng đóng trình duyệt, xóa cookie hoặc hết hạn session sau 24 phút mặc định), toàn bộ vector hành vi của khách vãng lai sẽ biến mất và hệ thống sẽ quay trở lại trạng thái Cold-Start ban đầu.
