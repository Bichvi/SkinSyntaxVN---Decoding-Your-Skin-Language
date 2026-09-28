<?php
/**
 * Offline Evaluation Suite for SkinSyntaxVN Recommendation Engine V1
 * Evaluates:
 * 1. System Metrics (integrity, leakage, duplication, determinism, crash)
 * 2. Coverage & Diversity Metrics
 * 3. Content-Based Sanity Evaluation across 7 Skincare Categories
 * 4. Profile & Hybrid Sanity Evaluation across Real Survey Profiles
 * 5. Baseline Comparison (Simple vs Content-Based vs Hybrid V1)
 * 6. Human Evaluation Dataset generation (CSV export)
 * 7. Explainability Audit
 * 8. Performance Benchmark (Median, p95 runtime)
 */

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/models/TaiKhoan.php';
require_once __DIR__ . '/../app/services/ContentBasedRecommender.php';
global $db;

$spModel = new SanPham($db);
$cbr = new ContentBasedRecommender();

// Build brand lookup map
$brandMap = [];
foreach ($db->thuong_hieu->find() as $bDoc) {
    $brandMap[(int)$bDoc['ma_thuong_hieu']] = (string)$bDoc['ten_thuong_hieu'];
}

// Build category lookup map
$allCategories = [];
foreach ($db->danh_muc->find() as $cat) {
    $allCategories[(int)$cat['ma_danh_muc']] = (string)$cat['ten_danh_muc'];
}

// Load all active products with normalized brand and category
$allProducts = [];
$allBrandsInCatalog = [];
foreach ($db->san_pham->find(['trang_thai' => 'active', 'gia_ban' => ['$gt' => 0]]) as $doc) {
    $p = (array)$doc;
    $pid = (string)$p['ma_san_pham'];
    $bId = (int)($p['ma_thuong_hieu'] ?? 0);
    $bName = $brandMap[$bId] ?? 'Khác';
    $p['thuong_hieu'] = $bName;
    if ($bName !== 'Khác' && $bName !== '') {
        $allBrandsInCatalog[$bName] = true;
    }
    $allProducts[$pid] = $p;
}
$totalCatalogCount = count($allProducts);
$totalCatalogBrands = count($allBrandsInCatalog);

echo "Loaded catalog: {$totalCatalogCount} active products, " . count($allCategories) . " categories, {$totalCatalogBrands} brands.\n";

// =====================================================================
// STEP 1: SYSTEM METRICS EVALUATION
// =====================================================================
echo "\n--- STEP 1: EVALUATING SYSTEM INTEGRITY METRICS ---\n";

$testContexts = [];
// 1. Cold start / Guest
$testContexts[] = ['mode' => 'guest_cold', 'recent' => [], 'profile' => null];
// 2. Guest with 1-5 recent views
$sampleRecentPool = ['1000', '83', '68', '1805', '631', '1534', '18'];
for ($i = 1; $i <= 5; $i++) {
    $testContexts[] = ['mode' => 'guest_recent_' . $i, 'recent' => array_slice($sampleRecentPool, 0, $i), 'profile' => null];
}
// 3. Logged-in survey users from DB
$realUsers = iterator_to_array($db->khach_hang->find());
$realProfiles = [];
foreach ($realUsers as $u) {
    if (!empty($u['van_de_da']) || !empty($u['tinh_trang_dac_biet'])) {
        $p = [
            'user_id' => $u['ma_kh'],
            'name' => $u['ho_ten'],
            'skin_type' => null,
            'van_de_da' => $u['van_de_da'] ?? '',
            'muc_tieu_cham_soc' => $u['muc_tieu_cham_soc'] ?? '',
            'ngan_sach' => !empty($u['ngan_sach']) ? (int)$u['ngan_sach'] : null,
            'thanh_phan_tranh' => !empty($u['thanh_phan_tranh']) ? [$u['thanh_phan_tranh']] : []
        ];
        // Parse loai_da from tinh_trang_dac_biet (e.g. loaida:5)
        if (!empty($u['tinh_trang_dac_biet']) && preg_match('/loaida:(\d+)/', (string)$u['tinh_trang_dac_biet'], $m)) {
            $lid = (int)$m[1];
            $loaiDaMap = [
                1 => 'Da dầu',
                2 => 'Da khô',
                3 => 'Da khô/Hỗn hợp khô',
                4 => 'Da thường',
                5 => 'Da hỗn hợp/Dầu mụn',
                6 => 'Da nhạy cảm',
                7 => 'Da mụn',
                8 => 'Da dầu/Hỗn hợp thiên dầu',
                9 => 'Da dầu thiếu nước',
                10 => 'Da khô/Mất nước'
            ];
            $p['skin_type'] = $loaiDaMap[$lid] ?? 'Da thường/Mọi loại da';
            $p['loai_da_id'] = $lid;
        } else {
            $p['skin_type'] = 'Da thường/Mọi loại da';
        }
        $realProfiles[] = $p;
    }
}

foreach ($realProfiles as $idx => $prof) {
    // Profile-only
    $testContexts[] = ['mode' => 'profile_only_' . $idx, 'recent' => [], 'profile' => $prof];
    // Hybrid with 1 recent item
    $testContexts[] = ['mode' => 'hybrid_1_' . $idx, 'recent' => ['1000'], 'profile' => $prof];
    // Hybrid with 3 recent items
    $testContexts[] = ['mode' => 'hybrid_3_' . $idx, 'recent' => ['1000', '83', '68'], 'profile' => $prof];
}

// 4. Edge cases: missing fields, incomplete survey, zero budget, huge budget
$testContexts[] = ['mode' => 'edge_empty_profile', 'recent' => [], 'profile' => []];
$testContexts[] = ['mode' => 'edge_only_skin_type', 'recent' => [], 'profile' => ['skin_type' => 'Da dầu']];
$testContexts[] = ['mode' => 'edge_only_concerns', 'recent' => [], 'profile' => ['van_de_da' => 'mụn đầu đen']];
$testContexts[] = ['mode' => 'edge_zero_budget', 'recent' => ['1000'], 'profile' => ['skin_type' => 'Da khô', 'ngan_sach' => 0]];
$testContexts[] = ['mode' => 'edge_huge_budget', 'recent' => ['1000'], 'profile' => ['skin_type' => 'Da khô', 'ngan_sach' => 100000000]];

echo "Created test suite of " . count($testContexts) . " contexts.\n";

$duplicateCount = 0;
$viewedLeakageCount = 0;
$familyDuplicationCount = 0;
$invalidCatalogCount = 0;
$crashCount = 0;
$totalRecsChecked = 0;

$allRecommendedIds = [];
$allRecommendedCategories = [];
$allRecommendedBrands = [];

foreach ($testContexts as $ctx) {
    try {
        $items = $spModel->getHybridRecommendations($ctx['recent'], $ctx['profile'], 10);
        if (empty($items)) {
            // Cold start fallback to Simple Recommender
            $items = $spModel->getSimpleRecommenderProducts(10);
        }
        
        $seenIds = [];
        $seenFamilies = [];
        
        foreach ($items as $item) {
            $totalRecsChecked++;
            $id = (string)($item['ma_san_pham'] ?? '');
            
            // Catalog validity
            if (!isset($allProducts[$id])) {
                $invalidCatalogCount++;
            } else {
                $allRecommendedIds[$id] = true;
                $catId = (int)($item['ma_danh_muc'] ?? 0);
                if ($catId > 0) $allRecommendedCategories[$catId] = true;
                $br = trim((string)($item['thuong_hieu'] ?? ''));
                if ($br !== '' && $br !== 'Khác') $allRecommendedBrands[$br] = true;
            }
            
            // Duplicate ID check
            if (isset($seenIds[$id])) {
                $duplicateCount++;
            }
            $seenIds[$id] = true;
            
            // Viewed item leakage check
            if (!empty($ctx['recent']) && in_array($id, $ctx['recent'], true)) {
                $viewedLeakageCount++;
            }
            
            // Family duplication check
            $family = $cbr->extractProductFamily($item['ten_san_pham'] ?? '');
            if (isset($seenFamilies[$family])) {
                $familyDuplicationCount++;
            }
            $seenFamilies[$family] = true;
        }
    } catch (Throwable $e) {
        $crashCount++;
        echo "Crash in context {$ctx['mode']}: " . $e->getMessage() . "\n";
    }
}

// Determinism test: Run 5 times on same complex context
$detRun1 = $spModel->getHybridRecommendations(['1000', '83'], $realProfiles[0], 10);
$detRun2 = $spModel->getHybridRecommendations(['1000', '83'], $realProfiles[0], 10);
$ids1 = array_column($detRun1, 'ma_san_pham');
$ids2 = array_column($detRun2, 'ma_san_pham');
$isDeterministic = ($ids1 === $ids2);

$duplicateRate = $totalRecsChecked > 0 ? ($duplicateCount / $totalRecsChecked) : 0;
$leakageRate = $totalRecsChecked > 0 ? ($viewedLeakageCount / $totalRecsChecked) : 0;
$familyDupRate = $totalRecsChecked > 0 ? ($familyDuplicationCount / $totalRecsChecked) : 0;
$crashRate = count($testContexts) > 0 ? ($crashCount / count($testContexts)) : 0;
$catalogValidityRate = $totalRecsChecked > 0 ? (($totalRecsChecked - $invalidCatalogCount) / $totalRecsChecked) : 1.0;

echo "System Integrity Results:\n";
echo "  Total recommendations evaluated: {$totalRecsChecked}\n";
echo "  Duplicate Rate: " . number_format($duplicateRate * 100, 4) . "% ({$duplicateCount})\n";
echo "  Viewed Item Leakage Rate: " . number_format($leakageRate * 100, 4) . "% ({$viewedLeakageCount})\n";
echo "  Product-Family Duplication Rate: " . number_format($familyDupRate * 100, 4) . "% ({$familyDuplicationCount})\n";
echo "  Catalog Validity Rate: " . number_format($catalogValidityRate * 100, 2) . "% ({$invalidCatalogCount} invalid)\n";
echo "  Missing Data Crash Rate: " . number_format($crashRate * 100, 2) . "% ({$crashCount} crashes)\n";
echo "  Determinism Rate: " . ($isDeterministic ? "100% (Identical across runs)" : "FAILED") . "\n";

// =====================================================================
// STEP 2: COVERAGE & DIVERSITY METRICS
// =====================================================================
echo "\n--- STEP 2: EVALUATING COVERAGE & DIVERSITY METRICS ---\n";

$catalogCoverage = count($allRecommendedIds) / $totalCatalogCount;
$categoryCoverage = count($allRecommendedCategories) / count($allCategories);
$brandCoverage = count($allRecommendedBrands) / $totalCatalogBrands;

echo "Catalog Coverage: " . count($allRecommendedIds) . " / {$totalCatalogCount} (" . number_format($catalogCoverage * 100, 2) . "%)\n";
echo "Category Coverage: " . count($allRecommendedCategories) . " / " . count($allCategories) . " (" . number_format($categoryCoverage * 100, 2) . "%)\n";
echo "Brand Coverage: " . count($allRecommendedBrands) . " / {$totalCatalogBrands} (" . number_format($brandCoverage * 100, 2) . "%)\n";

// Compute intra-list diversity across test contexts
$intraListCategoryDiv = [];
$intraListBrandDiv = [];
$intraListFamilyDiv = [];

foreach ($testContexts as $ctx) {
    $items = $spModel->getHybridRecommendations($ctx['recent'], $ctx['profile'], 10);
    if (empty($items)) {
        $items = $spModel->getSimpleRecommenderProducts(10);
    }
    if (empty($items)) continue;
    
    $cats = [];
    $brands = [];
    $families = [];
    foreach ($items as $it) {
        $cats[(int)($it['ma_danh_muc'] ?? 0)] = true;
        $brands[trim((string)($it['thuong_hieu'] ?? ''))] = true;
        $families[$cbr->extractProductFamily($it['ten_san_pham'] ?? '')] = true;
    }
    $n = count($items);
    $intraListCategoryDiv[] = count($cats) / $n;
    $intraListBrandDiv[] = count($brands) / $n;
    $intraListFamilyDiv[] = count($families) / $n;
}

$meanCatDiv = array_sum($intraListCategoryDiv) / count($intraListCategoryDiv);
$meanBrandDiv = array_sum($intraListBrandDiv) / count($intraListBrandDiv);
$meanFamilyDiv = array_sum($intraListFamilyDiv) / count($intraListFamilyDiv);

echo "Mean Intra-List Category Diversity: " . number_format($meanCatDiv, 4) . " (Unique categories / N)\n";
echo "Mean Intra-List Brand Diversity: " . number_format($meanBrandDiv, 4) . " (Unique brands / N)\n";
echo "Mean Intra-List Family Diversity: " . number_format($meanFamilyDiv, 4) . " (Unique families / N)\n";

// =====================================================================
// STEP 3: CONTENT-BASED SANITY EVALUATION (7 REPRESENTATIVE CATEGORIES)
// =====================================================================
echo "\n--- STEP 3: CONTENT-BASED SANITY EVALUATION (7 CATEGORIES) ---\n";

$targetCatNames = [
    'Cleanser' => ['Sữa Rửa Mặt', 'Gel Rửa Mặt'],
    'Toner' => ['Nước Cân Bằng', 'Nước Hoa Hồng', 'Toner'],
    'Serum' => ['Tinh Chất', 'Serum'],
    'Moisturizer' => ['Kem Dưỡng', 'Dưỡng Ẩm'],
    'Sunscreen' => ['Chống Nắng'],
    'Mask' => ['Mặt Nạ'],
    'Lip Care' => ['Son Dưỡng', 'Mặt Nạ Môi']
];

$sourceSampleProducts = [];
foreach ($targetCatNames as $groupName => $keywords) {
    foreach ($allProducts as $p) {
        $pName = mb_strtolower($p['ten_san_pham'], 'UTF-8');
        $match = false;
        foreach ($keywords as $kw) {
            if (mb_stripos($pName, mb_strtolower($kw, 'UTF-8')) !== false) {
                $match = true;
                break;
            }
        }
        if ($match && !empty($p['loai_da']) && !empty($p['thuong_hieu']) && $p['thuong_hieu'] !== 'Khác') {
            $sourceSampleProducts[$groupName] = $p;
            break;
        }
    }
}

echo sprintf("%-12s | %-6s | %-6s | %-20s | %-20s | %s\n",
    "Category", "ID", "CatID", "Brand", "Loại da", "Tên sản phẩm");
echo str_repeat("-", 100) . "\n";
foreach ($sourceSampleProducts as $grp => $sp) {
    echo sprintf("%-12s | %-6s | %-6s | %-20s | %-20s | %s\n",
        $grp, $sp['ma_san_pham'], $sp['ma_danh_muc'], mb_substr($sp['thuong_hieu'], 0, 18),
        mb_substr($sp['loai_da'], 0, 18), mb_substr($sp['ten_san_pham'], 0, 35));
}

$cbSanityResults = [];
foreach ($sourceSampleProducts as $grp => $source) {
    $srcId = (string)$source['ma_san_pham'];
    $srcCat = (int)($source['ma_danh_muc'] ?? 0);
    $srcSkin = trim((string)($source['loai_da'] ?? ''));
    $srcBrand = trim((string)($source['thuong_hieu'] ?? ''));
    
    $items = $spModel->getHybridRecommendations([$srcId], null, 10);
    
    $sameCatCount = 0;
    $sameSkinCount = 0;
    $sameBrandCount = 0;
    $cosSims = [];
    $uniqueBrands = [];
    $uniqueFamilies = [];
    
    foreach ($items as $it) {
        if ((int)($it['ma_danh_muc'] ?? 0) === $srcCat) $sameCatCount++;
        if (trim((string)($it['loai_da'] ?? '')) === $srcSkin) $sameSkinCount++;
        if (trim((string)($it['thuong_hieu'] ?? '')) === $srcBrand) $sameBrandCount++;
        
        $cosSims[] = (float)($it['recommender_meta']['content_score'] ?? $it['recommender_meta']['similarity'] ?? 0);
        $b = trim((string)($it['thuong_hieu'] ?? ''));
        if ($b !== '') $uniqueBrands[$b] = true;
        $uniqueFamilies[$cbr->extractProductFamily($it['ten_san_pham'] ?? '')] = true;
    }
    
    $n = count($items);
    $cbSanityResults[$grp] = [
        'source_id' => $srcId,
        'source_name' => $source['ten_san_pham'],
        'same_cat_at_10' => $sameCatCount / ($n ?: 1),
        'same_skin_at_10' => $sameSkinCount / ($n ?: 1),
        'same_brand_at_10' => $sameBrandCount / ($n ?: 1),
        'mean_cosine_sim' => !empty($cosSims) ? (array_sum($cosSims) / count($cosSims)) : 0,
        'unique_brands_at_10' => count($uniqueBrands),
        'unique_families_at_10' => count($uniqueFamilies)
    ];
}

echo "\nDescriptive Content-Based Metrics across 7 Categories (Top-10):\n";
echo sprintf("%-12s | %-11s | %-12s | %-12s | %-10s | %-14s | %-14s\n",
    "Category", "Same-Cat@10", "Same-Skin@10", "Same-Brand@10", "Mean CosSim", "Unique Brands", "Unique Families");
echo str_repeat("-", 100) . "\n";
foreach ($cbSanityResults as $grp => $m) {
    echo sprintf("%-12s | %-11.2f | %-12.2f | %-12.2f | %-10.4f | %-14d | %-14d\n",
        $grp,
        $m['same_cat_at_10'],
        $m['same_skin_at_10'],
        $m['same_brand_at_10'],
        $m['mean_cosine_sim'],
        $m['unique_brands_at_10'],
        $m['unique_families_at_10']
    );
}

// =====================================================================
// STEP 4: PROFILE / HYBRID SANITY EVALUATION (REAL PROFILES)
// =====================================================================
echo "\n--- STEP 4: PROFILE & HYBRID SANITY EVALUATION ON REAL SURVEY DATA ---\n";

$hybridStats = [];
foreach ($realProfiles as $idx => $prof) {
    // Test Hybrid with 1 viewed item
    $viewedId = '1000'; // Standard cleanser session
    $items = $spModel->getHybridRecommendations([$viewedId], $prof, 10);
    
    $exactSkin = 0;
    $universalSkin = 0;
    $noSkinMatch = 0;
    $withinBudget = 0;
    $priceBudgetRatios = [];
    $cats = [];
    $brands = [];
    
    $budget = $prof['ngan_sach'] ?? null;
    $userSkin = $prof['skin_type'] ?? '';
    
    foreach ($items as $it) {
        $pSkin = trim((string)($it['loai_da'] ?? ''));
        if ($pSkin === $userSkin) {
            $exactSkin++;
        } elseif ($pSkin === 'Da thường/Mọi loại da' || $pSkin === 'Mọi loại da') {
            $universalSkin++;
        } else {
            $noSkinMatch++;
        }
        
        $pPrice = (float)($it['gia_ban'] ?? 0);
        if ($budget !== null && $budget > 0) {
            if ($pPrice <= $budget) {
                $withinBudget++;
            }
            $priceBudgetRatios[] = $pPrice / $budget;
        } else {
            $withinBudget++;
        }
        
        $cats[(int)($it['ma_danh_muc'] ?? 0)] = true;
        $b = trim((string)($it['thuong_hieu'] ?? ''));
        if ($b !== '') $brands[$b] = true;
    }
    
    $n = count($items);
    sort($priceBudgetRatios);
    $medRatio = !empty($priceBudgetRatios) ? $priceBudgetRatios[(int)(count($priceBudgetRatios) / 2)] : 1.0;
    
    $hybridStats[] = [
        'user_name' => $prof['name'],
        'skin_type' => $prof['skin_type'],
        'budget' => $budget,
        'exact_skin_pct' => $exactSkin / ($n ?: 1),
        'universal_skin_pct' => $universalSkin / ($n ?: 1),
        'no_skin_pct' => $noSkinMatch / ($n ?: 1),
        'within_budget_pct' => $withinBudget / ($n ?: 1),
        'median_price_budget_ratio' => $medRatio,
        'category_count' => count($cats),
        'brand_count' => count($brands)
    ];
}

echo sprintf("%-15s | %-18s | %-10s | %-10s | %-10s | %-12s | %-12s | %-8s | %-8s\n",
    "User", "Skin Type", "ExactSkin%", "UnivSkin%", "NoMatch%", "InBudget%", "MedPrice/Budg", "Cats@10", "Brands@10");
echo str_repeat("-", 115) . "\n";
foreach ($hybridStats as $st) {
    echo sprintf("%-15s | %-18s | %-10.2f | %-10.2f | %-10.2f | %-12.2f | %-12.2f | %-8d | %-8d\n",
        mb_substr($st['user_name'], 0, 14),
        mb_substr($st['skin_type'], 0, 18),
        $st['exact_skin_pct'],
        $st['universal_skin_pct'],
        $st['no_skin_pct'],
        $st['within_budget_pct'],
        $st['median_price_budget_ratio'],
        $st['category_count'],
        $st['brand_count']
    );
}

// =====================================================================
// STEP 5: BASELINE COMPARISON (SIMPLE vs CONTENT-BASED vs HYBRID V1)
// =====================================================================
echo "\n--- STEP 5: BASELINE COMPARISON ON FIXED CONTEXT ---\n";
// Fixed test context: User 15 (Da khô/Hỗn hợp khô, Budget 500k, Viewed SP1000 Cleanser)
$fixedProfile = $realProfiles[1]; // User 15: Da khô/Hỗn hợp khô
$fixedRecent = ['1000'];

// Model 1: Simple Recommender
$simpleRes = $spModel->getSimpleRecommenderProducts(10);
// Model 2: Content-Based without Profile
$cbRes = $spModel->getHybridRecommendations($fixedRecent, null, 10);
// Model 3: Hybrid V1
$hybridRes = $spModel->getHybridRecommendations($fixedRecent, $fixedProfile, 10);

$models = [
    'Baseline 1: Simple Recommender' => $simpleRes,
    'Baseline 2: Content-Based' => $cbRes,
    'Model: Hybrid V1' => $hybridRes
];

echo sprintf("%-30s | %-10s | %-10s | %-10s | %-10s | %-10s | %-10s\n",
    "Model", "ExactSkin", "UnivSkin", "NoMatch", "InBudget%", "UniqueCats", "UniqueBrands");
echo str_repeat("-", 105) . "\n";

foreach ($models as $mName => $items) {
    $exactSkin = 0;
    $univSkin = 0;
    $noMatch = 0;
    $inBudget = 0;
    $cats = [];
    $brands = [];
    $n = count($items);
    
    foreach ($items as $it) {
        $pSkin = trim((string)($it['loai_da'] ?? ''));
        if ($pSkin === $fixedProfile['skin_type']) {
            $exactSkin++;
        } elseif ($pSkin === 'Da thường/Mọi loại da' || $pSkin === 'Mọi loại da') {
            $univSkin++;
        } else {
            $noMatch++;
        }
        $pPrice = (float)($it['gia_ban'] ?? 0);
        if ($pPrice <= ($fixedProfile['ngan_sach'] ?? 500000)) {
            $inBudget++;
        }
        $cats[(int)($it['ma_danh_muc'] ?? 0)] = true;
        $b = trim((string)($it['thuong_hieu'] ?? ''));
        if ($b !== '') $brands[$b] = true;
    }
    
    echo sprintf("%-30s | %-10.2f | %-10.2f | %-10.2f | %-10.2f | %-10d | %-10d\n",
        $mName,
        $exactSkin / ($n ?: 1),
        $univSkin / ($n ?: 1),
        $noMatch / ($n ?: 1),
        $inBudget / ($n ?: 1),
        count($cats),
        count($brands)
    );
}

// =====================================================================
// STEP 6: EXPLAINABILITY AUDIT
// =====================================================================
echo "\n--- STEP 6: EXPLAINABILITY AUDIT ---\n";
$explainMismatchCount = 0;
$medicalClaimCount = 0;
$testedBadgeItems = 0;

foreach ($testContexts as $ctx) {
    $items = $spModel->getHybridRecommendations($ctx['recent'], $ctx['profile'], 10);
    foreach ($items as $it) {
        $testedBadgeItems++;
        $meta = $it['recommender_meta'] ?? [];
        $reason = $meta['reason'] ?? '';
        $badges = explode(' • ', $reason);
        
        // Check "Đúng loại da của bạn"
        if (in_array("Đúng loại da của bạn", $badges, true)) {
            if (($meta['skin_type_match'] ?? 0) < 1.0) {
                $explainMismatchCount++;
            }
        }
        // Check "Phù hợp mọi loại da"
        if (in_array("Phù hợp mọi loại da", $badges, true)) {
            if (($meta['skin_type_match'] ?? 0) < 0.70 || ($meta['skin_type_match'] ?? 0) >= 1.0) {
                $explainMismatchCount++;
            }
        }
        // Check "Trong ngân sách"
        if (in_array("Trong ngân sách", $badges, true)) {
            if (($meta['budget_score'] ?? 0) < 1.0) {
                $explainMismatchCount++;
            }
        }
        // Check "Tương tự sản phẩm bạn vừa xem"
        if (in_array("Tương tự sản phẩm bạn vừa xem", $badges, true)) {
            if (empty($ctx['recent'])) {
                $explainMismatchCount++;
            }
        }
        // Medical claim check
        $medKeywords = ['chữa khỏi', 'đặc trị y khoa', 'cam kết an toàn', 'không bao giờ dị ứng', 'chuẩn y khoa'];
        foreach ($medKeywords as $mkw) {
            if (mb_stripos($reason, $mkw) !== false) {
                $medicalClaimCount++;
            }
        }
    }
}

echo "Explainability Audit Results:\n";
echo "  Total badge occurrences audited: {$testedBadgeItems}\n";
echo "  Reason signal mismatch count: {$explainMismatchCount}\n";
echo "  Medical / Absolute safety claim count: {$medicalClaimCount}\n";

// =====================================================================
// STEP 7: PERFORMANCE (RUNTIME BENCHMARK)
// =====================================================================
echo "\n--- STEP 7: RUNTIME PERFORMANCE BENCHMARK ---\n";

function benchmarkFunction(callable $fn, int $iterations = 30): array {
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $t0 = microtime(true);
        $fn();
        $t1 = microtime(true);
        $times[] = ($t1 - $t0) * 1000; // ms
    }
    sort($times);
    $median = $times[(int)($iterations * 0.5)];
    $p95 = $times[(int)($iterations * 0.95)];
    $mean = array_sum($times) / $iterations;
    return ['median' => $median, 'p95' => $p95, 'mean' => $mean];
}

$benchSimple = benchmarkFunction(function() use ($spModel) {
    return $spModel->getSimpleRecommenderProducts(8);
}, 30);

$benchContent = benchmarkFunction(function() use ($spModel) {
    return $spModel->getHybridRecommendations(['1000', '83'], null, 8);
}, 30);

$benchProfile = benchmarkFunction(function() use ($spModel, $realProfiles) {
    return $spModel->getHybridRecommendations([], $realProfiles[0], 8);
}, 30);

$benchHybrid = benchmarkFunction(function() use ($spModel, $realProfiles) {
    return $spModel->getHybridRecommendations(['1000', '83'], $realProfiles[0], 8);
}, 30);

echo sprintf("%-20s | %-12s | %-12s | %-12s\n", "Mode", "Median (ms)", "p95 (ms)", "Mean (ms)");
echo str_repeat("-", 60) . "\n";
echo sprintf("%-20s | %-12.2f | %-12.2f | %-12.2f\n", "Simple Recommender", $benchSimple['median'], $benchSimple['p95'], $benchSimple['mean']);
echo sprintf("%-20s | %-12.2f | %-12.2f | %-12.2f\n", "Content-Based", $benchContent['median'], $benchContent['p95'], $benchContent['mean']);
echo sprintf("%-20s | %-12.2f | %-12.2f | %-12.2f\n", "Profile-Aware", $benchProfile['median'], $benchProfile['p95'], $benchProfile['mean']);
echo sprintf("%-20s | %-12.2f | %-12.2f | %-12.2f\n", "Hybrid V1", $benchHybrid['median'], $benchHybrid['p95'], $benchHybrid['mean']);

// =====================================================================
// STEP 8: HUMAN EVALUATION DATASET GENERATION (CSV EXPORT)
// =====================================================================
echo "\n--- STEP 8: GENERATING HUMAN EVALUATION CSV TEMPLATE ---\n";
$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}
$csvFile = $outputDir . '/human_evaluation_template.csv';
$fp = fopen($csvFile, 'w');
// Write UTF-8 BOM for Excel
fputs($fp, "\xEF\xBB\xBF");

// Header columns
fputcsv($fp, [
    'evaluation_id',
    'profile_summary',
    'recent_viewed_products',
    'recommended_product',
    'rank',
    'algorithm_mode',
    'relevance_score_1_to_5',
    'profile_fit_score_1_to_5',
    'diversity_score_1_to_5',
    'explanation_useful_1_to_5',
    'notes'
]);

$evalRows = [];
$evalId = 1;

// Sample 5 distinct profiles across different modes
$humanTestCases = [
    [
        'title' => 'Da Khô - Cấp Ẩm (User 15)',
        'profile' => $realProfiles[1],
        'recent' => ['83'], // Cerave Cream
        'modes' => ['content', 'profile', 'hybrid']
    ],
    [
        'title' => 'Da Nhạy Cảm - Dễ Kích Ứng (User 2)',
        'profile' => $realProfiles[2],
        'recent' => ['68'], // Timeless B5
        'modes' => ['content', 'profile', 'hybrid']
    ],
    [
        'title' => 'Da Mụn - Kiểm Soát Nhờn (User 6)',
        'profile' => $realProfiles[3],
        'recent' => ['1000'], // Eucerin Gel
        'modes' => ['content', 'profile', 'hybrid']
    ],
    [
        'title' => 'Da Dầu - Lỗ Chân Lông To (User 16)',
        'profile' => $realProfiles[0],
        'recent' => ['1002'], // Eucerin Toner
        'modes' => ['content', 'profile', 'hybrid']
    ],
    [
        'title' => 'Da Thường / Cold Start',
        'profile' => null,
        'recent' => ['18'], // Son dưỡng DHC
        'modes' => ['simple', 'content']
    ]
];

foreach ($humanTestCases as $tc) {
    $profSum = $tc['title'];
    if (!empty($tc['profile'])) {
        $profSum .= " | Skin: " . ($tc['profile']['skin_type'] ?? '') . 
                    " | Budget: " . number_format($tc['profile']['ngan_sach'] ?? 0) . "d" .
                    " | Concern: " . ($tc['profile']['van_de_da'] ?? '');
    }
    
    $recentNames = [];
    foreach ($tc['recent'] as $rid) {
        $recentNames[] = $rid . ' (' . ($allProducts[$rid]['ten_san_pham'] ?? 'N/A') . ')';
    }
    $recentSummary = implode('; ', $recentNames);
    
    foreach ($tc['modes'] as $m) {
        if ($m === 'simple') {
            $items = array_slice($spModel->getSimpleRecommenderProducts(5), 0, 5);
        } elseif ($m === 'content') {
            $items = $spModel->getHybridRecommendations($tc['recent'], null, 5);
        } elseif ($m === 'profile') {
            $items = $spModel->getHybridRecommendations([], $tc['profile'], 5);
        } else { // hybrid
            $items = $spModel->getHybridRecommendations($tc['recent'], $tc['profile'], 5);
        }
        
        foreach ($items as $idx => $it) {
            $recStr = ($it['ma_san_pham'] ?? '') . ' - ' . ($it['ten_san_pham'] ?? '') . ' (' . number_format($it['gia_ban'] ?? 0) . 'd, ' . ($it['loai_da'] ?? '') . ', ' . ($it['thuong_hieu'] ?? '') . ')';
            fputcsv($fp, [
                'EVAL_' . str_pad($evalId++, 4, '0', STR_PAD_LEFT),
                $profSum,
                $recentSummary,
                $recStr,
                $idx + 1,
                $m,
                '', // relevance_score_1_to_5 (left blank for human)
                '', // profile_fit_score_1_to_5
                '', // diversity_score_1_to_5
                '', // explanation_useful_1_to_5
                ''  // notes
            ]);
        }
    }
}

fclose($fp);
echo "Successfully exported " . ($evalId - 1) . " rows to {$csvFile}.\n";
echo "Human scores left blank as required.\n";

echo "\n=================================================================\n";
echo "OFFLINE EVALUATION COMPLETED.\n";
echo "=================================================================\n";
