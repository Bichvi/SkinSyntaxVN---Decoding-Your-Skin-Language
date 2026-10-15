<?php
// backend/tests/inspect_request_lifecycle.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CacheService.php';

class DetailedProfiler implements MongoDB\Driver\Monitoring\CommandSubscriber {
    public array $commands = [];
    public function commandStarted(MongoDB\Driver\Monitoring\CommandStartedEvent $event): void {
        $name = $event->getCommandName();
        if (in_array($name, ['ping', 'isMaster', 'hello', 'buildInfo', 'saslStart', 'saslContinue'], true)) {
            return;
        }
        $cmd = (array)$event->getCommand();
        $this->commands[] = [
            'command' => $name,
            'collection' => $cmd[$name] ?? ($cmd['collection'] ?? 'unknown'),
            'filter' => isset($cmd['filter']) ? (array)$cmd['filter'] : null,
            'pipeline' => isset($cmd['pipeline']) ? count($cmd['pipeline']) . ' stages' : null,
        ];
    }
    public function commandSucceeded(MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void {}
    public function commandFailed(MongoDB\Driver\Monitoring\CommandFailedEvent $event): void {}
}

$profiler = new DetailedProfiler();
MongoDB\Driver\Monitoring\addSubscriber($profiler);

function inspectRoute(string $name, array $params, string $route): void {
    global $profiler;
    echo "\n======================================================\n";
    echo "INSPECTING ROUTE: $name ($route)\n";
    echo "======================================================\n";

    $profiler->commands = [];
    $_GET = $params;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = $route;
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    $start = microtime(true);
    ob_start();
    try {
        include '/var/www/html/public/index.php';
    } catch (Throwable $t) {
        echo "Exception: " . $t->getMessage() . "\n";
    }
    $html = ob_get_clean();
    $dur = (microtime(true) - $start) * 1000;

    echo "Duration: " . round($dur, 2) . " ms | HTML length: " . strlen($html) . "\n";
    echo "MongoDB Command Count: " . count($profiler->commands) . "\n";
    foreach ($profiler->commands as $idx => $cmd) {
        echo "  [" . ($idx + 1) . "] {$cmd['command']} on collection '{$cmd['collection']}'";
        if ($cmd['filter']) {
            echo " filter: " . json_encode($cmd['filter'], JSON_UNESCAPED_UNICODE);
        }
        if ($cmd['pipeline']) {
            echo " pipeline: " . $cmd['pipeline'];
        }
        echo "\n";
    }

    $cache = CacheService::getInstance();
    echo "Cache Metrics: " . json_encode($cache->getMetrics(), JSON_UNESCAPED_UNICODE) . "\n";
}

// 1. Inspect warm cache runs
inspectRoute('Homepage', ['r' => 'home'], '?r=home');
inspectRoute('Homepage (2nd hit)', ['r' => 'home'], '?r=home');
inspectRoute('Category', ['r' => 'tatca'], '?r=tatca');
inspectRoute('Product Detail', ['r' => 'chitiet', 'id' => '111'], '?r=chitiet&id=111');
