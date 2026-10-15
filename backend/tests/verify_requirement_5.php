<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/ProductVideo.php';

$client = new MongoDB\Client(getenv('MONGO_URI') ?: 'mongodb://mongodb:27017');
$db = $client->selectDatabase(getenv('MONGO_DB') ?: 'skinsyntax');
$pvModel = new ProductVideo($db);

echo "=== REQUIREMENT 5 SECURITY & ACCESS CONTROL VERIFICATION ===\n";

// 1. Non-existent 24-char ObjectId
$resNonExistent = file_get_contents("http://skinsyntax-nginx/index.php?r=product_video_stream&id=000000000000000000000000");
$headers1 = $http_response_header ?? [];
$status1 = explode(' ', $headers1[0] ?? '')[1] ?? 'unknown';
echo "1. Non-existent ID: Status = $status1 (Expected 404)\n";

// 2. Traversal attempt
$ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
$resTrav = file_get_contents("http://skinsyntax-nginx/index.php?r=product_video_stream&id=../../etc/passwd", false, $ctx);
$status2 = explode(' ', $http_response_header[0] ?? '')[1] ?? 'unknown';
echo "2. Path traversal: Status = $status2 (Expected 404)\n";

// 3. Unpublished / hidden video test
$testHiddenDoc = [
    'video_id' => '6acc27e526e9d8acf59c457e',
    'title' => 'Test Hidden Video Unpublished',
    'associated_product_ids' => ['6376'],
    'primary_product_id' => '6376',
    'display_priority' => 5,
    'is_published' => false,
    'created_at' => new MongoDB\BSON\UTCDateTime(),
    'updated_at' => new MongoDB\BSON\UTCDateTime(),
];
$insertResult = $db->ai_product_videos->insertOne($testHiddenDoc);
$hiddenId = (string)$insertResult->getInsertedId();

$resHidden = file_get_contents("http://skinsyntax-nginx/index.php?r=product_video_stream&id=" . $hiddenId, false, $ctx);
$status3 = explode(' ', $http_response_header[0] ?? '')[1] ?? 'unknown';
echo "3. Unpublished / Hidden video stream: Status = $status3 (Expected 404)\n";

// Verify hidden video does not show up in getPublishedVideosByProductId
$publishedList = $pvModel->getPublishedVideosByProductId('6376');
$foundHidden = false;
foreach ($publishedList as $p) {
    if ($p['id'] === $hiddenId) $foundHidden = true;
}
echo "4. Hidden video in product detail query: " . ($foundHidden ? "FOUND (FAIL)" : "NOT FOUND (PASS)") . "\n";

// Cleanup test hidden record
$db->ai_product_videos->deleteOne(['_id' => new MongoDB\BSON\ObjectId($hiddenId)]);
echo "5. Cleaned up test hidden document ID $hiddenId.\n";
