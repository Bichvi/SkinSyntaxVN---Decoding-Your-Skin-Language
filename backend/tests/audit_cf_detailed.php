<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

function printSample($colName, $limit = 2) {
    global $db;
    echo "=========================================================\n";
    echo "COLLECTION: $colName (Total: " . $db->selectCollection($colName)->countDocuments() . ")\n";
    echo "=========================================================\n";
    $cursor = $db->selectCollection($colName)->find([], ['limit' => $limit]);
    foreach ($cursor as $doc) {
        print_r($doc);
    }
}

printSample('khach_hang', 2);
printSample('nguoidung', 2);
printSample('nhan_vien', 2);
printSample('hoa_don', 2);
printSample('chi_tiet_hoa_don', 3);
printSample('danh_gia_san_pham', 2);
printSample('danh_gia', 2);
printSample('lich_su_tim_kiem', 2);
printSample('lich_su_chat', 2);
