# Báo Cáo Tổng Kết Toàn Diện Nghiên Cứu Phân Cụm SkinSyntaxVN
## Step 7E: Đánh Giá Lựa Chọn Mô Hình & Xác Định Vai Trò Kiến Trúc Hệ Thống
### (Final Clustering Consolidation, Model Selection & System Role Assessment)

**Hệ thống nghiên cứu:** SkinSyntaxVN Machine Learning Research Track  
**Phạm vi tổng kết:** Toàn bộ chuỗi thực nghiệm Step 6A, Step 6B, Step 6C, Step 7A, Step 7B, Step 7C, Step 7C.1, Step 7D  
**Tập dữ liệu mở rộng tối đa:** $N = 2,473$ sản phẩm chăm sóc da mặt hoạt động  
**Cơ chế danh mục:** 28 nút phả hệ `danh_muc` MongoDB (21 danh mục lá, 7 nhánh cha)  
**Trạng thái kiểm định cuối cùng (Final Status):**  
### **RESEARCH COMPLETE — NOT PRODUCTION VALIDATED**

---

### BẮT BUỘC TRÍCH DẪN ĐỊNH DANH (MANDATORY ACADEMIC STATEMENTS)
> *"Step 7E tổng kết toàn bộ chuỗi nghiên cứu phân cụm K-Means từ Step 6A đến Step 7D. Các cụm phân hoạch phản ánh đặc tính hình học và cấu trúc dữ liệu của các không gian vector được lựa chọn; chúng không chứng minh chất lượng gợi ý (recommendation quality) hay tính tương đương lâm sàng (clinical equivalence) giữa các sản phẩm."*

> *"Category alignment không được xem là external validation vì taxonomy được đưa trực tiếp vào feature representation."*

> *"Variant collapsing produced a material change in partition structure under this sensitivity analysis (ARI = 0.5655, NMI = 0.7458 tại Step 7D)."*

---

### I. TRẢ LỜI TRỰC DIỆN 8 CÂU HỎI CỐT LÕI (REQUIRED FINAL QUESTIONS)

#### Q1. K-Means có hữu ích cho SkinSyntaxVN không?
- **Trả lời:** **CÓ, NHƯNG CÓ ĐIỀU KIỆN VÀ PHẠM VI NGHIÊM NGẶT.**
- K-Means hữu ích như một công cụ phân tích khám phá ngoại tuyến (offline exploratory analysis) và trích xuất cấu trúc hình học của catalog sản phẩm. Nó không phải là một giải pháp gợi ý độc lập.

#### Q2. K-Means hữu ích cho việc gì?
- **Trả lời:**
  1. *Phân đoạn và thấu hiểu cấu trúc danh mục sản phẩm (Exploratory Catalog Segmentation):* Nhận diện sự phân bố của 2,473 sản phẩm theo các tổ hợp danh mục, phân khúc giá và thuộc tính loại da.
  2. *Kiểm toán dữ liệu sàn (Catalog Health Auditing):* Phát hiện 364 dòng đa biến thể (898 sản phẩm), kiểm toán 27 sản phẩm khuyết thành phần, đo lường độ mất cân bằng danh mục (Gini = 0.5631).
  3. *Tiềm năng làm tầng lọc ứng viên thô hoặc kiểm soát độ đa dạng (Coarse Candidate Retrieval / Diversity Bucketing):* Có cơ sở hình học tốt nhưng cần quy trình kiểm định chuyên gia da liễu và chính sách xử lý biến thể trước khi vận hành.

#### Q3. Có nên dùng K-Means làm recommender chính không?
- **Trả lời:** **TUYỆT ĐỐI KHÔNG.**
- K-Means là thuật toán học không giám sát nhằm tối thiểu hóa phương sai nội cụm (WCSS). Nó không có hàm mục tiêu xếp hạng (ranking objective), không học sở thích người dùng. Nghiên cứu hiện tại chưa cung cấp bằng chứng cho thấy khoảng cách tới tâm cụm tương quan với độ thỏa dụng, sở thích hoặc xác suất mua hàng của người dùng. Việc dùng nhãn cụm làm điểm số gợi ý sẽ phá vỡ tính cá nhân hóa của SkinSyntaxVN.

#### Q4. Có nên tích hợp ngay vào production không?
- **Trả lời:** **KHÔNG.**
- Mô hình hiện tại **CHƯA ĐẠT CHUẨN SẴN SÀNG SẢN XUẤT (NOT PRODUCTION-READY)**. Toàn bộ các bước mới dừng ở mức kiểm định hình học toán học trên môi trường nghiên cứu; chưa có human relevance evaluation with a predefined protocol, chưa có chính sách xử lý biến thể sản phẩm, và chưa trải qua thử nghiệm A/B trên lưu lượng truy cập thực tế.

#### Q5. Nếu tích hợp trong tương lai, vai trò nào có cơ sở thực nghiệm tốt nhất?
- **Trả lời:**
  - **Vai trò được evidence hỗ trợ trực tiếp nhất hiện tại:** *Phân tích và phân đoạn catalog ngoại tuyến (Offline exploratory catalog analysis / catalog segmentation)* phục vụ kiểm toán chất lượng dữ liệu, phát hiện dòng biến thể và quản trị danh mục.
  - Các vai trò khác như *Candidate generation (sinh ứng viên)* và *Diversity control (kiểm soát độ đa dạng)* chỉ là **PLAUSIBLE BUT UNVALIDATED** (các vai trò tương lai hợp lý về mặt lý thuyết nhưng chưa được kiểm định thực nghiệm), không được gọi là “vai trò an toàn nhất đã được xác nhận”.

#### Q6. Representation nào đáng giữ làm research reference?
- **Trả lời:**
  - **`CONFIG_TAX_PRICE_SKIN_ING` (62 chiều)** trên toàn bộ catalog $N=2,473$ là biểu diễn toàn diện nhất đáng lưu giữ làm chuẩn tham chiếu nghiên cứu (Research Reference).
  - Biểu diễn này kết hợp hài hòa giữa cấu trúc phân cấp danh mục (`HIERARCHICAL_MULTI_HOT`), giá bán chuẩn hóa log, cờ loại da nhị phân và vector thành phần V2 SVD 30 chiều (tăng độ trùng lặp thuật ngữ thành phần láng giềng thêm +67.5% mà vẫn giữ tỷ lệ cùng danh mục lá 93.25%).
  - Bên cạnh đó, **`CONFIG_TAX_PRICE` (29 chiều)** là biểu diễn tham chiếu thứ hai cho các bài toán phân đoạn thuần túy theo ngành hàng và phân khúc giá.

#### Q7. Có K tối ưu chung không?
- **Trả lời:** **KHÔNG TỒN TẠI GIÁ TRỊ K TỐI ƯU PHỔ QUÁT (NO UNIVERSAL K ESTABLISHED).**
- Xuyên suốt Step 6B, 6C, 7A, 7B, 7C và 7D, các chỉ số nội tại (Silhouette, Elbow, DBI, CHI) liên tục đưa ra các gợi ý xung đột tùy thuộc vào không gian đặc trưng và mức độ chi tiết mong muốn. Các giá trị $K=6, 8, 10$ chỉ là các mốc khảo sát tham chiếu, không phải là chân lý toán học toàn cục.

#### Q8. Evidence nào còn thiếu?
- **Trả lời:**
  1. *Đánh giá mức độ liên quan từ con người theo quy trình định trước (Human relevance evaluation with a predefined protocol):* Chưa có kiểm định mức độ liên quan chuẩn mực từ người dùng hoặc người đánh giá độc lập. Đánh giá từ bác sĩ da liễu / chuyên gia chuyên môn (dermatologist/domain-expert review) chỉ cần thiết nếu hệ thống muốn đưa ra các khẳng định về mức độ phù hợp lâm sàng hoặc da liễu (dermatological/clinical suitability claims).
  2. *Thử nghiệm hành vi người dùng (User Online Evaluation):* Chưa có chỉ số CTR, Conversion Rate hay phản hồi thực tế từ người dùng.
  3. *Chính sách xử lý biến thể sản phẩm (Product-Family Deduplication Policy):* Chưa triển khai cơ chế gộp các kích cỡ dung tích khác nhau của cùng một sản phẩm.

---

### II. MA TRẬN ĐÁNH GIÁ CÁC KHÔNG GIAN BIỂU DIỄN ĐẶC TRƯNG

Bảng so sánh 9 không gian biểu diễn đã được thực nghiệm trong chuỗi nghiên cứu (trích từ [representation_decision_matrix.csv](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/clustering/clustering_final_assessment_v1/representation_decision_matrix.csv)):

| Tên Không Gian | Số Chiều | Độ Bao Phủ (N) | Silhouette (K=8) | Ổn Định Khởi Tạo (ARI) | Ổn Định Mẫu Con (ARI) | Mức Phụ Thuộc Taxonomy | Mức Phụ Thuộc Thành Phần | Độ Nhạy Biến Thể |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **M_9D** | 9 | 1,004 (40.6%) | 0.5180 | 0.8850 | 0.8420 | Ẩn (5 vai trò) | Không | Cao |
| **MI_STEP7C** | 39 | 1,004 (40.6%) | 0.4120 | 0.8520 | 0.8120 | Ẩn (5 vai trò) | Cao (Parser V1) | Cao |
| **MI_PARSED_V2** | 39 | 1,004 (40.6%) | 0.4150 | 0.8600 | 0.8150 | Ẩn (5 vai trò) | Cao (Parser V2) | Cao |
| **TAX_LEAF** | 21 | 2,473 (100%) | 0.7595 | 0.9285 | 0.8840 | Cực cao (21 lá) | Không | Trung bình |
| **TAX_HIER** | 28 | 2,473 (100%) | 0.7896 | 0.9150 | 0.8710 | Cực cao (28 nút) | Không | Trung bình |
| **TAX_PRICE** | 29 | 2,473 (100%) | 0.4966 | 0.8250 | 0.8200 | Cao (phân cấp) | Không | Cao (giá) |
| **TAX_PRICE_SKIN** | 32 | 2,473 (100%) | 0.3762 | 0.7709 | 0.8066 | Cao (phân cấp) | Không | Cao |
| **TAX_PRICE_SKIN_ING** | 62 | 2,473 (100%) | 0.2619 | 0.7923 | 0.8116 | Cân bằng | Cao (SVD 30D) | Thực chất (ARI=0.5655) |
| **BLOCK_NORMALIZED**| 62 | 2,473 (100%) | 0.1965 | 0.6544 | 0.6540 | Cân bằng L2 | Cân bằng L2 | Thực chất |

*Lưu ý học thuật:* Không áp dụng phép cộng điểm tổng hợp tùy tiện (arbitrary aggregate score) hay xếp hạng người chiến thắng (winner score) giữa các cấu hình vì mỗi cấu hình phục vụ một mục tiêu hình học và phân tích riêng.

---

### III. ĐÁNH GIÁ VAI TRÒ HỆ THỐNG CỦA PHÂN CỤM (ROLE ASSESSMENT MATRIX)

Đánh giá 7 vai trò tiềm năng theo 3 cấp độ chuẩn mực (trích từ [clustering_role_matrix.csv](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/clustering/clustering_final_assessment_v1/clustering_role_matrix.csv)):

1. **Exploratory catalog segmentation (Phân đoạn khám phá catalog):**
   - **Trạng thái:** **`SUPPORTED`**
   - **Chứng cứ:** Step 7D phân đoạn thành công 2,473 sản phẩm thành các nhóm trực quan theo danh mục và giá.
2. **Offline catalog analysis (Phân tích catalog ngoại tuyến):**
   - **Trạng thái:** **`SUPPORTED`**
   - **Chứng cứ:** Nhận diện chính xác 364 dòng biến thể, 27 sản phẩm khuyết thành phần, đo lường mất cân bằng Gini = 0.5631.
3. **Candidate generation (Tạo tập ứng viên thô):**
   - **Trạng thái:** **`PLAUSIBLE BUT UNVALIDATED`**
   - **Chứng cứ:** Sản phẩm cùng cụm có độ tương đồng ngữ cảnh cao (93.25% cùng lá), nhưng độ bao phủ và độ chính xác ứng viên chưa được kiểm định qua hành vi người dùng.
4. **Diversity control (Kiểm soát độ đa dạng gợi ý):**
   - **Trạng thái:** **`PLAUSIBLE BUT UNVALIDATED`**
   - **Chứng cứ:** Nhãn cụm có thể đóng vai trò các bucket đa dạng, nhưng nếu không xử lý biến thể thì các sản phẩm cùng dòng sẽ chiếm trọn danh sách.
5. **Similar-product retrieval (Truy xuất sản phẩm tương tự):**
   - **Trạng thái:** **`PLAUSIBLE BUT UNVALIDATED`**
   - **Chứng cứ:** Top-10 láng giềng tăng độ trùng lặp thuật ngữ thành phần +67.5%, nhưng cần human relevance evaluation with a predefined protocol trước khi hiển thị cho người dùng; đánh giá từ bác sĩ da liễu / chuyên gia chuyên môn (dermatologist/domain-expert review) chỉ cần thiết nếu hệ thống muốn đưa ra các khẳng định về mức độ phù hợp lâm sàng / da liễu.
6. **Direct recommendation ranking (Xếp hạng gợi ý trực tiếp):**
   - **Trạng thái:** **`NOT SUPPORTED BY CURRENT EVIDENCE`**
   - **Chứng cứ:** K-Means chỉ tối ưu hóa WCSS hình học, hoàn toàn không có hàm xếp hạng sở thích người dùng.
7. **User personalization (Cá nhân hóa người dùng):**
   - **Trạng thái:** **`NOT SUPPORTED BY CURRENT EVIDENCE`**
   - **Chứng cứ:** Chưa từng phân cụm hay mô hình hóa dữ liệu hành vi của người dùng trong nghiên cứu này.

---

### IV. BẢN ĐỒ RANH GIỚI KIẾN TRÚC & MỨC ĐỘ SẴN SÀNG SẢN XUẤT

- **Tách biệt nhiệm vụ giữa các thuật toán:**
  - **K-Means:** Nhóm sản phẩm ngoại tuyến theo đặc tính tĩnh của catalog (Unsupervised Product Grouping).
  - **Apriori / FP-Growth:** Khai phá sản phẩm thường mua cùng nhau (Frequently Bought Together) từ giỏ hàng.
  - **Collaborative Filtering:** Dự đoán sở thích người dùng từ ma trận tương tác lịch sử.
  - **Content-Based:** Khớp thuộc tính hoạt chất với hồ sơ loại da người dùng theo thời gian thực.
  - **Simple Weighted Rating:** Điểm chuẩn chất lượng toàn cục cho sản phẩm mới.
- **Trạng thái sản xuất:**
  - Toàn bộ module K-Means được phân loại rõ ràng: **CHƯA ĐƯỢC TÍCH HỢP VÀO PRODUCTION**.
  - Mã nguồn sản xuất PHP trong `api/`, `src/`, hệ thống Recommender hiện tại và giao diện người dùng Frontend UI được **BẢO VỆ NGUYÊN VẸN 100%**.

---

### V. KẾT LUẬN CUỐI CÙNG & CHỈ THỊ DỪNG (STOP DIRECTIVE)

1. **Trạng thái chính thức của toàn bộ nghiên cứu phân cụm:**
   ### **RESEARCH COMPLETE — NOT PRODUCTION VALIDATED**
   *(Nghiên cứu phân cụm K-Means cho danh mục sản phẩm SkinSyntaxVN đã hoàn tất giai đoạn nghiên cứu học thuật khám phá; mô hình có cơ sở hình học vững chắc nhưng KHÔNG ĐỦ ĐIỀU KIỆN ĐỂ TRIỂN KHAI TRỰC TIẾP LÊN PRODUCTION).*

2. **Tuân thủ quy chế dừng (STOP DIRECTIVE):**
   - **DỪNG NGAY sau Step 7E.**
   - **KHÔNG** triển khai K-Means vào production.
   - **KHÔNG** sửa đổi mã nguồn Recommender.
   - **KHÔNG** sửa đổi giao diện Frontend UI.
   - **KHÔNG** tự chạy Step 7F hay bất kỳ bước nghiên cứu nào tiếp theo.
   - **KHÔNG** tạo recommendation endpoint từ K-Means.
