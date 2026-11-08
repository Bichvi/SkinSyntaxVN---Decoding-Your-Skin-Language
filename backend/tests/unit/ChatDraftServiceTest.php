<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/services/CheckoutCore.php';
require_once __DIR__ . '/../../app/services/ChatDraftService.php';

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

final class ChatDraftMemoryStore implements ChatDraftStore
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

final class ChatDraftMongoCollectionFake
{
    public array $calls = [];
    public array $document = [
        '_id' => 'mongo-draft', 'sub' => 'customer-1', 'used_at' => null,
        'items' => [['id' => 'p1', 'qty' => 1]],
    ];

    public function createIndex(array $keys, array $options): void {}

    public function findOne(array $filter, array $options = []): array
    {
        $this->calls[] = ['method' => 'findOne', 'options' => $options];
        return $this->document;
    }

    public function countDocuments(array $filter): int { return 0; }

    public function findOneAndUpdate(array $filter, array $update, array $options = []): array
    {
        $this->calls[] = ['method' => 'findOneAndUpdate', 'options' => $options];
        if (array_key_exists('used_at', $update['$set'])) {
            $this->document['used_at'] = $update['$set']['used_at'];
        }
        return $this->document;
    }
}

final class ChatDraftMongoDbFake
{
    public ChatDraftMongoCollectionFake $chat_order_drafts;

    public function __construct()
    {
        $this->chat_order_drafts = new ChatDraftMongoCollectionFake();
    }
}

final class ChatDraftCoreFake extends CheckoutCore
{
    public ?array $voucher = null;
    public ?array $validatedVoucher = null;
    public array $bestVoucherCustomerIds = [];

    public function __construct() {}
    public function preview(array $checkoutItems): array
    {
        $items = [];
        $subtotal = 0;
        foreach ($checkoutItems as $id => $qty) {
            $qty = (int)$qty;
            $unit = $id === 'p2' ? 200 : 100;
            $product = ['ten_san_pham' => $id === 'p2' ? 'Serum' : 'Cleanser', 'hinh_anh' => 'image-' . $id];
            $items[] = ['id' => (string)$id, 'product' => $product, 'qty' => $qty, 'unit_price' => $unit, 'line_total' => $unit * $qty];
            $subtotal += $unit * $qty;
        }
        return ['items' => $items, 'subtotal' => $subtotal, 'stock_errors' => []];
    }
    public function bestVoucher(string $customerId, int $subtotal): ?array
    {
        $this->bestVoucherCustomerIds[] = $customerId;
        return $this->voucher;
    }
    public function validateVoucher(string $code, int $subtotal): array
    {
        return $this->validatedVoucher ?? ['ok' => false, 'message' => 'Mã không hợp lệ.'];
    }
}

$clock = static fn(): int => 1_800_000_000;
$claims = ['sub' => 'customer-1', 'em' => 'user@example.test', 'customer_id' => '77'];
$snapshot = ['p1' => ['id' => 'p1'], 'p2' => ['id' => 'p2']];
$baseRequest = [
    'scope' => 'mentioned_items',
    'product_ids' => ['p1'],
    'qty' => ['p1' => 2],
    'address_choice' => 'new',
    'new_address' => [
        'ten_nguoi_nhan' => 'Nguyen Van A',
        'sdt_nguoi_nhan' => '0901234123',
        'dia_chi_giao_hang' => '12 Nguyen Hue, TP.HCM',
    ],
    'payment_method' => 'cod',
];

$store = new ChatDraftMemoryStore();
$core = new ChatDraftCoreFake();
$service = new ChatDraftService($store, $core, $clock);
$freshService = static fn(): ChatDraftService => new ChatDraftService(new ChatDraftMemoryStore(), $core, $clock);
$card = $service->upsert($claims, $baseRequest, $snapshot);
$check('creates_card_with_masked_phone', $card['receiver'] === [
    'name' => 'Nguyen Van A', 'phone_masked' => '09xx xxx 123', 'address_short' => 'TP.HCM',
] && $card['items'][0]['line_total'] === 200 && $card['expires_at'] === 1_800_000_600);

$check('card_carries_server_now', $card['server_now'] === 1_800_000_000);
$check('get_card_carries_server_now', $service->get($card['draft_id'], 'customer-1')['server_now'] === 1_800_000_000);

$core->voucher = ['voucher' => ['ma_code' => 'SAVE'], 'discount' => 50];
$voucherCard = $freshService()->upsert($claims, $baseRequest, $snapshot);
$check('auto_applies_best_voucher', $voucherCard['voucher'] === ['code' => 'SAVE', 'discount' => 50]
    && $core->bestVoucherCustomerIds[array_key_last($core->bestVoucherCustomerIds)] === '77');

$core->validatedVoucher = ['ok' => false, 'message' => 'Đơn hàng chưa đạt tối thiểu.'];
$invalidVoucherRequest = $baseRequest;
$invalidVoucherRequest['voucher_code'] = 'BAD10';
$invalidVoucherCard = $freshService()->upsert($claims, $invalidVoucherRequest, $snapshot);
$check('invalid_voucher_falls_back_with_warning', $invalidVoucherCard['voucher'] === ['code' => 'SAVE', 'discount' => 50]
    && $invalidVoucherCard['warnings'] === ['Mã BAD10 không áp dụng được: Đơn hàng chưa đạt tối thiểu.']);

$core->validatedVoucher = ['ok' => true, 'voucher' => ['ma_code' => 'DIRECT'], 'discount' => 25];
$validVoucherRequest = $baseRequest;
$validVoucherRequest['voucher_code'] = 'DIRECT';
$validVoucherCard = $freshService()->upsert($claims, $validVoucherRequest, $snapshot);
$check('valid_voucher_code_is_used', $validVoucherCard['voucher'] === ['code' => 'DIRECT', 'discount' => 25]
    && $validVoucherCard['warnings'] === []);

$remove = $baseRequest;
$remove['remove_voucher'] = true;
$removeCard = $freshService()->upsert($claims, $remove, $snapshot);
$check('remove_voucher_respected', $removeCard['voucher'] === null);
$falseRemove = $baseRequest;
$falseRemove['remove_voucher'] = 'false';
$falseRemove['voucher_code'] = 'DIRECT';
$falseRemoveCard = $freshService()->upsert($claims, $falseRemove, $snapshot);
$check('remove_voucher_only_accepts_true_values', $falseRemoveCard['voucher'] === ['code' => 'DIRECT', 'discount' => 25]);

$throws = static function (callable $fn): ?DomainException {
    try { $fn(); } catch (DomainException $e) { return $e; }
    return null;
};
$storeLimit = new ChatDraftMemoryStore();
$limitService = new ChatDraftService($storeLimit, $core, $clock);
for ($i = 0; $i < 3; $i++) $limitService->upsert($claims, $baseRequest, $snapshot);
$limitError = $throws(static fn() => $limitService->upsert($claims, $baseRequest, $snapshot));
$check('rejects_fourth_open_draft', $limitError?->getCode() === 429);

$other = $service->get($card['draft_id'], 'another-customer');
$check('draft_of_other_user_is_hidden', $other === null);
$store->docs[$card['draft_id']]['expires_at'] = 1_799_999_999;
$check('expired_draft_not_returned', $service->get($card['draft_id'], 'customer-1') === null);
$store->docs[$card['draft_id']]['used_at'] = 1_800_000_001;
$store->release($card['draft_id']);
$check('memory_release_restores_draft', isset($store->docs[$card['draft_id']]) && $store->docs[$card['draft_id']]['used_at'] === null);

$mongoDb = new ChatDraftMongoDbFake();
$mongoStore = new MongoChatDraftStore($mongoDb);
$mongoStore->find('mongo-draft');
$mongoStore->markUsed('mongo-draft', 1_800_000_001);
$mongoStore->release('mongo-draft');
$typeMapCalls = array_filter($mongoDb->chat_order_drafts->calls, static fn(array $call): bool =>
    in_array($call['method'], ['findOne', 'findOneAndUpdate'], true)
);
$check('mongo_store_uses_array_type_map_and_release_restores', count($typeMapCalls) === 3
    && count(array_filter($typeMapCalls, static fn(array $call): bool => ($call['options']['typeMap'] ?? null) === [
        'root' => 'array', 'document' => 'array', 'array' => 'array',
    ])) === 3
    && $mongoDb->chat_order_drafts->document['used_at'] === null);

$wholeRequest = $baseRequest;
$wholeRequest['scope'] = 'whole_cart';
$wholeRequest['product_ids'] = ['not-in-snapshot'];
$wholeCard = $freshService()->upsert($claims, $wholeRequest, $snapshot);
$check('whole_cart_uses_cart_snapshot', count($wholeCard['items']) === 2
    && array_column($wholeCard['items'], 'id') === ['p1', 'p2']);

$receiverMissing = $baseRequest;
$receiverMissing['receiver'] = $baseRequest['new_address'];
unset($receiverMissing['new_address']);
$receiverError = $throws(static fn() => $service->upsert($claims, $receiverMissing, $snapshot));
$check('raw_receiver_keys_are_ignored', $receiverError?->getErrorCode() === 'receiver_missing');

$receiverInvalid = $baseRequest;
$receiverInvalid['address_choice'] = 'saved';
$receiverInvalidError = $throws(static fn() => $service->upsert($claims, $receiverInvalid, $snapshot));
$check('invalid_address_choice_rejected', $receiverInvalidError?->getErrorCode() === 'receiver_invalid');

$normalizedReceiver = $baseRequest;
$normalizedReceiver['new_address'] = [
    'ten_nguoi_nhan' => '  Nguyen Van B  ',
    'sdt_nguoi_nhan' => ' +84 901.234.567 ',
    'dia_chi_giao_hang' => ' 123 Le Loi, TP.HCM ',
    'ignored' => 'must not be stored',
];
$normalizedCard = $service->upsert($claims, $normalizedReceiver, $snapshot);
$check('new_receiver_is_validated_and_trimmed', $normalizedCard['receiver'] === [
    'name' => 'Nguyen Van B', 'phone_masked' => '09xx xxx 567', 'address_short' => 'TP.HCM',
]);

$qrError = $throws(static function () use ($service, $claims, $baseRequest, $snapshot): void {
    $request = $baseRequest;
    $request['payment_method'] = 'bank_transfer_qr';
    $service->upsert($claims, $request, $snapshot);
});
$check('qr_rejected_when_disabled', $qrError?->getCode() === 422);

$replaceStore = new ChatDraftMemoryStore();
$replaceService = new ChatDraftService($replaceStore, $core, $clock);
$old = $replaceService->upsert($claims, $baseRequest, $snapshot);
$newRequest = $baseRequest;
$newRequest['replaces'] = $old['draft_id'];
$new = $replaceService->upsert($claims, $newRequest, $snapshot);
$check('replaces_marks_old_used', $replaceStore->docs[$old['draft_id']]['used_at'] === 1_800_000_000
    && $replaceStore->countOpen('customer-1', 1_800_000_000) === 1 && $new['draft_id'] !== $old['draft_id']);

$foreignClaims = ['sub' => 'customer-2', 'em' => 'other@example.test', 'customer_id' => '88'];
$foreign = $replaceService->upsert($foreignClaims, $baseRequest, $snapshot);
$crossUserRequest = $baseRequest;
$crossUserRequest['replaces'] = $foreign['draft_id'];
$replaceService->upsert($claims, $crossUserRequest, $snapshot);
$check('replaces_ignores_another_users_draft', empty($replaceStore->docs[$foreign['draft_id']]['used_at']));

// Conflict warnings on the draft card.
$checkerCalls = [];
$checker = static function (array $ids) use (&$checkerCalls): array {
    $checkerCalls[] = $ids;
    return ['Retinol và AHA không nên dùng cùng buổi tối.'];
};
$conflictService = new ChatDraftService(new ChatDraftMemoryStore(), $core, $clock, null, $checker);
$twoItemRequest = $baseRequest;
$twoItemRequest['product_ids'] = ['p1', 'p2'];
$conflictCard = $conflictService->upsert($claims, $twoItemRequest, $snapshot);
$check('conflict_checker_output_merged_into_warnings', $checkerCalls !== []
    && $checkerCalls[0] === ['p1', 'p2']
    && in_array('Retinol và AHA không nên dùng cùng buổi tối.', $conflictCard['warnings'], true));
$noCheckerCard = (new ChatDraftService(new ChatDraftMemoryStore(), $core, $clock))->upsert($claims, $baseRequest, $snapshot);
$check('no_conflict_checker_means_no_extra_warnings', $noCheckerCard['warnings'] === []);
$throwingService = new ChatDraftService(new ChatDraftMemoryStore(), $core, $clock, null, static function (array $ids): array {
    throw new RuntimeException('graph down');
});
$check('conflict_checker_failure_never_blocks_draft', $throwingService->upsert($claims, $baseRequest, $snapshot)['warnings'] === []);

$warnRequest = $baseRequest;
$warnRequest['conflict_warnings'] = [
    'Cảnh báo một',
    str_repeat('x', 500),
    "  ",
    ['not', 'a', 'string'],
    "Cảnh báo	tab
xuống dòng",
    'Cảnh báo thứ tư',
    'Cảnh báo thứ năm',
];
$warnStore = new ChatDraftMemoryStore();
$warnCard = (new ChatDraftService($warnStore, $core, $clock))->upsert($claims, $warnRequest, $snapshot);
$check('payload_conflict_warnings_are_sanitised_and_capped', count($warnCard['warnings']) === 3
    && $warnCard['warnings'][0] === 'Cảnh báo một'
    && mb_strlen($warnCard['warnings'][1]) === 200
    && $warnCard['warnings'][2] === 'Cảnh báo tab xuống dòng');
$check('payload_conflict_warnings_survive_draft_get', (new ChatDraftService($warnStore, $core, $clock))->get($warnCard['draft_id'], 'customer-1')['warnings'] === $warnCard['warnings']);

$core->validatedVoucher = null;
$voucherWarnService = new ChatDraftService(new ChatDraftMemoryStore(), $core, $clock);
$voucherWarnRequest = $warnRequest;
$voucherWarnRequest['voucher_code'] = 'BAD';
$voucherWarnCard = $voucherWarnService->upsert($claims, $voucherWarnRequest, $snapshot);
$check('conflict_warnings_append_after_voucher_warning', str_starts_with($voucherWarnCard['warnings'][0], 'Mã BAD không áp dụng được')
    && count($voucherWarnCard['warnings']) === 4);
$badTypeRequest = $baseRequest;
$badTypeRequest['conflict_warnings'] = 'not-an-array';
$check('non_array_conflict_warnings_ignored', $voucherWarnService->upsert($claims, $badTypeRequest, $snapshot)['warnings'] === []);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
