# Báo Cáo Kỹ Thuật Bộ Phân Tách Thành Phần V2 (Ingredient Parser V2)
## Step 7C.1: Cải Tiến Cấu Trúc, Xử Lý Ngoại Lệ & Bảo Toàn Ngữ Cảnh

### 1. Kiến Trúc Bộ Phân Tách V2
Bộ phân tách `ingredient_parser_v2.py` được xây dựng để khắc phục các sai sót cấu trúc nghiêm trọng trong V1:
1. **Bảo toàn số có dấu phẩy trong danh pháp IUPAC (`__NUMCOMMA__`):**
   - Ngăn chặn việc tách `1,2-hexanediol` thành `1` (bị loại) và `2-hexanediol`.
   - Kết quả: V2 khôi phục thành công 239 lần xuất hiện của `1_2_hexanediol` (thành phần dưỡng ẩm/dung môi phổ biến thứ 4 trong toàn bộ cơ sở dữ liệu), giảm triệt để lỗi phân đoạn của V1.
2. **Phân định dấu gạch chéo `/` theo ngữ cảnh INCI:**
   - Bảo toàn các danh pháp liên kết co-monomer, polymer hoặc hỗn hợp este: `caprylic/capric triglyceride`, `acrylates/c10-30 alkyl acrylate crosspolymer`, `dimethicone/vinyl dimethicone crosspolymer`, `flower/leaf/stem extract`. Các thực thể này được gán nhãn cờ `PRESERVED_COMPOUND`.
   - Chuẩn hóa các cặp từ đồng nghĩa đa ngữ có dấu gạch chéo: `aqua/water/eau` -> `water`, `parfum/fragrance` -> `fragrance`, loại bỏ các unigram rác.
   - Các dấu gạch chéo có khoảng trắng (` A / B `) được nhận diện chính xác là dấu phân cách thành phần độc lập thay vì bị ghép dính.
3. **Xử lý dấu ngoặc đơn `(...)` thông minh:**
   - Thay vì xóa mù quáng toàn bộ ngoặc đơn như V1 (`re.sub(r'\(.*?\)', '', t)`), V2:
     - Tách bỏ các thông số nồng độ định lượng: `(10%)`, `(1,000 ppm)`.
     - Chuyển đổi liên từ hỗn hợp thương mại `(and)` thành dấu phẩy `,` (ví dụ hỗn hợp `Polyacrylamide (and) C13-14 Isoparaffin (and) Laureth-7` được phân tách thành 3 thực thể độc lập thay vì bị gộp dính thành một chuỗi khổng lồ).
     - Thay thế dấu ngoặc bằng khoảng trắng để các tên thông thường / tên thực vật bên trong không bị dính vào từ liền kề.
4. **Hệ Thống Gắn Cờ Cấu Trúc (Structural Flags):**
   - Mỗi token được gắn cờ truy vết: `PARSED_STANDARD`, `PRESERVED_COMPOUND`, `RESOLVED_NUMERIC_COMMA`, hoặc `UNCERTAIN`.
   - Các đoạn văn bản mơ hồ (chứa ký tự lạ, độ dài > 6 từ do thiếu dấu phẩy trong crawling) **không bị âm thầm xóa bỏ** mà được lưu giữ và gắn cờ `UNCERTAIN`.

### 2. Các Lỗi Chưa Giải Quyết Triệt Để & Giới Hạn Cố Hữu
1. **Lỗi chính tả gốc từ nguồn crawling:** Nếu văn bản nhãn gốc viết sai (ví dụ thiếu dấu phẩy ngăn cách 2 chất, hoặc gõ sai ký tự), parser phân tách dựa trên quy tắc cấu trúc không thể tự động sửa mà chỉ có thể gắn cờ `UNCERTAIN`.
2. **Biến thể chiết xuất thực vật (Botanical Synonyms):** Chưa có từ điển INCI ngoài để chuẩn hóa đồng nghĩa giữa `centella asiatica extract` và `centella asiatica leaf extract`.
