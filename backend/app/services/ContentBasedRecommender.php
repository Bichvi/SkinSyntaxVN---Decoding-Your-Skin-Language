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
    // ==============================================================
    // ONE CONFIG LOCATION: PHASE A ADAPTIVE RECOMMENDER CONFIGURATION
    // (Engineering Baseline Heuristics - V1)
    // ==============================================================

    /**
     * V1 Engineering baseline weights for multi-signal behavior fusion.
     * Note: Engineering baseline chosen for V1; future evaluation may tune it.
     */
    public const BEHAVIOR_WEIGHTS_BASELINE = [
        'cart'     => 0.35,
        'view'     => 0.35,
        'search'   => 0.20,
        'purchase' => 0.10,
    ];

    /**
     * Position decay weights for most recent queries in MRU order [q1_latest, q2_prev, q3_oldest].
     */
    public const SEARCH_POSITION_WEIGHTS = [1.0, 0.6, 0.3];

    /**
     * Engineering baseline heuristic half-life for purchase exponential decay w(t) = exp(-lambda * delta_t).
     * lambda = ln(2) / PURCHASE_HALF_LIFE_DAYS.
     * Note: 60 days is an engineering baseline heuristic chosen for V1 (not learned/validated optimal value).
     */
    public const PURCHASE_HALF_LIFE_DAYS = 60;

    /**
     * Frozen hybrid query combination weight: U_query = 0.50 U_behavior + 0.50 V_profile
     */
    public const HYBRID_ALPHA = 0.50;

    /**
     * FinalScore weights for profile & hybrid modes:
     * 70% Content + 20% Skin Compatibility + 10% Budget
     */
    public const SCORING_WEIGHTS_HYBRID = [
        'content' => 0.70,
        'skin'    => 0.20,
        'budget'  => 0.10,
    ];

    /**
     * Scoring weights for pure behavior/session content:
     * 90% Content + 10% Price/Budget match
     */
    public const SCORING_WEIGHTS_BEHAVIOR = [
        'content' => 0.90,
        'price'   => 0.10,
    ];

    /**
     * Order statuses strictly representing valid purchases in SkinSyntaxVN schema.
     */
    public const VALID_PURCHASE_STATUSES = [
        'hoàn thành', 'hoan thanh', 'completed',
        'đang giao', 'dang giao', 'shipping',
        'đã xác nhận', 'da xac nhan', 'confirmed',
    ];

    /**
     * Backward-compatible entrypoint: recommend based purely on recent viewed items
     */
    public function recommend(array $recentViewedIds, int $limit = 4, $db = null): array {
        return $this->recommendHybrid($recentViewedIds, null, $limit, $db);
    }

    /**
     * Adaptive Recommender V1 (Phase A): Context-Routed Recommendation
     *
     * Handles 6 distinct Context Router modes:
     * 1. SIMPLE: Cold-start guest / no signals -> [] (caller uses Simple Recommender)
     * 2. BEHAVIOR_CONTENT: Session signals (search, view, cart) without skin profile
     * 3. PURCHASE_CONTENT: Valid purchase history without session signals or skin profile
     * 4. PROFILE_CONTENT: Customer skin profile without session/behavior signals
     * 5. ADAPTIVE_HYBRID: Combination of behavioral signals and customer skin profile
     * 6. PARTIAL_PROFILE_FALLBACK: Incomplete/empty profile vector fallback
     *
     * @param array $recentViewedIds List of viewed ma_san_pham
     * @param array|null $skinProfile Customer skin profile
     * @param int $limit Number of recommendations
     * @param MongoDB\Database|null $db Optional db connection
     * @param array $behaviorSignals Multi-signal behavior payload ['search' => ..., 'view' => ..., 'cart' => ..., 'purchases' => ...]
     * @return array List of recommendation candidates with explainable metadata
     */
    public function recommendHybrid(
        array $recentViewedIds,
        ?array $skinProfile,
        int $limit = 4,
        $db = null,
        array $behaviorSignals = []
    ): array {
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

        // 1. Gather & validate raw signals
        $rawViews = !empty($behaviorSignals['view']) && is_array($behaviorSignals['view'])
            ? $behaviorSignals['view']
            : $recentViewedIds;

        $validRecent = [];
        foreach ($rawViews as $id) {
            $strId = (string)$id;
            if (isset($vectors[$strId]) && !in_array($strId, $validRecent, true)) {
                $validRecent[] = $strId;
            }
        }
        $validRecent = array_slice($validRecent, 0, 5);

        // Cart items: normalize to [pid => quantity]
        $rawCart = $behaviorSignals['cart'] ?? [];
        $validCart = [];
        if (is_array($rawCart)) {
            foreach ($rawCart as $k => $v) {
                if (is_array($v) && isset($v['ma_san_pham'])) {
                    $pidStr = (string)$v['ma_san_pham'];
                    if (isset($vectors[$pidStr])) {
                        $validCart[$pidStr] = max(1, (int)($v['so_luong'] ?? 1));
                    }
                } elseif (is_numeric($k) && is_string($v) && isset($vectors[$v])) {
                    $validCart[$v] = 1;
                } else {
                    $pidStr = (string)$k;
                    if (isset($vectors[$pidStr])) {
                        $validCart[$pidStr] = max(1, is_numeric($v) ? (int)$v : 1);
                    }
                }
            }
        }

        // Search queries
        $rawSearches = $behaviorSignals['search'] ?? [];
        $validQueries = [];
        if (is_array($rawSearches)) {
            foreach ($rawSearches as $q) {
                $str = trim((string)$q);
                if ($str !== '' && !in_array($str, $validQueries, true)) {
                    $validQueries[] = $str;
                }
            }
        }
        $validQueries = array_slice($validQueries, 0, 3);

        // Purchases: array of ['ma_san_pham' => ..., 'so_luong' => ..., 'ngay_dat' => ...]
        $rawPurchases = $behaviorSignals['purchases'] ?? [];
        $validPurchases = [];
        if (is_array($rawPurchases)) {
            foreach ($rawPurchases as $item) {
                if (is_array($item)) {
                    $pid = (string)($item['ma_san_pham'] ?? $item['id'] ?? '');
                    if ($pid !== '' && isset($vectors[$pid])) {
                        $validPurchases[] = $item;
                    }
                } elseif (is_string($item) || is_numeric($item)) {
                    $pid = (string)$item;
                    if (isset($vectors[$pid])) {
                        $validPurchases[] = ['ma_san_pham' => $pid, 'so_luong' => 1];
                    }
                }
            }
        }

        // 2. Build Behavioral Vectors
        $searchData = $this->buildSearchQueryVector($validQueries, $filteredDocFreq);
        $viewData = $this->buildViewQueryVector($validRecent, $vectors, $prices);
        $cartData = $this->buildCartQueryVector($validCart, $vectors, $prices);
        $purchaseData = $this->buildPurchaseQueryVector($validPurchases, $vectors, $prices);

        $behaviorData = $this->buildBehaviorQueryVector(
            $searchData['vector'],
            $viewData['vector'],
            $cartData['vector'],
            $purchaseData['vector'],
            $viewData['ref_price'],
            $cartData['ref_price'],
            $purchaseData['ref_price']
        );

        $behaviorVec = $behaviorData['vector'];
        $activeBehaviorSignals = $behaviorData['active_signals'];
        $refPrice = $behaviorData['ref_price'];

        // 3. Validate Skin Profile
        $hasProfileMeta = false;
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

            if ($custSkinType !== null || !empty($skinProfile['van_de_da']) || !empty($skinProfile['muc_tieu_cham_soc']) || $custBudget !== null) {
                $hasProfileMeta = true;
            }
        }

        $profileVec = [];
        if ($hasProfileMeta) {
            $profileQueryData = $this->buildProfileQueryVector($skinProfile, $filteredDocFreq);
            $profileVec = $profileQueryData['vector'];
            $profileTermsUsed = $profileQueryData['terms'];
        }
        $hasProfileVector = !empty($profileVec);

        // 4. Context Router: Determine algorithm mode and dominant signal
        $algorithmMode = 'SIMPLE';
        $dominantSignal = 'SIMPLE';
        $allActiveSignals = $activeBehaviorSignals;
        if ($hasProfileVector || $hasProfileMeta) {
            $allActiveSignals[] = 'profile';
        }

        if (empty($activeBehaviorSignals) && !$hasProfileMeta && !$hasProfileVector) {
            $algorithmMode = 'SIMPLE';
            $dominantSignal = 'SIMPLE';
        } elseif ($hasProfileMeta && !$hasProfileVector) {
            $algorithmMode = 'PARTIAL_PROFILE_FALLBACK';
            $dominantSignal = !empty($behaviorData['dominant_signal']) ? $behaviorData['dominant_signal'] : 'PROFILE';
        } elseif ($hasProfileVector && empty($activeBehaviorSignals)) {
            $algorithmMode = 'PROFILE_CONTENT';
            $dominantSignal = 'PROFILE';
        } elseif ($hasProfileVector && !empty($activeBehaviorSignals)) {
            $algorithmMode = 'ADAPTIVE_HYBRID';
            $dominantSignal = 'HYBRID';
        } else {
            // Behavior only
            if (count($activeBehaviorSignals) === 1 && $activeBehaviorSignals[0] === 'purchase') {
                $algorithmMode = 'PURCHASE_CONTENT';
                $dominantSignal = 'PURCHASE';
            } else {
                $algorithmMode = 'BEHAVIOR_CONTENT';
                $dominantSignal = $behaviorData['dominant_signal'];
            }
        }

        // If cold-start SIMPLE mode, return empty list (caller falls back to Simple Recommender Top-4)
        if ($algorithmMode === 'SIMPLE') {
            return [];
        }

        // Backward-compatible source_mode string
        $sourceMode = match ($algorithmMode) {
            'ADAPTIVE_HYBRID' => 'hybrid',
            'PROFILE_CONTENT' => 'profile',
            'PURCHASE_CONTENT', 'BEHAVIOR_CONTENT' => 'content',
            'PARTIAL_PROFILE_FALLBACK' => (!empty($behaviorVec) ? 'content' : 'profile'),
            default => 'simple',
        };

        // 5. Combine Query Vector
        $combinedQuery = [];
        if ($algorithmMode === 'ADAPTIVE_HYBRID') {
            // Frozen baseline: 0.50 Behavior + 0.50 Profile
            foreach ($behaviorVec as $term => $val) {
                $combinedQuery[$term] = ($combinedQuery[$term] ?? 0.0) + (self::HYBRID_ALPHA * $val);
            }
            foreach ($profileVec as $term => $val) {
                $combinedQuery[$term] = ($combinedQuery[$term] ?? 0.0) + (self::HYBRID_ALPHA * $val);
            }
        } elseif ($algorithmMode === 'PROFILE_CONTENT') {
            $combinedQuery = $profileVec;
        } elseif (!empty($behaviorVec)) {
            $combinedQuery = $behaviorVec;
        } elseif (!empty($profileVec)) {
            $combinedQuery = $profileVec;
        }

        $combinedQuery = $this->l2NormalizeVector($combinedQuery);
        $qNorm = $this->computeVectorNorm($combinedQuery);
        if ($qNorm <= 0.0) {
            return [];
        }

        // 6. Score Candidates
        $candidates = [];
        $validRecentLookup = array_flip($validRecent);
        $cartLookup = array_flip($cartData['cart_ids']);

        $bNorm = $this->computeVectorNorm($behaviorVec);
        $pNorm = $this->computeVectorNorm($profileVec);

        foreach ($vectors as $pid => $vec) {
            // Strictly exclude viewed products (preserves existing production behavior)
            if (isset($validRecentLookup[$pid])) {
                continue;
            }

            // Exclude current cart SKUs to avoid recommending what is already in cart
            if (isset($cartLookup[$pid])) {
                continue;
            }

            // Dot product with combined query
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
                    if ($algorithmMode === 'ADAPTIVE_HYBRID' || $algorithmMode === 'PROFILE_CONTENT') {
                        // 70% Content + 20% Skin Compatibility + 10% Budget
                        $finalScore = (self::SCORING_WEIGHTS_HYBRID['content'] * $contentSim)
                                    + (self::SCORING_WEIGHTS_HYBRID['skin'] * $skinScore)
                                    + (self::SCORING_WEIGHTS_HYBRID['budget'] * $budgetScore);
                    } elseif ($algorithmMode === 'PARTIAL_PROFILE_FALLBACK') {
                        if ($custSkinType !== null || $custBudget !== null) {
                            $finalScore = (self::SCORING_WEIGHTS_HYBRID['content'] * $contentSim)
                                        + (self::SCORING_WEIGHTS_HYBRID['skin'] * $skinScore)
                                        + (self::SCORING_WEIGHTS_HYBRID['budget'] * $budgetScore);
                        } else {
                            $finalScore = (self::SCORING_WEIGHTS_BEHAVIOR['content'] * $contentSim)
                                        + (self::SCORING_WEIGHTS_BEHAVIOR['price'] * $priceSim);
                        }
                    } else {
                        // Pure behavior/purchase: 90% Content + 10% PriceSim
                        $finalScore = (self::SCORING_WEIGHTS_BEHAVIOR['content'] * $contentSim)
                                    + (self::SCORING_WEIGHTS_BEHAVIOR['price'] * $priceSim);
                    }

                    // Compute individual behavior and profile contributions for explainability
                    $behaviorContribution = 0.0;
                    if ($bNorm > 0.0) {
                        $bDot = 0.0;
                        foreach ($behaviorVec as $t => $bv) {
                            if (isset($vec[$t])) $bDot += $bv * $vec[$t];
                        }
                        $behaviorContribution = round($bDot / ($bNorm * $docNorm), 4);
                    }

                    $profileContribution = 0.0;
                    if ($pNorm > 0.0) {
                        $pDot = 0.0;
                        foreach ($profileVec as $t => $pv) {
                            if (isset($vec[$t])) $pDot += $pv * $vec[$t];
                        }
                        $profileContribution = round($pDot / ($pNorm * $docNorm), 4);
                    }

                    $candidates[$pid] = [
                        'ma_san_pham' => (string)$pid,
                        'content_score' => round($contentSim, 4),
                        'skin_type_match' => round($skinScore, 2),
                        'budget_score' => round($budgetScore, 4),
                        'final_score' => round($finalScore, 4),
                        'behavior_contribution' => $behaviorContribution,
                        'profile_contribution' => $profileContribution,
                    ];
                }
            }
        }

        // Sort descending by final_score
        uasort($candidates, fn($a, $b) => $b['final_score'] <=> $a['final_score']);

        // 7. Apply Product Family Diversity Filter & Grounded Explainability
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

            // Build grounded explainable reasons matching active scoring signals
            $reasons = [];
            if (in_array('search', $activeBehaviorSignals, true)) {
                $reasons[] = "Dựa trên tìm kiếm gần đây";
            }
            if (in_array('view', $activeBehaviorSignals, true)) {
                $reasons[] = "Tương tự sản phẩm vừa xem";
            }
            if (in_array('cart', $activeBehaviorSignals, true)) {
                $reasons[] = "Phù hợp với giỏ hàng";
            }
            if (in_array('purchase', $activeBehaviorSignals, true)) {
                $reasons[] = "Tương thích lịch sử mua sắm";
            }

            if ($scores['skin_type_match'] >= 1.0) {
                $reasons[] = "Khớp loại da";
            } elseif ($scores['skin_type_match'] >= 0.70 && ($hasProfileVector || $custSkinType !== null)) {
                $reasons[] = "Phù hợp mọi loại da";
            }

            if ($custBudget !== null && $scores['budget_score'] >= 1.0) {
                $reasons[] = "Trong ngân sách";
            }

            if (empty($reasons)) {
                $reasons[] = "Gợi ý cá nhân hóa";
            }

            $reasonTag = implode(' • ', array_unique($reasons));

            $results[] = [
                'ma_san_pham' => (string)$pid,
                'algorithm_mode' => $algorithmMode,
                'source_mode' => $sourceMode,
                'active_signals' => $allActiveSignals,
                'dominant_signal' => $dominantSignal,
                'similarity' => $scores['content_score'],
                'content_score' => $scores['content_score'],
                'skin_score' => $scores['skin_type_match'],
                'skin_type_match' => $scores['skin_type_match'],
                'budget_score' => $scores['budget_score'],
                'final_score' => $scores['final_score'],
                'behavior_contribution' => $scores['behavior_contribution'],
                'profile_contribution' => $scores['profile_contribution'],
                'source_recent_items' => $validRecent,
                'profile_terms_used' => $profileTermsUsed,
                'reason_tags' => $reasons,
                'reason' => $reasonTag,
                'ingredient_warning' => [
                    'has_warning' => false,
                    'avoid_ingredients_requested' => $avoidIngredients,
                    'status' => 'non_medical_limitation'
                ],
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Compute L2 Euclidean norm of a sparse vector
     */
    public function computeVectorNorm(array $vec): float {
        $sumSq = 0.0;
        foreach ($vec as $val) {
            $sumSq += ((float)$val) * ((float)$val);
        }
        return sqrt($sumSq);
    }

    /**
     * L2 normalize sparse vector to unit length
     */
    public function l2NormalizeVector(array $vec): array {
        $norm = $this->computeVectorNorm($vec);
        if ($norm <= 0.0) {
            return [];
        }
        $normalized = [];
        foreach ($vec as $k => $v) {
            $normalized[$k] = round($v / $norm, 6);
        }
        return $normalized;
    }

    /**
     * Build search query vector with MRU position decay
     * V_search = Normalize(w1*TFIDF(query_latest) + w2*TFIDF(query_previous) + w3*TFIDF(query_oldest))
     */
    public function buildSearchQueryVector(array $queries, array $filteredDocFreq): array {
        $cleanQueries = [];
        foreach ($queries as $q) {
            $str = trim((string)$q);
            if ($str !== '' && !in_array($str, $cleanQueries, true)) {
                $cleanQueries[] = $str;
            }
        }
        $cleanQueries = array_slice($cleanQueries, 0, 3);
        if (empty($cleanQueries)) {
            return ['vector' => [], 'terms' => []];
        }

        $posWeights = self::SEARCH_POSITION_WEIGHTS;
        $combined = [];
        $termsUsed = [];

        foreach ($cleanQueries as $idx => $queryStr) {
            $w = $posWeights[$idx] ?? 0.1;
            $tokens = $this->tokenize($queryStr);
            $counts = array_count_values($tokens);
            $totalTokens = array_sum($counts);
            if ($totalTokens <= 0) continue;

            foreach ($counts as $t => $cnt) {
                if (isset($filteredDocFreq[$t])) {
                    $tf = $cnt / $totalTokens;
                    $idf = (float)$filteredDocFreq[$t];
                    $val = $w * ($tf * $idf);
                    $combined[$t] = ($combined[$t] ?? 0.0) + $val;
                    $termsUsed[] = $t;
                }
            }
        }

        $normVec = $this->l2NormalizeVector($combined);
        return [
            'vector' => $normVec,
            'terms' => array_values(array_unique($termsUsed)),
            'queries_used' => $cleanQueries,
        ];
    }

    /**
     * Build view query vector from recent viewed product IDs (MRU order)
     */
    public function buildViewQueryVector(array $validRecentIds, array $vectors, array $prices): array {
        if (empty($validRecentIds)) {
            return ['vector' => [], 'ref_price' => 0.0];
        }

        $weights = $this->getRecencyWeights(count($validRecentIds));
        $combined = [];
        $weightedPriceSum = 0.0;
        $weightSum = 0.0;

        foreach ($validRecentIds as $idx => $pid) {
            $pidStr = (string)$pid;
            if (!isset($vectors[$pidStr])) continue;
            $w = $weights[$idx] ?? 0.1;
            foreach ($vectors[$pidStr] as $term => $val) {
                $combined[$term] = ($combined[$term] ?? 0.0) + ($w * $val);
            }
            if (isset($prices[$pidStr]) && $prices[$pidStr] > 0) {
                $weightedPriceSum += $w * (float)$prices[$pidStr];
                $weightSum += $w;
            }
        }

        $normVec = $this->l2NormalizeVector($combined);
        $refPrice = $weightSum > 0 ? ($weightedPriceSum / $weightSum) : 0.0;
        return [
            'vector' => $normVec,
            'ref_price' => $refPrice,
        ];
    }

    /**
     * Build cart query vector from cart items
     */
    public function buildCartQueryVector(array $cartItems, array $vectors, array $prices): array {
        if (empty($cartItems)) {
            return ['vector' => [], 'ref_price' => 0.0, 'cart_ids' => []];
        }

        $combined = [];
        $weightedPriceSum = 0.0;
        $totalQty = 0;
        $validCartIds = [];

        foreach ($cartItems as $pid => $qty) {
            $pidStr = (string)$pid;
            if (!isset($vectors[$pidStr])) continue;
            $validCartIds[] = $pidStr;
            $q = max(1, min(10, (int)$qty));
            foreach ($vectors[$pidStr] as $term => $val) {
                $combined[$term] = ($combined[$term] ?? 0.0) + ($q * $val);
            }
            if (isset($prices[$pidStr]) && $prices[$pidStr] > 0) {
                $weightedPriceSum += $q * (float)$prices[$pidStr];
                $totalQty += $q;
            }
        }

        $normVec = $this->l2NormalizeVector($combined);
        $refPrice = $totalQty > 0 ? ($weightedPriceSum / $totalQty) : 0.0;
        return [
            'vector' => $normVec,
            'ref_price' => $refPrice,
            'cart_ids' => $validCartIds,
        ];
    }

    /**
     * Build purchase query vector with exponential time decay w(t) = exp(-lambda * delta_t)
     * lambda = ln(2) / PURCHASE_HALF_LIFE_DAYS (60 days baseline heuristic)
     * Fallback weight = 1.0 if timestamp is invalid or missing (no fake timestamps).
     */
    public function buildPurchaseQueryVector(array $purchases, array $vectors, array $prices): array {
        if (empty($purchases)) {
            return ['vector' => [], 'ref_price' => 0.0];
        }

        $combined = [];
        $weightedPriceSum = 0.0;
        $weightSum = 0.0;
        $halfLife = self::PURCHASE_HALF_LIFE_DAYS;
        $lambda = log(2) / $halfLife;
        $now = time();

        foreach ($purchases as $item) {
            $pid = (string)($item['ma_san_pham'] ?? $item['id'] ?? '');
            if ($pid === '' || !isset($vectors[$pid])) continue;

            $qty = max(1, (int)($item['so_luong'] ?? 1));

            // Time decay calculation
            $decayWeight = 1.0;
            $dateRaw = $item['ngay_dat'] ?? null;
            $ts = null;

            if ($dateRaw instanceof \MongoDB\BSON\UTCDateTime) {
                $ts = (int)($dateRaw->toDateTime()->getTimestamp());
            } elseif (is_numeric($dateRaw) && $dateRaw > 0) {
                $ts = (int)$dateRaw;
            } elseif (is_string($dateRaw) && trim($dateRaw) !== '') {
                $parsed = strtotime($dateRaw);
                if ($parsed !== false && $parsed > 0) {
                    $ts = $parsed;
                }
            }

            if ($ts !== null && $ts <= $now) {
                $deltaDays = max(0.0, ($now - $ts) / 86400.0);
                $decayWeight = exp(-$lambda * $deltaDays);
            } else {
                // Fallback weight = 1.0 if timestamp invalid/missing
                $decayWeight = 1.0;
            }

            $effectiveWeight = $decayWeight * $qty;
            foreach ($vectors[$pid] as $term => $val) {
                $combined[$term] = ($combined[$term] ?? 0.0) + ($effectiveWeight * $val);
            }

            if (isset($prices[$pid]) && $prices[$pid] > 0) {
                $weightedPriceSum += $effectiveWeight * (float)$prices[$pid];
                $weightSum += $effectiveWeight;
            }
        }

        $normVec = $this->l2NormalizeVector($combined);
        $refPrice = $weightSum > 0 ? ($weightedPriceSum / $weightSum) : 0.0;
        return [
            'vector' => $normVec,
            'ref_price' => $refPrice,
        ];
    }

    /**
     * Multi-signal behavior fusion with self-normalization over active signals
     */
    public function buildBehaviorQueryVector(
        array $searchVec,
        array $viewVec,
        array $cartVec,
        array $purchaseVec,
        float $refPriceView,
        float $refPriceCart,
        float $refPricePurchase
    ): array {
        $activeSignals = [];
        if (!empty($cartVec))     $activeSignals['cart'] = $cartVec;
        if (!empty($viewVec))     $activeSignals['view'] = $viewVec;
        if (!empty($searchVec))   $activeSignals['search'] = $searchVec;
        if (!empty($purchaseVec)) $activeSignals['purchase'] = $purchaseVec;

        if (empty($activeSignals)) {
            return ['vector' => [], 'active_signals' => [], 'dominant_signal' => '', 'ref_price' => 0.0];
        }

        $baseline = self::BEHAVIOR_WEIGHTS_BASELINE;
        $activeWeightSum = 0.0;
        foreach (array_keys($activeSignals) as $sigKey) {
            $activeWeightSum += ($baseline[$sigKey] ?? 0.1);
        }

        $combined = [];
        $dominantSignal = '';
        $maxNormalizedWeight = -1.0;

        foreach ($activeSignals as $sigKey => $sigVec) {
            $normalizedWeight = ($baseline[$sigKey] ?? 0.1) / $activeWeightSum;
            if ($normalizedWeight > $maxNormalizedWeight) {
                $maxNormalizedWeight = $normalizedWeight;
                $dominantSignal = strtoupper($sigKey);
            }
            foreach ($sigVec as $term => $val) {
                $combined[$term] = ($combined[$term] ?? 0.0) + ($normalizedWeight * $val);
            }
        }

        // Determine reference price based on dominant signal
        $refPrice = 0.0;
        if (isset($activeSignals['cart']) && $refPriceCart > 0) {
            $refPrice = $refPriceCart;
        } elseif (isset($activeSignals['view']) && $refPriceView > 0) {
            $refPrice = $refPriceView;
        } elseif (isset($activeSignals['purchase']) && $refPricePurchase > 0) {
            $refPrice = $refPricePurchase;
        }

        $normVec = $this->l2NormalizeVector($combined);
        return [
            'vector' => $normVec,
            'active_signals' => array_keys($activeSignals),
            'dominant_signal' => $dominantSignal,
            'ref_price' => $refPrice,
        ];
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
