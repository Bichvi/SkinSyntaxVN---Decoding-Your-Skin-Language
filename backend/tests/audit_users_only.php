<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

echo "=== KHACH HANG (Total: " . $db->khach_hang->countDocuments() . ") ===\n";
foreach ($db->khach_hang->find([], ['limit' => 2]) as $d) {
    print_r($d);
}

echo "=== NGUOIDUNG (Total: " . $db->nguoidung->countDocuments() . ") ===\n";
foreach ($db->nguoidung->find([], ['limit' => 2]) as $d) {
    print_r($d);
}

echo "=== NHAN VIEN (Total: " . $db->nhan_vien->countDocuments() . ") ===\n";
foreach ($db->nhan_vien->find([], ['limit' => 2]) as $d) {
    print_r($d);
}
