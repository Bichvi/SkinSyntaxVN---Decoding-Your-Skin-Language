<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/services/ChatAgentToken.php';
require_once __DIR__ . '/../../app/services/ChatAgentAudit.php';
require_once __DIR__ . '/../../app/controllers/ChatAgentInternalController.php';

if (!defined('BASE_URL')) define('BASE_URL', '/shop');

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$parseCases = [
    ['#DH123', 123],
    ['DH123', 123],
    ['123', 123],
    [' #dh 12 ', 12],
    ['abc', null],
];
foreach ($parseCases as [$input, $expected]) {
    $actual = method_exists(ChatAgentInternalController::class, 'parseOrderCode')
        ? ChatAgentInternalController::parseOrderCode($input)
        : null;
    $check('parseOrderCode ' . json_encode($input), $actual === $expected);
}

$view = method_exists(ChatAgentInternalController::class, 'orderView')
    ? ChatAgentInternalController::orderView([
        'ma_hoa_don' => 12,
        'ngay_dat' => '2026-10-27T12:34:56+00:00',
        'tong_tien' => '99000',
        'trang_thai_normalized' => 'confirmed',
        'status_thanh_toan' => 'Đã thanh toán',
        'ten_nguoi_nhan' => 'Private Name',
        'sdt_nguoi_nhan' => '0900000000',
        'dia_chi_giao_hang' => 'Private Address',
    ])
    : [];
$check('orderView has only safe fields', array_keys($view) === [
    'order_id', 'created_at', 'total', 'status', 'payment_state', 'url',
]);
$check('orderView omits receiver data', !array_key_exists('ten_nguoi_nhan', $view)
    && !array_key_exists('sdt_nguoi_nhan', $view)
    && !array_key_exists('dia_chi_giao_hang', $view));

final class ChatAgentOrdersFakeCollection
{
    public array $calls = [];
    public function __construct(private readonly array $docs) {}
    public function find(array $filter, array $options): ArrayObject
    {
        $this->calls[] = [$filter, $options];
        return new ArrayObject($this->docs);
    }
}

final class ChatAgentOrdersFakeDb
{
    public function __construct(public ChatAgentOrdersFakeCollection $hoa_don) {}
}

$finder = new ChatAgentOrdersFakeCollection([
    new ArrayObject(['ma_hoa_don' => 3, 'ngay_dat' => '2026-10-03', 'tong_tien' => 300]),
    new ArrayObject(['ma_hoa_don' => 2, 'ngay_dat' => '2026-10-02', 'tong_tien' => 200]),
    new ArrayObject(['ma_hoa_don' => 1, 'ngay_dat' => '2026-10-01', 'tong_tien' => 100]),
    new ArrayObject(['ma_hoa_don' => 0, 'ngay_dat' => '2026-09-30', 'tong_tien' => 0]),
]);
$auditRecords = [];
$controller = new ChatAgentInternalController(
    'internal-secret',
    'token-secret',
    new ChatAgentOrdersFakeDb($finder),
    null,
    new ChatAgentAudit(static function (array $document) use (&$auditRecords): void {
        $auditRecords[] = $document;
    }),
    null,
    static fn(string $email): array => ['ma_kh' => '42'],
    static fn(int $id, string $email): ?array => null
);

$invoke = static function (ChatAgentInternalController $controller, array $claims, array $post): array {
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'HTTP_X_INTERNAL_TOKEN' => 'internal-secret',
        'HTTP_X_AGENT_TOKEN' => ChatAgentToken::issue($claims, 'token-secret'),
    ];
    $_POST = $post;
    http_response_code(200);
    ob_start();
    $controller->handle('internal_agent_orders');
    $body = json_decode((string)ob_get_clean(), true);
    return [http_response_code(), is_array($body) ? $body : []];
};

[$status, $body] = $invoke($controller, ['sub' => 'agent-1', 'em' => '', 'scope' => ['order:read']], []);
$check('empty email returns 404 without query', $status === 404 && $finder->calls === []);

[$status, $body] = $invoke($controller, ['sub' => 'agent-1', 'em' => 'owner@example.test', 'scope' => ['order:read']], []);
$check('latest orders returns three normalized views', $status === 200 && count($body['orders'] ?? []) === 3
    && ($body['orders'][0]['order_id'] ?? null) === 3
    && ($finder->calls[0][0]['ma_kh']['$in'] ?? []) === [42, '42']
    && ($finder->calls[0][1]['sort']['ngay_dat'] ?? null) === -1
    && ($finder->calls[0][1]['limit'] ?? null) === 3);

$auditRecords = [];
[$status, $body] = $invoke($controller, ['sub' => 'agent-1', 'em' => 'owner@example.test', 'scope' => ['order:read']], ['order_code' => '#DH12']);
$check('foreign order returns 404', $status === 404 && ($auditRecords[0]['action'] ?? '') === 'internal_agent_orders'
    && ($auditRecords[0]['params']['order_code'] ?? '') === '#DH12'
    && ($auditRecords[0]['outcome'] ?? '') === 'not_found');

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
