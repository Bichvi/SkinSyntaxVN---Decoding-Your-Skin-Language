<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

echo "--- THUONG HIEU SAMPLE ---\n";
$brand = $db->thuong_hieu->findOne();
print_r($brand);

echo "--- DANH MUC SAMPLE ---\n";
$cat = $db->danh_muc->findOne();
print_r($cat);

echo "--- XUAT XU SAMPLE ---\n";
$origin = $db->xuat_xu->findOne();
print_r($origin);

echo "--- SAN PHAM SAMPLE ---\n";
$sp = $db->san_pham->findOne([], ['projection' => [
    'ma_san_pham' => 1,
    'ma_thuong_hieu' => 1,
    'ma_danh_muc' => 1,
    'ma_xuat_xu' => 1,
    'ten_san_pham' => 1
]]);
print_r($sp);
