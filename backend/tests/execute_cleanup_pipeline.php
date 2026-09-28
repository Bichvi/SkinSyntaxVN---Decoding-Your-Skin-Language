<?php
/**
 * Script: execute_cleanup_pipeline.php
 * Purpose: Safe backup and cleanup of skinsyntax on Atlas with full validation.
 */
require_once __DIR__ . '/../app/config/db.php';
global $db;

$whitelist = [
    // Làm sạch
    1, 2, 4, 17,
    // Dưỡng ẩm
    7, 30, 37,
    // Chống nắng
    6,
    // Mặt nạ
    11, 19, 53, 83,
    // Đặc trị
    9, 25, 105,
    // Bộ chăm sóc da mặt
    3,
    // Dưỡng mắt
    38, 60,
    // Dưỡng môi
    18, 29, 73
];

echo "====================================================\n";
echo "STEP 1: PRE-CHECK & BACKUP VERIFICATION\n";
echo "====================================================\n";

$dbName = $db->getDatabaseName();
echo "1. Current Database: $dbName\n";
if ($dbName !== 'skinsyntax') {
    die("FATAL: Database is not 'skinsyntax'. Aborting!\n");
}

$initialProductCount = $db->san_pham->countDocuments();
$initialCategoryCount = $db->danh_muc->countDocuments();
echo "2. Initial san_pham count: $initialProductCount\n";
echo "   Initial danh_muc count: $initialCategoryCount\n";

if ($initialProductCount !== 6377) {
    die("FATAL: Expected 6377 products in san_pham, found $initialProductCount. Aborting!\n");
}
if ($initialCategoryCount !== 125) {
    die("FATAL: Expected 125 categories in danh_muc, found $initialCategoryCount. Aborting!\n");
}

// Check if backup collections already exist
$backupProductCollName = 'san_pham_backup_6377';
$backupCategoryCollName = 'danh_muc_backup_125';

$existingColls = iterator_to_array($db->listCollectionNames());
if (in_array($backupProductCollName, $existingColls)) {
    die("FATAL: Backup collection '$backupProductCollName' already exists. Aborting to avoid overwrite!\n");
}
if (in_array($backupCategoryCollName, $existingColls)) {
    die("FATAL: Backup collection '$backupCategoryCollName' already exists. Aborting to avoid overwrite!\n");
}

echo "3. Creating backups on MongoDB Atlas...\n";
// Server-side copy via $out pipeline
$db->san_pham->aggregate([
    ['$match' => (object)[]],
    ['$out' => $backupProductCollName]
]);
echo "   -> Backup created: $backupProductCollName\n";

$db->danh_muc->aggregate([
    ['$match' => (object)[]],
    ['$out' => $backupCategoryCollName]
]);
echo "   -> Backup created: $backupCategoryCollName\n";

// Validate backup counts
$backupProductCount = $db->selectCollection($backupProductCollName)->countDocuments();
$backupCategoryCount = $db->selectCollection($backupCategoryCollName)->countDocuments();

echo "4. Validating backup integrity:\n";
echo "   Source san_pham: $initialProductCount | Backup: $backupProductCount\n";
echo "   Source danh_muc: $initialCategoryCount | Backup: $backupCategoryCount\n";

if ($backupProductCount !== $initialProductCount) {
    die("FATAL: Backup product count mismatch ($backupProductCount != $initialProductCount)!\n");
}
if ($backupCategoryCount !== $initialCategoryCount) {
    die("FATAL: Backup category count mismatch ($backupCategoryCount != $initialCategoryCount)!\n");
}

// Sample check
$sampleSource = $db->san_pham->findOne(['ma_danh_muc' => 18]);
$sampleBackup = $db->selectCollection($backupProductCollName)->findOne(['ma_san_pham' => $sampleSource['ma_san_pham']]);
if (!$sampleBackup || (string)$sampleBackup['_id'] !== (string)$sampleSource['_id'] || $sampleBackup['ten_san_pham'] !== $sampleSource['ten_san_pham']) {
    die("FATAL: Sample verification between source and backup failed!\n");
}
echo "   -> Sample document verification passed. ma_san_pham and _id perfectly intact.\n\n";

echo "====================================================\n";
echo "STEP 2: VERIFY TARGET DATASET COUNT BEFORE MUTATION\n";
echo "====================================================\n";

$matchFilter = ['ma_danh_muc' => ['$in' => $whitelist]];
$targetCount = $db->san_pham->countDocuments($matchFilter);
echo "Products matching whitelist: $targetCount (Expected: 2473)\n";

if ($targetCount !== 2473) {
    die("FATAL: Target count $targetCount does not match expected 2473. Aborting without mutating production!\n");
}

$lipBalmCount = $db->san_pham->countDocuments(['ma_danh_muc' => 18]);
echo "Son Dưỡng Môi [ID: 18] count: $lipBalmCount (Expected: 167)\n";
if ($lipBalmCount !== 167) {
    die("FATAL: Category 18 count $lipBalmCount does not match expected 167!\n");
}
echo "Target count verified strictly.\n\n";

echo "====================================================\n";
echo "STEP 3: EXECUTE CLEANUP ON PRODUCTION COLLECTIONS\n";
echo "====================================================\n";

// Delete non-whitelisted documents from san_pham
$deleteProductsResult = $db->san_pham->deleteMany(['ma_danh_muc' => ['$nin' => $whitelist]]);
echo "Deleted " . $deleteProductsResult->getDeletedCount() . " non-skincare products from san_pham.\n";

// Delete non-whitelisted categories from danh_muc
$deleteCatsResult = $db->danh_muc->deleteMany(['ma_danh_muc' => ['$nin' => $whitelist]]);
echo "Deleted " . $deleteCatsResult->getDeletedCount() . " non-skincare categories from danh_muc.\n";

$finalProductCount = $db->san_pham->countDocuments();
$finalCategoryCount = $db->danh_muc->countDocuments();
echo "Post-cleanup san_pham count: $finalProductCount\n";
echo "Post-cleanup danh_muc count: $finalCategoryCount\n";

if ($finalProductCount !== 2473) {
    die("FATAL: Post-cleanup san_pham count is $finalProductCount, expected 2473!\n");
}
if ($finalCategoryCount !== 21) {
    die("FATAL: Post-cleanup danh_muc count is $finalCategoryCount, expected 21!\n");
}
echo "Cleanup completed successfully!\n\n";

echo "====================================================\n";
echo "STEP 4: POST-CLEANUP VALIDATION\n";
echo "====================================================\n";

// 1. Whitelist violation check
$nonWhitelistCount = $db->san_pham->countDocuments(['ma_danh_muc' => ['$nin' => $whitelist]]);
echo "- ma_danh_muc outside whitelist: $nonWhitelistCount\n";

// 2. Orphan category check
$validCategoryIds = iterator_to_array($db->danh_muc->distinct('ma_danh_muc'));
$orphanProducts = $db->san_pham->countDocuments(['ma_danh_muc' => ['$nin' => $validCategoryIds]]);
echo "- Orphan ma_danh_muc (not in danh_muc): $orphanProducts\n";

// 3. Unique ma_san_pham and duplicate check
$distinctProductIds = count($db->san_pham->distinct('ma_san_pham'));
echo "- Total products: $finalProductCount | Distinct ma_san_pham: $distinctProductIds\n";
$duplicateIdCount = $finalProductCount - $distinctProductIds;
echo "- Duplicate ma_san_pham: $duplicateIdCount\n";

// 4. Quality checks on 2473 products
$cursor = $db->san_pham->find();
$missingName = 0;
$missingPrice = 0;
$invalidPrice = 0;
$missingImage = 0;
$missingBrand = 0;
$missingDesc = 0;
$missingIngredients = 0;
$missingSkinType = 0;
$missingSkinIssues = 0;

$soldVals = [];
$ratingVals = [];
$ratingCountVals = [];

$catDistribution = [];

foreach ($cursor as $p) {
    // Category distribution
    $cid = $p['ma_danh_muc'];
    $catDistribution[$cid] = ($catDistribution[$cid] ?? 0) + 1;

    // Name
    $name = trim($p['ten_san_pham'] ?? '');
    if ($name === '') $missingName++;

    // Price
    $price = $p['gia_ban'] ?? null;
    if ($price === null || $price === '') {
        $missingPrice++;
    } elseif (!is_numeric($price) || (float)$price <= 0) {
        $invalidPrice++;
    }

    // Image
    $img = trim($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? '');
    if ($img === '') $missingImage++;

    // Brand
    $brand = trim($p['thuong_hieu'] ?? '');
    $brandId = $p['ma_thuong_hieu'] ?? null;
    if ($brand === '' && empty($brandId)) $missingBrand++;

    // Description
    $desc = trim($p['mo_ta'] ?? $p['mo_ta_ngan'] ?? '');
    if ($desc === '') $missingDesc++;

    // Ingredients
    $ing = trim($p['thanh_phan'] ?? $p['thanh_phan_chinh'] ?? '');
    if ($ing === '') $missingIngredients++;

    // Skin Type
    $st = trim($p['loai_da'] ?? '');
    $sts = $p['loai_da_phu_hop'] ?? null;
    if ($st === '' && empty($sts)) $missingSkinType++;

    // Skin issues
    $si = $p['van_de_ve_da'] ?? null;
    if (empty($si)) $missingSkinIssues++;

    // Metrics for Simple Recommender
    $sold = $p['so_luong_da_ban'] ?? null;
    if ($sold !== null && is_numeric($sold)) {
        $soldVals[] = (float)$sold;
    }

    $rating = $p['diem_danh_gia_tb'] ?? $p['diem_danh_gia'] ?? null;
    if ($rating !== null && is_numeric($rating)) {
        $ratingVals[] = (float)$rating;
    }

    $rc = $p['so_luong_danh_gia'] ?? null;
    if ($rc !== null && is_numeric($rc)) {
        $ratingCountVals[] = (int)$rc;
    }
}

echo "- Missing product name: $missingName\n";
echo "- Missing price: $missingPrice\n";
echo "- Invalid price (<= 0): $invalidPrice\n";
echo "- Missing image: $missingImage\n";
echo "- Missing brand: $missingBrand\n";
echo "- Missing description: $missingDesc\n";
echo "- Missing skin type: $missingSkinType\n";
echo "- Missing skin issues: $missingSkinIssues (" . round(($missingSkinIssues / $finalProductCount) * 100, 2) . "%)\n";
echo "- Missing ingredients: $missingIngredients (" . round(($missingIngredients / $finalProductCount) * 100, 2) . "%)\n\n";

echo "--- Category Distribution ---\n";
ksort($catDistribution);
foreach ($catDistribution as $cid => $cnt) {
    $cdoc = $db->danh_muc->findOne(['ma_danh_muc' => $cid]);
    $cname = $cdoc['ten_danh_muc'] ?? 'UNKNOWN';
    echo sprintf("Category ID: %-3d | Name: %-30s | Count: %d\n", $cid, $cname, $cnt);
}
echo "\n";

echo "====================================================\n";
echo "STEP 5: SIMPLE RECOMMENDER READINESS STATISTICS\n";
echo "====================================================\n";

function computeStats(array $arr, string $label, int $total) {
    $count = count($arr);
    $missing = $total - $count;
    if ($count === 0) {
        echo "$label: No numeric data.\n";
        return;
    }
    sort($arr);
    $min = min($arr);
    $max = max($arr);
    $sum = array_sum($arr);
    $mean = $sum / $count;
    $zeroCount = count(array_filter($arr, fn($v) => $v == 0));

    // Median
    $mid = (int)floor($count / 2);
    $median = ($count % 2 === 0) ? ($arr[$mid - 1] + $arr[$mid]) / 2 : $arr[$mid];

    echo sprintf("%-20s | Available: %-5d | Missing: %-5d | Zero: %-5d | Min: %-6.2f | Max: %-8.2f | Mean: %-6.2f | Median: %-6.2f\n",
        $label, $count, $missing, $zeroCount, $min, $max, $mean, $median);
}

echo sprintf("%-20s | %-16s | %-14s | %-11s | %-11s | %-13s | %-12s | %-12s\n",
    "Field", "Available", "Missing", "Zero Count", "Min", "Max", "Mean", "Median");
echo str_repeat("-", 120) . "\n";
computeStats($soldVals, "so_luong_da_ban", $finalProductCount);
computeStats($ratingVals, "diem_danh_gia_tb", $finalProductCount);
computeStats($ratingCountVals, "so_luong_danh_gia", $finalProductCount);
echo "\n";

echo "====================================================\n";
echo "STEP 6: CONTENT-BASED READINESS (METADATA COVERAGE)\n";
echo "====================================================\n";

$fields = [
    'ten_san_pham' => $finalProductCount - $missingName,
    'danh_muc_day_du' => $finalProductCount,
    'thuong_hieu' => $finalProductCount - $missingBrand,
    'mo_ta / mo_ta_ngan' => $finalProductCount - $missingDesc,
    'thanh_phan' => $finalProductCount - $missingIngredients,
    'loai_da' => $finalProductCount - $missingSkinType,
    'van_de_ve_da' => $finalProductCount - $missingSkinIssues,
    'gia_ban' => $finalProductCount - ($missingPrice + $invalidPrice),
];

echo sprintf("%-25s | %-10s | %-10s | %-10s\n", "Field", "Available", "Missing", "Coverage %");
echo str_repeat("-", 65) . "\n";
foreach ($fields as $fieldName => $avail) {
    $miss = $finalProductCount - $avail;
    $cov = round(($avail / $finalProductCount) * 100, 2);
    echo sprintf("%-25s | %-10d | %-10d | %-10s\n", $fieldName, $avail, $miss, "$cov%");
}
echo "====================================================\n";
echo "PIPELINE COMPLETED SUCCESSFULLY!\n";
echo "====================================================\n";
