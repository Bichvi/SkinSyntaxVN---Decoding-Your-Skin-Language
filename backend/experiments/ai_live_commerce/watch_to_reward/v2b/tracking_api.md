# TÀI LIỆU TRACKING API — PHASE V2B

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phạm vi:** Public / Customer Watch Tracking Endpoints  

---

## 1. Danh sách Endpoints

| STT | Route (`r=...`) | Method | Mục đích | Quyền truy cập |
| :---: | :--- | :---: | :--- | :--- |
| 1 | `video_watch_start` | `POST` / `GET` | Bắt đầu hoặc khôi phục watch session | Public (Guest & Customer) |
| 2 | `video_watch_heartbeat` | `POST` | Báo cáo nhịp tim phát lại định kỳ | Public (Session holder) |
| 3 | `video_watch_status` | `GET` | Tra cứu tiến độ và trạng thái đủ điều kiện | Public (Session holder) |
| 4 | `video_watch_complete` | `POST` | Yêu cầu xác thực hoàn tất xem video trên server | Public (Session holder) |

---

## 2. Chi tiết Đặc tả từng Endpoint

### 2.1. Start Watch Session (`video_watch_start`)
- **URL:** `BASE_URL/index.php?r=video_watch_start`
- **Method:** `POST` (khuyến nghị) hoặc `GET`
- **Headers:** `Content-Type: application/json`
- **Request Body (JSON):**
  ```json
  {
    "publication_id": "6acc6bd54e1a7ffa0e0672b2",
    "video_id": "6acc27e526e9d8acf59c457e",
    "campaign_id": "rew_a81f3c9091"
  }
  ```
  *(Có thể gửi `publication_id` hoặc `video_id`)*
- **Response Thành công (HTTP 200):**
  ```json
  {
    "ok": true,
    "session": {
      "watch_session_id": "9f82d1c750b28414e21a8c0d3810f9e1",
      "video_id": "6acc27e526e9d8acf59c457e",
      "publication_id": "6acc6bd54e1a7ffa0e0672b2",
      "campaign_id": "rew_a81f3c9091",
      "video_duration": 73.816236,
      "playback_progress": 0.0,
      "watched_seconds": 0.0,
      "watched_coverage_percent": 0.0,
      "watch_state": "IN_PROGRESS",
      "is_eligible": false,
      "is_guest": false
    },
    "campaign": {
      "campaign_id": "rew_a81f3c9091",
      "campaign_name": "Voucher xem video Syna 3D",
      "discount_type": "percentage",
      "discount_value": 15,
      "max_discount_amount": 50000,
      "min_order_amount": 100000,
      "is_active": true
    }
  }
  ```
- **Response Lỗi (HTTP 400 / 404):**
  ```json
  {
    "ok": false,
    "code": "CAMPAIGN_NOT_ACTIVE",
    "message": "Không có chiến dịch ưu đãi hợp lệ cho video này."
  }
  ```

---

### 2.2. Report Playback Heartbeat (`video_watch_heartbeat`)
- **URL:** `BASE_URL/index.php?r=video_watch_heartbeat`
- **Method:** `POST`
- **Headers:** `Content-Type: application/json`
- **Request Body (JSON):**
  ```json
  {
    "watch_session_id": "9f82d1c750b28414e21a8c0d3810f9e1",
    "current_time": 15.4,
    "playback_rate": 1.0,
    "is_paused": false
  }
  ```
- **Xác thực Backend:**
  1. Kiểm tra session tồn tại và quyền sở hữu (guest/customer session).
  2. Rate limit: Nếu khoảng cách với heartbeat trước < 1.8 giây $\rightarrow$ từ chối (HTTP 429).
  3. Kiểm tra tính hợp lệ của campaign: Nếu campaign đã hết hạn hoặc bị tắt $\rightarrow$ chuyển trạng thái session sang `EXPIRED`.
  4. Kiểm tra vận tốc tua: Nếu $\Delta t_{\text{play}} > (\Delta T_{\text{server}} \times \text{playback\_rate}) + 2.0\text{s}$, từ chối ghi nhận khoảng tua vào độ phủ.
  5. Hợp khoảng hợp lệ vào `valid_watched_intervals`.
  6. Nếu độ phủ $\ge 95\%$ $\rightarrow$ tự động kích hoạt `watch_state = 'ELIGIBLE'`.
- **Response Thành công (HTTP 200):**
  ```json
  {
    "ok": true,
    "playback_progress": 15.4,
    "watched_seconds": 15.4,
    "watched_coverage_percent": 20.86,
    "watch_state": "IN_PROGRESS",
    "is_eligible": false,
    "message": "Heartbeat ghi nhận thành công."
  }
  ```

---

### 2.3. Query Watch Progress (`video_watch_status`)
- **URL:** `BASE_URL/index.php?r=video_watch_status&watch_session_id=...` hoặc `&publication_id=...`
- **Method:** `GET`
- **Response Thành công (HTTP 200):**
  ```json
  {
    "ok": true,
    "watch_session_id": "9f82d1c750b28414e21a8c0d3810f9e1",
    "video_duration": 73.816236,
    "playback_progress": 73.816236,
    "watched_seconds": 73.816236,
    "watched_coverage_percent": 100.0,
    "watch_state": "ELIGIBLE",
    "is_eligible": true,
    "is_guest": false
  }
  ```

---

### 2.4. Validate Watch Completion (`video_watch_complete`)
- **URL:** `BASE_URL/index.php?r=video_watch_complete`
- **Method:** `POST`
- **Request Body (JSON):**
  ```json
  {
    "watch_session_id": "9f82d1c750b28414e21a8c0d3810f9e1"
  }
  ```
- **Xác thực Backend:**
  - Kiểm tra tổng độ phủ thực tế đã lưu trong backend.
  - Nếu đủ điều kiện ($\ge 95\%$ hoặc thiếu $< 2.5$ giây): Chuyển trạng thái sang `ELIGIBLE`.
  - Nếu chưa đủ: Trả về `is_eligible: false` kèm số giây còn thiếu.
  - **Lưu ý:** Tuyệt đối không tạo voucher trong collection `voucher`.
- **Response Thành công (HTTP 200):**
  ```json
  {
    "ok": true,
    "is_eligible": true,
    "watch_state": "ELIGIBLE",
    "watched_coverage_percent": 100.0,
    "message": "Đã đủ điều kiện nhận voucher ưu đãi!"
  }
  ```
- **Response Chưa đủ (HTTP 400):**
  ```json
  {
    "ok": false,
    "is_eligible": false,
    "watch_state": "IN_PROGRESS",
    "watched_coverage_percent": 42.5,
    "message": "Chưa xem đủ thời lượng yêu cầu để mở khóa ưu đãi."
  }
  ```
