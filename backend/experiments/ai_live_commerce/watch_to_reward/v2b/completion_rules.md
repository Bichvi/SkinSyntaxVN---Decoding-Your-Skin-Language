# QUY TẮC HOÀN THÀNH VÀ TÍNH TOÁN ĐỘ PHỦ — PHASE V2B

**Hệ thống:** SkinSyntaxVN — Watch-to-Reward  
**Quy tắc:** Real Playback Coverage & Completion Verification  

---

## 1. Thuật toán Hợp khoảng thời gian (Interval Union Coverage)

Hệ thống **không cộng dồn ngây thơ** các khoảng thời gian giữa các heartbeat. Thay vào đó, backend duy trì một mảng các khoảng thời gian đã xem thực tế:  
`intervals = [[start_1, end_1], [start_2, end_2], ...]`

### 1.1. Thuật toán hợp nhất (Interval Merge Algorithm)
Khi client gửi một khoảng xem mới `[new_start, new_end]`:
1. Giới hạn khoảng hợp lệ: `new_start = max(0, new_start)`, `new_end = min(video_duration, new_end)`.
2. Nếu `new_end <= new_start`, bỏ qua.
3. Ghép khoảng mới vào danh sách hiện tại.
4. Sắp xếp danh sách theo `start` tăng dần.
5. Duyệt tuyến tính qua mảng:
   - Nếu `interval[i].start <= current_merged.end + EPSILON` (với `EPSILON = 0.5s` để nối liền các đoạn xem liên tiếp bị ngắt nhẹ do nhịp heartbeat):
     `current_merged.end = max(current_merged.end, interval[i].end)`
   - Ngược lại, đẩy `current_merged` vào kết quả và bắt đầu khoảng mới.
6. Tính tổng thời lượng đã xem:  
   $$\text{watched\_seconds} = \sum (\text{end}_k - \text{start}_k)$$
7. Tính tỉ lệ phần trăm độ phủ:  
   $$\text{watched\_coverage\_percent} = \min\left(100.0, \frac{\text{watched\_seconds}}{\text{video\_duration}} \times 100.0\right)$$

---

## 2. Ngưỡng Hoàn Thành Đủ Điều Kiện (Completion Threshold)

### 2.1. Thách thức kỹ thuật của Video Player
- Các container video (MP4/H.264) thường kết thúc ở giây `duration - 0.2s` hoặc `duration - 0.5s` do làm tròn frame.
- Nhịp heartbeat của client gửi định kỳ mỗi 4–5 giây; sự kiện `ended` của trình duyệt có thể kích hoạt trước khi nhịp heartbeat tiếp theo điểm đúng 100.000%.
- Nếu bắt buộc chính xác tuyệt đối $100.000\%$, người dùng xem trọn vẹn video từ đầu tới cuối có thể bị kẹt ở $99.7\%$ hoặc $99.9\%$.

### 2.2. Ngưỡng đề xuất mặc định của SkinSyntaxVN
Hệ thống công nhận trạng thái `ELIGIBLE` khi thỏa mãn **MỘT TRONG HAI** điều kiện sau:
1. **Tỉ lệ độ phủ $\ge 95.0\%$**:
   $$\text{watched\_coverage\_percent} \ge 95.0\%$$
2. **Thời lượng xem hợp lệ chỉ thiếu tối đa 2.5 giây so với thời lượng video**:
   $$\text{watched\_seconds} \ge (\text{video\_duration} - 2.5\text{s})$$

> **Bảo đảm:** Người dùng không thể tua từ 0:00 tới cuối để đạt điều kiện này, vì để tích lũy được $\ge 95\%$ độ phủ, người dùng bắt buộc phải để video phát thực tế tối thiểu 95% thời lượng video qua thời gian thực trên server!

---

## 3. Các Quy Tắc Kiểm Soát Hành Vi Phát Lại (Playback Rules)

### 3.1. Tua video (Seeking / Fast-Forward)
- Server so sánh thời gian thực trôi qua trên server ($\Delta T_{\text{server}} = T_{\text{now}} - T_{\text{last}}$) với bước nhảy thời gian của video ($\Delta t_{\text{play}} = t_{\text{curr}} - t_{\text{last}}$).
- Vận tốc tối đa cho phép là `2.0x`.
- Ngưỡng bước nhảy tối đa cho phép:  
  $$\Delta t_{\text{max\_allowed}} = (\Delta T_{\text{server}} \times \text{playback\_rate}) + \text{TOLERANCE}$$  
  *(với $\text{TOLERANCE} = 2.0\text{s}$ để bù độ trễ mạng và chênh lệch xung nhịp).*
- Nếu $\Delta t_{\text{play}} > \Delta t_{\text{max\_allowed}}$:  
  **Phát hiện tua nhanh (Seek detected).** Server **từ chối cộng đoạn nhảy này** vào `valid_watched_intervals`. Server chỉ cập nhật vị trí phát lại `last_playback_time = t_curr` để làm mốc cho nhịp tiếp theo.

### 3.2. Tạm dừng (Pause / Resume)
- Khi client gửi trạng thái `is_paused: true` hoặc $\Delta t_{\text{play}} \approx 0$:
  Server không sinh thêm khoảng xem mới, `watched_seconds` không đổi.
- Khi người dùng bấm play trở lại, server ghi nhận mốc thời gian bắt đầu đoạn xem mới.

### 3.3. Xem lại cùng một đoạn (Replay / Rewind)
- Người dùng tua ngược lại và xem lại đoạn từ 0:00 đến 0:30.
- Khoảng `[0, 30]` khi được hợp với khoảng `[0, 30]` đã có trước đó vẫn cho ra `[0, 30]`.
- Tổng thời lượng đã xem **không tăng thêm**, ngăn chặn thủ thuật xem lặp đi lặp lại một đoạn ngắn để gian lận đủ thời lượng.

### 3.4. Tải lại trang (Page Reload)
- Khi người dùng F5 hoặc reload trang, client gọi endpoint `video_watch_start`.
- Server tìm kiếm session đang có sẵn trong database của visitor cho video đó.
- Tiến độ xem trước đó được trả về nguyên vẹn (`progress = X%`).
- **Reload trang không tự động làm tăng tiến độ xem.** Muốn tăng tiếp, người dùng phải tiếp tục bấm xem video.

### 3.5. Đồng thời mở nhiều tab (Multiple Tabs)
- Nếu người dùng mở nhiều tab cùng chạy 1 video để cố tình nhân đôi tốc độ:
  - Rate limiting (tối thiểu 2s giữa 2 heartbeat) sẽ chặn các request trùng lặp từ tab thứ 2.
  - Khoảng thời gian các tab gửi lên đều nằm trong cùng khoảng thời gian thực đang phát, thuật toán hợp khoảng sẽ hợp nhất chúng lại mà không nhân đôi số giây.

### 3.6. Video xuất hiện trên nhiều sản phẩm (Multi-Product Linked Video)
- Nếu cùng 1 video được gắn cho 2 sản phẩm A và B:
  - Chiến dịch ưu đãi voucher gắn liền với `video_id` duy nhất (`ai_video_reward_campaigns`).
  - Watch session dùng chung `campaign_id` và `video_id`.
  - Khách hàng xem xong trên sản phẩm A hay B đều có cùng một trạng thái đủ điều kiện duy nhất, tránh việc lặp lại ưu đãi trái phép.

### 3.7. Khách vãng lai (Guest) vs Khách đăng nhập (Customer)
- Khách vãng lai được xem và theo dõi tiến độ trên giao diện PDP.
- Khi đạt điều kiện, UI hiển thị: *"Đã đủ điều kiện nhận voucher! Đăng nhập để nhận ưu đãi."*
- Hệ thống backend tuyệt đối **không tự động cấp voucher** cho bất kỳ ai trong Phase V2B.
