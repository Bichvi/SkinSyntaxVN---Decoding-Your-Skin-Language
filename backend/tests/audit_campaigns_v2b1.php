<?php
require_once __DIR__ . '/../app/config/db.php';
$camps = iterator_to_array($pdo->ai_video_reward_campaigns->find([]));
echo "Total campaigns in collection: " . count($camps) . PHP_EOL;

foreach ($camps as $idx => $c) {
    echo "--- Campaign #" . ($idx + 1) . " ---" . PHP_EOL;
    echo "ID (_id): " . (string)$c['_id'] . PHP_EOL;
    echo "Campaign ID: " . ($c['campaign_id'] ?? 'N/A') . PHP_EOL;
    echo "Campaign Name: " . ($c['campaign_name'] ?? 'N/A') . PHP_EOL;
    echo "Video ID: " . ($c['video_id'] ?? 'N/A') . PHP_EOL;
    echo "Publication ID: " . ($c['publication_id'] ?? 'N/A') . PHP_EOL;
    echo "Active: " . ((!empty($c['is_active'])) ? 'TRUE' : 'FALSE') . PHP_EOL;
    echo "Discount: " . ($c['discount_type'] ?? '') . " " . ($c['discount_value'] ?? '') . PHP_EOL;
    echo "Max Discount: " . ($c['max_discount_amount'] ?? 'N/A') . PHP_EOL;
    echo "Min Order: " . ($c['min_order_amount'] ?? 'N/A') . PHP_EOL;
    echo "Total Quota: " . ($c['total_quota'] ?? 0) . PHP_EOL;
    echo "Issued Count: " . ($c['issued_count'] ?? 0) . PHP_EOL;
    echo "User Limit: " . ($c['user_limit'] ?? 1) . PHP_EOL;
    echo "Start Date: " . (isset($c['start_date']) && $c['start_date'] instanceof MongoDB\BSON\UTCDateTime ? $c['start_date']->toDateTime()->format('Y-m-d H:i:s') : ($c['start_date'] ?? 'N/A')) . PHP_EOL;
    echo "End Date: " . (isset($c['end_date']) && $c['end_date'] instanceof MongoDB\BSON\UTCDateTime ? $c['end_date']->toDateTime()->format('Y-m-d H:i:s') : ($c['end_date'] ?? 'N/A')) . PHP_EOL;
    echo "Created At: " . (isset($c['created_at']) && $c['created_at'] instanceof MongoDB\BSON\UTCDateTime ? $c['created_at']->toDateTime()->format('Y-m-d H:i:s') : ($c['created_at'] ?? 'N/A')) . PHP_EOL;
    echo "Updated At: " . (isset($c['updated_at']) && $c['updated_at'] instanceof MongoDB\BSON\UTCDateTime ? $c['updated_at']->toDateTime()->format('Y-m-d H:i:s') : ($c['updated_at'] ?? 'N/A')) . PHP_EOL;
    echo PHP_EOL;
}
