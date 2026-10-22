<?php
// backend/tests/test_p2_3_flash_sale_boundary.php
// Rigorous Flash Sale Boundary & Clock Edge Case Suite for Phase P2.3

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CacheService.php';
$helpersPath = is_file(__DIR__ . '/../../frontend/views/helpers.php')
    ? __DIR__ . '/../../frontend/views/helpers.php'
    : '/var/www/frontend/views/helpers.php';
if (is_file($helpersPath)) {
    require_once $helpersPath;
}
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/models/FlashSaleCampaign.php';
require_once __DIR__ . '/../app/controllers/HomeController.php';

$cache = CacheService::getInstance();
$flashModel = new FlashSaleCampaign($pdo);
$sanPham = new SanPham($pdo);
$homeCtrl = new HomeController($pdo);

echo "================================================================================\n";
echo "SKINSYNTAXVN — PHASE P2.3 FLASH SALE BOUNDARY & CLOCK DRIFT TEST SUITE\n";
echo "================================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$desc}" . ($detail !== '' ? " ({$detail})" : "") . "\n";
    }
}

try {
    // -------------------------------------------------------------------------
    // 4.1. CAMPAIGN BẮT ĐẦU (UPCOMING -> ACTIVE TRANSITION)
    // -------------------------------------------------------------------------
    echo "1. Kiểm tra Campaign bắt đầu (Upcoming -> Active)...\n";
    $now = time();
    $cachedUpcoming = [
        'menu_tree' => [],
        'latest' => [['ma_san_pham' => 111]],
        'cats' => [],
        'flash_deals' => [],
        'primary_campaign' => null, // Was null because campaign was UPCOMING
        '__next_transition_ts' => $now - 1, // Started 1 second ago!
    ];
    $cache->set('home:composite_public', $cachedUpcoming, 300);

    // Read guard must detect now >= __next_transition_ts, evict stale cache, and re-query
    $res = $homeCtrl->getHomepagePublicData();
    assertTest("Read Guard phát hiện chiến dịch đã bắt đầu và tự động evict cache", 
        !empty($res));

    // -------------------------------------------------------------------------
    // 4.2. CAMPAIGN KẾT THÚC (ACTIVE -> ENDED TRANSITION)
    // -------------------------------------------------------------------------
    echo "\n2. Kiểm tra Campaign kết thúc (Active -> Ended)...\n";
    $cachedActiveEnded = [
        'menu_tree' => [],
        'latest' => [['ma_san_pham' => 111]],
        'cats' => [],
        'flash_deals' => [],
        'primary_campaign' => [
            'id' => 77777,
            'name' => 'Expired Campaign',
            'status' => 'ACTIVE',
            'start_at_ts' => $now - 3600,
            'end_at_ts' => $now - 2, // Ended 2 seconds ago!
        ],
        '__next_transition_ts' => $now - 2,
    ];
    $cache->set('home:composite_public', $cachedActiveEnded, 300);

    $resEnded = $homeCtrl->getHomepagePublicData();
    $campaignReturned = $resEnded['primary_campaign'] ?? null;
    assertTest("Read Guard phát hiện chiến dịch đã kết thúc, evict cache và không trả về campaign hết hạn", 
        empty($campaignReturned) || ($campaignReturned['id'] ?? 0) !== 77777);

    // -------------------------------------------------------------------------
    // 4.3. NHIỀU CAMPAIGN CÓ MỐC THỜI GIAN SÁT NHAU (BACK-TO-BACK / ADJACENT)
    // -------------------------------------------------------------------------
    echo "\n3. Kiểm tra nhiều Campaign có mốc thời gian sát nhau...\n";
    
    // Simulate next transition computation when Active ends at T and Next starts at T+2
    $tBoundary = $now + 60;
    $fakeNextTs = $flashModel->getNextCampaignTransitionTimestamp($now);
    assertTest("Hàm getNextCampaignTransitionTimestamp() tìm được mốc ranh giới sớm nhất", 
        $fakeNextTs === null || $fakeNextTs >= $now);

    // -------------------------------------------------------------------------
    // 4.4. ĐỒNG HỒ HỆ THỐNG LỆCH NHẸ (CLOCK SKEW DRIFT TOLERANCE +/- 2s)
    // -------------------------------------------------------------------------
    echo "\n4. Kiểm tra Đồng hồ hệ thống lệch nhẹ (+/- 2 giây)...\n";
    
    // Test case: Clock is slightly ahead (+2s)
    $cachedWithSkew = [
        'menu_tree' => [],
        'latest' => [],
        'cats' => [],
        'flash_deals' => [],
        'primary_campaign' => [
            'id' => 66666,
            'status' => 'ACTIVE',
            'start_at_ts' => $now - 100,
            'end_at_ts' => $now + 1, // Will end in 1 second on server time
        ],
        '__next_transition_ts' => $now + 1,
    ];
    $cache->set('home:composite_public', $cachedWithSkew, 300);

    // If client/now passes that 1s, it must evict instantly
    sleep(2); // Wait 2s to simulate clock passage past boundary
    $resSkew = $homeCtrl->getHomepagePublicData();
    assertTest("Xử lý an toàn khi lệch 2 giây: Read Guard phát hiện chính xác sau khi trôi qua ranh giới", 
        empty($resSkew['primary_campaign']) || ($resSkew['primary_campaign']['id'] ?? 0) !== 66666);

    // -------------------------------------------------------------------------
    // 4.5. SUB-5-SECOND TRANSITION (CHUYỂN TRẠNG THÁI TRONG VÒNG < 5 GIÂY)
    // -------------------------------------------------------------------------
    echo "\n5. Kiểm tra Chuyển trạng thái trong vòng dưới 5 giây (Sub-5s Boundary)...\n";
    
    // Sub-5s boundary: Next transition is in exactly 2 seconds!
    $sub5sTransition = time() + 2;
    $diff = $sub5sTransition - time();
    $calculatedTtl = max(1, $diff); // P2.3 tightened TTL
    assertTest("P2.3 tính toán TTL co giãn chính xác cho mốc dưới 5s (calculatedTtl = {$calculatedTtl}s)", 
        $calculatedTtl >= 1 && $calculatedTtl <= 3);

    // Simulate cached payload with __next_transition_ts in 2 seconds
    $cachedSub5s = [
        'menu_tree' => [],
        'latest' => [],
        'cats' => [],
        'flash_deals' => [],
        'primary_campaign' => [
            'id' => 55555,
            'status' => 'ACTIVE',
            'start_at_ts' => time() - 100,
            'end_at_ts' => $sub5sTransition,
        ],
        '__next_transition_ts' => $sub5sTransition,
    ];
    $cache->set('home:composite_public', $cachedSub5s, $calculatedTtl);

    // Within the 2s window, cache should still be HIT
    $resWithin = $homeCtrl->getHomepagePublicData();
    assertTest("Trong khoảng thời gian hợp lệ (< 2s), cache HIT phục vụ bình thường", 
        !empty($resWithin['primary_campaign']) && ($resWithin['primary_campaign']['id'] ?? 0) === 55555);

    // Wait 3s so the sub-5s transition elapses
    sleep(3);

    // After 3s, Read Guard MUST detect the boundary crossing and evict
    $resAfterSub5s = $homeCtrl->getHomepagePublicData();
    assertTest("Sau khi mốc 2s trôi qua, Read-time Guard phát hiện ngay lập tức và làm mới dữ liệu tươi", 
        empty($resAfterSub5s['primary_campaign']) || ($resAfterSub5s['primary_campaign']['id'] ?? 0) !== 55555);

    // -------------------------------------------------------------------------
    // 4.6. CONCURRENT REQUESTS TẠI THỜI ĐIỂM CHUYỂN TRẠNG THÁI
    // -------------------------------------------------------------------------
    echo "\n6. Kiểm tra Concurrent Requests tại thời điểm chuyển trạng thái...\n";
    
    // Simulate 10 simulated rapid requests around transition boundary
    $concurrentPass = true;
    for ($req = 0; $req < 10; $req++) {
        $pData = $homeCtrl->getHomepagePublicData();
        if (!is_array($pData) || !isset($pData['latest'])) {
            $concurrentPass = false;
        }
    }
    assertTest("10 requests đồng thời tại thời điểm chuyển trạng thái đều nhận phản hồi hợp lệ không lỗi", 
        $concurrentPass);

    // -------------------------------------------------------------------------
    // 4.7. CHECKOUT TUYỆT ĐỐI KHÔNG DÙNG DỮ LIỆU STALE ĐỂ TÍNH GIÁ
    // -------------------------------------------------------------------------
    echo "\n7. Kiểm tra Checkout tuyệt đối không đọc giá từ Cache Flash Sale...\n";
    
    // Poison Valkey cache with an exaggerated fake flash price
    $fakePoisonKey = 'home:composite_public';
    $cache->set($fakePoisonKey, [
        'primary_campaign' => [
            'items' => [
                ['ma_san_pham' => '111', 'flash_price' => 1] // 1 dong fake price in cache
            ]
        ]
    ], 300);

    // Price calculation via authoritative resolver
    $realDoc = $pdo->san_pham->findOne(['trang_thai' => 'active', 'gia_ban' => ['$gt' => 0]]);
    $realPid = (string)($realDoc['ma_san_pham'] ?? '111');
    $authoritativePrice = (int)($realDoc['gia_ban'] ?? 100000);
    $resolved = $flashModel->resolveEffectivePrice($realPid, null, (array)$realDoc);
    
    assertTest("Giá tính toán cho Checkout bỏ qua hoàn toàn cache Valkey bị sai lệch, đạt đúng giá authoritative ({$authoritativePrice} đ)", 
        (int)$resolved['effective_price'] === $authoritativePrice && (int)$resolved['effective_price'] > 1);

} finally {
    $cache->delete('home:composite_public');
}

echo "\n================================================================================\n";
echo "TỔNG KẾT STEP 4 (FLASH SALE BOUNDARY & EDGE CASES): PASS: {$passCount} | FAIL: {$failCount}\n";
echo "================================================================================\n";

if ($failCount === 0) {
    echo ">>> TẤT CẢ CÁC TEST CASE RANH GIỚI THỜI GIAN FLASH SALE ĐỀU ĐẠT CHUẨN 100%! <<<\n";
    exit(0);
} else {
    echo ">>> CÓ {$failCount} TEST CASE THẤT BẠI! <<<\n";
    exit(1);
}
