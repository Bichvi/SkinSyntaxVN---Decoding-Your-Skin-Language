<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

$collections = ['san_pham', 'danh_gia_san_pham', 'danh_gia', 'hoi_dap_san_pham', 'thuong_hieu', 'danh_muc', 'xuat_xu'];

foreach ($collections as $colName) {
    echo "=== INDEXES FOR $colName ===\n";
    $indexes = iterator_to_array($db->{$colName}->listIndexes());
    foreach ($indexes as $idx) {
        echo "  Name: " . $idx->getName() . " | Key: " . json_encode($idx->getKey()) . "\n";
    }
    $count = $db->{$colName}->countDocuments();
    echo "  Total docs: $count\n\n";
}
