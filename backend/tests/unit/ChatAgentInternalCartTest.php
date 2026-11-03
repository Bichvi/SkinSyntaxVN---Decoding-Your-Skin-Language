<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/services/ChatAgentToken.php';
require_once __DIR__ . '/../../app/services/ChatCartEffect.php';
require_once __DIR__ . '/../../app/services/CheckoutCore.php';
require_once __DIR__ . '/../../app/services/ChatAgentAudit.php';
require_once __DIR__ . '/../../app/controllers/ChatAgentInternalController.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

class ChatAgentInternalCartFakeCore extends CheckoutCore
{
    public array $previewResult = [];

    public function __construct()
    {
    }

    public function preview(array $checkoutItems): array
    {
        return $this->previewResult;
    }
}

$call = static function (array $claims, array $post, ChatAgentInternalCartFakeCore $core, array &$auditRecords, string $route = 'internal_agent_cart_add'): array {
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'HTTP_X_INTERNAL_TOKEN' => 'internal-secret',
        'HTTP_X_AGENT_TOKEN' => ChatAgentToken::issue($claims, 'token-secret'),
    ];
    $_POST = $post;
    http_response_code(200);
    $audit = new ChatAgentAudit(static function (array $document) use (&$auditRecords): void {
        $auditRecords[] = $document;
    });
    $controller = new ChatAgentInternalController('internal-secret', 'token-secret', null, $core, $audit);
    ob_start();
    $controller->handle($route);
    $body = json_decode((string)ob_get_clean(), true);
    return [http_response_code(), is_array($body) ? $body : []];
};

$claims = ['sub' => 'user-7', 'scope' => ['cart:write']];
$auditRecords = [];
$happy = new ChatAgentInternalCartFakeCore();
$happy->previewResult = [
    'items' => [[
        'id' => 'p1',
        'product' => ['ten_san_pham' => 'Cleanser'],
        'unit_price' => 125000,
        'qty' => 2,
    ]],
    'subtotal' => 250000,
    'stock_errors' => [],
];
[$status, $body] = $call($claims, ['product_id' => 'p1', 'qty' => 2], $happy, $auditRecords);
$check('cart_add_happy_path', $status === 200
    && isset($body['effect'], $body['line']['id'], $body['line']['name'], $body['line']['unit_price'], $body['line']['qty'])
    && ChatCartEffect::verify($body['effect'], 'token-secret', 'user-7'));

$auditRecords = [];
[$status, $body] = $call($claims, ['product_id' => 'p1', 'qty' => 'two'], $happy, $auditRecords);
$check('cart_add_non_int_qty_422', $status === 422 && $body['code'] === 'invalid_request'
    && $auditRecords[0]['params']['product_id'] === 'p1'
    && $auditRecords[0]['params']['qty'] === 0);

$auditRecords = [];
[$status, $body] = $call($claims, ['product_id' => 'p1', 'qty' => 1], $happy, $auditRecords, 'internal_agent_cart_remove');
$check('cart_remove_happy_path', $status === 200
    && $body['effect']['op'] === 'remove'
    && ChatCartEffect::verify($body['effect'], 'token-secret', 'user-7'));

$auditRecords = [];
$outOfStock = new ChatAgentInternalCartFakeCore();
$outOfStock->previewResult = ['items' => [], 'subtotal' => 0, 'stock_errors' => ['Hết hàng']];
[$status, $body] = $call($claims, ['product_id' => 'p1', 'qty' => 2], $outOfStock, $auditRecords);
$check('cart_add_out_of_stock_409', $status === 409 && $body['code'] === 'out_of_stock'
    && $auditRecords[0]['outcome'] === 'out_of_stock');

$auditRecords = [];
$missing = new ChatAgentInternalCartFakeCore();
$missing->previewResult = ['items' => [], 'subtotal' => 0, 'stock_errors' => []];
[$status, $body] = $call($claims, ['product_id' => 'missing', 'qty' => 1], $missing, $auditRecords);
$check('cart_add_unknown_product_404', $status === 404 && $body['code'] === 'not_found'
    && $auditRecords[0]['outcome'] === 'not_found');

$auditRecords = [];
$noScope = new ChatAgentInternalCartFakeCore();
[$status, $body] = $call(['sub' => 'user-7', 'scope' => ['chat']], ['product_id' => 'p1', 'qty' => 1], $noScope, $auditRecords);
$check('cart_add_missing_scope_403', $status === 403 && ($body['error'] ?? '') === 'Forbidden'
    && $auditRecords[0]['outcome'] === 'error');

$auditRecords = [];
[$status, $body] = $call(['sub' => 'user-7', 'scope' => ['chat']], [
    'product_id' => str_repeat('x', 80), 'qty' => 'bad',
], $noScope, $auditRecords, 'internal_agent_cart_remove');
$check('cart_remove_missing_scope_403_audit_normalized', $status === 403
    && strlen($auditRecords[0]['params']['product_id']) === 64
    && $auditRecords[0]['params']['qty'] === 0);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
