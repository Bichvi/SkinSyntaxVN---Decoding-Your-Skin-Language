<?php
/**
 * evaluate_cf.php
 * Offline Evaluation Engine for Collaborative Filtering Experiment (Phase C)
 *
 * Implements:
 * 1. Temporal Per-User Holdout (Leave-One-Out on latest positive interaction).
 * 2. Unseen Candidate Pool (all active catalog items minus user's train items).
 * 3. Baselines: RANDOM, MOST_POPULAR.
 * 4. Models: Item-Based kNN (Cosine similarity), Regularized Biased Matrix Factorization via SGD (Funk MF).
 * 5. Offline Hybrid Simulation: Adaptive/Popularity Base + CF (weights: 0.0, 0.2, 0.4).
 * 6. Metrics: HitRate@K, Precision@K, Recall@K, NDCG@K, MRR@K (K = 5, 10).
 */

class CollaborativeFilteringEvaluator {
    private array $catalog;
    private array $trainMatrix;     // [uId => [pId => score]]
    private array $testSet;         // [uId => test_pId]
    private array $excludedUsers;
    private array $itemPopularity;  // [pId => total_score]
    private float $globalMean;
    private int $kLatent;
    private int $sgdEpochs;
    private float $learningRate;
    private float $regularization;

    public function __construct(
        array $catalog,
        int $kLatent = 5,
        int $sgdEpochs = 25,
        float $learningRate = 0.01,
        float $regularization = 0.05
    ) {
        $this->catalog = $catalog;
        $this->kLatent = $kLatent;
        $this->sgdEpochs = $sgdEpochs;
        $this->learningRate = $learningRate;
        $this->regularization = $regularization;
    }

    /**
     * Prepare Temporal Per-User Holdout Split
     * For each user with >= 5 distinct items:
     * - Latest positive item (weight >= 3.0, or latest view) = Held-out Test Item
     * - Earlier items = Train Set
     */
    public function prepareTemporalHoldout(array $interactions, int $minTrainItems = 5): array {
        // Group interactions by user
        $userEvents = [];
        foreach ($interactions as $ev) {
            $u = (string)$ev['ma_kh'];
            $ts = $ev['created_at'] instanceof \MongoDB\BSON\UTCDateTime
                ? $ev['created_at']->toDateTime()->getTimestamp()
                : (int)($ev['created_at'] ?? time());
            $userEvents[$u][] = [
                'pid' => (string)$ev['ma_san_pham'],
                'type' => (string)$ev['loai_tuong_tac'],
                'weight' => (float)($ev['trong_so'] ?? 1.0),
                'timestamp' => $ts,
            ];
        }

        $this->trainMatrix = [];
        $this->testSet = [];
        $this->excludedUsers = [];

        foreach ($userEvents as $u => $events) {
            // Sort chronologically
            usort($events, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

            // Group by item to compute aggregated implicit scores: r_ui = min(10.0, sum(weights))
            $itemScores = [];
            $itemLastSeen = [];
            foreach ($events as $ev) {
                $p = $ev['pid'];
                $itemScores[$p] = min(10.0, ($itemScores[$p] ?? 0.0) + $ev['weight']);
                $itemLastSeen[$p] = $ev['timestamp'];
            }

            if (count($itemScores) < ($minTrainItems + 1)) {
                $this->excludedUsers[$u] = count($itemScores);
                continue;
            }

            // Identify candidate positive holdout items (prefer purchases/carts with score >= 3.0)
            $positiveItems = [];
            foreach ($itemScores as $p => $score) {
                if ($score >= 3.0) {
                    $positiveItems[$p] = $itemLastSeen[$p];
                }
            }

            // Pick latest positive item as holdout; if none >= 3.0, pick latest item overall
            if (!empty($positiveItems)) {
                arsort($positiveItems);
                $testPid = array_key_first($positiveItems);
            } else {
                arsort($itemLastSeen);
                $testPid = array_key_first($itemLastSeen);
            }

            $this->testSet[$u] = $testPid;

            // Remaining items form the train set
            foreach ($itemScores as $p => $score) {
                if ($p !== $testPid) {
                    $this->trainMatrix[$u][$p] = $score;
                }
            }
        }

        // Compute global train statistics
        $allRatings = [];
        $this->itemPopularity = [];
        foreach ($this->trainMatrix as $u => $pList) {
            foreach ($pList as $p => $sc) {
                $this->itemPopularity[$p] = ($this->itemPopularity[$p] ?? 0.0) + $sc;
                $allRatings[] = $sc;
            }
        }
        $this->globalMean = !empty($allRatings) ? (array_sum($allRatings) / count($allRatings)) : 3.5;
        arsort($this->itemPopularity);

        return [
            'total_users' => count($userEvents),
            'evaluated_users' => count($this->testSet),
            'excluded_users' => count($this->excludedUsers),
            'train_pairs' => count($allRatings),
        ];
    }

    /**
     * Train Item-Item Cosine Similarity Matrix
     */
    public function trainItemKnn(): array {
        // Invert train matrix to item-user: [pId => [uId => score]]
        $itemUser = [];
        $itemNorms = [];
        foreach ($this->trainMatrix as $u => $pList) {
            foreach ($pList as $p => $sc) {
                $itemUser[$p][$u] = $sc;
            }
        }

        foreach ($itemUser as $p => $uVector) {
            $sq = 0.0;
            foreach ($uVector as $sc) $sq += $sc * $sc;
            $itemNorms[$p] = sqrt($sq);
        }

        $itemIds = array_keys($itemUser);
        $numItems = count($itemIds);
        $simMatrix = [];

        for ($i = 0; $i < $numItems; $i++) {
            $pA = $itemIds[$i];
            $vecA = $itemUser[$pA];
            $normA = $itemNorms[$pA];
            if ($normA <= 0.0) continue;

            for ($j = $i + 1; $j < $numItems; $j++) {
                $pB = $itemIds[$j];
                $vecB = $itemUser[$pB];
                $normB = $itemNorms[$pB];
                if ($normB <= 0.0) continue;

                $dot = 0.0;
                foreach ($vecA as $u => $valA) {
                    if (isset($vecB[$u])) {
                        $dot += $valA * $vecB[$u];
                    }
                }

                if ($dot > 0.0) {
                    $cos = $dot / ($normA * $normB);
                    if ($cos > 0.01) {
                        $simMatrix[$pA][$pB] = round($cos, 4);
                        $simMatrix[$pB][$pA] = round($cos, 4);
                    }
                }
            }
        }

        return $simMatrix;
    }

    /**
     * Train Regularized Biased Matrix Factorization via SGD (Funk MF)
     */
    public function trainMatrixFactorization(int $seed = 42): array {
        $k = $this->kLatent;
        $users = array_keys($this->trainMatrix);
        $items = array_keys($this->itemPopularity);

        $userBias = [];
        $itemBias = [];
        $userFactors = [];
        $itemFactors = [];

        // Deterministic initialization based on seed
        mt_srand($seed);
        foreach ($users as $u) {
            $userBias[$u] = 0.0;
            $userFactors[$u] = [];
            for ($f = 0; $f < $k; $f++) {
                $userFactors[$u][$f] = (mt_rand(1, 100) / 1000.0);
            }
        }
        foreach ($items as $p) {
            $itemBias[$p] = 0.0;
            $itemFactors[$p] = [];
            for ($f = 0; $f < $k; $f++) {
                $itemFactors[$p][$f] = (mt_rand(1, 100) / 1000.0);
            }
        }

        $mu = $this->globalMean;
        $gamma = $this->learningRate;
        $lambda = $this->regularization;

        // Flatten training examples
        $samples = [];
        foreach ($this->trainMatrix as $u => $pList) {
            foreach ($pList as $p => $r) {
                $samples[] = [$u, $p, (float)$r];
            }
        }

        // SGD iterations
        for ($epoch = 0; $epoch < $this->sgdEpochs; $epoch++) {
            foreach ($samples as $sm) {
                $u = $sm[0];
                $p = $sm[1];
                $r = $sm[2];

                $dot = 0.0;
                for ($f = 0; $f < $k; $f++) {
                    $dot += $userFactors[$u][$f] * $itemFactors[$p][$f];
                }
                $pred = $mu + $userBias[$u] + $itemBias[$p] + $dot;
                $err = $r - $pred;

                // Update biases
                $userBias[$u] += $gamma * ($err - $lambda * $userBias[$u]);
                $itemBias[$p] += $gamma * ($err - $lambda * $itemBias[$p]);

                // Update latent vectors
                for ($f = 0; $f < $k; $f++) {
                    $uOld = $userFactors[$u][$f];
                    $iOld = $itemFactors[$p][$f];
                    $userFactors[$u][$f] += $gamma * ($err * $iOld - $lambda * $uOld);
                    $itemFactors[$p][$f] += $gamma * ($err * $uOld - $lambda * $iOld);
                }
            }
        }

        return [
            'mu' => $mu,
            'user_bias' => $userBias,
            'item_bias' => $itemBias,
            'user_factors' => $userFactors,
            'item_factors' => $itemFactors,
        ];
    }

    /**
     * Train Bayesian Personalized Ranking (BPR) via SGD on pairwise implicit preferences
     *
     * Formulation:
     *   Optimization: min -sum_{(u, i, j)} ln sigma(x_uij) + lambda * (||p_u||^2 + ||q_i||^2 + ||q_j||^2 + b_i^2 + b_j^2)
     *   Prediction:   x_ui = b_i + p_u^T q_i
     *   Difference:   x_uij = x_ui - x_uj = (b_i - b_j) + p_u^T (q_i - q_j)
     */
    public function trainBpr(int $seed = 42, array $config = []): array {
        $k = $config['k'] ?? $this->kLatent;
        $gamma = $config['learning_rate'] ?? 0.05;
        $lambda = $config['regularization'] ?? 0.01;
        $epochs = $config['epochs'] ?? 25;

        $users = array_keys($this->trainMatrix);
        $catalogPids = array_map('strval', array_keys($this->catalog));
        $catalogCount = count($catalogPids);

        // Initialize factors and biases deterministically
        mt_srand($seed);
        $userFactors = [];
        $itemFactors = [];
        $itemBias = [];

        foreach ($users as $u) {
            $userFactors[$u] = [];
            for ($f = 0; $f < $k; $f++) {
                $userFactors[$u][$f] = (mt_rand(-50, 50) / 1000.0);
            }
        }

        foreach ($catalogPids as $p) {
            $itemBias[$p] = 0.0;
            $itemFactors[$p] = [];
            for ($f = 0; $f < $k; $f++) {
                $itemFactors[$p][$f] = (mt_rand(-50, 50) / 1000.0);
            }
        }

        // Map positive observed training items per user
        $userPositives = [];
        $trainingPairs = [];
        foreach ($this->trainMatrix as $u => $pList) {
            $uStr = (string)$u;
            foreach ($pList as $p => $r) {
                $pStr = (string)$p;
                $userPositives[$uStr][$pStr] = true;
                $trainingPairs[] = [$uStr, $pStr];
            }
        }

        $numPairs = count($trainingPairs);
        if ($numPairs === 0) {
            return [];
        }

        $rng = new DeterministicRandom($seed);

        // SGD Training on BPR Triples
        for ($epoch = 0; $epoch < $epochs; $epoch++) {
            for ($idx = 0; $idx < $numPairs; $idx++) {
                $pair = $trainingPairs[$idx];
                $u = $pair[0];
                $i = $pair[1];

                // Negative sampling: sample unseen item j not in user train and not held-out test target
                $testTarget = isset($this->testSet[$u]) ? (string)$this->testSet[$u] : null;
                $j = null;
                for ($tries = 0; $tries < 100; $tries++) {
                    $candJ = $catalogPids[$rng->int(0, $catalogCount - 1)];
                    if (!isset($userPositives[$u][$candJ]) && $candJ !== $testTarget) {
                        $j = $candJ;
                        break;
                    }
                }
                if ($j === null) continue;

                // Difference calculation
                $dotDiff = 0.0;
                for ($f = 0; $f < $k; $f++) {
                    $dotDiff += $userFactors[$u][$f] * ($itemFactors[$i][$f] - $itemFactors[$j][$f]);
                }
                $x_uij = ($itemBias[$i] - $itemBias[$j]) + $dotDiff;

                // Sigmoid derivative: z = 1 / (1 + exp(x_uij)) with numerical clipping
                if ($x_uij > 30.0) {
                    $z = exp(-$x_uij);
                } elseif ($x_uij < -30.0) {
                    $z = 1.0;
                } else {
                    $z = 1.0 / (1.0 + exp($x_uij));
                }

                // Update biases
                $itemBias[$i] += $gamma * ($z - $lambda * $itemBias[$i]);
                $itemBias[$j] += $gamma * (-$z - $lambda * $itemBias[$j]);

                // Update factors
                for ($f = 0; $f < $k; $f++) {
                    $uOld = $userFactors[$u][$f];
                    $iOld = $itemFactors[$i][$f];
                    $jOld = $itemFactors[$j][$f];

                    $userFactors[$u][$f] += $gamma * ($z * ($iOld - $jOld) - $lambda * $uOld);
                    $itemFactors[$i][$f] += $gamma * ($z * $uOld - $lambda * $iOld);
                    $itemFactors[$j][$f] += $gamma * (-$z * $uOld - $lambda * $jOld);
                }
            }
        }

        return [
            'item_bias' => $itemBias,
            'user_factors' => $userFactors,
            'item_factors' => $itemFactors,
            'config' => [
                'k' => $k,
                'learning_rate' => $gamma,
                'regularization' => $lambda,
                'epochs' => $epochs,
                'negative_sampling' => 'uniform_unseen',
                'seed' => $seed,
            ]
        ];
    }

    /**
     * Evaluate models against unseen candidate pool for all eligible users
     */
    public function evaluateAll(int $seed = 42, bool $includeBpr = false, array $bprConfig = []): array {
        $simMatrix = $this->trainItemKnn();
        $mfModel = $this->trainMatrixFactorization($seed);
        $bprModel = $includeBpr ? $this->trainBpr($seed, $bprConfig) : null;

        $catalogPids = array_keys($this->catalog);

        // Pre-normalize Popularity scores
        $maxPop = !empty($this->itemPopularity) ? max($this->itemPopularity) : 1.0;
        $normPopularity = [];
        foreach ($this->itemPopularity as $p => $pop) {
            $normPopularity[$p] = $pop / $maxPop;
        }

        $methods = ['RANDOM', 'MOST_POPULAR', 'ITEM_KNN', 'MATRIX_FACTORIZATION'];
        if ($includeBpr) {
            $methods[] = 'BPR';
        }
        $methods[] = 'HYBRID_W02';
        $methods[] = 'HYBRID_W04';

        $results = [];
        foreach ($methods as $m) {
            $results[$m] = [
                'hit_5' => 0, 'hit_10' => 0,
                'precision_5' => 0.0, 'precision_10' => 0.0,
                'recall_5' => 0.0, 'recall_10' => 0.0,
                'ndcg_5' => 0.0, 'ndcg_10' => 0.0,
                'mrr_10' => 0.0,
            ];
        }

        $numEval = count($this->testSet);
        if ($numEval === 0) {
            return [];
        }

        foreach ($this->testSet as $u => $targetPid) {
            $seenPids = $this->trainMatrix[$u] ?? [];

            // Unseen candidate pool: all active catalog items except user's train items
            $candidatePool = [];
            foreach ($catalogPids as $candPid) {
                if (!isset($seenPids[$candPid])) {
                    $candidatePool[] = $candPid;
                }
            }

            // 1. RANDOM
            shuffle($candidatePool);
            $recsRandom = array_slice($candidatePool, 0, 10);
            $this->accumulateMetrics($results['RANDOM'], $targetPid, $recsRandom);

            // 2. MOST_POPULAR
            $popScores = [];
            foreach ($candidatePool as $candPid) {
                $popScores[$candPid] = $this->itemPopularity[$candPid] ?? 0.0;
            }
            arsort($popScores);
            $recsPop = array_slice(array_keys($popScores), 0, 10);
            $this->accumulateMetrics($results['MOST_POPULAR'], $targetPid, $recsPop);

            // 3. ITEM_KNN
            $knnScores = [];
            foreach ($candidatePool as $candPid) {
                $score = 0.0;
                foreach ($seenPids as $pastPid => $weight) {
                    $sim = $simMatrix[$pastPid][$candPid] ?? 0.0;
                    if ($sim > 0.0) {
                        $score += $weight * $sim;
                    }
                }
                // Fallback slight popularity if no neighbor overlap
                $knnScores[$candPid] = $score > 0 ? $score : (($this->itemPopularity[$candPid] ?? 0.0) * 0.001);
            }
            arsort($knnScores);
            $recsKnn = array_slice(array_keys($knnScores), 0, 10);
            $this->accumulateMetrics($results['ITEM_KNN'], $targetPid, $recsKnn);

            // 4. MATRIX_FACTORIZATION
            $mfScores = [];
            $uFactors = $mfModel['user_factors'][$u] ?? null;
            $uBias = $mfModel['user_bias'][$u] ?? 0.0;
            $mu = $mfModel['mu'];

            foreach ($candidatePool as $candPid) {
                if ($uFactors !== null && isset($mfModel['item_factors'][$candPid])) {
                    $dot = 0.0;
                    for ($f = 0; $f < $this->kLatent; $f++) {
                        $dot += $uFactors[$f] * $mfModel['item_factors'][$candPid][$f];
                    }
                    $mfScores[$candPid] = $mu + $uBias + ($mfModel['item_bias'][$candPid] ?? 0.0) + $dot;
                } else {
                    $mfScores[$candPid] = ($this->itemPopularity[$candPid] ?? 0.0) * 0.001;
                }
            }
            arsort($mfScores);
            $recsMf = array_slice(array_keys($mfScores), 0, 10);
            $this->accumulateMetrics($results['MATRIX_FACTORIZATION'], $targetPid, $recsMf);

            // 5. BPR (Bayesian Personalized Ranking)
            if ($includeBpr && $bprModel !== null) {
                $bprScores = [];
                $uBprFactors = $bprModel['user_factors'][$u] ?? null;
                $kBpr = $bprModel['config']['k'] ?? $this->kLatent;

                foreach ($candidatePool as $candPid) {
                    if ($uBprFactors !== null && isset($bprModel['item_factors'][$candPid])) {
                        $dot = 0.0;
                        for ($f = 0; $f < $kBpr; $f++) {
                            $dot += $uBprFactors[$f] * $bprModel['item_factors'][$candPid][$f];
                        }
                        $bprScores[$candPid] = ($bprModel['item_bias'][$candPid] ?? 0.0) + $dot;
                    } else {
                        $bprScores[$candPid] = ($this->itemPopularity[$candPid] ?? 0.0) * 0.001;
                    }
                }
                arsort($bprScores);
                $recsBpr = array_slice(array_keys($bprScores), 0, 10);
                $this->accumulateMetrics($results['BPR'], $targetPid, $recsBpr);
            }

            // 6. HYBRID W=0.2 (80% Popularity + 20% CF) & HYBRID W=0.4 (60% Popularity + 40% CF)
            // Normalize candidate scores to [0, 1]
            $maxKnn = !empty($knnScores) && max($knnScores) > 0 ? max($knnScores) : 1.0;
            $maxMf = !empty($mfScores) && max($mfScores) > 0 ? max($mfScores) : 1.0;

            $hybScores02 = [];
            $hybScores04 = [];
            foreach ($candidatePool as $candPid) {
                $pScore = $normPopularity[$candPid] ?? 0.0;
                $cfScore = 0.5 * (($knnScores[$candPid] ?? 0.0) / $maxKnn) + 0.5 * (($mfScores[$candPid] ?? 0.0) / $maxMf);
                $hybScores02[$candPid] = (0.80 * $pScore) + (0.20 * $cfScore);
                $hybScores04[$candPid] = (0.60 * $pScore) + (0.40 * $cfScore);
            }
            arsort($hybScores02);
            arsort($hybScores04);
            $recsHyb02 = array_slice(array_keys($hybScores02), 0, 10);
            $recsHyb04 = array_slice(array_keys($hybScores04), 0, 10);

            $this->accumulateMetrics($results['HYBRID_W02'], $targetPid, $recsHyb02);
            $this->accumulateMetrics($results['HYBRID_W04'], $targetPid, $recsHyb04);
        }

        // Average across all evaluated users
        $finalMetrics = [];
        foreach ($results as $m => $data) {
            $finalMetrics[$m] = [
                'HitRate@5' => round($data['hit_5'] / $numEval, 4),
                'Precision@5' => round($data['precision_5'] / $numEval, 4),
                'Recall@5' => round($data['recall_5'] / $numEval, 4),
                'NDCG@5' => round($data['ndcg_5'] / $numEval, 4),
                'HitRate@10' => round($data['hit_10'] / $numEval, 4),
                'Precision@10' => round($data['precision_10'] / $numEval, 4),
                'Recall@10' => round($data['recall_10'] / $numEval, 4),
                'NDCG@10' => round($data['ndcg_10'] / $numEval, 4),
                'MRR@10' => round($data['mrr_10'] / $numEval, 4),
            ];
        }

        return [
            'num_evaluated_users' => $numEval,
            'metrics' => $finalMetrics,
        ];
    }

    /**
     * Compute ranking metrics for single user
     */
    private function accumulateMetrics(array &$bucket, string|int $targetPid, array $top10): void {
        $topStr = array_map('strval', $top10);
        $targetStr = (string)$targetPid;
        $top5 = array_slice($topStr, 0, 5);

        // K = 5
        $rank5 = array_search($targetStr, $top5, true);
        if ($rank5 !== false) {
            $bucket['hit_5']++;
            $bucket['precision_5'] += (1.0 / 5.0);
            $bucket['recall_5'] += 1.0;
            $bucket['ndcg_5'] += (1.0 / log($rank5 + 2, 2));
        }

        // K = 10
        $rank10 = array_search($targetStr, $topStr, true);
        if ($rank10 !== false) {
            $bucket['hit_10']++;
            $bucket['precision_10'] += (1.0 / 10.0);
            $bucket['recall_10'] += 1.0;
            $bucket['ndcg_10'] += (1.0 / log($rank10 + 2, 2));
            $bucket['mrr_10'] += (1.0 / ($rank10 + 1));
        }
    }

    public function getTrainMatrix(): array {
        return $this->trainMatrix;
    }

    public function getTestSet(): array {
        return $this->testSet;
    }

    public function getCatalog(): array {
        return $this->catalog;
    }
}
