# AUDIT — PHASE V2A: VIDEO REWARD CAMPAIGN INFRASTRUCTURE

**SkinSyntaxVN AI Live Commerce — Watch-to-Reward System**  
**Role:** Senior PHP/MongoDB E-commerce & Security Engineer  
**Date:** October 13, 2026  
**Status:** FULL PASS  

---

## 1. Mục tiêu Audit

Trước khi thiết kế và triển khai Phase V2A (Video Reward Campaign Admin), toàn bộ cơ sở hạ tầng e-commerce hiện hữu về Voucher/Coupon, Khách hàng, Video Publication và Bảo mật đã được kiểm toán toàn diện để tránh tạo trùng lặp hoặc xung đột kiến trúc.

---

## 2. Kết quả kiểm toán cơ sở hạ tầng hiện có

### 2.1. Voucher Collection & Model (`Voucher.php`)
- **Tên collection MongoDB:** `voucher` (dạng số ít).
- **Cấu trúc trường dữ liệu:**
  - `ma_voucher`: `int` (tự tăng qua `getNextNumericId`).
  - `ma_code`: `string` (chuỗi mã ký tự in hoa chuẩn hóa, ví dụ: `WELCOME10`, `SYNA15`).
  - `ten_voucher`: `string` (tiêu đề voucher).
  - `loai_giam`: `'fixed'` (tiền mặt VNĐ) hoặc `'percent'` (phần trăm).
  - `gia_tri_giam`: `int` (số tiền hoặc phần trăm chiết khấu).
  - `gia_tri_don_toi_thieu`: `int` (giá trị giỏ hàng tối thiểu).
  - `giam_toi_da`: `int|null` (giới hạn trần chiết khấu cho voucher phần trăm).
  - `so_luong`: `int|null` (tổng số lượng phát hành).
  - `so_luong_da_dung`: `int` (số lượt đã được khách hàng áp dụng trong hóa đơn thanh toán).
  - `ngay_bat_dau`, `ngay_ket_thuc`: `UTCDateTime` (mốc hiệu lực thời gian).
  - `trang_thai`: `'active'` | `'inactive'`.
- **Logic Checkout & Redemption (`HoaDon.php`, `gio_hang.php`, `thanh_toan.php`):**
  - Voucher được áp dụng thông qua `ma_code`.
  - Hệ thống tính toán chiết khấu trên tổng tiền đơn hàng sau khi kiểm tra điều kiện `gia_tri_don_toi_thieu` và ngày hiệu lực.
- **Quyết định kiến trúc cho Phase V2A:**
  - Phase V2A **KHÔNG** sao chép hoặc tạo bảng voucher song song.
  - V2A tạo một collection mẫu chiến dịch thưởng riêng biệt: `ai_video_reward_campaigns`.
  - Chiến dịch này định nghĩa khuôn mẫu chính sách quà tặng gắn với video AI (tỉ lệ giảm, trần giảm, điều kiện, hạn ngạch).
  - Quá trình chuyển đổi từ phần thưởng sang mã voucher trong collection `voucher` được bảo lưu nghiêm ngặt cho Phase V2B (User Watch Tracking & Issuance), tuyệt đối không tự động phát hành trong V2A.

### 2.2. AI Product Video Publication Schema (`ProductVideo.php`)
- **Tên collection MongoDB:** `ai_product_videos`.
- **Cấu trúc trường dữ liệu:**
  - `_id`: `ObjectId` (khóa định danh duy nhất của publication).
  - `video_id`: `string` (hex 24 ký tự tham chiếu video kết xuất từ `ai_idol_videos`).
  - `title`: `string` (tiêu đề hiển thị trên PDP).
  - `is_published`: `boolean` (trạng thái hiển thị công khai).
  - `associated_product_ids`: `array<string>` (danh sách ID sản phẩm được gắn video).
  - `primary_product_id`: `string` (sản phẩm chính).
  - `display_priority`: `int` (độ ưu tiên hiển thị).
  - `render_status`: `string` (ví dụ: `READY`).
  - `duration`, `format`, `resolution`, `avatar_mode`: thông số kỹ thuật video.
- **Baseline Publication thực tế:**
  - ID: `6acc6bd54e1a7ffa0e0672b2`
  - Video ID: `6acc27e526e9d8acf59c457e`
  - Title: `Syna 3D - Giới thiệu Combo Nước Tẩy Trang L'Oreal`
  - Sản phẩm liên kết: `["6376"]`
  - Trạng thái: `is_published: true`, video đã render hoàn chỉnh.

### 2.3. Customer Account Identity
- **Collection MongoDB:** `khach_hang`.
- **Định danh khách hàng:** `ma_kh` (`int`) và `email`.
- **Session Auth:** `$_SESSION['user']` lưu `id`, `role`, `email`.
- **Quyền Admin:** `$_SESSION['user']['role'] === 'admin'` hoặc `is_admin === 1`.
- **Bảo mật phân quyền:** Bắt buộc đăng nhập với vai trò `admin` cho mọi thao tác ghi/cập nhật cấu hình voucher xem video. Khách vãng lai và tài khoản thường bị chặn ngay lập tức (HTTP 302/403).

### 2.4. CSRF & RBAC Conventions
- **Controller Quản trị AI Idol:** `AdminAiIdolController.php`.
- **CSRF Token:** Được quản lý qua `$_SESSION['admin_ai_idol_csrf']`.
- **Cơ chế xác thực CSRF:** Phương thức `verifyCsrf(?array $input)` đối chiếu token được gửi trong payload JSON (`$input['csrf']`) hoặc header `X-CSRF-Token` bằng `hash_equals`.
- **Quy chuẩn định tuyến:**
  - Được đăng ký tập trung trong `backend/app/routes/ai_idol.php`.
  - Được lọc qua whitelist `in_array($r, [...], true)` tại các cổng vào `index.php`, `backend/public/index.php`, `frontend/public/index.php`.

### 2.5. Flash Sale Stacking Policy
- **Collection Flash Sale:** `flash_sale_campaigns`.
- **Quy tắc xếp chồng ưu đãi:** Mặc định không áp dụng đồng thời voucher xem video với các phiên Flash Sale (`stack_with_flash_sale = false`) để bảo vệ biên lợi nhuận của cửa hàng, trừ khi Admin chủ động bật tính năng này.

---

## 3. Kết luận Audit

Toàn bộ phụ thuộc đã được lập bản đồ đầy đủ. Thiết kế dữ liệu `ai_video_reward_campaigns` tích hợp hoàn toàn liền mạch với `ai_product_videos`, tôn trọng quy chuẩn bảo mật CSRF/RBAC của hệ thống, không làm thay đổi giá gốc sản phẩm và không gây xung đột với bộ máy thanh toán hiện hành.
