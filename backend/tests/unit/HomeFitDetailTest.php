<?php
// Test thuần PHP (không DB) cho HomeFitDetail::build.
// Chạy: php backend/tests/unit/HomeFitDetailTest.php
require_once __DIR__ . '/../../app/services/HomeFitDetail.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$eq = function (string $name, $got, $want) use ($check) {
    $check($name . ($got === $want ? '' : ' (got ' . var_export($got, true) . ')'), $got === $want);
};
$build = fn(array $p, array $r = [], string $s = 'skin') => HomeFitDetail::build($p, $r, $s);

// actives: nồng độ + thứ tự + dedupe theo short
$p = ['thanh_phan_sach' => 'Water, 5% Panthenol, Centella Asiatica Extract, Madecassoside, Tea Tree Oil, Hyaluronic Acid'];
$eq('actives: nồng độ, thứ tự, dedupe', $build($p)['actives'] ?? null, ['5% B5', 'Rau má', 'Tràm trà', 'Hyaluronic Acid']);
$p = ['thanh_phan_sach' => 'Niacinamide, Retinol, Ceramide NP, Squalane, Zinc PCA, Arbutin'];
$eq('actives: tối đa 4', count($build($p)['actives'] ?? []), 4);
$eq('actives: không có glossary -> rỗng -> []', $build(['thanh_phan_sach' => 'Water, Glycerin']), []);
$eq('actives: thanh_phan_sach không phải chuỗi', $build(['thanh_phan_sach' => ['x']]), []);

// concern_ingredient: thành phần theo reason đứng đầu
$p = ['thanh_phan_sach' => 'Panthenol, 2% Salicylic Acid, Niacinamide'];
$r = ['code' => 'concern_ingredient', 'concern' => 'Mụn', 'ingredients' => ['Salicylic Acid']];
$eq('concern_ingredient: reason lên đầu + nồng độ', $build($p, $r, 'concern')['actives'] ?? null, ['2% BHA', 'B5', 'Niacinamide']);
$eq('skin: giữ thứ tự thành phần', $build($p, $r, 'skin')['actives'] ?? null, ['B5', '2% BHA', 'Niacinamide']);
$eq('concern_target: giữ thứ tự thành phần', $build($p, ['code' => 'concern_target'] + $r, 'concern')['actives'] ?? null, ['B5', '2% BHA', 'Niacinamide']);

// skin_types
$p = ['loai_da_keys' => ['da_dau', 'da_kho', 'da_mun', 'moi_loai_da']];
$eq('skin_types: da của user lên đầu, bỏ moi_loai_da', $build($p, ['skin_type' => 'da khô'])['skin_types'] ?? null, ['Da khô', 'Da dầu', 'Da mụn']);
$eq('skin_types: tối đa 3', count($build(['loai_da_keys' => ['da_dau', 'da_kho', 'da_mun', 'da_nhay_cam']])['skin_types'] ?? []), 3);
$eq('skin_types: chỉ moi_loai_da -> hiện tên', $build(['loai_da_keys' => ['moi_loai_da']])['skin_types'] ?? null, [TagCatalog::name('skin_types', 'moi_loai_da')]);
$eq('skin_types: section concern không đổi thứ tự', $build(['loai_da_keys' => ['da_dau', 'da_kho']], ['skin_type' => 'Da khô'], 'concern')['skin_types'] ?? null, ['Da dầu', 'Da khô']);
$eq('skin_types: key lạ bị bỏ', $build(['loai_da_keys' => ['khong_co', 'da_kho']])['skin_types'] ?? null, ['Da khô']);

// benefits
$all = array_keys(TagCatalog::groups()['benefits']);
$eq('benefits: tối đa 3', count($build(['cong_dung_keys' => $all])['benefits'] ?? []), 3);
$eq('benefits: distinct, key lạ bị bỏ', $build(['cong_dung_keys' => ['cap_am', 'cap_am', 'zzz', 'lam_diu']])['benefits'] ?? null, ['Cấp ẩm', 'Làm dịu']);

// concerns: chỉ khi SP có và reason cũng có
$p = ['van_de_da_keys' => ['mun', 'dau_thua', 'tham_mun']];
$eq('concerns skin: giao SP x reason', $build($p, ['concerns' => ['Mụn', 'Khô / thiếu ẩm']])['concerns'] ?? null, ['Mụn']);
$eq('concerns skin: không phân biệt hoa thường, tối đa 2', $build($p, ['concerns' => ['mụn', 'DẦU THỪA', 'Thâm mụn']])['concerns'] ?? null, ['Mụn', 'Dầu thừa']);
$eq('concerns skin: reason có mà SP không có -> bỏ', $build(['van_de_da_keys' => ['dau_thua'], 'cong_dung_keys' => ['cap_am']], ['concerns' => ['Mụn']])['concerns'] ?? null, []);
$eq('concerns concern: dùng reason.concern', $build($p, ['concern' => 'Dầu thừa'], 'concern')['concerns'] ?? null, ['Dầu thừa']);
$eq('concerns concern: SP không có -> []', $build(['van_de_da_keys' => ['mun']], ['concern' => 'Dầu thừa'], 'concern'), []);

// rỗng / rác
$eq('rỗng -> []', $build([]), []);
$eq('giá trị không phải chuỗi bị bỏ', $build(['loai_da_keys' => [1, null, '', ' '], 'cong_dung_keys' => 'x', 'van_de_da_keys' => [[]]], ['concerns' => [5, '']]), []);

echo PHP_EOL . "Tổng: $pass PASS, $fail FAIL" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
