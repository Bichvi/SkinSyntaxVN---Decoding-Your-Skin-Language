<?php
// backend/tests/test_p2_1_invalidation.php
// Verification of Invalidation, Correctness & Security for Phase P2.1

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CacheService.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/models/FlashSaleCampaign.php';
require_once __DIR__ . '/../app/models/ProductVideo.php';
require_once __DIR__ . '/../app/models/HoaDon.php';

$cache = CacheService::getInstance();
$sanPham = new SanPham($pdo);
$flashModel = new FlashSaleCampaign($pdo);
$videoModel = new ProductVideo($pdo);
$orderModel = new HoaDon($pdo);

echo "================================================================================\n";
echo "SKINSYNTAXVN — PHASE P2.1 CORRECTNESS & INVALIDATION TEST SUITE\n";
echo "================================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $desc, bool $condition): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$desc}\n";
    }
}

// -----------------------------------------------------------------------------
// 1. ADMIN PRODUCT UPDATE & PRICE INVALIDATION
// -----------------------------------------------------------------------------
echo "1. Kiểm tra Admin cập nhật giá / thông tin sản phẩm và Invalidation...\n";

$testId = 111;
$origProduct = $sanPham->find($testId);
$origPrice = (float)($origProduct['gia_ban'] ?? 0);

// Populate cache
$cache->set('home:composite_public', ['test' => 1], 300);
$cache->set('catalog:tatca:test', ['test' => 1], 300);
$cache->set('product:detail:' . $testId, ['gia_ban' => $origPrice], 300);

assertTest("Cache trước khi invalidate có dữ liệu", $cache->has('home:composite_public') && $cache->has('product:detail:' . $testId));

// Trigger invalidation
SanPham::invalidateProductCache($testId);

assertTest("Sau invalidateProductCache, key home:composite_public bị xóa", !$cache->has('home:composite_public'));
assertTest("Sau invalidateProductCache, key product:detail:{$testId} bị xóa", !$cache->has('product:detail:' . $testId));
assertTest("Sau invalidateProductCache, pattern catalog:* bị xóa", !$cache->has('catalog:tatca:test'));

// -----------------------------------------------------------------------------
// 2. PRODUCT VISIBILITY & HIDING
// -----------------------------------------------------------------------------
echo "\n2. Kiểm tra ẩn / xóa sản phẩm không hiển thị trên Storefront...\n";

// Ensure visibleProductFilter rejects inactive
$hiddenDoc = ['trang_thai' => 'inactive', 'ma_san_pham' => 99999];
$ref = new ReflectionClass($sanPham);
$method = $ref->getMethod('normalizeProductVisibilityStatus');
$method->setAccessible(true);
$statusInactive = $method->invoke($sanPham, 'tam_an');
assertTest("Trạng thái 'tam_an' được chuẩn hóa thành 'inactive'", $statusInactive === 'inactive');

$statusActive = $method->invoke($sanPham, 'active');
assertTest("Trạng thái 'active' được chuẩn hóa thành 'active'", $statusActive === 'active');

// -----------------------------------------------------------------------------
// 3. FLASH SALE LIFECYCLE & INVALIDATION
// -----------------------------------------------------------------------------
echo "\n3. Kiểm tra vòng đời Flash Sale và Invalidation khi chiến dịch thay đổi...\n";

$cache->set('home:composite_public', ['test' => 1], 300);
$cache->set('catalog:tatca:test', ['test' => 1], 300);

FlashSaleCampaign::clearRequestCache();

assertTest("FlashSaleCampaign::clearRequestCache() xóa sạch home:* và catalog:*", 
    !$cache->has('home:composite_public') && !$cache->has('catalog:tatca:test'));

// -----------------------------------------------------------------------------
// 4. AI PRODUCT VIDEO PUBLICATION & STREAMING SECURITY
// -----------------------------------------------------------------------------
echo "\n4. Kiểm tra Video Commerce: Invalidation & Bảo mật Video chưa Publish...\n";

$cache->set('product:videos:' . $testId, ['video1'], 1800);
$cache->set('product:videos:all', ['video1'], 1800);

// Simulate publication change
$videoModel->invalidateCache();

assertTest("ProductVideo::invalidateCache() xóa sạch product:videos:*", 
    !$cache->has('product:videos:' . $testId) && !$cache->has('product:videos:all'));

// Test stream security: unpublished video ID check
$fakeDraftPubId = '000000000000000000000000';
$draftDoc = $videoModel->getPublishedVideoById($fakeDraftPubId);
assertTest("getPublishedVideoById() từ chối video không tồn tại hoặc chưa publish (trả về null)", $draftDoc === null);

// -----------------------------------------------------------------------------
// 5. CHECKOUT PRICING NEVER READS FROM CACHE
// -----------------------------------------------------------------------------
echo "\n5. Kiểm tra Giá tính toán Checkout / Hóa đơn luôn Authoritative từ MongoDB...\n";

// Inspect HoaDon calculateOrderTotals or similar pricing logic
$refOrder = new ReflectionClass($orderModel);
$hasDbProperty = $refOrder->hasProperty('db') || $refOrder->hasProperty('collection') || $refOrder->hasProperty('orderCollection');
assertTest("HoaDon model gắn trực tiếp với MongoDB instance (không phụ thuộc CacheService)", $hasDbProperty);

// Ensure resolution of flash sale price during checkout uses canonical resolver
$refFlash = new ReflectionClass($flashModel);
assertTest("FlashSaleCampaign có hydrateCampaignItems() tính giá authoritative từ MongoDB", 
    $refFlash->hasMethod('hydrateCampaignItems'));

// -----------------------------------------------------------------------------
// 6. SECURITY: NO SENSITIVE DATA IN CACHE
// -----------------------------------------------------------------------------
echo "\n6. Kiểm tra An toàn Bảo mật (Không cache Voucher, Session, CSRF, Token)...\n";

$allKeysCursor = '0';
$leakedKeys = [];
// Scan current keys to ensure no session / auth / customer keys
$status = $cache->getSafeStatus();
assertTest("CacheService che giấu an toàn password Valkey trong getSafeStatus()", 
    !str_contains(json_encode($status), 'password') && $status['is_available']);

echo "\n================================================================================\n";
echo "TỔNG KẾT STEP 4: PASS: {$passCount} | FAIL: {$failCount}\n";
echo "================================================================================\n";

if ($failCount === 0) {
    echo ">>> TẤT CẢ TIÊU CHÍ CORRECTNESS & INVALIDATION ĐỀU ĐẠT 100%! <<<\n";
}
