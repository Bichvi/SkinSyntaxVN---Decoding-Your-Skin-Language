<?php
// Thuần PHP, không DB: các route khách đã sửa phải có case và trỏ tới method public tồn tại.
$root = dirname(__DIR__, 3);
$routers = ['backend/public/index.php', 'frontend/public/index.php', 'index.php'];
$expect = [
    'capnhatthongtin' => ['TaiKhoanController', 'capNhatThongTin'],
    'capnhathoso' => ['TaiKhoanController', 'capNhatThongTin'],
    'guidanhgia' => ['QuanTriController', 'customerReviewSave'],
    'danhgia_sanpham' => ['QuanTriController', 'customerReviewSave'],
    'guicauhoi' => ['QuanTriController', 'customerQuestionSave'],
    'hoidap_sanpham' => ['QuanTriController', 'customerQuestionSave'],
    'lichsuchat' => ['QuanTriController', 'customerChat'],
    'chat_send' => ['QuanTriController', 'customerChatSend'],
    'apdung_voucher' => ['HomeController', 'apDungVoucher'],
    'bo_voucher' => ['HomeController', 'boVoucher'],
    'apdung_diem' => ['HomeController', 'apDungDiem'],
    'bo_diem' => ['HomeController', 'boDiem'],
];
$fail = 0; $n = 0;
$check = function ($ok, $msg) use (&$fail, &$n) { $n++; if (!$ok) { $fail++; echo "FAIL: $msg\n"; } };
foreach ($routers as $rf) {
    $src = file_get_contents("$root/$rf");
    foreach ($expect as $route => [$cls, $m]) {
        $re = '/case\s+\'' . preg_quote($route, '/') . '\'\s*:(?:\s*case\s+\'[^\']+\'\s*:)*\s*\(new\s+(\w+)\(\$pdo\)\)->(\w+)\(\)/';
        $ok = preg_match($re, $src, $mm) === 1 && $mm[1] === $cls && $mm[2] === $m;
        $check($ok, "$rf route $route -> $cls::$m");
        $csrc = file_get_contents("$root/backend/app/controllers/$cls.php");
        $check(preg_match('/public\s+function\s+' . $m . '\s*\(/', $csrc) === 1, "$cls::$m không tồn tại");
    }
    foreach (['danhgiaSanpham', 'hoidapSanpham', 'capnhatHoso', 'datDiaChiMacDinh', 'xoaDiaChi'] as $dead) {
        $check(strpos($src, "->$dead(") === false, "$rf còn gọi method không tồn tại $dead");
    }
}
echo $fail ? "FAILED $fail/$n\n" : "PASS $n assertions\n";
exit($fail ? 1 : 0);
