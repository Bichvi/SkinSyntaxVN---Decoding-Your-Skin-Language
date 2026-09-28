<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
global $db;

$spModel = new SanPham($db);

$products = iterator_to_array($db->san_pham->find(['trang_thai' => 'active', 'gia_ban' => ['$gt' => 0]]));
$totalProducts = count($products);

echo "Total active products in database: {$totalProducts}\n";

$ratingsAll = [];
$ratingsValid = [];
$reviewCountsAll = [];
$reviewCountsValid = [];

$equalSoldCountAndReviewCount = 0;
$diffCount = 0;

foreach ($products as $doc) {
    $p = (array)$doc;
    $r = (float)($p['diem_danh_gia'] ?? 0);
    $v = (int)($p['so_luong_danh_gia'] ?? 0);
    $sold = (int)($p['so_luong_da_ban'] ?? 0);

    $ratingsAll[] = $r;
    $reviewCountsAll[] = $v;

    if ($r > 0 && $v > 0) {
        $ratingsValid[] = $r;
        $reviewCountsValid[] = $v;
    }

    if ($sold === $v) {
        $equalSoldCountAndReviewCount++;
    } else {
        $diffCount++;
    }
}

$validRatedCount = count($ratingsValid);
echo "Valid rated products (R > 0 and v > 0): {$validRatedCount} / {$totalProducts} (" . number_format($validRatedCount / $totalProducts * 100, 2) . "%)\n";

// C calculation
$c_valid = !empty($ratingsValid) ? array_sum($ratingsValid) / count($ratingsValid) : 0;
$c_all = !empty($ratingsAll) ? array_sum($ratingsAll) / count($ratingsAll) : 0;

echo "\n--- C (MEAN RATING) ---\n";
echo "A. Only R > 0 and v > 0: " . number_format($c_valid, 4) . "\n";
echo "B. All products (including rating 0): " . number_format($c_all, 4) . "\n";

// Percentile helper function
function getPercentile(array $data, float $p): float {
    sort($data);
    $n = count($data);
    if ($n === 0) return 0.0;
    $pos = ($n - 1) * $p;
    $base = (int)floor($pos);
    $rest = $pos - $base;
    if (isset($data[$base + 1])) {
        return $data[$base] + $rest * ($data[$base + 1] - $data[$base]);
    }
    return (float)$data[$base];
}

$percentiles = ['P50' => 0.50, 'P60' => 0.60, 'P70' => 0.70, 'P75' => 0.75, 'P80' => 0.80, 'P90' => 0.90];

echo "\n--- REVIEW COUNT PERCENTILES (ON VALID RATED PRODUCTS, N = {$validRatedCount}) ---\n";
foreach ($percentiles as $label => $p) {
    echo "{$label}: " . getPercentile($reviewCountsValid, $p) . "\n";
}

echo "\n--- REVIEW COUNT PERCENTILES (ON ALL PRODUCTS, N = {$totalProducts}) ---\n";
foreach ($percentiles as $label => $p) {
    echo "{$label}: " . getPercentile($reviewCountsAll, $p) . "\n";
}

echo "\n--- SO_LUONG_DA_BAN VS SO_LUONG_DANH_GIA ---\n";
echo "Total products: {$totalProducts}\n";
echo "so_luong_da_ban == so_luong_danh_gia: {$equalSoldCountAndReviewCount} (" . number_format($equalSoldCountAndReviewCount / $totalProducts * 100, 2) . "%)\n";
echo "so_luong_da_ban != so_luong_danh_gia: {$diffCount}\n";

// Check Production Top-10
echo "\n--- PRODUCTION SIMPLE RECOMMENDER TOP 10 ---\n";
$prodRecs = $spModel->getSimpleRecommenderProducts(10);
echo sprintf("%-3s | %-6s | %-8s | %-6s | %-6s | %-8s | %s\n",
    "#", "ID", "WR", "R", "v", "Sold", "Tên Sản Phẩm");
echo str_repeat("-", 80) . "\n";
foreach ($prodRecs as $idx => $r) {
    $meta = $r['recommender_meta'];
    echo sprintf("%-3d | %-6s | %-8.4f | %-6.2f | %-6d | %-8d | %s\n",
        $idx + 1,
        $r['ma_san_pham'],
        $meta['weighted_rating'],
        $meta['R'],
        $meta['v'],
        $r['so_luong_da_ban'] ?? 0,
        mb_substr($r['ten_san_pham'], 0, 45)
    );
}
