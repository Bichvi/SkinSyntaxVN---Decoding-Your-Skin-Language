<?php
// backend/tests/test_v2b1_review.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/VideoRewardCampaign.php';
require_once __DIR__ . '/../app/models/ProductVideo.php';
require_once __DIR__ . '/../app/models/VideoWatchSession.php';
require_once __DIR__ . '/../app/controllers/VideoWatchController.php';

echo "========================================================\n";
echo "SKINSYNTAXVN — PHASE V2B.1: WATCH COMPLETION & SAFETY REVIEW\n";
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

// Snapshot voucher collection count & product price before testing
$voucherCountBefore = $pdo->voucher->countDocuments([]);

// Deactivate any existing active campaigns for baseline video to ensure clean test setup
$existingActives = $pdo->ai_video_reward_campaigns->find(['video_id' => $baselineVideoId, 'is_active' => true]);
$restoredActiveIds = [];
foreach ($existingActives as $ea) {
    $cid = (string)$ea['campaign_id'];
    $restoredActiveIds[] = $cid;
    $rewardModel->toggleActive($cid, false);
}

// Create dedicated test campaign
$campRes = $rewardModel->saveCampaign([
    'campaign_name' => 'Chiến dịch Test V2B.1 Safety Review',
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

assertTest(!empty($campRes['ok']), "Khởi tạo campaign test cho V2B.1 review", $campRes['message'] ?? '');
$testCampaignId = $campRes['campaign']['campaign_id'] ?? '';
echo "Test Campaign ID: {$testCampaignId}\n\n";

// Clean any previous watch sessions for this test campaign
$pdo->ai_video_watch_sessions->deleteMany(['campaign_id' => $testCampaignId]);

echo "--- 1. XEM BÌNH THƯỜNG ĐẾN CUỐI (NORMAL WATCH TO END) ---\n";
$start1 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_v2b1_normal',
]);
$sid1 = $start1['session']['watch_session_id'];

// Incremental watch from 0s to baselineDuration in 5s steps
$currentTime = 0.0;
$stepSeconds = 5.0;
$totalSteps = (int)ceil($baselineDuration / $stepSeconds);

for ($step = 1; $step <= $totalSteps; $step++) {
    $currentTime = min($baselineDuration, $step * $stepSeconds);
    $pdo->ai_video_watch_sessions->updateOne(
        ['watch_session_id' => $sid1],
        ['$set' => ['last_server_time' => microtime(true) - 5.0]]
    );
    $watchSessionModel->recordHeartbeat($sid1, $currentTime, 1.0, false);
}

$session1Final = $watchSessionModel->getSessionById($sid1);
assertTest(
    ($session1Final['watch_state'] ?? '') === 'ELIGIBLE' && !empty($session1Final['is_eligible']),
    "1.1 Xem tuần tự đến cuối video đạt ELIGIBLE thành công",
    "Coverage: " . ($session1Final['watched_coverage_percent'] ?? 0) . "%, Watched: " . ($session1Final['watched_seconds'] ?? 0) . "s"
);

echo "\n--- 2. COVERAGE ĐẠT 95% NHƯNG CHƯA TỚI ĐOẠN CUỐI (STOPPING EARLY) ---\n";
// Watch 0s to 70.15s (70.15 / 73.816 = 95.03% coverage), but STOP before reaching (duration - 2.5s = 71.316s)
$start2 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_v2b1_early_stop',
]);
$sid2 = $start2['session']['watch_session_id'];

$targetEarlyTime = round($baselineDuration * 0.9505, 2); // ~70.16s
$curr2 = 0.0;
while ($curr2 < $targetEarlyTime) {
    $curr2 = min($targetEarlyTime, $curr2 + 5.0);
    $pdo->ai_video_watch_sessions->updateOne(
        ['watch_session_id' => $sid2],
        ['$set' => ['last_server_time' => microtime(true) - 5.0]]
    );
    $hbEarly = $watchSessionModel->recordHeartbeat($sid2, $curr2, 1.0, false);
}

$s2Check = $watchSessionModel->getSessionById($sid2);
$s2ValComp = $watchSessionModel->validateCompletion($sid2);

assertTest(
    (float)($s2Check['watched_coverage_percent'] ?? 0) >= 95.0,
    "2.1 Coverage đã vượt ngưỡng 95%",
    "Coverage: " . ($s2Check['watched_coverage_percent'] ?? 0) . "%"
);
assertTest(
    empty($s2Check['is_eligible']) && ($s2Check['watch_state'] ?? '') === 'IN_PROGRESS',
    "2.2 Dù đạt >=95% coverage, nhưng CHƯA phát đến đoạn đuôi (end_last < duration - 2.5s) -> KHÔNG cấp ELIGIBLE",
    "Watch State: " . ($s2Check['watch_state'] ?? '') . ", is_eligible: " . ($s2Check['is_eligible'] ? 'true' : 'false')
);
assertTest(
    empty($s2ValComp['is_eligible']) && empty($s2ValComp['has_reached_end']),
    "2.3 validateCompletion từ chối rõ ràng và yêu cầu xem đến đoạn kết",
    "Message: " . ($s2ValComp['message'] ?? '')
);

echo "\n--- 3. TUA TỚI CUỐI (SEEK TO END) ---\n";
$start3 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_v2b1_seek_end',
]);
$sid3 = $start3['session']['watch_session_id'];

// From 0s, jump to 73.0s in 2.5s server elapsed time (> 1.8s rate limit, but delta=73s > allowed 4.5s)
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid3], ['$set' => ['last_server_time' => microtime(true) - 2.5]]);
$hbSeek = $watchSessionModel->recordHeartbeat($sid3, 73.0, 1.0, false);
$s3Final = $watchSessionModel->getSessionById($sid3);

assertTest(
    !empty($hbSeek['was_seek']) && empty($s3Final['is_eligible']) && ($s3Final['watch_state'] ?? '') === 'IN_PROGRESS',
    "3.1 Tua tới cuối bị phát hiện là seek, không được cấp ELIGIBLE",
    "Coverage: " . ($s3Final['watched_coverage_percent'] ?? 0) . "%, Watched: " . ($s3Final['watched_seconds'] ?? 0) . "s"
);

echo "\n--- 4. GIẢ MẠO HEARTBEAT / SPAM DÀY ĐẶC ---\n";
$start4 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_v2b1_spam',
]);
$sid4 = $start4['session']['watch_session_id'];

// Send valid heartbeat
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid4], ['$set' => ['last_server_time' => microtime(true) - 4.0]]);
$watchSessionModel->recordHeartbeat($sid4, 4.0, 1.0, false);

// Immediately send 2nd heartbeat 0.2s later (Rate limiting triggers)
$spamHb = $watchSessionModel->recordHeartbeat($sid4, 4.2, 1.0, false);
assertTest(
    ($spamHb['code'] ?? '') === 'RATE_LIMITED',
    "4.1 Heartbeat gửi cách 0.2s bị chặn bởi Rate Limiting",
    "Code: " . ($spamHb['code'] ?? '')
);

echo "\n--- 5. PLAYBACK RATE BẤT THƯỜNG (SPEED SPOOFING) ---\n";
// Attempt 8x speed (advance 16s in 2s server time)
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid4], ['$set' => ['last_server_time' => microtime(true) - 2.0]]);
$hbSpeedCheat = $watchSessionModel->recordHeartbeat($sid4, 20.0, 8.0, false);

assertTest(
    !empty($hbSpeedCheat['was_seek']),
    "5.1 PlaybackRate 8x gian lận bị phát hiện và từ chối ghi nhận khoảng",
    "Was seek: " . ($hbSpeedCheat['was_seek'] ? 'YES' : 'NO')
);

echo "\n--- 6. RELOAD TRANG GIỮA CHỪNG ---\n";
$start6 = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_v2b1_reload',
]);
$sid6 = $start6['session']['watch_session_id'];

// Play 0 to 15s
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid6], ['$set' => ['last_server_time' => microtime(true) - 15.0]]);
$watchSessionModel->recordHeartbeat($sid6, 15.0, 1.0, false);
$s6Before = $watchSessionModel->getSessionById($sid6);

// Simulate page reload
$reloadRes = $watchSessionModel->startOrResumeSession([
    'publication_id' => $baselinePubId,
    'video_id' => $baselineVideoId,
    'campaign_id' => $testCampaignId,
    'customer_id' => 'cust_v2b1_reload',
]);
$s6After = $reloadRes['session'];

assertTest(
    ($s6After['watch_session_id'] ?? '') === $sid6 && abs((float)$s6Before['watched_seconds'] - (float)$s6After['watched_seconds']) < 0.01,
    "6.1 Reload khôi phục đúng session và không tự cộng tiến độ",
    "Watched: " . ($s6After['watched_seconds'] ?? 0) . "s"
);

echo "\n--- 7. MULTI-TAB TRÊN CÙNG VIDEO ---\n";
// Tab 2 sends heartbeat concurrently on session sid6
$multiTabHb = $watchSessionModel->recordHeartbeat($sid6, 15.2, 1.0, false);
assertTest(
    ($multiTabHb['code'] ?? '') === 'RATE_LIMITED',
    "7.1 Multi-tab gửi heartbeat song song bị chặn bởi rate limit",
    "Code: " . ($multiTabHb['code'] ?? '')
);

echo "\n--- 8. CAMPAIGN BỊ TẮT GIỮA LÚC XEM ---\n";
$rewardModel->toggleActive($testCampaignId, false);
$pdo->ai_video_watch_sessions->updateOne(['watch_session_id' => $sid6], ['$set' => ['last_server_time' => microtime(true) - 5.0]]);
$hbDeact = $watchSessionModel->recordHeartbeat($sid6, 20.0, 1.0, false);

assertTest(
    empty($hbDeact['ok']) && ($hbDeact['watch_state'] ?? '') === 'EXPIRED',
    "8.1 Chiến dịch bị tắt giữa lúc xem chuyển session sang EXPIRED",
    "Watch State: " . ($hbDeact['watch_state'] ?? '')
);

// Restore campaign active status for clean state
$rewardModel->toggleActive($testCampaignId, true);

echo "\n--- 9. KHÔNG CẤP VOUCHER THẬT TRONG V2B.1 ---\n";
$voucherCountAfter = $pdo->voucher->countDocuments([]);
assertTest(
    $voucherCountBefore === $voucherCountAfter,
    "9.1 Collection voucher giữ nguyên không đổi: {$voucherCountBefore} == {$voucherCountAfter} (Không tạo voucher)",
    "Voucher count: {$voucherCountAfter}"
);

echo "\n--- 10. KHÔNG THAY ĐỔI QUOTA CHIẾN DỊCH ---\n";
$campFinal = $rewardModel->getCampaignById($testCampaignId);
assertTest(
    (int)($campFinal['issued_count'] ?? 0) === 0 && (int)($campFinal['total_quota'] ?? 0) === 500,
    "10.1 Quota chiến dịch không đổi, issued_count giữ nguyên = 0",
    "Issued count: " . ($campFinal['issued_count'] ?? 0)
);

// Cleanup test campaign
$rewardModel->deleteCampaign($testCampaignId);
$pdo->ai_video_watch_sessions->deleteMany(['campaign_id' => $testCampaignId]);

// Restore original active campaign(s)
foreach ($restoredActiveIds as $racid) {
    $rewardModel->toggleActive($racid, true);
}
echo "\n [CLEANUP] Đã dọn dẹp sạch sẽ campaign test, watch sessions và khôi phục trạng thái active gốc.\n\n";

echo "========================================================\n";
echo "SUMMARY: PASS = {$passCount} | FAIL = {$failCount}\n";
echo "STATUS: " . ($failCount === 0 ? "ALL PASS" : "HAS FAILURES") . "\n";
echo "========================================================\n";

exit($failCount === 0 ? 0 : 1);
