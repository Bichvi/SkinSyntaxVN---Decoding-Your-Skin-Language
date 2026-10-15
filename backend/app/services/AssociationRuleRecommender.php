<?php
/**
 * AssociationRuleRecommender.php
 * Pairwise Co-occurrence & Association Rules Recommender (Phase D)
 *
 * Implements:
 * 1. Apriori-like pairwise co-occurrence / 2-itemset mining over order transactions (chi_tiet_hoa_don)
 * 2. Support, Confidence, and Lift metrics calculation for genuine co-purchases
 * 3. Multi-tier routine complement fallback when transaction frequency is sparse
 * 4. Model caching to frequently_bought_together.json
 * 5. Bundle recommendation for Product Detail & Cart pages
 */

class AssociationRuleRecommender {
    private static ?array $cachedRules = null;
    private string $cachePath;
    private $db;

    public const MIN_SUPPORT = 0.01;
    public const MIN_CONFIDENCE = 0.10;
    public const MIN_LIFT = 1.0;

    public function __construct($db = null, ?string $cachePath = null) {
        if ($db === null) {
            global $db;
            $this->db = $db;
        } else {
            $this->db = $db;
        }
        $this->cachePath = $cachePath ?? dirname(__DIR__) . '/content/frequently_bought_together.json';
    }

    /**
     * Get Frequently Bought Together recommendations for a given product
     */
    public function getFrequentlyBoughtTogether(string|int $productId, int $limit = 3): array {
        $rules = $this->loadRules();
        $pid = (string)$productId;

        if (isset($rules['product_rules'][$pid]) && !empty($rules['product_rules'][$pid])) {
            $candidates = array_slice($rules['product_rules'][$pid], 0, $limit);
            return $this->populateProductDetails($candidates);
        }

        // Routine complement fallback: if item has no co-purchase rules, find high-rated complement from same brand/routine
        return $this->getRoutineComplementFallback($pid, $limit);
    }

    /**
     * Enrich rule items with product name, price, image from database
     */
    private function populateProductDetails(array $items): array {
        if (!$this->db || empty($items)) return $items;
        $pids = [];
        foreach ($items as $it) {
            $pids[] = is_numeric($it['ma_san_pham']) ? (int)$it['ma_san_pham'] : (string)$it['ma_san_pham'];
            $pids[] = (string)$it['ma_san_pham'];
        }
        try {
            $cursor = $this->db->san_pham->find(['ma_san_pham' => ['$in' => array_values(array_unique($pids))]]);
            $docMap = [];
            foreach ($cursor as $doc) {
                $docMap[(string)$doc['ma_san_pham']] = (array)$doc;
            }
            $enriched = [];
            foreach ($items as $it) {
                $idStr = (string)$it['ma_san_pham'];
                if (isset($docMap[$idStr])) {
                    $doc = $docMap[$idStr];
                    $it['ten_san_pham'] = (string)($doc['ten_san_pham'] ?? '');
                    $it['gia_ban'] = (int)($doc['gia_ban'] ?? 0);
                    $it['gia_thi_truong'] = (int)($doc['gia_thi_truong'] ?? 0);
                    $it['hinh_anh'] = (string)($doc['link_hinh_anh'] ?? $doc['hinh_anh'] ?? '');
                    $it['diem_danh_gia'] = (float)($doc['diem_danh_gia'] ?? 5.0);
                    $it['recommendation_source'] = $it['recommendation_source'] ?? 'ASSOCIATION_RULE';
                    $enriched[] = $it;
                }
            }
            return !empty($enriched) ? $enriched : $items;
        } catch (\Throwable $e) {
            error_log('populateProductDetails error: ' . $e->getMessage());
            return $items;
        }
    }

    /**
     * Fallback for products with no transaction co-occurrence:
     * Suggests complementary routine steps from the same brand or compatible routine
     */
    private function getRoutineComplementFallback(string $productId, int $limit = 3): array {
        if (!$this->db) return [];
        try {
            $pidInt = is_numeric($productId) ? (int)$productId : $productId;
            $target = $this->db->san_pham->findOne(['ma_san_pham' => $pidInt]);
            if (!$target) return [];

            $brandId = $target['ma_thuong_hieu'] ?? null;
            $catId = $target['ma_danh_muc'] ?? null;
            $results = [];
            $seenIds = [
                (string)$target['ma_san_pham'] => true,
                (int)$target['ma_san_pham'] => true,
            ];

            // Tier 1: Same brand, complementary category
            if ($brandId && $catId) {
                $cursor = $this->db->san_pham->find([
                    'ma_san_pham' => ['$ne' => $target['ma_san_pham']],
                    'trang_thai' => 'active',
                    'gia_ban' => ['$gt' => 0],
                    'ma_thuong_hieu' => $brandId,
                    'ma_danh_muc' => ['$ne' => $catId]
                ], ['limit' => $limit, 'sort' => ['diem_danh_gia' => -1, 'so_luong_da_ban' => -1]]);
                foreach ($cursor as $doc) {
                    $docPid = (string)$doc['ma_san_pham'];
                    $seenIds[$docPid] = true;
                    $results[] = [
                        'ma_san_pham' => $docPid,
                        'ten_san_pham' => (string)($doc['ten_san_pham'] ?? ''),
                        'gia_ban' => (int)($doc['gia_ban'] ?? 0),
                        'gia_thi_truong' => (int)($doc['gia_thi_truong'] ?? 0),
                        'hinh_anh' => (string)($doc['link_hinh_anh'] ?? $doc['hinh_anh'] ?? ''),
                        'diem_danh_gia' => (float)($doc['diem_danh_gia'] ?? 5.0),
                        'support' => null,
                        'confidence' => null,
                        'lift' => null,
                        'recommendation_source' => 'ROUTINE_COMPLEMENT',
                        'rule_type' => 'routine_complement',
                    ];
                }
            }

            // Tier 2: Same brand, any category
            if (count($results) < $limit && $brandId) {
                $needed = $limit - count($results);
                $cursor = $this->db->san_pham->find([
                    'ma_san_pham' => ['$nin' => array_keys($seenIds)],
                    'trang_thai' => 'active',
                    'gia_ban' => ['$gt' => 0],
                    'ma_thuong_hieu' => $brandId,
                ], ['limit' => $needed, 'sort' => ['diem_danh_gia' => -1, 'so_luong_da_ban' => -1]]);
                foreach ($cursor as $doc) {
                    $docPid = (string)$doc['ma_san_pham'];
                    $seenIds[$docPid] = true;
                    $results[] = [
                        'ma_san_pham' => $docPid,
                        'ten_san_pham' => (string)($doc['ten_san_pham'] ?? ''),
                        'gia_ban' => (int)($doc['gia_ban'] ?? 0),
                        'gia_thi_truong' => (int)($doc['gia_thi_truong'] ?? 0),
                        'hinh_anh' => (string)($doc['link_hinh_anh'] ?? $doc['hinh_anh'] ?? ''),
                        'diem_danh_gia' => (float)($doc['diem_danh_gia'] ?? 5.0),
                        'support' => null,
                        'confidence' => null,
                        'lift' => null,
                        'recommendation_source' => 'BRAND_COMPLEMENT',
                        'rule_type' => 'brand_complement',
                    ];
                }
            }

            // Tier 3: Top-rated products overall
            if (count($results) < $limit) {
                $needed = $limit - count($results);
                $cursor = $this->db->san_pham->find([
                    'ma_san_pham' => ['$nin' => array_keys($seenIds)],
                    'trang_thai' => 'active',
                    'gia_ban' => ['$gt' => 0],
                ], ['limit' => $needed, 'sort' => ['diem_danh_gia' => -1, 'so_luong_da_ban' => -1]]);
                foreach ($cursor as $doc) {
                    $docPid = (string)$doc['ma_san_pham'];
                    $seenIds[$docPid] = true;
                    $results[] = [
                        'ma_san_pham' => $docPid,
                        'ten_san_pham' => (string)($doc['ten_san_pham'] ?? ''),
                        'gia_ban' => (int)($doc['gia_ban'] ?? 0),
                        'gia_thi_truong' => (int)($doc['gia_thi_truong'] ?? 0),
                        'hinh_anh' => (string)($doc['link_hinh_anh'] ?? $doc['hinh_anh'] ?? ''),
                        'diem_danh_gia' => (float)($doc['diem_danh_gia'] ?? 5.0),
                        'support' => null,
                        'confidence' => null,
                        'lift' => null,
                        'recommendation_source' => 'POPULARITY_FALLBACK',
                        'rule_type' => 'popular_complement',
                    ];
                }
            }

            return $results;
        } catch (\Throwable $e) {
            error_log('getRoutineComplementFallback error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Load mined rules from JSON cache or build if missing
     */
    public function loadRules(): ?array {
        if (self::$cachedRules !== null) {
            return self::$cachedRules;
        }

        if (file_exists($this->cachePath)) {
            $content = file_get_contents($this->cachePath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data) && !empty($data['rules'])) {
                    self::$cachedRules = $data;
                    return self::$cachedRules;
                }
            }
        }

        if ($this->db !== null) {
            return $this->mineRules($this->db);
        }

        return null;
    }

    /**
     * Mine association rules from chi_tiet_hoa_don and tuong_tac_nguoi_dung
     */
    public function mineRules($db = null): array {
        $db = $db ?? $this->db;
        if (!$db) return [];

        // 1. Group transactions: [ma_hoa_don => [ma_san_pham1, ma_san_pham2, ...]]
        $transactions = [];

        try {
            $orderItems = iterator_to_array($db->chi_tiet_hoa_don->find([], [
                'projection' => ['ma_hoa_don' => 1, 'ma_san_pham' => 1]
            ]));
            foreach ($orderItems as $item) {
                $hid = (int)($item['ma_hoa_don'] ?? 0);
                $pid = (string)($item['ma_san_pham'] ?? '');
                if ($hid > 0 && $pid !== '') {
                    $transactions[$hid][$pid] = true;
                }
            }
        } catch (\Throwable $e) {
            error_log('mineRules order items error: ' . $e->getMessage());
        }

        // Also extract cart co-occurrences from tuong_tac_nguoi_dung (session baskets)
        try {
            $cartInteractions = iterator_to_array($db->tuong_tac_nguoi_dung->find(
                ['loai_tuong_tac' => 'add_to_cart'],
                ['projection' => ['session_id' => 1, 'ma_san_pham' => 1]]
            ));
            foreach ($cartInteractions as $ci) {
                $sid = 'cart_' . (string)($ci['session_id'] ?? '');
                $pid = (string)($ci['ma_san_pham'] ?? '');
                if ($pid !== '') {
                    $transactions[$sid][$pid] = true;
                }
            }
        } catch (\Throwable $e) {
            error_log('mineRules cart interactions error: ' . $e->getMessage());
        }

        $cleanTransactions = [];
        foreach ($transactions as $t) {
            $items = array_keys($t);
            if (count($items) >= 2) {
                $cleanTransactions[] = $items;
            }
        }

        $N = count($cleanTransactions);
        if ($N === 0) {
            // If no multi-item transactions yet, return empty rules structure
            $emptyData = [
                'generated_at' => date('c'),
                'total_transactions' => 0,
                'rules' => [],
                'product_rules' => [],
            ];
            @file_put_contents($this->cachePath, json_encode($emptyData, JSON_UNESCAPED_UNICODE));
            self::$cachedRules = $emptyData;
            return $emptyData;
        }

        // 2. Frequency counting for single items and pairs (Apriori 1-itemsets & 2-itemsets)
        $itemCounts = [];
        $pairCounts = [];

        foreach ($cleanTransactions as $basket) {
            $len = count($basket);
            for ($i = 0; $i < $len; $i++) {
                $itemA = $basket[$i];
                $itemCounts[$itemA] = ($itemCounts[$itemA] ?? 0) + 1;

                for ($j = $i + 1; $j < $len; $j++) {
                    $itemB = $basket[$j];
                    $pairKey = $itemA < $itemB ? "{$itemA}|{$itemB}" : "{$itemB}|{$itemA}";
                    $pairCounts[$pairKey] = ($pairCounts[$pairKey] ?? 0) + 1;
                }
            }
        }

        // 3. Compute Metrics: Support, Confidence, Lift
        $rules = [];
        $productRules = [];

        foreach ($pairCounts as $pairKey => $pairCount) {
            [$itemA, $itemB] = explode('|', $pairKey);
            $supportAB = $pairCount / $N;

            $supportA = ($itemCounts[$itemA] ?? 1) / $N;
            $supportB = ($itemCounts[$itemB] ?? 1) / $N;

            // Rule A => B
            $confAtoB = $supportAB / $supportA;
            $liftAtoB = $supportB > 0 ? ($confAtoB / $supportB) : 1.0;

            // Rule B => A
            $confBtoA = $supportAB / $supportB;
            $liftBtoA = $supportA > 0 ? ($confBtoA / $supportA) : 1.0;

            if ($liftAtoB >= self::MIN_LIFT) {
                $rules[] = [
                    'antecedent' => $itemA,
                    'consequent' => $itemB,
                    'support' => round($supportAB, 4),
                    'confidence' => round($confAtoB, 4),
                    'lift' => round($liftAtoB, 4),
                ];
                $productRules[$itemA][] = [
                    'ma_san_pham' => $itemB,
                    'support' => round($supportAB, 4),
                    'confidence' => round($confAtoB, 4),
                    'lift' => round($liftAtoB, 4),
                    'rule_type' => 'frequent_itemset',
                    'recommendation_source' => 'ASSOCIATION_RULE',
                ];
            }

            if ($liftBtoA >= self::MIN_LIFT) {
                $rules[] = [
                    'antecedent' => $itemB,
                    'consequent' => $itemA,
                    'support' => round($supportAB, 4),
                    'confidence' => round($confBtoA, 4),
                    'lift' => round($liftBtoA, 4),
                ];
                $productRules[$itemB][] = [
                    'ma_san_pham' => $itemA,
                    'support' => round($supportAB, 4),
                    'confidence' => round($confBtoA, 4),
                    'lift' => round($liftBtoA, 4),
                    'rule_type' => 'frequent_itemset',
                    'recommendation_source' => 'ASSOCIATION_RULE',
                ];
            }
        }

        // Sort rules by Lift descending
        foreach ($productRules as $pid => &$rList) {
            uasort($rList, fn($a, $b) => $b['lift'] <=> $a['lift']);
        }

        $resultData = [
            'generated_at' => date('c'),
            'total_transactions' => $N,
            'total_rules' => count($rules),
            'rules' => $rules,
            'product_rules' => $productRules,
        ];

        @file_put_contents($this->cachePath, json_encode($resultData, JSON_UNESCAPED_UNICODE));
        self::$cachedRules = $resultData;
        return $resultData;
    }
}
