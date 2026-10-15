<?php
// backend/app/models/FlashSaleCampaign.php
/**
 * FlashSaleCampaign.php
 *
 * Domain Model & Reusable Effective Pricing Engine for SkinSyntaxVN Flash Sale Campaigns.
 * Implements server-derived lifecycle (DISABLED / UPCOMING / ACTIVE / ENDED),
 * strict time handling (UTC storage, Unix comparison, Asia/Ho_Chi_Minh display),
 * multi-product campaign validation with interval conflict rejection,
 * and canonical effective price resolution across Cart, Checkout preview, and Order creation.
 */

class FlashSaleCampaign {
    private \MongoDB\Database $db;
    private ?string $lastErrorMessage = null;
    private static ?array $requestActiveCampaignsCache = null;

    public const COLLECTION_NAME = 'flash_sale_campaigns';
    public const TIMEZONE_DISPLAY = 'Asia/Ho_Chi_Minh';

    public const STATUS_DISABLED = 'DISABLED';
    public const STATUS_UPCOMING = 'UPCOMING';
    public const STATUS_ACTIVE   = 'ACTIVE';
    public const STATUS_ENDED    = 'ENDED';

    public const PRICING_SOURCE_REGULAR    = 'REGULAR';
    public const PRICING_SOURCE_FLASH_SALE = 'FLASH_SALE';

    public function __construct($db = null) {
        if (is_object($db) && method_exists($db, 'raw')) {
            $this->db = $db->raw();
        } elseif ($db instanceof \MongoDB\Database) {
            $this->db = $db;
        } else {
            global $db;
            if (is_object($db) && method_exists($db, 'raw')) {
                $this->db = $db->raw();
            } elseif ($db instanceof \MongoDB\Database) {
                $this->db = $db;
            } else {
                throw new RuntimeException('FlashSaleCampaign requires a valid MongoDB\\Database instance.');
            }
        }
    }

    public function getLastErrorMessage(): ?string {
        return $this->lastErrorMessage;
    }

    private function setLastErrorMessage(?string $message): void {
        $this->lastErrorMessage = $message;
    }

    public static function clearRequestCache(): void {
        self::$requestActiveCampaignsCache = null;
        if (class_exists('SanPham') && method_exists('SanPham', 'invalidateProductCache')) {
            SanPham::invalidateProductCache();
        } elseif (class_exists('CacheService')) {
            CacheService::getInstance()->deleteByPattern('home:*');
            CacheService::getInstance()->deleteByPattern('catalog:*');
        }
    }

    public function getNextNumericId(string $collection = self::COLLECTION_NAME, string $column = 'ma_campaign'): int {
        try {
            $lastDoc = $this->db->{$collection}->findOne([], ['sort' => [$column => -1]]);
            if ($lastDoc && isset($lastDoc[$column]) && is_numeric($lastDoc[$column])) {
                return (int)$lastDoc[$column] + 1;
            }
        } catch (\Throwable $e) {
            error_log('FlashSaleCampaign getNextNumericId error: ' . $e->getMessage());
        }
        return 1;
    }

    // =========================================================================
    // TIME & TIMEZONE HANDLING
    // =========================================================================

    /**
     * Normalize input datetime string/timestamp into MongoDB UTCDateTime.
     * Storage timezone: UTC.
     */
    public function normalizeDateTimeToUtc($value): ?\MongoDB\BSON\UTCDateTime {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return $value;
        }

        if (is_numeric($value)) {
            $ts = (int)$value;
            // Handle milliseconds if passed
            if ($ts > 9999999999) {
                return new \MongoDB\BSON\UTCDateTime($ts);
            }
            return new \MongoDB\BSON\UTCDateTime($ts * 1000);
        }

        $str = trim((string)$value);
        if ($str === '') {
            return null;
        }

        try {
            // Default parse in Asia/Ho_Chi_Minh if no timezone offset is given
            $tz = new \DateTimeZone(self::TIMEZONE_DISPLAY);
            $dt = new \DateTime($str, $tz);
            return new \MongoDB\BSON\UTCDateTime($dt->getTimestamp() * 1000);
        } catch (\Throwable $e) {
            $ts = strtotime($str);
            if ($ts !== false) {
                return new \MongoDB\BSON\UTCDateTime($ts * 1000);
            }
        }

        return null;
    }

    /**
     * Convert MongoDB UTCDateTime to Unix timestamp (seconds since epoch in UTC).
     * Comparison timezone: Unix timestamp / UTC.
     */
    public function utcDateTimeToTimestamp($utc): ?int {
        if ($utc instanceof \MongoDB\BSON\UTCDateTime) {
            return (int)floor($utc->toDateTime()->getTimestamp());
        }
        if (is_numeric($utc)) {
            return (int)$utc;
        }
        if (is_string($utc) && trim($utc) !== '') {
            $ts = strtotime($utc);
            return $ts !== false ? $ts : null;
        }
        return null;
    }

    /**
     * Format MongoDB UTCDateTime into formatted string in Asia/Ho_Chi_Minh timezone.
     * Display timezone: Asia/Ho_Chi_Minh.
     */
    public function normalizeUtcToDisplayFormat($utc, string $format = 'Y-m-d H:i:s'): ?string {
        if (!$utc) {
            return null;
        }
        try {
            $tz = new \DateTimeZone(self::TIMEZONE_DISPLAY);
            if ($utc instanceof \MongoDB\BSON\UTCDateTime) {
                return $utc->toDateTime()->setTimezone($tz)->format($format);
            }
            if (is_numeric($utc)) {
                $dt = new \DateTime('@' . (int)$utc);
                $dt->setTimezone($tz);
                return $dt->format($format);
            }
            $dt = new \DateTime((string)$utc);
            $dt->setTimezone($tz);
            return $dt->format($format);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // =========================================================================
    // SERVER-DERIVED LIFECYCLE
    // =========================================================================

    /**
     * Derive campaign lifecycle state purely from server time.
     *
     * State definitions:
     * - DISABLED: enabled == false
     * - UPCOMING: enabled && now < start_at
     * - ACTIVE:   enabled && start_at <= now < end_at
     * - ENDED:    enabled && now >= end_at
     *
     * @param array $campaign Normalized campaign document
     * @param int|null $nowTimestamp Optional server timestamp for deterministic test time traveling
     * @return string One of STATUS_DISABLED, STATUS_UPCOMING, STATUS_ACTIVE, STATUS_ENDED
     */
    public function getCampaignStatus(array $campaign, ?int $nowTimestamp = null): string {
        $isEnabled = !empty($campaign['enabled']) || !empty($campaign['is_active']);
        if (!$isEnabled) {
            return self::STATUS_DISABLED;
        }

        $now = $nowTimestamp !== null ? $nowTimestamp : time();
        $start = $this->getCampaignStartTimestamp($campaign);
        $end = $this->getCampaignEndTimestamp($campaign);

        if ($start === null || $end === null || $end <= $start) {
            return self::STATUS_DISABLED;
        }

        if ($now < $start) {
            return self::STATUS_UPCOMING;
        }

        if ($now >= $start && $now < $end) {
            return self::STATUS_ACTIVE;
        }

        return self::STATUS_ENDED;
    }

    /**
     * Safely convert MongoDB BSONArray, Traversable, or array to standard PHP array.
     */
    public static function bsonToArray($val): array {
        if (is_array($val)) {
            return $val;
        }
        if ($val instanceof \Traversable) {
            return iterator_to_array($val);
        }
        if (is_object($val)) {
            return (array)$val;
        }
        return [];
    }

    public function getCampaignStartTimestamp(array $campaign): ?int {
        if (!empty($campaign['start_at_ts'])) {
            return (int)$campaign['start_at_ts'];
        }
        return $this->utcDateTimeToTimestamp($campaign['start_at_raw'] ?? $campaign['start_at'] ?? null);
    }

    public function getCampaignEndTimestamp(array $campaign): ?int {
        if (!empty($campaign['end_at_ts'])) {
            return (int)$campaign['end_at_ts'];
        }
        return $this->utcDateTimeToTimestamp($campaign['end_at_raw'] ?? $campaign['end_at'] ?? null);
    }

    // =========================================================================
    // NORMALIZATION
    // =========================================================================

    public function normalizeCampaignRow($doc, ?int $nowTimestamp = null): array {
        if (!$doc) {
            return [];
        }
        $c = (array)$doc;

        $campaignId = (int)($c['ma_campaign'] ?? $c['id'] ?? 0);
        $name = trim((string)($c['ten_campaign'] ?? $c['name'] ?? ''));
        $description = trim((string)($c['mo_ta'] ?? $c['description'] ?? ''));
        $isEnabled = !empty($c['is_active']) || !empty($c['enabled']);

        $startUtc = $c['start_at'] instanceof \MongoDB\BSON\UTCDateTime ? $c['start_at'] : $this->normalizeDateTimeToUtc($c['start_at'] ?? null);
        $endUtc = $c['end_at'] instanceof \MongoDB\BSON\UTCDateTime ? $c['end_at'] : $this->normalizeDateTimeToUtc($c['end_at'] ?? null);

        $startTs = $this->utcDateTimeToTimestamp($startUtc);
        $endTs = $this->utcDateTimeToTimestamp($endUtc);

        $itemsRaw = self::bsonToArray($c['items'] ?? null);
        $items = [];
        foreach ($itemsRaw as $it) {
            $itemArr = (array)$it;
            $pid = trim((string)($itemArr['ma_san_pham'] ?? $itemArr['product_id'] ?? $itemArr['id'] ?? ''));
            if ($pid === '') continue;

            $regularPrice = max(0, (int)($itemArr['regular_price'] ?? $itemArr['gia_ban'] ?? 0));
            $flashPrice = max(0, (int)($itemArr['flash_price'] ?? $itemArr['gia_flash'] ?? 0));
            $discountPct = null;
            if ($regularPrice > 0 && $flashPrice > 0 && $flashPrice < $regularPrice) {
                $discountPct = (int)round((($regularPrice - $flashPrice) / $regularPrice) * 100);
            }

            $items[] = [
                'ma_san_pham' => $pid,
                'product_id' => $pid,
                'regular_price' => $regularPrice,
                'flash_price' => $flashPrice,
                'phan_tram_giam' => $discountPct,
                'savings_amount' => max(0, $regularPrice - $flashPrice),
            ];
        }

        $normalized = [
            'ma_campaign' => $campaignId,
            'id' => $campaignId,
            'ten_campaign' => $name,
            'name' => $name,
            'mo_ta' => $description,
            'is_active' => $isEnabled,
            'enabled' => $isEnabled,
            'start_at' => $this->normalizeUtcToDisplayFormat($startUtc),
            'end_at' => $this->normalizeUtcToDisplayFormat($endUtc),
            'start_at_ts' => $startTs,
            'end_at_ts' => $endTs,
            'start_at_raw' => $startUtc,
            'end_at_raw' => $endUtc,
            'items' => $items,
            'item_count' => count($items),
            'created_at' => $this->normalizeUtcToDisplayFormat($c['created_at'] ?? null),
            'updated_at' => $this->normalizeUtcToDisplayFormat($c['updated_at'] ?? null),
        ];

        $normalized['status'] = $this->getCampaignStatus($normalized, $nowTimestamp);
        return $normalized;
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * Validate campaign payload strictly before persistence.
     *
     * Rules:
     * - name not empty
     * - end_at > start_at
     * - product exists in canonical san_pham
     * - product is active (not inactive/hidden/0)
     * - flash_price > 0
     * - flash_price < canonical regular selling price
     * - no duplicate product inside campaign
     * - reject overlapping enabled campaign intervals for the same product
     *
     * @param array $payload
     * @param int|null $excludeCampaignId ID of campaign being edited (to exclude self from conflict check)
     * @return array ['ok' => bool, 'message' => string, 'errors' => array, 'validated_data' => array]
     */
    public function validateCampaign(array $payload, ?int $excludeCampaignId = null): array {
        $errors = [];

        $name = trim((string)($payload['ten_campaign'] ?? $payload['name'] ?? ''));
        if ($name === '') {
            $errors['ten_campaign'] = 'Tên chiến dịch Flash Sale không được để trống.';
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $errors['ten_campaign'] = 'Tên chiến dịch phải từ 2 đến 150 ký tự.';
        }

        $startUtc = $this->normalizeDateTimeToUtc($payload['start_at'] ?? null);
        $endUtc = $this->normalizeDateTimeToUtc($payload['end_at'] ?? null);

        if (!$startUtc) {
            $errors['start_at'] = 'Thời gian bắt đầu không hợp lệ.';
        }
        if (!$endUtc) {
            $errors['end_at'] = 'Thời gian kết thúc không hợp lệ.';
        }

        $startTs = $this->utcDateTimeToTimestamp($startUtc);
        $endTs = $this->utcDateTimeToTimestamp($endUtc);

        if ($startTs !== null && $endTs !== null) {
            if ($endTs <= $startTs) {
                $errors['end_at'] = 'Thời gian kết thúc phải lớn hơn thời gian bắt đầu.';
            }
        }

        $isEnabled = !array_key_exists('is_active', $payload) && !array_key_exists('enabled', $payload)
            ? true
            : (!empty($payload['is_active']) || !empty($payload['enabled']));

        // Validate items
        $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if (empty($rawItems)) {
            $errors['items'] = 'Chiến dịch Flash Sale phải có ít nhất một sản phẩm tham gia.';
        }

        $validatedItems = [];
        $seenProductIds = [];

        foreach ($rawItems as $idx => $it) {
            $itArr = (array)$it;
            $pid = trim((string)($itArr['ma_san_pham'] ?? $itArr['product_id'] ?? $itArr['id'] ?? ''));

            if ($pid === '') {
                $errors["item_{$idx}_product"] = "Sản phẩm tại dòng #" . ($idx + 1) . " không có mã sản phẩm hợp lệ.";
                continue;
            }

            // Duplicate SKU inside campaign
            if (isset($seenProductIds[$pid])) {
                $errors["item_{$idx}_duplicate"] = "Sản phẩm #{$pid} bị trùng lặp bên trong cùng một chiến dịch.";
                continue;
            }
            $seenProductIds[$pid] = true;

            // Product exists and is active check in canonical san_pham
            $productDoc = $this->findProductInCatalog($pid);
            if (!$productDoc) {
                $errors["item_{$idx}_not_found"] = "Sản phẩm #{$pid} không tồn tại trong danh mục.";
                continue;
            }

            $status = strtolower(trim((string)($productDoc['trang_thai'] ?? $productDoc['status'] ?? 'active')));
            if (in_array($status, ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0'], true)) {
                $errors["item_{$idx}_inactive"] = "Sản phẩm #{$pid} đang ở trạng thái ngưng hoạt động/ẩn, không thể tham gia Flash Sale.";
                continue;
            }

            // Regular price
            $regularPrice = (int)($productDoc['gia_ban'] ?? 0);
            if ($regularPrice <= 0) {
                $regularPrice = (int)($productDoc['gia_thi_truong'] ?? 0);
            }
            if ($regularPrice <= 0) {
                $errors["item_{$idx}_regular_price"] = "Sản phẩm #{$pid} không có giá bán niêm yết hợp lệ trong hệ thống.";
                continue;
            }

            // Flash price validation
            $rawFlashPrice = $itArr['flash_price'] ?? $itArr['gia_flash'] ?? null;
            if ($rawFlashPrice === null || !is_numeric($rawFlashPrice)) {
                $errors["item_{$idx}_flash_price"] = "Giá Flash Sale của sản phẩm #{$pid} không hợp lệ.";
                continue;
            }

            $flashPrice = (int)$rawFlashPrice;
            if ($flashPrice <= 0) {
                $errors["item_{$idx}_flash_price_zero"] = "Giá Flash Sale của sản phẩm #{$pid} phải lớn hơn 0.";
                continue;
            }

            if ($flashPrice >= $regularPrice) {
                $errors["item_{$idx}_flash_price_gte"] = "Giá Flash Sale (" . number_format($flashPrice, 0, ',', '.') . " đ) phải nhỏ hơn giá niêm yết (" . number_format($regularPrice, 0, ',', '.') . " đ) của sản phẩm #{$pid}.";
                continue;
            }

            $discountPercent = (int)round((($regularPrice - $flashPrice) / $regularPrice) * 100);

            $validatedItems[] = [
                'ma_san_pham' => $pid,
                'regular_price' => $regularPrice,
                'flash_price' => $flashPrice,
                'phan_tram_giam' => $discountPercent,
            ];
        }

        // Overlapping campaign check for the same product
        if ($isEnabled && $startTs !== null && $endTs !== null && !empty($validatedItems)) {
            $conflictErrors = $this->checkOverlappingCampaigns(array_keys($seenProductIds), $startTs, $endTs, $excludeCampaignId);
            if (!empty($conflictErrors)) {
                $errors = array_merge($errors, $conflictErrors);
            }
        }

        $ok = empty($errors);
        $message = $ok ? 'Dữ liệu chiến dịch hợp lệ.' : (string)reset($errors);

        return [
            'ok' => $ok,
            'message' => $message,
            'errors' => $errors,
            'validated_data' => $ok ? [
                'ten_campaign' => $name,
                'mo_ta' => trim((string)($payload['mo_ta'] ?? '')),
                'start_at' => $startUtc,
                'end_at' => $endUtc,
                'is_active' => $isEnabled,
                'items' => $validatedItems,
            ] : [],
        ];
    }

    /**
     * Check if any product in $productIds belongs to another enabled campaign with an overlapping time interval.
     * Interval overlap: max(start1, start2) < min(end1, end2)
     */
    public function checkOverlappingCampaigns(array $productIds, int $startTs, int $endTs, ?int $excludeCampaignId = null): array {
        $conflictErrors = [];
        if (empty($productIds) || $endTs <= $startTs) {
            return $conflictErrors;
        }

        $filter = [
            'is_active' => ['$ne' => false],
        ];
        if ($excludeCampaignId !== null && $excludeCampaignId > 0) {
            $filter['ma_campaign'] = ['$ne' => $excludeCampaignId];
        }

        // Look for campaigns that overlap in time
        // Overlap exists iff (start_existing < end_new) AND (end_existing > start_new)
        $filter['start_at'] = ['$lt' => new \MongoDB\BSON\UTCDateTime($endTs * 1000)];
        $filter['end_at']   = ['$gt' => new \MongoDB\BSON\UTCDateTime($startTs * 1000)];

        $cursor = $this->db->{self::COLLECTION_NAME}->find($filter);
        $targetIds = array_map('strval', $productIds);
        foreach ($cursor as $doc) {
            $otherCampaign = (array)$doc;
            $otherId = (int)($otherCampaign['ma_campaign'] ?? 0);
            $otherName = (string)($otherCampaign['ten_campaign'] ?? ('#' . $otherId));
            $otherItems = self::bsonToArray($otherCampaign['items'] ?? null);

            foreach ($otherItems as $ot) {
                $otPid = trim((string)($ot['ma_san_pham'] ?? $ot['product_id'] ?? ''));
                if (in_array($otPid, $targetIds, true)) {
                    $conflictErrors["conflict_{$otPid}_{$otherId}"] = "Sản phẩm #{$otPid} đã tham gia chiến dịch '{$otherName}' (#{$otherId}) có khung giờ trùng lặp.";
                }
            }
        }

        return $conflictErrors;
    }

    public function findProductInCatalog(string $productId): ?array {
        $productId = trim($productId);
        if ($productId === '') return null;

        $or = [
            ['ma_san_pham' => $productId],
            ['id' => $productId],
        ];
        if (is_numeric($productId)) {
            $or[] = ['ma_san_pham' => (int)$productId];
            $or[] = ['id' => (int)$productId];
        }
        if (preg_match('/^[a-f0-9]{24}$/i', $productId)) {
            try {
                $or[] = ['_id' => new \MongoDB\BSON\ObjectId($productId)];
            } catch (\Throwable $e) {}
        }

        $doc = $this->db->san_pham->findOne(['$or' => $or]);
        return $doc ? (array)$doc : null;
    }

    // =========================================================================
    // PERSISTENCE (CRUD)
    // =========================================================================

    public function saveCampaign(array $payload, ?int $campaignId = null): ?int {
        $validation = $this->validateCampaign($payload, $campaignId);
        if (!$validation['ok']) {
            $this->setLastErrorMessage($validation['message']);
            return null;
        }

        $data = $validation['validated_data'];
        $nowUtc = new \MongoDB\BSON\UTCDateTime();

        try {
            if ($campaignId !== null && $campaignId > 0) {
                $data['updated_at'] = $nowUtc;
                $this->db->{self::COLLECTION_NAME}->updateOne(
                    ['ma_campaign' => $campaignId],
                    ['$set' => $data]
                );
                self::clearRequestCache();
                return $campaignId;
            } else {
                $newId = $this->getNextNumericId();
                $data['ma_campaign'] = $newId;
                $data['created_at'] = $nowUtc;
                $data['updated_at'] = $nowUtc;
                $this->db->{self::COLLECTION_NAME}->insertOne($data);
                self::clearRequestCache();
                return $newId;
            }
        } catch (\Throwable $e) {
            error_log('FlashSaleCampaign saveCampaign error: ' . $e->getMessage());
            $this->setLastErrorMessage('Lỗi lưu chiến dịch: ' . $e->getMessage());
            return null;
        }
    }

    public function deleteCampaign(int $campaignId): bool {
        if ($campaignId <= 0) return false;
        try {
            $res = $this->db->{self::COLLECTION_NAME}->deleteOne(['ma_campaign' => $campaignId]);
            self::clearRequestCache();
            return $res->getDeletedCount() > 0;
        } catch (\Throwable $e) {
            error_log('FlashSaleCampaign deleteCampaign error: ' . $e->getMessage());
            $this->setLastErrorMessage($e->getMessage());
            return false;
        }
    }

    public function getCampaignById(int $campaignId, ?int $nowTimestamp = null): ?array {
        if ($campaignId <= 0) return null;
        try {
            $doc = $this->db->{self::COLLECTION_NAME}->findOne(['ma_campaign' => $campaignId]);
            return $doc ? $this->normalizeCampaignRow($doc, $nowTimestamp) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * List all campaigns with server-derived status.
     */
    public function listCampaigns(array $filter = [], array $options = [], ?int $nowTimestamp = null): array {
        try {
            $sort = $options['sort'] ?? ['start_at' => -1, 'ma_campaign' => -1];
            $cursor = $this->db->{self::COLLECTION_NAME}->find($filter, ['sort' => $sort]);
            $list = [];
            foreach ($cursor as $doc) {
                $list[] = $this->normalizeCampaignRow($doc, $nowTimestamp);
            }
            return $list;
        } catch (\Throwable $e) {
            error_log('FlashSaleCampaign listCampaigns error: ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================================
    // EFFECTIVE PRICING ENGINE
    // =========================================================================

    /**
     * Load all currently ACTIVE campaigns into request memory cache.
     */
    private function loadActiveCampaigns(?int $nowTimestamp = null): array {
        if (self::$requestActiveCampaignsCache !== null && $nowTimestamp === null) {
            return self::$requestActiveCampaignsCache;
        }

        $now = $nowTimestamp !== null ? $nowTimestamp : time();
        $nowUtc = new \MongoDB\BSON\UTCDateTime($now * 1000);

        $filter = [
            'is_active' => ['$ne' => false],
            'start_at'  => ['$lte' => $nowUtc],
            'end_at'    => ['$gt' => $nowUtc],
        ];

        try {
            $cursor = $this->db->{self::COLLECTION_NAME}->find($filter, ['sort' => ['start_at' => -1]]);
            $campaigns = [];
            foreach ($cursor as $doc) {
                $campaigns[] = $this->normalizeCampaignRow($doc, $now);
            }

            if ($nowTimestamp === null) {
                self::$requestActiveCampaignsCache = $campaigns;
            }
            return $campaigns;
        } catch (\Throwable $e) {
            error_log('FlashSaleCampaign loadActiveCampaigns error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Resolve the canonical effective price for a product.
     *
     * Rule:
     * If there is an active Flash Sale campaign containing this product:
     *   effective_price = flash_price
     *   pricing_source = 'FLASH_SALE'
     * Otherwise:
     *   effective_price = canonical regular selling price (gia_ban)
     *   pricing_source = 'REGULAR'
     *
     * Invariant:
     * Same product + same active campaign produces the exact same price for every user.
     * NO personalized pricing.
     *
     * @param string|int $productId
     * @param int|null $nowTimestamp Optional server timestamp for deterministic test time traveling
     * @param array|null $preloadedProduct Optional product document to avoid extra DB query
     * @return array
     */
    public function resolveEffectivePrice(string|int $productId, ?int $nowTimestamp = null, ?array $preloadedProduct = null): array {
        $pidStr = trim((string)$productId);
        $product = $preloadedProduct ?? $this->findProductInCatalog($pidStr);

        $regularPrice = 0;
        $marketPrice = 0;
        if ($product) {
            $regularPrice = (int)($product['gia_ban'] ?? 0);
            $marketPrice = (int)($product['gia_thi_truong'] ?? 0);
            if ($regularPrice <= 0 && $marketPrice > 0) {
                $regularPrice = $marketPrice;
            }
        }

        $activeCampaigns = $this->loadActiveCampaigns($nowTimestamp);

        // Check if product is in any active campaign
        foreach ($activeCampaigns as $c) {
            foreach ($c['items'] as $item) {
                if ((string)$item['ma_san_pham'] === $pidStr) {
                    $flashPrice = (int)$item['flash_price'];
                    // Flash sale price is effective only if valid and lower than regular price
                    if ($flashPrice > 0 && ($regularPrice <= 0 || $flashPrice < $regularPrice)) {
                        $discountPct = $regularPrice > 0
                            ? (int)round((($regularPrice - $flashPrice) / $regularPrice) * 100)
                            : 0;

                        return [
                            'product_id' => $pidStr,
                            'regular_price' => $regularPrice,
                            'market_price' => $marketPrice,
                            'effective_price' => $flashPrice,
                            'pricing_source' => self::PRICING_SOURCE_FLASH_SALE,
                            'is_flash_sale' => true,
                            'campaign_id' => (int)$c['ma_campaign'],
                            'campaign_name' => (string)$c['ten_campaign'],
                            'discount_percent' => $discountPct,
                            'savings_amount' => max(0, $regularPrice - $flashPrice),
                            'valid_until' => $c['end_at'],
                            'valid_until_ts' => $c['end_at_ts'],
                        ];
                    }
                }
            }
        }

        // Regular price fallback
        $regularDiscountPct = null;
        if ($marketPrice > $regularPrice && $marketPrice > 0) {
            $regularDiscountPct = (int)round((($marketPrice - $regularPrice) / $marketPrice) * 100);
        }

        return [
            'product_id' => $pidStr,
            'regular_price' => $regularPrice,
            'market_price' => $marketPrice,
            'effective_price' => $regularPrice,
            'pricing_source' => self::PRICING_SOURCE_REGULAR,
            'is_flash_sale' => false,
            'campaign_id' => null,
            'campaign_name' => null,
            'discount_percent' => $regularDiscountPct,
            'savings_amount' => max(0, $marketPrice - $regularPrice),
            'valid_until' => null,
            'valid_until_ts' => null,
        ];
    }

    // =========================================================================
    // ADMIN MANAGEMENT HELPERS
    // =========================================================================

    /**
     * Search active products in catalog for Admin Flash Sale product selector.
     */
    public function searchProductsForSelector(string $query, int $limit = 15): array {
        $q = trim($query);
        $limit = max(1, min(50, $limit));

        $filter = [
            'trang_thai' => ['$nin' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']]
        ];

        if ($q !== '') {
            $or = [];
            if (is_numeric($q)) {
                $or[] = ['ma_san_pham' => (int)$q];
                $or[] = ['ma_san_pham' => $q];
                $or[] = ['id' => (int)$q];
                $or[] = ['id' => $q];
            }
            $diacriticRegex = $this->buildVietnameseDiacriticRegex($q);
            $or[] = ['ten_san_pham' => ['$regex' => $diacriticRegex, '$options' => 'i']];
            $or[] = ['thuong_hieu' => ['$regex' => $diacriticRegex, '$options' => 'i']];
            $or[] = ['danh_muc_day_du' => ['$regex' => $diacriticRegex, '$options' => 'i']];
            $filter['$or'] = $or;
        }

        $options = [
            'limit' => $limit,
            'sort' => ['ma_san_pham' => 1],
            'projection' => [
                'ma_san_pham' => 1,
                'id' => 1,
                'ten_san_pham' => 1,
                'gia_ban' => 1,
                'gia_thi_truong' => 1,
                'link_hinh_anh' => 1,
                'hinh_anh' => 1,
                'danh_muc_day_du' => 1,
                'loai_san_pham' => 1,
                'thuong_hieu' => 1,
                'trang_thai' => 1,
            ]
        ];

        try {
            $cursor = $this->db->san_pham->find($filter, $options);
            $results = [];
            foreach ($cursor as $doc) {
                $p = (array)$doc;
                $pid = (string)($p['ma_san_pham'] ?? $p['id'] ?? '');
                $regPrice = (int)($p['gia_ban'] ?? 0);
                $mktPrice = (int)($p['gia_thi_truong'] ?? 0);
                if ($regPrice <= 0 && $mktPrice > 0) {
                    $regPrice = $mktPrice;
                }

                $img = (string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? '');
                if (function_exists('resolve_image_url')) {
                    $img = resolve_image_url($img);
                }

                $results[] = [
                    'id' => $pid,
                    'ma_san_pham' => $pid,
                    'ten_san_pham' => (string)($p['ten_san_pham'] ?? ''),
                    'gia_ban' => $regPrice,
                    'gia_thi_truong' => $mktPrice,
                    'hinh_anh' => $img,
                    'danh_muc' => (string)($p['danh_muc_day_du'] ?? $p['loai_san_pham'] ?? 'Mỹ phẩm'),
                    'thuong_hieu' => (string)($p['thuong_hieu'] ?? 'SkinSyntax'),
                ];
            }
            return $results;
        } catch (\Throwable $e) {
            error_log('FlashSaleCampaign searchProductsForSelector error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Build regex pattern that matches Vietnamese diacritics even if search term is typed without accents.
     */
    private function buildVietnameseDiacriticRegex(string $query): string {
        $escaped = preg_quote($query, '/');
        $map = [
            'a' => '[aàáảãạăằắẳẵặâầấẩẫậAÀÁẢÃẠĂẰẮẲẴẶÂẦẤẨẪẬ]',
            'e' => '[eèéẻẽẹêềếểễệEÈÉẺẼẸÊỀẾỂỄỆ]',
            'i' => '[iìíỉĩịIÌÍỈĨỊ]',
            'o' => '[oòóỏõọôồốổỗộơờớởỡợOÒÓỎÕỌÔỒỐỔỖỘƠỜỚỞỠỢ]',
            'u' => '[uùúủũụưừứửữựUÙÚỦŨỤƯỪỨỬỮỰ]',
            'y' => '[yỳýỷỹỵYỲÝỶỸỴ]',
            'd' => '[dđDĐ]',
        ];
        $pattern = '';
        $len = mb_strlen($escaped, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($escaped, $i, 1, 'UTF-8');
            $lower = mb_strtolower($char, 'UTF-8');
            $pattern .= $map[$lower] ?? $char;
        }
        return $pattern;
    }

    /**
     * Quick toggle enabled/disabled status of a campaign.
     */
    public function toggleCampaign(int $campaignId): ?bool {
        if ($campaignId <= 0) return null;
        try {
            $doc = $this->db->{self::COLLECTION_NAME}->findOne(['ma_campaign' => $campaignId]);
            if (!$doc) return null;

            $currentActive = !empty($doc['is_active']) || !empty($doc['enabled']);
            $newActive = !$currentActive;

            $this->db->{self::COLLECTION_NAME}->updateOne(
                ['ma_campaign' => $campaignId],
                ['$set' => ['is_active' => $newActive, 'updated_at' => new \MongoDB\BSON\UTCDateTime()]]
            );
            self::clearRequestCache();
            return $newActive;
        } catch (\Throwable $e) {
            error_log('FlashSaleCampaign toggleCampaign error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Safety check before deleting a campaign.
     */
    public function canDeleteCampaign(int $campaignId, ?int $now = null): array {
        $campaign = $this->getCampaignById($campaignId, $now);
        if (!$campaign) {
            return ['can_delete' => false, 'reason' => 'Chiến dịch không tồn tại.'];
        }

        if ($campaign['status'] === self::STATUS_ACTIVE) {
            return [
                'can_delete' => false,
                'reason' => 'Không thể xóa chiến dịch đang diễn ra. Vui lòng tắt chiến dịch trước khi xóa.'
            ];
        }

        return ['can_delete' => true, 'reason' => ''];
    }

    // =========================================================================
    // PHASE 2C: PUBLIC FLASH SALE EXPERIENCE ENGINE
    // =========================================================================

    /**
     * Retrieve the single primary ACTIVE Flash Sale campaign.
     * Rule: earliest end_at, then ma_campaign ASC as tie-breaker.
     * Hydrates valid san_pham catalog data in batch without N+1 queries.
     */
    public function getPrimaryActiveCampaign(?int $now = null): ?array {
        $nowTs = $now !== null ? $now : time();
        $nowUtc = new \MongoDB\BSON\UTCDateTime($nowTs * 1000);

        $filter = [
            'is_active' => ['$ne' => false],
            'start_at'  => ['$lte' => $nowUtc],
            'end_at'    => ['$gt' => $nowUtc],
        ];

        try {
            $cursor = $this->db->{self::COLLECTION_NAME}->find($filter, [
                'sort' => ['end_at' => 1, 'ma_campaign' => 1],
                'limit' => 1,
            ]);

            $doc = null;
            foreach ($cursor as $itemDoc) {
                $doc = $itemDoc;
                break;
            }

            if (!$doc) {
                return null;
            }

            $campaign = $this->normalizeCampaignRow($doc, $nowTs);
            if ($campaign['status'] !== self::STATUS_ACTIVE) {
                return null;
            }

            $hydratedItems = $this->hydrateCampaignItems($campaign['items'], $nowTs);
            $this->sortBaselineRanking($hydratedItems);

            $campaign['hydrated_items'] = $hydratedItems;
            $campaign['valid_deal_count'] = count($hydratedItems);

            return $campaign;
        } catch (\Throwable $e) {
            error_log('FlashSaleCampaign getPrimaryActiveCampaign error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve the next upcoming Flash Sale campaign (if any) for honest display.
     */
    public function getUpcomingCampaign(?int $now = null): ?array {
        $nowTs = $now !== null ? $now : time();
        $nowUtc = new \MongoDB\BSON\UTCDateTime($nowTs * 1000);

        $filter = [
            'is_active' => ['$ne' => false],
            'start_at'  => ['$gt' => $nowUtc],
        ];

        try {
            $cursor = $this->db->{self::COLLECTION_NAME}->find($filter, [
                'sort' => ['start_at' => 1, 'ma_campaign' => 1],
                'limit' => 1,
            ]);

            foreach ($cursor as $doc) {
                $c = $this->normalizeCampaignRow($doc, $nowTs);
                if ($c['status'] === self::STATUS_UPCOMING) {
                    return $c;
                }
            }
            return null;
        } catch (\Throwable $e) {
            error_log('FlashSaleCampaign getUpcomingCampaign error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Hydrate campaign items with canonical san_pham catalog data in batch.
     * Skips invalid, missing, or inactive products safely.
     * Avoids N+1 query pattern.
     */
    public function hydrateCampaignItems(array $items, ?int $now = null): array {
        if (empty($items)) {
            return [];
        }

        $skuIds = [];
        foreach ($items as $item) {
            $pid = trim((string)($item['ma_san_pham'] ?? $item['product_id'] ?? ''));
            if ($pid !== '') {
                if (!in_array($pid, $skuIds, true)) {
                    $skuIds[] = $pid;
                }
                if (ctype_digit($pid)) {
                    $intPid = (int)$pid;
                    if (!in_array($intPid, $skuIds, true)) {
                        $skuIds[] = $intPid;
                    }
                }
            }
        }
        if (empty($skuIds)) {
            return [];
        }

        // Single batch query to san_pham collection
        try {
            $spDocs = $this->db->san_pham->find([
                'ma_san_pham' => ['$in' => $skuIds],
            ]);

            $catalogMap = [];
            foreach ($spDocs as $sp) {
                $spArr = method_exists($sp, 'getArrayCopy') ? $sp->getArrayCopy() : (array)$sp;
                $status = strtolower(trim((string)($spArr['trang_thai'] ?? 'active')));
                if (in_array($status, ['inactive', 'hidden', 'disabled', '0', 'deleted'], true)) {
                    continue; // Skip inactive products
                }
                $pidStr = (string)($spArr['ma_san_pham'] ?? '');
                $catalogMap[$pidStr] = $spArr;
            }

            $hydrated = [];
            foreach ($items as $item) {
                $pidStr = trim((string)($item['ma_san_pham'] ?? $item['product_id'] ?? ''));
                if (!isset($catalogMap[$pidStr])) {
                    // Product missing, invalid, or inactive - skip safely
                    continue;
                }

                $sp = $catalogMap[$pidStr];
                $regularPrice = (int)($item['regular_price'] ?? $sp['gia_ban'] ?? 0);
                if ($regularPrice <= 0) {
                    $regularPrice = (int)($sp['gia_ban'] ?? 0);
                }
                $marketPrice = (int)($sp['gia_thi_truong'] ?? 0);
                if ($regularPrice <= 0 && $marketPrice > 0) {
                    $regularPrice = $marketPrice;
                }

                $flashPrice = (int)($item['flash_price'] ?? 0);

                // Valid flash deal condition: flashPrice > 0 and lower than regularPrice
                if ($flashPrice <= 0 || ($regularPrice > 0 && $flashPrice >= $regularPrice)) {
                    continue;
                }

                $discountPercent = $regularPrice > 0
                    ? (int)round((($regularPrice - $flashPrice) / $regularPrice) * 100)
                    : 0;

                $rating = isset($sp['diem_danh_gia']) && is_numeric($sp['diem_danh_gia'])
                    ? (float)$sp['diem_danh_gia']
                    : 0.0;
                $reviewCount = isset($sp['so_luong_danh_gia']) && is_numeric($sp['so_luong_danh_gia'])
                    ? (int)$sp['so_luong_danh_gia']
                    : 0;

                $rawImg = (string)($sp['link_hinh_anh'] ?? $sp['hinh_anh'] ?? '');
                $img = function_exists('resolve_image_url') ? resolve_image_url($rawImg) : $rawImg;

                $hydrated[] = [
                    'id' => $pidStr,
                    'ma_san_pham' => $pidStr,
                    'product_id' => $pidStr,
                    'ten_san_pham' => (string)($sp['ten_san_pham'] ?? ''),
                    'thuong_hieu' => (string)($sp['thuong_hieu'] ?? 'SkinSyntax'),
                    'danh_muc' => (string)($sp['danh_muc_day_du'] ?? $sp['loai_san_pham'] ?? ''),
                    'gia_ban' => $regularPrice,
                    'regular_price' => $regularPrice,
                    'market_price' => $marketPrice,
                    'flash_price' => $flashPrice,
                    'effective_price' => $flashPrice,
                    'phan_tram_giam' => $discountPercent,
                    'discount_percent' => $discountPercent,
                    'savings_amount' => max(0, $regularPrice - $flashPrice),
                    'diem_danh_gia' => $rating,
                    'so_luong_danh_gia' => $reviewCount,
                    'link_hinh_anh' => $img,
                    'hinh_anh' => $img,
                    'raw_product' => $sp,
                ];
            }

            return $hydrated;
        } catch (\Throwable $e) {
            error_log('FlashSaleCampaign hydrateCampaignItems error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Deterministic baseline ranking for Phase 2C.
     * Order:
     * 1. discount_percent DESC
     * 2. diem_danh_gia DESC
     * 3. so_luong_danh_gia DESC
     * 4. ma_san_pham ASC (stable tie-breaker)
     */
    public function sortBaselineRanking(array &$items): void {
        usort($items, static function (array $a, array $b): int {
            // 1. discount_percent DESC
            $discA = (int)($a['discount_percent'] ?? 0);
            $discB = (int)($b['discount_percent'] ?? 0);
            if ($discA !== $discB) {
                return $discB <=> $discA;
            }

            // 2. diem_danh_gia DESC
            $ratingA = (float)($a['diem_danh_gia'] ?? 0);
            $ratingB = (float)($b['diem_danh_gia'] ?? 0);
            if (abs($ratingA - $ratingB) > 0.0001) {
                return $ratingB <=> $ratingA;
            }

            // 3. so_luong_danh_gia DESC
            $cntA = (int)($a['so_luong_danh_gia'] ?? 0);
            $cntB = (int)($b['so_luong_danh_gia'] ?? 0);
            if ($cntA !== $cntB) {
                return $cntB <=> $cntA;
            }

            // 4. Stable tie-breaker: ma_san_pham ASC
            return strcmp((string)$a['ma_san_pham'], (string)$b['ma_san_pham']);
        });
    }

    /**
     * Sort All Deals based on user-selected criteria.
     * Supported: featured, discount, rating, price_asc, price_desc
     */
    public function sortCampaignDeals(array &$items, string $sort): void {
        switch ($sort) {
            case 'discount':
                // Giảm nhiều nhất: discount_percent DESC, then ma_san_pham ASC
                usort($items, static function (array $a, array $b): int {
                    $discA = (int)($a['discount_percent'] ?? 0);
                    $discB = (int)($b['discount_percent'] ?? 0);
                    if ($discA !== $discB) {
                        return $discB <=> $discA;
                    }
                    return strcmp((string)$a['ma_san_pham'], (string)$b['ma_san_pham']);
                });
                break;

            case 'rating':
                // Đánh giá tốt: diem_danh_gia DESC, then so_luong_danh_gia DESC, then ma_san_pham ASC
                usort($items, static function (array $a, array $b): int {
                    $ratingA = (float)($a['diem_danh_gia'] ?? 0);
                    $ratingB = (float)($b['diem_danh_gia'] ?? 0);
                    if (abs($ratingA - $ratingB) > 0.0001) {
                        return $ratingB <=> $ratingA;
                    }
                    $cntA = (int)($a['so_luong_danh_gia'] ?? 0);
                    $cntB = (int)($b['so_luong_danh_gia'] ?? 0);
                    if ($cntA !== $cntB) {
                        return $cntB <=> $cntA;
                    }
                    return strcmp((string)$a['ma_san_pham'], (string)$b['ma_san_pham']);
                });
                break;

            case 'price_asc':
                // Giá thấp -> cao: effective_price ASC, then ma_san_pham ASC
                usort($items, static function (array $a, array $b): int {
                    $priceA = (int)($a['effective_price'] ?? 0);
                    $priceB = (int)($b['effective_price'] ?? 0);
                    if ($priceA !== $priceB) {
                        return $priceA <=> $priceB;
                    }
                    return strcmp((string)$a['ma_san_pham'], (string)$b['ma_san_pham']);
                });
                break;

            case 'price_desc':
                // Giá cao -> thấp: effective_price DESC, then ma_san_pham ASC
                usort($items, static function (array $a, array $b): int {
                    $priceA = (int)($a['effective_price'] ?? 0);
                    $priceB = (int)($b['effective_price'] ?? 0);
                    if ($priceA !== $priceB) {
                        return $priceB <=> $priceA;
                    }
                    return strcmp((string)$a['ma_san_pham'], (string)$b['ma_san_pham']);
                });
                break;

            case 'featured':
            default:
                // Ưu đãi nổi bật: deterministic baseline ranking
                $this->sortBaselineRanking($items);
                break;
        }
    }

    /**
     * Phase 2D.2: Rank campaign candidates using personalized deal ranking adapter.
     * Safely falls back to Phase 2C baseline if an error occurs.
     */
    public function rankCampaignCandidates(array $candidates, ?array $userContext): array {
        try {
            require_once __DIR__ . '/PersonalizedDealRanker.php';
            $ranker = new PersonalizedDealRanker($this->db);
            return $ranker->rankCampaignCandidates($candidates, $userContext);
        } catch (\Throwable $e) {
            error_log('FlashSaleCampaign rankCampaignCandidates error: ' . $e->getMessage());
            // Graceful fallback: return baseline sorted candidates
            $fallbackItems = $candidates;
            $this->sortBaselineRanking($fallbackItems);
            return [
                'ranked_items'    => $fallbackItems,
                'ranking_mode'    => 'BASELINE',
                'user_state'      => 'STATE_A',
                'is_personalized' => false,
            ];
        }
    }
}

