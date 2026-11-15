<?php
// Test thuần PHP (Mongo giả, không DB thật) cho phiên xem mode 'time' + cộng giây theo ngày.
// Chạy: php backend/tests/unit/VideoWatchTimeModeTest.php
require_once __DIR__ . '/support/FakeMongo.php';
require_once __DIR__ . '/../../app/services/RewardRules.php';
require_once __DIR__ . '/../../app/models/VideoWatchDaily.php';
require_once __DIR__ . '/../../app/models/VideoWatchSession.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$db = new FakeDb();
$db->ai_product_videos->docs[] = ['_id' => 'pubA', 'video_id' => 'vA', 'is_published' => true, 'duration' => 60];
$db->ai_product_videos->docs[] = ['_id' => 'pubB', 'video_id' => 'vB', 'is_published' => true, 'duration' => 60];
$m = new VideoWatchSession($db);

// Đặt giả lập "đã trôi $real giây thực" cho phiên $sid.
$backdate = function (string $sid, float $real, float $playback) use ($db) {
    foreach ($db->ai_video_watch_sessions->docs as &$d) {
        if ($d['watch_session_id'] === $sid) {
            $d['last_server_time'] = microtime(true) - $real;
            $d['last_playback_time'] = $playback;
        }
    }
    unset($d);
};

$g = $m->startOrResumeSession(['video_id' => 'vA', 'guest_session_id' => 'g1']);
$check('khách vãng lai + không chiến dịch -> NO_ACTIVE_CAMPAIGN như cũ', $g['ok'] === false && $g['code'] === 'NO_ACTIVE_CAMPAIGN');

$s = $m->startOrResumeSession(['video_id' => 'vA', 'customer_id' => 'u1']);
$check('đã đăng nhập + không chiến dịch -> phiên mode time', $s['ok'] === true && $s['session']['mode'] === 'time');
$sid = $s['session']['watch_session_id'];
$check('resume cùng ngày trả lại đúng phiên', $m->startOrResumeSession(['video_id' => 'vA', 'customer_id' => 'u1'])['session']['watch_session_id'] === $sid);

$backdate($sid, 4.0, 0.0);
$h = $m->recordHeartbeat($sid, 4.0, 1.0, false);
$check('heartbeat time-mode OK, không đụng chiến dịch, cộng ngày', $h['ok'] === true && $h['today_seconds'] === 4 && $h['is_eligible'] === false);

$backdate($sid, 4.0, 4.0);
$check('tua tới không được cộng', $m->recordHeartbeat($sid, 50.0, 1.0, false)['today_seconds'] === 4);

// 2 tab (2 video) cùng u2, chỉ 2s thực giữa hai lần cộng
$a = $m->startOrResumeSession(['video_id' => 'vA', 'customer_id' => 'u2'])['session']['watch_session_id'];
$b = $m->startOrResumeSession(['video_id' => 'vB', 'customer_id' => 'u2'])['session']['watch_session_id'];
$backdate($a, 4.0, 0.0);
$r1 = $m->recordHeartbeat($a, 4.0, 1.0, false);
foreach ($db->video_watch_daily->docs as &$dd) {
    if ($dd['customer_id'] === 'u2') {
        $dd['last_credit_at'] -= 2.0; // 2s thực đã trôi kể từ lần cộng đầu
    }
}
unset($dd);
$backdate($b, 4.0, 0.0);
$r2 = $m->recordHeartbeat($b, 4.0, 1.0, false);
$total = $r2['today_seconds'];
$realElapsed = 4.0 + 2.0;
$check('2 tab không cộng nhanh hơn thời gian thật (đúng 4s + 3s = 7s)', $total === 7 && $r1['today_seconds'] === 4 && $total <= $realElapsed + RewardRules::CREDIT_JITTER_SECONDS);

// validateCompletion trên phiên time: không đủ điều kiện, không ghi trạng thái
$db->ai_video_watch_sessions->docs[] = [
    'watch_session_id' => 'ts1', 'customer_id' => 'u4', 'video_id' => 'vA', 'publication_id' => 'pubA',
    'campaign_id' => null, 'mode' => 'time', 'date_vn' => RewardRules::dateVn(time()), 'video_duration' => 60.0,
    'watch_state' => 'IN_PROGRESS', 'is_eligible' => false, 'valid_watched_intervals' => [[0.0, 60.0]],
    'watched_seconds' => 60.0, 'watched_coverage_percent' => 100.0, 'last_server_time' => microtime(true) - 4, 'last_playback_time' => 60.0,
];
$v = $m->validateCompletion('ts1');
$stored = null;
foreach ($db->ai_video_watch_sessions->docs as $d) { if ($d['watch_session_id'] === 'ts1') { $stored = $d; } }
$check('validateCompletion mode time -> TIME_MODE, không eligible, không ghi state', $v['ok'] === true && $v['code'] === 'TIME_MODE' && $v['is_eligible'] === false && $stored['watch_state'] === 'IN_PROGRESS' && $stored['is_eligible'] === false);

// Phiên chiến dịch có sẵn nhưng chiến dịch không còn hoạt động -> hành vi cũ
$db->ai_video_watch_sessions->docs[] = [
    'watch_session_id' => 'cs1', 'customer_id' => 'u3', 'video_id' => 'vA', 'publication_id' => 'pubA',
    'campaign_id' => 'cX', 'video_duration' => 60.0, 'watch_state' => 'IN_PROGRESS', 'is_eligible' => false,
    'valid_watched_intervals' => [], 'watched_seconds' => 0.0, 'last_server_time' => microtime(true) - 4, 'last_playback_time' => 0.0,
];
$expired = $m->recordHeartbeat('cs1', 4.0, 1.0, false);
$check('phiên có chiến dịch giữ hành vi cũ khi chiến dịch hết hạn -> CAMPAIGN_EXPIRED', $expired['ok'] === false && $expired['code'] === 'CAMPAIGN_EXPIRED');

// Hồi quy: phiên chiến dịch đủ phủ + tới cuối vẫn lên ELIGIBLE qua validateCompletion
$db->ai_video_watch_sessions->docs[] = [
    'watch_session_id' => 'cs2', 'customer_id' => 'u5', 'video_id' => 'vA', 'publication_id' => 'pubA',
    'campaign_id' => 'cY', 'video_duration' => 60.0, 'watch_state' => 'IN_PROGRESS', 'is_eligible' => false,
    'valid_watched_intervals' => [[0.0, 60.0]], 'watched_seconds' => 60.0, 'watched_coverage_percent' => 100.0,
    'last_server_time' => microtime(true) - 4, 'last_playback_time' => 60.0,
];
$vc = $m->validateCompletion('cs2');
$storedC = null;
foreach ($db->ai_video_watch_sessions->docs as $d) { if ($d['watch_session_id'] === 'cs2') { $storedC = $d; } }
$check('hồi quy: phiên chiến dịch đủ điều kiện -> ELIGIBLE và ghi state', $vc['ok'] === true && $vc['is_eligible'] === true && $vc['watch_state'] === 'ELIGIBLE' && $storedC['watch_state'] === 'ELIGIBLE' && $storedC['is_eligible'] === true);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
