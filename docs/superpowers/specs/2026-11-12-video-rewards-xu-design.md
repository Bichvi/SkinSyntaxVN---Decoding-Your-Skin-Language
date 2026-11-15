# Xem video nhận xu, điểm danh và trang Phần thưởng — Design

Ngày: 2026-11-12 · Nhánh: `syna_10_11` · Trạng thái: chờ duyệt

## 1. Mục tiêu

Khách xem video sản phẩm (Syna AI Idol) để nhận **xu**, giống trải nghiệm "Phần thưởng" của các sàn TMĐT nhưng mang nhận diện SkinSyntax:

1. **Xu theo từng video**: admin đặt số xu cho chiến dịch của mỗi video, xem đủ thì bấm "Nhận".
2. **Điểm danh 7 ngày**: mỗi ngày xem ít nhất 60 giây video thì được bấm "Điểm danh". Chuỗi 7 ngày liên tiếp, ngày 7 có quà lớn; bỏ một ngày thì chuỗi quay về ngày 1.
3. **Mốc thời gian xem mỗi ngày**: thanh tiến độ 0–30 phút, đạt các mốc 5/15/30 phút thì mở hộp quà xu.
4. **Trang Phần thưởng** (`index.php?r=phan_thuong`): hiển thị số xu hiện có, khu điểm danh, khu mốc xem, nút "Xem ngay" và lịch sử xu.
5. **Vòng đếm ngược**: vòng tròn chạy theo chiều kim đồng hồ, đếm thời gian còn lại để nhận xu, giữa vòng là m:ss; đủ thời gian thì vòng chuyển thành nút "Nhận". Vòng nằm trong trình xem video (giai đoạn A đã dựng sẵn khung, có hàng `data-reward-row="xu"`).

Khách vãng lai vẫn xem được video, nhưng muốn nhận xu hay điểm danh thì phải đăng nhập.

Ngoài phạm vi bản đầu: livestream (chưa đếm giờ xem live), rút xu ra tiền, bảng xếp hạng, nhiệm vụ mạng xã hội.

## 2. Quyết định đã chốt với người dùng

| Vấn đề | Quyết định |
| --- | --- |
| Luật xu theo video | Admin đặt số xu cho từng chiến dịch video; mỗi tài khoản nhận 1 lần/video |
| Xu có tính vào hạng và dùng khi thanh toán không | Có: tính chung vào `diemtl` (giống điểm đánh giá), có trần mỗi ngày |
| Giao diện máy tính | Video 16:9 lớn bên trái, cột Phần thưởng / Sản phẩm / Video tiếp theo bên phải |
| Đếm thời gian | Vòng tròn chạy theo chiều kim đồng hồ, đếm ngược, đơn giản, dễ hiểu |

## 3. Đơn vị xu và quy đổi (CẦN DUYỆT)

Hiện tại `HomeController::POINT_VALUE_VND = 1000` (1 điểm trừ 1.000đ khi thanh toán), còn mua hàng được 1 điểm cho mỗi 10.000đ. Nếu 1 xu = 1 điểm thì "50 xu" sẽ thành 50.000đ, quá hào phóng.

Đề xuất:
- **100 xu = 1 điểm tích lũy = 1.000đ** (tức 1 xu = 10đ). Hằng số nằm ở một chỗ: `RewardLedger::XU_PER_POINT = 100`.
- Mỗi tài khoản nhận **tối đa 300 xu/ngày** từ video (tính chung cả ba nguồn: theo video, điểm danh, mốc xem). 300 xu tương đương 3 điểm, tức 3.000đ/ngày, và tối đa khoảng 90 điểm/tháng, nên không thể "cày" lên VIP (500 điểm).
- Bảng điểm danh mặc định: 10 · 10 · 20 · 10 · 10 · 20 · 50 (hộp quà ngày 7). Mốc xem mặc định: 5 phút được 10 xu, 15 phút được 20 xu, 30 phút được 40 xu. Xu theo video do admin đặt, mặc định 20, tối đa 100.
- Trên giao diện hiển thị cả hai: "1.250 xu ≈ 12 điểm (12.000đ)".

## 4. Kiến trúc

### 4.1 Đếm thời gian xem đã được server xác thực, cho mọi video

Hiện tại `VideoWatchSession::startOrResumeSession` trả `NO_ACTIVE_CAMPAIGN` khi video không có chiến dịch voucher, nên những video đó không đếm được giờ xem.

Thay đổi:
- Cho phép phiên xem **không gắn chiến dịch** (`campaign_id = null`, `mode = 'time'`) khi đã đăng nhập. Phiên vẫn kiểm tra như cũ: video phải đã xuất bản, có giới hạn nhịp heartbeat, kiểm tra tốc độ phát, không cộng phần tua tới. Riêng các bước về chiến dịch, đủ điều kiện và nhận voucher thì bỏ qua khi `mode = 'time'`.
- Các phiên có chiến dịch giữ nguyên hành vi (không phá luồng voucher đang chạy).
- Mỗi lần heartbeat hợp lệ, phần số giây **mới được cộng** (`watchedSecondsAfter − watchedSecondsBefore`, đã giới hạn bởi thời gian thật trôi qua trên server) được `$inc` vào tài liệu theo ngày `video_watch_daily`, khóa `{customer_id, date_vn}` (theo giờ `Asia/Ho_Chi_Minh`). Xem lại một đoạn đã được tính thì không cộng thêm. Một khách mở nhiều tab cùng lúc cũng không được cộng nhanh hơn thời gian thật: `video_watch_daily.last_credit_at` chặn tổng số giây cộng trong một khoảng không vượt quá số giây thật đã trôi qua.

### 4.2 Sổ xu (ledger) — nguồn sự thật

Tạo collection mới `reward_xu_ledger`, mỗi bản ghi là một lần cộng xu:

```
{ _id, customer_id, ma_kh, amount_xu (>0), source: 'video'|'checkin'|'watch_milestone',
  source_key (unique), date_vn, meta: {video_id?, campaign_id?, streak_day?, milestone_min?},
  created_at }
```

- Index unique trên `source_key` giúp chống cộng trùng khi bấm nhiều lần hoặc gọi lại request:
  - xu theo video: `video:{customer_id}:{campaign_id}`
  - điểm danh: `checkin:{customer_id}:{date_vn}`
  - mốc xem: `milestone:{customer_id}:{date_vn}:{5|15|30}`
- Trần ngày được kiểm tra trước khi ghi, dựa trên tổng `amount_xu` của ngày đó. Nếu vượt trần thì chỉ cộng phần còn lại; khi còn 0 thì trả mã `DAILY_CAP_REACHED` và giao diện hiện "Hôm nay bạn đã nhận đủ xu".
- Model mới: `backend/app/models/RewardLedger.php`, gồm `award()`, `balanceXu()`, `todayXu()`, `history()`.

### 4.3 Gộp vào điểm tích lũy

`HoaDon::syncCustomerLoyaltyByMaKh` tính lại `diemtl` từ đầu mỗi lần chạy. Vì vậy thêm một dòng cạnh dòng điểm đánh giá:

```
$videoBonusPoints = intdiv(RewardLedger::sumXu($maKh), RewardLedger::XU_PER_POINT);
$totalPoints = max(0, $totalPoints + $reviewBonusPoints + $videoBonusPoints);
```

- Sau mỗi lần `award()` thành công, gọi `syncCustomerLoyaltyByMaKh` để `diemtl` và hạng khách cập nhật ngay. Cần đổi hàm này từ `private` sang `public`, hoặc thêm một wrapper public.
- Phần xu lẻ (chưa đủ 100) hiển thị là "xu đang tích", chưa thành điểm.

### 4.4 Xu theo video (admin đặt)

- Thêm vào `VideoRewardCampaign` trường `reward_xu` (int, 0–100, mặc định 0). Bằng 0 thì chiến dịch chỉ có voucher như cũ.
- Form admin chiến dịch (trang quản lý video sản phẩm) có thêm ô "Thưởng xu (0–100)".
- Khi phiên đạt `ELIGIBLE`, nút "Nhận" trong vòng đếm ngược gọi `video_watch_claim_reward`. Endpoint này cấp voucher như cũ, và nếu `reward_xu > 0` thì cộng thêm xu qua `award('video', ...)`. Response trả về thêm `xu_awarded` và `xu_balance`.

### 4.5 Endpoint mới (`VideoWatchController` / `RewardController` mới)

| Route | Method | Việc |
| --- | --- | --- |
| `reward_summary` | GET | Số xu, điểm quy đổi, xu hôm nay, trạng thái chuỗi điểm danh (7 ô), số giây đã xem hôm nay, các mốc đã nhận |
| `reward_checkin` | POST | Điểm danh: cần `video_watch_daily.seconds >= 60` hôm nay; tính ngày trong chuỗi |
| `reward_milestone_claim` | POST `{minutes}` | Nhận hộp quà mốc nếu đã xem đủ số phút |
| `reward_history` | GET | 30 bản ghi gần nhất trong ledger |
| `phan_thuong` | GET (trang) | Trang Phần thưởng |

Tất cả các route: chỉ cho tài khoản đã đăng nhập (guest nhận 401 kèm link đăng nhập), POST có kiểm tra CSRF theo cách các form hiện tại đang làm, và response JSON có dạng `{ok, code, message, ...}` giống `VideoWatchController`.

Cách tính chuỗi điểm danh: dựa vào bản ghi `checkin` gần nhất. Nếu là hôm qua thì `streak_day = prev + 1`, quá 7 thì quay về 1. Nếu không phải hôm qua thì `streak_day = 1`.

## 5. Giao diện

### 5.1 Vòng đếm ngược (dùng chung)
- Helper `createRing(el).set(progress, remainingSec, state)` từ giai đoạn A, viết bằng SVG: vòng chạy theo chiều kim đồng hồ từ vị trí 12 giờ, nét vàng `#C9A24A` trên nền mờ, giữa vòng là m:ss.
- Các trạng thái: `counting`, `paused` (hiện chữ "Đang tạm dừng"), `ready` (nút "Nhận"), `done` (dấu ✓ "Đã nhận"), `capped` ("Đủ xu hôm nay").
- Server là nguồn sự thật. Giữa hai heartbeat, client chỉ nội suy khi video đang phát, và không bao giờ vượt quá giá trị server cộng 4 giây.

### 5.2 Trong trình xem video
- Hàng "+N xu" (đã có chỗ sẵn từ giai đoạn A) hiện khi `reward_xu > 0`.
- Thêm một hàng "Điểm danh hôm nay": vòng đếm 60 giây cho đến khi đủ; đủ rồi thì chuyển thành nút "Điểm danh +10 xu".
- Khi nhận xu thành công: một toast nhỏ "+20 xu" bay lên rồi mờ dần (không có hiệu ứng khi người dùng bật `prefers-reduced-motion`).

### 5.3 Trang Phần thưởng (`frontend/views/phan_thuong.php`)
Từ trên xuống dưới:
1. **Hero**: nền xanh rêu, tiêu đề "Phần thưởng", dòng phụ "Xem video Syna, nhận xu đổi ưu đãi".
2. **Thẻ số dư**: "Xu hiện có 1.250 ≈ 12 điểm (12.000đ)", kèm nút "Dùng khi thanh toán" dẫn tới trang giỏ hàng.
3. **Điểm danh hằng ngày**: 7 ô ngày; các ô đã nhận có dấu ✓, ô hôm nay có viền vàng, ô ngày 7 là hộp quà. Nút "Điểm danh" có hai trạng thái: khi chưa đủ thì bị khóa và hiện vòng đếm "Xem thêm 0:40", khi đủ thì bấm được. Dòng chú thích: "Xem ít nhất 1 phút video để điểm danh".
4. **Xem video mỗi ngày**: thanh 0–30 phút có 3 hộp quà ở các mốc 5/15/30, kèm nút "Xem ngay" mở danh sách video có thưởng.
5. **Lịch sử xu**: danh sách "+20 xu · Xem video Serum B5 · 12/11".
- Trang responsive (điện thoại 375px không bị cuộn ngang), dùng màu SkinSyntax, không dùng nhận diện của bên thứ ba.
- Lối vào trang: thêm mục "Phần thưởng" vào menu tài khoản/header, cộng một badge "Điểm danh" khi hôm nay chưa điểm danh.

## 6. Bảo mật và chống gian lận
- Mọi số giây đều do server tính (dùng lại `recordHeartbeat`). Client không bao giờ gửi lên số xu hay số giây.
- Index unique `source_key` cộng trần ngày chặn cộng trùng và chặn cày xu.
- Phân quyền: chỉ chủ tài khoản mới nhận được xu cho chính mình. `customer_id` lấy từ session, không lấy từ body request.
- Log chỉ ghi `customer_id` và mã lỗi, không ghi email hay token.

## 7. Kiểm thử
- **Pytest/PHPUnit**: theo cách repo đang làm. Không chạy các script trong `backend/tests` vì chúng ghi vào DB thật. Test mới dùng collection giả, hoặc DB test riêng bằng cách đặt `MONGO_URI` trỏ tới DB test.
- **Unit test cho `RewardLedger`**: cộng trùng bị chặn, trần ngày được cắt đúng, quy đổi xu sang điểm, chuỗi điểm danh (liên tiếp, đứt chuỗi, quay vòng sau ngày 7).
- **Unit test cho phiên `mode = 'time'`**: cộng giây theo ngày, không cộng phần xem lại hay phần tua tới, mở nhiều tab không cộng nhanh hơn thời gian thật.
- **Contract test cho các route mới**: kiểm tra guest bị chặn (401), CSRF, và dạng response.
- **Kiểm thử thật trên trình duyệt** (desktop và điện thoại): xem 60 giây rồi điểm danh, nhận xu theo video, trang Phần thưởng cập nhật số dư, trang thanh toán hiển thị điểm mới.

## 8. Thứ tự triển khai
1. `RewardLedger` + collection + index, kèm unit test.
2. Phiên `mode = 'time'` + cộng vào `video_watch_daily`, kèm test.
3. Gộp vào `syncCustomerLoyaltyByMaKh`, kèm test (đây là thay đổi có rủi ro cao nhất vì đụng hạng khách, phải chạy GitNexus impact trước khi sửa).
4. Endpoint `reward_*` và `reward_xu` cho chiến dịch (gồm form admin).
5. Giao diện: hàng xu và hàng điểm danh trong trình xem, trang Phần thưởng, lối vào trang.
6. Kiểm thử E2E trên trình duyệt.

## 9. Câu hỏi mở
- Tỉ lệ 100 xu = 1 điểm và trần 300 xu/ngày (mục 3) có ổn không?
- Bảng xu điểm danh và các mốc xem (mục 3) có cần admin chỉnh được không, hay để hằng số là đủ cho bản đầu?
