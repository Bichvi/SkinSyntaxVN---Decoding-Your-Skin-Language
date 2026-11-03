<?php
declare(strict_types=1);

final class ChatAgentInternalController
{
    private $db;
    private ?CheckoutCore $checkoutCoreOverride;
    private ?ChatAgentAudit $auditOverride;
    private $draftServiceOverride;
    private $customerResolver;
    private $orderResolver;
    private string $activeRoute = '';
    private bool $routeAuditRecorded = false;

    public function __construct(
        private readonly string $internalSecret,
        private readonly string $tokenSecret,
        $db = null,
        ?CheckoutCore $checkoutCore = null,
        ?ChatAgentAudit $audit = null,
        $draftService = null,
        ?callable $customerResolver = null,
        ?callable $orderResolver = null
    ) {
        $this->db = $db;
        $this->checkoutCoreOverride = $checkoutCore;
        $this->auditOverride = $audit;
        $this->draftServiceOverride = $draftService;
        $this->customerResolver = $customerResolver;
        $this->orderResolver = $orderResolver;
    }

    public static function authenticate(array $server, string $internalSecret, string $tokenSecret, ?int $now = null): array
    {
        if ($internalSecret === '') {
            throw new DomainException('Internal authentication unavailable', 401);
        }
        $presentedInternal = (string)($server['HTTP_X_INTERNAL_TOKEN'] ?? '');
        if ($presentedInternal === '' || !hash_equals($internalSecret, $presentedInternal)) {
            throw new DomainException('Invalid internal token', 401);
        }
        $authorization = (string)($server['HTTP_AUTHORIZATION'] ?? '');
        if ($authorization === '') {
            $authorization = (string)($server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }
        if ($authorization !== '') {
            if (preg_match('/\ABearer\s+([^\s]+)\z/i', $authorization, $matches) !== 1) {
                throw new DomainException('Invalid authorization header', 401);
            }
            return ChatAgentToken::verify($matches[1], $tokenSecret, $now);
        }

        $agentToken = (string)($server['HTTP_X_AGENT_TOKEN'] ?? '');
        if ($agentToken === '') {
            throw new DomainException('Invalid authorization header', 401);
        }
        return ChatAgentToken::verify($agentToken, $tokenSecret, $now);
    }

    public static function listenerAllows(string $route, array $server, bool $requireListener): bool
    {
        $isInternalRoute = str_starts_with($route, 'internal_agent_');
        $isListenerRequest = ($server['CHAT_AGENT_INTERNAL_LISTENER'] ?? null) === '1';
        if ($isInternalRoute) {
            return !$requireListener || $isListenerRequest;
        }
        return !$isListenerRequest;
    }

    public function handle(string $route): void
    {
        $this->activeRoute = $route;
        $this->routeAuditRecorded = false;
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->auditIfNeeded($route, '', 'error');
            $this->respond(['error' => 'Method not allowed'], 405);
            return;
        }
        if (!in_array($route, [
            'internal_agent_ping', 'internal_agent_cart_add', 'internal_agent_cart_remove',
            'internal_agent_checkout_defaults', 'internal_agent_draft', 'internal_agent_reorder',
            'internal_agent_orders',
        ], true)) {
            $this->respond(['error' => 'Not found'], 404);
            return;
        }
        try {
            $claims = self::authenticate($_SERVER, $this->internalSecret, $this->tokenSecret);
            $sub = $claims['sub'] ?? null;
            if (!is_string($sub) || $sub === '') {
                throw new DomainException('Invalid token', 401);
            }
            if ($route === 'internal_agent_ping') {
                $this->respond(['ok' => true, 'sub' => $sub], 200);
                return;
            }

            $requiredScope = $route === 'internal_agent_checkout_defaults' ? 'draft:create' : 'cart:write';
            if (in_array($route, ['internal_agent_draft', 'internal_agent_reorder'], true)) {
                $requiredScope = 'draft:create';
            }
            if ($route === 'internal_agent_orders') {
                $requiredScope = 'order:read';
            }
            if (!ChatAgentToken::hasScope($claims, $requiredScope)) {
                $body = $this->requestBody();
                $params = $route === 'internal_agent_orders'
                    ? ['order_code' => substr(trim((string)($body['order_code'] ?? '')), 0, 64)]
                    : $this->auditCartParams($body);
                $this->audit($sub, $route, $params, 'error');
                $this->respond(['error' => 'Forbidden'], 403);
                return;
            }

            if ($route === 'internal_agent_checkout_defaults') {
                $this->handleCheckoutDefaults($claims, $sub);
                return;
            }
            if ($route === 'internal_agent_draft') {
                $this->handleDraft($claims, $sub);
                return;
            }
            if ($route === 'internal_agent_reorder') {
                $this->handleReorder($claims, $sub);
                return;
            }
            if ($route === 'internal_agent_orders') {
                $this->handleOrders($claims, $sub);
                return;
            }
            $this->handleCart($route, $sub);
        } catch (DomainException $e) {
            $this->auditIfNeeded($route, (string)($sub ?? ''), 'error');
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 401;
            if (method_exists($e, 'getErrorCode')) {
                $this->respond([
                    'ok' => false,
                    'code' => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ], $status);
            } else {
                $this->respond(['error' => $e->getMessage()], $status);
            }
        } catch (Throwable $e) {
            $this->auditIfNeeded($route, (string)($sub ?? ''), 'error');
            $this->respond(['error' => 'Internal server error'], 500);
        }
    }

    private function handleCart(string $route, string $sub): void
    {
        $body = $this->requestBody();
        $productId = $body['product_id'] ?? null;
        $qty = $route === 'internal_agent_cart_remove' ? ($body['qty'] ?? 1) : ($body['qty'] ?? null);
        $params = $this->auditCartParams($body);

        if (!is_string($productId) || $productId === '' || strlen($productId) > 64
            || !is_int($qty) || $qty < 1 || $qty > 20) {
            $this->audit($sub, $route, $params, 'error');
            $this->respond(['ok' => false, 'code' => 'invalid_request'], 422);
            return;
        }

        if ($route === 'internal_agent_cart_remove') {
            $effect = ChatCartEffect::sign([
                'op' => 'remove',
                'product_id' => $productId,
                'qty' => $qty,
                'sub' => $sub,
                'exp' => time() + 120,
                'nonce' => bin2hex(random_bytes(8)),
            ], $this->tokenSecret);
            $this->audit($sub, $route, $params, 'ok');
            $this->respond(['ok' => true, 'effect' => $effect], 200);
            return;
        }

        $preview = $this->checkoutCore()->preview([$productId => $qty]);
        if (!empty($preview['stock_errors'])) {
            $this->audit($sub, $route, $params, 'out_of_stock');
            $this->respond([
                'ok' => false,
                'code' => 'out_of_stock',
                'message' => (string)$preview['stock_errors'][0],
            ], 409);
            return;
        }
        if (empty($preview['items'])) {
            $this->audit($sub, $route, $params, 'not_found');
            $this->respond(['ok' => false, 'code' => 'not_found'], 404);
            return;
        }

        $item = $preview['items'][0];
        $effect = ChatCartEffect::sign([
            'op' => 'add',
            'product_id' => $productId,
            'qty' => $qty,
            'sub' => $sub,
            'exp' => time() + 120,
            'nonce' => bin2hex(random_bytes(8)),
        ], $this->tokenSecret);
        $this->audit($sub, $route, $params, 'ok');
        $this->respond([
            'ok' => true,
            'effect' => $effect,
            'line' => [
                'id' => (string)($item['id'] ?? $productId),
                'name' => (string)($item['product']['ten_san_pham'] ?? ($item['product']['name'] ?? '')),
                'unit_price' => (int)($item['unit_price'] ?? 0),
                'qty' => (int)($item['qty'] ?? $qty),
            ],
        ], 200);
    }

    private function handleCheckoutDefaults(array $claims, string $sub): void
    {
        require_once __DIR__ . '/../services/ChatDraftService.php';
        $email = trim((string)($claims['em'] ?? ''));
        $customer = $this->customerForEmail($email);
        $receiver = null;
        if ($customer !== null) {
            $receiver = ChatDraftService::maskReceiver($this->checkoutCore()->defaultReceiver(
                $customer,
                ['ho_ten' => (string)($claims['ho_ten'] ?? $claims['name'] ?? '')]
            ));
        }
        $methods = ['cod', 'vnpay'];
        if ($this->qrTransferEnabled()) $methods[] = 'bank_transfer_qr';
        $this->audit($sub, 'internal_agent_checkout_defaults', [], 'ok');
        $this->respond([
            'receiver' => $receiver,
            'last_payment_method' => $this->lastPaymentMethod($customer),
            'methods' => $methods,
        ], 200);
    }

    private function handleDraft(array $claims, string $sub): void
    {
        $body = $this->requestBody();
        if (isset($body['draft_id']) && !isset($body['scope'])) {
            $card = $this->draftService()->get((string)$body['draft_id'], $sub);
            $this->audit($sub, 'internal_agent_draft', ['draft_id' => substr((string)$body['draft_id'], 0, 64)], $card === null ? 'not_found' : 'ok');
            $this->respond($card === null ? ['error' => 'Not found'] : ['ok' => true, 'card' => $card], $card === null ? 404 : 200);
            return;
        }

        $customer = $this->customerForEmail(trim((string)($claims['em'] ?? '')));
        $claims = $this->claimsWithCustomerId($claims, $customer);
        unset($body['receiver'], $body['default_receiver'], $body['_server_default_receiver']);
        if ((string)($body['address_choice'] ?? 'default') === 'default') {
            $body['_server_default_receiver'] = $this->checkoutCore()->defaultReceiver(
                $customer,
                ['ho_ten' => (string)($claims['ho_ten'] ?? $claims['name'] ?? '')]
            );
        }
        $snapshot = $body['cart_snapshot'] ?? $body['cartSnapshot'] ?? [];
        try {
            $card = $this->draftService()->upsert($claims, $body, is_array($snapshot) ? $snapshot : []);
            $this->audit($sub, 'internal_agent_draft', ['draft_id' => $card['draft_id'] ?? ''], 'ok');
            $this->respond(['ok' => true, 'card' => $card], 200);
        } catch (DomainException $e) {
            $this->audit($sub, 'internal_agent_draft', [], 'error');
            throw $e;
        }
    }

    private function handleReorder(array $claims, string $sub): void
    {
        $body = $this->requestBody();
        $rawCode = trim((string)($body['order_code'] ?? ''));
        if (preg_match('/\A#?(?:DH)?(\d+)\z/i', $rawCode, $matches) !== 1) {
            $this->audit($sub, 'internal_agent_reorder', [], 'error');
            $this->respond(['error' => 'Invalid order code'], 422);
            return;
        }
        $email = trim((string)($claims['em'] ?? ''));
        if ($email === '') {
            $this->audit($sub, 'internal_agent_reorder', [], 'not_found');
            $this->respond(['error' => 'Order not found'], 404);
            return;
        }
        $orderId = (int)$matches[1];
        $order = $this->orderForId($orderId, $email);
        if ($order === null) {
            $this->audit($sub, 'internal_agent_reorder', ['order_id' => $orderId], 'not_found');
            $this->respond(['error' => 'Order not found'], 404);
            return;
        }
        $lines = $this->orderLines($orderId, $order);
        if ($lines === []) {
            $this->audit($sub, 'internal_agent_reorder', ['order_id' => $orderId], 'error');
            $this->respond(['error' => 'Order has no items'], 422);
            return;
        }
        $req = $body;
        $customer = $this->customerForEmail($email);
        $claims = $this->claimsWithCustomerId($claims, $customer);
        unset($req['receiver'], $req['default_receiver'], $req['_server_default_receiver']);
        $req['scope'] = 'mentioned_items';
        $req['product_ids'] = array_keys($lines);
        $req['qty'] = $lines;
        $req['address_choice'] = $req['address_choice'] ?? 'default';
        if ($req['address_choice'] === 'default') {
            $req['_server_default_receiver'] = $this->checkoutCore()->defaultReceiver(
                $customer,
                ['ho_ten' => (string)($claims['ho_ten'] ?? $claims['name'] ?? '')]
            );
        }
        $snapshot = $body['cart_snapshot'] ?? $body['cartSnapshot'] ?? array_fill_keys(array_keys($lines), []);
        $card = $this->draftService()->upsert($claims, $req, is_array($snapshot) ? $snapshot : []);
        $this->audit($sub, 'internal_agent_reorder', ['order_id' => $orderId], 'ok');
        $this->respond(['ok' => true, 'card' => $card], 200);
    }

    private function handleOrders(array $claims, string $sub): void
    {
        $body = $this->requestBody();
        $orderCode = substr(trim((string)($body['order_code'] ?? '')), 0, 64);
        $params = ['order_code' => $orderCode];
        $email = trim((string)($claims['em'] ?? ''));
        if ($email === '') {
            $this->audit($sub, 'internal_agent_orders', $params, 'not_found');
            $this->respond(['error' => 'Order not found'], 404);
            return;
        }

        try {
            $orderId = self::parseOrderCode($orderCode);
            if ($orderId !== null) {
                $order = $this->orderForId($orderId, $email);
                if ($order === null) {
                    $this->audit($sub, 'internal_agent_orders', $params, 'not_found');
                    $this->respond(['error' => 'Order not found'], 404);
                    return;
                }
                $this->audit($sub, 'internal_agent_orders', $params, 'ok');
                $this->respond(['orders' => [self::orderView($order)]], 200);
                return;
            }

            $customer = $this->customerForEmail($email);
            $customerId = $customer['ma_kh'] ?? null;
            if ((!is_int($customerId) && !is_string($customerId)) || trim((string)$customerId) === '') {
                $this->audit($sub, 'internal_agent_orders', $params, 'not_found');
                $this->respond(['orders' => []], 200);
                return;
            }
            $ids = [(string)$customerId];
            if (ctype_digit((string)$customerId)) $ids = [(int)$customerId, (string)$customerId];
            if ($this->db === null) throw new RuntimeException('Order database unavailable');
            $cursor = $this->db->hoa_don->find(
                ['ma_kh' => ['$in' => $ids]],
                ['sort' => ['ngay_dat' => -1], 'limit' => 3, 'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]
            );
            $orders = [];
            foreach ($cursor as $document) {
                if (count($orders) >= 3) break;
                $normalized = $this->toArrayRecursive($document);
                if (is_array($normalized)) $orders[] = self::orderView($normalized);
            }
            $this->audit($sub, 'internal_agent_orders', $params, 'ok');
            $this->respond(['orders' => $orders], 200);
        } catch (Throwable $e) {
            $this->audit($sub, 'internal_agent_orders', $params, 'error');
            $this->routeAuditRecorded = true;
            $this->respond(['error' => 'Internal server error'], 500);
        }
    }

    public static function parseOrderCode(string $value): ?int
    {
        $value = preg_replace('/\s+/', '', trim($value)) ?? '';
        if (preg_match('/\A#?(?:DH)?(\d+)\z/i', $value, $matches) !== 1) return null;
        $id = (int)$matches[1];
        return $id > 0 ? $id : null;
    }

    public static function orderView(array $order): array
    {
        require_once __DIR__ . '/../services/OrderTabs.php';
        $created = $order['created_at'] ?? ($order['ngay_dat'] ?? null);
        if ($created instanceof DateTimeInterface) {
            $createdAt = $created->format(DateTimeInterface::ATOM);
        } elseif (is_object($created) && method_exists($created, 'toDateTime')) {
            $createdAt = $created->toDateTime()->format(DateTimeInterface::ATOM);
        } else {
            try {
                $createdAt = (new DateTimeImmutable((string)$created))->format(DateTimeInterface::ATOM);
            } catch (Throwable $e) {
                $createdAt = (new DateTimeImmutable('@0'))->format(DateTimeInterface::ATOM);
            }
        }
        $orderId = (int)($order['ma_hoa_don'] ?? $order['order_id'] ?? 0);
        return [
            'order_id' => $orderId,
            'created_at' => $createdAt,
            'total' => (int)($order['tong_tien'] ?? $order['total'] ?? 0),
            'status' => OrderTabs::orderStatus($order),
            'payment_state' => OrderTabs::paymentState($order),
            'url' => BASE_URL . '/index.php?r=hoso',
        ];
    }

    private function requestBody(): array
    {
        if (is_array($_POST) && $_POST !== []) {
            return $_POST;
        }
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $body = json_decode($raw, true);
        return is_array($body) ? $body : [];
    }

    private function audit(string $actor, string $action, array $params, string $outcome): void
    {
        if (in_array($action, ['internal_agent_draft', 'internal_agent_reorder', 'internal_agent_orders'], true)) {
            $this->routeAuditRecorded = true;
        }
        ($this->auditOverride ?? $this->auditService())->record($actor, $action, $params, $outcome);
    }

    private function auditCartParams(array $body): array
    {
        $rawProductId = $body['product_id'] ?? null;
        $productId = is_scalar($rawProductId) ? substr((string)$rawProductId, 0, 64) : '';
        $rawQty = $body['qty'] ?? null;
        $qty = $rawQty === null ? null : (is_scalar($rawQty) ? (int)$rawQty : 0);
        return ['product_id' => $productId, 'qty' => $qty];
    }

    private function draftService(): ChatDraftService
    {
        if ($this->draftServiceOverride instanceof ChatDraftService) return $this->draftServiceOverride;
        if ($this->db === null) throw new RuntimeException('Draft database unavailable');
        require_once __DIR__ . '/../services/ChatDraftService.php';
        require_once __DIR__ . '/../models/Voucher.php';
        $voucherModel = new Voucher($this->db);
        return new ChatDraftService(
            new MongoChatDraftStore($this->db),
            $this->checkoutCore(),
            static fn(): int => time(),
            static fn(string $code, int $subtotal): array => $voucherModel->validateForCheckout($code, $subtotal)
        );
    }

    private function customerForEmail(string $email): ?array
    {
        if ($email === '') return null;
        if ($this->customerResolver !== null) {
            $customer = ($this->customerResolver)($email);
            return is_array($customer) ? $customer : null;
        }
        if ($this->db === null) return null;
        require_once __DIR__ . '/../models/TaiKhoan.php';
        $customer = (new TaiKhoan($this->db))->getKhachHangByEmail($email);
        return is_array($customer) ? $customer : null;
    }

    private function orderForId(int $id, string $email): ?array
    {
        if ($this->orderResolver !== null) {
            $order = ($this->orderResolver)($id, $email);
            $normalized = $this->toArrayRecursive($order);
            return is_array($normalized) ? $normalized : null;
        }
        if ($this->db === null) return null;
        require_once __DIR__ . '/../models/HoaDon.php';
        $order = (new HoaDon($this->db))->getOrderById($id, $email);
        $normalized = $this->toArrayRecursive($order);
        return is_array($normalized) ? $normalized : null;
    }

    private function orderLines(int $orderId, array $order): array
    {
        $order = $this->toArrayRecursive($order);
        $raw = $order['chi_tiet_hoa_don'] ?? $order['items'] ?? [];
        if ($raw === [] && $this->db !== null) {
            $raw = iterator_to_array($this->db->chi_tiet_hoa_don->find(['ma_hoa_don' => $orderId]));
        }
        $raw = $this->toArrayRecursive($raw);
        $lines = [];
        foreach ($raw as $line) {
            $line = $this->toArrayRecursive($line);
            if (!is_array($line)) continue;
            $id = trim((string)($line['ma_san_pham'] ?? $line['product_id'] ?? $line['id'] ?? ''));
            $qty = (int)($line['so_luong'] ?? $line['qty'] ?? 1);
            if ($id !== '') $lines[$id] = max(1, min(20, $qty));
        }
        return $lines;
    }

    private function lastPaymentMethod(?array $customer): string
    {
        $rawCustomerId = $customer['ma_kh'] ?? null;
        if ((!is_int($rawCustomerId) && !is_string($rawCustomerId)) || trim((string)$rawCustomerId) === '' || $this->db === null) {
            return 'cod';
        }
        $customerId = ctype_digit((string)$rawCustomerId) ? (int)$rawCustomerId : $rawCustomerId;
        if ((is_int($customerId) && $customerId <= 0) || (is_string($customerId) && $customerId === '')) return 'cod';
        try {
            $cursor = $this->db->hoa_don->find(['ma_kh' => $customerId], ['sort' => ['ma_hoa_don' => -1], 'limit' => 1]);
            $orders = iterator_to_array($cursor);
            return strtolower(trim((string)($orders[0]['hinh_thuc_thanh_toan'] ?? 'cod'))) ?: 'cod';
        } catch (Throwable $e) {
            return 'cod';
        }
    }

    private function qrTransferEnabled(): bool
    {
        require_once __DIR__ . '/../services/ChatDraftService.php';
        return ChatDraftService::isQrTransferEnabled();
    }

    private function claimsWithCustomerId(array $claims, ?array $customer): array
    {
        $claims['customer_id'] = (string)($customer['ma_kh'] ?? '');
        return $claims;
    }

    private function toArrayRecursive(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) $result[$key] = $this->toArrayRecursive($child);
            return $result;
        }
        if (is_object($value) && (is_a($value, 'MongoDB\\Model\\BSONDocument') || is_a($value, 'MongoDB\\Model\\BSONArray'))) {
            return $this->toArrayRecursive($value->getArrayCopy());
        }
        if ($value instanceof \ArrayObject) {
            return $this->toArrayRecursive($value->getArrayCopy());
        }
        if ($value instanceof \stdClass) {
            return $this->toArrayRecursive(get_object_vars($value));
        }
        if ($value instanceof \Traversable) {
            $result = [];
            foreach ($value as $key => $child) $result[$key] = $this->toArrayRecursive($child);
            return $result;
        }
        return $value;
    }

    private function auditIfNeeded(string $route, string $sub, string $outcome): void
    {
        if (!in_array($route, ['internal_agent_draft', 'internal_agent_reorder', 'internal_agent_orders'], true) || $this->routeAuditRecorded) {
            return;
        }
        $this->audit($sub, $route, [], $outcome);
    }

    private function auditService(): ChatAgentAudit
    {
        try {
            if ($this->db === null) {
                throw new RuntimeException('Audit database unavailable');
            }
            require_once __DIR__ . '/../services/ChatAgentAudit.php';
            return ChatAgentAudit::forDb($this->db);
        } catch (Throwable $e) {
            return new ChatAgentAudit(static function (array $document): void {
            });
        }
    }

    private function checkoutCore(): CheckoutCore
    {
        if ($this->checkoutCoreOverride !== null) {
            return $this->checkoutCoreOverride;
        }
        if ($this->db === null) {
            throw new RuntimeException('Checkout database unavailable');
        }
        require_once __DIR__ . '/../models/SanPham.php';
        require_once __DIR__ . '/../models/Voucher.php';
        require_once __DIR__ . '/../models/CustomerVoucher.php';
        require_once __DIR__ . '/../models/FlashSaleCampaign.php';
        require_once __DIR__ . '/../services/CheckoutCore.php';

        $db = $this->db;
        $productModel = new SanPham($db);
        $voucherModel = new Voucher($db);
        $customerVoucherModel = new CustomerVoucher($db);
        $effectivePrice = static fn(string $id, array $product) =>
            (new FlashSaleCampaign($db))->resolveEffectivePrice($id, null, $product);
        return new CheckoutCore($db, $productModel, $voucherModel, $customerVoucherModel, $effectivePrice);
    }

    private function respond(array $body, int $status): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
