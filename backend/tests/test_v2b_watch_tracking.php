<?php
// backend/tests/test_v2b_watch_tracking.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/VideoRewardCampaign.php';
require_once __DIR__ . '/../app/models/ProductVideo.php';
require_once __DIR__ . '/../app/models/VideoWatchSession.php';
require_once __DIR__ . '/../app/controllers/VideoWatchController.php';

echo "========================================================\n";
echo "SKINSYNTAXVN — PHASE V2B: VIDEO WATCH TRACKING TEST SUITE\n";
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

// 0. Setup Models & Baseline Data
$rewardModel = new VideoRewardCampaign($pdo);
$productVideoModel = new ProductVideo($pdo);
$watchSessionModel = new VideoWatchSession($pdo);

// Find published video
$pub = $pdo->ai_product_videos->findOne(['is_published' => true]);
if (!$pub) {
    echo "FATAL: Không tìm thấy published video trong ai_product_videos!\n";
    exit(1);
}

$baselineVideoId = (string)$pub['video_id'];
$baselinePubId = (string)$pub['_id'];
$baselineDuration = (float)($pub['duration'] ?? 73.8);

echo "Baseline Video: ID={$baselineVideoId}, PubId={$baselinePubId}, Duration={$baselineDuration}s\n\n";

// Snapshot voucher collection count & product price before testing
$voucherCountBefore = $pdo->voucher->countDocuments([]);
$campaignIssuedBefore = 0;

// Deactivate any existing active campaigns for baseline video to ensure test idempotency
$existingActives = $pdo->ai_video_reward_campaigns->find(['video_id' => $baselineVideoId, 'is_active' => true]);
$restoredActiveIds = [];
foreach ($existingActives as $ea) {
    $cid = (string)$ea['campaign_id'];
    $restoredActiveIds[] = $cid;
    $rewardModel->toggleActive($cid, false);
}

// Create active test campaign for baseline video
$campRes = $rewardModel->saveCampaign([
    'campaign_name' => 'Chiến dịch Test V2B Real Watch',
    'video_id' => $baselineVideoId,
    'publication_id' => $baselinePubId,
    'discount_type' => 'percentage',
    'discount_value' => 15,
    'max_discount_amount' => 50000,
    'min_order_amount' => 100000,
    'total_quota' => 500,
    'user_limit' => 1,
    'is_active' => true,
    'start_date' => date('Y-m-d H:i:s', time() - 3600),
    'end_date' => date('Y-m-d H:i:s', time() + 86400 * 7),
]);

assertTest(!empty($campRes['ok']), "Khởi tạo campaign test cho baseline video", $campRes['message'] ?? '');
$testCampaignId = $campRes['campaign']['campaign_id'] ?? '';
echo "Test Campaign ID: {$testCampaignId}\n\n";

// Clean any previous watch sessions for this test campaign
$pdo->ai_video_watch_sessions->deleteMany(['campaign_id' => $testCampaignId]);

echo "--- 1. NORMAL WATCH TO COMPLETION (100% COVERAGE -> ELIGIBLE) ---\n";
$start1 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_normal_watch_01',
]);
assertTest(!empty($start1['ok']) && !empty($start1['session']['watch_session_id']), "1.1 Bắt đầu watch session cho khách hàng đăng nhập", "Session ID: " . ($start1['session']['watch_session_id'] ?? ''));
$sid1 = $start1['session']['watch_session_id'];

// Simulate incremental playback in steps of 5 seconds (5s elapsed per 5s play)
$currentTime = 0.0;
$stepSeconds = 5.0;
$totalSteps = (int)ceil($baselineDuration / $stepSeconds);

for ($step = 1; $step <= $totalSteps; $step++) {
    $currentTime = min($baselineDuration, $step * $stepSeconds);
    // Simulate server elapsed time by updating last_server_time back in past
    $pdo->ai_video_watch_sessions->updateOne(
        ['watch_session_id' => $sid1],
        ['$set' => ['last_server_time' => microtime(true) - 5.0]]
    );
    $hbRes = $watchSessionModel->recordHeartbeat($sid1, $currentTime, 1.0, false);
}

$session1Final = $watchSessionModel->getSessionById($sid1);
assertTest(
    ($session1Final['watch_state'] ?? '') === 'ELIGIBLE' && !empty($session1Final['is_eligible']),
    "1.2 Xem bình thường tuần tự đạt trạng thái ELIGIBLE",
    "Coverage: " . ($session1Final['watched_coverage_percent'] ?? 0) . "%, Watched: " . ($session1Final['watched_seconds'] ?? 0) . "s"
);

echo "\n--- 2. PAUSE / RESUME (NO ACCUMULATION DURING PAUSE) ---\n";
$start2 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_pause_resume_02',
]);
$sid2 = $start2['session']['watch_session_id'];

// Watch 0 to 10s
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid2], ['$set' => ['last_server_time' => microtime(true) - 10.0]]);
$watchSessionModel->recordHeartbeat($sid2, 10.0, 1.0, false);
$s2AfterPlay = $watchSessionModel->getSessionById($sid2);
$watchedBeforePause = (float)($s2AfterPlay['watched_seconds'] ?? 0);

// Pause for 3 heartbeats (position remains at 10.0s)
for ($p = 0; $p < 3; $p++) {
    $pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid2], ['$set' => ['last_server_time' => microtime(true) - 4.0]]);
    $watchSessionModel->recordHeartbeat($sid2, 10.0, 1.0, true);
}
$s2AfterPause = $watchSessionModel->getSessionById($sid2);
$watchedAfterPause = (float)($s2AfterPause['watched_seconds'] ?? 0);

assertTest(
    abs($watchedBeforePause - $watchedAfterPause) < 0.01,
    "2.1 Khi pause, thời lượng xem không bị cộng dồn",
    "Before: {$watchedBeforePause}s == After: {$watchedAfterPause}s"
);

// Resume and play from 10s to 15s
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid2], ['$set' => ['last_server_time' => microtime(true) - 5.0]]);
$watchSessionModel->recordHeartbeat($sid2, 15.0, 1.0, false);
$s2AfterResume = $watchSessionModel->getSessionById($sid2);
assertTest(
    (float)($s2AfterResume['watched_seconds'] ?? 0) >= 14.5,
    "2.2 Khi resume, tiếp tục tích lũy tiến độ xem hợp lệ",
    "Watched: " . ($s2AfterResume['watched_seconds'] ?? 0) . "s"
);

echo "\n--- 3. SEEK FROM START TO END (FAST FORWARD JUMP REJECTED) ---\n";
$start3 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_seeker_03',
]);
$sid3 = $start3['session']['watch_session_id'];

// User at 0s jumps immediately to 70s in 2 seconds elapsed server time
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid3], ['$set' => ['last_server_time' => microtime(true) - 2.0]]);
$seekHb = $watchSessionModel->recordHeartbeat($sid3, 70.0, 1.0, false);

assertTest(
    !empty($seekHb['was_seek']) && ($seekHb['is_eligible'] ?? false) === false,
    "3.1 Tua từ đầu tới cuối bị phát hiện là seek và không được cấp ELIGIBLE",
    "Was seek: " . ($seekHb['was_seek'] ? 'YES' : 'NO') . " | Coverage: " . ($seekHb['watched_coverage_percent'] ?? 0) . "%"
);
$s3Final = $watchSessionModel->getSessionById($sid3);
assertTest(
    (float)($s3Final['watched_seconds'] ?? 0) < 5.0 && ($s3Final['watch_state'] ?? '') === 'IN_PROGRESS',
    "3.2 Tổng số giây đã xem không bị tăng vọt sau khi tua",
    "Watched seconds: " . ($s3Final['watched_seconds'] ?? 0) . "s"
);

echo "\n--- 4. PLAYBACK RATE VALIDATION (1.5x / 2.0x ALLOWED, 10x REJECTED) ---\n";
$start4 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_speed_04',
]);
$sid4 = $start4['session']['watch_session_id'];

// 1.5x speed: 4s server elapsed = 6s video advance (Allowed)
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid4], ['$set' => ['last_server_time' => microtime(true) - 4.0]]);
$hb15x = $watchSessionModel->recordHeartbeat($sid4, 6.0, 1.5, false);
assertTest(
    empty($hb15x['was_seek']) && (float)($hb15x['watched_seconds'] ?? 0) >= 5.5,
    "4.1 Tốc độ phát 1.5x hợp lệ được chấp nhận",
    "Watched: " . ($hb15x['watched_seconds'] ?? 0) . "s"
);

// 10x speed cheat: 2s server elapsed claimed 20s advance (Rejected as seek)
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid4], ['$set' => ['last_server_time' => microtime(true) - 2.0]]);
$hb10x = $watchSessionModel->recordHeartbeat($sid4, 26.0, 10.0, false);
assertTest(
    !empty($hb10x['was_seek']),
    "4.2 Gian lận tăng tốc 10x bị phát hiện và từ chối",
    "Was seek: " . ($hb10x['was_seek'] ? 'YES' : 'NO')
);

echo "\n--- 5. RELOAD MIDWAY (RESUMES PROGRESS WITHOUT AUTO-INCREMENT) ---\n";
$start5 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_reload_05',
]);
$sid5 = $start5['session']['watch_session_id'];

// Watch 0 to 20s
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid5], ['$set' => ['last_server_time' => microtime(true) - 20.0]]);
$watchSessionModel->recordHeartbeat($sid5, 20.0, 1.0, false);
$s5BeforeReload = $watchSessionModel->getSessionById($sid5);

// User reloads page: calls startOrResumeSession again with same customer_id
$reloadRes = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_reload_05',
]);

assertTest(
    !empty($reloadRes['resumed']) && ($reloadRes['session']['watch_session_id'] ?? '') === $sid5,
    "5.1 Reload trang khôi phục đúng session đang dang dở",
    "Resumed session: " . ($reloadRes['session']['watch_session_id'] ?? '')
);
assertTest(
    abs((float)$reloadRes['session']['watched_seconds'] - (float)$s5BeforeReload['watched_seconds']) < 0.01,
    "5.2 Reload trang không tự động cộng thêm tiến độ",
    "Watched seconds giữ nguyên: " . ($reloadRes['session']['watched_seconds'] ?? 0) . "s"
);

echo "\n--- 6. MULTIPLE TABS / CONCURRENT HEARTBEATS ON SAME VIDEO ---\n";
$start6 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_multitab_06',
]);
$sid6 = $start6['session']['watch_session_id'];

// Tab 1 sends heartbeat at t=10s
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid6], ['$set' => ['last_server_time' => microtime(true) - 10.0]]);
$watchSessionModel->recordHeartbeat($sid6, 10.0, 1.0, false);

// Tab 2 immediately sends heartbeat 0.5s later (Rate limit triggers)
$tab2Hb = $watchSessionModel->recordHeartbeat($sid6, 10.5, 1.0, false);
assertTest(
    ($tab2Hb['code'] ?? '') === 'RATE_LIMITED',
    "6.1 Tab thứ 2 gửi trùng thời điểm bị chặn bởi Rate Limiting",
    "Code: " . ($tab2Hb['code'] ?? 'none')
);

echo "\n--- 7. REPLAY HEARTBEAT REQUEST (IDEMPOTENT / DUPLICATE IGNORED) ---\n";
// Send same position twice
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid6], ['$set' => ['last_server_time' => microtime(true) - 5.0]]);
$hbReplay1 = $watchSessionModel->recordHeartbeat($sid6, 15.0, 1.0, false);
$s6BeforeReplay = $watchSessionModel->getSessionById($sid6);

$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid6], ['$set' => ['last_server_time' => microtime(true) - 5.0]]);
$hbReplay2 = $watchSessionModel->recordHeartbeat($sid6, 15.0, 1.0, false);
$s6AfterReplay = $watchSessionModel->getSessionById($sid6);

assertTest(
    abs((float)$s6BeforeReplay['watched_seconds'] - (float)$s6AfterReplay['watched_seconds']) < 0.01,
    "7.1 Replay cùng một vị trí video không làm tăng tổng số giây đã xem",
    "Watched: " . ($s6AfterReplay['watched_seconds'] ?? 0) . "s"
);

echo "\n--- 8. FAKE PROGRESS: CLIENT SENDS FAKE 100% OR DIRECT ELIGIBLE REQUEST ---\n";
// Attempt to call validateCompletion directly on incomplete session
$valComp = $watchSessionModel->validateCompletion($sid6);
assertTest(
    empty($valComp['is_eligible']) && ($valComp['watch_state'] ?? '') === 'IN_PROGRESS',
    "8.1 Gọi validateCompletion khi chưa đủ độ phủ bị từ chối ELIGIBLE",
    "Remaining seconds: " . ($valComp['remaining_seconds'] ?? 0) . "s"
);

echo "\n--- 9. SEND HEARTBEAT FOR DIFFERENT VIDEO / INVALID SESSION ---\n";
$hbDiffVideo = $watchSessionModel->recordHeartbeat('fake_non_existent_session_id', 10.0, 1.0, false);
assertTest(
    empty($hbDiffVideo['ok']) && ($hbDiffVideo['code'] ?? '') === 'SESSION_NOT_FOUND',
    "9.1 Gửi heartbeat cho session không tồn tại bị từ chối 404",
    "Code: " . ($hbDiffVideo['code'] ?? '')
);

echo "\n--- 10. CAMPAIGN DEACTIVATED MID-WATCH ---\n";
$start10 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_deactivate_10',
]);
$sid10 = $start10['session']['watch_session_id'];

// Admin deactivates campaign
$rewardModel->toggleActive($testCampaignId, false);

// Client sends heartbeat
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid10], ['$set' => ['last_server_time' => microtime(true) - 5.0]]);
$hbDeact = $watchSessionModel->recordHeartbeat($sid10, 10.0, 1.0, false);

assertTest(
    empty($hbDeact['ok']) && ($hbDeact['watch_state'] ?? '') === 'EXPIRED',
    "10.1 Chiến dịch bị tắt giữa lúc xem chuyển trạng thái session thành EXPIRED",
    "Watch state: " . ($hbDeact['watch_state'] ?? '')
);

// Re-activate campaign for remaining tests
$rewardModel->toggleActive($testCampaignId, true);

echo "\n--- 11. CAMPAIGN EXPIRED BY DATE ---\n";
// Create expired campaign
$campExpiredRes = $rewardModel->saveCampaign([
    'campaign_name' => 'Chiến dịch Test Đã Hết Hạn',
    'video_id' => $baselineVideoId,
    'publication_id' => $baselinePubId,
    'discount_type' => 'percentage',
    'discount_value' => 10,
    'total_quota' => 100,
    'is_active' => false,
    'start_date' => date('Y-m-d H:i:s', time() - 86400 * 10),
    'end_date' => date('Y-m-d H:i:s', time() - 86400 * 2), // Ended 2 days ago
]);
$expCampId = $campExpiredRes['campaign']['campaign_id'] ?? '';

$startExp = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $expCampId,
    'customer_id' => 'cust_expired_11',
]);
assertTest(
    empty($startExp['ok']) && ($startExp['code'] ?? '') === 'NO_ACTIVE_CAMPAIGN',
    "11.1 Từ chối mở session cho chiến dịch đã hết hạn",
    "Code: " . ($startExp['code'] ?? '')
);
$rewardModel->deleteCampaign($expCampId);

echo "\n--- 12. GUEST SESSION VS LOGGED-IN SESSION ---\n";
$startGuest = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'guest_session_id' => 'guest_session_token_xyz_123',
]);
assertTest(
    !empty($startGuest['ok']) && empty($startGuest['session']['customer_id']) && !empty($startGuest['session']['is_guest']),
    "12.1 Khách vãng lai khởi tạo watch session thành công với is_guest = true",
    "Guest session: " . ($startGuest['session']['guest_session_id'] ?? '')
);

echo "\n--- 13. VIDEO WITHOUT REWARD CAMPAIGN ---\n";
// Dummy publication without campaign
$startNoCamp = $watchSessionModel->startOrResumeSession([
    'publication_id' => '000000000000000000000000',
    'video_id' => 'non_existent_video_sku',
]);
assertTest(
    empty($startNoCamp['ok']) && in_array($startNoCamp['code'] ?? '', ['VIDEO_NOT_FOUND', 'NO_ACTIVE_CAMPAIGN'], true),
    "13.1 Video không tồn tại hoặc không có campaign trả về lỗi rõ ràng",
    "Code: " . ($startNoCamp['code'] ?? '')
);

echo "\n--- 14. VIDEO UNPUBLISHED ---\n";
// Temporarily unpublish video
$productVideoModel->setPublishedStatus($baselinePubId, false);
$startUnpub = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
]);
assertTest(
    empty($startUnpub['ok']) && ($startUnpub['code'] ?? '') === 'VIDEO_NOT_FOUND',
    "14.1 Từ chối theo dõi video đã bị gỡ xuất bản (chuyển sang nháp)",
    "Code: " . ($startUnpub['code'] ?? '')
);
// Restore published state
$productVideoModel->setPublishedStatus($baselinePubId, true);

echo "\n--- 15. REGRESSION CHECK: NO VOUCHER ISSUED IN V2B & QUOTA UNTOUCHED ---\n";
$voucherCountAfter = $pdo->voucher->countDocuments([]);
$campaignDocFinal = $rewardModel->getCampaignById($testCampaignId);

assertTest(
    $voucherCountBefore === $voucherCountAfter,
    "15.1 Collection voucher hoàn toàn không thay đổi: {$voucherCountBefore} == {$voucherCountAfter} (Không tạo voucher)",
    "Count: {$voucherCountAfter}"
);
assertTest(
    (int)($campaignDocFinal['issued_count'] ?? 0) === 0,
    "15.2 Issued count của chiến dịch vẫn là 0 (Không tự trừ hạn ngạch trong Phase V2B)",
    "Issued count: " . ($campaignDocFinal['issued_count'] ?? 0)
);

// Clean up test data
$rewardModel->deleteCampaign($testCampaignId);
$pdo->ai_video_watch_sessions->deleteMany(['campaign_id' => $testCampaignId]);

// Restore original active campaign(s)
foreach ($restoredActiveIds as $racid) {
    $rewardModel->toggleActive($racid, true);
}
echo " [CLEANUP] Đã dọn dẹp sạch sẽ campaign test & watch sessions test, khôi phục active state.\n\n";

echo "========================================================\n";
echo "SUMMARY: PASS = {$passCount} | FAIL = {$failCount}\n";
echo "STATUS: " . ($failCount === 0 ? "ALL PASS" : "HAS FAILURES") . "\n";
echo "========================================================\n";

exit($failCount === 0 ? 0 : 1);
