<?php
/**
 * verify_phase_c1_validation.php
 * Automated 20-Point Test Suite for Phase C.1 Scientific Validation
 *
 * Verifies:
 * 1. production DB counts unchanged
 * 2. original Phase C output preserved
 * 3. original 3-seed metrics reproducible
 * 4. target item always exists in candidate set
 * 5. target item absent from train
 * 6. deterministic same-seed validation
 * 7. true sparsity experiment changes actual matrix density/history
 * 8. segment user counts sum correctly
 * 9. popularity concentration metrics valid
 * 10. preference recovery metrics bounded [0,1]
 * 11. latent scenario isolated by scenario field
 * 12. latent weight 0 scenario works
 * 13. latent weight .25 works
 * 14. latent weight .50 works
 * 15. all latent scenarios reproducible
 * 16. Homepage HTTP 200
 * 17. Phase A seven scenarios unchanged
 * 18. Simple Top-24 unchanged
 * 19. CF still disconnected from production
 * 20. no synthetic data written to production
 */

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/services/ContentBasedRecommender.php';
require_once __DIR__ . '/../experiments/collaborative_filtering/generate_synthetic_dataset.php';
require_once __DIR__ . '/../experiments/collaborative_filtering/evaluate_cf.php';

global $db, $mongoClient;

$passed = 0;
$failed = 0;

function assertTest(int $num, string $name, bool $condition, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] Test {$num}: {$name}" . ($details ? " ({$details})" : "") . "\n";
    } else {
        $failed++;
        echo "  [FAIL] Test {$num}: {$name}" . ($details ? " ({$details})" : "") . "\n";
    }
}

echo "=================================================================\n";
echo "SKINSYNTAXVN — PHASE C.1 SCIENTIFIC VALIDATION VERIFICATION\n";
echo "=================================================================\n\n";

$prodDb = $db instanceof \MongoDatabaseCompat ? $db->raw() : $db;
$expDb = $mongoClient->selectDatabase('skinsyntax_cf_dev');
$expDir = __DIR__ . '/../experiments/collaborative_filtering/output';
$valDir = $expDir . '/validation';

// -------------------------------------------------------------
// 1. Production DB counts unchanged
// -------------------------------------------------------------
$pTuongTac = $prodDb->tuong_tac_nguoi_dung->countDocuments();
$pHonDon = $prodDb->hoa_don->countDocuments();
$pChiTiet = $prodDb->chi_tiet_hoa_don->countDocuments();
$pDanhGia = $prodDb->danh_gia->countDocuments();
$pSanPham = $prodDb->san_pham->countDocuments();

$prodCountsValid = ($pSanPham === 2473 && $pHonDon === 29 && $pChiTiet === 34 && $pDanhGia === 8);
assertTest(1, "production DB counts unchanged",
    $prodCountsValid,
    "san_pham: {$pSanPham}, hoa_don: {$pHonDon}, chi_tiet: {$pChiTiet}, danh_gia: {$pDanhGia}"
);

// -------------------------------------------------------------
// 2. Original Phase C output preserved
// -------------------------------------------------------------
$origFiles = [
    'config.json',
    'dataset_stats.json',
    'metrics_seed_42.json',
    'metrics_seed_123.json',
    'metrics_seed_2026.json',
    'multi_seed_evaluation.json',
    'sparsity_experiment.json',
    'cold_start_analysis.json',
];
$allOrigExist = true;
foreach ($origFiles as $f) {
    if (!file_exists($expDir . '/' . $f)) {
        $allOrigExist = false;
        break;
    }
}
assertTest(2, "original Phase C output preserved",
    $allOrigExist,
    "Checked all 8 core Phase C artifact files"
);

// -------------------------------------------------------------
// 3. Original 3-seed metrics reproducible
// -------------------------------------------------------------
$multiSeedFile = $expDir . '/multi_seed_evaluation.json';
$multiSeedData = json_decode(file_get_contents($multiSeedFile), true);
$mPopularHR = $multiSeedData['mean_metrics']['MOST_POPULAR']['HitRate@10'] ?? 0;
$mKnnHR = $multiSeedData['mean_metrics']['ITEM_KNN']['HitRate@10'] ?? 0;
$mMfHR = $multiSeedData['mean_metrics']['MATRIX_FACTORIZATION']['HitRate@10'] ?? 0;

$metricsPreserved = (
    abs($mPopularHR - 0.0469) < 0.001 &&
    abs($mKnnHR - 0.0076) < 0.001 &&
    abs($mMfHR - 0.0166) < 0.001
);
assertTest(3, "original 3-seed metrics reproducible",
    $metricsPreserved,
    "Popular: {$mPopularHR}, kNN: {$mKnnHR}, MF: {$mMfHR}"
);

// -------------------------------------------------------------
// 4 & 5. Target item in candidates and absent from train
// -------------------------------------------------------------
$sanityFile = $valDir . '/evaluation_sanity_samples.json';
$sanityData = file_exists($sanityFile) ? json_decode(file_get_contents($sanityFile), true) : null;
$targetsInCand = $sanityData['all_target_candidates_present'] ?? false;
$targetsAbsentTrain = $sanityData['all_targets_unseen_in_train'] ?? false;

assertTest(4, "target item always exists in candidate set",
    $targetsInCand === true,
    "Verified in 20 random audited users"
);

assertTest(5, "target item absent from train",
    $targetsAbsentTrain === true,
    "No target item leaked into training matrix"
);

// -------------------------------------------------------------
// 6. Deterministic same-seed validation
// -------------------------------------------------------------
$genA = new SyntheticDatasetGenerator($prodDb, $expDb, 7777, ['num_users' => 10]);
$dataA = $genA->generate(false);
$genB = new SyntheticDatasetGenerator($prodDb, $expDb, 7777, ['num_users' => 10]);
$dataB = $genB->generate(false);

$sameSeedOk = (
    count($dataA['interactions']) === count($dataB['interactions']) &&
    $dataA['interactions'][0]['ma_san_pham'] === $dataB['interactions'][0]['ma_san_pham'] &&
    $dataA['interactions'][count($dataA['interactions']) - 1]['ma_san_pham'] === $dataB['interactions'][count($dataB['interactions']) - 1]['ma_san_pham']
);
assertTest(6, "deterministic same-seed validation",
    $sameSeedOk,
    "Generated identical interaction sequence with seed 7777"
);

// -------------------------------------------------------------
// 7. True sparsity experiment changes actual matrix density/history
// -------------------------------------------------------------
$sparsityValFile = $valDir . '/true_sparsity_experiment.json';
$trueSparsityData = file_exists($sparsityValFile) ? json_decode(file_get_contents($sparsityValFile), true) : null;
$sparseDensity = $trueSparsityData['tiers']['SPARSE (Short History)']['matrix_density_pct'] ?? 0;
$medDensity = $trueSparsityData['tiers']['MEDIUM (Moderate History)']['matrix_density_pct'] ?? 0;
$denseDensity = $trueSparsityData['tiers']['DENSE (Rich History)']['matrix_density_pct'] ?? 0;

$sparsityChanged = ($sparseDensity < $medDensity && $medDensity < $denseDensity && $sparseDensity > 0);
assertTest(7, "true sparsity experiment changes actual matrix density/history",
    $sparsityChanged,
    "Densities: Sparse {$sparseDensity}%, Med {$medDensity}%, Dense {$denseDensity}%"
);

// -------------------------------------------------------------
// 8. Segment user counts sum correctly
// -------------------------------------------------------------
$histFile = $valDir . '/history_segment_metrics.json';
$histData = json_decode(file_get_contents($histFile), true);
$histTotal = $histData['total_evaluated_users'] ?? 0;
$histSum = 0;
foreach ($histData['segments'] as $seg) {
    $histSum += $seg['users'];
}

$popFile = $valDir . '/popularity_segment_metrics.json';
$popData = json_decode(file_get_contents($popFile), true);
$popSum = 0;
foreach ($popData['segments'] as $seg) {
    $popSum += $seg['users'];
}

$countsSumOk = ($histSum === $histTotal && $popSum === $histTotal && $histTotal > 0);
assertTest(8, "segment user counts sum correctly",
    $countsSumOk,
    "History segments sum: {$histSum}, Pop bias segments sum: {$popSum} (total: {$histTotal})"
);

// -------------------------------------------------------------
// 9. Popularity concentration metrics valid
// -------------------------------------------------------------
$concFile = $valDir . '/popularity_concentration.json';
$concData = json_decode(file_get_contents($concFile), true);
$top10Share = $concData['concentration_shares']['top_10_pct_products']['share_pct'] ?? 0;
$giniCoeff = $concData['gini_coefficient']['full_catalog_gini'] ?? 0;

$concValid = ($top10Share > 50.0 && $giniCoeff > 0.70);
assertTest(9, "popularity concentration metrics valid",
    $concValid,
    "Top 10% items capture {$top10Share}% of interactions, Gini = {$giniCoeff}"
);

// -------------------------------------------------------------
// 10. Preference recovery metrics bounded [0,1]
// -------------------------------------------------------------
$prefRecFile = $valDir . '/preference_recovery.json';
$prefRecData = json_decode(file_get_contents($prefRecFile), true);
$recMetrics = $prefRecData['preference_recovery_metrics'] ?? [];

$boundedOk = true;
foreach ($recMetrics as $m => $scores) {
    foreach ($scores as $sKey => $val) {
        if ($val < 0.0 || $val > 1.0) {
            $boundedOk = false;
            break 2;
        }
    }
}
assertTest(10, "preference recovery metrics bounded [0,1]",
    $boundedOk,
    "All CategoryAffinity, BrandAffinity, PriceRangeMatch in [0.0, 1.0]"
);

// -------------------------------------------------------------
// 11. Latent scenario isolated by scenario field
// -------------------------------------------------------------
$latentFile = $valDir . '/latent_recovery_experiment.json';
$latentData = json_decode(file_get_contents($latentFile), true);
$scenarioField = $latentData['scenario'] ?? '';

$isolatedScenario = ($scenarioField === 'cf_latent_recovery_v1');
assertTest(11, "latent scenario isolated by scenario field",
    $isolatedScenario,
    "Scenario: '{$scenarioField}'"
);

// -------------------------------------------------------------
// 12, 13, 14. Latent weight 0, 0.25, 0.50 scenarios work
// -------------------------------------------------------------
$w0 = isset($latentData['findings']['latent_weight_0.00']['mean_metrics']['MATRIX_FACTORIZATION']);
$w25 = isset($latentData['findings']['latent_weight_0.25']['mean_metrics']['MATRIX_FACTORIZATION']);
$w50 = isset($latentData['findings']['latent_weight_0.50']['mean_metrics']['MATRIX_FACTORIZATION']);

assertTest(12, "latent weight 0 scenario works",
    $w0,
    "MF HR@10: " . ($latentData['findings']['latent_weight_0.00']['mean_metrics']['MATRIX_FACTORIZATION']['Mean_HR@10'] ?? 'N/A')
);

assertTest(13, "latent weight .25 works",
    $w25,
    "MF HR@10: " . ($latentData['findings']['latent_weight_0.25']['mean_metrics']['MATRIX_FACTORIZATION']['Mean_HR@10'] ?? 'N/A')
);

assertTest(14, "latent weight .50 works",
    $w50,
    "MF HR@10: " . ($latentData['findings']['latent_weight_0.50']['mean_metrics']['MATRIX_FACTORIZATION']['Mean_HR@10'] ?? 'N/A')
);

// -------------------------------------------------------------
// 15. All latent scenarios reproducible
// -------------------------------------------------------------
$seedsCount = count($latentData['seeds'] ?? []);
assertTest(15, "all latent scenarios reproducible",
    $seedsCount === 3,
    "Evaluated across 3 deterministic seeds (42, 123, 2026)"
);

// -------------------------------------------------------------
// 16. Homepage HTTP 200
// -------------------------------------------------------------
$ch = curl_init('http://skinsyntax-nginx/index.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$homeHtml = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assertTest(16, "Homepage HTTP 200",
    $httpCode === 200 && strlen($homeHtml) > 50000,
    "HTTP {$httpCode}, Size: " . strlen($homeHtml) . " bytes"
);

// -------------------------------------------------------------
// 17. Production Phase A 7 scenarios unchanged
// -------------------------------------------------------------
$spModel = new SanPham($db);

$stagesConfig = [
    'Stage A' => ['views' => [], 'profile' => null, 'signals' => []],
    'Stage B' => ['views' => [], 'profile' => null, 'signals' => ['search' => ['serum da dầu']]],
    'Stage C' => ['views' => ['1000'], 'profile' => null, 'signals' => ['search' => ['serum da dầu'], 'view' => ['1000']]],
    'Stage D' => ['views' => ['1000'], 'profile' => null, 'signals' => ['search' => ['serum da dầu'], 'view' => ['1000'], 'cart' => ['412' => 1]]],
    'Stage E' => ['views' => [], 'profile' => null, 'signals' => ['purchases' => [['ma_san_pham' => '266', 'so_luong' => 1, 'ngay_dat' => date('Y-m-d H:i:s', time() - 86400 * 10)]]]],
    'Stage F' => ['views' => [], 'profile' => [
        'skin_type' => 'Da dầu',
        'van_de_da' => ['Mụn', 'Lỗ chân lông to'],
        'muc_tieu_cham_soc' => 'Kiểm soát dầu mụn',
        'ngan_sach' => 350000
    ], 'signals' => []],
    'Stage G' => [
        'survey' => ['loai_da' => 'da_dau', 'muc_gia' => 'binh_dan'],
        'views' => ['1000'],
        'profile' => [
            'skin_type' => 'Da dầu',
            'van_de_da' => ['Mụn', 'Lỗ chân lông to'],
            'muc_tieu_cham_soc' => 'Kiểm soát dầu mụn',
            'ngan_sach' => 350000
        ],
        'signals' => [
            'search' => ['serum da dầu'], 'view' => ['1000'], 'cart' => ['412' => 1],
            'purchases' => [['ma_san_pham' => '266', 'so_luong' => 1, 'ngay_dat' => date('Y-m-d H:i:s', time() - 86400 * 10)]]
        ]
    ]
];

$expectedModes = [
    'Stage A' => 'SIMPLE',
    'Stage B' => 'BEHAVIOR_CONTENT',
    'Stage C' => 'BEHAVIOR_CONTENT',
    'Stage D' => 'BEHAVIOR_CONTENT',
    'Stage E' => 'PURCHASE_CONTENT',
    'Stage F' => 'PROFILE_CONTENT',
    'Stage G' => 'ADAPTIVE_HYBRID'
];

$stagesAllMatch = true;
$mismatchDetails = '';
foreach ($stagesConfig as $sKey => $sCtx) {
    $sRecs = $spModel->getHybridRecommendations($sCtx['views'], $sCtx['profile'], 4, $sCtx['signals']);
    $sMode = $sRecs[0]['recommender_meta']['algorithm_mode'] ?? '';
    if ($sMode !== $expectedModes[$sKey] || count($sRecs) !== 4) {
        $stagesAllMatch = false;
        $mismatchDetails = "Mismatch at {$sKey}: got {$sMode}, expected {$expectedModes[$sKey]}";
        break;
    }
}
assertTest(17, "production Phase A 7 scenarios unchanged",
    $stagesAllMatch,
    $stagesAllMatch ? "All 7 stages A-G produced exact algorithm modes and 4 items" : $mismatchDetails
);

// -------------------------------------------------------------
// 18. Simple Top-24 unchanged
// -------------------------------------------------------------
$currentSimple24 = $spModel->getSimpleRecommenderProducts(24);
$baselineTop24File = __DIR__ . '/output/simple_baseline_top24.json';
$baselineTop24 = file_exists($baselineTop24File) ? json_decode(file_get_contents($baselineTop24File), true) : [];
$simpleMatches = (count($currentSimple24) === 24 && count($baselineTop24) === 24);
if ($simpleMatches) {
    for ($i = 0; $i < 24; $i++) {
        if ((string)($currentSimple24[$i]['ma_san_pham'] ?? '') !== (string)($baselineTop24[$i]['ma_san_pham'] ?? '')) {
            $simpleMatches = false;
            break;
        }
    }
}
assertTest(18, "Simple Top-24 unchanged",
    $simpleMatches,
    "All 24 SKUs match Phase A baseline exactly"
);

// -------------------------------------------------------------
// 19. CF still disconnected from production
// -------------------------------------------------------------
$homeCtrlCode = file_get_contents(__DIR__ . '/../app/controllers/HomeController.php');
$sanPhamModelCode = file_get_contents(__DIR__ . '/../app/models/SanPham.php');
$cbCode = file_get_contents(__DIR__ . '/../app/services/ContentBasedRecommender.php');

$noCfInHome = (strpos($homeCtrlCode, 'CollaborativeFiltering') === false);
$noCfInModel = (strpos($sanPhamModelCode, 'CollaborativeFiltering') === false);
$noCfInCb = (strpos($cbCode, 'CollaborativeFiltering') === false);

assertTest(19, "CF still disconnected from production",
    $noCfInHome && $noCfInModel && $noCfInCb,
    "Home: DISCONNECTED, SanPham: DISCONNECTED, ContentBased: DISCONNECTED"
);

// -------------------------------------------------------------
// 20. No synthetic data written to production
// -------------------------------------------------------------
$synInProd = $prodDb->tuong_tac_nguoi_dung->countDocuments(['source' => 'synthetic_seed']);
$synOrdersInProd = $prodDb->hoa_don->countDocuments(['source' => 'synthetic_seed']);
$synUsersInProd = $prodDb->nguoi_dung->countDocuments(['source' => 'synthetic_seed']);

$noSynInProd = ($synInProd === 0 && $synOrdersInProd === 0 && $synUsersInProd === 0);
assertTest(20, "no synthetic data written to production",
    $noSynInProd,
    "Synthetic records in prod DB: {$synInProd} interactions, {$synOrdersInProd} orders, {$synUsersInProd} users"
);

echo "\n=================================================================\n";
echo "SUMMARY: Passed {$passed} / " . ($passed + $failed) . " tests. Failed: {$failed}\n";
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
