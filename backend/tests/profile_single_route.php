<?php
class MongoQueryLogger implements MongoDB\Driver\Monitoring\CommandSubscriber {
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
    }

    public function commandSucceeded(MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void {}
    public function commandFailed(MongoDB\Driver\Monitoring\CommandFailedEvent $event): void {}
}

$logger = new MongoQueryLogger();
MongoDB\Driver\Monitoring\addSubscriber($logger);

$route = $argv[1] ?? '?r=home';
$query = [];
parse_str(parse_url($route, PHP_URL_QUERY) ?? '', $query);
$_GET = $query;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $route;
$_SERVER['SCRIPT_NAME'] = '/index.php';

ob_start();
$start = microtime(true);
try {
    include '/var/www/html/public/index.php';
} catch (Throwable $e) {}
$duration = (microtime(true) - $start) * 1000;
ob_end_clean();

$thFind = $logger->collectionCounts['thuong_hieu']['find'] ?? 0;
$dmFind = $logger->collectionCounts['danh_muc']['find'] ?? 0;
$xxFind = $logger->collectionCounts['xuat_xu']['find'] ?? 0;
$total = array_sum($logger->counts);

echo json_encode([
    'route' => $route,
    'duration_ms' => round($duration, 2),
    'total_queries' => $total,
    'thuong_hieu_find' => $thFind,
    'danh_muc_find' => $dmFind,
    'xuat_xu_find' => $xxFind,
    'collections' => $logger->collectionCounts
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
