# DANH SÁCH TẬP TIN THAY ĐỔI
## SkinSyntaxVN — Homepage Recommendation UX Phase 2

Các thay đổi được giới hạn ở mức tối thiểu tuyệt đối nhằm đảm bảo tính toàn vẹn hệ thống và an toàn dữ liệu:

---

### 1. `backend/app/models/SanPham.php`
- **Phương thức sửa:** `getHomepageProductSections(int $limitEach = 4, ...)`
- **Dòng sửa:** ~764–820
- **Mục đích:** Khử trùng lặp SKU giữa Section 3 (`forYou`) và Section 5.5 (`topRatedWeighted`) ở tầng trình diễn (presentation-level deduplication).
- **Chi tiết thay đổi:**
  - Lấy danh sách Simple Recommender pool với kích thước lớn hơn (`$limitEach * 4 = 16`).
  - Lấy danh sách ID đã được chọn cho `forYou` (`$forYouIds`).
  - Lọc bỏ các sản phẩm có ID trùng với `forYouIds` khi gán vào `topRatedWeighted`.
  - Trong trường hợp Cold-start (chưa đăng nhập, chưa có hành vi), `forYou` nhận Top 1–4, còn `topRatedWeighted` nhận Top 5–8 của Simple Recommender.
  - **Số lượng trùng lặp: Giảm từ 4/4 (100% trùng) xuống 0/4 (0% trùng).**
  - Giữ nguyên toàn bộ logic IMDb Weighted Rating ($m=21, C=4.8890$) và cơ chế cache JSON file / in-memory.

---

### 2. `frontend/views/home.php`
- **Các vị trí sửa đổi:**
  1. **Dòng 36–50:** Chuẩn hóa câu thoại SYNA Mascot, đổi thông điệp khảo sát từ "Làm khảo sát da 1 phút" thành "Khám phá làn da của bạn, SYNA tìm vài món phù hợp nhé!" nhằm tránh dark pattern giục giã.
  2. **Dòng 142–238 (`$renderHomeProductCard`):**
     - Xóa hoàn toàn fallback rating `4.9` và lượt đánh giá giả `128`.
     - Nếu sản phẩm có review thật (`rating > 0 && review_count > 0`): hiển thị số sao và số lượt thật `(N)`.
     - Nếu sản phẩm chưa có đánh giá: hiển thị biểu tượng sao rỗng và text `"Chưa có đánh giá"`.
     - Xóa hoàn toàn nhãn `"Đã khảo sát"` và liên kết `"Độ hợp ->"`.
     - Chỉ hiển thị nhãn độ hợp (`Khớp loại da`, `Mọi loại da`, `Trong ngân sách`) khi sản phẩm đến từ personalized context có grounded reason tags. Non-personalized cards (Flash Sale, Bảng xếp hạng, Mới lên kệ) không nhận tem hồ sơ.
     - Cập nhật alt text cho hình ảnh sản phẩm tránh chuỗi rỗng.
     - Tối ưu nút bấm hành động cho Mobile (< 576px): Nút "Mua ngay" được ẩn trên màn hình nhỏ (`d-none d-sm-block`), nút "Thêm vào giỏ" hiển thị full-width; màn hình lớn giữ nguyên 2 nút cạnh nhau.
  3. **Dòng 265–270:** Chuẩn hóa CTA trên Hero Banner từ "Khảo sát da 1 phút" thành "Khám phá làn da của bạn".
  4. **Dòng 480–573 (Section 3 For-You):**
     - Cập nhật bộ điều hướng tiêu đề động phản ánh chính xác 8 trạng thái recommendation evidence (SIMPLE, BEHAVIOR SEARCH, BEHAVIOR VIEW, BEHAVIOR CART, PURCHASE, PROFILE, HYBRID, PARTIAL_PROFILE_FALLBACK).
     - Không hiển thị bất kỳ thuật ngữ thuật toán tiếng Anh nào (TF-IDF, Cosine, vector, weight, ADAPTIVE_HYBRID,...).
     - Trích xuất nhãn thẻ ngắn gọn và có cơ sở từ `reason_tags`.
     - Dọn dẹp CTA khảo sát bị lặp, dùng câu thống nhất: "Hoàn thành khảo sát da để nhận gợi ý cá nhân hóa hơn &rarr;".
  5. **Dòng 704 & 727:** Nâng cấp thẻ tiêu đề của Section 5.5 và 5.6 từ `h3` lên `h2` đảm bảo cấu trúc ngữ nghĩa HTML hợp lệ.
  6. **Dòng 746–808 (Section 7 Routine):**
     - Việt hóa toàn diện tiêu đề và 4 bước chăm sóc da:
       - `DAILY SKINCARE REGIMEN` $\rightarrow$ `QUY TRÌNH CHĂM SÓC DA HẰNG NGÀY`
       - `STEP 1 / Cleanse` $\rightarrow$ `BƯỚC 1: Làm sạch`
       - `STEP 2 / Treat` $\rightarrow$ `BƯỚC 2: Đặc trị`
       - `STEP 3 / Hydrate` $\rightarrow$ `BƯỚC 3: Dưỡng ẩm`
       - `STEP 4 / Protect` $\rightarrow$ `BƯỚC 4: Bảo vệ`
     - Chuẩn hóa thông điệp empty state routine khi chưa làm khảo sát.
  7. **Dòng 833–836:** Chuẩn hóa nút khảo sát trong Final CTA Banner thành "Khám phá làn da của bạn".
