# Phương Pháp Luận Kiểm Toán Nhãn Token & Đính Chính Giới Hạn
## Step 7C.1: Forensic Audit on Token Categorization & min_df Reality

### 1. Nguồn Gốc Phân Loại 100 Token Cũ Của Step 7C
Trong Step 7C, mẫu kiểm toán 100 thuật ngữ (50 top-IDF + 50 random) đã được báo cáo với các tỷ lệ:
- `VALID_INGREDIENT`: 69%
- `CHEMICAL_FRAGMENT`: 19%
- `GENERIC_WORD`: 12%
- `NOISE`: 0%
- `MALFORMED`: 0%

**Kết quả kiểm tra mã nguồn (`build_and_run_step7c.py`, lines 288-331):**
1. **Hoàn toàn dựa trên heuristic rule-based trong code:** Phân loại được thực hiện tự động bằng một script Python với các tập từ khóa cứng `chemical_fragments` (27 từ như `sodium`, `acid`, `glycol`...) và `generic_words` (24 từ như `leaf`, `extract`, `cho`...).
2. **Không có external validated dictionary:** Không sử dụng từ điển hóa học quốc tế (CosIng, PubChem, hay CIR).
3. **Không có human expert annotation độc lập:** Không qua hội đồng dược sĩ hay chuyên gia da liễu thẩm định.
4. **Quy tắc fallback tự động:** Bất kỳ cụm từ nào có >= 2 từ (`len(term_clean.split()) >= 2`) hoặc không nằm trong tập fragment đều mặc định rơi vào `else: cat = "VALID_INGREDIENT"`.
5. **Khả năng tái lập:** Code hoàn toàn có thể chạy lại với cùng seed 42, nhưng **về mặt khoa học, 69% không phải là Ground-Truth Accuracy** mà chỉ là kết quả của một bộ luật suy nghiệm nội bộ.

**Đính chính bắt buộc:**
- Tái định danh kết quả này thành **"Mẫu kiểm toán heuristic (Heuristic Audit Sample)"**.
- Ghi nhận rõ giới hạn: Tỷ lệ 69% phản ánh sự thiên lệch của quy tắc fallback phân tách chuỗi, không thể coi là tỷ lệ chính xác tuyệt đối của thực thể hóa học.

### 2. Bản Chất Kỹ Thuật Của min_df=2 (Không Phải Spell-Check)
Trong Step 7C có nhận định rằng `min_df=2` giúp loại bỏ lỗi chính tả. 
**Đính chính kỹ thuật bắt buộc:**
- `min_df=2` trong `TfidfVectorizer` **chỉ có một ý nghĩa toán học duy nhất:** Loại bỏ các parsed terms chỉ xuất hiện trong ít hơn 2 tài liệu (sản phẩm).
- `min_df=2` **KHÔNG PHẢI VÀ KHÔNG ĐƯỢC GỌI LÀ BỘ KIỂM TRA CHÍNH TẢ (Spell-Checker):**
  - Một lỗi chính tả hoặc lỗi crawling nếu lặp lại trên 2 sản phẩm (ví dụ do dùng chung một nguồn mô tả Shopee/Lazada) vẫn sẽ vượt qua `min_df=2` và đi vào từ vựng.
  - Ngược lại, một hoạt chất hóa học hoàn toàn hợp lệ, quý hiếm hoặc mang tính đột phá nhưng chỉ có trong đúng 1 sản phẩm duy nhất trong tập dữ liệu sẽ bị loại bỏ hoàn toàn.
