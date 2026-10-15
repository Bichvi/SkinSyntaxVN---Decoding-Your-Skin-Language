<?php
require_once __DIR__ . '/../app/config/db.php';

$client = new MongoDB\Client(getenv('MONGO_URI') ?: 'mongodb://mongodb:27017');
$db = $client->selectDatabase(getenv('MONGO_DB') ?: 'skinsyntax');

$targetId = new MongoDB\BSON\ObjectId('6acc6bd54e1a7ffa0e0672b2');

$res = $db->ai_product_videos->updateOne(
    ['_id' => $targetId],
    ['$set' => [
        'title' => 'Syna 3D - Giới thiệu Combo Nước Tẩy Trang L\'Oreal',
        'display_priority' => 10,
        'associated_product_ids' => ['6376'],
        'primary_product_id' => '6376',
        'is_published' => true,
    ]]
);

echo "Matched: " . $res->getMatchedCount() . ", Modified: " . $res->getModifiedCount() . "\n";
