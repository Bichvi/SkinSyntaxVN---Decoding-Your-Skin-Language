<?php
declare(strict_types=1);

// Buffer test output so controllerResponse() can still set HTTP status headers.
ob_start();

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
require_once __DIR__ . '/../../app/services/ChatOrderOtp.php';
require_once __DIR__ . '/../../app/services/Mailer.php';
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
        'otp' => $options['otp'] ?? '',
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
        $options['purchase_enabled'] ?? null,
        null,
        null,
        null,
        $options['otp_obj'] ?? null,
        $options['mailer'] ?? null
    );
    $action = $options['action'] ?? 'confirm';
    ob_start();
    $controller->$action();
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
$otpClockNow = 1_800_000_000;
$otpClock = static function () use (&$otpClockNow): int { return $otpClockNow; };
$otpSession = [];
$otpObj = new ChatOrderOtp($otpSession, $otpClock);
$otpCode = $otpObj->issue('customer-1', 'draft-1');
$wrongCode = $otpCode === '000000' ? '111111' : '000000';
$noOtpDocs = [];
[$noOtp, $noOtpStatus] = controllerResponse([
    'confirm_service' => $controllerService,
    'limiter' => new RateLimiter($controllerRedis),
    'purchase_enabled' => static fn(): bool => true,
    'otp_obj' => $otpObj,
]);
$check('controller_confirm_without_otp_428_no_order', $noOtpStatus === 428 && $noOtp['ok'] === false
    && $noOtp['code'] === 'otp_required' && empty($controllerStore->docs['draft-1']['used_at']));
[$badOtp, $badOtpStatus] = controllerResponse([
    'confirm_service' => $controllerService,
    'limiter' => new RateLimiter($controllerRedis),
    'purchase_enabled' => static fn(): bool => true,
    'otp_obj' => $otpObj,
    'otp' => $wrongCode,
]);
$check('controller_wrong_otp_422_counts_attempt', $badOtpStatus === 422 && $badOtp['code'] === 'otp_invalid'
    && $otpSession['chat_order_otp']['customer-1']['attempts'] === 1
    && empty($controllerStore->docs['draft-1']['used_at']));
[$positive, $positiveStatus] = controllerResponse([
    'confirm_service' => $controllerService,
    'limiter' => new RateLimiter($controllerRedis),
    'purchase_enabled' => static fn(): bool => true,
    'otp_obj' => $otpObj,
    'otp' => ' ' . substr($otpCode, 0, 3) . ' ' . substr($otpCode, 3),
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

$check('otp_consumed_after_order_created', !$otpObj->has('customer-1'));
[$reuse, $reuseStatus] = controllerResponse([
    'confirm_service' => $controllerService,
    'limiter' => new RateLimiter($controllerRedis),
    'purchase_enabled' => static fn(): bool => true,
    'otp_obj' => $otpObj,
    'otp' => $otpCode,
]);
$check('controller_reused_otp_428', $reuseStatus === 428 && $reuse['code'] === 'otp_required');

// 'changed' keeps the code but moves it to the replacement draft.
$chStore = new ChatOrderConfirmMemoryStore();
$chCore = new ChatOrderConfirmCoreFake();
chatOrderConfirmDraft($chStore);
$chCore->prices['p1'] = 150;
$chService = new ChatOrderConfirmService(
    new ChatDraftService($chStore, $chCore, static fn(): int => 1_800_000_000),
    $chStore,
    $chCore,
    static fn(array $payload): int => 909,
    static fn(): int => 1_800_000_000
);
$chSession = [];
$chOtp = new ChatOrderOtp($chSession, $otpClock);
$chCode = $chOtp->issue('customer-1', 'draft-1');
[$chResp, $chStatus] = controllerResponse([
    'confirm_service' => $chService,
    'limiter' => new RateLimiter($controllerRedis),
    'purchase_enabled' => static fn(): bool => true,
    'otp_obj' => $chOtp,
    'otp' => $chCode,
]);
$chNewId = $chResp['card']['draft_id'] ?? '';
$check('controller_changed_rebinds_otp_to_new_draft', $chStatus === 200 && ($chResp['status'] ?? '') === 'changed' && $chNewId !== ''
    && ($chSession['chat_order_otp']['customer-1']['draft_id'] ?? '') === $chNewId);

// sendOtp
$sendRedis = new ChatOrderConfirmRedisFake();
$sendRedis->reply = [1, 600];
$sendStore = new ChatOrderConfirmMemoryStore();
$sendCore = new ChatOrderConfirmCoreFake();
chatOrderConfirmDraft($sendStore);
chatOrderConfirmDraft($sendStore, 'foreign-draft', 'customer-2', 'other@example.test');
$sendService = new ChatOrderConfirmService(
    new ChatDraftService($sendStore, $sendCore, static fn(): int => 1_800_000_000),
    $sendStore,
    $sendCore,
    static fn(array $payload): int => 910,
    static fn(): int => 1_800_000_000
);
$sendSession = [];
$sendOtpObj = new ChatOrderOtp($sendSession, $otpClock);
$sent = [];
$fakeMailer = static function (string $to, string $subject, string $html) use (&$sent): bool {
    $sent[] = [$to, $subject, $html];
    return true;
};
$sendOpts = static fn(array $extra = []): array => $extra + [
    'action' => 'sendOtp',
    'confirm_service' => $sendService,
    'limiter' => new RateLimiter($sendRedis),
    'purchase_enabled' => static fn(): bool => true,
    'otp_obj' => $sendOtpObj,
    'mailer' => $fakeMailer,
];
[$sNoCsrf, $sNoCsrfStatus] = controllerResponse($sendOpts(['csrf' => '']));
$check('send_otp_missing_csrf_403', $sNoCsrfStatus === 403 && $sNoCsrf['ok'] === false && $sent === []);
[$sForeign, $sForeignStatus] = controllerResponse($sendOpts(['draft_id' => 'foreign-draft']));
$check('send_otp_foreign_draft_404', $sForeignStatus === 404 && $sForeign['code'] === 'not_found' && $sent === []);
[$sNone, $sNoneStatus] = controllerResponse($sendOpts(['draft_id' => 'missing']));
$check('send_otp_unknown_draft_404', $sNoneStatus === 404 && $sent === []);
[$sOk, $sOkStatus] = controllerResponse($sendOpts());
$check('send_otp_happy_path', $sOkStatus === 200 && $sOk['ok'] === true && $sOk['email_masked'] === 'u***@example.test'
    && $sOk['expires_in'] === 300 && $sOk['resend_in'] === 60
    && count($sent) === 1 && $sent[0][0] === 'user@example.test'
    && str_contains($sent[0][1], 'Mã xác nhận đơn hàng')
    && preg_match('/>(\d{6})</', $sent[0][2], $mm) === 1
    && hash('sha256', $mm[1]) === ($sendSession['chat_order_otp']['customer-1']['hash'] ?? ''));
$check('send_otp_response_never_contains_code', !str_contains(json_encode($sOk), $mm[1] ?? 'x-none'));
[$sCool, $sCoolStatus] = controllerResponse($sendOpts());
$check('send_otp_cooldown_429', $sCoolStatus === 429 && $sCool['code'] === 'otp_cooldown' && count($sent) === 1);

$failSession = [];
$failOtp = new ChatOrderOtp($failSession, $otpClock);
[$sFail, $sFailStatus] = controllerResponse($sendOpts([
    'otp_obj' => $failOtp,
    'mailer' => static fn(string $to, string $subject, string $html): bool => false,
]));
$check('send_otp_mail_failure_502_and_clears_code', $sFailStatus === 502 && $sFail['code'] === 'mail_failed' && !$failOtp->has('customer-1'));

$limRedis = new ChatOrderConfirmRedisFake();
$limRedis->reply = [4, 600];
[$sLim, $sLimStatus] = controllerResponse($sendOpts(['limiter' => new RateLimiter($limRedis), 'otp_obj' => new ChatOrderOtp($failSession, $otpClock)]));
$check('send_otp_rate_limited_429', $sLimStatus === 429);

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
$emOtpSession = [];
$emOtp = new ChatOrderOtp($emOtpSession, $otpClock);
$emCode = $emOtp->issue($ownerSub, 'draft-1');
[$emResp, $emStatus] = controllerResponse([
    'confirm_service' => $emService,
    'limiter' => new RateLimiter($emRedis),
    'purchase_enabled' => static fn(): bool => true,
    'user' => $noIdUser,
    'otp_obj' => $emOtp,
    'otp' => $emCode,
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
$otherSub = ChatAgentIdentity::subFor($otherNoIdUser);
$otherOtpSession = [];
$otherOtp = new ChatOrderOtp($otherOtpSession, $otpClock);
$otherCode = $otherOtp->issue($otherSub, 'draft-1');
[$emResp2, $emStatus2] = controllerResponse([
    'confirm_service' => $emService2,
    'limiter' => new RateLimiter($emRedis),
    'purchase_enabled' => static fn(): bool => true,
    'user' => $otherNoIdUser,
    'otp_obj' => $otherOtp,
    'otp' => $otherCode,
]);
$check('controller_other_id_zero_user_cannot_confirm_draft', $emStatus2 === 404 && $emResp2['ok'] === false);


// Cart lines are decremented by the ordered quantity, not wiped.
[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store);
$session = ['gio_hang' => ['p1' => 5, 'p2' => 1]];
$service->confirm('draft-1', $user, $session);
$check('cart_line_decremented_by_ordered_qty', ($session['gio_hang']['p1'] ?? null) === 3 && ($session['gio_hang']['p2'] ?? null) === 1);

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store);
$session = ['gio_hang' => ['p1' => 1]];
$service->confirm('draft-1', $user, $session);
$check('cart_line_removed_when_ordered_qty_covers_it', !isset($session['gio_hang']['p1']) && $session['gio_hang'] === []);

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store);
$session = ['gio_hang' => ['p2' => 4]];
$service->confirm('draft-1', $user, $session);
$check('cart_without_ordered_line_is_untouched', $session['gio_hang'] === ['p2' => 4]);

[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store);
$session = [];
$service->confirm('draft-1', $user, $session);
$check('missing_cart_is_not_created_by_confirm', !isset($session['gio_hang']) || $session['gio_hang'] === []);

// The "changed" card shows the customer their full receiver again.
[$service, $store, $core] = chatOrderConfirmServiceFixture();
chatOrderConfirmDraft($store);
$core->prices['p1'] = 150;
$session = ['gio_hang' => ['p1' => 2]];
$changedFull = $service->confirm('draft-1', $user, $session);
$check('changed_card_carries_full_receiver', $changedFull['status'] === 'changed'
    && ($changedFull['card']['receiver']['phone'] ?? '') === '0901 234 123'
    && ($changedFull['card']['receiver']['address'] ?? '') === '12 Nguyen Hue, TP.HCM'
    && ($changedFull['card']['receiver']['phone_masked'] ?? '') === '09xx xxx 123'
    && ($changedFull['card']['receiver']['address_short'] ?? '') === 'TP.HCM'
    && ($changedFull['card']['items'][0]['qty'] ?? 0) === 2);

// enrichCard is the browser-only view used by the SSE relay; other users get the card unchanged.
$newCard = $changedFull['card'];
unset($newCard['receiver']['phone'], $newCard['receiver']['address']);
$check('enrich_card_adds_full_receiver_for_owner', ($service->enrichCard($newCard, 'customer-1')['receiver']['phone'] ?? '') === '0901 234 123');
$check('enrich_card_ignores_other_sub', $service->enrichCard($newCard, 'customer-2') === $newCard);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
