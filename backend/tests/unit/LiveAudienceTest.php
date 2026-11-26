<?php
declare(strict_types=1);

// Pure authorization regression tests: synthetic identities, no database or network.
$role = 'guest';
$user = [];
function current_role(): string { return $GLOBALS['role']; }
function current_user(): array { return $GLOBALS['user']; }
require_once __DIR__ . '/../../app/controllers/LiveController.php';
$audience = new ReflectionMethod(LiveController::class, 'liveAudience');
$controller = new LiveController(null);
$room = ['publisher_staff_id' => 'fixture-host', 'live_staff' => ['fixture-mod' => ['moderate' => true]]];
$cases = [
    ['guest', [], false, false],
    ['khachhang', ['ma_kh' => 'fixture-customer'], false, false],
    ['nhanvien', ['ma_nv' => 'fixture-other'], false, false],
    ['nhanvien', ['ma_nv' => 'fixture-host'], true, true],
    ['nhanvien', ['ma_nv' => 'fixture-mod'], false, true],
    ['admin', ['id' => 'fixture-admin'], true, true],
];
$fail = 0;
foreach ($cases as [$role, $user, $publish, $moderate]) {
    $actual = $audience->invoke($controller, $room);
    $ok = $actual['publish'] === $publish && $actual['moderate'] === $moderate;
    echo ($ok ? 'PASS' : 'FAIL') . ' role=' . $role . ' publication and moderation boundaries' . PHP_EOL;
    if (!$ok) $fail++;
}
exit($fail ? 1 : 0);
