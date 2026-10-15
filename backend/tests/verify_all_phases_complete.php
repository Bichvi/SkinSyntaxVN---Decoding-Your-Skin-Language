<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
/**
 * Comprehensive Verification Suite for Recommendation Engine Phases A, B, C, D
 *
 * Usage inside docker container:
 * php /var/www/html/tests/verify_all_phases_complete.php
 */

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/models/TuongTac.php';
require_once __DIR__ . '/../app/models/HoaDon.php';
require_once __DIR__ . '/../app/services/ContentBasedRecommender.php';
require_once __DIR__ . '/../app/services/InteractionLogger.php';
require_once __DIR__ . '/../app/services/CollaborativeFilteringRecommender.php';
require_once __DIR__ . '/../app/services/AssociationRuleRecommender.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertTest(string $description, bool $condition, string $details = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$description}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$description}" . ($details !== '' ? " -> {$details}" : '') . "\n";
    }
}

echo "====================================================================\n";
echo "SKINSYNTAX VN - COMPREHENSIVE RECOMMENDER VERIFICATION (PHASES A-D)\n";
echo "====================================================================\n\n";

global $db;
if (!$db) {
    echo "FATAL: MongoDB connection unavailable.\n";
    exit(1);
}

// -------------------------------------------------------------
// PHASE A: ADAPTIVE CONTENT-BASED RECOMMENDER
// -------------------------------------------------------------
echo "[1/4] Testing Phase A: Adaptive Content-Based Engine...\n";

$spModel = new SanPham($db);

// Test 1: New guest -> SIMPLE mode
$recs1 = $spModel->getHybridRecommendations([], null, 4, []);
$meta1 = $recs1[0]['recommender_meta'] ?? [];
assertTest("Context router routes new guest to SIMPLE mode", ($meta1['algorithm_mode'] ?? '') === 'SIMPLE' && count($recs1) === 4, "Mode: " . ($meta1['algorithm_mode'] ?? 'none'));

// Test 2: Search only -> BEHAVIOR_CONTENT (dominant: SEARCH)
$recs2 = $spModel->getHybridRecommendations([], null, 4, ['search' => ['serum da dầu']]);
$meta2 = $recs2[0]['recommender_meta'] ?? [];
assertTest("Context router detects search intent -> BEHAVIOR_CONTENT (SEARCH)", ($meta2['algorithm_mode'] ?? '') === 'BEHAVIOR_CONTENT' && ($meta2['dominant_signal'] ?? '') === 'SEARCH');

// Test 3: View only -> BEHAVIOR_CONTENT (dominant: VIEW)
$recs3 = $spModel->getHybridRecommendations(['1000'], null, 4, ['view' => ['1000']]);
$meta3 = $recs3[0]['recommender_meta'] ?? [];
assertTest("Context router detects view intent -> BEHAVIOR_CONTENT (VIEW)", ($meta3['algorithm_mode'] ?? '') === 'BEHAVIOR_CONTENT' && ($meta3['dominant_signal'] ?? '') === 'VIEW');

// Test 4: Cart only -> BEHAVIOR_CONTENT (dominant: CART)
$recs4 = $spModel->getHybridRecommendations([], null, 4, ['cart' => ['1000' => 1]]);
$meta4 = $recs4[0]['recommender_meta'] ?? [];
assertTest("Context router detects cart intent -> BEHAVIOR_CONTENT (CART)", ($meta4['algorithm_mode'] ?? '') === 'BEHAVIOR_CONTENT' && ($meta4['dominant_signal'] ?? '') === 'CART');

// Test 5: Profile without behavior -> PROFILE_CONTENT
$skinProfile = [
    'skin_type' => 'Da dầu',
    'van_de_da' => ['Mụn', 'Lỗ chân lông to'],
    'muc_tieu_cham_soc' => 'Kiểm soát dầu nhờn',
    'ngan_sach' => 350000
];
$recs5 = $spModel->getHybridRecommendations([], $skinProfile, 4, []);
$meta5 = $recs5[0]['recommender_meta'] ?? [];
assertTest("Context router detects profile without behavior -> PROFILE_CONTENT", ($meta5['algorithm_mode'] ?? '') === 'PROFILE_CONTENT');

// Test 6: Profile + Behavior -> ADAPTIVE_HYBRID
$recs6 = $spModel->getHybridRecommendations(['1000'], $skinProfile, 4, ['search' => ['kem chống nắng']]);
$meta6 = $recs6[0]['recommender_meta'] ?? [];
assertTest("Context router detects profile + behavior -> ADAPTIVE_HYBRID mode", ($meta6['algorithm_mode'] ?? '') === 'ADAPTIVE_HYBRID');

// Test 7: Section 3 returns adaptive products with explanation breakdown
$sections = $spModel->getHomepageProductSections(
    8,
    [],
    null,
    ['search' => ['mụn', 'tràm trà']]
);
$adaptiveSec = $sections['forYou'] ?? [];
assertTest("Adaptive recommendation section returns products", !empty($adaptiveSec), "Count: " . count($adaptiveSec));
if (!empty($adaptiveSec)) {
    $firstItem = $adaptiveSec[0];
    assertTest("Adaptive recommendation includes explainability breakdown", !empty($firstItem['recommender_meta']['reason']) || !empty($firstItem['recommender_meta']['reason_tags']));
}

// -------------------------------------------------------------
// PHASE B: UNIFIED INTERACTION LOGGING
// -------------------------------------------------------------
echo "\n[2/4] Testing Phase B: Unified Interaction Logging...\n";

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$tuongTacModel = new TuongTac($db);
$currentSessionId = 'test_verify_' . bin2hex(random_bytes(6));
$testProductId = '1000';

// Test 8: Log view interaction
InteractionLogger::log('view', $testProductId, null, $currentSessionId);
assertTest("InteractionLogger::logView executed without error", true);

// Test 9: Log cart interaction
InteractionLogger::log('add_to_cart', $testProductId, null, $currentSessionId, ['quantity' => 2]);
assertTest("InteractionLogger::logCart executed without error", true);

// Test 10: Verify record exists in MongoDB collection tuong_tac_nguoi_dung
$foundRecords = $tuongTacModel->getSessionInteractions($currentSessionId);
assertTest("tuong_tac_nguoi_dung contains logged records for session", count($foundRecords) >= 2, "Count: " . count($foundRecords));

// Cleanup test records
$db->tuong_tac_nguoi_dung->deleteMany(['session_id' => $currentSessionId]);

// -------------------------------------------------------------
// PHASE C: COLLABORATIVE FILTERING (EXPERIMENTAL)
// -------------------------------------------------------------
echo "\n[3/4] Testing Phase C: Collaborative Filtering (kNN & Funk MF)...\n";

$cfRecommender = new CollaborativeFilteringRecommender($db);

// Test 11: Train/Build CF model from existing orders & interactions
$trainResult = $cfRecommender->buildModel();
assertTest("Collaborative Filtering model builds successfully", isset($trainResult['item_similarity']));
assertTest("CF model builds item similarity matrix", !empty($trainResult['item_similarity']), "Items: " . count($trainResult['item_similarity'] ?? []));

// Test 12: Item-Item kNN recommendation
// Grab any valid product id from san_pham
$sampleProduct = $db->san_pham->findOne(['trang_thai' => 'active'], ['projection' => ['ma_san_pham' => 1]]);
$sampleId = (string)($sampleProduct['ma_san_pham'] ?? '1');
$knnRecs = $cfRecommender->recommendSimilarItems($sampleId, 4);
assertTest("recommendSimilarItems returns candidates or graceful empty array", is_array($knnRecs));

// Test 13: User CF recommendation (kNN + MF)
$userRecs = $cfRecommender->recommendForUser(1, [$sampleId], 4);
assertTest("recommendForUser computes CF recommendations array", is_array($userRecs));

// -------------------------------------------------------------
// PHASE D: ASSOCIATION RULES & FREQUENTLY BOUGHT TOGETHER
// -------------------------------------------------------------
echo "\n[4/4] Testing Phase D: Association Rules Mining (Pairwise Co-occurrence)...\n";

$assocRecommender = new AssociationRuleRecommender($db);

// Test 14: Mine association rules from orders & cart
$miningResult = $assocRecommender->mineRules();
assertTest("AssociationRuleRecommender::mineRules builds rules cache", isset($miningResult['rules']) || isset($miningResult['total_transactions']));

// Test 15: getFrequentlyBoughtTogether returns valid products with metadata
$fbtProducts = $assocRecommender->getFrequentlyBoughtTogether($sampleId, 3);
assertTest("getFrequentlyBoughtTogether returns recommendations array", is_array($fbtProducts) && !empty($fbtProducts), "Count: " . count($fbtProducts));
if (!empty($fbtProducts)) {
    $fbtFirst = $fbtProducts[0];
    assertTest("FBT recommendation has enriched fields (ten_san_pham, gia_ban)", !empty($fbtFirst['ten_san_pham']) && isset($fbtFirst['gia_ban']), "Name: " . ($fbtFirst['ten_san_pham'] ?? 'none'));
}

// -------------------------------------------------------------
// END-TO-END HTTP TESTS (via Nginx)
// -------------------------------------------------------------
echo "\n[5/5] Testing End-to-End HTTP Endpoints...\n";

// HTTP 1: Homepage
$ch = curl_init('http://skinsyntax-nginx/index.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$homeHtml = curl_exec($ch);
$homeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertTest("Homepage responds with HTTP 200", $homeHttpCode === 200, "HTTP {$homeHttpCode}");
assertTest("Homepage renders adaptive section container", strpos($homeHtml, 'Gợi ý dành cho bạn') !== false || strpos($homeHtml, 'GỢI Ý HÔM NAY') !== false);

// HTTP 2: Product Detail Page
$ch = curl_init("http://skinsyntax-nginx/index.php?r=chitiet&id={$sampleId}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$detailHtml = curl_exec($ch);
$detailHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertTest("Product Detail responds with HTTP 200", $detailHttpCode === 200, "HTTP {$detailHttpCode}");
assertTest("Product Detail renders Frequently Bought Together section", strpos($detailHtml, 'Mua Kèm Tiết Kiệm') !== false || strpos($detailHtml, 'frequently-bought-card') !== false);

// -------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------
echo "\n====================================================================\n";
echo "VERIFICATION SUMMARY:\n";
echo "Total Tests: {$totalTests}\n";
echo "Passed:      {$passedTests}\n";
echo "Failed:      {$failedTests}\n";
echo "====================================================================\n";

if ($failedTests > 0) {
    echo "RESULT: FAIL ({$failedTests} test(s) failed)\n";
    exit(1);
} else {
    echo "RESULT: ALL TESTS PASSED! FULL PIPELINE READY FOR PRODUCTION.\n";
    exit(0);
}
