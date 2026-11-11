<?php

/**
 * Static contract checks for the refresh-aware homepage product sections.
 * This test deliberately does not load .env, MongoDB, Redis, or production data.
 */

$root = dirname(__DIR__, 2);
$homeController = (string)file_get_contents($root . '/backend/app/controllers/HomeController.php');
$sanPham = (string)file_get_contents($root . '/backend/app/models/SanPham.php');
$homeView = (string)file_get_contents($root . '/frontend/views/home.php');

require_once $root . '/backend/app/models/SanPham.php';
$selector = new ReflectionMethod(SanPham::class, 'selectHomepageProductWindow');
$selector->setAccessible(true);
$candidatePool = array_map(static fn (int $id): array => ['ma_san_pham' => (string)$id], range(1, 8));
$windowA = $selector->invoke(new SanPham(null), $candidatePool, 4, 101, []);
$windowB = $selector->invoke(new SanPham(null), $candidatePool, 4, 202, []);
$windowAIds = array_column($windowA, 'ma_san_pham');
$windowBIds = array_column($windowB, 'ma_san_pham');

$checks = [
    'request seed is generated for homepage refreshes' => strpos($homeController, 'random_int(1, 2147483647)') !== false,
    'homepage receives the refresh seed and latest candidate pool' => strpos($homeController, '$homepageRefreshSeed, $latest') !== false,
    'homepage public cache key is versioned for the wider pool' => strpos($homeController, "home:composite_public:v2") !== false,
    'latest candidate pool is wider than the visible four-card window' => strpos($homeController, 'latest(24, true, true)') !== false,
    'model accepts an optional refresh seed' => strpos($sanPham, '?int $refreshSeed = null') !== false,
    'model returns the refresh-aware today section' => strpos($sanPham, "'today' => \$todayProducts") !== false,
    'model returns the refresh-aware new-products section' => strpos($sanPham, "'newProducts' => \$newProducts") !== false,
    'selection excludes duplicate product ids' => strpos($sanPham, 'isset($unique[$id])') !== false,
    'selection rotates candidates by a request seed' => strpos($sanPham, "hash('sha256', (string)\$seed") !== false,
    'view renders a today recommendation heading' => strpos($homeView, 'GỢI Ý HÔM NAY') !== false,
    'view preserves the rating context label' => strpos($homeView, 'Được Yêu Thích Nhất') !== false,
    'view renders the new-products candidate section' => strpos($homeView, "\$homepageSections['newProducts']") !== false,
    'view has guest copy' => strpos($homeView, 'Gợi ý hôm nay, mỗi lần ghé là một lựa chọn mới') !== false,
    'view has logged-in no-profile copy' => strpos($homeView, 'Gợi ý hôm nay dành cho bạn') !== false,
    'view has completed-profile copy' => strpos($homeView, 'Gợi ý hôm nay cho làn da của bạn') !== false,
    'different refresh seeds produce different candidate windows' => $windowAIds !== $windowBIds,
    'candidate window has no duplicates and respects its limit' => count($windowAIds) === 4 && count($windowAIds) === count(array_unique($windowAIds)),
];

$passed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' HOME-PERSONALIZATION - ' . $name . PHP_EOL;
    $passed += $ok ? 1 : 0;
}

$total = count($checks);
echo "Homepage personalization contract tests: {$passed}/{$total} passed, " . ($total - $passed) . " failed." . PHP_EOL;
exit($passed === $total ? 0 : 1);

