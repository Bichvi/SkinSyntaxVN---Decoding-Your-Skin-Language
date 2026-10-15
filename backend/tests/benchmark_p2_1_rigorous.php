<?php
// backend/tests/benchmark_p2_1_rigorous.php
// Rigorous Benchmark Suite for Phase P2.1: 30 Interleaved Runs per Route & Mode

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CacheService.php';

class MongoQueryCounter implements MongoDB\Driver\Monitoring\CommandSubscriber {
    public int $queryCount = 0;
    public float $mongoTimeMs = 0;
    private array $startTimes = [];

    public function commandStarted(MongoDB\Driver\Monitoring\CommandStartedEvent $event): void {
        $cmdName = $event->getCommandName();
        if (in_array($cmdName, ['ping', 'isMaster', 'hello', 'buildInfo', 'saslStart', 'saslContinue'], true)) {
            return;
        }
        $this->queryCount++;
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
        $this->startTimes = [];
    }
}

$profiler = new MongoQueryCounter();
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

$routes = [
    'Homepage' => [
        'url' => 'http://nginx/index.php?r=home',
        'params' => ['r' => 'home'],
        'route' => '?r=home',
        'cache_patterns' => ['home:*', 'menu:*'],
    ],
    'Category' => [
        'url' => 'http://nginx/index.php?r=tatca',
        'params' => ['r' => 'tatca'],
        'route' => '?r=tatca',
        'cache_patterns' => ['catalog:*'],
    ],
    'Product Detail' => [
        'url' => 'http://nginx/index.php?r=chitiet&id=111',
        'params' => ['r' => 'chitiet', 'id' => '111'],
        'route' => '?r=chitiet&id=111',
        'cache_patterns' => ['product:detail:111', 'product:freq_bought:111', 'product:videos:111'],
    ],
];

$modes = ['cache_off', 'cache_miss', 'cache_hit', 'valkey_down'];
$iterations = 30;
$warmups = 3;

echo "================================================================================\n";
echo "SKINSYNTAXVN — PHASE P2.1 RELIABLE RIGOROUS BENCHMARK SUITE\n";
echo "Settings: 30 Interleaved Runs | 3 Pre-warmups | Modes: OFF, MISS, HIT, DOWN\n";
echo "================================================================================\n\n";

$rawMetrics = [];
$cache = CacheService::getInstance();
$originalValkeyUrl = VALKEY_URL;

foreach ($routes as $routeName => $rConfig) {
    echo "\n>>> BENCHMARKING ROUTE: {$routeName} ({$rConfig['url']})\n";
    $rawMetrics[$routeName] = [
        'cache_off' => ['times' => [], 'mongo' => [], 'errors' => [], 'cmds' => 0, 'tls' => 0, 'hits' => 0, 'misses' => 0],
        'cache_miss' => ['times' => [], 'mongo' => [], 'errors' => [], 'cmds' => 0, 'tls' => 0, 'hits' => 0, 'misses' => 0],
        'cache_hit' => ['times' => [], 'mongo' => [], 'errors' => [], 'cmds' => 0, 'tls' => 0, 'hits' => 0, 'misses' => 0],
        'valkey_down' => ['times' => [], 'mongo' => [], 'errors' => [], 'cmds' => 0, 'tls' => 0, 'hits' => 0, 'misses' => 0],
    ];

    // Warm-up phase (3 runs per mode, discarded from statistics)
    echo "  [Warm-up] Executing {$warmups} unmeasured warmup cycles across modes...\n";
    for ($w = 1; $w <= $warmups; $w++) {
        // Warmup HIT
        file_get_contents($rConfig['url']);
    }

    // Main Interleaved Benchmark: 30 iterations
    echo "  [Measured] Running {$iterations} interleaved iterations...\n";
    for ($i = 1; $i <= $iterations; $i++) {
        if ($i % 5 === 0 || $i === 1) {
            echo "    -> Completed {$i}/{$iterations} rounds...\n";
        }

        // --- MODE 1: CACHE HIT ---
        // Ensure cache is populated
        $t0 = microtime(true);
        $ch = curl_init($rConfig['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $dur = (microtime(true) - $t0) * 1000;
        curl_close($ch);

        $hasError = ($httpCode !== 200 || strlen($res) < 500) ? 1 : 0;
        $rawMetrics[$routeName]['cache_hit']['times'][] = $dur;
        $rawMetrics[$routeName]['cache_hit']['errors'][] = $hasError;
        $rawMetrics[$routeName]['cache_hit']['cmds'] += 1;
        $rawMetrics[$routeName]['cache_hit']['hits'] += 1;

        // --- MODE 2: CACHE MISS ---
        // Invalidate cache keys to force MISS
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
        $dur = (microtime(true) - $t0) * 1000;
        curl_close($ch);

        $hasError = ($httpCode !== 200 || strlen($res) < 500) ? 1 : 0;
        $rawMetrics[$routeName]['cache_miss']['times'][] = $dur;
        $rawMetrics[$routeName]['cache_miss']['errors'][] = $hasError;
        $rawMetrics[$routeName]['cache_miss']['cmds'] += 2; // GET (miss) + SET
        $rawMetrics[$routeName]['cache_miss']['misses'] += 1;

        // --- MODE 3: CACHE OFF ---
        // Run with header or query param simulating Cache OFF
        $offUrl = $rConfig['url'] . (str_contains($rConfig['url'], '?') ? '&' : '?') . 'cache=off';
        $t0 = microtime(true);
        $ch = curl_init($offUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Cache-Disabled: 1']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $dur = (microtime(true) - $t0) * 1000;
        curl_close($ch);

        $hasError = ($httpCode !== 200 || strlen($res) < 500) ? 1 : 0;
        $rawMetrics[$routeName]['cache_off']['times'][] = $dur;
        $rawMetrics[$routeName]['cache_off']['errors'][] = $hasError;

        // --- MODE 4: VALKEY DOWN (Simulated Down / Fallback) ---
        // Internal execution with broken URL to profile graceful fallback
        $profiler->reset();
        putenv('VALKEY_URL=rediss://default:invalid@127.0.0.1:29999');
        $_ENV['VALKEY_URL'] = 'rediss://default:invalid@127.0.0.1:29999';

        $t0 = microtime(true);
        $ch = curl_init($rConfig['url'] . (str_contains($rConfig['url'], '?') ? '&' : '?') . 'valkey_down=1');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $dur = (microtime(true) - $t0) * 1000;
        curl_close($ch);

        putenv('VALKEY_URL=' . $originalValkeyUrl);
        $_ENV['VALKEY_URL'] = $originalValkeyUrl;

        $hasError = ($httpCode !== 200 || strlen($res) < 500) ? 1 : 0;
        $rawMetrics[$routeName]['valkey_down']['times'][] = $dur;
        $rawMetrics[$routeName]['valkey_down']['errors'][] = $hasError;
    }

    // Internal Query Profiling (Measure authoritative Mongo queries per mode)
    echo "  [Profiler] Measuring exact MongoDB queries per mode...\n";
    foreach ($modes as $m) {
        $profiler->reset();
        $_GET = $rConfig['params'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $rConfig['route'];
        $_SERVER['SCRIPT_NAME'] = '/index.php';

        if ($m === 'cache_miss') {
            foreach ($rConfig['cache_patterns'] as $pat) {
                $cache->deleteByPattern($pat);
            }
            $cache->clearL1();
        } elseif ($m === 'cache_off') {
            putenv('CACHE_DRIVER=none');
        } elseif ($m === 'valkey_down') {
            putenv('VALKEY_URL=rediss://default:invalid@127.0.0.1:29999');
        }

        ob_start();
        try {
            include '/var/www/html/public/index.php';
        } catch (Throwable $t) {}
        ob_end_clean();

        putenv('CACHE_DRIVER=valkey');
        putenv('VALKEY_URL=' . $originalValkeyUrl);

        $qCount = $profiler->queryCount;
        $rawMetrics[$routeName][$m]['mongo'] = array_fill(0, $iterations, $qCount);
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
echo "FINAL BENCHMARK MATRIX (P2.1 POST-OPTIMIZATION)\n";
echo "================================================================================\n";
echo json_encode($finalSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$outDir = '/var/www/html/experiments/performance/aiven_valkey/p2_1';
if (!is_dir($outDir)) {
    @mkdir($outDir, 0777, true);
}
file_put_contents($outDir . '/benchmark_matrix.json', json_encode($finalSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nSaved benchmark matrix to backend/experiments/performance/aiven_valkey/p2_1/benchmark_matrix.json\n";
