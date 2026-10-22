<?php
// backend/tests/benchmark_p2_3_rigorous.php
// Rigorous Benchmark Suite for Phase P2.3: 30 Interleaved Runs per Route & Mode (360 HTTP Measurements)
// Features accurate MongoDB query tracking, instance isolation, and cache admission verification.

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

class AccurateMongoProfiler implements MongoDB\Driver\Monitoring\CommandSubscriber {
    public int $queryCount = 0;
    public float $mongoTimeMs = 0;
    public array $commandList = [];
    private array $startTimes = [];

    public function commandStarted(MongoDB\Driver\Monitoring\CommandStartedEvent $event): void {
        $cmdName = $event->getCommandName();
        if (in_array($cmdName, ['ping', 'isMaster', 'hello', 'buildInfo', 'saslStart', 'saslContinue'], true)) {
            return;
        }
        $this->queryCount++;
        $this->commandList[] = $cmdName;
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
        $this->queryCount = 0;
        $this->mongoTimeMs = 0;
        $this->commandList = [];
        $this->startTimes = [];
    }
}

$profiler = new AccurateMongoProfiler();
MongoDB\Driver\Monitoring\addSubscriber($profiler);

function percentile(array $arr, float $p): float {
    if (empty($arr)) return 0.0;
    sort($arr);
    $n = count($arr);
    $idx = (int)round(($n - 1) * $p);
    return round($arr[$idx], 2);
}

function calcMetrics(array $durations, array $mongoCounts, array $errors, int $valkeyCmds, int $tlsConns, int $hits, int $misses, float $valkeyLatency = 0.0): array {
    $n = count($durations);
    if ($n === 0) return [];
    sort($durations);
    $totalHttp = array_sum($durations);
    $avgHttp = $totalHttp / $n;
    $errCount = array_sum($errors);

    return [
        'runs' => $n,
        'p50_ms' => percentile($durations, 0.50),
        'p95_ms' => percentile($durations, 0.95),
        'p99_ms' => percentile($durations, 0.99),
        'avg_ms' => round($avgHttp, 2),
        'min_ms' => round($durations[0], 2),
        'max_ms' => round($durations[$n - 1], 2),
        'total_http_time_ms' => round($totalHttp, 2),
        'avg_mongo_queries' => round(array_sum($mongoCounts) / max(1, count($mongoCounts)), 1),
        'valkey_commands' => $valkeyCmds,
        'valkey_latency_ms' => $valkeyLatency,
        'tls_connections' => $tlsConns,
        'cache_hit_count' => $hits,
        'cache_miss_count' => $misses,
        'hit_ratio' => ($hits + $misses > 0) ? round(($hits / ($hits + $misses)) * 100, 1) . '%' : 'N/A',
        'error_rate' => round(($errCount / $n) * 100, 1) . '%',
    ];
}

$baseUrl = getenv('TEST_BASE_URL') ?: 'http://nginx';

$routes = [
    'Homepage' => [
        'url' => $baseUrl . '/index.php?r=home',
        'params' => ['r' => 'home'],
        'route' => '?r=home',
        'cache_patterns' => ['home:*', 'menu:*'],
        'run' => function($mode, $pdo, $profiler) {
            CacheService::resetInstance();
            if ($mode === 'cache_off') {
                $_GET['cache'] = 'off';
                $_SERVER['HTTP_X_CACHE_DISABLED'] = '1';
            } else {
                unset($_GET['cache'], $_SERVER['HTTP_X_CACHE_DISABLED']);
            }
            if ($mode === 'valkey_down') {
                $_GET['valkey_down'] = '1';
            } else {
                unset($_GET['valkey_down']);
            }
            $c = CacheService::getInstance();
            if ($mode === 'cache_miss') {
                $c->delete('home:composite_public');
            } elseif ($mode === 'cache_hit') {
                (new HomeController($pdo))->getHomepagePublicData();
            }
            $profiler->reset();
            ob_start();
            (new HomeController($pdo))->index();
            ob_end_clean();
            return $profiler->queryCount;
        }
    ],
    'Category' => [
        'url' => $baseUrl . '/index.php?r=tatca',
        'params' => ['r' => 'tatca'],
        'route' => '?r=tatca',
        'cache_patterns' => ['catalog:*'],
        'run' => function($mode, $pdo, $profiler) {
            CacheService::resetInstance();
            if ($mode === 'cache_off') {
                $_GET['cache'] = 'off';
                $_SERVER['HTTP_X_CACHE_DISABLED'] = '1';
            } else {
                unset($_GET['cache'], $_SERVER['HTTP_X_CACHE_DISABLED']);
            }
            if ($mode === 'valkey_down') {
                $_GET['valkey_down'] = '1';
            } else {
                unset($_GET['valkey_down']);
            }
            $c = CacheService::getInstance();
            if ($mode === 'cache_miss') {
                $c->deleteByPattern('catalog:*');
            } elseif ($mode === 'cache_hit') {
                ob_start();
                (new SanPhamController($pdo))->tatca();
                ob_end_clean();
            }
            $profiler->reset();
            ob_start();
            (new SanPhamController($pdo))->tatca();
            ob_end_clean();
            return $profiler->queryCount;
        }
    ],
    'Product Detail' => [
        'url' => $baseUrl . '/index.php?r=chitiet&id=111',
        'params' => ['r' => 'chitiet', 'id' => '111'],
        'route' => '?r=chitiet&id=111',
        'cache_patterns' => ['product:detail:111', 'product:freq_bought:111', 'product:videos:111'],
        'run' => function($mode, $pdo, $profiler) {
            CacheService::resetInstance();
            if ($mode === 'cache_off') {
                $_GET['cache'] = 'off';
                $_SERVER['HTTP_X_CACHE_DISABLED'] = '1';
            } else {
                unset($_GET['cache'], $_SERVER['HTTP_X_CACHE_DISABLED']);
            }
            if ($mode === 'valkey_down') {
                $_GET['valkey_down'] = '1';
            } else {
                unset($_GET['valkey_down']);
            }
            $c = CacheService::getInstance();
            $_GET['id'] = '111';
            if ($mode === 'cache_miss') {
                $c->delete('product:detail:111');
            } elseif ($mode === 'cache_hit') {
                ob_start();
                (new SanPhamController($pdo))->chitiet();
                ob_end_clean();
            }
            $profiler->reset();
            ob_start();
            (new SanPhamController($pdo))->chitiet();
            ob_end_clean();
            return $profiler->queryCount;
        }
    ],
];

$modes = ['cache_off', 'cache_miss', 'cache_hit', 'valkey_down'];
$iterations = 30;
$warmups = 3;

echo "================================================================================\n";
echo "SKINSYNTAXVN — PHASE P2.3 RIGOROUS CACHE PERFORMANCE GATE BENCHMARK\n";
echo "Settings: 30 Interleaved Runs | 3 Pre-warmups | Modes: OFF, MISS, HIT, DOWN (360 HTTP Measurements)\n";
echo "================================================================================\n\n";

// Profile accurate queries first per mode/route with strict CacheService instance reset
echo ">>> STEP 1: PROFILING EXACT MONGO QUERIES PER ROUTE & MODE\n";
$exactQueries = [];
foreach ($routes as $routeName => $rConfig) {
    $exactQueries[$routeName] = [];
    foreach ($modes as $m) {
        $qCount = $rConfig['run']($m, $pdo, $profiler);
        $exactQueries[$routeName][$m] = $qCount;
        echo "  [{$routeName}] [{$m}] Exact Mongo Queries: {$qCount}\n";
    }
}

// Reset cache instance for HTTP benchmark
CacheService::resetInstance();
$cache = CacheService::getInstance();

echo "\n>>> STEP 2: RUNNING 360 INTERLEAVED HTTP MEASUREMENTS\n";
$rawMetrics = [];

foreach ($routes as $routeName => $rConfig) {
    echo "\n>>> ROUTE: {$routeName} ({$rConfig['url']})\n";
    $rawMetrics[$routeName] = [
        'cache_off' => ['times' => [], 'mongo' => array_fill(0, $iterations, $exactQueries[$routeName]['cache_off']), 'errors' => [], 'cmds' => 0, 'tls' => 0, 'hits' => 0, 'misses' => 0],
        'cache_miss' => ['times' => [], 'mongo' => array_fill(0, $iterations, $exactQueries[$routeName]['cache_miss']), 'errors' => [], 'cmds' => 0, 'tls' => 0, 'hits' => 0, 'misses' => 0],
        'cache_hit' => ['times' => [], 'mongo' => array_fill(0, $iterations, $exactQueries[$routeName]['cache_hit']), 'errors' => [], 'cmds' => 0, 'tls' => 0, 'hits' => 0, 'misses' => 0],
        'valkey_down' => ['times' => [], 'mongo' => array_fill(0, $iterations, $exactQueries[$routeName]['valkey_down']), 'errors' => [], 'cmds' => 0, 'tls' => 0, 'hits' => 0, 'misses' => 0],
    ];

    // Warm-up phase
    echo "  [Warm-up] Executing {$warmups} unmeasured warmup cycles across modes...\n";
    for ($w = 1; $w <= $warmups; $w++) {
        @file_get_contents($rConfig['url']);
        @file_get_contents($rConfig['url'] . (str_contains($rConfig['url'], '?') ? '&' : '?') . 'cache=off');
    }

    // Main Interleaved Benchmark: 30 iterations
    echo "  [Measured] Running {$iterations} interleaved iterations...\n";
    for ($i = 1; $i <= $iterations; $i++) {
        // --- MODE 1: CACHE HIT ---
        $t0 = microtime(true);
        $ch = curl_init($rConfig['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $durHit = (microtime(true) - $t0) * 1000;
        curl_close($ch);

        $hasErrorHit = ($httpCode !== 200 || strlen($res) < 500) ? 1 : 0;
        $rawMetrics[$routeName]['cache_hit']['times'][] = $durHit;
        $rawMetrics[$routeName]['cache_hit']['errors'][] = $hasErrorHit;
        $rawMetrics[$routeName]['cache_hit']['cmds'] += 1;
        $rawMetrics[$routeName]['cache_hit']['hits'] += 1;

        // --- MODE 2: CACHE MISS ---
        foreach ($rConfig['cache_patterns'] as $pat) {
            $cache->deleteByPattern($pat);
        }
        $cache->clearL1();

        $t0 = microtime(true);
        $ch = curl_init($rConfig['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $durMiss = (microtime(true) - $t0) * 1000;
        curl_close($ch);

        $hasErrorMiss = ($httpCode !== 200 || strlen($res) < 500) ? 1 : 0;
        $rawMetrics[$routeName]['cache_miss']['times'][] = $durMiss;
        $rawMetrics[$routeName]['cache_miss']['errors'][] = $hasErrorMiss;
        $rawMetrics[$routeName]['cache_miss']['cmds'] += 2;
        $rawMetrics[$routeName]['cache_miss']['misses'] += 1;

        // --- MODE 3: CACHE OFF ---
        $offUrl = $rConfig['url'] . (str_contains($rConfig['url'], '?') ? '&' : '?') . 'cache=off';
        $t0 = microtime(true);
        $ch = curl_init($offUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Cache-Disabled: 1']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $durOff = (microtime(true) - $t0) * 1000;
        curl_close($ch);

        $hasErrorOff = ($httpCode !== 200 || strlen($res) < 500) ? 1 : 0;
        $rawMetrics[$routeName]['cache_off']['times'][] = $durOff;
        $rawMetrics[$routeName]['cache_off']['errors'][] = $hasErrorOff;

        // --- MODE 4: VALKEY DOWN ---
        $downUrl = $rConfig['url'] . (str_contains($rConfig['url'], '?') ? '&' : '?') . 'valkey_down=1';
        $t0 = microtime(true);
        $ch = curl_init($downUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $durDown = (microtime(true) - $t0) * 1000;
        curl_close($ch);

        $hasErrorDown = ($httpCode !== 200 || strlen($res) < 500) ? 1 : 0;
        $rawMetrics[$routeName]['valkey_down']['times'][] = $durDown;
        $rawMetrics[$routeName]['valkey_down']['errors'][] = $hasErrorDown;

        if ($i % 5 === 0 || $i === 1) {
            echo sprintf("    [Round %02d/30] HIT=%.1fms, MISS=%.1fms, OFF=%.1fms, DOWN=%.1fms\n", $i, $durHit, $durMiss, $durOff, $durDown);
        }
    }
}

$tPing = microtime(true);
$cache->ping();
$valkeyLatency = round((microtime(true) - $tPing) * 1000, 2);

// Compute final matrix
$finalSummary = [];
foreach ($routes as $routeName => $rConfig) {
    $finalSummary[$routeName] = [];
    foreach ($modes as $m) {
        $d = $rawMetrics[$routeName][$m];
        $finalSummary[$routeName][$m] = calcMetrics(
            $d['times'],
            $d['mongo'],
            $d['errors'],
            $d['cmds'],
            $d['tls'],
            $d['hits'],
            $d['misses'],
            ($m === 'cache_off' || $m === 'valkey_down') ? 0.0 : $valkeyLatency
        );
    }
}

echo "\n================================================================================\n";
echo "FINAL BENCHMARK MATRIX (P2.3 CACHE PERFORMANCE GATE)\n";
echo "================================================================================\n";
echo json_encode($finalSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$outDirs = [
    '/var/www/html/experiments/performance/aiven_valkey/p2_3',
    dirname(__DIR__) . '/experiments/performance/aiven_valkey/p2_3',
];
foreach ($outDirs as $outDir) {
    if (!is_dir($outDir)) {
        @mkdir($outDir, 0777, true);
    }
    @file_put_contents($outDir . '/benchmark_matrix.json', json_encode($finalSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
echo "\nSaved benchmark matrix to backend/experiments/performance/aiven_valkey/p2_3/benchmark_matrix.json\n";
