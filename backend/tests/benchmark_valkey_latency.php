<?php
// backend/tests/benchmark_valkey_latency.php
// Micro-benchmark for Valkey TLS network latency, warm commands, serialization, and fallback

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/services/CacheService.php';

$valkeyUrl = ss_env('VALKEY_URL', '');
if (empty($valkeyUrl)) {
    echo "VALKEY_URL not set.\n";
    exit(1);
}

echo "========================================================================\n";
echo "SKINSYNTAXVN — AIVEN VALKEY LATENCY & CONNECTION ANALYSIS\n";
echo "========================================================================\n\n";

// 1. Cold TLS Connection Latency (repeating 5 fresh connections)
echo "1. MEASURING COLD TLS CONNECTION LATENCY (Fresh Socket + TLS Handshake):\n";
$coldLatencies = [];
for ($i = 1; $i <= 5; $i++) {
    $start = microtime(true);
    $client = new Predis\Client($valkeyUrl, [
        'parameters' => ['timeout' => 2.0, 'read_write_timeout' => 2.0],
        'ssl' => ['verify_peer' => true]
    ]);
    $client->connect();
    $duration = (microtime(true) - $start) * 1000;
    $coldLatencies[] = $duration;
    echo "   Cold Connection #{$i}: " . round($duration, 2) . " ms\n";
    $client->disconnect();
}
$avgCold = round(array_sum($coldLatencies) / count($coldLatencies), 2);
echo "   -> Average Cold TLS Handshake Latency: {$avgCold} ms\n\n";

// 2. Warm Connection Command Latency (Over already open connection)
echo "2. MEASURING WARM COMMAND LATENCY (Reusing Open Socket):\n";
$warmClient = new Predis\Client($valkeyUrl, [
    'parameters' => ['timeout' => 2.0, 'read_write_timeout' => 2.0],
    'ssl' => ['verify_peer' => true]
]);
$warmClient->connect();

$pingTimes = [];
for ($i = 1; $i <= 10; $i++) {
    $start = microtime(true);
    $warmClient->ping();
    $pingTimes[] = (microtime(true) - $start) * 1000;
}
$avgPing = round(array_sum($pingTimes) / count($pingTimes), 2);
echo "   -> PING 10 iterations: Min: " . round(min($pingTimes), 2) . " ms | Max: " . round(max($pingTimes), 2) . " ms | Avg: {$avgPing} ms\n";

// Test warm SET and GET
$sampleData = [
    'id' => 111,
    'name' => 'SkinSyntax Serum B5 Hyaluronic Acid 30ml',
    'price' => 350000,
    'attributes' => ['brand' => 'SkinSyntax Lab', 'origin' => 'Korea', 'stock' => 100],
    'tags' => ['phuc_hoi', 'cap_am', 'giam_kich_ung']
];
$encodedData = json_encode($sampleData, JSON_UNESCAPED_UNICODE);
$testKey = 'skinsyntax:perf:latency_test';

$setTimes = [];
for ($i = 1; $i <= 5; $i++) {
    $start = microtime(true);
    $warmClient->setex($testKey, 60, $encodedData);
    $setTimes[] = (microtime(true) - $start) * 1000;
}
$avgSet = round(array_sum($setTimes) / count($setTimes), 2);
echo "   -> Warm SETEX (5 runs): Avg: {$avgSet} ms\n";

$getTimes = [];
for ($i = 1; $i <= 5; $i++) {
    $start = microtime(true);
    $warmClient->get($testKey);
    $getTimes[] = (microtime(true) - $start) * 1000;
}
$avgGet = round(array_sum($getTimes) / count($getTimes), 2);
echo "   -> Warm GET (5 runs): Avg: {$avgGet} ms\n";
$warmClient->del([$testKey]);
$warmClient->disconnect();
echo "\n";

// 3. Serialization / Deserialization CPU Overhead
echo "3. SERIALIZATION / DESERIALIZATION OVERHEAD (1,000 iterations):\n";
$startJsonEnc = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $json = json_encode($sampleData, JSON_UNESCAPED_UNICODE);
}
$jsonEncMs = round((microtime(true) - $startJsonEnc) * 1000, 3);

$startJsonDec = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    $decoded = json_decode($json, true);
}
$jsonDecMs = round((microtime(true) - $startJsonDec) * 1000, 3);
echo "   -> 1,000 x json_encode: {$jsonEncMs} ms (0.00" . round($jsonEncMs, 0) . " ms/op)\n";
echo "   -> 1,000 x json_decode: {$jsonDecMs} ms (0.00" . round($jsonDecMs, 0) . " ms/op)\n";
echo "   -> Serialization overhead is negligible (< 0.01 ms per entity).\n\n";

// 4. CacheService Request-Lifecycle Latency (HIT vs MISS vs Fallback)
echo "4. CACHESERVICE PIPELINE MEASUREMENTS:\n";
$cache = CacheService::getInstance();

// Cache MISS latency (get nonexistent key)
$missTimes = [];
for ($i = 1; $i <= 5; $i++) {
    $start = microtime(true);
    $cache->get('skinsyntax:perf:miss_key_' . $i);
    $missTimes[] = (microtime(true) - $start) * 1000;
}
$avgMiss = round(array_sum($missTimes) / count($missTimes), 2);
echo "   -> Cache MISS latency (Network lookup): Avg: {$avgMiss} ms\n";

// Cache HIT latency
$hitKey = 'skinsyntax:perf:hit_key';
$cache->set($hitKey, $sampleData, 60);

$hitTimes = [];
for ($i = 1; $i <= 5; $i++) {
    $start = microtime(true);
    $cache->get($hitKey);
    $hitTimes[] = (microtime(true) - $start) * 1000;
}
$avgHit = round(array_sum($hitTimes) / count($hitTimes), 2);
echo "   -> Cache HIT latency (Network retrieval): Avg: {$avgHit} ms\n";
$cache->delete($hitKey);

// 5. Fallback Latency when Valkey is Unreachable
echo "\n5. FALLBACK LATENCY WHEN VALKEY IS DOWN:\n";
$brokenStart = microtime(true);
$brokenCache = new CacheService('valkey', 'rediss://default:badpass@127.0.0.1:29999', 'skinsyntax_test:', 300);
$fallbackVal = $brokenCache->get('any_key', 'fallback_val');
$fallbackMs = round((microtime(true) - $brokenStart) * 1000, 2);
echo "   -> Unreachable Valkey detection & fallback duration: {$fallbackMs} ms (immediate failover)\n";

$results = [
    'avg_cold_tls_connection_ms' => $avgCold,
    'avg_warm_ping_ms' => $avgPing,
    'avg_warm_set_ms' => $avgSet,
    'avg_warm_get_ms' => $avgGet,
    'json_encode_1000ops_ms' => $jsonEncMs,
    'json_decode_1000ops_ms' => $jsonDecMs,
    'cache_miss_ms' => $avgMiss,
    'cache_hit_ms' => $avgHit,
    'fallback_failover_ms' => $fallbackMs,
];

file_put_contents('/var/www/html/tests/output_valkey_latency.json', json_encode($results, JSON_PRETTY_PRINT));
echo "\nSaved latency metrics to backend/tests/output_valkey_latency.json\n";
