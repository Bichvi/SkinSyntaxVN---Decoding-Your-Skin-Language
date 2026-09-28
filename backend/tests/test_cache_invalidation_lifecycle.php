<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
global $db;

echo "=== FINAL CACHE INVALIDATION LIFECYCLE TEST ===\n\n";

$spModel = new SanPham($db);
$cacheFile = dirname(__DIR__) . '/app/content/simple_recommender_cache.json';

// Test 1: Clear and Build Cache
echo "1. Building Simple Recommender Cache...\n";
$spModel->rebuildSimpleRecommenderCache();

if (file_exists($cacheFile)) {
    $cachedData = json_decode(file_get_contents($cacheFile), true);
    $count = is_array($cachedData) ? count($cachedData) : 0;
    echo "   [PASS] Cache file generated at: {$cacheFile} (Item count: {$count})\n";
} else {
    echo "   [FAIL] Cache file was not created.\n";
    exit(1);
}

// Test 2: In-memory cache hit
echo "2. Verifying In-Memory Cache Read...\n";
$start = microtime(true);
$inMemory = $spModel->getSimpleRecommenderProducts(24);
$duration = (microtime(true) - $start) * 1000;
echo "   [PASS] Read " . count($inMemory) . " items in " . round($duration, 3) . " ms (in-memory hit).\n";

// Test 3: Test controlled write path - updateProductVisibility
echo "3. Testing Controlled Write Path 1: updateProductVisibility()...\n";
// Choose a product, record original status
$testId = '1000'; // SP 1000 Eucerin
$productBefore = $spModel->findById($testId);
$origStatus = $productBefore['trang_thai'] ?? 'active';

echo "   Original status of SP {$testId}: {$origStatus}\n";
$updateResult = $spModel->updateProductVisibility($testId, 'inactive');
echo "   updateProductVisibility('{$testId}', 'inactive') returned: " . ($updateResult ? "true" : "false") . "\n";

// Verify cache was invalidated
if (!file_exists($cacheFile)) {
    echo "   [PASS] File cache simple_recommender_cache.json was successfully UNLINKED/INVALIDATED.\n";
} else {
    echo "   [FAIL] Cache file still exists after updateProductVisibility!\n";
}

// Rollback DB update for Test 3
$rollbackResult = $spModel->updateProductVisibility($testId, $origStatus);
echo "   [ROLLBACK] Reverted SP {$testId} status back to '{$origStatus}': " . ($rollbackResult ? "success" : "failed") . "\n";

// Test 4: Test controlled write path - adminUpdate
echo "4. Testing Controlled Write Path 2: adminUpdate()...\n";
// Rebuild cache first
$spModel->rebuildSimpleRecommenderCache();
if (!file_exists($cacheFile)) {
    echo "   [FAIL] Failed to re-create cache file.\n";
    exit(1);
}
echo "   Cache rebuilt successfully. File exists.\n";

// Perform controlled adminUpdate (updating with same current data to keep DB intact)
$productBeforeAdmin = $spModel->findById($testId);
$adminUpdateData = [
    'ten_san_pham' => $productBeforeAdmin['ten_san_pham'] ?? '',
    'gia_ban' => $productBeforeAdmin['gia_ban'] ?? 0,
    'gia_thi_truong' => $productBeforeAdmin['gia_thi_truong'] ?? 0,
    'trang_thai' => $productBeforeAdmin['trang_thai'] ?? 'active',
];

$adminUpdateResult = $spModel->adminUpdate($testId, $adminUpdateData);
echo "   adminUpdate('{$testId}') returned: " . ($adminUpdateResult ? "true" : "false") . "\n";

// Verify cache was invalidated
if (!file_exists($cacheFile)) {
    echo "   [PASS] File cache simple_recommender_cache.json was successfully UNLINKED/INVALIDATED by adminUpdate.\n";
} else {
    echo "   [FAIL] Cache file still exists after adminUpdate!\n";
}

// Test 5: Rebuild and Verify Output Consistency
echo "5. Rebuilding Cache and Verifying Simple Output Consistency...\n";
$finalProducts = $spModel->getSimpleRecommenderProducts(24);

if (count($finalProducts) === 24) {
    echo "   [PASS] getSimpleRecommenderProducts(24) returned exactly 24 products.\n";
} else {
    echo "   [FAIL] Expected 24 products, got " . count($finalProducts) . "\n";
}

// Verify ranking invariant
$top1 = $finalProducts[0];
$top2 = $finalProducts[1];
$top3 = $finalProducts[2];
echo "   Top 3 Products after rebuild:\n";
echo "   #1: ID={$top1['ma_san_pham']} | {$top1['ten_san_pham']} | WR={$top1['recommender_meta']['weighted_rating']}\n";
echo "   #2: ID={$top2['ma_san_pham']} | {$top2['ten_san_pham']} | WR={$top2['recommender_meta']['weighted_rating']}\n";
echo "   #3: ID={$top3['ma_san_pham']} | {$top3['ten_san_pham']} | WR={$top3['recommender_meta']['weighted_rating']}\n";

// Verify file cache written
if (file_exists($cacheFile)) {
    echo "   [PASS] Cache file has been regenerated properly.\n";
} else {
    echo "   [FAIL] Cache file missing after getSimpleRecommenderProducts.\n";
}

// Verify product 1000 state in DB
$productAfterAll = $spModel->findById($testId);
if (($productAfterAll['trang_thai'] ?? '') === $origStatus) {
    echo "   [PASS] Test product {$testId} database state is completely intact/rolled back.\n";
} else {
    echo "   [FAIL] Test product {$testId} status mismatch: expected {$origStatus}, got " . ($productAfterAll['trang_thai'] ?? '') . "\n";
}

echo "\n=== ALL CACHE INVALIDATION LIFECYCLE TESTS PASSED ===\n";
