# DANH SÁCH CÁC TỆP THAY ĐỔI TRONG PHASE P2.2 (CHANGED FILES)

Trong Phase P2.2, nhằm tuân thủ nghiêm ngặt nguyên tắc **"Sửa tối thiểu tại đúng vị trí (Fix only verified issues)"**, chỉ có **2 tệp mã nguồn backend** được chỉnh sửa, cùng với các tệp kiểm thử và tài liệu đo đạc hiệu năng:

---

## 1. MÃ NGUỒN BACKEND ĐƯỢC CHỈNH SỬA

### 1.1. `backend/app/models/FlashSaleCampaign.php`
- **Mục đích:** Khắc phục khiếm khuyết phụ thuộc ngầm khi gọi `clearRequestCache()` và bổ sung cơ chế tính toán ranh giới chuyển giao Flash Sale kế tiếp.
- **Thay đổi chi tiết:**
  1. Trong `clearRequestCache()`:
     - Thêm kiểm tra và nạp phòng hộ: `if (!class_exists('SanPham') && is_file(__DIR__ . '/SanPham.php')) require_once __DIR__ . '/SanPham.php';`
     - Thêm nạp phòng hộ `CacheService.php` nếu `SanPham` không có sẵn: gọi `deleteByPattern('home:*')`, `deleteByPattern('catalog:*')`, `deleteByPattern('menu:*')`.
     - Đảm bảo Valkey composite cache luôn luôn bị xóa khi Flash Sale thay đổi, bất kể được gọi từ script hay controller nào.
  2. Bổ sung phương thức công khai mới:
     - `public function getNextCampaignTransitionTimestamp(?int $now = null): ?int`: Truy vấn MongoDB Atlas xác định mốc Unix timestamp gần nhất trong tương lai mà chiến dịch Active sẽ kết thúc hoặc chiến dịch Upcoming sẽ bắt đầu.

### 1.2. `backend/app/controllers/HomeController.php`
- **Mục đích:** Triển khai cơ chế Dual-Layer Time Boundary Guard bảo vệ tính an toàn thời gian thực của Flash Sale trên Homepage.
- **Thay đổi chi tiết:**
  1. Trong `getHomepagePublicData()`:
     - **Lớp đọc phòng hộ (Read-time Guard):** Khi phát hiện Cache HIT, đối chiếu `start_at_ts` và `end_at_ts` của `primary_campaign` cũng như metadata `__next_transition_ts` với `time()`. Nếu đã trôi qua mốc chuyển giao, tự động xóa cache và nạp authoritative từ MongoDB Atlas.
     - **Lớp ghi động (Dynamic TTL Bounding):** Tính toán `$nextTransitionTs = $flashSaleModel->getNextCampaignTransitionTimestamp()`. Nếu mốc chuyển giao sắp diễn ra trong vòng ít hơn 300 giây, tự động co giãn TTL: `effectiveTtl = max(5, $nextTransitionTs - time())`.
     - Lưu metadata `__next_transition_ts` vào payload cache để phục vụ đối soát.

---

## 2. BẢO TOÀN TUYỆT ĐỐI CÁC KHU VỰC NGOẠI VI (STRICT BOUNDARY PRESERVATION)

Tuân thủ nghiêm ngặt ràng buộc của Phase P2.2:
- **KHÔNG thay đổi logic tính giá Flash Sale** (`resolveEffectivePrice`, `hydrateCampaignItems`).
- **KHÔNG thay đổi Checkout & Hóa đơn** (`HoaDon.php`, `HoaDonController.php`).
- **KHÔNG thay đổi Hệ thống Voucher** (`Voucher.php`, `VoucherController.php`).
- **KHÔNG thay đổi Hệ thống Gợi ý (Recommendation Engine)** (`GoiYContentBased.php`, `SanPham::getSimpleRecommenderProducts`).
- **KHÔNG thay đổi MongoDB Schema** hoặc các chỉ mục hiện hành.
- **KHÔNG xóa toàn bộ database Valkey** (`FLUSHALL` / `FLUSHDB` bị cấm, chỉ xóa key theo pattern).

---

## 3. TỆP KIỂM THỬ VÀ TÀI LIỆU ĐO ĐẠC ĐƯỢC TẠO

1. `backend/tests/test_p2_2_cache_consistency.php`: Bộ kiểm thử 12 kịch bản tính nhất quán bộ nhớ đệm và an toàn Flash Sale.
2. `backend/tests/benchmark_p2_2_rigorous.php`: Bộ công cụ benchmark đo đạc 360 lần HTTP measurements tương thích hoàn toàn môi trường Docker Nginx/PHP-FPM.
3. `backend/experiments/performance/aiven_valkey/p2_2/audit.md`
4. `backend/experiments/performance/aiven_valkey/p2_2/flash_sale_invalidation.md`
5. `backend/experiments/performance/aiven_valkey/p2_2/cache_consistency_tests.md`
6. `backend/experiments/performance/aiven_valkey/p2_2/performance_regression.md`
7. `backend/experiments/performance/aiven_valkey/p2_2/changed_files.md`
8. `backend/experiments/performance/aiven_valkey/p2_2/final_report.md`
