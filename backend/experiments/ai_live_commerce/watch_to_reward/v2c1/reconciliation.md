# CƠ CHẾ ĐỐI SOÁT VÀ CHỮA LÀNH DỮ LIỆU (RECONCILIATION & DATA REPAIR) — PHASE V2C.1

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Dịch vụ Đối Soát Hạn Mức và Xử Lý Dữ Liệu Rác (Quota Audit & Orphan Eradication Service)  
**Tác giả:** Senior Data Integrity & Transaction Engineer  
**Ngày:** 13/10/2026  

---

## 1. Lý do Cần Cơ chế Đối Soát (Reconciliation)

Trong một hệ thống phân tán chịu tải cao:
- Dù đã có Multi-Document Transactions và Compensating Rollback, các sự cố ngoại cảnh (ví dụ: máy chủ backend sập nguồn đột ngột giữa chừng khi mạng gián đoạn, hoặc admin can thiệp dữ liệu thủ công) vẫn có thể dẫn tới hiện tượng lệch số liệu (drift):
  - `issued_count` trên campaign lớn hơn số lượng voucher thực tế cấp cho khách.
  - Tồn tại mã trong `voucher` mà không có bản ghi tương ứng trong `customer_vouchers` (Voucher mồ côi).
  - Tồn tại entitlement trỏ tới mã voucher không tồn tại.
- Để đảm bảo ngân sách khuyến mãi và tính minh bạch tài chính, hệ thống cần một dịch vụ **Đối soát & Chữa lành Tự động (Audit & Self-Healing)**.

---

## 2. Thiết kế Dịch vụ Đối Soát (`reconcileCampaignQuota()`)

Trong [`CustomerVoucher.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/CustomerVoucher.php), chúng tôi tích hợp phương thức `reconcileCampaignQuota(string $campaignId)` thực hiện 4 bước đối soát toàn diện:

```
[Bắt đầu đối soát Campaign: campaign_id]
           │
           ▼
[Bước 1: Đếm số lượng Entitlement Hợp Lệ]
countDocuments(customer_vouchers, {campaign_id: id}) -> actual_count
           │
           ▼
[Bước 2: Quét Tìm Voucher Mồ Côi trong collection voucher]
Quét tất cả document trong voucher có {campaign_id: id}
So khớp từng mã với customer_vouchers
Nếu không tồn tại entitlement tương ứng -> Gắn cờ Orphan Voucher!
           │
           ▼
[Bước 3: Dọn Dẹp / Xóa Sạch Voucher Mồ Côi]
deleteMany(voucher, {ma_code: {$in: orphan_codes}})
           │
           ▼
[Bước 4: Cân Chỉnh Lại issued_count trên Campaign nếu có Drift]
Nếu issued_count != actual_count:
updateOne(ai_video_reward_campaigns, {$set: {issued_count: actual_count}})
           │
           ▼
[Trả về Báo cáo Đối Soát Chi Tiết]
```

### 2.1. Cấu trúc Kết quả Trả về

```json
{
  "ok": true,
  "campaign_id": "rew_4d1749723c",
  "previous_issued_count": 1,
  "actual_entitlements_count": 0,
  "reconciled": true,
  "orphans_detected": 0,
  "orphans_cleaned": 0,
  "clean": false
}
```

---

## 3. Kết quả Thực nghiệm Thực tế

Trong bộ test `test_v2c1_failure_and_security.php`:

### 3.1. Đối Soát Sau Khi Bơm Lỗi Crash Quota
- Mô phỏng crash làm `issued_count` tăng lên 1 trong khi chưa có voucher nào được tạo.
- Chạy `reconcileCampaignQuota()`:
  ```text
  [PASS] 1.2 Reconciliation phát hiện drift và hoàn trả quota về đúng 0
         -> Previous issued: 1 -> Reconciled to: 0
  ```
- Quota bị giữ sai đã được trả lại tự động cho người dùng khác!

### 3.2. Đối Soát Tổng Thể Toàn Hệ Thống
- Sau khi kết thúc tất cả các bài kiểm tra giao dịch và concurrency:
  ```text
  --- 7. KIỂM TRA TÍNH TOÀN VẸN VÀ KHÔNG CÓ BẢN GHI MỒ CÔI (AUDIT & RECONCILIATION) ---
   [PASS] 7.1 Kiểm toán toàn diện: Zero voucher mồ côi, Zero quota lệch
          -> Actual: 3, Orphans: 0, Clean: true
  ```
- **Kết quả:** Không có bất kỳ voucher mồ côi nào, số lượng `issued_count` trên campaign khớp 100% với số lượng bản ghi trong `customer_vouchers`.

---

## 4. Khuyến nghị Vận hành Tự động (Ops Recommendation)

1. **Scheduled Cron Job:** Thiết lập một cron job chạy định kỳ mỗi 6 giờ gọi lệnh đối soát cho tất cả các chiến dịch đang hoạt động (`is_active: true`) để đảm bảo phát hiện và khắc phục ngay mọi sai lệch bất thường.
2. **Admin Dashboard Alert:** Nếu phát hiện sai lệch quota hoặc có voucher mồ côi bị dọn dẹp, hệ thống tự động ghi nhật ký vào hệ thống monitoring để đội ngũ kỹ thuật rà soát nguyên nhân gốc rễ.
