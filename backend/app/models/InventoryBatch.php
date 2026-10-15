<?php

/**
 * Inventory batches are the source of truth for stock and expiry filters.
 * Product master data stays in san_pham; each row here represents one lot in
 * one warehouse. Stock changes are recorded as inventory movements.
 */
class InventoryBatch
{
    private $db;
    private ?string $lastErrorMessage = null;
    private const VALID_STATUSES = ['available', 'quarantined', 'expired', 'depleted'];
    private const VALID_MOVEMENTS = [
        'receipt', 'sale', 'reserve', 'release', 'return', 'damage',
        'adjustment_increase', 'adjustment_decrease',
    ];

    public function __construct($db)
    {
        $this->db = is_object($db) && method_exists($db, 'raw') ? $db->raw() : $db;
    }

    public function getLastErrorMessage(): ?string
    {
        return $this->lastErrorMessage;
    }

    public function ensureIndexes(): void
    {
        try {
            $this->db->inventory_batches->createIndex(
                ['product_id' => 1, 'warehouse_id' => 1, 'batch_code' => 1],
                ['unique' => true, 'name' => 'inventory_batch_identity']
            );
            $this->db->inventory_batches->createIndex(
                ['status' => 1, 'expires_on' => 1, 'available_quantity' => 1],
                ['name' => 'inventory_expiry_stock']
            );
            $this->db->inventory_batches->createIndex(
                ['warehouse_id' => 1, 'status' => 1, 'expires_on' => 1],
                ['name' => 'inventory_warehouse_expiry']
            );
            $this->db->inventory_movements->createIndex(
                ['batch_id' => 1, 'occurred_at' => -1],
                ['name' => 'inventory_movement_history']
            );
        } catch (Throwable $e) {
            // Index creation must not take down the Admin page. The error is
            // logged so deployment can repair duplicate legacy rows explicitly.
            error_log('inventory indexes: ' . $e->getMessage());
        }
    }

    public function listProductOptions(string $keyword = ''): array
    {
        $filter = [];
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $regex = new \MongoDB\BSON\Regex(preg_quote($keyword), 'i');
            $filter['$or'] = [
                ['ma_san_pham' => $regex],
                ['ten_san_pham' => $regex],
            ];
        }

        $items = [];
        foreach ($this->db->san_pham->find($filter, ['sort' => ['ma_san_pham' => 1], 'limit' => 200]) as $doc) {
            $items[] = [
                'product_id' => (string)($doc['ma_san_pham'] ?? $doc['id'] ?? $doc['_id'] ?? ''),
                'name' => (string)($doc['ten_san_pham'] ?? 'Sản phẩm chưa đặt tên'),
            ];
        }
        return array_values(array_filter($items, static fn(array $item): bool => $item['product_id'] !== ''));
    }

    public function listBatches(array $filters = []): array
    {
        $query = [];
        $keyword = trim((string)($filters['q'] ?? ''));
        if ($keyword !== '') {
            $regex = new \MongoDB\BSON\Regex(preg_quote($keyword), 'i');
            $query['$or'] = [
                ['product_id' => $regex],
                ['batch_code' => $regex],
                ['warehouse_id' => $regex],
            ];
        }
        $statusFilter = trim((string)($filters['status'] ?? ''));
        $warehouseFilter = trim((string)($filters['warehouse_id'] ?? ''));
        foreach (['product_id'] as $field) {
            $value = trim((string)($filters[$field] ?? ''));
            if ($value !== '') {
                $query[$field] = $value;
            }
        }
        $expiryConditions = [];
        $showExpired = trim((string)($filters['show_expired'] ?? '')) === '1'
            || $statusFilter === 'expired';
        $virtualExpiredWarehouse = $warehouseFilter === 'AUTO-EXPIRED';
        if (!$virtualExpiredWarehouse && $warehouseFilter !== '') {
            $query['warehouse_id'] = $warehouseFilter;
        }
        if ($virtualExpiredWarehouse) {
            $statusFilter = 'expired';
            $showExpired = true;
        }
        if ($statusFilter !== '' && $statusFilter !== 'expired') {
            $query['status'] = $statusFilter;
        }
        if (!$showExpired) {
            $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Ho_Chi_Minh'));
            $expiryConditions[] = ['expires_on' => ['$gte' => $this->parseDate($today->format('Y-m-d'))]];
            $expiryConditions[] = ['status' => ['$ne' => 'expired']];
        }
        if ($statusFilter === 'expired') {
            $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Ho_Chi_Minh'));
            $expiryConditions[] = ['$or' => [
                ['status' => 'expired'],
                ['expires_on' => ['$lt' => $this->parseDate($today->format('Y-m-d'))]],
            ]];
        }
        foreach ([['expires_from', '$gte'], ['expires_to', '$lte']] as [$input, $operator]) {
            $date = $this->parseDate((string)($filters[$input] ?? ''));
            if ($date !== null) {
                $expiryConditions[] = ['expires_on' => [$operator => $date]];
            }
        }
        if ($expiryConditions) {
            $query['$and'] = array_merge($query['$and'] ?? [], $expiryConditions);
        }

        $limit = min(500, max(1, (int)($filters['limit'] ?? 100)));
        $cursor = $this->db->inventory_batches->find($query, [
            'sort' => ['expires_on' => 1, 'updated_at' => -1],
            'limit' => $limit,
        ]);
        $rows = [];
        foreach ($cursor as $doc) {
            $rows[] = $this->normalizeBatch($doc);
        }
        // Một truy vấn $in cho toàn bộ sản phẩm của trang thay vì findOne cho từng sản phẩm.
        $productNames = $this->findProductNames(array_column($rows, 'product_id'));
        $items = [];
        foreach ($rows as $row) {
            $productId = $row['product_id'];
            $row['product_name'] = $productNames[$productId] ?? 'Không tìm thấy sản phẩm';
            $row['remaining_days'] = $this->remainingDays($row['expires_on']);
            $row['source_warehouse_id'] = $row['warehouse_id'];
            if ($row['remaining_days'] !== null && $row['remaining_days'] <= 0) {
                $row['warehouse_id'] = 'AUTO-EXPIRED';
            }
            $items[] = $row;
        }
        return $items;
    }

    /**
     * Build deterministic demo candidates for products that do not have a
     * batch ledger yet. Dates are intentionally labelled as generated data;
     * this helper never overwrites an existing batch or product master data.
     */
    public function previewAutoSeed(array $options = []): array
    {
        $warehouseId = trim((string)($options['warehouse_id'] ?? 'AUTO-DEFAULT')) ?: 'AUTO-DEFAULT';
        $shelfLifeMonths = min(60, max(1, (int)($options['shelf_life_months'] ?? 24)));
        $remainingMonths = min($shelfLifeMonths - 1, max(1, (int)($options['remaining_months'] ?? 18)));
        $limit = min(5000, max(1, (int)($options['limit'] ?? 500)));
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Ho_Chi_Minh'));
        $manufacturedOn = $today->modify('-' . ($shelfLifeMonths - $remainingMonths) . ' months');
        $expiresOn = $manufacturedOn->modify('+' . $shelfLifeMonths . ' months');

        $existing = [];
        foreach ($this->db->inventory_batches->find([], ['projection' => ['product_id' => 1, 'warehouse_id' => 1]]) as $batch) {
            $productId = (string)($batch['product_id'] ?? '');
            $warehouse = (string)($batch['warehouse_id'] ?? '');
            if ($productId !== '' && $warehouse === $warehouseId) {
                $existing[$productId] = true;
            }
        }

        $candidates = [];
        foreach ($this->db->san_pham->find([], [
            'projection' => [
                'ma_san_pham' => 1, 'id' => 1, 'ten_san_pham' => 1,
                'trang_thai' => 1, 'status' => 1,
                'so_luong_ton_kho' => 1, 'so_luong_ton' => 1,
                'ton_kho' => 1, 'stock' => 1, 'quantity' => 1,
            ],
            'sort' => ['ma_san_pham' => 1],
            'limit' => 20000,
        ]) as $product) {
            $productId = (string)($product['ma_san_pham'] ?? $product['id'] ?? '');
            if ($productId === '' || isset($existing[$productId])) {
                continue;
            }
            $status = strtolower(trim((string)($product['trang_thai'] ?? $product['status'] ?? 'active')));
            if (in_array($status, ['inactive', 'hidden', 'disabled', '0', 'tam_an', 'ngung ban'], true)) {
                continue;
            }

            $stock = $this->extractProductStock($product);
            $state = $stock === null ? 'needs_verification' : 'ready';
            $candidates[] = [
                'product_id' => $productId,
                'product_name' => (string)($product['ten_san_pham'] ?? 'Sản phẩm chưa đặt tên'),
                'batch_code' => 'AUTO-' . $this->safeBatchCode($productId) . '-' . $today->format('Ymd'),
                'warehouse_id' => $warehouseId,
                'manufactured_on' => $manufacturedOn->format('Y-m-d'),
                'expires_on' => $expiresOn->format('Y-m-d'),
                'quantity' => $stock === null ? null : max(0, $stock),
                'status' => $stock === 0 ? 'depleted' : 'available',
                'state' => $state,
                'reason' => $stock === null
                    ? 'Sản phẩm chưa có trường tồn kho rõ ràng; cần kiểm tra trước khi tạo lô.'
                    : 'Mốc demo 24 tháng: sản xuất cách hiện tại 6 tháng, còn khoảng 18 tháng.',
                'data_source' => 'auto_generated_demo',
                'verification_status' => 'unverified',
            ];
            if (count($candidates) >= $limit) {
                break;
            }
        }

        $ready = count(array_filter($candidates, static fn(array $item): bool => $item['state'] === 'ready'));
        $needsVerification = count($candidates) - $ready;
        return [
            'warehouse_id' => $warehouseId,
            'shelf_life_months' => $shelfLifeMonths,
            'remaining_months' => $remainingMonths,
            'candidates' => $candidates,
            'total' => count($candidates),
            'ready' => $ready,
            'needs_verification' => $needsVerification,
        ];
    }

    /**
     * Insert only ready auto-generated candidates. Existing products and
     * batches are left untouched; duplicate races are skipped safely.
     */
    public function autoSeedMissingBatches(array $options = [], array $actor = []): array
    {
        $preview = $this->previewAutoSeed($options);
        $created = 0;
        $skipped = 0;
        $errors = [];
        $now = new \MongoDB\BSON\UTCDateTime();
        $createdBy = (string)($actor['id'] ?? $actor['ma_nv'] ?? $actor['email'] ?? 'admin');

        foreach ($preview['candidates'] as $candidate) {
            if ($candidate['state'] !== 'ready') {
                $skipped++;
                continue;
            }
            $manufactured = $this->parseDate((string)$candidate['manufactured_on']);
            $expires = $this->parseDate((string)$candidate['expires_on']);
            $quantity = max(0, (int)$candidate['quantity']);
            $document = [
                'product_id' => $candidate['product_id'],
                'batch_code' => $candidate['batch_code'],
                'warehouse_id' => $candidate['warehouse_id'],
                'manufactured_on' => $manufactured,
                'expires_on' => $expires,
                'received_on' => $now,
                'initial_quantity' => $quantity,
                'available_quantity' => $quantity,
                'reserved_quantity' => 0,
                'sold_quantity' => 0,
                'damaged_quantity' => 0,
                'status' => $quantity > 0 ? 'available' : 'depleted',
                'data_source' => 'auto_generated_demo',
                'verification_status' => 'unverified',
                'needs_verification' => true,
                'source_note' => 'Tự sinh để demo bộ lọc kho; cần đối chiếu phiếu nhập/nhãn thật.',
                'created_by' => $createdBy,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            try {
                $result = $this->db->inventory_batches->insertOne($document);
                if ($quantity > 0) {
                    $this->db->inventory_movements->insertOne([
                        'batch_id' => $result->getInsertedId(),
                        'product_id' => $candidate['product_id'],
                        'warehouse_id' => $candidate['warehouse_id'],
                        'type' => 'receipt',
                        'quantity' => $quantity,
                        'quantity_before' => 0,
                        'quantity_after' => $quantity,
                        'note' => 'Nhập lô demo tự động — cần xác minh',
                        'created_by' => $createdBy,
                        'occurred_at' => $now,
                    ]);
                }
                $created++;
            } catch (Throwable $e) {
                $message = strtolower($e->getMessage());
                if (str_contains($message, 'duplicate')) {
                    $skipped++;
                    continue;
                }
                $errors[] = $candidate['product_id'] . ': Không thể tạo lô tự động.';
                error_log('inventory auto seed: ' . $e->getMessage());
            }
        }

        return [
            'preview' => $preview,
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Preview a diverse, deterministic set of demo lots. This is deliberately
     * separate from autoSeedMissingBatches(): the normal seed creates one
     * conservative lot per product, while this set creates several realistic
     * test scenarios for expiry, stock and status filters.
     */
    public function previewDiverseSeed(array $options = []): array
    {
        $warehouseId = trim((string)($options['warehouse_id'] ?? 'AUTO-SCENARIOS')) ?: 'AUTO-SCENARIOS';
        $expiredWarehouseId = 'AUTO-EXPIRED';
        $limit = min(3000, max(10, (int)($options['limit'] ?? 1000)));
        $profiles = $this->diverseDemoProfiles();
        $existing = [];
        foreach ($this->db->inventory_batches->find(
            ['$or' => [
                ['warehouse_id' => $warehouseId],
                ['warehouse_id' => $expiredWarehouseId],
            ]],
            ['projection' => ['batch_code' => 1, 'warehouse_id' => 1]]
        ) as $batch) {
            $code = (string)($batch['batch_code'] ?? '');
            if ($code !== '') {
                $existing[(string)($batch['warehouse_id'] ?? '') . '|' . $code] = true;
            }
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Ho_Chi_Minh'));
        $eligible = 0;
        $ready = 0;
        $byProfile = array_fill_keys(array_column($profiles, 'key'), 0);
        $seenProducts = [];
        foreach ($this->activeProductsForDemo() as $product) {
            $productId = (string)($product['product_id'] ?? '');
            if ($productId === '' || isset($seenProducts[$productId])) {
                continue;
            }
            $seenProducts[$productId] = true;
            $eligible++;
            $profile = $profiles[($eligible - 1) % count($profiles)];
            $profileWarehouseId = $profile['key'] === 'expired' ? $expiredWarehouseId : $warehouseId;
            $code = 'DEMO-' . $profile['key'] . '-' . $this->safeBatchCode($productId) . '-' . $today->format('Ymd');
            if (!isset($existing[$profileWarehouseId . '|' . $code]) && $ready < $limit) {
                $ready++;
                $byProfile[$profile['key']]++;
            }
        }

        return [
            'warehouse_id' => $warehouseId,
            'limit' => $limit,
            'eligible_products' => $eligible,
            'ready' => $ready,
            'profiles' => $profiles,
            'by_profile' => $byProfile,
        ];
    }

    /**
     * Create multiple deterministic demo scenarios without touching product
     * master data or existing lots. Re-running this method is idempotent by
     * warehouse + generated batch code.
     */
    public function seedDiverseDemoBatches(array $options = [], array $actor = []): array
    {
        $preview = $this->previewDiverseSeed($options);
        $warehouseId = $preview['warehouse_id'];
        $expiredWarehouseId = 'AUTO-EXPIRED';
        $limit = (int)$preview['limit'];
        $profiles = $preview['profiles'];
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Ho_Chi_Minh'));
        $now = new \MongoDB\BSON\UTCDateTime();
        $createdBy = (string)($actor['id'] ?? $actor['ma_nv'] ?? $actor['email'] ?? 'admin');
        $existing = [];
        foreach ($this->db->inventory_batches->find(
            ['$or' => [
                ['warehouse_id' => $warehouseId],
                ['warehouse_id' => $expiredWarehouseId],
            ]],
            ['projection' => ['batch_code' => 1, 'warehouse_id' => 1]]
        ) as $batch) {
            $code = (string)($batch['batch_code'] ?? '');
            if ($code !== '') {
                $existing[(string)($batch['warehouse_id'] ?? '') . '|' . $code] = true;
            }
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $byProfile = array_fill_keys(array_column($profiles, 'key'), 0);
        $seenProducts = [];
        foreach ($this->activeProductsForDemo() as $product) {
            if ($created >= $limit) {
                break;
            }
            $productId = (string)($product['product_id'] ?? '');
            if ($productId === '' || isset($seenProducts[$productId])) {
                continue;
            }
            $seenProducts[$productId] = true;
            $profile = $profiles[(count($seenProducts) - 1) % count($profiles)];
            $profileWarehouseId = $profile['key'] === 'expired' ? $expiredWarehouseId : $warehouseId;
            $batchCode = 'DEMO-' . $profile['key'] . '-' . $this->safeBatchCode($productId) . '-' . $today->format('Ymd');
            if (isset($existing[$profileWarehouseId . '|' . $batchCode])) {
                $skipped++;
                continue;
            }

            $manufacturedOn = $today->modify($profile['manufactured_offset']);
            $expiresOn = $today->modify($profile['expires_offset']);
            $initial = (int)$profile['initial_quantity'];
            $available = (int)$profile['available_quantity'];
            $sold = max(0, $initial - $available);
            $document = [
                'product_id' => $productId,
                'batch_code' => $batchCode,
                'warehouse_id' => $profileWarehouseId,
                'manufactured_on' => $this->parseDate($manufacturedOn->format('Y-m-d')),
                'expires_on' => $this->parseDate($expiresOn->format('Y-m-d')),
                'received_on' => $now,
                'initial_quantity' => $initial,
                'available_quantity' => $available,
                'reserved_quantity' => 0,
                'sold_quantity' => $sold,
                'damaged_quantity' => 0,
                'status' => $profile['status'],
                'scenario' => $profile['key'],
                'scenario_label' => $profile['label'],
                'data_source' => 'auto_generated_diverse_demo',
                'verification_status' => 'unverified',
                'needs_verification' => true,
                'source_note' => 'Dữ liệu đa dạng tự sinh để test bộ lọc kho/hạn dùng; không phải tồn kho thật.',
                'created_by' => $createdBy,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            try {
                $result = $this->db->inventory_batches->insertOne($document);
                if ($initial > 0) {
                    $this->db->inventory_movements->insertOne([
                        'batch_id' => $result->getInsertedId(),
                        'product_id' => $productId,
                        'warehouse_id' => $profileWarehouseId,
                        'type' => 'receipt',
                        'quantity' => $initial,
                        'quantity_before' => 0,
                        'quantity_after' => $initial,
                        'note' => 'Nhập lô demo đa dạng — cần xác minh',
                        'created_by' => $createdBy,
                        'occurred_at' => $now,
                    ]);
                }
                if ($sold > 0) {
                    $this->db->inventory_movements->insertOne([
                        'batch_id' => $result->getInsertedId(),
                        'product_id' => $productId,
                        'warehouse_id' => $profileWarehouseId,
                        'type' => 'sale',
                        'quantity' => $sold,
                        'quantity_before' => $initial,
                        'quantity_after' => $available,
                        'note' => 'Dữ liệu bán demo để test tồn kho',
                        'created_by' => $createdBy,
                        'occurred_at' => $now,
                    ]);
                }
                $existing[$profileWarehouseId . '|' . $batchCode] = true;
                $created++;
                $byProfile[$profile['key']]++;
            } catch (Throwable $e) {
                if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    $skipped++;
                    continue;
                }
                $errors[] = $productId . ': Không thể tạo kịch bản demo.';
                error_log('inventory diverse seed: ' . $e->getMessage());
            }
        }

        return [
            'preview' => $preview,
            'created' => $created,
            'skipped' => $skipped,
            'by_profile' => $byProfile,
            'errors' => $errors,
        ];
    }

    /**
     * Create ten deterministic demo lots with exact expiry dates requested by
     * the admin. They live in a dedicated demo warehouse and can be re-run
     * safely because the generated batch codes are stable.
     */
    public function seedFixedExpiryDemoBatches(array $options = [], array $actor = []): array
    {
        $warehouseId = trim((string)($options['warehouse_id'] ?? 'AUTO-FIXED-EXPIRY')) ?: 'AUTO-FIXED-EXPIRY';
        $expiryDates = [
            '2028-09-28', '2028-03-29', '2028-05-01', '2029-03-02',
            '2028-09-28', '2028-03-29', '2028-05-01', '2029-03-02',
            '2028-09-28', '2028-03-29',
        ];
        $now = new \MongoDB\BSON\UTCDateTime();
        $createdBy = (string)($actor['id'] ?? $actor['ma_nv'] ?? $actor['email'] ?? 'admin');
        $existing = [];
        foreach ($this->db->inventory_batches->find(
            ['warehouse_id' => $warehouseId],
            ['projection' => ['batch_code' => 1]]
        ) as $batch) {
            $code = (string)($batch['batch_code'] ?? '');
            if ($code !== '') {
                $existing[$code] = true;
            }
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $byExpiry = array_fill_keys(array_values(array_unique($expiryDates)), 0);
        $seenProducts = [];
        foreach ($this->activeProductsForDemo() as $product) {
            $slot = count($seenProducts);
            if ($slot >= count($expiryDates)) {
                break;
            }
            $productId = (string)($product['product_id'] ?? '');
            if ($productId === '' || isset($seenProducts[$productId])) {
                continue;
            }
            $seenProducts[$productId] = true;
            $expiryDate = $expiryDates[$slot];
            $expiresOn = new \DateTimeImmutable($expiryDate, new \DateTimeZone('Asia/Ho_Chi_Minh'));
            $manufacturedOn = $expiresOn->modify('-24 months');
            $batchCode = 'FIXED-EXPIRY-' . str_replace('-', '', $expiresOn->format('Ymd')) . '-' . $this->safeBatchCode($productId);
            if (isset($existing[$batchCode])) {
                $skipped++;
                $byExpiry[$expiryDate]++;
                continue;
            }

            $initial = 30 + ($slot % 4) * 10;
            $document = [
                'product_id' => $productId,
                'batch_code' => $batchCode,
                'warehouse_id' => $warehouseId,
                'manufactured_on' => $this->parseDate($manufacturedOn->format('Y-m-d')),
                'expires_on' => $this->parseDate($expiresOn->format('Y-m-d')),
                'received_on' => $now,
                'initial_quantity' => $initial,
                'available_quantity' => $initial,
                'reserved_quantity' => 0,
                'sold_quantity' => 0,
                'damaged_quantity' => 0,
                'status' => 'available',
                'scenario' => 'fixed-expiry-demo',
                'scenario_label' => '10 lô demo theo ngày HSD yêu cầu',
                'data_source' => 'auto_generated_fixed_expiry_demo',
                'verification_status' => 'unverified',
                'needs_verification' => true,
                'source_note' => 'Dữ liệu demo theo ngày HSD admin yêu cầu; không phải tồn kho thật.',
                'created_by' => $createdBy,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            try {
                $result = $this->db->inventory_batches->insertOne($document);
                $this->db->inventory_movements->insertOne([
                    'batch_id' => $result->getInsertedId(),
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'type' => 'receipt',
                    'quantity' => $initial,
                    'quantity_before' => 0,
                    'quantity_after' => $initial,
                    'note' => 'Nhập lô demo theo ngày HSD cố định — cần xác minh',
                    'created_by' => $createdBy,
                    'occurred_at' => $now,
                ]);
                $existing[$batchCode] = true;
                $created++;
                $byExpiry[$expiryDate]++;
            } catch (Throwable $e) {
                if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    $skipped++;
                    continue;
                }
                $errors[] = $productId . ': Không thể tạo lô theo ngày HSD.';
                error_log('inventory fixed expiry seed: ' . $e->getMessage());
            }
        }

        return [
            'warehouse_id' => $warehouseId,
            'requested' => count($expiryDates),
            'created' => $created,
            'skipped' => $skipped,
            'expiry_dates' => array_values(array_unique($expiryDates)),
            'by_expiry' => $byExpiry,
            'errors' => $errors,
        ];
    }

    public function createBatch(array $input, array $actor = []): ?array
    {
        $this->lastErrorMessage = null;
        $productId = trim((string)($input['product_id'] ?? ''));
        $batchCode = trim((string)($input['batch_code'] ?? ''));
        $warehouseId = trim((string)($input['warehouse_id'] ?? ''));
        $manufacturedOn = $this->parseDate((string)($input['manufactured_on'] ?? ''));
        $expiresOn = $this->parseDate((string)($input['expires_on'] ?? ''));
        $receivedInput = trim((string)($input['received_on'] ?? ''));
        $receivedOn = $receivedInput === '' ? null : $this->parseDate($receivedInput);
        $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($productId === '' || !$this->productExists($productId)) {
            return $this->fail('Sản phẩm không tồn tại hoặc chưa được chọn.');
        }
        if ($batchCode === '' || $warehouseId === '') {
            return $this->fail('Mã lô và mã kho là bắt buộc.');
        }
        if ($manufacturedOn === null || $expiresOn === null) {
            return $this->fail('Ngày sản xuất và hạn sử dụng phải đúng định dạng YYYY-MM-DD.');
        }
        if ($expiresOn <= $manufacturedOn) {
            return $this->fail('Hạn sử dụng phải sau ngày sản xuất.');
        }
        if ($receivedInput !== '' && $receivedOn === null) {
            return $this->fail('Ngày nhập kho phải đúng định dạng YYYY-MM-DD.');
        }
        if ($quantity === false) {
            return $this->fail('Số lượng nhập phải là số nguyên lớn hơn 0.');
        }

        $now = new \MongoDB\BSON\UTCDateTime();
        $document = [
            'product_id' => $productId,
            'batch_code' => $batchCode,
            'warehouse_id' => $warehouseId,
            'manufactured_on' => $manufacturedOn,
            'expires_on' => $expiresOn,
            'received_on' => $receivedOn ?: $now,
            'initial_quantity' => (int)$quantity,
            'available_quantity' => (int)$quantity,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'damaged_quantity' => 0,
            'status' => 'available',
            'created_by' => (string)($actor['id'] ?? $actor['ma_nv'] ?? $actor['email'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            $result = $this->db->inventory_batches->insertOne($document);
            $batchId = $result->getInsertedId();
            $this->db->inventory_movements->insertOne([
                'batch_id' => $batchId,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'type' => 'receipt',
                'quantity' => (int)$quantity,
                'quantity_before' => 0,
                'quantity_after' => (int)$quantity,
                'note' => 'Nhập lô ban đầu',
                'created_by' => $document['created_by'],
                'occurred_at' => $now,
            ]);
            return $this->normalizeBatch($this->db->inventory_batches->findOne(['_id' => $batchId]));
        } catch (Throwable $e) {
            $this->lastErrorMessage = str_contains(strtolower($e->getMessage()), 'duplicate')
                ? 'Lô hàng này đã tồn tại trong kho đã chọn.'
                : 'Không thể lưu lô hàng. Vui lòng thử lại.';
            error_log('inventory batch create: ' . $e->getMessage());
            return null;
        }
    }

    public function addMovement(string $batchId, string $type, int $quantity, string $note, array $actor = []): ?array
    {
        $this->lastErrorMessage = null;
        $type = trim($type);
        if (!in_array($type, self::VALID_MOVEMENTS, true) || $quantity <= 0) {
            return $this->fail('Loại điều chỉnh hoặc số lượng không hợp lệ.');
        }
        $objectId = $this->objectId($batchId);
        if ($objectId === null) {
            return $this->fail('Mã lô không hợp lệ.');
        }

        $before = $this->db->inventory_batches->findOne(['_id' => $objectId]);
        if (!$before) {
            return $this->fail('Không tìm thấy lô hàng.');
        }
        $beforeRow = $this->normalizeBatch($before);
        if (in_array($type, ['sale', 'reserve'], true)
            && ($beforeRow['status'] !== 'available' || (($this->remainingDays($beforeRow['expires_on']) ?? 0) <= 0))) {
            return $this->fail('Không thể xuất hoặc giữ hàng từ lô đã hết hạn/cách ly.');
        }
        $inc = $this->movementIncrement($type, $quantity);
        $availabilityFilter = ['_id' => $objectId];
        if (($inc['available_quantity'] ?? 0) < 0) {
            $availabilityFilter['available_quantity'] = ['$gte' => abs((int)$inc['available_quantity'])];
        }
        if (($inc['reserved_quantity'] ?? 0) < 0) {
            $availabilityFilter['reserved_quantity'] = ['$gte' => abs((int)$inc['reserved_quantity'])];
        }
        if (($inc['sold_quantity'] ?? 0) < 0) {
            $availabilityFilter['sold_quantity'] = ['$gte' => abs((int)$inc['sold_quantity'])];
        }

        $now = new \MongoDB\BSON\UTCDateTime();
        $newAvailable = (int)$beforeRow['available_quantity'] + (int)($inc['available_quantity'] ?? 0);
        $newStatus = in_array($beforeRow['status'], ['quarantined', 'expired'], true)
            ? $beforeRow['status']
            : ($newAvailable > 0 ? 'available' : 'depleted');
        $update = ['$inc' => $inc, '$set' => ['status' => $newStatus, 'updated_at' => $now]];
        try {
            $result = $this->db->inventory_batches->updateOne($availabilityFilter, $update);
            if ($result->getMatchedCount() !== 1) {
                return $this->fail('Số lượng điều chỉnh vượt quá số lượng khả dụng hiện tại.');
            }
            $after = $this->db->inventory_batches->findOne(['_id' => $objectId]);
            $afterRow = $this->normalizeBatch($after);
            $this->db->inventory_movements->insertOne([
                'batch_id' => $objectId,
                'product_id' => $beforeRow['product_id'],
                'warehouse_id' => $beforeRow['warehouse_id'],
                'type' => $type,
                'quantity' => $quantity,
                'quantity_before' => $beforeRow['available_quantity'],
                'quantity_after' => $afterRow['available_quantity'],
                'note' => trim($note),
                'created_by' => (string)($actor['id'] ?? $actor['ma_nv'] ?? $actor['email'] ?? ''),
                'occurred_at' => $now,
            ]);
            return $afterRow;
        } catch (Throwable $e) {
            $this->lastErrorMessage = 'Không thể cập nhật tồn kho lô hàng.';
            error_log('inventory batch movement: ' . $e->getMessage());
            return null;
        }
    }

    private function movementIncrement(string $type, int $quantity): array
    {
        return match ($type) {
            'receipt', 'return', 'adjustment_increase' => [
                'available_quantity' => $quantity,
                ...($type === 'receipt' ? ['initial_quantity' => $quantity] : []),
                ...($type === 'return' ? ['sold_quantity' => -$quantity] : []),
            ],
            'sale' => ['available_quantity' => -$quantity, 'sold_quantity' => $quantity],
            'reserve' => ['available_quantity' => -$quantity, 'reserved_quantity' => $quantity],
            'release' => ['available_quantity' => $quantity, 'reserved_quantity' => -$quantity],
            'damage', 'adjustment_decrease' => [
                'available_quantity' => -$quantity,
                ...($type === 'damage' ? ['damaged_quantity' => $quantity] : []),
            ],
            default => [],
        };
    }

    private function extractProductStock($product): ?int
    {
        $product = (array)$product;
        foreach (['so_luong_ton_kho', 'so_luong_ton', 'ton_kho', 'stock', 'quantity'] as $field) {
            if (array_key_exists($field, $product) && $product[$field] !== null && $product[$field] !== '') {
                return max(0, (int)$product[$field]);
            }
        }
        return null;
    }

    private function activeProductsForDemo(): iterable
    {
        foreach ($this->db->san_pham->find([], [
            'projection' => [
                'ma_san_pham' => 1, 'id' => 1, 'ten_san_pham' => 1,
                'trang_thai' => 1, 'status' => 1,
            ],
            'sort' => ['ma_san_pham' => 1],
            'limit' => 20000,
        ]) as $product) {
            $productId = (string)($product['ma_san_pham'] ?? $product['id'] ?? '');
            if ($productId === '') {
                continue;
            }
            $status = strtolower(trim((string)($product['trang_thai'] ?? $product['status'] ?? 'active')));
            if (in_array($status, ['inactive', 'hidden', 'disabled', '0', 'tam_an', 'ngung ban'], true)) {
                continue;
            }
            yield [
                'product_id' => $productId,
                'product_name' => (string)($product['ten_san_pham'] ?? 'Sản phẩm chưa đặt tên'),
            ];
        }
    }

    private function diverseDemoProfiles(): array
    {
        return [
            [
                'key' => 'expiry-17m',
                'label' => 'Hạn còn khoảng 17 tháng',
                'manufactured_offset' => '-7 months',
                'expires_offset' => '+17 months',
                'initial_quantity' => 120,
                'available_quantity' => 120,
                'status' => 'available',
            ],
            [
                'key' => 'expiry-18m',
                'label' => 'Hạn tối đa 18 tháng',
                'manufactured_offset' => '-6 months',
                'expires_offset' => '+18 months',
                'initial_quantity' => 80,
                'available_quantity' => 80,
                'status' => 'available',
            ],
            [
                'key' => 'expiry-19m',
                'label' => 'Hạn còn khoảng 19 tháng',
                'manufactured_offset' => '-5 months',
                'expires_offset' => '+19 months',
                'initial_quantity' => 45,
                'available_quantity' => 45,
                'status' => 'available',
            ],
            [
                'key' => 'expiry-3m',
                'label' => 'Sắp hết hạn trong 3 tháng',
                'manufactured_offset' => '-21 months',
                'expires_offset' => '+3 months',
                'initial_quantity' => 30,
                'available_quantity' => 30,
                'status' => 'available',
            ],
            [
                'key' => 'stock-high',
                'label' => 'Tồn kho nhiều trên 100',
                'manufactured_offset' => '-4 months',
                'expires_offset' => '+20 months',
                'initial_quantity' => 250,
                'available_quantity' => 250,
                'status' => 'available',
            ],
            [
                'key' => 'stock-low',
                'label' => 'Tồn kho thấp không quá 10',
                'manufactured_offset' => '-4 months',
                'expires_offset' => '+20 months',
                'initial_quantity' => 7,
                'available_quantity' => 7,
                'status' => 'available',
            ],
            [
                'key' => 'stock-out',
                'label' => 'Đã hết hàng',
                'manufactured_offset' => '-4 months',
                'expires_offset' => '+20 months',
                'initial_quantity' => 40,
                'available_quantity' => 0,
                'status' => 'depleted',
            ],
            [
                'key' => 'expired',
                'label' => 'Đã hết hạn',
                'manufactured_offset' => '-27 months',
                'expires_offset' => '-3 months',
                'initial_quantity' => 18,
                'available_quantity' => 18,
                'status' => 'expired',
            ],
            [
                'key' => 'quarantined',
                'label' => 'Tạm cách ly kiểm tra',
                'manufactured_offset' => '-4 months',
                'expires_offset' => '+20 months',
                'initial_quantity' => 40,
                'available_quantity' => 40,
                'status' => 'quarantined',
            ],
            [
                'key' => 'expiry-long',
                'label' => 'Hạn còn dài trên 24 tháng',
                'manufactured_offset' => '-1 month',
                'expires_offset' => '+36 months',
                'initial_quantity' => 60,
                'available_quantity' => 60,
                'status' => 'available',
            ],
        ];
    }

    private function safeBatchCode(string $productId): string
    {
        $code = preg_replace('/[^A-Za-z0-9]+/', '-', $productId) ?: 'PRODUCT';
        return trim($code, '-') ?: 'PRODUCT';
    }

    private function normalizeBatch($doc): array
    {
        $row = (array)$doc;
        $row['id'] = isset($row['_id']) ? (string)$row['_id'] : '';
        foreach (['manufactured_on', 'expires_on', 'received_on', 'created_at', 'updated_at'] as $field) {
            if (($row[$field] ?? null) instanceof \MongoDB\BSON\UTCDateTime) {
                $row[$field] = $row[$field]->toDateTime()->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d');
            }
        }
        foreach (['initial_quantity', 'available_quantity', 'reserved_quantity', 'sold_quantity', 'damaged_quantity'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        $row['product_id'] = (string)($row['product_id'] ?? '');
        $row['batch_code'] = (string)($row['batch_code'] ?? '');
        $row['warehouse_id'] = (string)($row['warehouse_id'] ?? '');
        $row['status'] = (string)($row['status'] ?? 'available');
        return $row;
    }

    private function parseDate(string $value): ?\MongoDB\BSON\UTCDateTime
    {
        $value = trim($value);
        if ($value === '') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Asia/Ho_Chi_Minh'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) return null;
        return new \MongoDB\BSON\UTCDateTime($date->setTimezone(new \DateTimeZone('UTC'))->getTimestamp() * 1000);
    }

    private function remainingDays(string $date): ?int
    {
        if ($date === '') return null;
        $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Ho_Chi_Minh'));
        if (!$expiry) return null;
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Ho_Chi_Minh'));
        return (int)$today->diff($expiry)->format('%r%a');
    }

    private function objectId(string $value): ?\MongoDB\BSON\ObjectId
    {
        try {
            return new \MongoDB\BSON\ObjectId($value);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function productExists(string $productId): bool
    {
        $values = [$productId];
        if (ctype_digit($productId)) $values[] = (int)$productId;
        return (bool)$this->db->san_pham->findOne(['ma_san_pham' => ['$in' => $values]], ['projection' => ['_id' => 1]]);
    }

    /**
     * Tra tên nhiều sản phẩm bằng một truy vấn $in, khớp giống findProductName:
     * mã dạng chuỗi và (nếu toàn chữ số) cả dạng số; lấy bản ghi đầu tiên khớp.
     * Trả [product_id => tên]; mã không có sản phẩm thì không có khoá.
     */
    private function findProductNames(array $productIds): array
    {
        $keyToIds = [];
        $values = [];
        foreach ($productIds as $productId) {
            $productId = (string)$productId;
            $keys = [['s:' . $productId, $productId]];
            if (ctype_digit($productId)) $keys[] = ['n:' . (int)$productId, (int)$productId];
            foreach ($keys as [$key, $value]) {
                $values[$key] = $value;
                $keyToIds[$key][$productId] = true;
            }
        }
        if (!$values) return [];
        $names = [];
        $cursor = $this->db->san_pham->find(
            ['ma_san_pham' => ['$in' => array_values($values)]],
            ['projection' => ['ma_san_pham' => 1, 'ten_san_pham' => 1]]
        );
        foreach ($cursor as $doc) {
            $raw = $doc['ma_san_pham'] ?? null;
            if (is_string($raw)) {
                $key = 's:' . $raw;
            } elseif (is_int($raw)) {
                $key = 'n:' . $raw;
            } elseif (is_float($raw) && $raw == floor($raw)) {
                $key = 'n:' . (int)$raw;
            } else {
                continue;
            }
            foreach (array_keys($keyToIds[$key] ?? []) as $productId) {
                $productId = (string)$productId;
                if (!array_key_exists($productId, $names)) {
                    $names[$productId] = (string)($doc['ten_san_pham'] ?? 'Không tìm thấy sản phẩm');
                }
            }
        }
        return $names;
    }

    private function findProductName(string $productId): string
    {
        $values = [$productId];
        if (ctype_digit($productId)) $values[] = (int)$productId;
        $doc = $this->db->san_pham->findOne(['ma_san_pham' => ['$in' => $values]], ['projection' => ['ten_san_pham' => 1]]);
        return (string)($doc['ten_san_pham'] ?? 'Không tìm thấy sản phẩm');
    }

    private function fail(string $message): ?array
    {
        $this->lastErrorMessage = $message;
        return null;
    }
}
