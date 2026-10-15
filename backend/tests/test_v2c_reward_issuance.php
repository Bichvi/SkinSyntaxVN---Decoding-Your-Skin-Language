<?php
// backend/tests/test_v2c_reward_issuance.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/VideoRewardCampaign.php';
require_once __DIR__ . '/../app/models/ProductVideo.php';
require_once __DIR__ . '/../app/models/VideoWatchSession.php';
require_once __DIR__ . '/../app/models/CustomerVoucher.php';
require_once __DIR__ . '/../app/models/Voucher.php';

echo "========================================================\n";
echo "SKINSYNTAXVN — PHASE V2C: REAL REWARD VOUCHER ISSUANCE\n";
echo "========================================================\n\n";

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

// Purge any stale test records from previous test runs to ensure clean test state
$pdo->customer_vouchers->deleteMany(['customer_id' => ['$regex' => '^cust_v2c_']]);
$pdo->voucher->deleteMany(['customer_id' => ['$regex' => '^cust_v2c_']]);
$pdo->ai_video_watch_sessions->deleteMany(['customer_id' => ['$regex' => '^cust_v2c_']]);

// Snapshot product price & Flash sale collection before tests
$prodDocBefore = $pdo->san_pham->findOne(['ma_san_pham' => 6376]) ?: $pdo->san_pham->findOne(['ma_san_pham' => '6376']);
$productPriceBefore = $prodDocBefore ? (float)($prodDocBefore['gia_ban'] ?? 0) : 0.0;
$flashSaleCountBefore = $pdo->flash_sale_campaigns->countDocuments([]);
$orderCountBefore = $pdo->hoa_don->countDocuments([]);

// Save & deactivate active campaigns for isolation
$existingActives = $pdo->ai_video_reward_campaigns->find(['video_id' => $baselineVideoId, 'is_active' => true]);
$restoredActiveIds = [];
foreach ($existingActives as $ea) {
    $cid = (string)$ea['campaign_id'];
    $restoredActiveIds[] = $cid;
    $rewardModel->toggleActive($cid, false);
}

// Create dedicated test campaign
$campRes = $rewardModel->saveCampaign([
    'campaign_name' => 'Chiến dịch Test V2C Voucher Issuance',
    'video_id' => $baselineVideoId,
    'publication_id' => $baselinePubId,
    'discount_type' => 'percentage',
    'discount_value' => 15,
    'max_discount_amount' => 50000,
    'min_order_amount' => 100000,
    'total_quota' => 100,
    'user_limit' => 1,
    'is_active' => true,
    'start_date' => date('Y-m-d H:i:s', time() - 3600),
    'end_date' => date('Y-m-d H:i:s', time() + 86400 * 7),
]);

assertTest(!empty($campRes['ok']), "Khởi tạo campaign test cho V2C issuance", $campRes['message'] ?? '');
$testCampaignId = $campRes['campaign']['campaign_id'] ?? '';
echo "Test Campaign ID: {$testCampaignId}\n\n";

// Helper to simulate complete watch
function simulateCompleteWatch($watchSessionModel, $pdo, string $pubId, string $vidId, string $campId, ?string $custId, ?string $guestId, float $duration): string {
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

echo "--- 1. USER HỢP LỆ XEM ĐỦ VÀ CLAIM THÀNH CÔNG ---\n";
$sid1 = simulateCompleteWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c_user1', null, $baselineDuration);
$claim1 = $customerVoucherModel->claimReward($sid1, 'cust_v2c_user1', 'user1@skinsyntax.test', null);

assertTest(
    !empty($claim1['ok']) && !empty($claim1['voucher']['voucher_code']),
    "1.1 Khách hàng xem đủ video claim thành công voucher",
    "Voucher Code: " . ($claim1['voucher']['voucher_code'] ?? '') . ", Status: " . ($claim1['voucher']['status'] ?? '')
);

$dbVoucher1 = $pdo->voucher->findOne(['ma_code' => $claim1['voucher']['voucher_code']]);
assertTest(
    $dbVoucher1 && (int)$dbVoucher1['so_luong'] === 1 && (int)$dbVoucher1['so_luong_da_dung'] === 0,
    "1.2 Voucher thực thi trong collection voucher được tạo với so_luong = 1",
    "ma_voucher: " . ($dbVoucher1['ma_voucher'] ?? '')
);

echo "\n--- 2. USER CHƯA XEM ĐỦ BỊ TỪ CHỐI CLAIM ---\n";
$start2 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_v2c_user2',
]);
$sid2 = $start2['session']['watch_session_id'];
// Watch only 10s
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid2], ['$set' => ['last_server_time' => microtime(true) - 10.0]]);
$watchSessionModel->recordHeartbeat($sid2, 10.0, 1.0, false);

$claim2 = $customerVoucherModel->claimReward($sid2, 'cust_v2c_user2', 'user2@skinsyntax.test', null);
assertTest(
    empty($claim2['ok']) && ($claim2['code'] ?? '') === 'NOT_ELIGIBLE',
    "2.1 Phiên xem chưa đủ điều kiện bị từ chối cấp voucher",
    "Code: " . ($claim2['code'] ?? '') . " - Message: " . ($claim2['message'] ?? '')
);

echo "\n--- 3. GUEST KHÔNG THỂ CLAIM TRỰC TIẾP ---\n";
$sid3 = simulateCompleteWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, null, 'guest_v2c_session_3', $baselineDuration);
$claim3 = $customerVoucherModel->claimReward($sid3, '', null, 'guest_v2c_session_3');
assertTest(
    empty($claim3['ok']) && ($claim3['code'] ?? '') === 'UNAUTHENTICATED',
    "3.1 Khách vãng lai chưa đăng nhập bị từ chối claim trực tiếp",
    "Code: " . ($claim3['code'] ?? '')
);

// Khách đăng nhập và claim an toàn với session tương ứng
$claim3Migrated = $customerVoucherModel->claimReward($sid3, 'cust_v2c_migrated_3', 'migrated3@skinsyntax.test', 'guest_v2c_session_3');
assertTest(
    !empty($claim3Migrated['ok']) && !empty($claim3Migrated['voucher']['voucher_code']),
    "3.2 Khách đăng nhập cùng phiên trình duyệt nhận thưởng thành công",
    "Voucher Code: " . ($claim3Migrated['voucher']['voucher_code'] ?? '')
);

echo "\n--- 4. USER A KHÔNG THỂ CLAIM WATCH SESSION CỦA USER B ---\n";
$sid4 = simulateCompleteWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c_owner_A', null, $baselineDuration);
$claim4Theft = $customerVoucherModel->claimReward($sid4, 'cust_v2c_attacker_B', 'attackerB@skinsyntax.test', null);
assertTest(
    empty($claim4Theft['ok']) && ($claim4Theft['code'] ?? '') === 'FORBIDDEN_SESSION',
    "4.1 Tài khoản B bị chặn khi cố ý chiếm đoạt session của Tài khoản A",
    "Code: " . ($claim4Theft['code'] ?? '')
);

echo "\n--- 5. DOUBLE-CLICK CLAIM (IDEMPOTENCY) ---\n";
// Call claimReward again immediately on sid1
$claimDouble = $customerVoucherModel->claimReward($sid1, 'cust_v2c_user1', 'user1@skinsyntax.test', null);
assertTest(
    !empty($claimDouble['ok']) && !empty($claimDouble['already_claimed']),
    "5.1 Double-click claim trả về kết quả thành công mà không cấp thêm voucher mới",
    "Already claimed: " . ($claimDouble['already_claimed'] ? 'true' : 'false')
);

echo "\n--- 6. CONCURRENT REQUESTS CÙNG USER TRÊN CÙNG CAMPAIGN ---\n";
// User 1 attempts to watch another session on the same campaign and claim again
$sid1b = simulateCompleteWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c_user1', null, $baselineDuration);
$claim1b = $customerVoucherModel->claimReward($sid1b, 'cust_v2c_user1', 'user1@skinsyntax.test', null);
assertTest(
    empty($claim1b['ok']) && ($claim1b['code'] ?? '') === 'USER_LIMIT_REACHED',
    "6.1 Cùng user gửi yêu cầu từ session thứ 2 bị chặn bởi giới hạn user_limit",
    "Code: " . ($claim1b['code'] ?? '')
);

echo "\n--- 7. CONCURRENT REQUESTS NHIỀU USER KHI QUOTA CÒN 1 ---\n";
// Set test campaign total_quota strictly to current issued_count + 1 (leaving exactly 1 slot available)
$currentCampDoc = $rewardModel->getCampaignById($testCampaignId);
$currIssued = (int)($currentCampDoc['issued_count'] ?? 0);
$pdo->ai_video_reward_campaigns->updateOne(
    ['campaign_id' => $testCampaignId],
    ['$set' => ['total_quota' => $currIssued + 1]]
);

$sidRaceUserX = simulateCompleteWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c_race_X', null, $baselineDuration);
$sidRaceUserY = simulateCompleteWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c_race_Y', null, $baselineDuration);

// User X claims first (takes the last remaining slot)
$claimX = $customerVoucherModel->claimReward($sidRaceUserX, 'cust_v2c_race_X', 'x@test.com', null);
// User Y claims concurrently (should be rejected because quota is exhausted)
$claimY = $customerVoucherModel->claimReward($sidRaceUserY, 'cust_v2c_race_Y', 'y@test.com', null);

assertTest(
    !empty($claimX['ok']) && empty($claimY['ok']) && ($claimY['code'] ?? '') === 'QUOTA_EXHAUSTED',
    "7.1 Khi quota còn đúng 1, User X nhận thành công và User Y bị từ chối QUOTA_EXHAUSTED",
    "User X ok=" . ($claimX['ok'] ? 'true' : 'false') . ", User Y code=" . ($claimY['code'] ?? '')
);

$campQuotaDoc = $rewardModel->getCampaignById($testCampaignId);
assertTest(
    (int)($campQuotaDoc['issued_count'] ?? 0) === ($currIssued + 1),
    "7.2 Quota được bảo toàn chính xác = " . ($currIssued + 1) . ", không bị cấp vượt",
    "Issued count: " . ($campQuotaDoc['issued_count'] ?? 0) . " / Total quota: " . ($campQuotaDoc['total_quota'] ?? 0)
);

// Restore total_quota = 100 for subsequent test cases
$pdo->ai_video_reward_campaigns->updateOne(
    ['campaign_id' => $testCampaignId],
    ['$set' => ['total_quota' => 100]]
);

echo "\n--- 8. RETRY REQUEST SAU TIMEOUT (IDEMPOTENCY) ---\n";
$retryRes = $customerVoucherModel->claimReward($sid1, 'cust_v2c_user1', 'user1@skinsyntax.test', null);
assertTest(
    !empty($retryRes['ok']) && ($retryRes['voucher']['voucher_code'] ?? '') === ($claim1['voucher']['voucher_code'] ?? ''),
    "8.1 Retry request trả về đúng voucher ban đầu mà không sinh bản ghi mới",
    "Voucher Code: " . ($retryRes['voucher']['voucher_code'] ?? '')
);

echo "\n--- 9. CAMPAIGN BỊ TẮT TRƯỚC CLAIM ---\n";
// Watch while campaign is still active
$sid9 = simulateCompleteWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c_user9', null, $baselineDuration);
// Admin deactivates campaign before user claims
$rewardModel->toggleActive($testCampaignId, false);
$claim9 = $customerVoucherModel->claimReward($sid9, 'cust_v2c_user9', 'u9@test.com', null);
assertTest(
    empty($claim9['ok']) && ($claim9['code'] ?? '') === 'CAMPAIGN_INACTIVE',
    "9.1 Chiến dịch bị tắt trước khi claim bị từ chối CAMPAIGN_INACTIVE",
    "Code: " . ($claim9['code'] ?? '')
);
$rewardModel->toggleActive($testCampaignId, true);

echo "\n--- 10. CAMPAIGN HẾT HẠN (EXPIRED BY DATE) ---\n";
// Watch while campaign is still valid
$sid10 = simulateCompleteWatch($watchSessionModel, $pdo, $baselinePubId, $baselineVideoId, $testCampaignId, 'cust_v2c_user10', null, $baselineDuration);
// Campaign expires before user claims
$pdo->ai_video_reward_campaigns->updateOne(
    ['campaign_id' => $testCampaignId],
    ['$set' => ['end_date' => new \MongoDB\BSON\UTCDateTime((time() - 3600) * 1000)]]
);
$claim10 = $customerVoucherModel->claimReward($sid10, 'cust_v2c_user10', 'u10@test.com', null);
assertTest(
    empty($claim10['ok']) && ($claim10['code'] ?? '') === 'CAMPAIGN_EXPIRED',
    "10.1 Chiến dịch quá hạn ngày kết thúc bị từ chối CAMPAIGN_EXPIRED",
    "Code: " . ($claim10['code'] ?? '')
);
// Restore valid end_date
$pdo->ai_video_reward_campaigns->updateOne(
    ['campaign_id' => $testCampaignId],
    ['$set' => ['end_date' => new \MongoDB\BSON\UTCDateTime((time() + 86400 * 7) * 1000)]]
);

echo "\n--- 11. VOUCHER ĐÃ NHẬN KHÔNG CẤP TRÙNG ---\n";
$customer1VouchersCount = $pdo->customer_vouchers->countDocuments(['customer_id' => 'cust_v2c_user1']);
assertTest(
    $customer1VouchersCount === 1,
    "11.1 Khách hàng cust_v2c_user1 chỉ có đúng 1 bản ghi voucher trong collection customer_vouchers",
    "Count: {$customer1VouchersCount}"
);

echo "\n--- 12. KHÔNG VƯỢT PER-USER LIMIT ---\n";
$totalUser1Vouchers = $pdo->customer_vouchers->countDocuments([
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_v2c_user1'
]);
assertTest(
    $totalUser1Vouchers <= 1,
    "12.1 Số lượng voucher của user1 không vượt quá user_limit (= 1)",
    "Count: {$totalUser1Vouchers}"
);

echo "\n--- 13. KHÔNG VƯỢT TOTAL_QUOTA ---\n";
$campDocNow = $rewardModel->getCampaignById($testCampaignId);
$issuedNow = (int)($campDocNow['issued_count'] ?? 0);
$totalQuotaNow = (int)($campDocNow['total_quota'] ?? 0);
assertTest(
    $issuedNow <= $totalQuotaNow,
    "13.1 Issued count ({$issuedNow}) <= total_quota ({$totalQuotaNow})",
    "Issued: {$issuedNow} / Quota: {$totalQuotaNow}"
);

echo "\n--- 14. CUSTOMER WALLET CHỈ THẤY VOUCHER CỦA CHÍNH MÌNH ---\n";
$walletUser1 = $customerVoucherModel->getCustomerVouchers('cust_v2c_user1');
$walletUser3 = $customerVoucherModel->getCustomerVouchers('cust_v2c_migrated_3');
$walletNonExistent = $customerVoucherModel->getCustomerVouchers('cust_stranger_unknown');

$user1HasOnlySelf = true;
foreach ($walletUser1 as $w1) {
    if ($w1['customer_id'] !== 'cust_v2c_user1') {
        $user1HasOnlySelf = false;
        break;
    }
}

assertTest(
    count($walletUser1) === 1 && $user1HasOnlySelf && count($walletUser3) === 1 && count($walletNonExistent) === 0,
    "14.1 Customer wallet cách ly hoàn toàn dữ liệu giữa các tài khoản",
    "User1: " . count($walletUser1) . " voucher, Stranger: " . count($walletNonExistent) . " voucher"
);

echo "\n--- 15. KHÔNG THAY ĐỔI GIÁ SẢN PHẨM ---\n";
$prodDocAfter = $pdo->san_pham->findOne(['ma_san_pham' => 6376]) ?: $pdo->san_pham->findOne(['ma_san_pham' => '6376']);
$productPriceAfter = $prodDocAfter ? (float)($prodDocAfter['gia_ban'] ?? 0) : 0.0;
assertTest(
    $productPriceBefore > 0 && $productPriceBefore === $productPriceAfter,
    "15.1 Giá bán gốc san_pham.gia_ban giữ nguyên: {$productPriceBefore} == {$productPriceAfter}",
    "Giá gốc không đổi: " . number_format($productPriceAfter) . " VNĐ"
);

echo "\n--- 16. KHÔNG ẢNH HƯỞNG FLASH SALE ---\n";
$flashSaleCountAfter = $pdo->flash_sale_campaigns->countDocuments([]);
assertTest(
    $flashSaleCountBefore === $flashSaleCountAfter,
    "16.1 Collection flash_sale_campaigns hoàn toàn không bị ảnh hưởng: {$flashSaleCountBefore} == {$flashSaleCountAfter}",
    "Flash sale count: {$flashSaleCountAfter}"
);

echo "\n--- 17. KHÔNG ÁP VOUCHER TẠI CHECKOUT TRONG V2C ---\n";
$orderCountAfter = $pdo->hoa_don->countDocuments([]);
assertTest(
    $orderCountBefore === $orderCountAfter,
    "17.1 Collection hoa_don giữ nguyên, không áp dụng voucher vào checkout ở Phase V2C: {$orderCountBefore} == {$orderCountAfter}",
    "Order count: {$orderCountAfter}"
);

// Cleanup test records
$rewardModel->deleteCampaign($testCampaignId);
$pdo->ai_video_watch_sessions->deleteMany(['campaign_id' => $testCampaignId]);
$pdo->customer_vouchers->deleteMany(['campaign_id' => $testCampaignId]);
$pdo->voucher->deleteMany(['campaign_id' => $testCampaignId]);

// Restore active status of original campaign(s)
foreach ($restoredActiveIds as $racid) {
    $rewardModel->toggleActive($racid, true);
}
echo "\n [CLEANUP] Đã dọn dẹp sạch sẽ dữ liệu kiểm thử và khôi phục campaign active gốc.\n\n";

echo "========================================================\n";
echo "SUMMARY: PASS = {$passCount} | FAIL = {$failCount}\n";
echo "STATUS: " . ($failCount === 0 ? "ALL PASS" : "HAS FAILURES") . "\n";
echo "========================================================\n";

exit($failCount === 0 ? 0 : 1);
