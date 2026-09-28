<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';

global $db;
$model = new SanPham($db);

$t0 = microtime(true);
$latest = $model->latest(12, true, true);
$tLatest = (microtime(true) - $t0) * 1000;

$t0 = microtime(true);
$pipeline = [
    ['$match' => ['danh_muc_day_du' => ['$nin' => [null, '']]]],
    ['$group' => ['_id' => '$danh_muc_day_du', 'so_luong' => ['$sum' => 1]]],
    ['$sort' => ['so_luong' => -1]],
    ['$limit' => 6]
];
$cursor = $db->san_pham->aggregate($pipeline);
$cats = iterator_to_array($cursor);
$tCats = (microtime(true) - $t0) * 1000;

$t0 = microtime(true);
$sections = $model->getHomepageProductSections(8);
$tSections = (microtime(true) - $t0) * 1000;

$t0 = microtime(true);
$menu = $model->menuTree();
$tMenu = (microtime(true) - $t0) * 1000;

echo "--- HOME CONTROLLER SUB-PARTS TIMING ---\n";
echo "1. latest(12): " . round($tLatest, 2) . " ms\n";
echo "2. getHighlightedCategories (aggregate 6377 docs): " . round($tCats, 2) . " ms\n";
echo "3. getHomepageProductSections(8): " . round($tSections, 2) . " ms\n";
echo "4. menuTree (aggregate 6377 docs): " . round($tMenu, 2) . " ms\n";
