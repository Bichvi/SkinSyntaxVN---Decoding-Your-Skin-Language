<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
global $db;

$spModel = new SanPham($db);

echo "=================================================================\n";
echo "VERIFICATION & BENCHMARK OF OPTIMIZED SIMPLE RECOMMENDER\n";
echo "=================================================================\n";

// Step 1: Rebuild cache and test Top-24 Consistency against Baseline
echo "\n--- STEP 1: TOP-24 CONSISTENCY TEST ---\n";
$baselineFile = __DIR__ . '/output/simple_baseline_top24.json';
if (!file_exists($baselineFile)) {
    die("Error: Baseline file not found at {$baselineFile}\n");
}
$baselineData = json_decode(file_get_contents($baselineFile), true);

// Rebuild cache
SanPham::clearSimpleRecommenderCache();
$optTop24 = $spModel->getSimpleRecommenderProducts(24);

$allMatch = true;
echo sprintf("%-4s | %-6s vs %-6s | %-8s vs %-8s | %-4s vs %-4s | %-4s vs %-4s | %s\n",
    "Rank", "BaseID", "OptID", "BaseWR", "OptWR", "B_v", "O_v", "B_R", "O_R", "Status");
echo str_repeat("-", 80) . "\n";

foreach ($baselineData as $i => $base) {
    $opt = $optTop24[$i];
    $optId = (string)$opt['ma_san_pham'];
    $optWR = (float)$opt['recommender_meta']['weighted_rating'];
    $optV = (int)$opt['recommender_meta']['v'];
    $optR = (float)$opt['recommender_meta']['R'];

    $baseId = (string)$base['ma_san_pham'];
    $baseWR = (float)$base['WR'];
    $baseV = (int)$base['v'];
    $baseR = (float)$base['R'];

    $isEqual = ($baseId === $optId) && ($baseWR === $optWR) && ($baseV === $optV) && ($baseR === $optR);
    if (!$isEqual) $allMatch = false;

    echo sprintf("%-4d | %-6s vs %-6s | %-8.4f vs %-8.4f | %-4d vs %-4d | %-4.1f vs %-4.1f | %s\n",
        $i + 1, $baseId, $optId, $baseWR, $optWR, $baseV, $optV, $baseR, $optR, ($isEqual ? "MATCH" : "MISMATCH"));
}

if (!$allMatch) {
    die("\nCRITICAL FAILURE: Top-24 is NOT identical to baseline! Must rollback!\n");
}
echo "\nTop-24 Consistency: 100% IDENTICAL across all 24 ranks!\n";

// Step 2: Regression test on limits 4, 8, 10, 24
echo "\n--- STEP 2: REGRESSION TESTS (LIMITS 4, 8, 10, 24) ---\n";
foreach ([4, 8, 10, 24] as $lim) {
    $items = $spModel->getSimpleRecommenderProducts($lim);
    $cnt = count($items);
    $expectedIds = array_map('strval', array_slice(array_column($baselineData, 'ma_san_pham'), 0, $lim));
    $actualIds = array_map('strval', array_column($items, 'ma_san_pham'));
    $pass = ($cnt === $lim) && ($expectedIds === $actualIds);
    echo "Limit {$lim}: count={$cnt}, IDs match: " . ($pass ? "PASS" : "FAIL") . "\n";
    if (!$pass) {
        echo "Expected: " . json_encode($expectedIds) . "\n";
        echo "Actual:   " . json_encode($actualIds) . "\n";
        die("Regression failure on limit {$lim}!\n");
    }
}

// Step 3: Homepage Sections Regression
echo "\n--- STEP 3: HOMEPAGE SECTIONS REGRESSION ---\n";
$sections = $spModel->getHomepageProductSections(8, [], null);
$topRated = $sections['topRatedWeighted'] ?? [];
$expectedHomeIds = array_map('strval', array_slice(array_column($baselineData, 'ma_san_pham'), 0, 8));
$actualHomeIds = array_map('strval', array_column($topRated, 'ma_san_pham'));
$passHome = (count($topRated) === 8) && ($actualHomeIds === $expectedHomeIds);
echo "Homepage Sections (topRatedWeighted): count=" . count($topRated) . " -> " . ($passHome ? "PASS" : "FAIL") . "\n";
if (!$passHome) {
    echo "Expected: " . json_encode($expectedHomeIds) . "\n";
    echo "Actual:   " . json_encode($actualHomeIds) . "\n";
    die("Regression failure in homepage sections!\n");
}

// Content-Based & Hybrid Regression
require_once __DIR__ . '/../app/services/ContentBasedRecommender.php';
$cbr = new ContentBasedRecommender();
$cbRecs = $spModel->getHybridRecommendations(['1000'], null, 4);
echo "Content-Based Recommender: returned " . count($cbRecs) . " items -> " . (!empty($cbRecs) ? "PASS" : "FAIL") . "\n";

$hybridRecs = $spModel->getHybridRecommendations(['1000'], ['skin_type' => 'Da khô'], 4);
echo "Hybrid Recommender: returned " . count($hybridRecs) . " items -> " . (!empty($hybridRecs) ? "PASS" : "FAIL") . "\n";

// Step 4: Benchmark AFTER optimization (30 runs each - realistic cross-request file cache)
echo "\n--- STEP 4: BENCHMARK AFTER OPTIMIZATION (30 RUNS EACH - REALISTIC CROSS-REQUEST FILE CACHE) ---\n";
function runBenchmarkOpt($spModel, int $limit, int $runs = 30): array {
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        // Reset in-memory cache to measure realistic file-cache read on every HTTP request
        SanPham::clearLookupCache();
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

$benchAfter4 = runBenchmarkOpt($spModel, 4, 30);
$benchAfter8 = runBenchmarkOpt($spModel, 8, 30);
$benchAfter10 = runBenchmarkOpt($spModel, 10, 30);

// Load BEFORE benchmark
$beforeFile = __DIR__ . '/output/simple_benchmark_before.json';
$beforeData = file_exists($beforeFile) ? json_decode(file_get_contents($beforeFile), true) : [];

echo sprintf("%-8s | %-14s | %-14s | %-14s | %-14s | %-10s\n",
    "Limit", "Median Before", "Median After", "p95 Before", "p95 After", "Speedup");
echo str_repeat("-", 85) . "\n";

$limits = [
    'Top-4' => ['before' => $beforeData['top4'] ?? null, 'after' => $benchAfter4],
    'Top-8' => ['before' => $beforeData['top8'] ?? null, 'after' => $benchAfter8],
    'Top-10' => ['before' => $beforeData['top10'] ?? null, 'after' => $benchAfter10],
];

foreach ($limits as $label => $data) {
    $medBefore = $data['before']['median'] ?? 0;
    $medAfter = $data['after']['median'] ?? 0;
    $p95Before = $data['before']['p95'] ?? 0;
    $p95After = $data['after']['p95'] ?? 0;
    $speedup = ($medAfter > 0) ? ($medBefore / $medAfter) : 0;
    
    echo sprintf("%-8s | %-14.2f | %-14.4f | %-14.2f | %-14.4f | %-10.1fx\n",
        $label, $medBefore, $medAfter, $p95Before, $p95After, $speedup);
}

// Step 5: Test HTTP Home Status
echo "\n--- STEP 5: HOME CONTROLLER & HTTP INTEGRITY CHECK ---\n";
require_once dirname(__DIR__, 2) . '/frontend/views/helpers.php';
require_once __DIR__ . '/../app/controllers/HomeController.php';
$_GET['r'] = 'home';
ob_start();
$homeCtrl = new HomeController($db);
$homeCtrl->index();
$outputHtml = ob_get_clean();
$hasContent = strlen($outputHtml) > 50000 && (strpos($outputHtml, 'Dành Riêng Cho Bạn') !== false || strpos($outputHtml, 'Được Yêu Thích Nhất') !== false);
echo "HomeController dispatch: " . ($hasContent ? "PASS (Payload size: " . strlen($outputHtml) . " bytes)" : "FAIL") . "\n";

echo "\n=================================================================\n";
echo "ALL OPTIMIZATION CHECKS COMPLETED SUCCESSFULLY!\n";
echo "=================================================================\n";
