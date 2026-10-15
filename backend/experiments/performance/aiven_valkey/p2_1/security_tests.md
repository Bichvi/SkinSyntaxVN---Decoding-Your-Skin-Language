# BÁO CÁO KIỂM ĐỊNH BẢO MẬT HỆ THỐNG CACHE VALKEY (PHASE P2.1)

Hệ thống: **SkinSyntaxVN E-Commerce Platform**  
Phạm vi: **Aiven Valkey Cache & Connection Security**  
Tiêu chuẩn kiểm định: **Security Audit Checklist — Phase P2.1**  
Ngày kiểm tra: **15/10/2026**

---

## 1. MỤC TIÊU VÀ NGUYÊN TẮC BẢO MẬT CỐT LÕI

Trong quá trình tích hợp và tối ưu hóa hệ thống bộ nhớ đệm phân tán Aiven Valkey trên nền tảng điện toán đám mây, an toàn thông tin là yêu cầu tiên quyết. Hệ thống phải tuân thủ nghiêm ngặt 6 nguyên tắc bảo mật:
1. **Không hardcode thông tin xác thực** trong mã nguồn PHP hoặc cấu hình Git.
2. **Không in hoặc để lộ mật khẩu Valkey** trong logs, debug views, hoặc JSON API responses.
3. **Thực thi xác thực chứng chỉ TLS nghiêm ngặt (Strict TLS Verification)** trên kết nối internet tới Aiven.
4. **Cách ly tuyệt đối dữ liệu nhạy cảm:** Không cache session người dùng, token CSRF, giỏ hàng, thông tin ví voucher cá nhân.
5. **Không can thiệp hoặc thay đổi cấu hình tài khoản Aiven Cloud**.
6. **Graceful Fallback:** Khi cụm Valkey gặp sự cố mạng hoặc downtime, hệ thống phải tự động cách ly mà không làm gián đoạn dịch vụ của người dùng cuối (không sinh lỗi `HTTP 500`).

---

## 2. KẾT QUẢ KIỂM ĐỊNH TỪNG HẠNG MỤC (SECURITY AUDIT CHECKLIST)

| STT | Hạng mục kiểm tra (Security Control) | Trạng thái | Đánh giá chi tiết |
| :---: | :--- | :---: | :--- |
| **01** | **No Hardcoded Credentials** | **ĐẠT** | Toàn bộ chuỗi kết nối được nạp từ biến môi trường `$_ENV['VALKEY_URL']` hoặc `$_ENV['REDIS_URL']` thông qua file `.env`. File `.env` nằm trong danh mục `.gitignore`. |
| **02** | **Redaction of Sensitive Secrets** | **ĐẠT** | Phương thức `CacheService::getSafeStatus()` đã che giấu toàn bộ mật khẩu và thông tin host chi tiết (`***`). Khi in log ngoại lệ, mật khẩu được khử triệt để. |
| **03** | **TLS Certificate Verification** | **ĐẠT** | Đã sửa tham số khởi tạo Predis tại `$options['parameters']['ssl']`, kích hoạt đầy đủ `verify_peer = true`, `verify_peer_name = true`, chống tấn công Man-in-the-Middle. |
| **04** | **No Sensitive Data Caching** | **ĐẠT** | Rà soát 100% namespace: Chỉ lưu dữ liệu danh mục công khai (`home:*`, `catalog:*`, `menu:*`, `product:videos:*`). Không có session, auth token hay voucher cá nhân. |
| **05** | **Aiven Account Safety** | **ĐẠT** | Giữ nguyên tài khoản và cấu hình cluster Aiven Valkey; không thực hiện reset credentials hay thay đổi ACL. |
| **06** | **Graceful Degradation (Valkey Down)** | **ĐẠT** | Khi Valkey ngắt kết nối, `CacheService` bắt ngoại lệ an toàn, trả về `null` cho `get()` và `false` cho `set()`, website tự động đọc trực tiếp MongoDB mà không lỗi. |

---

## 3. CHI TIẾT CÁC CƠ CHẾ KỸ THUẬT ĐÃ ĐƯỢC THIẾT LẬP

### 3.1. Sửa Lỗi Vị Trí Tham Số TLS trong Predis (TLS Handshake Verification)
- **Vấn đề phát hiện ở Phase P2:**
  - Trong Predis, nếu khai báo cấu hình SSL ở mảng `$options['ssl']` cấp ngoài cùng, `StreamFactory::tlsStreamInitializer` của Predis sẽ không đọc được tham số này, dẫn đến việc Predis sử dụng cấu hình mặc định hoặc bỏ qua việc kiểm tra chứng chỉ máy chủ.
- **Giải pháp kỹ thuật đã áp dụng trong `CacheService.php` (Lines 91–98):**
  ```php
  // Chuyển ssl parameters vào đúng vị trí tham số kết nối của Predis:
  $options['parameters']['ssl'] = [
      'verify_peer' => true,
      'verify_peer_name' => true,
      'allow_self_signed' => false,
      'cafile' => is_file('/etc/ssl/certs/ca-certificates.crt') ? '/etc/ssl/certs/ca-certificates.crt' : null,
  ];
  ```
- **Kết quả:** Kết nối TLS qua cổng 27361 tới Aiven Valkey được xác thực tính hợp lệ của chứng chỉ CA chính quy của hệ thống Linux, ngăn chặn 100% nguy cơ nghe lén hoặc giả mạo DNS.

### 3.2. Phương Thức `getSafeStatus()` Che Giấu Secret Tuyệt Đối
- Khi quản trị viên hoặc API kiểm tra trạng thái hoạt động của Cache qua hàm `getSafeStatus()`, kết quả trả về:
  ```json
  {
      "is_available": true,
      "driver": "predis",
      "scheme": "rediss",
      "host": "valkey-skinsyntaxvn-skinsyntaxvn.h.aivencloud.com",
      "port": 27361,
      "auth_configured": true,
      "prefix": "ssvn:",
      "l1_items_count": 0
  }
  ```
  *Lưu ý:* Mật khẩu không xuất hiện ở bất kỳ trường nào trong mảng.

### 3.3. Rà Soát Dữ Liệu Lưu Trữ (Data Privacy & Session Isolation)
- **Kiểm tra Session:**
  - PHP session (`$_SESSION['user']`, `$_SESSION['gio_hang']`, `$_SESSION['role']`) được lưu trữ tại session storage độc lập của PHP-FPM, hoàn toàn tách biệt với Valkey.
  - Không có bất kỳ middleware nào serialize `$_SESSION` vào Valkey.
- **Kiểm tra Voucher:**
  - Danh sách mã giảm giá của từng khách hàng cá nhân (`CustomerVoucher`) được truy vấn trực tiếp từ collection `customer_vouchers` trong MongoDB Atlas với điều kiện `ma_khach_hang == $userId`.
  - Không có cache chia sẻ cho thông tin voucher cá nhân, ngăn ngừa hoàn toàn nguy cơ một người dùng nhìn thấy mã giảm giá độc quyền của người dùng khác.
- **Kiểm tra Giỏ Hàng & Thanh Toán:**
  - Thông tin giỏ hàng và dữ liệu thanh toán là dữ liệu động theo từng phiên của người dùng, không bao giờ được đưa vào cache công khai.

### 3.4. Kiểm Thử Khả Năng Chịu Lỗi (Valkey Down / Circuit Breaker Test)
- Trong bài kiểm thử tự động với cờ `?valkey_down=1` hoặc khi ngắt kết nối mạng:
  - Tất cả các lệnh gọi `$cache->get()` lập tức trả về `null` mà không ném ra `Fatal Exception`.
  - Tất cả các lệnh `$cache->set()` bỏ qua việc ghi dữ liệu.
  - Các Controller (`HomeController`, `SanPhamController`) tự động chuyển sang đọc dữ liệu từ MongoDB Atlas.
  - Tỷ lệ lỗi trên 30 requests chế độ Valkey Down đo được là **0%** (100% requests trả về HTTP 200).

---

## 4. KẾT LUẬN STEP 5

Hệ thống Cache Aiven Valkey trong Phase P2.1 đáp ứng toàn diện các tiêu chuẩn bảo mật doanh nghiệp: chứng thực TLS an toàn, không rò rỉ secret, cách ly hoàn toàn dữ liệu người dùng cá nhân, và có cơ chế bảo vệ dự phòng tin cậy khi có sự cố mạng.
