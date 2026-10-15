# DANH SÁCH CÁC TẬP TIN THAY ĐỔI VÀ TẠO MỚI (CHANGED FILES) — PHASE V2C

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Cấp Voucher Thật cho Khách Hàng (Voucher Issuance & Customer Wallet)  
**Ngày:** 13/10/2026  

---

## 1. Tập tin Tạo Mới (New Files)

| Đường dẫn tập tin | Mô tả và Chức năng |
|---|---|
| [backend/app/models/CustomerVoucher.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/CustomerVoucher.php) | Model phụ trách quản lý Ví Voucher khách hàng, logic cấp voucher nguyên tử (`claimReward`), kiểm tra điều kiện (eligibility, campaign, user limit), trừ quota nguyên tử qua `$expr`, tạo voucher thực thi trong collection `voucher`, cơ chế rollback, và tra cứu voucher cá nhân (`getCustomerVouchers`). |
| [backend/tests/test_v2c_reward_issuance.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/tests/test_v2c_reward_issuance.php) | Bộ test tự động toàn diện kiểm thử 17 kịch bản nghiệp vụ, an ninh, concurrency, quota reservation, và regression. |
| [backend/experiments/ai_live_commerce/watch_to_reward/v2c/audit.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c/audit.md) | Báo cáo kiểm toán hiện trạng hệ thống và thiết kế dữ liệu ban đầu. |
| [backend/experiments/ai_live_commerce/watch_to_reward/v2c/issuance_architecture.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c/issuance_architecture.md) | Tài liệu kiến trúc cấp voucher 2 tầng (Two-Tier Entitlement & Execution Model). |
| [backend/experiments/ai_live_commerce/watch_to_reward/v2c/voucher_schema.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c/voucher_schema.md) | Chi tiết schema dữ liệu của `customer_vouchers` và `voucher`, từ điển dữ liệu và chỉ mục MongoDB. |
| [backend/experiments/ai_live_commerce/watch_to_reward/v2c/atomicity_and_idempotency.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c/atomicity_and_idempotency.md) | Cơ chế xử lý đồng thời, trừ quota nguyên tử, chống double-click và hoàn tác bù trừ (Compensating Rollback). |
| [backend/experiments/ai_live_commerce/watch_to_reward/v2c/customer_wallet.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c/customer_wallet.md) | Tài liệu tích hợp Ví Voucher trên trang tài khoản người dùng (`hoso.php`) và phân lập dữ liệu người dùng. |
| [backend/experiments/ai_live_commerce/watch_to_reward/v2c/security_tests.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c/security_tests.md) | Báo cáo kiểm thử an ninh ứng dụng và ngăn chặn khai thác lỗ hổng cấp thưởng. |
| [backend/experiments/ai_live_commerce/watch_to_reward/v2c/concurrency_tests.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c/concurrency_tests.md) | Báo cáo thử nghiệm chịu tải, tranh chấp quota và tính bất biến. |
| [backend/experiments/ai_live_commerce/watch_to_reward/v2c/browser_tests.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c/browser_tests.md) | Tài liệu kiểm thử trình duyệt và trải nghiệm người dùng thực tế trên PDP và Ví Voucher. |
| [backend/experiments/ai_live_commerce/watch_to_reward/v2c/regression_tests.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c/regression_tests.md) | Báo cáo kiểm thử không hồi quy, bảo toàn giá sản phẩm, Flash Sale và Checkout. |
| [backend/experiments/ai_live_commerce/watch_to_reward/v2c/changed_files.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c/changed_files.md) | Tổng hợp các tập tin tạo mới và chỉnh sửa. |
| [backend/experiments/ai_live_commerce/watch_to_reward/v2c/final_report.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/v2c/final_report.md) | Báo cáo nghiệm thu hoàn thành Phase V2C và đề xuất kế hoạch Phase V2D. |

---

## 2. Tập tin Chỉnh Sửa (Modified Files)

### 2.1. [backend/app/controllers/VideoWatchController.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/VideoWatchController.php)
- **Nội dung thay đổi:**
  - Bổ sung action `claimReward()`: Tiếp nhận request `POST`, kiểm tra session đăng nhập, gọi `CustomerVoucher::claimReward()` và trả về JSON chuẩn hóa.
  - Bổ sung action `getCustomerVouchers()`: Endpoint JSON lấy danh sách voucher của khách hàng đang đăng nhập.

### 2.2. [backend/app/controllers/TaiKhoanController.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/TaiKhoanController.php)
- **Nội dung thay đổi:**
  - Trong phương thức `hoso()`: Khởi tạo model `CustomerVoucher`, nạp danh sách voucher của khách hàng đăng nhập thông qua `getCustomerVouchers($customerId)` và truyền sang view qua biến `$customerVouchers`.

### 2.3. [frontend/views/chitiet.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/chitiet.php)
- **Nội dung thay đổi:**
  - Bổ sung khu vực hành động nhận voucher `#synaRewardActionArea` trong card video Syna.
  - Tích hợp JavaScript xử lý sự kiện xem video đủ điều kiện (`is_eligible`):
    - Hiển thị nút "Nhận Voucher Ngay" với hiệu ứng thu hút.
    - Xử lý Guest: Hiển thị prompt yêu cầu đăng nhập và nút chuyển hướng an toàn.
    - Xử lý User đã đăng nhập: Chống double-click bằng `isClaiming` và `btn.disabled = true`.
    - Sau khi claim thành công: Hiển thị card chúc mừng chứa mã voucher, nút sao chép mã (Copy to clipboard) và nút mở Ví Voucher cá nhân.
    - Xử lý lỗi quota hết hoặc chiến dịch quá hạn.

### 2.4. [frontend/views/hoso.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/hoso.php)
- **Nội dung thay đổi:**
  - Thêm tab menu "Ví Voucher Của Tôi" với badge hiển thị số lượng voucher khả dụng.
  - Thêm nội dung panel `#vouchers`: Hiển thị danh sách card voucher với đầy đủ thông tin (Mã code, Tỷ lệ giảm, Đơn tối thiểu, Giảm tối đa, Thời hạn hiệu lực, Trạng thái Khả dụng/Đã dùng/Hết hạn, Nút sao chép mã, Nút xem sản phẩm áp dụng).

### 2.5. File Định Tuyến (Routing)
- [index.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/index.php)
- [frontend/public/index.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/public/index.php)
- [backend/public/index.php](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/public/index.php)
- **Nội dung thay đổi:**
  - Bổ sung route `video_watch_claim_reward` $\rightarrow$ `VideoWatchController::claimReward()`.
  - Bổ sung route `customer_vouchers` $\rightarrow$ `VideoWatchController::getCustomerVouchers()`.
