<?php
// backend/tests/test_invalidation_p2.php
// Verification of Cache Invalidation on Admin Updates

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CacheService.php';
require_once __DIR__ . '/../app/models/SanPham.php';

echo "========================================================================\n";
echo "SKINSYNTAXVN — P2 CACHE INVALIDATION & LIFECYCLE VERIFICATION\n";
echo "========================================================================\n\n";

$cache = CacheService::getInstance();
$model = new SanPham($pdo);

$testProductId = '111';

// Step 1: Pre-populate cache keys
$cache->set("product:detail:{$testProductId}", ['name' => 'Original Cached Name', 'price' => 100000], 3600);
$cache->set("home:latest:12:1:1", [['id' => $testProductId, 'name' => 'Original']], 3600);
$cache->set("catalog:tatca:test_hash_123", ['items' => [['id' => $testProductId]], 'total' => 1], 3600);
$cache->set("menu:tree:14", ['Skincare' => ['total_count' => 5]], 3600);

echo "Step 1: Keys populated in Valkey:\n";
echo "  - product:detail:{$testProductId} -> " . ($cache->has("product:detail:{$testProductId}") ? 'EXISTS' : 'MISSING') . "\n";
echo "  - home:latest:12:1:1 -> " . ($cache->has("home:latest:12:1:1") ? 'EXISTS' : 'MISSING') . "\n";
echo "  - catalog:tatca:test_hash_123 -> " . ($cache->has("catalog:tatca:test_hash_123") ? 'EXISTS' : 'MISSING') . "\n";
echo "  - menu:tree:14 -> " . ($cache->has("menu:tree:14") ? 'EXISTS' : 'MISSING') . "\n\n";

// Step 2: Trigger invalidation hook
echo "Step 2: Triggering SanPham::invalidateProductCache('{$testProductId}')...\n";
SanPham::invalidateProductCache($testProductId);

// Step 3: Assert eviction
$hasProduct = $cache->has("product:detail:{$testProductId}");
$hasHome = $cache->has("home:latest:12:1:1");
$hasCatalog = $cache->has("catalog:tatca:test_hash_123");
$hasMenu = $cache->has("menu:tree:14");

$allEvicted = (!$hasProduct && !$hasHome && !$hasCatalog && !$hasMenu);

echo "Step 3: Verification post-invalidation:\n";
echo "  - product:detail:{$testProductId} evicted: " . (!$hasProduct ? '[PASS]' : '[FAIL]') . "\n";
echo "  - home:* keys evicted: " . (!$hasHome ? '[PASS]' : '[FAIL]') . "\n";
echo "  - catalog:* keys evicted: " . (!$hasCatalog ? '[PASS]' : '[FAIL]') . "\n";
echo "  - menu:* keys evicted: " . (!$hasMenu ? '[PASS]' : '[FAIL]') . "\n\n";

if ($allEvicted) {
    echo "========================================================================\n";
    echo "RESULT: ALL CACHE INVALIDATION ASSERTIONS PASSED\n";
    echo "========================================================================\n";
    exit(0);
} else {
    echo "========================================================================\n";
    echo "RESULT: FAILED TO EVICT ALL KEYS\n";
    echo "========================================================================\n";
    exit(1);
}
