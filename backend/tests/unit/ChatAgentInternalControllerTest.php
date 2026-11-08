<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/services/ChatAgentToken.php';
require_once __DIR__ . '/../../app/services/CheckoutCore.php';
require_once __DIR__ . '/../../app/services/ChatDraftService.php';
require_once __DIR__ . '/../../app/services/ChatAgentAudit.php';
require_once __DIR__ . '/../../app/controllers/ChatAgentInternalController.php';

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

final class ChatAgentControllerMemoryStore implements ChatDraftStore
{
    public array $docs = [];

    public function insert(array $d): void { $this->docs[$d['_id']] = $d; }
    public function find(string $id): ?array { return $this->docs[$id] ?? null; }
    public function countOpen(string $sub, int $now): int
    {
        return count(array_filter($this->docs, static fn(array $d): bool =>
            ($d['sub'] ?? '') === $sub && empty($d['used_at']) && (int)($d['expires_at'] ?? 0) > $now
        ));
    }
    public function markUsed(string $id, int $now): bool
    {
        if (!isset($this->docs[$id]) || !empty($this->docs[$id]['used_at'])) return false;
        $this->docs[$id]['used_at'] = $now;
        return true;
    }
    public function release(string $id): void { unset($this->docs[$id]); }
}

final class ChatAgentControllerFakeCore extends CheckoutCore
{
    public array $lastPreviewItems = [];

    public function __construct() {}

    public function preview(array $checkoutItems): array
    {
        $this->lastPreviewItems = $checkoutItems;
        $items = [];
        foreach ($checkoutItems as $id => $qty) {
            $items[] = [
                'id' => $id,
                'product' => ['ten_san_pham' => 'Product ' . $id, 'hinh_anh' => 'image-' . $id],
                'qty' => $qty,
                'unit_price' => 100,
                'line_total' => 100 * $qty,
            ];
        }
        return ['items' => $items, 'subtotal' => count($items) * 100, 'stock_errors' => []];
    }

    public function defaultReceiver(?array $customer, array $user): array
    {
        return [
            'ten_nguoi_nhan' => (string)($customer['ho_ten'] ?? $user['ho_ten'] ?? ''),
            'sdt_nguoi_nhan' => (string)($customer['so_dien_thoai'] ?? ''),
            'dia_chi_giao_hang' => (string)($customer['dia_chi'] ?? ''),
        ];
    }

    public function bestVoucher(string $customerId, int $subtotal): ?array { return null; }
}

$claims = [
    'sub' => 'agent-1',
    'em' => 'owner@example.test',
    'scope' => ['draft:create'],
];
$core = new ChatAgentControllerFakeCore();
$store = new ChatAgentControllerMemoryStore();
$service = new ChatDraftService($store, $core, static fn(): int => 1_800_000_000);
$auditRecords = [];
$audit = new ChatAgentAudit(static function (array $document) use (&$auditRecords): void {
    $auditRecords[] = $document;
});
$orderResolverCalls = [];
$orderResolver = static function (int $id, string $email) use (&$orderResolverCalls): array {
    $orderResolverCalls[] = [$id, $email];
    return [
        'chi_tiet_hoa_don' => new ArrayObject([
            new ArrayObject(['ma_san_pham' => 'old-1', 'so_luong' => 25]),
            new ArrayObject(['ma_san_pham' => 'old-2', 'so_luong' => 3]),
        ]),
    ];
};
$controller = new ChatAgentInternalController(
    'internal-secret',
    'token-secret',
    null,
    $core,
    $audit,
    $service,
    static fn(string $email): array => [
        'ma_kh' => '42',
        'ho_ten' => 'Owner',
        'so_dien_thoai' => '0901234123',
        'dia_chi' => '12 Nguyen Hue, TP.HCM',
    ],
    $orderResolver
);

$invoke = static function (ChatAgentInternalController $controller, array $claims, array $post, string $route): array {
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'HTTP_X_INTERNAL_TOKEN' => 'internal-secret',
        'HTTP_X_AGENT_TOKEN' => ChatAgentToken::issue($claims, 'token-secret'),
    ];
    $_POST = $post;
    http_response_code(200);
    ob_start();
    $controller->handle($route);
    $body = json_decode((string)ob_get_clean(), true);
    return [http_response_code(), is_array($body) ? $body : []];
};

[$status, $body] = $invoke($controller, $claims, [
    'order_code' => '#DH123',
    'address_choice' => 'new',
    'new_address' => [
        'ten_nguoi_nhan' => 'Owner',
        'sdt_nguoi_nhan' => '0901234123',
        'dia_chi_giao_hang' => '12 Nguyen Hue, TP.HCM',
    ],
    'cart_snapshot' => ['live-only' => ['id' => 'live-only', 'qty' => 1]],
], 'internal_agent_reorder');
$check('reorder_normalizes_array_object_lines_and_ignores_live_snapshot', $status === 200
    && $core->lastPreviewItems === ['old-1' => 20, 'old-2' => 3]
    && ($body['ok'] ?? null) === true
    && ($body['card']['items'][0]['id'] ?? null) === 'old-1'
    && !isset($body['items']));

$callsBeforeMissingEmail = count($orderResolverCalls);
[$status, $body] = $invoke($controller, ['sub' => 'agent-1', 'scope' => ['draft:create']], [
    'order_code' => '123',
], 'internal_agent_reorder');
$check('reorder_with_empty_email_returns_404_without_order_lookup', $status === 404
    && count($orderResolverCalls) === $callsBeforeMissingEmail);

$auditRecords = [];
[$status, $body] = $invoke($controller, ['sub' => 'agent-1', 'em' => 'owner@example.test', 'scope' => ['order:read']], [], 'internal_agent_checkout_defaults');
$check('checkout_defaults_requires_draft_create_scope', $status === 403
    && $auditRecords !== [] && $auditRecords[0]['outcome'] === 'error');

$auditRecords = [];
$failingController = new ChatAgentInternalController(
    'internal-secret',
    'token-secret',
    null,
    $core,
    $audit,
    $service,
    static fn(string $email): array => ['ma_kh' => 42],
    static function (int $id, string $email): ?array {
        throw new RuntimeException('lookup failed');
    }
);
[$status, $body] = $invoke($failingController, $claims, ['order_code' => '123'], 'internal_agent_reorder');
$check('reorder_generic_failure_is_audited', $status === 500
    && ($body['error'] ?? '') === 'Internal server error'
    && $auditRecords !== [] && $auditRecords[array_key_last($auditRecords)]['outcome'] === 'error');

[$status, $body] = $invoke($controller, $claims, [], 'internal_agent_checkout_defaults');
$check('checkout_defaults_shape_matches_flask_contract', $status === 200
    && is_array($body['receiver'] ?? null)
    && isset($body['receiver']['name'], $body['receiver']['phone_masked'], $body['receiver']['address_short'])
    && ($body['last_payment_method'] ?? null) === 'cod'
    && in_array('cod', (array)($body['methods'] ?? []), true));

$auditRecords = [];
[$status, $body] = $invoke($controller, $claims, [
    'scope' => 'mentioned_items',
    'product_ids' => ['p1'],
    'address_choice' => 'default',
    'payment_method' => 'cod',
    'cart_snapshot' => [],
], 'internal_agent_draft');
$check('draft_create_responds_with_ok_card_wrapper', $status === 200
    && ($body['ok'] ?? null) === true
    && is_array($body['card'] ?? null)
    && preg_match('/\A[a-f0-9]{32}\z/', (string)($body['card']['draft_id'] ?? '')) === 1
    && !isset($body['draft_id']) && !isset($body['items']));
$createdDraftId = (string)($body['card']['draft_id'] ?? '');
[$status, $body] = $invoke($controller, $claims, ['draft_id' => $createdDraftId], 'internal_agent_draft');
$check('draft_get_by_id_uses_same_wrapper', $status === 200
    && ($body['ok'] ?? null) === true
    && ($body['card']['draft_id'] ?? null) === $createdDraftId
    && !isset($body['draft_id']));
[$status, $body] = $invoke($controller, $claims, ['draft_id' => 'does-not-exist'], 'internal_agent_draft');
$check('draft_get_missing_is_404', $status === 404);

$auditRecords = [];
[$status, $body] = $invoke($controller, $claims, [
    'scope' => 'mentioned_items',
    'product_ids' => ['p1'],
    'address_choice' => 'invalid',
], 'internal_agent_draft');
$check('receiver_domain_error_returns_error_code', $status === 422
    && $body === [
        'ok' => false,
        'code' => 'receiver_invalid',
        'message' => 'Loại địa chỉ người nhận không hợp lệ.',
    ]
    && $auditRecords !== [] && $auditRecords[array_key_last($auditRecords)]['outcome'] === 'error');

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
