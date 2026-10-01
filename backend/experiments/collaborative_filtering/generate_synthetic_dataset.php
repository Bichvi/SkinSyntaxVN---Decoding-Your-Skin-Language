<?php
/**
 * generate_synthetic_dataset.php
 * Isolated Synthetic Dataset Generator for Collaborative Filtering Experiment (Phase C)
 *
 * Guarantees:
 * 1. ZERO modification to production collections.
 * 2. Writes exclusively to isolated experimental database: skinsyntax_cf_dev.
 * 3. Reads production catalog (san_pham, danh_muc, thuong_hieu) READ-ONLY.
 * 4. Latent preference structure (NO demographic stereotyping).
 * 5. Probabilistic funnel (view -> cart -> purchase -> rating).
 * 6. Deterministic reproducible random seeds (e.g. 42, 123, 2026).
 * 7. Chronological timestamp ordering.
 * 8. Strict labeling: source = "synthetic_seed", scenario = "cf_experiment_v1".
 */

require_once __DIR__ . '/../../app/config/db.php';

/**
 * 32-bit Xorshift PRNG for clean, fast, deterministic reproducibility without float overflow
 */
class DeterministicRandom {
    private int $state;

    public function __construct(int $seed) {
        $s = $seed & 0x7FFFFFFF;
        $this->state = ($s === 0) ? 2463534242 : $s;
    }

    public function next(): float {
        $x = $this->state;
        $x ^= ($x << 13) & 0x7FFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5) & 0x7FFFFFFF;
        $this->state = $x & 0x7FFFFFFF;
        return (float)$this->state / 2147483648.0;
    }

    public function int(int $min, int $max): int {
        if ($min >= $max) return $min;
        return $min + (int)floor($this->next() * ($max - $min + 1));
    }

    public function float(float $min, float $max): float {
        return $min + $this->next() * ($max - $min);
    }

    public function choice(array $arr) {
        if (empty($arr)) return null;
        $keys = array_keys($arr);
        $k = $keys[$this->int(0, count($keys) - 1)];
        return $arr[$k];
    }
}

class SyntheticDatasetGenerator {
    private \MongoDB\Database $prodDb;
    private \MongoDB\Database $expDb;
    private DeterministicRandom $rng;
    private array $config;

    public const SCENARIO = 'cf_experiment_v1';
    public const GENERATOR_VERSION = '1.0';
    public const SOURCE_TAG = 'synthetic_seed';

    public function __construct(
        $prodDb,
        $expDb,
        int $seed = 42,
        array $configOverrides = []
    ) {
        $this->prodDb = $prodDb instanceof \MongoDatabaseCompat ? $prodDb->raw() : $prodDb;
        $this->expDb = $expDb instanceof \MongoDatabaseCompat ? $expDb->raw() : $expDb;
        $this->rng = new DeterministicRandom($seed);

        $this->config = array_merge([
            'seed' => $seed,
            'num_users' => 500,
            'min_interactions' => 8,
            'target_avg_interactions' => 25,
            'max_interactions' => 45,
            'p_cart_given_view' => 0.35,
            'p_purchase_given_cart' => 0.45,
            'p_rating_given_purchase' => 0.40,
            'p_search_click_ratio' => 0.20,
            'user_id_start' => 1000001,
            'days_history' => 60,
        ], $configOverrides);
    }

    /**
     * Load real active product catalog READ-ONLY
     */
    public function loadCatalog(): array {
        $cursor = $this->prodDb->san_pham->find(
            ['trang_thai' => 'active', 'gia_ban' => ['$gt' => 0]],
            ['projection' => [
                'ma_san_pham' => 1,
                'ten_san_pham' => 1,
                'ma_danh_muc' => 1,
                'ma_thuong_hieu' => 1,
                'gia_ban' => 1,
                'diem_danh_gia' => 1,
                'so_luong_da_ban' => 1,
            ]]
        );

        $catalog = [];
        foreach ($cursor as $doc) {
            $pid = (string)$doc['ma_san_pham'];
            $catalog[$pid] = [
                'ma_san_pham' => $pid,
                'ten_san_pham' => (string)($doc['ten_san_pham'] ?? ''),
                'ma_danh_muc' => (string)($doc['ma_danh_muc'] ?? '0'),
                'ma_thuong_hieu' => (string)($doc['ma_thuong_hieu'] ?? '0'),
                'gia_ban' => (int)($doc['gia_ban'] ?? 0),
                'diem_danh_gia' => (float)($doc['diem_danh_gia'] ?? 5.0),
                'so_luong_da_ban' => (int)($doc['so_luong_da_ban'] ?? 0),
            ];
        }
        return $catalog;
    }

    /**
     * Generate synthetic user profile with latent preferences (NO stereotypes)
     */
    private function generateUserProfile(int $userId, array $allCategories, array $allBrands): array {
        // Sample 2-4 preferred categories
        $numCat = $this->rng->int(2, min(4, count($allCategories)));
        $catsSampled = [];
        for ($i = 0; $i < $numCat; $i++) {
            $c = $this->rng->choice($allCategories);
            if ($c !== null) $catsSampled[$c] = true;
        }

        // Sample 2-5 preferred brands
        $numBrand = $this->rng->int(2, min(5, count($allBrands)));
        $brandsSampled = [];
        for ($i = 0; $i < $numBrand; $i++) {
            $b = $this->rng->choice($allBrands);
            if ($b !== null) $brandsSampled[$b] = true;
        }

        // Price range preference (3 soft tiers + individual continuous variance)
        $tier = $this->rng->int(1, 3);
        if ($tier === 1) {
            $minPrice = $this->rng->int(50000, 150000);
            $maxPrice = $this->rng->int(250000, 450000);
        } elseif ($tier === 2) {
            $minPrice = $this->rng->int(200000, 400000);
            $maxPrice = $this->rng->int(600000, 1000000);
        } else {
            $minPrice = $this->rng->int(500000, 900000);
            $maxPrice = $this->rng->int(1200000, 3000000);
        }

        // Latent 5-factor affinity vector
        $latentFactors = [];
        for ($k = 0; $k < 5; $k++) {
            $latentFactors[] = round($this->rng->float(-1.0, 1.0), 3);
        }

        $scenario = $this->config['scenario'] ?? self::SCENARIO;
        $genVersion = ($scenario === 'cf_experiment_v2_corrected') ? '2.0_corrected' : ($this->config['generator_version'] ?? self::GENERATOR_VERSION);

        return [
            'user_id' => $userId,
            'source' => self::SOURCE_TAG,
            'scenario' => $scenario,
            'generator_version' => $genVersion,
            'seed' => $this->config['seed'],
            'preferred_categories' => array_keys($catsSampled),
            'preferred_brands' => array_keys($brandsSampled),
            'price_min' => $minPrice,
            'price_max' => $maxPrice,
            'exploration_tendency' => round($this->rng->float(0.10, 0.40), 3),
            'popularity_bias' => round($this->rng->float(0.20, 0.70), 3),
            'repeat_purchase_tendency' => round($this->rng->float(0.05, 0.25), 3),
            'latent_factors' => $latentFactors,
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
        ];
    }

    /**
     * Compute latent affinity between synthetic user profile and a product
     */
    public function computeAffinity(array $profile, array $prod): float {
        $isExploration = $this->rng->next() < $profile['exploration_tendency'];
        if ($isExploration) {
            // Random exploratory browsing with small noise
            return 0.2 + $this->rng->float(0.0, 0.3);
        }

        $isV2 = (($this->config['scenario'] ?? self::SCENARIO) === 'cf_experiment_v2_corrected');
        $score = 0.05; // Base exploration floor

        // Category match
        $catMatch = $isV2
            ? in_array((string)$prod['ma_danh_muc'], array_map('strval', $profile['preferred_categories']), true)
            : in_array((string)$prod['ma_danh_muc'], $profile['preferred_categories'], true);
        if ($catMatch) {
            $score += 0.40;
        }

        // Brand match
        $brandMatch = $isV2
            ? in_array((string)$prod['ma_thuong_hieu'], array_map('strval', $profile['preferred_brands']), true)
            : in_array((string)$prod['ma_thuong_hieu'], $profile['preferred_brands'], true);
        if ($brandMatch) {
            $score += 0.35;
        }

        // Budget match
        $price = $prod['gia_ban'];
        if ($price >= $profile['price_min'] && $price <= $profile['price_max']) {
            $score += 0.20;
        } elseif ($price < $profile['price_min'] * 1.5 && $price > $profile['price_min'] * 0.5) {
            $score += 0.08;
        }

        // Popularity bias
        $salesNorm = min(1.0, ($prod['so_luong_da_ban'] ?? 0) / 1000.0);
        $ratingNorm = max(0.0, (($prod['diem_danh_gia'] ?? 5.0) - 3.0) / 2.0);
        $score += $profile['popularity_bias'] * (0.6 * $salesNorm + 0.4 * $ratingNorm);

        // Latent noise factor
        $noise = $this->rng->float(-0.08, 0.08);
        return max(0.02, $score + $noise);
    }

    /**
     * Run generation pipeline and insert to skinsyntax_cf_dev
     */
    public function generate(bool $persistToMongo = true): array {
        $catalog = $this->loadCatalog();
        if (empty($catalog)) {
            throw new \RuntimeException("Production catalog is empty or unreachable.");
        }

        $allCategories = array_values(array_unique(array_column($catalog, 'ma_danh_muc')));
        $allBrands = array_values(array_unique(array_column($catalog, 'ma_thuong_hieu')));
        $catalogPids = array_keys($catalog);
        $catalogCount = count($catalogPids);

        $numUsers = $this->config['num_users'];
        $startId = $this->config['user_id_start'];
        $baseTimestamp = time() - ($this->config['days_history'] * 86400);

        $scenario = $this->config['scenario'] ?? self::SCENARIO;
        $genVersion = ($scenario === 'cf_experiment_v2_corrected') ? '2.0_corrected' : ($this->config['generator_version'] ?? self::GENERATOR_VERSION);

        $users = [];
        $interactions = [];
        $interactionId = 1;
        $orderIdCounter = 500001;

        $categoryMap = [];
        $brandMap = [];
        foreach ($catalog as $pid => $prod) {
            $cat = (string)$prod['ma_danh_muc'];
            $br = (string)$prod['ma_thuong_hieu'];
            $categoryMap[$cat][] = $pid;
            $brandMap[$br][] = $pid;
        }

        // Sort items inside each category and brand by popularity descending (head items first)
        $popScoreFn = fn($pid) => (($catalog[$pid]['so_luong_da_ban'] ?? 0) * 0.6 + ($catalog[$pid]['diem_danh_gia'] ?? 5.0) * 0.4);
        foreach ($categoryMap as $cat => &$pList) {
            usort($pList, fn($a, $b) => $popScoreFn($b) <=> $popScoreFn($a));
        }
        foreach ($brandMap as $br => &$pList) {
            usort($pList, fn($a, $b) => $popScoreFn($b) <=> $popScoreFn($a));
        }

        // Global top popular products
        $sortedByPop = $catalog;
        uasort($sortedByPop, fn($a, $b) => ($b['so_luong_da_ban'] * 0.6 + $b['diem_danh_gia'] * 0.4) <=> ($a['so_luong_da_ban'] * 0.6 + $a['diem_danh_gia'] * 0.4));
        $globalPopularPids = array_slice(array_keys($sortedByPop), 0, 100);

        for ($uIdx = 0; $uIdx < $numUsers; $uIdx++) {
            $uId = $startId + $uIdx;
            $profile = $this->generateUserProfile($uId, $allCategories, $allBrands);
            $users[$uId] = $profile;

            // Target number of interaction sessions for this user
            $targetCount = $this->rng->int(
                $this->config['min_interactions'],
                $this->config['max_interactions']
            );

            $currentTimestamp = $baseTimestamp + $this->rng->int(0, 86400 * 5);
            $interactedPids = [];

            // Assemble structured candidate pool aligned with user's latent preferences
            $poolCandidates = [];

            // 1. From preferred categories (Pareto power-law sampling)
            foreach ($profile['preferred_categories'] as $pCat) {
                $cList = $categoryMap[$pCat] ?? [];
                $cCount = count($cList);
                if ($cCount > 0) {
                    $sampleSize = min(12, $cCount);
                    for ($s = 0; $s < $sampleSize; $s++) {
                        // Power-law exponent 2.2: 50% mass in top 20% head items, long tail in bottom 80%
                        $pIdx = (int)floor(pow($this->rng->next(), 2.2) * $cCount);
                        $cPid = $cList[min($cCount - 1, $pIdx)];
                        if ($cPid) $poolCandidates[$cPid] = true;
                    }
                }
            }

            // 2. From preferred brands (Pareto power-law sampling)
            foreach ($profile['preferred_brands'] as $pBrand) {
                $bList = $brandMap[$pBrand] ?? [];
                $bCount = count($bList);
                if ($bCount > 0) {
                    $sampleSize = min(10, $bCount);
                    for ($s = 0; $s < $sampleSize; $s++) {
                        $pIdx = (int)floor(pow($this->rng->next(), 2.0) * $bCount);
                        $bPid = $bList[min($bCount - 1, $pIdx)];
                        if ($bPid) $poolCandidates[$bPid] = true;
                    }
                }
            }

            // 3. From global popular items
            for ($s = 0; $s < 10; $s++) {
                $popPid = $this->rng->choice($globalPopularPids);
                if ($popPid) $poolCandidates[$popPid] = true;
            }

            // 4. Random exploration from overall catalog
            for ($s = 0; $s < 6; $s++) {
                $rndPid = $catalogPids[$this->rng->int(0, $catalogCount - 1)];
                if ($rndPid) $poolCandidates[$rndPid] = true;
            }

            $candidates = [];
            foreach (array_keys($poolCandidates) as $candPid) {
                if (isset($catalog[$candPid])) {
                    $candidates[$candPid] = $this->computeAffinity($profile, $catalog[$candPid]);
                }
            }
            arsort($candidates);
            $topPool = array_slice(array_keys($candidates), 0, 16);

            $userEvents = 0;
            while ($userEvents < $targetCount) {
                // Select product: either repeat purchase / topPool / exploratory
                $chooseRepeat = (!empty($interactedPids) && $this->rng->next() < $profile['repeat_purchase_tendency']);
                if ($chooseRepeat) {
                    $pid = $this->rng->choice($interactedPids);
                } else {
                    $pid = $this->rng->choice($topPool);
                }
                if (!$pid) continue;

                $interactedPids[] = $pid;
                $interactedPids = array_values(array_unique($interactedPids));

                // Step 1: VIEW or SEARCH_CLICK
                $isSearchClick = ($this->rng->next() < $this->config['p_search_click_ratio']);
                $viewType = $isSearchClick ? 'search_click' : 'view';
                $viewWeight = $isSearchClick ? 1.5 : 1.0;

                $interactions[] = [
                    'ma_tuong_tac' => $interactionId++,
                    'ma_kh' => $uId,
                    'session_id' => 'syn_sess_' . $uId . '_' . bin2hex(random_bytes(3)),
                    'ma_san_pham' => $pid,
                    'loai_tuong_tac' => $viewType,
                    'trong_so' => $viewWeight,
                    'source' => self::SOURCE_TAG,
                    'scenario' => $scenario,
                    'generator_version' => $genVersion,
                    'seed' => $this->config['seed'],
                    'metadata' => [
                        'synthetic' => true,
                        'query' => $isSearchClick ? 'synthetic search' : null
                    ],
                    'created_at' => new \MongoDB\BSON\UTCDateTime($currentTimestamp * 1000),
                ];
                $userEvents++;

                // Step 2: FUNNEL -> ADD_TO_CART
                if ($this->rng->next() < $this->config['p_cart_given_view']) {
                    $currentTimestamp += $this->rng->int(60, 900); // 1 - 15 minutes later
                    $cartQty = $this->rng->int(1, 3);
                    $interactions[] = [
                        'ma_tuong_tac' => $interactionId++,
                        'ma_kh' => $uId,
                        'session_id' => 'syn_sess_' . $uId,
                        'ma_san_pham' => $pid,
                        'loai_tuong_tac' => 'add_to_cart',
                        'trong_so' => 3.0,
                        'source' => self::SOURCE_TAG,
                        'scenario' => $scenario,
                        'generator_version' => $genVersion,
                        'seed' => $this->config['seed'],
                        'metadata' => ['quantity' => $cartQty, 'synthetic' => true],
                        'created_at' => new \MongoDB\BSON\UTCDateTime($currentTimestamp * 1000),
                    ];
                    $userEvents++;

                    // Step 3: FUNNEL -> PURCHASE
                    if ($this->rng->next() < $this->config['p_purchase_given_cart']) {
                        $currentTimestamp += $this->rng->int(300, 3600); // 5 - 60 minutes later
                        $orderId = $orderIdCounter++;
                        $interactions[] = [
                            'ma_tuong_tac' => $interactionId++,
                            'ma_kh' => $uId,
                            'session_id' => 'syn_sess_' . $uId,
                            'ma_san_pham' => $pid,
                            'loai_tuong_tac' => 'purchase',
                            'trong_so' => 5.0,
                            'source' => self::SOURCE_TAG,
                            'scenario' => $scenario,
                            'generator_version' => $genVersion,
                            'seed' => $this->config['seed'],
                            'metadata' => [
                                'order_id' => $orderId,
                                'quantity' => $cartQty,
                                'don_gia' => $catalog[$pid]['gia_ban'] ?? 100000,
                                'synthetic' => true
                            ],
                            'created_at' => new \MongoDB\BSON\UTCDateTime($currentTimestamp * 1000),
                        ];
                        $userEvents++;

                        // Step 4: FUNNEL -> RATING
                        if ($this->rng->next() < $this->config['p_rating_given_purchase']) {
                            $currentTimestamp += $this->rng->int(86400 * 2, 86400 * 7); // 2 - 7 days later
                            $ratingStars = $this->rng->int(3, 5);
                            $interactions[] = [
                                'ma_tuong_tac' => $interactionId++,
                                'ma_kh' => $uId,
                                'session_id' => 'syn_sess_' . $uId,
                                'ma_san_pham' => $pid,
                                'loai_tuong_tac' => 'rating',
                                'trong_so' => (float)$ratingStars,
                                'source' => self::SOURCE_TAG,
                                'scenario' => $scenario,
                                'generator_version' => $genVersion,
                                'seed' => $this->config['seed'],
                                'metadata' => [
                                    'so_sao' => $ratingStars,
                                    'synthetic' => true
                                ],
                                'created_at' => new \MongoDB\BSON\UTCDateTime($currentTimestamp * 1000),
                            ];
                            $userEvents++;
                        }
                    }
                }

                // Advance timestamp for next interaction session (3 hours to 2 days)
                $currentTimestamp += $this->rng->int(3600 * 3, 86400 * 2);
            }
        }

        // Sort interactions chronologically globally
        usort($interactions, fn($a, $b) => $a['created_at']->toDateTime()->getTimestamp() <=> $b['created_at']->toDateTime()->getTimestamp());

        // Calculate statistics
        $stats = $this->computeSanityStats($users, $interactions, count($catalog));

        // Persist strictly to isolated experimental database
        if ($persistToMongo) {
            $this->expDb->synthetic_users->drop();
            $this->expDb->synthetic_interactions->drop();
            $this->expDb->synthetic_config->drop();

            $this->expDb->synthetic_users->insertMany(array_values($users));
            $this->expDb->synthetic_interactions->insertMany($interactions);
            $this->expDb->synthetic_config->insertOne([
                'config' => $this->config,
                'stats' => $stats,
                'generated_at' => new \MongoDB\BSON\UTCDateTime(),
            ]);

            // Create index on experimental collections
            $this->expDb->synthetic_interactions->createIndex(['ma_kh' => 1, 'created_at' => 1]);
            $this->expDb->synthetic_interactions->createIndex(['ma_san_pham' => 1]);
        }

        return [
            'config' => $this->config,
            'users' => $users,
            'interactions' => $interactions,
            'stats' => $stats,
        ];
    }

    /**
     * Compute comprehensive sanity statistics
     */
    public function computeSanityStats(array $users, array $interactions, int $totalCatalogCount): array {
        $userInteractionsCount = [];
        $productInteractionsCount = [];
        $uniquePairs = [];
        $eventCounts = [
            'view' => 0,
            'search_click' => 0,
            'add_to_cart' => 0,
            'purchase' => 0,
            'rating' => 0,
        ];

        foreach ($interactions as $ev) {
            $u = (string)$ev['ma_kh'];
            $p = (string)$ev['ma_san_pham'];
            $t = (string)$ev['loai_tuong_tac'];

            $userInteractionsCount[$u] = ($userInteractionsCount[$u] ?? 0) + 1;
            $productInteractionsCount[$p] = ($productInteractionsCount[$p] ?? 0) + 1;
            $uniquePairs[$u . '_' . $p] = true;

            if (isset($eventCounts[$t])) {
                $eventCounts[$t]++;
            }
        }

        $countsList = array_values($userInteractionsCount);
        sort($countsList);
        $totalUsers = count($userInteractionsCount);
        $median = 0;
        if ($totalUsers > 0) {
            $mid = (int)floor($totalUsers / 2);
            $median = ($totalUsers % 2 !== 0) ? $countsList[$mid] : (($countsList[$mid - 1] + $countsList[$mid]) / 2);
        }

        $uniqueProductsCount = count($productInteractionsCount);
        $uniquePairsCount = count($uniquePairs);
        $matrixCells = $totalUsers * $totalCatalogCount;
        $density = $matrixCells > 0 ? round(($uniquePairsCount / $matrixCells) * 100, 4) : 0.0;

        // User thresholds
        $uGe5 = count(array_filter($countsList, fn($c) => $c >= 5));
        $uGe10 = count(array_filter($countsList, fn($c) => $c >= 10));
        $uGe20 = count(array_filter($countsList, fn($c) => $c >= 20));

        // Product thresholds
        $pCounts = array_values($productInteractionsCount);
        $pGe2 = count(array_filter($pCounts, fn($c) => $c >= 2));
        $pGe5 = count(array_filter($pCounts, fn($c) => $c >= 5));
        $pGe10 = count(array_filter($pCounts, fn($c) => $c >= 10));

        return [
            'synthetic_users' => $totalUsers,
            'total_interactions' => count($interactions),
            'unique_user_item_pairs' => $uniquePairsCount,
            'unique_products_interacted' => $uniqueProductsCount,
            'catalog_total_products' => $totalCatalogCount,
            'catalog_coverage_pct' => round(($uniqueProductsCount / max(1, $totalCatalogCount)) * 100, 2),
            'avg_interactions_per_user' => $totalUsers > 0 ? round(count($interactions) / $totalUsers, 2) : 0,
            'median_interactions_per_user' => $median,
            'min_interactions_per_user' => !empty($countsList) ? min($countsList) : 0,
            'max_interactions_per_user' => !empty($countsList) ? max($countsList) : 0,
            'matrix_dimensions' => "{$totalUsers} users x {$totalCatalogCount} items",
            'matrix_density_pct' => $density,
            'event_counts' => $eventCounts,
            'users_ge_5_interactions' => $uGe5,
            'users_ge_10_interactions' => $uGe10,
            'users_ge_20_interactions' => $uGe20,
            'products_ge_2_interactions' => $pGe2,
            'products_ge_5_interactions' => $pGe5,
            'products_ge_10_interactions' => $pGe10,
        ];
    }
}

// CLI runner if executed directly
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    global $db, $mongoClient;

    $options = getopt('', ['seed:', 'users:']);
    $seed = isset($options['seed']) ? (int)$options['seed'] : 42;
    $users = isset($options['users']) ? (int)$options['users'] : 500;

    $expDb = $mongoClient->selectDatabase('skinsyntax_cf_dev');
    $generator = new SyntheticDatasetGenerator($db, $expDb, $seed, ['num_users' => $users]);
    $result = $generator->generate(true);

    echo "=== SYNTHETIC DATASET GENERATION COMPLETE ===\n";
    echo json_encode($result['stats'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
