<?php
declare(strict_types=1);

interface ChatDraftStore
{
    public function insert(array $d): void;
    public function find(string $id): ?array;
    public function countOpen(string $sub, int $now): int;
    public function markUsed(string $id, int $now): bool;
    public function release(string $id): void;
}

final class ChatDraftException extends DomainException
{
    public function __construct(string $message, int $httpCode, private readonly string $errorCode)
    {
        parent::__construct($message, $httpCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}

final class MongoChatDraftStore implements ChatDraftStore
{
    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];
    private $collection;
    private bool $indexEnsured = false;

    public function __construct($db)
    {
        $this->collection = $db->chat_order_drafts;
    }

    public function insert(array $d): void
    {
        $this->ensureIndex();
        $this->collection->insertOne($d);
    }

    public function find(string $id): ?array
    {
        $this->ensureIndex();
        $doc = $this->collection->findOne(['_id' => $id], ['typeMap' => self::TYPE_MAP]);
        return $doc === null ? null : (array)$doc;
    }

    public function countOpen(string $sub, int $now): int
    {
        $this->ensureIndex();
        return $this->collection->countDocuments([
            'sub' => $sub,
            'used_at' => null,
            'expires_at' => ['$gt' => $now],
        ]);
    }

    public function markUsed(string $id, int $now): bool
    {
        $this->ensureIndex();
        $result = $this->collection->findOneAndUpdate(
            ['_id' => $id, 'used_at' => null],
            ['$set' => ['used_at' => $now]],
            ['typeMap' => self::TYPE_MAP]
        );
        return $result !== null;
    }

    public function release(string $id): void
    {
        $this->ensureIndex();
        $this->collection->findOneAndUpdate(
            ['_id' => $id],
            ['$set' => ['used_at' => null]],
            ['typeMap' => self::TYPE_MAP]
        );
    }

    private function ensureIndex(): void
    {
        if ($this->indexEnsured) return;
        $this->indexEnsured = true;
        try {
            $this->collection->createIndex(['expires_at' => 1], ['expireAfterSeconds' => 0]);
        } catch (Throwable $e) {
            // Index creation is best-effort; the application still checks expiry.
        }
    }
}

final class ChatDraftService
{
    private ChatDraftStore $store;
    private CheckoutCore $core;
    /** @var callable(): int */
    private $clock;
    /** @var callable(string, int): array */
    private $voucherValidator;
    /** @var (callable(array): array)|null */
    private $conflictChecker;

    public function __construct(
        ChatDraftStore $store,
        CheckoutCore $core,
        callable $clock,
        ?callable $voucherValidator = null,
        ?callable $conflictChecker = null
    )
    {
        $this->store = $store;
        $this->core = $core;
        $this->clock = $clock;
        $this->conflictChecker = $conflictChecker;
        $coreForVoucher = $this->core;
        $this->voucherValidator = $voucherValidator ?? static function (string $code, int $subtotal) use ($coreForVoucher): array {
            if (method_exists($coreForVoucher, 'validateVoucher')) {
                return $coreForVoucher->validateVoucher($code, $subtotal);
            }
            return ['ok' => false, 'message' => 'Không thể xác thực mã giảm giá.'];
        };
    }

    public static function isQrTransferEnabled(): bool
    {
        return trim((string)(defined('BANK_TRANSFER_BANK_ID') ? BANK_TRANSFER_BANK_ID : '')) !== ''
            && trim((string)(defined('BANK_TRANSFER_ACCOUNT_NO') ? BANK_TRANSFER_ACCOUNT_NO : '')) !== ''
            && trim((string)(defined('BANK_TRANSFER_ACCOUNT_NAME') ? BANK_TRANSFER_ACCOUNT_NAME : '')) !== '';
    }

    public function upsert(array $claims, array $req, array $cartSnapshot): array
    {
        $now = (int)($this->clock)();
        $sub = trim((string)($claims['sub'] ?? ''));
        if ($sub === '') throw new DomainException('Invalid token', 401);

        $replaces = trim((string)($req['replaces'] ?? ''));
        $replacement = $replaces === '' ? null : $this->store->find($replaces);
        $replacingOpen = is_array($replacement)
            && (string)($replacement['sub'] ?? '') === $sub
            && empty($replacement['used_at'])
            && (int)($replacement['expires_at'] ?? 0) > $now;
        if ($this->store->countOpen($sub, $now) - ($replacingOpen ? 1 : 0) >= 3) {
            throw new ChatDraftException('Bạn có quá nhiều bản nháp đang mở.', 429, 'too_many_drafts');
        }

        $paymentMethod = strtolower(trim((string)($req['payment_method'] ?? 'cod')));
        $allowed = ['cod', 'vnpay'];
        if (self::isQrTransferEnabled()) $allowed[] = 'bank_transfer_qr';
        if (!in_array($paymentMethod, $allowed, true)) {
            throw new ChatDraftException('Phương thức thanh toán không hợp lệ.', 422, 'invalid_request');
        }

        $checkoutItems = $this->checkoutItems($req, $cartSnapshot);
        if ($checkoutItems === []) throw new ChatDraftException('Không có sản phẩm để tạo bản nháp.', 422, 'invalid_request');
        $preview = $this->core->preview($checkoutItems);
        if (!empty($preview['stock_errors'])) {
            $stockMessage = trim((string)($preview['stock_errors'][0] ?? ''));
            if ($stockMessage === '' || stripos($stockMessage, 'không đủ hàng') === false) {
                $stockMessage = 'Sản phẩm trong đơn hàng không đủ hàng.';
            }
            throw new ChatDraftException($stockMessage, 409, 'out_of_stock');
        }
        if (empty($preview['items'])) {
            throw new ChatDraftException('Không có sản phẩm để tạo bản nháp.', 422, 'invalid_request');
        }

        $receiver = $this->resolveReceiver($req);

        $subtotal = (int)($preview['subtotal'] ?? 0);
        $voucher = null;
        $warnings = [];
        if (!$this->isTrueFlag($req['remove_voucher'] ?? false)) {
            $voucherCode = trim((string)($req['voucher_code'] ?? ''));
            if ($voucherCode === '') {
                $voucher = $this->core->bestVoucher((string)($claims['customer_id'] ?? ''), $subtotal);
            } else {
                $validated = ($this->voucherValidator)($voucherCode, $subtotal);
                if (!empty($validated['ok'])) {
                    $voucher = $validated;
                } else {
                    $message = trim((string)($validated['message'] ?? 'Mã giảm giá không áp dụng được.'));
                    $warnings[] = 'Mã ' . $voucherCode . ' không áp dụng được: ' . $message;
                    $voucher = $this->core->bestVoucher((string)($claims['customer_id'] ?? ''), $subtotal);
                }
            }
        }
        $discount = max(0, (int)($voucher['discount'] ?? 0));
        $shipping = 30000;
        $displayItems = [];
        $storedItems = [];
        foreach ($preview['items'] as $item) {
            $id = (string)($item['id'] ?? '');
            $qty = max(1, (int)($item['qty'] ?? $checkoutItems[$id] ?? 1));
            $displayItems[] = [
                'id' => $id,
                'name' => (string)($item['product']['ten_san_pham'] ?? ($item['product']['name'] ?? '')),
                'image' => (string)($item['product']['hinh_anh'] ?? ($item['product']['image'] ?? '')),
                'qty' => $qty,
                'unit_price' => (int)($item['unit_price'] ?? 0),
                'line_total' => (int)($item['line_total'] ?? 0),
            ];
            $storedItems[] = ['id' => $id, 'qty' => $qty];
        }

        $warnings = array_merge(
            $warnings,
            $this->conflictWarnings(array_column($storedItems, 'id'), $req['conflict_warnings'] ?? null)
        );

        if ($replacingOpen) $this->store->markUsed($replaces, $now);

        $draftId = bin2hex(random_bytes(16));
        $expiresAt = $now + 600;
        $this->store->insert([
            '_id' => $draftId,
            'sub' => $sub,
            'em' => (string)($claims['em'] ?? ''),
            'items' => $storedItems,
            'receiver' => $receiver,
            'payment_method' => $paymentMethod,
            'voucher_code' => $voucher === null ? null : (string)($voucher['voucher']['ma_code'] ?? ($voucher['code'] ?? '')),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping' => $shipping,
            'total' => max(0, $subtotal - $discount) + $shipping,
            'created_at' => $now,
            'expires_at' => $expiresAt,
            'used_at' => null,
            '_display_items' => $displayItems,
            '_warnings' => $warnings,
        ]);

        return [
            'draft_id' => $draftId,
            'expires_at' => $expiresAt,
            'items' => $displayItems,
            'subtotal' => $subtotal,
            'voucher' => $voucher === null ? null : [
                'code' => (string)($voucher['voucher']['ma_code'] ?? ($voucher['code'] ?? '')),
                'discount' => $discount,
            ],
            'shipping' => $shipping,
            'total' => max(0, $subtotal - $discount) + $shipping,
            'receiver' => $this->maskReceiver($receiver),
            'payment_method' => $paymentMethod,
            'warnings' => $warnings,
        ];
    }

    public function get(string $id, string $sub): ?array
    {
        $doc = $this->store->find($id);
        $now = (int)($this->clock)();
        if ($doc === null || (string)($doc['sub'] ?? '') !== $sub || !empty($doc['used_at'])
            || (int)($doc['expires_at'] ?? 0) <= $now) return null;

        $discount = (int)($doc['discount'] ?? 0);
        $voucherCode = trim((string)($doc['voucher_code'] ?? ''));
        return [
            'draft_id' => (string)$doc['_id'],
            'expires_at' => (int)$doc['expires_at'],
            'items' => $doc['_display_items'] ?? $doc['items'] ?? [],
            'subtotal' => (int)($doc['subtotal'] ?? 0),
            'voucher' => $voucherCode === '' ? null : ['code' => $voucherCode, 'discount' => $discount],
            'shipping' => (int)($doc['shipping'] ?? 30000),
            'total' => (int)($doc['total'] ?? 0),
            'receiver' => $this->maskReceiver((array)($doc['receiver'] ?? [])),
            'payment_method' => (string)($doc['payment_method'] ?? 'cod'),
            'warnings' => array_values(array_filter((array)($doc['_warnings'] ?? []), 'is_string')),
        ];
    }

    /**
     * Conflict advice for the card: the optional server-side checker first, then the (sanitised)
     * advice strings the agent passed in. Advisory only, so any failure yields no warning.
     *
     * @param list<string> $productIds
     * @return list<string>
     */
    private function conflictWarnings(array $productIds, mixed $agentWarnings): array
    {
        $warnings = [];
        if ($this->conflictChecker !== null && count($productIds) >= 2) {
            try {
                $found = ($this->conflictChecker)(array_values(array_map('strval', $productIds)));
                $warnings = array_merge($warnings, $this->cleanWarnings($found));
            } catch (Throwable $e) {
                // Advisory only; never block draft creation.
            }
        }
        $warnings = array_merge($warnings, $this->cleanWarnings($agentWarnings));
        return array_values(array_unique($warnings));
    }

    /** @return list<string> at most 3 non-empty single-line strings of at most 200 characters */
    private function cleanWarnings(mixed $values): array
    {
        if (!is_array($values)) return [];
        $clean = [];
        foreach ($values as $value) {
            if (!is_string($value)) continue;
            $text = trim((string)preg_replace('/[\s\p{C}]+/u', ' ', $value));
            if ($text === '') continue;
            if ($this->stringLength($text) > 200) {
                $text = function_exists('mb_substr') ? mb_substr($text, 0, 200) : substr($text, 0, 200);
            }
            $clean[] = $text;
            if (count($clean) >= 3) break;
        }
        return $clean;
    }

    private function checkoutItems(array $req, array $snapshot): array
    {
        $snapshotItems = is_array($snapshot['items'] ?? null) ? $snapshot['items'] : $snapshot;
        $available = [];
        foreach ($snapshotItems as $key => $item) {
            if (is_array($item)) {
                $id = trim((string)($item['id'] ?? $item['product_id'] ?? $key));
                if ($id !== '') $available[$id] = $item;
            } elseif (is_scalar($item)) {
                $available[(string)$key] = ['id' => (string)$key];
            }
        }
        $scope = (string)($req['scope'] ?? 'mentioned_items');
        $ids = $scope === 'whole_cart' ? array_keys($available) : (array)($req['product_ids'] ?? []);
        $qtyMap = is_array($req['qty'] ?? null) ? $req['qty'] : [];
        $items = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if ($id === '') continue;
            $snapshotQty = isset($available[$id]) ? ($available[$id]['qty'] ?? 1) : 1;
            $qty = (int)($qtyMap[$id] ?? $snapshotQty);
            $items[$id] = max(1, min(20, $qty));
        }
        return $items;
    }

    private function resolveReceiver(array $req): array
    {
        $choice = $req['address_choice'] ?? null;
        if ($choice === null || (is_string($choice) && trim($choice) === '')) {
            throw new ChatDraftException('Thiếu thông tin người nhận.', 422, 'receiver_missing');
        }
        if (!is_string($choice) || !in_array($choice, ['default', 'new'], true)) {
            throw new ChatDraftException('Loại địa chỉ người nhận không hợp lệ.', 422, 'receiver_invalid');
        }

        if ($choice === 'default') {
            if (!is_array($req['_server_default_receiver'] ?? null)) {
                throw new ChatDraftException('Thiếu thông tin người nhận.', 422, 'receiver_missing');
            }
            return $this->validateReceiver($req['_server_default_receiver']);
        }

        if (!is_array($req['new_address'] ?? null)) {
            throw new ChatDraftException('Thiếu thông tin người nhận.', 422, 'receiver_missing');
        }
        return $this->validateReceiver($req['new_address']);
    }

    private function validateReceiver(array $receiver): array
    {
        $name = trim((string)($receiver['ten_nguoi_nhan'] ?? ''));
        $phone = trim((string)($receiver['sdt_nguoi_nhan'] ?? ''));
        $address = trim((string)($receiver['dia_chi_giao_hang'] ?? ''));
        if ($name === '' || $phone === '' || $address === '') {
            throw new ChatDraftException('Thiếu thông tin người nhận.', 422, 'receiver_missing');
        }
        if ($this->stringLength($name) < 1 || $this->stringLength($name) > 100
            || $this->stringLength($address) < 5 || $this->stringLength($address) > 255) {
            throw new ChatDraftException('Thông tin người nhận không hợp lệ.', 422, 'receiver_invalid');
        }

        if (str_starts_with($phone, '+84')) {
            $phone = '0' . substr($phone, 3);
        }
        $phone = str_replace([' ', '.'], '', $phone);
        if (preg_match('/\A\d{9,11}\z/', $phone) !== 1) {
            throw new ChatDraftException('Thông tin người nhận không hợp lệ.', 422, 'receiver_invalid');
        }

        return [
            'ten_nguoi_nhan' => $name,
            'sdt_nguoi_nhan' => $phone,
            'dia_chi_giao_hang' => $address,
        ];
    }

    private function isTrueFlag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1'
            || $value === 'true';
    }

    private function stringLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    public static function maskReceiver(array $receiver): array
    {
        $digits = preg_replace('/[^0-9]/', '', (string)($receiver['sdt_nguoi_nhan'] ?? '')) ?? '';
        $phone = strlen($digits) >= 5 ? substr($digits, 0, 2) . 'xx xxx ' . substr($digits, -3) : '***';
        $address = trim((string)($receiver['dia_chi_giao_hang'] ?? ''));
        $comma = strrpos($address, ',');
        return [
            'name' => trim((string)($receiver['ten_nguoi_nhan'] ?? '')),
            'phone_masked' => $phone,
            'address_short' => trim($comma === false ? $address : substr($address, $comma + 1)),
        ];
    }
}
