<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';

global $db;
$model = new SanPham($db);

$sections = [
    'best_seller' => ['sort' => ['so_luong_da_ban' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1], 'filter' => []],
    'top_rated'   => ['sort' => ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1], 'filter' => []],
    'discount'    => ['sort' => ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1], 'filter' => ['$or' => [['phan_tram_giam' => ['$gt' => 0]], ['$expr' => ['$gt' => ['$gia_thi_truong', '$gia_ban']]]]]],
    'most_viewed' => ['sort' => ['luot_xem' => -1, 'ma_san_pham' => -1], 'filter' => []],
    'new'         => ['sort' => ['ngay_tao' => -1, 'ma_san_pham' => -1], 'filter' => []],
];

$activeFilter = ['trang_thai' => ['$nin' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']]];

foreach ($sections as $name => $cfg) {
    $f = empty($cfg['filter']) ? $activeFilter : ['$and' => [$activeFilter, $cfg['filter']]];
    $cursor = $db->san_pham->find($f, ['sort' => $cfg['sort'], 'limit' => 24]);
    $raw = [];
    foreach ($cursor as $doc) {
        $raw[] = (array)$doc;
    }
    
    // Run deduplicateProductVariants
    $deduped = $model->deduplicateProductVariants($raw, 1);
    
    $rawCount = count($raw);
    $dedupedCount = count($deduped);
    $removedByDedup = $rawCount - $dedupedCount;
    
    // Check if we fetched only 6 docs:
    $cursor6 = $db->san_pham->find($f, ['sort' => $cfg['sort'], 'limit' => 6]);
    $raw6 = [];
    foreach ($cursor6 as $doc) $raw6[] = (array)$doc;
    $deduped6 = $model->deduplicateProductVariants($raw6, 1);

    echo "Section: $name\n";
    echo "  Raw fetched (limit 24): $rawCount\n";
    echo "  After dedup: $dedupedCount (real variants removed: $removedByDedup)\n";
    echo "  Final rendered (slice 6): " . min(6, $dedupedCount) . "\n";
    echo "  IF limit was 6: raw: " . count($raw6) . " -> after dedup: " . count($deduped6) . "\n\n";
}
