# SECURITY & RBAC VERIFICATION REPORT — PHASE V2A

**System:** SkinSyntaxVN Video Reward Campaign Management  
**Scope:** RBAC Guards, CSRF Defense, Input Sanitization, No Cross-Customer Leak  
**Test Suite:** `backend/tests/test_v2a_reward_campaign.php`  
**Status:** FULL PASS (100% Passed)  

---

## 1. Cơ chế Bảo mật được Kiểm toán & Áp dụng

### 1.1. Phân quyền Quản trị (Admin-Only RBAC)
- Mọi endpoint tạo, cập nhật, lấy dữ liệu và đổi trạng thái chiến dịch voucher đều được bảo vệ bởi hàm `checkAdmin()` trong `AdminAiIdolController.php`:
  ```php
  private function checkAdmin(): void {
      if (session_status() === PHP_SESSION_NONE) {
          session_start();
      }
      $user = $_SESSION['user'] ?? null;
      if (!$user) {
          header('Location: index.php');
          exit;
      }
      $role = strtolower((string)($user['role'] ?? $user['vai_tro'] ?? $user['quyen'] ?? ''));
      $isAdmin = $role === 'admin' || (int)($user['is_admin'] ?? 0) === 1;
      if (!$isAdmin) {
          $this->denyAccess(); // HTTP 403
      }
  }
  ```
- **Kết quả kiểm thử:**
  - Khách vãng lai (không có session cookie) gửi request POST `r=admin_ai_idol_save_reward_campaign` bị chuyển hướng lập tức (`HTTP 302 -> index.php`).
  - Khách hàng đăng nhập vai trò thông thường (không phải admin) nhận mã `HTTP 403 Forbidden`.

---

### 1.2. Phòng chống CSRF (Cross-Site Request Forgery)
- Session key quản lý: `$_SESSION['admin_ai_idol_csrf']`.
- Xác thực hai đầu: Token CSRF được gửi qua thuộc tính `csrf` trong body JSON hoặc header HTTP `X-CSRF-Token`.
- Kiểm tra bằng hàm an toàn theo thời gian hằng số `hash_equals()`:
  ```php
  if (!hash_equals($expected, $token)) {
      http_response_code(403);
      echo json_encode(['ok' => false, 'message' => 'Lỗi bảo mật CSRF: Phiên làm việc không hợp lệ...']);
      exit;
  }
  ```
- **Kết quả kiểm thử:**
  - Request mang token CSRF giả mạo (`invalid_csrf_token_fake`) bị từ chối với mã lỗi `HTTP 403`.
  - Request thiếu token CSRF bị từ chối với mã lỗi `HTTP 403`.
  - Request mang token CSRF hợp lệ được xử lý thành công với mã `HTTP 200`.

---

### 1.3. Server-Side Input Validation & Safe Queries
- Toàn bộ giá trị nhập liệu (loại giảm giá, tỉ lệ phần trăm, số tiền, ngày bắt đầu/kết thúc, hạn ngạch) đều được kiểm tra chặt chẽ ở phía Server tại `VideoRewardCampaign::saveCampaign()`.
- **Chống NoSQL Injection:**
  - Mã định danh `video_id`, `publication_id`, `campaign_id` được ép kiểu nghiêm ngặt `(string)` và làm sạch bằng `trim()`.
  - Không sử dụng nối chuỗi truy vấn thô vào driver MongoDB.
  - Các ObjectId được xác thực regex `^[a-f0-9]{24}$` trước khi khởi tạo `new MongoDB\BSON\ObjectId($id)`.

---

### 1.4. Chống Submit Trùng Lặp & Chống Xung Đột
- Chỉ mục duy nhất MongoDB (`unique index`) trên trường `campaign_id` loại bỏ hoàn toàn khả năng trùng lặp khóa.
- Ràng buộc **Một chiến dịch kích hoạt duy nhất cho mỗi video:**
  - Khi tạo mới hoặc cập nhật với `is_active = true`, hệ thống chủ động tìm kiếm các chiến dịch khác cùng `video_id` đang `is_active: true`.
  - Nếu đã có chiến dịch khác đang hoạt động, server lập tức từ chối và yêu cầu Admin tắt chiến dịch cũ trước.

---

### 1.5. Bảo Vệ Dữ Liệu Khách Hàng (No Cross-Customer Leak)
- Collection `ai_video_reward_campaigns` là cấu hình cấp chiến dịch và sản phẩm, hoàn toàn không lưu trữ thông tin nhận dạng cá nhân (PII), thông tin thẻ hay mật khẩu người dùng.
- Giới hạn nhận thưởng `user_limit` được chuẩn bị sẵn sàng cho kiểm tra phiên người dùng độc lập trong Phase V2B mà không để lộ lịch sử nhận giữa các tài khoản.

---

## 2. Bảng Tổng Hợp Kết Quả Test Bảo Mật

| STT | Kịch bản kiểm thử bảo mật | Điều kiện đầu vào | Kết quả mong đợi | Kết quả thực tế | Trạng thái |
| :---: | :--- | :--- | :--- | :--- | :---: |
| 1 | Khách vãng lai gửi yêu cầu ghi | Không có session cookie | HTTP 302 / 403 | HTTP 302 | **PASS** |
| 2 | CSRF token sai mã | Cookie Admin + `csrf: "fake"` | HTTP 403 | HTTP 403 | **PASS** |
| 3 | CSRF token bị bỏ trống | Cookie Admin + không có csrf | HTTP 403 | HTTP 403 | **PASS** |
| 4 | CSRF token hợp lệ | Cookie Admin + token đúng | HTTP 200 | HTTP 200 | **PASS** |
| 5 | Chiết khấu phần trăm vượt ngưỡng | `discount_value: 110` | Bị từ chối | Lỗi: Tỉ lệ phải từ 1-100% | **PASS** |
| 6 | Chiết khấu phần trăm âm/bằng 0 | `discount_value: 0` | Bị từ chối | Lỗi: Tỉ lệ phải từ 1-100% | **PASS** |
| 7 | Chiết khấu cố định âm | `discount_value: -20000` | Bị từ chối | Lỗi: Số tiền phải > 0 | **PASS** |
| 8 | Ngày bắt đầu sau ngày kết thúc | `start_date >= end_date` | Bị từ chối | Lỗi: Bắt đầu phải trước kết thúc | **PASS** |
| 9 | Tham chiếu video không tồn tại | `video_id: "fake_vid"` | Bị từ chối | Lỗi: Không tìm thấy video hợp lệ | **PASS** |
| 10 | Video chưa duyệt/chưa READY | `render_status: "PENDING"` | Bị từ chối | Lỗi: Chỉ video READY mới được tạo | **PASS** |
| 11 | Tạo 2 chiến dịch active cho 1 video| Cùng video_id, `is_active: true` | Bị từ chối | Lỗi: Video đã có chiến dịch active | **PASS** |
| 12 | Giảm hạn ngạch dưới số đã phát | `quota: 30`, `issued: 50` | Bị từ chối | Lỗi: Quota không được nhỏ hơn đã phát | **PASS** |
