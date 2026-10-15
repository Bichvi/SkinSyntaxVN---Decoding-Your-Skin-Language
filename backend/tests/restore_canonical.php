<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/VideoRewardCampaign.php';

$rewardModel = new VideoRewardCampaign($pdo);

// Delete test campaigns if any
$pdo->ai_video_reward_campaigns->deleteMany(['campaign_name' => ['$regex' => 'Test V2C']]);
$pdo->customer_vouchers->deleteMany(['customer_id' => ['$regex' => '^cust_v2c_']]);
$pdo->voucher->deleteMany(['customer_id' => ['$regex' => '^cust_v2c_']]);
$pdo->ai_video_watch_sessions->deleteMany(['customer_id' => ['$regex' => '^cust_v2c_']]);

// Restore canonical campaign states
$rewardModel->toggleActive('rew_141ad8d65d', false);
$rewardModel->toggleActive('rew_7e6ada3dc3', true);

echo "CLEANUP & RESTORE COMPLETE:\n";
foreach ($pdo->ai_video_reward_campaigns->find([]) as $c) {
    echo "Campaign: " . $c['campaign_id'] . " | active=" . ($c['is_active'] ? 'true' : 'false') . " | name=" . $c['campaign_name'] . "\n";
}
