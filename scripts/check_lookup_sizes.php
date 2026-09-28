<?php
require_once __DIR__ . '/../backend/app/config/db.php';
echo 'thuong_hieu count: ' . $db->thuong_hieu->countDocuments() . PHP_EOL;
echo 'danh_muc count: ' . $db->danh_muc->countDocuments() . PHP_EOL;
echo 'xuat_xu count: ' . $db->xuat_xu->countDocuments() . PHP_EOL;

// Sample 1 doc each to check field names
$brand = $db->thuong_hieu->findOne();
echo 'thuong_hieu fields: ' . json_encode(array_keys((array)$brand)) . PHP_EOL;
$cat = $db->danh_muc->findOne();
echo 'danh_muc fields: ' . json_encode(array_keys((array)$cat)) . PHP_EOL;
$origin = $db->xuat_xu->findOne();
echo 'xuat_xu fields: ' . json_encode(array_keys((array)$origin)) . PHP_EOL;
