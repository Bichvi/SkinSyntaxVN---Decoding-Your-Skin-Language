<?php
// backend/tests/benchmark_baseline_p0.php
// Performance Baseline Benchmark (Phase P0) for SkinSyntaxVN

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';

// Find a valid product for detail view
$sampleProduct = $pdo->san_pham->findOne(['trang_thai' => 1], ['sort' => ['id' => -1]]);
$sampleProductId = $sampleProduct['id'] ?? ($sampleProduct['ma_san_pham'] ?? 111);
$sampleProductName = $sampleProduct['ten_san_pham'] ?? 'Sample Product';

echo "Using sample product for PDP benchmark: ID={$sampleProductId} ({$sampleProductName})\n\n";

class MongoQueryProfiler implements MongoDB\Driver\Monitoring\CommandSubscriber {
    public array $commands = [];
    public array $counts = [];
    public array $collectionCounts = [];
    public float $mongoTimeMs = 0;

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
        $this->commands[$event->getRequestId()] = microtime(true);
    }

    public function commandSucceeded(MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void {
        $reqId = $event->getRequestId();
        if (isset($this->commands[$reqId])) {
            $this->mongoTimeMs += (microtime(true) - $this->commands[$reqId]) * 1000;
            unset($this->commands[$reqId]);
        }
    }

    public function commandFailed(MongoDB\Driver\Monitoring\CommandFailedEvent $event): void {
        $reqId = $event->getRequestId();
        if (isset($this->commands[$reqId])) {
            $this->mongoTimeMs += (microtime(true) - $this->commands[$reqId]) * 1000;
            unset($this->commands[$reqId]);
        }
    }

    public function reset(): void {
        $this->commands = [];
        $this->counts = [];
        $this->collectionCounts = [];
        $this->mongoTimeMs = 0;
    }
}

$profiler = new MongoQueryProfiler();
MongoDB\Driver\Monitoring\addSubscriber($profiler);

$endpoints = [
    'Homepage' => [
        'url' => 'http://nginx/index.php?r=home',
        'route' => '?r=home',
        'params' => ['r' => 'home']
    ],
    'Category (TatCa)' => [
        'url' => 'http://nginx/index.php?r=tatca',
        'route' => '?r=tatca',
        'params' => ['r' => 'tatca']
    ],
    'Product Detail' => [
        'url' => 'http://nginx/index.php?r=chitiet&id=' . $sampleProductId,
        'route' => '?r=chitiet&id=' . $sampleProductId,
        'params' => ['r' => 'chitiet', 'id' => (string)$sampleProductId]
    ],
    'Search' => [
        'url' => 'http://nginx/index.php?r=timkiem&keyword=serum',
        'route' => '?r=timkiem&keyword=serum',
        'params' => ['r' => 'timkiem', 'keyword' => 'serum']
    ],
    'Recommendation' => [
        'url' => 'http://nginx/index.php?r=goiy',
        'route' => '?r=goiy',
        'params' => ['r' => 'goiy']
    ],
];

function calculatePercentiles(array $values): array {
    if (empty($values)) {
        return ['p50' => 0, 'p95' => 0, 'min' => 0, 'max' => 0, 'avg' => 0];
    }
    sort($values);
    $count = count($values);
    $p50Index = (int)floor($count * 0.50);
    $p95Index = min($count - 1, (int)floor($count * 0.95));

    return [
        'min' => round($values[0], 2),
        'max' => round($values[$count - 1], 2),
        'avg' => round(array_sum($values) / $count, 2),
        'p50' => round($values[$p50Index], 2),
        'p95' => round($values[$p95Index], 2),
    ];
}

$benchmarkResults = [];

echo "========================================================================================\n";
echo "BENCHMARKING ENDPOINTS VIA HTTP (Nginx -> PHP-FPM -> MongoDB Atlas)\n";
echo "Runs per endpoint: 5 iterations\n";
echo "========================================================================================\n";

foreach ($endpoints as $name => $meta) {
    echo "\n>>> Benchmarking {$name} ({$meta['url']})...\n";
    $times = [];
    $httpCodes = [];

    for ($i = 1; $i <= 5; $i++) {
        $ch = curl_init($meta['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $start = microtime(true);
        $res = curl_exec($ch);
        $totalTime = (microtime(true) - $start) * 1000;
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $times[] = $totalTime;
        $httpCodes[] = $httpCode;
        echo "   Run #{$i}: {$httpCode} in " . round($totalTime, 2) . " ms\n";
    }

    $stats = calculatePercentiles($times);
    $errorCount = count(array_filter($httpCodes, fn($code) => $code !== 200));

    // Now measure internal query count & breakdown on this route
    $profiler->reset();
    $_GET = $meta['params'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $meta['route'];
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    $memBefore = memory_get_peak_usage(true);
    $internalStart = microtime(true);
    ob_start();
    try {
        include '/var/www/html/public/index.php';
    } catch (Throwable $t) {
        // capture
    }
    ob_end_clean();
    $internalTime = (microtime(true) - $internalStart) * 1000;
    $memPeak = memory_get_peak_usage(true);

    $totalMongoQueries = array_sum($profiler->counts);

    $benchmarkResults[$name] = [
        'url' => $meta['url'],
        'http_stats' => $stats,
        'http_codes' => array_unique($httpCodes),
        'error_rate' => ($errorCount / count($httpCodes)) * 100 . '%',
        'internal_time_ms' => round($internalTime, 2),
        'mongo_queries' => $totalMongoQueries,
        'mongo_time_ms' => round($profiler->mongoTimeMs, 2),
        'collection_breakdown' => $profiler->collectionCounts,
        'peak_memory_mb' => round($memPeak / 1024 / 1024, 2)
    ];

    echo "   -> p50: {$stats['p50']} ms | p95: {$stats['p95']} ms | Atlas queries: {$totalMongoQueries} (Atlas time: " . round($profiler->mongoTimeMs, 2) . " ms)\n";
}

echo "\n========================================================================================\n";
echo "SUMMARY BASELINE REPORT (PHASE P0 - NO VALKEY CACHE)\n";
echo "========================================================================================\n";
echo json_encode($benchmarkResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// Save JSON artifact for documentation
file_put_contents('/var/www/html/tests/output_baseline_p0.json', json_encode($benchmarkResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nSaved baseline metrics to backend/tests/output_baseline_p0.json\n";
