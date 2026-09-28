<?php
/**
 * ContentBasedRecommender.php
 * Refined Content-Based and Hybrid Recommender (Hybrid V1)
 * Combining Session Realtime Behavior with Customer Skin Profile,
 * Skin-Type Compatibility, Budget Reranking, and Product Family Diversity.
 *
 * For SkinSyntaxVN facial skincare catalog.
 */
class ContentBasedRecommender {
    private static ?array $cachedIndex = null;
    private string $cachePath;

    public function __construct(?string $cachePath = null) {
        $this->cachePath = $cachePath ?? dirname(__DIR__) . '/content/tfidf_cache.json';
    }

    /**
     * Backward-compatible entrypoint: recommend based purely on recent viewed items
     */
    public function recommend(array $recentViewedIds, int $limit = 4, $db = null): array {
        return $this->recommendHybrid($recentViewedIds, null, $limit, $db);
    }

    /**
     * Hybrid Recommender V1: Context-Routed recommendation
     * Handles 4 distinct states:
     * 1. No profile + No recent items -> [] (caller uses Simple Recommender)
     * 2. No profile + Recent items -> Pure Content-Based
     * 3. Profile + No recent items -> Profile-Aware Content-Based
     * 4. Profile + Recent items -> Hybrid (Session + Profile)
     *
     * @param array $recentViewedIds List of viewed ma_san_pham
     * @param array|null $skinProfile Customer skin profile
     * @param int $limit Number of recommendations
     * @param MongoDB\Database|null $db Optional db connection
     * @return array List of recommendation candidates with explainable metadata
     */
    public function recommendHybrid(array $recentViewedIds, ?array $skinProfile, int $limit = 4, $db = null): array {
        $index = $this->loadIndex($db);
        if (!$index || empty($index['vectors'])) {
            return [];
        }

        $vectors = $index['vectors'];
        $norms = $index['norms'] ?? [];
        $prices = $index['prices'] ?? [];
        $names = $index['names'] ?? [];
        $skinTypes = $index['skin_types'] ?? [];
        $filteredDocFreq = $index['filtered_doc_freq'] ?? [];

        // 1. Validate recent viewed items
        $validRecent = [];
        foreach ($recentViewedIds as $id) {
            $strId = (string)$id;
            if (isset($vectors[$strId]) && !in_array($strId, $validRecent, true)) {
                $validRecent[] = $strId;
            }
        }
        $validRecent = array_slice($validRecent, 0, 5);

        // 2. Validate skin profile
        $hasProfile = false;
        $profileTermsUsed = [];
        $custSkinType = null;
        $custBudget = null;
        $avoidIngredients = [];

        if (is_array($skinProfile)) {
            $custSkinType = !empty($skinProfile['skin_type']) ? trim((string)$skinProfile['skin_type']) : null;
            $custBudget = isset($skinProfile['ngan_sach']) && is_numeric($skinProfile['ngan_sach']) && $skinProfile['ngan_sach'] > 0
                ? (int)$skinProfile['ngan_sach']
                : (isset($skinProfile['budget']) && is_numeric($skinProfile['budget']) && $skinProfile['budget'] > 0 ? (int)$skinProfile['budget'] : null);
            $avoidIngredients = $skinProfile['avoid_ingredients'] ?? [];
            if (!is_array($avoidIngredients) && !empty($skinProfile['thanh_phan_tranh'])) {
                $avoidIngredients = array_map('trim', explode(',', (string)$skinProfile['thanh_phan_tranh']));
            }

            // Check if profile contains at least one meaningful preference
            if ($custSkinType !== null || !empty($skinProfile['van_de_da']) || !empty($skinProfile['muc_tieu_cham_soc'])) {
                $hasProfile = true;
            }
        }

        // 3. Context Router
        $hasSession = !empty($validRecent);
        if (!$hasSession && !$hasProfile) {
            return []; // Fallback to Simple Recommender
        }

        $sourceMode = 'simple';
        if ($hasSession && !$hasProfile) {
            $sourceMode = 'content';
        } elseif (!$hasSession && $hasProfile) {
            $sourceMode = 'profile';
        } else {
            $sourceMode = 'hybrid';
        }

        // 4. Build Session Query Vector
        $sessionVec = [];
        $refPrice = 0.0;
        if ($hasSession) {
            $weights = $this->getRecencyWeights(count($validRecent));
            $weightedPriceSum = 0.0;
            $weightSum = 0.0;
            foreach ($validRecent as $idx => $pid) {
                $w = $weights[$idx] ?? 0.1;
                foreach ($vectors[$pid] as $term => $val) {
                    $sessionVec[$term] = ($sessionVec[$term] ?? 0.0) + ($w * $val);
                }
                if (isset($prices[$pid]) && $prices[$pid] > 0) {
                    $weightedPriceSum += $w * (float)$prices[$pid];
                    $weightSum += $w;
                }
            }
            $refPrice = $weightSum > 0 ? ($weightedPriceSum / $weightSum) : 0.0;
        }

        // 5. Build Profile Query Vector
        $profileVec = [];
        if ($hasProfile) {
            $profileQueryData = $this->buildProfileQueryVector($skinProfile, $filteredDocFreq);
            $profileVec = $profileQueryData['vector'];
            $profileTermsUsed = $profileQueryData['terms'];
        }

        // 6. Combine Query Vector
        $combinedQuery = [];
        if ($sourceMode === 'hybrid') {
            // Selected baseline: 0.50 Session + 0.50 Profile
            foreach ($sessionVec as $term => $val) {
                $combinedQuery[$term] = ($combinedQuery[$term] ?? 0.0) + (0.50 * $val);
            }
            foreach ($profileVec as $term => $val) {
                $combinedQuery[$term] = ($combinedQuery[$term] ?? 0.0) + (0.50 * $val);
            }
        } elseif ($sourceMode === 'content') {
            $combinedQuery = $sessionVec;
        } elseif ($sourceMode === 'profile') {
            $combinedQuery = $profileVec;
        }

        $qNormSq = 0.0;
        foreach ($combinedQuery as $val) {
            $qNormSq += $val * $val;
        }
        $qNorm = sqrt($qNormSq);
        if ($qNorm <= 0.0) {
            return [];
        }

        // 7. Score Candidates
        $candidates = [];
        $validRecentLookup = array_flip($validRecent);

        foreach ($vectors as $pid => $vec) {
            // Strictly exclude viewed products
            if (isset($validRecentLookup[$pid])) {
                continue;
            }

            // Dot product
            $dot = 0.0;
            if (count($combinedQuery) < count($vec)) {
                foreach ($combinedQuery as $t => $qVal) {
                    if (isset($vec[$t])) {
                        $dot += $qVal * $vec[$t];
                    }
                }
            } else {
                foreach ($vec as $t => $vVal) {
                    if (isset($combinedQuery[$t])) {
                        $dot += $vVal * $combinedQuery[$t];
                    }
                }
            }

            if ($dot > 0.0) {
                $docNorm = (float)($norms[$pid] ?? 1.0);
                if ($docNorm > 0.0) {
                    $contentSim = $dot / ($qNorm * $docNorm);
                    $candPrice = (float)($prices[$pid] ?? 0);
                    $candSkinType = (string)($skinTypes[$pid] ?? 'Unknown');

                    $skinScore = $this->computeSkinTypeScore($candSkinType, $custSkinType);
                    $budgetScore = $this->computeBudgetScore($candPrice, $custBudget);
                    $priceSim = $refPrice > 0 ? $this->getPriceScore($candPrice, $refPrice) : 1.0;

                    // Scoring formula based on mode:
                    if ($sourceMode === 'content') {
                        $finalScore = (0.90 * $contentSim) + (0.10 * $priceSim);
                    } else {
                        // Profile & Hybrid: 70% Content + 20% Skin Compatibility + 10% Budget
                        $finalScore = (0.70 * $contentSim) + (0.20 * $skinScore) + (0.10 * $budgetScore);
                    }

                    $candidates[$pid] = [
                        'ma_san_pham' => (string)$pid,
                        'content_score' => round($contentSim, 4),
                        'skin_type_match' => round($skinScore, 2),
                        'budget_score' => round($budgetScore, 4),
                        'final_score' => round($finalScore, 4),
                    ];
                }
            }
        }

        // Sort descending by final_score
        uasort($candidates, fn($a, $b) => $b['final_score'] <=> $a['final_score']);

        // 8. Apply Product Family Diversity Filter & Explainability
        $results = [];
        $seenFamilies = [];

        foreach ($candidates as $pid => $scores) {
            $pName = (string)($names[$pid] ?? '');
            if ($pName !== '') {
                $family = $this->extractProductFamily($pName);
                if ($family !== '') {
                    if (isset($seenFamilies[$family])) {
                        continue;
                    }
                    $seenFamilies[$family] = true;
                }
            }

            // Build grounded explainable reasons
            $reasons = [];
            if ($scores['skin_type_match'] >= 1.0) {
                $reasons[] = "Đúng loại da của bạn";
            } elseif ($scores['skin_type_match'] >= 0.70) {
                $reasons[] = "Phù hợp mọi loại da";
            }

            if ($custBudget !== null && $scores['budget_score'] >= 1.0) {
                $reasons[] = "Trong ngân sách";
            }

            if ($hasSession) {
                $reasons[] = "Tương tự sản phẩm bạn vừa xem";
            }

            $reasonTag = !empty($reasons) ? implode(' • ', $reasons) : "Gợi ý cá nhân hóa";

            $results[] = [
                'ma_san_pham' => (string)$pid,
                'source_mode' => $sourceMode,
                'similarity' => $scores['content_score'],
                'content_score' => $scores['content_score'],
                'skin_type_match' => $scores['skin_type_match'],
                'budget_score' => $scores['budget_score'],
                'final_score' => $scores['final_score'],
                'source_recent_items' => $validRecent,
                'profile_terms_used' => $profileTermsUsed,
                'ingredient_warning' => [
                    'has_warning' => false,
                    'avoid_ingredients_requested' => $avoidIngredients,
                    'status' => 'non_medical_limitation'
                ],
                'reason' => $reasonTag,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Build TF-IDF query vector from skin profile text
     */
    public function buildProfileQueryVector(array $skinProfile, array $filteredDocFreq): array {
        $parts = [];
        if (!empty($skinProfile['skin_type'])) {
            $parts[] = (string)$skinProfile['skin_type'];
        }
        if (!empty($skinProfile['van_de_da'])) {
            $parts[] = is_array($skinProfile['van_de_da'])
                ? implode(' ', $skinProfile['van_de_da'])
                : (string)$skinProfile['van_de_da'];
        }
        if (!empty($skinProfile['muc_tieu_cham_soc'])) {
            $parts[] = (string)$skinProfile['muc_tieu_cham_soc'];
        }

        $rawText = implode(' ', $parts);
        $tokens = $this->tokenize($rawText);
        $counts = array_count_values($tokens);
        $total = array_sum($counts);
        if ($total <= 0) {
            return ['vector' => [], 'terms' => []];
        }

        $vec = [];
        $termsUsed = [];
        foreach ($counts as $t => $count) {
            if (isset($filteredDocFreq[$t])) {
                $tf = $count / $total;
                $idf = (float)$filteredDocFreq[$t];
                $val = round($tf * $idf, 4);
                $vec[$t] = $val;
                $termsUsed[] = $t;
            }
        }

        return ['vector' => $vec, 'terms' => array_values(array_unique($termsUsed))];
    }

    /**
     * Compute skin type compatibility score
     * Exact match: 1.0
     * Universal ('Da thường/Mọi loại da'): 0.70
     * Unknown product: 0.30
     * Incompatible: 0.0
     */
    public function computeSkinTypeScore(string $prodLoaiDa, ?string $custSkinType): float {
        if (empty($custSkinType)) {
            return 0.50; // Neutral if user did not provide skin type
        }

        $p = mb_strtolower(trim($prodLoaiDa), 'UTF-8');
        $c = mb_strtolower(trim($custSkinType), 'UTF-8');

        if ($p === $c) {
            return 1.0;
        }

        if ($p === 'da thường/mọi loại da') {
            return 0.70;
        }

        if ($p === 'unknown') {
            return 0.30;
        }

        return 0.0;
    }

    /**
     * Compute soft budget score in [0.10, 1.0]
     */
    public function computeBudgetScore(float $candPrice, ?int $budget): float {
        if (!$budget || $budget <= 0) {
            return 1.0;
        }
        if ($candPrice <= $budget) {
            return 1.0;
        }
        $over = $candPrice - $budget;
        return max(0.10, 1.0 - ($over / $budget));
    }

    /**
     * Compute price similarity score between candidate and reference price
     */
    public function getPriceScore(float $candPrice, float $refPrice): float {
        if ($candPrice <= 0 || $refPrice <= 0) {
            return 1.0;
        }
        $maxP = max($candPrice, $refPrice);
        return max(0.0, 1.0 - (abs($candPrice - $refPrice) / $maxP));
    }

    /**
     * Normalize product title to identify product family (strips volume, pack, promo markers)
     */
    public function extractProductFamily(string $name): string {
        $clean = mb_strtolower($name, 'UTF-8');
        $clean = preg_replace('/^(\[.*?\]|\(.*?\))\s*/u', '', $clean);
        $clean = preg_replace('/\b\d+(\.\d+)?\s*(ml|g|kg|l|oz|miếng|gói|viên|set|combo)\b/ui', '', $clean);
        $clean = preg_replace('/\b(combo\s*\d*|x\d+)\b/ui', '', $clean);
        $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $clean);
        return trim(preg_replace('/\s+/u', ' ', $clean));
    }

    /**
     * Recency weights prioritizing the most recently viewed products
     */
    private function getRecencyWeights(int $count): array {
        switch ($count) {
            case 1:
                return [1.0];
            case 2:
                return [0.65, 0.35];
            case 3:
                return [0.55, 0.30, 0.15];
            case 4:
                return [0.50, 0.25, 0.15, 0.10];
            case 5:
            default:
                return [0.50, 0.25, 0.15, 0.07, 0.03];
        }
    }

    /**
     * Load pre-computed TF-IDF index from JSON cache or build if missing
     */
    private function loadIndex($db = null): ?array {
        if (self::$cachedIndex !== null) {
            return self::$cachedIndex;
        }

        if (file_exists($this->cachePath)) {
            $content = file_get_contents($this->cachePath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data) && !empty($data['vectors'])) {
                    self::$cachedIndex = $data;
                    return self::$cachedIndex;
                }
            }
        }

        // Rebuild cache if file missing or invalid
        if ($db !== null) {
            $this->buildIndex($db);
            return self::$cachedIndex;
        }

        return null;
    }

    /**
     * Build TF-IDF feature index from MongoDB collection san_pham using Config B weights
     */
    public function buildIndex($db): array {
        $brandMap = [];
        $brandCursor = $db->thuong_hieu->find([]);
        foreach ($brandCursor as $b) {
            $brandMap[(string)$b['ma_thuong_hieu']] = (string)$b['ten_thuong_hieu'];
        }

        $catMap = [];
        $catCursor = $db->danh_muc->find([]);
        foreach ($catCursor as $c) {
            $catMap[(string)$c['ma_danh_muc']] = (string)$c['ten_danh_muc'];
        }

        $cursor = $db->san_pham->find(
            ['trang_thai' => 'active', 'gia_ban' => ['$gt' => 0]],
            ['projection' => [
                'ma_san_pham' => 1,
                'ten_san_pham' => 1,
                'danh_muc_day_du' => 1,
                'ma_danh_muc' => 1,
                'thuong_hieu' => 1,
                'ma_thuong_hieu' => 1,
                'loai_da' => 1,
                'thanh_phan' => 1,
                'thanh_phan_chinh' => 1,
                'mo_ta_ngan' => 1,
                'mo_ta' => 1,
                'gia_ban' => 1
            ]]
        );

        $docTokens = [];
        $docFreq = [];
        $prices = [];
        $names = [];
        $skinTypes = [];
        $products = iterator_to_array($cursor);
        $N = count($products);

        foreach ($products as $p) {
            $pid = (string)$p['ma_san_pham'];
            $name = (string)($p['ten_san_pham'] ?? '');
            $cat = (string)($p['danh_muc_day_du'] ?? ($catMap[(string)($p['ma_danh_muc'] ?? '')] ?? ''));
            $brand = (string)($p['thuong_hieu'] ?? ($brandMap[(string)($p['ma_thuong_hieu'] ?? '')] ?? ''));
            $skin = (string)($p['loai_da'] ?? 'Unknown');
            $ing = (string)($p['thanh_phan'] ?? $p['thanh_phan_chinh'] ?? '');
            $desc = (string)($p['mo_ta_ngan'] ?? $p['mo_ta'] ?? '');
            $descSnippet = mb_substr(strip_tags($desc), 0, 300, 'UTF-8');

            $prices[$pid] = (float)($p['gia_ban'] ?? 0);
            $names[$pid] = $name;
            $skinTypes[$pid] = $skin;

            // Config B Feature Weights:
            // Name 2x, Brand 1x, Category 3x, Skin Type 3x, Ingredients 2x, Description 1x
            $text = str_repeat($name . " ", 2) .
                    str_repeat($brand . " ", 1) .
                    str_repeat($cat . " ", 3) .
                    str_repeat($skin . " ", 3) .
                    str_repeat($ing . " ", 2) .
                    $descSnippet;

            $tokens = $this->tokenize($text);
            $termCounts = array_count_values($tokens);
            $docTokens[$pid] = $termCounts;

            foreach (array_keys($termCounts) as $t) {
                $docFreq[$t] = ($docFreq[$t] ?? 0) + 1;
            }
        }

        // Smooth IDF for terms with document frequency >= 3 and <= 80% of corpus
        $filteredDocFreq = [];
        foreach ($docFreq as $t => $df) {
            if ($df >= 3 && $df <= $N * 0.80) {
                $filteredDocFreq[$t] = log((1 + $N) / (1 + $df)) + 1;
            }
        }

        $tfidfVectors = [];
        $vectorNorms = [];

        foreach ($docTokens as $pid => $termCounts) {
            $totalTerms = array_sum($termCounts);
            $scoredTerms = [];

            foreach ($termCounts as $t => $count) {
                if (isset($filteredDocFreq[$t])) {
                    $tf = $count / $totalTerms;
                    $idf = $filteredDocFreq[$t];
                    $scoredTerms[$t] = $tf * $idf;
                }
            }

            // Keep top 30 most informative terms per product
            arsort($scoredTerms);
            $topTerms = array_slice($scoredTerms, 0, 30, true);

            $vec = [];
            $normSq = 0.0;
            foreach ($topTerms as $t => $val) {
                $rval = round($val, 4);
                $vec[$t] = $rval;
                $normSq += $rval * $rval;
            }
            $norm = sqrt($normSq);
            $tfidfVectors[$pid] = $vec;
            $vectorNorms[$pid] = round($norm > 0 ? $norm : 1.0, 4);
        }

        $cacheData = [
            'generated_at' => date('c'),
            'config' => 'Hybrid V1 (Config B + SkinTypes + DocFreq)',
            'total_products' => $N,
            'vocabulary_size' => count($filteredDocFreq),
            'vectors' => $tfidfVectors,
            'norms' => $vectorNorms,
            'prices' => $prices,
            'names' => $names,
            'skin_types' => $skinTypes,
            'filtered_doc_freq' => $filteredDocFreq
        ];

        @file_put_contents($this->cachePath, json_encode($cacheData, JSON_UNESCAPED_UNICODE));
        self::$cachedIndex = $cacheData;
        return $cacheData;
    }

    /**
     * Clean and tokenize Vietnamese text, preserving diacritics
     */
    private function tokenize(string $text): array {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        $cleanTokens = [];
        foreach ($tokens as $t) {
            if (mb_strlen($t, 'UTF-8') >= 2 && !is_numeric($t)) {
                $cleanTokens[] = $t;
            }
        }
        return $cleanTokens;
    }
}
