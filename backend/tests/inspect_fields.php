<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

$doc = (array)$db->san_pham->findOne();
echo "--- ALL FIELDS IN SAN_PHAM ---\n";
print_r(array_keys($doc));

echo "\n--- CHECK SORT FIELDS IN SAMPLE DOC ---\n";
$fieldsToCheck = [
    'trang_thai',
    'status',
    'so_luong_ban',
    'so_luong_da_ban',
    'luot_mua',
    'so_luong_danh_gia',
    'diem_danh_gia',
    'phan_tram_giam',
    'tien_tiet_kiem',
    'luot_xem',
    'ngay_tao',
    'ma_san_pham',
    'gia_ban',
    'gia_thi_truong',
    'danh_muc_day_du',
    'loai_san_pham'
];

foreach ($fieldsToCheck as $f) {
    $exists = $db->san_pham->countDocuments([$f => ['$exists' => true]]);
    $val = $doc[$f] ?? 'NOT_IN_SAMPLE';
    $type = isset($doc[$f]) ? gettype($doc[$f]) : 'N/A';
    echo sprintf("%-20s: %5d docs exist | sample value: %s (%s)\n", $f, $exists, is_scalar($val) ? (string)$val : json_encode($val), $type);
}
