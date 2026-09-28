<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

echo "=== LOAI_DA COLLECTION ===\n";
foreach ($db->loai_da->find([]) as $ld) {
    echo "ID: " . $ld['ma_loai_da'] . " => " . $ld['ten_loai_da'] . "\n";
}

echo "\n=== PRODUCT SIDE METADATA (2,473 PRODUCTS) ===\n";
$total = $db->san_pham->countDocuments();
$hasLoaiDa = $db->san_pham->countDocuments(['loai_da' => ['$exists' => true, '$ne' => '']]);
$hasThanhPhan = $db->san_pham->countDocuments(['thanh_phan' => ['$exists' => true, '$ne' => '']]);
$hasThanhPhanChinh = $db->san_pham->countDocuments(['thanh_phan_chinh' => ['$exists' => true, '$ne' => '']]);
$hasMoTa = $db->san_pham->countDocuments(['mo_ta' => ['$exists' => true, '$ne' => '']]);
$hasDanhMuc = $db->san_pham->countDocuments(['danh_muc_day_du' => ['$exists' => true, '$ne' => '']]);
$hasGiaBan = $db->san_pham->countDocuments(['gia_ban' => ['$gt' => 0]]);
$hasVanDeVeDa = $db->san_pham->countDocuments(['van_de_ve_da' => ['$exists' => true, '$ne' => '']]);

echo "Total Products: $total\n";
echo "loai_da: $hasLoaiDa / $total (" . round($hasLoaiDa / $total * 100, 2) . "%)\n";
echo "thanh_phan: $hasThanhPhan / $total (" . round($hasThanhPhan / $total * 100, 2) . "%)\n";
echo "thanh_phan_chinh: $hasThanhPhanChinh / $total (" . round($hasThanhPhanChinh / $total * 100, 2) . "%)\n";
echo "Either thanh_phan or thanh_phan_chinh: " . $db->san_pham->countDocuments(['$or' => [['thanh_phan' => ['$exists' => true, '$ne' => '']], ['thanh_phan_chinh' => ['$exists' => true, '$ne' => '']]]]) . "\n";
echo "mo_ta: $hasMoTa / $total (" . round($hasMoTa / $total * 100, 2) . "%)\n";
echo "danh_muc_day_du: $hasDanhMuc / $total (" . round($hasDanhMuc / $total * 100, 2) . "%)\n";
echo "gia_ban > 0: $hasGiaBan / $total (" . round($hasGiaBan / $total * 100, 2) . "%)\n";
echo "van_de_ve_da: $hasVanDeVeDa / $total (" . round($hasVanDeVeDa / $total * 100, 2) . "%)\n";

// Distinct values of loai_da in products
$distinctProductLoaiDa = $db->san_pham->distinct('loai_da');
echo "\nDistinct product loai_da values (" . count($distinctProductLoaiDa) . "):\n";
foreach ($distinctProductLoaiDa as $ld) {
    $cnt = $db->san_pham->countDocuments(['loai_da' => $ld]);
    echo "  * [" . ($ld ?? 'NULL/EMPTY') . "] => $cnt products\n";
}
