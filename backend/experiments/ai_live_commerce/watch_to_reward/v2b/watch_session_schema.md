# THIẾT KẾ SCHEMA WATCH SESSION — PHASE V2B

**Hệ thống:** SkinSyntaxVN — Watch-to-Reward  
**Collection:** `ai_video_watch_sessions`  
**Database:** MongoDB Atlas (hoặc local replica trong môi trường container)  

---

## 1. Mục đích thiết kế (Design Purpose)

Bản ghi Watch Session lưu trữ phiên theo dõi tiến độ xem video hoàn toàn phía backend.  
- Ngăn chặn triệt để tình trạng client tự giả mạo `progress = 100%`.
- Lưu trữ các khoảng thời gian đã xem hợp lệ (`valid_watched_intervals`) thay vì chỉ cộng dồn số giây.
- Hỗ trợ cả khách vãng lai (`guest_session_id`) và khách hàng đã đăng nhập (`customer_id`).
- Xác định quyền nhận thưởng (`ELIGIBLE`) thông qua thuật toán hợp khoảng thời gian trên server.

---

## 2. Đặc tả Cấu trúc Dữ liệu (Schema Specification)

| Trường (Field) | Kiểu dữ liệu (BSON/PHP) | Bắt buộc | Mô tả |
| :--- | :--- | :--- | :--- |
| `_id` | `ObjectId` | Có | Khóa chính BSON tự sinh |
| `watch_session_id` | `string` | Có | Mã định danh session ngẫu nhiên, khó đoán (32 ký tự hex: `bin2hex(random_bytes(16))`). **Unique Index**. |
| `customer_id` | `string` / `null` | Không | Mã định danh khách hàng nếu đã đăng nhập (`$_SESSION['user']['id']` hoặc `ma_kh` / `email`). |
| `guest_session_id` | `string` / `null` | Không | Mã phiên khách vãng lai lấy từ PHP `session_id()` nếu chưa đăng nhập. |
| `video_id` | `string` | Có | Mã tài sản video từ hệ thống AI Idol (24 hex). |
| `publication_id` | `string` | Có | Mã bản ghi xuất bản video trên sản phẩm (`ai_product_videos._id`). |
| `campaign_id` | `string` | Có | Mã chiến dịch ưu đãi voucher đang liên kết (`ai_video_reward_campaigns.campaign_id`). |
| `video_duration` | `double` | Có | **Thời lượng video được xác minh trên backend** (lấy từ database `ai_product_videos`, không tin tưởng client). |
| `playback_progress` | `double` | Có | Vị trí phát lại hiện tại của video (giây). |
| `valid_watched_intervals` | `array<array[2]>` | Có | Mảng các khoảng thời gian đã xem hợp lệ, không chồng chéo: `[[s1, e1], [s2, e2], ...]`. |
| `watched_seconds` | `double` | Có | Tổng số giây đã xem hợp lệ (tổng độ dài các khoảng sau khi hợp). |
| `watched_coverage_percent` | `double` | Có | Phần trăm độ phủ đã xem hợp lệ so với `video_duration` (0.0 đến 100.0). |
| `watch_state` | `string` | Có | Trạng thái xem: `IN_PROGRESS`, `ELIGIBLE`, `EXPIRED`. Mặc định: `IN_PROGRESS`. |
| `is_eligible` | `bool` | Có | Cờ boolean xác nhận đã đủ điều kiện nhận ưu đãi. Mặc định: `false`. |
| `eligible_at` | `UTCDateTime` / `null` | Không | Thời điểm đạt chuẩn `ELIGIBLE`. |
| `heartbeat_count` | `int` | Có | Tổng số nhịp heartbeat hợp lệ đã gửi trong session này. |
| `last_heartbeat_at` | `UTCDateTime` | Có | Thời điểm gửi heartbeat gần nhất (theo giờ UTC server). |
| `last_server_time` | `double` | Có | Timestamp microsecond của server tại lần heartbeat trước (`microtime(true)`), dùng để kiểm soát elapsed time. |
| `last_playback_time` | `double` | Có | Vị trí video tại lần heartbeat trước, dùng để tính toán delta phát lại. |
| `client_metadata` | `object` | Có | Thông tin môi trường client: `ip`, `user_agent`. |
| `created_at` | `UTCDateTime` | Có | Thời điểm tạo session. |
| `updated_at` | `UTCDateTime` | Có | Thời điểm cập nhật session gần nhất. |

---

## 3. Chỉ mục MongoDB (MongoDB Indexes)

```javascript
db.ai_video_watch_sessions.createIndex({ "watch_session_id": 1 }, { unique: true });
db.ai_video_watch_sessions.createIndex({ "video_id": 1, "campaign_id": 1 });
db.ai_video_watch_sessions.createIndex({ "publication_id": 1 });
db.ai_video_watch_sessions.createIndex({ "customer_id": 1 });
db.ai_video_watch_sessions.createIndex({ "guest_session_id": 1 });
db.ai_video_watch_sessions.createIndex({ "updated_at": -1 });
```

---

## 4. Document Mẫu (Sample JSON Document)

```json
{
  "_id": { "$oid": "670c3a8b4e1a7ffa0e068991" },
  "watch_session_id": "9f82d1c750b28414e21a8c0d3810f9e1",
  "customer_id": "kh_6376_user",
  "guest_session_id": null,
  "video_id": "6acc27e526e9d8acf59c457e",
  "publication_id": "6acc6bd54e1a7ffa0e0672b2",
  "campaign_id": "rew_a81f3c9091",
  "video_duration": 73.816236,
  "playback_progress": 73.816236,
  "valid_watched_intervals": [
    [0.0, 73.816236]
  ],
  "watched_seconds": 73.816236,
  "watched_coverage_percent": 100.0,
  "watch_state": "ELIGIBLE",
  "is_eligible": true,
  "eligible_at": { "$date": "2026-10-13T03:30:15.120Z" },
  "heartbeat_count": 15,
  "last_heartbeat_at": { "$date": "2026-10-13T03:30:15.120Z" },
  "last_server_time": 1791862215.120,
  "last_playback_time": 73.816236,
  "client_metadata": {
    "ip": "172.18.0.1",
    "user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36"
  },
  "created_at": { "$date": "2026-10-13T03:28:45.000Z" },
  "updated_at": { "$date": "2026-10-13T03:30:15.120Z" }
}
```

---

## 5. Ràng buộc Nghiệp vụ cốt lõi (Core Invariants)

1. **Unguessable Session ID:** Sinh ngẫu nhiên bằng `bin2hex(random_bytes(16))` (128 bits entropy), kẻ tấn công không thể tự dự đoán session ID của người dùng khác.
2. **Authoritative Duration:** Thời lượng `video_duration` tuyệt đối không nhận từ client. Khi bắt đầu session, server truy vấn trực tiếp bản ghi xuất bản trong `ai_product_videos` để lấy duration chính xác.
3. **Immutability of Reward State:** Khi session đã đạt trạng thái `ELIGIBLE`, trạng thái không bị giáng cấp về `IN_PROGRESS` nếu người dùng xem lại.
4. **Decoupled from Voucher Creation:** Bản ghi này chỉ ghi nhận quyền nhận thưởng (`is_eligible = true`). Trong Phase V2B, không có bất kỳ voucher nào được tạo trong collection `voucher`.
