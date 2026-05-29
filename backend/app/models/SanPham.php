<?php
// backend/app/models/SanPham.php

class SanPham {
    private $db;
    private ?string $lastErrorMessage = null;
    private const VI_ACCENTS = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ';
    private const VI_ASCII = 'aaaaaaaaaaaaaaaaaeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyyd';

    public function __construct($db) {
        $this->db = $db;
    }

    public function getLastErrorMessage(): ?string {
        return $this->lastErrorMessage;
    }

    private function setError(?string $message): void {
        $this->lastErrorMessage = $message;
    }

    private function normalizeProductVisibilityStatus(?string $status): string {
        $normalized = strtolower(trim((string)($status ?? '')));
        if (in_array($normalized, ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0'], true)) {
            return 'inactive';
        }
        return 'active';
    }

    private function visibleProductFilter(): array {
        return ['trang_thai' => ['$nin' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']]];
    }

    private function availableProductFilter(): array {
        // Product discovery still shows out-of-stock products, but UI/backend disables buying them.
        return $this->visibleProductFilter();
    }

    public function getProductStock(array $product): ?int {
        foreach (['so_luong_ton_kho', 'so_luong_ton', 'ton_kho', 'stock', 'quantity'] as $field) {
            if (array_key_exists($field, $product) && $product[$field] !== null && $product[$field] !== '') {
                return max(0, (int)$product[$field]);
            }
        }
        return null;
    }

    public function isProductAvailable($product): bool {
        if (!$product) return false;
        $p = (array)$product;
        if ($this->normalizeProductVisibilityStatus((string)($p['trang_thai'] ?? $p['status'] ?? 'active')) !== 'active') {
            return false;
        }
        if (strtolower(trim((string)($p['trang_thai_kho'] ?? ''))) === 'het_hang') {
            return false;
        }
        $stock = $this->getProductStock($p);
        return $stock === null || $stock > 0;
    }

    private function normalizeProductRecord($product): array {
        if (!$product) return [];
        $p = (array) $product;

        // Xử lý alias _id thành id nếu cần
        if (isset($p['ma_san_pham'])) {
            $p['id'] = (string) $p['ma_san_pham'];
        }

        // Ghép tên thương hiệu / danh mục từ các collection phụ (Giả lập JOIN)
        if (isset($p['ma_thuong_hieu'])) {
            $brand = $this->db->thuong_hieu->findOne(['ma_thuong_hieu' => $p['ma_thuong_hieu']]);
            $p['thuong_hieu'] = $brand ? $brand['ten_thuong_hieu'] : '';
        }

        if (isset($p['ma_danh_muc'])) {
            $cat = $this->db->danh_muc->findOne(['ma_danh_muc' => $p['ma_danh_muc']]);
            $p['loai_san_pham'] = $cat ? $cat['ten_danh_muc'] : '';
            if (empty($p['danh_muc_day_du'])) {
                $p['danh_muc_day_du'] = $p['loai_san_pham'];
            }
        }

        if (isset($p['ma_xuat_xu'])) {
            $origin = $this->db->xuat_xu->findOne(['ma_xuat_xu' => $p['ma_xuat_xu']]);
            $p['xuat_xu_thuong_hieu'] = $origin ? $origin['ten_xuat_xu'] : '';
        }

        // Chuẩn hóa một số field hay dùng
        if (empty($p['link_hinh_anh']) && !empty($p['hinh_anh'])) {
            $p['link_hinh_anh'] = $p['hinh_anh'];
        }
        if (empty($p['thanh_phan_chinh']) && !empty($p['thanh_phan'])) {
            $p['thanh_phan_chinh'] = $p['thanh_phan'];
        }

        $rawStatus = $p['trang_thai'] ?? $p['status'] ?? 'active';
        $normalizedStatus = $this->normalizeProductVisibilityStatus((string)$rawStatus);
        $p['trang_thai'] = $normalizedStatus;
        $p['status'] = $normalizedStatus;
        $stock = $this->getProductStock($p);
        $p['ton_kho_hien_thi'] = $stock;
        if ($stock !== null) {
            $p['so_luong_ton_kho'] = $stock;
            $p['trang_thai_kho'] = $stock > 0 ? 'con_hang' : 'het_hang';
        }
        $p['is_available'] = $this->isProductAvailable($p);

        return $p;
    }

    public function buildProductIdQuery($productId): array {
        $productId = trim((string)($productId ?? ''));
        $or = [
            ['ma_san_pham' => $productId],
            ['id' => $productId],
        ];
        if ($productId !== '' && is_numeric($productId)) {
            $or[] = ['ma_san_pham' => (int)$productId];
            $or[] = ['id' => (int)$productId];
        }
        if ($productId !== '' && preg_match('/^[a-f0-9]{24}$/i', $productId)) {
            try {
                $or[] = ['_id' => new \MongoDB\BSON\ObjectId($productId)];
            } catch (Throwable $e) {
                // Ignore invalid ObjectId strings; ma_san_pham/id lookup still applies.
            }
        }
        return ['$or' => $or];
    }

    private function productFlexibleFilter($productId): array {
        return $this->buildProductIdQuery($productId);
    }

    public function getProductBriefById($productId): array {
        $product = $this->db->san_pham->findOne($this->productFlexibleFilter($productId));
        if (!$product) return [];
        $p = $this->normalizeProductRecord($product);
        return [
            'id' => (string)($p['id'] ?? $p['ma_san_pham'] ?? $productId),
            'ma_san_pham' => (string)($p['ma_san_pham'] ?? $p['id'] ?? $productId),
            'ten_san_pham' => (string)($p['ten_san_pham'] ?? ''),
            'thuong_hieu' => (string)($p['thuong_hieu'] ?? ''),
            'gia_ban' => (int)($p['gia_ban'] ?? 0),
            'link_hinh_anh' => (string)($p['link_hinh_anh'] ?? ''),
            'loai_san_pham' => (string)($p['loai_san_pham'] ?? ''),
            'danh_muc_day_du' => (string)($p['danh_muc_day_du'] ?? ''),
            'so_luong_ton_kho' => $p['so_luong_ton_kho'] ?? null,
            'trang_thai_kho' => (string)($p['trang_thai_kho'] ?? ''),
        ];
    }

    // Helper tạo Regex tìm kiếm không phân biệt hoa thường và hỗ trợ tiếng Việt cơ bản
    private function buildSearchRegex(string $q): \MongoDB\BSON\Regex {
        $q = preg_quote(trim($q));
        return new \MongoDB\BSON\Regex($q, 'i');
    }

    public function latest(int $limit = 8, bool $onlyVisibleOnWebsite = false): array {
        $filter = [];
        if ($onlyVisibleOnWebsite) {
            $filter = $this->availableProductFilter();
        }

        $options = [
            'sort' => ['ngay_tao' => -1, 'ma_san_pham' => -1],
            'limit' => $limit
        ];

        $cursor = $this->db->san_pham->find($filter, $options);
        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeProductRecord($doc);
        }
        return $items;
    }

    public function find($id, bool $onlyVisibleOnWebsite = false) {
        $filter = $this->productFlexibleFilter($id);
        
        if ($onlyVisibleOnWebsite) {
            $filter = ['$and' => [$filter, $this->visibleProductFilter()]];
        }

        $product = $this->db->san_pham->findOne($filter);

        if (!$product) {
            return false;
        }

        return $this->normalizeProductRecord($product);
    }

    public function findById($id, bool $onlyVisibleOnWebsite = false) {
        return $this->find($id, $onlyVisibleOnWebsite);
    }

    public function updateProductVisibility(string $id, string $status): bool {
        $this->setError(null);
        $id = trim($id);
        if ($id === '') {
            $this->setError('Thieu ma san pham can cap nhat.');
            return false;
        }

        $normalizedStatus = $this->normalizeProductVisibilityStatus($status);
        $payload = [
            'trang_thai' => $normalizedStatus,
            'status' => $normalizedStatus,
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        try {
            foreach ($this->productIdentityFilters($id) as $filter) {
                $result = $this->db->san_pham->updateOne($filter, ['$set' => $payload]);
                if ($result->getMatchedCount() > 0) {
                    return true;
                }
            }
            $this->setError('Khong tim thay san pham can cap nhat.');
            return false;
        } catch (Throwable $e) {
            $this->setError('Loi cap nhat trang thai san pham: ' . $e->getMessage());
            return false;
        }
    }

    public function tangLuotXem(string $id): void {
        $filter = ['ma_san_pham' => $id];
        $update = ['$inc' => ['luot_xem' => 1]];
        
        $result = $this->db->san_pham->updateOne($filter, $update);
        if ($result->getModifiedCount() === 0 && is_numeric($id)) {
            $this->db->san_pham->updateOne(['ma_san_pham' => (int) $id], $update);
        }
    }

    public function menuTree(int $cap2LimitEach = 14): array {
        // Trong MongoDB, aggregate để group danh mục
        $pipeline = [
            ['$match' => ['danh_muc_day_du' => ['$ne' => null, '$not' => new \MongoDB\BSON\Regex('^$')]]],
            ['$group' => [
                '_id' => '$danh_muc_day_du',
                'so_luong' => ['$sum' => 1]
            ]],
            ['$sort' => ['so_luong' => -1]]
        ];

        $cursor = $this->db->san_pham->aggregate($pipeline);
        $tree = [];

        foreach ($cursor as $doc) {
            $fullPath = (string) $doc['_id'];
            $count = (int) $doc['so_luong'];

            // Cắt chuỗi giống hàm cap1Expr / cap2Expr cũ
            $parts = explode(' -> ', $fullPath);
            if (strpos($fullPath, 'Sức Khỏe - Làm Đẹp -> ') === 0) {
                $c1 = trim($parts[1] ?? '');
                $c2 = trim($parts[2] ?? '');
            } else {
                $c1 = trim($parts[0] ?? '');
                $c2 = trim($parts[1] ?? '');
            }

            if (!$c1) continue;
            if (!isset($tree[$c1])) $tree[$c1] = [];
            
            if ($c2 && count($tree[$c1]) < $cap2LimitEach) {
                $tree[$c1][$c2] = ($tree[$c1][$c2] ?? 0) + $count;
            }
        }
        return $tree;
    }

    public function paginate(int $page, int $perPage, string $q = '', string $cap1Val = '', string $cap2Val = '', string $statusFilter = '', bool $onlyVisibleOnWebsite = false, string $stockStatusFilter = ''): array {
        $page = max(1, $page);
        $skip = ($page - 1) * $perPage;
        
        $filter = [];

        if (trim($q) !== '') {
            $regex = $this->buildSearchRegex($q);
            $filter['$or'] = [
                ['ten_san_pham' => $regex],
                ['ma_san_pham' => $regex],
                ['thuong_hieu' => $regex],
                ['danh_muc_day_du' => $regex],
                ['loai_san_pham' => $regex],
                ['barcode' => $regex],
            ];
            if (is_numeric(trim($q))) {
                $filter['$or'][] = ['ma_san_pham' => (int)trim($q)];
            }
        }

        if ($cap1Val !== '' || $cap2Val !== '') {
            $searchCat = trim($cap1Val . ' -> ' . $cap2Val, ' -> ');
            $filter['danh_muc_day_du'] = $this->buildSearchRegex($searchCat);
        }

        if ($onlyVisibleOnWebsite) {
            $filter = empty($filter) ? $this->availableProductFilter() : ['$and' => [$filter, $this->availableProductFilter()]];
        } elseif (in_array(strtolower(trim($statusFilter)), ['active', 'inactive'])) {
            if ($statusFilter === 'active') {
                $filter['trang_thai'] = ['$nin' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']];
            } else {
                $filter['trang_thai'] = ['$in' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']];
            }
        }

        $stockStatusFilter = strtolower(trim($stockStatusFilter));
        if (in_array($stockStatusFilter, ['con_hang', 'het_hang'], true)) {
            $stockFilter = $stockStatusFilter === 'con_hang'
                ? ['$or' => [['so_luong_ton_kho' => ['$gt' => 0]], ['trang_thai_kho' => 'con_hang']]]
                : ['$or' => [['so_luong_ton_kho' => ['$lte' => 0]], ['trang_thai_kho' => 'het_hang']]];
            $filter = empty($filter) ? $stockFilter : ['$and' => [$filter, $stockFilter]];
        }

        $total = $this->db->san_pham->countDocuments($filter);
        
        $options = [
            'sort' => ['ma_san_pham' => -1],
            'skip' => $skip,
            'limit' => $perPage
        ];

        $cursor = $this->db->san_pham->find($filter, $options);
        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeProductRecord($doc);
        }

        return ['items' => $items, 'total' => $total];
    }

    public function searchSuggestions(string $q, int $limit = 8, bool $onlyVisibleOnWebsite = false): array {
        $limit = max(1, min(20, $limit));
        $q = trim($q);
        if ($q === '') return [];

        $filter = [];
        $regex = $this->buildSearchRegex($q);
        $filter['$or'] = [
            ['ten_san_pham' => $regex],
            ['ma_san_pham' => $regex],
            ['thanh_phan_chinh' => $regex]
        ];

        if ($onlyVisibleOnWebsite) {
            $filter = ['$and' => [$filter, $this->availableProductFilter()]];
        }

        $options = [
            'sort' => ['ten_san_pham' => 1],
            'limit' => $limit
        ];

        $cursor = $this->db->san_pham->find($filter, $options);
        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeProductRecord($doc);
        }
        return $items;
    }

    public function getTopTrending(int $limit = 5, bool $onlyVisibleOnWebsite = false): array {
        $limit = max(1, min(20, $limit));
        $filter = [];
        if ($onlyVisibleOnWebsite) {
            $filter = $this->availableProductFilter();
        }

        $options = [
            'sort' => ['luot_xem' => -1, 'so_luong_danh_gia' => -1, 'ten_san_pham' => 1],
            'limit' => $limit
        ];

        $cursor = $this->db->san_pham->find($filter, $options);
        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeProductRecord($doc);
        }
        return $items;
    }

    public function searchLive(string $q, int $limit = 5, bool $onlyVisibleOnWebsite = false): array {
        return $this->searchSuggestions($q, $limit, $onlyVisibleOnWebsite);
    }

    private function findHomepageProducts(array $extraFilter, array $sort, int $limit): array {
        $limit = max(1, min(24, $limit));
        $filter = $this->availableProductFilter();
        if (!empty($extraFilter)) {
            $filter = ['$and' => [$filter, $extraFilter]];
        }

        $cursor = $this->db->san_pham->find($filter, ['sort' => $sort, 'limit' => $limit]);
        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeProductRecord($doc);
        }
        return $items;
    }

    public function getHomepageProductSections(int $limitEach = 4): array {
        $limitEach = max(4, min(12, $limitEach));

        return [
            'flashDeals' => $this->getFlashSaleProducts($limitEach),
            'bestSellers' => $this->findHomepageProducts([], ['so_luong_ban' => -1, 'luot_mua' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1], $limitEach),
            'topSearches' => $this->findHomepageProducts([], ['luot_xem' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1], $limitEach),
            'forYou' => $this->findHomepageProducts([], ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ngay_tao' => -1], $limitEach),
        ];
    }

    public function getFlashSaleProducts(int $limit = 8): array {
        $discountFilter = [
            '$or' => [
                ['phan_tram_giam' => ['$gt' => 0]],
                ['tien_tiet_kiem' => ['$gt' => 0]],
            ],
        ];

        return $this->findHomepageProducts(
            $discountFilter,
            ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1],
            max(1, min(24, $limit))
        );
    }

    private function normalizeDiscoveryArgs($filters = [], int $limit = 6, ?string $sort = null): array {
        if (is_int($filters)) {
            return [[], max(1, min(48, $filters)), $sort];
        }

        if (!is_array($filters)) {
            $filters = [];
        }

        return [$filters, max(1, min(48, $limit)), $sort];
    }

    public function buildProductFilters(array $request): array {
        $parts = [$this->availableProductFilter()];

        $keyword = trim((string)($request['keyword'] ?? $request['q'] ?? ''));
        if ($keyword !== '') {
            $regex = $this->buildSearchRegex($keyword);
            $parts[] = ['$or' => [
                ['ten_san_pham' => $regex],
                ['thuong_hieu' => $regex],
                ['danh_muc_day_du' => $regex],
                ['loai_san_pham' => $regex],
                ['thanh_phan_sach' => $regex],
                ['thanh_phan_chinh' => $regex],
                ['thanh_phan_day_du' => $regex],
                ['loai_da' => $regex],
                ['mo_ta' => $regex],
                ['mo_ta_san_pham' => $regex],
            ]];
        }

        $category = trim((string)($request['danh_muc'] ?? $request['category'] ?? ''));
        if ($category !== '') {
            $regex = $this->buildSearchRegex($category);
            $parts[] = ['$or' => [
                ['danh_muc_day_du' => $regex],
                ['loai_san_pham' => $regex],
            ]];
        }

        $brand = trim((string)($request['thuong_hieu'] ?? $request['brand'] ?? ''));
        if ($brand !== '') {
            $regex = $this->exactTextRegex($brand);
            $brandConditions = [
                ['thuong_hieu' => $regex],
                ['ten_thuong_hieu' => $regex],
            ];
            $brandDoc = $this->db->thuong_hieu->findOne(['ten_thuong_hieu' => $regex], ['projection' => ['ma_thuong_hieu' => 1]]);
            if ($brandDoc && isset($brandDoc['ma_thuong_hieu'])) {
                $brandConditions[] = ['ma_thuong_hieu' => $brandDoc['ma_thuong_hieu']];
                $brandConditions[] = ['ma_thuong_hieu' => (string)$brandDoc['ma_thuong_hieu']];
            }
            $parts[] = ['$or' => $brandConditions];
        }

        $price = [];
        $min = preg_replace('/[^\d]/', '', (string)($request['gia_tu'] ?? $request['price_min'] ?? ''));
        $max = preg_replace('/[^\d]/', '', (string)($request['gia_den'] ?? $request['price_max'] ?? ''));
        if ($min !== '') $price['$gte'] = (int)$min;
        if ($max !== '') $price['$lte'] = (int)$max;
        if (!empty($price)) {
            $parts[] = ['gia_ban' => $price];
        }

        return count($parts) === 1 ? $parts[0] : ['$and' => $parts];
    }

    public function buildProductSort(?string $sort, array $defaultSort): array {
        $sort = trim((string)$sort);
        if ($sort === '' || $sort === 'default') {
            return $defaultSort;
        }

        $sortMap = [
            'price_asc' => ['gia_ban' => 1, 'ma_san_pham' => -1],
            'price_desc' => ['gia_ban' => -1, 'ma_san_pham' => -1],
            'best_seller' => ['so_luong_da_ban' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1],
            'top_rated' => ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1],
            'discount' => ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1],
            'newest' => ['ngay_tao' => -1, 'ma_san_pham' => -1],
            'most_viewed' => ['luot_xem' => -1, 'ma_san_pham' => -1],
        ];

        return $sortMap[$sort] ?? $defaultSort;
    }

    private function findDiscoveryProducts(array $baseFilter, array $filters, array $defaultSort, int $limit, ?string $sort = null): array {
        $filter = $this->buildProductFilters($filters);
        if (!empty($baseFilter)) {
            $filter = ['$and' => [$filter, $baseFilter]];
        }

        $items = [];
        $cursor = $this->db->san_pham->find($filter, [
            'sort' => $this->buildProductSort($sort ?? (string)($filters['sort'] ?? ''), $defaultSort),
            'limit' => max(1, min(48, $limit)),
        ]);
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeProductRecord($doc);
        }
        return $items;
    }

    private function discountDiscoveryFilter(): array {
        return ['$or' => [
            ['phan_tram_giam' => ['$gt' => 0]],
            ['$expr' => ['$gt' => ['$gia_thi_truong', '$gia_ban']]],
        ]];
    }

    public function getBestSellerProducts($filters = [], int $limit = 6, ?string $sort = null): array {
        [$filters, $limit, $sort] = $this->normalizeDiscoveryArgs($filters, $limit, $sort);
        return $this->findDiscoveryProducts([], $filters, ['so_luong_da_ban' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1], $limit, $sort);
    }

    public function getTopRatedProducts($filters = [], int $limit = 6, ?string $sort = null): array {
        [$filters, $limit, $sort] = $this->normalizeDiscoveryArgs($filters, $limit, $sort);
        return $this->findDiscoveryProducts([], $filters, ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1], $limit, $sort);
    }

    public function getHighRatingProducts($filters = [], int $limit = 6, ?string $sort = null): array {
        return $this->getTopRatedProducts($filters, $limit, $sort);
    }

    public function getDiscountProducts($filters = [], int $limit = 6, ?string $sort = null): array {
        [$filters, $limit, $sort] = $this->normalizeDiscoveryArgs($filters, $limit, $sort);
        return $this->findDiscoveryProducts($this->discountDiscoveryFilter(), $filters, ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1], $limit, $sort);
    }

    public function getMostViewedProducts($filters = [], int $limit = 6, ?string $sort = null): array {
        [$filters, $limit, $sort] = $this->normalizeDiscoveryArgs($filters, $limit, $sort);
        return $this->findDiscoveryProducts([], $filters, ['luot_xem' => -1, 'ma_san_pham' => -1], $limit, $sort);
    }

    public function getPopularProducts($filters = [], int $limit = 6, ?string $sort = null): array {
        return $this->getMostViewedProducts($filters, $limit, $sort);
    }

    public function getNewProducts($filters = [], int $limit = 6, ?string $sort = null): array {
        [$filters, $limit, $sort] = $this->normalizeDiscoveryArgs($filters, $limit, $sort);
        return $this->findDiscoveryProducts([], $filters, ['ngay_tao' => -1, 'ma_san_pham' => -1], $limit, $sort);
    }

    public function getProductsByType(string $type, array $filters = [], int $page = 1, int $perPage = 24): array {
        $page = max(1, $page);
        $perPage = max(1, min(60, $perPage));
        $type = trim($type);

        $baseFilter = $this->availableProductFilter();
        $sort = ['ma_san_pham' => -1];

        switch ($type) {
            case 'flash-sale':
            case 'discount':
                $baseFilter = ['$and' => [
                    $baseFilter,
                    ['$or' => [
                        ['phan_tram_giam' => ['$gt' => 0]],
                        ['tien_tiet_kiem' => ['$gt' => 0]],
                    ]],
                ]];
                $sort = ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1];
                break;
            case 'best-seller':
                $sort = ['so_luong_ban' => -1, 'luot_mua' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1];
                break;
            case 'high-rating':
                $sort = ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1];
                break;
            case 'popular':
                $sort = ['so_luong_danh_gia' => -1, 'diem_danh_gia' => -1, 'luot_xem' => -1, 'ma_san_pham' => -1];
                break;
            case 'new':
                $sort = ['ngay_tao' => -1, 'ma_san_pham' => -1];
                break;
            default:
                return $this->paginate($page, $perPage, (string)($filters['q'] ?? ''), (string)($filters['cap1'] ?? ''), (string)($filters['cap2'] ?? ''), '', true);
        }

        $options = [
            'sort' => $sort,
            'skip' => ($page - 1) * $perPage,
            'limit' => $perPage,
        ];

        $items = [];
        $cursor = $this->db->san_pham->find($baseFilter, $options);
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeProductRecord($doc);
        }

        return [
            'items' => $items,
            'total' => $this->db->san_pham->countDocuments($baseFilter),
        ];
    }

    public function getCollectionProducts(string $type, array $filters = [], int $page = 1, int $perPage = 20, ?string $sort = null): array {
        $page = max(1, $page);
        $perPage = max(1, min(60, $perPage));
        $type = trim($type);

        $baseFilter = [];
        $defaultSort = ['so_luong_da_ban' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1];

        switch ($type) {
            case 'top_rated':
                $defaultSort = ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1];
                break;
            case 'discount':
                $baseFilter = $this->discountDiscoveryFilter();
                $defaultSort = ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1];
                break;
            case 'most_viewed':
                $defaultSort = ['luot_xem' => -1, 'ma_san_pham' => -1];
                break;
            case 'new':
                $defaultSort = ['ngay_tao' => -1, 'ma_san_pham' => -1];
                break;
            case 'best_seller':
            default:
                $type = 'best_seller';
                break;
        }

        $filter = $this->buildProductFilters($filters);
        if (!empty($baseFilter)) {
            $filter = ['$and' => [$filter, $baseFilter]];
        }

        $total = $this->db->san_pham->countDocuments($filter);
        $cursor = $this->db->san_pham->find($filter, [
            'sort' => $this->buildProductSort($sort ?? (string)($filters['sort'] ?? ''), $defaultSort),
            'skip' => ($page - 1) * $perPage,
            'limit' => $perPage,
        ]);

        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeProductRecord($doc);
        }

        return [
            'type' => $type,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => (int)max(1, ceil($total / $perPage)),
        ];
    }

    public function publicRecommendationDiscovery(array $params, int $limit = 24): array {
        return $this->searchProducts($params, trim((string)($params['sort'] ?? 'popular')), 1, $limit)['items'];
    }

    public function searchProducts(array $filters, string $sort = 'popular', int $page = 1, int $limit = 24): array {
        $page = max(1, $page);
        $limit = max(4, min(48, $limit));
        $filterParts = [];

        $keyword = trim((string)($filters['keyword'] ?? $filters['q'] ?? ''));
        if ($keyword !== '') {
            $regex = $this->buildSearchRegex($keyword);
            $brandIds = [];
            $brandCursor = $this->db->thuong_hieu->find(['ten_thuong_hieu' => $regex], ['projection' => ['ma_thuong_hieu' => 1], 'limit' => 20]);
            foreach ($brandCursor as $brandDoc) {
                if (isset($brandDoc['ma_thuong_hieu'])) {
                    $brandIds[] = $brandDoc['ma_thuong_hieu'];
                }
            }

            $filterParts[] = [
                '$or' => [
                    ['ten_san_pham' => $regex],
                    ['thuong_hieu' => $regex],
                    ['loai_san_pham' => $regex],
                    ['danh_muc_day_du' => $regex],
                    ['loai_da' => $regex],
                    ['thanh_phan' => $regex],
                    ['thanh_phan_chinh' => $regex],
                    ['thanh_phan_day_du' => $regex],
                    ['mo_ta' => $regex],
                    ['mo_ta_san_pham' => $regex],
                    ['ma_thuong_hieu' => ['$in' => array_values(array_unique($brandIds))]],
                ],
            ];
        }

        $priceFilter = [];
        $priceMin = preg_replace('/[^\d]/', '', (string)($filters['price_min'] ?? ''));
        $priceMax = preg_replace('/[^\d]/', '', (string)($filters['price_max'] ?? ''));
        if ($priceMin !== '') $priceFilter['$gte'] = (int)$priceMin;
        if ($priceMax !== '') $priceFilter['$lte'] = (int)$priceMax;
        if (!empty($priceFilter)) {
            $filterParts[] = ['gia_ban' => $priceFilter];
        }

        $category = trim((string)($filters['category'] ?? ''));
        if ($category !== '') {
            $categoryRegex = $this->buildSearchRegex($category);
            $filterParts[] = ['$or' => [
                ['danh_muc_day_du' => $categoryRegex],
                ['loai_san_pham' => $categoryRegex],
            ]];
        }

        $brand = trim((string)($filters['brand'] ?? ''));
        if ($brand !== '') {
            $brandDoc = $this->db->thuong_hieu->findOne(['ten_thuong_hieu' => $this->exactTextRegex($brand)]);
            if ($brandDoc && isset($brandDoc['ma_thuong_hieu'])) {
                $filterParts[] = ['$or' => [
                    ['ma_thuong_hieu' => $brandDoc['ma_thuong_hieu']],
                    ['thuong_hieu' => $this->exactTextRegex($brand)],
                ]];
            } else {
                $filterParts[] = ['thuong_hieu' => $this->buildSearchRegex($brand)];
            }
        }

        $filter = $this->availableProductFilter();
        if (!empty($filterParts)) {
            $filter = ['$and' => array_merge([$filter], $filterParts)];
        }

        $sortKey = trim($sort !== '' ? $sort : (string)($filters['sort'] ?? 'popular'));
        $sortMap = [
            'best_seller' => ['so_luong_ban' => -1, 'luot_mua' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1],
            'top_rated' => ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1],
            'discount' => ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1],
            'price_asc' => ['gia_ban' => 1, 'ma_san_pham' => -1],
            'price_desc' => ['gia_ban' => -1, 'ma_san_pham' => -1],
            'popular' => ['so_luong_danh_gia' => -1, 'diem_danh_gia' => -1, 'luot_xem' => -1, 'ma_san_pham' => -1],
        ];

        $cursor = $this->db->san_pham->find($filter, [
            'sort' => $sortMap[$sortKey] ?? $sortMap['popular'],
            'skip' => ($page - 1) * $limit,
            'limit' => $limit,
        ]);

        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeProductRecord($doc);
        }

        return [
            'items' => $items,
            'total' => $this->db->san_pham->countDocuments($filter),
        ];
    }

    public function publicRecommendationSections(int $limitEach = 6, array $filters = [], ?string $sort = null): array {
        $limitEach = max(1, min(16, $limitEach));
        return [
            'best_seller' => $this->getBestSellerProducts($filters, $limitEach, $sort),
            'top_rated' => $this->getTopRatedProducts($filters, $limitEach, $sort),
            'discount' => $this->getDiscountProducts($filters, $limitEach, $sort),
            'most_viewed' => $this->getMostViewedProducts($filters, $limitEach, $sort),
            'new' => $this->getNewProducts($filters, $limitEach, $sort),
        ];
    }

    private function parseLookupLabel(string $label): array {
        $label = trim($label);
        $id = null;
        if (preg_match('/\(#\s*([^)]+)\)\s*$/', $label, $matches)) {
            $id = trim((string)$matches[1]);
            $label = trim((string)preg_replace('/\s*\(#\s*[^)]+\)\s*$/', '', $label));
        }
        return [$label, $id];
    }

    private function nextNumericCode(string $collectionName, string $fieldName, int $startAt = 1): int {
        $max = $startAt - 1;
        $cursor = $this->db->{$collectionName}->find([], ['projection' => [$fieldName => 1]]);
        foreach ($cursor as $doc) {
            $value = $doc[$fieldName] ?? null;
            if (is_numeric($value)) {
                $max = max($max, (int)$value);
            }
        }
        return $max + 1;
    }

    private function exactTextRegex(string $value): \MongoDB\BSON\Regex {
        return new \MongoDB\BSON\Regex('^' . preg_quote(trim($value), '/') . '$', 'i');
    }

    private function productIdentityFilters(string $code): array {
        $code = trim($code);
        $filters = [['ma_san_pham' => $code], ['id' => $code]];
        if (is_numeric($code)) {
            $filters[] = ['ma_san_pham' => (int)$code];
            $filters[] = ['id' => (int)$code];
        }
        if ($code !== '' && preg_match('/^[a-f0-9]{24}$/i', $code)) {
            try {
                $filters[] = ['_id' => new \MongoDB\BSON\ObjectId($code)];
            } catch (Throwable $e) {
                // Keep flexible product code lookup even when ObjectId parsing fails.
            }
        }
        return $filters;
    }

    private function normalizeAdminPayload(array $data, ?array $current = null): array {
        $payload = [
            'ma_san_pham' => trim((string)($data['ma_san_pham'] ?? $current['ma_san_pham'] ?? '')),
            'ten_san_pham' => trim((string)($data['ten_san_pham'] ?? $current['ten_san_pham'] ?? '')),
            'ma_thuong_hieu' => trim((string)($data['ma_thuong_hieu'] ?? $current['ma_thuong_hieu'] ?? '')),
            'ma_danh_muc' => trim((string)($data['ma_danh_muc'] ?? $current['ma_danh_muc'] ?? '')),
            'gia_ban' => trim((string)($data['gia_ban'] ?? $current['gia_ban'] ?? '')),
            'gia_thi_truong' => trim((string)($data['gia_thi_truong'] ?? $current['gia_thi_truong'] ?? '')),
            'dung_tich' => trim((string)($data['dung_tich'] ?? $current['dung_tich'] ?? '')),
            'loai_da' => trim((string)($data['loai_da'] ?? $current['loai_da'] ?? '')),
            'mo_ta' => trim((string)($data['mo_ta'] ?? $current['mo_ta'] ?? '')),
            'thanh_phan_chinh' => trim((string)($data['thanh_phan_chinh'] ?? $current['thanh_phan_chinh'] ?? '')),
            'thanh_phan_day_du' => trim((string)($data['thanh_phan_day_du'] ?? $current['thanh_phan_day_du'] ?? '')),
            'hdsd' => trim((string)($data['hdsd'] ?? $current['hdsd'] ?? '')),
            'link_hinh_anh' => trim((string)($data['link_hinh_anh'] ?? $current['link_hinh_anh'] ?? '')),
        ];

        foreach (['ma_san_pham', 'ma_thuong_hieu', 'ma_danh_muc'] as $field) {
            if ($payload[$field] !== '' && is_numeric($payload[$field])) {
                $payload[$field] = (int)$payload[$field];
            }
        }
        foreach (['gia_ban', 'gia_thi_truong'] as $field) {
            if ($payload[$field] !== '' && is_numeric($payload[$field])) {
                $payload[$field] = (float)$payload[$field];
            }
        }
        $market = (float)($payload['gia_thi_truong'] ?? 0);
        $sale = (float)($payload['gia_ban'] ?? 0);
        $payload['phan_tram_giam'] = ($market > 0 && $sale > 0 && $market > $sale) ? round((($market - $sale) / $market) * 100) : 0;
        $status = $this->normalizeProductVisibilityStatus((string)($data['trang_thai'] ?? $current['trang_thai'] ?? 'active'));
        $payload['trang_thai'] = $status;
        $payload['status'] = $status;
        $payload['updated_at'] = new \MongoDB\BSON\UTCDateTime();
        return $payload;
    }

    public function getNextProductCode(): string {
        return (string)$this->nextNumericCode('san_pham', 'ma_san_pham', 1);
    }

    public function hasProductCode(string $code, ?string $excludeId = null): bool {
        $code = trim($code);
        if ($code === '') return false;
        foreach ($this->productIdentityFilters($code) as $filter) {
            if ($excludeId !== null && trim($excludeId) !== '') {
                $filter = ['$and' => [$filter, ['$nor' => $this->productIdentityFilters($excludeId)]]];
            }
            if ($this->db->san_pham->findOne($filter, ['projection' => ['ma_san_pham' => 1]])) {
                return true;
            }
        }
        return false;
    }

    public function hasProductName(string $name, ?string $excludeId = null): bool {
        $name = trim($name);
        if ($name === '') return false;
        $filter = ['ten_san_pham' => $this->exactTextRegex($name)];
        if ($excludeId !== null && trim($excludeId) !== '') {
            $filter = ['$and' => [$filter, ['$nor' => $this->productIdentityFilters($excludeId)]]];
        }
        return (bool)$this->db->san_pham->findOne($filter, ['projection' => ['ma_san_pham' => 1]]);
    }

    public function ensureBrandByName(string $name): ?int {
        [$name, $pickedId] = $this->parseLookupLabel($name);
        if ($pickedId !== null && $pickedId !== '') return (int)$pickedId;
        if ($name === '') return null;
        $existing = $this->db->thuong_hieu->findOne(['ten_thuong_hieu' => $this->exactTextRegex($name)]);
        if ($existing && isset($existing['ma_thuong_hieu'])) return (int)$existing['ma_thuong_hieu'];
        $id = $this->nextNumericCode('thuong_hieu', 'ma_thuong_hieu', 1);
        $this->db->thuong_hieu->insertOne(['ma_thuong_hieu' => $id, 'ten_thuong_hieu' => $name, 'created_at' => new \MongoDB\BSON\UTCDateTime(), 'updated_at' => new \MongoDB\BSON\UTCDateTime()]);
        return $id;
    }

    public function ensureCategoryByName(string $name): ?int {
        [$name, $pickedId] = $this->parseLookupLabel($name);
        if ($pickedId !== null && $pickedId !== '') return (int)$pickedId;
        if ($name === '') return null;
        $existing = $this->db->danh_muc->findOne(['ten_danh_muc' => $this->exactTextRegex($name)]);
        if ($existing && isset($existing['ma_danh_muc'])) return (int)$existing['ma_danh_muc'];
        $id = $this->nextNumericCode('danh_muc', 'ma_danh_muc', 1);
        $this->db->danh_muc->insertOne(['ma_danh_muc' => $id, 'ten_danh_muc' => $name, 'danh_muc_day_du' => $name, 'created_at' => new \MongoDB\BSON\UTCDateTime(), 'updated_at' => new \MongoDB\BSON\UTCDateTime()]);
        return $id;
    }

    public function adminInsert(array $data): bool {
        $this->setError(null);
        try {
            $payload = $this->normalizeAdminPayload($data);
            if ((string)$payload['ma_san_pham'] === '' || (string)$payload['ten_san_pham'] === '') {
                $this->setError('Thieu ma hoac ten san pham.');
                return false;
            }
            $payload['ngay_tao'] = new \MongoDB\BSON\UTCDateTime();
            $payload['created_at'] = new \MongoDB\BSON\UTCDateTime();
            $this->db->san_pham->insertOne($payload);
            return true;
        } catch (Throwable $e) {
            $this->setError('Loi them san pham: ' . $e->getMessage());
            return false;
        }
    }

    public function adminUpdate(string $id, array $data): bool {
        $this->setError(null);
        try {
            $current = $this->findById($id);
            if (!$current) {
                $this->setError('Khong tim thay san pham can cap nhat.');
                return false;
            }
            $payload = $this->normalizeAdminPayload($data, $current);
            unset($payload['ma_san_pham'], $payload['ngay_tao'], $payload['created_at']);
            foreach ($this->productIdentityFilters($id) as $filter) {
                $result = $this->db->san_pham->updateOne($filter, ['$set' => $payload]);
                if ($result->getMatchedCount() > 0) return true;
            }
            $this->setError('Khong tim thay san pham can cap nhat.');
            return false;
        } catch (Throwable $e) {
            $this->setError('Loi cap nhat san pham: ' . $e->getMessage());
            return false;
        }
    }

    public function adminDelete(string $id): bool {
        return $this->updateProductVisibility($id, 'inactive');
    }

    public function updateInventory(string $id, int $stock): bool {
        $this->setError(null);
        $id = trim($id);
        $stock = max(0, $stock);
        if ($id === '') {
            $this->setError('Thieu ma san pham can cap nhat kho.');
            return false;
        }

        $payload = [
            'so_luong_ton_kho' => $stock,
            'trang_thai_kho' => $stock > 0 ? 'con_hang' : 'het_hang',
            'da_khoi_tao_kho' => true,
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        try {
            foreach ($this->productIdentityFilters($id) as $filter) {
                $result = $this->db->san_pham->updateOne($filter, ['$set' => $payload]);
                if ($result->getMatchedCount() > 0) return true;
            }
            $this->setError('Khong tim thay san pham can cap nhat kho.');
            return false;
        } catch (Throwable $e) {
            $this->setError('Loi cap nhat ton kho: ' . $e->getMessage());
            return false;
        }
    }

    public function listBrandOptions(): array {
        $options = ['sort' => ['ten_thuong_hieu' => 1]];
        $cursor = $this->db->thuong_hieu->find([], $options);
        $items = [];
        foreach ($cursor as $doc) {
            if (!empty($doc['ten_thuong_hieu'])) {
                $items[] = (array) $doc;
            }
        }
        return $items;
    }

    public function getBrands(): array {
        return $this->listBrandOptions();
    }

    public function listCategoryOptions(): array {
        $options = ['sort' => ['ten_danh_muc' => 1]];
        $cursor = $this->db->danh_muc->find([], $options);
        $items = [];
        foreach ($cursor as $doc) {
            if (!empty($doc['ten_danh_muc'])) {
                $items[] = (array) $doc;
            }
        }
        return $items;
    }

    public function getCategories(): array {
        return $this->listCategoryOptions();
    }
}
