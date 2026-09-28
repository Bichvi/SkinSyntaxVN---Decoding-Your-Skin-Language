<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/services/ContentBasedRecommender.php';
global $db;

$sanPhamModel = new SanPham($db);
$service = new ContentBasedRecommender();

echo "=================================================================\n";
echo "STEP 7: COMPREHENSIVE VERIFICATION SUITE\n";
echo "=================================================================\n";

$passCount = 0;
$failCount = 0;

function assertCondition(string $name, bool $cond, string $details = '') {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] $name\n";
        if ($details) echo "        $details\n";
        $passCount++;
    } else {
        echo " [FAIL] $name\n";
        if ($details) echo "        $details\n";
        $failCount++;
    }
}

// 1. Guest without history does not crash and returns empty array
$guestRecs = $sanPhamModel->getContentBasedRecommendations([], 8);
assertCondition(
    "Guest without history does not crash and returns empty array",
    is_array($guestRecs) && empty($guestRecs),
    "Returned " . count($guestRecs) . " items"
);

// 2. Recent viewed recommendations work with valid input
$viewed = [1000]; // SP1000 Eucerin Gel Rửa Mặt 75ml
$recs = $sanPhamModel->getContentBasedRecommendations($viewed, 8);
assertCondition(
    "Recent viewed returns recommendations",
    is_array($recs) && count($recs) > 0,
    "Returned " . count($recs) . " items for viewed ID 1000"
);

// 3. Exclude viewed items from recommendations
$recsIds = array_map(fn($p) => (int)$p['ma_san_pham'], $recs);
$containsViewed = in_array(1000, $recsIds);
assertCondition(
    "Exclude viewed items",
    !$containsViewed,
    "Viewed ID 1000 is NOT in recommended IDs: [" . implode(', ', $recsIds) . "]"
);

// 4. No duplicate IDs in recommendation output
$uniqueIds = array_unique($recsIds);
assertCondition(
    "No duplicate IDs in recommendation result",
    count($uniqueIds) === count($recsIds),
    "Count unique: " . count($uniqueIds) . " vs count total: " . count($recsIds)
);

// 5. Diversity rule works: no duplicate product families in recommendations
$families = [];
$hasDuplicateFamily = false;
$dupFamilyName = '';
foreach ($recs as $p) {
    $fam = $service->extractProductFamily($p['ten_san_pham']);
    if (isset($families[$fam])) {
        $hasDuplicateFamily = true;
        $dupFamilyName = $fam;
        break;
    }
    $families[$fam] = true;
}
assertCondition(
    "Diversity rule works: no duplicate product families in Top-N",
    !$hasDuplicateFamily,
    !$hasDuplicateFamily ? "All " . count($recs) . " products have distinct product families" : "Duplicate family: $dupFamilyName"
);

// 6. Deterministic: consecutive calls with same input produce identical output
$recs2 = $sanPhamModel->getContentBasedRecommendations($viewed, 8);
$recsIds2 = array_map(fn($p) => (int)$p['ma_san_pham'], $recs2);
assertCondition(
    "Deterministic recommendations across runs",
    $recsIds === $recsIds2,
    "Run 1: [" . implode(', ', $recsIds) . "] === Run 2: [" . implode(', ', $recsIds2) . "]"
);

// 7. Simple Recommender is not affected and still functions
$simpleRecs = $sanPhamModel->getSimpleRecommenderProducts(8);
assertCondition(
    "Simple Recommender is intact and unaffected",
    is_array($simpleRecs) && count($simpleRecs) === 8 && isset($simpleRecs[0]['recommender_meta']['weighted_rating']),
    "Returned " . count($simpleRecs) . " products with weighted rating: " . round($simpleRecs[0]['recommender_meta']['weighted_rating'] ?? 0, 4)
);

// 8. HTTP Home returns HTTP 200
$ch = curl_init('http://skinsyntax-nginx/?r=home');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assertCondition(
    "HTTP Home returns 200 OK",
    $httpCode === 200,
    "HTTP status code: $httpCode (Response length: " . strlen($response ?? '') . " bytes)"
);

echo "\n-----------------------------------------------------------------\n";
echo "SUMMARY: Passed $passCount / " . ($passCount + $failCount) . " checks.\n";
echo "=================================================================\n";
