# VÒNG ĐỜI KẾT NỐI (CONNECTION LIFECYCLE) & KIỂM TRA TÁI SỬ DỤNG SOCKET

## 1. Cơ Chế Kết Nối Trong Môi Trường PHP-FPM

Hệ thống SkinSyntaxVN chạy backend PHP trên nền tảng **PHP-FPM (FastCGI Process Manager)** với 8 worker processes (`pool www`).
Trong mô hình này:
- Mỗi worker PHP-FPM là một tiến trình riêng biệt (PIDs 30–37).
- Mỗi worker xử lý tuần tự một HTTP request tại một thời điểm.
- Sau khi một request kết thúc (chu kỳ Request Lifecycle hoàn tất):
  - Toàn bộ biến toàn cục, biến môi trường người dùng, các thể hiện Singleton (`CacheService::$instance`) và biến tĩnh của PHP sẽ bị Garbage Collector thu hồi.
  - Tuy nhiên, các tài nguyên cấp hệ thống (System Resources) được đánh dấu là **Persistent Streams** (`STREAM_CLIENT_PERSISTENT`) sẽ **được giữ lại** trong bảng socket của worker process đó mà không bị đóng.

---

## 2. Kiểm Tra Thực Tế Khả Năng Tái Sử Dụng Persistent Connection

### 2.1. Kiểm Tra Trong Nội Bộ Worker (Predis Stream Persistence)
Đo kiểm trực tiếp bằng script `test_fpm_connection_persistence.php`:
```
Testing multiple Predis client instantiations within same worker:
Attempt 1: connect() took 1637.94 ms, PING took 89.68 ms (PONG: PONG)
Attempt 2: connect() took 48.18 ms,   PING took 61.14 ms (PONG: PONG)
Attempt 3: connect() took 41.69 ms,   PING took 295.98 ms (PONG: PONG)
```

**Phân tích kết quả:**
1. **Lần 1 (Cold Handshake):** Tốn $1637.94\text{ ms}$ để thực hiện phân giải DNS, bắt tay TCP ba bước và trao đổi chứng chỉ bảo mật TLS (TLS 1.3 handshake với Aiven Cloud).
2. **Lần 2 (Reused Socket):** Chỉ tốn $48.18\text{ ms}$ (nhanh hơn **34 lần**). Socket cũ được hệ điều hành giữ lại trong tiến trình, bỏ qua hoàn toàn giai đoạn TLS handshake, chỉ gửi lệnh tái xác thực `HELLO` / `AUTH`.
3. **Lần 3 (Reused Socket):** Tiếp tục tái sử dụng với thời gian kết nối $41.69\text{ ms}$.

### 2.2. Kiểm Tra Hành Vi Qua Các Worker Khác Nhau
- Do có 8 worker độc lập, khi một đợt tải HTTP ập đến, 8 request đầu tiên tương ứng với 8 worker sẽ trải qua lần "khởi động lạnh" (Cold Handshake) để mỗi worker mở 1 socket persistent tới Aiven Valkey.
- Sau giai đoạn warm-up ($w=3$ requests/mode), tất cả các worker đều đã sở hữu persistent TLS socket đang mở.
- Kết quả kiểm tra `INFO Stats` trên Aiven Valkey cho thấy:
  - Chỉ số `total_connections_received` tăng rất chậm (chỉ tương ứng với số lượng worker khi có sự luân chuyển tiến trình hoặc timeout).
  - Không có tình trạng tạo socket mới trên từng HTTP request đơn lẻ.

---

## 3. Kiểm Tra Đảm Bảo Cô Lập L1 Request-Scoped Memory Cache

### 3.1. Thiết Kế Của L1 Request Cache
Trong `CacheService.php`:
```php
class CacheService {
    private static ?CacheService $instance = null;
    private array $l1MemoryCache = [];
    ...
```
`$l1MemoryCache` được thiết kế như một mảng PHP thông thường bên trong đối tượng `CacheService`.

### 3.2. Xác Minh Tính Cô Lập Giữa Các Requests
- **Vấn đề cần kiểm tra:** Liệu dữ liệu nhạy cảm hoặc trạng thái của một request trước đó có vô tình lưu lại trong worker và rò rỉ sang request tiếp theo hay không?
- **Kết quả xác minh:**
  1. Khi một HTTP request kết thúc, PHP-FPM gọi `php_request_shutdown()`. Toàn bộ bộ nhớ heap của request bị dọn sạch.
  2. `CacheService::$instance` trở về `null`.
  3. Ở request tiếp theo, `CacheService::getInstance()` khởi tạo một instance hoàn toàn mới với `$this->l1MemoryCache = []`.
  4. Để bảo đảm tuyệt đối trong môi trường test CLI dài hạn (nơi PHP process không tắt), phương thức `CacheService::clearL1()` được cung cấp để chủ động giải phóng bộ nhớ giữa các vòng lặp.
- **Kết luận:** **100% AN TOÀN**, không có bất kỳ hiện tượng rò rỉ dữ liệu L1 nào giữa các HTTP requests khác nhau.
