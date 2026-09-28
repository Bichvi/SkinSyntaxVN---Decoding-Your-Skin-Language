<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

echo "=================================================================\n";
echo "STEP 1: AUDIT USER DATA\n";
echo "=================================================================\n";

$cntKhachHang = $db->khach_hang->countDocuments();
$cntNguoiDung = $db->nguoidung->countDocuments();
$cntNhanVien = $db->nhan_vien->countDocuments();

echo "Collection khach_hang: $cntKhachHang documents\n";
echo "Collection nguoidung: $cntNguoiDung documents\n";
echo "Collection nhan_vien: $cntNhanVien documents\n";

$khDocs = iterator_to_array($db->khach_hang->find());
$khIds = [];
$khEmails = [];
foreach ($khDocs as $doc) {
    if (isset($doc['ma_kh'])) $khIds[] = $doc['ma_kh'];
    if (isset($doc['email'])) $khEmails[] = strtolower(trim($doc['email']));
}
echo "khach_hang unique ma_kh: " . count(array_unique($khIds)) . " (min: " . min($khIds) . ", max: " . max($khIds) . ")\n";
echo "khach_hang unique emails: " . count(array_unique($khEmails)) . "\n";

$ndDocs = iterator_to_array($db->nguoidung->find());
$ndEmails = [];
$ndRoles = [];
foreach ($ndDocs as $doc) {
    if (isset($doc['email'])) $ndEmails[] = strtolower(trim($doc['email']));
    $role = $doc['vai_tro'] ?? 'customer';
    $ndRoles[$role] = ($ndRoles[$role] ?? 0) + 1;
}
echo "nguoidung unique emails: " . count(array_unique($ndEmails)) . "\n";
echo "nguoidung roles: " . json_encode($ndRoles) . "\n";

echo "\n=================================================================\n";
echo "STEP 2 & 3: AUDIT ORDERS (hoa_don & chi_tiet_hoa_don)\n";
echo "=================================================================\n";

$orderDocs = iterator_to_array($db->hoa_don->find());
$orderItemDocs = iterator_to_array($db->chi_tiet_hoa_don->find());

$totalOrders = count($orderDocs);
$totalLineItems = count($orderItemDocs);

echo "Total orders in hoa_don: $totalOrders\n";
echo "Total line items in chi_tiet_hoa_don: $totalLineItems\n";

// Order statuses
$orderStatuses = [];
$orderPaymentStatuses = [];
$orderUserIds = [];
$orderMap = [];
foreach ($orderDocs as $od) {
    $maHd = (int)($od['ma_hoa_don'] ?? 0);
    $orderMap[$maHd] = $od;
    $st = $od['trang_thai'] ?? $od['status_don_hang'] ?? 'unknown';
    $pst = $od['status_thanh_toan'] ?? 'unknown';
    $orderStatuses[$st] = ($orderStatuses[$st] ?? 0) + 1;
    $orderPaymentStatuses[$pst] = ($orderPaymentStatuses[$pst] ?? 0) + 1;
    if (isset($od['ma_kh']) && is_numeric($od['ma_kh']) && (int)$od['ma_kh'] > 0) {
        $orderUserIds[] = (int)$od['ma_kh'];
    }
}
echo "Order status breakdown: " . json_encode($orderStatuses, JSON_UNESCAPED_UNICODE) . "\n";
echo "Payment status breakdown: " . json_encode($orderPaymentStatuses, JSON_UNESCAPED_UNICODE) . "\n";
echo "Orders with valid ma_kh: " . count($orderUserIds) . " (Unique users: " . count(array_unique($orderUserIds)) . ")\n";

// Items analysis
$purchasedProducts = [];
$orderToProducts = [];
$userToPurchasedProducts = [];
$invalidOrderFk = 0;
$invalidProductFk = 0;

// Load active products in DB
$activeProductIds = [];
foreach ($db->san_pham->find([], ['projection' => ['ma_san_pham' => 1]]) as $p) {
    $activeProductIds[(int)$p['ma_san_pham']] = true;
}

foreach ($orderItemDocs as $item) {
    $maHd = (int)($item['ma_hoa_don'] ?? 0);
    $maSp = (int)($item['ma_san_pham'] ?? 0);

    if (!isset($orderMap[$maHd])) {
        $invalidOrderFk++;
    }

    if (!isset($activeProductIds[$maSp])) {
        $invalidProductFk++;
    }

    $purchasedProducts[] = $maSp;
    $orderToProducts[$maHd][] = $maSp;

    $orderUser = isset($orderMap[$maHd]['ma_kh']) ? (int)$orderMap[$maHd]['ma_kh'] : 0;
    if ($orderUser > 0) {
        $userToPurchasedProducts[$orderUser][] = $maSp;
    }
}

$uniquePurchasedProducts = array_unique($purchasedProducts);
echo "Unique products purchased: " . count($uniquePurchasedProducts) . "\n";
echo "Line items linking to valid hoa_don: " . ($totalLineItems - $invalidOrderFk) . " / $totalLineItems (Orphan: $invalidOrderFk)\n";
echo "Line items linking to existing skincare catalog (2473): " . ($totalLineItems - $invalidProductFk) . " / $totalLineItems (Not in catalog: $invalidProductFk)\n";

$itemsPerOrder = array_map(fn($items) => count($items), $orderToProducts);
$avgItemsPerOrder = !empty($itemsPerOrder) ? array_sum($itemsPerOrder) / count($itemsPerOrder) : 0;
echo "Average line items per order: " . round($avgItemsPerOrder, 2) . " (Min: " . (!empty($itemsPerOrder)?min($itemsPerOrder):0) . ", Max: " . (!empty($itemsPerOrder)?max($itemsPerOrder):0) . ")\n";

// Purchases per user
echo "\nPurchases per user distribution:\n";
$userPurchaseCounts = array_map(fn($arr) => count($arr), $userToPurchasedProducts);
arsort($userPurchaseCounts);
foreach ($userPurchaseCounts as $uId => $cnt) {
    echo "  User $uId: $cnt purchased items\n";
}

// Purchases per product
$productPurchaseCounts = array_count_values($purchasedProducts);
arsort($productPurchaseCounts);
echo "Top purchased products:\n";
foreach (array_slice($productPurchaseCounts, 0, 5, true) as $pId => $cnt) {
    $inCatalog = isset($activeProductIds[$pId]) ? 'In Skincare Catalog' : 'NOT in Skincare Catalog';
    echo "  Product $pId: $cnt purchases ($inCatalog)\n";
}

echo "\n=================================================================\n";
echo "STEP 4: AUDIT RATINGS (danh_gia & danh_gia_san_pham)\n";
echo "=================================================================\n";

$ratingsCol1 = iterator_to_array($db->danh_gia->find());
$ratingsCol2 = iterator_to_array($db->danh_gia_san_pham->find());

echo "danh_gia: " . count($ratingsCol1) . " documents\n";
echo "danh_gia_san_pham: " . count($ratingsCol2) . " documents\n";

echo "\n--- danh_gia sample details ---\n";
$usersD1 = [];
$prodsD1 = [];
$starsD1 = [];
foreach ($ratingsCol1 as $r) {
    $u = $r['ma_kh'] ?? 'unknown';
    $p = $r['ma_san_pham'] ?? 'unknown';
    $s = $r['so_sao'] ?? 0;
    $usersD1[] = $u;
    $prodsD1[] = $p;
    $starsD1[] = $s;
    echo "  ma_kh: $u, ma_san_pham: $p, so_sao: $s, ngay: " . (isset($r['ngay_danh_gia']) ? 'has_ts' : 'no_ts') . "\n";
}
echo "danh_gia unique users: " . count(array_unique($usersD1)) . ", unique products: " . count(array_unique($prodsD1)) . "\n";

echo "\n--- danh_gia_san_pham sample details ---\n";
$usersD2 = [];
$prodsD2 = [];
$starsD2 = [];
foreach ($ratingsCol2 as $r) {
    $u = $r['ma_khach_hang'] ?? 'unknown';
    $p = $r['ma_san_pham'] ?? 'unknown';
    $s = $r['so_sao'] ?? 0;
    $usersD2[] = $u;
    $prodsD2[] = $p;
    $starsD2[] = $s;
    echo "  ma_khach_hang: $u, ma_san_pham: $p, so_sao: $s, ngay: " . (isset($r['ngay_danh_gia']) ? 'has_ts' : 'no_ts') . "\n";
}
echo "danh_gia_san_pham unique users: " . count(array_unique($usersD2)) . ", unique products: " . count(array_unique($prodsD2)) . "\n";

echo "\n=================================================================\n";
echo "STEP 5: OTHER IMPLICIT SIGNALS\n";
echo "=================================================================\n";

$cntTimKiem = $db->lich_su_tim_kiem->countDocuments();
$cntChat = $db->lich_su_chat->countDocuments();
$cntLiveChat = $db->phien_live_chats->countDocuments();
$cntHoiDap = $db->hoi_dap_san_pham->countDocuments();

echo "lich_su_tim_kiem: $cntTimKiem documents\n";
echo "lich_su_chat: $cntChat documents\n";
echo "phien_live_chats: $cntLiveChat documents\n";
echo "hoi_dap_san_pham: $cntHoiDap documents\n";

echo "\n=================================================================\n";
echo "STEP 6: COMBINED REAL USER-ITEM INTERACTION MATRIX\n";
echo "=================================================================\n";

// Gather all REAL user-item interaction pairs (Orders line items + Ratings)
$interactions = []; // [user_id => [prod_id => count]]

// From Chi tiết hóa đơn
foreach ($orderItemDocs as $item) {
    $maHd = (int)($item['ma_hoa_don'] ?? 0);
    $maSp = (int)($item['ma_san_pham'] ?? 0);
    $maKh = isset($orderMap[$maHd]['ma_kh']) ? (int)$orderMap[$maHd]['ma_kh'] : 0;
    if ($maKh > 0 && $maSp > 0) {
        $interactions[$maKh][$maSp] = ($interactions[$maKh][$maSp] ?? 0) + 1;
    }
}

// From danh_gia
foreach ($ratingsCol1 as $r) {
    $u = isset($r['ma_kh']) && is_numeric($r['ma_kh']) ? (int)$r['ma_kh'] : 0;
    $p = isset($r['ma_san_pham']) && is_numeric($r['ma_san_pham']) ? (int)$r['ma_san_pham'] : 0;
    if ($u > 0 && $p > 0) {
        $interactions[$u][$p] = ($interactions[$u][$p] ?? 0) + 1;
    }
}

// From danh_gia_san_pham
foreach ($ratingsCol2 as $r) {
    $u = isset($r['ma_khach_hang']) && is_numeric($r['ma_khach_hang']) ? (int)$r['ma_khach_hang'] : 0;
    $p = isset($r['ma_san_pham']) && is_numeric($r['ma_san_pham']) ? (int)$r['ma_san_pham'] : 0;
    if ($u > 0 && $p > 0) {
        $interactions[$u][$p] = ($interactions[$u][$p] ?? 0) + 1;
    }
}

$allUsersWithInteractions = array_keys($interactions);
$allProductsWithInteractions = [];
$totalDistinctPairs = 0;
$totalInteractionEvents = 0;

$userInteractionsCount = [];
$productInteractionsCount = [];

foreach ($interactions as $u => $pList) {
    $userInteractionsCount[$u] = array_sum($pList);
    foreach ($pList as $p => $cnt) {
        $allProductsWithInteractions[$p] = true;
        $totalDistinctPairs++;
        $totalInteractionEvents += $cnt;
        $productInteractionsCount[$p] = ($productInteractionsCount[$p] ?? 0) + $cnt;
    }
}

$numUsers = count($allUsersWithInteractions);
$numProducts = count($allProductsWithInteractions);
$numTotalCatalog = 2473;

echo "Unique users with ANY interaction: $numUsers\n";
echo "Unique products with ANY interaction: $numProducts (out of $numTotalCatalog skincare catalog)\n";
echo "Total interaction events: $totalInteractionEvents (Distinct user-item pairs: $totalDistinctPairs)\n";

$matrixDimLocal = $numUsers * $numProducts;
$densityLocal = $matrixDimLocal > 0 ? ($totalDistinctPairs / $matrixDimLocal) : 0;
$sparsityLocal = 1.0 - $densityLocal;

$matrixDimGlobal = $numUsers * $numTotalCatalog;
$densityGlobal = $matrixDimGlobal > 0 ? ($totalDistinctPairs / $matrixDimGlobal) : 0;
$sparsityGlobal = 1.0 - $densityGlobal;

echo "\nSub-matrix (Active Users x Interacted Products):\n";
echo "  Dimensions: $numUsers x $numProducts = $matrixDimLocal cells\n";
echo "  Density: " . round($densityLocal * 100, 4) . "%\n";
echo "  Sparsity: " . round($sparsityLocal * 100, 4) . "%\n";

echo "\nFull matrix (Active Users x Full Catalog 2,473 products):\n";
echo "  Dimensions: $numUsers x $numTotalCatalog = $matrixDimGlobal cells\n";
echo "  Density: " . round($densityGlobal * 100, 6) . "%\n";
echo "  Sparsity: " . round($sparsityGlobal * 100, 6) . "%\n";

// User activity distribution
$uGte2 = count(array_filter($userInteractionsCount, fn($c) => $c >= 2));
$uGte5 = count(array_filter($userInteractionsCount, fn($c) => $c >= 5));
$uGte10 = count(array_filter($userInteractionsCount, fn($c) => $c >= 10));

echo "\nUser Activity Distribution:\n";
echo "  Users with >= 2 interactions: $uGte2 / $numUsers\n";
echo "  Users with >= 5 interactions: $uGte5 / $numUsers\n";
echo "  Users with >= 10 interactions: $uGte10 / $numUsers\n";

// Product activity distribution
$pGte2 = count(array_filter($productInteractionsCount, fn($c) => $c >= 2));
$pGte5 = count(array_filter($productInteractionsCount, fn($c) => $c >= 5));
$pGte10 = count(array_filter($productInteractionsCount, fn($c) => $c >= 10));

echo "\nProduct Activity Distribution:\n";
echo "  Products with >= 2 interactions: $pGte2 / $numProducts\n";
echo "  Products with >= 5 interactions: $pGte5 / $numProducts\n";
echo "  Products with >= 10 interactions: $pGte10 / $numProducts\n";
