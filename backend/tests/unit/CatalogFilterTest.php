<?php
// Test thuần PHP (không DB) cho SanPham::buildCatalogExtraFilter / buildCatalogExtraParts (bộ lọc phụ trang tatca/goiy).
// Chạy: php backend/tests/unit/CatalogFilterTest.php   (cần extension mongodb vì dùng BSON\Regex)
require_once __DIR__ . '/../../app/models/SanPham.php';

$fail = 0;
$pass = 0;
$eq = function (string $name, $got, $want) use (&$fail, &$pass) {
    $ok = $got === $want;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : ' (got ' . var_export($got, true) . ')') . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

// Constructor chỉ lưu $db; các phần thuần không chạm DB (không truyền brand)
$model = new SanPham(null);

$eq('extra rỗng -> []', $model->buildCatalogExtraFilter([]), []);
$eq('giá trị rỗng/không hợp lệ bị bỏ qua', $model->buildCatalogExtraFilter([
    'brand' => '  ', 'price_min' => 'abc', 'price_max' => '', 'skin' => 'khong_ton_tai',
    'min_rating' => 0, 'on_sale' => false, 'in_stock' => '', 'unknown_key' => 'x',
]), []);

$eq('giá: bỏ ký tự không phải số', $model->buildCatalogExtraFilter(['price_min' => '200.000đ', 'price_max' => '1,000,000']),
    [['gia_ban' => ['$gte' => 200000, '$lte' => 1000000]]]);
$eq('giá: chỉ min', $model->buildCatalogExtraFilter(['price_min' => '1000000']), [['gia_ban' => ['$gte' => 1000000]]]);
$eq('giá: chỉ max', $model->buildCatalogExtraFilter(['price_max' => '199999']), [['gia_ban' => ['$lte' => 199999]]]);
$eq('giá: min = 0 vẫn là điều kiện', $model->buildCatalogExtraFilter(['price_min' => '0', 'price_max' => '200000']),
    [['gia_ban' => ['$gte' => 0, '$lte' => 200000]]]);

// Loại da: khớp từ khóa HOẶC "Mọi loại da"
$skinExpect = [
    'dau' => 'dầu', 'kho' => 'khô', 'nhay_cam' => 'nhạy cảm', 'mun' => 'mụn',
];
foreach ($skinExpect as $key => $kw) {
    $cond = $model->buildCatalogExtraFilter(['skin' => $key])[0]['$or'] ?? [];
    $eq("skin $key: 2 nhánh", count($cond), 2);
    $eq("skin $key: nhánh từ khóa", [$cond[0]['loai_da']->getPattern(), $cond[0]['loai_da']->getFlags()], [$kw, 'i']);
    $eq("skin $key: nhánh Mọi loại da", [$cond[1]['loai_da']->getPattern(), $cond[1]['loai_da']->getFlags()], ['Mọi loại da', 'i']);
}

$eq('đánh giá >= 4', $model->buildCatalogExtraFilter(['min_rating' => 4]), [['diem_danh_gia' => ['$gte' => 4.0]]]);
$eq('đánh giá chuỗi "4"', $model->buildCatalogExtraFilter(['min_rating' => '4']), [['diem_danh_gia' => ['$gte' => 4.0]]]);
$eq('đánh giá không hợp lệ', $model->buildCatalogExtraFilter(['min_rating' => 'x']), []);
$eq('đang giảm giá', $model->buildCatalogExtraFilter(['on_sale' => true]), [['phan_tram_giam' => ['$gt' => 0]]]);
$eq('còn hàng', $model->buildCatalogExtraFilter(['in_stock' => true]),
    [['$and' => [['so_luong_ton_kho' => ['$gt' => 0]], ['trang_thai_kho' => ['$ne' => 'het_hang']]]]]);

// Nhiều bộ lọc -> nhiều điều kiện AND, theo từng chiều
$parts = $model->buildCatalogExtraParts(['price_max' => '500000', 'on_sale' => true, 'min_rating' => 4]);
$eq('parts: khóa chiều', array_keys($parts), ['price', 'rating', 'sale']);
$eq('filter: đếm điều kiện', count($model->buildCatalogExtraFilter(['price_max' => '500000', 'on_sale' => true, 'min_rating' => 4, 'in_stock' => true])), 4);

// Đa chọn: loại da dạng mảng -> $or các điều kiện; 1 phần tử -> điều kiện đơn
$multi = $model->buildCatalogExtraParts(['skins' => ['dau', 'mun', 'bậy']]);
$eq('skins: 2 hợp lệ -> $or 2 nhánh', count($multi['skin']['$or'] ?? []), 2);
$eq('skins: mỗi nhánh là điều kiện loại da', isset($multi['skin']['$or'][0]['$or']), true);
$one = $model->buildCatalogExtraParts(['skins' => ['kho']])['skin'];
$eq('skins: 1 phần tử = điều kiện đơn (không bọc $or ngoài)', [count($one['$or']), $one['$or'][0]['loai_da']->getPattern()], [2, 'khô']);
$eq('skins: toàn khóa lạ -> bỏ qua', $model->buildCatalogExtraParts(['skins' => ['x', '']]), []);
$eq('skin đơn + skins trùng -> không nhân đôi', isset($model->buildCatalogExtraParts(['skin' => 'dau', 'skins' => ['dau']])['skin']['$or'][0]['loai_da']), true);

// Đa chọn: danh mục lá -> $in số nguyên, bỏ rác, trùng, giới hạn 50
$eq('categories: ép số nguyên, bỏ rác/trùng', $model->buildCatalogExtraParts(['categories' => ['12', 12, 'abc', '-3', '0', '7', [], null]])['category'],
    ['ma_danh_muc' => ['$in' => [12, 7]]]);
$eq('categories: tối đa 50', count($model->buildCatalogExtraParts(['categories' => range(1, 80)])['category']['ma_danh_muc']['$in']), 50);
$eq('categories rỗng -> bỏ qua', $model->buildCatalogExtraParts(['categories' => [], 'skins' => [], 'brands' => []]), []);
$eq('brands toàn rỗng -> bỏ qua', $model->buildCatalogExtraParts(['brands' => ['', '  ']]), []);
$mix = $model->buildCatalogExtraParts(['categories' => [5], 'skins' => ['dau', 'mun'], 'price_max' => '300000', 'on_sale' => true]);
$eq('trộn nhiều chiều: khóa', array_keys($mix), ['category', 'price', 'skin', 'sale']);

echo PHP_EOL . "Tổng: $pass PASS, $fail FAIL" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
