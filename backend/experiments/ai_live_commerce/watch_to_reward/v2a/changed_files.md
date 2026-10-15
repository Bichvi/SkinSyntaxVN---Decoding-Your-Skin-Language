# CHANGED FILES LOG — PHASE V2A

**Phase:** V2A — Video Reward Campaign Admin  
**Repository:** SkinSyntaxVN  
**Date:** October 13, 2026  

---

## 1. Danh sách Tệp mới tạo (Created Files)

1. **`backend/app/models/VideoRewardCampaign.php`**
   - Domain Model quản lý collection `ai_video_reward_campaigns`.
   - Thiết lập chỉ mục MongoDB (unique index `campaign_id`, `video_id`, `is_active`).
   - Cung cấp các phương thức CRUD an toàn: `saveCampaign()`, `toggleActive()`, `getCampaignById()`, `getCampaignByVideoId()`, `listCampaigns()`, `deleteCampaign()`.
   - Thực thi các ràng buộc nghiệp vụ: video READY & đã xuất bản, 1 active campaign/video, giới hạn tỉ lệ chiết khấu, ràng buộc ngày tháng, bảo vệ hạn ngạch không giảm dưới số lượng đã phát.

2. **`backend/tests/test_v2a_reward_campaign.php`**
   - Bộ kiểm thử tự động toàn diện gồm 26 assertions kiểm tra toàn bộ logic nghiệp vụ, bảo mật RBAC, CSRF và kiểm tra hồi quy không chạm vào giá sản phẩm hay collection `voucher`.

3. **`backend/experiments/ai_live_commerce/watch_to_reward/v2a/audit.md`**
   - Báo cáo kiểm toán kiến trúc, rà soát các collection `voucher`, `khach_hang`, `ai_product_videos`, và các chính sách bảo mật/Flash Sale hiện hành.

4. **`backend/experiments/ai_live_commerce/watch_to_reward/v2a/schema.md`**
   - Đặc tả chi tiết lược đồ dữ liệu MongoDB `ai_video_reward_campaigns`, kiểu dữ liệu, ràng buộc, chỉ mục và document mẫu.

5. **`backend/experiments/ai_live_commerce/watch_to_reward/v2a/admin_ui.md`**
   - Tài liệu mô tả giao diện Admin mở rộng tại `flow=publication`, thẻ video xuất bản, modal cấu hình `#rewardModal`, và quy trình thao tác.

6. **`backend/experiments/ai_live_commerce/watch_to_reward/v2a/security_tests.md`**
   - Báo cáo kiểm thử bảo mật: RBAC, CSRF, server validation, chống NoSQL injection và chống submit trùng lặp.

7. **`backend/experiments/ai_live_commerce/watch_to_reward/v2a/regression_tests.md`**
   - Báo cáo kiểm tra hồi quy xác nhận giá sản phẩm, quy trình checkout và số lượng voucher hiện có được bảo toàn nguyên vẹn.

8. **`backend/experiments/ai_live_commerce/watch_to_reward/v2a/changed_files.md`**
   - Nhật ký theo dõi toàn bộ các tệp thay đổi và thêm mới trong phase này.

9. **`backend/experiments/ai_live_commerce/watch_to_reward/v2a/final_report.md`**
   - Báo cáo nghiệm thu tổng thể Phase V2A với trạng thái FULL PASS.

---

## 2. Danh sách Tệp đã cập nhật (Modified Files)

1. **`backend/app/controllers/AdminAiIdolController.php`**
   - Bổ sung logic gắn thông tin `reward_campaign` cho từng video trong `listProductVideos()`.
   - Bổ sung 3 endpoint controller được bảo vệ bởi Admin RBAC và CSRF:
     - `getRewardCampaign()`: Lấy cấu hình chiến dịch voucher và dữ liệu video.
     - `saveRewardCampaign()`: Xác thực CSRF và lưu/cập nhật cấu hình chiến dịch.
     - `toggleRewardCampaign()`: Xác thực CSRF và bật/tắt nhanh chiến dịch.

2. **`backend/app/routes/ai_idol.php`**
   - Đăng ký 3 route mới vào bảng điều hướng:
     - `'admin_ai_idol_reward_campaign' => ['GET', 'getRewardCampaign']`
     - `'admin_ai_idol_save_reward_campaign' => ['POST', 'saveRewardCampaign']`
     - `'admin_ai_idol_toggle_reward_campaign' => ['POST', 'toggleRewardCampaign']`

3. **`index.php`**
   - Bổ sung 3 route mới vào whitelist route `in_array($r, [...], true)` tại cổng vào root.

4. **`backend/public/index.php`**
   - Bổ sung 3 route mới vào whitelist route `in_array($r, [...], true)` tại cổng vào backend.

5. **`frontend/public/index.php`**
   - Bổ sung 3 route mới vào whitelist route `in_array($r, [...], true)` tại cổng vào frontend.

6. **`frontend/views/admin/ai_idol/index.php`**
   - Thêm modal `#rewardModal` và lớp phủ `#rewardModalBackdrop` với đầy đủ các trường cấu hình: Tên chiến dịch, loại giảm giá, mức giảm, giảm tối đa, đơn hàng tối thiểu, ngày bắt đầu/kết thúc, hạn ngạch, giới hạn/user, stacking chính sách, bật/tắt active, chọn sản phẩm áp dụng và khung hiển thị thống kê thực tế `#rewardStatsCard`.

7. **`frontend/public/assets/css/ai-idol-studio.css`**
   - Bổ sung CSS định dạng cho `.reward-modal`, `.reward-stats-card`, `.reward-stats-grid`, `.reward-stat-box`, `.pub-badge-status.reward-active`, `.pub-badge-status.reward-inactive`, và `.pub-card-reward-box`.

8. **`frontend/public/assets/js/ai-idol-studio.js`**
   - Cập nhật hàm `renderPublicationVideos()` để hiển thị huy hiệu chiến dịch voucher và số liệu phát hành thực tế (chỉ khi có dữ liệu thật).
   - Thêm nút "Cấu hình voucher xem video" trên các thẻ video đã xuất bản và đạt trạng thái READY.
   - Bổ sung toàn bộ logic mở modal (`openRewardModal()`), đóng modal, cập nhật giao diện theo loại giảm giá, lọc/chọn sản phẩm áp dụng, gửi dữ liệu cấu hình lên server kèm CSRF và bật/tắt kích hoạt chiến dịch.
