<?php
// backend/tests/benchmark_pdp_mongo_vs_valkey.php
// Direct Head-to-Head Comparison: MongoDB Indexed findOne vs Valkey HIT

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CacheService.php';
require_once __DIR__ . '/../app/models/SanPham.php';

$sanPham = new SanPham($pdo);
$cache = CacheService::getInstance();

$testId = 111;
// Warm up data
$product = $sanPham->find($testId, true);
$cache->set('product:detail:' . $testId, $product, 3600);

echo "========================================================================\n";
echo "BENCHMARK: MongoDB Indexed findOne vs Aiven Valkey Remote GET (30 runs)\n";
echo "========================================================================\n\n";

function stats(array $arr): array {
    sort($arr);
    $n = count($arr);
    return [
        'min' => round($arr[0], 2),
        'max' => round($arr[$n - 1], 2),
        'avg' => round(array_sum($arr) / $n, 2),
        'p50' => round($arr[(int)floor($n * 0.50)], 2),
        'p95' => round($arr[min($n - 1, (int)floor($n * 0.95))], 2),
    ];
}

$mongoTimes = [];
$valkeyTimes = [];

// Interleaved execution (30 runs each) to eliminate time-based network bias
for ($i = 1; $i <= 30; $i++) {
    // 1. Valkey GET (Remote TLS)
    // Clear L1 to force remote Valkey query
    $cache->clearL1();
    $t0 = microtime(true);
    $vDoc = $cache->get('product:detail:' . $testId);
    $valkeyTimes[] = (microtime(true) - $t0) * 1000;

    // 2. MongoDB findOne (Indexed)
    $t1 = microtime(true);
    $mDoc = $sanPham->find($testId, true);
    $mongoTimes[] = (microtime(true) - $t1) * 1000;
}

$mStats = stats($mongoTimes);
$vStats = stats($valkeyTimes);

echo "MongoDB findOne (Indexed):  min={$mStats['min']}ms | avg={$mStats['avg']}ms | p50={$mStats['p50']}ms | p95={$mStats['p95']}ms | max={$mStats['max']}ms\n";
echo "Aiven Valkey GET (TLS HIT): min={$vStats['min']}ms | avg={$vStats['avg']}ms | p50={$vStats['p50']}ms | p95={$vStats['p95']}ms | max={$vStats['max']}ms\n\n";

if ($vStats['p50'] > $mStats['p50']) {
    $diff = round($vStats['p50'] - $mStats['p50'], 2);
    $pct = round(($diff / $mStats['p50']) * 100, 1);
    echo ">>> VERDICT: Aiven Valkey is {$diff}ms ({$pct}%) SLOWER than MongoDB indexed findOne! <<<\n";
    echo "Recommendation: Do NOT use remote Valkey for single-product lookups.\n";
} else {
    $diff = round($mStats['p50'] - $vStats['p50'], 2);
    echo ">>> VERDICT: Aiven Valkey is {$diff}ms faster than MongoDB. <<<\n";
}
