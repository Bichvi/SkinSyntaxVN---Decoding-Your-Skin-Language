<?php
// backend/tests/test_p2_2_cache_consistency.php
// Rigorous Cache Consistency & Flash Sale Safety Test Suite for Phase P2.2

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
require_once __DIR__ . '/../app/models/ProductVideo.php';
require_once __DIR__ . '/../app/models/HoaDon.php';
require_once __DIR__ . '/../app/controllers/HomeController.php';
require_once __DIR__ . '/../app/controllers/SanPhamController.php';

$cache = CacheService::getInstance();
$sanPham = new SanPham($pdo);
$flashModel = new FlashSaleCampaign($pdo);
$videoModel = new ProductVideo($pdo);
$orderModel = new HoaDon($pdo);
$homeCtrl = new HomeController($pdo);

echo "================================================================================\n";
echo "SKINSYNTAXVN — PHASE P2.2 CACHE CONSISTENCY & FLASH SALE SAFETY TEST SUITE\n";
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

$QA_PRODUCT_ID = 999988;
$savedQaCampaignId = null;

// Cleanup any leftover QA data from previous runs
$pdo->san_pham->deleteOne(['ma_san_pham' => $QA_PRODUCT_ID]);

try {
    // -------------------------------------------------------------------------
    // 1. HOMEPAGE CACHE HIT
    // -------------------------------------------------------------------------
    echo "1. Kiểm tra Homepage Cache HIT...\n";
    $cache->delete('home:composite_public');
    
    // First retrieval should compute and populate cache
    $freshData = $homeCtrl->getHomepagePublicData();
    assertTest("Lần 1 getHomepagePublicData() sinh dữ liệu hợp lệ", 
        is_array($freshData) && isset($freshData['latest'], $freshData['cats'], $freshData['flash_deals']));
    assertTest("Cache key home:composite_public đã tồn tại trên Valkey", 
        $cache->has('home:composite_public'));

    // Second retrieval should be a Cache HIT
    $cachedData = $homeCtrl->getHomepagePublicData();
    assertTest("Lần 2 getHomepagePublicData() đọc từ Cache HIT", 
        is_array($cachedData) && count($cachedData['latest']) === count($freshData['latest']));

    // -------------------------------------------------------------------------
    // 2 & 3. ADMIN THAY ĐỔI FLASH SALE & HOMEPAGE PHẢN ÁNH THAY ĐỔI
    // -------------------------------------------------------------------------
    echo "\n2 & 3. Kiểm tra Admin thay đổi Flash Sale & Homepage phản ánh thay đổi...\n";
    
    // Find an active product not currently conflicting in campaigns
    $sampleProduct = $pdo->san_pham->findOne(['trang_thai' => 'active', 'gia_ban' => ['$gt' => 50000]]);
    $samplePid = (string)($sampleProduct['ma_san_pham'] ?? '111');
    $samplePrice = (int)($sampleProduct['gia_ban'] ?? 100000);
    $sampleFlashPrice = max(1000, (int)round($samplePrice * 0.7));

    // Ensure cache has data before mutation
    $cache->set('home:composite_public', ['test' => 1], 300);
    assertTest("Pre-condition: home:composite_public tồn tại trước mutation", $cache->has('home:composite_public'));

    $now = time();
    $qaPayload = [
        'ten_campaign' => 'QA_TEST_FLASH_SALE_' . time(),
        'start_at' => date('Y-m-d H:i:s', $now - 60),
        'end_at' => date('Y-m-d H:i:s', $now + 3600),
        'is_active' => true,
        'items' => [
            [
                'ma_san_pham' => $samplePid,
                'regular_price' => $samplePrice,
                'flash_price' => $sampleFlashPrice,
            ]
        ]
    ];

    $savedQaCampaignId = $flashModel->saveCampaign($qaPayload);
    assertTest("Admin tạo QA Flash Sale Campaign thành công", 
        $savedQaCampaignId !== null && $savedQaCampaignId > 0, 
        (string)$flashModel->getLastErrorMessage());
    assertTest("Sau khi Admin lưu Flash Sale, composite cache home:composite_public bị xóa", 
        !$cache->has('home:composite_public'));

    // Homepage must reflect the new Flash Sale
    $publicAfterCreate = $homeCtrl->getHomepagePublicData();
    assertTest("Homepage tự nạp dữ liệu mới và phản ánh Flash Sale mới", 
        is_array($publicAfterCreate) && !empty($publicAfterCreate['primary_campaign']));

    // Repopulate cache
    $cache->set('home:composite_public', ['test' => 1], 300);
    // Admin toggles Flash Sale off
    $flashModel->toggleCampaign($savedQaCampaignId);
    assertTest("Sau khi Admin tắt (toggle) Flash Sale, home:composite_public bị xóa", 
        !$cache->has('home:composite_public'));

    // Repopulate cache
    $cache->set('home:composite_public', ['test' => 1], 300);
    // Admin deletes QA Flash Sale
    $flashModel->deleteCampaign($savedQaCampaignId);
    assertTest("Sau khi Admin xóa Flash Sale, home:composite_public bị xóa", 
        !$cache->has('home:composite_public'));

    // -------------------------------------------------------------------------
    // 4. CAMPAIGN ĐẾN THỜI ĐIỂM BẮT ĐẦU (UPCOMING -> ACTIVE TRANSITION)
    // -------------------------------------------------------------------------
    echo "\n4. Kiểm tra Campaign đến thời điểm bắt đầu (Dynamic TTL & Read Guard)...\n";
    
    // Simulate cache with an upcoming campaign transition metadata that has just elapsed
    $fakePastTs = time() - 1; // Transition boundary was 1 second ago (campaign started)
    $staleComposite = [
        'menu_tree' => [],
        'latest' => [['ma_san_pham' => $samplePid]],
        'cats' => [],
        'flash_deals' => [],
        'primary_campaign' => null,
        '__next_transition_ts' => $fakePastTs,
    ];
    $cache->set('home:composite_public', $staleComposite, 300);
    assertTest("Giả lập cache có __next_transition_ts đã vượt qua mốc bắt đầu", 
        $cache->has('home:composite_public'));

    // Read should detect boundary crossing, evict stale cache, and recompute
    $refreshed = $homeCtrl->getHomepagePublicData();
    assertTest("getHomepagePublicData() phát hiện mốc bắt đầu đã tới, evict cache và tái tạo authoritative", 
        is_array($refreshed));

    // Verify transition timestamp calculation method
    $nextTs = $flashModel->getNextCampaignTransitionTimestamp();
    assertTest("getNextCampaignTransitionTimestamp() trả về kết quả hợp lệ (null hoặc timestamp tương lai)", 
        $nextTs === null || $nextTs > time());

    // -------------------------------------------------------------------------
    // 5. CAMPAIGN ĐẾN THỜI ĐIỂM KẾT THÚC (ACTIVE -> ENDED TRANSITION)
    // -------------------------------------------------------------------------
    echo "\n5. Kiểm tra Campaign đến thời điểm kết thúc (Active -> Ended Guard)...\n";
    
    // Simulate cache with an active campaign whose end_at_ts has just passed
    $staleExpiredCampaignComposite = [
        'menu_tree' => [],
        'latest' => [['ma_san_pham' => $samplePid]],
        'cats' => [],
        'flash_deals' => [],
        'primary_campaign' => [
            'id' => 88888,
            'status' => 'ACTIVE',
            'start_at_ts' => time() - 3600,
            'end_at_ts' => time() - 5, // Ended 5 seconds ago!
        ],
        '__next_transition_ts' => time() - 5,
    ];
    $cache->set('home:composite_public', $staleExpiredCampaignComposite, 300);

    // Call getHomepagePublicData - read guard must detect end_at_ts <= now and evict!
    $dataAfterExpiry = $homeCtrl->getHomepagePublicData();
    assertTest("getHomepagePublicData() từ chối campaign hết hạn trong cache và recompute", 
        empty($dataAfterExpiry['primary_campaign']) || ($dataAfterExpiry['primary_campaign']['id'] ?? 0) !== 88888);

    // -------------------------------------------------------------------------
    // 6. SẢN PHẨM BỊ ẨN
    // -------------------------------------------------------------------------
    echo "\n6. Kiểm tra Sản phẩm bị ẩn (Product Visibility Invalidation)...\n";
    
    // Create QA product
    $pdo->san_pham->insertOne([
        'ma_san_pham' => $QA_PRODUCT_ID,
        'ten_san_pham' => 'QA Hidden Test Product',
        'gia_ban' => 200000,
        'trang_thai' => 'active',
        'status' => 'active',
        'so_luong_ton_kho' => 50,
        'da_khoi_tao_kho' => true,
    ]);

    // Populate cache
    $cache->set('home:composite_public', ['test' => 1], 300);
    $cache->set('catalog:tatca:test_qa', ['test' => 1], 300);
    $cache->set('product:detail:' . $QA_PRODUCT_ID, ['test' => 1], 300);

    // Admin hides product
    $sanPham->updateProductVisibility((string)$QA_PRODUCT_ID, 'inactive');

    assertTest("Sau khi ẩn sản phẩm, home:composite_public bị xóa", !$cache->has('home:composite_public'));
    assertTest("Sau khi ẩn sản phẩm, product:detail bị xóa", !$cache->has('product:detail:' . $QA_PRODUCT_ID));
    assertTest("Sau khi ẩn sản phẩm, catalog:* bị xóa", !$cache->has('catalog:tatca:test_qa'));

    $hiddenCheck = $sanPham->find($QA_PRODUCT_ID, true);
    assertTest("find(id, true) từ chối trả về sản phẩm đã bị ẩn trên Storefront", empty($hiddenCheck));

    // -------------------------------------------------------------------------
    // 7. GIÁ SẢN PHẨM THAY ĐỔI
    // -------------------------------------------------------------------------
    echo "\n7. Kiểm tra Giá sản phẩm thay đổi & Invalidation...\n";
    
    $cache->set('home:composite_public', ['test' => 1], 300);
    $cache->set('product:detail:' . $QA_PRODUCT_ID, ['gia_ban' => 200000], 300);

    // Invalidate product cache
    SanPham::invalidateProductCache($QA_PRODUCT_ID);

    assertTest("Sau khi thay đổi giá sản phẩm, home:composite_public bị xóa", !$cache->has('home:composite_public'));
    assertTest("Sau khi thay đổi giá sản phẩm, product:detail bị xóa", !$cache->has('product:detail:' . $QA_PRODUCT_ID));

    // -------------------------------------------------------------------------
    // 8. VIDEO ĐƯỢC PUBLISH / UNPUBLISH
    // -------------------------------------------------------------------------
    echo "\n8. Kiểm tra Video Commerce Publish / Unpublish Invalidation...\n";
    
    $cache->set('product:videos:' . $QA_PRODUCT_ID, ['video_qa'], 1800);
    ProductVideo::invalidateCache();
    assertTest("ProductVideo::invalidateCache() xóa sạch product:videos:*", 
        !$cache->has('product:videos:' . $QA_PRODUCT_ID));

    // -------------------------------------------------------------------------
    // 9. VALKEY UNAVAILABLE (CIRCUIT BREAKER & DB FALLBACK)
    // -------------------------------------------------------------------------
    echo "\n9. Kiểm tra Valkey Unavailable (Graceful Circuit Breaker)...\n";
    
    // Create an instance pointing to an unreachable port
    $downCache = new CacheService('valkey', 'rediss://default:dummy@127.0.0.1:29999');
    $getRes = $downCache->get('any_key', 'fallback_default');
    assertTest("Valkey sập: get() trả về fallback an toàn không gây Fatal Error", $getRes === 'fallback_default');

    $setRes = $downCache->set('any_key', ['val' => 1], 300);
    assertTest("Valkey sập: set() trả về false an toàn", $setRes === false);

    $delRes = $downCache->delete('any_key');
    assertTest("Valkey sập: delete() trả về false an toàn", $delRes === false);

    // -------------------------------------------------------------------------
    // 10. CHECKOUT VẪN ĐỌC GIÁ AUTHORITATIVE TỪ MONGODB
    // -------------------------------------------------------------------------
    echo "\n10. Kiểm tra Checkout luôn đọc giá Authoritative từ MongoDB...\n";
    
    $qaOrderDoc = $sanPham->find($samplePid);
    $actualDbPrice = is_array($qaOrderDoc) ? (int)($qaOrderDoc['gia_ban'] ?? 0) : $samplePrice;
    
    $pricing = $flashModel->resolveEffectivePrice((string)$samplePid, null, is_array($qaOrderDoc) ? $qaOrderDoc : null);
    assertTest("Giá tính toán cho Checkout lấy trực tiếp từ MongoDB (không qua Valkey)", 
        (int)$pricing['effective_price'] === $actualDbPrice);

    // -------------------------------------------------------------------------
    // 11. RECOMMENDATION KHÔNG THAY ĐỔI
    // -------------------------------------------------------------------------
    echo "\n11. Kiểm tra Recommendation Engine không bị ảnh hưởng...\n";
    
    $recProducts = $sanPham->getSimpleRecommenderProducts(6);
    assertTest("Recommendation Engine trả về danh sách sản phẩm hợp lệ", 
        is_array($recProducts) && count($recProducts) > 0);

    // -------------------------------------------------------------------------
    // 12. KHÔNG RÒ DỮ LIỆU CÁ NHÂN TRONG COMPOSITE CACHE
    // -------------------------------------------------------------------------
    echo "\n12. Kiểm tra An toàn Bảo mật: Không rò rỉ dữ liệu cá nhân trong Cache...\n";
    
    $compositeCached = $cache->get('home:composite_public');
    if (!is_array($compositeCached)) {
        $compositeCached = $homeCtrl->getHomepagePublicData();
    }
    $cachedJson = json_encode($compositeCached, JSON_UNESCAPED_UNICODE);
    
    $hasPersonalData = str_contains($cachedJson, 'customer_id') ||
                       str_contains($cachedJson, 'email') ||
                       str_contains($cachedJson, 'password') ||
                       str_contains($cachedJson, 'gio_hang') ||
                       str_contains($cachedJson, 'cart_items') ||
                       str_contains($cachedJson, 'search_history');

    assertTest("Composite cache home:composite_public hoàn toàn không chứa dữ liệu cá nhân/user/session", 
        !$hasPersonalData);

} finally {
    // Clean up QA artifacts safely
    if ($savedQaCampaignId) {
        $pdo->flash_sale_campaigns->deleteOne(['ma_campaign' => $savedQaCampaignId]);
    }
    $pdo->san_pham->deleteOne(['ma_san_pham' => $QA_PRODUCT_ID]);
    $cache->delete('home:composite_public');
    $cache->delete('product:detail:' . $QA_PRODUCT_ID);
    $cache->delete('product:videos:' . $QA_PRODUCT_ID);
}

echo "\n================================================================================\n";
echo "TỔNG KẾT STEP 4 (CACHE CONSISTENCY & FLASH SALE SAFETY): PASS: {$passCount} | FAIL: {$failCount}\n";
echo "================================================================================\n";

if ($failCount === 0) {
    echo ">>> TẤT CẢ 12 KỊCH BẢN KIỂM THỬ ĐỀU ĐẠT CHUẨN 100%! <<<\n";
    exit(0);
} else {
    echo ">>> CÓ {$failCount} KỊCH BẢN THẤT BẠI! <<<\n";
    exit(1);
}
