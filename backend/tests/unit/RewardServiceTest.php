<?php
// Test thuần PHP (Mongo giả, không DB thật) cho RewardService.
// Chạy: php backend/tests/unit/RewardServiceTest.php
require_once __DIR__ . '/support/FakeMongo.php';
require_once __DIR__ . '/../../app/services/RewardRules.php';
require_once __DIR__ . '/../../app/models/RewardLedger.php';
require_once __DIR__ . '/../../app/models/RewardCheckin.php';
require_once __DIR__ . '/../../app/models/VideoWatchDaily.php';
require_once __DIR__ . '/../../app/services/RewardService.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$db = new FakeDb();
$now = strtotime('2026-11-15 03:00:00 UTC'); // 10:00 ngày 15 giờ VN
$syncCalls = 0;
$svc = new RewardService(
    $db,
    fn(string $email) => $email === 'a@x.vn' ? 7 : null,
    function (int $maKh) use (&$syncCalls) { $syncCalls++; },
    function () use (&$now) { return $now; }
);
$setSeconds = function (string $dateVn, int $sec) use ($db) {
    foreach ($db->video_watch_daily->docs as $i => $d) {
        if ($d['customer_id'] === 'u1' && $d['date_vn'] === $dateVn) {
            $db->video_watch_daily->docs[$i]['seconds'] = $sec;
            return;
        }
    }
    $db->video_watch_daily->docs[] = ['customer_id' => 'u1', 'date_vn' => $dateVn, 'seconds' => $sec];
};

$u = ['customer_id' => 'u1', 'customer_email' => 'a@x.vn'];
$check('chưa đăng nhập -> LOGIN_REQUIRED', $svc->checkin(['customer_id' => null, 'customer_email' => null])['code'] === 'LOGIN_REQUIRED');
$check('không có hồ sơ khách -> NO_CUSTOMER_PROFILE', $svc->checkin(['customer_id' => 's1', 'customer_email' => 'staff@x.vn'])['code'] === 'NO_CUSTOMER_PROFILE');

$setSeconds('2026-11-15', 59);
$check('xem 59s -> NOT_ENOUGH_WATCH', $svc->checkin($u)['code'] === 'NOT_ENOUGH_WATCH');

$setSeconds('2026-11-15', 60);
$r = $svc->checkin($u);
$check('điểm danh ngày 1 = 10 xu, gọi sync 1 lần', $r['code'] === 'OK' && $r['xu_awarded'] === 10 && $syncCalls === 1);
$check('bấm lần 2 -> ALREADY_CHECKED_IN', $svc->checkin($u)['code'] === 'ALREADY_CHECKED_IN' && $syncCalls === 1);

$now = strtotime('2026-11-15 17:00:00 UTC'); // 00:00 ngày 16 giờ VN
$setSeconds('2026-11-16', 60);
$check('qua nửa đêm VN -> ngày 2', $svc->checkin($u)['xu_awarded'] === 10 && $svc->summary($u)['checkin']['days'][1]['state'] === 'done');

$setSeconds('2026-11-16', 240);
$check('mốc 5 phút khi mới 4 phút -> NOT_ENOUGH_WATCH; mốc 7 -> INVALID_MILESTONE',
    $svc->claimMilestone($u, 5)['code'] === 'NOT_ENOUGH_WATCH' && $svc->claimMilestone($u, 7)['code'] === 'INVALID_MILESTONE');

$check('xu video: reward_xu 0 -> NO_XU; 20 -> OK; lần 2 -> ALREADY_CLAIMED',
    $svc->awardVideoXu($u, ['campaign_id' => 'c0', 'reward_xu' => 0], 'v')['code'] === 'NO_XU'
    && $svc->awardVideoXu($u, ['campaign_id' => 'c1', 'reward_xu' => 20], 'v')['code'] === 'OK'
    && $svc->awardVideoXu($u, ['campaign_id' => 'c1', 'reward_xu' => 20], 'v')['code'] === 'ALREADY_CLAIMED');

$s = $svc->summary($u);
$check('summary: balance, quy đổi, mốc', $s['xu_balance'] === 40 && $s['points_from_xu'] === 0 && $s['xu_pending'] === 40 && count($s['milestones']) === 3 && $s['daily_cap'] === 300);
$check('history có nhãn tiếng Việt', $svc->history($u)[0]['label'] !== '');

// ---- ràng buộc bổ sung ----
$db2 = new FakeDb();
$now2 = strtotime('2026-11-15 03:00:00 UTC');
$resolveCalls = 0;
$svc2 = new RewardService(
    $db2,
    function (string $email) use (&$resolveCalls) { $resolveCalls++; return ['b@x.vn' => 8, 'c@x.vn' => 9, 'd@x.vn' => 10][$email] ?? null; },
    function (int $maKh) { if ($maKh === 9) { throw new RuntimeException('sync boom'); } },
    function () use (&$now2) { return $now2; }
);
$sec2 = function (string $cid) use ($db2) { $db2->video_watch_daily->docs[] = ['customer_id' => $cid, 'date_vn' => '2026-11-15', 'seconds' => 60]; };
$ledger2 = new RewardLedger($db2);
$e2 = fn($key, $xu) => ['customer_id' => 'u2', 'ma_kh' => 8, 'source' => 'video', 'source_key' => $key, 'amount_xu' => $xu, 'date_vn' => '2026-11-15', 'meta' => []];
$ledger2->award($e2('video:u2:a', 100)); $ledger2->award($e2('video:u2:b', 100)); $ledger2->award($e2('video:u2:c', 95));
$sec2('u2');
$r = $svc2->checkin(['customer_id' => 'u2', 'customer_email' => 'b@x.vn']);
$check('đã 295 xu, điểm danh 10 -> OK chỉ cộng 5', $r['code'] === 'OK' && $r['xu_awarded'] === 5 && $r['xu_balance'] === 300);

$sec2('u3');
$r = $svc2->checkin(['customer_id' => 'u3', 'customer_email' => 'c@x.vn']);
$check('syncLoyalty ném lỗi -> vẫn OK và sổ đã ghi', $r['code'] === 'OK' && $ledger2->hasKey('checkin:u3:2026-11-15'));

$u4 = ['customer_id' => 'u4', 'customer_email' => 'd@x.vn'];
$first = $svc2->awardVideoXu($u4, ['campaign_id' => 'cx', 'reward_xu' => 5], 'v');
$callsAfterFirst = $resolveCalls;
$second = $svc2->awardVideoXu($u4, ['campaign_id' => 'cx', 'reward_xu' => 5], 'v');
$check('awardVideoXu lần 2 -> ALREADY_CLAIMED, không gọi resolveMaKh, không tính số dư',
    $second['code'] === 'ALREADY_CLAIMED' && $resolveCalls === $callsAfterFirst && $second['xu_balance'] === null);

$src = file_get_contents(__DIR__ . '/../../app/models/VideoRewardCampaign.php');
$check("VideoRewardCampaign lưu reward_xu ở normalize/insert/update", substr_count($src, "'reward_xu' =>") >= 2 && strpos($src, "\$row['reward_xu']") !== false);

// ---- điểm danh khi chạm trần 300 xu: vẫn giữ chuỗi ----
$db3 = new FakeDb();
$now3 = strtotime('2026-11-15 03:00:00 UTC');
$svc3 = new RewardService(
    $db3,
    fn(string $email) => $email === 'e@x.vn' ? 11 : null,
    function (int $maKh) {},
    function () use (&$now3) { return $now3; },
    fn(int $maKh) => $maKh === 11 ? 25 : 0
);
$u5 = ['customer_id' => 'u5', 'customer_email' => 'e@x.vn'];
$sec5 = function (string $dateVn, int $sec) use ($db3) {
    $db3->video_watch_daily->docs[] = ['customer_id' => 'u5', 'date_vn' => $dateVn, 'seconds' => $sec];
};
$led3 = new RewardLedger($db3);
$cap3 = fn($key, $date) => $led3->award(['customer_id' => 'u5', 'ma_kh' => 11, 'source' => 'video', 'source_key' => $key, 'amount_xu' => 100, 'date_vn' => $date, 'meta' => []]);
$cap3('video:u5:a', '2026-11-15'); $cap3('video:u5:b', '2026-11-15'); $cap3('video:u5:c', '2026-11-15');
$sec5('2026-11-15', 90);
$s5 = $svc3->summary($u5);
$check('chạm trần: can_checkin vẫn true, points_available trả về', $s5['xu_today'] === 300 && $s5['checkin']['can_checkin'] === true && $s5['points_available'] === 25);
$r = $svc3->checkin($u5);
$rows = $db3->reward_checkins->docs;
$check('chạm trần -> OK, xu_awarded 0, có dòng reward_checkins streak 1',
    $r['ok'] === true && $r['code'] === 'OK' && $r['xu_awarded'] === 0 && count($rows) === 1 && $rows[0]['streak_day'] === 1 && $rows[0]['ma_kh'] === 11 && strpos($r['message'], 'đã đủ 300 xu') !== false);
$check('điểm danh lần 2 trong ngày -> ALREADY_CHECKED_IN', $svc3->checkin($u5)['code'] === 'ALREADY_CHECKED_IN' && count($db3->reward_checkins->docs) === 1);
$check('summary sau điểm danh ở trần: checked_in_today, ngày 1 done', ($x = $svc3->summary($u5))['checkin']['checked_in_today'] === true && $x['checkin']['days'][0]['state'] === 'done');
$check('reward_checkins có unique index {ma_kh, date_vn}', $db3->reward_checkins->indexes[0]['unique'] === true && array_keys($db3->reward_checkins->indexes[0]['keys']) === ['ma_kh', 'date_vn']);

$now3 = strtotime('2026-11-15 17:00:00 UTC'); // 16/11 giờ VN
$sec5('2026-11-16', 60);
$r2 = $svc3->checkin($u5);
$check('hôm sau chuỗi tiếp tục ngày 2 dù ngày 1 nhận 0 xu', $r2['code'] === 'OK' && $r2['xu_awarded'] === 10 && $db3->reward_checkins->docs[1]['streak_day'] === 2 && $db3->reward_checkins->docs[1]['xu_awarded'] === 10);

// tương thích ngược: chuỗi chỉ có trong sổ xu cũ
$db4 = new FakeDb();
$now4 = strtotime('2026-11-15 03:00:00 UTC');
$svc4 = new RewardService($db4, fn(string $e) => 12, function (int $m) {}, function () use (&$now4) { return $now4; });
$db4->reward_xu_ledger->docs[] = ['customer_id' => 'u6', 'ma_kh' => 12, 'source' => 'checkin', 'source_key' => 'checkin:u6:2026-11-14', 'amount_xu' => 10, 'date_vn' => '2026-11-14', 'meta' => ['streak_day' => 3]];
$db4->video_watch_daily->docs[] = ['customer_id' => 'u6', 'date_vn' => '2026-11-15', 'seconds' => 70];
$r = $svc4->checkin(['customer_id' => 'u6', 'customer_email' => 'f@x.vn']);
$check('fallback sổ xu cũ: hôm qua ngày 3 -> hôm nay ngày 4 (10 xu)', $r['code'] === 'OK' && $r['xu_awarded'] === 10 && $db4->reward_checkins->docs[0]['streak_day'] === 4);
$check('summary khách không có hồ sơ -> points_available 0', $svc4->summary(['customer_id' => null, 'customer_email' => null])['points_available'] === 0);

// ---- lỗi tạm thời khi ghi sổ xu: gỡ dòng điểm danh, thử lại vẫn nhận xu ----
$db5 = new FakeDb();
$now5 = strtotime('2026-11-15 03:00:00 UTC');
$svc5 = new RewardService($db5, fn(string $e) => 13, function (int $m) {}, function () use (&$now5) { return $now5; });
$u7 = ['customer_id' => 'u7', 'customer_email' => 'g@x.vn'];
$db5->video_watch_daily->docs[] = ['customer_id' => 'u7', 'date_vn' => '2026-11-15', 'seconds' => 60];
$db5->reward_xu_ledger->throwOnInsert = true;
$r = $svc5->checkin($u7);
$leftover = array_filter($db5->reward_checkins->docs, fn($d) => $d['ma_kh'] === 13 && $d['date_vn'] === '2026-11-15');
$check('sổ xu ném lỗi -> TEMPORARY_ERROR và không còn dòng reward_checkins', $r['ok'] === false && $r['code'] === 'TEMPORARY_ERROR' && count($leftover) === 0);
$db5->reward_xu_ledger->throwOnInsert = false;
$r = $svc5->checkin($u7);
$check('thử lại sau lỗi -> OK, xu_awarded 10 (ngày 1)', $r['code'] === 'OK' && $r['xu_awarded'] === RewardRules::checkinXu(1) && $r['xu_awarded'] === 10 && count($db5->reward_checkins->docs) === 1);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
