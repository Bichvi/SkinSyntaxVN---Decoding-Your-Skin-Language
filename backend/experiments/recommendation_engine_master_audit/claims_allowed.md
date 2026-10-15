# TUYÊN BỐ ĐƯỢC PHÉP VÀ KHÔNG ĐƯỢC PHÉP (CLAIMS ALLOWED & UNSUPPORTED)
## HỆ THỐNG GỢI Ý SKINSYNTAXVN — FINAL MASTER AUDIT

Tài liệu này xác lập ranh giới học thuật và kỹ thuật chính xác cho luận văn tốt nghiệp / báo cáo kỹ thuật của hệ thống gợi ý SkinSyntaxVN, nhằm ngăn chặn các phát biểu quá mức (overclaims) hoặc diễn giải sai lệch kết quả nghiên cứu.

---

### 1. CÁC TUYÊN BỐ ĐƯỢC KHOA HỌC & DỮ LIỆU HỖ TRỢ (SUPPORTED CLAIMS)

Các kết luận sau đây được bảo chứng trực tiếp bởi mã nguồn thực tế, dữ liệu cơ sở dữ liệu hữu cơ hoặc các thí nghiệm kiểm soát đã hoàn thành:

1. **Hệ thống hỗ trợ gợi ý Cold-Start (Khởi đầu lạnh):**
   * *Bằng chứng:* Khi người dùng là khách vãng lai hoàn toàn mới (chưa đăng nhập, không có lịch sử tìm kiếm, xem, giỏ hàng, đơn hàng), hệ thống tự động định tuyến về `SimpleRecommender` dựa trên mô hình Weighted Rating chuẩn IMDb.
   * *Thuật toán:* $WR = \frac{v}{v+m} R + \frac{m}{v+m} C$ với tham số chuẩn xác định từ catalog ($m = 21.0$, $C = 4.8890$).

2. **Gợi ý tự động thích ứng với các tín hiệu hành vi thực tế (Adaptive Behavior):**
   * *Bằng chứng:* Hệ thống thu thập và xử lý 4 loại tín hiệu hành vi (`SEARCH`, `VIEW`, `CART`, `PURCHASE`) từ session và database, tổng hợp thành vector hành vi $U_{behavior}$ để truy vấn và cá nhân hóa danh sách gợi ý.
   * *Đã kiểm chứng:* Đã kiểm thử thành công qua 9 kịch bản kiểm thử runtime thực tế.

3. **Hồ sơ khảo sát làn da có thể điều hướng xếp hạng (Profile Influence):**
   * *Bằng chứng:* Vector hồ sơ $V_{profile}$ được xây dựng từ kết quả khảo sát (loại da, nhu cầu, thành phần quan tâm) và hòa trộn với vector hành vi theo tỉ lệ $0.50 : 0.50$ trong chế độ `ADAPTIVE_HYBRID`, đồng thời cộng điểm skin match bonus ($+0.20$) và budget match ($+0.10$) tại bước Reranking.

4. **Hệ thống Content-Based dựa trên độ tương đồng siêu dữ liệu sản phẩm (Product Metadata Similarity):**
   * *Bằng chứng:* Mô hình TF-IDF 128 chiều với smooth IDF và trọng số Config B (Tên x2, Danh mục x3, Loại da x3, Thành phần x2, Thương hiệu x1, Mô tả x1) tính toán Cosine Similarity trực tiếp trên không gian đặc trưng sản phẩm thực tế của catalog ($N = 2,473$).

5. **Simple Weighted Rating cung cấp baseline toàn cục phi cá nhân hóa ổn định:**
   * *Bằng chứng:* Đảm bảo danh mục nổi bật hiển thị các sản phẩm được đánh giá cao nhưng có đủ số lượng đánh giá ($v \ge 21$) nhằm tránh thiên vị điểm số tuyệt đối từ ít lượt đánh giá.

6. **Nghiên cứu K-Means bao phủ toàn bộ phân loại danh mục (Full Catalog Taxonomy):**
   * *Bằng chứng:* Thí nghiệm Step 7D đã mở rộng phân cụm K-Means (với $K=8$ đóng vai trò reference $K$ cho phân tích minh họa, không phải cấu hình phổ quát toàn hệ thống) trên toàn bộ 2,473 sản phẩm thuộc 28 danh mục hoạt động, kiểm chứng qua các không gian đặc trưng cấu hình phân loại và giá.

7. **Luật kết hợp (Association Rules) đã được kiểm chứng trên tập dữ liệu kiểm soát:**
   * *Bằng chứng:* Cả hai thuật toán Apriori và FP-Growth đã được chạy và đối soát tính đúng đắn trên các tập dữ liệu giỏ hàng kiểm soát (30, 100, 300 baskets), chứng minh sự hội tụ và tính ổn định của các chỉ số Support, Confidence, Lift.

8. **Lọc cộng tác (Collaborative Filtering) đã được đánh giá qua thí nghiệm offline kiểm soát:**
   * *Bằng chứng:* Các mô hình Item-kNN, Funk SVD/MF và Bayesian Personalized Ranking (BPR) đã được triển khai và so sánh trên dữ liệu tương tác kiểm soát; trong các thí nghiệm synthetic implicit-feedback, BPR cải thiện đáng kể so với implementation Funk MF hiện tại ở một số metric ranking và cho thấy tín hiệu đáng nghiên cứu tiếp. Kết quả không thiết lập hiệu quả trên người dùng SkinSyntaxVN thực.

---

### 2. CÁC TUYÊN BỐ TUYỆT ĐỐI KHÔNG ĐƯỢC PHÉP (UNSUPPORTED / PROHIBITED CLAIMS)

Các phát biểu sau đây **không có bằng chứng xác thực**, **thiếu dữ liệu thực tế** hoặc **sai lệch về bản chất kỹ thuật**, do đó **nghiêm cấm** sử dụng trong luận văn và tài liệu báo cáo:

1. ❌ **"K-Means cải thiện tỷ lệ chuyển đổi (conversion rate) hoặc doanh thu":**
   * *Lý do:* K-Means chỉ là nghiên cứu cấu trúc catalog offline (Step 7A–7E). K-Means chưa từng được tích hợp vào production và chưa từng trải qua thử nghiệm A/B trên người dùng thực.

2. ❌ **"Thuật toán BPR làm tăng tỷ lệ mua hàng của người dùng thực":**
   * *Lý do:* Nghiên cứu BPR được đánh giá trên tập dữ liệu synthetic/controlled implicit feedback. Chưa có dữ liệu tương tác hữu cơ đủ lớn từ người dùng thực để validate online.

3. ❌ **"Các luật kết hợp (Association Rules) phản ánh chính xác thói quen giỏ hàng của khách hàng thực tế":**
   * *Lý do:* Dữ liệu giao dịch thực tế hiện chỉ có 28 đơn hàng với 33 dòng chi tiết đơn hàng (hầu hết đơn hàng chỉ có 1 item). Các luật Apriori/FP-Growth được trích xuất từ dữ liệu giỏ hàng mô phỏng kiểm soát.

4. ❌ **"Độ tương đồng thành phần mỹ phẩm (Ingredient Similarity) tương đương với hiệu quả lâm sàng hoặc dược lý":**
   * *Lý do:* Trùng khớp chuỗi văn bản thành phần (textual cosine similarity) không tính đến nồng độ hoạt chất, công nghệ bào chế, tá dược dẫn truyền, độ pH hay xung đột hóa học thực tế.

5. ❌ **"Các trọng số gợi ý (Behavior, Profile, Rerank) đạt mức tối ưu toán học (Mathematically Optimal)":**
   * *Lý do:* Toàn bộ các hệ số ($0.35, 0.35, 0.20, 0.10$; $0.50 : 0.50$; $0.70 / 0.20 / 0.10$) là **Engineering Baselines / Heuristics** được thiết lập qua tinh chỉnh nghiệp vụ, không phải kết quả của quá trình học máy tối ưu hóa hàm mất mát (loss function optimization / hyperparameter grid search).

6. ❌ **"Hệ thống đã được kiểm chứng y khoa hoặc da liễu (Medically / Dermatologically Validated)":**
   * *Lý do:* Hệ thống cung cấp gợi ý thương mại điện tử dựa trên thông tin nhãn hàng và bảng khảo sát tự khai báo của người dùng. Không có quy trình thử nghiệm lâm sàng hoặc hội đồng bác sĩ da liễu xác nhận chẩn đoán y khoa.

7. ❌ **"Phân cụm K-Means đã sẵn sàng đưa vào vận hành (Production-Ready)":**
   * *Lý do:* Kết luận chính thức từ Step 7E là: *RESEARCH COMPLETE — NOT PRODUCTION VALIDATED*. K-Means chỉ có vai trò khám phá danh mục offline (exploratory catalog analysis) và phân khúc catalog, chưa có bằng chứng xác nhận tính khả thi hay hiệu quả thực tế trong candidate retrieval.

8. ❌ **"Trường so_luong_da_ban trong bảng sản phẩm phản ánh doanh số bán hàng thực tế":**
   * *Lý do:* Trường này chứa giá trị khởi tạo hiển thị ban đầu, không được đồng bộ tự động từ bảng chi tiết hóa đơn (`chi_tiet_hoa_don`). Doanh số thực tế chỉ được tính từ các đơn hàng có trạng thái hợp lệ.
