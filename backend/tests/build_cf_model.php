<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CollaborativeFilteringRecommender.php';
global $db;

$cf = new CollaborativeFilteringRecommender($db);
$model = $cf->buildModel($db);
echo "CF Model Built successfully!\n";
echo "Total users: " . $model['total_users'] . "\n";
echo "Total items: " . $model['total_items'] . "\n";
echo "Global mean: " . $model['global_mean'] . "\n";

$recs = $cf->recommendSimilarItems('1000', 4);
echo "Similar items to #1000 via CF:\n";
print_r($recs);
