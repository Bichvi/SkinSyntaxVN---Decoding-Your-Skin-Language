<?php
// backend/app/models/SanPham.php

class SanPham {
    private $db;
    private ?string $lastErrorMessage = null;
    private const VI_ACCENTS = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ';
    private const VI_ASCII = 'aaaaaaaaaaaaaaaaaeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyyd';

    public const CARD_PROJECTION = [
        'ma_san_pham' => 1,
        'id' => 1,
        'ten_san_pham' => 1,
        'gia_ban' => 1,
        'gia_thi_truong' => 1,
        'tien_tiet_kiem' => 1,
        'phan_tram_giam' => 1,
        'dung_tich' => 1,
        'loai_da' => 1,
        'danh_muc_day_du' => 1,
        'loai_san_pham' => 1,
        'ma_thuong_hieu' => 1,
        'ma_danh_muc' => 1,
        'ma_xuat_xu' => 1,
        'ma_noi_san_xuat' => 1,
        'ma_loai_da' => 1,
        'diem_danh_gia' => 1,
        'so_luong_danh_gia' => 1,
        'link_hinh_anh' => 1,
        'hinh_anh' => 1,
        'trang_thai' => 1,
        'trang_thai_kho' => 1,
        'so_luong_ton_kho' => 1,
        'so_luong_ton' => 1,
        'ton_kho' => 1,
        'so_luong_da_ban' => 1,
        'ngay_tao' => 1,
    ];

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

    private static ?array $brandLookupMap = null;
    private static ?array $categoryLookupMap = null;
    private static ?array $originLookupMap = null;
    private static ?array $simpleRecommenderMemoryCache = null;
    private static ?array $categoryHierarchyCache = null;

    public static function clearLookupCache(): void {
        self::$brandLookupMap = null;
        self::$categoryLookupMap = null;
        self::$originLookupMap = null;
        self::$simpleRecommenderMemoryCache = null;
        self::$menuTreeCache = null;
        self::$categoryHierarchyCache = null;
    }

    public static function clearSimpleRecommenderCache(): void {
        self::$simpleRecommenderMemoryCache = null;
        $cacheFile = dirname(__DIR__) . '/content/simple_recommender_cache.json';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    public function rebuildSimpleRecommenderCache(): array {
        self::clearSimpleRecommenderCache();
        return $this->getSimpleRecommenderProducts(24);
    }

    private function getBrandName($brandId): string {
        if ($brandId === null || $brandId === '') {
            return '';
        }
        if (self::$brandLookupMap === null) {
            self::$brandLookupMap = [];
            $cursor = $this->db->thuong_hieu->find([], [
                'projection' => ['ma_thuong_hieu' => 1, 'ten_thuong_hieu' => 1]
            ]);
            foreach ($cursor as $doc) {
                if (isset($doc['ma_thuong_hieu'])) {
                    self::$brandLookupMap[(string)$doc['ma_thuong_hieu']] = (string)($doc['ten_thuong_hieu'] ?? '');
                }
            }
        }
        return self::$brandLookupMap[(string)$brandId] ?? '';
    }

    private function getCategoryName($categoryId): string {
        if ($categoryId === null || $categoryId === '') {
            return '';
        }
        if (self::$categoryLookupMap === null) {
            self::$categoryLookupMap = [];
            $cursor = $this->db->danh_muc->find([], [
                'projection' => ['ma_danh_muc' => 1, 'ten_danh_muc' => 1]
            ]);
            foreach ($cursor as $doc) {
                if (isset($doc['ma_danh_muc'])) {
                    self::$categoryLookupMap[(string)$doc['ma_danh_muc']] = (string)($doc['ten_danh_muc'] ?? '');
                }
            }
        }
        return self::$categoryLookupMap[(string)$categoryId] ?? '';
    }

    private function getOriginName($originId): string {
        if ($originId === null || $originId === '') {
            return '';
        }
        if (self::$originLookupMap === null) {
            self::$originLookupMap = [];
            $cursor = $this->db->xuat_xu->find([], [
                'projection' => ['ma_xuat_xu' => 1, 'ten_xuat_xu' => 1]
            ]);
            foreach ($cursor as $doc) {
                if (isset($doc['ma_xuat_xu'])) {
                    self::$originLookupMap[(string)$doc['ma_xuat_xu']] = (string)($doc['ten_xuat_xu'] ?? '');
                }
            }
        }
        return self::$originLookupMap[(string)$originId] ?? '';
    }

    private function normalizeProductRecord($product): array {
        if (!$product) return [];
        $p = (array) $product;

        // Xử lý alias _id thành id nếu cần
        if (isset($p['ma_san_pham'])) {
            $p['id'] = (string) $p['ma_san_pham'];
        }

        // Ghép tên thương hiệu / danh mục từ các collection phụ (Lookup từ in-memory cache)
        if (isset($p['ma_thuong_hieu'])) {
            $p['thuong_hieu'] = $this->getBrandName($p['ma_thuong_hieu']);
        }

        if (isset($p['ma_danh_muc'])) {
            $p['loai_san_pham'] = $this->getCategoryName($p['ma_danh_muc']);
            if (empty($p['danh_muc_day_du'])) {
                $p['danh_muc_day_du'] = $p['loai_san_pham'];
            }
        }

        if (isset($p['ma_xuat_xu'])) {
            $p['xuat_xu_thuong_hieu'] = $this->getOriginName($p['ma_xuat_xu']);
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

    public function latest(int $limit = 8, bool $onlyVisibleOnWebsite = false, bool $excludeNonBeauty = false): array {
        $filter = [];
        if ($onlyVisibleOnWebsite) {
            $filter = $this->availableProductFilter();
        }

        if ($excludeNonBeauty) {
            $nonBeautyRegex = new \MongoDB\BSON\Regex('răng|bàn chải|toothpaste', 'i');
            $beautyFilter = [
                'loai_san_pham' => ['$not' => $nonBeautyRegex],
                'danh_muc_day_du' => ['$not' => $nonBeautyRegex],
                'ten_san_pham' => ['$not' => $nonBeautyRegex],
            ];
            $filter = count($filter) > 0 ? ['$and' => [$filter, $beautyFilter]] : $beautyFilter;
        }

        $options = [
            'sort' => ['ngay_tao' => -1, 'ma_san_pham' => -1],
            'limit' => $limit,
            'projection' => self::CARD_PROJECTION,
        ];

        $cursor = $this->db->san_pham->find($filter, $options);
        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeProductRecord($doc);
        }
        return $items;
    }


    public function getAllProducts(array $filters = [], int $limit = 100, ?string $sort = null): array {
        $res = $this->paginate(1, max(1, $limit), '', '', '', '', false);
        return $res['items'] ?? [];
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
            $filter = $this->productFlexibleFilter($id);
            $result = $this->db->san_pham->updateMany($filter, ['$set' => $payload]);
            if ($result->getMatchedCount() > 0) {
                self::clearSimpleRecommenderCache();
                return true;
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

    private static ?array $menuTreeCache = null;

    public function getCanonicalCategoryHierarchy(): array {
        if (self::$categoryHierarchyCache !== null) {
            return self::$categoryHierarchyCache;
        }

        $cursor = $this->db->danh_muc->find([
            'trang_thai' => ['$nin' => ['inactive', 'hidden', 'disabled', 'off', '0']]
        ]);

        $byId = [];
        $children = [];
        $roots = [];

        foreach ($cursor as $doc) {
            $cat = (array)$doc;
            if (!isset($cat['ma_danh_muc']) || !is_numeric($cat['ma_danh_muc'])) {
                continue;
            }
            $id = (int)$cat['ma_danh_muc'];
            $cat['ma_danh_muc'] = $id;
            $cat['ten_danh_muc'] = trim((string)($cat['ten_danh_muc'] ?? $cat['danh_muc_day_du'] ?? ''));
            $cat['parent_id'] = (isset($cat['parent_id']) && $cat['parent_id'] !== null && $cat['parent_id'] !== '' && is_numeric($cat['parent_id']))
                ? (int)$cat['parent_id']
                : null;
            $cat['level'] = isset($cat['level']) && is_numeric($cat['level']) ? (int)$cat['level'] : 1;
            $cat['thu_tu_hien_thi'] = isset($cat['thu_tu_hien_thi']) && is_numeric($cat['thu_tu_hien_thi']) ? (int)$cat['thu_tu_hien_thi'] : 999;
            $byId[$id] = $cat;
        }

        foreach ($byId as $id => $cat) {
            $pid = $cat['parent_id'];
            if ($pid !== null && isset($byId[$pid]) && $pid !== $id) {
                $children[$pid][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        $sortFn = function (int $a, int $b) use ($byId): int {
            $orderA = $byId[$a]['thu_tu_hien_thi'] ?? 999;
            $orderB = $byId[$b]['thu_tu_hien_thi'] ?? 999;
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }
            return $a <=> $b;
        };

        usort($roots, $sortFn);
        foreach ($children as $pid => &$cList) {
            usort($cList, $sortFn);
        }
        unset($cList);

        $leafIdsByNode = [];
        $collectLeaves = function (int $nodeId, array $visited = []) use (&$collectLeaves, $byId, $children, &$leafIdsByNode): array {
            if (isset($leafIdsByNode[$nodeId])) {
                return $leafIdsByNode[$nodeId];
            }
            if (isset($visited[$nodeId]) || !isset($byId[$nodeId])) {
                return [];
            }
            $visited[$nodeId] = true;
            $node = $byId[$nodeId];
            $nodeChildren = $children[$nodeId] ?? [];
            $leaves = [];

            if (empty($nodeChildren) || !empty($node['is_leaf'])) {
                if (empty($nodeChildren)) {
                    $leaves[] = $nodeId;
                }
            }
            foreach ($nodeChildren as $childId) {
                foreach ($collectLeaves($childId, $visited) as $leafId) {
                    $leaves[] = $leafId;
                }
            }
            $leaves = array_values(array_unique($leaves));
            $leafIdsByNode[$nodeId] = $leaves;
            return $leaves;
        };

        foreach (array_keys($byId) as $id) {
            $collectLeaves($id);
        }

        self::$categoryHierarchyCache = [
            'by_id' => $byId,
            'children' => $children,
            'roots' => $roots,
            'leaf_ids_by_node' => $leafIdsByNode,
        ];

        return self::$categoryHierarchyCache;
    }

    public function isLeafCategory($categoryId): bool {
        if ($categoryId === null || $categoryId === '' || !is_numeric($categoryId)) {
            return false;
        }
        $id = (int)$categoryId;
        $hierarchy = $this->getCanonicalCategoryHierarchy();
        if (!isset($hierarchy['by_id'][$id])) {
            return false;
        }
        $cat = $hierarchy['by_id'][$id];
        $hasChildren = !empty($hierarchy['children'][$id]);
        if ($hasChildren) {
            return false;
        }
        if (array_key_exists('is_leaf', $cat) && $cat['is_leaf'] === false) {
            return false;
        }
        return true;
    }

    private function normalizeCategoryMatchKey(string $val): string {
        $val = mb_strtolower(trim($val), 'UTF-8');
        $val = preg_replace('/\s+/', ' ', $val);
        return $val;
    }

    public function resolveCategoryLeafIds(string $cap1Val = '', string $cap2Val = ''): array {
        $hierarchy = $this->getCanonicalCategoryHierarchy();
        $byId = $hierarchy['by_id'];
        $leafIdsByNode = $hierarchy['leaf_ids_by_node'];

        $cap1Val = trim($cap1Val);
        $cap2Val = trim($cap2Val);
        if ($cap1Val === '' && $cap2Val === '') {
            return [];
        }

        $matchesNode = function (array $cat, string $query): bool {
            $qNorm = $this->normalizeCategoryMatchKey($query);
            if ($qNorm === '') return false;
            if (is_numeric($query) && (int)$query === (int)$cat['ma_danh_muc']) {
                return true;
            }
            $nameNorm = $this->normalizeCategoryMatchKey((string)($cat['ten_danh_muc'] ?? ''));
            if ($nameNorm === $qNorm) {
                return true;
            }
            $slugNorm = $this->normalizeCategoryMatchKey((string)($cat['slug'] ?? ''));
            if ($slugNorm !== '' && $slugNorm === $qNorm) {
                return true;
            }
            return false;
        };

        $isDescendantOrSelf = function (int $nodeId, int $ancestorId) use ($byId): bool {
            $cur = $nodeId;
            $guard = 0;
            while ($cur !== null && isset($byId[$cur]) && $guard < 10) {
                if ($cur === $ancestorId) {
                    return true;
                }
                $cur = $byId[$cur]['parent_id'] ?? null;
                $guard++;
            }
            return false;
        };

        $matchedCap1Ids = [];
        if ($cap1Val !== '') {
            foreach ($byId as $id => $cat) {
                if ($matchesNode($cat, $cap1Val)) {
                    $matchedCap1Ids[] = $id;
                }
            }
        }

        $targetNodeIds = [];
        if ($cap2Val !== '') {
            foreach ($byId as $id => $cat) {
                if ($matchesNode($cat, $cap2Val)) {
                    if (!empty($matchedCap1Ids)) {
                        foreach ($matchedCap1Ids as $c1Id) {
                            if ($isDescendantOrSelf($id, $c1Id)) {
                                $targetNodeIds[] = $id;
                                break;
                            }
                        }
                    } else {
                        $targetNodeIds[] = $id;
                    }
                }
            }
            // Fallback: if cap2Val matched a valid category node even without strict cap1 ancestor
            if (empty($targetNodeIds)) {
                foreach ($byId as $id => $cat) {
                    if ($matchesNode($cat, $cap2Val)) {
                        $targetNodeIds[] = $id;
                    }
                }
            }
        } else {
            $targetNodeIds = $matchedCap1Ids;
        }

        if (empty($targetNodeIds)) {
            return [];
        }

        $leafIds = [];
        foreach ($targetNodeIds as $nodeId) {
            foreach ($leafIdsByNode[$nodeId] ?? [] as $leafId) {
                $leafIds[] = (int)$leafId;
                $leafIds[] = (string)$leafId;
            }
        }

        return array_values(array_unique($leafIds, SORT_REGULAR));
    }

    public function menuTree(int $cap2LimitEach = 14): array {
        if (self::$menuTreeCache !== null) {
            return self::$menuTreeCache;
        }

        $hierarchy = $this->getCanonicalCategoryHierarchy();
        $byId = $hierarchy['by_id'];
        $children = $hierarchy['children'];
        $roots = $hierarchy['roots'];
        $leafIdsByNode = $hierarchy['leaf_ids_by_node'];

        // Aggregate direct product counts from san_pham.ma_danh_muc
        $countPipeline = [
            ['$match' => $this->availableProductFilter()],
            ['$group' => [
                '_id' => '$ma_danh_muc',
                'so_luong' => ['$sum' => 1]
            ]]
        ];
        $countCursor = $this->db->san_pham->aggregate($countPipeline);
        $directCounts = [];
        foreach ($countCursor as $row) {
            if ($row['_id'] !== null && $row['_id'] !== '' && is_numeric($row['_id'])) {
                $cid = (int)$row['_id'];
                $directCounts[$cid] = ($directCounts[$cid] ?? 0) + (int)$row['so_luong'];
            }
        }

        $tree = [];
        foreach ($roots as $rootId) {
            $rootCat = $byId[$rootId] ?? null;
            if (!$rootCat) continue;
            $c1Name = $rootCat['ten_danh_muc'];
            if ($c1Name === '') continue;

            $level2Ids = $children[$rootId] ?? [];
            $groups = [];
            $rootSum = 0;
            $legacyFlat = [];

            foreach ($level2Ids as $l2Id) {
                $l2Cat = $byId[$l2Id] ?? null;
                if (!$l2Cat) continue;
                $c2Name = $l2Cat['ten_danh_muc'];
                if ($c2Name === '') continue;

                $isLeaf = !empty($l2Cat['is_leaf']) || empty($children[$l2Id]);
                $leaves = $leafIdsByNode[$l2Id] ?? [];
                $recSum = 0;
                foreach ($leaves as $leafId) {
                    $recSum += $directCounts[$leafId] ?? 0;
                }

                $childItems = [];
                if (!$isLeaf && !empty($children[$l2Id])) {
                    foreach ($children[$l2Id] as $l3Id) {
                        if (count($childItems) >= $cap2LimitEach) {
                            break;
                        }
                        $l3Cat = $byId[$l3Id] ?? null;
                        if (!$l3Cat) continue;
                        $childCount = $directCounts[$l3Id] ?? 0;
                        $childItems[] = [
                            'id' => (int)$l3Id,
                            'name' => $l3Cat['ten_danh_muc'],
                            'slug' => (string)($l3Cat['slug'] ?? ''),
                            'is_leaf' => true,
                            'count' => (int)$childCount
                        ];
                    }
                }

                $groupData = [
                    'id' => (int)$l2Id,
                    'name' => $c2Name,
                    'slug' => (string)($l2Cat['slug'] ?? ''),
                    'is_leaf' => $isLeaf,
                    'direct_count' => (int)($directCounts[$l2Id] ?? 0),
                    'recursive_count' => (int)$recSum,
                    'children' => $childItems
                ];

                $groups[] = $groupData;
                $rootSum += $recSum;
                $legacyFlat[$c2Name] = (int)$recSum;
            }

            if (!empty($groups) || $rootSum > 0) {
                $tree[$c1Name] = array_merge([
                    'root_id' => (int)$rootId,
                    'root_name' => $c1Name,
                    'total_count' => (int)$rootSum,
                    'groups' => $groups,
                ], $legacyFlat);
            }
        }

        self::$menuTreeCache = $tree;
        return $tree;
    }

    public function paginate(int $page, int $perPage, string $q = '', string $cap1Val = '', string $cap2Val = '', string $statusFilter = '', bool $onlyVisibleOnWebsite = false, string $stockStatusFilter = '', string $sort = 'default'): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
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
            $leafIds = $this->resolveCategoryLeafIds($cap1Val, $cap2Val);
            if (!empty($leafIds)) {
                $filter['ma_danh_muc'] = ['$in' => $leafIds];
            } else {
                // Backward compatibility fallback if unmapped category string is queried
                $searchCat = trim($cap1Val . ' -> ' . $cap2Val, ' -> ');
                $filter['danh_muc_day_du'] = $this->buildSearchRegex($searchCat);
            }
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
            'sort' => $this->buildProductSort($sort, ['ma_san_pham' => -1]),
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
            ['thanh_phan' => $regex],
            ['thanh_phan_full' => $regex],
            ['thanh_phan_sach' => $regex],
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

        $options = [
            'sort' => $sort,
            'limit' => $limit,
            'projection' => self::CARD_PROJECTION,
        ];
        $cursor = $this->db->san_pham->find($filter, $options);
        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeProductRecord($doc);
        }
        return $items;
    }

    public function getHomepageProductSections(
        int $limitEach = 4,
        array $recentViewedIds = [],
        ?array $userProfile = null,
        array $behaviorSignals = []
    ): array {
        $limitEach = max(4, min(12, $limitEach));

        $flashDeals = $this->getFlashSaleProducts($limitEach);

        // Adaptive Recommender V1 (Phase A)
        $adaptiveRecs = $this->getHybridRecommendations($recentViewedIds, $userProfile, $limitEach, $behaviorSignals);

        // If adaptiveRecs is empty (e.g. cold start guest or simple mode), fallback to Simple Recommender Top-4
        if (empty($adaptiveRecs)) {
            $fallbackSimple = $this->getSimpleRecommenderProducts(4);
            $adaptiveRecs = [];
            foreach ($fallbackSimple as $p) {
                $p['recommender_meta'] = [
                    'algorithm' => 'adaptive_v1',
                    'algorithm_mode' => 'SIMPLE',
                    'source_mode' => 'simple',
                    'active_signals' => [],
                    'dominant_signal' => 'SIMPLE',
                    'similarity_score' => 0.0,
                    'content_score' => 0.0,
                    'skin_score' => 0.70,
                    'skin_type_match' => 0.70,
                    'budget_score' => 1.0,
                    'final_score' => round((float)($p['diem_danh_gia_weighted'] ?? $p['diem_danh_gia'] ?? 4.8), 4),
                    'behavior_contribution' => 0.0,
                    'profile_contribution' => 0.0,
                    'source_recent_items' => [],
                    'profile_terms_used' => [],
                    'reason_tags' => ['Sản phẩm nổi bật được yêu thích'],
                    'reason' => 'Sản phẩm nổi bật được yêu thích',
                ];
                $adaptiveRecs[] = $p;
            }
        }

        // Section 5.5 Simple Recommender using IMDb Weighted Rating (Top-24)
        // Presentation-level deduplication: avoid duplicating SKUs rendered in forYou
        $forYouIds = array_map('strval', array_column($adaptiveRecs, 'ma_san_pham'));
        $simplePool = $this->getSimpleRecommenderProducts(max(16, $limitEach * 4));
        $topRatedWeighted = [];
        foreach ($simplePool as $p) {
            $pid = (string)($p['ma_san_pham'] ?? '');
            if (!in_array($pid, $forYouIds, true)) {
                $topRatedWeighted[] = $p;
                if (count($topRatedWeighted) >= $limitEach) {
                    break;
                }
            }
        }
        if (empty($topRatedWeighted)) {
            $topRatedWeighted = array_slice($simplePool, 0, $limitEach);
        }

        return [
            'flashDeals' => $flashDeals,
            'bestSellers' => [],
            'topSearches' => [],
            'forYou' => $adaptiveRecs, // Section 3 single primary Adaptive For-You section
            'adaptiveForYou' => $adaptiveRecs,
            'topRatedWeighted' => $topRatedWeighted, // Section 5.5 Simple Recommender
            'contentBased' => $adaptiveRecs, // Backward compatibility
            'hybridRecs' => $adaptiveRecs,
        ];
    }

    /**
     * Adaptive Recommender V1 (Phase A): Combines in-session behavior with customer skin profile
     *
     * @param array $recentViewedIds Array of viewed ma_san_pham
     * @param array|null $userProfile Skin profile from customer survey
     * @param int $limit Max items to return
     * @param array $behaviorSignals Multi-signal behavior payload
     * @return array List of normalized product records with recommender_meta
     */
    public function getHybridRecommendations(
        array $recentViewedIds = [],
        ?array $userProfile = null,
        int $limit = 4,
        array $behaviorSignals = []
    ): array {
        require_once dirname(__DIR__) . '/services/ContentBasedRecommender.php';
        $service = new ContentBasedRecommender();
        $recommendations = $service->recommendHybrid($recentViewedIds, $userProfile, $limit, $this->db, $behaviorSignals);

        if (empty($recommendations)) {
            $fallback = $this->getSimpleRecommenderProducts($limit);
            $stamped = [];
            foreach ($fallback as $p) {
                $p['recommender_meta'] = [
                    'algorithm' => 'adaptive_v1',
                    'algorithm_mode' => 'SIMPLE',
                    'source_mode' => 'simple',
                    'active_signals' => [],
                    'dominant_signal' => 'SIMPLE',
                    'similarity_score' => 0.0,
                    'content_score' => 0.0,
                    'skin_score' => 0.70,
                    'skin_type_match' => 0.70,
                    'budget_score' => 1.0,
                    'final_score' => round((float)($p['diem_danh_gia_weighted'] ?? $p['diem_danh_gia'] ?? 4.8), 4),
                    'behavior_contribution' => 0.0,
                    'profile_contribution' => 0.0,
                    'source_recent_items' => [],
                    'profile_terms_used' => [],
                    'reason_tags' => ['Sản phẩm nổi bật được yêu thích'],
                    'reason' => 'Sản phẩm nổi bật được yêu thích',
                    'ingredient_warning' => null,
                ];
                $stamped[] = $p;
            }
            return $stamped;
        }

        $targetIds = array_column($recommendations, 'ma_san_pham');
        $recMetaMap = [];
        foreach ($recommendations as $rec) {
            $recMetaMap[$rec['ma_san_pham']] = $rec;
        }

        $targetIdsInt = array_map(fn($id) => is_numeric($id) ? (int)$id : $id, $targetIds);
        $targetIdsStr = array_map(fn($id) => (string)$id, $targetIds);
        $queryIds = array_values(array_unique(array_merge($targetIdsInt, $targetIdsStr)));

        // Query products from MongoDB by target IDs
        $cursor = $this->db->san_pham->find([
            'ma_san_pham' => ['$in' => $queryIds],
            'trang_thai' => 'active',
            'gia_ban' => ['$gt' => 0]
        ]);

        $productMap = [];
        foreach ($cursor as $doc) {
            $p = $this->normalizeProductRecord($doc);
            $pid = (string)($p['ma_san_pham'] ?? '');
            if ($pid !== '') {
                $meta = $recMetaMap[$pid] ?? [];
                $p['recommender_meta'] = [
                    'algorithm' => 'adaptive_v1',
                    'algorithm_mode' => $meta['algorithm_mode'] ?? 'ADAPTIVE_HYBRID',
                    'source_mode' => $meta['source_mode'] ?? 'hybrid',
                    'active_signals' => $meta['active_signals'] ?? [],
                    'dominant_signal' => $meta['dominant_signal'] ?? 'HYBRID',
                    'similarity_score' => $meta['similarity'] ?? ($meta['content_score'] ?? 0.0),
                    'content_score' => $meta['content_score'] ?? 0.0,
                    'skin_score' => $meta['skin_score'] ?? ($meta['skin_type_match'] ?? 0.0),
                    'skin_type_match' => $meta['skin_type_match'] ?? 0.0,
                    'budget_score' => $meta['budget_score'] ?? 1.0,
                    'final_score' => $meta['final_score'] ?? 0.0,
                    'behavior_contribution' => $meta['behavior_contribution'] ?? 0.0,
                    'profile_contribution' => $meta['profile_contribution'] ?? 0.0,
                    'source_recent_items' => $meta['source_recent_items'] ?? $recentViewedIds,
                    'profile_terms_used' => $meta['profile_terms_used'] ?? [],
                    'reason_tags' => $meta['reason_tags'] ?? [],
                    'reason' => $meta['reason'] ?? 'Dành riêng cho bạn',
                    'ingredient_warning' => $meta['ingredient_warning'] ?? null,
                ];
                $productMap[$pid] = $p;
            }
        }

        // Maintain sorted order by final_score descending
        $ordered = [];
        foreach ($targetIds as $tid) {
            if (isset($productMap[$tid])) {
                $ordered[] = $productMap[$tid];
            }
        }

        return $ordered;
    }

    /**
     * Backward-compatible Content-Based Recommender wrapper
     */
    public function getContentBasedRecommendations(array $recentViewedIds, int $limit = 4): array {
        return $this->getHybridRecommendations($recentViewedIds, null, $limit);
    }

    /**
     * Simple Recommender: IMDb Weighted Rating formula
     * WR = (v / (v + m)) * R + (m / (v + m)) * C
     *
     * @param int $limit Number of top products to return
     * @param float|null $m Minimum vote threshold (default 21.0, P75)
     * @param float|null $C Global mean rating (default 4.8890)
     * @return array Top rated products with recommender_meta
     */
    public function getSimpleRecommenderProducts(int $limit = 8, ?float $m = null, ?float $C = null): array {
        $limit = max(1, min(24, $limit));
        $isDefaultConfig = ($m === null || abs($m - 21.0) < 0.0001) && ($C === null || abs($C - 4.8890) < 0.0001);
        $m = $m ?? 21.0;
        $C = $C ?? 4.8890;

        // 1. In-memory static cache hit (for repeated calls within same request)
        if ($isDefaultConfig && self::$simpleRecommenderMemoryCache !== null) {
            return array_slice(self::$simpleRecommenderMemoryCache, 0, $limit);
        }

        // 2. Application file-level cache hit (TTL: 86400s / 24h with stale fallback)
        $cacheFile = dirname(__DIR__) . '/content/simple_recommender_cache.json';
        $fallbackCached = null;
        if ($isDefaultConfig && file_exists($cacheFile)) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached) && !empty($cached)) {
                $fallbackCached = $cached;
                $mtime = filemtime($cacheFile);
                if ($mtime !== false && (time() - $mtime) < 86400) {
                    self::$simpleRecommenderMemoryCache = $cached;
                    return array_slice(self::$simpleRecommenderMemoryCache, 0, $limit);
                }
            }
        }

        try {
            // 3. Optimized query with projection (excludes heavy HTML/text fields)
            $filter = $this->availableProductFilter();
            $condition = [
                'so_luong_danh_gia' => ['$gte' => $m],
                'diem_danh_gia' => ['$gt' => 0],
                'gia_ban' => ['$gt' => 0]
            ];
            $filter = ['$and' => [$filter, $condition]];

            $projection = self::CARD_PROJECTION;

        $cursor = $this->db->san_pham->find($filter, ['projection' => $projection]);
        $candidates = [];

        foreach ($cursor as $doc) {
            $p = $this->normalizeProductRecord($doc);
            $r = (float)($p['diem_danh_gia'] ?? 0);
            $v = (int)($p['so_luong_danh_gia'] ?? 0);
            $wr = ($v + $m > 0) ? (($v / ($v + $m)) * $r + ($m / ($v + $m)) * $C) : 0.0;
            $p['recommender_meta'] = [
                'R' => $r,
                'v' => $v,
                'C' => $C,
                'm' => $m,
                'weighted_rating' => round($wr, 4)
            ];
            $candidates[] = $p;
        }

        usort($candidates, function($a, $b) {
            $diff = $b['recommender_meta']['weighted_rating'] <=> $a['recommender_meta']['weighted_rating'];
            if ($diff !== 0) return $diff;
            $vDiff = $b['recommender_meta']['v'] <=> $a['recommender_meta']['v'];
            if ($vDiff !== 0) return $vDiff;
            return strcmp((string)($a['ma_san_pham'] ?? ''), (string)($b['ma_san_pham'] ?? ''));
        });

        $topCandidates = array_slice($candidates, 0, 24);

        if ($isDefaultConfig && !empty($topCandidates)) {
            self::$simpleRecommenderMemoryCache = $topCandidates;
            $dir = dirname($cacheFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            @file_put_contents($cacheFile, json_encode($topCandidates, JSON_UNESCAPED_UNICODE));
        }

        return array_slice($topCandidates, 0, $limit);
        } catch (Throwable $e) {
            error_log('getSimpleRecommenderProducts error: ' . $e->getMessage());
            if ($fallbackCached !== null) {
                self::$simpleRecommenderMemoryCache = $fallbackCached;
                return array_slice($fallbackCached, 0, $limit);
            }
            throw $e;
        }
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
            $leafIds = $this->resolveCategoryLeafIds('', $category);
            if (!empty($leafIds)) {
                $parts[] = ['ma_danh_muc' => ['$in' => $leafIds]];
            } else {
                $regex = $this->buildSearchRegex($category);
                $parts[] = ['$or' => [
                    ['danh_muc_day_du' => $regex],
                    ['loai_san_pham' => $regex],
                ]];
            }
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
        if ($sort === '' || $sort === 'default' || $sort === 'mac_dinh') {
            return $defaultSort;
        }

        $sortMap = [
            'price_asc' => ['gia_ban' => 1, 'ma_san_pham' => -1],
            'gia_asc' => ['gia_ban' => 1, 'ma_san_pham' => -1],

            'price_desc' => ['gia_ban' => -1, 'ma_san_pham' => -1],
            'gia_desc' => ['gia_ban' => -1, 'ma_san_pham' => -1],

            'best_seller' => ['so_luong_da_ban' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1],
            'ban_chay' => ['so_luong_da_ban' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1],

            'top_rated' => ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1],
            'high_rating' => ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1],
            'danh_gia_cao' => ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1],

            'discount' => ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1],
            'discount_desc' => ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1],
            'giam_gia' => ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1],

            'newest' => ['ngay_tao' => -1, 'created_at' => -1, 'ma_san_pham' => -1],
            'moi_nhat' => ['ngay_tao' => -1, 'created_at' => -1, 'ma_san_pham' => -1],

            'most_viewed' => ['luot_xem' => -1, 'ma_san_pham' => -1],
            'views_desc' => ['luot_xem' => -1, 'ma_san_pham' => -1],
            'nhieu_luot_xem' => ['luot_xem' => -1, 'ma_san_pham' => -1],
        ];

        return $sortMap[$sort] ?? $defaultSort;
    }

    public function deduplicateProductVariants(array $items, int $maxPerLine = 1): array {
        if (empty($items)) {
            return [];
        }

        $deduped = [];
        $counts = [];

        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $groupKey = '';
            foreach (['parent_product_id', 'product_group', 'ma_dong_san_pham', 'dong_san_pham'] as $field) {
                if (!empty($item[$field])) {
                    $groupKey = strtolower(trim((string)$item[$field]));
                    break;
                }
            }

            if ($groupKey === '') {
                $name = (string)($item['ten_san_pham'] ?? $item['name'] ?? '');
                $brand = (string)($item['thuong_hieu'] ?? $item['brand'] ?? '');
                
                // Strip shade numbers, volume numbers, shade names, etc.
                $cleanName = preg_replace('/(\b\d{2,4}[a-z]?\b|\b\d+\s*(ml|g|kg|oz)\b|\b(shade|tone|màu)\s*\w+\b|\b(fair|light|medium|deep|honey|neutralizer|nude|ivory)\b)/i', '', $name);
                $cleanName = preg_replace('/\([^)]*\)/', '', $cleanName);
                $cleanName = preg_replace('/[^\p{L}\p{N}\s]/u', '', $cleanName);
                $cleanName = preg_replace('/\s+/', ' ', trim($cleanName));

                $groupKey = strtolower(trim($brand . '_' . $cleanName));
            }

            if ($groupKey === '') {
                $deduped[] = $item;
                continue;
            }

            $currentCount = $counts[$groupKey] ?? 0;
            if ($currentCount < $maxPerLine) {
                $counts[$groupKey] = $currentCount + 1;
                $deduped[] = $item;
            }
        }

        return $deduped;
    }

    private function findDiscoveryProducts(array $baseFilter, array $filters, array $defaultSort, int $limit, ?string $sort = null): array {
        $filter = $this->buildProductFilters($filters);
        if (!empty($baseFilter)) {
            $filter = ['$and' => [$filter, $baseFilter]];
        }

        $rawItems = [];
        // Fetch extra items so deduplication leaves enough diverse items
        $fetchLimit = max(24, $limit * 4);
        $options = [
            'sort' => $this->buildProductSort($sort ?? (string)($filters['sort'] ?? ''), $defaultSort),
            'limit' => max(1, min(60, $fetchLimit)),
            'projection' => self::CARD_PROJECTION,
        ];
        $cursor = $this->db->san_pham->find($filter, $options);
        foreach ($cursor as $doc) {
            $rawItems[] = $this->normalizeProductRecord($doc);
        }

        $deduped = $this->deduplicateProductVariants($rawItems, 1);
        return array_slice($deduped, 0, $limit);
    }

    private function discountDiscoveryFilter(): array {
        return ['$or' => [
            ['phan_tram_giam' => ['$gt' => 0]],
            ['tien_tiet_kiem' => ['$gt' => 0]],
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
                    ['thanh_phan_full' => $regex],
                    ['thanh_phan_sach' => $regex],
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
            $leafIds = $this->resolveCategoryLeafIds('', $category);
            if (!empty($leafIds)) {
                $filterParts[] = ['ma_danh_muc' => ['$in' => $leafIds]];
            } else {
                $categoryRegex = $this->buildSearchRegex($category);
                $filterParts[] = ['$or' => [
                    ['danh_muc_day_du' => $categoryRegex],
                    ['loai_san_pham' => $categoryRegex],
                ]];
            }
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
            'newest' => ['ngay_tao' => -1, 'ma_san_pham' => -1],
            'most_viewed' => ['luot_xem' => -1, 'ma_san_pham' => -1],
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
        if (self::$brandLookupMap !== null) {
            self::$brandLookupMap[(string)$id] = $name;
        }
        return $id;
    }

    public function ensureCategoryByName(string $name): ?int {
        [$name, $pickedId] = $this->parseLookupLabel($name);
        if ($pickedId !== null && $pickedId !== '') {
            $cid = (int)$pickedId;
            return $this->isLeafCategory($cid) ? $cid : null;
        }
        if ($name === '') return null;
        $existing = $this->db->danh_muc->findOne(['ten_danh_muc' => $this->exactTextRegex($name)]);
        if ($existing && isset($existing['ma_danh_muc'])) {
            $cid = (int)$existing['ma_danh_muc'];
            return $this->isLeafCategory($cid) ? $cid : null;
        }
        $id = $this->nextNumericCode('danh_muc', 'ma_danh_muc', 1);
        $this->db->danh_muc->insertOne([
            'ma_danh_muc' => $id,
            'ten_danh_muc' => $name,
            'danh_muc_day_du' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> ' . $name,
            'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> ' . $name,
            'parent_id' => 1001,
            'level' => 2,
            'is_leaf' => true,
            'thu_tu_hien_thi' => 99,
            'trang_thai' => 'active',
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ]);
        self::clearLookupCache();
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
            if ((string)$payload['ma_danh_muc'] === '' || !$this->isLeafCategory($payload['ma_danh_muc'])) {
                $this->setError('San pham chi duoc gan vao danh muc la (leaf category).');
                return false;
            }
            $hierarchy = $this->getCanonicalCategoryHierarchy();
            $catDoc = $hierarchy['by_id'][(int)$payload['ma_danh_muc']] ?? null;
            if ($catDoc) {
                $payload['loai_san_pham'] = (string)($catDoc['ten_danh_muc'] ?? '');
                $payload['danh_muc_day_du'] = (string)($catDoc['full_path'] ?? $catDoc['danh_muc_day_du'] ?? $catDoc['ten_danh_muc'] ?? '');
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
            if ((string)$payload['ma_danh_muc'] === '' || !$this->isLeafCategory($payload['ma_danh_muc'])) {
                $this->setError('San pham chi duoc gan vao danh muc la (leaf category).');
                return false;
            }
            $hierarchy = $this->getCanonicalCategoryHierarchy();
            $catDoc = $hierarchy['by_id'][(int)$payload['ma_danh_muc']] ?? null;
            if ($catDoc) {
                $payload['loai_san_pham'] = (string)($catDoc['ten_danh_muc'] ?? '');
                $payload['danh_muc_day_du'] = (string)($catDoc['full_path'] ?? $catDoc['danh_muc_day_du'] ?? $catDoc['ten_danh_muc'] ?? '');
            }
            unset($payload['ma_san_pham'], $payload['ngay_tao'], $payload['created_at']);
            foreach ($this->productIdentityFilters($id) as $filter) {
                $result = $this->db->san_pham->updateOne($filter, ['$set' => $payload]);
                if ($result->getMatchedCount() > 0) {
                    self::clearSimpleRecommenderCache();
                    return true;
                }
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

    public function listCategoryOptions(bool $onlyLeaf = true): array {
        $hierarchy = $this->getCanonicalCategoryHierarchy();
        $byId = $hierarchy['by_id'];
        $children = $hierarchy['children'];
        $roots = $hierarchy['roots'];

        $orderedIds = [];
        $traverse = function (int $nodeId, array $visited = []) use (&$traverse, $children, &$orderedIds): void {
            if (isset($visited[$nodeId])) return;
            $visited[$nodeId] = true;
            $orderedIds[] = $nodeId;
            foreach ($children[$nodeId] ?? [] as $childId) {
                $traverse($childId, $visited);
            }
        };
        foreach ($roots as $rootId) {
            $traverse($rootId);
        }
        foreach (array_keys($byId) as $id) {
            if (!in_array($id, $orderedIds, true)) {
                $orderedIds[] = $id;
            }
        }

        $items = [];
        foreach ($orderedIds as $id) {
            $cat = $byId[$id] ?? null;
            if (!$cat) continue;
            if ($onlyLeaf && !$this->isLeafCategory($id)) {
                continue;
            }
            $pid = $cat['parent_id'] ?? null;
            $cat['parent_name'] = ($pid !== null && isset($byId[$pid])) ? $byId[$pid]['ten_danh_muc'] : '';
            $items[] = $cat;
        }

        return $items;
    }

    public function getCategories(): array {
        return $this->listCategoryOptions(true);
    }
}
