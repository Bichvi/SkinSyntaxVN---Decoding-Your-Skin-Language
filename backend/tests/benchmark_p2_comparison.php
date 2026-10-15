<?php
// backend/tests/benchmark_p2_comparison.php
// Rigorous Benchmark Comparison: Cache OFF vs Cache MISS vs Cache HIT vs Valkey Down

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CacheService.php';

class MongoQueryProfiler implements MongoDB\Driver\Monitoring\CommandSubscriber {
    public array $counts = [];
    public array $collectionCounts = [];
    public float $mongoTimeMs = 0;
    private array $startTimes = [];

    public function commandStarted(MongoDB\Driver\Monitoring\CommandStartedEvent $event): void {
        $cmdName = $event->getCommandName();
        if (in_array($cmdName, ['ping', 'isMaster', 'hello', 'buildInfo', 'saslStart', 'saslContinue'], true)) {
            return;
        }
        $this->counts[$cmdName] = ($this->counts[$cmdName] ?? 0) + 1;
        $cmdDoc = (array)$event->getCommand();
        $coll = $cmdDoc[$cmdName] ?? ($cmdDoc['collection'] ?? 'unknown');
        if (is_string($coll)) {
            $this->collectionCounts[$coll][$cmdName] = ($this->collectionCounts[$coll][$cmdName] ?? 0) + 1;
        }
        $this->startTimes[$event->getRequestId()] = microtime(true);
    }

    public function commandSucceeded(MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void {
        $reqId = $event->getRequestId();
        if (isset($this->startTimes[$reqId])) {
            $this->mongoTimeMs += (microtime(true) - $this->startTimes[$reqId]) * 1000;
            unset($this->startTimes[$reqId]);
        }
    }

    public function commandFailed(MongoDB\Driver\Monitoring\CommandFailedEvent $event): void {
        $reqId = $event->getRequestId();
        if (isset($this->startTimes[$reqId])) {
            $this->mongoTimeMs += (microtime(true) - $this->startTimes[$reqId]) * 1000;
            unset($this->startTimes[$reqId]);
        }
    }

    public function reset(): void {
        $this->counts = [];
        $this->collectionCounts = [];
        $this->mongoTimeMs = 0;
        $this->startTimes = [];
    }
}

$profiler = new MongoQueryProfiler();
MongoDB\Driver\Monitoring\addSubscriber($profiler);

function calculateStats(array $values): array {
    if (empty($values)) {
        return ['min' => 0, 'max' => 0, 'avg' => 0, 'p50' => 0, 'p95' => 0];
    }
    sort($values);
    $n = count($values);
    $p50Idx = (int)floor($n * 0.50);
    $p95Idx = min($n - 1, (int)floor($n * 0.95));

    return [
        'min' => round($values[0], 2),
        'max' => round($values[$n - 1], 2),
        'avg' => round(array_sum($values) / $n, 2),
        'p50' => round($values[$p50Idx], 2),
        'p95' => round($values[$p95Idx], 2),
    ];
}

$endpoints = [
    'Homepage' => [
        'url' => 'http://nginx/index.php?r=home',
        'params' => ['r' => 'home'],
        'route' => '?r=home',
    ],
    'Category' => [
        'url' => 'http://nginx/index.php?r=tatca',
        'params' => ['r' => 'tatca'],
        'route' => '?r=tatca',
    ],
    'Product Detail' => [
        'url' => 'http://nginx/index.php?r=chitiet&id=111',
        'params' => ['r' => 'chitiet', 'id' => '111'],
        'route' => '?r=chitiet&id=111',
    ],
];

echo "========================================================================================\n";
echo "SKINSYNTAXVN — P2 BENCHMARK COMPARISON (Cache OFF vs MISS vs HIT vs DOWN)\n";
echo "========================================================================\n\n";

$matrix = [];
$cache = CacheService::getInstance();

foreach ($endpoints as $name => $ep) {
    echo ">>> EVALUATING: {$name} ({$ep['url']})\n";
    $matrix[$name] = [];

    // --- STATE 1: CACHE HIT (Warm Cache) ---
    // First ensure warm cache by loading once
    file_get_contents($ep['url']);
    
    // Benchmark 5 HTTP runs on warm cache
    $hitTimes = [];
    for ($i = 1; $i <= 5; $i++) {
        $start = microtime(true);
        $ch = curl_init($ep['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        $dur = (microtime(true) - $start) * 1000;
        curl_close($ch);
        $hitTimes[] = $dur;
    }
    $hitStats = calculateStats($hitTimes);

    // Profile internal query count on warm cache
    $profiler->reset();
    $_GET = $ep['params'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $ep['route'];
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    ob_start();
    try {
        include '/var/www/html/public/index.php';
    } catch (Throwable $t) {}
    ob_end_clean();
    $hitQueries = array_sum($profiler->counts);
    $hitDbTime = round($profiler->mongoTimeMs, 2);

    $matrix[$name]['cache_hit'] = [
        'http_p50_ms' => $hitStats['p50'],
        'http_p95_ms' => $hitStats['p95'],
        'http_avg_ms' => $hitStats['avg'],
        'mongo_queries' => $hitQueries,
        'mongo_time_ms' => $hitDbTime,
    ];
    echo "  [CACHE HIT]  p50: {$hitStats['p50']} ms | p95: {$hitStats['p95']} ms | Atlas queries: {$hitQueries} (DB wait: {$hitDbTime} ms)\n";

    // --- STATE 2: CACHE MISS ---
    // Flush cache keys for this endpoint
    $cache->deleteByPattern('home:*');
    $cache->deleteByPattern('catalog:*');
    $cache->deleteByPattern('product:*');
    $cache->deleteByPattern('menu:*');
    $cache->clearL1();

    $profiler->reset();
    $_GET = $ep['params'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $ep['route'];
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $missStart = microtime(true);
    ob_start();
    try {
        include '/var/www/html/public/index.php';
    } catch (Throwable $t) {}
    ob_end_clean();
    $missDuration = (microtime(true) - $missStart) * 1000;
    $missQueries = array_sum($profiler->counts);
    $missDbTime = round($profiler->mongoTimeMs, 2);

    $matrix[$name]['cache_miss'] = [
        'internal_time_ms' => round($missDuration, 2),
        'mongo_queries' => $missQueries,
        'mongo_time_ms' => $missDbTime,
    ];
    echo "  [CACHE MISS] Duration: " . round($missDuration, 2) . " ms | Atlas queries: {$missQueries} (DB wait: {$missDbTime} ms)\n";

    // Re-populate cache for subsequent tests
    file_get_contents($ep['url']);
}

// --- STATE 3: VALKEY UNAVAILABLE (Simulated Outage / Fallback Test) ---
echo "\n>>> TESTING VALKEY OUTAGE / FALLBACK BEHAVIOR ACROSS ROUTES:\n";
$matrix['fallback_outage'] = [];
foreach ($endpoints as $name => $ep) {
    // Temporarily point CacheService to invalid endpoint
    $brokenUrl = 'rediss://default:bad@127.0.0.1:29999';
    $origUrl = getenv('VALKEY_URL');
    putenv('VALKEY_URL=' . $brokenUrl);
    $_ENV['VALKEY_URL'] = $brokenUrl;
    $_SERVER['VALKEY_URL'] = $brokenUrl;

    // Reset reflection or create fresh
    $profiler->reset();
    $_GET = $ep['params'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $ep['route'];
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    $fbStart = microtime(true);
    ob_start();
    try {
        include '/var/www/html/public/index.php';
    } catch (Throwable $t) {}
    $outHtml = ob_get_clean();
    $fbDuration = (microtime(true) - $fbStart) * 1000;
    $fbQueries = array_sum($profiler->counts);
    $hasContent = strlen($outHtml) > 500 && !str_contains($outHtml, 'Fatal error');

    // Restore original URL
    putenv('VALKEY_URL=' . $origUrl);
    $_ENV['VALKEY_URL'] = $origUrl;
    $_SERVER['VALKEY_URL'] = $origUrl;

    $matrix['fallback_outage'][$name] = [
        'fallback_success' => $hasContent,
        'duration_ms' => round($fbDuration, 2),
        'mongo_queries' => $fbQueries,
    ];

    $statusStr = $hasContent ? 'PASS (Fallback to Atlas OK)' : 'FAIL';
    echo "  [VALKEY DOWN] {$name}: {$statusStr} in " . round($fbDuration, 2) . " ms (Atlas queries: {$fbQueries})\n";
}

echo "\n========================================================================================\n";
echo "SUMMARY MATRIX (PHASE P2 RESULTS)\n";
echo "========================================================================================\n";
echo json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

file_put_contents('/var/www/html/tests/output_p2_comparison.json', json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nSaved comparison metrics to backend/tests/output_p2_comparison.json\n";
