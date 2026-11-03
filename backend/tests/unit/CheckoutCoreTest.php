<?php

require_once __DIR__ . '/../../app/services/CheckoutCore.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

class CheckoutCoreProductFake
{
    public array $products;
    public array $stocks;
    public array $availability;

    public function __construct(array $products, array $stocks = [], array $availability = [])
    {
        $this->products = $products;
        $this->stocks = $stocks;
        $this->availability = $availability;
    }

    public function findById(string $id): ?array
    {
        return $this->products[$id] ?? null;
    }

    public function getProductStock(array $product): ?int
    {
        return $this->stocks[(string)($product['id'] ?? '')] ?? null;
    }

    public function isProductAvailable(array $product): bool
    {
        return $this->availability[(string)($product['id'] ?? '')] ?? true;
    }
}

class CheckoutCoreVoucherFake
{
    public array $results;
    public array $calls = [];

    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function validateForCheckout(string $code, int $subtotal): array
    {
        $this->calls[] = [$code, $subtotal];
        return $this->results[$code] ?? ['ok' => false];
    }
}

class CheckoutCoreCustomerVoucherFake
{
    public array $vouchers;
    public array $calls = [];

    public function __construct(array $vouchers)
    {
        $this->vouchers = $vouchers;
    }

    public function getCustomerVouchers(string $customerId, ?string $status = null): array
    {
        $this->calls[] = [$customerId, $status];
        return $this->vouchers;
    }
}

$price = static fn(string $id, array $product): int => (int)($product['price'] ?? 0) + 10;

$products = new CheckoutCoreProductFake([
    'p1' => ['id' => 'p1', 'ten_san_pham' => 'Cleanser', 'price' => 100],
    'p2' => ['id' => 'p2', 'ten_san_pham' => 'Serum', 'price' => 200],
]);
$emptyVoucher = new CheckoutCoreVoucherFake([]);
$emptyCustomerVouchers = new CheckoutCoreCustomerVoucherFake([]);
$core = new CheckoutCore(null, $products, $emptyVoucher, $emptyCustomerVouchers, $price);

$preview = $core->preview(['p1' => 2, 'p2' => 1]);
$check('preview_sums_effective_prices', $preview['subtotal'] === 430
    && $preview['items'][0]['unit_price'] === 110
    && $preview['items'][1]['line_total'] === 210);

$stockProducts = new CheckoutCoreProductFake(
    ['p1' => ['id' => 'p1', 'ten_san_pham' => 'Cleanser', 'price' => 100]],
    ['p1' => 1]
);
$stockPreview = (new CheckoutCore($products, $stockProducts, $emptyVoucher, $emptyCustomerVouchers, $price))->preview(['p1' => 2]);
$check('preview_reports_stock_error_and_skips_item', $stockPreview['items'] === [] && count($stockPreview['stock_errors']) === 1);

$missingPreview = $core->preview(['missing' => 1]);
$check('preview_skips_missing_product', $missingPreview === ['items' => [], 'subtotal' => 0, 'stock_errors' => []]);

$voucherResults = [
    'SMALL' => ['ok' => true, 'discount' => 500, 'voucher' => ['ngay_ket_thuc' => '2030-01-01']],
    'LARGE' => ['ok' => true, 'discount' => 900, 'voucher' => ['ngay_ket_thuc' => '2030-02-01']],
];
$voucherModel = new CheckoutCoreVoucherFake($voucherResults);
$customerVoucherModel = new CheckoutCoreCustomerVoucherFake([
    ['voucher_code' => 'SMALL'],
    ['voucher_code' => 'LARGE'],
]);
$voucherCore = new CheckoutCore(null, $products, $voucherModel, $customerVoucherModel, $price);
$best = $voucherCore->bestVoucher('customer-1', 5000);
$check('best_voucher_picks_largest_discount', $best === $voucherResults['LARGE']
    && $customerVoucherModel->calls === [['customer-1', 'AVAILABLE']]);

$invalidVoucherModel = new CheckoutCoreVoucherFake([
    'BAD1' => ['ok' => false],
    'BAD2' => ['ok' => false],
]);
$invalidCustomerVoucherModel = new CheckoutCoreCustomerVoucherFake([
    ['voucher_code' => 'BAD1'],
    ['voucher_code' => 'BAD2'],
]);
$invalidCore = new CheckoutCore(null, $products, $invalidVoucherModel, $invalidCustomerVoucherModel, $price);
$check('best_voucher_none_when_all_invalid', $invalidCore->bestVoucher('customer-1', 5000) === null);

$receiver = $core->defaultReceiver(
    ['ho_ten' => ' Nguyen Van A ', 'so_dien_thoai' => '0900000000', 'dia_chi' => ' Hanoi '],
    ['ho_ten' => 'Fallback']
);
$check('default_receiver_matches_shape', array_keys($receiver) === [
    'ten_nguoi_nhan', 'sdt_nguoi_nhan', 'dia_chi_giao_hang'
] && $receiver === [
    'ten_nguoi_nhan' => 'Nguyen Van A',
    'sdt_nguoi_nhan' => '0900000000',
    'dia_chi_giao_hang' => 'Hanoi',
]);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
