<?php
require_once __DIR__ . '/../app/config/db.php';
$pubs = iterator_to_array($pdo->ai_product_videos->find([]));
echo 'Publications count: ' . count($pubs) . PHP_EOL;
foreach ($pubs as $p) {
    echo 'ID: ' . (string)$p['_id'] . ' | video_id: ' . ($p['video_id'] ?? '') . ' | published: ' . (($p['is_published'] ?? false) ? 'YES' : 'NO') . ' | products: ' . json_encode($p['associated_product_ids'] ?? []) . ' | duration: ' . ($p['duration'] ?? 0) . PHP_EOL;
}
$camps = iterator_to_array($pdo->ai_video_reward_campaigns->find([]));
echo 'Campaigns count: ' . count($camps) . PHP_EOL;
foreach ($camps as $c) {
    echo 'Campaign ID: ' . ($c['campaign_id'] ?? '') . ' | video_id: ' . ($c['video_id'] ?? '') . ' | is_active: ' . (($c['is_active'] ?? false) ? 'YES' : 'NO') . ' | quota: ' . ($c['total_quota'] ?? 0) . ' | issued: ' . ($c['issued_count'] ?? 0) . PHP_EOL;
}
