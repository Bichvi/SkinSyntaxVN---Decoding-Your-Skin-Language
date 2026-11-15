<?php
// Test thuần PHP (Mongo giả, không DB thật): xu video được gộp vào điểm tích lũy.
// Chạy: php backend/tests/unit/HoaDonVideoXuLoyaltyTest.php
require_once __DIR__ . '/support/FakeMongo.php';
require_once __DIR__ . '/../../app/models/HoaDon.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$db = new FakeDb();
$db->khach_hang->docs = [['ma_kh' => 7, 'diemtl' => 0]];
$db->hoa_don->docs = [['ma_kh' => 7, 'da_tich_diem' => true, 'diem_cong' => 30, 'diem_su_dung' => 0, 'da_hoan_diem' => false]];
$db->danh_gia->docs = [['ma_kh' => 7], ['ma_kh' => 7]];
$db->reward_xu_ledger->docs = [['ma_kh' => 7, 'source' => 'video', 'source_key' => 'video:u1:c1', 'amount_xu' => 1250, 'date_vn' => '2026-11-15']];
$hd = new HoaDon($db);

$hd->syncLoyaltyForCustomer(7);
$check('diemtl = 30 đơn + 2 đánh giá + 12 từ xu = 44', $db->khach_hang->docs[0]['diemtl'] === 44);
$check('loaikh vẫn tính từ tổng (Thuong < 500)', $db->khach_hang->docs[0]['loaikh'] === 'Thuong');
$db->reward_xu_ledger->throwOnRead = true;
$hd->syncLoyaltyForCustomer(7);
$check('sổ xu lỗi -> vẫn đồng bộ, xu tính 0 (=32), không văng lỗi', $db->khach_hang->docs[0]['diemtl'] === 32);
$db->reward_xu_ledger->throwOnRead = false;
$db->reward_xu_ledger->docs = [];
$hd->syncLoyaltyForCustomer(7);
$check('không có xu -> giữ nguyên công thức cũ (=32)', $db->khach_hang->docs[0]['diemtl'] === 32);

$check('đồng bộ điểm không tạo index sổ xu (đường đọc, không ensureIndexes)', count($db->reward_xu_ledger->indexes) === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
