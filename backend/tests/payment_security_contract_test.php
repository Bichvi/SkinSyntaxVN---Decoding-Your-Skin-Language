<?php
declare(strict_types=1);

/**
 * Static payment-boundary contract checks.
 *
 * These checks deliberately do not load .env, call SePay, or connect to MongoDB.
 * They protect the fail-closed configuration and the webhook/order guards.
 */
$root = dirname(__DIR__, 2);
$config = (string)file_get_contents($root . '/backend/app/config/config.php');
$home = (string)file_get_contents($root . '/backend/app/controllers/HomeController.php');
$order = (string)file_get_contents($root . '/backend/app/models/HoaDon.php');

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

$check(
    'PAY-CONFIG-WEBHOOK',
    'webhook secret has no hardcoded fallback',
    (bool)preg_match("/ss_env\('BANK_TRANSFER_WEBHOOK_SECRET',\s*''\)/", $config)
);
$check(
    'PAY-CONFIG-SEPAY-TOKEN',
    'SePay API token has no hardcoded fallback',
    (bool)preg_match("/ss_env\('SEPAY_API_TOKEN',\s*''\)/", $config)
);
$check(
    'PAY-CONFIG-SEPAY-ACCOUNT',
    'SePay account number is environment-configured',
    (bool)preg_match("/ss_env\('SEPAY_ACCOUNT_NUMBER',\s*''\)/", $config)
);
$check(
    'PAY-CONFIG-POLLING',
    'SePay polling is disabled by default',
    (bool)preg_match("/ss_env\('SEPAY_POLLING_ENABLED',\s*'0'\)/", $config)
);
$check(
    'PAY-CONFIG-FACEBOOK-SECRET',
    'Facebook OAuth secret has no hardcoded fallback',
    (bool)preg_match("/ss_env\('FACEBOOK_APP_SECRET',\s*''\)/", $config)
);
$check(
    'PAY-WEBHOOK-FAIL-CLOSED',
    'missing webhook secret rejects the request',
    (bool)preg_match('/if \(\$secret === \'\'\)\s*\{.*?return false;\s*\}/s', $home)
);
$check(
    'PAY-WEBHOOK-GUARDS',
    'webhook keeps POST, authentication and transaction normalization guards',
    str_contains($home, '$_SERVER[\'REQUEST_METHOD\'] !== \'POST\'')
        && str_contains($home, 'isValidWebhookRequest()')
        && str_contains($home, 'normalizeWebhookTransactions')
        && str_contains($home, 'markPaidByTransfer')
);
$check(
    'PAY-ORDER-GUARDS',
    'order payment update keeps method, idempotency, cancellation and amount guards',
    str_contains($order, "'bank_transfer_qr'")
        && str_contains($order, "'already_paid' => true")
        && str_contains($order, "'cancelled'")
        && str_contains($order, '$amount < $expectedAmount')
);
$check(
    'PAY-VNPAY-GUARDS',
    'VNPAY return/IPN and model update verify method, amount and transaction status',
    str_contains($home, "'Invalid Payment Method'")
        && str_contains($home, 'vnp_TransactionStatus')
        && str_contains($home, 'intdiv($vnpAmount, 100)')
        && str_contains($home, '$vnpayPreflight->isConfigured()')
        && str_contains($order, "Đơn hàng không sử dụng phương thức VNPAY")
);

$total = $passed + $failed;
echo "Payment security contract tests: {$passed}/{$total} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
