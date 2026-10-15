# CHANGED FILES LOG — PHASE V2B

**Phase:** V2B — Real Video Watch Tracking & Eligibility Verification  
**Repository:** SkinSyntaxVN  
**Date:** October 13, 2026  

---

## 1. Danh sách Tệp mới tạo (Created Files)

1. **`backend/app/models/VideoWatchSession.php`**
   - Domain Model quản lý collection `ai_video_watch_sessions`.
   - Sinh token session ngẫu nhiên khó đoán (`bin2hex(random_bytes(16))`).
   - Cài đặt thuật toán hợp khoảng thời gian `mergeIntervals()` và tính độ phủ thực tế `watched_coverage_percent`.
   - Xử lý xác thực rate-limiting heartbeat, phát hiện tua nhanh/seek, đối chiếu server elapsed time với playback speed (tối đa 2.0x).
   - Tự động gắn cờ `ELIGIBLE` khi đạt ngưỡng $\ge 95\%$ hoặc thiếu $< 2.5$ giây so với thời lượng video.
   - Hoàn toàn tách biệt khỏi việc tạo voucher hoặc sửa quota.

2. **`backend/app/controllers/VideoWatchController.php`**
   - Controller tiếp nhận và điều phối 4 API endpoints cho tính năng watch tracking:
     - `start()`: Bắt đầu hoặc khôi phục session cho khách vãng lai và khách đăng nhập.
     - `heartbeat()`: Báo cáo nhịp tim định kỳ, ghi nhận vị trí phát lại và trả về tiến độ.
     - `status()`: Tra cứu tiến độ và trạng thái đủ điều kiện nhận voucher.
     - `complete()`: Xác thực hoàn tất xem video trên server.

3. **`backend/tests/test_v2b_watch_tracking.php`**
   - Bộ kiểm thử tự động toàn diện kiểm tra 15 kịch bản bảo mật và logic playback:
     - Xem bình thường 100% đạt ELIGIBLE.
     - Tạm dừng không cộng dồn thời gian.
     - Tua nhanh từ đầu tới cuối bị từ chối.
     - Giới hạn tốc độ phát (chấp nhận 1.5x, chặn 10x).
     - Tải lại trang khôi phục session và không tự cộng tiến độ.
     - Mở nhiều tab bị chặn bởi Rate Limiting.
     - Replay heartbeat không làm tăng số giây đã xem.
     - Client gửi fake progress bị từ chối ELIGIBLE.
     - Gửi heartbeat cho session không tồn tại.
     - Chiến dịch bị tắt giữa chừng chuyển sang EXPIRED.
     - Chiến dịch hết hạn ngày bị từ chối khởi tạo.
     - Phiên khách vãng lai (guest) vs khách đăng nhập (customer).
     - Video không có campaign ưu đãi.
     - Video chưa xuất bản bị từ chối.
     - Kiểm tra hồi quy không tạo voucher và không tăng issued_count.

4. **Tài liệu báo cáo kỹ thuật tại `backend/experiments/ai_live_commerce/watch_to_reward/v2b/`:**
   - `audit.md`: Báo cáo kiểm toán kiến trúc, hiện trạng source code và các lỗ hổng playback tiềm ẩn.
   - `watch_session_schema.md`: Đặc tả chi tiết schema MongoDB `ai_video_watch_sessions`, chỉ mục và document mẫu.
   - `tracking_api.md`: Đặc tả kỹ thuật 4 API endpoints (method, url, request, response, error codes).
   - `completion_rules.md`: Chi tiết thuật toán Interval Union, ngưỡng sai số 95%, quy tắc tua/pause/replay.
   - `security_tests.md`: Ma trận kiểm thử bảo mật và kết quả thực tế.
   - `browser_tests.md`: Báo cáo kiểm thử trình duyệt thực tế với Chromium CDP kèm ảnh chụp màn hình và video ghi hình.
   - `regression_tests.md`: Báo cáo xác nhận catalog, voucher, cart và checkout nguyên vẹn 100%.
   - `changed_files.md`: Tệp nhật ký này.
   - `final_report.md`: Báo cáo tổng kết nghiệm thu Phase V2B.

---

## 2. Danh sách Tệp đã cập nhật (Modified Files)

1. **`backend/app/models/VideoRewardCampaign.php`**
   - Bổ sung phương thức `getActiveCampaignForVideo(string $videoId, ?string $publicationId = null): ?array` để kiểm tra chiến dịch có đang có hiệu lực (`is_active = true`, trong khoảng thời gian bắt đầu/kết thúc, còn hạn ngạch).

2. **`backend/app/controllers/SanPhamController.php`**
   - Trong phương thức `chitiet()`: nạp `VideoRewardCampaign` và đính kèm thông tin `reward_campaign` đang hoạt động vào từng video trong `$productVideos` để view có dữ liệu hiển thị.

3. **`index.php`**
   - Đăng ký 4 route mới: `video_watch_start`, `video_watch_heartbeat`, `video_watch_status`, `video_watch_complete` điều hướng tới `VideoWatchController`.

4. **`backend/public/index.php`**
   - Đồng bộ đăng ký 4 route watch tracking mới.

5. **`frontend/public/index.php`**
   - Đồng bộ đăng ký `product_video_stream` và 4 route watch tracking mới.

6. **`frontend/views/chitiet.php`**
   - Thêm khối giao diện `#synaRewardCard` hiển thị: Tên chiến dịch ưu đãi, mức giảm giá, badge trạng thái `#synaRewardBadge`, thanh tiến độ gradient `#synaRewardProgressBar`, nhãn thời gian đã xem và ghi chú cho khách vãng lai.
   - Bổ sung logic JavaScript đồng bộ heartbeat định kỳ 4s với server, xử lý sự kiện `pause`, `playing`, `ended`, khôi phục tiến độ khi tải trang và chuyển đổi video trong playlist.

7. **`frontend/public/assets/css/ai-idol-studio.css`**
   - Bổ sung định dạng CSS cho `.syna-reward-card`, `.syna-reward-icon-badge`, `.syna-reward-progress-bar`.
