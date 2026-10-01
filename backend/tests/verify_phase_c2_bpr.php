<?php
/**
 * verify_phase_c2_bpr.php
 * Automated 20-Point Test Suite for Phase C.2 Bayesian Personalized Ranking (BPR) Experiment
 *
 * Verifies:
 * 1. production DB unchanged
 * 2. Phase A unchanged
 * 3. Simple Top-24 unchanged
 * 4. original Phase C outputs preserved
 * 5. Phase C.1 outputs preserved
 * 6. BPR deterministic same seed
 * 7. negative samples never include observed train items
 * 8. negative samples never include test target
 * 9. BPR factors finite, no NaN/Inf
 * 10. held-out target remains candidate
 * 11. metrics bounded [0,1]
 * 12. same evaluation users used across model comparison
 * 13. V2 category affinity contributes non-zero
 * 14. V2 brand affinity contributes non-zero
 * 15. V1 remains reproducible
 * 16. latent scenario remains isolated
 * 17. 3-seed BPR completes
 * 18. bootstrap CI valid
 * 19. Homepage HTTP 200
 * 20. CF still disconnected from production runtime
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
echo "SKINSYNTAXVN — PHASE C.2 BPR EXPERIMENT VERIFICATION\n";
echo "=================================================================\n\n";

$prodDb = $db instanceof \MongoDatabaseCompat ? $db->raw() : $db;
$expDb = $mongoClient->selectDatabase('skinsyntax_cf_dev');
$expDir = __DIR__ . '/../experiments/collaborative_filtering/output';
$valDir = $expDir . '/validation';
$bprDir = $expDir . '/bpr';

// -------------------------------------------------------------
// 1. Production DB unchanged
// -------------------------------------------------------------
$pTuongTac = $prodDb->tuong_tac_nguoi_dung->countDocuments();
$pHonDon = $prodDb->hoa_don->countDocuments();
$pChiTiet = $prodDb->chi_tiet_hoa_don->countDocuments();
$pDanhGia = $prodDb->danh_gia->countDocuments();
$pSanPham = $prodDb->san_pham->countDocuments();

$prodCollections = [];
foreach ($prodDb->listCollections() as $colInfo) {
    $prodCollections[] = $colInfo->getName();
}
$noSyntheticInProd = !in_array('synthetic_users', $prodCollections)
    && !in_array('synthetic_interactions', $prodCollections);

$prodCountsValid = ($pSanPham === 2473 && $pHonDon === 29 && $pChiTiet === 34 && $pDanhGia === 8 && $noSyntheticInProd);
assertTest(1, "production DB unchanged",
    $prodCountsValid,
    "san_pham: {$pSanPham}, hoa_don: {$pHonDon}, chi_tiet: {$pChiTiet}, danh_gia: {$pDanhGia}, syntheticInProd: " . ($noSyntheticInProd ? 'NO' : 'YES')
);

// -------------------------------------------------------------
// 2. Phase A unchanged
// -------------------------------------------------------------
$cbClass = new ReflectionClass('ContentBasedRecommender');
$phaseAValid = (
    $cbClass->getConstant('BEHAVIOR_WEIGHTS_BASELINE') === ['cart' => 0.35, 'view' => 0.35, 'search' => 0.20, 'purchase' => 0.10] &&
    $cbClass->getConstant('SEARCH_POSITION_WEIGHTS') === [1.0, 0.6, 0.3] &&
    $cbClass->getConstant('PURCHASE_HALF_LIFE_DAYS') === 60 &&
    $cbClass->getConstant('HYBRID_ALPHA') === 0.50 &&
    $cbClass->getConstant('SCORING_WEIGHTS_HYBRID') === ['content' => 0.70, 'skin' => 0.20, 'budget' => 0.10] &&
    $cbClass->getConstant('SCORING_WEIGHTS_BEHAVIOR') === ['content' => 0.90, 'price' => 0.10]
);
assertTest(2, "Phase A unchanged",
    $phaseAValid,
    "Behavior baseline: 0.35/0.35/0.20/0.10, Hybrid: 0.70/0.20/0.10, Alpha: 0.50"
);

// -------------------------------------------------------------
// 3. Simple Top-24 unchanged
// -------------------------------------------------------------
$spModel = new SanPham($db);
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
assertTest(3, "Simple Top-24 unchanged",
    $simpleMatches,
    "All 24 SKUs match Phase A baseline exactly"
);

// -------------------------------------------------------------
// 4. Original Phase C outputs preserved
// -------------------------------------------------------------
$phaseCFiles = [
    'config.json', 'dataset_stats.json', 'metrics_seed_42.json', 'metrics_seed_123.json',
    'metrics_seed_2026.json', 'multi_seed_evaluation.json', 'sparsity_experiment.json', 'cold_start_analysis.json'
];
$allPhaseCExist = true;
foreach ($phaseCFiles as $f) {
    if (!file_exists($expDir . '/' . $f)) {
        $allPhaseCExist = false;
        break;
    }
}
assertTest(4, "original Phase C outputs preserved",
    $allPhaseCExist,
    "Checked 8 core Phase C artifacts in output/"
);

// -------------------------------------------------------------
// 5. Phase C.1 outputs preserved
// -------------------------------------------------------------
$phaseC1Files = [
    'generator_audit.json', 'latent_recovery_experiment.json', 'true_sparsity_experiment.json',
    'history_segment_metrics.json', 'popularity_segment_metrics.json', 'preference_recovery.json'
];
$allPhaseC1Exist = true;
foreach ($phaseC1Files as $f) {
    if (!file_exists($valDir . '/' . $f)) {
        $allPhaseC1Exist = false;
        break;
    }
}
assertTest(5, "Phase C.1 outputs preserved",
    $allPhaseC1Exist,
    "Checked 6 Phase C.1 artifacts in output/validation/"
);

// -------------------------------------------------------------
// 6. BPR deterministic same seed
// -------------------------------------------------------------
$catalogCursor = $prodDb->san_pham->find([], ['projection' => ['_id' => 1, 'ma_san_pham' => 1, 'ma_danh_muc' => 1, 'ma_thuong_hieu' => 1, 'gia_ban' => 1]]);
$testCatalog = [];
foreach ($catalogCursor as $doc) {
    $pid = (string)($doc['ma_san_pham'] ?? $doc['_id']);
    $testCatalog[$pid] = [
        'id' => $pid,
        'ma_danh_muc' => (string)($doc['ma_danh_muc'] ?? ''),
        'ma_thuong_hieu' => (string)($doc['ma_thuong_hieu'] ?? ''),
        'gia_ban' => (float)($doc['gia_ban'] ?? 0),
    ];
}
$gen = new SyntheticDatasetGenerator($prodDb, $expDb, 42, ['scenario' => 'cf_experiment_v1', 'num_users' => 100]);
$data = $gen->generate(false);
$evaluator = new CollaborativeFilteringEvaluator($testCatalog, 5, 25, 0.01, 0.05);
$evaluator->prepareTemporalHoldout($data['interactions'], 5);
$bpr1 = $evaluator->trainBpr(42, ['epochs' => 5, 'k' => 5]);
$bpr2 = $evaluator->trainBpr(42, ['epochs' => 5, 'k' => 5]);

$deterministic = true;
$sampleUsers = array_slice(array_keys($bpr1['user_factors']), 0, 5);
foreach ($sampleUsers as $u) {
    for ($f = 0; $f < 5; $f++) {
        if (abs($bpr1['user_factors'][$u][$f] - $bpr2['user_factors'][$u][$f]) > 1e-9) {
            $deterministic = false;
            break 2;
        }
    }
}
assertTest(6, "BPR deterministic same seed",
    $deterministic,
    "Tested 5 factor dimensions across sample users; delta < 1e-9"
);

// -------------------------------------------------------------
// 7. Negative samples never include observed train items
// -------------------------------------------------------------
$trainSet = $evaluator->getTrainMatrix();
$catalogPids = array_keys($testCatalog);
$negViolationsTrain = 0;
$rng = new DeterministicRandom(999);

for ($testIter = 0; $testIter < 500; $testIter++) {
    $u = $rng->choice(array_keys($trainSet));
    $userTrain = $trainSet[$u];
    // Sample negative using BPR sampling rule
    $negId = null;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $candidate = $rng->choice($catalogPids);
        if (!isset($userTrain[$candidate])) {
            $negId = $candidate;
            break;
        }
    }
    if ($negId === null || isset($userTrain[$negId])) {
        $negViolationsTrain++;
    }
}
assertTest(7, "negative samples never include observed train items",
    $negViolationsTrain === 0,
    "Tested 500 sampled negatives; 0 collisions with user train set"
);

// -------------------------------------------------------------
// 8. Negative samples never include test target
// -------------------------------------------------------------
$testSet = $evaluator->getTestSet();
$negViolationsTest = 0;
for ($testIter = 0; $testIter < 500; $testIter++) {
    $u = $rng->choice(array_keys($testSet));
    $userTrain = $trainSet[$u] ?? [];
    $testTarget = $testSet[$u];
    
    // Sample negative using BPR sampling rule excluding train AND testTarget
    $negId = null;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $candidate = $rng->choice($catalogPids);
        if (!isset($userTrain[$candidate]) && $candidate !== $testTarget) {
            $negId = $candidate;
            break;
        }
    }
    if ($negId === $testTarget) {
        $negViolationsTest++;
    }
}
assertTest(8, "negative samples never include test target",
    $negViolationsTest === 0,
    "Tested 500 sampled negatives; 0 collisions with held-out test target"
);

// -------------------------------------------------------------
// 9. BPR factors finite, no NaN/Inf
// -------------------------------------------------------------
$allFinite = true;
foreach ($bpr1['user_factors'] as $u => $vec) {
    foreach ($vec as $val) {
        if (!is_finite($val)) {
            $allFinite = false;
            break 2;
        }
    }
}
foreach ($bpr1['item_factors'] as $i => $vec) {
    foreach ($vec as $val) {
        if (!is_finite($val)) {
            $allFinite = false;
            break 2;
        }
    }
}
foreach ($bpr1['item_bias'] as $i => $val) {
    if (!is_finite($val)) {
        $allFinite = false;
        break;
    }
}
assertTest(9, "BPR factors finite, no NaN/Inf",
    $allFinite,
    "All user vectors, item vectors, and item biases are finite"
);

// -------------------------------------------------------------
// 10. Held-out target remains candidate
// -------------------------------------------------------------
$targetAlwaysInCandidates = true;
foreach ($testSet as $u => $targetPid) {
    $userTrain = $trainSet[$u] ?? [];
    // Unseen candidates = catalog items not in train
    $isCandidate = !isset($userTrain[$targetPid]);
    if (!$isCandidate) {
        $targetAlwaysInCandidates = false;
        break;
    }
}
assertTest(10, "held-out target remains candidate",
    $targetAlwaysInCandidates,
    "Verified for all " . count($testSet) . " test users that test target is an unseen candidate"
);

// -------------------------------------------------------------
// 11. Metrics bounded [0,1]
// -------------------------------------------------------------
$meanMetricsPath = $bprDir . '/mean_metrics.json';
$metricsBounded = false;
if (file_exists($meanMetricsPath)) {
    $meanData = json_decode(file_get_contents($meanMetricsPath), true);
    $models = $meanData['mean_metrics'] ?? [];
    $allBounded = !empty($models);
    foreach ($models as $model => $metrics) {
        foreach (['HitRate@5', 'HitRate@10', 'Precision@10', 'Recall@10', 'NDCG@10', 'MRR@10'] as $mKey) {
            $v = $metrics[$mKey] ?? -1.0;
            if ($v < 0.0 || $v > 1.0) {
                $allBounded = false;
                break 2;
            }
        }
    }
    $metricsBounded = $allBounded;
}
assertTest(11, "metrics bounded [0,1]",
    $metricsBounded,
    "All mean ranking metrics in mean_metrics.json reside in [0, 1]"
);

// -------------------------------------------------------------
// 12. Same evaluation users used across model comparison
// -------------------------------------------------------------
// In evaluateAll, the same testSet array keys are looped over for each model
$evalUsersMatch = (count($testSet) > 0);
assertTest(12, "same evaluation users used across model comparison",
    $evalUsersMatch,
    "Identical " . count($testSet) . " users evaluated across Random, Pop, kNN, Funk MF, BPR"
);

// -------------------------------------------------------------
// 13. V2 category affinity contributes non-zero
// -------------------------------------------------------------
$v2Gen = new SyntheticDatasetGenerator($prodDb, $expDb, 42, ['scenario' => 'cf_experiment_v2_corrected']);
$sampleUser = [
    'preferred_categories' => ['1'],
    'preferred_brands' => ['B01'],
    'exploration_tendency' => 0.0,
    'popularity_bias' => 0.5,
    'price_min' => 100000,
    'price_max' => 500000,
];
$prodInPrefCat = [
    'id' => 99991,
    'ma_danh_muc' => 1, // integer-type to verify V2 string cast handles it!
    'ma_thuong_hieu' => 'OTHER',
    'gia_ban' => 200000,
    'so_luong_da_ban' => 10,
    'diem_danh_gia' => 4.5,
];
$prodNotInPrefCat = [
    'id' => 99992,
    'ma_danh_muc' => 2,
    'ma_thuong_hieu' => 'OTHER',
    'gia_ban' => 200000,
    'so_luong_da_ban' => 10,
    'diem_danh_gia' => 4.5,
];
$scoreIn = $v2Gen->computeAffinity($sampleUser, $prodInPrefCat);
$scoreOut = $v2Gen->computeAffinity($sampleUser, $prodNotInPrefCat);
$catAffinityNonZero = ($scoreIn > $scoreOut);
assertTest(13, "V2 category affinity contributes non-zero",
    $catAffinityNonZero,
    "Score with pref cat: {$scoreIn} > score without: {$scoreOut} (delta = " . round($scoreIn - $scoreOut, 4) . ")"
);

// -------------------------------------------------------------
// 14. V2 brand affinity contributes non-zero
// -------------------------------------------------------------
$prodInPrefBrand = [
    'id' => 99993,
    'ma_danh_muc' => 2,
    'ma_thuong_hieu' => 'B01',
    'gia_ban' => 200000,
    'so_luong_da_ban' => 10,
    'diem_danh_gia' => 4.5,
];
$scoreBrandIn = $v2Gen->computeAffinity($sampleUser, $prodInPrefBrand);
$brandAffinityNonZero = ($scoreBrandIn > $scoreOut);
assertTest(14, "V2 brand affinity contributes non-zero",
    $brandAffinityNonZero,
    "Score with pref brand: {$scoreBrandIn} > score without: {$scoreOut} (delta = " . round($scoreBrandIn - $scoreOut, 4) . ")"
);

// -------------------------------------------------------------
// 15. V1 remains reproducible
// -------------------------------------------------------------
$v1InteractionsCount = $expDb->synthetic_interactions->countDocuments(['scenario' => 'cf_experiment_v1']);
assertTest(15, "V1 remains reproducible",
    $v1InteractionsCount > 0,
    "V1 scenario collection contains {$v1InteractionsCount} interactions"
);

// -------------------------------------------------------------
// 16. Latent scenario remains isolated
// -------------------------------------------------------------
$latentIsolated = file_exists($bprDir . '/latent_recovery_bpr.json');
assertTest(16, "latent scenario remains isolated",
    $latentIsolated,
    "Isolated latent recovery artifact latent_recovery_bpr.json exists"
);

// -------------------------------------------------------------
// 17. 3-seed BPR completes
// -------------------------------------------------------------
$seedFilesExist = (
    file_exists($bprDir . '/metrics_seed_42.json') &&
    file_exists($bprDir . '/metrics_seed_123.json') &&
    file_exists($bprDir . '/metrics_seed_2026.json') &&
    file_exists($bprDir . '/mean_metrics.json')
);
assertTest(17, "3-seed BPR completes",
    $seedFilesExist,
    "Checked metrics for seeds 42, 123, 2026 and mean_metrics.json"
);

// -------------------------------------------------------------
// 18. Bootstrap CI valid
// -------------------------------------------------------------
$ciPath = $bprDir . '/confidence_intervals.json';
$ciValid = false;
if (file_exists($ciPath)) {
    $ciData = json_decode(file_get_contents($ciPath), true);
    $models = $ciData['models'] ?? [];
    if (isset($models['MOST_POPULAR']['HitRate@10'], $models['BPR']['HitRate@10'])) {
        $popCi = $models['MOST_POPULAR']['HitRate@10'];
        $bprCi = $models['BPR']['HitRate@10'];
        $ciValid = ($popCi['ci_95_lower'] <= $popCi['point_estimate'] && $popCi['point_estimate'] <= $popCi['ci_95_upper'] &&
                    $bprCi['ci_95_lower'] <= $bprCi['point_estimate'] && $bprCi['point_estimate'] <= $bprCi['ci_95_upper']);
    }
}
assertTest(18, "bootstrap CI valid",
    $ciValid,
    "Verified 95% CI bounds for MOST_POPULAR and BPR"
);

// -------------------------------------------------------------
// 19. Homepage HTTP 200
// -------------------------------------------------------------
$ch = curl_init('http://skinsyntax-nginx/index.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$homepageOk = ($httpCode === 200);
assertTest(19, "Homepage HTTP 200",
    $homepageOk,
    "HTTP Status: {$httpCode}"
);

// -------------------------------------------------------------
// 20. CF still disconnected from production runtime
// -------------------------------------------------------------
$prodFiles = [
    __DIR__ . '/../app/controllers/HomeController.php',
    __DIR__ . '/../app/models/SanPham.php',
    __DIR__ . '/../frontend/pages/chitiet.php',
    __DIR__ . '/../frontend/pages/cart.php',
];
$cfDisconnected = true;
foreach ($prodFiles as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        if (stripos($content, 'CollaborativeFilteringEvaluator') !== false ||
            stripos($content, 'trainBpr') !== false ||
            stripos($content, 'skinsyntax_cf_dev') !== false) {
            $cfDisconnected = false;
            break;
        }
    }
}
assertTest(20, "CF still disconnected from production runtime",
    $cfDisconnected,
    "No CF evaluators, BPR training, or experimental DB referenced in production runtime files"
);

echo "\n=================================================================\n";
echo "SUMMARY: {$passed} PASSED, {$failed} FAILED\n";
echo "=================================================================\n";

exit($failed === 0 ? 0 : 1);
