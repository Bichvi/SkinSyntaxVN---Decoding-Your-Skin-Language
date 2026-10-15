<?php
// backend/tests/test_pdp_timing_parts.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CacheService.php';
require_once '/var/www/frontend/views/helpers.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/models/DanhGia.php';
require_once __DIR__ . '/../app/models/HoiDap.php';

$id = '93';
$sanPham = new SanPham($pdo);
$reviewModel = new DanhGia($pdo);
$questionModel = new HoiDap($pdo);

function timeIt($label, $callable) {
    $t = microtime(true);
    $res = $callable();
    $ms = round((microtime(true) - $t) * 1000, 2);
    echo sprintf("%-35s: %8.2f ms\n", $label, $ms);
    return $res;
}

echo "TIMING PDP COMPONENTS FOR ID {$id}:\n";
echo str_repeat('-', 50) . "\n";

$p = timeIt("1. find(\$id, true)", fn() => $sanPham->find($id, true));
$reviews = timeIt("2. getReviewsByProduct", fn() => $reviewModel->getReviewsByProduct($id, [], 1, 8));
$reviewStats = timeIt("3. getReviewStats", fn() => $reviewModel->getReviewStats($id, $p));
$questions = timeIt("4. getQuestionsByProduct", fn() => $questionModel->getQuestionsByProduct($id, 20));
$qCount = timeIt("5. countQuestionsByProduct", fn() => $questionModel->countQuestionsByProduct($id));

require_once __DIR__ . '/../app/services/AssociationRuleRecommender.php';
$assoc = new AssociationRuleRecommender($pdo);
$freq = timeIt("6. frequentlyBought", fn() => $assoc->getFrequentlyBoughtTogether($id, 4));

require_once __DIR__ . '/../app/models/ProductVideo.php';
$pv = new ProductVideo($pdo);
$vids = timeIt("7. productVideos", fn() => $pv->getPublishedVideosByProductId($id));

$menu = timeIt("8. menuTree", fn() => $sanPham->menuTree());
