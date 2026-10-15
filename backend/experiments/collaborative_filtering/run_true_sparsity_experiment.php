<?php
/**
 * run_true_sparsity_experiment.php
 * Controlled True Sparsity / User History Length Experiment (Phase C.1)
 *
 * Keeps:
 * - Constant 500 users
 * - Constant 2,473 products catalog
 * - Constant generator assumptions and seed (42)
 *
 * Varies:
 * - SPARSE: ~5-10 unique items / user (short history)
 * - MEDIUM: ~15-25 unique items / user (moderate history)
 * - DENSE: ~30-50 unique items / user (rich history)
 */

require_once __DIR__ . '/../../app/config/db.php';
require_once __DIR__ . '/generate_synthetic_dataset.php';
require_once __DIR__ . '/evaluate_cf.php';

global $db, $mongoClient;

$outputDir = __DIR__ . '/output/validation';
if (!is_dir($outputDir)) {
    @mkdir($outputDir, 0777, true);
}

$expDb = $mongoClient->selectDatabase('skinsyntax_cf_dev');

echo "=== PHASE C.1: TRUE SPARSITY / HISTORY LENGTH EXPERIMENT ===\n";

$tiers = [
    'SPARSE (Short History)' => [
        'min_interactions' => 6,
        'max_interactions' => 14,
        'target_avg_interactions' => 10,
    ],
    'MEDIUM (Moderate History)' => [
        'min_interactions' => 18,
        'max_interactions' => 45,
        'target_avg_interactions' => 30,
    ],
    'DENSE (Rich History)' => [
        'min_interactions' => 50,
        'max_interactions' => 90,
        'target_avg_interactions' => 70,
    ],
];

$catalog = null;
$results = [];

foreach ($tiers as $tierLabel => $tierConfig) {
    echo "  -> Running {$tierLabel}...\n";
    $config = array_merge([
        'num_users' => 500,
        'seed' => 42,
    ], $tierConfig);

    $generator = new SyntheticDatasetGenerator($db, $expDb, 42, $config);
    if ($catalog === null) {
        $catalog = $generator->loadCatalog();
    }
    $data = $generator->generate(false); // In-memory run

    $stats = $data['stats'];
    $interactions = $data['interactions'];

    // Measure average unique items per user
    $userUniqueItems = [];
    foreach ($interactions as $ev) {
        $u = (string)$ev['ma_kh'];
        $p = (string)$ev['ma_san_pham'];
        $userUniqueItems[$u][$p] = true;
    }
    $uniqueCounts = array_map('count', $userUniqueItems);
    $avgUniqueItems = round(array_sum($uniqueCounts) / max(1, count($uniqueCounts)), 1);

    // Evaluate models with Leave-One-Out Per-User Holdout
    // Minimum train items: for SPARSE use 4 items to ensure adequate eligible users; for MEDIUM/DENSE use 5 items
    $minTrain = ($tierLabel === 'SPARSE (Short History)') ? 4 : 5;
    $evaluator = new CollaborativeFilteringEvaluator($catalog, 5, 25, 0.01, 0.05);
    $splitInfo = $evaluator->prepareTemporalHoldout($interactions, $minTrain);
    $evalRes = $evaluator->evaluateAll(42);

    $mPopular = $evalRes['metrics']['MOST_POPULAR'] ?? [];
    $mKnn = $evalRes['metrics']['ITEM_KNN'] ?? [];
    $mMf = $evalRes['metrics']['MATRIX_FACTORIZATION'] ?? [];

    $results[$tierLabel] = [
        'users' => 500,
        'avg_unique_items_per_user' => $avgUniqueItems,
        'min_unique_items' => !empty($uniqueCounts) ? min($uniqueCounts) : 0,
        'max_unique_items' => !empty($uniqueCounts) ? max($uniqueCounts) : 0,
        'total_events' => $stats['total_interactions'],
        'unique_user_item_pairs' => $stats['unique_user_item_pairs'],
        'matrix_density_pct' => $stats['matrix_density_pct'],
        'catalog_coverage_pct' => $stats['catalog_coverage_pct'],
        'eligible_evaluation_users' => $splitInfo['evaluated_users'],
        'excluded_users' => $splitInfo['excluded_users'],
        'comparison' => [
            'MOST_POPULAR' => [
                'HR@10' => $mPopular['HitRate@10'] ?? 0.0,
                'Recall@10' => $mPopular['Recall@10'] ?? 0.0,
                'NDCG@10' => $mPopular['NDCG@10'] ?? 0.0,
            ],
            'ITEM_KNN' => [
                'HR@10' => $mKnn['HitRate@10'] ?? 0.0,
                'Recall@10' => $mKnn['Recall@10'] ?? 0.0,
                'NDCG@10' => $mKnn['NDCG@10'] ?? 0.0,
            ],
            'MATRIX_FACTORIZATION' => [
                'HR@10' => $mMf['HitRate@10'] ?? 0.0,
                'Recall@10' => $mMf['Recall@10'] ?? 0.0,
                'NDCG@10' => $mMf['NDCG@10'] ?? 0.0,
            ],
        ],
    ];

    echo "     [Done] Avg unique items: {$avgUniqueItems}, Matrix Density: {$stats['matrix_density_pct']}%, Eval Users: {$splitInfo['evaluated_users']}\n";
    echo "            Popular HR@10: {$mPopular['HitRate@10']}, kNN HR@10: {$mKnn['HitRate@10']}, MF HR@10: {$mMf['HitRate@10']}\n";
}

$payload = [
    'experiment_name' => 'TRUE_SPARSITY_EXPERIMENT',
    'controlled_parameters' => [
        'num_users' => 500,
        'total_catalog' => count($catalog),
        'random_seed' => 42,
    ],
    'note' => 'Unlike the historical dataset scale experiment (100, 300, 500 users) which maintained constant per-user interaction length, this true sparsity experiment fixes user count at 500 and modulates per-user interaction depth (5-10 vs 15-25 vs 30-50 unique items).',
    'tiers' => $results,
];

file_put_contents($outputDir . '/true_sparsity_experiment.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\n[OK] Saved true_sparsity_experiment.json successfully!\n";
