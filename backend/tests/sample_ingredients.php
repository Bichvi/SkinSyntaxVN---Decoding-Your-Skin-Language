<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

$samples = $db->san_pham->find([], ['limit' => 5, 'projection' => ['ma_san_pham' => 1, 'ten_san_pham' => 1, 'thanh_phan' => 1, 'thanh_phan_chinh' => 1]]);

echo "=== INGREDIENTS FORMAT INSPECTION ===\n";
foreach ($samples as $s) {
    echo "---------------------------------------------------------\n";
    echo "ID: " . $s['ma_san_pham'] . " | " . $s['ten_san_pham'] . "\n";
    echo "thanh_phan: " . mb_substr($s['thanh_phan'] ?? '', 0, 200) . "...\n";
}
