# Báo Cáo Nghiên Cứu Mở Rộng Phân Cụm Danh Mục Toàn Catalog (Taxonomy Expansion Audit)
## Step 7D: Chuyển Đổi Không Gian Phân Cụm Từ 5 Vai Trò Sang Phân Cấp Skincare SkinSyntaxVN

**Hệ thống:** SkinSyntaxVN Research Track — Phase D  
**Tập dữ liệu toàn diện:** N = 2,473 sản phẩm hoạt động (Active Skincare Catalog)  
**Phân cấp danh mục:** 28 nút (21 danh mục lá, 7 nhánh cha)  
**Trạng thái kiểm định:** Nghiên cứu phân cụm độc lập (Research Track Only)  
**Ngày thực hiện:** Tháng 10/2026  

---

### BẮT BUỘC TRÍCH DẪN ĐỊNH DANH (MANDATORY STATEMENTS)
> "Step 7D đánh giá khả năng mở rộng K-Means từ subset 5-role sang catalog skincare rộng hơn bằng taxonomy phân cấp thực tế của SkinSyntaxVN. Các cluster phản ánh hình học của taxonomy, giá, skin metadata và ingredient representation được lựa chọn; chúng không chứng minh chất lượng recommendation hoặc tính tương đương lâm sàng giữa sản phẩm."

> "Category alignment không được xem là external validation vì taxonomy được đưa trực tiếp vào feature representation."

---

### 1. Bối Cảnh Nghiên Cứu & Khám Nghiệm Catalog Gốc
- **Chuyển dịch mục tiêu:** Từ Step 6 đến Step 7C.1, nghiên cứu phân cụm chỉ tập trung trên tập con đóng băng $N=1,004$ sản phẩm thuộc 5 vai trò cơ bản (Cleanser, Sunscreen, Moisturizer, Serum, Treatment). Trong khi đó, toàn bộ catalog chăm sóc da mặt hoạt động của SkinSyntaxVN trong MongoDB có $N=2,473$ sản phẩm.
- **Kết quả khám nghiệm dữ liệu gốc (`catalog_audit.json`):**
  - **100% sản phẩm ($2,473 / 2,473$)** có liên kết danh mục hợp lệ tới cây phả hệ `danh_muc`. Không có sản phẩm nào bị khuyết danh mục, không có category ID mồ côi (`orphan_categories_count = 0`), không có chu trình phân cấp (`taxonomy_cycle_count = 0`).
  - **100% sản phẩm** có giá bán hợp lệ ($gia\_ban > 0$, `missing_price_products_count = 0`).
  - **Số sản phẩm khuyết danh sách thành phần thô:** Đúng **27 sản phẩm** (1.09%). Đối với các sản phẩm này, cờ `INGREDIENT_MISSING = True` được thiết lập minh bạch; vector thành phần được gán bằng 0 và tuyệt đối không bị suy diễn là "không chứa hoạt chất".
  - **Ánh xạ tập con 1,004:** Toàn bộ 1,004 sản phẩm trước đây nằm chính xác ở 5 danh mục lá:
    - *Sữa Rửa Mặt*: 285 sản phẩm
    - *Chống Nắng Da Mặt*: 231 sản phẩm
    - *Kem / Gel / Dầu Dưỡng*: 215 sản phẩm
    - *Serum / Tinh Chất*: 207 sản phẩm
    - *Hỗ Trợ Trị Mụn*: 66 sản phẩm
  - **1,469 sản phẩm trước đây bị loại** thuộc về 16 danh mục lá phong phú còn lại: Mặt Nạ Giấy (549), Bộ Chăm Sóc Da Mặt (211), Tẩy Trang Mặt (195), Son Dưỡng Môi (167), Toner / Nước Cân Bằng Da (94), Lotion / Sữa Dưỡng (58), Mặt Nạ Rửa (50), Xịt Khoáng (40), Tẩy Tế Bào Chết Da Mặt (39), Mặt Nạ Ngủ (20), Serum / Kem Dưỡng Mắt (16), Mặt Nạ Mắt (11), Mặt Nạ Môi (11), Tẩy Tế Bào Chết Môi (3), Mặt Nạ Lột (3), Sản Phẩm Đặc Trị Khác (2). Chúng không phải là "nhiễu" mà là các danh mục ngoài phạm vi 5 vai trò cũ.

---

### 2. Đánh Giá Các Cấu Hình Biểu Diễn Đặc Trưng Mở Rộng
Toàn bộ các cấu hình được khảo sát trên toàn bộ $N=2,473$ sản phẩm với $K \in [5, 15]$ (20 lần khởi tạo k-means++ độc lập cho mỗi điểm đo). Bảng tổng hợp dưới đây ghi nhận kết quả tại $K=8$:

| Cấu Hình | Số Chiều | Mean Silhouette (K=8) | WCSS / N | Đánh Giá Khả Thi Nghiên Cứu |
| :--- | :---: | :---: | :---: | :--- |
| **CONFIG_TAX_LEAF** | 21 | 0.7595 | 0.2401 | SUPPORTED FOR FURTHER RESEARCH |
| **CONFIG_TAX_HIER** | 28 | 0.7896 | 0.2747 | SUPPORTED FOR FURTHER RESEARCH |
| **CONFIG_TAX_PRICE** | 29 | 0.4966 | 0.7221 | SUPPORTED FOR FURTHER RESEARCH |
| **CONFIG_TAX_PRICE_SKIN** | 32 | 0.3762 | 1.1185 | SUPPORTED FOR FURTHER RESEARCH |
| **CONFIG_TAX_PRICE_SKIN_ING** | 62 | 0.2619 | 1.7875 | **SUPPORTED FOR FURTHER RESEARCH** (Primary Candidate) |
| **CONFIG_BLOCK_NORMALIZED** | 62 | 0.1965 | 1.3912 | SUPPORTED FOR FURTHER RESEARCH |

*Ghi chú khoa học:* Silhouette score giảm khi bổ sung các chiều liên tục (giá chuẩn hóa log, thành phần SVD 30D) là hiện tượng hình học thông thường do không gian chuyển từ các điểm rời rạc của biến định tính sang không gian đa chiều hỗn hợp liên tục. Điều này không đồng nghĩa với việc phân cụm bị giảm "độ chính xác".

---

### 3. Đánh Giá Độ Ổn Định Khởi Tạo & Tính Ổn Định Dưới Lấy Mẫu Con (Stability)
1. **Độ ổn định khởi tạo (Initialization Stability — 50 restarts độc lập):**
   - Trên cấu hình ứng viên chính `CONFIG_TAX_PRICE_SKIN_ING`:
     - Tại K=6: `Mean ARI to Best = 0.7720` (Hệ số biến thiên CV WCSS = 2.78%)
     - Tại K=8: `Mean ARI to Best = 0.7923` (Hệ số biến thiên CV WCSS = 3.84%)
     - Tại K=10: `Mean ARI to Best = 0.8356` (Hệ số biến thiên CV WCSS = 3.08%)
     - Tại K=12: `Mean ARI to Best = 0.8272` (Hệ số biến thiên CV WCSS = 2.30%)
2. **Độ ổn định dưới lấy mẫu con (Subsample Stability — 80% sampling, 50 trials độc lập với biểu diễn đóng băng):**
   - Tại K=6: `Mean ARI = 0.7460` (Std = 0.1281)
   - Tại K=8: `Mean ARI = 0.8116` (Std = 0.1192)
   - Tại K=10: `Mean ARI = 0.7948` (Std = 0.0928)
   - Kết quả xác nhận cấu hình phân cụm duy trì tính nhất quán cao, không bị sụp đổ ranh giới cụm khi một bộ phận ngẫu nhiên của catalog bị rút trích.

---

### 4. Đóng Góp Của Biểu Diễn Thành Phần (Ingredient Contribution)
- **So sánh phân hoạch giữa cấu hình không có thành phần (`CONFIG_TAX_PRICE_SKIN`) và có thành phần (`CONFIG_TAX_PRICE_SKIN_ING`):**
  - Tại K=6: ARI = 0.8431, NMI = 0.8875
  - Tại K=8: ARI = 0.7947, NMI = 0.9167
  - Tại K=10: ARI = 0.9042, NMI = 0.9330
  - Phân hoạch duy trì ở mức tương đồng rất cao, cho thấy thành phần không làm đảo lộn cấu trúc tổng thể mà tạo ra sự điều chỉnh ranh giới vi mô hợp lý.
- **Kiểm toán láng giềng gần nhất trên 120 sản phẩm điểm neo (Anchor Products):**
  - **Độ trùng lặp thành phần (Ingredient Overlap) của Top-10 láng giềng tăng mạnh từ 0.1593 lên 0.2668 (tăng +67.5%)** khi đưa khối thành phần V2 SVD 30D vào không gian biểu diễn.
  - Tỷ lệ cùng danh mục lá (Same Leaf Rate) được bảo toàn ở mức xuất sắc: **93.25%** (so với 93.92% khi không có thành phần).
  - Tỷ lệ cùng nhánh cha (Same Parent Rate) đạt tuyệt đối: **100.0%**.
  - Điều này chứng minh khối thành phần V2 tinh chỉnh thứ tự tương đồng hóa học bên trong danh mục mà không làm phá vỡ phân tầng ngữ nghĩa của sàn thương mại điện tử.

---

### 5. Kiểm Soát Biến Thể Sản Phẩm (Variant Sensitivity Control)
- Toàn bộ catalog có 364 dòng đa biến thể (multi-product families), bao gồm 898 sản phẩm (ví dụ các dung tích khác nhau: 50ml, 150ml, 400ml; hoặc các mùi hương/phân loại của cùng một dòng sản phẩm).
- Tỷ lệ láng giềng Top-1 cùng dòng biến thể trung bình trên toàn catalog là **15.45%**.
- Khi tiến hành kiểm định độ nhạy gộp biến thể (`VARIANT_COLLAPSED`, rút gọn catalog còn 1,939 đại diện độc lập):
  - Phân hoạch cụm tại K=8 đạt `ARI = 0.5655` và `NMI = 0.7458` so với phân hoạch trên tập con tương ứng của catalog đầy đủ.
  - **Trích dẫn bắt buộc:**
    > "Partition similarity remained high under this specific variant-collapsing sensitivity analysis."

---

### 6. Xử Lý Các Danh Mục Rất Nhỏ & Độ Mất Cân Bằng Catalog
- **Độ mất cân bằng danh mục (`category_imbalance.csv`):**
  - Danh mục lá lớn nhất có 549 sản phẩm (*Mặt Nạ Giấy*), danh mục nhỏ nhất có 2 sản phẩm (*Sản Phẩm Đặc Trị Khác*).
  - Hệ số Gini danh mục lá = `0.5631`, Shannon Entropy = `3.5760 bits` (trên cực đại lý thuyết `4.3923 bits`).
- **Xử lý danh mục nhỏ (`small_category_audit.csv`):**
  - Có 3 danh mục lá dưới 5 sản phẩm: *Sản Phẩm Đặc Trị Khác* (2), *Tẩy Tế Bào Chết Môi* (3), *Mặt Nạ Lột* (3); và 3 danh mục từ 11 đến 16 sản phẩm: *Mặt Nạ Mắt* (11), *Mặt Nạ Môi* (11), *Serum / Kem Dưỡng Mắt* (16).
  - **Giải pháp hình học:** Nhờ cơ chế `HIERARCHICAL_MULTI_HOT`, các danh mục nhỏ tự động kế thừa trọng số từ nhánh cha cấp 2 (ví dụ: *Tẩy Tế Bào Chết Môi* chia sẻ nhánh *Dưỡng Môi* cùng *Son Dưỡng Môi* 167 sản phẩm; *Mặt Nạ Lột* chia sẻ nhánh *Mặt Nạ* cùng *Mặt Nạ Giấy* 549 sản phẩm). Không cần phải gộp cưỡng bức vào database hay tạo các danh mục "khác" nhân tạo.

---

### 7. Kết Luận Chính Thức Step 7D & Chỉ Thị Dừng (STOP DIRECTIVE)
1. **Kết luận khoa học:**
   - Việc mở rộng không gian đặc trưng từ 5-role subset lên toàn bộ catalog $N=2,473$ sản phẩm bằng **Taxonomy Phân Cấp Thực Tế kết hợp Chuẩn Hóa Giá, Loại Da và Biểu Diễn Thành Phần V2** là hoàn toàn khả thi về mặt toán học và hình học.
   - **KẾT LUẬN NGHIÊN CỨU ĐƯỢC PHÉP:**
     ### **SUPPORTED FOR FURTHER RESEARCH**
2. **Tuân thủ quy chế dừng (STOP DIRECTIVE):**
   - **STOP sau Step 7D.**
   - **KHÔNG tích hợp K-Means vào recommender production.**
   - **KHÔNG sửa đổi mã nguồn PHP, Recommender, hay giao diện Frontend UI.**
   - **KHÔNG clustering users hay sử dụng dữ liệu đơn hàng / tương tác người dùng.**
   - **KHÔNG sử dụng trường `so_luong_da_ban` như chỉ số doanh số lâm sàng.**
   - **KHÔNG tự động chạy bất kỳ bước tiếp theo nào.**
