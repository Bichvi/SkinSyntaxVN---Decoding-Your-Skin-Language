<?php
// Test thuần PHP (không DB, không mạng) cho câu + nhãn thẻ "Vì sao phù hợp".
// Chạy: php backend/tests/unit/HomeWhyFitCopyTest.php
require_once __DIR__ . '/../../app/services/HomeWhyFitCopy.php';
require_once __DIR__ . '/../../app/services/HomeReasonDetail.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$texts = static fn(array $r): array => array_column($r['tags'], 'text');
$val = static fn(string $kind, string $text): array => ['shared' => [], 'same_benefits' => [], 'extra_benefits' => [], 'value' => ['kind' => $kind, 'text' => $text]];

// ---- 4 ví dụ thật ----
$r = HomeWhyFitCopy::build(['code' => 'seen_shared_ingredient', 'ingredients' => ['Centella Asiatica Extract']],
    ['shared' => ['Centella Asiatica Extract'], 'same_benefits' => ['Làm dịu'], 'extra_benefits' => [],
     'value' => ['kind' => 'cheaper', 'text' => 'Rẻ hơn 188.000đ so với SP bạn đã xem']], 'Serum / Tinh Chất');
$check('rau má: headline', $r['headline'] === 'Cũng có rau má – làm dịu, giảm đỏ');
$check('rau má: tags', $texts($r) === ['Rẻ hơn 188K']);
$check('rau má: icon/kind', $r['tags'][0]['kind'] === 'value' && $r['tags'][0]['icon'] === 'bi-cash-coin');

$r = HomeWhyFitCopy::build([], ['shared' => ['Niacinamide', 'Tranexamic Acid'], 'same_benefits' => [], 'extra_benefits' => ['Chống lão hóa'],
    'value' => ['kind' => 'discount', 'text' => 'Đang giảm 50%']]);
$check('angel: headline', $r['headline'] === 'Cũng có Niacinamide + Tranexamic Acid – mờ thâm');
$check('angel: tags (không lặp Giảm 50%)', $texts($r) === ['+ Chống lão hóa']);
$check('angel: icon', $r['tags'][0]['icon'] === '' && $r['tags'][0]['kind'] === 'extra');

$r = HomeWhyFitCopy::build([], ['shared' => [], 'same_benefits' => ['Phục hồi', 'Sáng da', 'Làm dịu'], 'extra_benefits' => ['Chống lão hóa'],
    'value' => ['kind' => 'cheaper', 'text' => 'Rẻ hơn 71.000đ so với SP bạn đã xem']]);
$check('retinol: headline', $r['headline'] === 'Cũng phục hồi, sáng da, làm dịu');
$check('retinol: tags', $texts($r) === ['Rẻ hơn 71K', '+ Chống lão hóa']);

$r = HomeWhyFitCopy::build([], ['shared' => [], 'same_benefits' => ['Sáng da', 'Phục hồi', 'Làm dịu'], 'extra_benefits' => [],
    'value' => ['kind' => 'similar', 'text' => 'Giá tương đương SP bạn đã xem']]);
$check('innisfree: headline', $r['headline'] === 'Cũng sáng da, phục hồi, làm dịu');
$check('innisfree: tags', $texts($r) === ['Giá tương đương']);

// ---- định dạng giá ----
$check('71000 -> 71K', $texts(HomeWhyFitCopy::build([], $val('cheaper', 'Rẻ hơn 71.000đ so với SP bạn đã xem'))) === ['Rẻ hơn 71K']);
$check('1.250.000 -> 1,3 triệu', $texts(HomeWhyFitCopy::build([], $val('cheaper', 'Rẻ hơn 1.250.000đ so với SP'))) === ['Rẻ hơn 1,3 triệu']);
$check('2.000.000 -> 2 triệu (bỏ ,0)', $texts(HomeWhyFitCopy::build([], $val('cheaper', 'Rẻ hơn 2.000.000đ so với SP'))) === ['Rẻ hơn 2 triệu']);
$check('cheaper không parse được', $texts(HomeWhyFitCopy::build([], $val('cheaper', 'rẻ lắm'))) === ['Rẻ hơn']);
$check('per_unit ml', $texts(HomeWhyFitCopy::build([], $val('per_unit', '50ml, tính ra rẻ hơn 20% mỗi ml'))) === ['Rẻ hơn 20%/ml']);
$check('per_unit g', $texts(HomeWhyFitCopy::build([], $val('per_unit', '50g, tính ra rẻ hơn 15% mỗi g'))) === ['Rẻ hơn 15%/g']);
$check('per_unit icon', HomeWhyFitCopy::build([], $val('per_unit', '50ml, tính ra rẻ hơn 20% mỗi ml'))['tags'][0]['icon'] === 'bi-box-seam');
$check('per_unit không parse được', $texts(HomeWhyFitCopy::build([], $val('per_unit', 'xyz'))) === ['Rẻ hơn theo ml']);
$check('rating không lặp số sao', $texts(HomeWhyFitCopy::build([], $val('rating', 'Đánh giá cao hơn (4,8 so với 4,5)'))) === ['Đánh giá cao hơn']);
$check('rating icon', HomeWhyFitCopy::build([], $val('rating', 'Đánh giá cao hơn (4,8 so với 4,5)'))['tags'][0]['icon'] === 'bi-star-fill');
$check('rating không parse được', $texts(HomeWhyFitCopy::build([], $val('rating', 'Đánh giá cao'))) === ['Đánh giá cao hơn']);

// ---- headline các nhánh ----
$r = HomeWhyFitCopy::build([], ['shared' => ['Zzz Unknown Extract'], 'same_benefits' => ['Cấp ẩm', 'Phục hồi', 'Làm dịu'], 'extra_benefits' => [], 'value' => null]);
$check('INCI lạ + same_benefits', $r['headline'] === 'Cũng có Zzz Unknown Extract – cấp ẩm, phục hồi');
$r = HomeWhyFitCopy::build([], ['shared' => ['Zzz Unknown Extract'], 'same_benefits' => [], 'extra_benefits' => [], 'value' => null]);
$check('INCI lạ không benefit', $r['headline'] === 'Cũng có Zzz Unknown Extract');
$r = HomeWhyFitCopy::build(['ingredients' => ['Niacinamide']], []);
$check('shared lấy từ reason.ingredients', $r['headline'] === 'Cũng có Niacinamide – mờ thâm, kiềm dầu');
$check('không dữ liệu + category', HomeWhyFitCopy::build([], [], 'Serum / Tinh Chất')['headline'] === 'Cùng loại Serum / Tinh Chất');
$r = HomeWhyFitCopy::build([], []);
$check('rỗng -> headline rỗng, không tag', $r['headline'] === '' && $r['tags'] === []);
$check('glycerin không có trong glossary -> dùng INCI gốc', HomeWhyFitCopy::build([], ['shared' => ['Glycerin']])['headline'] === 'Cũng có Glycerin');
$check('zinc oxide không khớp kẽm', HomeWhyFitCopy::build([], ['shared' => ['Zinc Oxide']])['headline'] === 'Cũng có Zinc Oxide');
$check('trùng short được gộp', HomeWhyFitCopy::build([], ['shared' => ['Centella Asiatica Extract', 'Madecassoside']])['headline'] === 'Cũng có rau má – làm dịu, giảm đỏ');

// ---- tags ----
$r = HomeWhyFitCopy::build([], ['shared' => [], 'same_benefits' => ['Phục hồi'], 'extra_benefits' => ['Phục hồi', 'Cấp ẩm'], 'value' => null]);
$check('extra đã có trong headline bị bỏ', $texts($r) === ['+ Cấp ẩm']);
$r = HomeWhyFitCopy::build([], ['shared' => [], 'same_benefits' => ['Sáng da'], 'extra_benefits' => ['Cấp ẩm', 'Chống nắng', 'Se khít'],
    'value' => ['kind' => 'similar', 'text' => 'Giá tương đương SP bạn đã xem']]);
$check('tối đa 2 tag', count($r['tags']) === 2 && $texts($r) === ['Giá tương đương', '+ Cấp ẩm']);
$r = HomeWhyFitCopy::build([], ['shared' => [], 'same_benefits' => ['Sáng da'], 'extra_benefits' => ['Cấp ẩm', 'Chống nắng', 'Se khít'], 'value' => null]);
$check('tối đa 2 tag (không value)', $texts($r) === ['+ Cấp ẩm', '+ Chống nắng']);

// ---- tích hợp với HomeReasonDetail thật ----
$seed = ['gia_ban' => 400000, 'cong_dung_keys' => ['cap_am', 'phuc_hoi']];
$prod = ['gia_ban' => 212000, 'cong_dung_keys' => ['cap_am', 'phuc_hoi']];
$detail = HomeReasonDetail::build(['code' => 'seen_same_category', 'ingredients' => []], $prod, $seed);
$r = HomeWhyFitCopy::build(['code' => 'seen_same_category', 'ingredients' => []], $detail, 'Serum');
$check('tích hợp: tag Rẻ hơn 188K', ($r['tags'][0]['text'] ?? '') === 'Rẻ hơn 188K');
$check('tích hợp: headline từ công dụng chung', $r['headline'] === 'Cũng cấp ẩm, phục hồi');

// ---- forProduct: khối "Hợp với da…" / "Hợp với vấn đề…" ----
$skin1004 = ['thanh_phan_sach' => 'rau má madagascar, 2% t.x.a, 4% niacinamide, vitamin c dạng eaa', 'cong_dung_keys' => ['sang_da', 'lam_diu', 'phuc_hoi'],
    'van_de_da_keys' => ['xin_mau', 'kich_ung'], 'phan_tram_giam' => 30, 'diem_danh_gia' => 4.9, 'so_luong_danh_gia' => 263, 'so_luong_da_ban' => 1200];
$skinReason = ['code' => 'skin_fit', 'skin_type' => 'Da khô', 'concerns' => ['Mụn']];
$r = HomeWhyFitCopy::forProduct($skin1004, $skinReason, 'skin');
$check('forProduct: headline rau má + TXA', $r['headline'] === 'Có rau má + 2% Tranexamic Acid – làm dịu, giảm đỏ');
$check('forProduct: không "Hỗ trợ mụn" (thiếu mun)', !in_array('Hỗ trợ mụn', $texts($r), true));
$check('forProduct: không tag Đã bán/giảm giá/sao', $texts($r) === []);

$acne = ['thanh_phan_sach' => '', 'cong_dung_keys' => ['kiem_dau'], 'van_de_da_keys' => ['mun'], 'phan_tram_giam' => 25, 'diem_danh_gia' => 4.9, 'so_luong_danh_gia' => 263];
$r = HomeWhyFitCopy::forProduct($acne, $skinReason, 'skin');
$check('forProduct: concern xác thực, không lặp giảm giá/sao', $texts($r) === ['Hỗ trợ mụn'] && $r['tags'][0]['kind'] === 'extra');
$r = HomeWhyFitCopy::forProduct(['van_de_da_keys' => ['mun']], ['concerns' => ['Mụn']], 'skin');
$check('forProduct: chỉ concern, không value', $texts($r) === ['Hỗ trợ mụn']);
$r = HomeWhyFitCopy::forProduct(['van_de_da_keys' => ['mun'], 'phan_tram_giam' => 40], ['concerns' => ['mụn']], 'concern');
$check('forProduct: section concern không thêm Hỗ trợ', $texts($r) === []);

$rc = ['code' => 'concern_ingredient', 'concern' => 'Dầu thừa', 'ingredients' => ['Niacinamide']];
$r = HomeWhyFitCopy::forProduct(['thanh_phan_sach' => 'rau má, 4% niacinamide'], $rc, 'concern');
$check('concern_ingredient: Niacinamide đứng trước', $r['headline'] === 'Có 4% Niacinamide + rau má – mờ thâm, kiềm dầu'
    || str_starts_with($r['headline'], 'Có 4% Niacinamide + rau má'));
$r = HomeWhyFitCopy::forProduct(['thanh_phan_sach' => 'rau má, 4% niacinamide'], ['code' => 'concern_target', 'concern' => 'Dầu thừa'], 'concern');
$check('concern_target: giữ thứ tự thành phần SP', str_starts_with($r['headline'], 'Có rau má + 4% Niacinamide'));
$r = HomeWhyFitCopy::forProduct(['thanh_phan_sach' => 'nước, glycerin, 4% niacinamide'], [], 'skin');
$check('1 hoạt chất: Có 4% Niacinamide – ...', $r['headline'] === 'Có 4% Niacinamide – mờ thâm, kiềm dầu');

$r = HomeWhyFitCopy::forProduct(['thanh_phan_sach' => 'nước, glycerin', 'cong_dung_keys' => ['sang_da', 'lam_diu', 'phuc_hoi']], [], 'skin');
$check('không hoạt chất -> Giúp từ cong_dung_keys', $r['headline'] === 'Giúp ' . mb_strtolower(TagCatalog::name('benefits', 'sang_da'), 'UTF-8') . ', '
    . mb_strtolower(TagCatalog::name('benefits', 'lam_diu'), 'UTF-8') && $r['tags'] === []);
$r = HomeWhyFitCopy::forProduct([], [], 'skin');
$check('không dữ liệu -> headline rỗng', $r['headline'] === '' && $r['tags'] === []);

$check('không còn tag Đã bán (1200)', HomeWhyFitCopy::forProduct(['so_luong_da_ban' => 1200], [], 'skin')['tags'] === []);
$check('không còn tag Đã bán (350)', HomeWhyFitCopy::forProduct(['so_luong_da_ban' => 350], [], 'skin')['tags'] === []);
$check('bán < 100 không có tag', HomeWhyFitCopy::forProduct(['so_luong_da_ban' => 99], [], 'skin')['tags'] === []);
$check('rating thấp/ít đánh giá không có tag', HomeWhyFitCopy::forProduct(['diem_danh_gia' => 4.9, 'so_luong_danh_gia' => 5], [], 'skin')['tags'] === []
    && HomeWhyFitCopy::forProduct(['diem_danh_gia' => 4.4, 'so_luong_danh_gia' => 500], [], 'skin')['tags'] === []);
$check('giảm giá không thành tag', HomeWhyFitCopy::forProduct(['phan_tram_giam' => 50], [], 'skin')['tags'] === []);
$r = HomeWhyFitCopy::forProduct(['phan_tram_giam' => 30, 'diem_danh_gia' => 4.8, 'so_luong_danh_gia' => 50, 'so_luong_da_ban' => 500], [], 'skin');
$check('forProduct: giảm giá/sao/đã bán không thành tag', $texts($r) === []);
$r = HomeWhyFitCopy::forProduct(['van_de_da_keys' => ['mun'], 'so_luong_da_ban' => 500], ['concerns' => ['Mụn']], 'skin');
$check('forProduct: chỉ concern, không Đã bán', $texts($r) === ['Hỗ trợ mụn']);

// Không hoạt chất, không công dụng (nước tẩy trang L'Oreal thật): câu theo loại SP, vẫn giữ nhãn.
$loreal = ['thanh_phan_sach' => 'có hai lớp chất lỏng giúp hòa tan chất bẩn', 'loai_san_pham' => 'Tẩy Trang Mặt',
    'phan_tram_giam' => 41, 'diem_danh_gia' => 4.8, 'so_luong_danh_gia' => 298, 'so_luong_da_ban' => 298];
$r = HomeWhyFitCopy::forProduct($loreal, ['code' => 'skin_fit', 'skin_type' => 'Da khô', 'concerns' => []], 'skin');
$check('dự phòng skin: loại SP + loại da', $r['headline'] === 'Tẩy trang mặt hợp da khô');
$check('dự phòng skin: không có nhãn Đã bán', $texts($r) === []);
$r = HomeWhyFitCopy::forProduct($loreal, ['code' => 'concern_target', 'concern' => 'Dầu thừa'], 'concern');
$check('dự phòng concern: loại SP + vấn đề', $r['headline'] === 'Tẩy trang mặt hỗ trợ dầu thừa');
$check('dự phòng: thiếu loại SP -> rỗng', HomeWhyFitCopy::forProduct(['thanh_phan_sach' => 'abc'], ['skin_type' => 'Da khô'], 'skin')['headline'] === '');

echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
