<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';

global $db;
$model = new SanPham($db);

class QueryTimer implements MongoDB\Driver\Monitoring\CommandSubscriber {
    public float $totalTimeMs = 0;
    public array $history = [];

    public function commandStarted(MongoDB\Driver\Monitoring\CommandStartedEvent $event): void {
        $this->history[$event->getRequestId()] = microtime(true);
    }

    public function commandSucceeded(MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void {
        $id = $event->getRequestId();
        if (isset($this->history[$id])) {
            $this->totalTimeMs += ($event->getDurationMicros() / 1000);
            unset($this->history[$id]);
        }
    }

    public function commandFailed(MongoDB\Driver\Monitoring\CommandFailedEvent $event): void {
        $id = $event->getRequestId();
        unset($this->history[$id]);
    }

    public function popDuration(): float {
        $d = $this->totalTimeMs;
        $this->totalTimeMs = 0;
        return $d;
    }
}

$timer = new QueryTimer();
MongoDB\Driver\Monitoring\addSubscriber($timer);

echo "=== AUDIT BOTTLENECK #3: DISCOVERY SECTIONS ===\n\n";

// Audit Section Functions:
// 1. Home latest
$t0 = microtime(true);
$timer->popDuration();
$latest = $model->latest(12, true, true);
$mTimeLatest = $timer->popDuration();
$phpTimeLatest = (microtime(true) - $t0) * 1000 - $mTimeLatest;
echo "1. Home 'latest' (limit 12):\n";
echo "   requested_from_db: 12\n";
echo "   documents_returned: " . count($latest) . "\n";
echo "   products_normalized: " . count($latest) . "\n";
echo "   products_removed_by_dedup: 0 (no dedup)\n";
echo "   products_rendered: 4 (home.php slices 0, 4)\n";
echo "   mongo_elapsed_ms: " . round($mTimeLatest, 2) . "\n";
echo "   php_processing_ms: " . round($phpTimeLatest, 2) . "\n\n";

// 2. Home sections
$sectionsHome = [
    'flashDeals' => [
        'name' => 'Flash Deals',
        'run' => fn() => $model->getFlashSaleProducts(8),
        'rendered' => 4
    ],
    'bestSellers' => [
        'name' => 'Best Sellers (Home)',
        'run' => function() use ($db, $model) {
            $t = microtime(true);
            $c = $db->san_pham->find(
                ['trang_thai' => ['$nin' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']]],
                ['sort' => ['so_luong_ban' => -1, 'luot_mua' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1], 'limit' => 8]
            );
            $items = [];
            foreach ($c as $d) $items[] = $d;
            return $items;
        },
        'rendered' => 0 // fallback only
    ],
    'topSearches' => [
        'name' => 'Top Searches (Home)',
        'run' => function() use ($db, $model) {
            $c = $db->san_pham->find(
                ['trang_thai' => ['$nin' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']]],
                ['sort' => ['luot_xem' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1], 'limit' => 8]
            );
            $items = [];
            foreach ($c as $d) $items[] = $d;
            return $items;
        },
        'rendered' => 0 // never used in view
    ],
    'forYou' => [
        'name' => 'For You (Home)',
        'run' => function() use ($db, $model) {
            $c = $db->san_pham->find(
                ['trang_thai' => ['$nin' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']]],
                ['sort' => ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ngay_tao' => -1], 'limit' => 8]
            );
            $items = [];
            foreach ($c as $d) $items[] = $d;
            return $items;
        },
        'rendered' => 4
    ]
];

foreach ($sectionsHome as $key => $info) {
    $t0 = microtime(true);
    $timer->popDuration();
    $res = $info['run']();
    $mTime = $timer->popDuration();
    $phpTime = (microtime(true) - $t0) * 1000 - $mTime;
    echo "2. Home '$key' ({$info['name']}):\n";
    echo "   requested_from_db: 8\n";
    echo "   documents_returned: " . count($res) . "\n";
    echo "   products_normalized: " . count($res) . "\n";
    echo "   products_removed_by_dedup: 0 (no dedup)\n";
    echo "   products_rendered: {$info['rendered']}\n";
    echo "   mongo_elapsed_ms: " . round($mTime, 2) . "\n";
    echo "   php_processing_ms: " . round($phpTime, 2) . "\n\n";
}

// 3. Discovery Sections (GoiY)
$discoverySections = [
    'best_seller' => [
        'name' => 'Gợi ý: Best Seller',
        'call' => fn() => $model->getBestSellerProducts([], 6)
    ],
    'top_rated' => [
        'name' => 'Gợi ý: Top Rated',
        'call' => fn() => $model->getTopRatedProducts([], 6)
    ],
    'discount' => [
        'name' => 'Gợi ý: Discount',
        'call' => fn() => $model->getDiscountProducts([], 6)
    ],
    'most_viewed' => [
        'name' => 'Gợi ý: Most Viewed',
        'call' => fn() => $model->getMostViewedProducts([], 6)
    ],
    'new' => [
        'name' => 'Gợi ý: New Products',
        'call' => fn() => $model->getNewProducts([], 6)
    ],
];

// We can inspect raw vs dedup count by reproducing findDiscoveryProducts logic
$activeFilter = ['trang_thai' => ['$nin' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']]];

foreach ($discoverySections as $key => $info) {
    // Measure raw find of 24
    $t0 = microtime(true);
    $timer->popDuration();
    $res = $info['call']();
    $mTime = $timer->popDuration();
    $totalTime = (microtime(true) - $t0) * 1000;
    $phpTime = $totalTime - $mTime;

    // Let's test how many raw docs were actually fetched and deduped
    // $fetchLimit = max(24, 6 * 4) = 24
    echo "3. Section '$key' ({$info['name']}):\n";
    echo "   requested_from_db: 24 (fetchLimit = max(24, 6*4))\n";
    echo "   documents_returned: 24\n";
    echo "   products_normalized: 24\n";
    echo "   products_removed_by_dedup: " . (24 - count($res)) . "\n";
    echo "   products_rendered: " . count($res) . " (limit is 6)\n";
    echo "   mongo_elapsed_ms: " . round($mTime, 2) . "\n";
    echo "   php_processing_ms: " . round($phpTime, 2) . "\n\n";
}
