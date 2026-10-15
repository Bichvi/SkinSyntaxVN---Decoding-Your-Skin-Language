<?php
// backend/tests/test_cache_service_p1.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/services/CacheService.php';

echo "========================================================\n";
echo "SKINSYNTAXVN — P1 CACHE SERVICE & FALLBACK VERIFICATION\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $message): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] " . $message . "\n";
        $passCount++;
    } else {
        echo "[FAIL] " . $message . "\n";
        $failCount++;
    }
}

// 1. Singleton & Safe Status
$cache = CacheService::getInstance();
$status = $cache->getSafeStatus();

assertTest($status['driver'] === 'valkey', "Driver is 'valkey'");
assertTest($status['tls_enabled'] === true, "TLS is enabled (scheme: " . $status['scheme'] . ")");
assertTest(!empty($status['host']) && $status['host'] !== 'none', "Host is configured: " . $status['host']);
assertTest(!isset($status['password']), "Password is NOT exposed in safe status");

// 2. Connectivity & PING
$startPing = microtime(true);
$pingResult = $cache->ping();
$pingDuration = round((microtime(true) - $startPing) * 1000, 2);
assertTest($pingResult === true, "Valkey PING succeeded in {$pingDuration} ms");
assertTest($cache->isAvailable() === true, "CacheService reports isAvailable() === true");

// 3. SET & GET with complex payload
$testKey = 'qa_p1_test_' . bin2hex(random_bytes(4));
$complexData = [
    'id' => 9999,
    'name' => 'SkinSyntax Serum B5',
    'price' => 350000,
    'tags' => ['phuc_hoi', 'cap_am'],
    'in_stock' => true,
    'meta' => ['timestamp' => time()]
];

$setResult = $cache->set($testKey, $complexData, 30);
assertTest($setResult === true, "Cache SET completed with 30s TTL");

$getData = $cache->get($testKey);
assertTest(is_array($getData) && $getData['id'] === 9999 && $getData['price'] === 350000, "Cache GET retrieved identical complex array");

// 4. HAS & DELETE
assertTest($cache->has($testKey) === true, "Cache HAS returns true for existing key");
$delResult = $cache->delete($testKey);
assertTest($delResult === true, "Cache DELETE returned true");
assertTest($cache->get($testKey, 'missing') === 'missing', "Cache GET after DELETE returns default fallback value");
assertTest($cache->has($testKey) === false, "Cache HAS returns false after deletion");

// 5. REMEMBER pattern
$rememberKey = 'qa_p1_remember_' . bin2hex(random_bytes(4));
$callbackCallCount = 0;
$dummyCallback = function() use (&$callbackCallCount) {
    $callbackCallCount++;
    return ['source' => 'authoritative_db', 'count' => $callbackCallCount];
};

// First call: MISS -> should invoke callback and cache
$result1 = $cache->remember($rememberKey, 30, $dummyCallback);
assertTest($result1['source'] === 'authoritative_db' && $callbackCallCount === 1, "Cache MISS executed callback from authoritative DB");

// Second call: HIT -> should return cached data WITHOUT executing callback
$result2 = $cache->remember($rememberKey, 30, $dummyCallback);
assertTest($result2['source'] === 'authoritative_db' && $callbackCallCount === 1, "Cache HIT returned cached result without re-executing callback (count still 1)");

// Clean up remember key
$cache->delete($rememberKey);

// 6. PATTERN DELETION (SCAN-based)
$patPrefix = 'qa_p1_batch_' . bin2hex(random_bytes(3));
$cache->set($patPrefix . '_1', 'val1', 30);
$cache->set($patPrefix . '_2', 'val2', 30);
$cache->set($patPrefix . '_3', 'val3', 30);

$deletedCount = $cache->deleteByPattern($patPrefix . '*');
assertTest($deletedCount >= 3, "deleteByPattern safely removed batch keys via SCAN (deleted: {$deletedCount})");
assertTest($cache->get($patPrefix . '_1') === null, "Batch key 1 verified deleted");

// 7. FALLBACK RESILIENCE TEST
echo "\n--- TESTING FALLBACK RESILIENCE (Simulating Valkey down) ---\n";
// Create CacheService instance with broken/unreachable URL
$brokenCache = new CacheService('valkey', 'rediss://default:badpass@127.0.0.1:29999', 'skinsyntax_test:', 300);

// Should report not available without throwing uncaught fatal error
assertTest($brokenCache->isAvailable() === false, "Broken CacheService reports isAvailable() === false gracefully");
assertTest($brokenCache->ping() === false, "Broken CacheService ping() returns false without throwing exception");

// get() on broken service returns default
$fallbackGet = $brokenCache->get('any_key', 'safe_fallback_value');
assertTest($fallbackGet === 'safe_fallback_value', "Broken CacheService get() gracefully returns default fallback");

// remember() on broken service executes callback directly and returns data
$dbFallbackCalled = false;
$fallbackResult = $brokenCache->remember('any_key', 300, function() use (&$dbFallbackCalled) {
    $dbFallbackCalled = true;
    return ['authoritative_mongo' => true, 'timestamp' => time()];
});
assertTest($dbFallbackCalled === true && ($fallbackResult['authoritative_mongo'] ?? false) === true, "Broken CacheService remember() gracefully falls back to authoritative callback");

// 8. METRICS
$metrics = $cache->getMetrics();
echo "\n--- CACHE METRICS ---\n";
echo "Hits: {$metrics['hits']}, Misses: {$metrics['misses']}, Writes: {$metrics['writes']}, Hit Ratio: {$metrics['hit_ratio']}\n";
assertTest($metrics['hits'] >= 1 && $metrics['writes'] >= 1, "Cache metrics track hits and writes correctly");

echo "\n========================================================\n";
echo "RESULT: {$passCount} PASSED, {$failCount} FAILED\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
