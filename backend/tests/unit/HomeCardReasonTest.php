<?php
// Test thuần PHP (không DB) cho HomeCardReason::for.
// Chạy: php backend/tests/unit/HomeCardReasonTest.php
require_once __DIR__ . '/../../app/services/HomeCardReason.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$eq = function (string $name, $got, $want) use ($check) {
    $check($name . ($got === $want ? '' : ' (got ' . var_export($got, true) . ')'), $got === $want);
};

$eq('vấn đề + loại da', HomeCardReason::for(['van_de_da_keys' => ['mun', 'dau_thua'], 'loai_da_keys' => ['da_dau']]), 'Hỗ trợ mụn, dầu thừa · Hợp da dầu');
$eq('tối đa 2 vấn đề', HomeCardReason::for(['van_de_da_keys' => ['mun', 'dau_thua', 'kho_da'], 'loai_da_keys' => ['da_dau']]), 'Hỗ trợ mụn, dầu thừa · Hợp da dầu');
$eq('chỉ vấn đề', HomeCardReason::for(['van_de_da_keys' => ['kich_ung']]), 'Hỗ trợ kích ứng');
$eq('nhãn gọn', HomeCardReason::for(['van_de_da_keys' => ['kho_da', 'nam_tan_nhang']]), 'Hỗ trợ da khô, nám da');
$eq('chỉ loại da', HomeCardReason::for(['loai_da_keys' => ['da_kho']]), 'Hợp da khô');
$eq('moi_loai_da bị bỏ qua -> rơi xuống đánh giá', HomeCardReason::for(['loai_da_keys' => ['moi_loai_da'], 'diem_danh_gia' => 4.9, 'so_luong_danh_gia' => 546]), '4,9★ từ 546 đánh giá');
$eq('moi_loai_da + da_mun -> lấy da_mun', HomeCardReason::for(['loai_da_keys' => ['moi_loai_da', 'da_mun']]), 'Hợp da mụn');
$eq('key lạ bị bỏ qua', HomeCardReason::for(['van_de_da_keys' => ['khong_co'], 'loai_da_keys' => ['khong_co']]), '');
$eq('key lạ nhưng có lượt bán', HomeCardReason::for(['van_de_da_keys' => ['khong_co'], 'so_luong_da_ban' => 229]), 'Đã bán 229');
$eq('đánh giá: ngưỡng 4.5 và 20 lượt', HomeCardReason::for(['diem_danh_gia' => '4.5', 'so_luong_danh_gia' => '20']), '4,5★ từ 20 đánh giá');
$eq('đánh giá thấp hơn ngưỡng điểm -> xuống lượt bán', HomeCardReason::for(['diem_danh_gia' => 4.4, 'so_luong_danh_gia' => 500, 'so_luong_da_ban' => 15]), 'Đã bán 15');
$eq('đánh giá ít hơn 20 lượt -> xuống lượt bán', HomeCardReason::for(['diem_danh_gia' => 5, 'so_luong_danh_gia' => 19, 'so_luong_da_ban' => 10]), 'Đã bán 10');
$eq('đã bán < 10 -> rỗng', HomeCardReason::for(['so_luong_da_ban' => 9]), '');
$eq('đã bán nghìn dùng dấu chấm', HomeCardReason::for(['so_luong_da_ban' => 1234]), 'Đã bán 1.234');
$eq('nhãn ưu tiên hơn đánh giá', HomeCardReason::for(['loai_da_keys' => ['da_nhay_cam'], 'diem_danh_gia' => 5, 'so_luong_danh_gia' => 100]), 'Hợp da nhạy cảm');
$eq('sản phẩm rỗng', HomeCardReason::for([]), '');
$eq('trường null', HomeCardReason::for(['van_de_da_keys' => null, 'loai_da_keys' => null, 'diem_danh_gia' => null]), '');
$eq('không escape', HomeCardReason::for(['loai_da_keys' => ['da_dau']]), 'Hợp da dầu');

echo PHP_EOL . "Tổng: $pass PASS, $fail FAIL" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
