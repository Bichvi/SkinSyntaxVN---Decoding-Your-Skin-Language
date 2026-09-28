<?php
class ProfileLogger implements MongoDB\Driver\Monitoring\CommandSubscriber {
    public float $mongoTimeMs = 0;
    public float $lookupTimeMs = 0;
    public float $otherMongoTimeMs = 0;
    public int $queryCount = 0;
    public array $lookupCollections = ['thuong_hieu', 'danh_muc', 'xuat_xu'];
    private array $pending = [];

    public function commandStarted(MongoDB\Driver\Monitoring\CommandStartedEvent $event): void {
        $this->pending[$event->getRequestId()] = [
            'name' => $event->getCommandName(),
            'start' => microtime(true),
            'coll' => (array)$event->getCommand()
        ];
    }

    public function commandSucceeded(MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void {
        $id = $event->getRequestId();
        if (isset($this->pending[$id])) {
            $duration = ($event->getDurationMicros() / 1000);
            $this->mongoTimeMs += $duration;
            $this->queryCount++;
            
            $cmd = $this->pending[$id]['coll'];
            $cmdName = $this->pending[$id]['name'];
            $coll = $cmd[$cmdName] ?? ($cmd['collection'] ?? '');
            
            if (in_array($coll, $this->lookupCollections, true)) {
                $this->lookupTimeMs += $duration;
            } else {
                $this->otherMongoTimeMs += $duration;
            }
            unset($this->pending[$id]);
        }
    }

    public function commandFailed(MongoDB\Driver\Monitoring\CommandFailedEvent $event): void {
        unset($this->pending[$event->getRequestId()]);
    }

    public function reset(): void {
        $this->mongoTimeMs = 0;
        $this->lookupTimeMs = 0;
        $this->otherMongoTimeMs = 0;
        $this->queryCount = 0;
        $this->pending = [];
    }
}

$logger = new ProfileLogger();
MongoDB\Driver\Monitoring\addSubscriber($logger);

function runProfiledRoute(string $route, ProfileLogger $logger): array {
    $logger->reset();

    if (class_exists('SanPham') && method_exists('SanPham', 'clearLookupCache')) {
        SanPham::clearLookupCache();
    }

    $query = [];
    parse_str(parse_url($route, PHP_URL_QUERY) ?? '', $query);
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $route;
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    ob_start();
    $t0 = microtime(true);
    try {
        include '/var/www/html/public/index.php';
    } catch (Throwable $e) {}
    $totalTime = (microtime(true) - $t0) * 1000;
    $output = ob_get_clean();

    $mongoTime = $logger->mongoTimeMs;
    $lookupTime = $logger->lookupTimeMs;
    $businessMongoTime = $logger->otherMongoTimeMs;
    $phpTime = max(0, $totalTime - $mongoTime);

    return [
        'route' => $route,
        'total_time_ms' => round($totalTime, 2),
        'total_mongo_ms' => round($mongoTime, 2),
        'lookup_loading_ms' => round($lookupTime, 2),
        'business_mongo_ms' => round($businessMongoTime, 2),
        'php_processing_ms' => round($phpTime, 2),
        'query_count' => $logger->queryCount,
        'html_size_bytes' => strlen($output)
    ];
}

$routes = ['?r=home', '?r=chitiet&id=111', '?r=goiy'];

echo "=== DETAILED BREAKDOWN PER ROUTE ===\n\n";
foreach ($routes as $r) {
    // Run in separate process or isolate
    echo "--- ROUTE: $r ---\n";
    $data = runProfiledRoute($r, $logger);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
}
