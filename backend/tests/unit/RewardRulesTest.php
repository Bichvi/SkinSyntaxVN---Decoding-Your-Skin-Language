<?php
// Test thuần PHP (không DB, không mạng) cho RewardRules.
// Chạy: php backend/tests/Unit/RewardRulesTest.php
require_once __DIR__ . '/../../app/services/RewardRules.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

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

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
