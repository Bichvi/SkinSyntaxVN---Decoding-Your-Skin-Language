# TÍNH NGUYÊN TỬ VÀ KHẢ NĂNG BẤT BIẾN (ATOMICITY & IDEMPOTENCY) — PHASE V2C

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Quản lý Đồng Thời (Concurrency Management) và Cấp Voucher Bất Biến  
**Tác giả:** Senior PHP/MongoDB E-commerce Engineer & Application Security Engineer  
**Ngày:** 13/10/2026  

---

## 1. Vấn đề Thách thức trong Môi trường Thương mại Điện tử Thực tế

Khi triển khai cơ chế Watch-to-Reward cho chiến dịch video AI:
- Hàng nghìn người dùng có thể cùng xem xong video và nhấn nút "Nhận voucher" vào cùng một thời điểm.
- Người dùng có thể click liên tục nhiều lần (double-click/spam click).
- Kết nối mạng chập chờn dẫn tới client retry request nhiều lần sau khi gặp timeout tạm thời.
- **Rủi ro lớn nhất:** Cấp vượt quá ngân sách chiến dịch (`issued_count > total_quota`) hoặc cấp nhiều voucher cho cùng một tài khoản (`user_limit > 1`).

Mô hình "Read-then-Write" thông thường (đọc DB kiểm tra số lượng $\rightarrow$ nếu còn thì insert $\rightarrow$ sau đó tăng số lượng) **hoàn toàn thất bại** trong môi trường có đồng thời (concurrency).

---

## 2. Giải pháp Cấp Thưởng Nguyên tử (Atomic Issuance Architecture)

Chúng tôi đã thiết kế và triển khai cơ chế bảo vệ 3 lớp phối hợp giữa MongoDB Atomic Operators, Unique Constraints và Compensating Rollback:

```
[Request Claim]
      │
      ▼
[Lớp 1: Idempotency Check] ───(Đã có voucher?)───► [Trả về voucher cũ ngay (already_claimed=true)]
      │ (Chưa có)
      ▼
[Lớp 2: Atomic Quota Reservation]
      │ db.ai_video_reward_campaigns.updateOne(
      │   { campaign_id: ..., $expr: { $lt: ["$issued_count", "$total_quota"] } },
      │   { $inc: { issued_count: 1 } }
      │ )
      ├────(Modified == 0)────► [Từ chối: QUOTA_EXHAUSTED]
      ▼ (Thành công - Đã giữ chỗ quota)
[Lớp 3: Tạo Voucher & Entitlement]
      │ Insert voucher & customer_vouchers
      ├────(Có lỗi / Duplicate Index)────► [Compensating Rollback: $inc issued_count: -1]
      ▼ (Thành công)
[Gắn cờ reward_claimed trong session]
      │
      ▼
[Trả kết quả SUCCESS cho Client]
```

---

## 3. Chi tiết Triển khai Kỹ thuật

### 3.1. Atomic Quota Reservation với `$expr` và `$inc`

Thay vì đọc `issued_count` từ database rồi so sánh bằng PHP, lệnh giữ chỗ quota được thực thi tại mức MongoDB Engine trong một thao tác ghi nguyên tử duy nhất:

```php
$reserveResult = $this->db->ai_video_reward_campaigns->updateOne(
    [
        'campaign_id' => $campaignId,
        'is_active' => true,
        '$expr' => [
            '$lt' => ['$issued_count', '$total_quota']
        ]
    ],
    [
        '$inc' => ['issued_count' => 1],
        '$set' => ['updated_at' => new UTCDateTime()]
    ]
);

if ($reserveResult->getModifiedCount() === 0) {
    return [
        'ok' => false,
        'code' => 'QUOTA_EXHAUSTED',
        'message' => 'Rất tiếc, số lượng voucher ưu đãi cho video này đã được nhận hết.'
    ];
}
```

**Tính chất:**
- MongoDB Document-Level Locking đảm bảo chỉ có các request đến trước khi `issued_count < total_quota` mới được sửa đổi document.
- Ngay khi `issued_count` đạt tới `total_quota`, tất cả các request đồng thời khác ngay lập tức nhận về `modifiedCount = 0` và bị từ chối.
- Tuyệt đối không thể xảy ra hiện tượng cấp vượt quota (`issued_count > total_quota`).

### 3.2. Tính Bất biến (Idempotency) đối với Retry và Double-Click

Để xử lý việc retry request từ client hoặc người dùng nhấn nút nhiều lần:
1. **Kiểm tra phiên xem trước:**
   ```php
   $existingSessionVoucher = $this->db->customer_vouchers->findOne([
       'watch_session_id' => $watchSessionId
   ]);
   if ($existingSessionVoucher) {
       return [
           'ok' => true,
           'already_claimed' => true,
           'voucher' => $this->formatVoucher($existingSessionVoucher)
       ];
   }
   ```
2. **Kiểm tra giới hạn tài khoản (Per-User Limit):**
   ```php
   $userClaimCount = $this->db->customer_vouchers->countDocuments([
       'campaign_id' => $campaignId,
       'customer_id' => (string)$customerId
   ]);
   if ($userClaimCount >= $userLimit) {
       return [
           'ok' => false,
           'code' => 'USER_LIMIT_REACHED',
           'message' => 'Bạn đã nhận voucher của chiến dịch này trước đó.'
       ];
   }
   ```
3. **Unique Compound Index trên MongoDB:**
   - Index `{'campaign_id': 1, 'customer_id': 1}` (UNIQUE)
   - Index `{'watch_session_id': 1}` (UNIQUE)
   - Nếu hai request từ cùng một user lọt qua tầng kiểm tra cùng một mili giây, unique constraint ở MongoDB sẽ ném `MongoDB\Driver\Exception\BulkWriteException (E11000 duplicate key error)`.

### 3.3. Cơ chế Bù trừ Hoàn tác (Compensating Rollback)

Nếu quá trình tạo mã voucher thực thi hoặc ghi nhận entitlement gặp trục trặc (ví dụ đụng unique index, lỗi kết nối DB, v.v.), server bắt buộc phải hoàn trả quota đã giữ chỗ:

```php
try {
    // 1. Tạo voucher trong collection voucher
    // 2. Tạo entitlement trong collection customer_vouchers
} catch (\Throwable $e) {
    // Hoàn tác tăng quota
    $this->db->ai_video_reward_campaigns->updateOne(
        ['campaign_id' => $campaignId],
        [
            '$inc' => ['issued_count' => -1],
            '$set' => ['updated_at' => new UTCDateTime()]
        ]
    );
    throw $e;
}
```

Điều này ngăn ngừa hiện tượng "rò rỉ quota" (quota leak), đảm bảo `issued_count` luôn phản ánh chính xác số lượng voucher thực tế được cấp thành công.

---

## 4. Kết quả Thực nghiệm (Test Evidence)

Các kịch bản kiểm thử đồng thời và idempotency trong `test_v2c_reward_issuance.php` đã xác minh:
1. **Double-click claim:** Gọi `claimReward()` 2 lần liên tiếp trên cùng 1 session $\rightarrow$ Trả về `already_claimed: true`, không tạo bản ghi thứ 2 (`[PASS] 5.1`).
2. **Quota race condition khi quota còn đúng 1:** Cùng lúc User X và User Y claim $\rightarrow$ User X thành công, User Y bị từ chối `QUOTA_EXHAUSTED` (`[PASS] 7.1`).
3. **Bảo toàn số lượng Quota:** `issued_count` dừng chính xác ở số quota cho phép, không lệch 1 đơn vị nào (`[PASS] 7.2`).
4. **Retry request sau timeout:** Trả về voucher ban đầu an toàn (`[PASS] 8.1`).
