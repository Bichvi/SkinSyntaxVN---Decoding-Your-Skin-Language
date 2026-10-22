<?php
// backend/tests/inspect_mongo_queries_accurate.php
// Accurate isolation of MongoDB queries per route and mode

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

class StrictMongoQueryCounter implements MongoDB\Driver\Monitoring\CommandSubscriber {
    public int $queryCount = 0;
    public array $queries = [];

    public function commandStarted(MongoDB\Driver\Monitoring\CommandStartedEvent $event): void {
        $cmdName = $event->getCommandName();
        if (in_array($cmdName, ['ping', 'isMaster', 'hello', 'buildInfo', 'saslStart', 'saslContinue'], true)) {
            return;
        }
        $this->queryCount++;
        $cmd = (array)$event->getCommand();
        $this->queries[] = [
            'cmd' => $cmdName,
            'collection' => $cmd[$cmdName] ?? '',
        ];
    }

    public function commandSucceeded(MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void {}
    public function commandFailed(MongoDB\Driver\Monitoring\CommandFailedEvent $event): void {}

    public function reset(): void {
        $this->queryCount = 0;
        $this->queries = [];
    }
}

$counter = new StrictMongoQueryCounter();
MongoDB\Driver\Monitoring\addSubscriber($counter);

function resetCacheService(?string $driver = null, ?string $url = null): void {
    $ref = new ReflectionClass('CacheService');
    $prop = $ref->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
    
    if ($driver !== null) {
        $propDriver = $ref->getProperty('driver');
    }
}

$cache = CacheService::getInstance();

echo "================================================================================\n";
echo "ACCURATE ISOLATED MONGODB QUERY COUNT PER ROUTE & MODE\n";
echo "================================================================================\n\n";

$routes = [
    'Homepage' => function($mode, $pdo, $counter) {
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

        // Reset singleton with proper mode
        resetCacheService();
        $c = CacheService::getInstance();

        if ($mode === 'cache_miss') {
            $c->delete('home:composite_public');
        } elseif ($mode === 'cache_hit') {
            // Ensure warm
            (new HomeController($pdo))->getHomepagePublicData();
        }

        $counter->reset();
        ob_start();
        (new HomeController($pdo))->index();
        ob_end_clean();
        return ['count' => $counter->queryCount, 'queries' => $counter->queries];
    },

    'Category' => function($mode, $pdo, $counter) {
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

        resetCacheService();
        $c = CacheService::getInstance();

        if ($mode === 'cache_miss') {
            $c->deleteByPattern('catalog:*');
        } elseif ($mode === 'cache_hit') {
            ob_start();
            (new SanPhamController($pdo))->tatca();
            ob_end_clean();
        }

        $counter->reset();
        ob_start();
        (new SanPhamController($pdo))->tatca();
        ob_end_clean();
        return ['count' => $counter->queryCount, 'queries' => $counter->queries];
    },

    'Product Detail' => function($mode, $pdo, $counter) {
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

        resetCacheService();
        $c = CacheService::getInstance();

        $_GET['id'] = '111';
        if ($mode === 'cache_miss') {
            $c->delete('product:detail:111');
            $c->delete('product:freq_bought:111');
            $c->delete('product:videos:111');
            $c->delete('menu:tree:14');
        } elseif ($mode === 'cache_hit') {
            ob_start();
            (new SanPhamController($pdo))->chitiet();
            ob_end_clean();
        }

        $counter->reset();
        ob_start();
        (new SanPhamController($pdo))->chitiet();
        ob_end_clean();
        return ['count' => $counter->queryCount, 'queries' => $counter->queries];
    },
];

$modes = ['cache_hit', 'cache_miss', 'cache_off', 'valkey_down'];

foreach ($routes as $name => $fn) {
    echo ">>> ROUTE: {$name}\n";
    foreach ($modes as $m) {
        $res = $fn($m, $pdo, $counter);
        echo sprintf("  %-12s: %d queries\n", strtoupper($m), $res['count']);
        foreach ($res['queries'] as $q) {
            echo "    -> cmd: {$q['cmd']} on collection: '{$q['collection']}'\n";
        }
    }
    echo "\n";
}
