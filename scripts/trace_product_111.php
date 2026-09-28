<?php
require_once __DIR__ . '/../backend/app/config/db.php';
require_once __DIR__ . '/../backend/app/models/SanPham.php';

echo "=== TRACE PRODUCT 111 ===" . PHP_EOL;

// 1. Direct query Atlas
$cursor = $db->san_pham->find([
    '$or' => [
        ['ma_san_pham' => 111],
        ['ma_san_pham' => '111'],
        ['id' => 111],
        ['id' => '111'],
    ]
]);

$atlasDocs = iterator_to_array($cursor);
echo "1. Direct Atlas query matches: " . count($atlasDocs) . " document(s)" . PHP_EOL;
foreach ($atlasDocs as $idx => $d) {
    echo "   Doc #$idx: _id: " . (string)$d['_id'] 
        . " | ma_san_pham: " . var_export($d['ma_san_pham'], true) 
        . " | ten_san_pham: " . var_export($d['ten_san_pham'], true) . PHP_EOL;
}

// 2. Call model find('111')
$spModel = new SanPham($pdo);
$modelProductString = $spModel->find('111');
echo "2. Model find('111'):" . PHP_EOL;
if ($modelProductString) {
    echo "   ten_san_pham: " . var_export($modelProductString['ten_san_pham'], true) . PHP_EOL;
    echo "   id: " . var_export($modelProductString['id'] ?? null, true) . PHP_EOL;
} else {
    echo "   NOT FOUND!" . PHP_EOL;
}

// 3. Call model find(111)
$modelProductInt = $spModel->find(111);
echo "3. Model find(111):" . PHP_EOL;
if ($modelProductInt) {
    echo "   ten_san_pham: " . var_export($modelProductInt['ten_san_pham'], true) . PHP_EOL;
} else {
    echo "   NOT FOUND!" . PHP_EOL;
}

// 4. Check if duplicate ma_san_pham exists
$countDuplicates = $db->san_pham->countDocuments([
    '$or' => [
        ['ma_san_pham' => 111],
        ['ma_san_pham' => '111']
    ]
]);
echo "4. Total documents matching ma_san_pham 111 (int or string): $countDuplicates" . PHP_EOL;

// 5. Test view rendering simulation
$p = $modelProductString;
$viewTitle = htmlspecialchars($p['ten_san_pham'] ?? '', ENT_QUOTES, 'UTF-8');
echo "5. Value passed to view: " . var_export($p['ten_san_pham'] ?? '', true) . PHP_EOL;
echo "   Rendered in view html: " . var_export($viewTitle, true) . PHP_EOL;
