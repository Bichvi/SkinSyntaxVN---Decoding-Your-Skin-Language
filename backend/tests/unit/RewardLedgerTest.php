<?php
// Test thuần PHP (Mongo giả, không DB thật) cho RewardLedger và VideoWatchDaily.
// Chạy: php backend/tests/unit/RewardLedgerTest.php
require_once __DIR__ . '/support/FakeMongo.php';
require_once __DIR__ . '/../../app/services/RewardRules.php';
require_once __DIR__ . '/../../app/models/RewardLedger.php';
require_once __DIR__ . '/../../app/models/VideoWatchDaily.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$db = new FakeDb();
$ledger = new RewardLedger($db);
$daily = new VideoWatchDaily($db);
$e = fn($key, $xu, $src = 'video') => ['customer_id' => 'u1', 'ma_kh' => 7, 'source' => $src, 'source_key' => $key, 'amount_xu' => $xu, 'date_vn' => '2026-11-15', 'meta' => []];

$check('constructor mặc định vẫn tạo index unique source_key', in_array(['keys' => ['source_key' => 1], 'unique' => true], $db->reward_xu_ledger->indexes, true));
$r = $ledger->award($e('video:u1:c1', 20));
$check('award OK cộng đủ 20', $r === ['ok' => true, 'code' => 'OK', 'xu_awarded' => 20]);
$check('award trùng source_key -> DUPLICATE, không thêm bản ghi', $ledger->award($e('video:u1:c1', 20))['code'] === 'DUPLICATE' && count($db->reward_xu_ledger->docs) === 1);
$db->reward_xu_ledger->forceDuplicateOnInsert = true;
$check('insert ném 11000 -> DUPLICATE (không văng lỗi)', $ledger->award($e('checkin:u1:2026-11-15', 10, 'checkin'))['code'] === 'DUPLICATE');
$db->reward_xu_ledger->forceDuplicateOnInsert = false;
$db->reward_xu_ledger->forceDuplicateOnInsert = true;
$db->reward_xu_ledger->duplicateExceptionCode = 0;
$check('E11000 chỉ trong message (code 0) -> DUPLICATE', $ledger->award($e('checkin:u1:2026-11-16', 10, 'checkin'))['code'] === 'DUPLICATE');
$db->reward_xu_ledger->forceDuplicateOnInsert = false;
$db->reward_xu_ledger->duplicateExceptionCode = 11000;
$reviewReward = $ledger->award(['customer_id' => 'u2', 'ma_kh' => 8, 'source' => 'review', 'source_key' => 'review:501', 'amount_xu' => 100, 'date_vn' => '2026-11-15', 'meta' => ['review_id' => 501]]);
$check('đánh giá là nguồn xu hợp lệ và cộng đúng 100 xu', $reviewReward['code'] === 'OK' && $ledger->sumXu(8) === 100);
$ledger->award($e('milestone:u1:2026-11-15:30', 270, 'watch_milestone'));
$check('đã 290 -> award 20 chỉ cộng 10', $ledger->award($e('video:u1:c2', 20))['xu_awarded'] === 10 && $ledger->sumXu(7, '2026-11-15') === 300);
$check('chạm 300 -> DAILY_CAP_REACHED, không ghi bản ghi 0 xu', $ledger->award($e('video:u1:c3', 20))['code'] === 'DAILY_CAP_REACHED' && !$ledger->hasKey('video:u1:c3'));
$check('amount_xu <= 0 hoặc thiếu source_key -> INVALID', $ledger->award($e('', 5))['code'] === 'INVALID' && $ledger->award($e('video:u1:c4', 0))['code'] === 'INVALID');
$check('sumXu không lọc ngày = tổng mọi ngày', $ledger->sumXu(7) === 300);
$check('history mới nhất trước, giới hạn limit', count($ledger->history(7, 2)) === 2 && $ledger->history(7, 2)[0]['source_key'] === 'video:u1:c2');
$check('credit lần đầu tối đa 10s', $daily->credit('u1', '2026-11-15', 30.0, 1000.0) === 10.0);
$check('2 tab: credit 4s sau 2s thực chỉ được 3s', $daily->credit('u1', '2026-11-15', 4.0, 1002.0) === 3.0 && $daily->secondsToday('u1', '2026-11-15') === 13);
$check('ngày khác tách riêng', $daily->secondsToday('u1', '2026-11-16') === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
