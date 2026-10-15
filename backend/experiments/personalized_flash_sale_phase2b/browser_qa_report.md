# BÁO CÁO KIỂM THỬ TRÌNH DUYỆT GIAO DIỆN ADMIN (BROWSER QA REPORT)

## 1. Phương thức và Môi trường Kiểm thử
- **Môi trường**: Google Chrome Headless thông qua giao thức Chrome DevTools Protocol (CDP) và máy chủ web PHP nội bộ.
- **Phạm vi kiểm thử**: Chỉ kiểm thử các trang quản trị Flash Sale trong khu vực Admin (`admin_flash_sales`, `admin_flash_sale_create`, `admin_flash_sale_edit`, `admin_flash_sale_detail`).
- **Độ phân giải kiểm thử**:
  1. **Desktop**: `1440 x 900` px.
  2. **Tablet**: `768 x 1024` px (chế độ dọc/dọc ngang máy tính bảng).

---

## 2. Kết quả Thực nghiệm Từng Thành phần Giao diện

### A. Trang Danh sách Chiến dịch (`index.php?r=admin_flash_sales`)
- **Desktop (1440x900)**:
  - Tiêu đề trang hiển thị chuẩn xác tiếng Việt: *"Quản lý Flash Sale"*.
  - Mục menu sidebar *"Flash Sale"* (icon `bi-lightning-charge-fill`, chú thích *"Chiến dịch & Giá sốc"*) hiển thị đúng vị trí trong nhóm *"VẬN HÀNH BÁN HÀNG"*.
  - Bảng danh sách chiến dịch hiển thị đầy đủ các cột: Mã, Tên, Bắt đầu, Kết thúc, Trạng thái, Số SP, Mức giảm, Thao tác.
  - Trạng thái chiến dịch hiển thị đúng badge màu sắc theo tiêu chuẩn:
    - `ACTIVE` $\to$ Badge xanh lá *"Đang diễn ra"*.
    - `UPCOMING` $\to$ Badge xanh lam *"Sắp diễn ra"*.
    - `ENDED` $\to$ Badge xám *"Đã kết thúc"*.
    - `DISABLED` $\to$ Badge đỏ *"Đã tắt"*.

### B. Form Tạo & Sửa Chiến dịch (`index.php?r=admin_flash_sale_create`)
- **Khuyến nghị số lượng mềm**:
  - Banner hiển thị rõ ràng: *"Khuyến nghị: 12–20 sản phẩm để đảm bảo đủ lựa chọn cho trải nghiệm cá nhân hóa."*
  - Bộ đếm sản phẩm tự động cập nhật số lượng và trạng thái: `Chưa đạt 12 SP` (vàng), `Số lượng tối ưu` (xanh lá), `Hơi nhiều SP` (lam).
- **Bộ chọn sản phẩm thông minh (Product Selector)**:
  - Không nạp toàn bộ 2,473 sản phẩm vào DOM.
  - Ô tìm kiếm hỗ trợ debounce 250ms: gõ `"serum"` kích hoạt AJAX gọi `api_admin_flash_sale_search_products` trả về 15 kết quả phù hợp nhất kèm ảnh, mã SP, giá niêm yết và thương hiệu.
  - Bấm chọn sản phẩm từ dropdown: Sản phẩm được chèn ngay vào bảng và khóa nút chọn để chống trùng lặp.
- **Định dạng tiền tệ và Live Discount Preview**:
  - Khi nhập số tiền (ví dụ: `150000`), Javascript tự động format hiển thị phân cách hàng nghìn thành `150.000 đ`.
  - Huy hiệu phần trăm giảm giá tự động tính toán tức thì (ví dụ: `-58%`) dựa trên giá niêm yết canonical của sản phẩm.
  - Nếu nhập giá $\ge$ giá niêm yết hoặc $\le 0$, hệ thống hiển thị thông báo lỗi trực tiếp dưới ô nhập.
- **Cảnh báo an toàn khi sửa chiến dịch Đang diễn ra**:
  - Khi mở chỉnh sửa một chiến dịch đang `ACTIVE`, form tự động kích hoạt banner cảnh báo màu vàng hổ phách: *"Chiến dịch đang diễn ra (ACTIVE)! Thay đổi giá hoặc sản phẩm có thể ảnh hưởng trực tiếp đến giỏ hàng và đơn giá thanh toán hiện tại của khách hàng."*

### C. Trang Chi tiết / Xem trước Chiến dịch (`index.php?r=admin_flash_sale_detail`)
- Hiển thị đầy đủ thông tin chiến dịch, mã ID, thời gian bắt đầu/kết thúc (theo giờ Việt Nam).
- **Đồng hồ đếm ngược**:
  - Hiển thị thời gian còn lại: `HH : MM : SS`.
  - Khi thời gian về 0, đồng hồ **tuyệt đối không tự reset** mà hiển thị trạng thái `Đã kết thúc`.
- Bảng Deal hiển thị đầy đủ: Ảnh sản phẩm, Mã SKU, Tên, Danh mục, Giá niêm yết (gạch ngang), Giá Flash Sale nổi bật, % giảm và Số tiền tiết kiệm.

### D. Kiểm tra Độ tương thích Tablet (`768 x 1024`)
- Kiểm tra tràn chiều ngang: `document.documentElement.scrollWidth <= document.documentElement.clientWidth`.
- Bảng sản phẩm tự động kích hoạt thanh cuộn ngang `table-responsive`, các nút thao tác không bị dồn hàng hoặc vỡ layout.

### E. Quy trình Chốt đơn thử nghiệm & Dọn dẹp dữ liệu (Controlled Test Cleanup)
- Chiến dịch kiểm thử `TEST_FLASHSALE_PHASE2B_BROWSER_QA` đã được tạo thành công qua form, xuất hiện trên danh sách, và sau đó được xóa sạch sẽ thông qua nút xóa trong giao diện Admin.
- Không còn bất kỳ chiến dịch test nào tồn đọng trên MongoDB Atlas sau buổi kiểm thử.
