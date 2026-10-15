# DANH SÁCH CÁC TẬP TIN THAY ĐỔI VÀ TẠO MỚI (CHANGED FILES) — PHASE V2C.1

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Cấp Voucher Thật, Tính Nguyên Tử Giao Dịch và An Ninh (Phase V2C.1)  
**Ngày:** 13/10/2026  

---

## 1. Tập tin Chỉnh sửa Cốt lõi (Modified Core Files)

| Đường dẫn tập tin | Nội dung và Lý do Chỉnh sửa |
|---|---|
| [backend/app/models/CustomerVoucher.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/CustomerVoucher.php) | **Gia cố cấp độ giao dịch & an ninh:**<br>1. Tích hợp MongoDB Client Session & Multi-Document ACID Transactions (`executeWithTransaction`).<br>2. Nâng cấp chỉ mục duy nhất từ `(campaign_id, customer_id)` sang `(campaign_id, customer_id, claim_seq)` để giải quyết xung đột khi Admin thiết lập `user_limit > 1`.<br>3. Thêm cơ chế xóa sạch voucher mồ côi (`deleteOne` trong `voucher`) khi quá trình tạo entitlement gặp lỗi trong chế độ fallback.<br>4. Bổ sung phương thức `reconcileCampaignQuota(string $campaignId)` để phát hiện độ lệch quota và dọn dẹp voucher rác.<br>5. Bổ sung tham số giả lập lỗi phục vụ kiểm thử Failure Injection (`simulateCrashAfterReservation`, `simulateCrashAfterVoucherInsert`). |

---

## 2. Tập tin Kiểm thử Tạo Mới (New Test Files)

| Đường dẫn tập tin | Mô tả và Chức năng |
|---|---|
| [backend/tests/test_v2c1_failure_and_security.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/tests/test_v2c1_failure_and_security.php) | Bộ test tự động kiểm thử toàn diện 19 kịch bản: Bơm lỗi crash sau reservation, crash sau tạo execution voucher, Multi-Document Transaction atomicity, Idempotency, hỗ trợ `user_limit > 1` qua `claim_seq`, tranh chấp quota tại biên (quota = 1), audit & reconciliation, và regression. |
| [backend/tests/verify_transactions_and_indexes.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/tests/verify_transactions_and_indexes.php) | Script khảo sát topology MongoDB Atlas, kiểm tra danh sách index thực tế, và xác minh khả năng thực thi transaction multi-collection. |
| [backend/tests/restore_canonical.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/tests/restore_canonical.php) | Script dọn dẹp dữ liệu kiểm thử và khôi phục trạng thái chuẩn cho các chiến dịch demo (`rew_141ad8d65d`: inactive, `rew_7e6ada3dc3`: active). |

---

## 3. Tập tin Tài liệu Báo cáo Kỹ thuật (Technical Documentation)

Thư mục lưu trữ:  
`backend/experiments/ai_live_commerce/watch_to_reward/v2c1/`

| Đường dẫn tập tin | Nội dung chi tiết |
|---|---|
| [audit.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c1/audit.md) | Báo cáo kiểm toán luồng phát hành và các điểm có thể thất bại. |
| [transaction_review.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c1/transaction_review.md) | Đánh giá năng lực thực thi Multi-Document ACID Transactions trên MongoDB Atlas Replica Set. |
| [unique_index_review.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c1/unique_index_review.md) | Phân tích xung đột schema giữa `user_limit > 1` và giải pháp nâng cấp unique compound index `claim_seq`. |
| [failure_injection_tests.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c1/failure_injection_tests.md) | Báo cáo thử nghiệm bơm lỗi crash server, drop socket và cơ chế tự phục hồi. |
| [concurrency_tests.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c1/concurrency_tests.md) | Báo cáo kiểm thử đồng thời, tranh chấp quota tại biên và double-click. |
| [reconciliation.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c1/reconciliation.md) | Chi tiết cơ chế đối soát hạn mức tự động và dọn dẹp bản ghi rác. |
| [checkout_security_contract.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c1/checkout_security_contract.md) | Hợp đồng kỹ thuật và 7 điều khoản bắt buộc cho việc tích hợp Checkout ở Phase V2D. |
| [regression_tests.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c1/regression_tests.md) | Báo cáo kiểm thử hồi quy xác minh tính toàn vẹn của toàn sàn. |
| [changed_files.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c1/changed_files.md) | Danh sách tổng hợp các tập tin thay đổi và tạo mới. |
| [final_report.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c1/final_report.md) | Báo cáo tổng kết nghiệm thu hoàn tất Phase V2C.1. |
