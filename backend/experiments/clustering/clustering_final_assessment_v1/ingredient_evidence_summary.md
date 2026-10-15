# Tổng Hợp Chứng Cứ Về Biểu Diễn Thành Phần (Ingredient Evidence Summary)
## Phân Tích Thực Nghiệm Từ Step 7B, 7C, 7C.1 Và 7D

Tài liệu này tổng hợp toàn bộ các phát hiện và giới hạn thực nghiệm liên quan đến việc đưa thành phần sản phẩm vào không gian đặc trưng phân cụm của SkinSyntaxVN.

---

### 1. Phân Biệt Các Khái Niệm Cốt Lõi (Mandatory Conceptual Boundaries)
Nghiên cứu của SkinSyntaxVN thiết lập ranh giới học thuật rõ ràng giữa 4 khái niệm thường bị nhầm lẫn:

1. **Biểu diễn thành phần phân tích cú pháp (Parsed Ingredient Representation):**
   - Là kết quả xử lý chuỗi văn bản danh sách thành phần bằng Parser V2, tính toán trọng số TF-IDF (min_df=2, max_df=0.85) và giảm chiều bằng TruncatedSVD (30 chiều).
   - Đây thuần túy là một phép biến đổi toán học trên dữ liệu văn bản (vector space model).
2. **Bản thể học hóa học (Chemical Ontology) $\neq$ Parsed Representation:**
   - Parser V2 không chứa cây phân loại hóa học (ví dụ: họ lipid, hợp chất phenolic, este mạch ngắn). Nó chỉ phân tách các chuỗi từ và cụm danh từ.
3. **Tính tương đương lâm sàng (Clinical Equivalence) $\neq$ Chemical Similarity:**
   - Việc hai sản phẩm có độ trùng lặp thành phần cao không chứng minh rằng chúng có tác dụng sinh học hoặc hiệu quả điều trị da liễu giống nhau trên da người dùng (bỏ qua nồng độ hoạt chất, công nghệ bọc, pH của nền).
4. **Mức độ liên quan gợi ý (Recommendation Relevance) $\neq$ Cluster Membership:**
   - Hai sản phẩm nằm cùng cụm hình học không đồng nghĩa với việc sản phẩm này là sự thay thế hoàn hảo hoặc đề xuất phù hợp cho người dùng.

---

### 2. Các Bằng Chứng Thực Nghiệm Đã Xác Minh

#### A. Sửa lỗi cấu trúc cú pháp (Structural Parsing Improvements — Step 7C.1)
- **Vấn đề của Parser V1:** Cắt chuỗi theo ký tự gạch chéo (`/`) làm gãy các hóa chất hỗn hợp hoặc dung môi phổ biến như `propanediol / butylene glycol` thành các token lỗi (`diol`).
- **Thực nghiệm Parser V2:** Áp dụng biểu thức chính quy nhận diện danh pháp hóa học, xử lý dấu phân cách ngữ cảnh và dấu ngoặc đơn. Trong các pattern lỗi được kiểm toán tại Step 7C.1, Parser V2 không còn ghi nhận split error tương ứng.
- **Tác động lân cận:** So sánh Top-10 láng giềng giữa V1 và V2 trên tập $N=1,004$ đạt `Mean Jaccard@10 = 0.8140`. Con số này phản ánh mức độ trùng lặp cao giữa hai bộ láng giềng nhưng có sự điều chỉnh cục bộ do sửa lỗi token.

#### B. Thay đổi hình học cụm và phân hóa công thức (Geometric Shift & Formula Differentiation)
- **Tác động lên phân hoạch vĩ mô:**
  - Tại Step 7D trên toàn bộ catalog $N=2,473$: So sánh giữa cấu hình không có thành phần (`CONFIG_TAX_PRICE_SKIN`) và có thành phần (`CONFIG_TAX_PRICE_SKIN_ING`) tại $K=8$ đạt:
    - **`ARI = 0.7947`**
    - **`NMI = 0.9167`**
  - Điều này chứng minh khối thành phần không phá vỡ cấu trúc danh mục vĩ mô mà hoạt động như một lực kéo vi mô, tái phân bổ ranh giới cụm để phân hóa các công thức khác nhau trong cùng danh mục lá.
- **Tác động lên hành vi láng giềng (Top-10 Nearest Neighbors):**
  - Trên 120 Anchor Products khảo sát tại Step 7D:
    - **Độ trùng lặp thuật ngữ thành phần (`parsed ingredient-term overlap`):** Tăng mạnh từ **`0.1593`** (khi không có thành phần) lên **`0.2668`** (khi có thành phần V2 SVD 30D), tương ứng mức tăng **`+67.5%`**.
    - **Tỷ lệ cùng danh mục lá (Same Leaf Rate):** Đạt **`93.25%`** (so với 93.92% khi không có thành phần).
    - **Tỷ lệ cùng nhánh cha (Same Parent Rate):** Đạt tuyệt đối **`100.0%`**.

#### C. Xử lý sản phẩm khuyết thành phần (Missing Ingredients Handling)
- Trong toàn bộ $N=2,473$ sản phẩm, có đúng **27 sản phẩm (1.09%)** không có thông tin thành phần thô.
- Cờ `INGREDIENT_MISSING = True` được bật công khai; vector thành phần được gán bằng vector không ($0$). Việc gán vector 0 thuần túy là giải pháp kỹ thuật bảo toàn kích thước ma trận, **tuyệt đối không được diễn giải là sản phẩm không chứa hoạt chất**.

---

### 3. Những Gì Thành Phần Chưa Thể Chứng Minh
1. **Chưa chứng minh cải thiện chất lượng gợi ý:** Chưa có A/B test hoặc đánh giá người dùng (online user study) để khẳng định gợi ý dựa trên thành phần làm tăng tỷ lệ chuyển đổi hoặc độ hài lòng.
2. **Chưa phản ánh nồng độ hoạt chất:** Danh sách thành phần theo quy định quốc tế chỉ xếp theo thứ tự giảm dần nồng độ (xuống đến 1%), không cung cấp tỷ lệ phần trăm chính xác (ví dụ: Niacinamide 2% vs 10% có vector gần như tương đương).
3. **Chưa có bản thể học dược lý:** Các hoạt chất đồng vận (synergistic) hoặc đối kháng (antagonistic) chưa được mô hình hóa trong không gian SVD.
