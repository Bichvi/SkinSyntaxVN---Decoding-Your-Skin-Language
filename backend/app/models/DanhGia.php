<?php

class DanhGia {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    private function getNextNumericId(string $collection, string $column): int {
        $lastDoc = $this->db->{$collection}->findOne([], ['sort' => [$column => -1]]);
        return $lastDoc ? (int)$lastDoc[$column] + 1 : 1;
    }

    private function productIdCandidates(string $productId): array {
        $productId = trim((string)$productId);
        $ids = [$productId];
        if ($productId !== '' && is_numeric($productId)) {
            $ids[] = (int)$productId;
        }
        return array_values(array_unique($ids, SORT_REGULAR));
    }

    private function productFilter(string $productId): array {
        $ids = $this->productIdCandidates($productId);
        return ['$or' => [
            ['ma_san_pham' => ['$in' => $ids]],
            ['id' => ['$in' => $ids]],
        ]];
    }

    private function orderItemProductFilter(string $productId): array {
        $ids = $this->productIdCandidates($productId);
        return ['$or' => [
            ['ma_san_pham' => ['$in' => $ids]],
            ['id_san_pham' => ['$in' => $ids]],
            ['product_id' => ['$in' => $ids]],
        ]];
    }

    private function scalarIdCandidates($value): array {
        $value = trim((string)($value ?? ''));
        if ($value === '') return [];
        $ids = [$value];
        if (is_numeric($value)) {
            $ids[] = (int)$value;
        }
        return array_values(array_unique($ids, SORT_REGULAR));
    }

    private function orderItemIdFromDetail(array $detail): string {
        foreach (['ma_chi_tiet_hoa_don', 'id_chi_tiet_hoa_don', 'idChiTietHoaDon', 'order_item_id', 'id'] as $field) {
            if (isset($detail[$field]) && trim((string)$detail[$field]) !== '') {
                return trim((string)$detail[$field]);
            }
        }
        return '';
    }

    private function orderIdFromReview(array $review): string {
        foreach (['ma_hoa_don', 'idHoaDon', 'order_id'] as $field) {
            if (isset($review[$field]) && trim((string)$review[$field]) !== '') {
                return trim((string)$review[$field]);
            }
        }
        return '';
    }

    private function orderItemIdFromReview(array $review): string {
        foreach (['ma_chi_tiet_hoa_don', 'idChiTietHoaDon', 'order_item_id'] as $field) {
            if (isset($review[$field]) && trim((string)$review[$field]) !== '') {
                return trim((string)$review[$field]);
            }
        }
        return '';
    }

    private function reviewPurchaseKey(string $orderId, string $orderItemId, string $productId): string {
        if ($orderItemId !== '') {
            return 'item:' . $orderItemId;
        }
        return 'order:' . $orderId . '|product:' . $productId;
    }

    private function visibleFilter(string $productId): array {
        return [
            '$and' => [
                $this->productFilter($productId),
                ['$or' => [
                    ['trang_thai' => ['$exists' => false]],
                    ['trang_thai' => 'hien_thi'],
                    ['trang_thai' => 'active'],
                ]],
            ],
        ];
    }

    private function normalizeOrderStatus($status): string {
        $raw = trim((string)($status ?? ''));
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
        $normalized = str_replace(['_', '-'], ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?: $normalized;
        $map = [
            'chờ xử lý' => 'pending',
            'cho xu ly' => 'pending',
            'pending' => 'pending',
            'đã xác nhận' => 'confirmed',
            'da xac nhan' => 'confirmed',
            'confirmed' => 'confirmed',
            'đang giao' => 'shipping',
            'dang giao' => 'shipping',
            'shipping' => 'shipping',
            'hoàn thành' => 'completed',
            'hoan thanh' => 'completed',
            'completed' => 'completed',
            'đã hủy' => 'cancelled',
            'da huy' => 'cancelled',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
        ];
        return $map[$normalized] ?? $normalized;
    }
    #
    private function normalizeReviewDate(array &$review, string $field = 'ngay_danh_gia'): void {
        if (!empty($review[$field]) && $review[$field] instanceof \MongoDB\BSON\UTCDateTime) {
            $review[$field] = $review[$field]->toDateTime()
                ->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))
                ->format('Y-m-d H:i:s');
        }
    }

    private function normalizeShopReply($reply, array $legacy = []): ?array {
        if (is_object($reply)) {
            $reply = (array)$reply;
        }
        if (is_array($reply) && trim((string)($reply['noi_dung'] ?? '')) !== '') {
            if (!empty($reply['ngay_phan_hoi']) && $reply['ngay_phan_hoi'] instanceof \MongoDB\BSON\UTCDateTime) {
                $reply['ngay_phan_hoi'] = $reply['ngay_phan_hoi']->toDateTime()
                    ->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))
                    ->format('Y-m-d H:i:s');
            }
            return $reply;
        }

        $content = trim((string)($legacy['phan_hoi'] ?? ''));
        if ($content === '') {
            return null;
        }
        return [
            'noi_dung' => $content,
            'ngay_phan_hoi' => $legacy['ngay_phan_hoi'] ?? null,
            'ma_nhan_vien' => $legacy['ma_nv_phan_hoi'] ?? null,
        ];
    }

    private function approximateBreakdown(float $average, int $count): array {
        $breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        if ($count <= 0 || $average <= 0) {
            return $breakdown;
        }
        $baseStar = max(1, min(5, (int)floor($average)));
        $nextStar = min(5, $baseStar + 1);
        $nextRatio = max(0, min(1, $average - $baseStar));
        $nextCount = (int)round($count * $nextRatio);
        $breakdown[$baseStar] = max(0, $count - $nextCount);
        if ($nextStar !== $baseStar) {
            $breakdown[$nextStar] += $nextCount;
        }
        return $breakdown;
    }

    public function resolveCustomerByEmail(string $email, string $defaultName = 'Khách hàng'): ?array {
        $email = trim($email);
        if ($email === '') return null;

        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
        $customer = $this->db->khach_hang->findOne(['email' => $regex]);
        if ($customer) return (array)$customer;

        $id = $this->getNextNumericId('khach_hang', 'ma_kh');
        $payload = [
            'ma_kh' => $id,
            'ho_ten' => trim($defaultName) !== '' ? $defaultName : 'Khách hàng',
            'email' => $email,
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ];
        $this->db->khach_hang->insertOne($payload);
        return $payload;
    }

    public function getReviewsByProduct(string $productId, array $filters = [], int $page = 1, int $limit = 5): array {
        $productId = trim((string)$productId);
        if ($productId === '') return [];

        $star = (int)($filters['star'] ?? 0);
        $hasImage = !empty($filters['has_image']);

        $newFilter = $this->visibleFilter($productId);
        if ($star >= 1 && $star <= 5) {
            $newFilter['$and'][] = ['so_sao' => $star];
        }
        if ($hasImage) {
            $newFilter['$and'][] = ['hinh_anh.0' => ['$exists' => true]];
        }

        $items = [];
        foreach ($this->db->danh_gia_san_pham->find($newFilter, ['sort' => ['ngay_danh_gia' => -1, 'ma_danh_gia' => -1]]) as $doc) {
            $review = (array)$doc;
            $this->normalizeReviewDate($review);
            $review['ma_khach_hang'] = $review['ma_khach_hang'] ?? ($review['ma_kh'] ?? null);
            $review['ten_khach_hang'] = $review['ten_khach_hang'] ?? 'Khách hàng';
            $review['hinh_anh'] = is_array($review['hinh_anh'] ?? null) ? $review['hinh_anh'] : [];
            $review['da_mua_hang'] = !empty($review['da_mua_hang']);
            $review['phan_hoi_shop'] = $this->normalizeShopReply($review['phan_hoi_shop'] ?? null);
            $review['_source'] = 'danh_gia_san_pham';
            $items[] = $review;
        }

        if (!$hasImage) {
            $legacyFilter = $this->productFilter($productId);
            if ($star >= 1 && $star <= 5) {
                $legacyFilter['so_sao'] = $star;
            }
            foreach ($this->db->danh_gia->find($legacyFilter, ['sort' => ['ngay_danh_gia' => -1, 'ma_danh_gia' => -1]]) as $doc) {
                $legacy = (array)$doc;
                $legacyId = (int)($legacy['ma_danh_gia'] ?? 0);
                if ($legacyId > 0 && $this->db->danh_gia_san_pham->findOne(['legacy_ma_danh_gia' => $legacyId])) {
                    continue;
                }
                $this->normalizeReviewDate($legacy);
                $items[] = [
                    'ma_danh_gia' => $legacy['ma_danh_gia'] ?? null,
                    'ma_san_pham' => (string)($legacy['ma_san_pham'] ?? $productId),
                    'ma_khach_hang' => $legacy['ma_kh'] ?? null,
                    'ten_khach_hang' => $legacy['ten_khach_hang'] ?? 'Khách hàng',
                    'so_sao' => (int)($legacy['so_sao'] ?? 0),
                    'noi_dung' => (string)($legacy['noi_dung'] ?? ''),
                    'hinh_anh' => [],
                    'ngay_danh_gia' => $legacy['ngay_danh_gia'] ?? '',
                    'da_mua_hang' => true,
                    'phan_hoi_shop' => $this->normalizeShopReply(null, $legacy),
                    'trang_thai' => 'hien_thi',
                    '_source' => 'danh_gia',
                ];
            }
        }

        usort($items, function ($a, $b) {
            return strtotime((string)($b['ngay_danh_gia'] ?? '')) <=> strtotime((string)($a['ngay_danh_gia'] ?? ''));
        });

        $page = max(1, $page);
        $limit = max(1, min(30, $limit));
        return array_slice($items, ($page - 1) * $limit, $limit);
    }

    public function getReviewStats(string $productId, array $product = []): array {
        $productId = trim((string)$productId);
        $crawlAverage = (float)($product['diem_danh_gia'] ?? 0);
        $crawlCount = max(0, (int)($product['so_luong_danh_gia'] ?? 0));
        $breakdown = $this->approximateBreakdown($crawlAverage, $crawlCount);
        $userCount = 0;
        $userSum = 0;
        $withImages = 0;

        if ($productId !== '') {
            foreach ($this->db->danh_gia_san_pham->find($this->visibleFilter($productId)) as $doc) {
                $review = (array)$doc;
                $star = max(1, min(5, (int)($review['so_sao'] ?? 0)));
                $breakdown[$star]++;
                $userCount++;
                $userSum += $star;
                if (!empty($review['hinh_anh'])) {
                    $withImages++;
                }
            }

            foreach ($this->db->danh_gia->find($this->productFilter($productId)) as $doc) {
                $review = (array)$doc;
                $legacyId = (int)($review['ma_danh_gia'] ?? 0);
                if ($legacyId > 0 && $this->db->danh_gia_san_pham->findOne(['legacy_ma_danh_gia' => $legacyId])) {
                    continue;
                }
                $star = max(1, min(5, (int)($review['so_sao'] ?? 0)));
                $breakdown[$star]++;
                $userCount++;
                $userSum += $star;
            }
        }

        $total = $crawlCount + $userCount;
        $average = $total > 0
            ? round((($crawlAverage * $crawlCount) + $userSum) / $total, 1)
            : 0.0;

        return [
            'average' => $average,
            'total' => $total,
            'breakdown' => $breakdown,
            'stars' => $breakdown,
            'user_review_count' => $userCount,
            'crawl_review_count' => $crawlCount,
            'with_images' => $withImages,
        ];
    }

    private function completedPurchasesForProduct(int $customerId, string $productId): array {
        $purchases = [];
        if ($customerId <= 0 || trim($productId) === '') return $purchases;

        $orders = [];
        $orderIds = [];
        foreach ($this->db->hoa_don->find(
            ['ma_kh' => $customerId],
            ['sort' => ['ngay_dat' => 1, 'ma_hoa_don' => 1], 'projection' => ['ma_hoa_don' => 1, 'trang_thai' => 1, 'trang_thai_normalized' => 1, 'ngay_dat' => 1]]
        ) as $orderDoc) {
            $order = (array)$orderDoc;
            if (($order['trang_thai_normalized'] ?? '') === 'completed' || $this->normalizeOrderStatus($order['trang_thai'] ?? '') === 'completed') {
                $orderId = trim((string)($order['ma_hoa_don'] ?? ''));
                if ($orderId !== '') {
                    $orders[$orderId] = $order;
                    $orderIds[] = is_numeric($orderId) ? (int)$orderId : $orderId;
                }
            }
        }

        if (empty($orderIds)) return $purchases;

        $cursor = $this->db->chi_tiet_hoa_don->find([
            '$and' => [
                ['ma_hoa_don' => ['$in' => $orderIds]],
                $this->orderItemProductFilter($productId),
            ],
        ], ['sort' => ['ma_hoa_don' => 1, 'id' => 1]]);

        foreach ($cursor as $detailDoc) {
            $detail = (array)$detailDoc;
            $orderId = trim((string)($detail['ma_hoa_don'] ?? ''));
            $itemId = $this->orderItemIdFromDetail($detail);
            $purchaseProductId = trim((string)($detail['ma_san_pham'] ?? $productId));
            $purchases[] = [
                'order_id' => $orderId,
                'ma_hoa_don' => $orderId,
                'order_item_id' => $itemId,
                'ma_chi_tiet_hoa_don' => $itemId,
                'product_id' => $purchaseProductId,
                'key' => $this->reviewPurchaseKey($orderId, $itemId, $purchaseProductId),
                'order_date' => $orders[$orderId]['ngay_dat'] ?? null,
            ];
        }

        return $purchases;
    }

    private function reviewedPurchaseKeys(int $customerId, string $productId, array $purchases): array {
        $consumed = [];
        $legacyReviews = 0;
        $visibleReviewClause = ['$or' => [
            ['trang_thai' => ['$exists' => false]],
            ['trang_thai' => 'hien_thi'],
            ['trang_thai' => 'active'],
        ]];

        $newFilter = [
            '$and' => [
                ['ma_khach_hang' => $customerId],
                $this->productFilter($productId),
                $visibleReviewClause,
            ],
        ];
        foreach ($this->db->danh_gia_san_pham->find($newFilter) as $doc) {
            $review = (array)$doc;
            $orderId = $this->orderIdFromReview($review);
            $itemId = $this->orderItemIdFromReview($review);
            if ($orderId !== '' || $itemId !== '') {
                $reviewProductId = trim((string)($review['ma_san_pham'] ?? $productId));
                $consumed[$this->reviewPurchaseKey($orderId, $itemId, $reviewProductId)] = true;
            } else {
                $legacyReviews++;
            }
        }

        foreach ($this->db->danh_gia->find([
            '$and' => [
                ['$or' => [['ma_kh' => $customerId], ['ma_khach_hang' => $customerId]]],
                $this->productFilter($productId),
            ],
        ]) as $doc) {
            $review = (array)$doc;
            $orderId = $this->orderIdFromReview($review);
            $itemId = $this->orderItemIdFromReview($review);
            if ($orderId !== '' || $itemId !== '') {
                $reviewProductId = trim((string)($review['ma_san_pham'] ?? $productId));
                $consumed[$this->reviewPurchaseKey($orderId, $itemId, $reviewProductId)] = true;
            } else {
                $legacyReviews++;
            }
        }

        // Reviews written before order linkage existed consume the oldest completed purchases first.
        foreach ($purchases as $purchase) {
            if ($legacyReviews <= 0) break;
            $key = (string)($purchase['key'] ?? '');
            if ($key !== '' && empty($consumed[$key])) {
                $consumed[$key] = true;
                $legacyReviews--;
            }
        }

        return $consumed;
    }

    public function canUserReviewProduct(int $customerId, string $productId, $preferredOrderId = null, $preferredOrderItemId = null): array {
        $productId = trim((string)$productId);
        $result = [
            'has_purchased' => false,
            'has_reviewed' => false,
            'order_id' => '',
            'order_item_id' => '',
            'message' => 'Chỉ đơn Hoàn thành mới được đánh giá.',
        ];
        if ($customerId <= 0 || $productId === '') return $result;

        $purchases = $this->completedPurchasesForProduct($customerId, $productId);
        $result['has_purchased'] = !empty($purchases);
        if (empty($purchases)) {
            return $result;
        }

        $consumed = $this->reviewedPurchaseKeys($customerId, $productId, $purchases);
        $preferredOrderId = trim((string)($preferredOrderId ?? ''));
        $preferredOrderItemId = trim((string)($preferredOrderItemId ?? ''));
        $hasPreferredPurchase = $preferredOrderId !== '' || $preferredOrderItemId !== '';
        $eligible = null;

        foreach ($purchases as $purchase) {
            $key = (string)($purchase['key'] ?? '');
            if ($key === '' || !empty($consumed[$key])) {
                continue;
            }
            $matchesPreferred = ($preferredOrderId === '' || $preferredOrderId === (string)($purchase['order_id'] ?? ''))
                && ($preferredOrderItemId === '' || $preferredOrderItemId === (string)($purchase['order_item_id'] ?? ''));
            if ($matchesPreferred) {
                $eligible = $purchase;
                break;
            }
            if (!$hasPreferredPurchase && $eligible === null) {
                $eligible = $purchase;
            }
        }

        if ($eligible === null) {
            $result['has_reviewed'] = true;
            $result['message'] = 'Bạn đã đánh giá tất cả các lần mua của sản phẩm này.';
            return $result;
        }

        $result['has_reviewed'] = false;
        $result['order_id'] = (string)($eligible['order_id'] ?? '');
        $result['ma_hoa_don'] = $result['order_id'];
        $result['order_item_id'] = (string)($eligible['order_item_id'] ?? '');
        $result['ma_chi_tiet_hoa_don'] = $result['order_item_id'];
        $result['message'] = '';
        return $result;
    }

    public function addReview(string $productId, int $customerId, array $data): array {
        $productId = trim((string)$productId);
        $content = trim((string)($data['noi_dung'] ?? ''));
        $stars = max(1, min(5, (int)($data['so_sao'] ?? 5)));
        if ($productId === '' || $customerId <= 0 || $content === '') {
            return ['ok' => false, 'message' => 'Vui lòng nhập đầy đủ nội dung đánh giá.'];
        }

        $preferredOrderId = trim((string)($data['ma_hoa_don'] ?? $data['order_id'] ?? ''));
        $preferredOrderItemId = trim((string)($data['ma_chi_tiet_hoa_don'] ?? $data['order_item_id'] ?? ''));
        $eligibility = $this->canUserReviewProduct($customerId, $productId, $preferredOrderId, $preferredOrderItemId);
        if (empty($eligibility['has_purchased'])) {
            return ['ok' => false, 'message' => 'Chỉ đơn Hoàn thành mới được đánh giá.'];
            return ['ok' => false, 'message' => 'Bạn chỉ có thể đánh giá sản phẩm sau khi đơn hàng đã hoàn thành.'];
        }
        if (!empty($eligibility['has_reviewed'])) {
            return ['ok' => false, 'message' => 'Bạn đã đánh giá tất cả các lần mua của sản phẩm này.'];
            return ['ok' => false, 'message' => 'Bạn đã đánh giá sản phẩm này rồi.'];
        }

        $customer = $this->db->khach_hang->findOne(['ma_kh' => $customerId]);
        $orderId = trim((string)($eligibility['order_id'] ?? $eligibility['ma_hoa_don'] ?? ''));
        $orderItemId = trim((string)($eligibility['order_item_id'] ?? $eligibility['ma_chi_tiet_hoa_don'] ?? ''));
        $payload = [
            'ma_danh_gia' => $this->getNextNumericId('danh_gia_san_pham', 'ma_danh_gia'),
            'ma_san_pham' => (string)$productId,
            'ma_khach_hang' => $customerId,
            'ten_khach_hang' => $customer['ho_ten'] ?? 'Khách hàng',
            'ma_hoa_don' => is_numeric($orderId) ? (int)$orderId : $orderId,
            'order_id' => is_numeric($orderId) ? (int)$orderId : $orderId,
            'ma_chi_tiet_hoa_don' => ($orderItemId !== '' && is_numeric($orderItemId)) ? (int)$orderItemId : $orderItemId,
            'order_item_id' => ($orderItemId !== '' && is_numeric($orderItemId)) ? (int)$orderItemId : $orderItemId,
            'so_sao' => $stars,
            'noi_dung' => $content,
            'hinh_anh' => array_values(array_filter((array)($data['hinh_anh'] ?? []))),
            'ngay_danh_gia' => new \MongoDB\BSON\UTCDateTime(),
            'da_mua_hang' => true,
            'phan_hoi_shop' => null,
            'trang_thai' => 'hien_thi',
        ];

        try {
            $this->db->danh_gia_san_pham->createIndex(
                ['ma_khach_hang' => 1, 'ma_chi_tiet_hoa_don' => 1, 'ma_san_pham' => 1],
                ['unique' => true, 'sparse' => true, 'name' => 'uniq_customer_order_item_product_review']
            );
            $this->db->danh_gia_san_pham->createIndex(
                ['ma_khach_hang' => 1, 'ma_hoa_don' => 1, 'ma_san_pham' => 1],
                ['unique' => true, 'sparse' => true, 'name' => 'uniq_customer_order_product_review']
            );
        } catch (Throwable $e) {
            error_log('review unique index warning: ' . $e->getMessage());
        }

        // Chi tiet review nam trong danh_gia_san_pham; san_pham giu rating crawl goc.
        $this->db->danh_gia_san_pham->insertOne($payload);
        $this->createReviewNotification($payload);
        return ['ok' => true, 'message' => 'Đã gửi đánh giá sản phẩm.'];
    }

    private function createReviewNotification(array $review): void {
        try {
            $product = $this->db->san_pham->findOne($this->productFilter((string)($review['ma_san_pham'] ?? '')));
            $productName = $product['ten_san_pham'] ?? ('#' . ($review['ma_san_pham'] ?? ''));
            $customerName = (string)($review['ten_khach_hang'] ?? 'Khách hàng');
            $stars = (int)($review['so_sao'] ?? 0);
            $now = new \MongoDB\BSON\UTCDateTime();
            $this->db->thong_bao->insertOne([
                'ma_thong_bao' => $this->getNextNumericId('thong_bao', 'ma_thong_bao'),
                'loai' => 'review',
                'ma_san_pham' => (string)($review['ma_san_pham'] ?? ''),
                'ma_danh_gia' => (int)($review['ma_danh_gia'] ?? 0),
                'tieu_de' => 'Đánh giá sản phẩm mới',
                'noi_dung' => $customerName . ' vừa đánh giá ' . $stars . ' sao cho sản phẩm ' . $productName . '.',
                'da_doc' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            error_log('review notification error: ' . $e->getMessage());
        }
    }
}
