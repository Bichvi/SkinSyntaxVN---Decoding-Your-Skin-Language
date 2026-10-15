<?php
// backend/tests/test_phase_v1.php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/ProductVideo.php';

echo "========================================================\n";
echo "SKINSYNTAXVN — PHASE V1 COMPREHENSIVE AUTOMATED TEST SUITE\n";
echo "========================================================\n\n";

$results = [];

function assertTest($name, $condition, $details = '') {
    global $results;
    if ($condition) {
        echo " [PASS] $name\n";
        if ($details) echo "        -> $details\n";
        $results[] = ['name' => $name, 'status' => 'PASS', 'details' => $details];
        return true;
    } else {
        echo "![FAIL] $name\n";
        if ($details) echo "        -> $details\n";
        $results[] = ['name' => $name, 'status' => 'FAIL', 'details' => $details];
        return false;
    }
}

$client = new MongoDB\Client(getenv('MONGO_URI') ?: 'mongodb://mongodb:27017');
$db = $client->selectDatabase(getenv('MONGO_DB') ?: 'skinsyntax');
$pvModel = new ProductVideo($db);

// 1. One product -> one video (Product 6376)
$vids6376 = $pvModel->getPublishedVideosByProductId('6376');
assertTest(
    "1. One product -> one video (Product 6376)",
    count($vids6376) >= 1 && isset($vids6376[0]['_id']),
    "Found " . count($vids6376) . " published video(s). First ID: " . ($vids6376[0]['_id'] ?? 'none')
);

// Verify stream URL format
$pubId = (string)$vids6376[0]['_id'];
$streamUrl = "http://skinsyntax-nginx/index.php?r=product_video_stream&id=" . $pubId;

// Test HTTP 200 Stream for Published Video
$ch = curl_init($streamUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

assertTest(
    "1.1 Stream HTTP 200 and Content-Type video/mp4",
    $httpCode === 200 && str_contains($contentType, 'video/mp4'),
    "HTTP Code: $httpCode | Content-Type: $contentType"
);

// Test HTTP 206 Partial Content (Range: bytes=0-1023)
$ch = curl_init($streamUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Range: bytes=0-1023']);
$resRange = curl_exec($ch);
$httpCodeRange = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assertTest(
    "1.2 Media streaming HTTP Range seeking (206 Partial Content)",
    $httpCodeRange === 206 && str_contains($resRange, 'Content-Range: bytes 0-1023/'),
    "HTTP Code: $httpCodeRange | Range header validated"
);

// 2. Product without video (Product 995)
$vids995 = $pvModel->getPublishedVideosByProductId('995');
assertTest(
    "2. Product without video (Product 995)",
    count($vids995) === 0,
    "Expected 0 videos, got " . count($vids995)
);

// 3. Multiple products -> shared video
// Associate video with both 6376 and 995
$resAssoc = $pvModel->publishVideo([
    'video_id' => (string)$vids6376[0]['video_id'],
    'associated_product_ids' => ['6376', '995'],
    'title' => 'Syna 3D - Multi Product Shared Showcase',
    'description' => 'Shared video across combo 6376 and product 995',
    'display_priority' => 15
]);
assertTest(
    "3. Associate video with multiple products (6376 & 995)",
    ($resAssoc['ok'] ?? false) === true,
    "Updated publication ID: " . ($resAssoc['publication_id'] ?? '')
);

$vids995After = $pvModel->getPublishedVideosByProductId('995');
$vids6376After = $pvModel->getPublishedVideosByProductId('6376');
assertTest(
    "3.1 Shared video appears on both product pages",
    count($vids995After) >= 1 && count($vids6376After) >= 1,
    "Product 995 count: " . count($vids995After) . " | Product 6376 count: " . count($vids6376After)
);

// Revert 995 association so QA data remains clean
$pvModel->publishVideo([
    'video_id' => (string)$vids6376[0]['video_id'],
    'associated_product_ids' => ['6376'],
    'title' => 'Syna 3D - Giới thiệu Combo Nước Tẩy Trang L\'Oreal',
    'description' => 'Chăm sóc làn da dịu nhẹ cùng Syna 3D và Combo L\'Oreal',
    'display_priority' => 10
]);

// 4. Unpublished video toggle
// Unpublish
$toggleOff = $pvModel->setPublishedStatus($pubId, false);
assertTest(
    "4. Admin unpublishes video without deleting source asset",
    ($toggleOff['ok'] ?? false) === true,
    "setPublishedStatus(pubId, false) returned ok=true"
);

// Verify stream returns 404 for unpublished video
$ch = curl_init($streamUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
$resUnpub = curl_exec($ch);
$codeUnpub = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assertTest(
    "4.1 Unpublished video cannot be streamed publicly (HTTP 404)",
    $codeUnpub === 404,
    "HTTP Code: $codeUnpub"
);

// Verify product 6376 returns 0 published videos
$vidsUnpub = $pvModel->getPublishedVideosByProductId('6376');
assertTest(
    "4.2 Product detail hides video when unpublished",
    count($vidsUnpub) === 0,
    "Published video count: " . count($vidsUnpub)
);

// Re-publish
$toggleOn = $pvModel->setPublishedStatus($pubId, true);
assertTest(
    "4.3 Re-publish restores public availability",
    ($toggleOn['ok'] ?? false) === true,
    "setPublishedStatus(pubId, true) returned ok=true"
);

// 5. Unapproved / Failed video publication prevention
// Try publishing non-existent video
$fakePublish = $pvModel->publishVideo([
    'video_id' => '000000000000000000000000',
    'associated_product_ids' => ['6376'],
    'title' => 'Fake Unapproved Video',
    'display_priority' => 1
]);
assertTest(
    "5. Reject publication of non-existent or unrendered video asset",
    ($fakePublish['ok'] ?? true) === false && str_contains($fakePublish['message'] ?? '', 'không tồn tại'),
    "Error message: " . ($fakePublish['message'] ?? '')
);

// 6. Security & Path Traversal Verification
$traversalUrls = [
    'Path traversal attempt with ../' => "http://skinsyntax-nginx/index.php?r=product_video_stream&id=../../etc/passwd",
    'Path traversal with encoded slashes' => "http://skinsyntax-nginx/index.php?r=product_video_stream&id=..%2F..%2Fetc%2Fpasswd",
    'Malformed non-hex ID' => "http://skinsyntax-nginx/index.php?r=product_video_stream&id=invalid_id_not_hex_123",
    'Non-existent 24-char ObjectId' => "http://skinsyntax-nginx/index.php?r=product_video_stream&id=000000000000000000000000",
];

foreach ($traversalUrls as $testTitle => $tUrl) {
    $ch = curl_init($tUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    $resTrav = curl_exec($ch);
    $codeTrav = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $noSystemPath = !str_contains($resTrav, '/app/storage') && !str_contains($resTrav, '/var/www') && !str_contains($resTrav, 'root:x:0:0:');
    
    assertTest(
        "6. Security: $testTitle",
        in_array($codeTrav, [400, 404], true) && $noSystemPath,
        "HTTP Code: $codeTrav | Path disclosure avoided: " . ($noSystemPath ? 'YES' : 'NO')
    );
}

// 7. Deterministic priority order
// Add a higher priority publication
$resHighPriority = $pvModel->publishVideo([
    'video_id' => (string)$vids6376[0]['video_id'],
    'associated_product_ids' => ['6376'],
    'title' => 'Syna 3D - High Priority Video',
    'description' => 'Testing priority sorting',
    'display_priority' => 99
]);
$vidsPriority = $pvModel->getPublishedVideosByProductId('6376');
assertTest(
    "7. Deterministic display priority ordering",
    count($vidsPriority) >= 1 && ($vidsPriority[0]['display_priority'] ?? 0) === 99,
    "Top video priority: " . ($vidsPriority[0]['display_priority'] ?? 'none') . " | Title: " . ($vidsPriority[0]['title'] ?? '')
);

// Clean up high priority publication created for test
if (!empty($resHighPriority['publication_id'])) {
    $db->ai_product_videos->deleteOne(['_id' => new MongoDB\BSON\ObjectId($resHighPriority['publication_id'])]);
}

echo "\n========================================================\n";
$passCount = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$totalCount = count($results);
echo "SUMMARY: $passCount / $totalCount TESTS PASSED\n";
echo "========================================================\n";
