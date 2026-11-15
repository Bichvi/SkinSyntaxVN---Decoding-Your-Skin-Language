# Xem video nhận xu, điểm danh và trang Phần thưởng (2026-11-12 → 2026-11-15)

Nhánh `syna_10_11`. **Đã commit.** Spec: `docs/superpowers/specs/2026-11-12-video-rewards-xu-design.md`. Plan: `docs/superpowers/plans/2026-11-15-video-rewards-xu.md`.

## Người dùng thấy gì

### Trang chi tiết sản phẩm (`?r=chitiet`)
- **Lưới 2×2 cân đối:**
  - Hàng trên là thẻ ảnh (thumbnail nằm trong thẻ) và thẻ thông tin rút gọn: thông số thành chip, còn hàng và "Hỏi AI" gộp một dòng.
  - Hàng dưới là "Quyền lợi mua hàng" bên trái và "Video về sản phẩm" bên phải.
  - Đo ở 1440px: hai thẻ cùng hàng có cùng mép trên, mép dưới, mép trái và mép phải.
  - Trên điện thoại thứ tự là ảnh, thông tin, quyền lợi rồi video, không cuộn ngang ở 375px.
- **Mini player** phát video mới nhất ở góc phải.
- **Thanh voucher gọn** chỉ hiện khi video mới nhất có chiến dịch voucher.

### Trình xem video (popup)
- Máy tính: video 16:9 ở bên trái, cột Phần thưởng / Sản phẩm / Video tiếp theo ở bên phải.
- Điện thoại: toàn màn hình, có bottom sheet.
- Khối **Phần thưởng** chỉ nằm trong trình xem, không hiện trên trang chi tiết. Khối này gồm:
  - vòng voucher, cho video có chiến dịch voucher;
  - hàng "+N xu", cho chiến dịch có `reward_xu`;
  - **Điểm danh hôm nay**: vòng đếm 60 giây rồi nút "+N xu";
  - **Mốc xem 5/15/30 phút**: hiện tiếp sau khi đã điểm danh, kèm nút **Nhận**.
- Vòng chạy theo chiều kim đồng hồ.
- Vòng chỉ chạy khi đang phát **đoạn chưa xem**. Khi tua, tua ngược, xem lại, tạm dừng hoặc đổi tốc độ thì vòng đứng yên, và nó không bao giờ chạy lùi.
- Khi nhận được xu, toast "+N xu" hiện kèm link đến trang Phần thưởng.

### Trang Phần thưởng (`?r=phan_thuong`)
- Mục **Xu đã tích lũy**, kèm **Điểm khả dụng** lấy từ `diemtl`.
- Điểm danh 7 ô (10·10·20·10·10·20·50, ô ngày 7 là hộp quà).
- Mốc 5/15/30 phút và lịch sử xu.
- Khách vãng lai thấy nút "Đăng nhập để nhận xu".
- Tài khoản quản trị hoặc nhân viên thấy "Tài khoản quản trị không tích xu."
- Header có link "Phần thưởng" kèm chấm "Điểm danh"; link này chỉ hiện cho tài khoản `khach_hang`.

### Admin (`product_videos`)
- Ô **Thưởng xu (0–100)** trong form chiến dịch, mặc định 20 khi tạo mới.

## Luật

| Mục | Giá trị |
| --- | --- |
| Quy đổi | 100 xu = 1 điểm tích lũy = 1.000đ (`RewardRules::XU_PER_POINT`) |
| Trần | 300 xu/ngày/tài khoản, tính chung xu video, điểm danh và mốc xem |
| Điểm danh | Cần ≥ 60 giây xem trong ngày. Bỏ một ngày thì chuỗi quay về ngày 1, sau ngày 7 cũng quay về ngày 1. Khi đã chạm trần, điểm danh vẫn được ghi (giữ chuỗi), chỉ là 0 xu |
| Mốc xem | 5 phút → 10 xu, 15 phút → 20 xu, 30 phút → 40 xu |
| Xu theo video | Mỗi tài khoản nhận 1 lần cho mỗi chiến dịch. Chỉ chủ phiên xem mới nhận được (`hash_equals`) |
| Xem lại | Trong cùng ngày, đoạn đã xem không được tính lại. Sang ngày mới thì video cũ được tính lại (người dùng chọn ngày 2026-11-15) |
| Ngày | Theo giờ `Asia/Ho_Chi_Minh` |

## Kỹ thuật

- **Collection mới:**
  - `reward_xu_ledger`: unique `source_key`, kèm index `{ma_kh, date_vn}`.
  - `video_watch_daily`: unique `{customer_id, date_vn}`.
  - `reward_checkins`: unique `{ma_kh, date_vn}`.
  - Index được tạo ở cold path, tức constructor mặc định hoặc `startOrResumeSession`. Đường heartbeat, đường tóm tắt (summary) và đường tính điểm không tạo index.
  - **Nên tạo trước các index này trên Atlas**, phòng trường hợp tài khoản DB không có quyền `createIndex`.
- **Phiên xem `mode='time'`** (`VideoWatchSession`):
  - Dành cho khách đã đăng nhập xem video không có voucher.
  - Mỗi heartbeat hợp lệ cộng số giây mới vào `video_watch_daily`, giới hạn theo thời gian thật (thêm tối đa 1 giây mỗi lần).
  - `validateCompletion` trả về `TIME_MODE` và không ghi gì.
- **Điểm tích lũy:** `HoaDon::syncCustomerLoyaltyByMaKh` cộng thêm `intdiv(tổng xu, 100)`.
  - Hàm này có rủi ro **HIGH** theo GitNexus (13 nơi phụ thuộc: đặt hàng, hủy, xác nhận).
  - Phần cộng thêm được bọc try/catch và kiểm tra `is_file` trước khi require. Sổ xu lỗi thì tính là 0, không làm hỏng đơn hàng.
- **Route mới:**
  - `phan_thuong`
  - `reward_summary`
  - `reward_history` (trả về `{ok, code, items}`)
  - `reward_checkin`
  - `reward_milestone_claim`
- **Bảo mật:**
  - Các POST bắt buộc có header `X-CSRF-Token` (`RewardCsrf`, so bằng `hash_equals`).
  - Khách vãng lai nhận 401.
  - Lỗi tạm thời khi điểm danh trả về 503 `TEMPORARY_ERROR` và xóa bản ghi `reward_checkins` vừa tạo, để lần thử lại vẫn được cộng xu.
- **File chính:**
  - Backend: `backend/app/services/{RewardRules,RewardService,RewardCsrf}.php`, `backend/app/models/{RewardLedger,VideoWatchDaily,RewardCheckin}.php`, `backend/app/controllers/RewardController.php`.
  - Frontend: `frontend/views/phan_thuong.php`, `frontend/public/assets/{css/phan-thuong.css,js/phan-thuong.js}`.
  - File sửa: `HoaDon.php`, `VideoWatchSession.php`, `VideoRewardCampaign.php`, `VideoWatchController.php`, `SanPhamController.php`, `backend/public/index.php`, `frontend/views/chitiet.php`, `frontend/views/layouts/header.php`, `frontend/views/admin/product_videos.php`, `frontend/public/assets/js/admin-product-videos.js`.

## Kiểm thử đã chạy

### Unit test (Mongo giả, không chạm Atlas)
Chạy bằng `C:\xampp\php\php.exe backend/tests/unit/<File>.php`:

| File | Kết quả |
| --- | --- |
| RewardRules | 16/0 |
| RewardLedger | 13/0 |
| HoaDonVideoXuLoyalty | 5/0 |
| VideoWatchTimeMode | 9/0 |
| RewardService | 24/0 |
| VideoWatchXuGate | 8/0 |
| RewardController | 11/0 |
| HoaDonStockEvent (test cũ) | 26/0 |

### API thật trên localhost (tài khoản test `e2e.customer@skinsyntax.test`)

| Trường hợp | Kết quả |
| --- | --- |
| Đăng nhập | OK |
| `reward_summary` | 200, cấu trúc đúng |
| `reward_checkin` không có CSRF | 403 |
| `reward_checkin` khi chưa xem đủ | 400 `NOT_ENOUGH_WATCH` |
| Mốc 7 phút | 400 |
| `reward_history` khi là khách vãng lai | 401 |

### Trình duyệt
- **Máy tính 1440px:**
  - Lưới 2×2 thẳng hàng.
  - Trình xem hiện "Đã điểm danh · Ngày 1" và "Mốc 5 phút · +10 xu".
  - Vòng voucher đứng yên khi tua về 0 rồi xem lại 6 giây.
  - Vòng chạy tiếp khi phát qua đoạn đã xem.
- **Điện thoại 375px:** đúng thứ tự, không cuộn ngang.
- **Điểm danh thật:** người dùng tự đăng nhập tài khoản test trong khung trình duyệt và điểm danh ngày 1 thành công.
- **Chưa chạy E2E:**
  - nhận xu theo video với chiến dịch có `reward_xu`;
  - bấm Nhận ở mốc 5 phút sau khi xem đủ;
  - trang thanh toán hiển thị điểm mới.

## Rủi ro còn lại / việc sau

- **Dữ liệu test trên Atlas:** tài khoản test có bản ghi `reward_checkins`, `reward_xu_ledger`, `video_watch_daily` và phiên xem.
  - Dọn bằng tay theo `ma_kh` của tài khoản test, hoặc chạy `php scripts/e2e/remove_test_accounts.php`. Script này chỉ xóa tài khoản, không xóa các bản ghi phần thưởng.
- **Trần ngày là trần "mềm"** khi có nhiều request cùng lúc với `source_key` khác nhau: có thể lố thêm một lần cộng.
- **Cộng giây không nguyên tử:** mở nhiều tab lệch pha thì tổng giây được cộng có thể nhanh hơn thời gian thật tối đa khoảng (4+N)/4 lần, trong đó N là số tab.
- **Cache 30 phút** dữ liệu video của `SanPhamController`: admin sửa `reward_xu` thì phải chờ cache hết hạn mới thấy trên trang.
- Các mục nhỏ còn lại nằm trong ledger SDD `.superpowers/sdd/2026-11-15-video-rewards-xu/progress.md`, là file git bỏ qua.
