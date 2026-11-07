<?php
declare(strict_types=1);

/**
 * Isolated payment boundary tests. No .env, SePay, MongoDB, or network access.
 * The fake collections only exercise rejection/idempotency branches.
 */
define('BANK_TRANSFER_WEBHOOK_SECRET', 'unit-test-secret');
require_once __DIR__ . '/../app/models/HoaDon.php';
require_once __DIR__ . '/../app/controllers/HomeController.php';

final class PaymentBoundaryFakeCollection
{
    public function __construct(private ?array $document = null)
    {
    }

    public function findOne(array $filter, array $options = []): ?array
    {
        if ($this->document === null) {
            return null;
        }

        foreach ($filter as $field => $expected) {
            if (is_array($expected)) {
                continue;
            }
            if (($this->document[$field] ?? null) !== $expected) {
                return null;
            }
        }

        return $this->document;
    }

    public function updateOne(array $filter, array $update): object
    {
        return new stdClass();
    }

    public function updateMany(array $filter, array $update): object
    {
        return new stdClass();
    }
}

final class PaymentBoundaryFakeDb
{
    public PaymentBoundaryFakeCollection $hoa_don;
    public PaymentBoundaryFakeCollection $khach_hang;
    public PaymentBoundaryFakeCollection $chi_tiet_hoa_don;

    public function __construct(?array $order)
    {
        $this->hoa_don = new PaymentBoundaryFakeCollection($order);
        $this->khach_hang = new PaymentBoundaryFakeCollection(null);
        $this->chi_tiet_hoa_don = new PaymentBoundaryFakeCollection(null);
    }
}

$passed = 0;
$failed = 0;
$check = static function (string $id, string $title, bool $ok) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "PASS {$id} - {$title}\n";
        return;
    }

    $failed++;
    echo "FAIL {$id} - {$title}\n";
};

$controller = (new ReflectionClass(HomeController::class))->newInstanceWithoutConstructor();
$validWebhook = new ReflectionMethod(HomeController::class, 'isValidWebhookRequest');
$validWebhook->setAccessible(true);
$normalize = new ReflectionMethod(HomeController::class, 'normalizeWebhookTransactions');
$normalize->setAccessible(true);
$extractOrder = new ReflectionMethod(HomeController::class, 'extractOrderIdFromTransferContent');
$extractOrder->setAccessible(true);

$_GET = [];
$_SERVER['HTTP_X_WEBHOOK_SECRET'] = 'wrong-secret';
$check('PAY-UNIT-BAD-SECRET', 'invalid webhook secret is rejected', $validWebhook->invoke($controller) === false);
$_SERVER['HTTP_X_WEBHOOK_SECRET'] = 'unit-test-secret';
$check('PAY-UNIT-GOOD-SECRET', 'configured webhook secret is accepted', $validWebhook->invoke($controller) === true);

$transactions = $normalize->invoke($controller, [
    'data' => [
        ['description' => 'SKIN HD 42', 'amount' => '1,250', 'transferType' => 'in', 'referenceCode' => 'tx-1'],
        ['description' => 'SKIN HD 43', 'amount' => 1000, 'transferType' => 'out'],
        ['description' => '', 'amount' => 1000, 'transferType' => 'in'],
    ],
]);
$check('PAY-UNIT-NORMALIZE', 'only positive incoming transactions are normalized', count($transactions) === 1 && $transactions[0]['amount'] === 1250);
$check('PAY-UNIT-MATCH', 'transfer content matches the expected order code format', $extractOrder->invoke($controller, 'Payment SKIN HD 42') === 42 && $extractOrder->invoke($controller, 'Payment 42') === null);

$unknownOrder = new HoaDon(new PaymentBoundaryFakeDb(null));
$unknownResult = $unknownOrder->markPaidByTransfer(999, 1000, 'tx-unknown', 'SKIN HD 999');
$check('PAY-UNIT-UNKNOWN', 'unknown order is rejected', ($unknownResult['ok'] ?? true) === false);

$underpaidOrder = new HoaDon(new PaymentBoundaryFakeDb([
    'ma_hoa_don' => 42,
    'ma_kh' => 7,
    'hinh_thuc_thanh_toan' => 'bank_transfer_qr',
    'status_thanh_toan' => 'Chờ chuyển khoản',
    'tong_tien' => 1000,
]));
$underpaidResult = $underpaidOrder->markPaidByTransfer(42, 999, 'tx-under', 'SKIN HD 42');
$check('PAY-UNIT-UNDERPAY', 'underpayment is rejected', ($underpaidResult['ok'] ?? true) === false);

$replayedOrder = new HoaDon(new PaymentBoundaryFakeDb([
    'ma_hoa_don' => 42,
    'ma_kh' => 7,
    'hinh_thuc_thanh_toan' => 'bank_transfer_qr',
    'status_thanh_toan' => 'Đã thanh toán',
    'tong_tien' => 1000,
]));
$replayedResult = $replayedOrder->markPaidByTransfer(42, 1000, 'tx-1', 'SKIN HD 42');
$check('PAY-UNIT-REPLAY', 'replayed payment is idempotently acknowledged', ($replayedResult['already_paid'] ?? false) === true);

$total = $passed + $failed;
echo "Payment boundary unit tests: {$passed}/{$total} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
