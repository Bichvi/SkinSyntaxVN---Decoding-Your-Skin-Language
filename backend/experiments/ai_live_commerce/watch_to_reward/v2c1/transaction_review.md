# ĐÁNH GIÁ NĂNG LỰC GIAO DỊCH MONGODB (TRANSACTION REVIEW) — PHASE V2C.1

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Khảo sát và Đánh giá Năng lực Giao dịch Multi-Document ACID Transactions  
**Tác giả:** Senior PHP/MongoDB Transaction Engineer  
**Ngày:** 13/10/2026  
**Kết luận:** **MULTI-DOCUMENT TRANSACTIONS FULLY SUPPORTED & VERIFIED**  

---

## 1. Kết quả Khảo sát Môi trường MongoDB Deployment

Qua script kiểm tra thực tế `backend/tests/verify_transactions_and_indexes.php` thực thi trên Docker container `skinsyntax-php` kết nối trực tiếp với MongoDB Cluster:

```text
=== MONGODB DEPLOYMENT & TRANSACTION CAPABILITY AUDIT ===
Topology / setName: atlas-l1ar0n-shard-0
isWritablePrimary / ismaster: true
msg: N/A

=== MULTI-COLLECTION TRANSACTION TEST ===
Committed counts: c1=1, c2=1 (Expect 1, 1)
Aborted rollback counts: c1=0, c2=0 (Expect 0, 0)
MULTI-COLLECTION ATOMIC TRANSACTIONS VERIFIED 100% WORKING!
```

- **Topology:** Hệ thống đang kết nối trực tiếp tới **MongoDB Atlas Replica Set** (Shard Replica Set: `atlas-l1ar0n-shard-0`).
- **PHP Driver Support:** Thư viện `mongodb/mongodb` và PHP MongoDB extension 1.15+ hỗ trợ đầy đủ các hàm Client Sessions:
  - `$session = $client->startSession()`
  - `$session->startTransaction([...])`
  - `$session->commitTransaction()`
  - `$session->abortTransaction()`

---

## 2. Kiến trúc Giao dịch Đa Collection Được Triển Khai

Trong [`CustomerVoucher.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/CustomerVoucher.php), phương thức `executeWithTransaction()` bao bọc toàn bộ 6 bước phát hành trong một phiên giao dịch đơn lẻ:

```php
$session = $this->client->startSession();
$session->startTransaction([
    'readConcern'  => new \MongoDB\Driver\ReadConcern('local'),
    'writeConcern' => new \MongoDB\Driver\WriteConcern('majority'),
]);

try {
    // 1. Kiểm tra session idempotency trong transaction snapshot
    // 2. Kiểm tra user_limit theo số lượng voucher hiện có
    // 3. Trừ quota nguyên tử trên ai_video_reward_campaigns (với ['session' => $session])
    // 4. Tạo execution voucher trong voucher (với ['session' => $session])
    // 5. Tạo entitlement trong customer_vouchers (với ['session' => $session])
    // 6. Cập nhật watch_state = REWARDED trên ai_video_watch_sessions (với ['session' => $session])

    // Commit toàn bộ
    $session->commitTransaction();
} catch (\Throwable $e) {
    $session->abortTransaction();
    // Xử lý retry nếu gặp TransientTransactionError
}
```

---

## 3. Các Cơ chế Bảo đảm Độ Tin cậy (Reliability Mechanisms)

1. **Write Concern Majority:**
   Mọi thao tác ghi trong transaction sử dụng Write Concern `majority`. Đảm bảo dữ liệu đã được nhân bản đồng thuận (replicated) trên đa số các node trong Atlas Replica Set trước khi trả về thành công cho client.

2. **Xử lý TransientTransactionError:**
   Nếu gặp xung đột ghi (Write Conflict) do nhiều tiến trình cạnh tranh trên cùng một document chiến dịch, MongoDB driver sẽ gán nhãn lỗi `TransientTransactionError`.
   Hệ thống có cơ chế retry thông minh với exponential backoff (tối đa 2 lần thử) trước khi trả lỗi xác định cho người dùng.

3. **Chống Retry Mù quáng (Blind Retries Prevention):**
   Nếu lỗi phát sinh là lỗi vi phạm logic nghiệp vụ (ví dụ: `QUOTA_EXHAUSTED`, `USER_LIMIT_REACHED`, hoặc vi phạm ràng buộc Unique Key `E11000`), hệ thống **tuyệt đối không retry**, mà abort transaction lập tức và trả thông báo rõ ràng cho client.

4. **Cơ chế Phục hồi khi không có Transaction (Graceful Non-Transactional Fallback):**
   Trong trường hợp hệ thống chạy trên môi trường không hỗ trợ Replica Set (ví dụ: một số môi trường kiểm thử Local MongoDB Standalone cũ), hệ thống tự động kích hoạt `executeWithCompensatingFallback()`:
   - Trừ quota nguyên tử qua `$expr`.
   - Tạo voucher thực thi.
   - Nếu tạo entitlement thất bại: Ngay lập tức thực thi **Compensating Rollback**: xóa voucher vừa tạo trong `voucher` và hoàn trả quota (`$inc: -1`) trên campaign.

---

## 4. Kết luận

Hệ thống SkinSyntaxVN tại Phase V2C.1 đạt mức độ toàn vẹn **FULL ATOMIC** nhờ sự hỗ trợ đa văn bản (multi-document ACID) thực tế từ MongoDB Atlas, kết hợp cùng cơ chế phòng vệ bù trừ hai lớp (two-layer defense-in-depth).
