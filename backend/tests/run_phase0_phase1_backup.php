<?php
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/services/ContentBasedRecommender.php';

global $db;

echo "=================================================================\n";
echo "PHASE 0: PRE-CHECK & PHASE 1: BACKUP\n";
echo "=================================================================\n\n";

// 1. Database verification
$dbName = MONGO_DB_NAME;
echo "[PHASE 0.1] Connected Database: {$dbName}\n";

$spCount = $db->san_pham->countDocuments();
$dmCount = $db->danh_muc->countDocuments();
echo "[PHASE 0.2] san_pham count: {$spCount} (Expected: 2473)\n";
echo "[PHASE 0.2] danh_muc count: {$dmCount} (Expected: 21)\n";

if ($spCount !== 2473 || $dmCount !== 21) {
    die("[ABORT] Counts do not match expected 2473 / 21!\n");
}

// 3. Verify 21 leaf IDs
$expectedLeafIds = [1, 2, 3, 4, 6, 7, 9, 11, 17, 18, 19, 25, 29, 30, 37, 38, 53, 60, 73, 83, 105];
$actualLeafDocs = iterator_to_array($db->danh_muc->find([], ['sort' => ['ma_danh_muc' => 1]]));
$actualIds = array_map(fn($d) => (int)$d['ma_danh_muc'], $actualLeafDocs);
if ($actualIds !== $expectedLeafIds) {
    die("[ABORT] 21 Leaf IDs do not match! Actual: " . json_encode($actualIds) . "\n");
}
echo "[PHASE 0.3] All 21 leaf IDs verified: " . implode(', ', $actualIds) . "\n";

// 4. Verify #18 Son Dưỡng Môi = 167
$cat18 = $db->danh_muc->findOne(['ma_danh_muc' => 18]);
$count18 = $db->san_pham->countDocuments(['ma_danh_muc' => 18]);
echo "[PHASE 0.4] #18 Name: '" . ($cat18['ten_danh_muc'] ?? '') . "', Product Count: {$count18} (Expected: 167)\n";
if ($count18 !== 167) {
    die("[ABORT] #18 product count is {$count18}, expected 167!\n");
}

// 5. Verify Recommender Baseline before migration
$spModel = new SanPham($db);
$simpleTop24 = $spModel->getSimpleRecommenderProducts(24);
$baselineFile = __DIR__ . '/output/simple_baseline_top24.json';
$baselineTop24 = json_decode(file_get_contents($baselineFile), true);
$simpleMatch = true;
for ($i = 0; $i < 24; $i++) {
    if ((string)$simpleTop24[$i]['ma_san_pham'] !== (string)$baselineTop24[$i]['ma_san_pham']) {
        $simpleMatch = false;
        break;
    }
}
if (!$simpleMatch || count($simpleTop24) !== 24) {
    die("[ABORT] Simple Recommender Top-24 pre-check failed!\n");
}
echo "[PHASE 0.5] Simple Recommender Top-24 pre-check: PASS (100% match)\n";

$cbService = new ContentBasedRecommender();
$cbTest1000 = $cbService->recommend(['1000'], 4, $db);
$cbTest18 = $cbService->recommend(['71'], 4, $db); // ID 71 is DHC Lip Balm in #18
if (count($cbTest1000) !== 4 || count($cbTest18) !== 4) {
    die("[ABORT] Content-Based Recommender pre-check failed!\n");
}
echo "[PHASE 0.5] Content-Based & Hybrid pre-check: PASS\n";

// Save pre-migration recommendation snapshot for comparison in Phase 10/14
$preRecSnapshot = [
    'sp_1000_eucerin' => $cbTest1000,
    'sp_71_dhc_lip_balm' => $cbTest18,
    'sp_412_laneige_lip' => $cbService->recommend(['412'], 4, $db),
    'hybrid_oily_acne' => $cbService->recommendHybrid(['1000'], [
        'skin_type' => 'Da dầu',
        'van_de_da' => ['Mụn', 'Lỗ chân lông to'],
        'muc_tieu_cham_soc' => 'Kiểm soát dầu và ngừa mụn',
        'ngan_sach' => 350000
    ], 4, $db),
];

// =================================================================
// PHASE 1: BACKUP
// =================================================================
echo "\n--- STARTING PHASE 1: BACKUP ---\n";
$backupDir = dirname(__DIR__) . '/storage/backups/category_migration_20260930';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

$dmBackupFile = $backupDir . '/danh_muc_backup_21.json';
$spBackupFile = $backupDir . '/san_pham_backup_2473.json';
$tfidfBackupFile = $backupDir . '/tfidf_cache_before_migration.json';
$preRecSnapshotFile = $backupDir . '/pre_migration_rec_snapshot.json';

if (!file_exists($dmBackupFile)) {
    $allDm = iterator_to_array($db->danh_muc->find([], ['sort' => ['ma_danh_muc' => 1]]));
    file_put_contents($dmBackupFile, json_encode($allDm, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "[BACKUP] Created {$dmBackupFile}\n";
} else {
    echo "[BACKUP] Exists (not overwritten): {$dmBackupFile}\n";
}

if (!file_exists($spBackupFile)) {
    $allSp = iterator_to_array($db->san_pham->find([], ['sort' => ['ma_san_pham' => 1]]));
    file_put_contents($spBackupFile, json_encode($allSp, JSON_UNESCAPED_UNICODE));
    echo "[BACKUP] Created {$spBackupFile}\n";
} else {
    echo "[BACKUP] Exists (not overwritten): {$spBackupFile}\n";
}

$currentTfidfFile = dirname(__DIR__) . '/app/content/tfidf_cache.json';
if (!file_exists($tfidfBackupFile) && file_exists($currentTfidfFile)) {
    copy($currentTfidfFile, $tfidfBackupFile);
    echo "[BACKUP] Created {$tfidfBackupFile}\n";
}

file_put_contents($preRecSnapshotFile, json_encode($preRecSnapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// Verify backups
$dmRestored = json_decode(file_get_contents($dmBackupFile), true);
$spRestored = json_decode(file_get_contents($spBackupFile), true);

echo "[VERIFY BACKUP] danh_muc source={$dmCount} vs backup=" . count($dmRestored) . "\n";
echo "[VERIFY BACKUP] san_pham source={$spCount} vs backup=" . count($spRestored) . "\n";

if (count($dmRestored) !== 21 || count($spRestored) !== 2473) {
    die("[ABORT] Backup verification count mismatch!\n");
}

// Verify sample document preservation
$sampleSp = $spRestored[0];
$dbSampleSp = $db->san_pham->findOne(['ma_san_pham' => $sampleSp['ma_san_pham']]);
if ((int)$dbSampleSp['ma_danh_muc'] !== (int)$sampleSp['ma_danh_muc'] || (string)$dbSampleSp['ten_san_pham'] !== (string)$sampleSp['ten_san_pham']) {
    die("[ABORT] Sample document verification failed!\n");
}
echo "[VERIFY BACKUP] Sample product ID={$sampleSp['ma_san_pham']}, ma_danh_muc={$sampleSp['ma_danh_muc']} verified identical.\n";
echo "\n=== PHASE 0 & PHASE 1 COMPLETED SUCCESSFULLY ===\n";
