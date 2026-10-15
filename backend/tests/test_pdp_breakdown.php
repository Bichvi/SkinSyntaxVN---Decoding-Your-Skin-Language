<?php
// backend/tests/test_pdp_breakdown.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';

$sanPham = new SanPham($pdo);

// Find real product ID
$realDoc = $pdo->san_pham->findOne(['trang_thai' => ['$nin' => ['hidden', 'inactive']]]);
$realId = (string)($realDoc['id'] ?? $realDoc['ma_san_pham'] ?? 103);
echo "Testing real product ID: {$realId} (Name: {$realDoc['ten_san_pham']})\n";

// 1. Single product find
$t0 = microtime(true);
$p = $sanPham->find($realId, true);
$findMs = round((microtime(true) - $t0) * 1000, 2);

// 2. Frequently bought together
$t1 = microtime(true);
$frequentlyBought = [];
if (is_file(dirname(__DIR__) . '/app/services/AssociationRuleRecommender.php')) {
    require_once dirname(__DIR__) . '/app/services/AssociationRuleRecommender.php';
    $assoc = new AssociationRuleRecommender($pdo);
    $frequentlyBought = $assoc->getFrequentlyBoughtTogether($realId, 4);
}
$freqMs = round((microtime(true) - $t1) * 1000, 2);

// 3. Product videos
$t2 = microtime(true);
require_once dirname(__DIR__) . '/app/models/ProductVideo.php';
require_once dirname(__DIR__) . '/app/models/VideoRewardCampaign.php';
$pvModel = new ProductVideo($pdo);
$rwModel = new VideoRewardCampaign($pdo);
$videos = $pvModel->getPublishedVideosByProductId($realId);
foreach ($videos as &$v) {
    $v['reward_campaign'] = $rwModel->getActiveCampaignForVideo((string)($v['video_id'] ?? ''), (string)($v['id'] ?? ''));
}
unset($v);
$vidMs = round((microtime(true) - $t2) * 1000, 2);

echo "PDP Atlas Direct Query Latencies:\n";
echo "1. find('{$realId}'): {$findMs} ms (Found: " . (!empty($p) ? 'YES' : 'NO') . ")\n";
echo "2. frequentlyBought: {$freqMs} ms (Items: " . count($frequentlyBought) . ")\n";
echo "3. productVideos: {$vidMs} ms (Videos: " . count($videos) . ")\n";
