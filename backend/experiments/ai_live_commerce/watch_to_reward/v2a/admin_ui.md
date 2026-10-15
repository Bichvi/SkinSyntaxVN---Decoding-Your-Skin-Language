# ADMIN UI SPECIFICATION & INTEGRATION — PHASE V2A

**Route:** `http://localhost/index.php?r=admin_ai_idol&flow=publication`  
**View File:** `frontend/views/admin/ai_idol/index.php`  
**Script File:** `frontend/public/assets/js/ai-idol-studio.js`  
**Style File:** `frontend/public/assets/css/ai-idol-studio.css`  

---

## 1. Mục tiêu Giao diện Admin

Mở rộng bảng điều khiển xuất bản thương mại của AI Idol Studio (`flow=publication`) để cung cấp cho Admin tính năng **"Cấu hình voucher xem video"** (Watch-to-Reward Campaign) cho từng video sản phẩm Syna đã được kết xuất sẵn sàng và xuất bản công khai.

---

## 2. Các điểm mở rộng trên Giao diện

### 2.1. Thẻ Video Xuất Bản (`.pub-card`)
- **Vị trí:** Mỗi thẻ video trong lưới xuất bản `pubVideoGrid`.
- **Nút hành động:**
  - Đối với video đã xuất bản và đạt trạng thái `READY`:
    ```html
    <button type="button" class="idol-btn primary config-reward-btn" data-video-id="...">
        <i class="bi bi-gift me-1"></i> Cấu hình voucher xem video
    </button>
    ```
  - Nếu video chưa xuất bản hoặc đang render, nút này không xuất hiện hoặc bị vô hiệu hóa kèm thông báo rõ ràng.
- **Huy hiệu trạng thái chiến dịch:**
  - Nếu đã có chiến dịch voucher đang hoạt động: hiển thị huy hiệu xanh `Voucher: 15% (Đang chạy)`.
  - Nếu chiến dịch đang tạm dừng: hiển thị huy hiệu xám `Voucher (Tạm dừng)`.
- **Hiển thị số liệu phát hành thực tế (Strictly Real Data):**
  - Chỉ khi có số lượng voucher thực tế được phát hành (`issued_count > 0`), thẻ mới hiển thị thông tin:
    `Đã phát thực tế: X / Y`.
  - Khi chưa có lượt phát hành (`issued_count == 0`), hệ thống **tuyệt đối không hiển thị số liệu ảo** để giữ tính minh bạch.

---

### 2.2. Modal Cấu Hình Voucher Xem Video (`#rewardModal`)
Modal được thiết kế theo phong cách hiện đại của SkinSyntaxVN Studio với kích thước tối ưu (chiều rộng tối đa 680px), bao gồm:

1. **Header:**
   - Tiêu đề: *Cấu hình voucher xem video (Watch-to-Reward)*.
   - Nhãn định danh: *WATCH-TO-REWARD*.
   - Nút đóng modal góc trên bên phải.
2. **Khung thông tin Video tóm tắt:**
   - Ảnh poster video và thời lượng.
   - Tên chiến dịch AI Idol và tiêu đề video trên PDP.
   - Mã định danh video (`video_id`).
3. **Bảng dữ liệu thống kê phát hành thực tế (`#rewardStatsCard`):**
   - Chỉ xuất hiện khi chiến dịch đã được tạo trước đó trên hệ thống.
   - Bảng hiển thị 4 chỉ số thực:
     1. *Đã phát*: `issued_count`
     2. *Tổng hạn ngạch*: `total_quota`
     3. *Còn lại*: `total_quota - issued_count`
     4. *Giới hạn/User*: `user_limit`
   - Huy hiệu trạng thái: *Đang hoạt động* / *Đang tạm dừng*.
   - Mốc thời gian tạo và cập nhật gần nhất.
4. **Các trường cấu hình trong Form:**
   - **Tên chiến dịch ưu đãi:** Ô nhập chuỗi tối đa 150 ký tự.
   - **Loại giảm giá:** Dropdown chọn *Phần trăm (%)* hoặc *Số tiền cố định (VNĐ)*.
     - Khi chọn *Phần trăm*: ô mức giảm nhận giá trị từ 1 đến 100%; hiển thị trường *Mức giảm tối đa (VNĐ)*.
     - Khi chọn *Số tiền cố định*: ô mức giảm tự động chuyển sang đơn vị VNĐ, ẩn trường mức giảm tối đa.
   - **Đơn hàng tối thiểu (VNĐ):** Ô nhập số tiền tối thiểu của giỏ hàng (0 = không giới hạn).
   - **Ngày bắt đầu & Ngày kết thúc:** Hai ô nhập `datetime-local` với kiểm tra tự động mốc bắt đầu phải trước mốc kết thúc.
   - **Tổng hạn ngạch phát hành:** Ô nhập số lượng voucher tối đa được phát (không thể giảm dưới số lượng đã phát).
   - **Giới hạn nhận / khách hàng:** Ô nhập số lần nhận voucher (mặc định 1).
   - **Chính sách cộng dồn ưu đãi (Stacking Policies):**
     - Checkbox: *Cho phép áp dụng chung với Flash Sale* (mặc định tắt).
     - Checkbox: *Cho phép cộng dồn với voucher khuyến mãi khác* (mặc định tắt).
   - **Kích hoạt chiến dịch ngay:** Checkbox bật/tắt trạng thái `is_active` ngay khi lưu.
   - **Sản phẩm áp dụng voucher:**
     - Thanh tìm kiếm sản phẩm trực tiếp (tìm theo tên và mã sản phẩm trong kho).
     - Nút tiện ích *Chọn các sản phẩm của video* (tự động điền các sản phẩm gắn với video này).
     - Nút *Bỏ chọn hết*.
     - Hàng thẻ (chips) hiển thị trực quan các sản phẩm đang được chọn kèm nút xóa nhanh.
     - Danh sách cuộn chọn sản phẩm kèm hình ảnh, tên và giá bán.
5. **Footer:**
   - Nút **Tắt chiến dịch / Kích hoạt chiến dịch** (`#rewardToggleActiveBtn`): Cho phép chuyển đổi nhanh trạng thái bật/tắt mà không cần sửa form.
   - Nút **Đóng**.
   - Nút **Lưu cấu hình voucher** (`#rewardModalSubmitBtn`): Gửi payload lên server kèm xác thực CSRF.

---

## 3. Quy trình Trải nghiệm Người dùng (Admin Workflow)

1. Admin truy cập `http://localhost/index.php?r=admin_ai_idol&flow=publication`.
2. Admin tìm video mong muốn trong danh sách video đã xuất bản.
3. Bấm nút **Cấu hình voucher xem video**.
4. Hệ thống tải dữ liệu hiện có từ backend (`admin_ai_idol_reward_campaign`) và hiển thị lên modal.
5. Admin tùy chỉnh tỉ lệ giảm, thời hạn hiệu lực, sản phẩm áp dụng và hạn ngạch phát hành.
6. Admin bấm **Lưu cấu hình voucher**.
7. Modal thông báo xác nhận thành công, tự động đóng lại và cập nhật ngay lập tức giao diện thẻ video mà không cần tải lại toàn bộ trang web.
