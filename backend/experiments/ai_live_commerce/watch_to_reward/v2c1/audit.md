# BÁO CÁO KIỂM TOÁN MÃ NGUỒN VÀ LUỒNG PHÁT HÀNH (AUDIT & TRACE) — PHASE V2C.1

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Kiểm toán Tính Nguyên tử và Rà soát An ninh Cấp Thưởng (Voucher Issuance Atomicity & Security Review)  
**Tác giả:** Senior PHP/MongoDB Transaction Engineer & E-commerce Security Engineer  
**Ngày:** 13/10/2026  
**Trạng thái:** Hoàn tất kiểm toán & Đã gia cố (Hardened & Audited)  

---

## 1. Mục tiêu Kiểm toán

Trong Phase V2C, hệ thống đã triển khai thành công tính năng cấp voucher thật cho khách hàng xem video Syna hợp lệ.
Mục tiêu của **Phase V2C.1** là rà soát chuyên sâu cấp độ giao dịch (transaction level), kiểm tra tính bất biến (idempotency), phân tích các điểm có thể thất bại (failure points), xác minh năng lực giao dịch thực tế của MongoDB Atlas và thiết lập hợp đồng bảo mật chuẩn bị cho Phase V2D.

---

## 2. Danh mục Thành phần Kiểm toán Thực tế

| Thành phần / Tệp tin | Vai trò trong hệ thống | Đánh giá hiện trạng kiểm toán |
|---|---|---|
| [`CustomerVoucher.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/CustomerVoucher.php) | Model nghiệp vụ quản lý cấp voucher và ví khách hàng | Đã nâng cấp hỗ trợ MongoDB Multi-Document ACID Transactions, loại trừ hoàn toàn voucher mồ côi, bổ sung `claim_seq` hỗ trợ `user_limit > 1`, và tích hợp công cụ reconciliation. |
| [`VideoWatchController.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/VideoWatchController.php) | Endpoint điều khiển tiếp nhận yêu cầu claim từ browser | Xác thực đăng nhập qua session server-side, bảo vệ chống mạo danh ID. |
| [`VideoWatchSession.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/VideoWatchSession.php) | Theo dõi và xác nhận điều kiện hoàn thành xem video | Xác thực coverage $\ge 95\%$ và tail reach trước khi cho phép gắn nhãn `ELIGIBLE`. |
| [`VideoRewardCampaign.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/VideoRewardCampaign.php) | Quản lý cấu hình chiến dịch, quota, giới hạn và stacking | Xác định `total_quota`, `issued_count`, `user_limit`, `stack_with_flash_sale`, `stack_with_other_vouchers`. |
| [`Voucher.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/Voucher.php) | Model voucher thực thi truyền thống của sàn | Duy trì cấu trúc chuẩn `so_luong = 1` cho mã cá nhân, không bị xáo trộn schema chung. |
| **Collection `customer_vouchers`** | Lưu trữ quyền sở hữu voucher (Entitlement / Wallet) | Đã cập nhật Unique Compound Index: `['campaign_id', 'customer_id', 'claim_seq']`, `['watch_session_id']`, `['voucher_code']`. |
| **Collection `voucher`** | Lưu trữ mã voucher thực thi | Sinh mã ngẫu nhiên duy nhất `SYNA-XXXXXXXX` với `so_luong = 1`. |
| **Collection `ai_video_reward_campaigns`** | Cấu hình chiến dịch và hạn mức phát hành | Lưu trữ `total_quota` và `issued_count` phục vụ trừ quota nguyên tử. |

---

## 3. Phân tích Luồng Dữ liệu và Điểm Thất bại Tiềm ẩn (Trace & Failure Points)

### 3.1. Luồng Phát hành Chuẩn
```
[1. Watch Session ELIGIBLE]
           │
           ▼
[2. Authenticated Customer Identity] (Server Session $_SESSION['user']['id'])
           │
           ▼
[3. Session Idempotency & User Limit Verification]
           │
           ▼
[4. Atomic Quota Reservation] ($expr: issued_count < total_quota)
           │
           ▼
[5. Create Execution Voucher] (Collection voucher, so_luong = 1)
           │
           ▼
[6. Create Customer Entitlement] (Collection customer_vouchers, status = AVAILABLE)
           │
           ▼
[7. Transition Session State] (ai_video_watch_sessions -> REWARDED)
           │
           ▼
[8. Success Response Payload] (JSON to Client)
```

### 3.2. Rà soát Các Điểm Thất bại Tiềm ẩn Được Phát hiện và Khắc phục

1. **Điểm Thất bại 1 (Crash sau Quota Reservation):**
   - *Nguy cơ:* Nếu server crash hoặc connection drop ngay sau khi tăng `issued_count` nhưng trước khi tạo voucher, quota sẽ bị mất mà khách không nhận được voucher ("quota leak").
   - *Giải pháp gia cố:* Trong môi trường MongoDB Atlas, bọc toàn bộ chuỗi thao tác trong Multi-Document Transaction. Nếu xảy ra ngắt quãng, transaction tự động abort tại database engine. Đồng thời, cung cấp hàm `reconcileCampaignQuota()` để tự động phát hiện độ lệch giữa `issued_count` và số lượng entitlement thực tế.

2. **Điểm Thất bại 2 (Crash sau khi Tạo Execution Voucher trong `voucher`):**
   - *Nguy cơ:* Tạo thành công bản ghi trong `voucher`, nhưng quá trình insert vào `customer_vouchers` bị crash hoặc đụng unique key.
   - *Giải pháp gia cố:*
     - Khi dùng Transaction: Toàn bộ bản ghi trong `voucher` và `customer_vouchers` cùng rollback về 0.
     - Khi chạy Fallback không dùng Transaction: Trong khối `catch (\Throwable $e)`, bổ sung lệnh xóa sạch voucher vừa tạo:
       `$this->db->voucher->deleteOne(['ma_code' => $voucherCode]);`
       Đảm bảo không bao giờ để lại **Voucher mồ côi (Orphan Voucher)**.

3. **Điểm Thất bại 3 (Xung đột Cấu hình `user_limit > 1` với Unique Index):**
   - *Nguy cơ:* Unique index cũ `['campaign_id' => 1, 'customer_id' => 1]` ngăn cản khách hàng nhận voucher thứ hai ngay cả khi Admin cấu hình `user_limit = 2` hoặc `3`.
   - *Giải pháp gia cố:* Bổ sung trường `claim_seq` và nâng cấp unique index thành `['campaign_id' => 1, 'customer_id' => 1, 'claim_seq' => 1]`.

---

## 4. Kết luận Đánh giá Audit

Hệ thống đã nhận diện chính xác toàn bộ các điểm nghẽn rủi ro và hoàn thành tái cấu trúc an toàn ở tầng Database Engine, sẵn sàng cho các bài kiểm tra failure injection và concurrency thực tế.
