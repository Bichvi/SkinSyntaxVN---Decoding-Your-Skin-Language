# SKINSYNTAXVN — PHASE P2.3: CHANGED FILES & AUDIT LOG

**Author:** Senior PHP/MongoDB/Valkey Performance & Security Engineer  
**Date:** 2026-10-18  
**Scope:** Inventory of Source Code Modifications and New Verification Artifacts

---

## 1. Production Source Code Changes (Mã nguồn chính)

### 1.1. `backend/app/controllers/HomeController.php`
- **Vị trí sửa đổi:** Dòng 130 - 138 trong phương thức `getHomepagePublicData()`.
- **Nội dung thay đổi:**
  - Thay thế công thức tính Dynamic TTL cũ:
    ```php
    // Cũ (Tiềm ẩn rủi ro lưu cache 5s khi transition < 5s)
    $effectiveTtl = min(300, max(5, $nextTransitionTs - time()));
    ```
  - Sang công thức an toàn biên thời gian mới:
    ```php
    // Mới: Sub-5s boundary precision
    $diff = $nextTransitionTs - time();
    $effectiveTtl = $diff <= 0 ? 1 : max(1, min(300, $diff));
    ```
- **Mục đích:** Khi chiến dịch Flash Sale chỉ còn 1, 2, 3 giây là chuyển trạng thái, TTL được thu hẹp về đúng số giây còn lại (hoặc 1 giây nếu đã chạm mốc), đảm bảo key tự động hết hạn trên Valkey mà không bị giữ lại tối thiểu 5 giây.

---

### 1.2. `backend/app/services/CacheService.php`
- **Vị trí sửa đổi:**
  - Dòng 57 - 64: Bổ sung phương thức `public static function resetInstance(): void`.
  - Dòng 350 - 410: Bổ sung bộ ba phương thức phòng vệ sự cố Invalidation Failure:
    - `recordInvalidationFailure(?string $key = null, ?string $reason = null): void`
    - `isBypassedDueToFailedInvalidation(): bool`
    - `clearInvalidationFailure(): void`
  - Tích hợp kiểm tra `isBypassedDueToFailedInvalidation()` tại đầu các phương thức `get()` và `mget()`.
- **Mục đích:**
  - `resetInstance()`: Cho phép reset trạng thái Singleton trong các vòng lặp kiểm thử và đo lường, ngăn chặn lỗi tái sử dụng cache sai chế độ giữa các bài test.
  - Safe Bypass Guard: Khi lệnh xóa cache trên Valkey gặp sự cố mạng/timeout, hệ thống tự động ghi nhận cờ cục bộ và bypass tầng cache trong vòng 300 giây, bảo vệ người dùng không bị phục vụ dữ liệu cũ và tuyệt đối không cần dùng đến lệnh hủy diệt `FLUSHALL`.

---

## 2. Test Suites & Benchmarking Infrastructure (Mã kiểm thử & Công cụ đo)

### 2.1. `backend/tests/inspect_mongo_queries_accurate.php`
- **Mục đích:** Kiểm tra và xác thực số lượng truy vấn MongoDB Atlas thực tế trên từng route theo 4 mode (HIT, MISS, OFF, DOWN), giải mã nguyên nhân số liệu 0 queries của benchmark cũ.

### 2.2. `backend/tests/inspect_latency_breakdown.php`
- **Mục đích:** Đo đạc vi mô độ trễ từng thành phần (TLS handshake, network RTT, Atlas indexed lookup, Category find, composite Atlas read, JSON decode/encode, Template render) với 30 mẫu đo độc lập. Kết quả lưu tại `backend/experiments/performance/aiven_valkey/p2_3/latency_breakdown.json`.

### 2.3. `backend/tests/test_p2_3_flash_sale_boundary.php`
- **Mục đích:** Kiểm thử tự động 9 kịch bản biên thời gian của Flash Sale: chuyển trạng thái dưới 5s, campaign kết thúc giữa chừng, campaign bắt đầu, hai campaign liền kề, lệch đồng hồ hệ thống $\pm 2$s, lỗi xóa cache, truy cập đồng thời tại ranh giới $T=0$, và tính độc lập của giá thanh toán authoritative. Kết quả: **9/9 PASS**.

### 2.4. `backend/tests/test_p2_3_invalidation_failure.php`
- **Mục đích:** Kiểm thử tự động 9 kịch bản lỗi invalidation trên Valkey: kích hoạt cờ lỗi, tự động bypass sang Atlas, tự động phục hồi khi có lệnh xóa thành công, bảo vệ thanh toán, đọc đồng thời, và cam kết zero database flush (`FLUSHALL/DB`). Kết quả: **9/9 PASS**.

### 2.5. `backend/tests/benchmark_p2_3_rigorous.php`
- **Mục đích:** Bộ kịch bản đo lường hiệu năng HTTP 360 requests hoàn chỉnh (3 routes $\times$ 4 modes $\times$ 30 runs) với warm-up, interleaving, theo dõi lệnh Valkey, kết nối TLS, và đo đạc chính xác số truy vấn MongoDB Atlas.

---

## 3. Documentation Reports (Báo cáo kỹ thuật P2.3)

Tất cả các tài liệu được lưu trữ tại `backend/experiments/performance/aiven_valkey/p2_3/`:
- `benchmark_audit.md`: Phân tích lỗi benchmark P2.2, giải thích 0 queries và xác minh tính cô lập.
- `latency_breakdown.md`: Bảng chi tiết độ trễ từng vi thành phần và lý giải vì sao PDP Cache HIT chậm hơn Cache OFF.
- `cache_admission_decisions.md`: Chính sách chấp thuận cache từng route (Bypass PDP, Selective Category, Dual-Guard Homepage).
- `flash_sale_boundary_tests.md`: Biên bản kiểm thử ranh giới thời gian Flash Sale và độ lệch đồng hồ.
- `invalidation_failure_tests.md`: Biên bản kiểm thử khả năng chịu lỗi và Safe Bypass Guard khi Valkey xóa thất bại.
- `benchmark_results.md`: Bảng ma trận hiệu năng 360 HTTP measurements đối đầu thực tế.
- `changed_files.md`: Danh mục chi tiết các tệp thay đổi và vai trò.
- `final_report.md`: Báo cáo tổng kết toàn diện Phase P2.3 và phân loại PASS.
