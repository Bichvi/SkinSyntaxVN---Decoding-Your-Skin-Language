# KIỂM TOÁN HỆ THỐNG VÀ KIẾN TRÚC — PHASE V2B: REAL VIDEO WATCH TRACKING

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Giai đoạn:** Phase V2B — Real Video Watch Tracking & Eligibility Verification  
**Thời gian kiểm toán:** 13/10/2026  
**Kỹ sư thực hiện:** Senior PHP/MongoDB Engineer, Video Playback Engineer & Application Security Engineer  

---

## 1. Mục tiêu và Phạm vi Kiểm toán (Scope & Objectives)

Phase V2B thiết lập cơ chế **theo dõi tiến độ xem video Syna 3D thực tế phía backend** và **xác định quyền đủ điều kiện nhận thưởng (eligibility)** của khách hàng trên trang chi tiết sản phẩm (PDP).

### Ranh giới nghiêm ngặt (Strict Boundaries):
- **CHỈ** thực hiện: Lưu trữ watch session backend, tính toán độ phủ video đã xem qua hợp các khoảng thời gian (interval union/coverage), xác thực điều kiện `ELIGIBLE` trên server, hiển thị thanh tiến độ và thông tin chiến dịch trên PDP.
- **KHÔNG** cấp voucher thật trong collection `voucher`.
- **KHÔNG** trừ hạn ngạch (`total_quota`) hay tăng `issued_count` trong `ai_video_reward_campaigns`.
- **KHÔNG** tự động áp dụng mã giảm giá.
- **KHÔNG** can thiệp quy trình thanh toán (checkout), giá bán sản phẩm, giá Flash Sale, hệ thống Recommendation Engine hay AI rendering pipeline.

---

## 2. Kiểm toán Hiện trạng Source Code Thực tế (Current State Audit)

### 2.1. Model `ProductVideo.php`
- **Vị trí:** `backend/app/models/ProductVideo.php`
- **Collection quản lý:** `ai_product_videos`
- **Trạng thái:**
  - `getPublishedVideosByProductId($productId)` trả về danh sách video đã xuất bản và sắp xếp theo `display_priority` giảm dần, `created_at` giảm dần.
  - Video xuất bản hiện tại: ID `6acc6bd54e1a7ffa0e0672b2`, `video_id` `6acc27e526e9d8acf59c457e`, liên kết sản phẩm `6376`, thời lượng thực tế `73.816236` giây.
  - `duration` được lưu trữ chính xác dưới dạng `float`.

### 2.2. Model `VideoRewardCampaign.php` (từ Phase V2A)
- **Vị trí:** `backend/app/models/VideoRewardCampaign.php`
- **Collection quản lý:** `ai_video_reward_campaigns`
- **Trạng thái:**
  - Cung cấp CRUD và validation cho chiến dịch voucher xem video liên kết với video READY đã xuất bản.
  - Hỗ trợ `getCampaignByVideoId($videoId)`, `getCampaignByPublicationId($pubId)`.
  - Cần bổ sung phương thức kiểm tra chiến dịch có đang có hiệu lực (`getActiveCampaignForVideo`) xét theo thời gian hiện tại (`start_date <= now <= end_date`), trạng thái `is_active === true`, và hạn ngạch còn trống (`issued_count < total_quota`).

### 2.3. Controller & View Trang Chi tiết Sản phẩm (PDP)
- **Controller:** `backend/app/controllers/SanPhamController.php` (phương thức `chitiet()`).
  - Đã nạp `$productVideos = $productVideoModel->getPublishedVideosByProductId(...)` và truyền sang view.
  - Hiện tại chưa đính kèm thông tin chiến dịch thưởng (`reward_campaign`) vào từng video để view hiển thị.
- **View:** `frontend/views/chitiet.php`
  - Đã có khung trình phát Syna 3D video `#synaVideoShowcase` (HTML5 `<video id="synaVideoPlayer">`), lớp phủ cover overlay `#synaVideoOverlay`, xử lý playlist.
  - Hiện tại chưa có khối giao diện hiển thị ưu đãi, thanh tiến độ mở khóa voucher và badge trạng thái đủ điều kiện.

### 2.4. Video Stream Endpoint
- **Route:** `r=product_video_stream&id={pubId}&product_id={productId}`
- **Thực thi:** `SanPhamController::streamProductVideo()`
- **Đặc tính:** Proxy stream video từ AI Idol Service, hỗ trợ HTTP 206 Partial Content và Range request byte-seeking. Nhả session lock (`session_write_close()`) để không làm nghẽn các request song song.

### 2.5. Định danh Khách hàng & Quản lý Session
- **Đăng nhập:** Lưu trong `$_SESSION['user']` (chứa `id`/`ma_kh`, `email`, `role`, `ho_ten`).
- **Khách vãng lai (Guest):** PHP Session tự động khởi tạo (`session_start()`) có `session_id()` duy nhất thông qua cookie `PHPSESSID`.
- **Nguyên tắc V2B:**
  - Khách vãng lai được phép bắt đầu watch session và theo dõi tiến độ xem video trên PDP (để khuyến khích đăng nhập nhận thưởng).
  - Khách đăng nhập gắn kết trực tiếp `customer_id` với watch session.
  - Mọi trạng thái xem và tính toán tiến độ được lưu trữ trên server MongoDB, **tuyệt đối không dùng localStorage** làm nguồn xác thực quyền nhận voucher.

### 2.6. Điểm vào Routing (Entrypoints)
- Các file entrypoints chính: `index.php`, `backend/public/index.php`, `frontend/public/index.php`.
- Cần đăng ký các route watch tracking mới:
  - `video_watch_start`: Bắt đầu/nạp watch session.
  - `video_watch_heartbeat`: Báo cáo nhịp tim phát lại định kỳ (kèm chống tua gian lận).
  - `video_watch_status`: Tra cứu tiến độ và trạng thái đủ điều kiện.
  - `video_watch_complete`: Xác thực hoàn thành xem video trên server.

---

## 3. Lỗ hổng Bảo mật Playback Phổ biến & Biện pháp Đối phó trong V2B

| Lỗ hổng tiềm ẩn | Nguy cơ | Biện pháp ngăn chặn trong V2B |
| :--- | :--- | :--- |
| **Client tự gửi `progress: 100%`** | Client can thiệp payload JS gửi 100% để nhận thưởng tức thì. | Server **hoàn toàn phớt lờ** tiến độ client tự xưng. Server chỉ tính tiến độ dựa trên các khoảng thời gian phát hợp lệ (`valid_watched_intervals`). |
| **Tua video tới cuối (Fast Forward / Seeking)** | Người dùng kéo thanh trượt từ 0:00 tới 1:14 rồi dừng. | Server phát hiện bước nhảy thời gian (`delta > elapsed * max_rate + buffer`). Không cộng đoạn tua vào khoảng đã xem; chỉ ghi nhận vị trí mới. |
| **Tăng tốc độ phát bất thường (`playbackRate = 16x`)** | Người dùng mở DevTools đổi tốc độ video lên cực cao. | Server giới hạn tốc độ tối đa cho phép là `2.0x`. Mọi bước nhảy vượt quá `elapsed * 2.0x + buffer` bị coi là tua và không được tính. |
| **Cộng dồn heartbeat ngây thơ (Naive Summing)** | Xem lại một đoạn 10 giây nhiều lần hoặc pause mà heartbeat vẫn gửi làm tăng tiến độ. | Server sử dụng **thuật toán hợp khoảng (Interval Union)**. Khoảng trùng lặp không làm tăng tổng số giây đã xem. Khi pause, không phát sinh khoảng mới. |
| **Replay Heartbeat / Gửi liên tục** | Dùng curl/Postman bắn hàng nghìn request heartbeat mỗi giây. | Áp dụng **Rate Limiting** phía server (tối thiểu 2 giây giữa 2 heartbeat). Các request trùng timestamp hoặc gửi quá dày bị từ chối ngay lập tức. |
| **Thay đổi video_id giữa chừng** | Dùng session của video ngắn cho video dài hoặc video khác. | Session bị khóa cứng với `video_id` và `campaign_id` được xác thực khi khởi tạo. |
| **Chiến dịch bị tắt / hết hạn giữa lúc xem** | Người dùng mở tab xem sau khi admin đã dừng chiến dịch. | Mỗi heartbeat và kiểm tra hoàn tất đều kiểm tra tính hợp lệ của campaign. Nếu campaign bị tắt hoặc hết hạn, trạng thái chuyển `EXPIRED`. |

> **Lưu ý về giới hạn bảo mật (Security Limitation):**  
> Trình duyệt không thể chứng minh người dùng thực sự dán mắt vào màn hình hay chỉ bật video ở tab nền/đi chỗ khác. Cơ chế interval coverage đảm bảo dữ liệu media thực tế được phát tuần tự qua thời gian thực với tốc độ hợp lý, đáp ứng tiêu chuẩn ngành cho tính năng Watch-to-Reward.

---

## 4. Kế hoạch Thay đổi Tệp (Files to be Created & Modified)

### 4.1. Tệp tạo mới:
1. `backend/app/models/VideoWatchSession.php`: Domain model quản lý collection `ai_video_watch_sessions` và thuật toán hợp khoảng interval union.
2. `backend/app/controllers/VideoWatchController.php`: Controller xử lý 4 API endpoints (start, heartbeat, status, complete).
3. `backend/tests/test_v2b_watch_tracking.php`: Bộ kiểm thử tự động toàn diện kiểm tra 15 kịch bản bảo mật và logic nghiệp vụ.
4. Tài liệu báo cáo trong thư mục `backend/experiments/ai_live_commerce/watch_to_reward/v2b/`:
   - `audit.md` (tệp này)
   - `watch_session_schema.md`
   - `tracking_api.md`
   - `completion_rules.md`
   - `security_tests.md`
   - `browser_tests.md`
   - `regression_tests.md`
   - `changed_files.md`
   - `final_report.md`

### 4.2. Tệp cập nhật:
1. `backend/app/models/VideoRewardCampaign.php`: Bổ sung hàm tiện ích `getActiveCampaignForVideo()` kiểm tra thời hạn và hạn ngạch.
2. `backend/app/controllers/SanPhamController.php`: Bổ sung nạp chiến dịch voucher thưởng vào danh sách video hiển thị trên PDP.
3. `index.php`, `backend/public/index.php`, `frontend/public/index.php`: Đăng ký 4 route watch tracking mới.
4. `frontend/views/chitiet.php`: Bổ sung banner ưu đãi, thanh tiến độ Watch-to-Reward, nhãn thời lượng đã xem và JavaScript client playback tracker tích hợp heartbeat backend.
5. `frontend/public/assets/css/ai-idol-studio.css` (hoặc CSS trong PDP): Thêm style hiển thị thanh tiến độ, huy hiệu phần thưởng và trạng thái mở khóa.
