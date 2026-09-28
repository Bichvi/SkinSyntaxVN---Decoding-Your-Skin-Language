<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

$targetIds = [1000, 266, 728, 3358, 2009, 868];
$cursor = $db->san_pham->find(['ma_san_pham' => ['$in' => $targetIds]]);

echo "=== AUDIT OF PRODUCTS 1000, 266, 728, 3358, 2009, 868 ===\n\n";
foreach ($cursor as $doc) {
    echo "--------------------------------------------------------\n";
    echo "ID: " . $doc['ma_san_pham'] . "\n";
    echo "Tên: " . $doc['ten_san_pham'] . "\n";
    echo "Thương hiệu: " . ($doc['thuong_hieu'] ?? '') . "\n";
    echo "Danh mục: " . ($doc['danh_muc_day_du'] ?? '') . "\n";
    echo "Loại da: " . ($doc['loai_da'] ?? '') . "\n";
    echo "Dung tích: " . ($doc['dung_tich'] ?? '') . "\n";
    echo "Giá bán: " . number_format($doc['gia_ban'] ?? 0) . " đ\n";
    echo "Thành phần chính: " . mb_substr($doc['thanh_phan_chinh'] ?? '', 0, 100) . "...\n";
    echo "Thành phần đầy đủ: " . mb_substr($doc['thanh_phan'] ?? '', 0, 100) . "...\n";
    echo "Mô tả ngắn: " . mb_substr(strip_tags($doc['mo_ta_ngan'] ?? $doc['mo_ta'] ?? ''), 0, 120) . "...\n";
}
