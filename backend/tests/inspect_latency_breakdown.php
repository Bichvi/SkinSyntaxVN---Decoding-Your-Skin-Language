<?php
// backend/tests/inspect_latency_breakdown.php
// Precision Latency Breakdown for Phase P2.3: Valkey TLS vs Atlas vs PHP Engine

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CacheService.php';
$helpersPath = is_file(__DIR__ . '/../../frontend/views/helpers.php')
    ? __DIR__ . '/../../frontend/views/helpers.php'
    : '/var/www/frontend/views/helpers.php';
if (is_file($helpersPath)) {
    require_once $helpersPath;
}
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/models/FlashSaleCampaign.php';
require_once __DIR__ . '/../app/controllers/HomeController.php';
require_once __DIR__ . '/../app/controllers/SanPhamController.php';

function median(array $arr): float {
    if (empty($arr)) return 0.0;
    sort($arr);
    $n = count($arr);
    $mid = (int)floor($n / 2);
    return $n % 2 !== 0 ? (float)$arr[$mid] : (float)(($arr[$mid - 1] + $arr[$mid]) / 2);
}

function mean(array $arr): float {
    return empty($arr) ? 0.0 : round(array_sum($arr) / count($arr), 2);
}

echo "================================================================================\n";
echo "SKINSYNTAXVN — PHASE P2.3 PRECISION LATENCY BREAKDOWN (30 SAMPLES EACH)\n";
echo "================================================================================\n\n";

$samples = 30;

// -----------------------------------------------------------------------------
// 1. VALKEY CONNECTION OVERHEAD (TCP CONNECT + TLS HANDSHAKE)
// -----------------------------------------------------------------------------
echo "1. Đo đạc TLS Handshake & Kết nối Predis tới Aiven Valkey...\n";
$valkeyUrl = VALKEY_URL;
$parsed = parse_url($valkeyUrl);
$host = $parsed['host'] ?? '';
$port = $parsed['port'] ?? 6379;

$tlsTimes = [];
for ($i = 0; $i < $samples; $i++) {
    $t0 = microtime(true);
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $sock = @stream_socket_client("tls://{$host}:{$port}", $errno, $errstr, 5.0, STREAM_CLIENT_CONNECT, $context);
    $dur = (microtime(true) - $t0) * 1000;
    if ($sock) {
        $tlsTimes[] = $dur;
        fclose($sock);
    }
}
$medianTls = median($tlsTimes);
$avgTls = mean($tlsTimes);
echo sprintf("   TLS Handshake RTT: Median = %.2f ms | Avg = %.2f ms | Min = %.2f ms | Max = %.2f ms\n",
    $medianTls, $avgTls, min($tlsTimes), max($tlsTimes));

// -----------------------------------------------------------------------------
// 2. WARM GET / MGET LATENCY TRÊN VALKEY (ĐÃ KẾT NỐI SẴN)
// -----------------------------------------------------------------------------
echo "\n2. Đo đạc Warm GET / MGET / PING trên Aiven Valkey...\n";
$cache = CacheService::getInstance();

// Ping latency
$pingTimes = [];
for ($i = 0; $i < $samples; $i++) {
    $t0 = microtime(true);
    $cache->ping();
    $pingTimes[] = (microtime(true) - $t0) * 1000;
}
echo sprintf("   Valkey PING (Pure Network RTT): Median = %.2f ms | Avg = %.2f ms\n", median($pingTimes), mean($pingTimes));

// GET home:composite_public latency
// Ensure key exists
(new HomeController($pdo))->getHomepagePublicData();
$getCompositeTimes = [];
$compositePayload = null;
for ($i = 0; $i < $samples; $i++) {
    $cache->clearL1(); // Force reading through remote TLS
    $t0 = microtime(true);
    $compositePayload = $cache->get('home:composite_public');
    $getCompositeTimes[] = (microtime(true) - $t0) * 1000;
}
$payloadSizeKb = round(strlen(json_encode($compositePayload)) / 1024, 2);
echo sprintf("   Valkey GET home:composite_public (%s KB): Median = %.2f ms | Avg = %.2f ms\n",
    $payloadSizeKb, median($getCompositeTimes), mean($getCompositeTimes));

// MGET 3 keys for PDP
$mgetTimes = [];
for ($i = 0; $i < $samples; $i++) {
    $cache->clearL1();
    $t0 = microtime(true);
    $cache->mget(['product:freq_bought:111', 'product:videos:111', 'menu:tree:14']);
    $mgetTimes[] = (microtime(true) - $t0) * 1000;
}
echo sprintf("   Valkey MGET (3 keys bundled): Median = %.2f ms | Avg = %.2f ms\n", median($mgetTimes), mean($mgetTimes));

// -----------------------------------------------------------------------------
// 3. MONGODB ATLAS QUERY LATENCY
// -----------------------------------------------------------------------------
echo "\n3. Đo đạc thời gian truy vấn trực tiếp MongoDB Atlas (Cloud TLS)...\n";

// Single indexed lookup: findOne(['ma_san_pham' => 111])
$findSingleTimes = [];
for ($i = 0; $i < $samples; $i++) {
    $t0 = microtime(true);
    $pdo->san_pham->findOne(['ma_san_pham' => 111]);
    $findSingleTimes[] = (microtime(true) - $t0) * 1000;
}
echo sprintf("   Atlas findOne(['ma_san_pham' => 111]) (Indexed): Median = %.2f ms | Avg = %.2f ms\n",
    median($findSingleTimes), mean($findSingleTimes));

// Category aggregation / pagination query (24 items)
$catTimes = [];
for ($i = 0; $i < $samples; $i++) {
    $t0 = microtime(true);
    $cursor = $pdo->san_pham->find(['trang_thai' => 'active'], ['limit' => 24, 'sort' => ['ngay_tao' => -1]]);
    iterator_to_array($cursor);
    $catTimes[] = (microtime(true) - $t0) * 1000;
}
echo sprintf("   Atlas find (24 active products, sorted): Median = %.2f ms | Avg = %.2f ms\n",
    median($catTimes), mean($catTimes));

// Homepage 6 authoritative queries in sequence (un-cached)
$homeMongoTotalTimes = [];
for ($i = 0; $i < $samples; $i++) {
    $t0 = microtime(true);
    // 1. menuTree
    (new SanPham($pdo))->menuTree();
    // 2. latest
    (new SanPham($pdo))->latest(12, true, true);
    // 3. flash_deals
    (new SanPham($pdo))->getFlashSaleProducts(8);
    // 4. primary_campaign
    (new FlashSaleCampaign($pdo))->getPrimaryActiveCampaign();
    // 5. next transition
    (new FlashSaleCampaign($pdo))->getNextCampaignTransitionTimestamp();
    $homeMongoTotalTimes[] = (microtime(true) - $t0) * 1000;
}
echo sprintf("   Atlas Homepage full authoritative queries (All 5 parts): Median = %.2f ms | Avg = %.2f ms\n",
    median($homeMongoTotalTimes), mean($homeMongoTotalTimes));

// -----------------------------------------------------------------------------
// 4. PHP JSON SERIALIZATION / DESERIALIZATION OVERHEAD
// -----------------------------------------------------------------------------
echo "\n4. Đo đạc chi phí JSON Encode / Decode trong PHP...\n";
$rawJson = json_encode($compositePayload, JSON_UNESCAPED_UNICODE);
$jsonEncodeTimes = [];
$jsonDecodeTimes = [];

for ($i = 0; $i < $samples; $i++) {
    $t0 = microtime(true);
    json_encode($compositePayload, JSON_UNESCAPED_UNICODE);
    $jsonEncodeTimes[] = (microtime(true) - $t0) * 1000;

    $t1 = microtime(true);
    json_decode($rawJson, true);
    $jsonDecodeTimes[] = (microtime(true) - $t1) * 1000;
}
echo sprintf("   json_encode(%s KB): Median = %.3f ms | Avg = %.3f ms\n", $payloadSizeKb, median($jsonEncodeTimes), mean($jsonEncodeTimes));
echo sprintf("   json_decode(%s KB): Median = %.3f ms | Avg = %.3f ms\n", $payloadSizeKb, median($jsonDecodeTimes), mean($jsonDecodeTimes));

// -----------------------------------------------------------------------------
// 5. TEMPLATE RENDERING TIME (PHP VIEW ENGINE)
// -----------------------------------------------------------------------------
echo "\n5. Đo đạc thời gian Render HTML Template (View Generation)...\n";

$homeCtrl = new HomeController($pdo);
$renderHomeTimes = [];
for ($i = 0; $i < $samples; $i++) {
    ob_start();
    $t0 = microtime(true);
    $homeCtrl->index();
    $renderHomeTimes[] = (microtime(true) - $t0) * 1000;
    ob_end_clean();
}
echo sprintf("   HomeController::index() execution: Median = %.2f ms | Avg = %.2f ms\n",
    median($renderHomeTimes), mean($renderHomeTimes));

$spCtrl = new SanPhamController($pdo);
$renderTatCaTimes = [];
for ($i = 0; $i < $samples; $i++) {
    ob_start();
    $t0 = microtime(true);
    $spCtrl->tatca();
    $renderTatCaTimes[] = (microtime(true) - $t0) * 1000;
    ob_end_clean();
}
echo sprintf("   SanPhamController::tatca() execution: Median = %.2f ms | Avg = %.2f ms\n",
    median($renderTatCaTimes), mean($renderTatCaTimes));

$renderChiTietTimes = [];
$_GET['id'] = '111';
for ($i = 0; $i < $samples; $i++) {
    ob_start();
    $t0 = microtime(true);
    $spCtrl->chitiet();
    $renderChiTietTimes[] = (microtime(true) - $t0) * 1000;
    ob_end_clean();
}
echo sprintf("   SanPhamController::chitiet() execution: Median = %.2f ms | Avg = %.2f ms\n",
    median($renderChiTietTimes), mean($renderChiTietTimes));

$summaryJson = [
    'tls_handshake_ms' => ['median' => $medianTls, 'avg' => $avgTls],
    'valkey_ping_ms' => ['median' => median($pingTimes), 'avg' => mean($pingTimes)],
    'valkey_get_composite_ms' => ['median' => median($getCompositeTimes), 'avg' => mean($getCompositeTimes), 'size_kb' => $payloadSizeKb],
    'valkey_mget_ms' => ['median' => median($mgetTimes), 'avg' => mean($mgetTimes)],
    'atlas_find_single_ms' => ['median' => median($findSingleTimes), 'avg' => mean($findSingleTimes)],
    'atlas_find_catalog_ms' => ['median' => median($catTimes), 'avg' => mean($catTimes)],
    'atlas_homepage_full_queries_ms' => ['median' => median($homeMongoTotalTimes), 'avg' => mean($homeMongoTotalTimes)],
    'php_json_encode_ms' => ['median' => median($jsonEncodeTimes), 'avg' => mean($jsonEncodeTimes)],
    'php_json_decode_ms' => ['median' => median($jsonDecodeTimes), 'avg' => mean($jsonDecodeTimes)],
    'home_execution_ms' => ['median' => median($renderHomeTimes), 'avg' => mean($renderHomeTimes)],
    'category_execution_ms' => ['median' => median($renderTatCaTimes), 'avg' => mean($renderTatCaTimes)],
    'pdp_execution_ms' => ['median' => median($renderChiTietTimes), 'avg' => mean($renderChiTietTimes)],
];

@file_put_contents('/var/www/html/experiments/performance/aiven_valkey/p2_3/latency_breakdown.json', json_encode($summaryJson, JSON_PRETTY_PRINT));
@file_put_contents(dirname(__DIR__) . '/experiments/performance/aiven_valkey/p2_3/latency_breakdown.json', json_encode($summaryJson, JSON_PRETTY_PRINT));
echo "\nSaved breakdown to backend/experiments/performance/aiven_valkey/p2_3/latency_breakdown.json\n";
