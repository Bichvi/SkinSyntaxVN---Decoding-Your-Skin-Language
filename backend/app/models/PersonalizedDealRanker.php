<?php
/**
 * PersonalizedDealRanker.php
 *
 * Phase 2D.2: Personalized Deal Ranking Adapter & Engine.
 *
 * Scors ONLY eligible campaign candidates for a given user context.
 * Reuses existing production semantics from ContentBasedRecommender without
 * duplicating recommender logic or scanning the full 2,473-product catalog.
 *
 * ARCHITECTURAL INVARIANTS:
 * 1. Campaign controls WHAT products are eligible (0 non-campaign product leakage).
 * 2. Recommender decides ONLY the ordering.
 * 3. Price is NEVER personalized (Same campaign + same SKU = identical prices for all users).
 * 4. Weights are selected engineering heuristics, not mathematically learned optima.
 * 5. Simple Recommender constants m=21.0, C=4.8890 are strictly preserved.
 */

require_once __DIR__ . '/../services/ContentBasedRecommender.php';
require_once __DIR__ . '/SanPham.php';

class PersonalizedDealRanker {
    // Selected engineering heuristic weights for composite ranking
    public const WEIGHT_PERSONAL = 0.50;
    public const WEIGHT_DISCOUNT = 0.30;
    public const WEIGHT_RATING   = 0.20;

    // Guest fallback weights (no personal relevance available)
    public const GUEST_WEIGHT_DISCOUNT = 0.60;
    public const GUEST_WEIGHT_RATING   = 0.40;

    // Profile-only internal heuristic weights
    public const PROFILE_WEIGHT_SKIN   = 0.60;
    public const PROFILE_WEIGHT_TFIDF  = 0.25;
    public const PROFILE_WEIGHT_BUDGET = 0.15;

    // Hybrid internal heuristic weights
    public const HYBRID_WEIGHT_BEHAVIOR = 0.55;
    public const HYBRID_WEIGHT_PROFILE  = 0.45;

    // Canonical Simple Recommender constants
    public const CANONICAL_M = 21.0;
    public const CANONICAL_C = 4.8890;

    private $pdo;
    private $recommender;
    private ?array $index = null;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->recommender = new ContentBasedRecommender();
        $this->loadIndex();
    }

    private function loadIndex(): void {
        $cachePath = dirname(__DIR__) . '/content/tfidf_cache.json';
        if (file_exists($cachePath)) {
            $content = file_get_contents($cachePath);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data) && !empty($data['vectors'])) {
                    $this->index = $data;
                }
            }
        }
    }

    /**
     * Resolve user state deterministically from available context.
     *
     * State A: Guest / Cold Start
     * State B: Behavior Only (search, view, cart without profile survey)
     * State C: Profile Only (survey completed, no session interactions)
     * State D: Profile + Behavior (Hybrid)
     * State E: Purchase Signal Present (User with prior valid orders)
     */
    public function resolveUserState(?array $userContext): string {
        if (empty($userContext)) {
            return 'STATE_A';
        }

        $hasProfile = !empty($userContext['profile']) && !empty($userContext['profile']['skin_type']);
        $behavior = $userContext['behavior'] ?? [];
        $hasSearch = !empty($behavior['search']);
        $hasView = !empty($behavior['view']);
        $hasCart = !empty($behavior['cart']);
        $hasPurchases = !empty($behavior['purchases']);
        $hasBehavior = ($hasSearch || $hasView || $hasCart);

        if ($hasProfile && ($hasBehavior || $hasPurchases)) {
            return $hasPurchases ? 'STATE_E' : 'STATE_D';
        }

        if ($hasProfile && !$hasBehavior && !$hasPurchases) {
            return 'STATE_C';
        }

        if (!$hasProfile && ($hasBehavior || $hasPurchases)) {
            return 'STATE_B';
        }

        return 'STATE_A';
    }

    /**
     * Score and rank ONLY the candidate campaign items.
     *
     * Returns:
     * [
     *   'ranked_items' => [...],
     *   'ranking_mode' => 'BASELINE' | 'BEHAVIOR' | 'PROFILE' | 'HYBRID',
     *   'user_state'   => 'STATE_A' .. 'STATE_E',
     *   'is_personalized' => bool
     * ]
     */
    public function rankCampaignCandidates(array $candidateItems, ?array $userContext): array {
        if (empty($candidateItems)) {
            return [
                'ranked_items' => [],
                'ranking_mode' => 'BASELINE',
                'user_state' => 'STATE_A',
                'is_personalized' => false,
            ];
        }

        $userState = $this->resolveUserState($userContext);

        // Precompute raw signals for each candidate
        $scoredItems = [];
        $rawPersonalList = [];
        $rawDiscountList = [];
        $rawWrList = [];

        // Build query vectors once if needed
        $behaviorQueryVector = null;
        $searchVector = null;
        $viewVector = null;
        $profileQueryVector = null;
        $skinType = null;
        $userBudget = null;

        $vectors = $this->index['vectors'] ?? [];
        $prices = $this->index['prices'] ?? [];
        $filteredDocFreq = $this->index['filtered_doc_freq'] ?? [];

        if (in_array($userState, ['STATE_B', 'STATE_D', 'STATE_E'], true)) {
            $rawSearches = $userContext['behavior']['search'] ?? [];
            $rawViews = $userContext['behavior']['view'] ?? [];
            $rawCart = $userContext['behavior']['cart'] ?? [];

            $searchData = $this->recommender->buildSearchQueryVector((array)$rawSearches, $filteredDocFreq);
            $searchVector = $searchData['vector'] ?? [];

            $viewData = $this->recommender->buildViewQueryVector((array)$rawViews, $vectors, $prices);
            $viewVector = $viewData['vector'] ?? [];

            $cartData = $this->recommender->buildCartQueryVector((array)$rawCart, $vectors, $prices);
            $cartVector = $cartData['vector'] ?? [];

            $purchaseData = $this->recommender->buildPurchaseQueryVector([], $vectors, $prices);
            $purchaseVector = $purchaseData['vector'] ?? [];

            $behaviorData = $this->recommender->buildBehaviorQueryVector(
                $searchVector,
                $viewVector,
                $cartVector,
                $purchaseVector,
                (float)($viewData['ref_price'] ?? 0.0),
                (float)($cartData['ref_price'] ?? 0.0),
                (float)($purchaseData['ref_price'] ?? 0.0)
            );
            $behaviorQueryVector = $behaviorData['vector'] ?? [];
        }

        if (in_array($userState, ['STATE_C', 'STATE_D', 'STATE_E'], true)) {
            $profile = $userContext['profile'] ?? [];
            $profileData = $this->recommender->buildProfileQueryVector($profile, $filteredDocFreq);
            $profileQueryVector = $profileData['vector'] ?? [];
            $skinType = $profile['skin_type'] ?? null;
            $userBudget = isset($profile['budget']) && is_numeric($profile['budget']) ? (int)$profile['budget'] : null;
        }

        foreach ($candidateItems as $item) {
            $sp = $item['raw_product'] ?? $item;
            $pidStr = (string)($item['ma_san_pham'] ?? $sp['ma_san_pham'] ?? '');
            $docVector = is_array($sp['tfidf_vector'] ?? null)
                ? $sp['tfidf_vector']
                : ($vectors[$pidStr] ?? []);

            // 1. Discount percentage (from regular & flash price)
            $regularPrice = (float)($item['regular_price'] ?? $sp['gia_ban'] ?? 0);
            $flashPrice = (float)($item['flash_price'] ?? $item['effective_price'] ?? $regularPrice);
            $discountPct = (float)($item['discount_percent'] ?? 0);
            if ($discountPct <= 0 && $regularPrice > 0 && $flashPrice < $regularPrice) {
                $discountPct = round((($regularPrice - $flashPrice) / $regularPrice) * 100);
            }

            // 2. Simple Weighted Rating (canonical m=21, C=4.8890)
            $v = (float)($sp['so_luong_danh_gia'] ?? 0);
            $R = (float)($sp['diem_danh_gia'] ?? 0);
            $weightedRating = ($v + self::CANONICAL_M > 0)
                ? (($v / ($v + self::CANONICAL_M)) * $R) + ((self::CANONICAL_M / ($v + self::CANONICAL_M)) * self::CANONICAL_C)
                : self::CANONICAL_C;

            // 3. Personal Relevance calculation according to state
            $personalScore = 0.0;
            $behaviorScore = 0.0;
            $profileScore = 0.0;
            $skinScore = 0.0;
            $budgetScore = 0.0;
            $searchScore = 0.0;
            $viewScore = 0.0;

            if ($userState === 'STATE_B') {
                if (!empty($behaviorQueryVector) && !empty($docVector)) {
                    $behaviorScore = $this->cosineSimilarity($behaviorQueryVector, $docVector);
                }
                $personalScore = $behaviorScore;
            } elseif ($userState === 'STATE_C') {
                $skinScore = $this->recommender->computeSkinTypeScore($sp['loai_da'] ?? '', $skinType);
                $contentScore = (!empty($profileQueryVector) && !empty($docVector))
                    ? $this->cosineSimilarity($profileQueryVector, $docVector)
                    : 0.0;
                // Effective flash price is used for flash sale budget proximity
                $budgetScore = $this->recommender->computeBudgetScore($flashPrice, $userBudget);
                $profileScore = (self::PROFILE_WEIGHT_SKIN * $skinScore)
                              + (self::PROFILE_WEIGHT_TFIDF * $contentScore)
                              + (self::PROFILE_WEIGHT_BUDGET * $budgetScore);
                $personalScore = $profileScore;
            } elseif ($userState === 'STATE_D' || $userState === 'STATE_E') {
                if (!empty($behaviorQueryVector) && !empty($docVector)) {
                    $behaviorScore = $this->cosineSimilarity($behaviorQueryVector, $docVector);
                }
                $skinScore = $this->recommender->computeSkinTypeScore($sp['loai_da'] ?? '', $skinType);
                $contentScore = (!empty($profileQueryVector) && !empty($docVector))
                    ? $this->cosineSimilarity($profileQueryVector, $docVector)
                    : 0.0;
                $budgetScore = $this->recommender->computeBudgetScore($flashPrice, $userBudget);
                $profileScore = (self::PROFILE_WEIGHT_SKIN * $skinScore)
                              + (self::PROFILE_WEIGHT_TFIDF * $contentScore)
                              + (self::PROFILE_WEIGHT_BUDGET * $budgetScore);
                $personalScore = (self::HYBRID_WEIGHT_BEHAVIOR * $behaviorScore)
                               + (self::HYBRID_WEIGHT_PROFILE * $profileScore);
            }

            // Calculate specific sub-signals for explainability/reason tags
            if (!empty($searchVector) && !empty($docVector)) {
                $searchScore = $this->cosineSimilarity($searchVector, $docVector);
            }
            if (!empty($viewVector) && !empty($docVector)) {
                $viewScore = $this->cosineSimilarity($viewVector, $docVector);
            }

            $entry = $item;
            $entry['_calc'] = [
                'raw_personal' => $personalScore,
                'raw_discount' => $discountPct,
                'raw_wr'       => $weightedRating,
                'sub_signals'  => [
                    'behavior'  => $behaviorScore,
                    'profile'   => $profileScore,
                    'skin'      => $skinScore,
                    'budget'    => $budgetScore,
                    'search'    => $searchScore,
                    'view'      => $viewScore,
                    'v'         => $v,
                    'r'         => $R,
                ],
            ];

            $scoredItems[] = $entry;
            $rawPersonalList[] = $personalScore;
            $rawDiscountList[] = $discountPct;
            $rawWrList[] = $weightedRating;
        }

        // Determine ranking mode and fallback condition
        $hasDifferentiatingPersonalSignal = false;
        $hasUsableVector = (!empty($behaviorQueryVector) || !empty($profileQueryVector));

        if ($userState !== 'STATE_A' && $hasUsableVector) {
            $minP = min($rawPersonalList);
            $maxP = max($rawPersonalList);
            // Require meaningful relevance (max personal score at least 0.15 and spread at least 0.05)
            // to avoid triggering personalization on background noise or random token overlap.
            if (($maxP - $minP) >= 0.05 && $maxP >= 0.15) {
                $hasDifferentiatingPersonalSignal = true;
            }
        }

        $rankingMode = 'BASELINE';
        if ($hasDifferentiatingPersonalSignal) {
            if ($userState === 'STATE_B') {
                $rankingMode = 'BEHAVIOR';
            } elseif ($userState === 'STATE_C') {
                $rankingMode = 'PROFILE';
            } else {
                $rankingMode = 'HYBRID';
            }
        }

        // Perform in-campaign Min-Max normalization
        $normPersonal = $this->minMaxNormalize($rawPersonalList);
        $normDiscount = $this->minMaxNormalize($rawDiscountList);
        $normWr       = $this->minMaxNormalize($rawWrList);

        // Compute composite score and select grounded reason tag
        for ($i = 0; $i < count($scoredItems); $i++) {
            $np = $normPersonal[$i];
            $nd = $normDiscount[$i];
            $nw = $normWr[$i];
            $sub = $scoredItems[$i]['_calc']['sub_signals'];
            $discPct = (int)$scoredItems[$i]['_calc']['raw_discount'];

            if ($rankingMode !== 'BASELINE') {
                $composite = (self::WEIGHT_PERSONAL * $np)
                           + (self::WEIGHT_DISCOUNT * $nd)
                           + (self::WEIGHT_RATING * $nw);
            } else {
                $composite = (self::GUEST_WEIGHT_DISCOUNT * $nd)
                           + (self::GUEST_WEIGHT_RATING * $nw);
            }

            // Reason tag selection (grounded in actual evidence)
            $reasonTag = $this->selectReasonTag($rankingMode, $sub, $discPct);

            $scoredItems[$i]['_rank_meta'] = [
                'ranking_mode'        => $rankingMode,
                'user_state'          => $userState,
                'composite_score'     => round($composite, 4),
                'normalized_personal' => round($np, 4),
                'normalized_discount' => round($nd, 4),
                'normalized_wr'       => round($nw, 4),
                'reason_tag'          => $reasonTag,
                'raw_personal'        => round($scoredItems[$i]['_calc']['raw_personal'], 4),
                'discount_percent'    => $discPct,
            ];
            // Attach public reason tag for UI cards
            $scoredItems[$i]['flash_sale_reason_tag'] = $reasonTag;
            unset($scoredItems[$i]['_calc']);
        }

        // Sort items deterministically
        // Order: composite_score DESC, personal_relevance DESC, discount_percent DESC, wr DESC, ma_san_pham ASC
        usort($scoredItems, static function (array $a, array $b): int {
            $metaA = $a['_rank_meta'];
            $metaB = $b['_rank_meta'];

            $compDiff = $metaB['composite_score'] <=> $metaA['composite_score'];
            if ($compDiff !== 0) {
                return $compDiff;
            }

            $pDiff = $metaB['raw_personal'] <=> $metaA['raw_personal'];
            if ($pDiff !== 0) {
                return $pDiff;
            }

            $dDiff = $metaB['discount_percent'] <=> $metaA['discount_percent'];
            if ($dDiff !== 0) {
                return $dDiff;
            }

            $wDiff = $metaB['normalized_wr'] <=> $metaA['normalized_wr'];
            if ($wDiff !== 0) {
                return $wDiff;
            }

            return strcmp((string)$a['ma_san_pham'], (string)$b['ma_san_pham']);
        });

        return [
            'ranked_items'    => $scoredItems,
            'ranking_mode'    => $rankingMode,
            'user_state'      => $userState,
            'is_personalized' => ($rankingMode !== 'BASELINE'),
        ];
    }

    /**
     * Apply secondary product-family diversity for Homepage Top 4.
     * Prevents the top 4 slots from being dominated by identical brand/family variants.
     */
    public function applyTop4Diversity(array $rankedItems, int $limit = 4): array {
        if (count($rankedItems) <= $limit) {
            return $rankedItems;
        }

        $top4 = [];
        $deferred = [];
        $seenBrands = [];

        foreach ($rankedItems as $item) {
            $brand = trim(strtolower((string)($item['thuong_hieu'] ?? '')));
            if ($brand !== '' && !isset($seenBrands[$brand]) && count($top4) < $limit) {
                $top4[] = $item;
                $seenBrands[$brand] = true;
            } else {
                $deferred[] = $item;
            }
        }

        // If fewer than limit distinct brands exist, fill remaining slots from deferred
        while (count($top4) < $limit && !empty($deferred)) {
            $top4[] = array_shift($deferred);
        }

        return $top4;
    }

    /**
     * In-campaign Min-Max normalization with safe zero-range handling.
     * If all values are identical (max == min), all items receive equal neutral contribution 0.50.
     */
    private function minMaxNormalize(array $values): array {
        if (empty($values)) {
            return [];
        }
        $min = min($values);
        $max = max($values);
        $range = $max - $min;

        $normalized = [];
        foreach ($values as $val) {
            if ($range <= 0.000001) {
                $normalized[] = 0.50; // Neutral uniform value
            } else {
                $normalized[] = ($val - $min) / $range;
            }
        }
        return $normalized;
    }

    /**
     * Select grounded reason tag based on actual active evidence.
     * Prioritizes specific user signals first, falls back to deal or rating evidence.
     */
    private function selectReasonTag(string $rankingMode, array $sub, int $discountPct): string {
        if ($rankingMode !== 'BASELINE') {
            // 1. Search evidence
            if (($sub['search'] ?? 0.0) >= 0.40) {
                return 'Theo tìm kiếm gần đây';
            }
            // 2. View evidence
            if (($sub['view'] ?? 0.0) >= 0.40) {
                return 'Tương tự sản phẩm bạn vừa xem';
            }
            // 3. Skin profile match
            if (($sub['skin'] ?? 0.0) >= 1.0) {
                return 'Phù hợp loại da của bạn';
            }
            // 4. Budget fit
            if (($sub['budget'] ?? 0.0) >= 1.0) {
                return 'Trong ngân sách của bạn';
            }
        }

        // Deal & Rating evidence (applicable to baseline & fallback)
        if ($discountPct >= 35) {
            return "Giảm sâu {$discountPct}%";
        }
        if (($sub['v'] ?? 0) >= self::CANONICAL_M && ($sub['r'] ?? 0) >= 4.5) {
            return 'Được đánh giá tốt';
        }
        if ($discountPct > 0) {
            return "Giảm {$discountPct}%";
        }

        return 'Ưu đãi nổi bật';
    }

    /**
     * Cosine similarity between two sparse/dense associative vectors.
     */
    private function cosineSimilarity(array $vecA, array $vecB): float {
        if (empty($vecA) || empty($vecB)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($vecA as $k => $v) {
            $normA += $v * $v;
            if (isset($vecB[$k])) {
                $dot += $v * $vecB[$k];
            }
        }
        foreach ($vecB as $v) {
            $normB += $v * $v;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $dot / (sqrt($normA) * sqrt($normB))));
    }
}
