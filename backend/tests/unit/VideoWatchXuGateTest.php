<?php
// Test thuần PHP cho cổng quyết định cộng xu theo video (không DB).
// Chạy: php backend/tests/unit/VideoWatchXuGateTest.php
require_once __DIR__ . '/../../app/controllers/VideoWatchController.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$session = ['customer_id' => 'u1', 'mode' => 'campaign', 'campaign_id' => 'c1', 'xu_settled' => false];
$me = ['customer_id' => 'u1', 'customer_email' => 'a@x.vn'];
$elig = ['is_eligible' => true];
$g = fn($s, $i, $r) => VideoWatchController::shouldAwardVideoXu($s, $i, $r);

$check('chủ phiên, đủ điều kiện -> true', $g($session, $me, $elig) === true);
$check('đủ điều kiện qua session.watch_state ELIGIBLE -> true', $g($session, $me, ['session' => ['watch_state' => 'ELIGIBLE']]) === true);
$check('khách khác dùng watch_session_id của người khác -> false', $g($session, ['customer_id' => 'u2'], $elig) === false);
$check('khách vãng lai -> false', $g($session, ['customer_id' => null], $elig) === false);
$check('phiên không có chủ (guest) -> false', $g(['customer_id' => null] + $session, $me, $elig) === false);
$check('mode time -> false', $g(['mode' => 'time'] + $session, $me, $elig) === false);
$check('đã settle -> false', $g(['xu_settled' => true] + $session, $me, $elig) === false);
$check('chưa đủ điều kiện -> false', $g($session, $me, ['is_eligible' => false]) === false);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
