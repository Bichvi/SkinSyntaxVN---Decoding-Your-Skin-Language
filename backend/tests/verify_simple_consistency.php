<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
global $db;

$spModel = new SanPham($db);

// Path A: Production Simple Method
$recsA = $spModel->getSimpleRecommenderProducts(10);

// Path B: Homepage Sections Simple Recommender
$sections = $spModel->getHomepageProductSections(10, [], null);
$recsB = $sections['topRatedWeighted'];

echo "=== PATH A (getSimpleRecommenderProducts) ===\n";
foreach ($recsA as $idx => $r) {
    echo sprintf("%2d | ID: %-5s | WR: %-8.4f | v: %-3d | R: %-4.2f | %s\n",
        $idx + 1, $r['ma_san_pham'], $r['recommender_meta']['weighted_rating'],
        $r['recommender_meta']['v'], $r['recommender_meta']['R'], mb_substr($r['ten_san_pham'], 0, 40));
}

echo "\n=== PATH B (getHomepageProductSections['topRatedWeighted']) ===\n";
foreach ($recsB as $idx => $r) {
    echo sprintf("%2d | ID: %-5s | WR: %-8.4f | v: %-3d | R: %-4.2f | %s\n",
        $idx + 1, $r['ma_san_pham'], $r['recommender_meta']['weighted_rating'],
        $r['recommender_meta']['v'], $r['recommender_meta']['R'], mb_substr($r['ten_san_pham'], 0, 40));
}

$idsA = array_column($recsA, 'ma_san_pham');
$idsB = array_column($recsB, 'ma_san_pham');
$wrA = array_map(fn($r) => $r['recommender_meta']['weighted_rating'], $recsA);
$wrB = array_map(fn($r) => $r['recommender_meta']['weighted_rating'], $recsB);

$matchIds = ($idsA === $idsB);
$matchWR = ($wrA === $wrB);

echo "\nIDs Match: " . ($matchIds ? "100% IDENTICAL" : "MISMATCH") . "\n";
echo "WR Scores Match: " . ($matchWR ? "100% IDENTICAL" : "MISMATCH") . "\n";
