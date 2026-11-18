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


// ---- Chat-order correctness: partial receiver change, validation, quantities, payment, totals ----
$errCode = static fn(?DomainException $e): string => $e instanceof ChatDraftException ? $e->getErrorCode() : '';
$fullDefault = ['ten_nguoi_nhan' => 'Tran Thi C', 'sdt_nguoi_nhan' => '0912345678', 'dia_chi_giao_hang' => '5 Hai Ba Trung, Quan 1, TP.HCM'];

// Partial change relative to the replaced open draft.
$pStore = new ChatDraftMemoryStore();
$pService = new ChatDraftService($pStore, $core, $clock);
$pOld = $pService->upsert($claims, $baseRequest, $snapshot);
$phoneOnly = ['scope' => 'mentioned_items', 'product_ids' => ['p1'], 'address_choice' => 'new',
    'new_address' => ['sdt_nguoi_nhan' => '0987654321'], 'payment_method' => 'cod', 'replaces' => $pOld['draft_id']];
$pNew = $pService->upsert($claims, $phoneOnly, $snapshot);
$check('partial_phone_change_keeps_name_and_address_of_replaced_draft',
    $pStore->docs[$pNew['draft_id']]['receiver'] === ['ten_nguoi_nhan' => 'Nguyen Van A', 'sdt_nguoi_nhan' => '0987654321', 'dia_chi_giao_hang' => '12 Nguyen Hue, TP.HCM']
    && $pNew['receiver']['phone_masked'] === '09xx xxx 321');
$addrOnly = $phoneOnly;
$addrOnly['new_address'] = ['dia_chi_giao_hang' => '99 Le Loi, Quan 3, TP.HCM'];
$addrOnly['replaces'] = $pNew['draft_id'];
$pNew2 = $pService->upsert($claims, $addrOnly, $snapshot);
$check('partial_address_change_keeps_phone_from_previous_change',
    $pStore->docs[$pNew2['draft_id']]['receiver'] === ['ten_nguoi_nhan' => 'Nguyen Van A', 'sdt_nguoi_nhan' => '0987654321', 'dia_chi_giao_hang' => '99 Le Loi, Quan 3, TP.HCM']);
$nameOnly = $phoneOnly;
$nameOnly['new_address'] = ['ten_nguoi_nhan' => 'Le Van D', 'sdt_nguoi_nhan' => '  ', 'dia_chi_giao_hang' => ''];
$nameOnly['replaces'] = $pNew2['draft_id'];
$pNew3 = $pService->upsert($claims, $nameOnly, $snapshot);
$check('partial_name_change_ignores_blank_fields',
    $pStore->docs[$pNew3['draft_id']]['receiver'] === ['ten_nguoi_nhan' => 'Le Van D', 'sdt_nguoi_nhan' => '0987654321', 'dia_chi_giao_hang' => '99 Le Loi, Quan 3, TP.HCM']);

// "keep": a payment-only change keeps the receiver of the replaced draft, even when the account has a default.
$keepReq = ['scope' => 'mentioned_items', 'product_ids' => [], 'address_choice' => 'keep', 'payment_method' => 'vnpay',
    'replaces' => $pNew3['draft_id'], '_server_default_receiver' => $fullDefault];
$pKeep = $pService->upsert($claims, $keepReq, $snapshot);
$check('keep_reuses_replaced_receiver_not_account_default',
    $pStore->docs[$pKeep['draft_id']]['receiver'] === $pStore->docs[$pNew3['draft_id']]['receiver']
    && $pKeep['payment_method'] === 'vnpay');
// "keep" without an open draft falls back to the account default.
$keepFresh = $freshService()->upsert($claims, ['scope' => 'mentioned_items', 'product_ids' => ['p1'], 'address_choice' => 'keep',
    'payment_method' => 'cod', '_server_default_receiver' => $fullDefault], $snapshot);
$check('keep_without_open_draft_uses_default', $keepFresh['receiver']['name'] === 'Tran Thi C');
// "default" while the account default is incomplete cannot be what the customer meant: keep the draft's receiver.
$emptyDefault = ['ten_nguoi_nhan' => 'E2E', 'sdt_nguoi_nhan' => '', 'dia_chi_giao_hang' => ''];
$pDef = $pService->upsert($claims, ['scope' => 'mentioned_items', 'product_ids' => [], 'address_choice' => 'default',
    'payment_method' => 'cod', 'replaces' => $pKeep['draft_id'], '_server_default_receiver' => $emptyDefault], $snapshot);
$check('default_with_incomplete_account_default_keeps_draft_receiver',
    $pStore->docs[$pDef['draft_id']]['receiver'] === $pStore->docs[$pNew3['draft_id']]['receiver']);
// An explicit "default" with a complete account default switches to it.
$pDef2 = $pService->upsert($claims, ['scope' => 'mentioned_items', 'product_ids' => [], 'address_choice' => 'default',
    'payment_method' => 'cod', 'replaces' => $pDef['draft_id'], '_server_default_receiver' => $fullDefault], $snapshot);
$check('explicit_default_switches_to_complete_account_default', $pStore->docs[$pDef2['draft_id']]['receiver']['ten_nguoi_nhan'] === 'Tran Thi C');

// Partial change relative to the server default when nothing is being replaced.
$dReq = $phoneOnly;
unset($dReq['replaces']);
$dReq['_server_default_receiver'] = $fullDefault;
$dStore = new ChatDraftMemoryStore();
$dCard = (new ChatDraftService($dStore, $core, $clock))->upsert($claims, $dReq, $snapshot);
$check('partial_change_from_default_when_no_replaced_draft',
    $dStore->docs[$dCard['draft_id']]['receiver'] === ['ten_nguoi_nhan' => 'Tran Thi C', 'sdt_nguoi_nhan' => '0987654321', 'dia_chi_giao_hang' => '5 Hai Ba Trung, Quan 1, TP.HCM']);
$staleReq = $dReq;
$staleReq['replaces'] = 'no-such-draft';
$staleCard = (new ChatDraftService(new ChatDraftMemoryStore(), $core, $clock))->upsert($claims, $staleReq, $snapshot);
$check('stale_replaces_falls_back_to_default_receiver', $staleCard['receiver']['name'] === 'Tran Thi C');

// Missing field messages.
$noBase = $phoneOnly;
unset($noBase['replaces']);
$e = $throws(static fn() => $freshService()->upsert($claims, $noBase, $snapshot));
$check('missing_name_and_address_named', $errCode($e) === 'receiver_missing' && $e->getCode() === 422
    && str_contains($e->getMessage(), 'tên người nhận') && str_contains($e->getMessage(), 'địa chỉ giao hàng')
    && !str_contains($e->getMessage(), 'số điện thoại'));
$noPhone = $dReq;
$noPhone['new_address'] = ['ten_nguoi_nhan' => 'A B', 'dia_chi_giao_hang' => '12 Nguyen Hue, TP.HCM'];
unset($noPhone['_server_default_receiver']);
$e = $throws(static fn() => $freshService()->upsert($claims, $noPhone, $snapshot));
$check('missing_phone_message_exact', $errCode($e) === 'receiver_missing' && $e->getMessage() === 'Thiếu số điện thoại người nhận.');

// Phone formats.
$phoneCase = static function (string $phone) use ($claims, $baseRequest, $snapshot, $freshService, $throws): array {
    $req = $baseRequest;
    $req['new_address']['sdt_nguoi_nhan'] = $phone;
    $got = null;
    $e = $throws(function () use (&$got, $freshService, $claims, $req, $snapshot): void {
        $svc = $freshService();
        $card = $svc->upsert($claims, $req, $snapshot);
        $got = $card['receiver']['phone_masked'];
    });
    return [$got, $e instanceof ChatDraftException ? $e->getErrorCode() : ($e === null ? '' : 'other')];
};
$check('phone_plus84_accepted', $phoneCase('+84 912 345 678')[0] === '09xx xxx 678');
$check('phone_dots_accepted', $phoneCase('0912.345.678')[0] === '09xx xxx 678');
$check('phone_dashes_parens_accepted', $phoneCase('(0912) 345-678')[0] === '09xx xxx 678');
$check('phone_84_prefix_accepted', $phoneCase('84912345678')[0] === '09xx xxx 678');
$check('phone_landline_accepted', $phoneCase('02838221234')[0] === '02xx xxx 234');
$check('phone_9_digits_rejected', $phoneCase('091234567')[1] === 'receiver_phone_invalid');
$check('phone_bad_prefix_rejected', $phoneCase('0123456789')[1] === 'receiver_phone_invalid');
$check('phone_letters_rejected', $phoneCase('09123abc78')[1] === 'receiver_phone_invalid');
$check('phone_11_digit_mobile_rejected', $phoneCase('09123456789')[1] === 'receiver_phone_invalid');
$e = $throws(static function () use ($freshService, $claims, $baseRequest, $snapshot): void {
    $r = $baseRequest; $r['new_address']['sdt_nguoi_nhan'] = '123';
    $freshService()->upsert($claims, $r, $snapshot);
});
$check('phone_invalid_message', $e?->getCode() === 422 && $e->getMessage() === 'Số điện thoại không hợp lệ (cần 10 số, bắt đầu bằng 0).');

// Address and name validation.
$e = $throws(static function () use ($freshService, $claims, $baseRequest, $snapshot): void {
    $r = $baseRequest; $r['new_address']['dia_chi_giao_hang'] = '  12   Q1 ';
    $freshService()->upsert($claims, $r, $snapshot);
});
$check('address_too_short_rejected', $errCode($e) === 'receiver_address_invalid' && $e->getCode() === 422
    && $e->getMessage() === 'Địa chỉ cần ghi rõ số nhà/đường, phường/xã, quận/huyện, tỉnh/thành.');
$e = $throws(static function () use ($freshService, $claims, $baseRequest, $snapshot): void {
    $r = $baseRequest; $r['new_address']['dia_chi_giao_hang'] = str_repeat('a', 256);
    $freshService()->upsert($claims, $r, $snapshot);
});
$check('address_too_long_rejected', $errCode($e) === 'receiver_address_invalid');
$wsStore = new ChatDraftMemoryStore();
$wsReq = $baseRequest;
$wsReq['new_address']['dia_chi_giao_hang'] = "  12  Nguyen\tHue,   TP.HCM  ";
$wsCard = (new ChatDraftService($wsStore, $core, $clock))->upsert($claims, $wsReq, $snapshot);
$check('address_whitespace_collapsed', $wsStore->docs[$wsCard['draft_id']]['receiver']['dia_chi_giao_hang'] === '12 Nguyen Hue, TP.HCM');
foreach (['1', '12345', 'A'] as $badName) {
    $e = $throws(static function () use ($freshService, $claims, $baseRequest, $snapshot, $badName): void {
        $r = $baseRequest; $r['new_address']['ten_nguoi_nhan'] = $badName;
        $freshService()->upsert($claims, $r, $snapshot);
    });
    $check('name_invalid_' . $badName, $errCode($e) === 'receiver_name_invalid' && $e->getCode() === 422);
}

// Quantities.
$qtyRequest = static function (mixed $qty) use ($baseRequest): array {
    $r = $baseRequest;
    $r['qty'] = ['p1' => $qty];
    return $r;
};
foreach ([0, 21, -1, 1.5, 'abc', '2.5', '0'] as $badQty) {
    $e = $throws(static fn() => $freshService()->upsert($claims, $qtyRequest($badQty), $snapshot));
    $check('qty_invalid_' . var_export($badQty, true), $errCode($e) === 'qty_invalid' && $e->getCode() === 422
        && $e->getMessage() === 'Số lượng mỗi sản phẩm phải từ 1 đến 20.');
}
$check('qty_string_two_accepted', $freshService()->upsert($claims, $qtyRequest('2'), $snapshot)['items'][0]['qty'] === 2);
$check('qty_twenty_accepted', $freshService()->upsert($claims, $qtyRequest(20), $snapshot)['items'][0]['qty'] === 20);

// Quantity fallback: replaced draft beats cart snapshot, even for an item that is not in the cart.
$qStore = new ChatDraftMemoryStore();
$qService = new ChatDraftService($qStore, $core, $clock);
$qOldReq = $baseRequest;
$qOldReq['qty'] = ['p1' => 3];
$qOld = $qService->upsert($claims, $qOldReq, ['p2' => ['id' => 'p2']]);
$qNewReq = $baseRequest;
unset($qNewReq['qty']);
$qNewReq['replaces'] = $qOld['draft_id'];
$qNew = $qService->upsert($claims, $qNewReq, ['p2' => ['id' => 'p2']]);
$check('qty_falls_back_to_replaced_draft_for_item_not_in_cart', $qNew['items'][0]['qty'] === 3 && $qNew['subtotal'] === 300);
$qSnapReq = $baseRequest;
unset($qSnapReq['qty']);
$qSnap = $freshService()->upsert($claims, $qSnapReq, ['p1' => ['id' => 'p1', 'qty' => 4]]);
$check('qty_falls_back_to_cart_snapshot', $qSnap['items'][0]['qty'] === 4);
$qOne = $freshService()->upsert($claims, $qSnapReq, ['p2' => ['id' => 'p2']]);
$check('qty_falls_back_to_one', $qOne['items'][0]['qty'] === 1);
$qExplicitReq = $baseRequest;
$qExplicitReq['qty'] = ['p1' => 5];
$qExplicitReq['replaces'] = $qNew['draft_id'];
$check('explicit_qty_beats_replaced_draft', $qService->upsert($claims, $qExplicitReq, [])['items'][0]['qty'] === 5);

// Empty product_ids with a replaced draft reuses its items and quantities (e.g. "đổi sang VNPay").
$rStore = new ChatDraftMemoryStore();
$rService = new ChatDraftService($rStore, $core, $clock);
$rOldReq = $baseRequest;
$rOldReq['product_ids'] = ['p1', 'p2'];
$rOldReq['qty'] = ['p1' => 2, 'p2' => 3];
$rOld = $rService->upsert($claims, $rOldReq, []);
$rNew = $rService->upsert($claims, [
    'scope' => 'mentioned_items', 'product_ids' => [], 'address_choice' => 'new', 'new_address' => [],
    'payment_method' => 'vnpay', 'replaces' => $rOld['draft_id'],
], []);
$check('empty_product_ids_reuse_replaced_draft_items', array_column($rNew['items'], 'qty', 'id') === ['p1' => 2, 'p2' => 3]
    && $rNew['payment_method'] === 'vnpay' && $rNew['total'] === $rOld['total']
    && $rNew['receiver'] === $rOld['receiver']);
$e = $throws(static fn() => $freshService()->upsert($claims, ['scope' => 'mentioned_items', 'product_ids' => [], 'address_choice' => 'new',
    'new_address' => $baseRequest['new_address'], 'payment_method' => 'cod'], $snapshot));
$check('empty_product_ids_without_replaced_draft_rejected', $e?->getCode() === 422);

// Payment codes.
$e = $throws(static function () use ($freshService, $claims, $baseRequest, $snapshot): void {
    $r = $baseRequest; $r['payment_method'] = 'bank_transfer_qr';
    $freshService()->upsert($claims, $r, $snapshot);
});
$check('qr_disabled_has_own_code_and_message', $errCode($e) === 'payment_unsupported' && $e->getCode() === 422
    && $e->getMessage() === 'Cửa hàng chưa hỗ trợ chuyển khoản QR, bạn chọn COD hoặc VNPay nhé.');
$e = $throws(static function () use ($freshService, $claims, $baseRequest, $snapshot): void {
    $r = $baseRequest; $r['payment_method'] = 'momo';
    $freshService()->upsert($claims, $r, $snapshot);
});
$check('unknown_payment_is_payment_invalid', $errCode($e) === 'payment_invalid' && $e->getCode() === 422);

// Totals stay subtotal - discount + 30000 across a change of quantity, voucher and payment.
$core->voucher = null;
$tStore = new ChatDraftMemoryStore();
$tService = new ChatDraftService($tStore, $core, $clock);
$t1 = $tService->upsert($claims, $baseRequest, $snapshot);
$check('total_with_two_items', $t1['subtotal'] === 200 && $t1['total'] === 30200 && $t1['shipping'] === 30000);
$core->validatedVoucher = ['ok' => true, 'voucher' => ['ma_code' => 'V50'], 'discount' => 50];
$t2Req = $baseRequest;
$t2Req['qty'] = ['p1' => 7];
$t2Req['voucher_code'] = 'V50';
$t2Req['payment_method'] = 'vnpay';
$t2Req['replaces'] = $t1['draft_id'];
$t2 = $tService->upsert($claims, $t2Req, $snapshot);
$check('total_after_qty_voucher_payment_change', $t2['subtotal'] === 700 && $t2['voucher']['discount'] === 50
    && $t2['total'] === 700 - 50 + 30000 && $tStore->docs[$t2['draft_id']]['total'] === $t2['total']);
$core->validatedVoucher = null;

// Full receiver for the customer-facing card; the masked form stays for the LLM.
$disp = ChatDraftService::displayReceiver(['ten_nguoi_nhan' => 'Tran Thi C', 'sdt_nguoi_nhan' => '0912345678', 'dia_chi_giao_hang' => '5 Hai Ba Trung, Quan 1, TP.HCM']);
$check('display_receiver_full_values', $disp === ['name' => 'Tran Thi C', 'phone' => '0912 345 678', 'address' => '5 Hai Ba Trung, Quan 1, TP.HCM']);
$check('display_receiver_landline_format', ChatDraftService::displayReceiver(['sdt_nguoi_nhan' => '02838221234'])['phone'] === '028 3822 1234');
$check('display_receiver_empty_is_safe', ChatDraftService::displayReceiver([]) === ['name' => '', 'phone' => '', 'address' => '']);
$check('masked_receiver_still_masks', ChatDraftService::maskReceiver($fullDefault) === ['name' => 'Tran Thi C', 'phone_masked' => '09xx xxx 678', 'address_short' => 'TP.HCM']);
$check('card_from_upsert_stays_masked', !isset($t1['receiver']['phone']) && !isset($t1['receiver']['address']));
$full = $tService->withFullReceiver($t2, 'customer-1');
$check('with_full_receiver_adds_full_fields_and_keeps_masked',
    $full['receiver']['phone'] === '0901 234 123' && $full['receiver']['address'] === '12 Nguyen Hue, TP.HCM'
    && $full['receiver']['phone_masked'] === '09xx xxx 123' && $full['receiver']['address_short'] === 'TP.HCM'
    && $full['draft_id'] === $t2['draft_id'] && $full['total'] === $t2['total']);
$check('with_full_receiver_other_sub_unchanged', $tService->withFullReceiver($t2, 'someone-else') === $t2);
$check('with_full_receiver_unknown_draft_unchanged', $tService->withFullReceiver(['draft_id' => 'nope', 'receiver' => ['name' => 'x']], 'customer-1') === ['draft_id' => 'nope', 'receiver' => ['name' => 'x']]);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
