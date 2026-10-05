# Đính Chính Học Thuật Sau Kiểm Định Step 7D (Step 7D Final Corrections)
## Nghiên Cứu Mở Rộng Phân Cụm Danh Mục Toàn Catalog (Taxonomy Expansion Audit)

Tài liệu này chuẩn hóa và hiệu đính các diễn giải học thuật từ Step 7D trước khi bước vào tổng kết Step 7E:

---

### 1. Không Gọi Bất Kỳ Cấu Hình Nào Là "Primary Candidate" Hay "Winner"
- **Đính chính:** Cấu hình `CONFIG_TAX_PRICE_SKIN_ING` không được định danh là "Primary Candidate" hay "Winner" chỉ vì tích hợp nhiều khối đặc trưng hơn.
- **Nguyên tắc học thuật:** Trong Step 7D, không có cấu hình chiến thắng tuyệt đối ("winner configuration"). Mỗi cấu hình phản ánh một giả thiết hình học và cấu trúc dữ liệu riêng biệt.

---

### 2. Diễn Giải Chính Xác Về Biến Động Silhouette Khi Thêm Biến Liên Tục
- **Cách diễn giải trước:** Silhouette giảm khi thêm continuous features "không phản ánh suy giảm chất lượng".
- **Đính chính chuẩn xác:**
  > *"Silhouette thay đổi phản ánh geometry khác nhau giữa các feature spaces và phải được xem cùng stability, interpretability, coverage và sensitivity metrics."*

---

### 3. Phân Biệt Khái Niệm Giữa Trùng Lặp Chuỗi Thành Phần Và Tương Đồng Hóa Học
- **Đính chính:** Không gọi parsed ingredient similarity là "chemical similarity" (tương đồng hóa học).
- **Thuật ngữ chuẩn hóa:**
  > Dùng **"parsed ingredient-term overlap"** (độ trùng lặp thuật ngữ thành phần đã phân tích cú pháp). Không suy diễn thuật ngữ thành phần phân tích được thành cấu trúc hóa học hoặc dược lý lâm sàng.

---

### 4. Diễn Giải Đúng Mực Về Phân Tích Độ Nhạy Gộp Biến Thể (Variant Collapsing)
- **Kết quả thực nghiệm:**
  - ARI = 0.5655
  - NMI = 0.7458
- **Đính chính:** Không mặc định gọi partition similarity là "high" (cao).
- **Diễn giải học thuật bắt buộc:**
  > *"Variant collapsing produced a material change in partition structure under this sensitivity analysis."*

---

### 5. Diễn Giải Về Cơ Chế Mã Hóa Phân Cấp Đối Với Danh Mục Rất Nhỏ
- **Cách diễn giải trước:** Không nói hierarchical encoding "loại bỏ hoàn toàn nhu cầu gộp category nhỏ".
- **Đính chính chuẩn xác:**
  > *"Hierarchical encoding allows small leaf categories to retain their identity while sharing ancestor features."*
