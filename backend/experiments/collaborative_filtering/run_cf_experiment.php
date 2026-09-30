<?php
/**
 * run_cf_experiment.php
 * Main Orchestration Pipeline for Phase C Isolated Synthetic CF Experiment
 *
 * Runs:
 * 1. Multi-Seed Anti-Circularity Evaluation (Seeds 42, 123, 2026)
 * 2. Data Sparsity Ablation Evaluation (Dataset S: 100, M: 300, L: 500)
 * 3. Cold-Start Analysis & Baseline Comparison
 * 4. Generates all thesis-ready JSON artifacts under output/
 */

require_once __DIR__ . '/../../app/config/db.php';
require_once __DIR__ . '/generate_synthetic_dataset.php';
require_once __DIR__ . '/evaluate_cf.php';

global $db, $mongoClient;

$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) {
    @mkdir($outputDir, 0777, true);
}

$expDb = $mongoClient->selectDatabase('skinsyntax_cf_dev');

echo "=================================================================\n";
echo "PHASE C — ISOLATED SYNTHETIC COLLABORATIVE FILTERING EXPERIMENT\n";
echo "=================================================================\n";
echo "Production DB: " . $db->getDatabaseName() . " (READ-ONLY)\n";
echo "Experimental DB: " . $expDb->getDatabaseName() . " (ISOLATED WRITES)\n\n";

// -------------------------------------------------------------
// PART 1: MULTI-SEED EXPERIMENT (Seeds 42, 123, 2026)
// -------------------------------------------------------------
echo "[1/3] Running Multi-Seed Experiment (500 users across 3 seeds)...\n";

$seeds = [42, 123, 2026];
$seedResults = [];
$primaryStats = null;
$catalogCache = null;

foreach ($seeds as $seed) {
    echo "  -> Generating dataset for Seed {$seed}...\n";
    $generator = new SyntheticDatasetGenerator($db, $expDb, $seed, ['num_users' => 500]);
    // For seed 42, persist to MongoDB; for subsequent seeds, run evaluation in-memory
    $persist = ($seed === 42);
    $data = $generator->generate($persist);

    if ($catalogCache === null) {
        $catalogCache = $generator->loadCatalog();
    }
    if ($seed === 42) {
        $primaryStats = $data['stats'];
        @file_put_contents($outputDir . '/config.json', json_encode($data['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @file_put_contents($outputDir . '/dataset_stats.json', json_encode($data['stats'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    echo "  -> Evaluating Seed {$seed}...\n";
    $evaluator = new CollaborativeFilteringEvaluator($catalogCache, 5, 25, 0.01, 0.05);
    $splitInfo = $evaluator->prepareTemporalHoldout($data['interactions'], 5);
    $evalRes = $evaluator->evaluateAll($seed);

    $outPayload = [
        'seed' => $seed,
        'split_info' => $splitInfo,
        'metrics' => $evalRes['metrics'],
    ];
    $seedResults[$seed] = $evalRes['metrics'];
    @file_put_contents($outputDir . "/metrics_seed_{$seed}.json", json_encode($outPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "     [OK] Seed {$seed} evaluated ({$splitInfo['evaluated_users']} users evaluated, {$splitInfo['excluded_users']} excluded).\n";
}

// Compute Mean Metrics
$methods = array_keys(reset($seedResults));
$meanMetrics = [];
$firstSeedRes = reset($seedResults);
$firstMethodRes = reset($firstSeedRes);
$metricKeys = array_keys($firstMethodRes);

foreach ($methods as $m) {
    $meanMetrics[$m] = [];
    foreach ($metricKeys as $k) {
        $vals = [];
        foreach ($seeds as $s) {
            $vals[] = $seedResults[$s][$m][$k] ?? 0.0;
        }
        $meanMetrics[$m][$k] = round(array_sum($vals) / count($vals), 4);
    }
}

$multiSeedPayload = [
    'seeds' => $seeds,
    'mean_metrics' => $meanMetrics,
    'individual_seed_metrics' => $seedResults,
    'generator_assumption_statement' => "Synthetic-data evaluation demonstrates whether the implemented Collaborative Filtering pipeline can recover preference structures under controlled assumptions. It does not establish recommendation quality for real SkinSyntaxVN users."
];
@file_put_contents($outputDir . '/multi_seed_evaluation.json', json_encode($multiSeedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "  -> Multi-seed evaluation completed. Mean metrics saved.\n\n";

// -------------------------------------------------------------
// PART 2: DATA SPARSITY ABLATION EXPERIMENT (100, 300, 500 Users)
// -------------------------------------------------------------
echo "[2/3] Running Data Sparsity Ablation Experiment (Sizes: 100, 300, 500)...\n";

$sparsitySizes = [
    'Dataset S (100 users)' => 100,
    'Dataset M (300 users)' => 300,
    'Dataset L (500 users)' => 500,
];

$sparsityResults = [];
foreach ($sparsitySizes as $label => $uCount) {
    echo "  -> Evaluating {$label}...\n";
    $gen = new SyntheticDatasetGenerator($db, $expDb, 42, ['num_users' => $uCount]);
    $d = $gen->generate(false); // In-memory

    $ev = new CollaborativeFilteringEvaluator($catalogCache, 5, 25, 0.01, 0.05);
    $spSplit = $ev->prepareTemporalHoldout($d['interactions'], 5);
    $spEval = $ev->evaluateAll(42);

    $sparsityResults[$label] = [
        'users' => $uCount,
        'interactions' => $d['stats']['total_interactions'],
        'unique_pairs' => $d['stats']['unique_user_item_pairs'],
        'unique_products' => $d['stats']['unique_products_interacted'],
        'density_pct' => $d['stats']['matrix_density_pct'],
        'catalog_coverage_pct' => $d['stats']['catalog_coverage_pct'],
        'evaluated_users' => $spSplit['evaluated_users'],
        'metrics_summary' => [
            'MOST_POPULAR' => [
                'HR@10' => $spEval['metrics']['MOST_POPULAR']['HitRate@10'],
                'Recall@10' => $spEval['metrics']['MOST_POPULAR']['Recall@10'],
                'NDCG@10' => $spEval['metrics']['MOST_POPULAR']['NDCG@10'],
            ],
            'ITEM_KNN' => [
                'HR@10' => $spEval['metrics']['ITEM_KNN']['HitRate@10'],
                'Recall@10' => $spEval['metrics']['ITEM_KNN']['Recall@10'],
                'NDCG@10' => $spEval['metrics']['ITEM_KNN']['NDCG@10'],
            ],
            'MATRIX_FACTORIZATION' => [
                'HR@10' => $spEval['metrics']['MATRIX_FACTORIZATION']['HitRate@10'],
                'Recall@10' => $spEval['metrics']['MATRIX_FACTORIZATION']['Recall@10'],
                'NDCG@10' => $spEval['metrics']['MATRIX_FACTORIZATION']['NDCG@10'],
            ],
            'HYBRID_W02' => [
                'HR@10' => $spEval['metrics']['HYBRID_W02']['HitRate@10'],
                'Recall@10' => $spEval['metrics']['HYBRID_W02']['Recall@10'],
                'NDCG@10' => $spEval['metrics']['HYBRID_W02']['NDCG@10'],
            ],
        ],
    ];
}

@file_put_contents($outputDir . '/sparsity_experiment.json', json_encode($sparsityResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "  -> Sparsity experiment completed. Results saved.\n\n";

// -------------------------------------------------------------
// PART 3: COLD-START ANALYSIS
// -------------------------------------------------------------
echo "[3/3] Performing Cold-Start Analysis & Thesis Validation...\n";

$coldStartAnalysis = [
    'cold_start_user' => [
        'scenario' => 'Completely new visitor with 0 past interactions',
        'cf_knn_behavior' => 'Returns 0 recommendations (no neighbor overlap)',
        'cf_mf_behavior' => 'Falls back to global bias mu; lacks personalized latent factor vector p_u',
        'production_countermeasure' => 'Phase A Simple Weighted Rating (WR) and Skin Profile Matching gracefully handle cold-start users without error'
    ],
    'cold_start_item' => [
        'scenario' => 'Newly added catalog SKU with 0 purchase or view history',
        'cf_knn_behavior' => 'Unreachable via item similarity matrix (zero column in co-interaction)',
        'cf_mf_behavior' => 'Cannot train item latent vector q_i; relies solely on global bias',
        'production_countermeasure' => 'Phase A Content-Based TF-IDF Recommender instantly embeds new SKUs via ingredients, category, and skin suitability features regardless of sales volume'
    ],
    'hybrid_necessity' => 'Demonstrates why CF can only serve as a supplemental signal in an Adaptive Hybrid Recommender once organic density reaches critical mass, and why Content-Based & Simple WR remain foundational.'
];

@file_put_contents($outputDir . '/cold_start_analysis.json', json_encode($coldStartAnalysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "=================================================================\n";
echo "EXPERIMENT COMPLETED SUCCESSFULLY!\n";
echo "Artifacts written to: " . realpath($outputDir) . "\n";
echo "=================================================================\n";
