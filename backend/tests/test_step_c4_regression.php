<?php
// backend/tests/test_step_c4_regression.php
// STEP C4 — REGRESSION TEST CHO HỆ THỐNG SAU KHI MIGRATE MAKEUP

require_once __DIR__ . '/../app/config/config.php';

$_ENV['DB_MODE'] = 'local';
$_ENV['MONGO_URI'] = 'mongodb://127.0.0.1:27018';
$_ENV['MONGO_DB_NAME'] = 'skinsyntax_v2';
putenv('DB_MODE=local');
putenv('MONGO_URI=mongodb://127.0.0.1:27018');
putenv('MONGO_DB_NAME=skinsyntax_v2');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/models/GoiYContentBased.php';

echo "======================================================================\n";
echo "STEP C4 — REGRESSION TEST SAU KHI MIGRATE MAKEUP VAO SKINSYNTAX_V2\n";
echo "Database: " . MONGO_DB_NAME . " on " . MONGO_URI . "\n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function check(string $name, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] $name\n";
        $passCount++;
    } else {
        echo "  [FAIL] $name" . ($detail ? " ($detail)" : "") . "\n";
        $failCount++;
    }
}

$spModel = new SanPham($pdo);
$goiY = new GoiYContentBased($pdo);

// -------------------------------------------------------------
// 1. TÌM KIẾM "kem nền", "son kem", "mascara" PHẢI TRẢ VỀ MAKEUP
// -------------------------------------------------------------
echo "1. Kiểm tra Tìm kiếm Makeup ('kem nền', 'son kem', 'mascara')...\n";
$makeupKeywords = [
    'kem nền' => 'Kem Nền',
    'son kem' => 'Son Kem',
    'mascara' => 'Mascara'
];

foreach ($makeupKeywords as $kw => $label) {
    $res = $spModel->searchProducts(['keyword' => $kw], 'popular', 1, 50);
    $items = $res['items'] ?? [];
    $total = $res['total'] ?? 0;
    check("Tìm kiếm '$kw' trả về kết quả (tổng $total sản phẩm)", !empty($items) && $total > 0);
    
    // Kiểm tra có sản phẩm makeup trong kết quả tìm kiếm
    $hasMakeupId = false;
    foreach ($items as $item) {
        $id = (int)($item['ma_san_pham'] ?? $item['id'] ?? 0);
        if ($id >= 1388 && $id <= 3002) {
            $hasMakeupId = true;
            break;
        }
    }
    check("Tìm kiếm '$kw' có chứa sản phẩm Makeup thuộc dải ID 1388..3002", $hasMakeupId);
}

// -------------------------------------------------------------
// 2. CATALOG VÀ CHI TIẾT SẢN PHẨM MAKEUP HOẠT ĐỘNG
// -------------------------------------------------------------
echo "\n2. Kiểm tra Catalog và Chi tiết sản phẩm Makeup...\n";
// Lấy một sản phẩm makeup tiêu biểu qua find()
$sampleMakeup = $spModel->find('1388');
if (!$sampleMakeup) {
    $sampleMakeup = $spModel->find(1388);
}
check("Lấy chi tiết sản phẩm Makeup ID 1388 thành công qua SanPham::find()", !empty($sampleMakeup));
if ($sampleMakeup) {
    check("Sản phẩm 1388 có tên sản phẩm hợp lệ ('{$sampleMakeup['ten_san_pham']}')", !empty($sampleMakeup['ten_san_pham']));
    check("Sản phẩm 1388 có giá bán > 0 (" . number_format($sampleMakeup['gia_ban'] ?? 0) . " đ)", (float)($sampleMakeup['gia_ban'] ?? 0) > 0);
    check("Sản phẩm 1388 có hình ảnh hợp lệ", !empty($sampleMakeup['link_hinh_anh']));
    check("Sản phẩm 1388 thuộc danh mục Makeup (ma_danh_muc: {$sampleMakeup['ma_danh_muc']})", (int)($sampleMakeup['ma_danh_muc'] ?? 0) >= 22);
    check("Sản phẩm 1388 giữ nguyên loại da gốc ('{$sampleMakeup['loai_da']}', ma_loai_da: {$sampleMakeup['ma_loai_da']})", !empty($sampleMakeup['loai_da']));
    
    // Kiểm tra sản phẩm makeup không áp dụng loại da (VD: 1392)
    $sampleUnassignedMakeup = $spModel->find(1392);
    check("Sản phẩm Makeup không xác định loại da (ID 1392) có loại da 'Không áp dụng' (ma_loai_da: 4)", 
        ($sampleUnassignedMakeup['loai_da'] ?? '') === 'Không áp dụng' && (int)($sampleUnassignedMakeup['ma_loai_da'] ?? 0) === 4);
}

// Lấy danh sách sản phẩm theo danh mục Makeup lá (danh mục 29: Son Kem / Tint)
$catProducts = $spModel->searchProducts(['category' => '29'], 'popular', 1, 10);
check("Lấy sản phẩm theo danh mục lá Makeup [29 - Son Kem] thành công (" . ($catProducts['total'] ?? 0) . " items)", !empty($catProducts['items']));

// Lấy danh sách sản phẩm theo danh mục cha Makeup cấp 1 (danh mục 23: Trang Điểm Môi)
$cap1Products = $spModel->searchProducts(['category' => '23'], 'popular', 1, 10);
check("Lấy sản phẩm theo danh mục cha Makeup [23 - Trang Điểm Môi] thành công (" . ($cap1Products['total'] ?? 0) . " items)", !empty($cap1Products['items']));

// -------------------------------------------------------------
// 3. GIỎ HÀNG VÀ TỒN KHO SẢN PHẨM MAKEUP
// -------------------------------------------------------------
echo "\n3. Kiểm tra Giỏ hàng và Tồn kho Makeup...\n";
$stock = (int)($sampleMakeup['so_luong_ton_kho'] ?? 0);
$status = $sampleMakeup['trang_thai'] ?? '';
$stockStatus = $sampleMakeup['trang_thai_kho'] ?? '';

check("Sản phẩm Makeup có trạng thái active", $status === 'active');
check("Sản phẩm Makeup có trạng thái kho con_hang", $stockStatus === 'con_hang');
check("Sản phẩm Makeup có tồn kho sẵn sàng bán ($stock sản phẩm)", $stock > 0);

// Giả lập cấu trúc item giỏ hàng (Cart Item Structure)
$cartItem = [
    'id' => (int)$sampleMakeup['ma_san_pham'],
    'ten_san_pham' => $sampleMakeup['ten_san_pham'],
    'gia_ban' => (float)$sampleMakeup['gia_ban'],
    'so_luong' => 2,
    'link_hinh_anh' => $sampleMakeup['link_hinh_anh'],
    'thanh_tien' => (float)$sampleMakeup['gia_ban'] * 2
];
check("Cấu trúc item giỏ hàng cho sản phẩm Makeup hợp lệ", $cartItem['thanh_tien'] > 0 && $cartItem['id'] >= 1388);

// -------------------------------------------------------------
// 4 & 5. TRANG GỢI Ý SKINCARE VÀ CÔ LẬP KHÔNG CÓ MAKEUP
// -------------------------------------------------------------
echo "\n4 & 5. Kiểm tra Gợi ý Skincare và Cô lập Không có Makeup...\n";
$skincareLeaves = $goiY->getSkincareCategoryLeafIds();
check("getSkincareCategoryLeafIds trả về chính xác 14 lá skincare (không chứa makeup)", count($skincareLeaves) === 14);

// Test 1: Quiz gợi ý cá nhân hóa theo hồ sơ da
$recProfile = [
    'skin_type' => 'Da dầu',
    'concerns' => ['Mụn', 'Dầu thừa'],
    'budget' => '1000000',
    'is_routine' => false,
    'query_text' => ''
];
$recItems = $goiY->recommendFromPost($recProfile, 12);
check("Gợi ý cá nhân hóa Skincare trả về kết quả (" . count($recItems) . " sản phẩm)", !empty($recItems));

$hasMakeupInRec = false;
foreach ($recItems as $item) {
    $id = (int)($item['ma_san_pham'] ?? $item['id'] ?? 0);
    $cid = (int)($item['ma_danh_muc'] ?? 0);
    if ($id >= 1388 || !in_array($cid, $skincareLeaves, true)) {
        $hasMakeupInRec = true;
        break;
    }
}
check("100% kết quả gợi ý cá nhân hóa Skincare KHÔNG chứa bất kỳ sản phẩm Makeup nào", !$hasMakeupInRec);

// Test 2: Gợi ý chu trình Skincare toàn diện (Full Routine)
$routineProfile = [
    'skin_type' => 'Da hỗn hợp',
    'concerns' => ['Lỗ chân lông to'],
    'budget' => '2000000',
    'is_routine' => true,
    'query_text' => ''
];
$routineItems = $goiY->recommendFromPost($routineProfile, 6);
check("Gợi ý Full Routine Skincare trả về kết quả (" . count($routineItems) . " bước)", !empty($routineItems));

$hasMakeupInRoutine = false;
foreach ($routineItems as $item) {
    $id = (int)($item['ma_san_pham'] ?? $item['id'] ?? 0);
    $cid = (int)($item['ma_danh_muc'] ?? 0);
    if ($id >= 1388 || !in_array($cid, $skincareLeaves, true)) {
        $hasMakeupInRoutine = true;
        break;
    }
}
check("100% chu trình Routine Skincare KHÔNG chứa sản phẩm Makeup", !$hasMakeupInRoutine);

// -------------------------------------------------------------
// 6. CÁC SECTION TRANG CHỦ HOẠT ĐỘNG BÌNH THƯỜNG
// -------------------------------------------------------------
echo "\n6. Kiểm tra các Section sản phẩm Trang chủ...\n";
$sections = $spModel->publicRecommendationSections(6);
check("publicRecommendationSections trả về đủ 5 keys chính", 
    isset($sections['best_seller'], $sections['top_rated'], $sections['discount'], $sections['most_viewed'], $sections['new'])
);
foreach (['best_seller', 'top_rated', 'discount', 'most_viewed', 'new'] as $secKey) {
    check("Section [$secKey] trả về sản phẩm hợp lệ (" . count($sections[$secKey] ?? []) . " items)", !empty($sections[$secKey]));
}

echo "\n======================================================================\n";
echo "TỔNG KẾT STEP C4: PASS: $passCount | FAIL: $failCount\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
