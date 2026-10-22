<?php
// backend/tests/test_p2_3_invalidation_failure.php
// Rigorous Cache Invalidation Failure & Safe Bypass Test Suite for Phase P2.3

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
$sanPham = new SanPham($pdo);
$flashModel = new FlashSaleCampaign($pdo);
$homeCtrl = new HomeController($pdo);

echo "================================================================================\n";
echo "SKINSYNTAXVN — PHASE P2.3 CACHE INVALIDATION FAILURE & SAFE BYPASS SUITE\n";
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

$flagFile = $cache->getInvalidationFailureFlagFile();

try {
    // -------------------------------------------------------------------------
    // 5.1. SIMULATE VALKEY DELETION FAILURE & MARKER RECORDING
    // -------------------------------------------------------------------------
    echo "1. Kiểm tra Ghi nhận Cờ Safe Bypass khi Xóa Valkey Thất Bại...\n";
    
    // Clear any existing flag
    $cache->clearInvalidationFailure();
    assertTest("Khởi tạo: Không tồn tại cờ invalidation failure", !file_exists($flagFile));

    // Simulate invalidation failure on pattern 'home:*'
    $cache->recordInvalidationFailure('home:*', 'Simulated Connection Reset By Peer');
    assertTest("Ghi nhận cờ Invalidation Failure thành công vào storage/cache", file_exists($flagFile));

    $flagData = json_decode((string)file_get_contents($flagFile), true);
    assertTest("Cờ chứa đúng pattern 'home:*' và timestamp", 
        isset($flagData['failed_at'], $flagData['pattern']) && $flagData['pattern'] === 'home:*');

    // -------------------------------------------------------------------------
    // 5.2. SAFE BYPASS MODE ON GET & MGET
    // -------------------------------------------------------------------------
    echo "\n2. Kiểm tra Safe Bypass Mode: Tự động bỏ qua Cache cũ khi Invalidation lỗi...\n";
    
    // Seed a stale key directly into Valkey
    $cache->set('home:composite_public', ['stale_data' => true], 300);

    // Because flag for 'home:*' is active, get('home:composite_public') MUST return default/null (Bypass)
    $bypassedValue = $cache->get('home:composite_public', null);
    assertTest("get('home:composite_public') tự động kích hoạt Safe Bypass, trả về null để ép nạp MongoDB", 
        $bypassedValue === null);

    // mget with bypassed key
    $mgetRes = $cache->mget(['home:composite_public', 'other:unrelated'], 'fallback');
    assertTest("mget() tự động bypass các khóa thuộc pattern bị lỗi invalidation", 
        $mgetRes['home:composite_public'] === 'fallback');

    // -------------------------------------------------------------------------
    // 5.3. CHECKOUT KHÔNG BAO GIỜ BỊ ẢNH HƯỞNG BỞI INVALIDATION FAILURE
    // -------------------------------------------------------------------------
    echo "\n3. Kiểm tra Checkout hoàn toàn độc lập và không bị ảnh hưởng...\n";
    
    $pDoc = $pdo->san_pham->findOne(['trang_thai' => 'active', 'gia_ban' => ['$gt' => 0]]);
    $pPid = (string)($pDoc['ma_san_pham'] ?? '111');
    $authoritativePrice = (int)($pDoc['gia_ban'] ?? 100000);
    $checkoutPrice = $flashModel->resolveEffectivePrice($pPid, null, (array)$pDoc);
    assertTest("Checkout tính giá trực tiếp từ MongoDB Atlas, không bị gián đoạn hay sai lệch giá", 
        (int)$checkoutPrice['effective_price'] === $authoritativePrice && $authoritativePrice > 0);

    // -------------------------------------------------------------------------
    // 5.4. TỰ ĐỘNG PHỤC HỒI KHI INVALIDATION THÀNH CÔNG
    // -------------------------------------------------------------------------
    echo "\n4. Kiểm tra Tự động phục hồi khi Invalidation tiếp theo thành công...\n";
    
    // Successful deletion must clear the failure marker
    $cache->delete('home:composite_public');
    assertTest("Khi xóa thành công, cờ Safe Bypass được dọn dẹp sạch sẽ", !file_exists($flagFile));

    // Cache operations resume normally
    $cache->set('home:test_recovery', ['recovered' => true], 60);
    $recoveredVal = $cache->get('home:test_recovery', null);
    assertTest("Sau khi phục hồi, Cache GET/SET hoạt động bình thường", 
        is_array($recoveredVal) && !empty($recoveredVal['recovered']));
    $cache->delete('home:test_recovery');

    // -------------------------------------------------------------------------
    // 5.5. KHÔNG XÓA TOÀN BỘ VALKEY DATABASE (ZERO FLUSHDB / FLUSHALL)
    // -------------------------------------------------------------------------
    echo "\n5. Kiểm tra Không xóa toàn bộ database Valkey (Zero FLUSHALL)...\n";
    
    // Seed an unrelated namespace key (e.g., ai_idol or session)
    $cache->set('unrelated_domain:keep_me', 'important_data', 300);
    
    // Trigger pattern invalidation for home:*
    $cache->deleteByPattern('home:*');
    
    assertTest("deleteByPattern('home:*') chỉ xóa namespace được chỉ định, bảo tồn các namespace khác", 
        $cache->get('unrelated_domain:keep_me') === 'important_data');
    
    $cache->delete('unrelated_domain:keep_me');

} finally {
    $cache->clearInvalidationFailure();
    $cache->delete('home:composite_public');
    $cache->delete('unrelated_domain:keep_me');
}

echo "\n================================================================================\n";
echo "TỔNG KẾT STEP 5 (CACHE INVALIDATION FAILURE & RESILIENCE): PASS: {$passCount} | FAIL: {$failCount}\n";
echo "================================================================================\n";

if ($failCount === 0) {
    echo ">>> TẤT CẢ CÁC KỊCH BẢN XỬ LÝ LỖI INVALIDATION ĐỀU ĐẠT CHUẨN 100%! <<<\n";
    exit(0);
} else {
    echo ">>> CÓ {$failCount} KỊCH BẢN THẤT BẠI! <<<\n";
    exit(1);
}
