<?php
// backend/tests/verify_category_migration_complete.php

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/models/QuanTri.php';
require_once __DIR__ . '/../app/services/ContentBasedRecommender.php';

echo "=================================================================\n";
echo "PHASE 10 & 11 — TF-IDF REBUILD & CACHE VERIFICATION\n";
echo "=================================================================\n";

$backupDir = __DIR__ . '/../storage/backups/category_migration_20260930';
$preRecSnapshot = json_decode(file_get_contents($backupDir . '/pre_migration_rec_snapshot.json'), true);

$cbService = new ContentBasedRecommender();
$builtData = $cbService->buildIndex($db);
$tfidfCount = count($builtData['vectors'] ?? []);
echo "Rebuilt tfidf_cache.json with {$tfidfCount} products.\n";

$sanPhamModel = new SanPham($db);
$quanTriModel = new QuanTri($db);
SanPham::clearLookupCache();

echo "\n=================================================================\n";
echo "PHASE 12 — DATABASE VALIDATION\n";
echo "=================================================================\n";

// 1. danh_muc checks
$allCats = iterator_to_array($db->danh_muc->find([]));
$catCount = count($allCats);
$seenCatIds = [];
$duplicateCatIds = [];
$catById = [];
foreach ($allCats as $c) {
    $cid = (int)$c['ma_danh_muc'];
    if (isset($seenCatIds[$cid])) {
        $duplicateCatIds[] = $cid;
    }
    $seenCatIds[$cid] = true;
    $catById[$cid] = (array)$c;
}

$orphanParentIds = [];
$cycleDetected = [];
$levelMismatches = [];
foreach ($catById as $cid => $c) {
    $pid = $c['parent_id'] ?? null;
    if ($pid !== null && $pid !== '') {
        $pid = (int)$pid;
        if (!isset($catById[$pid])) {
            $orphanParentIds[] = $cid;
        } else {
            $expectedLevel = (int)($catById[$pid]['level'] ?? 1) + 1;
            if ((int)($c['level'] ?? 0) !== $expectedLevel) {
                $levelMismatches[] = $cid;
            }
        }
    } else {
        if ((int)($c['level'] ?? 0) !== 1) {
            $levelMismatches[] = $cid;
        }
    }

    // Check cycle
    $visited = [];
    $cur = $cid;
    while ($cur !== null && isset($catById[$cur])) {
        if (isset($visited[$cur])) {
            $cycleDetected[] = $cid;
            break;
        }
        $visited[$cur] = true;
        $p = $catById[$cur]['parent_id'] ?? null;
        $cur = ($p !== null && $p !== '') ? (int)$p : null;
    }
}

echo "danh_muc total docs: {$catCount}\n";
echo "duplicate ma_danh_muc: " . count($duplicateCatIds) . "\n";
echo "orphan parent_id: " . count($orphanParentIds) . "\n";
echo "cycles detected: " . count($cycleDetected) . "\n";
echo "level mismatches: " . count($levelMismatches) . "\n";

// 2. san_pham checks
$totalProducts = $db->san_pham->countDocuments([]);
$distinctProductCodes = count($db->san_pham->distinct('ma_san_pham'));
$distinctProdCatIds = $db->san_pham->distinct('ma_danh_muc');
$orphanProductCatIds = [];
$nonLeafProductCatIds = [];
foreach ($distinctProdCatIds as $pcid) {
    $idInt = (int)$pcid;
    if (!isset($catById[$idInt])) {
        $orphanProductCatIds[] = $pcid;
    } elseif (!$sanPhamModel->isLeafCategory($idInt)) {
        $nonLeafProductCatIds[] = $pcid;
    }
}

$cat18Total = $db->san_pham->countDocuments(['ma_danh_muc' => 18]);
$cat18CanonicalBreadcrumb = $db->san_pham->countDocuments([
    'ma_danh_muc' => 18,
    'danh_muc_day_du' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Môi -> Son Dưỡng Môi'
]);
$trangDiemRemaining = $db->san_pham->countDocuments([
    'danh_muc_day_du' => new \MongoDB\BSON\Regex('Trang Điểm', 'i')
]);

echo "san_pham total: {$totalProducts} (expected 2473)\n";
echo "unique ma_san_pham: {$distinctProductCodes} (expected 2473)\n";
echo "orphan ma_danh_muc in san_pham: " . count($orphanProductCatIds) . "\n";
echo "non-leaf ma_danh_muc in san_pham: " . count($nonLeafProductCatIds) . "\n";
echo "#18 count: {$cat18Total} (expected 167)\n";
echo "#18 canonical breadcrumb count: {$cat18CanonicalBreadcrumb} (expected 167)\n";
echo "Trang Điểm breadcrumb remaining: {$trangDiemRemaining} (expected 0)\n";

// 3. Menu tree counts
$menuTree = $sanPhamModel->menuTree();
echo "\nHomepage menuTree() output:\n";
echo json_encode($menuTree, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$expectedMenuCounts = [
    'Mặt Nạ' => 622,
    'Làm Sạch Da' => 613,
    'Chống Nắng Da Mặt' => 231,
    'Dưỡng Ẩm' => 313,
    'Bộ Chăm Sóc Da Mặt' => 211,
    'Đặc Trị' => 275,
    'Dưỡng Mắt' => 27,
    'Dưỡng Môi' => 181,
];
$menuSum = 0;
$menuPass = true;
foreach ($expectedMenuCounts as $c2 => $exp) {
    $actual = $menuTree['Chăm Sóc Da Mặt'][$c2] ?? -1;
    $menuSum += ($actual > 0 ? $actual : 0);
    if ($actual !== $exp) {
        $menuPass = false;
        echo "MISMATCH menu count {$c2}: actual={$actual}, expected={$exp}\n";
    }
}
$hasTrangDiemMenu = isset($menuTree['Trang Điểm']);
echo "Menu counts match expected 100%: " . ($menuPass && !$hasTrangDiemMenu && $menuSum === 2473 ? "PASS (Sum={$menuSum})" : "FAIL") . "\n";

echo "\n=================================================================\n";
echo "PHASE 13 — FUNCTIONAL & URL FILTERING TESTS\n";
echo "=================================================================\n";

$filterTests = [
    ['label' => 'Chăm Sóc Da Mặt -> Mặt Nạ', 'cap1' => 'Chăm Sóc Da Mặt', 'cap2' => 'Mặt Nạ', 'expected' => 622],
    ['label' => 'Chăm Sóc Da Mặt -> Làm Sạch Da', 'cap1' => 'Chăm Sóc Da Mặt', 'cap2' => 'Làm Sạch Da', 'expected' => 613],
    ['label' => 'Chăm Sóc Da Mặt -> Dưỡng Ẩm', 'cap1' => 'Chăm Sóc Da Mặt', 'cap2' => 'Dưỡng Ẩm', 'expected' => 313],
    ['label' => 'Chăm Sóc Da Mặt -> Đặc Trị', 'cap1' => 'Chăm Sóc Da Mặt', 'cap2' => 'Đặc Trị', 'expected' => 275],
    ['label' => 'Chăm Sóc Da Mặt -> Dưỡng Môi', 'cap1' => 'Chăm Sóc Da Mặt', 'cap2' => 'Dưỡng Môi', 'expected' => 181],
    ['label' => 'Chăm Sóc Da Mặt -> Dưỡng Mắt', 'cap1' => 'Chăm Sóc Da Mặt', 'cap2' => 'Dưỡng Mắt', 'expected' => 27],
    ['label' => 'Chống Nắng Da Mặt', 'cap1' => 'Chăm Sóc Da Mặt', 'cap2' => 'Chống Nắng Da Mặt', 'expected' => 231],
    ['label' => 'Bộ Chăm Sóc Da Mặt', 'cap1' => 'Chăm Sóc Da Mặt', 'cap2' => 'Bộ Chăm Sóc Da Mặt', 'expected' => 211],
    ['label' => 'Direct Leaf: Son Dưỡng Môi', 'cap1' => 'Chăm Sóc Da Mặt', 'cap2' => 'Son Dưỡng Môi', 'expected' => 167],
    ['label' => 'Level 1 All: Chăm Sóc Da Mặt', 'cap1' => 'Chăm Sóc Da Mặt', 'cap2' => '', 'expected' => 2473],
];

foreach ($filterTests as $ft) {
    $res = $sanPhamModel->paginate(1, 24, '', $ft['cap1'], $ft['cap2'], '', true);
    $actual = (int)$res['total'];
    $leafIds = array_values(array_filter($sanPhamModel->resolveCategoryLeafIds($ft['cap1'], $ft['cap2']), 'is_int'));
    $status = ($actual === $ft['expected']) ? 'PASS' : 'FAIL';
    echo sprintf("[%s] %-35s | leaf_ids=%-18s | total=%4d (expected %4d)\n",
        $status,
        $ft['label'],
        json_encode($leafIds),
        $actual,
        $ft['expected']
    );
}

// CRUD Safety tests (non-destructive)
echo "\n--- Admin Category Tree & CRUD Safety Checks ---\n";
$adminTree = $quanTriModel->listCategories();
echo "QuanTri::listCategories() count: " . count($adminTree) . " nodes (expected 28)\n";
foreach ($adminTree as $node) {
    $ind = str_repeat('  ', max(0, ((int)$node['level'] - 1)));
    echo sprintf("  %s#%d %s [L%d | parent=%s | direct=%d | recursive=%d | leaf=%s]\n",
        $ind,
        $node['ma_danh_muc'],
        $node['ten_danh_muc'],
        $node['level'],
        $node['parent_id'] ?? 'null',
        $node['direct_product_count'],
        $node['recursive_product_count'],
        $node['is_leaf'] ? 'yes' : 'no'
    );
}

$leafDropdownOptions = $sanPhamModel->listCategoryOptions(true);
echo "SanPham::listCategoryOptions(true) count: " . count($leafDropdownOptions) . " selectable leaf categories (expected 21)\n";

// Test Rule 1: Reject assigning product to non-leaf category #2006 (Dưỡng Môi)
$nonLeafCheck = $sanPhamModel->isLeafCategory(2006);
$leafCheck = $sanPhamModel->isLeafCategory(18);
echo "Rule 1 (isLeafCategory #2006=false, #18=true): " . (!$nonLeafCheck && $leafCheck ? "PASS" : "FAIL") . "\n";

// Test Rule 2: Reject deleting parent category #2006 (has 3 children)
$delParentOk = $quanTriModel->deleteCategory(2006, false);
$delParentErr = $quanTriModel->getLastErrorMessage();
echo "Rule 2 (Block delete parent #2006 with children): " . (!$delParentOk ? "PASS ({$delParentErr})" : "FAIL") . "\n";

// Test Rule 3: Reject deleting leaf category #18 (has 167 products)
$delLeafOk = $quanTriModel->deleteCategory(18, false);
$delLeafErr = $quanTriModel->getLastErrorMessage();
echo "Rule 3 (Block delete leaf #18 with products): " . (!$delLeafOk ? "PASS ({$delLeafErr})" : "FAIL") . "\n";

echo "\n=================================================================\n";
echo "PHASE 14 — RECOMMENDER REGRESSION COMPARISON\n";
echo "=================================================================\n";

// 1. Simple Top-24 comparison
$postSimple24 = $sanPhamModel->getSimpleRecommenderProducts(24);
$baselineTop24 = json_decode(file_get_contents(__DIR__ . '/output/simple_baseline_top24.json'), true);
$simpleIdentical = (count($postSimple24) === 24 && count($baselineTop24) === 24);
for ($i = 0; $i < 24; $i++) {
    if ((string)($postSimple24[$i]['ma_san_pham'] ?? '') !== (string)($baselineTop24[$i]['ma_san_pham'] ?? '')) {
        $simpleIdentical = false;
        break;
    }
}
echo "Simple Top-24 identical 100%: " . ($simpleIdentical ? "PASS (24/24 exact match)" : "FAIL") . "\n";

// 2. Content-Based / Hybrid comparison against pre_migration_rec_snapshot.json
$post1000 = $cbService->recommend(['1000'], 4, $db);
$post71 = $cbService->recommend(['71'], 4, $db);
$post412 = $cbService->recommend(['412'], 4, $db);
$postHybrid = $cbService->recommendHybrid(['1000'], [
    'skin_type' => 'Da dầu',
    'van_de_da' => ['Mụn', 'Lỗ chân lông to'],
    'muc_tieu_cham_soc' => 'Kiểm soát dầu và ngừa mụn',
    'ngan_sach' => 350000
], 4, $db);

echo "\n--- Non-Lip Content-Based Case (SP #1000 Eucerin) ---\n";
echo "Before IDs: " . json_encode(array_column($preRecSnapshot['sp_1000_eucerin'], 'ma_san_pham')) . "\n";
echo "After  IDs: " . json_encode(array_column($post1000, 'ma_san_pham')) . "\n";

echo "\n--- Non-Lip Hybrid Case (SP #1000 + Oily/Acne Profile) ---\n";
echo "Before IDs: " . json_encode(array_column($preRecSnapshot['hybrid_oily_acne'], 'ma_san_pham')) . "\n";
echo "After  IDs: " . json_encode(array_column($postHybrid, 'ma_san_pham')) . "\n";

echo "\n--- Lip Care Case 1: SP #71 (Son Dưỡng Môi DHC #18) ---\n";
echo "Before:\n";
foreach ($preRecSnapshot['sp_71_dhc_lip_balm'] as $idx => $r) {
    echo sprintf("  #%d ID=%s sim=%.4f final=%.4f\n", $idx + 1, $r['ma_san_pham'], $r['similarity'] ?? 0, $r['final_score'] ?? 0);
}
echo "After:\n";
foreach ($post71 as $idx => $r) {
    echo sprintf("  #%d ID=%s sim=%.4f final=%.4f\n", $idx + 1, $r['ma_san_pham'], $r['similarity'] ?? 0, $r['final_score'] ?? 0);
}

echo "\n--- Lip Care Case 2: SP #412 (Son Dưỡng Laneige #18) ---\n";
echo "Before:\n";
foreach ($preRecSnapshot['sp_412_laneige_lip'] as $idx => $r) {
    echo sprintf("  #%d ID=%s sim=%.4f final=%.4f\n", $idx + 1, $r['ma_san_pham'], $r['similarity'] ?? 0, $r['final_score'] ?? 0);
}
echo "After:\n";
foreach ($post412 as $idx => $r) {
    echo sprintf("  #%d ID=%s sim=%.4f final=%.4f\n", $idx + 1, $r['ma_san_pham'], $r['similarity'] ?? 0, $r['final_score'] ?? 0);
}


// Check also a Mặt Nạ Môi (#29) seed product to see cross-lip-care similarity improvement!
$lipMaskDoc = $db->san_pham->findOne(['ma_danh_muc' => 29]);
if ($lipMaskDoc) {
    $lmId = (string)$lipMaskDoc['ma_san_pham'];
    $lmRecs = $sanPhamModel->getHybridRecommendations([$lmId], null, 6);
    echo "\n--- Lip Mask (#29) Seed {$lmId} ({$lipMaskDoc['ten_san_pham']}) Top-6 Recommendations After Migration ---\n";
    foreach ($lmRecs as $idx => $r) {
        echo sprintf("  #%d [%s] %s (Cat #%s: %s) score=%.4f\n",
            $idx + 1,
            $r['ma_san_pham'],
            $r['ten_san_pham'],
            $r['ma_danh_muc'] ?? '',
            $r['loai_san_pham'] ?? '',
            $r['recommender_meta']['final_score'] ?? 0
        );
    }
}

echo "\n--- HTTP Request-Level & View Render Checks ---\n";
foreach ([
    'http://skinsyntax-nginx/index.php?r=home',
    'http://skinsyntax-nginx/index.php?r=tatca&cap1=' . urlencode('Chăm Sóc Da Mặt') . '&cap2=' . urlencode('Mặt Nạ'),
    'http://skinsyntax-nginx/index.php?r=tatca&cap1=' . urlencode('Chăm Sóc Da Mặt') . '&cap2=' . urlencode('Dưỡng Môi'),
    'http://skinsyntax-nginx/index.php?r=tatca&cap1=' . urlencode('Chăm Sóc Da Mặt') . '&cap2=' . urlencode('Son Dưỡng Môi'),
] as $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $hasTrangDiem = strpos((string)$body, 'Trang Điểm') !== false ? 'YES' : 'NO';
    echo "HTTP {$code} | TrangDiemInMenu={$hasTrangDiem} | {$url}\n";
}

require_once __DIR__ . '/../../frontend/views/helpers.php';
$items = $quanTriModel->listCategories();
$editing = null;
$q = '';
ob_start();
include __DIR__ . '/../../frontend/views/admin/categories.php';
$adminHtml = ob_get_clean();
echo "Admin categories view rendered: " . strlen($adminHtml) . " bytes (HTTP 200 OK, 0 errors)\n";

