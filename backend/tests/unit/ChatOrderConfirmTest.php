<?php
declare(strict_types=1);

define('BASE_URL', 'https://shop.test');
define('VNPAY_TMN_CODE', 'TESTTMN');
define('VNPAY_HASH_SECRET', 'TESTSECRET');
define('VNPAY_URL', 'https://pay.test/pay');
define('VNPAY_RETURN_URL', 'https://shop.test/index.php?r=vnpay_return');

require_once __DIR__ . '/../../app/services/RedisLite.php';
require_once __DIR__ . '/../../app/services/RateLimiter.php';
require_once __DIR__ . '/../../app/services/CheckoutCore.php';
require_once __DIR__ . '/../../app/services/ChatDraftService.php';
require_once __DIR__ . '/../../app/services/ProductEventTracker.php';
require_once __DIR__ . '/../../app/controllers/ShopAssistantController.php';
require_once __DIR__ . '/../../app/services/ChatAgentGate.php';
require_once __DIR__ . '/../../app/services/ChatAgentAudit.php';
require_once __DIR__ . '/../../app/services/ChatOrderConfirmService.php';
require_once __DIR__ . '/../../app/controllers/ChatAgentController.php';

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

final class ChatOrderConfirmMemoryStore implements ChatDraftStore
{
    public array $docs = [];

    public function insert(array $d): void { $this->docs[(string)$d['_id']] = $d; }
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
    public function release(string $id): void
    {
        if (isset($this->docs[$id])) $this->docs[$id]['used_at'] = null;
    }
}

final class ChatOrderConfirmCoreFake extends CheckoutCore
{
    public array $prices = ['p1' => 100, 'p2' => 200];
    public array $voucherResults = [];
    public array $bestVoucherResults = [];

    public function __construct() {}

    public function preview(array $checkoutItems): array
    {
        $items = [];
        $subtotal = 0;
        foreach ($checkoutItems as $id => $qty) {
            if (!array_key_exists((string)$id, $this->prices)) continue;
            $qty = max(1, (int)$qty);
            $unit = (int)$this->prices[(string)$id];
            $items[] = [
                'id' => (string)$id,
                'product' => ['ten_san_pham' => 'Product ' . $id, 'hinh_anh' => 'image-' . $id],
                'qty' => $qty,
                'unit_price' => $unit,
                'line_total' => $unit * $qty,
            ];
            $subtotal += $unit * $qty;
        }
        return ['items' => $items, 'subtotal' => $subtotal, 'stock_errors' => []];
    }

    public function bestVoucher(string $customerId, int $subtotal): ?array
    {
        return $this->bestVoucherResults[$subtotal] ?? null;
    }

    public function validateVoucher(string $code, int $subtotal): array
    {
        return $this->voucherResults[$code . ':' . $subtotal] ?? ['ok' => false, 'message' => 'Mã không hợp lệ.'];
    }
}

final class ChatOrderConfirmRedisFake extends RedisLite
{
    public $reply;
    public function __construct() { parent::__construct('redis://127.0.0.1:1'); }
    public function eval(string $script, array $keys = [], array $args = []) { return $this->reply; }
}

function chatOrderConfirmDraft(
    ChatOrderConfirmMemoryStore $store,
    string $id = 'draft-1',
    string $sub = 'customer-1',
    string $email = 'user@example.test',
    int $subtotal = 200,
    int $discount = 0,
    ?string $voucherCode = null,
    int $expiresAt = 1_800_000_600
): void {
    $store->docs[$id] = [
        '_id' => $id,
        'sub' => $sub,
        'em' => $email,
        'items' => [['id' => 'p1', 'qty' => 2]],
        'receiver' => [
            'ten_nguoi_nhan' => 'Nguyen Van A',
            'sdt_nguoi_nhan' => '0901234123',
            'dia_chi_giao_hang' => '12 Nguyen Hue, TP.HCM',
        ],
        'payment_method' => 'cod',
        'voucher_code' => $voucherCode,
        'subtotal' => $subtotal,
        'discount' => $discount,
        'shipping' => 30000,
        'expires_at' => $expiresAt,
        'used_at' => null,
    ];
}

function chatOrderConfirmServiceFixture(?callable $createOrder = null): array
{
    $store = new ChatOrderConfirmMemoryStore();
    $core = new ChatOrderConfirmCoreFake();
    $clock = static fn(): int => 1_800_000_000;
    $drafts = new ChatDraftService($store, $core, $clock);
    $createOrder ??= static fn(array $payload): int => 901;
    $service = new ChatOrderConfirmService($drafts, $store, $core, $createOrder, $clock);
    return [$service, $store, $core];
}

$user = [
    'id' => 'customer-1',
    'ho_ten' => 'Nguyen Van A',
    'email' => 'user@example.test',
    'role' => 'khach_hang',
    'vai_tro' => 'khach_hang',
];

$capturedPayload = null;
[$service, $store, $core] = chatOrderConfirmServiceFixture(static function (array $payload) use (&$capturedPayload): int {
    $capturedPayload = $payload;
    return 901;
});
chatOrderConfirmDraft($store);
$session = ['gio_hang' => ['p1' => 2, 'p2' => 1]];
$created = $service->confirm('draft-1', $user, $session);
$check('confirm_creates_order_once', $created['status'] === 'created' && $created['order_id'] === 901
    && $created['next']['type'] === 'view_order'
    && $capturedPayload['email'] === 'user@example.test'
    && $capturedPayload['ho_ten_mac_dinh'] === 'Nguyen Van A'
    && $capturedPayload['ten_nguoi_nhan'] === 'Nguyen Van A'
    && $capturedPayload['hinh_thuc_thanh_toan'] === 'cod'
    && $capturedPayload['phi_van_chuyen'] === 30000
    && $capturedPayload['tam_tinh'] === 200
    && $capturedPayload['so_tien_giam'] === 0
    && $capturedPayload['diem_su_dung'] === 0
    && $capturedPayload['tien_giam_diem'] === 0
    && $capturedPayload['ma_voucher'] === null
    && $capturedPayload['ma_giam_gia'] === ''
    && $capturedPayload['checkout_items'] === ['p1' => 2]);

$createCalls = 0;
[$service, $store, $core] = chatOrderConfirmServiceFixture(static function (array $payload) use (&$createCalls): int {
    $createCalls++;
    return 902;
});
chatOrderConfirmDraft($store);
$session = ['gio_hang' => ['p1' => 2]];
$service->confirm('draft-1', $user, $session);
$second = null;
try { $service->confirm('draft-1', $user, $session); } catch (DomainException $e) { $second = $e; }
$check('confirm_twice_creates_one_order', $second?->getCode() === 409 && $createCalls === 1);

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store, 'bson-shaped');
$store->docs['bson-shaped']['items'] = new ArrayObject([
    new ArrayObject(['id' => 'p1', 'qty' => 2]),
]);
$session = [];
$bsonItemsOrder = $service->confirm('bson-shaped', $user, $session);
$check('bson_shaped_arrayobject_items_create_order', $bsonItemsOrder['status'] === 'created');

$bsonPayload = null;
[$service, $store, $core] = chatOrderConfirmServiceFixture(static function (array $payload) use (&$bsonPayload): int {
    $bsonPayload = $payload;
    return 903;
});
chatOrderConfirmDraft($store, 'bson-shaped-payload');
$store->docs['bson-shaped-payload']['items'] = new ArrayObject([
    new ArrayObject(['id' => 'p1', 'qty' => 2]),
]);
$service->confirm('bson-shaped-payload', $user, $session);
$check('bson_shaped_arrayobject_items_have_right_checkout_items', $bsonPayload['checkout_items'] === ['p1' => 2]);

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store);
$core->prices['p1'] = 150;
$session = ['gio_hang' => ['p1' => 2]];
$changed = $service->confirm('draft-1', $user, $session);
$newDraftId = $changed['card']['draft_id'] ?? '';
$check('price_changed_returns_new_draft', $changed['status'] === 'changed' && $newDraftId !== ''
    && !empty($store->docs['draft-1']['used_at']) && isset($store->docs[$newDraftId]));

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store, 'foreign', 'customer-2', 'other@example.test');
$foreignError = null;
$session = [];
try { $service->confirm('foreign', $user, $session); } catch (DomainException $e) { $foreignError = $e; }
$check('confirm_rejects_other_users_draft', $foreignError?->getCode() === 404);

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store, 'expired', 'customer-1', 'user@example.test', 200, 0, null, 1_799_999_999);
$expiredError = null;
$session = [];
try { $service->confirm('expired', $user, $session); } catch (DomainException $e) { $expiredError = $e; }
$check('expired_draft_410', $expiredError?->getCode() === 410);

[$service, $store, $core] = chatOrderConfirmServiceFixture(static function (array $payload): int {
    throw new RuntimeException('database unavailable');
});
chatOrderConfirmDraft($store);
$failure = null;
$session = [];
try { $service->confirm('draft-1', $user, $session); } catch (RuntimeException $e) { $failure = $e; }
$check('create_failure_releases_draft', $failure !== null && isset($store->docs['draft-1']) && $store->docs['draft-1']['used_at'] === null);

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store);
$session = ['gio_hang' => ['p1' => 2, 'p2' => 1]];
$service->confirm('draft-1', $user, $session);
$check('cart_lines_removed_after_order', !isset($session['gio_hang']['p1']) && isset($session['gio_hang']['p2']));

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store, 'vnpay-draft');
$store->docs['vnpay-draft']['payment_method'] = 'vnpay';
$session = [];
$vnpay = $service->confirm('vnpay-draft', $user, $session);
$check('vnpay_next_is_payment_url', $vnpay['next']['type'] === 'vnpay' && str_starts_with($vnpay['next']['url'], 'https://pay.test/pay?'));

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store, 'bad-voucher', 'customer-1', 'user@example.test', 200, 0, 'SAVE10');
$core->voucherResults['SAVE10:200'] = ['ok' => false, 'message' => 'Mã đã hết hạn.'];
$session = [];
$invalidVoucher = $service->confirm('bad-voucher', $user, $session);
$check('voucher_invalid_at_confirm_returns_changed', $invalidVoucher['status'] === 'changed'
    && ($invalidVoucher['card']['voucher'] ?? null) === null);

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store, 'receiver-failure');
$store->docs['receiver-failure']['receiver'] = [];
$core->prices['p1'] = 150;
$receiverFailure = null;
$receiverSession = [];
try { $service->confirm('receiver-failure', $user, $receiverSession); } catch (DomainException $e) { $receiverFailure = $e; }
$check('changed_draft_failure_releases_old_draft', $receiverFailure?->getCode() === 422
    && $receiverFailure instanceof ChatDraftException
    && $receiverFailure->getErrorCode() === 'receiver_missing'
    && $store->docs['receiver-failure']['used_at'] === null);

$vnpayStore = new ChatOrderConfirmMemoryStore();
$vnpayCore = new ChatOrderConfirmCoreFake();
$vnpayDraft = 'vnpay-created-total';
chatOrderConfirmDraft($vnpayStore, $vnpayDraft);
$vnpayStore->docs[$vnpayDraft]['payment_method'] = 'vnpay';
$vnpayService = new ChatOrderConfirmService(
    new ChatDraftService($vnpayStore, $vnpayCore, static fn(): int => 1_800_000_000),
    $vnpayStore,
    $vnpayCore,
    static fn(array $payload): int => 905,
    static fn(): int => 1_800_000_000,
    static function (int $orderId): array { return ['tong_tien' => 777]; }
);
$vnpayCreated = $vnpayService->confirm($vnpayDraft, $user, $receiverSession);
parse_str((string)parse_url($vnpayCreated['next']['url'], PHP_URL_QUERY), $vnpayQuery);
$check('vnpay_uses_created_order_total', (int)($vnpayQuery['vnp_Amount'] ?? 0) === 77700);

// Users with id 0 / no id: the draft owner is the email-hash sub, and only that same user can confirm it.
$noIdUser = ['id' => 0, 'ho_ten' => 'Nguyen Van A', 'email' => 'user@example.test', 'role' => 'khach_hang'];
$noKeyUser = ['ho_ten' => 'Nguyen Van A', 'email' => 'user@example.test', 'role' => 'khach_hang'];
$otherNoIdUser = ['id' => 0, 'ho_ten' => 'Tran B', 'email' => 'other@example.test', 'role' => 'khach_hang'];
$ownerSub = ChatAgentIdentity::subFor($noIdUser);
$check('no_id_user_sub_is_em_form', preg_match('/^em:[0-9a-f]{32}$/', $ownerSub) === 1);

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store, 'em-draft', $ownerSub);
$session = [];
$emForeignError = null;
try { $service->confirm('em-draft', $otherNoIdUser, $session); } catch (DomainException $e) { $emForeignError = $e; }
$check('em_draft_rejected_for_other_id_zero_user', $emForeignError?->getCode() === 404);
$emZeroError = null;
try { $service->confirm('em-draft', ['id' => 0], $session); } catch (DomainException $e) { $emZeroError = $e; }
$check('em_draft_rejected_for_identity_less_user', $emZeroError?->getCode() === 404);
$emOwn = $service->confirm('em-draft', $noIdUser, $session);
$check('em_draft_confirmed_by_id_zero_owner', $emOwn['status'] === 'created');

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store, 'em-draft-2', $ownerSub);
$session = [];
$emOwn2 = $service->confirm('em-draft-2', $noKeyUser, $session);
$check('em_draft_confirmed_by_user_without_id_key', $emOwn2['status'] === 'created');

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store, 'em-changed', $ownerSub);
$core->prices['p1'] = 150;
$session = [];
$emChanged = $service->confirm('em-changed', $noIdUser, $session);
$newEmDraft = $emChanged['card']['draft_id'] ?? '';
$check('changed_draft_for_id_zero_user_keeps_em_sub', $emChanged['status'] === 'changed'
    && ($store->docs[$newEmDraft]['sub'] ?? '') === $ownerSub);

function controllerResponse(array $options): array
{
    $_SESSION = ['gio_hang' => ['p1' => 2]];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ORIGIN'] = array_key_exists('origin', $options) ? (string)$options['origin'] : 'https://shop.test';
    $body = json_encode([
        'draft_id' => $options['draft_id'] ?? 'draft-1',
        'csrf' => $options['csrf'] ?? 'valid-csrf',
        'payment_method' => $options['payment_method'] ?? '',
    ]);
    $gate = new ChatAgentGate(null, []);
    $controller = new ChatAgentController(
        null,
        $options['confirm_service'] ?? null,
        $gate,
        $options['limiter'] ?? null,
        $options['audit'] ?? new ChatAgentAudit(static function (array $document): void {}),
        static fn(): bool => $options['logged_in'] ?? true,
        static fn(): array => $options['user'] ?? [
            'id' => 'customer-1', 'ho_ten' => 'Nguyen Van A', 'email' => 'user@example.test',
            'role' => 'khach_hang', 'vai_tro' => 'khach_hang',
        ],
        static fn(): string => 'valid-csrf',
        static fn(): string => (string)$body,
        $options['purchase_enabled'] ?? null
    );
    ob_start();
    $controller->confirm();
    $json = json_decode((string)ob_get_clean(), true);
    return [$json, http_response_code()];
}

[$missingCsrf, $missingCsrfStatus] = controllerResponse(['csrf' => '']);
$check('controller_missing_csrf_403', $missingCsrfStatus === 403 && $missingCsrf['ok'] === false && empty($missingCsrf['csrf']));
[$wrongOrigin, $wrongOriginStatus] = controllerResponse(['origin' => 'https://evil.test']);
$check('controller_wrong_origin_403', $wrongOriginStatus === 403 && $wrongOrigin['ok'] === false);
[$missingOrigin, $missingOriginStatus] = controllerResponse(['origin' => null]);
$check('controller_missing_origin_403', $missingOriginStatus === 403 && $missingOrigin['ok'] === false);
[$staff, $staffStatus] = controllerResponse(['user' => ['sub' => 'staff', 'email' => 'staff@example.test', 'role' => 'admin']]);
$check('controller_staff_role_403', $staffStatus === 403 && $staff['ok'] === false);
[$off, $offStatus] = controllerResponse(['purchase_enabled' => static fn(): bool => false]);
$check('controller_kill_switch_off_403', $offStatus === 403 && $off['ok'] === false);
$redis = new ChatOrderConfirmRedisFake();
$redis->reply = [6, 600];
[$limited, $limitedStatus] = controllerResponse(['limiter' => new RateLimiter($redis)]);
$check('controller_rate_limit_exceeded_429', $limitedStatus === 429 && $limited['ok'] === false);

$controllerStore = new ChatOrderConfirmMemoryStore();
$controllerCore = new ChatOrderConfirmCoreFake();
chatOrderConfirmDraft($controllerStore);
$controllerService = new ChatOrderConfirmService(
    new ChatDraftService($controllerStore, $controllerCore, static fn(): int => 1_800_000_000),
    $controllerStore,
    $controllerCore,
    static fn(array $payload): int => 906,
    static fn(): int => 1_800_000_000
);
$controllerRedis = new ChatOrderConfirmRedisFake();
$controllerRedis->reply = [1, 600];
$auditDocuments = [];
[$positive, $positiveStatus] = controllerResponse([
    'confirm_service' => $controllerService,
    'limiter' => new RateLimiter($controllerRedis),
    'purchase_enabled' => static fn(): bool => true,
    'audit' => new ChatAgentAudit(static function (array $document) use (&$auditDocuments): void {
        $auditDocuments[] = $document;
    }),
]);
$check('controller_positive_reaches_service_and_creates', $positiveStatus === 200
    && $positive['ok'] === true && $positive['status'] === 'created' && $positive['order_id'] === 906
    && $auditDocuments[0]['actor'] === 'customer-1'
    && $auditDocuments[0]['action'] === 'chat_order_confirm'
    && $auditDocuments[0]['outcome'] === 'created'
    && ($auditDocuments[0]['params']['draft_id'] ?? '') === 'draft-1'
    && ($auditDocuments[0]['params']['payment_method'] ?? '') === 'cod'
    && ($auditDocuments[0]['params']['order_id'] ?? 0) === 906);

$emStore = new ChatOrderConfirmMemoryStore();
$emCore = new ChatOrderConfirmCoreFake();
chatOrderConfirmDraft($emStore, 'draft-1', $ownerSub);
$emService = new ChatOrderConfirmService(
    new ChatDraftService($emStore, $emCore, static fn(): int => 1_800_000_000),
    $emStore,
    $emCore,
    static fn(array $payload): int => 907,
    static fn(): int => 1_800_000_000
);
$emRedis = new ChatOrderConfirmRedisFake();
$emRedis->reply = [1, 600];
$emAudit = [];
[$emResp, $emStatus] = controllerResponse([
    'confirm_service' => $emService,
    'limiter' => new RateLimiter($emRedis),
    'purchase_enabled' => static fn(): bool => true,
    'user' => $noIdUser,
    'audit' => new ChatAgentAudit(static function (array $document) use (&$emAudit): void { $emAudit[] = $document; }),
]);
$check('controller_id_zero_user_confirms_own_draft', $emStatus === 200 && $emResp['ok'] === true
    && $emResp['order_id'] === 907 && ($emAudit[0]['actor'] ?? '') === $ownerSub);

$emStore2 = new ChatOrderConfirmMemoryStore();
chatOrderConfirmDraft($emStore2, 'draft-1', $ownerSub);
$emService2 = new ChatOrderConfirmService(
    new ChatDraftService($emStore2, $emCore, static fn(): int => 1_800_000_000),
    $emStore2,
    $emCore,
    static fn(array $payload): int => 908,
    static fn(): int => 1_800_000_000
);
[$emResp2, $emStatus2] = controllerResponse([
    'confirm_service' => $emService2,
    'limiter' => new RateLimiter($emRedis),
    'purchase_enabled' => static fn(): bool => true,
    'user' => $otherNoIdUser,
]);
$check('controller_other_id_zero_user_cannot_confirm_draft', $emStatus2 === 404 && $emResp2['ok'] === false);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
