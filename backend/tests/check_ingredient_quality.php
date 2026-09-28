<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

$docs = $db->san_pham->find([], ['projection' => ['ma_san_pham' => 1, 'thanh_phan' => 1, 'thanh_phan_chinh' => 1, 'mo_ta' => 1]]);
$emptyIng = 0;
$shortIng = 0;
$onlyGeneric = 0;
$total = 0;

foreach ($docs as $d) {
    $total++;
    $ing = trim((string)($d['thanh_phan'] ?? ''));
    if ($ing === '') {
        $emptyIng++;
    } elseif (mb_strlen($ing, 'UTF-8') < 30) {
        $shortIng++;
    }
}

echo "Total products: $total\n";
echo "Empty thanh_phan: $emptyIng (" . round($emptyIng / $total * 100, 2) . "%)\n";
echo "Very short thanh_phan (<30 chars): $shortIng (" . round($shortIng / $total * 100, 2) . "%)\n";
