<?php
// Test thuần PHP (không DB) cho HomeReasonDetail::build.
// Chạy: php backend/tests/unit/HomeReasonDetailTest.php
require_once __DIR__ . '/../../app/services/HomeReasonDetail.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$eq = function (string $name, $got, $want) use ($check) {
    $check($name . ($got === $want ? '' : ' (got ' . var_export($got, true) . ')'), $got === $want);
};
$val = fn(array $p, array $s) => HomeReasonDetail::build([], $p, $s)['value'] ?? null;

$seed = ['gia_ban' => 300000, 'dung_tich' => '30ml', 'ma_danh_muc' => 5, 'diem_danh_gia' => 4.2, 'so_luong_danh_gia' => 100];

// value: cheaper
$eq('rẻ hơn', $val(['gia_ban' => 180000], $seed), ['kind' => 'cheaper', 'text' => 'Rẻ hơn 120.000đ so với SP bạn đã xem']);
$eq('rẻ hơn đúng 1.000đ', $val(['gia_ban' => 299000], $seed)['kind'] ?? '', 'cheaper');
$eq('rẻ hơn 500đ -> tương đương', $val(['gia_ban' => 299500], $seed)['kind'] ?? '', 'similar');
// similar
$eq('bằng giá', $val(['gia_ban' => 300000], $seed), ['kind' => 'similar', 'text' => 'Giá tương đương SP bạn đã xem']);
$eq('đắt hơn 9%', $val(['gia_ban' => 327000], $seed)['kind'] ?? '', 'similar');
// per_unit
$eq('đắt hơn 10% nhưng rẻ hơn theo ml', $val(['gia_ban' => 400000, 'dung_tich' => '50ml', 'ma_danh_muc' => 5], $seed),
    ['kind' => 'per_unit', 'text' => '50ml, tính ra rẻ hơn 20% mỗi ml']);
$eq('per_unit dùng danh_muc_day_du khi thiếu ma_danh_muc',
    $val(['gia_ban' => 400000, 'dung_tich' => '50ml', 'danh_muc_day_du' => 'A -> B -> Serum'], ['gia_ban' => 300000, 'dung_tich' => '30ml', 'danh_muc_day_du' => 'X -> Serum'])['kind'] ?? '', 'per_unit');
$eq('per_unit thể tích thập phân', $val(['gia_ban' => 400000, 'dung_tich' => '50,0 ML', 'ma_danh_muc' => 5], $seed)['text'] ?? '', '50ml, tính ra rẻ hơn 20% mỗi ml');
$eq('per_unit không đủ rẻ hơn 10%', $val(['gia_ban' => 400000, 'dung_tich' => '40ml', 'ma_danh_muc' => 5], $seed), null);
$eq('per_unit từ chối 2x400ml', $val(['gia_ban' => 400000, 'dung_tich' => '2x400ml', 'ma_danh_muc' => 5], $seed), null);
$eq('per_unit từ chối 310ml+500ml', $val(['gia_ban' => 400000, 'dung_tich' => '310ml+500ml', 'ma_danh_muc' => 5], $seed), null);
$eq('per_unit từ chối khác đơn vị', $val(['gia_ban' => 400000, 'dung_tich' => '50g', 'ma_danh_muc' => 5], $seed), null);
$eq('per_unit từ chối khác danh mục', $val(['gia_ban' => 400000, 'dung_tich' => '50ml', 'ma_danh_muc' => 6], $seed), null);
$eq('per_unit từ chối khi không so được danh mục', $val(['gia_ban' => 400000, 'dung_tich' => '50ml'], ['gia_ban' => 300000, 'dung_tich' => '30ml']), null);
// discount
$eq('giảm 46%', $val(['gia_ban' => 400000, 'phan_tram_giam' => 46], $seed), ['kind' => 'discount', 'text' => 'Đang giảm 46%']);
$eq('giảm 29% không đủ', $val(['gia_ban' => 400000, 'phan_tram_giam' => 29], $seed), null);
// rating
$eq('đánh giá cao hơn', $val(['gia_ban' => 400000, 'diem_danh_gia' => 4.9, 'so_luong_danh_gia' => 50], $seed),
    ['kind' => 'rating', 'text' => 'Đánh giá cao hơn (4,9 so với 4,2)']);
$eq('đánh giá ít lượt', $val(['gia_ban' => 400000, 'diem_danh_gia' => 4.9, 'so_luong_danh_gia' => 9], $seed), null);
$eq('đánh giá chênh nhỏ', $val(['gia_ban' => 400000, 'diem_danh_gia' => 4.4, 'so_luong_danh_gia' => 50], $seed), null);
$eq('seed chưa có đánh giá', $val(['gia_ban' => 400000, 'diem_danh_gia' => 4.9, 'so_luong_danh_gia' => 50], ['gia_ban' => 300000]), null);
// ưu tiên: rẻ hơn thắng giảm giá
$eq('ưu tiên cheaper hơn discount', $val(['gia_ban' => 100000, 'phan_tram_giam' => 50], $seed)['kind'] ?? '', 'cheaper');
// null khi đắt hơn mà không có gì
$eq('đắt hơn không có gì -> null', $val(['gia_ban' => 500000], $seed), null);
// giá lỗi
$eq('giá chữ không crash', $val(['gia_ban' => 'abc'], ['gia_ban' => 'xyz']), null);
$eq('giá thiếu vẫn xét giảm giá', $val(['phan_tram_giam' => 40], []), ['kind' => 'discount', 'text' => 'Đang giảm 40%']);

// benefits (dùng tag_keys.json thật: cap_am=Cấp ẩm)
$p = ['cong_dung_keys' => ['cap_am', 'phuc_hoi', 'khong_co_key', 'cap_am']];
$s = ['cong_dung_keys' => ['cap_am', 'phuc_hoi']];
$d = HomeReasonDetail::build([], $p, $s);
$eq('công dụng chung', $d['same_benefits'] ?? null, [TagCatalog::name('benefits', 'cap_am'), TagCatalog::name('benefits', 'phuc_hoi')]);
$eq('key lạ bị bỏ', $d['extra_benefits'] ?? null, []);
$allKeys = array_keys(TagCatalog::groups()['benefits']);
$d = HomeReasonDetail::build([], ['cong_dung_keys' => $allKeys], ['cong_dung_keys' => array_slice($allKeys, 0, 4)]);
$eq('tối đa 3 công dụng chung', count($d['same_benefits'] ?? []), 3);
$eq('tối đa 2 công dụng thêm', count($d['extra_benefits'] ?? []), min(2, max(0, count($allKeys) - 4)));
$d = HomeReasonDetail::build([], ['cong_dung_keys' => array_slice($allKeys, 0, 2)], ['cong_dung_keys' => array_slice($allKeys, 1, 1)]);
$eq('công dụng thêm = có ở SP mà seed không có', $d['extra_benefits'] ?? null, [TagCatalog::name('benefits', $allKeys[0])]);

// shared + seed null
$d = HomeReasonDetail::build(['ingredients' => ['Niacinamide', '', 5, ' Retinol ']], ['cong_dung_keys' => ['cap_am'], 'gia_ban' => 1], null);
$eq('shared được làm sạch', $d['shared'] ?? null, ['Niacinamide', 'Retinol']);
$eq('seed null -> không có benefits/value', [$d['same_benefits'] ?? 'x', $d['extra_benefits'] ?? 'x', array_key_exists('value', $d) ? $d['value'] : 'x'], [[], [], null]);

// rỗng
$eq('không có gì -> []', HomeReasonDetail::build([], [], null), []);
$eq('có seed nhưng không có gì -> []', HomeReasonDetail::build([], ['gia_ban' => 500000], $seed), []);
$eq('ingredients không phải mảng', HomeReasonDetail::build(['ingredients' => 'x'], [], null), []);

echo PHP_EOL . "Tổng: $pass PASS, $fail FAIL" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
