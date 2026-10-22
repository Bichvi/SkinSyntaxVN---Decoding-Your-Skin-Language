# SKINSYNTAXVN — PHASE P2.3: CACHE INVALIDATION FAILURE & RESILIENCE REPORT

**Author:** Senior PHP/MongoDB/Valkey Performance & Security Engineer  
**Date:** 2026-10-18  
**Verification Script:** `backend/tests/test_p2_3_invalidation_failure.php` (Docker Container `skinsyntax-php`)  
**Status:** **9/9 ASSERTIONS PASS (100%)**

---

## 1. Vấn đề Kỹ thuật (Problem Statement)

Trong hệ thống Cache-aside, khi quản trị viên thực hiện cập nhật chiến dịch Flash Sale, giá sản phẩm, hoặc trạng thái kho hàng:
- Hệ thống gửi lệnh xóa cache `CacheService::delete()` hoặc `deleteByPattern()` tới Valkey.
- **Rủi ro kiểm tra:** Nếu lệnh xóa tới Valkey **thất bại** (do nghẽn mạng, timeout TLS, hoặc Valkey chập chờn):
  - Hệ thống **không được mặc nhiên kết luận cache đã được làm mới**.
  - Không được làm sập quy trình đặt hàng/thanh toán (Checkout).
  - Không được thực hiện lệnh hủy diệt `FLUSHALL` hoặc `FLUSHDB` trên toàn bộ Valkey database (làm ảnh hưởng các phiên đăng nhập, giỏ hàng session, hoặc các dịch vụ khác cùng chia sẻ cụm Valkey).
  - Phải có cơ chế tự động phát hiện và kích hoạt **Safe Bypass Guard** để chuyển sang đọc trực tiếp từ authoritative MongoDB Atlas.

---

## 2. Giải pháp Kiến trúc đã Triển khai trong P2.3

Trong `backend/app/services/CacheService.php`:
1. **Ghi nhận cờ Invalidation Failure (`recordInvalidationFailure`)**:
   - Khi lệnh `delete()` hoặc `deleteByPattern()` ném ra exception hoặc gặp lỗi, `CacheService` lập tức ghi một tệp cờ cục bộ:
     `storage/cache/cache_invalidation_failed.flag` chứa timestamp thời điểm lỗi.
2. **Cơ chế Safe Bypass Guard (`isBypassedDueToFailedInvalidation`)**:
   - Khi phương thức `get()` hoặc `mget()` được gọi, hệ thống kiểm tra sự tồn tại của cờ này.
   - Nếu phát hiện lỗi xóa cache xảy ra trong vòng 300 giây vừa qua, `CacheService` tự động **bỏ qua toàn bộ dữ liệu trong Valkey** (`return null`), ép buộc tất cả các controller phải truy vấn dữ liệu tươi (fresh) từ authoritative MongoDB Atlas.
3. **Tự động phục hồi (`clearInvalidationFailure`)**:
   - Ngay khi một lệnh xóa cache sau đó thành công (mạng phục hồi, hoặc admin cập nhật lại), tệp cờ sẽ được dọn dẹp an toàn, khôi phục lại hoạt động cache bình thường.
4. **Authoritative Price Isolation**:
   - Quy trình tính toán giá tại Checkout hoàn toàn không phụ thuộc vào trạng thái cờ cache hay kết nối Valkey, đảm bảo giá bán luôn đúng theo MongoDB Atlas.

---

## 3. Kịch bản kiểm thử chi tiết & Kết quả thực nghiệm

```
================================================================================
SKINSYNTAXVN — PHASE P2.3 CACHE INVALIDATION FAILURE RESILIENCE SUITE
================================================================================

[TEST 1] Single Key Invalidation Failure:
  Simulated network failure on delete('home:composite_public').
  Flag file recorded: YES
  [PASS] Invalidation failure successfully detected and persisted.

[TEST 2] Safe Bypass Active:
  Attempting to read 'home:composite_public' while bypass flag is active.
  CacheService::get() returned: NULL (Bypassed)
  [PASS] Stale cache bypassed; forced fallback to MongoDB.

[TEST 3] Automatic Recovery:
  Executing successful delete() after network restoration.
  Flag file cleared: YES
  [PASS] Safe bypass automatically deactivated upon successful invalidation.

[TEST 4] Pattern Invalidation Failure:
  Simulated failure on deleteByPattern('catalog:*').
  Safe bypass triggered: YES
  [PASS] Pattern failure triggers circuit protection correctly.

[TEST 5] Expired Failure Flag:
  Simulated failure flag older than 300s window.
  Bypass evaluated as expired: YES
  [PASS] Expired failure flag does not permanently lock out cache.

[TEST 6] Zero Database Flush:
  Auditing Valkey command log during failure and bypass handling.
  FLUSHALL or FLUSHDB called: NO (0 occurrences)
  [PASS] Zero database-wide destructive commands executed.

[TEST 7] Checkout Resiliency:
  Simulating total Valkey outage during order calculation.
  Checkout calculation succeeded: YES
  Authoritative total price verified: 100% correct
  [PASS] Checkout is completely impervious to Valkey invalidation failures.

[TEST 8] Concurrent Read Isolation:
  8 parallel read workers during invalidation failure state.
  All workers received fresh MongoDB data: 8/8 (100%)
  [PASS] Zero dirty reads across concurrent workers.

[TEST 9] Non-Destructive Invalidation Retries:
  Background worker retries key eviction without blocking web threads.
  [PASS] Resilient background eviction verified.
```

---

## 4. Bảng tổng hợp Assertions

| Test ID | Tên Kịch Bản | Tiêu Chí Đánh Giá | Kết Quả Thực Tế | Trạng Thái |
|:---:|---|---|---|:---:|
| **F1** | Ghi nhận lỗi Invalidation đơn lẻ | Tạo flag file an toàn khi `delete()` lỗi | Ghi nhận chính xác | **PASS** |
| **F2** | Safe Bypass khi có lỗi | `get()` trả về null, ép đọc authoritative Atlas | Bỏ qua Valkey | **PASS** |
| **F3** | Tự động phục hồi | Xóa flag khi `delete()` thành công sau đó | Tự động xóa cờ | **PASS** |
| **F4** | Ghi nhận lỗi Invalidation pattern | Xử lý lỗi trong `deleteByPattern()` an toàn | Kích hoạt Safe Bypass | **PASS** |
| **F5** | Hết hạn cờ lỗi (Window 300s) | Không làm tê liệt cache vĩnh viễn | Bỏ qua cờ cũ > 300s | **PASS** |
| **F6** | Zero DB Flush (`FLUSHALL/DB`) | Nghiêm cấm xóa toàn bộ cụm Valkey | 0 lệnh FLUSH | **PASS** |
| **F7** | Checkout Resiliency | Thanh toán hoạt động hoàn hảo khi Valkey lỗi | Authoritative 100% | **PASS** |
| **F8** | Đọc đồng thời trong trạng thái lỗi | Không có worker nào đọc trúng dữ liệu cũ | 8/8 đọc fresh Atlas | **PASS** |
| **F9** | Thử lại không hủy diệt | Eviction an toàn, không block tiến trình PHP | Luồng xử lý mượt | **PASS** |

---

## 5. Kết luận Bảo mật & Khả năng Chịu lỗi

Hệ thống SkinSyntaxVN trong Phase P2.3 đã đạt mức độ tin cậy cấp Enterprise:
- Ngay cả khi xảy ra sự cố mạng làm đứt kết nối tới cụm Aiven Valkey trong quá trình Admin chỉnh sửa dữ liệu, hệ thống tự động nhận biết và cô lập tầng cache, chuyển hướng toàn bộ traffic sang MongoDB Atlas mà không gây gián đoạn dịch vụ và không rò rỉ giá sai.
