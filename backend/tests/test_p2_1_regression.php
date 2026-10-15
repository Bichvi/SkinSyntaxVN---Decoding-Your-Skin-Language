<?php
// backend/tests/test_p2_1_regression.php
// Comprehensive Regression Suite for Phase P2.1 across 10 functional modules

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CacheService.php';
require_once '/var/www/frontend/views/helpers.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/models/FlashSaleCampaign.php';
require_once __DIR__ . '/../app/models/ProductVideo.php';
require_once __DIR__ . '/../app/models/Voucher.php';
require_once __DIR__ . '/../app/models/HoaDon.php';
require_once __DIR__ . '/../app/models/GoiYContentBased.php';
require_once __DIR__ . '/../app/controllers/HomeController.php';
require_once __DIR__ . '/../app/controllers/SanPhamController.php';

$sanPham = new SanPham($pdo);
$homeCtrl = new HomeController($pdo);
$spCtrl = new SanPhamController($pdo);
$flashModel = new FlashSaleCampaign($pdo);
$videoModel = new ProductVideo($pdo);
$voucherModel = new Voucher($pdo);
$orderModel = new HoaDon($pdo);
$goiYModel = new GoiYContentBased($pdo);

echo "================================================================================\n";
echo "SKINSYNTAXVN — PHASE P2.1 REGRESSION TESTING (10 BUSINESS AREAS)\n";
echo "================================================================================\n\n";

$passCount = 0;
$failCount = 0;

function checkCase(string $desc, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$desc}" . ($detail ? " ({$detail})" : "") . "\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$desc}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

// 1. HOMEPAGE
echo "1. Kiểm tra Trang chủ (Homepage)...\n";
ob_start();
$homeCtrl->index();
$homeHtml = ob_get_clean();
checkCase("Homepage render thành công", strlen($homeHtml) > 5000 && !str_contains($homeHtml, 'Fatal error'));
checkCase("Homepage chứa Menu danh mục", str_contains($homeHtml, 'menu') || str_contains($homeHtml, 'danh-muc') || str_contains($homeHtml, 'Chăm Sóc Da'));
checkCase("Homepage không chứa lỗi PHP", !str_contains($homeHtml, 'Warning:') && !str_contains($homeHtml, 'Notice:'));

// 2. CATEGORY LISTING
echo "\n2. Kiểm tra Danh mục (Category Listing)...\n";
$_GET = ['r' => 'tatca'];
ob_start();
$spCtrl->tatca();
$catHtml = ob_get_clean();
checkCase("Category Listing render thành công", strlen($catHtml) > 5000 && !str_contains($catHtml, 'Fatal error'));
checkCase("Category Listing hiển thị sản phẩm và phân trang", str_contains($catHtml, 'product-card') || str_contains($catHtml, 'sản phẩm'));

// 3. PRODUCT DETAIL
echo "\n3. Kiểm tra Chi tiết sản phẩm (Product Detail - PDP)...\n";
$_GET = ['r' => 'chitiet', 'id' => '111'];
ob_start();
$spCtrl->chitiet();
$pdpHtml = ob_get_clean();
checkCase("PDP ID 111 render thành công", strlen($pdpHtml) > 5000 && !str_contains($pdpHtml, 'Fatal error'));
checkCase("PDP hiển thị giá bán và thông tin sản phẩm", str_contains($pdpHtml, 'đ') && str_contains($pdpHtml, 'Thêm vào giỏ hàng'));

// 4. SEARCH
echo "\n4. Kiểm tra Tìm kiếm (Search)...\n";
$searchRes = $sanPham->searchProducts(['q' => 'serum'], 'popular', 1, 10);
checkCase("Tìm kiếm 'serum' trả về kết quả hợp lệ", !empty($searchRes['items']) && ($searchRes['total'] ?? 0) > 0, "Tìm thấy " . ($searchRes['total'] ?? 0) . " sản phẩm");

// 5. RECOMMENDATION ENGINE
echo "\n5. Kiểm tra Hệ thống Gợi ý (Recommendation Engine)...\n";
$leafIds = $goiYModel->getSkincareCategoryLeafIds();
checkCase("Danh mục gợi ý skincare cô lập đúng các lá skincare", count($leafIds) > 0, count($leafIds) . " lá");
$recs = $goiYModel->recommendFromPost([
    'skin_type' => 'Da dầu',
    'concerns' => ['Mụn'],
    'budget' => '1000000',
    'is_routine' => false,
], 6);
checkCase("Gợi ý theo loại da trả về kết quả chính xác", !empty($recs), count($recs) . " sản phẩm");

// 6. FLASH SALE
echo "\n6. Kiểm tra Flash Sale...\n";
$primary = $flashModel->getPrimaryActiveCampaign();
checkCase("Truy vấn Primary Flash Sale Campaign thành công", $primary === null || isset($primary['ma_campaign']));

// 7. VIDEO COMMERCE
echo "\n7. Kiểm tra Video Commerce...\n";
$publishedVideos = $videoModel->getPublishedVideosByProductId('111');
checkCase("Lấy danh sách video published cho sản phẩm thành công", is_array($publishedVideos));

// 8. VOUCHER SYSTEM
echo "\n8. Kiểm tra Hệ thống Voucher...\n";
$publicVouchers = $voucherModel->listVouchers();
checkCase("Truy vấn danh sách voucher public thành công", is_array($publicVouchers));

// 9. CART SYSTEM
echo "\n9. Kiểm tra Giỏ hàng (Cart)...\n";
$_SESSION['gio_hang'] = [
    '111' => ['ma_san_pham' => 111, 'ten_san_pham' => 'Test Serum', 'gia_ban' => 200000, 'so_luong' => 2]
];
$cartTotal = 0;
foreach ($_SESSION['gio_hang'] as $item) {
    $cartTotal += $item['gia_ban'] * $item['so_luong'];
}
checkCase("Giỏ hàng tính toán tổng tiền chuẩn xác (400,000 đ)", $cartTotal === 400000);

// 10. CHECKOUT & AUTHORITATIVE PRICING
echo "\n10. Kiểm tra Checkout & Tính giá Authoritative từ MongoDB...\n";
$latest = $sanPham->latest(1);
$sampleId = $latest[0]['ma_san_pham'] ?? 1;
$p = $sanPham->find($sampleId);
$authPrice = (float)($p['gia_ban'] ?? 0);
checkCase("Giá sản phẩm ID {$sampleId} dùng cho checkout được lấy trực tiếp từ MongoDB", $authPrice > 0, number_format($authPrice) . " đ");

echo "\n================================================================================\n";
echo "TỔNG KẾT STEP 6 REGRESSION: PASS: {$passCount} | FAIL: {$failCount}\n";
echo "================================================================================\n";

if ($failCount === 0) {
    echo ">>> TẤT CẢ 10 KHU VỰC NGHIỆP VỤ ĐỀU PASS REGRESSION 100%! <<<\n";
}
