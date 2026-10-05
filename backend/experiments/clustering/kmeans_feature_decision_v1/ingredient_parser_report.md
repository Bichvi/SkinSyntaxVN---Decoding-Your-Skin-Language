# Báo Cáo Kiểm Toán Bộ Phân Tách Thành Phần (Ingredient Parser Audit)
## Step 7C: Phân Định Giữa Biểu Diễn Mảnh Từ (Word N-gram) và Thực Thể Đơn Vị (Entity-based)

### 1. Kết Quả Kiểm Toán Từ Vựng TF-IDF Step 7B (7,805 Thuật Ngữ)
Kiểm toán mẫu 100 thuật ngữ đại diện (50 thuật ngữ tần suất cao nhất + 50 thuật ngữ chọn mẫu ngẫu nhiên):
- **VALID_INGREDIENT (Thành phần hóa học độc lập hợp lệ):** 69%
- **CHEMICAL_FRAGMENT (Mảnh từ hóa học bị chia cắt):** 19%
- **GENERIC_WORD (Từ ngữ ngôn ngữ chung / bộ phận thực vật):** 12%
- **NOISE (Nhiễu kỹ thuật / mã số):** 0%
- **MALFORMED (Từ phân tách lỗi):** 0%

### 2. Phát Hiện Bản Chất Kỹ Thuật (Key Technical Insight)
- Trong Step 7B, bộ tách từ `TfidfVectorizer` phân tách theo ranh giới từ trắng (`\b[a-zA-Z\d_-]+\b`). Do đó, các hợp chất như `Sodium Hyaluronate` hoặc `Salicylic Acid` bị xé lẻ thành các unigram như `sodium`, `acid`.
- **Hệ quả hình học:** Một sản phẩm chứa `Citric Acid` (chất điều chỉnh độ pH) và một sản phẩm chứa `Salicylic Acid` (hoạt chất BHA bạt sừng trị mụn) bị gán chung một thuộc tính unigram `acid`. Điều này tạo ra sự tương đồng giả tạo (spurious correlation) giữa các sản phẩm không cùng cơ chế tác động.

### 3. Thiết Kế Bộ Phân Tách Thực Thể Hoạt Chất (Entity-based Parser - `I_INGREDIENT`)
- **Giả định chuẩn phân định (Delimiters):** Theo quy chuẩn danh pháp INCI quốc tế, các thực thể thành phần được ngăn cách bằng dấu phẩy `,`, chấm phẩy `;` hoặc dấu chấm tròn `•`. Các dấu gạch chéo `/` trong hợp chất liên kết (ví dụ `Caprylic/Capric Triglyceride`, `Acrylates/C10-30 Alkyl Acrylate Crosspolymer`) được bảo toàn thay vì bị xé nhỏ.
- **Ghép nối nguyên tử (Atomic Entity Token):** Các từ cấu thành một thực thể được nối bằng dấu gạch dưới `_` (ví dụ `salicylic_acid`, `sodium_hyaluronate`, `centella_asiatica_extract`). Nhờ vậy, ma trận TF-IDF đối xử với mỗi hóa chất như một chiều đặc trưng nguyên tử độc lập.

### 4. Giới Hạn & Các Trường Hợp Thất Bại (Parser Failure Cases)
- **Lỗi chính tả crawling:** Một số ít sản phẩm có tên thành phần bị dính liền do thiếu dấu phẩy trên nhãn gốc.
- **Thực thể thực vật đa dạng:** Các chiết xuất thực vật có nhiều cách ghi tương đương (ví dụ `scutellaria baicalensis extract` vs `scutellaria baicalensis root extract`) chưa được quy đổi về cùng một mã sinh học duy nhất (cần từ điển đối sánh ngoại vi).
