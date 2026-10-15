<?php
// backend/tests/test_v2a_reward_campaign.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/VideoRewardCampaign.php';
require_once __DIR__ . '/../app/models/ProductVideo.php';
require_once __DIR__ . '/../app/controllers/AdminAiIdolController.php';

// Initialize Admin test session before any output
session_start();
$_SESSION['admin_ai_idol_csrf'] = 'valid_v2a_test_csrf_token_secret';
$_SESSION['user'] = [
    'id' => 'staff-test-v2a',
    'role' => 'admin',
    'email' => 'admin@skinsyntax.vn'
];
$testSessionId = session_id();
session_write_close();
@chown("/tmp/sess_{$testSessionId}", 'www-data');
@chmod("/tmp/sess_{$testSessionId}", 0666);

echo "========================================================\n";
echo "SKINSYNTAXVN — PHASE V2A: VIDEO REWARD CAMPAIGN TEST SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $title, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] $title\n";
    } else {
        $failCount++;
        echo " [FAIL] $title: $details\n";
    }
}

// 0. Setup DB & Baseline Check
$rewardModel = new VideoRewardCampaign($pdo);
$productVideoModel = new ProductVideo($pdo);

// Find baseline published READY video
$baselinePub = $pdo->ai_product_videos->findOne([
    'is_published' => true
]);

if (!$baselinePub) {
    echo "FATAL: Không tìm thấy baseline published READY video trong ai_product_videos!\n";
    exit(1);
}

$baselineVideoId = (string)$baselinePub['video_id'];
$baselinePubId = (string)$baselinePub['_id'];
echo "Baseline Video: ID={$baselineVideoId}, PubId={$baselinePubId}, Title=" . ($baselinePub['title'] ?? '') . "\n\n";

// Snapshot product price & voucher collection count for regression test
$prod6376Before = $pdo->san_pham->findOne(['ma_san_pham' => '6376']);
if (!$prod6376Before && is_numeric('6376')) {
    $prod6376Before = $pdo->san_pham->findOne(['ma_san_pham' => 6376]);
}
$baselinePrice = (float)($prod6376Before['gia_ban'] ?? 0);
$voucherCountBefore = $pdo->voucher->countDocuments([]);

// Clean up any pre-existing test campaigns on baseline video for clean test run
$pdo->ai_video_reward_campaigns->deleteMany(['video_id' => $baselineVideoId]);

$createdCampaignId1 = null;
$createdCampaignId2 = null;

// ---------------------------------------------------------
// TEST 1: Create Valid Reward Campaign
// ---------------------------------------------------------
echo "--- 1. CREATE VALID REWARD CAMPAIGN ---\n";
$now = new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
$startStr = $now->format('Y-m-d H:i:s');
$endStr = (clone $now)->modify('+14 days')->format('Y-m-d H:i:s');

$createInput = [
    'video_id' => $baselineVideoId,
    'publication_id' => $baselinePubId,
    'campaign_name' => 'Ưu đãi xem video Syna 3D - Giảm 15%',
    'discount_type' => 'percentage',
    'discount_value' => 15,
    'max_discount_amount' => 50000,
    'min_order_amount' => 100000,
    'applicable_product_ids' => ['6376'],
    'start_date' => $startStr,
    'end_date' => $endStr,
    'total_quota' => 200,
    'user_limit' => 1,
    'is_active' => true,
    'stack_with_flash_sale' => false,
    'stack_with_other_vouchers' => false
];

$res1 = $rewardModel->saveCampaign($createInput);
assertTest(!empty($res1['ok']) && !empty($res1['campaign']['campaign_id']), "Tạo campaign hợp lệ thành công", json_encode($res1));
if (!empty($res1['ok'])) {
    $createdCampaignId1 = $res1['campaign']['campaign_id'];
    assertTest($res1['campaign']['discount_type'] === 'percentage', "Discount type = percentage");
    assertTest((int)$res1['campaign']['discount_value'] === 15, "Discount value = 15%");
    assertTest((int)$res1['campaign']['total_quota'] === 200, "Total quota = 200");
    assertTest($res1['campaign']['stack_with_flash_sale'] === false, "Stacking Flash Sale mặc định = false");
    assertTest((int)$res1['campaign']['issued_count'] === 0, "Issued count ban đầu = 0");
}

// ---------------------------------------------------------
// TEST 2: Reject Invalid Discounts
// ---------------------------------------------------------
echo "\n--- 2. REJECT INVALID DISCOUNTS ---\n";
// 2a. Percentage > 100
$badPercentHigh = $createInput;
$badPercentHigh['campaign_id'] = '';
$badPercentHigh['discount_value'] = 110;
$res2a = $rewardModel->saveCampaign($badPercentHigh);
assertTest(empty($res2a['ok']), "Từ chối phần trăm > 100% (110%)", $res2a['message'] ?? '');

// 2b. Percentage <= 0
$badPercentLow = $createInput;
$badPercentLow['campaign_id'] = '';
$badPercentLow['discount_value'] = 0;
$res2b = $rewardModel->saveCampaign($badPercentLow);
assertTest(empty($res2b['ok']), "Từ chối phần trăm <= 0% (0%)", $res2b['message'] ?? '');

// 2c. Fixed <= 0
$badFixed = $createInput;
$badFixed['campaign_id'] = '';
$badFixed['discount_type'] = 'fixed';
$badFixed['discount_value'] = -20000;
$res2c = $rewardModel->saveCampaign($badFixed);
assertTest(empty($res2c['ok']), "Từ chối fixed discount <= 0 (-20,000đ)", $res2c['message'] ?? '');

// ---------------------------------------------------------
// TEST 3: Reject Invalid Dates
// ---------------------------------------------------------
echo "\n--- 3. REJECT INVALID DATES ---\n";
$badDates = $createInput;
$badDates['campaign_id'] = '';
$badDates['start_date'] = $endStr;
$badDates['end_date'] = $startStr; // Start after end
$res3 = $rewardModel->saveCampaign($badDates);
assertTest(empty($res3['ok']), "Từ chối start_date >= end_date", $res3['message'] ?? '');

// ---------------------------------------------------------
// TEST 4: Reject Invalid Video Reference
// ---------------------------------------------------------
echo "\n--- 4. REJECT INVALID VIDEO REFERENCE ---\n";
$badVideo = $createInput;
$badVideo['campaign_id'] = '';
$badVideo['video_id'] = 'non_existent_video_99999';
$badVideo['publication_id'] = '';
$res4 = $rewardModel->saveCampaign($badVideo);
assertTest(empty($res4['ok']), "Từ chối video_id không tồn tại trong hệ thống", $res4['message'] ?? '');

// ---------------------------------------------------------
// TEST 5: Reject Unpublished / Unready Video
// ---------------------------------------------------------
echo "\n--- 5. REJECT UNPUBLISHED OR UNREADY VIDEO ---\n";
// Create temporary unready publication
$unreadyDoc = [
    'video_id' => 'temp_unready_vid_' . time(),
    'title' => 'Test Unready Video',
    'is_published' => false,
    'render_status' => 'PENDING',
    'associated_product_ids' => ['6376'],
    'created_at' => new \MongoDB\BSON\UTCDateTime()
];
$unreadyInsert = $pdo->ai_product_videos->insertOne($unreadyDoc);
$unreadyId = (string)$unreadyInsert->getInsertedId();

$badUnpublished = $createInput;
$badUnpublished['campaign_id'] = '';
$badUnpublished['video_id'] = $unreadyDoc['video_id'];
$badUnpublished['publication_id'] = $unreadyId;
$res5 = $rewardModel->saveCampaign($badUnpublished);
assertTest(empty($res5['ok']), "Từ chối video chưa xuất bản hoặc chưa READY", $res5['message'] ?? '');

// Clean up temporary unready doc
$pdo->ai_product_videos->deleteOne(['_id' => $unreadyInsert->getInsertedId()]);

// ---------------------------------------------------------
// TEST 6: Reject Duplicate Active Campaign
// ---------------------------------------------------------
echo "\n--- 6. REJECT DUPLICATE ACTIVE CAMPAIGN ---\n";
// Attempt to create a 2nd active campaign on the same baseline video
$dupInput = $createInput;
$dupInput['campaign_id'] = '';
$dupInput['campaign_name'] = 'Chiến dịch trùng lặp số 2';
$dupInput['is_active'] = true;
$res6 = $rewardModel->saveCampaign($dupInput);
assertTest(empty($res6['ok']), "Từ chối tạo thêm chiến dịch ACTIVE thứ 2 cho cùng 1 video", $res6['message'] ?? '');

// ---------------------------------------------------------
// TEST 7: Quota Reduction Protection Below Issued Count
// ---------------------------------------------------------
echo "\n--- 7. QUOTA REDUCTION VALIDATION ---\n";
// Simulate 50 vouchers already issued
$pdo->ai_video_reward_campaigns->updateOne(
    ['campaign_id' => $createdCampaignId1],
    ['$set' => ['issued_count' => 50]]
);

// Attempt reducing quota to 30 (< 50)
$reduceInput = $createInput;
$reduceInput['campaign_id'] = $createdCampaignId1;
$reduceInput['total_quota'] = 30;
$res7a = $rewardModel->saveCampaign($reduceInput);
assertTest(empty($res7a['ok']), "Từ chối giảm total_quota (30) xuống dưới issued_count (50)", $res7a['message'] ?? '');

// Increase quota to 250 (>= 50)
$increaseInput = $createInput;
$increaseInput['campaign_id'] = $createdCampaignId1;
$increaseInput['total_quota'] = 250;
$res7b = $rewardModel->saveCampaign($increaseInput);
assertTest(!empty($res7b['ok']) && (int)$res7b['campaign']['total_quota'] === 250, "Cho phép tăng total_quota lên 250", json_encode($res7b));

// Reset issued_count back to 0
$pdo->ai_video_reward_campaigns->updateOne(
    ['campaign_id' => $createdCampaignId1],
    ['$set' => ['issued_count' => 0]]
);

// ---------------------------------------------------------
// TEST 8: Toggle Active / Inactive
// ---------------------------------------------------------
echo "\n--- 8. TOGGLE ACTIVE & DEACTIVATE ---\n";
// Deactivate campaign 1
$res8a = $rewardModel->toggleActive($createdCampaignId1, false);
assertTest(!empty($res8a['ok']) && $res8a['is_active'] === false, "Tắt kích hoạt campaign 1 thành công", json_encode($res8a));

// Now create campaign 2 as active (since campaign 1 is inactive)
$createInput2 = $createInput;
$createInput2['campaign_id'] = '';
$createInput2['campaign_name'] = 'Chiến dịch thay thế số 2';
$createInput2['is_active'] = true;
$res8b = $rewardModel->saveCampaign($createInput2);
assertTest(!empty($res8b['ok']), "Tạo campaign 2 thành công sau khi campaign 1 đã tắt", json_encode($res8b));
if (!empty($res8b['ok'])) {
    $createdCampaignId2 = $res8b['campaign']['campaign_id'];
}

// Attempt to re-activate campaign 1 while campaign 2 is active -> must fail!
$res8c = $rewardModel->toggleActive($createdCampaignId1, true);
assertTest(empty($res8c['ok']), "Từ chối bật lại campaign 1 khi campaign 2 đang active", $res8c['message'] ?? '');

// Deactivate campaign 2 and re-activate campaign 1
$rewardModel->toggleActive($createdCampaignId2, false);
$res8d = $rewardModel->toggleActive($createdCampaignId1, true);
assertTest(!empty($res8d['ok']) && $res8d['is_active'] === true, "Bật lại campaign 1 thành công sau khi tắt campaign 2", json_encode($res8d));

// ---------------------------------------------------------
// TEST 9: Security RBAC & CSRF HTTP Verification
// ---------------------------------------------------------
echo "\n--- 9. SECURITY RBAC & CSRF ENDPOINTS ---\n";

// 9a. Unauthenticated Guest
$ch = curl_init("http://skinsyntax-nginx/index.php?r=admin_ai_idol_save_reward_campaign");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['video_id' => $baselineVideoId, 'csrf' => 'any']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);
$body9a = curl_exec($ch);
$code9a = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertTest($code9a === 302 || $code9a === 403, "Chặn khách vãng lai (HTTP {$code9a})");

// 9b. Invalid CSRF Token
$ch = curl_init("http://skinsyntax-nginx/index.php?r=admin_ai_idol_save_reward_campaign");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'video_id' => $baselineVideoId,
        'csrf' => 'invalid_csrf_token_fake'
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Cookie: PHPSESSID=' . $testSessionId
    ]
]);
$body9b = curl_exec($ch);
$code9b = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assertTest($code9b === 403, "Chặn CSRF sai mã (HTTP {$code9b})", $body9b);

// 9c. Valid CSRF & Admin Session GET Reward Campaign
$ch = curl_init("http://skinsyntax-nginx/index.php?r=admin_ai_idol_reward_campaign&video_id={$baselineVideoId}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Cookie: PHPSESSID=' . $testSessionId
    ]
]);
$body9c = curl_exec($ch);
$code9c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$json9c = json_decode($body9c, true);
assertTest($code9c === 200 && !empty($json9c['ok']) && !empty($json9c['campaign']), "GET reward campaign thành công qua Admin HTTP (HTTP {$code9c})");

// 9d. Valid CSRF & Admin Session POST Toggle
$ch = curl_init("http://skinsyntax-nginx/index.php?r=admin_ai_idol_toggle_reward_campaign");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'campaign_id' => $createdCampaignId1,
        'is_active' => false,
        'csrf' => 'valid_v2a_test_csrf_token_secret'
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Cookie: PHPSESSID=' . $testSessionId
    ]
]);
$body9d = curl_exec($ch);
$code9d = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$json9d = json_decode($body9d, true);
assertTest($code9d === 200 && !empty($json9d['ok']) && $json9d['is_active'] === false, "POST toggle campaign thành công qua Admin HTTP (HTTP {$code9d})");

// Restore campaign 1 to active
$rewardModel->toggleActive($createdCampaignId1, true);

// ---------------------------------------------------------
// TEST 10: Regression Check — Catalog Prices Untouched
// ---------------------------------------------------------
echo "\n--- 10. REGRESSION CHECK: CATALOG PRICES UNTOUCHED ---\n";
$prod6376After = $pdo->san_pham->findOne(['ma_san_pham' => '6376']);
if (!$prod6376After && is_numeric('6376')) {
    $prod6376After = $pdo->san_pham->findOne(['ma_san_pham' => 6376]);
}
$currentPrice = (float)($prod6376After['gia_ban'] ?? 0);
assertTest($baselinePrice === $currentPrice, "Giá bán sản phẩm 6376 không đổi: {$baselinePrice} == {$currentPrice}");

// ---------------------------------------------------------
// TEST 11: Regression Check — Voucher Collection Untouched
// ---------------------------------------------------------
echo "\n--- 11. REGRESSION CHECK: VOUCHER COLLECTION UNTOUCHED ---\n";
$voucherCountAfter = $pdo->voucher->countDocuments([]);
assertTest($voucherCountBefore === $voucherCountAfter, "Số lượng voucher trong collection voucher không đổi: {$voucherCountBefore} == {$voucherCountAfter} (Không tự phát hành voucher)");

// ---------------------------------------------------------
// TEST 12: Safe Cleanup of Test Artifacts
// ---------------------------------------------------------
echo "\n--- 12. CLEANUP TEST DATA ---\n";
if ($createdCampaignId2) {
    $rewardModel->deleteCampaign($createdCampaignId2);
}
// Clean campaign 1 or restore as configured
$rewardModel->deleteCampaign($createdCampaignId1);
assertTest($rewardModel->getCampaignById($createdCampaignId1) === null, "Đã dọn dẹp sạch sẽ campaign test, giữ toàn vẹn hệ thống");

echo "\n========================================================\n";
echo "SUMMARY: PASS = {$passCount} | FAIL = {$failCount}\n";
echo "STATUS: " . ($failCount === 0 ? "ALL PASS" : "FAILED") . "\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
