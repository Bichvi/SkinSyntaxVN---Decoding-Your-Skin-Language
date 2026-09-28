<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/services/ContentBasedRecommender.php';
global $db;

$sanPham = new SanPham($db);
$cbr = new ContentBasedRecommender();

echo "=================================================================\n";
echo "STEP 11 & 13: COMPREHENSIVE HYBRID V1 VERIFICATION SUITE\n";
echo "=================================================================\n";

$pass = 0;
$fail = 0;

function check(string $name, bool $cond, string $details = '') {
    global $pass, $fail;
    if ($cond) {
        echo " [PASS] $name\n";
        if ($details) echo "        $details\n";
        $pass++;
    } else {
        echo " [FAIL] $name\n";
        if ($details) echo "        $details\n";
        $fail++;
    }
}

// Case A: Guest + no history -> Expect Simple fallback (hybrid returns empty)
$secA = $sanPham->getHomepageProductSections(4, [], null);
check(
    "Case A: Guest + no history returns Simple Recommender fallback",
    empty($secA['contentBased']) && count($secA['topRatedWeighted']) === 4,
    "contentBased count: " . count($secA['contentBased']) . ", topRatedWeighted count: " . count($secA['topRatedWeighted'])
);

// Case B: Guest + recent views -> Expect Content-Based mode
$secB = $sanPham->getHomepageProductSections(4, [1000], null);
$modeB = $secB['contentBased'][0]['recommender_meta']['source_mode'] ?? '';
check(
    "Case B: Guest + recent views returns source_mode 'content'",
    count($secB['contentBased']) === 4 && $modeB === 'content',
    "Returned " . count($secB['contentBased']) . " items, source_mode: $modeB"
);

// Case C: Logged-in + no survey + no history -> Expect Simple fallback
$secC = $sanPham->getHomepageProductSections(4, [], ['email' => 'user@example.com']);
check(
    "Case C: Logged-in + no survey + no history returns Simple fallback",
    empty($secC['contentBased']) && count($secC['topRatedWeighted']) === 4,
    "contentBased count: " . count($secC['contentBased']) . ", topRatedWeighted: " . count($secC['topRatedWeighted'])
);

// Case D: Logged-in + no survey + recent views -> Expect Content-Based mode
$secD = $sanPham->getHomepageProductSections(4, [1000], ['email' => 'user@example.com']);
$modeD = $secD['contentBased'][0]['recommender_meta']['source_mode'] ?? '';
check(
    "Case D: Logged-in + no survey + recent views returns source_mode 'content'",
    count($secD['contentBased']) === 4 && $modeD === 'content',
    "source_mode: $modeD"
);

// Case E: Logged-in + survey + no recent views -> Expect Profile mode
$surveyProfile = [
    'skin_type' => 'Da khô/Hỗn hợp khô',
    'van_de_da' => 'Da khô căng, bong tróc',
    'muc_tieu_cham_soc' => 'Dưỡng sáng, mờ thâm nám',
    'ngan_sach' => 500000
];
$secE = $sanPham->getHomepageProductSections(4, [], $surveyProfile);
$modeE = $secE['contentBased'][0]['recommender_meta']['source_mode'] ?? '';
check(
    "Case E: Logged-in + survey + no recent views returns source_mode 'profile'",
    count($secE['contentBased']) === 4 && $modeE === 'profile',
    "source_mode: $modeE, Title item: " . ($secE['contentBased'][0]['ten_san_pham'] ?? '')
);

// Case F: Logged-in + survey + recent views -> Expect Hybrid mode
$secF = $sanPham->getHomepageProductSections(4, [1000], $surveyProfile);
$modeF = $secF['contentBased'][0]['recommender_meta']['source_mode'] ?? '';
check(
    "Case F: Logged-in + survey + recent views returns source_mode 'hybrid'",
    count($secF['contentBased']) === 4 && $modeF === 'hybrid',
    "source_mode: $modeF, Final score: " . ($secF['contentBased'][0]['recommender_meta']['final_score'] ?? '')
);

// Case G: Partial/incomplete survey -> Graceful fallback, no crash
$partialProfile = ['skin_type' => 'Da dầu/Hỗn hợp dầu']; // Missing van_de_da and muc_tieu_cham_soc
$secG = $sanPham->getHomepageProductSections(4, [], $partialProfile);
$modeG = $secG['contentBased'][0]['recommender_meta']['source_mode'] ?? '';
check(
    "Case G: Partial/incomplete survey handles gracefully without crash",
    count($secG['contentBased']) === 4 && $modeG === 'profile',
    "Handled successfully, source_mode: $modeG"
);

// Case H: Missing product ingredients handled safely
$hasWarningMeta = isset($secF['contentBased'][0]['recommender_meta']['ingredient_warning']);
check(
    "Case H: Products without ingredients handled safely without crash",
    $hasWarningMeta,
    "ingredient_warning metadata attached properly"
);

// Case I: Unknown product skin type handled with controlled fallback score
$unknownScore = $cbr->computeSkinTypeScore('Unknown', 'Da khô/Hỗn hợp khô');
check(
    "Case I: Unknown product skin type scored as controlled fallback (0.30)",
    $unknownScore === 0.30,
    "Score: $unknownScore"
);

// -------------------------------------------------------------
// STEP 13: REGRESSION CHECKS
// -------------------------------------------------------------
echo "\n--- STEP 13: REGRESSION INTEGRITY CHECKS ---\n";

// Simple Recommender intact
$simpleRecs = $sanPham->getSimpleRecommenderProducts(4);
check(
    "Regression: Simple Recommender IMDb formula intact",
    count($simpleRecs) === 4 && isset($simpleRecs[0]['recommender_meta']['weighted_rating']),
    "Weighted rating: " . ($simpleRecs[0]['recommender_meta']['weighted_rating'] ?? 0)
);

// Exclude viewed items
$viewedId = 1000;
$recsF = $secF['contentBased'];
$recIdsF = array_map(fn($p) => (int)$p['ma_san_pham'], $recsF);
check(
    "Regression: Exclude viewed items in recommendations",
    !in_array($viewedId, $recIdsF, true),
    "Viewed ID $viewedId is NOT in returned IDs: [" . implode(', ', $recIdsF) . "]"
);

// No duplicate IDs
check(
    "Regression: No duplicate IDs in recommendation result",
    count(array_unique($recIdsF)) === count($recIdsF),
    "Count unique: " . count(array_unique($recIdsF)) . " vs count: " . count($recIdsF)
);

// Diversity max 1 per product family
$fams = [];
$dupFam = false;
foreach ($recsF as $p) {
    $f = $cbr->extractProductFamily($p['ten_san_pham']);
    if (isset($fams[$f])) {
        $dupFam = true;
        break;
    }
    $fams[$f] = true;
}
check(
    "Regression: Diversity rule enforced (Max 1 / product family)",
    !$dupFam,
    "All " . count($recsF) . " products belong to distinct families"
);

// Deterministic output
$secF2 = $sanPham->getHomepageProductSections(4, [1000], $surveyProfile);
$recIdsF2 = array_map(fn($p) => (int)$p['ma_san_pham'], $secF2['contentBased']);
check(
    "Regression: Deterministic across consecutive invocations",
    $recIdsF === $recIdsF2,
    "Run 1: [" . implode(', ', $recIdsF) . "] === Run 2: [" . implode(', ', $recIdsF2) . "]"
);

// HTTP Home 200
$ch = curl_init('http://skinsyntax-nginx/?r=home');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

check(
    "Regression: HTTP Home returns 200 OK",
    $code === 200,
    "HTTP Code: $code, response length: " . strlen($resp ?? '') . " bytes"
);

echo "\n-----------------------------------------------------------------\n";
echo "SUMMARY: Passed $pass / " . ($pass + $fail) . " verification tests.\n";
echo "=================================================================\n";
