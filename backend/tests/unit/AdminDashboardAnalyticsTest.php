<?php
declare(strict_types=1);

// Dashboard analytics regression test. Uses in-memory Mongo-shaped fakes only.
// Chạy: php backend/tests/unit/AdminDashboardAnalyticsTest.php
require_once __DIR__ . '/../../app/models/QuanTri.php';

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

final class AdminAnalyticsFakeCollection implements IteratorAggregate
{
    public function __construct(private array $documents)
    {
    }

    public function find(array $filter = [], array $options = []): Traversable
    {
        return new ArrayIterator($this->documents);
    }

    public function countDocuments(array $filter = []): int
    {
        return count($this->documents);
    }

    public function findOne(array $filter = [], array $options = []): ?array
    {
        return null;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->documents);
    }
}

final class AdminAnalyticsFakeDb
{
    public function __construct(private array $collections)
    {
    }

    public function __get(string $name): AdminAnalyticsFakeCollection
    {
        return new AdminAnalyticsFakeCollection($this->collections[$name] ?? []);
    }
}

$db = new AdminAnalyticsFakeDb([
    'hoa_don' => [
        [
            'trang_thai' => 'completed',
            'hinh_thuc_thanh_toan' => 'cod',
            'tong_tien' => 100000,
            'ngay_hoan_thanh' => '2026-11-09 10:00:00',
        ],
        [
            'trang_thai' => 'completed',
            'hinh_thuc_thanh_toan' => 'bank_transfer_qr',
            'status_thanh_toan' => 'Chờ chuyển khoản',
            'tong_tien' => 999999,
            'ngay_hoan_thanh' => '2026-11-09 11:00:00',
        ],
        [
            'trang_thai' => 'completed',
            'hinh_thuc_thanh_toan' => 'bank_transfer_qr',
            'status_thanh_toan' => 'Đã thanh toán',
            'tong_tien' => 200000,
            'ngay_hoan_thanh' => '2026-11-08 12:00:00',
        ],
    ],
    'san_pham' => [
        ['trang_thai' => 'active', 'so_luong_ton_kho' => 20],
        ['trang_thai' => 'active', 'so_luong_ton_kho' => 5],
        ['trang_thai' => 'active', 'so_luong_ton_kho' => 0],
        ['trang_thai' => 'inactive', 'so_luong_ton_kho' => 20],
    ],
    'lich_su_chat' => [
        ['ma_nv' => 7, 'thoi_gian' => '2026-11-09 12:00:00'],
        ['ma_nv' => null, 'thoi_gian' => '2026-11-09 13:00:00'],
    ],
    'hoi_dap_san_pham' => [
        ['tra_loi' => ['noi_dung' => 'Đã trả lời', 'ngay_tra_loi' => '2026-11-08 14:00:00']],
        ['tra_loi' => null],
    ],
    'danh_gia_san_pham' => [
        ['phan_hoi_shop' => ['noi_dung' => 'Cảm ơn bạn', 'ngay_phan_hoi' => '2026-11-09 15:00:00']],
    ],
    'danh_gia' => [
        ['phan_hoi' => 'Đã phản hồi', 'ngay_phan_hoi' => '2026-11-08 16:00:00'],
    ],
]);

$model = new QuanTri($db);
$trend = $model->getDailySalesTrend('2026-11-08', '2026-11-09');
$check('trend includes every selected day', count($trend) === 2);
$check('paid QR order is included on its completion date', ($trend[0]['so_don'] ?? 0) === 1 && ($trend[0]['doanh_thu'] ?? 0) === 200000);
$check('unpaid QR order is excluded from revenue', ($trend[1]['so_don'] ?? 0) === 1 && ($trend[1]['doanh_thu'] ?? 0) === 100000);

$status = $model->getProductStatusSummary();
$check('product status groups are mutually exclusive', $status === [
    'dang_ban' => 1,
    'sap_het_hang' => 1,
    'het_hang' => 1,
    'dang_an' => 1,
    'tong' => 4,
]);

$workload = $model->getStaffWorkloadTrend('2026-11-08', '2026-11-09');
$check('staff workload includes every selected day', count($workload) === 2);
$check('staff workload counts only staff chat replies', ($workload[1]['so_phan_hoi_chat'] ?? 0) === 1);
$check('staff workload counts persisted Q&A and review replies',
    ($workload[0]['so_tra_loi_hoi_dap'] ?? 0) === 1
    && ($workload[0]['so_phan_hoi_danh_gia'] ?? 0) === 1
    && ($workload[1]['so_phan_hoi_danh_gia'] ?? 0) === 1
);

echo PHP_EOL . "PASS: {$pass}, FAIL: {$fail}" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
