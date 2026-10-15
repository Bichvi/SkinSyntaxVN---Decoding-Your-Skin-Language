# BÁO CÁO KIỂM THỬ BƠM LỖI HỆ THỐNG (FAILURE INJECTION TESTS) — PHASE V2C.1

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Kiểm thử Chịu Lỗi & Phục Hồi Sự Cố Cấp Thưởng (Fault Tolerance & Disaster Recovery)  
**Tác giả:** Senior E-commerce Reliability & Transaction Engineer  
**Ngày:** 13/10/2026  
**Kết quả kiểm thử:** 100% PASS  

---

## 1. Thiết kế Thử nghiệm Bơm Lỗi (Fault Injection Strategy)

Để xác nhận hệ thống không bị rò rỉ quota (quota leak) hoặc để lại rác dữ liệu khi xảy ra sự cố đột ngột trong môi trường phân tán, chúng tôi thiết kế bộ harness bơm lỗi có kiểm soát vào phương thức `claimReward()`:

1. **Failure Injection 1:** Giả lập Crash đột ngột ngay sau khi thực hiện Quota Reservation (tiến trình bị kill hoặc drop connection trước khi voucher được tạo).
2. **Failure Injection 2:** Giả lập Ngoại lệ sau khi đã insert voucher thực thi vào `voucher` nhưng trước khi insert entitlement vào `customer_vouchers`.
3. **Failure Injection 3:** Giả lập Lỗi mạng tạm thời (Transient Network Drop) trong quá trình thực thi Transaction.
4. **Failure Injection 4:** Giả lập Xung đột khóa duy nhất (Duplicate Key Collision E11000).

---

## 2. Kết quả Thực nghiệm Chi tiết

### 2.1. Kịch bản 1: Crash Sau Quota Reservation
- **Kích hoạt:** Gọi `claimReward($sid, $cid, ..., simulateCrashAfterReservation = true)`.
- **Hiện tượng:**
  - `issued_count` trên campaign bị tăng lên 1 đơn vị.
  - Tiến trình bị ngắt với mã lỗi `CRASH_AFTER_RESERVATION`.
  - Không có voucher hay entitlement nào được tạo ra.
- **Xử lý Phục hồi:**
  - Chạy công cụ `reconcileCampaignQuota($campaignId)`.
  - Hệ thống tự động so khớp `issued_count = 1` với số lượng voucher thực tế `= 0`.
  - Phát hiện độ lệch (drift) và tự động kéo `issued_count` trở về đúng `0`.
- **Kết quả:** **PASS** (Quota được phục hồi nguyên vẹn, không bị mất mát).

### 2.2. Kịch bản 2: Crash Sau Khi Tạo Execution Voucher (Zero Orphan Vouchers)
- **Kích hoạt:** Gọi `claimReward($sid, $cid, ..., simulateCrashAfterVoucherInsert = true)`.
- **Hiện tượng:**
  - Bản ghi trong `voucher` đã được insert thành công.
  - Một ngoại lệ nghiêm trọng phát sinh trước khi tạo `customer_vouchers`.
- **Xử lý Phục hồi của Hệ thống:**
  - Khối `catch (\Throwable $e)` kích hoạt cơ chế hoàn tác bù trừ (Compensating Rollback).
  - Lệnh xóa có chủ đích: `$this->db->voucher->deleteOne(['ma_code' => $voucherCode])` được thực thi ngay lập tức.
  - Quota trên campaign được hoàn trả bằng `$inc: ['issued_count' => -1]`.
- **Xác minh Sau Sự Cố:**
  - Số lượng voucher của user trong collection `voucher`: `0` bản ghi.
  - Số lượng voucher trong collection `customer_vouchers`: `0` bản ghi.
  - Số lượng `issued_count` trên campaign: `0`.
- **Kết quả:** **PASS** (Tuyệt đối không để lại voucher mồ côi).

### 2.3. Kịch bản 3: Multi-Document Transaction Abort
- **Mô tả:** Trong chế độ transaction, mọi lỗi phát sinh giữa chừng dẫn đến `$session->abortTransaction()`.
- **Xác minh:** Kiểm tra cả 4 collection liên quan (`ai_video_reward_campaigns`, `voucher`, `customer_vouchers`, `ai_video_watch_sessions`):
  - Không có bất kỳ thay đổi nào được commit.
  - Trạng thái quay trở lại chính xác 100% trước khi bắt đầu transaction.
- **Kết quả:** **PASS**.

---

## 3. Bảng Tổng Hợp Kiểm Thử Chịu Lỗi

| STT | Kịch bản Bơm Lỗi | Hành vi Hệ thống | Kết quả Mong đợi | Trạng thái Thực tế |
|---|---|---|---|---|
| 1 | Server crash sau khi giữ chỗ quota | Quota bị lệch tạm thời | Reconciliation sửa đúng về 0 | **PASS** |
| 2 | Crash sau khi tạo mã trong `voucher` | Trigger rollback tự động | Xóa voucher mồ côi, hoàn quota | **PASS** |
| 3 | Lỗi mạng giữa transaction | Driver abort transaction | Database sạch sẽ không thay đổi | **PASS** |
| 4 | Retry request sau sự cố | Nhận dạng session đã xem | Trả kết quả an toàn (Idempotent) | **PASS** |

---

## 4. Kết luận

Các kịch bản kiểm thử bơm lỗi đã chứng minh khả năng tự phục hồi (Self-Healing) và tính toàn vẹn cấp độ cao của hệ thống SkinSyntaxVN.
