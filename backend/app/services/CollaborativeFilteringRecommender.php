<?php
/**
 * CollaborativeFilteringRecommender.php
 * Collaborative Filtering Engine (Phase C)
 *
 * Implements:
 * 1. Item-Based kNN Collaborative Filtering with Cosine Similarity
 * 2. Regularized Matrix Factorization (Truncated SVD with User & Item Biases)
 * 3. Seed aggregation from tuong_tac_nguoi_dung, chi_tiet_hoa_don, and danh_gia
 * 4. Model caching to cf_model_cache.json
 * 5. Cold-start graceful fallback to Content-Based / Popularity
 */

class CollaborativeFilteringRecommender {
    private static ?array $cachedModel = null;
    private string $cachePath;
    private $db;

    public const LATENT_FACTORS = 5;
    public const SGD_EPOCHS = 25;
    public const LEARNING_RATE = 0.01;
    public const REGULARIZATION = 0.05;

    public function __construct($db = null, ?string $cachePath = null) {
        if ($db === null) {
            global $db;
            $this->db = $db;
        } else {
            $this->db = $db;
        }
        $this->cachePath = $cachePath ?? dirname(__DIR__) . '/content/cf_model_cache.json';
    }

    /**
     * Recommend products similar to a given product using Item-Item Collaborative Filtering
     */
    public function recommendSimilarItems(string|int $productId, int $limit = 4): array {
        $model = $this->loadModel();
        if (!$model) return [];

        $pid = (string)$productId;
        $simMatrix = $model['item_similarity'] ?? [];

        if (!isset($simMatrix[$pid]) || empty($simMatrix[$pid])) {
            return [];
        }

        $neighbors = $simMatrix[$pid];
        arsort($neighbors);

        $results = [];
        foreach ($neighbors as $candId => $simScore) {
            if ($candId === $pid) continue;
            $results[] = [
                'ma_san_pham' => (string)$candId,
                'cf_similarity' => round((float)$simScore, 4),
                'method' => 'item_based_knn',
            ];
            if (count($results) >= $limit) break;
        }

        return $results;
    }

    /**
     * Recommend products for a user using User-Item Collaborative Filtering (kNN + Matrix Factorization)
     */
    public function recommendForUser(int|string $userId, array $sessionProductIds = [], int $limit = 4): array {
        $model = $this->loadModel();
        if (!$model) return [];

        $uId = (string)$userId;
        $userInteractions = $model['user_item_matrix'][$uId] ?? [];

        // If user has session items, treat session items as positive implicit interactions
        foreach ($sessionProductIds as $sPid) {
            $sPidStr = (string)$sPid;
            if (!isset($userInteractions[$sPidStr])) {
                $userInteractions[$sPidStr] = 2.0; // Implicit session weight
            }
        }

        if (empty($userInteractions)) {
            return []; // Cold-start fallback
        }

        $itemSim = $model['item_similarity'] ?? [];
        $mu = (float)($model['global_mean'] ?? 3.5);
        $userBias = (float)($model['user_bias'][$uId] ?? 0.0);
        $itemBiases = $model['item_bias'] ?? [];
        $userFactors = $model['user_factors'][$uId] ?? null;
        $itemFactors = $model['item_factors'] ?? [];

        $scores = [];
        $interactedLookup = array_flip(array_map('strval', array_keys($userInteractions)));

        // Score candidates using Item-Item kNN aggregation & SVD score
        foreach ($itemSim as $candId => $neighbors) {
            if (isset($interactedLookup[$candId])) {
                continue; // Do not recommend items the user already interacted with
            }

            // 1. Item kNN score
            $weightedSimSum = 0.0;
            $simSum = 0.0;
            foreach ($userInteractions as $pastPid => $weight) {
                $sim = $itemSim[$pastPid][$candId] ?? ($itemSim[$candId][$pastPid] ?? 0.0);
                if ($sim > 0.0) {
                    $weightedSimSum += $weight * $sim;
                    $simSum += $sim;
                }
            }
            $knnScore = $simSum > 0 ? ($weightedSimSum / $simSum) : 0.0;

            // 2. SVD predicted score
            $svdScore = 0.0;
            if ($userFactors !== null && isset($itemFactors[$candId])) {
                $iBias = (float)($itemBiases[$candId] ?? 0.0);
                $dot = 0.0;
                foreach ($userFactors as $fIdx => $fVal) {
                    $dot += $fVal * ($itemFactors[$candId][$fIdx] ?? 0.0);
                }
                $svdScore = max(0.0, $mu + $userBias + $iBias + $dot);
            }

            // Combine kNN + SVD (70% kNN + 30% SVD when SVD available)
            $combinedScore = $svdScore > 0 ? (0.70 * $knnScore + 0.30 * ($svdScore / 5.0)) : $knnScore;

            if ($combinedScore > 0.01) {
                $scores[$candId] = [
                    'ma_san_pham' => (string)$candId,
                    'knn_score' => round($knnScore, 4),
                    'svd_score' => round($svdScore, 4),
                    'cf_score' => round($combinedScore, 4),
                ];
            }
        }

        uasort($scores, fn($a, $b) => $b['cf_score'] <=> $a['cf_score']);
        return array_slice(array_values($scores), 0, $limit);
    }

    /**
     * Load CF model from file cache or build if missing
     */
    public function loadModel(): ?array {
        if (self::$cachedModel !== null) {
            return self::$cachedModel;
        }

        if (file_exists($this->cachePath)) {
            $content = file_get_contents($this->cachePath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data) && !empty($data['item_similarity'])) {
                    self::$cachedModel = $data;
                    return self::$cachedModel;
                }
            }
        }

        if ($this->db !== null) {
            return $this->buildModel($this->db);
        }

        return null;
    }

    /**
     * Build Collaborative Filtering Model from MongoDB interactions, orders, and ratings
     */
    public function buildModel($db = null): array {
        $db = $db ?? $this->db;
        if (!$db) return [];

        // 1. Collect Interaction Pairs: [uId => [pId => score]]
        $matrix = [];

        // Source A: tuong_tac_nguoi_dung
        try {
            $interactions = iterator_to_array($db->tuong_tac_nguoi_dung->find([]));
            foreach ($interactions as $doc) {
                $u = $doc['ma_kh'] ? (string)$doc['ma_kh'] : ((string)($doc['session_id'] ?? ''));
                $p = (string)($doc['ma_san_pham'] ?? '');
                $w = (float)($doc['trong_so'] ?? 1.0);
                if ($u !== '' && $p !== '') {
                    $matrix[$u][$p] = ($matrix[$u][$p] ?? 0.0) + $w;
                }
            }
        } catch (\Throwable $e) {
            error_log('CF load tuong_tac error: ' . $e->getMessage());
        }

        // Source B: chi_tiet_hoa_don + hoa_don
        try {
            $orders = iterator_to_array($db->hoa_don->find([], ['projection' => ['ma_hoa_don' => 1, 'ma_kh' => 1]]));
            $orderUserMap = [];
            foreach ($orders as $od) {
                $hid = (int)($od['ma_hoa_don'] ?? 0);
                $uid = (int)($od['ma_kh'] ?? 0);
                if ($hid > 0 && $uid > 0) {
                    $orderUserMap[$hid] = (string)$uid;
                }
            }

            $orderItems = iterator_to_array($db->chi_tiet_hoa_don->find([]));
            foreach ($orderItems as $item) {
                $hid = (int)($item['ma_hoa_don'] ?? 0);
                $pid = (string)($item['ma_san_pham'] ?? '');
                $u = $orderUserMap[$hid] ?? '';
                if ($u !== '' && $pid !== '') {
                    $matrix[$u][$pid] = ($matrix[$u][$pid] ?? 0.0) + 5.0; // High implicit purchase signal
                }
            }
        } catch (\Throwable $e) {
            error_log('CF load orders error: ' . $e->getMessage());
        }

        // Source C: danh_gia
        try {
            $ratings = iterator_to_array($db->danh_gia->find([]));
            foreach ($ratings as $r) {
                $u = (string)($r['ma_kh'] ?? '');
                $p = (string)($r['ma_san_pham'] ?? '');
                $stars = (float)($r['so_sao'] ?? 5.0);
                if ($u !== '' && $p !== '') {
                    $matrix[$u][$p] = max($matrix[$u][$p] ?? 0.0, $stars);
                }
            }
        } catch (\Throwable $e) {
            error_log('CF load ratings error: ' . $e->getMessage());
        }

        // 2. Invert to Item-User Matrix for Item-Item CF: [pId => [uId => score]]
        $itemUserMatrix = [];
        $allRatings = [];
        $userAverages = [];

        foreach ($matrix as $u => $pList) {
            $userAverages[$u] = array_sum($pList) / count($pList);
            foreach ($pList as $p => $score) {
                $itemUserMatrix[$p][$u] = $score;
                $allRatings[] = $score;
            }
        }

        $globalMean = !empty($allRatings) ? (array_sum($allRatings) / count($allRatings)) : 3.5;

        // 3. Compute Item-Item Cosine Similarity
        $itemSimilarity = [];
        $itemIds = array_keys($itemUserMatrix);
        $numItems = count($itemIds);

        // Precompute item norms
        $itemNorms = [];
        foreach ($itemUserMatrix as $p => $uVector) {
            $sq = 0.0;
            foreach ($uVector as $val) $sq += $val * $val;
            $itemNorms[$p] = sqrt($sq);
        }

        for ($i = 0; $i < $numItems; $i++) {
            $pA = $itemIds[$i];
            $vecA = $itemUserMatrix[$pA];
            $normA = $itemNorms[$pA];
            if ($normA <= 0.0) continue;

            for ($j = $i + 1; $j < $numItems; $j++) {
                $pB = $itemIds[$j];
                $vecB = $itemUserMatrix[$pB];
                $normB = $itemNorms[$pB];
                if ($normB <= 0.0) continue;

                // Dot product on common users
                $dot = 0.0;
                foreach ($vecA as $u => $valA) {
                    if (isset($vecB[$u])) {
                        $dot += $valA * $vecB[$u];
                    }
                }

                if ($dot > 0.0) {
                    $sim = round($dot / ($normA * $normB), 4);
                    if ($sim > 0.01) {
                        $itemSimilarity[$pA][$pB] = $sim;
                        $itemSimilarity[$pB][$pA] = $sim;
                    }
                }
            }
        }

        // 4. Matrix Factorization (Regularized SVD via SGD)
        $k = self::LATENT_FACTORS;
        $userFactors = [];
        $itemFactors = [];
        $userBias = [];
        $itemBias = [];

        // Initialize factors with small random numbers
        foreach (array_keys($matrix) as $u) {
            $userBias[$u] = 0.0;
            $userFactors[$u] = array_map(fn() => (mt_rand(1, 100) / 1000.0), range(1, $k));
        }
        foreach ($itemIds as $p) {
            $itemBias[$p] = 0.0;
            $itemFactors[$p] = array_map(fn() => (mt_rand(1, 100) / 1000.0), range(1, $k));
        }

        $gamma = self::LEARNING_RATE;
        $lambda = self::REGULARIZATION;

        // SGD Training Loop
        for ($epoch = 0; $epoch < self::SGD_EPOCHS; $epoch++) {
            foreach ($matrix as $u => $pList) {
                foreach ($pList as $p => $r) {
                    if (!isset($itemFactors[$p])) continue;

                    // Predict
                    $dot = 0.0;
                    for ($f = 0; $f < $k; $f++) {
                        $dot += $userFactors[$u][$f] * $itemFactors[$p][$f];
                    }
                    $pred = $globalMean + $userBias[$u] + $itemBias[$p] + $dot;
                    $err = $r - $pred;

                    // Update biases
                    $userBias[$u] += $gamma * ($err - $lambda * $userBias[$u]);
                    $itemBias[$p] += $gamma * ($err - $lambda * $itemBias[$p]);

                    // Update latent factors
                    for ($f = 0; $f < $k; $f++) {
                        $uf = $userFactors[$u][$f];
                        $userFactors[$u][$f] += $gamma * ($err * $itemFactors[$p][$f] - $lambda * $uf);
                        $itemFactors[$p][$f] += $gamma * ($err * $uf - $lambda * $itemFactors[$p][$f]);
                    }
                }
            }
        }

        $modelData = [
            'generated_at' => date('c'),
            'total_users' => count($matrix),
            'total_items' => count($itemIds),
            'global_mean' => round($globalMean, 4),
            'user_item_matrix' => $matrix,
            'item_similarity' => $itemSimilarity,
            'user_bias' => $userBias,
            'item_bias' => $itemBias,
            'user_factors' => $userFactors,
            'item_factors' => $itemFactors,
        ];

        @file_put_contents($this->cachePath, json_encode($modelData, JSON_UNESCAPED_UNICODE));
        self::$cachedModel = $modelData;
        return $modelData;
    }
}
