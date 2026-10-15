# ĐÁNH GIÁ CHỈ MỤC DUY NHẤT VÀ TÍNH TƯƠNG THÍCH USER_LIMIT (UNIQUE INDEX REVIEW) — PHASE V2C.1

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Kiểm toán Index MongoDB & Tương thích Giới hạn Khách hàng (`user_limit`)  
**Tác giả:** Senior MongoDB Architect & Application Security Engineer  
**Ngày:** 13/10/2026  

---

## 1. Hiện trạng Kiểm toán Index Thực tế trên MongoDB

Kết quả truy vấn trực tiếp chỉ mục của collection `customer_vouchers` qua lệnh `listIndexes()`:

```json
[
  { "name": "_id_", "key": { "_id": 1 }, "unique": false },
  { "name": "campaign_id_1_customer_id_1_claim_seq_1", "key": { "campaign_id": 1, "customer_id": 1, "claim_seq": 1 }, "unique": true },
  { "name": "watch_session_id_1", "key": { "watch_session_id": 1 }, "unique": true },
  { "name": "voucher_code_1", "key": { "voucher_code": 1 }, "unique": true },
  { "name": "customer_id_1_status_1", "key": { "customer_id": 1, "status": 1 }, "unique": false },
  { "name": "created_at_-1", "key": { "created_at": -1 }, "unique": false }
]
```

---

## 2. Phát hiện Vấn đề Không Tương Thích Schema Ban Đầu

### 2.1. Điểm Xung Đột (Schema Conflict) trong Thiết kế Cũ
Trong thiết kế ban đầu của Phase V2C:
- Index trên `customer_vouchers` được cấu hình là:
  `{'campaign_id': 1, 'customer_id': 1}` **[UNIQUE]**
- Mục đích ban đầu: Chặn đứng nguy cơ một người dùng nhận nhiều voucher trên cùng một chiến dịch ở mức database constraint.
- **Xung đột thực tế:**
  Model chiến dịch [`VideoRewardCampaign.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/VideoRewardCampaign.php) và giao diện quản trị Admin cho phép thiết lập trường `user_limit`:
  `user_limit = 1`, `user_limit = 2`, `user_limit = 3`, v.v.
  Khi Admin cấu hình `user_limit = 2` (cho phép khách hàng xem nhiều video hoặc nhiều phần video để nhận tối đa 2 voucher):
  - Lần xem thứ 1 $\rightarrow$ Claim thành công (Tạo 1 document với `campaign_id`, `customer_id`).
  - Lần xem thứ 2 $\rightarrow$ Logic code cho phép vì `claimedCount (1) < user_limit (2)`.
  - Nhưng khi insert vào MongoDB $\rightarrow$ **Ném ngoại lệ `E11000 duplicate key error on campaign_id_1_customer_id_1`!**
  - Khách hàng không thể nhận voucher thứ hai dù Admin cấu hình hợp lệ!

---

## 3. Giải pháp Tái Cấu trúc (Architectural Refactoring)

Để hỗ trợ đầy đủ và an toàn cho mọi giá trị `user_limit \ge 1` mà vẫn giữ nguyên khả năng chống race condition ở tầng database engine:

### 3.1. Bổ sung Trường `claim_seq` (Sequence Slot)
- Thêm trường `claim_seq` kiểu số nguyên (`int`: 1, 2, ..., N) vào schema của `customer_vouchers` và `voucher`.
- Khi khách hàng claim lần đầu tiên trên chiến dịch: `claim_seq = 1`.
- Khi khách hàng xem tiếp video hợp lệ khác và claim lần thứ hai: `claim_seq = 2`.
- Tối đa đến `user_limit`.

### 3.2. Nâng cấp Unique Compound Index
- Loại bỏ index cứng nhắc `{'campaign_id': 1, 'customer_id': 1}`.
- Thay thế bằng **Compound Unique Index**:
  ```javascript
  db.customer_vouchers.createIndex(
      { campaign_id: 1, customer_id: 1, claim_seq: 1 },
      { unique: true }
  );
  ```

### 3.3. Cơ chế Bảo vệ Đồng thời (Concurrency Guard)
- **Nếu `user_limit = 1`:**
  - Khách hàng chỉ có thể tạo bản ghi với `claim_seq = 1`.
  - Mọi yêu cầu trùng lặp hoặc concurrent request cạnh tranh cho slot 1 sẽ bị chặn đứng ngay bởi unique constraint `(campaign_id, customer_id, 1)`.
- **Nếu `user_limit > 1` (ví dụ: `user_limit = 2`):**
  - Khách hàng hoàn tất phiên xem thứ 2 sẽ được cấp slot `claim_seq = 2` an toàn.
  - Hai tab cùng claim cho slot 2 sẽ chỉ có 1 tab thành công, tab còn lại bị unique index chặn.
  - Khi đã có 2 bản ghi (`claim_seq = 1` và `claim_seq = 2`), yêu cầu claim thứ 3 sẽ bị logic transaction chặn ngay lập tức với mã lỗi `USER_LIMIT_REACHED`.

---

## 4. Kết quả Thực nghiệm (Test Evidence)

Trích xuất kết quả kiểm thử thực tế từ `test_v2c1_failure_and_security.php`:

```text
--- 5. SUPPORT FOR USER_LIMIT > 1 VIA CLAIM_SEQ ---
 [PASS] 5.1 Khi Admin cấu hình user_limit = 2, User 1 nhận thành công voucher thứ 2
        -> Voucher 2 Code: SYNA-343FC1AE, Claim Seq: 2
 [PASS] 5.2 Database lưu trữ chính xác 2 voucher cho User 1 với claim_seq khác nhau
        -> Customer vouchers count = 2
 [PASS] 5.3 Khi vượt quá user_limit = 2, User 1 bị chặn với USER_LIMIT_REACHED
        -> Code: USER_LIMIT_REACHED
```

---

## 5. Kết luận

Chỉ mục duy nhất đã được nâng cấp thành công, đảm bảo tính nhất quán hoàn hảo 100% giữa quyền cấu hình `user_limit` của Admin và tính toàn vẹn dữ liệu của MongoDB Atlas.
