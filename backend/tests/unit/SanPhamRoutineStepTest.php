<?php
// Test thuần PHP (không DB) cho SanPham::routineStepOf / routineStepLabels.
// Chạy: php backend/tests/unit/SanPhamRoutineStepTest.php
require_once __DIR__ . '/../../app/models/SanPham.php';

$fail = 0;
$pass = 0;
$eq = function (string $name, $got, $want) use (&$fail, &$pass) {
    $ok = $got === $want;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : ' (got ' . var_export($got, true) . ')') . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$eq('sữa rửa mặt -> lam_sach', SanPham::routineStepOf(['danh_muc_day_du' => 'Chăm Sóc Da Mặt Làm Sạch Da -> Sữa Rửa Mặt']), 'lam_sach');
$eq('suffix đứng đầu chuỗi', SanPham::routineStepOf(['danh_muc_day_du' => 'Làm Sạch Da -> Sữa Rửa Mặt']), 'lam_sach');
$eq('serum -> dac_tri', SanPham::routineStepOf(['danh_muc_day_du' => 'Đặc Trị -> Serum / Tinh Chất']), 'dac_tri');
$eq('kem dưỡng -> duong_am', SanPham::routineStepOf(['danh_muc_day_du' => 'Dưỡng Ẩm -> Kem / Gel / Dầu Dưỡng']), 'duong_am');
$eq('lotion -> duong_am', SanPham::routineStepOf(['danh_muc_day_du' => 'X Dưỡng Ẩm -> Lotion / Sữa Dưỡng']), 'duong_am');
$eq('chống nắng', SanPham::routineStepOf(['danh_muc_day_du' => 'Da Mặt Chống Nắng Da Mặt']), 'chong_nang');
$eq('suffix không ở cuối -> null', SanPham::routineStepOf(['danh_muc_day_du' => 'Đặc Trị -> Serum / Tinh Chất Khác']), null);
$eq('dính liền ký tự trước -> null', SanPham::routineStepOf(['danh_muc_day_du' => 'XChống Nắng Da Mặt']), null);
$eq('thiếu trường -> null', SanPham::routineStepOf([]), null);
$eq('4 nhãn bước', SanPham::routineStepLabels(), ['lam_sach' => 'Làm sạch', 'dac_tri' => 'Đặc trị', 'duong_am' => 'Dưỡng ẩm', 'chong_nang' => 'Chống nắng']);

echo PHP_EOL . "Tổng: $pass PASS, $fail FAIL" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
