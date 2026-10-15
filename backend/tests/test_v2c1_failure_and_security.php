<?php
// backend/tests/test_v2c1_failure_and_security.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/VideoRewardCampaign.php';
require_once __DIR__ . '/../app/models/ProductVideo.php';
require_once __DIR__ . '/../app/models/VideoWatchSession.php';
require_once __DIR__ . '/../app/models/CustomerVoucher.php';
require_once __DIR__ . '/../app/models/Voucher.php';

echo "====================================================================\n";
echo "SKINSYNTAXVN — PHASE V2C.1: ATOMICITY, SECURITY & RECONCILIATION TEST\n";
echo "====================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $title, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] $title\n";
        if ($details !== '') echo "        -> $details\n";
    } else {
        $failCount++;
        echo "![FAIL] $title\n";
        if ($details !== '') echo "        -> $details\n";
    }
}

$rewardModel = new VideoRewardCampaign($pdo);
$productVideoModel = new ProductVideo($pdo);
$watchSessionModel = new VideoWatchSession($pdo);
$customerVoucherModel = new CustomerVoucher($pdo);
$voucherModel = new Voucher($pdo);

// Find published baseline video
$pub = $pdo->ai_product_videos->findOne(['is_published' => true]);
if (!$pub) {
    echo "FATAL: Không tìm thấy published video trong ai_product_videos!\n";
    exit(1);
}

$baselineVideoId = (string)$pub['video_id'];
$baselinePubId = (string)$pub['_id'];
$baselineDuration = (float)($pub['duration'] ?? 73.816236);

echo "Baseline Video: ID={$baselineVideoId}, PubId={$baselinePubId}, Duration={$baselineDuration}s\n\n";

// Purge any stale test records from previous test runs
$pdo->customer_vouchers->deleteMany(['customer_id' => ['$regex' => '^cust_v2c1_']]);
$pdo->voucher->deleteMany(['customer_id' => ['$regex' => '^cust_v2c1_']]);
$pdo->ai_video_watch_sessions->deleteMany(['customer_id' => ['$regex' => '^cust_v2c1_']]);
$pdo->ai_video_reward_campaigns->deleteMany(['campaign_name' => ['$regex' => 'Test V2C1']]);

// Snapshot baseline states for regression validation
$prodDocBefore = $pdo->san_pham->findOne(['ma_san_pham' => 6376]) ?: $pdo->san_pham->findOne(['ma_san_pham' => '6376']);
$productPriceBefore = $prodDocBefore ? (float)($prodDocBefore['gia_ban'] ?? 0) : 0.0;
$flashSaleCountBefore = $pdo->flash_sale_campaigns->countDocuments([]);
$orderCountBefore = $pdo->hoa_don->countDocuments([]);

// Deactivate existing active campaign(s) temporarily for test isolation
$existingActives = $pdo->ai_video_reward_campaigns->find(['video_id' => $baselineVideoId, 'is_active' => true]);
$restoredActiveIds = [];
foreach ($existingActives as $ea) {
    $cid = (string)$ea['campaign_id'];
    $restoredActiveIds[] = $cid;
    $rewardModel->toggleActive($cid, false);
}

// Create dedicated test campaign
$campRes = $rewardModel->saveCampaign([
    'campaign_name' => 'Chiến dịch Test V2C1 Atomicity & Failure',
    'video_id' => $baselineVideoId,
    'publication_id' => $baselinePubId,
    'discount_type' => 'percentage',
    'discount_value' => 20,
    'max_discount_amount' => 50000,
    'min_order_amount' => 100000,
    'total_quota' => 50,
    'user_limit' => 1,
    'is_active' => true,
    'start_date' => date('Y-m-d H:i:s', time() - 3600),
    'end_date' => date('Y-m-d H:i:s', time() + 86400 * 7),
]);

assertTest(!empty($campRes['ok']), "Khởi tạo campaign test V2C1", $campRes['message'] ?? '');
$testCampaignId = $campRes['campaign']['campaign_id'] ?? '';
echo "Test Campaign ID: {$testCampaignId}\n\n";

// Helper to simulate valid watch session
function simulateValidWatch($watchSessionModel, $pdo, string $pubId, string $vidId, string $campId, ?string $custId, ?string $guestId, float $duration): string {
    $start = $watchSessionModel->startOrResumeSession([
        'publication_id' => $pubId,
        'video_id' => $vidId,
        'campaign_id' => $campId,
        'customer_id' => $custId,
        'guest_session_id' => $guestId,
    ]);
    $sid = $start['session']['watch_session_id'];
    $stepSeconds = 5.0;
    $totalSteps = (int)ceil($duration / $stepSeconds);

    for ($step = 1; $step <= $totalSteps; $step++) {
        $currentTime = min($duration, $step * $stepSeconds);
        $pdo->ai_video_watch_sessions->updateOne(
            ['watch_session_id' => $sid],
            ['$set' => ['last_server_time' => microtime(true) - 5.0]]
        );
        $watchSessionModel->recordHeartbeat($sid, $currentTime, 1.0, false);
    }
    return $sid;
}

echo "--- 1. FAILURE INJECTION: CRASH SAU QUOTA RESERVATION ---\n";
// Simulate crash right after quota reservation: quota incremented but process dies before inserting voucher
$sidCrash1 = simulateValidWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c1_crash1', null, $baselineDuration);
$resCrash1 = $customerVoucherModel->claimReward($sidCrash1, 'cust_v2c1_crash1', 'crash1@test.com', null, true, false);

assertTest(
    empty($resCrash1['ok']) && ($resCrash1['code'] ?? '') === 'CRASH_AFTER_RESERVATION',
    "1.1 Mô phỏng server crash sau quota reservation",
    "Code: " . ($resCrash1['code'] ?? '')
);

// Quota was incremented by crash simulation, but no entitlement exists
$campDocCrash1 = $rewardModel->getCampaignById($testCampaignId);
$issuedBeforeRecon = (int)($campDocCrash1['issued_count'] ?? 0);
echo "        -> Quota drift detected: issued_count = {$issuedBeforeRecon}, actual vouchers = 0\n";

// Reconcile fixes this drift!
$reconRes1 = $customerVoucherModel->reconcileCampaignQuota($testCampaignId);
assertTest(
    !empty($reconRes1['ok']) && !empty($reconRes1['reconciled']) && $reconRes1['actual_entitlements_count'] === 0,
    "1.2 Reconciliation phát hiện drift và hoàn trả quota về đúng 0",
    "Previous issued: {$reconRes1['previous_issued_count']} -> Reconciled to: {$reconRes1['actual_entitlements_count']}"
);

echo "\n--- 2. FAILURE INJECTION: CRASH SAU TẠO EXECUTION VOUCHER (ZERO ORPHANS) ---\n";
// Simulate crash after voucher inserted into collection `voucher`: compensating rollback must delete orphan!
$sidCrash2 = simulateValidWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c1_crash2', null, $baselineDuration);
$resCrash2 = $customerVoucherModel->claimReward($sidCrash2, 'cust_v2c1_crash2', 'crash2@test.com', null, false, true);

assertTest(
    empty($resCrash2['ok']) && ($resCrash2['code'] ?? '') === 'ISSUANCE_ERROR',
    "2.1 Mô phỏng lỗi sau khi tạo voucher thực thi",
    "Code: " . ($resCrash2['code'] ?? '') . " - Message: " . ($resCrash2['message'] ?? '')
);

$orphansCount = $pdo->voucher->countDocuments(['customer_id' => 'cust_v2c1_crash2']);
$entitlementCrash2Count = $pdo->customer_vouchers->countDocuments(['customer_id' => 'cust_v2c1_crash2']);
assertTest(
    $orphansCount === 0 && $entitlementCrash2Count === 0,
    "2.2 Hoàn tác bù trừ xóa sạch voucher mồ côi (Zero Orphan Vouchers)",
    "Orphan vouchers count: {$orphansCount}, Entitlements count: {$entitlementCrash2Count}"
);

$campDocCrash2 = $rewardModel->getCampaignById($testCampaignId);
assertTest(
    (int)($campDocCrash2['issued_count'] ?? 0) === 0,
    "2.3 Quota được hoàn lại nguyên trạng = 0 sau rollback",
    "Issued count: " . ($campDocCrash2['issued_count'] ?? 0)
);

echo "\n--- 3. ACID MULTI-DOCUMENT TRANSACTION ATOMICITY (NORMAL PATH) ---\n";
$sidTxSuccess = simulateValidWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c1_user1', null, $baselineDuration);
$resTxSuccess = $customerVoucherModel->claimReward($sidTxSuccess, 'cust_v2c1_user1', 'user1@test.com', null);

assertTest(
    !empty($resTxSuccess['ok']) && ($resTxSuccess['code'] ?? '') === 'CLAIM_SUCCESS',
    "3.1 Cấp voucher qua ACID Transaction thành công",
    "Voucher Code: " . ($resTxSuccess['voucher']['voucher_code'] ?? '') . ", Status: " . ($resTxSuccess['voucher']['status'] ?? '')
);

$txVoucherInDb = $pdo->voucher->findOne(['ma_code' => $resTxSuccess['voucher']['voucher_code']]);
$txEntInDb = $pdo->customer_vouchers->findOne(['voucher_code' => $resTxSuccess['voucher']['voucher_code']]);
assertTest(
    $txVoucherInDb && $txEntInDb && (int)$txVoucherInDb['so_luong'] === 1 && (int)$txEntInDb['voucher_id'] === (int)$txVoucherInDb['ma_voucher'],
    "3.2 Cả 2 tầng voucher và entitlement được commit đồng thời và khớp 100%",
    "voucher.ma_voucher={$txVoucherInDb['ma_voucher']} == customer_vouchers.voucher_id={$txEntInDb['voucher_id']}"
);

echo "\n--- 4. RETRY SAU TIMEOUT & DOUBLE-CLICK (IDEMPOTENCY) ---\n";
$retryDouble = $customerVoucherModel->claimReward($sidTxSuccess, 'cust_v2c1_user1', 'user1@test.com', null);
assertTest(
    !empty($retryDouble['ok']) && !empty($retryDouble['already_claimed']),
    "4.1 Double-click claim trả về kết quả thành công mà không cấp thêm voucher",
    "already_claimed: " . ($retryDouble['already_claimed'] ? 'true' : 'false')
);

$user1TotalVouchers = $pdo->customer_vouchers->countDocuments(['customer_id' => 'cust_v2c1_user1']);
assertTest(
    $user1TotalVouchers === 1,
    "4.2 Tổng số voucher của user1 trong customer_vouchers vẫn chính xác = 1",
    "Count: {$user1TotalVouchers}"
);

echo "\n--- 5. SUPPORT FOR USER_LIMIT > 1 VIA CLAIM_SEQ ---\n";
// Update test campaign to user_limit = 2
$pdo->ai_video_reward_campaigns->updateOne(
    ['campaign_id' => $testCampaignId],
    ['$set' => ['user_limit' => 2]]
);

// User 1 watches a SECOND valid session on the same campaign
$sidUser1Second = simulateValidWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c1_user1', null, $baselineDuration);
$resUser1Second = $customerVoucherModel->claimReward($sidUser1Second, 'cust_v2c1_user1', 'user1@test.com', null);

assertTest(
    !empty($resUser1Second['ok']) && ($resUser1Second['code'] ?? '') === 'CLAIM_SUCCESS',
    "5.1 Khi Admin cấu hình user_limit = 2, User 1 nhận thành công voucher thứ 2",
    "Voucher 2 Code: " . ($resUser1Second['voucher']['voucher_code'] ?? '') . ", Claim Seq: " . ($resUser1Second['voucher']['claim_seq'] ?? '')
);

$user1VouchersNow = $pdo->customer_vouchers->countDocuments(['customer_id' => 'cust_v2c1_user1']);
assertTest(
    $user1VouchersNow === 2,
    "5.2 Database lưu trữ chính xác 2 voucher cho User 1 với claim_seq khác nhau",
    "Customer vouchers count = {$user1VouchersNow}"
);

// User 1 attempts a THIRD claim when user_limit = 2 -> MUST BE REJECTED
$sidUser1Third = simulateValidWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c1_user1', null, $baselineDuration);
$resUser1Third = $customerVoucherModel->claimReward($sidUser1Third, 'cust_v2c1_user1', 'user1@test.com', null);
assertTest(
    empty($resUser1Third['ok']) && ($resUser1Third['code'] ?? '') === 'USER_LIMIT_REACHED',
    "5.3 Khi vượt quá user_limit = 2, User 1 bị chặn với USER_LIMIT_REACHED",
    "Code: " . ($resUser1Third['code'] ?? '')
);

echo "\n--- 6. TRANH CHẤP QUOTA TẠI BIÊN (QUOTA = 1) TRONG TRANSACTION ---\n";
// Set total_quota = current issued + 1
$campDocBeforeRace = $rewardModel->getCampaignById($testCampaignId);
$currIssuedBeforeRace = (int)($campDocBeforeRace['issued_count'] ?? 0);
$pdo->ai_video_reward_campaigns->updateOne(
    ['campaign_id' => $testCampaignId],
    ['$set' => ['total_quota' => $currIssuedBeforeRace + 1]]
);

$sidRaceA = simulateValidWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c1_race_A', null, $baselineDuration);
$sidRaceB = simulateValidWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c1_race_B', null, $baselineDuration);

$claimA = $customerVoucherModel->claimReward($sidRaceA, 'cust_v2c1_race_A', 'raceA@test.com', null);
$claimB = $customerVoucherModel->claimReward($sidRaceB, 'cust_v2c1_race_B', 'raceB@test.com', null);

assertTest(
    !empty($claimA['ok']) && empty($claimB['ok']) && ($claimB['code'] ?? '') === 'QUOTA_EXHAUSTED',
    "6.1 Tranh chấp slot cuối cùng: User A nhận thành công, User B bị từ chối QUOTA_EXHAUSTED",
    "User A ok=" . ($claimA['ok'] ? 'true' : 'false') . ", User B code=" . ($claimB['code'] ?? '')
);

$campDocAfterRace = $rewardModel->getCampaignById($testCampaignId);
assertTest(
    (int)($campDocAfterRace['issued_count'] ?? 0) === ($currIssuedBeforeRace + 1),
    "6.2 Quota giữ nguyên chính xác ở mức " . ($currIssuedBeforeRace + 1) . ", không vượt dù có race condition",
    "Issued: {$campDocAfterRace['issued_count']} / Total: {$campDocAfterRace['total_quota']}"
);

// Restore total_quota = 50
$pdo->ai_video_reward_campaigns->updateOne(['campaign_id' => $testCampaignId], ['$set' => ['total_quota' => 50]]);

echo "\n--- 7. KIỂM TRA TÍNH TOÀN VẸN VÀ KHÔNG CÓ BẢN GHI MỒ CÔI (AUDIT & RECONCILIATION) ---\n";
$fullRecon = $customerVoucherModel->reconcileCampaignQuota($testCampaignId);
assertTest(
    !empty($fullRecon['ok']) && $fullRecon['orphans_detected'] === 0 && $fullRecon['clean'] === true,
    "7.1 Kiểm toán toàn diện: Zero voucher mồ côi, Zero quota lệch",
    "Actual: {$fullRecon['actual_entitlements_count']}, Orphans: {$fullRecon['orphans_detected']}, Clean: true"
);

echo "\n--- 8. REGRESSION & SAFETY ASSURANCE ---\n";
$prodDocAfter = $pdo->san_pham->findOne(['ma_san_pham' => 6376]) ?: $pdo->san_pham->findOne(['ma_san_pham' => '6376']);
$productPriceAfter = $prodDocAfter ? (float)($prodDocAfter['gia_ban'] ?? 0) : 0.0;
assertTest(
    $productPriceBefore === $productPriceAfter,
    "8.1 Giá gốc sản phẩm san_pham.gia_ban giữ nguyên: {$productPriceBefore} == {$productPriceAfter}",
    "Giá bán: " . number_format($productPriceAfter) . " VNĐ"
);

$flashSaleCountAfter = $pdo->flash_sale_campaigns->countDocuments([]);
assertTest(
    $flashSaleCountBefore === $flashSaleCountAfter,
    "8.2 Flash Sale campaigns không bị ảnh hưởng: {$flashSaleCountBefore} == {$flashSaleCountAfter}",
    "Flash sale count: {$flashSaleCountAfter}"
);

$orderCountAfter = $pdo->hoa_don->countDocuments([]);
assertTest(
    $orderCountBefore === $orderCountAfter,
    "8.3 Collection hoa_don giữ nguyên, không can thiệp checkout: {$orderCountBefore} == {$orderCountAfter}",
    "Order count: {$orderCountAfter}"
);

// Cleanup test records
$rewardModel->deleteCampaign($testCampaignId);
$pdo->ai_video_watch_sessions->deleteMany(['campaign_id' => $testCampaignId]);
$pdo->customer_vouchers->deleteMany(['campaign_id' => $testCampaignId]);
$pdo->voucher->deleteMany(['campaign_id' => $testCampaignId]);

// Restore canonical campaign states
foreach ($restoredActiveIds as $racid) {
    $rewardModel->toggleActive($racid, true);
}
echo "\n [CLEANUP] Dọn dẹp sạch sẽ dữ liệu kiểm thử và khôi phục campaign active gốc.\n\n";

echo "====================================================================\n";
echo "SUMMARY: PASS = {$passCount} | FAIL = {$failCount}\n";
echo "STATUS: " . ($failCount === 0 ? "ALL PASS" : "HAS FAILURES") . "\n";
echo "====================================================================\n";

exit($failCount === 0 ? 0 : 1);
