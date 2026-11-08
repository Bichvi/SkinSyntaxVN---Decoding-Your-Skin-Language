<?php
// U13: số liệu thống kê admin không tính tài khoản E2E (is_test_account:true). Mongo giả, không chạm DB thật.
// Chạy: php backend/tests/unit/AdminStatsTestAccountsTest.php
require_once __DIR__ . '/../../app/models/QuanTri.php';
require_once __DIR__ . '/../../app/models/ThongKe.php';

$pass = 0; $fail = 0;
$check = function (string $name, bool $ok) use (&$pass, &$fail) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; $ok ? $pass++ : $fail++; };

class RecColl {
    public static array $log = [];
    public function __construct(private string $name) {}
    public function countDocuments($filter = [], $opts = []) { self::$log[] = [$this->name, 'count', $filter]; return 7; }
    public function find($filter = [], $opts = []) { self::$log[] = [$this->name, 'find', $filter]; return new ArrayIterator([]); }
    public function findOne($filter = [], $opts = []) { return null; }
    public function aggregate($p, $o = []) { return new class { public function toArray() { return []; } public function getIterator(): Iterator { return new ArrayIterator([]); } }; }
}
$db = new class { public function __get($n) { return new RecColl($n); } };

$isTestExcluded = function (array $filter): bool {
    return ($filter['is_test_account'] ?? null) === ['$ne' => true];
};
$entry = function (string $coll, string $op): ?array {
    foreach (RecColl::$log as $e) { if ($e[0] === $coll && $e[1] === $op) { return $e[2]; } }
    return null;
};

(new QuanTri($db))->getDashboardSummary();
$check('dashboard: tong_khach_hang lọc is_test_account != true', $isTestExcluded($entry('khach_hang', 'count') ?? []));
$nv = $entry('nhan_vien', 'count') ?? [];
$check('dashboard: tong_nhan_vien lọc is_test_account != true, giữ điều kiện deleted_at', $isTestExcluded($nv) && isset($nv['$or']));

RecColl::$log = [];
$tk = new ThongKe($db);
$tk->getTongNguoiDung();
$f = $entry('nguoidung', 'count') ?? [];
$check('ThongKe::getTongNguoiDung lọc test + giữ vai_tro', $isTestExcluded($f) && ($f['vai_tro'] ?? '') === 'khach_hang');
$tk->getNguoiDungMoi(5);
$check('ThongKe::getNguoiDungMoi lọc test', $isTestExcluded($entry('nguoidung', 'find') ?? []));

echo PHP_EOL . "PASS: $pass, FAIL: $fail" . PHP_EOL;
exit($fail ? 1 : 0);
