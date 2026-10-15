<?php
require_once __DIR__ . '/../app/config/db.php';

$client = new MongoDB\Client(getenv('MONGO_URI') ?: 'mongodb://mongodb:27017');
$db = $client->selectDatabase(getenv('MONGO_DB') ?: 'skinsyntax');

echo "--- ALL RECORDS IN ai_product_videos ---\n";
$cursor = $db->ai_product_videos->find([]);
$count = 0;
foreach ($cursor as $doc) {
    $count++;
    echo json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
echo "Total records: $count\n";
