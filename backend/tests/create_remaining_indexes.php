<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

$indexesToCreate = [
    'san_pham' => [
        ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ngay_tao' => -1],
        ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1],
        ['luot_xem' => -1, 'ma_san_pham' => -1],
    ]
];

foreach ($indexesToCreate as $col => $list) {
    foreach ($list as $key) {
        $name = $db->{$col}->createIndex($key);
        echo "Created index on $col: $name\n";
    }
}
