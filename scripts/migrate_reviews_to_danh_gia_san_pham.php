<?php
// Manual migration only. Run from project root:
// php scripts/migrate_reviews_to_danh_gia_san_pham.php

require_once __DIR__ . '/../backend/app/config/config.php';
require_once __DIR__ . '/../backend/app/config/db.php';

$source = $pdo->danh_gia;
$target = $pdo->danh_gia_san_pham;
$migrated = 0;
$skipped = 0;

foreach ($source->find([]) as $doc) {
    $old = (array)$doc;
    $reviewId = (int)($old['ma_danh_gia'] ?? 0);
    $productId = trim((string)($old['ma_san_pham'] ?? ''));
    if ($reviewId <= 0 || $productId === '') {
        $skipped++;
        continue;
    }

    $exists = $target->findOne([
        '$or' => [
            ['legacy_ma_danh_gia' => $reviewId],
            ['ma_danh_gia' => $reviewId, '_source' => 'danh_gia'],
        ],
    ]);
    if ($exists) {
        $skipped++;
        continue;
    }

    $replyContent = trim((string)($old['phan_hoi'] ?? ''));
    $reply = null;
    if ($replyContent !== '') {
        $reply = [
            'noi_dung' => $replyContent,
            'ngay_phan_hoi' => $old['ngay_phan_hoi'] ?? null,
            'ma_nhan_vien' => $old['ma_nv_phan_hoi'] ?? null,
        ];
    }

    $target->insertOne([
        'ma_danh_gia' => $reviewId,
        'legacy_ma_danh_gia' => $reviewId,
        'ma_san_pham' => $productId,
        'ma_khach_hang' => (int)($old['ma_kh'] ?? 0),
        'ten_khach_hang' => $old['ten_khach_hang'] ?? 'Khách hàng',
        'so_sao' => (int)($old['so_sao'] ?? 0),
        'noi_dung' => (string)($old['noi_dung'] ?? ''),
        'hinh_anh' => [],
        'ngay_danh_gia' => $old['ngay_danh_gia'] ?? new MongoDB\BSON\UTCDateTime(),
        'da_mua_hang' => true,
        'phan_hoi_shop' => $reply,
        'trang_thai' => 'hien_thi',
        '_source' => 'danh_gia',
        'migrated_at' => new MongoDB\BSON\UTCDateTime(),
    ]);
    $migrated++;
}

echo json_encode([
    'ok' => true,
    'migrated' => $migrated,
    'skipped' => $skipped,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
