<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/VideoRewardCampaign.php';
$m = new VideoRewardCampaign($pdo);
$res = $m->saveCampaign([
    'campaign_name' => 'Ưu đãi Syna 3D - Giảm 15% khi xem video',
    'video_id' => '6acc27e526e9d8acf59c457e',
    'publication_id' => '6acc6bd54e1a7ffa0e0672b2',
    'discount_type' => 'percentage',
    'discount_value' => 15,
    'max_discount_amount' => 50000,
    'min_order_amount' => 100000,
    'total_quota' => 200,
    'user_limit' => 1,
    'is_active' => true,
    'start_date' => date('Y-m-d H:i:s', time() - 3600),
    'end_date' => date('Y-m-d H:i:s', time() + 86400 * 30),
]);
echo json_encode($res, JSON_UNESCAPED_UNICODE) . PHP_EOL;
