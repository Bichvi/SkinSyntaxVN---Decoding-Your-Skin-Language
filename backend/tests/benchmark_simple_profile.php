<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
global $db;

$spModel = new SanPham($db);

echo "=================================================================\n";
echo "STEP 1: PROFILE CURRENT BOTTLENECK OF SIMPLE RECOMMENDER\n";
echo "=================================================================\n";

// Save baseline Top-24
$baselineTop24 = $spModel->getSimpleRecommenderProducts(24);
$baselineFile = __DIR__ . '/output/simple_baseline_top24.json';
if (!is_dir(dirname($baselineFile))) {
    mkdir(dirname($baselineFile), 0777, true);
}
$baselineData = [];
foreach ($baselineTop24 as $rank => $p) {
    $baselineData[] = [
        'rank' => $rank + 1,
        'ma_san_pham' => (string)$p['ma_san_pham'],
        'ten_san_pham' => (string)$p['ten_san_pham'],
        'WR' => (float)$p['recommender_meta']['weighted_rating'],
        'v' => (int)$p['recommender_meta']['v'],
        'R' => (float)$p['recommender_meta']['R'],
    ];
}
file_put_contents($baselineFile, json_encode($baselineData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Saved baseline Top-24 to {$baselineFile} (" . count($baselineData) . " items).\n\n";

// Detailed Trace for 1 invocation
$m = 21.0;
$C = 4.8890;

$t0 = microtime(true);
$filter = [
    '$and' => [
        ['trang_thai' => ['$nin' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']]],
        [
            'so_luong_danh_gia' => ['$gte' => $m],
            'diem_danh_gia' => ['$gt' => 0],
            'gia_ban' => ['$gt' => 0]
        ]
    ]
];

$t1 = microtime(true);
$cursor = $db->san_pham->find($filter);
$docs = iterator_to_array($cursor);
$t2 = microtime(true);

$candidates = [];
$t3_hydrate_start = microtime(true);
foreach ($docs as $doc) {
    $p = (array)$doc;
    $candidates[] = $p;
}
$t3_hydrate_end = microtime(true);

$t4_wr_start = microtime(true);
foreach ($candidates as &$p) {
    $r = (float)($p['diem_danh_gia'] ?? 0);
    $v = (int)($p['so_luong_danh_gia'] ?? 0);
    $wr = ($v + $m > 0) ? (($v / ($v + $m)) * $r + ($m / ($v + $m)) * $C) : 0.0;
    $p['recommender_meta'] = [
        'R' => $r,
        'v' => $v,
        'C' => $C,
        'm' => $m,
        'weighted_rating' => round($wr, 4)
    ];
}
unset($p);
$t4_wr_end = microtime(true);

$t5_sort_start = microtime(true);
usort($candidates, function($a, $b) {
    $diff = $b['recommender_meta']['weighted_rating'] <=> $a['recommender_meta']['weighted_rating'];
    if ($diff !== 0) return $diff;
    $vDiff = $b['recommender_meta']['v'] <=> $a['recommender_meta']['v'];
    if ($vDiff !== 0) return $vDiff;
    return strcmp((string)($a['ma_san_pham'] ?? ''), (string)($b['ma_san_pham'] ?? ''));
});
$t5_sort_end = microtime(true);

echo "Trace breakdown on 1 sample invocation:\n";
echo "  Matching candidate documents: " . count($docs) . " docs\n";
echo "  Query + network fetch: " . number_format(($t2 - $t1) * 1000, 2) . " ms\n";
echo "  Document array conversion: " . number_format(($t3_hydrate_end - $t3_hydrate_start) * 1000, 2) . " ms\n";
echo "  WR computation: " . number_format(($t4_wr_end - $t4_wr_start) * 1000, 2) . " ms\n";
echo "  PHP usort(): " . number_format(($t5_sort_end - $t5_sort_start) * 1000, 2) . " ms\n";
echo "  Total sample execution: " . number_format(($t5_sort_end - $t0) * 1000, 2) . " ms\n\n";

// Benchmark function: 30 runs per limit
function runBenchmark($spModel, int $limit, int $runs = 30): array {
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $start = microtime(true);
        $spModel->getSimpleRecommenderProducts($limit);
        $times[] = (microtime(true) - $start) * 1000;
    }
    sort($times);
    $median = $times[(int)($runs * 0.5)];
    $p95 = $times[(int)($runs * 0.95)];
    $mean = array_sum($times) / $runs;
    return ['median' => $median, 'p95' => $p95, 'mean' => $mean, 'min' => min($times), 'max' => max($times)];
}

echo "Running 30 iterations for Limit = 4, 8, 10...\n";
$bench4 = runBenchmark($spModel, 4, 30);
$bench8 = runBenchmark($spModel, 8, 30);
$bench10 = runBenchmark($spModel, 10, 30);

echo "\n--- CURRENT BENCHMARK (BEFORE OPTIMIZATION, 30 RUNS EACH) ---\n";
echo sprintf("%-8s | %-12s | %-12s | %-12s | %-10s | %-10s\n", "Limit", "Median (ms)", "p95 (ms)", "Mean (ms)", "Min (ms)", "Max (ms)");
echo str_repeat("-", 72) . "\n";
echo sprintf("%-8s | %-12.2f | %-12.2f | %-12.2f | %-10.2f | %-10.2f\n", "Top-4", $bench4['median'], $bench4['p95'], $bench4['mean'], $bench4['min'], $bench4['max']);
echo sprintf("%-8s | %-12.2f | %-12.2f | %-12.2f | %-10.2f | %-10.2f\n", "Top-8", $bench8['median'], $bench8['p95'], $bench8['mean'], $bench8['min'], $bench8['max']);
echo sprintf("%-8s | %-12.2f | %-12.2f | %-12.2f | %-10.2f | %-10.2f\n", "Top-10", $bench10['median'], $bench10['p95'], $bench10['mean'], $bench10['min'], $bench10['max']);

// Save benchmark results to file for before-after comparison
file_put_contents(__DIR__ . '/output/simple_benchmark_before.json', json_encode([
    'top4' => $bench4,
    'top8' => $bench8,
    'top10' => $bench10,
], JSON_PRETTY_PRINT));
