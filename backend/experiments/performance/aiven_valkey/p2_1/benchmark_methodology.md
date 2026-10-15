# PHƯƠNG PHÁP ĐO LƯỜNG VÀ THỰC NGHIỆM BENCHMARK (PHASE P2.1)

## 1. Nguyên Tắc Thiết Kế Thực Nghiệm

Để giải quyết vấn đề số liệu bị nhiễu do biến động đường truyền Internet từ môi trường cục bộ tới Aiven Valkey và MongoDB Atlas, quy trình kiểm thử của Phase P2.1 tuân thủ các chuẩn mực đo lường nghiêm ngặt:

1. **Số lượng mẫu thống kê tối thiểu:** Đo tối thiểu **30 HTTP requests** cho từng route trên từng chế độ hoạt động (tổng cộng 120 requests/route, 360 requests toàn bộ benchmark suite).
2. **Tách biệt giai đoạn Warm-up:**
   - Thực hiện 3 chu kỳ warm-up không ghi nhận số liệu trước khi bắt đầu đo lường chính thức.
   - Loại bỏ hoàn toàn ảnh hưởng của cold-start, DNS lookup ban đầu và khởi tạo worker pool.
3. **Thực thi Xen Kẽ (Interleaved Execution):**
   - Không chạy 30 lần Cache HIT liên tiếp rồi mới chạy 30 lần Cache MISS.
   - Thay vào đó, trong mỗi vòng lặp $i \in [1..30]$, hệ thống lần lượt gọi:
     $$\text{Iteration } i: \quad \text{Cache HIT} \longrightarrow \text{Cache MISS} \longrightarrow \text{Cache OFF} \longrightarrow \text{Valkey DOWN}$$
   - Việc xen kẽ này phân bổ đều bất kỳ biến động đường truyền hoặc độ trễ mạng tạm thời nào vào tất cả các chế độ, giúp loại bỏ thiên lệch thời gian (time-based network bias).
4. **Phân định rõ ràng giữa các chế độ:**
   - **Cache HIT:** Toàn bộ cache keys liên quan đã được nạp sẵn vào Valkey. Backend đọc trực tiếp từ Valkey, không truy vấn MongoDB hoặc chỉ truy vấn tối thiểu.
   - **Cache MISS:** Xóa sạch các pattern key của route đó trước khi gọi request (`deleteByPattern`). Backend buộc phải truy vấn MongoDB để nạp lại cache.
   - **Cache OFF:** Gửi kèm cờ `cache=off` hoặc header `X-Cache-Disabled: 1`. Hệ thống tắt hoàn toàn CacheService, chuyển thẳng sang đọc authoritative từ MongoDB mà không hề kết nối hay phát sinh lệnh tới Valkey.
   - **Valkey DOWN (Outage Simulation):** Định tuyến URL tới cổng không thể kết nối (`rediss://127.0.0.1:29999`). Kiểm tra cơ chế Circuit Breaker bắt lỗi gracefully, tự động fallback về MongoDB Atlas mà không gây HTTP 500 hay sập ứng dụng.

---

## 2. Các Chỉ Số Đo Lường (Metrics & Formulas)

Đối với mỗi route và chế độ hoạt động, công cụ benchmark đo đạc và tổng hợp các thông số sau:

| Chỉ số | Ký hiệu / Đơn vị | Định nghĩa & Cách tính |
| :--- | :--- | :--- |
| **HTTP Response Time** | $\text{ms}$ | Thời gian đo từ khi cURL phát request tới khi nhận toàn bộ byte cuối cùng của phản hồi HTTP (`CURLINFO_TOTAL_TIME`). |
| **Median (p50)** | $\text{p50 (ms)}$ | Phân vị 50%, giá trị trung vị đại diện cho trải nghiệm người dùng thông thường. |
| **95th Percentile** | $\text{p95 (ms)}$ | Phân vị 95%, đo lường các trường hợp tải chậm hoặc tắc nghẽn mạng cục bộ. |
| **99th Percentile** | $\text{p99 (ms)}$ | Phân vị 99%, đo lường cận biên tồi nhất (worst-case performance). |
| **Total HTTP Time** | $\text{ms / s}$ | Tổng thời gian tiêu tốn cho 30 lần đo của chế độ đó. |
| **Average MongoDB Queries** | $\text{queries/req}$ | Số truy vấn MongoDB Atlas thực tế được theo dõi qua `MongoDB\Driver\Monitoring\CommandSubscriber`. |
| **Valkey Commands** | $\text{commands}$ | Tổng số lệnh đọc/ghi phát sinh tới Aiven Valkey trong quá trình thử nghiệm. |
| **Valkey Latency** | $\text{ms}$ | Độ trễ một roundtrip mạng PING tới Aiven Valkey server. |
| **TLS Connection Count** | $\text{connections}$ | Số phiên kết nối TLS socket được tạo mới. |
| **Cache Hit/Miss Ratio** | $\%$ | Tỷ lệ $\frac{\text{Hits}}{\text{Hits} + \text{Misses}} \times 100\%$. |
| **Error Rate** | $\%$ | Tỷ lệ các phản hồi có mã HTTP $\neq 200$ hoặc dung lượng HTML $< 500$ bytes. |

---

## 3. Môi Trường Thực Nghiệm (Test Harness)

- **Container Engine:** Docker Engine v27.x trên nền Windows WSL2.
- **Web Server:** Nginx 1.27-alpine (`skinsyntax-nginx`).
- **PHP Runtime:** PHP 8.2.33 FastCGI (`skinsyntax-php`), 8 FPM workers.
- **Database:** MongoDB Atlas (Cloud Replica Set, `skinsyntax_v2`, 3.002 sản phẩm).
- **Cache Store:** Aiven Valkey 7.x (Cloud Managed Service, TLS rediss://).
- **Execution Script:** `backend/tests/benchmark_p2_1_rigorous.php`.
- **Output Storage:** `backend/experiments/performance/aiven_valkey/p2_1/benchmark_matrix.json`.
