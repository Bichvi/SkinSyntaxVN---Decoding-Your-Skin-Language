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
        $stockFields = ['so_luong_ton', 'ton_kho', 'stock', 'quantity'];
        $missingAllStockFields = [];
        $positiveStockClauses = [];
        foreach ($stockFields as $field) {
            $missingAllStockFields[] = [$field => ['$exists' => false]];
            $positiveStockClauses[] = [$field => ['$gt' => 0]];
        }

        return [
            '$and' => [
                $this->visibleProductFilter(),
                ['$or' => array_merge([['$and' => $missingAllStockFields]], $positiveStockClauses)],
            ],
        ];
    }

    public function getProductStock(array $product): ?int {
        foreach (['so_luong_ton', 'ton_kho', 'stock', 'quantity'] as $field) {
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
        $stock = $this->getProductStock($p);
        return $stock === null || $stock > 0;
    }

    private function normalizeProductRecord($product): array {
        if (!$product) return [];
        $p = (array) $product;

        if (isset($p['ma_san_pham'])) {
            $p['id'] = (string) $p['ma_san_pham'];
        }

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
        $p['is_available'] = $this->isProductAvailable($p);

        return $p;
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
        $sid = trim((string)$id);
        if ($sid === '') {
            return false;
        }

        $visibility = [];
        if ($onlyVisibleOnWebsite) {
            $visibility['trang_thai'] = ['$nin' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']];
        }

        $product = $this->db->san_pham->findOne(array_merge($visibility, ['ma_san_pham' => $sid]));
        if (!$product && ctype_digit($sid)) {
            $product = $this->db->san_pham->findOne(array_merge($visibility, ['ma_san_pham' => (int)$sid]));
        }

        if (!$product) {
            return false;
        }

        $normalized = $this->normalizeProductRecord($product);
        if ($onlyVisibleOnWebsite && !$this->isProductAvailable($normalized)) {
            return false;
        }

        return $normalized;
    }

    public function findById($id, bool $onlyVisibleOnWebsite = false) {
        return $this->find($id, $onlyVisibleOnWebsite);
    }

    /**
     * Batch load products by id (single Mongo query).
     *
     * @param array<int, string|int> $ids
     * @return array<string, array> keyed by string product id
     */
    public function findByIds(array $ids, bool $onlyVisibleOnWebsite = false): array {
        $idList = [];
        foreach ($ids as $id) {
            $sid = trim((string)$id);
            if ($sid !== '' && !in_array($sid, $idList, true)) {
                $idList[] = $sid;
            }
        }

        if ($idList === []) {
            return [];
        }

        $orFilters = [];
        foreach ($idList as $sid) {
            $orFilters[] = ['ma_san_pham' => $sid];
            if (ctype_digit($sid)) {
                $orFilters[] = ['ma_san_pham' => (int)$sid];
            }
        }

        $filter = ['$or' => $orFilters];
        if ($onlyVisibleOnWebsite) {
            $filter['trang_thai'] = ['$nin' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']];
        }

        $out = [];
        $cursor = $this->db->san_pham->find($filter);
        foreach ($cursor as $doc) {
            $normalized = $this->normalizeProductRecord($doc);
            if ($onlyVisibleOnWebsite && !$this->isProductAvailable($normalized)) {
                continue;
            }
            $key = trim((string)($normalized['ma_san_pham'] ?? ''));
            if ($key !== '') {
                $out[$key] = $normalized;
            }
        }

        return $out;
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

    public function paginate(int $page, int $perPage, string $q = '', string $cap1Val = '', string $cap2Val = '', string $statusFilter = '', bool $onlyVisibleOnWebsite = false): array {
        $page = max(1, $page);
        $skip = ($page - 1) * $perPage;
        
        $filter = [];

        if (trim($q) !== '') {
            $regex = $this->buildSearchRegex($q);
            $filter['$or'] = [
                ['ten_san_pham' => $regex],
                ['ma_san_pham' => $regex],
                ['danh_muc_day_du' => $regex]
            ];
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
        $discountFilter = [
            '$or' => [
                ['phan_tram_giam' => ['$gt' => 0]],
                ['gia_thi_truong' => ['$gt' => 0]],
            ],
        ];

        return [
            'flashDeals' => $this->findHomepageProducts($discountFilter, ['phan_tram_giam' => -1, 'gia_ban' => 1, 'ma_san_pham' => -1], $limitEach),
            'bestSellers' => $this->findHomepageProducts([], ['so_luong_ban' => -1, 'luot_mua' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1], $limitEach),
            'topSearches' => $this->findHomepageProducts([], ['luot_xem' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1], $limitEach),
            'forYou' => $this->findHomepageProducts([], ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ngay_tao' => -1], $limitEach),
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
        $filters = [['ma_san_pham' => $code]];
        if (is_numeric($code)) {
            $filters[] = ['ma_san_pham' => (int)$code];
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
}
