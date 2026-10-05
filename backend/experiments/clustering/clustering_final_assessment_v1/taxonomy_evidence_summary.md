# Tổng Hợp Chứng Cứ Về Phân Cấp Danh Mục (Taxonomy Evidence Summary)
## Phân Tích Thực Nghiệm Từ Step 7D Trên Toàn Bộ Catalog N = 2,473

Tài liệu này tổng hợp toàn diện vai trò, ưu điểm và các ranh giới học thuật cần tuân thủ khi đưa hệ thống phân cấp danh mục (`danh_muc`) vào mô hình phân cụm của SkinSyntaxVN.

---

### 1. Nguồn Sự Thật & Tính Toàn Vẹn Của Taxonomy
- **Dữ liệu thực tế từ MongoDB:**
  - Nguồn phân loại duy nhất là bảng `danh_muc` và khóa ngoại `san_pham.ma_danh_muc`.
  - Cấu trúc gồm **28 nút** được phân bổ thành:
    - **1 nút gốc cấp 1 (Root):** *Chăm Sóc Da Mặt*.
    - **7 nhánh cha cấp 2 (Parents):** *Mặt Nạ*, *Làm Sạch Da*, *Dưỡng Ẩm*, *Đặc Trị*, *Dưỡng Mắt*, *Dưỡng Môi*, và *Chăm Sóc Da Mặt (Direct)*.
    - **21 danh mục lá cấp 3 (Leaves):** Bao phủ từ các nhóm lớn (*Mặt Nạ Giấy*: 549 sp, *Sữa Rửa Mặt*: 285 sp) đến các nhóm nhỏ (*Sản Phẩm Đặc Trị Khác*: 2 sp, *Tẩy Tế Bào Chết Môi*: 3 sp).
  - Khám nghiệm toàn vẹn xác nhận: 0 chu trình phân cấp (`taxonomy_cycle_count = 0`), 0 danh mục mồ côi (`orphan_categories_count = 0`), 0 sản phẩm mất danh mục.
  - **Tỷ lệ bao phủ catalog:** Đạt **100%** trên toàn bộ $N=2,473$ sản phẩm hoạt động, mở rộng thành công gấp 2.46 lần so với tập con đóng băng 5 vai trò cũ ($N=1,004$).

---

### 2. Hai Cơ Chế Mã Hóa Danh Mục & Bản Chất Hình Học

1. **Mã hóa nút lá đơn lẻ (`CONFIG_TAX_LEAF` — 21 chiều one-hot):**
   - Mỗi sản phẩm được gán 1 bit tại danh mục lá tương ứng.
   - Khoảng cách giữa 2 sản phẩm cùng danh mục lá là 0; giữa 2 sản phẩm khác danh mục lá luôn là $\sqrt{2} \approx 1.4142$.
   - **Hạn chế:** Coi mọi danh mục khác nhau là xa như nhau; không phân biệt được *Sữa Rửa Mặt* và *Tẩy Trang* (cùng là Làm Sạch Da) gần nhau hơn so với *Son Dưỡng Môi*.
2. **Mã hóa đa điểm phân cấp (`CONFIG_TAX_HIER` — 28 chiều multi-hot):**
   - Bao gồm 21 chiều danh mục lá và 7 chiều nhánh cha cấp trên.
   - Hai sản phẩm cùng nhánh cha nhưng khác lá có khoảng cách ngắn hơn ($\sqrt{2}$ thay vì 2), phản ánh đúng cấu trúc ngữ nghĩa ngành hàng.
   - **Đính chính khoa học:** *Hierarchical encoding allows small leaf categories to retain their identity while sharing ancestor features* (Mã hóa phân cấp cho phép các danh mục lá rất nhỏ giữ nguyên định danh trong khi vẫn chia sẻ đặc trưng với tổ tiên, thay vì phải gộp ép buộc thô bạo vào database).

---

### 3. Diễn Giải Đúng Đắn Về Các Chỉ Số Phân Cụm Dựa Trên Taxonomy

#### A. Cảnh báo về Silhouette Score rất cao của các cấu hình TAX-only
- Trong bảng kết quả Step 7D:
  - `CONFIG_TAX_LEAF` đạt Silhouette = **0.7595** (Median = 1.0000).
  - `CONFIG_TAX_HIER` đạt Silhouette = **0.7896** (Median = 0.9169).
- **Ranh giới học thuật bắt buộc:**
  - Điểm Silhouette rất cao này thuần túy là **hệ quả hình học của biểu diễn rời rạc nhị phân (discrete binary geometry)**. Các điểm dữ liệu nằm trùng nhau tại các đỉnh của không gian siêu lập phương (hypercube vertices), tự nhiên tạo ra khoảng cách nội cụm cực nhỏ và khoảng cách liên cụm lớn.
  - **TUYỆT ĐỐI KHÔNG SỬ DỤNG ĐIỂM SILHOUETTE NÀY ĐỂ CHỨNG MINH RẰNG MÔ HÌNH CÓ "CHẤT LƯỢNG NGỮ NGHĨA CAO" HAY "TỐI ƯU".**
  - Khi thêm các đặc trưng liên tục (giá bán chuẩn hóa log, vector SVD thành phần 30D), không gian trở nên liên tục và phân tán hơn, làm giảm Silhouette score xuống mức 0.26 – 0.37. Biến động này phản ánh hình học đa chiều hỗn hợp, cần được xem xét cùng với độ ổn định, khả năng diễn giải và độ bao phủ.

#### B. Cảnh báo về Category Alignment (Purity)
- **Trích dẫn bắt buộc:**
  > *"Category alignment không được xem là external validation vì taxonomy được đưa trực tiếp vào feature representation."*
- Việc một cụm có 99% hay 100% sản phẩm cùng một nhánh cha (ví dụ Cụm 0 có 100% Dưỡng Môi, Cụm 5 có 100% Bộ Chăm Sóc Da Mặt) là điều hiển nhiên về mặt đại số tuyến tính vì các biến danh mục chiếm trọng số lớn trong vector đặc trưng.
