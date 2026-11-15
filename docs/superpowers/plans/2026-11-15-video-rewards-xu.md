# Xem video nhận xu, điểm danh và trang Phần thưởng — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Khách đã đăng nhập xem video sản phẩm để nhận xu (theo video, điểm danh 7 ngày, mốc phút xem mỗi ngày). Xu quy đổi vào điểm tích lũy `diemtl`, và có trang Phần thưởng `index.php?r=phan_thuong`.

**Architecture:**
- **Luật tính toán:** gom vào lớp thuần `RewardRules`, không đụng DB.
- **Dữ liệu:** hai collection mới. `reward_xu_ledger` là sổ xu, nguồn sự thật, khóa `source_key` unique để chống cộng trùng. `video_watch_daily` lưu số giây xem đã được server xác thực, theo khách và theo ngày giờ Việt Nam.
- **Đếm giờ xem:** phiên xem video có thêm chế độ `mode = 'time'` (không cần chiến dịch voucher). Mỗi heartbeat hợp lệ cộng số giây mới vào `video_watch_daily`.
- **Điều phối:** `RewardService` gom ledger, daily, tra `ma_kh` và đồng bộ điểm. `RewardController` cung cấp JSON và trang.
- **Gộp vào điểm tích lũy:** `HoaDon::syncCustomerLoyaltyByMaKh` cộng thêm `intdiv(tổng xu, 100)` vào `diemtl`.

**Tech Stack:** PHP 8 (XAMPP, `C:\xampp\php\php.exe`), MongoDB PHP library 1.19 (Atlas), Bootstrap 5, JS thuần. Test là script PHP thuần trong `backend/tests/Unit`, theo kiểu `$check` hiện có, không chạm DB thật.

**Spec:** `docs/superpowers/specs/2026-11-12-video-rewards-xu-design.md`

## Global Constraints

- **Tỉ lệ quy đổi:** 100 xu = 1 điểm tích lũy = 1.000đ (`XU_PER_POINT = 100`).
- **Trần ngày:** 300 xu/ngày/tài khoản (`DAILY_CAP_XU = 300`), tính chung cả ba nguồn `video`, `checkin` và `watch_milestone`.
- **Điểm danh:** bảng 10 · 10 · 20 · 10 · 10 · 20 · 50 cho ngày 1→7. Cần xem ít nhất 60 giây video trong ngày. Bỏ một ngày thì chuỗi quay về ngày 1; sau ngày 7 cũng quay về ngày 1.
- **Mốc xem mỗi ngày:** 5 phút → 10 xu, 15 phút → 20 xu, 30 phút → 40 xu.
- **Xu theo video:** trường `reward_xu` của chiến dịch, nằm trong khoảng 0–100. Chiến dịch cũ (không có trường này) tính là 0. Form admin điền sẵn 20 khi tạo chiến dịch mới. Mỗi tài khoản nhận 1 lần cho mỗi chiến dịch.
- **Bảng xu và mốc xem:** là hằng số trong `RewardRules`, không cho admin chỉnh trong bản này.
- **Ngày:** mọi "ngày" tính theo `Asia/Ho_Chi_Minh` (`date_vn` dạng `Y-m-d`).
- **Phân quyền:**
  - Chỉ khách đã đăng nhập mới nhận xu hoặc điểm danh được. Khách vãng lai gọi API nhận `401 LOGIN_REQUIRED`.
  - `customer_id` luôn lấy từ session, không lấy từ body request.
  - `ma_kh` tra qua `TaiKhoan::getKhachHangByEmail(email trong session)`. Tài khoản không có hồ sơ `khach_hang` thì nhận `NO_CUSTOMER_PROFILE`.
- **Server là nguồn sự thật:** client không bao giờ gửi số xu hay số giây lên.
- **CSRF:** mọi POST `reward_*` phải có header `X-CSRF-Token` khớp `$_SESSION['reward_csrf']` (so sánh bằng `hash_equals`).
- **Dạng response JSON:** `{ok, code, message, ...}`, giống `VideoWatchController`. Lỗi trả mã HTTP 400/401/403/409 tương ứng.
- **Log:** chỉ ghi `customer_id` và mã lỗi; không ghi email, token hay chuỗi kết nối DB.
- **Copy giao diện:** tiếng Việt có dấu. Dùng màu SkinSyntax (xanh `#183B2B`, vàng `#C9A24A`), không dùng nhận diện của bên thứ ba.
- **Kiểm thử:**
  - Không chạy các script cũ trong `backend/tests` vì chúng ghi vào DB thật. Chỉ chạy các file `backend/tests/Unit/*Test.php` mới, viết theo kiểu Mongo giả.
  - Lệnh chạy test: `C:\xampp\php\php.exe backend/tests/Unit/<File>.php`. Đạt khi in ra `N passed, 0 failed` và exit code 0.
- **Trước khi sửa:**
  - Chạy GitNexus impact cho symbol sẽ sửa: `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .`
  - `syncCustomerLoyaltyByMaKh` đang ở mức **HIGH** (13 phụ thuộc, gồm đặt hàng, hủy và xác nhận đơn), nên phải báo người dùng trước.
- **Git:**
  - Không commit nếu người dùng chưa yêu cầu. Các bước "Checkpoint" chỉ commit khi đã được phép, và phải chạy `node .gitnexus/run.cjs detect-changes --scope all --repo .` trước.
  - `frontend/views/chitiet.php` dùng xuống dòng LF; giữ nguyên.

## Review Focus

1. **Một khách mở 2 tab hoặc 2 thiết bị xem cùng lúc.** Tổng số giây được cộng trong ngày không được tăng nhanh hơn thời gian thật (cho phép lệch 1 giây mỗi lần cộng). Test nằm ở Task 4.
2. **Ranh giới nửa đêm theo giờ Việt Nam.** 23:59 và 00:00 giờ VN là hai ngày khác nhau, kể cả khi theo giờ UTC chúng vẫn cùng một ngày (00:00 VN = 17:00 UTC hôm trước). Test nằm ở Task 1 và Task 5.
3. **Sổ xu lỗi (Mongo ném exception) trong lúc đồng bộ điểm sau đặt hoặc hủy đơn.** Đồng bộ vẫn phải hoàn tất, phần xu tính là 0 và không ném lỗi ra ngoài. Test nằm ở Task 3.
4. **Bấm "Điểm danh" hai lần, hoặc hai request đến cùng lúc.** Chỉ được có một bản ghi trong sổ; lần thứ hai trả `ALREADY_CHECKED_IN` thay vì lỗi 500. Test nằm ở Task 2 và Task 5.
5. **Số xu cần cộng lớn hơn phần trần còn lại trong ngày** (ví dụ đã có 290 xu, video thưởng 20). Chỉ cộng 10 xu. Khi đã chạm đúng 300 thì trả `DAILY_CAP_REACHED` và không ghi bản ghi 0 xu. Test nằm ở Task 2.

---

## File Structure

| File | Trách nhiệm |
| --- | --- |
| `backend/app/services/RewardRules.php` (mới) | Hằng số và hàm thuần: ngày VN, chuỗi điểm danh, trần ngày, quy đổi, giới hạn cộng giây |
| `backend/app/models/RewardLedger.php` (mới) | Collection `reward_xu_ledger`: ghi một lần cộng xu, tính tổng, lịch sử |
| `backend/app/models/VideoWatchDaily.php` (mới) | Collection `video_watch_daily`: cộng giây xem theo ngày, có giới hạn theo thời gian thật |
| `backend/app/services/RewardService.php` (mới) | Điều phối: tra khách, cộng xu theo video, điểm danh, mốc xem, tổng quan, lịch sử |
| `backend/app/services/RewardCsrf.php` (mới) | Sinh và kiểm token CSRF cho các route `reward_*` |
| `backend/app/controllers/RewardController.php` (mới) | Các route JSON và trang `phan_thuong` |
| `backend/app/models/HoaDon.php` (sửa) | Cộng điểm từ xu vào `diemtl`; thêm wrapper public |
| `backend/app/models/VideoWatchSession.php` (sửa) | Phiên `mode='time'`; heartbeat cộng giây theo ngày |
| `backend/app/models/VideoRewardCampaign.php` (sửa) | Trường `reward_xu` |
| `backend/app/controllers/VideoWatchController.php` (sửa) | Identity static; cộng xu khi phiên đủ điều kiện |
| `backend/app/controllers/SanPhamController.php` (sửa) | Truyền `rewardCsrf` và `isLoggedIn` cho trang chi tiết |
| `backend/public/index.php` (sửa) | Thêm 5 route |
| `frontend/views/admin/product_videos.php`, `frontend/public/assets/js/admin-product-videos.js` (sửa) | Ô "Thưởng xu (0–100)" |
| `frontend/views/chitiet.php` (sửa) | Hàng xu, hàng điểm danh và toast trong trình xem; mở phiên `time` cho mọi video |
| `frontend/views/phan_thuong.php`, `frontend/public/assets/css/phan-thuong.css`, `frontend/public/assets/js/phan-thuong.js` (mới) | Trang Phần thưởng |
| `frontend/views/layouts/header.php` (sửa) | Lối vào "Phần thưởng" kèm chấm "Điểm danh" |
| `backend/tests/Unit/support/FakeMongo.php` (mới) | Mongo giả trong bộ nhớ, dùng chung cho các test mới |
| `backend/tests/Unit/RewardRulesTest.php`, `RewardLedgerTest.php`, `HoaDonVideoXuLoyaltyTest.php`, `VideoWatchTimeModeTest.php`, `RewardServiceTest.php`, `RewardControllerTest.php` (mới) | Test |

---

### Task 1: `RewardRules` — luật thuần

**Files:**
- Create: `backend/app/services/RewardRules.php`
- Test: `backend/tests/Unit/RewardRulesTest.php`

**Interfaces:**
- Produces: `final class RewardRules`, chỉ có hằng số và hàm `public static`.
  - Hằng số:
    - `TZ = 'Asia/Ho_Chi_Minh'`
    - `XU_PER_POINT = 100`
    - `DAILY_CAP_XU = 300`
    - `CHECKIN_XU = [10,10,20,10,10,20,50]`
    - `CHECKIN_MIN_SECONDS = 60`
    - `MILESTONE_XU = [5 => 10, 15 => 20, 30 => 40]`
    - `VIDEO_XU_MAX = 100`
    - `VIDEO_XU_FORM_DEFAULT = 20`
    - `MAX_FIRST_CREDIT_SECONDS = 10.0`
    - `CREDIT_JITTER_SECONDS = 1.0`
  - Hàm:
    - `dateVn(int $ts): string`
    - `previousDateVn(string $dateVn): string`
    - `nextStreakDay(?string $lastCheckinDate, int $lastStreakDay, string $todayVn): int`
    - `checkinXu(int $streakDay): int`
    - `trimToCap(int $amountXu, int $todayXu): int`
    - `pointsFromXu(int $totalXu): int`
    - `clampCredit(float $delta, float $now, ?float $lastCreditAt): float`
    - `milestoneXu(int $minutes): ?int`
    - `normalizeVideoXu($value): int`

- [ ] **Step 1: Write the failing test** `backend/tests/Unit/RewardRulesTest.php`, theo khung `$check` và `exit($fail ? 1 : 0)` như `CartInsightsTest.php`. Các assertion:

```php
$check('dateVn: 16:59:59 UTC vẫn là ngày cũ', RewardRules::dateVn(gmmktime(16,59,59,11,14,2026)) === '2026-11-14');
$check('dateVn: 17:00 UTC là 00:00 VN hôm sau', RewardRules::dateVn(gmmktime(17,0,0,11,14,2026)) === '2026-11-15');
$check('previousDateVn qua tháng', RewardRules::previousDateVn('2026-12-01') === '2026-11-30');
$check('streak: chưa điểm danh lần nào -> 1', RewardRules::nextStreakDay(null, 0, '2026-11-15') === 1);
$check('streak: hôm qua ngày 3 -> 4', RewardRules::nextStreakDay('2026-11-14', 3, '2026-11-15') === 4);
$check('streak: hôm qua ngày 7 -> quay về 1', RewardRules::nextStreakDay('2026-11-14', 7, '2026-11-15') === 1);
$check('streak: cách 2 ngày -> 1', RewardRules::nextStreakDay('2026-11-13', 5, '2026-11-15') === 1);
$check('checkinXu ngày 3 = 20, ngày 7 = 50, ngoài 1..7 = 0', RewardRules::checkinXu(3) === 20 && RewardRules::checkinXu(7) === 50 && RewardRules::checkinXu(8) === 0);
$check('trimToCap: 290 + 20 -> 10', RewardRules::trimToCap(20, 290) === 10);
$check('trimToCap: đã 300 -> 0; vượt 300 -> 0', RewardRules::trimToCap(20, 300) === 0 && RewardRules::trimToCap(20, 350) === 0);
$check('pointsFromXu: 1299 -> 12', RewardRules::pointsFromXu(1299) === 12);
$check('clampCredit: lần đầu tối đa 10s', RewardRules::clampCredit(30.0, 1000.0, null) === 10.0);
$check('clampCredit: không vượt thời gian thật + 1s', RewardRules::clampCredit(4.0, 1002.0, 1000.0) === 3.0);
$check('clampCredit: delta âm -> 0', RewardRules::clampCredit(-2.0, 1010.0, 1000.0) === 0.0);
$check('milestoneXu', RewardRules::milestoneXu(15) === 20 && RewardRules::milestoneXu(10) === null);
$check('normalizeVideoXu: kẹp 0..100, rác -> 0', RewardRules::normalizeVideoXu('150') === 100 && RewardRules::normalizeVideoXu(-5) === 0 && RewardRules::normalizeVideoXu('abc') === 0 && RewardRules::normalizeVideoXu(null) === 0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\xampp\php\php.exe backend/tests/Unit/RewardRulesTest.php`
Expected: lỗi `Class "RewardRules" not found` (hoặc `Failed opening required`).

- [ ] **Step 3: Implement `RewardRules`.** Các hàm ngày dùng `DateTimeImmutable` kèm `DateTimeZone(self::TZ)`. `clampCredit` trả về `max(0, min($delta, $allowance))`, trong đó `$allowance` bằng `MAX_FIRST_CREDIT_SECONDS` khi `$lastCreditAt === null`, ngược lại bằng `max(0, $now - $lastCreditAt) + CREDIT_JITTER_SECONDS`. `nextStreakDay` chỉ nối chuỗi khi `$lastCheckinDate === previousDateVn($todayVn)` và `$lastStreakDay < 7`.

- [ ] **Step 4: Run test to verify it passes**

Run: `C:\xampp\php\php.exe backend/tests/Unit/RewardRulesTest.php`
Expected: `16 passed, 0 failed`.

- [ ] **Step 5: Checkpoint** (commit chỉ khi đã được phép): `git add backend/app/services/RewardRules.php backend/tests/Unit/RewardRulesTest.php`, message `feat(rewards): add pure xu reward rules`.

---

### Task 2: Mongo giả, `RewardLedger` và `VideoWatchDaily`

**Files:**
- Create: `backend/tests/Unit/support/FakeMongo.php`
- Create: `backend/app/models/RewardLedger.php`
- Create: `backend/app/models/VideoWatchDaily.php`
- Test: `backend/tests/Unit/RewardLedgerTest.php`

**Interfaces:**
- Consumes: `RewardRules::trimToCap`, `RewardRules::clampCredit` (Task 1).
- Produces:
  - `FakeDb` (ví dụ `$db->reward_xu_ledger` trả về một `FakeCollection`) và `FakeCollection`.
    - Các phương thức: `createIndex(array $keys, array $opts = [])`, `findOne(array $filter, array $opts = [])`, `find(array $filter, array $opts = [])`, `insertOne(array $doc)`, `updateOne(array $filter, array $update, array $opts = [])`, `countDocuments(array $filter)`.
    - Thuộc tính `public array $docs` và `public bool $throwOnRead`.
    - `$throwOnRead = true` khiến `find`, `findOne` và `countDocuments` ném `RuntimeException`.
    - Filter hỗ trợ: so sánh bằng (đối tượng so qua chuỗi), `$in`, `$gte`, `$lt`, `$or`.
    - `opts` hỗ trợ `sort` (một khóa) và `limit`.
    - `updateOne` hỗ trợ `$set`, `$inc`, `$setOnInsert` và `upsert`.
    - `insertOne` vi phạm index `unique` thì ném `new RuntimeException('E11000 duplicate key', 11000)`.
  - `RewardLedger::COLLECTION = 'reward_xu_ledger'`
  - `RewardLedger::__construct($db)`: tạo index unique cho `source_key` và index thường cho `{ma_kh:1, date_vn:1}`.
  - `RewardLedger::award(array $entry): array`
    - Đầu vào: `customer_id` (string), `ma_kh` (int), `source` (`'video'|'checkin'|'watch_milestone'`), `source_key` (string), `amount_xu` (int > 0), `date_vn` (string), `meta` (array).
    - Trả về: `['ok' => bool, 'code' => 'OK'|'DUPLICATE'|'DAILY_CAP_REACHED'|'INVALID', 'xu_awarded' => int]`.
  - `RewardLedger::sumXu(int $maKh, ?string $dateVn = null): int`
  - `RewardLedger::lastCheckin(int $maKh): ?array`: trả về bản ghi `checkin` gần nhất, có `date_vn` và `meta.streak_day`.
  - `RewardLedger::history(int $maKh, int $limit = 30): array`: sắp xếp theo `created_at` giảm dần.
  - `RewardLedger::hasKey(string $sourceKey): bool`
  - `VideoWatchDaily::COLLECTION = 'video_watch_daily'`
  - `VideoWatchDaily::__construct($db)`: tạo index unique cho `{customer_id:1, date_vn:1}`.
  - `VideoWatchDaily::credit(string $customerId, string $dateVn, float $delta, float $now): float`: trả về số giây thực sự được cộng.
  - `VideoWatchDaily::secondsToday(string $customerId, string $dateVn): int`

- [ ] **Step 1: Write the failing test** `backend/tests/Unit/RewardLedgerTest.php`. Đầu file `require` `support/FakeMongo.php`, `RewardRules.php`, `RewardLedger.php` và `VideoWatchDaily.php`. Biến dùng chung: `$e = fn($key, $xu, $src = 'video') => ['customer_id' => 'u1', 'ma_kh' => 7, 'source' => $src, 'source_key' => $key, 'amount_xu' => $xu, 'date_vn' => '2026-11-15', 'meta' => []];`. Các assertion:

```php
$r = $ledger->award($e('video:u1:c1', 20));
$check('award OK cộng đủ 20', $r === ['ok' => true, 'code' => 'OK', 'xu_awarded' => 20]);
$check('award trùng source_key -> DUPLICATE, không thêm bản ghi', $ledger->award($e('video:u1:c1', 20))['code'] === 'DUPLICATE' && count($db->reward_xu_ledger->docs) === 1);
// trùng do race: hasKey trả false nhưng insertOne ném 11000 (bật cờ forceDuplicateOnInsert cho lần gọi này rồi tắt)
$db->reward_xu_ledger->forceDuplicateOnInsert = true;
$check('insert ném 11000 -> DUPLICATE (không văng lỗi)', $ledger->award($e('checkin:u1:2026-11-15', 10, 'checkin'))['code'] === 'DUPLICATE');
$db->reward_xu_ledger->forceDuplicateOnInsert = false;
$ledger->award($e('milestone:u1:2026-11-15:30', 270, 'watch_milestone'));
$check('đã 290 -> award 20 chỉ cộng 10', $ledger->award($e('video:u1:c2', 20))['xu_awarded'] === 10 && $ledger->sumXu(7, '2026-11-15') === 300);
$check('chạm 300 -> DAILY_CAP_REACHED, không ghi bản ghi 0 xu', $ledger->award($e('video:u1:c3', 20))['code'] === 'DAILY_CAP_REACHED' && !$ledger->hasKey('video:u1:c3'));
$check('amount_xu <= 0 hoặc thiếu source_key -> INVALID', $ledger->award($e('', 5))['code'] === 'INVALID' && $ledger->award($e('video:u1:c4', 0))['code'] === 'INVALID');
$check('sumXu không lọc ngày = tổng mọi ngày', $ledger->sumXu(7) === 300);
$check('history mới nhất trước, giới hạn limit', count($ledger->history(7, 2)) === 2);
// VideoWatchDaily
$check('credit lần đầu tối đa 10s', $daily->credit('u1', '2026-11-15', 30.0, 1000.0) === 10.0);
$check('2 tab: credit 4s sau 2s thực chỉ được 3s', $daily->credit('u1', '2026-11-15', 4.0, 1002.0) === 3.0 && $daily->secondsToday('u1', '2026-11-15') === 13);
$check('ngày khác tách riêng', $daily->secondsToday('u1', '2026-11-16') === 0);
```

Lưu ý cho kịch bản "insert ném 11000": dựng bằng một `FakeCollection` có cờ `forceDuplicateOnInsert = true`, để `hasKey()` vẫn trả false nhưng `insertOne` ném lỗi mã 11000. Thêm cờ public này vào `FakeCollection`.

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\xampp\php\php.exe backend/tests/Unit/RewardLedgerTest.php`
Expected: `Class "RewardLedger" not found`.

- [ ] **Step 3: Implement `FakeMongo.php`, `RewardLedger` và `VideoWatchDaily`.**
  - `award()` chạy theo thứ tự: validate (INVALID) → `hasKey` (DUPLICATE) → `trimToCap(amount, sumXu(ma_kh, date_vn))`. Nếu kết quả bằng 0 thì trả DAILY_CAP_REACHED. Ngược lại `insertOne` với `amount_xu` đã cắt, `created_at` lấy giờ hiện tại theo UTC (`MongoDB\BSON\UTCDateTime` nếu class tồn tại, ngược lại dùng `time()`). Bắt `Throwable` có `getCode() === 11000` rồi trả DUPLICATE; mọi lỗi khác ném lại.
  - `credit()` đọc `last_credit_at` của tài liệu ngày đó, tính `RewardRules::clampCredit`. Nếu kết quả lớn hơn 0 thì `updateOne` với `upsert`, `$inc` cho `seconds` và `$set` cho `last_credit_at = $now`.
  - Chỉ dùng filter bằng nhau hoặc `$in` để Mongo giả xử lý được.

- [ ] **Step 4: Run test to verify it passes**

Run: `C:\xampp\php\php.exe backend/tests/Unit/RewardLedgerTest.php`
Expected: `11 passed, 0 failed`.

- [ ] **Step 5: Checkpoint**: add 4 file, message `feat(rewards): xu ledger and daily watch seconds`.

---

### Task 3: Gộp xu vào điểm tích lũy (`HoaDon`) — RỦI RO HIGH

**Files:**
- Modify: `backend/app/models/HoaDon.php`, gồm `syncCustomerLoyaltyByMaKh` (khoảng dòng 304–338) và một wrapper public mới.
- Test: `backend/tests/Unit/HoaDonVideoXuLoyaltyTest.php`

**Interfaces:**
- Consumes: `RewardLedger::sumXu(int $maKh)`, `RewardRules::pointsFromXu` (Task 1–2).
- Produces: `HoaDon::syncLoyaltyForCustomer(int $maKh): void`, gọi thẳng `syncCustomerLoyaltyByMaKh`.

- [ ] **Step 1: Báo rủi ro.** Chạy `node .gitnexus/run.cjs impact "syncCustomerLoyaltyByMaKh" --direction upstream --repo .`. Báo cho người dùng biết mức risk (HIGH), danh sách caller (`xulydathang`, `customerOrderCancel`, `confirm`, …), và rằng thay đổi chỉ thêm một số hạng được bọc try/catch.

- [ ] **Step 2: Write the failing test** `HoaDonVideoXuLoyaltyTest.php`, dùng `FakeDb`. Dữ liệu mẫu:
  - `khach_hang`: `{ma_kh: 7, diemtl: 0}`.
  - `hoa_don`: một đơn `{ma_kh: 7, da_tich_diem: true, diem_cong: 30, diem_su_dung: 0, da_hoan_diem: false}`.
  - `danh_gia`: 2 bản ghi của `ma_kh: 7`.
  - `reward_xu_ledger`: tổng 1250 xu cho `ma_kh: 7`.

```php
$hd->syncLoyaltyForCustomer(7);
$check('diemtl = 30 đơn + 2 đánh giá + 12 từ xu = 44', $db->khach_hang->docs[0]['diemtl'] === 44);
$check('loaikh vẫn tính từ tổng (Thuong < 500)', $db->khach_hang->docs[0]['loaikh'] === 'Thuong');
$db->reward_xu_ledger->throwOnRead = true;
$hd->syncLoyaltyForCustomer(7);
$check('sổ xu lỗi -> vẫn đồng bộ, xu tính 0 (=32), không văng lỗi', $db->khach_hang->docs[0]['diemtl'] === 32);
$db->reward_xu_ledger->throwOnRead = false; $db->reward_xu_ledger->docs = [];
$hd->syncLoyaltyForCustomer(7);
$check('không có xu -> giữ nguyên công thức cũ (=32)', $db->khach_hang->docs[0]['diemtl'] === 32);
```

`FakeDb` cần thêm `countDocuments` để đếm `danh_gia`.

- [ ] **Step 3: Run test to verify it fails**

Run: `C:\xampp\php\php.exe backend/tests/Unit/HoaDonVideoXuLoyaltyTest.php`
Expected: lỗi `Call to undefined method HoaDon::syncLoyaltyForCustomer()`.

- [ ] **Step 4: Implement.**
  - Thêm `private function videoBonusPoints(int $maKh): int`. Hàm `require_once` hai file `RewardLedger` và `RewardRules`, rồi trả về `RewardRules::pointsFromXu((new RewardLedger($this->db))->sumXu($maKh))`.
  - Bắt mọi `Throwable` và trả về 0. Khi lỗi, `error_log('loyalty video xu error ma_kh=' . $maKh . ' ' . get_class($e))`.
  - Trong `syncCustomerLoyaltyByMaKh`, đổi dòng tính tổng thành `max(0, $totalPoints + $reviewBonusPoints + $this->videoBonusPoints($maKh))`. Không sửa gì khác.
  - Thêm wrapper `public function syncLoyaltyForCustomer(int $maKh): void`.

- [ ] **Step 5: Run test to verify it passes**

Run: `C:\xampp\php\php.exe backend/tests/Unit/HoaDonVideoXuLoyaltyTest.php`
Expected: `4 passed, 0 failed`. Chạy thêm `C:\xampp\php\php.exe -l backend/app/models/HoaDon.php` và `C:\xampp\php\php.exe backend/tests/Unit/HoaDonStockEventTest.php`, kỳ vọng `0 failed` (test kho cũ vẫn xanh).

- [ ] **Step 6: Checkpoint**: message `feat(rewards): count video xu toward loyalty points`.

---

### Task 4: Đếm giờ xem cho mọi video (`VideoWatchSession` chế độ `time`)

**Files:**
- Modify: `backend/app/models/VideoWatchSession.php`, gồm `startOrResumeSession` (khoảng dòng 206–398) và `recordHeartbeat` (khoảng dòng 400–548).
- Test: `backend/tests/Unit/VideoWatchTimeModeTest.php`

**Interfaces:**
- Consumes: `VideoWatchDaily::credit` và `secondsToday` (Task 2), `RewardRules::dateVn` (Task 1).
- Produces:
  - Tài liệu phiên có thêm `mode` (`'campaign'|'time'`) và `date_vn` (chỉ phiên `time` mới có). `normalizeDocument` trả về `mode`; tài liệu cũ không có trường này thì là `'campaign'`.
  - Response heartbeat có thêm `today_seconds` (int), chỉ khi phiên có `customer_id`.
  - Response start có `session.mode`.

- [ ] **Step 1: Chạy GitNexus impact** cho `startOrResumeSession` và `recordHeartbeat`, rồi báo kết quả (lần trước là LOW).

- [ ] **Step 2: Write the failing test** `VideoWatchTimeModeTest.php`, dùng `FakeDb`.
  - `ai_product_videos` có `{_id: 'pubA', video_id: 'vA', is_published: true, duration: 60}`.
  - `ai_video_reward_campaigns` để rỗng.
  - Gọi `startOrResumeSession` với `video_id: 'vA'`.

```php
$g = $m->startOrResumeSession(['video_id' => 'vA', 'guest_session_id' => 'g1']);
$check('khách vãng lai + không chiến dịch -> NO_ACTIVE_CAMPAIGN như cũ', $g['ok'] === false && $g['code'] === 'NO_ACTIVE_CAMPAIGN');
$s = $m->startOrResumeSession(['video_id' => 'vA', 'customer_id' => 'u1']);
$check('đã đăng nhập + không chiến dịch -> phiên mode time', $s['ok'] === true && $s['session']['mode'] === 'time');
$check('resume cùng ngày trả lại đúng phiên', $m->startOrResumeSession(['video_id' => 'vA', 'customer_id' => 'u1'])['session']['watch_session_id'] === $s['session']['watch_session_id']);
// giả lập đã trôi 4s thực: đặt last_server_time = microtime(true) - 4, last_playback_time = 0
$h = $m->recordHeartbeat($sid, 4.0, 1.0, false);
$check('heartbeat time-mode OK, không đụng chiến dịch, cộng ngày', $h['ok'] === true && $h['today_seconds'] === 4 && $h['is_eligible'] === false);
// tua tới 50s sau 4s thực -> không cộng
$check('tua tới không được cộng', $m->recordHeartbeat($sid, 50.0, 1.0, false)['today_seconds'] === 4);
// 2 phiên (2 video) cùng u1 xen kẽ, mỗi phiên +4s sau 4s thực của riêng nó nhưng chỉ 2s thực giữa hai lần cộng
$check('2 tab không cộng nhanh hơn thời gian thật', $total <= $realElapsed + 2 * RewardRules::CREDIT_JITTER_SECONDS);
$check('phiên có chiến dịch giữ hành vi cũ khi chiến dịch hết hạn -> CAMPAIGN_EXPIRED', $expired['code'] === 'CAMPAIGN_EXPIRED');
```

Nếu `toObjectId('pubA')` trả về null thì truy vấn sẽ theo `video_id`, nên Mongo giả xử lý được. Để dựng thời gian thật trong test, ghi thẳng `last_server_time` vào `docs` của phiên trước mỗi heartbeat, không dùng `sleep`.

- [ ] **Step 3: Run test to verify it fails**

Run: `C:\xampp\php\php.exe backend/tests/Unit/VideoWatchTimeModeTest.php`
Expected: FAIL ở assertion "phiên mode time" (`NO_ACTIVE_CAMPAIGN`).

- [ ] **Step 4: Implement.**
  - **Trong `startOrResumeSession`:** khi `!$campaign && $customerId !== null`, vào nhánh time.
    - Tìm phiên cũ với `{video_id, mode: 'time', customer_id, date_vn: RewardRules::dateVn(time()), watch_state: 'IN_PROGRESS'}`.
    - Nếu không có thì tạo phiên mới có cùng các trường như phiên campaign, nhưng `campaign_id = null`, `mode = 'time'` và có `date_vn`.
    - Mọi nhánh khác (khách vãng lai, có chiến dịch) giữ nguyên.
  - **Trong `recordHeartbeat`:**
    - Khi `mode === 'time'`, bỏ qua bước 1 (kiểm tra chiến dịch) và bước 4 (kiểm tra đủ điều kiện).
    - Với mọi phiên có `customer_id`, sau bước 5 lấy `delta = watchedSeconds - (float)$sessionDoc['watched_seconds']` rồi gọi `VideoWatchDaily::credit(customer_id, dateVn(time()), delta, $nowFloat)`.
    - Phần cộng ngày bọc trong try/catch: lỗi thì chỉ `error_log`, heartbeat vẫn trả OK.
    - Thêm `today_seconds` vào response.

- [ ] **Step 5: Run test to verify it passes**

Run: `C:\xampp\php\php.exe backend/tests/Unit/VideoWatchTimeModeTest.php`
Expected: `7 passed, 0 failed`. Chạy thêm `C:\xampp\php\php.exe -l backend/app/models/VideoWatchSession.php`.

- [ ] **Step 6: Checkpoint**: message `feat(rewards): server-verified daily watch time for every video`.

---

### Task 5: `RewardService`, xu theo video và trường `reward_xu`

**Files:**
- Create: `backend/app/services/RewardService.php`
- Modify:
  - `backend/app/models/VideoRewardCampaign.php`: `normalizeDocument` (dòng 98) và `saveCampaign` (các khối insert/update khoảng dòng 437 và 465).
  - `backend/app/controllers/VideoWatchController.php`: `getCustomerIdentity` (dòng 32) đổi thành static public, và thêm bước cộng xu ở `start`, `heartbeat`, `complete`, `claimReward`.
  - `frontend/views/admin/product_videos.php`: thêm ô sau `apvRewardQuota` (khoảng dòng 220).
  - `frontend/public/assets/js/admin-product-videos.js`: ba vị trí là điền form khi sửa (khoảng dòng 667), reset khi tạo mới (khoảng 711) và payload lưu (khoảng 759/779).
- Test: `backend/tests/Unit/RewardServiceTest.php`

**Interfaces:**
- Consumes: Task 1–3 (`RewardLedger`, `VideoWatchDaily`, `RewardRules`, `HoaDon::syncLoyaltyForCustomer`).
- Produces:
  - `RewardService::__construct($db, ?callable $resolveMaKh = null, ?callable $syncLoyalty = null, ?callable $clock = null)`
    - `$resolveMaKh(string $email): ?int` mặc định dùng `TaiKhoan::getKhachHangByEmail`.
    - `$syncLoyalty(int $maKh): void` mặc định là `(new HoaDon($db))->syncLoyaltyForCustomer`.
    - `$clock(): int` mặc định là `time()`.
  - `customerFromIdentity(array $identity): ?array`. `$identity` có dạng như `VideoWatchController::customerIdentity()`, tức `customer_id` và `customer_email`. Trả về `['customer_id' => string, 'ma_kh' => int]`, hoặc null khi chưa đăng nhập hay không có hồ sơ.
  - Các hàm hành động, đều trả về `['ok' => bool, 'code' => string, 'message' => string, 'xu_awarded' => int, 'xu_balance' => int]`:
    - `awardVideoXu(array $identity, array $campaign, string $videoId): array`. Dùng `$campaign['campaign_id']` và `$campaign['reward_xu']`, với `source_key = "video:{customer_id}:{campaign_id}"`.
    - `checkin(array $identity): array`, với `source_key = "checkin:{customer_id}:{date_vn}"` và `meta.streak_day`.
    - `claimMilestone(array $identity, int $minutes): array`, với `source_key = "milestone:{customer_id}:{date_vn}:{minutes}"`.
  - Các mã `code` có thể trả về:
    - `OK`
    - `LOGIN_REQUIRED`
    - `NO_CUSTOMER_PROFILE`
    - `NOT_ENOUGH_WATCH`
    - `ALREADY_CHECKED_IN` (khi ledger trả DUPLICATE lúc điểm danh)
    - `ALREADY_CLAIMED` (khi ledger trả DUPLICATE ở video hoặc mốc xem)
    - `DAILY_CAP_REACHED`
    - `INVALID_MILESTONE`
    - `NO_XU` (khi `reward_xu` bằng 0)
  - `summary(array $identity): array`, gồm các khóa:
    - `xu_balance`, `points_from_xu`, `xu_pending` (= balance % 100), `xu_today`, `daily_cap` (300), `watched_seconds_today`
    - `checkin`: `{checked_in_today: bool, next_day: int, next_xu: int, seconds_needed: int, can_checkin: bool, days: [{day, xu, state: 'done'|'today'|'upcoming'}] x7}`
    - `milestones`: `[{minutes, xu, state: 'claimed'|'ready'|'locked'}]` x3
  - `history(array $identity): array`: tối đa 30 bản ghi, mỗi bản ghi gồm `{amount_xu, source, label, date_vn}`. `label` theo nguồn: "Xem video", "Điểm danh ngày N", "Xem đủ N phút".
  - `VideoWatchController::customerIdentity(): array` (public static). Method cũ `getCustomerIdentity()` gọi lại hàm này.
  - `VideoRewardCampaign`: `normalizeDocument` thêm `reward_xu` (int, mặc định 0). `saveCampaign` đọc `$input['reward_xu']` qua `RewardRules::normalizeVideoXu` rồi lưu ở cả nhánh insert lẫn update.

- [ ] **Step 1: Chạy GitNexus impact** cho `saveCampaign`, `normalizeDocument` (VideoRewardCampaign) và `getCustomerIdentity`. `UNKNOWN` thì xác nhận bằng `rg` và báo lại.

- [ ] **Step 2: Write the failing test** `RewardServiceTest.php`, dùng `FakeDb`. Đồng hồ giả `$now` có thể đổi giá trị. `resolveMaKh` trả 7 cho `a@x.vn` và null cho `staff@x.vn`. `syncLoyalty` chỉ ghi lại số lần được gọi.

```php
$u = ['customer_id' => 'u1', 'customer_email' => 'a@x.vn'];
$check('chưa đăng nhập -> LOGIN_REQUIRED', $svc->checkin(['customer_id' => null, 'customer_email' => null])['code'] === 'LOGIN_REQUIRED');
$check('không có hồ sơ khách -> NO_CUSTOMER_PROFILE', $svc->checkin(['customer_id' => 's1', 'customer_email' => 'staff@x.vn'])['code'] === 'NO_CUSTOMER_PROFILE');
$check('xem 59s -> NOT_ENOUGH_WATCH', /* video_watch_daily seconds=59 */ $svc->checkin($u)['code'] === 'NOT_ENOUGH_WATCH');
// seconds=60
$r = $svc->checkin($u);
$check('điểm danh ngày 1 = 10 xu, gọi sync 1 lần', $r['code'] === 'OK' && $r['xu_awarded'] === 10 && $syncCalls === 1);
$check('bấm lần 2 -> ALREADY_CHECKED_IN', $svc->checkin($u)['code'] === 'ALREADY_CHECKED_IN' && $syncCalls === 1);
// đồng hồ sang 00:00 VN hôm sau (17:00 UTC), seconds hôm sau = 60
$check('qua nửa đêm VN -> ngày 2', $svc->checkin($u)['xu_awarded'] === 10 && $svc->summary($u)['checkin']['days'][1]['state'] === 'done');
$check('mốc 5 phút khi mới 4 phút -> NOT_ENOUGH_WATCH; mốc 7 -> INVALID_MILESTONE', $svc->claimMilestone($u, 5)['code'] === 'NOT_ENOUGH_WATCH' && $svc->claimMilestone($u, 7)['code'] === 'INVALID_MILESTONE');
$check('xu video: reward_xu 0 -> NO_XU; 20 -> OK; lần 2 -> ALREADY_CLAIMED',
  $svc->awardVideoXu($u, ['campaign_id' => 'c0', 'reward_xu' => 0], 'v')['code'] === 'NO_XU'
  && $svc->awardVideoXu($u, ['campaign_id' => 'c1', 'reward_xu' => 20], 'v')['code'] === 'OK'
  && $svc->awardVideoXu($u, ['campaign_id' => 'c1', 'reward_xu' => 20], 'v')['code'] === 'ALREADY_CLAIMED');
$s = $svc->summary($u);
$check('summary: balance, quy đổi, mốc', $s['xu_balance'] === 40 && $s['points_from_xu'] === 0 && $s['xu_pending'] === 40 && count($s['milestones']) === 3 && $s['daily_cap'] === 300);
$check('history có nhãn tiếng Việt', $svc->history($u)[0]['label'] !== '');
```

Thêm một kiểm tra tĩnh để chắc `saveCampaign` đã lưu `reward_xu` ở cả hai nhánh: `substr_count(file_get_contents(.../VideoRewardCampaign.php), "'reward_xu' =>") >= 3` (gồm normalize, insert và update).

- [ ] **Step 3: Run test to verify it fails**

Run: `C:\xampp\php\php.exe backend/tests/Unit/RewardServiceTest.php`
Expected: `Class "RewardService" not found`.

- [ ] **Step 4: Implement `RewardService`, phần sửa `VideoRewardCampaign` và `customerIdentity()`.** Các hàm hành động chỉ gọi `$syncLoyalty($maKh)` khi kết quả là `OK`. Lỗi trong `syncLoyalty` thì `error_log` và vẫn trả OK, vì xu đã được ghi vào sổ.

- [ ] **Step 5: Gắn xu theo video vào `VideoWatchController`.**
  - Thêm `private function awardVideoXuIfEligible(array $result, string $sessionId): array`. Hàm chỉ chạy khi phiên đủ điều kiện (cùng điều kiện với `autoClaimEligibleReward`) và đã đăng nhập.
  - Luồng: `getSessionById` → `VideoRewardCampaign::getCampaignById(campaign_id)` → nếu `reward_xu > 0` thì gọi `RewardService::awardVideoXu`. Kết quả gắn vào `$result['xu_reward'] = ['code', 'xu_awarded', 'xu_balance']`.
  - Gọi hàm này ngay sau `autoClaimEligibleReward` trong `start`, `heartbeat` và `complete`, và sau khi `claimReward` thành công.
  - Bọc toàn bộ trong try/catch, không làm hỏng response voucher.

- [ ] **Step 6: Thêm ô vào form admin.**
  - Trong `product_videos.php`, sau khối hạn ngạch thêm `<label for="apvRewardXu">Thưởng xu (0–100)</label><input type="number" id="apvRewardXu" class="apv-input" min="0" max="100" value="20">` và `<small class="apv-help">100 xu = 1 điểm. Để 0 nếu chỉ tặng voucher.</small>`.
  - Trong JS:
    - Khi sửa: `$('apvRewardXu').value = c.reward_xu ?? 0`.
    - Khi tạo mới: `= 20`.
    - Payload lưu: `reward_xu: parseInt($('apvRewardXu').value, 10) || 0`.

- [ ] **Step 7: Run tests to verify they pass**

Run: `C:\xampp\php\php.exe backend/tests/Unit/RewardServiceTest.php`
Expected: `11 passed, 0 failed`. Chạy thêm `php -l` cho 3 file PHP đã sửa, và chạy lại test của Task 1–4, kỳ vọng `0 failed`.

- [ ] **Step 8: Checkpoint**: message `feat(rewards): reward service, per-video xu, admin reward_xu field`.

---

### Task 6: `RewardController`, CSRF và route

**Files:**
- Create: `backend/app/services/RewardCsrf.php`
- Create: `backend/app/controllers/RewardController.php`
- Modify: `backend/public/index.php`, thêm các case ngay sau `case 'customer_vouchers'` (khoảng dòng 279).
- Modify: `backend/app/controllers/SanPhamController.php` (`chitiet`, khoảng dòng 844), truyền thêm `'rewardCsrf' => RewardCsrf::token()` và `'isLoggedIn' => is_logged_in()`.
- Test: `backend/tests/Unit/RewardControllerTest.php`

**Interfaces:**
- Consumes: `RewardService` (Task 5), `VideoWatchController::customerIdentity()`.
- Produces:
  - `RewardCsrf::token(): string`: token nằm trong `$_SESSION['reward_csrf']`, dài 64 ký tự hex.
  - `RewardCsrf::valid(?string $given): bool`: so sánh bằng `hash_equals`.
  - `RewardController::__construct($pdo, ?RewardService $service = null, ?callable $identity = null)`
  - Các hàm trả kết quả, đều có dạng `['status' => int, 'body' => array]`:
    - `summaryResult(): array`
    - `historyResult(): array`
    - `checkinResult(string $method, ?string $csrf): array`
    - `milestoneResult(string $method, ?string $csrf, array $input): array`
  - Các hàm route (`summary()`, `history()`, `checkin()`, `milestoneClaim()`) chỉ gọi hàm `*Result` tương ứng, rồi `http_response_code` + `echo json` + `exit`.
  - `page(): void` render `phan_thuong` qua header và footer (giống `SanPhamController::render`, lấy `menuCats` từ `SanPham::menuTree()`). Dữ liệu truyền vào view: `['rewardCsrf', 'isLoggedIn', 'featuredProductId']`. `featuredProductId` là phần tử đầu tiên trong `associated_product_ids` của video mới xuất bản gần nhất, hoặc null.
  - Route mới:
    - `phan_thuong` → `page`
    - `reward_summary` → `summary`
    - `reward_history` → `history`
    - `reward_checkin` → `checkin`
    - `reward_milestone_claim` → `milestoneClaim`
  - Ánh xạ mã HTTP:
    - `LOGIN_REQUIRED` → 401
    - CSRF sai → 403, code `CSRF_INVALID`
    - Phương thức khác POST → 405, code `METHOD_NOT_ALLOWED`
    - `ALREADY_*` và `DAILY_CAP_REACHED` → 409
    - `NOT_ENOUGH_WATCH`, `INVALID_MILESTONE` và `NO_CUSTOMER_PROFILE` → 400
    - `OK` → 200

- [ ] **Step 1: Write the failing test** `RewardControllerTest.php`. Dùng một `RewardService` giả (lớp con ghi đè các hàm hành động để trả mã định sẵn), identity giả và `$_SESSION` là mảng.

```php
$check('summary khi chưa đăng nhập -> 401 LOGIN_REQUIRED', $guestCtrl->summaryResult()['status'] === 401);
$check('checkin GET -> 405', $ctrl->checkinResult('GET', RewardCsrf::token())['status'] === 405);
$check('checkin thiếu/sai CSRF -> 403 CSRF_INVALID', $ctrl->checkinResult('POST', 'sai')['body']['code'] === 'CSRF_INVALID');
$check('checkin OK -> 200 và body ok', $ctrl->checkinResult('POST', RewardCsrf::token())['status'] === 200);
$check('ALREADY_CHECKED_IN -> 409', $dupCtrl->checkinResult('POST', RewardCsrf::token())['status'] === 409);
$check('milestone minutes không phải số -> 400 INVALID_MILESTONE', $ctrl->milestoneResult('POST', RewardCsrf::token(), ['minutes' => 'x'])['body']['code'] === 'INVALID_MILESTONE');
$check('token ổn định trong phiên, 64 hex', RewardCsrf::token() === RewardCsrf::token() && preg_match('/^[a-f0-9]{64}$/', RewardCsrf::token()) === 1);
$check('checkin OK ghi $_SESSION[reward_checked_in_date] = ngày VN hôm nay', ($_SESSION['reward_checked_in_date'] ?? '') === RewardRules::dateVn(time()));
$idx = file_get_contents($root . '/backend/public/index.php');
$check('5 route đã nối', count(array_filter(['phan_thuong','reward_summary','reward_history','reward_checkin','reward_milestone_claim'], fn($r) => strpos($idx, "case '$r':") !== false)) === 5);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\xampp\php\php.exe backend/tests/Unit/RewardControllerTest.php`
Expected: `Class "RewardCsrf" not found`.

- [ ] **Step 3: Implement `RewardCsrf`, `RewardController`, các route và phần truyền dữ liệu trong `SanPhamController::chitiet`.** Hàm `checkin()` đọc token từ `$_SERVER['HTTP_X_CSRF_TOKEN']`. Khi `checkinResult` trả OK hoặc `ALREADY_CHECKED_IN`, và khi `summaryResult` thấy `checkin.checked_in_today = true`, ghi `$_SESSION['reward_checked_in_date'] = RewardRules::dateVn(time())` (header ở Task 8 đọc khóa này). `milestoneClaim()` đọc body JSON giống `VideoWatchController::readJsonInput`.

- [ ] **Step 4: Run test to verify it passes**

Run: `C:\xampp\php\php.exe backend/tests/Unit/RewardControllerTest.php`
Expected: `9 passed, 0 failed`. Chạy `php -l` cho các file đã sửa. Kiểm tra bằng curl rằng `curl -s -o NUL -w "%{http_code}" "http://localhost:8080/index.php?r=reward_summary"` trả về `401` khi chưa đăng nhập.

- [ ] **Step 5: Checkpoint**: message `feat(rewards): reward JSON endpoints with CSRF`.

---

### Task 7: Trình xem video — hàng xu, hàng điểm danh, toast, phiên `time`

**Files:**
- Modify: `frontend/views/chitiet.php`, gồm khối viewer (`#synaRewardCard`, `data-reward-row="xu"`), `startWatchSession`, `renderReward` và `updateRewardUI`.

**Interfaces:**
- Consumes:
  - Response heartbeat có `today_seconds` (Task 4).
  - `xu_reward` trong các response watch (Task 5).
  - `reward_summary` và `reward_checkin` (Task 6).
  - Biến view `$rewardCsrf` và `$isLoggedIn` (Task 6).
  - `reward_campaign.reward_xu` trong `synaVideoListData.reward` (Task 5). Lưu ý dữ liệu này đi qua cache 30 phút của `SanPhamController`.
- Produces: không có interface backend mới.

- [ ] **Step 1: Mở phiên `time`.**
  - Hiện `startWatchSession` dừng sớm khi không có `currentCampId`. Đổi điều kiện: nếu `isUserLoggedIn` thì vẫn POST `video_watch_start` với `publication_id` và `video_id`, không gửi `campaign_id`.
  - Với phiên `mode === 'time'`: không hiện UI voucher, không gọi `verifyCompletion` cho voucher, nhưng vẫn gửi heartbeat 4 giây như cũ.

- [ ] **Step 2: Hàng "+N xu".**
  - Khi video hiện tại có `reward.reward_xu > 0`, bỏ `hidden` khỏi `data-reward-row="xu"`.
  - Hàng này dùng chung tiến độ và vòng của voucher (cùng điều kiện đủ điều kiện), nhãn "+N xu".
  - Khi response có `xu_reward.code === 'OK'` thì đổi sang trạng thái `done` ("Đã nhận +N xu"). `ALREADY_CLAIMED` cũng hiện "Đã nhận". `DAILY_CAP_REACHED` hiện "Đủ xu hôm nay".

- [ ] **Step 3: Hàng "Điểm danh hôm nay"** (chỉ khi đã đăng nhập).
  - Khi viewer mở, gọi `GET reward_summary` một lần.
  - Nếu chưa điểm danh: hiện vòng `createRing` đếm ngược `60 - today_seconds`. Giá trị lấy từ server (`today_seconds` của mỗi heartbeat) và được nội suy giống vòng voucher.
  - Khi đủ 60 giây: tâm vòng đổi thành nút "Điểm danh +{next_xu} xu". Nút gọi `POST reward_checkin` với header `X-CSRF-Token: <?= h($rewardCsrf) ?>` (lấy từ thuộc tính `data-reward-csrf` trên `#synaVideoViewer`).
  - Nếu đã điểm danh: hiện "Đã điểm danh · Ngày N".
  - Trên điện thoại, hàng này nằm trong bottom sheet (khối Phần thưởng), còn chip góc trên vẫn chỉ hiện voucher.

- [ ] **Step 4: Toast "+N xu".**
  - Có bất kỳ `xu_awarded > 0` nào (video hoặc điểm danh) thì hiện toast `#synaVideoToast` nội dung "+N xu" kèm link "Xem phần thưởng" tới `?r=phan_thuong`, tự ẩn sau 3 giây.
  - Khi `prefers-reduced-motion`, toast không có hiệu ứng bay lên.

- [ ] **Step 5: Kiểm tra tĩnh.**
  - `C:\xampp\php\php.exe -l frontend/views/chitiet.php`
  - `git diff --check -- frontend/views/chitiet.php`
  - Kiểm tra mọi `getElementById` mới đều có phần tử tương ứng.
  - `curl` trang `chitiet&id=68` và `chitiet&id=1005` đều trả 200, không có `Fatal error|Warning:|Notice:`.

- [ ] **Step 6: Checkpoint**: message `feat(rewards): xu and daily check-in rows in the video viewer`.

---

### Task 8: Trang Phần thưởng và lối vào ở header

**Files:**
- Create:
  - `frontend/views/phan_thuong.php`
  - `frontend/public/assets/css/phan-thuong.css`
  - `frontend/public/assets/js/phan-thuong.js`
- Modify: `frontend/views/layouts/header.php`, gồm thanh tiện ích khi đã đăng nhập (khoảng dòng 155–158) và thẻ người dùng trong offcanvas di động (khoảng dòng 366–371).

**Interfaces:**
- Consumes: biến view `$rewardCsrf`, `$isLoggedIn` và `$featuredProductId` (Task 6); `reward_summary`, `reward_history`, `reward_checkin` và `reward_milestone_claim` (Task 6).
- Produces: thêm khóa session `$_SESSION['reward_checked_in_date']`.
  - `RewardController::summaryResult` và `checkinResult` ghi `date_vn` vào khóa này khi đã điểm danh hôm nay.
  - Header đọc khóa này để quyết định có hiện chấm "Điểm danh" hay không, không cần truy vấn DB. Phần ghi session này được thêm vào Task 6, kèm 1 assertion trong `RewardControllerTest`.

- [ ] **Step 1: View và CSS** (bố cục theo mục 5.3 của spec):
  - **Hero:** nền xanh rêu `#183B2B`, tiêu đề "Phần thưởng", dòng phụ "Xem video Syna, nhận xu đổi ưu đãi".
  - **Thẻ số dư:** "Xu hiện có", số lớn, dòng "≈ N điểm (N.000đ)" và "+X xu đang tích", nút "Dùng khi thanh toán" dẫn tới `?r=giohang`.
  - **Điểm danh hằng ngày:**
    - 7 ô, mỗi ô ghi số xu và "Ngày N"; ô đã nhận có ✓, ô hôm nay có viền vàng, ô ngày 7 là hộp quà.
    - Nút "Điểm danh" có hai trạng thái: khi bị khóa, hiện vòng nhỏ kèm "Xem thêm m:ss"; khi bấm được, nền vàng.
    - Dòng chú thích: "Xem ít nhất 1 phút video để điểm danh".
  - **Xem video mỗi ngày:**
    - Thanh tiến độ 0–30 phút với 3 hộp quà ở các mốc 5/15/30 phút.
    - Mỗi hộp quà ở trạng thái `locked`, `ready` (nút "Nhận +N") hoặc `claimed`.
    - Nút "Xem ngay" dẫn tới `?r=chitiet&id={featuredProductId}`; nếu không có thì dẫn tới `?r=live`.
  - **Lịch sử xu:** danh sách "+20 xu · Xem video · 12/11". Khi chưa có gì thì hiện "Bắt đầu xem video để nhận xu đầu tiên".
  - **Khách vãng lai:** vẫn thấy hero và thể lệ, kèm nút "Đăng nhập để nhận xu" mở `#authModal` giống header.
  - **Hiển thị:** khi xu chạm trần, nút hiện "Đủ 300 xu hôm nay".
  - **Responsive:** ở 375px không bị cuộn ngang. Mọi màu là token CSS trên `.phan-thuong`.

- [ ] **Step 2: JS.**
  - Tải `reward_summary` và `reward_history`, rồi render.
  - Các nút POST kèm `X-CSRF-Token` (đọc từ `data-reward-csrf`). Sau mỗi lần nhận thì render lại từ response hoặc gọi lại `reward_summary`.
  - Lỗi thì hiện thông báo tiếng Việt theo `message` server trả về.
  - Đếm ngược ở nút điểm danh chỉ để hiển thị; điều kiện thật do server quyết định.

- [ ] **Step 3: Header.**
  - Khi đã đăng nhập, thêm link "Phần thưởng" (icon `fa-gift`) trước "Đăng xuất" ở thanh tiện ích, và một dòng tương tự trong thẻ người dùng của offcanvas di động.
  - Hiện chấm vàng "Điểm danh" khi `($_SESSION['reward_checked_in_date'] ?? '') !== RewardRules::dateVn(time())`. Header phải `require_once` `RewardRules`, và chỉ làm vậy khi `is_logged_in()`.

- [ ] **Step 4: Kiểm tra tĩnh.**
  - `php -l` cho `phan_thuong.php` và `header.php`.
  - `curl -s -o NUL -w "%{http_code}" "http://localhost:8080/index.php?r=phan_thuong"` trả về 200 khi chưa đăng nhập.
  - Không có `Fatal error|Warning:|Notice:`.

- [ ] **Step 5: Checkpoint**: message `feat(rewards): rewards page and header entry`.

---

### Task 9: Kiểm thử thật trên trình duyệt và tài liệu

**Files:**
- Create: `update_video_phan_thuong.md` (theo quy ước `update_<tên_công_việc>.md`).
- Modify: `update.md` (thêm một dòng tóm tắt và link).

- [ ] **Step 1: Đọc skill `.claude/skills/e2e/SKILL.md`** rồi chạy E2E thật trên localhost bằng một tài khoản khách thử nghiệm.
  - Tài khoản lấy từ file seed hoặc fixture của dự án, hoặc tạo mới trên localhost. Không dùng tài khoản thật của người dùng.
  - Không in mật khẩu ra chat.
- [ ] **Step 2: Kịch bản**, chạy ở hai cỡ màn hình 1440×900 và 375×812:
  1. Chưa đăng nhập: `?r=phan_thuong` hiện nút "Đăng nhập để nhận xu"; API trả 401.
  2. Đăng nhập, mở video sản phẩm 68 (không có voucher), xem đủ 60 giây. Vòng điểm danh trong viewer đếm về 0, bấm "Điểm danh +10 xu", toast hiện ra.
  3. Trang Phần thưởng: ô ngày 1 có ✓, số dư là 10 xu, lịch sử có bản ghi "Điểm danh ngày 1".
  4. Bấm "Điểm danh" lần nữa thì nhận thông báo đã điểm danh, số dư không đổi.
  5. Chiến dịch có `reward_xu`: nếu admin gắn được trên một chiến dịch thử, xem đủ video để thấy "+N xu" và voucher. Làm theo đúng memory: chỉ dùng chiến dịch thử, và tắt hoặc dọn ngay sau khi test.
  6. Trang thanh toán hiển thị điểm khả dụng đúng theo `diemtl` mới (với 1250 xu thì phải hiện 12 điểm).
- [ ] **Step 3: Dọn dữ liệu thử** (bản ghi ledger, daily và phiên của tài khoản test). Phải hỏi người dùng trước khi xóa bất cứ thứ gì trên Atlas.
- [ ] **Step 4: Tài liệu `update_video_phan_thuong.md`**, gồm các mục:
  - mục tiêu
  - luật xu
  - collection mới và index
  - route mới
  - file đã đổi
  - lệnh test kèm kết quả
  - kết quả E2E
  - rủi ro còn lại (race trần ngày, cache 30 phút của dữ liệu video)
- [ ] **Step 5: Chạy GitNexus `detect-changes --scope all`**, chạy lại toàn bộ test mới (6 file) và `git diff --check`. Báo cáo theo mục "Before completion" của AGENTS.md.
