# BÁO CÁO KIỂM THỬ BẢO MẬT — PHASE V2B

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Giai đoạn:** Phase V2B: Real Video Watch Tracking & Eligibility Verification  
**Thời gian thực hiện:** 13/10/2026  
**Kết quả:** FULL PASS (100%)  

---

## 1. Mục tiêu Kiểm thử Bảo mật

Xác nhận hệ thống Watch Tracking phía backend ngăn chặn được các hành vi gian lận và tấn công phổ biến mà client có thể thực hiện nhằm nhận ưu đãi bất hợp pháp mà không xem video thực tế.

---

## 2. Danh sách Ma trận Kiểm thử Bảo mật (Security Test Matrix)

| Test Case | Kịch bản tấn công / Gian lận | Cơ chế ngăn chặn phía Backend | Kết quả | Ghi chú bằng chứng |
| :---: | :--- | :--- | :---: | :--- |
| **TC-SEC-01** | **Client tự xưng `progress: 100%`** hoặc gọi thẳng `video_watch_complete` khi chưa xem đủ. | Server tính toán tiến độ độc lập từ các khoảng thời gian đã xem trong database (`ai_video_watch_sessions`). Từ chối `ELIGIBLE` nếu chưa đủ thời lượng. | **PASS** | Gọi `validateCompletion` trả về `is_eligible: false`, `remaining_seconds: 58.8s`. |
| **TC-SEC-02** | **Tua video từ đầu tới cuối (Seeking forward)** trong 2 giây. | Server so sánh thời gian thực trôi qua trên server ($\Delta T_{\text{server}}$) với bước nhảy playback. Phát hiện tua (`was_seek = true`) và không cộng đoạn nhảy vào độ phủ. | **PASS** | Bước nhảy 0s $\rightarrow$ 70s bị từ chối; `watched_seconds` giữ nguyên 0s; `is_eligible: false`. |
| **TC-SEC-03** | **Tăng tốc độ phát 10x (Playback speed spoofing)** qua DevTools console. | Server giới hạn tốc độ tối đa cho phép là `2.0x`. Mọi bước nhảy vượt quá $\Delta T_{\text{server}} \times 2.0x + 2s$ bị đánh dấu là tua và không được tính. | **PASS** | Tốc độ 1.5x hợp lệ được chấp nhận (6s); gian lận 10x bị từ chối với `was_seek: true`. |
| **TC-SEC-04** | **Spam request / Gửi liên tục (Flood / DoS)** nhiều heartbeat mỗi giây. | Áp dụng Rate Limiting tối thiểu `1.8 giây` giữa hai heartbeat liên tiếp trên cùng một session. | **PASS** | Heartbeat gửi cách 0.5s bị từ chối ngay với HTTP 429 và mã lỗi `RATE_LIMITED`. |
| **TC-SEC-05** | **Replay Heartbeat (Tấn công phát lại cùng request)**. | Thuật toán Interval Union hợp nhất các khoảng thời gian. Gửi lại cùng một vị trí video không làm tăng tổng số giây đã xem. | **PASS** | Gửi 2 lần cùng vị trí 15s; `watched_seconds` không tăng thêm (`15s == 15s`). |
| **TC-SEC-06** | **Tạm dừng nhưng giả mạo heartbeat tiếp tục chạy**. | Khi `is_paused = true` hoặc vị trí không đổi, server không tạo khoảng thời gian mới; độ phủ không đổi. | **PASS** | Khi pause 3 nhịp liên tiếp, thời lượng xem trước và sau khi pause giữ nguyên 10s. |
| **TC-SEC-07** | **Mở nhiều tab cùng lúc để nhân đôi tốc độ xem**. | Session chia sẻ mốc `last_server_time`; tab thứ 2 bị chặn bởi Rate Limit và các khoảng trùng lặp được hợp nhất. | **PASS** | Tab thứ 2 bị chặn bởi `RATE_LIMITED`; không có hiện tượng nhân đôi thời gian xem. |
| **TC-SEC-08** | **Session ID không hợp lệ hoặc giả mạo (IDOR / Injection)**. | Session ID sinh bằng 32 ký tự hex ngẫu nhiên (`bin2hex(random_bytes(16))`), được validate chặt chẽ. Session không tồn tại trả về HTTP 404. | **PASS** | Gửi heartbeat với session giả mạo trả về lỗi `SESSION_NOT_FOUND`. |
| **TC-SEC-09** | **Chiến dịch bị tắt giữa lúc đang xem video**. | Server kiểm tra trạng thái chiến dịch trong mỗi nhịp heartbeat. Nếu admin tắt chiến dịch, session chuyển sang `EXPIRED`. | **PASS** | Heartbeat trả về `watch_state = 'EXPIRED'`, `is_eligible = false`. |
| **TC-SEC-10** | **Xem video khi chiến dịch đã hết hạn ngày**. | `startOrResumeSession` xác thực `start_date <= now <= end_date`. Chiến dịch hết hạn bị từ chối ngay khi bắt đầu. | **PASS** | Bị từ chối khởi tạo với mã lỗi `NO_ACTIVE_CAMPAIGN`. |
| **TC-SEC-11** | **Video chưa xuất bản (Unpublished draft)**. | Kiểm tra `is_published === true` từ collection `ai_product_videos`. | **PASS** | Video unpublish bị từ chối với mã lỗi `VIDEO_NOT_FOUND`. |
| **TC-SEC-12** | **Không phát hành voucher trái phép trong Phase V2B**. | Hệ thống chỉ gắn cờ `is_eligible = true` trên session, tuyệt đối không chèn bản ghi vào `voucher` và không tăng `issued_count`. | **PASS** | Số lượng voucher trong collection `voucher` giữ nguyên 2; `issued_count` giữ nguyên 0. |

---

## 3. Kết luận Bảo mật

Backend đã triển khai đầy đủ cơ chế bảo vệ theo chiều sâu (Defense-in-Depth):
1. **Server-authoritative state:** Trạng thái xem và quyền nhận thưởng do server quyết định 100%.
2. **Interval coverage math:** Thuật toán hợp khoảng thời gian ngăn chặn triệt để gian lận tua và xem lặp.
3. **Strict Boundaries:** Không có bất kỳ hành vi phát hành voucher hay can thiệp checkout nào diễn ra trong Phase V2B.
