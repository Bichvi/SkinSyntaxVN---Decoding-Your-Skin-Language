<?php
declare(strict_types=1);

/** Account-owned cart document shared by the PHP website and mobile API. */
final class CustomerCartStore
{
    private $db;
    private $carts;
    private $operations;

    public function __construct($db)
    {
        $this->db = $db;
        $this->carts = $db->customer_carts;
        $this->operations = $db->customer_cart_operations;
    }

    private function cartId(int $customerId): string
    {
        if ($customerId <= 0) {
            throw new InvalidArgumentException('Customer id is required.');
        }
        return 'customer-cart:' . $customerId;
    }

    /** @return array{_id:string,ma_kh:int,items:array,revision:int} */
    private function ensureCart(int $customerId): array
    {
        $id = $this->cartId($customerId);
        $existing = $this->carts->findOne(['_id' => $id]);
        if ($existing !== null) {
            return (array)$existing;
        }

        // Preserve any legacy account cart rows without modifying or deleting them.
        $quantities = [];
        foreach ($this->db->gio_hang->find(['ma_kh' => $customerId]) as $legacyRow) {
            $legacyRow = (array)$legacyRow;
            $productId = trim((string)($legacyRow['ma_san_pham'] ?? $legacyRow['product_id'] ?? ''));
            $quantity = max(0, (int)($legacyRow['so_luong'] ?? $legacyRow['quantity'] ?? 0));
            if ($productId !== '' && $quantity > 0) {
                $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
            }
        }

        $items = [];
        foreach ($quantities as $productId => $quantity) {
            $items[] = ['product_id' => (string)$productId, 'quantity' => (int)$quantity];
        }
        $document = [
            '_id' => $id,
            'ma_kh' => $customerId,
            'items' => $items,
            'revision' => 0,
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            'legacy_imported_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        try {
            $this->carts->insertOne($document);
            return $document;
        } catch (Throwable $error) {
            // Concurrent first requests can race on MongoDB's built-in _id index.
            $created = $this->carts->findOne(['_id' => $id]);
            if ($created !== null) {
                return (array)$created;
            }
            throw $error;
        }
    }

    public function get(int $customerId): array
    {
        $cart = $this->ensureCart($customerId);
        $items = [];
        foreach ((array)($cart['items'] ?? []) as $row) {
            $row = (array)$row;
            $productId = trim((string)($row['product_id'] ?? ''));
            $quantity = max(0, (int)($row['quantity'] ?? 0));
            if ($productId !== '' && $quantity > 0) {
                $items[$productId] = ($items[$productId] ?? 0) + $quantity;
            }
        }

        return [
            'customer_id' => $customerId,
            'items' => $items,
            'revision' => max(0, (int)($cart['revision'] ?? 0)),
        ];
    }

    /** @return array{ok:bool,code?:string,message?:string,quantity?:int} */
    public function increment(int $customerId, string $productId, int $quantity, int $stock): array
    {
        $productId = trim($productId);
        $quantity = max(1, $quantity);
        $stock = max(0, $stock);
        $id = $this->cartId($customerId);
        if ($productId === '' || strlen($productId) > 80) {
            return ['ok' => false, 'code' => 'invalid_product', 'message' => 'Mã sản phẩm không hợp lệ.'];
        }
        if ($stock <= 0 || $quantity > $stock) {
            return ['ok' => false, 'code' => 'out_of_stock', 'message' => 'Số lượng vượt quá tồn kho hiện có.'];
        }

        $this->ensureCart($customerId);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $result = $this->carts->updateOne(
                [
                    '_id' => $id,
                    'items' => ['$elemMatch' => [
                        'product_id' => $productId,
                        'quantity' => ['$lte' => $stock - $quantity],
                    ]],
                ],
                [
                    '$inc' => ['items.$.quantity' => $quantity, 'revision' => 1],
                    '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()],
                ]
            );
            if ($this->matchedCount($result) > 0) {
                $items = $this->get($customerId)['items'];
                return ['ok' => true, 'quantity' => (int)($items[$productId] ?? 0)];
            }

            $cart = $this->get($customerId);
            if (array_key_exists($productId, $cart['items'])) {
                return ['ok' => false, 'code' => 'out_of_stock', 'message' => 'Số lượng vượt quá tồn kho hiện có.'];
            }

            $result = $this->carts->updateOne(
                ['_id' => $id, 'items.product_id' => ['$ne' => $productId]],
                [
                    '$push' => ['items' => ['product_id' => $productId, 'quantity' => $quantity]],
                    '$inc' => ['revision' => 1],
                    '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()],
                ]
            );
            if ($this->matchedCount($result) > 0) {
                return ['ok' => true, 'quantity' => $quantity];
            }
        }

        return ['ok' => false, 'code' => 'cart_conflict', 'message' => 'Giỏ hàng vừa được cập nhật trên thiết bị khác. Vui lòng tải lại.'];
    }

    /** @return array{ok:bool,code?:string,message?:string,quantity?:int} */
    public function setQuantity(int $customerId, string $productId, int $quantity, int $stock): array
    {
        $productId = trim($productId);
        if ($quantity <= 0) {
            $this->remove($customerId, $productId);
            return ['ok' => true, 'quantity' => 0];
        }
        if ($productId === '' || strlen($productId) > 80) {
            return ['ok' => false, 'code' => 'invalid_product', 'message' => 'Mã sản phẩm không hợp lệ.'];
        }
        if ($stock <= 0 || $quantity > $stock) {
            return ['ok' => false, 'code' => 'out_of_stock', 'message' => 'Số lượng vượt quá tồn kho hiện có.'];
        }

        $id = $this->cartId($customerId);
        $this->ensureCart($customerId);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $result = $this->carts->updateOne(
                ['_id' => $id, 'items.product_id' => $productId],
                [
                    '$set' => [
                        'items.$.quantity' => $quantity,
                        'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                    ],
                    '$inc' => ['revision' => 1],
                ]
            );
            if ($this->matchedCount($result) > 0) {
                return ['ok' => true, 'quantity' => $quantity];
            }

            $result = $this->carts->updateOne(
                ['_id' => $id, 'items.product_id' => ['$ne' => $productId]],
                [
                    '$push' => ['items' => ['product_id' => $productId, 'quantity' => $quantity]],
                    '$inc' => ['revision' => 1],
                    '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()],
                ]
            );
            if ($this->matchedCount($result) > 0) {
                return ['ok' => true, 'quantity' => $quantity];
            }
        }

        return ['ok' => false, 'code' => 'cart_conflict', 'message' => 'Giỏ hàng vừa được cập nhật trên thiết bị khác. Vui lòng tải lại.'];
    }

    public function remove(int $customerId, string $productId): void
    {
        $productId = trim($productId);
        if ($productId === '') return;
        $this->ensureCart($customerId);
        $this->carts->updateOne(
            ['_id' => $this->cartId($customerId)],
            [
                '$pull' => ['items' => ['product_id' => $productId]],
                '$inc' => ['revision' => 1],
                '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()],
            ]
        );
    }

    public function clear(int $customerId): void
    {
        $this->ensureCart($customerId);
        $this->carts->updateOne(
            ['_id' => $this->cartId($customerId)],
            [
                '$set' => ['items' => [], 'updated_at' => new \MongoDB\BSON\UTCDateTime()],
                '$inc' => ['revision' => 1],
            ]
        );
    }

    /** Merge guest quantities into the account cart, keeping each item within current stock. */
    public function merge(int $customerId, array $guestQuantities, callable $stockForProduct): void
    {
        foreach ($guestQuantities as $productId => $rawQuantity) {
            $productId = trim((string)$productId);
            $guestQuantity = max(0, (int)$rawQuantity);
            if ($productId === '' || $guestQuantity <= 0) continue;

            $stock = max(0, (int)$stockForProduct($productId));
            if ($stock <= 0) continue;
            $current = (int)($this->get($customerId)['items'][$productId] ?? 0);
            $this->setQuantity($customerId, $productId, min($stock, $current + $guestQuantity), $stock);
        }
    }

    /** Reserve an operation id before changing the cart so retries cannot add twice. */
    public function addOnce(int $customerId, string $productId, int $quantity, int $stock, string $key): array
    {
        $key = trim($key);
        if (strlen($key) < 8 || strlen($key) > 120 || preg_match('/[^A-Za-z0-9._:-]/', $key)) {
            return ['ok' => false, 'code' => 'invalid_idempotency_key', 'message' => 'Yêu cầu cập nhật giỏ chưa hợp lệ.'];
        }

        $operationId = hash('sha256', $customerId . '|' . $key);
        try {
            $this->operations->insertOne([
                '_id' => $operationId,
                'customer_id' => $customerId,
                'status' => 'pending',
                'created_at' => new \MongoDB\BSON\UTCDateTime(),
                'expires_at' => new \MongoDB\BSON\UTCDateTime((time() + 86400) * 1000),
            ]);
        } catch (Throwable $error) {
            $existing = $this->operations->findOne(['_id' => $operationId]);
            if ($existing === null) throw $error;
            $existing = (array)$existing;
            if (($existing['status'] ?? '') === 'completed' && is_array($existing['outcome'] ?? null)) {
                return $existing['outcome'] + ['duplicate' => true];
            }
            return ['ok' => false, 'code' => 'request_in_progress', 'message' => 'Yêu cầu trước đang được xử lý. Vui lòng tải lại giỏ hàng.', 'duplicate' => true];
        }

        $outcome = $this->increment($customerId, $productId, $quantity, $stock);
        $this->operations->updateOne(
            ['_id' => $operationId],
            ['$set' => ['status' => 'completed', 'outcome' => $outcome, 'completed_at' => new \MongoDB\BSON\UTCDateTime()]]
        );
        return $outcome + ['duplicate' => false];
    }

    private function matchedCount($result): int
    {
        if (is_object($result) && method_exists($result, 'getMatchedCount')) {
            return (int)$result->getMatchedCount();
        }
        return (int)($result->matchedCount ?? 0);
    }
}
