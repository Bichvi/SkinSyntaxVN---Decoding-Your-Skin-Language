<?php
// Test thuần PHP (không DB, không mạng) cho chip công dụng thẻ trang chủ.
// Chạy: php backend/tests/unit/HomeBenefitChipsTest.php
require_once __DIR__ . '/../../app/services/HomeBenefitChips.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$r = HomeBenefitChips::for(['cong_dung_keys' => ['cap_am', 'phuc_hoi']], '');
$check('tên được map', $r === ['shown' => ['Cấp ẩm', 'Phục hồi'], 'more' => 0]);

$r = HomeBenefitChips::for(['cong_dung_keys' => ['khong_co', 'sang_da']], '');
$check('key lạ bị bỏ', $r['shown'] === ['Sáng da'] && $r['more'] === 0);

$r = HomeBenefitChips::for(['cong_dung_keys' => ['cap_am', 'cap_am', 'phuc_hoi']], '');
$check('key trùng chỉ tính một', $r['shown'] === ['Cấp ẩm', 'Phục hồi']);

$r = HomeBenefitChips::for(['cong_dung_keys' => new ArrayObject(['cap_am', 'lam_diu'])], '');
$check('ArrayObject', $r['shown'] === ['Cấp ẩm', 'Làm dịu']);
$r = HomeBenefitChips::for(['cong_dung_keys' => new ArrayIterator(['kiem_dau'])], '');
$check('ArrayIterator', $r['shown'] === ['Kiềm dầu']);

$r = HomeBenefitChips::for(['cong_dung_keys' => ['cap_am', 'phuc_hoi']], 'Có HA – cấp nước, căng mịn');
$check('alias "cấp nước" bỏ Cấp ẩm', $r['shown'] === ['Phục hồi']);

$r = HomeBenefitChips::for(['cong_dung_keys' => ['phuc_hoi', 'sang_da']], 'Giúp phục hồi, sáng da');
$check('tên đã nói bị bỏ', $r === ['shown' => [], 'more' => 0]);

$r = HomeBenefitChips::for(['cong_dung_keys' => ['cap_am', 'phuc_hoi', 'lam_diu', 'sang_da']], '', 2);
$check('max=2 còn more=2', $r['shown'] === ['Cấp ẩm', 'Phục hồi'] && $r['more'] === 2);

$r = HomeBenefitChips::for(['cong_dung_keys' => ['cap_am', 'phuc_hoi', 'lam_diu']], '', 0);
$check('max=0 shown rỗng, more=3', $r === ['shown' => [], 'more' => 3]);

$check('thiếu trường -> rỗng', HomeBenefitChips::for([], 'abc') === ['shown' => [], 'more' => 0]);

echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
