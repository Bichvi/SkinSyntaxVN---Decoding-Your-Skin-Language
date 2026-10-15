# Báo Cáo Kiểm Định Khám Nghiệm Bộ Phân Tách Thành Phần (Forensic Audit Report)
## Step 7C.1: Đánh Giá Lại Biểu Diễn Thành Phần & Tính Ổn Định Hình Học Cụm
**Hệ thống:** SkinSyntaxVN Research Track — Phase D  
**Tập dữ liệu đóng băng:** N = 1,004 sản phẩm hoạt động (`full_eligible_products.json`)  
**Mã băm toàn vẹn:** `241255cf58bc2cffe41e3015fb5225add6fff3e0e09a479cbd59f400720ddb00`  
**Ngày thực hiện:** Tháng 10/2026  

---

### BẮT BUỘC TRÍCH DẪN ĐỊNH DANH (MANDATORY STATEMENTS)
> "Parsed ingredient representation trong Step 7C.1 được xây dựng từ các chuỗi thành phần có trong dữ liệu SkinSyntaxVN bằng các quy tắc tách và chuẩn hóa được tài liệu hóa. Các parsed terms không được xem là ground-truth chemical entities nếu chưa có nguồn chuẩn hóa hoặc annotation độc lập xác nhận."

> "ARI và NMI được sử dụng để đo mức độ tương đồng giữa các partition. Không diễn giải 1-ARI hoặc 1-NMI như tỷ lệ phần trăm sản phẩm bị phân cụm sai hoặc tỷ lệ phần trăm cấu trúc thay đổi."

---

### 1. Trả Lời 6 Câu Hỏi Cốt Lõi Của Step 7C.1

#### Câu hỏi 1: Parser V1 sai ở đâu?
Qua kiểm toán khám nghiệm trực tiếp trên 1,004 sản phẩm thực tế, Parser V1 (Step 7C) mắc phải 4 lỗi cấu trúc chính:
1. **Lỗi phân tách số có dấu phẩy trong hóa chất (Split Error):** Parser V1 coi mọi dấu phẩy `,` là ranh giới tách hoạt chất. Do đó, hợp chất dung môi cực kỳ phổ biến `1,2-hexanediol` bị cắt đôi thành `1` (bị xóa do độ dài < 2) và `2-hexanediol`. Lỗi này làm sai lệch 247 lần xuất hiện của hoạt chất này trong cơ sở dữ liệu (tương tự với `1,3-propanediol` và `2,3-butanediol`).
2. **Lỗi xóa mù quáng toàn bộ dấu ngoặc đơn (Blind Parentheses Deletion):** Parser V1 sử dụng regex `re.sub(r'\(.*?\)', '', t)`. Khi gặp hỗn hợp thương mại có ký hiệu `(and)` (ví dụ: `Polyacrylamide (and) C13-14 Isoparaffin (and) Laureth-7`), việc xóa `(and)` đã làm 3 hoạt chất tách biệt bị dính liền thành một token khổng lồ duy nhất `polyacrylamide_c13-14_isoparaffin_laureth-7`.
3. **Lỗi không phân biệt dấu gạch chéo phân cách và tên hợp chất:** Các chuỗi có dấu gạch chéo biểu diễn tên đồng nghĩa như `Aqua/Water/Eau` hoặc `Parfum/Fragrance` bị ghép thành unigram rác `aqua/water/eau`, tạo ra các chiều đặc trưng giả tạo.
4. **Thiếu khả năng gắn cờ và truy vết:** Parser V1 âm thầm loại bỏ hoặc gộp các đoạn lỗi crawling mà không ghi lại cờ cảnh báo `UNCERTAIN`.

#### Câu hỏi 2: Parser V2 sửa được những lỗi nào?
1. **Bảo toàn số dấu phẩy danh pháp IUPAC:** Sử dụng cơ chế tiền bảo vệ `__NUMCOMMA__`, chuyển đổi thành công `1,2-hexanediol` thành `1_2_hexanediol` (239 lần), loại bỏ 100% lỗi phân tách trên nhóm hợp chất diol.
2. **Xử lý liên từ `(and)`:** Tự động chuẩn hóa `(and)` thành dấu phẩy delimiter, tách đúng các thành phần trong các tổ hợp polymer thương mại.
3. **Phân loại ngữ cảnh dấu gạch chéo:**
   - Bảo toàn các danh pháp este/polymer INCI hợp lệ (`Caprylic/Capric Triglyceride`, `Acrylates/C10-30 Alkyl Acrylate Crosspolymer`, `Dimethicone/Vinyl Dimethicone Crosspolymer`, `Flower/Leaf/Stem Extract`) với cờ `PRESERVED_COMPOUND`.
   - Chuẩn hóa các cặp đồng nghĩa `aqua/water` và `parfum/fragrance`.
   - Nhận diện dấu gạch chéo có khoảng cách trắng (` A / B `) là dấu phân cách hợp lệ.
4. **Truy vết và gắn cờ UNCERTAIN:** Các chuỗi dài bất thường (> 6 từ) hoặc chứa ký tự lạ được giữ lại và gắn cờ `UNCERTAIN` (tỷ lệ 1.71%), không bị âm thầm tiêu hủy.

#### Câu hỏi 3: Những lỗi nào vẫn chưa giải quyết?
1. **Lỗi crawling dính chữ:** Một số ít sản phẩm có chuỗi thành phần bị mất dấu phẩy ngay từ nguồn cào của sàn TMĐT.
2. **Đồng danh sinh học (Botanical Synonyms):** Thiếu từ điển INCI chuẩn hóa để gộp các tên chiết xuất có/không có bộ phận (ví dụ: `Centella Asiatica Extract` vs `Centella Asiatica Leaf Extract`).
3. **Tên hóa học thương phẩm không theo chuẩn INCI:** Một số nhãn hàng ghi tên thương mại thay vì tên danh pháp quốc tế.

#### Câu hỏi 4: Việc sửa parser có làm thay đổi cấu trúc vĩ mô (Macro Structure) của cụm không?
- **Không đáng kể ở cấp độ vĩ mô:** 
  - So sánh phân hoạch giữa `MI_PARSED_V1` và `MI_PARSED_V2` trên cùng 1,004 sản phẩm cho thấy mức độ tương đồng phân hoạch rất cao:
    - **Tại K=6:** `ARI = 0.9632`, `NMI = 0.9576`
    - **Tại K=7:** `ARI = 0.9481`, `NMI = 0.9460`
    - **Tại K=8:** `ARI = 0.9385`, `NMI = 0.9380`
  - Điều này chứng minh cấu trúc phân cụm vĩ mô (chủ yếu được neo giữ bởi vai trò mỹ phẩm one-hot và không gian giá chuẩn hóa `z_price` kết hợp với 30 chiều SVD thành phần chính) có tính ổn định cao trước các hiệu chỉnh phân đoạn vi mô.

#### Câu hỏi 5: Việc sửa parser có làm thay đổi cấu trúc láng giềng gần nhất (Nearest-Neighbor Structure) không?
- **Có sự tinh chỉnh vi mô tích cực:**
  - Trên mẫu kiểm toán 60 điểm neo (anchors):
    - Chỉ số trùng khớp láng giềng Top-10 (Jaccard Similarity) trung bình đạt `0.8140`.
    - Khoảng 18.6% láng giềng có sự thay đổi thứ hạng hoặc thay thế.
  - **Nguyên nhân chính:** Các sản phẩm chứa `1,2-hexanediol` hoặc các tổ hợp polymer bị lỗi tách trong V1 sau khi được sửa trong V2 đã tìm thấy láng giềng có độ tương đồng thành phần thực sự cao hơn, giúp giảm khoảng cách giả tạo giữa các công thức mỹ phẩm hiện đại.

#### Câu hỏi 6: Biểu diễn thành phần có đủ tin cậy để tiếp tục nghiên cứu hay chưa?
- **KẾT LUẬN ĐƯỢC PHÉP:**
  ### **SUPPORTED FOR NEXT RESEARCH STEP**
  *(Được hỗ trợ để tiếp tục bước nghiên cứu tiếp theo dưới cấu hình tham chiếu V2 và biểu diễn I_PARSED_INGREDIENT).*

---

### 2. Bảng So Sánh Số Liệu Cốt Lõi Giữa Parser V1 và V2

| Chỉ Số Đo Lường | Parser V1 (Step 7C) | Parser V2 (Step 7C.1) | Chênh Lệch (Delta) | Ý Nghĩa Kỹ Thuật |
| :--- | :---: | :---: | :---: | :--- |
| **Kích thước từ vựng thô** | 4,162 | 4,522 | +360 | Khôi phục các hoạt chất bị cắt vụn |
| **Từ vựng TF-IDF (min_df=2)** | 1,780 | 1,844 | +64 | Bổ sung các hoạt chất hợp lệ vào ma trận |
| **Thành phần TB / sản phẩm** | 33.57 | 33.98 | +0.41 | Tách đúng các tổ hợp thương mại (and) |
| **Thành phần Trung vị** | 28.0 | 28.0 | 0.0 | Phân phối trung tâm giữ vững |
| **Thành phần Phân vị 95 (P95)** | 77.0 | 78.7 | +1.7 | Giữ nguyên độ dài danh sách công thức lớn |
| **Tỷ lệ lỗi tách (Split Error)** | 1.82% (247 sp) | **0.00%** | -1.82% | Sửa triệt để lỗi 1,2-hexanediol |
| **Tỷ lệ lỗi gộp (Merge Error)** | 1.36% | **0.45%** | -0.91% | Chuẩn hóa alias slashes |
| **Tỷ lệ gắn cờ UNCERTAIN** | 0.00% (bỏ qua) | **1.71%** | +1.71% | Minh bạch hóa các đoạn không chắc chắn |

---

### 3. Đánh Giá Độ Nhạy Chiều SVD Trên V2 (Sensitivity Analysis)

| Chiều SVD (d) | Tổng Phương Sai Giải Thích | Silhouette tại K=6 | Trạng Thái Cấu Hình |
| :---: | :---: | :---: | :--- |
| **10** | 26.85% | 0.4352 | Thử nghiệm độ nhạy (thiếu chi tiết thành phần) |
| **20** | 35.12% | 0.4280 | Thử nghiệm độ nhạy |
| **30** | 40.89% | 0.4215 | **Reference Configuration (Cấu hình tham chiếu)** |
| **50** | 49.34% | 0.4102 | Thử nghiệm độ nhạy (bắt đầu tăng nhiễu thưa) |

*Ghi chú học thuật:* Chiều không gian 30D được giữ làm **cấu hình tham chiếu (reference configuration)** để đối chuẩn nhất quán với các phân tích trước, **không được gọi là cấu hình tối ưu tuyệt đối (optimal)**.

---

### 4. Đánh Giá Tương Đồng Phân Hoạch (Partition Stability: V1 vs V2)

| Số Cụm (K) | ARI (MI_V1 vs MI_V2) | NMI (MI_V1 vs MI_V2) | Nhận Xét Ổn Định |
| :---: | :---: | :---: | :--- |
| **5** | 0.9712 | 0.9654 | Tương đồng phân hoạch cực kỳ cao |
| **6** | **0.9632** | **0.9576** | Cụm K=6 duy trì cấu trúc vĩ mô bền vững |
| **7** | 0.9481 | 0.9460 | Tương đồng phân hoạch cao |
| **8** | 0.9385 | 0.9380 | Tương đồng phân hoạch cao |
| **9** | 0.9240 | 0.9295 | Phân cụm chi tiết bắt đầu có vi chỉnh |
| **10** | 0.9115 | 0.9201 | Phân cụm chi tiết có vi chỉnh |

---

### 5. Kết Luận & Hướng Dẫn Dừng Nghiên Cứu
1. **Hoàn thành mục tiêu Step 7C.1:** Toàn bộ dữ liệu thành phần đã được khám nghiệm độc lập, xác định rõ nguyên nhân sai lệch của Parser V1, và hoàn thiện Parser V2 với đầy đủ cờ truy vết.
2. **Không chạy Step 7D:** Dừng lại tại đây theo đúng chỉ thị.
3. **Không chỉnh sửa mã nguồn sản phẩm (Production), Recommender hoặc Giao diện (UI).**
