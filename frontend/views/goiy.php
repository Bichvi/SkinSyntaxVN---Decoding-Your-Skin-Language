<?php
$isLoggedIn = (bool)($isLoggedIn ?? false);
$showPublicDiscovery = isset($showPublicDiscovery) ? (bool)$showPublicDiscovery : !$isLoggedIn;
$publicFilters = is_array($publicFilters ?? null) ? $publicFilters : [];
$publicProducts = is_array($publicProducts ?? null) ? $publicProducts : [];
$totalFiltered = (int)($totalFiltered ?? count($publicProducts));
$publicSections = is_array($publicSections ?? null) ? $publicSections : [];
$profileSections = is_array($profileSections ?? null) ? $profileSections : [];
$brandOptions = is_array($brandOptions ?? null) ? $brandOptions : [];
$categoryOptions = is_array($categoryOptions ?? null) ? $categoryOptions : [];
$profile = is_array($recommendationProfile ?? null) ? $recommendationProfile : [];
$llamaRecommendation = is_array($llamaRecommendation ?? null) ? $llamaRecommendation : [];
$mongoUnavailableMessage = trim((string)($mongoUnavailableMessage ?? ''));
$profileUnavailableMessage = trim((string)($profileUnavailableMessage ?? ''));
$skinProfilePromptMessage = trim((string)($skinProfilePromptMessage ?? ''));
$surveyUrl = trim((string)($surveyUrl ?? (BASE_URL . '/index.php?r=khaosat')));

$sortOptions = [
    'default' => 'Mặc định',
    'price_asc' => 'Giá tăng dần',
    'price_desc' => 'Giá giảm dần',
    'best_seller' => 'Bán chạy',
    'top_rated' => 'Đánh giá cao',
    'discount' => 'Giảm giá nhiều',
    'newest' => 'Mới nhất',
    'most_viewed' => 'Nhiều lượt xem',
];
$pricePresets = [
    ['label' => 'Dưới 200k', 'min' => '0', 'max' => '200000'],
    ['label' => '200k - 500k', 'min' => '200000', 'max' => '500000'],
    ['label' => '500k - 1tr', 'min' => '500000', 'max' => '1000000'],
    ['label' => 'Trên 1tr', 'min' => '1000000', 'max' => ''],
];
$sections = [
    'best_seller' => ['title' => 'Sản phẩm bán chạy nhất', 'badge' => 'Bán chạy'],
    'top_rated' => ['title' => 'Sản phẩm được đánh giá cao', 'badge' => 'Đánh giá cao'],
    'discount' => ['title' => 'Sản phẩm đang giảm giá', 'badge' => 'Đang giảm giá'],
    'most_viewed' => ['title' => 'Sản phẩm được nhiều người quan tâm', 'badge' => 'Đang quan tâm'],
    'new' => ['title' => 'Sản phẩm mới', 'badge' => 'Mới lên kệ'],
];
$currentSort = trim((string)($publicFilters['sort'] ?? 'default'));
$hasActiveFilter = (!empty($publicFilters['keyword'])) || (!empty($publicFilters['gia_tu'])) || (!empty($publicFilters['gia_den'])) || (!empty($publicFilters['danh_muc'])) || (!empty($publicFilters['thuong_hieu'])) || ($currentSort !== '' && $currentSort !== 'default');
$queryWith = static function (array $extra) use ($publicFilters): string {
    $query = array_filter([
        'keyword' => trim((string)($publicFilters['keyword'] ?? '')),
        'danh_muc' => trim((string)($publicFilters['danh_muc'] ?? '')),
        'thuong_hieu' => trim((string)($publicFilters['thuong_hieu'] ?? '')),
        'gia_tu' => trim((string)($publicFilters['gia_tu'] ?? '')),
        'gia_den' => trim((string)($publicFilters['gia_den'] ?? '')),
        'sort' => trim((string)($publicFilters['sort'] ?? 'default')),
    ], static fn($value) => $value !== '');
    return http_build_query(array_merge($query, $extra));
};
$renderCard = static function (array $product, string $badgeLabel = '', string $cardVariant = 'default'): void {
    include __DIR__ . '/partials/goiy_product_card.php';
};

/*
 * The current PHP controller still returns the legacy product list shape. The
 * normalizer below also accepts the newer am_routine/pm_routine envelope so
 * this view can evolve without assuming fields that the catalog does not have.
 */
$asList = static function ($value): array {
    if (is_array($value)) {
        return array_values(array_filter(array_map(static fn($item): string => trim((string)$item), $value), static fn(string $item): bool => $item !== ''));
    }
    if (is_string($value) && trim($value) !== '') {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\n\r]+/u', $value) ?: [])));
    }
    return [];
};
$normalizeProduct = static function (array $item) use ($asList): array {
    $id = trim((string)($item['id'] ?? $item['product_id'] ?? $item['ma_san_pham'] ?? ''));
    $name = trim((string)($item['name'] ?? $item['ten_san_pham'] ?? $item['title'] ?? ''));
    $ingredients = $asList($item['key_ingredients'] ?? $item['matched_ingredients'] ?? $item['thanh_phan_chinh'] ?? $item['thanh_phan'] ?? []);
    $matchedConcerns = $asList($item['matched_concerns'] ?? $item['concerns'] ?? []);
    $image = trim((string)($item['image'] ?? $item['image_url'] ?? $item['link_hinh_anh'] ?? ''));
    $price = (int)($item['price'] ?? $item['gia_ban'] ?? 0);
    $originalPrice = (int)($item['original_price'] ?? $item['gia_thi_truong'] ?? $item['market_price'] ?? 0);
    $scoreRaw = $item['match_score'] ?? $item['match_percent'] ?? $item['score'] ?? null;
    $score = is_numeric($scoreRaw) ? max(0, min(100, (float)$scoreRaw)) : null;
    return array_merge($item, [
        'id' => $id,
        'name' => $name !== '' ? $name : 'Sản phẩm SkinSyntax',
        'brand' => trim((string)($item['brand'] ?? $item['thuong_hieu'] ?? '')),
        'price' => $price,
        'original_price' => $originalPrice,
        'image' => $image,
        'match_score' => $score,
        'key_ingredients' => $ingredients,
        'matched_concerns' => $matchedConcerns,
        'avoid_flags' => $asList($item['avoid_flags'] ?? []),
        'detail_url' => trim((string)($item['detail_url'] ?? '')),
        'stock_status' => trim((string)($item['stock_status'] ?? '')),
        'reason' => trim((string)($item['why_this_product'] ?? $item['reason'] ?? $item['llm_explanation'] ?? '')),
    ]);
};
$classifyProduct = static function (array $product): string {
    $name = mb_strtolower(implode(' ', array_filter([
        $product['name'] ?? '',
        $product['ten_san_pham'] ?? '',
    ])), 'UTF-8');
    $category = mb_strtolower(implode(' ', array_filter([
        $product['category'] ?? '',
        $product['danh_muc'] ?? '',
        $product['loai_san_pham'] ?? '',
    ])), 'UTF-8');
    $text = $name . ' ' . $category;
    if (preg_match('/tẩy trang|tay trang|micellar|cleansing water|cleansing oil|cleansing balm/u', $text)) return 'remover';
    if (preg_match('/chống nắng|chong nang|kem chống nắng|sunscreen|sunblock|spf/u', $text)) return 'sunscreen';
    if (preg_match('/sữa rửa mặt|sua rua mat|rửa mặt|rua mat|làm sạch|lam sach|cleanser|face wash|foaming wash|cleansing foam/u', $text)) return 'cleanser';
    if (preg_match('/toner|nước cân bằng|nuoc can bang|nước hoa hồng|nuoc hoa hong/u', $text)) return 'toner';
    if (preg_match('/hỗ trợ trị mụn|ho tro tri mun|chấm mụn|cham mun|spot treatment/u', $category . ' ' . $name)) return 'spot_treatment';
    if (preg_match('/serum|tinh chất|tinh chat|essence|ampoule|retinol|tretinoin|bha|aha|niacinamide|salicylic|benzoyl/u', $text)) return 'treatment';
    if (preg_match('/dưỡng ẩm|duong am|kem dưỡng|kem duong|phục hồi|phuc hoi|moisturizer|cream|gel dưỡng|gel duong|emulsion/u', $text)) return 'moisturizer';
    return 'other';
};
$isMakeupProduct = static function (array $product): bool {
    $text = mb_strtolower(implode(' ', array_filter([
        $product['name'] ?? '',
        $product['ten_san_pham'] ?? '',
        $product['category'] ?? '',
        $product['loai_san_pham'] ?? '',
        $product['danh_muc'] ?? '',
        $product['danh_muc_day_du'] ?? '',
    ])), 'UTF-8');
    return preg_match('/trang\s*điểm|trang\s*diem|makeup|foundation|kem\s+nền|kem\s+nen|phấn\s+nền|phan\s+nen|cushion|concealer|son\s+môi|son\s+moi|mascara|eyeliner|má\s+hồng|ma\s+hong|phấn\s+mắt|phan\s+mat|phấn\s+phủ|phan\s+phu|dầu\s+dưỡng\s+tóc|dau\s+duong\s+toc|dầu\s+gội|dau\s+goi|dầu\s+xả|dau\s+xa|\btóc\b|\btoc\b|dưỡng\s+thể|duong\s+the|body|sữa\s+tắm|sua\s+tam|khử\s+mùi|khu\s+mui|lăn\s+nách|lan\s+nach|trắng\s+răng|trang\s+rang|kem\s+đánh\s+răng|kem\s+danh\s+rang|nước\s+súc\s+miệng|nuoc\s+suc\s+mieng|\bnước\s+hoa\b|\bnuoc\s+hoa\b|parfum/u', $text) === 1;
};
$stepNames = [
    'remover' => 'Tẩy trang',
    'cleanser' => 'Làm sạch',
    'toner' => 'Toner / cân bằng',
    'treatment' => 'Serum',
    'moisturizer' => 'Dưỡng ẩm / phục hồi',
    'sunscreen' => 'Chống nắng',
    'spot_treatment' => 'Kem đặc trị',
];
$rawConflicts = $llamaRecommendation['conflict_warnings'] ?? $llamaRecommendation['conflicts'] ?? [];
$conflictWarnings = [];
if (is_array($rawConflicts)) {
    foreach ($rawConflicts as $warning) {
        if (!is_array($warning)) continue;
        $pair = is_array($warning['pair'] ?? null) ? array_values(array_filter(array_map('strval', $warning['pair']))) : [];
        if (count($pair) >= 2) {
            $conflictWarnings[] = [
                'pair' => array_slice($pair, 0, 2),
                'resolution' => trim((string)($warning['resolution'] ?? '')),
            ];
        }
    }
}
$conflictMatchesProduct = static function (array $product, array $warning): bool {
    $haystack = mb_strtolower(implode(' ', [$product['id'] ?? '', $product['name'] ?? '']), 'UTF-8');
    foreach (($warning['pair'] ?? []) as $label) {
        $needle = mb_strtolower(trim((string)$label), 'UTF-8');
        if ($needle !== '' && mb_strpos($haystack, $needle) !== false) return true;
    }
    return false;
};
$buildSafety = static function (array $product, array $warnings) use ($conflictMatchesProduct): array {
    $flags = is_array($product['avoid_flags'] ?? null) ? $product['avoid_flags'] : [];
    $productWarnings = is_array($product['warnings'] ?? null) ? $product['warnings'] : [];
    $hasIngredients = !empty($product['key_ingredients']);
    $matchedWarnings = array_values(array_filter($warnings, static fn(array $warning): bool => $conflictMatchesProduct($product, $warning)));
    if ($flags || $productWarnings || $matchedWarnings) {
        $parts = array_merge($flags, $productWarnings);
        foreach ($matchedWarnings as $warning) {
            if (!empty($warning['resolution'])) $parts[] = $warning['resolution'];
        }
        return ['level' => 'warning', 'text' => implode(' ', array_slice(array_filter(array_map('strval', $parts)), 0, 2)) ?: 'Có dữ liệu cần xem lại trước khi dùng.'];
    }
    if (!$hasIngredients) {
        return ['level' => 'unknown', 'text' => 'Chưa đủ dữ liệu thành phần trong response để kết luận an toàn.'];
    }
    return ['level' => 'safe', 'text' => 'Đã kiểm tra: chưa ghi nhận cờ tránh trong dữ liệu thành phần trả về.'];
};
$isProductForStep = static function (array $product, string $category) use ($classifyProduct): bool {
    return $category !== '' && $classifyProduct($product) === $category;
};
$normalizeStep = static function (array $rawStep, string $session) use ($normalizeProduct, $classifyProduct, $stepNames, $buildSafety, $isMakeupProduct, $isProductForStep): array {
    $rawProduct = is_array($rawStep['recommended_product'] ?? null)
        ? $rawStep['recommended_product']
        : (isset($rawStep['id']) || isset($rawStep['product_id']) || isset($rawStep['ma_san_pham']) ? $rawStep : null);
    $product = is_array($rawProduct) ? $normalizeProduct($rawProduct) : null;
    if (is_array($product) && $isMakeupProduct($product)) $product = null;
    $category = trim((string)($rawStep['category'] ?? ($product['category'] ?? '')));
    if (!in_array($category, array_keys($stepNames), true)) $category = is_array($product) ? $classifyProduct($product) : 'other';
    $alternatives = [];
    foreach (($rawStep['alternatives'] ?? []) as $alternative) {
        if (is_array($alternative)) {
            $normalizedAlternative = $normalizeProduct($alternative);
            if (!$isMakeupProduct($normalizedAlternative) && $isProductForStep($normalizedAlternative, $category)) {
                $alternatives[] = $normalizedAlternative;
            }
        }
    }
    $stepOrder = (int)($rawStep['step_order'] ?? 0);
    return [
        'step_name' => trim((string)($rawStep['step_name'] ?? $stepNames[$category] ?? 'Sản phẩm phù hợp')) ?: ($stepNames[$category] ?? 'Sản phẩm phù hợp'),
        'step_order' => $stepOrder > 0 ? $stepOrder : 1,
        'category' => $category,
        'session' => $session,
        'recommended_product' => $product,
        'alternatives' => $alternatives,
        'why_this_product' => trim((string)($rawStep['why_this_product'] ?? (is_array($product) ? ($product['reason'] ?? '') : ''))),
        'safety' => $buildSafety(is_array($product) ? $product : [], []),
    ];
};
$sourceProducts = [];
foreach (($llamaRecommendation['products'] ?? []) as $product) {
    if (is_array($product)) $sourceProducts[] = $normalizeProduct($product);
}
foreach ($profileSections as $sectionProducts) {
    if (!is_array($sectionProducts)) continue;
    foreach ($sectionProducts as $product) {
        if (is_array($product)) $sourceProducts[] = $normalizeProduct($product);
    }
}
$uniqueProducts = [];
foreach ($sourceProducts as $product) {
    $key = $product['id'] !== '' ? $product['id'] : md5($product['name'] . '|' . $product['price']);
    if (!isset($uniqueProducts[$key])) $uniqueProducts[$key] = $product;
}
$sourceProducts = array_values($uniqueProducts);
$sourceProducts = array_values(array_filter($sourceProducts, static fn(array $product): bool => !$isMakeupProduct($product)));
$canonicalProfile = is_array($llamaRecommendation['profile_summary'] ?? null) ? $llamaRecommendation['profile_summary'] : [];
$profileConcerns = $asList($canonicalProfile['concerns'] ?? $profile['concerns'] ?? []);
$rawAm = is_array($llamaRecommendation['am_routine'] ?? null) ? $llamaRecommendation['am_routine'] : [];
$rawPm = is_array($llamaRecommendation['pm_routine'] ?? null) ? $llamaRecommendation['pm_routine'] : [];
$routineOrder = [
    'am' => ['cleanser', 'moisturizer', 'sunscreen'],
    'pm' => ['remover', 'cleanser', 'toner', 'treatment', 'moisturizer', 'spot_treatment'],
];
$needsSpotTreatment = static function (array $concerns): bool {
    foreach ($concerns as $concern) {
        $normalized = mb_strtolower((string)$concern, 'UTF-8');
        if (preg_match('/mụn|mun|acne|viêm|viem/u', $normalized)) return true;
    }
    return false;
};
$makeFallbackRoutine = static function (array $products, string $session, array $concerns = []) use ($classifyProduct, $stepNames, $buildSafety, $routineOrder, $needsSpotTreatment): array {
    $order = $routineOrder[$session] ?? $routineOrder['am'];
    $groups = array_fill_keys($order, []);
    foreach ($products as $product) {
        $category = $classifyProduct($product);
        if (isset($groups[$category])) $groups[$category][] = $product;
    }
    if (!$needsSpotTreatment($concerns)) {
        unset($groups['spot_treatment']);
    }
    $steps = [];
    $stepOrder = 1;
    foreach ($order as $category) {
        if (empty($groups[$category])) continue;
        $chosen = array_shift($groups[$category]);
        $steps[] = [
            'step_name' => $stepNames[$category],
            'step_order' => $stepOrder++,
            'category' => $category,
            'session' => $session,
            'recommended_product' => $chosen,
            'alternatives' => array_values($groups[$category]),
            'why_this_product' => $chosen['reason'] ?? '',
            'safety' => $buildSafety($chosen, []),
        ];
    }
    return $steps;
};
$shapeRoutine = static function (array $steps, string $session, array $concerns) use ($routineOrder, $needsSpotTreatment, $stepNames): array {
    $order = $routineOrder[$session] ?? $routineOrder['am'];
    $positions = array_flip($order);
    $allowSpotTreatment = $needsSpotTreatment($concerns);
    $filtered = [];
    $seenCategories = [];
    foreach ($steps as $step) {
        if (!is_array($step)) continue;
        $category = trim((string)($step['category'] ?? ''));
        if (!isset($positions[$category])) continue;
        if ($category === 'spot_treatment' && !$allowSpotTreatment) continue;
        if (isset($seenCategories[$category])) continue;
        $seenCategories[$category] = true;
        $step['session'] = $session;
        $step['step_name'] = $stepNames[$category] ?? trim((string)($step['step_name'] ?? 'Sản phẩm phù hợp'));
        $filtered[] = $step;
    }
    usort($filtered, static function (array $left, array $right) use ($positions): int {
        return ($positions[$left['category']] ?? PHP_INT_MAX) <=> ($positions[$right['category']] ?? PHP_INT_MAX);
    });
    foreach ($filtered as $index => &$step) {
        $step['step_order'] = $index + 1;
    }
    unset($step);
    return $filtered;
};
$hasAmEnvelope = array_key_exists('am_routine', $llamaRecommendation) && is_array($llamaRecommendation['am_routine']);
$hasPmEnvelope = array_key_exists('pm_routine', $llamaRecommendation) && is_array($llamaRecommendation['pm_routine']);
$amRoutine = $hasAmEnvelope
    ? $shapeRoutine(array_values(array_filter(array_map(static fn($step) => is_array($step) ? $normalizeStep($step, 'am') : [], $rawAm), static fn(array $step): bool => is_array($step['recommended_product'] ?? null))), 'am', $profileConcerns)
    : $makeFallbackRoutine($sourceProducts, 'am', $profileConcerns);
$pmRoutine = $hasPmEnvelope
    ? $shapeRoutine(array_values(array_filter(array_map(static fn($step) => is_array($step) ? $normalizeStep($step, 'pm') : [], $rawPm), static fn(array $step): bool => is_array($step['recommended_product'] ?? null))), 'pm', $profileConcerns)
    : $makeFallbackRoutine($sourceProducts, 'pm', $profileConcerns);
foreach ([$amRoutine, $pmRoutine] as &$routine) {
    foreach ($routine as &$step) {
        $product = is_array($step['recommended_product'] ?? null) ? $step['recommended_product'] : [];
        $step['safety'] = $buildSafety($product, $conflictWarnings);
        $step['step_order'] = (int)($step['step_order'] ?? 1);
    }
    unset($step);
}
unset($routine);
$allSelected = [];
$selectedById = [];
foreach ([$amRoutine, $pmRoutine] as $routine) {
    foreach ($routine as $step) {
        $product = is_array($step['recommended_product'] ?? null) ? $step['recommended_product'] : [];
        $id = trim((string)($product['id'] ?? ''));
        if ($id !== '' && !isset($selectedById[$id])) {
            $selectedById[$id] = true;
            $allSelected[] = $product;
        }
    }
}
$profileSkinType = trim((string)($canonicalProfile['skin_type'] ?? $profile['skin_type'] ?? ''));
$profileAvoid = $asList($canonicalProfile['avoided'] ?? $canonicalProfile['avoid_ingredients'] ?? $profile['avoid_ingredients'] ?? []);
$profileBudget = (int)($profile['budget'] ?? $canonicalProfile['budget'] ?? 0);
$profileName = trim((string)($profile['display_name'] ?? 'bạn')) ?: 'bạn';
$profileSummaryForUi = [
    'skin_type' => $profileSkinType,
    'concerns' => $profileConcerns,
    'avoid_ingredients' => $profileAvoid,
    'budget' => $profileBudget,
];
$headlineConcern = $profileConcerns ? implode(', ', array_slice($profileConcerns, 0, 2)) : '';
$profileHeadline = $headlineConcern !== ''
    ? 'Routine ưu tiên cho ' . $headlineConcern
    : ($profileSkinType !== '' ? 'Sản phẩm phù hợp với ' . $profileSkinType : 'Gợi ý được chọn từ hồ sơ của bạn');
$profileSubheadline = $profileAvoid
    ? 'Đã ưu tiên tránh: ' . implode(', ', array_slice($profileAvoid, 0, 3))
    : 'Dựa trên hồ sơ da, mục tiêu và dữ liệu catalog hiện có.';
$excludedCount = null;
foreach ([$llamaRecommendation['excluded_count'] ?? null, $llamaRecommendation['profile_summary']['excluded_count'] ?? null] as $count) {
    if (is_numeric($count)) { $excludedCount = max(0, (int)$count); break; }
}
$comboFromApi = is_array($llamaRecommendation['combo'] ?? null) ? $llamaRecommendation['combo'] : [];
$comboTotal = is_numeric($comboFromApi['total_price'] ?? null) ? (int)$comboFromApi['total_price'] : 0;
$comboOriginal = 0;
if ($comboTotal <= 0) {
    $comboTotal = array_sum(array_map(static fn(array $product): int => (int)($product['price'] ?? 0), $allSelected));
}
foreach ($allSelected as $product) $comboOriginal += (int)($product['original_price'] ?? 0);
$comboDiscount = is_numeric($comboFromApi['discount_percent'] ?? null) ? (float)$comboFromApi['discount_percent'] : ($comboOriginal > $comboTotal && $comboTotal > 0 ? (1 - $comboTotal / $comboOriginal) * 100 : 0);
$comboProductIds = $asList($comboFromApi['product_ids'] ?? []);
if (!$comboProductIds) $comboProductIds = array_values(array_filter(array_map(static fn(array $product): string => trim((string)($product['id'] ?? '')), $allSelected)));
$mapProductIndex = [];
$ingredientProductCounts = [];
foreach ($allSelected as $product) {
    $id = trim((string)($product['id'] ?? ''));
    if ($id === '') continue;
    $mapProductIndex[$id] = $product;
    foreach ($product['key_ingredients'] ?? [] as $ingredient) {
        $key = mb_strtolower(trim((string)$ingredient), 'UTF-8');
        if ($key !== '') $ingredientProductCounts[$key] = ($ingredientProductCounts[$key] ?? 0) + 1;
    }
}
$sharedIngredients = array_keys(array_filter($ingredientProductCounts, static fn(int $count): bool => $count > 1));
$hasIngredientMap = $sharedIngredients || $conflictWarnings;
$toPayloadProduct = static function (array $product): array {
    return [
        'id' => (string)($product['id'] ?? ''),
        'name' => (string)($product['name'] ?? ''),
        'price' => (int)($product['price'] ?? 0),
        'original_price' => (int)($product['original_price'] ?? 0),
        'image' => (string)($product['image'] ?? ''),
        'match_score' => $product['match_score'] ?? null,
        'key_ingredients' => array_values($product['key_ingredients'] ?? []),
        'detail_url' => (string)($product['detail_url'] ?? ''),
    ];
};
$toPayloadStep = static function (array $step) use ($toPayloadProduct): array {
    return [
        'step_name' => (string)($step['step_name'] ?? ''),
        'step_order' => (int)($step['step_order'] ?? 0),
        'category' => (string)($step['category'] ?? ''),
        'recommended_product' => is_array($step['recommended_product'] ?? null) ? $toPayloadProduct($step['recommended_product']) : null,
        'alternatives' => array_values(array_map(static fn(array $product): array => $toPayloadProduct($product), array_filter($step['alternatives'] ?? [], 'is_array'))),
    ];
};
$routinePayload = [
    'am' => array_values(array_map($toPayloadStep, $amRoutine)),
    'pm' => array_values(array_map($toPayloadStep, $pmRoutine)),
    'conflict_warnings' => $conflictWarnings,
    'map_products' => array_values(array_map($toPayloadProduct, $mapProductIndex)),
    'combo' => ['total_price' => $comboTotal, 'original_price' => $comboOriginal, 'discount_percent' => round(max(0, min(100, $comboDiscount)), 1), 'product_ids' => $comboProductIds],
    'combo_from_api' => is_numeric($comboFromApi['total_price'] ?? null) && (int)$comboFromApi['total_price'] > 0,
    'cart_url' => BASE_URL . '/index.php?r=them_gio_hang_ajax',
];
$routineJson = json_encode($routinePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
$recommendationOk = !empty($llamaRecommendation['ok']);
$hasRoutine = $recommendationOk && !empty($allSelected);
?>
<link rel="stylesheet" href="<?= h(BASE_URL . '/assets/css/goiy-recommendation.css?v=2') ?>">

<div class="container my-4">
  <?php if ($showPublicDiscovery): ?>
    <div class="goiy-page">
      <section class="goiy-hero mb-4">
        <div class="row align-items-center g-4">
          <div class="col-lg-8">
            <span class="goiy-eyebrow"><i class="fa-solid fa-compass me-1"></i> Gợi ý & tìm kiếm sản phẩm</span>
            <h1>Khám phá sản phẩm phù hợp</h1>
            <p>Lọc sản phẩm theo từ khóa, mức giá, danh mục, thương hiệu và khám phá các dòng sản phẩm tại SkinSyntax.</p>
          </div>
          <div class="col-lg-4"><div class="goiy-hero-card h-100"><span class="goiy-eyebrow"><i class="fa-solid fa-fire me-1"></i> Catalog SkinSyntax</span><h3>Chọn đúng sản phẩm trước khi đưa vào routine.</h3></div></div>
        </div>
      </section>

      <?php if ($skinProfilePromptMessage !== ''): ?><div class="goiy-survey-alert mb-4"><strong><?= h($skinProfilePromptMessage) ?></strong><a class="btn btn-brand" href="<?= h($surveyUrl) ?>">Khảo sát da</a></div><?php endif; ?>
      <form class="goiy-filter mb-4" method="get" action="<?= h(BASE_URL . '/index.php') ?>">
        <input type="hidden" name="r" value="goiy">
        <div class="goiy-filter-grid">
          <div><label class="form-label" for="goiy-keyword">Từ khóa</label><input class="form-control" id="goiy-keyword" type="search" name="keyword" value="<?= h((string)($publicFilters['keyword'] ?? '')) ?>" placeholder="Serum B5, kem chống nắng..."></div>
          <div><label class="form-label" for="goiy-category">Danh mục</label><select class="form-select" id="goiy-category" name="danh_muc"><option value="">Tất cả danh mục</option><?php foreach ($categoryOptions as $cat): $catName = (string)($cat['ten_danh_muc'] ?? $cat['danh_muc_day_du'] ?? ''); if ($catName !== ''): ?><option value="<?= h($catName) ?>" <?= (($publicFilters['danh_muc'] ?? '') === $catName ? 'selected' : '') ?>><?= h($catName) ?></option><?php endif; endforeach; ?></select></div>
          <div><label class="form-label" for="goiy-brand">Thương hiệu</label><select class="form-select" id="goiy-brand" name="thuong_hieu"><option value="">Tất cả thương hiệu</option><?php foreach ($brandOptions as $brand): $brandName = (string)($brand['ten_thuong_hieu'] ?? $brand['thuong_hieu'] ?? ''); if ($brandName !== ''): ?><option value="<?= h($brandName) ?>" <?= (($publicFilters['thuong_hieu'] ?? '') === $brandName ? 'selected' : '') ?>><?= h($brandName) ?></option><?php endif; endforeach; ?></select></div>
          <div><label class="form-label" for="goiy-price-min">Giá từ</label><input class="form-control" id="goiy-price-min" type="number" min="0" step="10000" name="gia_tu" value="<?= h((string)($publicFilters['gia_tu'] ?? '')) ?>" placeholder="0"></div>
          <div><label class="form-label" for="goiy-price-max">Giá đến</label><input class="form-control" id="goiy-price-max" type="number" min="0" step="10000" name="gia_den" value="<?= h((string)($publicFilters['gia_den'] ?? '')) ?>" placeholder="1.000.000"></div>
          <div><label class="form-label" for="goiy-sort">Sắp xếp</label><select class="form-select" id="goiy-sort" name="sort"><?php foreach ($sortOptions as $value => $label): ?><option value="<?= h($value) ?>" <?= ($currentSort === $value ? 'selected' : '') ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
          <button class="btn btn-brand" type="submit"><i class="fa-solid fa-filter me-1"></i> Lọc</button><a class="btn btn-outline-secondary" href="<?= h(BASE_URL . '/index.php?r=goiy') ?>">Xóa</a>
        </div>
        <div class="price-pills"><span class="small text-muted me-1 align-self-center fw-bold">Mức giá nhanh:</span><?php foreach ($pricePresets as $preset): $isActive = ((string)($publicFilters['gia_tu'] ?? '') === (string)$preset['min']) && ((string)($publicFilters['gia_den'] ?? '') === (string)$preset['max']); $presetUrl = BASE_URL . '/index.php?' . $queryWith(['gia_tu' => $preset['min'], 'gia_den' => $preset['max']]); ?><a class="price-pill <?= $isActive ? 'active' : '' ?>" href="<?= h($presetUrl) ?>"><?= h($preset['label']) ?></a><?php endforeach; ?></div>
      </form>
      <?php if ($mongoUnavailableMessage !== ''): ?><div class="goiy-empty mb-4"><?= h($mongoUnavailableMessage) ?></div><?php endif; ?>
      <?php if ($hasActiveFilter): ?>
        <section class="goiy-section mb-4"><div class="goiy-section__head"><h2 class="goiy-section__title">Kết quả lọc (<?= number_format($totalFiltered) ?> sản phẩm)</h2><a class="goiy-section__more" href="<?= h(BASE_URL . '/index.php?r=goiy') ?>">Xóa bộ lọc</a></div><?php if (!$publicProducts): ?><div class="goiy-empty">Không tìm thấy sản phẩm phù hợp. Hãy thử từ khóa hoặc mức giá khác.</div><?php else: ?><div class="goiy-product-grid"><?php foreach ($publicProducts as $product): $renderCard($product, 'LỌC KHỚP'); endforeach; ?></div><?php endif; ?></section>
      <?php else: ?>
        <?php foreach ($sections as $key => $meta): $items = is_array($publicSections[$key] ?? null) ? $publicSections[$key] : []; ?><section class="goiy-section mb-4"><div class="goiy-section__head"><h2 class="goiy-section__title"><?= h($meta['title']) ?></h2><a class="goiy-section__more" href="<?= h(BASE_URL . '/index.php?r=product_collection&type=' . rawurlencode($key) . '&' . $queryWith([])) ?>">Xem tất cả</a></div><?php if (!$items): ?><div class="goiy-empty">Chưa có sản phẩm phù hợp trong nhóm này.</div><?php else: ?><div class="goiy-product-grid"><?php foreach ($items as $product): $renderCard($product, $meta['badge']); endforeach; ?></div><?php endif; ?></section><?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="recommendation-workspace" data-recommendation-root data-base-url="<?= h(BASE_URL) ?>">
      <header class="recommendation-hero">
        <div>
          <span class="recommendation-eyebrow"><i class="fa-solid fa-sparkles" aria-hidden="true"></i> Gợi ý cá nhân hóa</span>
          <h1><?= h($profileHeadline) ?></h1>
          <p class="recommendation-hero__description"><?= h($profileSubheadline) ?></p>
          <form class="recommendation-consult" id="aiConsultForm">
            <label for="aiConsultInput">Bạn muốn Syna giải thích thêm nhu cầu nào?</label>
            <input type="text" id="aiConsultInput" placeholder="Ví dụ: serum mờ thâm cho da dầu mụn" autocomplete="off">
            <button type="submit" id="aiConsultBtn"><i class="fa-solid fa-message" aria-hidden="true"></i> Hỏi Syna</button>
          </form>
        </div>
        <div class="recommendation-hero__facts" aria-label="Tóm tắt gợi ý">
          <div class="recommendation-fact"><strong><?= h($profileSkinType !== '' ? $profileSkinType : 'Chưa xác định') ?></strong><span>Loại da trong hồ sơ</span></div>
          <div class="recommendation-fact"><strong><?= h((string)count($allSelected)) ?></strong><span>Sản phẩm đang hiển thị</span></div>
          <?php if ($profileBudget > 0): ?><div class="recommendation-fact"><strong><?= h(vnd($profileBudget)) ?></strong><span>Ngân sách tham chiếu</span></div><?php endif; ?>
        </div>
      </header>

      <div class="recommendation-content">
        <main class="recommendation-main">
          <?php if (!$recommendationOk): ?>
            <div class="recommendation-message recommendation-message--error" role="alert"><strong>Chưa thể tải routine cá nhân hóa.</strong><br><?= h($profileUnavailableMessage !== '' ? $profileUnavailableMessage : ($llamaRecommendation['message'] ?? 'Bạn có thể thử lại sau hoặc cập nhật khảo sát da.')) ?></div>
          <?php elseif (!$hasRoutine): ?>
            <div class="recommendation-empty"><strong>Chưa đủ sản phẩm hợp lệ để dựng routine.</strong><br>Hệ thống không tự lấp dữ liệu thiếu. Bạn có thể cập nhật khảo sát hoặc khám phá catalog.</div>
          <?php else: ?>
            <div class="recommendation-tabs" role="tablist" aria-label="Cách xem gợi ý">
              <button type="button" role="tab" data-view-tab="routine" aria-selected="true"><i class="fa-solid fa-route" aria-hidden="true"></i> Routine theo bước</button>
              <?php if ($hasIngredientMap): ?><button type="button" role="tab" data-view-tab="ingredients" aria-selected="false"><i class="fa-solid fa-diagram-project" aria-hidden="true"></i> Mối liên hệ hoạt chất</button><?php endif; ?>
            </div>
            <section class="recommendation-view" data-recommendation-view="routine">
              <div class="recommendation-day-switcher"><h2 data-session-heading>Routine buổi sáng</h2><div class="recommendation-day-tabs" role="tablist" aria-label="Chọn buổi"><button type="button" role="tab" data-session-tab="am" aria-selected="true">Buổi sáng</button><button type="button" role="tab" data-session-tab="pm" aria-selected="false">Buổi tối</button></div></div>
              <div class="recommendation-routine-layout">
                <section class="skin-layer-card" aria-label="Lát cắt da và thứ tự thoa sản phẩm">
                  <div class="skin-layer-card__heading"><span class="recommendation-overline">Skin layer stack</span><h3 data-layer-heading>Thứ tự buổi sáng</h3><p class="skin-layer-card__hint">Chạm vào lớp để xem bước tương ứng.</p></div>
                  <div class="skin-layer-visual">
                    <svg viewBox="0 0 180 400" role="img" aria-label="Minh họa thứ tự các lớp trong routine">
                      <g class="skin-layer" data-layer-index="0" tabindex="0" role="button" aria-label="Lớp làm sạch"><rect x="30" y="20" width="120" height="70" rx="22" fill="#e7c8a5"></rect><text x="90" y="59" text-anchor="middle" fill="#5e4935" font-size="10" font-weight="700">Làm sạch</text></g>
                      <g class="skin-layer" data-layer-index="1" tabindex="0" role="button" aria-label="Lớp đặc trị"><rect x="30" y="94" width="120" height="80" rx="18" fill="#d9b18f"></rect><text x="90" y="139" text-anchor="middle" fill="#5e4935" font-size="10" font-weight="700">Đặc trị</text></g>
                      <g class="skin-layer" data-layer-index="2" tabindex="0" role="button" aria-label="Lớp dưỡng ẩm"><rect x="30" y="178" width="120" height="98" rx="16" fill="#c89672"></rect><text x="90" y="233" text-anchor="middle" fill="#fff7ed" font-size="10" font-weight="700">Dưỡng ẩm</text></g>
                      <g class="skin-layer" data-layer-index="3" tabindex="0" role="button" aria-label="Lớp chống nắng"><rect x="30" y="280" width="120" height="70" rx="22" fill="#99b99f"></rect><text x="90" y="319" text-anchor="middle" fill="#234435" font-size="10" font-weight="700">Chống nắng</text></g>
                    </svg>
                    <span class="skin-layer__label">Lớp hiển thị thay đổi theo buổi đang chọn</span>
                  </div>
                </section>
                <div>
                  <div class="recommendation-timeline" data-session-panel="am">
                    <?php $session = 'am'; foreach ($amRoutine as $index => $step): $stepKey = 'am-' . (int)($step['step_order'] ?? ($index + 1)); include __DIR__ . '/partials/recommendation_step_card.php'; endforeach; unset($session); ?>
                    <?php if (!$amRoutine): ?><div class="recommendation-timeline__empty">Chưa có bước buổi sáng từ dữ liệu hiện tại.</div><?php endif; ?>
                  </div>
                  <div class="recommendation-timeline" data-session-panel="pm" hidden>
                    <?php $session = 'pm'; foreach ($pmRoutine as $index => $step): $stepKey = 'pm-' . (int)($step['step_order'] ?? ($index + 1)); include __DIR__ . '/partials/recommendation_step_card.php'; endforeach; unset($session); ?>
                    <?php if (!$pmRoutine): ?><div class="recommendation-timeline__empty">Chưa có bước buổi tối từ dữ liệu hiện tại.</div><?php endif; ?>
                  </div>
                  <div class="recommendation-filter-empty" data-filter-empty hidden>Không có bước nào khớp vấn đề da đang chọn.</div>
                </div>
              </div>
            </section>
            <?php if ($hasIngredientMap): ?>
              <section class="recommendation-view" data-recommendation-view="ingredients" hidden>
                <div class="recommendation-map-card__heading"><span class="recommendation-overline">Ingredient constellation</span><h2>Những mối liên hệ cần biết</h2><p class="recommendation-map-card__hint">Chỉ hiển thị mối liên hệ có trong dữ liệu thành phần hoặc cảnh báo backend.</p></div>
                <div class="ingredient-map" data-ingredient-map><svg class="ingredient-map__svg" data-ingredient-lines aria-hidden="true"></svg><div class="ingredient-map__nodes" data-ingredient-nodes></div><div class="ingredient-map__mobile-list" data-ingredient-mobile-list></div></div>
                <div class="ingredient-map__legend"><span><i></i> Hoạt chất chung</span><span><i class="is-conflict"></i> Cặp cần lưu ý</span></div>
              </section>
            <?php endif; ?>
            <?php if (!empty($llamaRecommendation['safety_notes'])): ?><div class="recommendation-safety-notes"><strong><i class="fa-solid fa-shield-check" aria-hidden="true"></i> Ghi chú an toàn từ hệ thống</strong><br><?= nl2br(h((string)$llamaRecommendation['safety_notes'])) ?></div><?php endif; ?>
          <?php endif; ?>
        </main>
        <aside class="recommendation-aside">
          <section class="skin-identity-card">
            <div class="skin-identity-card__top"><div><span class="recommendation-overline">Skin identity</span><h2><?= h($profileSkinType !== '' ? 'Da ' . $profileSkinType : 'Hồ sơ da') ?></h2><p class="skin-identity-card__name"><?= h($profileName) ?></p></div><span class="skin-identity-card__badge" aria-hidden="true"><i class="fa-solid fa-droplet"></i></span></div>
            <div class="skin-identity-card__badges"><?php if ($profileSkinType !== ''): ?><span class="profile-chip">Da <?= h($profileSkinType) ?></span><?php endif; ?><?php if (!empty($profile['sensitivity'] ?? '')): ?><span class="profile-chip">Nhạy cảm <?= h((string)$profile['sensitivity']) ?></span><?php endif; ?></div>
            <?php if ($profileConcerns): ?><span class="skin-identity-card__section-label">Vấn đề da — chạm để lọc</span><div class="skin-identity-card__concerns"><?php foreach ($profileConcerns as $concern): ?><button type="button" class="profile-chip" data-concern-filter="<?= h($concern) ?>"><?= h($concern) ?></button><?php endforeach; ?></div><?php endif; ?>
            <?php if ($profileAvoid): ?><div class="skin-identity-card__avoid"><strong><i class="fa-solid fa-filter-circle-xmark" aria-hidden="true"></i> Thành phần đang loại trừ</strong><?= h(implode(', ', $profileAvoid)) ?><?php if ($excludedCount !== null): ?> · <?= h(number_format($excludedCount)) ?> sản phẩm đã loại bỏ<?php else: ?><br><small>Response hiện chưa trả excluded_count.</small><?php endif; ?></div><?php endif; ?>
            <div class="skin-identity-card__meta"><?php if ($profileBudget > 0): ?><div>Ngân sách tham chiếu <strong><?= h(vnd($profileBudget)) ?></strong></div><?php endif; ?><div>Trạng thái dữ liệu <strong><?= $recommendationOk ? 'Đã nhận response' : 'Chưa sẵn sàng' ?></strong></div></div>
            <a class="skin-identity-card__survey" href="<?= h($surveyUrl) ?>">Cập nhật khảo sát da <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></a>
          </section>
        </aside>
      </div>
      <?php if ($hasRoutine && $comboTotal > 0): ?><div class="recommendation-combo-feedback" data-combo-feedback aria-live="polite"></div><div class="recommendation-combo-bar"><div><div class="recommendation-combo-bar__title">Thêm toàn bộ chu trình vào giỏ</div><div class="recommendation-combo-bar__meta" data-combo-meta><?= h($comboFromApi ? 'Theo tổng giá từ response backend' : 'Tạm tính theo sản phẩm đang hiển thị') ?></div></div><div class="recommendation-combo-bar__price"><strong data-combo-total><?= h(vnd($comboTotal)) ?></strong><?php if ($comboOriginal > $comboTotal): ?><del data-combo-original><?= h(vnd($comboOriginal)) ?></del><?php endif; ?><?php if ($comboDiscount > 0): ?><span class="recommendation-combo-bar__discount" data-combo-discount>-<?= h((string)round($comboDiscount)) ?>%</span><?php endif; ?></div><button type="button" data-add-combo><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Thêm vào giỏ</button></div><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script type="application/json" id="recommendation-page-data"><?= $routineJson ?></script>
<script src="<?= h(BASE_URL . '/assets/js/goiy-recommendation.js?v=2') ?>" defer></script>
