# RÀ SOÁT QUY TẮC HOÀN THÀNH XEM VIDEO (COMPLETION RULE REVIEW) — PHASE V2B.1

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phạm vi:** Kiểm tra tính an toàn của điều kiện xác định trạng thái `ELIGIBLE`  
**Kỹ sư thực hiện:** Senior PHP/MongoDB Security Engineer  
**Ngày thực hiện:** 13/10/2026  

---

## 1. Kiểm toán Lỗ hổng của Quy tắc `OR` Cũ (Vulnerability Audit)

Trong Phase V2B ban đầu, điều kiện công nhận hoàn thành xem video được cấu hình như sau:
```php
if ($coveragePercent >= self::COMPLETION_THRESHOLD_PERCENT || $watchedSeconds >= ($duration - self::COMPLETION_BUFFER_SECONDS))
```
tức là:
$$\text{coverage} \ge 95.0\% \quad\lor\quad \text{watched\_seconds} \ge (\text{video\_duration} - 2.5\text{s})$$

### Rủi ro Bảo mật và Gian lận phát hiện được:
1. **Dừng video sớm trước đoạn kết (Early Stopping):**
   - Với video thời lượng 73.8s, ngưỡng 95% độ phủ tương ứng với `70.12s`.
   - Người xem có thể phát video từ `0.0s` đến `70.2s` rồi tắt trình duyệt hoặc dừng xem.
   - Do điều kiện là `OR`, vế 1 (`coverage >= 95%`) lập tức thỏa mãn, hệ thống cấp quyền `ELIGIBLE` ngay cả khi người xem **chưa bao giờ xem 3.6 giây cuối cùng** của video!
   - Trong thực tế thương mại, 3–5 giây cuối là thời điểm then chốt chứa thông điệp kêu gọi hành động (Call To Action - CTA), hướng dẫn nhận voucher và kết luận tư vấn từ Syna 3D.
2. **Video ngắn bị giảm tiêu chuẩn chất lượng:**
   - Với video ngắn (ví dụ 20s), vế 2 ($20\text{s} - 2.5\text{s} = 17.5\text{s}$) chỉ tương đương **87.5%** độ phủ nội dung. Điều kiện `OR` làm người xem video ngắn chỉ cần xem 87.5% là đã được tính hoàn thành.
3. **Thiếu kiểm tra bằng chứng chạm đích (Tail Reach Evidence):**
   - Việc chỉ kiểm tra tổng độ phủ mà không kiểm tra vị trí kết thúc của luồng phát khiến hệ thống không chứng minh được người xem đã theo dõi trọn vẹn mạch video từ đầu đến cuối.

---

## 2. Thiết kế và Triển khai Điều kiện Kết hợp Chặt chẽ (Combined AND Rule)

Để khắc phục triệt để lỗ hổng trên mà vẫn bảo toàn tính linh hoạt cho HTML5 video (tránh lỗi kẹt ở 99.8% do làm tròn container MP4), hệ thống đã nâng cấp sang **điều kiện kết hợp đồng thời (`AND`)**:

```php
$endLast = 0.0;
if (!empty($mergedIntervals)) {
    $lastPair = end($mergedIntervals);
    $endLast = (float)($lastPair[1] ?? 0.0);
}

$hasSufficientCoverage = ($coveragePercent >= self::COMPLETION_THRESHOLD_PERCENT);
$hasReachedNearEnd = ($endLast >= ($duration - self::COMPLETION_BUFFER_SECONDS));

// Combined rule: Bắt buộc thỏa mãn ĐỒNG THỜI cả độ phủ >= 95% VÀ đã phát vào phân đoạn đuôi
if ($hasSufficientCoverage && $hasReachedNearEnd) {
    $isEligible = true;
    $watchState = 'ELIGIBLE';
    $eligibleAt = $nowUtc;
}
```

### Các tiêu chí bắt buộc để đạt `ELIGIBLE`:
1. **Độ phủ nội dung (Content Coverage):**
   $$\text{watched\_coverage\_percent} \ge 95.0\%$$
   *(Được tính từ hợp các khoảng thời gian không chồng chéo `valid_watched_intervals`)*.
2. **Bằng chứng phát đến gần cuối (Tail Reach Evidence):**
   Khoảng xem cuối cùng phải kết thúc trong cự ly dung sai 2.5 giây so với thời lượng video:
   $$\text{end\_last} \ge (\text{video\_duration} - 2.5\text{s})$$
   *(Với video 73.816s, khoảng xem cuối phải đạt tối thiểu từ giây `71.316s` trở đi)*.
3. **Thời gian server tương thích với tốc độ phát:**
   Mỗi bước nhảy vị trí $\Delta t_{\text{play}}$ phải được bảo đảm bởi thời gian thực trôi qua trên máy chủ:
   $$\Delta t_{\text{play}} \le (\Delta T_{\text{server}} \times \text{playback\_rate}) + 2.0\text{s}$$
   với $\text{playback\_rate} \le 2.0x$.
4. **Không chấp nhận tua nhảy cóc (No Seek-to-End):**
   Mọi cú nhảy vượt quá thời gian cho phép đều bị đánh dấu `was_seek = true` và loại bỏ hoàn toàn khỏi độ phủ.
5. **Chống replay và spam heartbeat:**
   Thuật toán Interval Union đảm bảo xem lặp lại một đoạn không cộng dồn thời gian; Rate limit 1.8s chặn mọi request gửi dồn dập.

---

## 3. Kết quả Thử nghiệm Thực tế

| Kịch bản kiểm tra | Kỳ vọng | Kết quả thực tế | Trạng thái |
| :--- | :--- | :--- | :---: |
| **Xem tuần tự từ 0s đến hết (73.8s)** | Đủ coverage (100%) và chạm đuôi (73.8s $\ge$ 71.3s) $\rightarrow$ Cấp ELIGIBLE | `coverage = 100%`, `end_last = 73.816s`, `watch_state = ELIGIBLE` | **PASS** |
| **Dừng sớm ở 95.05% (70.16s)** | Đủ coverage (95.05%) nhưng thiếu đuôi (70.16s $<$ 71.3s) $\rightarrow$ Từ chối ELIGIBLE | `is_eligible = false`, `watch_state = IN_PROGRESS`, thông báo: *"Bạn cần xem đến đoạn kết video để hoàn tất điều kiện nhận ưu đãi."* | **PASS** |
| **Tua từ 0s tới 73s trong 2s** | Phát hiện seek $\rightarrow$ Không cộng khoảng, coverage = 0% $\rightarrow$ Từ chối | `was_seek = true`, `coverage = 0%`, `is_eligible = false` | **PASS** |

---

## 4. Kết luận

Quy tắc kết hợp `AND` (`coverage >= 95% AND end_last >= duration - 2.5s`) đã giải quyết hoàn toàn nguy cơ dừng xem sớm trước đoạn kết, đáp ứng tuyệt đối tiêu chuẩn an toàn dữ liệu trước khi bước sang Phase V2C.
