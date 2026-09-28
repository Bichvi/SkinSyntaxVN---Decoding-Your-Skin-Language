<?php
class MongoQueryLogger implements MongoDB\Driver\Monitoring\CommandSubscriber {
    public array $commands = [];
    public array $counts = [];
    public array $collectionCounts = [];

    public function commandStarted(MongoDB\Driver\Monitoring\CommandStartedEvent $event): void {
        $cmdName = $event->getCommandName();
        $this->counts[$cmdName] = ($this->counts[$cmdName] ?? 0) + 1;
        $cmdDoc = (array)$event->getCommand();
        $coll = $cmdDoc[$cmdName] ?? ($cmdDoc['collection'] ?? 'unknown');
        if (is_string($coll)) {
            $this->collectionCounts[$coll][$cmdName] = ($this->collectionCounts[$coll][$cmdName] ?? 0) + 1;
        }
        $this->commands[] = [
            'command' => $cmdName,
            'collection' => is_string($coll) ? $coll : '',
            'requestId' => $event->getRequestId(),
            'start' => microtime(true)
        ];
    }

    public function commandSucceeded(MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void {}
    public function commandFailed(MongoDB\Driver\Monitoring\CommandFailedEvent $event): void {}

    public function reset(): void {
        $this->commands = [];
        $this->counts = [];
        $this->collectionCounts = [];
    }
}

$logger = new MongoQueryLogger();
MongoDB\Driver\Monitoring\addSubscriber($logger);

function testRoute(string $route, MongoQueryLogger $logger): array {
    $logger->reset();

    // Setup environment for the route
    $query = [];
    parse_str(parse_url($route, PHP_URL_QUERY) ?? '', $query);
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $route;
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    // Clear SanPham lookup cache to simulate a fresh incoming request
    if (class_exists('SanPham') && method_exists('SanPham', 'clearLookupCache')) {
        SanPham::clearLookupCache();
    }

    ob_start();
    $start = microtime(true);
    try {
        // Run index.php within container
        include '/var/www/html/public/index.php';
    } catch (Throwable $e) {
        // ignore output/exit
    }
    $duration = (microtime(true) - $start) * 1000;
    ob_end_clean();

    $thFind = $logger->collectionCounts['thuong_hieu']['find'] ?? 0;
    $dmFind = $logger->collectionCounts['danh_muc']['find'] ?? 0;
    $xxFind = $logger->collectionCounts['xuat_xu']['find'] ?? 0;
    $totalQueries = array_sum($logger->counts);

    return [
        'duration_ms' => round($duration, 2),
        'total_queries' => $totalQueries,
        'thuong_hieu_find' => $thFind,
        'danh_muc_find' => $dmFind,
        'xuat_xu_find' => $xxFind,
        'coll_details' => $logger->collectionCounts
    ];
}

$routes = [
    '?r=home',
    '?r=chitiet&id=111',
    '?r=goiy'
];

echo "=== MONGODB COMMANDS & INTERNAL PROFILE ===\n";
foreach ($routes as $route) {
    $res = testRoute($route, $logger);
    echo "Route: $route\n";
    echo "  Execution Time: {$res['duration_ms']} ms\n";
    echo "  Total MongoDB Queries: {$res['total_queries']}\n";
    echo "  thuong_hieu find count: {$res['thuong_hieu_find']}\n";
    echo "  danh_muc find count: {$res['danh_muc_find']}\n";
    echo "  xuat_xu find count: {$res['xuat_xu_find']}\n";
    echo "  Collection breakdown: " . json_encode($res['coll_details'], JSON_UNESCAPED_UNICODE) . "\n\n";
}
