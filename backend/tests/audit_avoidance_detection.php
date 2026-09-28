<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

$hasInci = $db->san_pham->countDocuments([
    'thanh_phan' => new \MongoDB\BSON\Regex('(Aqua|Water|Alcohol Denat|Paraben|Fragrance|Parfum)', 'i')
]);

echo "Products mentioning Aqua/Water/Alcohol/Paraben/Fragrance in thanh_phan: $hasInci / 2473 (" . round($hasInci / 2473 * 100, 2) . "%)\n";

// Check mo_ta
$hasInciInDesc = $db->san_pham->countDocuments([
    'mo_ta' => new \MongoDB\BSON\Regex('(Aqua|Water|Alcohol Denat|Paraben|Fragrance|Parfum)', 'i')
]);
echo "Products mentioning Aqua/Water/Alcohol/Paraben/Fragrance in mo_ta: $hasInciInDesc / 2473 (" . round($hasInciInDesc / 2473 * 100, 2) . "%)\n";

// Let's test matching the 10 avoidance options
$avoidOptions = [
    'Alcohol' => '(cồn|alcohol|ethanol)',
    'Fragrance/Parfum' => '(hương liệu|fragrance|parfum)',
    'Paraben' => '(paraben|methylparaben|propylparaben)',
    'Mineral Oil' => '(dầu khoáng|mineral oil|paraffinum liquidum)',
    'Sulfate (SLS/SLES)' => '(sulfate|sulphate|sls|sles|sodium lauryl sulfate)',
    'Silicone' => '(silicone|dimethicone|cyclopentasiloxane)',
    'Essential Oil' => '(tinh dầu|essential oil)',
    'MIT/CMIT' => '(methylisothiazolinone|methylchloroisothiazolinone)',
    'Colorant' => '(phẩm màu|colorant|ci \d+)',
    'Lanolin' => '(lanolin)'
];

echo "\nDetection rate of Avoidance Ingredients in (thanh_phan + mo_ta):\n";
foreach ($avoidOptions as $opt => $regexStr) {
    $regex = new \MongoDB\BSON\Regex($regexStr, 'i');
    $cnt = $db->san_pham->countDocuments([
        '$or' => [
            ['thanh_phan' => $regex],
            ['mo_ta' => $regex]
        ]
    ]);
    echo sprintf("  %-25s: %4d / 2473 products (%5.2f%%)\n", $opt, $cnt, $cnt / 2473 * 100);
}
