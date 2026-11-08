<?php
declare(strict_types=1);

require_once __DIR__ . '/ChatDraftService.php';
require_once __DIR__ . '/ChatAgentIdentity.php';
require_once __DIR__ . '/CheckoutCore.php';
require_once __DIR__ . '/ProductEventTracker.php';
require_once __DIR__ . '/VnpayService.php';

final class ChatOrderConfirmService
{
    /** @var callable(array): int */
    private $createOrder;
    /** @var callable(): int */
    private $clock;
    /** @var callable(int): ?array|null */
    private $getOrder;

    public function __construct(
        private readonly ChatDraftService $drafts,
        private readonly ChatDraftStore $store,
        private readonly CheckoutCore $core,
        callable $createOrder,
        callable $clock,
        ?callable $getOrder = null
    ) {
        $this->createOrder = $createOrder;
        $this->clock = $clock;
        $this->getOrder = $getOrder;
    }

    public function confirm(string $draftId, array $user, array &$session): array
    {
        $now = (int)($this->clock)();
        $sub = trim((string)($user['sub'] ?? ChatAgentIdentity::subFor($user)));
        $draft = $this->store->find($draftId);
        if ($draft === null || (string)($draft['sub'] ?? '') !== $sub) {
            throw new DomainException('Không tìm thấy bản nháp đơn hàng.', 404);
        }
        if ((int)($draft['expires_at'] ?? 0) <= $now) {
            throw new DomainException('Bản nháp đơn hàng đã hết hạn.', 410);
        }
        if (!empty($draft['used_at'])) {
            throw new DomainException('Bản nháp đơn hàng đã được sử dụng.', 409);
        }

        $draftEmail = trim((string)($draft['em'] ?? ''));
        $userEmail = trim((string)($user['email'] ?? ''));
        if ($draftEmail === '' || $draftEmail !== $userEmail) {
            throw new DomainException('Không tìm thấy bản nháp đơn hàng.', 404);
        }

        $checkoutItems = $this->checkoutItems($draft['items'] ?? []);
        $preview = $this->core->preview($checkoutItems);
        $voucher = null;
        $discount = 0;
        $voucherCode = trim((string)($draft['voucher_code'] ?? ''));
        $voucherValid = $voucherCode === '';
        if ($voucherCode !== '') {
            $validated = method_exists($this->core, 'validateVoucher')
                ? $this->core->validateVoucher($voucherCode, (int)($preview['subtotal'] ?? 0))
                : ['ok' => false];
            if (!empty($validated['ok'])) {
                $voucherValid = true;
                $voucher = $validated;
                $discount = max(0, (int)($validated['discount'] ?? 0));
            }
        }

        $currentItems = $this->previewItems($preview);
        $same = empty($preview['stock_errors'])
            && $currentItems !== []
            && $currentItems === $checkoutItems
            && $voucherValid
            && (int)($draft['subtotal'] ?? 0) === (int)($preview['subtotal'] ?? 0)
            && (int)($draft['discount'] ?? 0) === $discount;
        if (!$same) {
            return $this->replaceChangedDraft($draft, $user, $checkoutItems, $voucherCode, $now);
        }

        if (!$this->store->markUsed($draftId, $now)) {
            throw new DomainException('Bản nháp đơn hàng đã được sử dụng.', 409);
        }

        $receiver = $this->normalizeValue($draft['receiver'] ?? []);
        $receiver = is_array($receiver) ? $receiver : [];
        $payload = [
            'analytics_sid' => ProductEventTracker::sessionHash(),
            'email' => $draftEmail,
            'ho_ten_mac_dinh' => (string)($user['ho_ten'] ?? ''),
            'ten_nguoi_nhan' => (string)($receiver['ten_nguoi_nhan'] ?? ''),
            'sdt_nguoi_nhan' => (string)($receiver['sdt_nguoi_nhan'] ?? ''),
            'dia_chi_giao_hang' => (string)($receiver['dia_chi_giao_hang'] ?? ''),
            'hinh_thuc_thanh_toan' => (string)($draft['payment_method'] ?? 'cod'),
            'phi_van_chuyen' => 30000,
            'tam_tinh' => (int)($preview['subtotal'] ?? 0),
            'so_tien_giam' => $discount,
            'diem_su_dung' => 0,
            'tien_giam_diem' => 0,
            'ma_voucher' => is_array($voucher['voucher'] ?? null) ? ($voucher['voucher']['ma_voucher'] ?? null) : null,
            'ma_giam_gia' => $voucherCode,
            'checkout_items' => $checkoutItems,
        ];

        try {
            $orderId = (int)($this->createOrder)($payload);
        } catch (Throwable $e) {
            $this->store->release($draftId);
            throw $e;
        }

        foreach ($checkoutItems as $productId => $_qty) {
            unset($session['gio_hang'][$productId]);
        }

        return [
            'status' => 'created',
            'order_id' => $orderId,
            'payment_method' => (string)($draft['payment_method'] ?? 'cod'),
            'next' => $this->nextStep((string)($draft['payment_method'] ?? 'cod'), $orderId, $payload),
        ];
    }

    public function paymentMethod(string $draftId): string
    {
        $draft = $this->store->find($draftId);
        return is_array($draft) ? (string)($draft['payment_method'] ?? '') : '';
    }

    private function replaceChangedDraft(array $draft, array $user, array $checkoutItems, string $voucherCode, int $now): array
    {
        $oldDraftId = (string)($draft['_id'] ?? '');
        if ($oldDraftId === '' || !$this->store->markUsed($oldDraftId, $now)) {
            throw new DomainException('Bản nháp đơn hàng đã được sử dụng.', 409);
        }

        $claims = [
            'sub' => (string)($user['sub'] ?? ChatAgentIdentity::subFor($user)),
            'em' => (string)($draft['em'] ?? ''),
            'customer_id' => (string)($user['customer_id'] ?? ''),
        ];
        $request = [
            'scope' => 'mentioned_items',
            'product_ids' => array_keys($checkoutItems),
            'qty' => $checkoutItems,
            'address_choice' => 'new',
            'new_address' => (array)($draft['receiver'] ?? []),
            'payment_method' => (string)($draft['payment_method'] ?? 'cod'),
        ];
        if ($voucherCode !== '') {
            $request['voucher_code'] = $voucherCode;
        } else {
            $request['remove_voucher'] = true;
        }
        $snapshot = array_fill_keys(array_keys($checkoutItems), []);
        try {
            $card = $this->drafts->upsert($claims, $request, ['items' => $snapshot]);
            return ['status' => 'changed', 'card' => $card];
        } catch (Throwable $e) {
            $this->store->release($oldDraftId);
            throw $this->mapChangedDraftError($e);
        }
    }

    private function checkoutItems($rawItems): array
    {
        $rawItems = $this->normalizeValue($rawItems);
        if (!is_array($rawItems)) return [];
        $items = [];
        foreach ($rawItems as $key => $raw) {
            if (is_array($raw)) {
                $id = trim((string)($raw['id'] ?? $key));
                $qty = (int)($raw['qty'] ?? 0);
            } else {
                $id = trim((string)$key);
                $qty = (int)$raw;
            }
            if ($id !== '' && $qty > 0) $items[$id] = min(20, $qty);
        }
        ksort($items);
        return $items;
    }

    private function previewItems(array $preview): array
    {
        $items = [];
        $previewItems = $this->normalizeValue($preview['items'] ?? []);
        foreach (is_array($previewItems) ? $previewItems : [] as $item) {
            $item = $this->normalizeValue($item);
            if (!is_array($item)) continue;
            $id = trim((string)($item['id'] ?? ''));
            if ($id !== '') $items[$id] = max(1, (int)($item['qty'] ?? 1));
        }
        ksort($items);
        return $items;
    }

    private function nextStep(string $paymentMethod, int $orderId, array $payload): array
    {
        $viewUrl = (defined('BASE_URL') ? BASE_URL : '.') . '/index.php?r=camon&ma_hoa_don=' . urlencode((string)$orderId);
        if ($paymentMethod !== 'vnpay') {
            return ['type' => $paymentMethod === 'bank_transfer_qr' ? 'qr' : 'view_order', 'url' => $viewUrl];
        }

        $vnpay = new VnpayService();
        if (!$vnpay->isConfigured()) return ['type' => 'view_order', 'url' => $viewUrl];
        try {
            $order = $this->getOrder === null ? null : ($this->getOrder)($orderId);
            $amount = is_array($order)
                ? (int)($order['tong_tien'] ?? 0)
                : max(0, (int)$payload['tam_tinh'] - (int)$payload['so_tien_giam']) + 30000;
            return [
                'type' => 'vnpay',
                'url' => $vnpay->createPaymentUrl(
                    (string)$orderId,
                    $amount,
                    'Thanh toan don hang #' . $orderId,
                    (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')
                ),
            ];
        } catch (Throwable $e) {
            return ['type' => 'view_order', 'url' => $viewUrl];
        }
    }

    private function mapChangedDraftError(Throwable $error): DomainException
    {
        if ($error instanceof ChatDraftException) {
            $code = $error->getErrorCode();
            if ($code === 'out_of_stock') {
                $message = trim($error->getMessage());
                if ($message === '' || stripos($message, 'không đủ hàng') === false) {
                    $message = 'Sản phẩm trong đơn hàng không đủ hàng.';
                }
                return new ChatDraftException($message, 409, 'out_of_stock');
            }
            if (str_starts_with($code, 'receiver_')) {
                return new ChatDraftException($error->getMessage(), 422, $code);
            }
        }

        return new DomainException('Không thể tạo bản nháp mới lúc này. Vui lòng thử lại.', 500);
    }

    private function normalizeValue($value)
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $child) {
                $normalized[$key] = $this->normalizeValue($child);
            }
            return $normalized;
        }
        if ($value instanceof \ArrayObject) {
            return $this->normalizeValue($value->getArrayCopy());
        }
        if ($value instanceof \Traversable) {
            $normalized = [];
            foreach ($value as $key => $child) {
                $normalized[$key] = $this->normalizeValue($child);
            }
            return $normalized;
        }
        if ($value instanceof \JsonSerializable) {
            return $this->normalizeValue($value->jsonSerialize());
        }
        if (is_object($value) && method_exists($value, 'getArrayCopy')) {
            return $this->normalizeValue($value->getArrayCopy());
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            return $this->normalizeValue($value->toArray());
        }
        return is_object($value) ? $this->normalizeValue(get_object_vars($value)) : $value;
    }
}
