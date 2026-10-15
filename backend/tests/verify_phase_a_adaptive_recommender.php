<?php
/**
 * verify_phase_a_adaptive_recommender.php
 * Automated verification test suite for Phase A Adaptive Recommender Production
 *
 * Verifies:
 * - 21 Automated Test Cases
 * - 7 Reproducible Before / After Demonstration Stages (A to G)
 * - Regression check on Simple Top-24 (100% identical to baseline)
 * - Home HTTP 200
 */

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/services/ContentBasedRecommender.php';
global $db;

$spModel = new SanPham($db);
$recommender = new ContentBasedRecommender();

echo "=================================================================\n";
echo "SKINSYNTAXVN — PHASE A ADAPTIVE RECOMMENDER VERIFICATION\n";
echo "=================================================================\n\n";

$passCount = 0;
$failCount = 0;

function runAssert(string $testName, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] {$testName}" . ($detail !== '' ? " ({$detail})" : "") . "\n";
    } else {
        $failCount++;
        echo " [FAIL] {$testName}" . ($detail !== '' ? " ({$detail})" : "") . "\n";
    }
}

// -----------------------------------------------------------------
// TEST 1: New guest -> SIMPLE
// -----------------------------------------------------------------
$recs1 = $spModel->getHybridRecommendations([], null, 4, []);
$firstMeta1 = $recs1[0]['recommender_meta'] ?? [];
runAssert(
    "Test 1: New guest -> SIMPLE",
    ($firstMeta1['algorithm_mode'] ?? '') === 'SIMPLE' && count($recs1) === 4,
    "Mode: " . ($firstMeta1['algorithm_mode'] ?? 'none') . ", Items: " . count($recs1)
);

// -----------------------------------------------------------------
// TEST 2: Search only -> BEHAVIOR_CONTENT
// -----------------------------------------------------------------
$recs2 = $spModel->getHybridRecommendations([], null, 4, [
    'search' => ['serum da dầu']
]);
$firstMeta2 = $recs2[0]['recommender_meta'] ?? [];
runAssert(
    "Test 2: Search only -> BEHAVIOR_CONTENT",
    ($firstMeta2['algorithm_mode'] ?? '') === 'BEHAVIOR_CONTENT' && ($firstMeta2['dominant_signal'] ?? '') === 'SEARCH',
    "Mode: " . ($firstMeta2['algorithm_mode'] ?? '') . ", Dominant: " . ($firstMeta2['dominant_signal'] ?? '')
);

// -----------------------------------------------------------------
// TEST 3: View only -> BEHAVIOR_CONTENT
// -----------------------------------------------------------------
$recs3 = $spModel->getHybridRecommendations(['1000'], null, 4, [
    'view' => ['1000']
]);
$firstMeta3 = $recs3[0]['recommender_meta'] ?? [];
runAssert(
    "Test 3: View only -> BEHAVIOR_CONTENT",
    ($firstMeta3['algorithm_mode'] ?? '') === 'BEHAVIOR_CONTENT' && ($firstMeta3['dominant_signal'] ?? '') === 'VIEW',
    "Mode: " . ($firstMeta3['algorithm_mode'] ?? '') . ", Dominant: " . ($firstMeta3['dominant_signal'] ?? '')
);

// -----------------------------------------------------------------
// TEST 4: Cart only -> BEHAVIOR_CONTENT
// -----------------------------------------------------------------
$recs4 = $spModel->getHybridRecommendations([], null, 4, [
    'cart' => ['1000' => 1]
]);
$firstMeta4 = $recs4[0]['recommender_meta'] ?? [];
runAssert(
    "Test 4: Cart only -> BEHAVIOR_CONTENT",
    ($firstMeta4['algorithm_mode'] ?? '') === 'BEHAVIOR_CONTENT' && ($firstMeta4['dominant_signal'] ?? '') === 'CART',
    "Mode: " . ($firstMeta4['algorithm_mode'] ?? '') . ", Dominant: " . ($firstMeta4['dominant_signal'] ?? '')
);

// -----------------------------------------------------------------
// TEST 5: Search + View -> combined vector
// -----------------------------------------------------------------
$recs5 = $spModel->getHybridRecommendations(['1000'], null, 4, [
    'search' => ['serum mụn'],
    'view' => ['1000']
]);
$firstMeta5 = $recs5[0]['recommender_meta'] ?? [];
$act5 = $firstMeta5['active_signals'] ?? [];
runAssert(
    "Test 5: Search + View -> combined vector",
    ($firstMeta5['algorithm_mode'] ?? '') === 'BEHAVIOR_CONTENT' && in_array('search', $act5, true) && in_array('view', $act5, true),
    "Active signals: " . implode(', ', $act5)
);

// -----------------------------------------------------------------
// TEST 6: Search + View + Cart -> combined vector
// -----------------------------------------------------------------
$recs6 = $spModel->getHybridRecommendations(['1000'], null, 4, [
    'search' => ['kem chống nắng'],
    'view' => ['1000'],
    'cart' => ['71' => 2]
]);
$firstMeta6 = $recs6[0]['recommender_meta'] ?? [];
$act6 = $firstMeta6['active_signals'] ?? [];
runAssert(
    "Test 6: Search + View + Cart -> combined vector",
    ($firstMeta6['algorithm_mode'] ?? '') === 'BEHAVIOR_CONTENT' 
    && in_array('search', $act6, true) 
    && in_array('view', $act6, true) 
    && in_array('cart', $act6, true),
    "Dominant: " . ($firstMeta6['dominant_signal'] ?? '') . ", Signals: " . implode(', ', $act6)
);

// -----------------------------------------------------------------
// TEST 7: Logged-in purchase only -> PURCHASE_CONTENT
// -----------------------------------------------------------------
$recs7 = $spModel->getHybridRecommendations([], null, 4, [
    'purchases' => [
        ['ma_san_pham' => '1000', 'so_luong' => 1, 'ngay_dat' => date('Y-m-d H:i:s', time() - 86400 * 5)]
    ]
]);
$firstMeta7 = $recs7[0]['recommender_meta'] ?? [];
runAssert(
    "Test 7: Logged-in purchase only -> PURCHASE_CONTENT",
    ($firstMeta7['algorithm_mode'] ?? '') === 'PURCHASE_CONTENT' && ($firstMeta7['dominant_signal'] ?? '') === 'PURCHASE',
    "Mode: " . ($firstMeta7['algorithm_mode'] ?? '') . ", Dominant: " . ($firstMeta7['dominant_signal'] ?? '')
);

// -----------------------------------------------------------------
// TEST 8: Logged-in behavior without survey -> BEHAVIOR_CONTENT
// -----------------------------------------------------------------
$recs8 = $spModel->getHybridRecommendations(['266'], null, 4, [
    'search' => ['toner'],
    'view' => ['266'],
    'purchases' => [
        ['ma_san_pham' => '1000', 'so_luong' => 1, 'ngay_dat' => date('Y-m-d H:i:s')]
    ]
]);
$firstMeta8 = $recs8[0]['recommender_meta'] ?? [];
runAssert(
    "Test 8: Logged-in behavior without survey -> BEHAVIOR_CONTENT",
    ($firstMeta8['algorithm_mode'] ?? '') === 'BEHAVIOR_CONTENT',
    "Mode: " . ($firstMeta8['algorithm_mode'] ?? '') . ", Signals: " . implode(', ', $firstMeta8['active_signals'] ?? [])
);

// -----------------------------------------------------------------
// TEST 9: Profile only -> PROFILE_CONTENT
// -----------------------------------------------------------------
$skinProfile9 = [
    'skin_type' => 'Da dầu',
    'van_de_da' => ['Mụn', 'Lỗ chân lông to'],
    'muc_tieu_cham_soc' => 'Kiểm soát dầu nhờn',
    'ngan_sach' => 350000
];
$recs9 = $spModel->getHybridRecommendations([], $skinProfile9, 4, []);
$firstMeta9 = $recs9[0]['recommender_meta'] ?? [];
runAssert(
    "Test 9: Profile only -> PROFILE_CONTENT",
    ($firstMeta9['algorithm_mode'] ?? '') === 'PROFILE_CONTENT' && ($firstMeta9['dominant_signal'] ?? '') === 'PROFILE',
    "Mode: " . ($firstMeta9['algorithm_mode'] ?? '') . ", Dominant: " . ($firstMeta9['dominant_signal'] ?? '')
);

// -----------------------------------------------------------------
// TEST 10: Profile + behavior -> ADAPTIVE_HYBRID
// -----------------------------------------------------------------
$recs10 = $spModel->getHybridRecommendations(['1000'], $skinProfile9, 4, [
    'view' => ['1000'],
    'search' => ['serum b5']
]);
$firstMeta10 = $recs10[0]['recommender_meta'] ?? [];
runAssert(
    "Test 10: Profile + behavior -> ADAPTIVE_HYBRID",
    ($firstMeta10['algorithm_mode'] ?? '') === 'ADAPTIVE_HYBRID' && ($firstMeta10['dominant_signal'] ?? '') === 'HYBRID',
    "Mode: " . ($firstMeta10['algorithm_mode'] ?? '') . ", Dominant: " . ($firstMeta10['dominant_signal'] ?? '')
);

// -----------------------------------------------------------------
// TEST 11: Profile + purchase -> ADAPTIVE_HYBRID
// -----------------------------------------------------------------
$recs11 = $spModel->getHybridRecommendations([], $skinProfile9, 4, [
    'purchases' => [
        ['ma_san_pham' => '1000', 'so_luong' => 1, 'ngay_dat' => date('Y-m-d')]
    ]
]);
$firstMeta11 = $recs11[0]['recommender_meta'] ?? [];
runAssert(
    "Test 11: Profile + purchase -> ADAPTIVE_HYBRID",
    ($firstMeta11['algorithm_mode'] ?? '') === 'ADAPTIVE_HYBRID',
    "Mode: " . ($firstMeta11['algorithm_mode'] ?? '') . ", Signals: " . implode(', ', $firstMeta11['active_signals'] ?? [])
);

// -----------------------------------------------------------------
// TEST 12: Partial profile -> graceful fallback
// -----------------------------------------------------------------
// Case 12a: Partial profile with budget only + behavior -> PARTIAL_PROFILE_FALLBACK
$partialProfileA = ['ngan_sach' => 250000];
$recs12a = $spModel->getHybridRecommendations(['1000'], $partialProfileA, 4, ['view' => ['1000']]);
$meta12a = $recs12a[0]['recommender_meta'] ?? [];

// Case 12b: Partial profile with budget only, NO behavior -> graceful fallback to SIMPLE Top-4
$recs12b = $spModel->getHybridRecommendations([], $partialProfileA, 4, []);
$meta12b = $recs12b[0]['recommender_meta'] ?? [];

runAssert(
    "Test 12: Partial profile -> graceful fallback",
    ($meta12a['algorithm_mode'] ?? '') === 'PARTIAL_PROFILE_FALLBACK' && ($meta12b['algorithm_mode'] ?? '') === 'SIMPLE' && count($recs12b) === 4,
    "12a Mode: " . ($meta12a['algorithm_mode'] ?? '') . ", 12b Mode: " . ($meta12b['algorithm_mode'] ?? '') . " (Items: " . count($recs12b) . ")"
);

// -----------------------------------------------------------------
// TEST 13: Invalid/deleted product IDs -> skip safely
// -----------------------------------------------------------------
$recs13 = $spModel->getHybridRecommendations(['99999999', 'invalid_xyz'], null, 4, [
    'view' => ['99999999', 'invalid_xyz'],
    'cart' => ['non_existing_sku' => 5],
    'purchases' => [['ma_san_pham' => 'fake_pid', 'so_luong' => 1]]
]);
$meta13 = $recs13[0]['recommender_meta'] ?? [];
runAssert(
    "Test 13: Invalid/deleted product IDs -> skip safely",
    count($recs13) === 4 && ($meta13['algorithm_mode'] ?? '') === 'SIMPLE',
    "Skipped invalid IDs cleanly and safely fell back to SIMPLE"
);

// -----------------------------------------------------------------
// TEST 14: Empty vectors -> fallback safely
// -----------------------------------------------------------------
$recs14 = $spModel->getHybridRecommendations([], null, 4, [
    'search' => ['@#$%^&*()_+'], // Stopwords/punctuations producing 0 tokens
]);
$meta14 = $recs14[0]['recommender_meta'] ?? [];
runAssert(
    "Test 14: Empty vectors -> fallback safely",
    count($recs14) === 4 && ($meta14['algorithm_mode'] ?? '') === 'SIMPLE',
    "Handled empty search token vector gracefully"
);

// -----------------------------------------------------------------
// TEST 15: No duplicate products
// -----------------------------------------------------------------
$ids10 = array_column($recs10, 'ma_san_pham');
$uniqueIds10 = array_unique($ids10);
runAssert(
    "Test 15: No duplicate products in recommendation result",
    count($ids10) === count($uniqueIds10),
    "Items: " . implode(', ', $ids10)
);

// -----------------------------------------------------------------
// TEST 16: Product Family Diversity
// -----------------------------------------------------------------
$familiesSeen = [];
$diversityOk = true;
foreach ($recs10 as $p) {
    $fam = $recommender->extractProductFamily($p['ten_san_pham'] ?? '');
    if ($fam !== '') {
        if (isset($familiesSeen[$fam])) {
            $diversityOk = false;
            break;
        }
        $familiesSeen[$fam] = true;
    }
}
runAssert(
    "Test 16: Product Family Diversity (max 1 SKU per product family)",
    $diversityOk,
    "Families count: " . count($familiesSeen)
);

// -----------------------------------------------------------------
// TEST 17: Recent viewed exclusion
// -----------------------------------------------------------------
// View SP 1000 and SP 266: neither must be recommended
$recs17 = $spModel->getHybridRecommendations(['1000', '266'], null, 4, [
    'view' => ['1000', '266']
]);
$recIds17 = array_map('strval', array_column($recs17, 'ma_san_pham'));
$viewExclusionOk = !in_array('1000', $recIds17, true) && !in_array('266', $recIds17, true);
runAssert(
    "Test 17: Recent viewed exclusion",
    $viewExclusionOk,
    "Viewed [1000, 266] excluded from [" . implode(', ', $recIds17) . "]"
);

// Also verify Correction 1: Cart SKU exclusion & Purchase inclusion
$recsCartExcl = $spModel->getHybridRecommendations([], null, 4, [
    'cart' => ['1000' => 1]
]);
$recCartIds = array_map('strval', array_column($recsCartExcl, 'ma_san_pham'));
runAssert(
    "Test 17b: Cart SKU exclusion (SKU in cart not recommended)",
    !in_array('1000', $recCartIds, true),
    "Cart SKU 1000 excluded from [" . implode(', ', $recCartIds) . "]"
);

// -----------------------------------------------------------------
// TEST 18: Catalog validity 100%
// -----------------------------------------------------------------
$allRecsChecked = array_merge($recs1, $recs2, $recs3, $recs5, $recs9, $recs10);
$allValidInDb = true;
foreach ($allRecsChecked as $p) {
    $pid = $p['ma_san_pham'] ?? '';
    $price = (float)($p['gia_ban'] ?? 0);
    $status = $p['trang_thai'] ?? '';
    if ($pid === '' || $price <= 0 || $status !== 'active') {
        $allValidInDb = false;
        break;
    }
}
runAssert(
    "Test 18: Catalog validity 100% (all products exist, active, price > 0)",
    $allValidInDb,
    "Checked " . count($allRecsChecked) . " recommendation records"
);

// -----------------------------------------------------------------
// TEST 19: Deterministic repeated input
// -----------------------------------------------------------------
$runA = $spModel->getHybridRecommendations(['1000'], $skinProfile9, 4, ['view' => ['1000']]);
$runB = $spModel->getHybridRecommendations(['1000'], $skinProfile9, 4, ['view' => ['1000']]);
$deterministic = (array_column($runA, 'ma_san_pham') === array_column($runB, 'ma_san_pham'));
if ($deterministic) {
    for ($i = 0; $i < count($runA); $i++) {
        if ($runA[$i]['recommender_meta']['final_score'] !== $runB[$i]['recommender_meta']['final_score']) {
            $deterministic = false;
            break;
        }
    }
}
runAssert(
    "Test 19: Deterministic repeated input",
    $deterministic,
    "Both runs produced identical product IDs and scores"
);

// -----------------------------------------------------------------
// TEST 20: Home HTTP 200
// -----------------------------------------------------------------
$ch = curl_init('http://skinsyntax-nginx/index.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$homeHtml = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
runAssert(
    "Test 20: Home HTTP 200",
    $httpCode === 200 && str_contains($homeHtml, 'SkinSyntax'),
    "HTTP Status: {$httpCode}, Payload length: " . strlen($homeHtml) . " bytes"
);

// -----------------------------------------------------------------
// TEST 21: Simple Top-24 MUST remain identical to baseline
// -----------------------------------------------------------------
$currentSimple24 = $spModel->getSimpleRecommenderProducts(24);
$baselineTop24 = json_decode(file_get_contents(__DIR__ . '/output/simple_baseline_top24.json'), true);
$simple24Matches = (count($currentSimple24) === 24 && count($baselineTop24) === 24);
$mismatchRank = null;
for ($i = 0; $i < 24; $i++) {
    $curId = (string)($currentSimple24[$i]['ma_san_pham'] ?? '');
    $baseId = (string)($baselineTop24[$i]['ma_san_pham'] ?? '');
    if ($curId !== $baseId) {
        $simple24Matches = false;
        $mismatchRank = $i + 1;
        break;
    }
}
runAssert(
    "Test 21: Simple Top-24 identical to baseline",
    $simple24Matches,
    $simple24Matches ? "24/24 exact match" : "Mismatch at rank #{$mismatchRank}"
);

echo "\nTest Summary: {$passCount} Passed, {$failCount} Failed.\n";

echo "\n=================================================================\n";
echo "REPRODUCIBLE BEFORE / AFTER DEMONSTRATION (STAGES A TO G)\n";
echo "=================================================================\n\n";

$stages = [
    'Stage A (Completely new guest)' => [
        'views' => [],
        'profile' => null,
        'signals' => []
    ],
    'Stage B (Search "serum da dầu")' => [
        'views' => [],
        'profile' => null,
        'signals' => ['search' => ['serum da dầu']]
    ],
    'Stage C (Then view recommended serum #1000)' => [
        'views' => ['1000'],
        'profile' => null,
        'signals' => ['search' => ['serum da dầu'], 'view' => ['1000']]
    ],
    'Stage D (Then add sunscreen #412 to cart)' => [
        'views' => ['1000'],
        'profile' => null,
        'signals' => [
            'search' => ['serum da dầu'],
            'view' => ['1000'],
            'cart' => ['412' => 1]
        ]
    ],
    'Stage E (Login user with purchase history #266, no survey)' => [
        'views' => [],
        'profile' => null,
        'signals' => [
            'purchases' => [
                ['ma_san_pham' => '266', 'so_luong' => 1, 'ngay_dat' => date('Y-m-d H:i:s', time() - 86400 * 10)]
            ]
        ]
    ],
    'Stage F (User completes skin survey: Da dầu, Mụn, 350k)' => [
        'views' => [],
        'profile' => [
            'skin_type' => 'Da dầu',
            'van_de_da' => ['Mụn', 'Lỗ chân lông to'],
            'muc_tieu_cham_soc' => 'Kiểm soát dầu mụn',
            'ngan_sach' => 350000
        ],
        'signals' => []
    ],
    'Stage G (Same user now has Profile + Full Behavior: Search + View + Cart + Purchases)' => [
        'views' => ['1000'],
        'profile' => [
            'skin_type' => 'Da dầu',
            'van_de_da' => ['Mụn', 'Lỗ chân lông to'],
            'muc_tieu_cham_soc' => 'Kiểm soát dầu mụn',
            'ngan_sach' => 350000
        ],
        'signals' => [
            'search' => ['serum da dầu'],
            'view' => ['1000'],
            'cart' => ['412' => 1],
            'purchases' => [
                ['ma_san_pham' => '266', 'so_luong' => 1, 'ngay_dat' => date('Y-m-d H:i:s', time() - 86400 * 10)]
            ]
        ]
    ],
];

foreach ($stages as $stageName => $ctx) {
    echo "-----------------------------------------------------------------\n";
    echo ">>> {$stageName}\n";
    echo "-----------------------------------------------------------------\n";

    $recs = $spModel->getHybridRecommendations($ctx['views'], $ctx['profile'], 4, $ctx['signals']);
    $firstMeta = $recs[0]['recommender_meta'] ?? [];

    echo "MODE:            " . ($firstMeta['algorithm_mode'] ?? 'N/A') . "\n";
    echo "ACTIVE SIGNALS:  " . json_encode($firstMeta['active_signals'] ?? []) . "\n";
    echo "DOMINANT SIGNAL: " . ($firstMeta['dominant_signal'] ?? 'N/A') . "\n";
    echo "TOP-4 PRODUCT IDs: " . json_encode(array_column($recs, 'ma_san_pham')) . "\n";
    echo "TOP-4 PRODUCTS:\n";

    foreach ($recs as $idx => $item) {
        $meta = $item['recommender_meta'] ?? [];
        $rank = $idx + 1;
        $name = $item['ten_san_pham'] ?? 'Unknown';
        $pid = $item['ma_san_pham'] ?? '';
        $final = number_format((float)($meta['final_score'] ?? 0), 4);
        $content = number_format((float)($meta['content_score'] ?? 0), 4);
        $skin = number_format((float)($meta['skin_score'] ?? 0), 2);
        $budget = number_format((float)($meta['budget_score'] ?? 0), 2);
        $bContrib = number_format((float)($meta['behavior_contribution'] ?? 0), 4);
        $pContrib = number_format((float)($meta['profile_contribution'] ?? 0), 4);
        $reasons = implode('; ', $meta['reason_tags'] ?? []);

        echo "  #{$rank} [ID: {$pid}] {$name}\n";
        echo "     Score Breakdown: Final={$final} | Content={$content} | Skin={$skin} | Budget={$budget} | B_Contrib={$bContrib} | P_Contrib={$pContrib}\n";
        echo "     Reason Tags:     {$reasons}\n";
    }
    echo "\n";
}
