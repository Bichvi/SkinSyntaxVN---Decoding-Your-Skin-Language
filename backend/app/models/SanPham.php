<?php
// backend/app/models/SanPham.php

class SanPham {
    private PDO $pdo;
    private ?array $sanPhamColumnsCache = null;
    private ?string $sanPhamStatusColumnCache = null;
    private ?string $lastErrorMessage = null;
    private const VI_ACCENTS = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ';
    private const VI_ASCII = 'aaaaaaaaaaaaaaaaaeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyyd';

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ensureProductVisibilityColumn();
    }

    public function getLastErrorMessage(): ?string {
        return $this->lastErrorMessage;
    }

    private function setError(?string $message): void {
        $this->lastErrorMessage = $message;
    }

    private function mapProductDbError(Throwable $e): string {
        $code = (string)($e->getCode() ?? '');
        $raw = strtolower((string)$e->getMessage());

        if ($code === '23505' || str_contains($raw, 'unique')) {
            if (str_contains($raw, 'ma_san_pham')) {
                return 'Mã sản phẩm đã tồn tại. Vui lòng dùng mã khác.';
            }
            if (str_contains($raw, 'ten_san_pham')) {
                return 'Tên sản phẩm đã tồn tại. Vui lòng nhập tên khác.';
            }
            return 'Dữ liệu bị trùng khóa duy nhất. Vui lòng kiểm tra lại mã và tên sản phẩm.';
        }

        if ($code === '23503' || str_contains($raw, 'foreign key')) {
            return 'Danh mục hoặc thương hiệu không hợp lệ. Vui lòng chọn lại dữ liệu liên kết.';
        }

        if ($code === '23502' || str_contains($raw, 'not-null')) {
            return 'Thiếu trường bắt buộc khi lưu sản phẩm. Vui lòng kiểm tra lại dữ liệu.';
        }

        return 'Không thể lưu sản phẩm lúc này. Vui lòng thử lại.';
    }

    private function resetSanPhamColumnCache(): void {
        $this->sanPhamColumnsCache = null;
        $this->sanPhamStatusColumnCache = null;
    }

    private function getSanPhamStatusColumn(): ?string {
        if ($this->sanPhamStatusColumnCache !== null) {
            return $this->sanPhamStatusColumnCache !== '' ? $this->sanPhamStatusColumnCache : null;
        }

        $column = $this->firstExistingSanPhamColumn(['trang_thai', 'status']);
        $this->sanPhamStatusColumnCache = $column ?? '';
        return $column;
    }

    private function ensureProductVisibilityColumn(): void {
        if ($this->firstExistingSanPhamColumn(['trang_thai', 'status']) !== null) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE san_pham ADD COLUMN IF NOT EXISTS trang_thai VARCHAR(20) DEFAULT 'active'");
            $this->resetSanPhamColumnCache();
            $statusColumn = $this->getSanPhamStatusColumn();
            if ($statusColumn !== null) {
                $this->pdo->exec("UPDATE san_pham SET $statusColumn = 'active' WHERE COALESCE(TRIM($statusColumn), '') = ''");
            }
        } catch (Throwable $e) {
            // Keep app working even when DB user cannot alter schema.
        }
    }

    private function normalizeProductVisibilityStatus(?string $status): string {
        $normalized = strtolower(trim((string)($status ?? '')));
        if (in_array($normalized, ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0'], true)) {
            return 'inactive';
        }

        return 'active';
    }

    private function getVisibilityClause(string $tableAlias = 'sp', bool $visible = true): ?string {
        $statusColumn = $this->getSanPhamStatusColumn();
        if ($statusColumn === null) {
            return null;
        }

        $expr = "LOWER(TRIM(COALESCE($tableAlias.$statusColumn, 'active')))";
        $hiddenSet = "('inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0')";

        return $visible
            ? "$expr NOT IN $hiddenSet"
            : "$expr IN $hiddenSet";
    }

    private function normalizeKeywordParts(string $q): array {
        $parts = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return empty($parts) && trim($q) !== '' ? [trim($q)] : $parts;
    }

    private function foldKeyword(string $value): string {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return strtr($value, [
            'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a',
            'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o',
            'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
            'đ' => 'd',
        ]);
    }

    private function foldSqlExpr(string $expr): string {
        return "translate(lower($expr), '" . self::VI_ACCENTS . "', '" . self::VI_ASCII . "')";
    }

    private function appendKeywordFilters(string &$where, array &$params, string $q, array $columns): void {
        if (trim($q) === '') {
            return;
        }

        $keywordParts = $this->normalizeKeywordParts($q);
        foreach ($keywordParts as $i => $part) {
            $param = ':q' . $i;
            $orParts = [];

            foreach ($columns as $columnExpr) {
                $orParts[] = $this->foldSqlExpr($columnExpr) . " LIKE " . $param;
            }

            $where .= " AND (" . implode(" OR ", $orParts) . ") ";
            $params[$param] = '%' . $this->foldKeyword($part) . '%';
        }
    }

    private function getSanPhamColumns(): array {
        if ($this->sanPhamColumnsCache !== null) {
            return $this->sanPhamColumnsCache;
        }

        $sql = "SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'san_pham'";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $cols = [];
        foreach ($rows as $row) {
            $name = (string)($row['column_name'] ?? '');
            if ($name !== '') {
                $cols[$name] = true;
            }
        }

        $this->sanPhamColumnsCache = $cols;
        $this->sanPhamStatusColumnCache = null;
        return $this->sanPhamColumnsCache;
    }

    private function hasSanPhamColumn(string $column): bool {
        return isset($this->getSanPhamColumns()[$column]);
    }

    private function firstExistingSanPhamColumn(array $candidates): ?string {
        foreach ($candidates as $column) {
            if ($this->hasSanPhamColumn($column)) {
                return $column;
            }
        }

        return null;
    }

    private function prepareProductColumnData(array $data): array {
        $prepared = [];

        foreach ($data as $column => $value) {
            $column = (string)$column;
            if ($this->hasSanPhamColumn($column)) {
                $prepared[$column] = $value;
            }
        }

        $fieldMap = [
            'ma_san_pham' => ['ma_san_pham'],
            'ten_san_pham' => ['ten_san_pham'],
            'ma_loai' => ['ma_loai'],
            'ma_thuong_hieu' => ['ma_thuong_hieu'],
            'ma_nsx' => ['ma_nsx', 'ma_noi_san_xuat'],
            'ma_noi_san_xuat' => ['ma_noi_san_xuat', 'ma_nsx'],
            'ma_xuat_xu' => ['ma_xuat_xu'],
            'ma_danh_muc' => ['ma_danh_muc'],
            'gia_ban' => ['gia_ban'],
            'gia_thi_truong' => ['gia_thi_truong'],
            'tien_tiet_kiem' => ['tien_tiet_kiem'],
            'phan_tram_giam' => ['phan_tram_giam'],
            'diem_danh_gia' => ['diem_danh_gia'],
            'so_luong_danh_gia' => ['so_luong_danh_gia'],
            'dung_tich' => ['dung_tich'],
            'loai_da' => ['loai_da'],
            'link_hinh_anh' => ['link_hinh_anh', 'hinh_anh'],
            'hinh_anh' => ['hinh_anh', 'link_hinh_anh'],
            'mo_ta' => ['mo_ta'],
            'thanh_phan_chinh' => ['thanh_phan_chinh', 'thanh_phan'],
            'thanh_phan_day_du' => ['thanh_phan_day_du', 'thanh_phan_full'],
            'thanh_phan' => ['thanh_phan', 'thanh_phan_chinh'],
            'thanh_phan_full' => ['thanh_phan_full', 'thanh_phan_day_du'],
            'hdsd' => ['hdsd'],
            'attribute' => ['attribute'],
            'danh_muc_day_du' => ['danh_muc_day_du'],
            'trang_thai' => ['trang_thai', 'status'],
            'status' => ['status', 'trang_thai'],
        ];

        foreach ($fieldMap as $source => $targets) {
            if (!array_key_exists($source, $data)) {
                continue;
            }

            $target = $this->firstExistingSanPhamColumn($targets);
            if ($target !== null) {
                $prepared[$target] = $data[$source];
            }
        }

        return $prepared;
    }

    private function enrichDerivedPricingFields(array $data): array {
        $hasGiaBan = array_key_exists('gia_ban', $data);
        $hasGiaThiTruong = array_key_exists('gia_thi_truong', $data);

        if (!$hasGiaBan && !$hasGiaThiTruong) {
            return $data;
        }

        $giaBanRaw = trim((string)($data['gia_ban'] ?? ''));
        $giaThiTruongRaw = trim((string)($data['gia_thi_truong'] ?? ''));

        if ($giaBanRaw === '' || $giaThiTruongRaw === '' || !is_numeric($giaBanRaw) || !is_numeric($giaThiTruongRaw)) {
            $data['phan_tram_giam'] = null;
            $data['tien_tiet_kiem'] = null;
            return $data;
        }

        $giaBan = (float)$giaBanRaw;
        $giaThiTruong = (float)$giaThiTruongRaw;

        if ($giaBan <= 0 || $giaThiTruong <= 0 || $giaThiTruong <= $giaBan) {
            $data['phan_tram_giam'] = null;
            $data['tien_tiet_kiem'] = null;
            return $data;
        }

        $tietKiem = $giaThiTruong - $giaBan;
        $phanTramGiam = (int)round(($tietKiem / $giaThiTruong) * 100);

        $data['tien_tiet_kiem'] = (string)max(0, (int)round($tietKiem));
        $data['phan_tram_giam'] = (string)max(0, $phanTramGiam);

        return $data;
    }

    private function normalizeProductRecord(array $product): array {
        if ((!isset($product['link_hinh_anh']) || trim((string)$product['link_hinh_anh']) === '') && isset($product['hinh_anh'])) {
            $product['link_hinh_anh'] = $product['hinh_anh'];
        }

        if ((!isset($product['thanh_phan_chinh']) || trim((string)$product['thanh_phan_chinh']) === '') && isset($product['thanh_phan'])) {
            $product['thanh_phan_chinh'] = $product['thanh_phan'];
        }

        if ((!isset($product['thanh_phan_day_du']) || trim((string)$product['thanh_phan_day_du']) === '') && isset($product['thanh_phan_full'])) {
            $product['thanh_phan_day_du'] = $product['thanh_phan_full'];
        }

        if ((!isset($product['thuong_hieu']) || trim((string)$product['thuong_hieu']) === '') && isset($product['ten_thuong_hieu'])) {
            $product['thuong_hieu'] = $product['ten_thuong_hieu'];
        }

        if ((!isset($product['loai_san_pham']) || trim((string)$product['loai_san_pham']) === '') && isset($product['ten_danh_muc'])) {
            $product['loai_san_pham'] = $product['ten_danh_muc'];
        }

        $statusColumn = $this->getSanPhamStatusColumn();
        $rawStatus = $statusColumn !== null
            ? (string)($product[$statusColumn] ?? 'active')
            : (string)($product['trang_thai'] ?? $product['status'] ?? 'active');
        $normalizedStatus = $this->normalizeProductVisibilityStatus($rawStatus);
        $product['trang_thai'] = $normalizedStatus;
        $product['status'] = $normalizedStatus;

        return $product;
    }

    private function buildSearchColumns(): array {
        $columns = [
            "CAST(sp.ma_san_pham AS TEXT)",
            'sp.ten_san_pham',
            "COALESCE(th.ten_thuong_hieu, '')",
            "COALESCE(dm.ten_danh_muc, '')",
            "COALESCE(sp.danh_muc_day_du, '')",
            "COALESCE(sp.dung_tich, '')"
        ];

        $available = $this->getSanPhamColumns();
        $optionalSanPhamCols = ['loai_da', 'thanh_phan_chinh', 'thanh_phan_day_du', 'mo_ta'];

        foreach ($optionalSanPhamCols as $col) {
            if (isset($available[$col])) {
                $columns[] = "COALESCE(sp.$col, '')";
            }
        }

        return $columns;
    }

    private function buildSuggestionColumns(): array {
        $columns = [
            "CAST(sp.ma_san_pham AS TEXT)",
            'sp.ten_san_pham',
            "COALESCE(th.ten_thuong_hieu, '')",
            "COALESCE(dm.ten_danh_muc, '')"
        ];

        $available = $this->getSanPhamColumns();
        if (isset($available['thanh_phan_chinh'])) {
            $columns[] = "COALESCE(sp.thanh_phan_chinh, '')";
        } elseif (isset($available['thanh_phan_day_du'])) {
            $columns[] = "COALESCE(sp.thanh_phan_day_du, '')";
        }

        return $columns;
    }

    // Lấy sản phẩm mới theo schema hiện tại
    public function latest(int $limit = 8, bool $onlyVisibleOnWebsite = false): array {
        $visibilityWhere = '';
        if ($onlyVisibleOnWebsite) {
            $visibilityClause = $this->getVisibilityClause('sp', true);
            if ($visibilityClause !== null) {
                $visibilityWhere = ' WHERE ' . $visibilityClause;
            }
        }

        $sql = "SELECT sp.*, sp.ma_san_pham AS id,
                       th.ten_thuong_hieu AS thuong_hieu,
                       COALESCE(dm.ten_danh_muc, sp.danh_muc_day_du) AS danh_muc_day_du,
                       dm.ten_danh_muc AS loai_san_pham,
                  COALESCE(xx.ten_xuat_xu, xxt.ten_xuat_xu) AS xuat_xu_thuong_hieu,
                  COALESCE(nsx.ten_nsx, sp.ma_noi_san_xuat::text) AS noi_san_xuat
                FROM san_pham sp
                LEFT JOIN thuong_hieu th ON sp.ma_thuong_hieu = th.ma_thuong_hieu
                LEFT JOIN danh_muc dm ON sp.ma_danh_muc = dm.ma_danh_muc
                LEFT JOIN xuat_xu xx ON sp.ma_xuat_xu = xx.ma_xuat_xu
                LEFT JOIN xuat_xu_thuong_hieu xxt ON sp.ma_xuat_xu = xxt.ma_xuat_xu
              LEFT JOIN noi_san_xuat nsx ON sp.ma_noi_san_xuat = nsx.ma_nsx
                                $visibilityWhere
                ORDER BY sp.ngay_tao DESC NULLS LAST, id DESC
                LIMIT :limit";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $item): array => $this->normalizeProductRecord($item), $items);
    }

    public function find($id, bool $onlyVisibleOnWebsite = false) {
        $visibilitySql = '';
        if ($onlyVisibleOnWebsite) {
            $visibilityClause = $this->getVisibilityClause('sp', true);
            if ($visibilityClause !== null) {
                $visibilitySql = ' AND ' . $visibilityClause;
            }
        }

        $sql = "SELECT sp.*, sp.ma_san_pham AS id,
                       th.ten_thuong_hieu AS thuong_hieu,
                                             th.ten_thuong_hieu,
                       COALESCE(dm.ten_danh_muc, sp.danh_muc_day_du) AS danh_muc_day_du,
                       dm.ten_danh_muc AS loai_san_pham,
                                             dm.ten_danh_muc,
                  COALESCE(xx.ten_xuat_xu, xxt.ten_xuat_xu) AS xuat_xu_thuong_hieu,
                  COALESCE(nsx.ten_nsx, sp.ma_noi_san_xuat::text) AS noi_san_xuat
                FROM san_pham sp
                LEFT JOIN thuong_hieu th ON sp.ma_thuong_hieu = th.ma_thuong_hieu
                LEFT JOIN danh_muc dm ON sp.ma_danh_muc = dm.ma_danh_muc
                LEFT JOIN xuat_xu xx ON sp.ma_xuat_xu = xx.ma_xuat_xu
                LEFT JOIN xuat_xu_thuong_hieu xxt ON sp.ma_xuat_xu = xxt.ma_xuat_xu
              LEFT JOIN noi_san_xuat nsx ON sp.ma_noi_san_xuat = nsx.ma_nsx
                WHERE sp.ma_san_pham = :id
                                $visibilitySql
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $product = $st->fetch(PDO::FETCH_ASSOC);
        return $product ? $this->normalizeProductRecord($product) : false;
    }

    // Alias cho find()
    public function findById($id, bool $onlyVisibleOnWebsite = false) {
        return $this->find($id, $onlyVisibleOnWebsite);
    }

    public function tangLuotXem(string $id): void {
        $sql = "UPDATE san_pham
                SET luot_xem = COALESCE(luot_xem, 0) + 1
                WHERE ma_san_pham = :id";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
    }

    /**
     * Menu danh mục kiểu Hasaki:
     * - Nếu danh_muc_day_du bắt đầu bằng "Sức Khỏe - Làm Đẹp -> ..." thì:
     *   cap1 = split_part(...,2) (vd: Chăm Sóc Da Mặt)
     *   cap2 = split_part(...,3) (vd: Dưỡng Ẩm / Làm Sạch Da / ...)
     * - Nếu không có prefix, cap1/cap2 lấy bình thường.
     */
        private function cap1Expr(): string {
                return "CASE
                                    WHEN danh_muc_day_du LIKE 'Sức Khỏe - Làm Đẹp -> %'
                                        THEN NULLIF(split_part(danh_muc_day_du, ' -> ', 2), '')
                                    ELSE NULLIF(split_part(danh_muc_day_du, ' -> ', 1), '')
                                END";
    }

        private function cap2Expr(): string {
                return "CASE
                                    WHEN danh_muc_day_du LIKE 'Sức Khỏe - Làm Đẹp -> %'
                                        THEN NULLIF(split_part(danh_muc_day_du, ' -> ', 3), '')
                                    ELSE NULLIF(split_part(danh_muc_day_du, ' -> ', 2), '')
                                END";
    }

    // Tree: [cap1 => [cap2 => count]]
    public function menuTree(int $cap2LimitEach = 14): array {
        $cap1 = $this->cap1Expr();
        $cap2 = $this->cap2Expr();

        $sql = "SELECT $cap1 AS cap1, $cap2 AS cap2, COUNT(*)::int AS so_luong
            FROM san_pham
            WHERE danh_muc_day_du IS NOT NULL AND danh_muc_day_du <> ''
            GROUP BY cap1, cap2
            HAVING $cap1 IS NOT NULL
            ORDER BY cap1 ASC, so_luong DESC";

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $tree = [];
        foreach ($rows as $r) {
            $c1 = $r['cap1'] ?? null;
            $c2 = $r['cap2'] ?? null;
            if (!$c1) continue;

            if (!isset($tree[$c1])) $tree[$c1] = [];
            // cap2 có thể null -> bỏ qua
            if ($c2) {
                // giới hạn số cap2 mỗi cap1 cho menu gọn
                if (count($tree[$c1]) < $cap2LimitEach) {
                    $tree[$c1][$c2] = (int)$r['so_luong'];
                }
            }
        }
        return $tree;
    }

    /**
     * Paginate + lọc theo q + cap1/cap2 (đúng theo menu)
     */
    public function paginate(
        int $page,
        int $perPage,
        string $q = '',
        string $cap1Val = '',
        string $cap2Val = '',
        string $statusFilter = '',
        bool $onlyVisibleOnWebsite = false
    ): array {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $cap1 = $this->cap1Expr();
        $cap2 = $this->cap2Expr();

        $where = " WHERE 1=1 ";
        $params = [];

        $this->appendKeywordFilters($where, $params, $q, $this->buildSearchColumns());

        if ($cap1Val !== '') {
            $where .= " AND ($cap1) = :cap1 ";
            $params[':cap1'] = $cap1Val;
        }
        if ($cap2Val !== '') {
            $where .= " AND ($cap2) = :cap2 ";
            $params[':cap2'] = $cap2Val;
        }

        if ($onlyVisibleOnWebsite) {
            $visibilityClause = $this->getVisibilityClause('sp', true);
            if ($visibilityClause !== null) {
                $where .= " AND $visibilityClause ";
            }
        }

        $statusFilter = strtolower(trim($statusFilter));
        if (!$onlyVisibleOnWebsite && in_array($statusFilter, ['active', 'inactive'], true)) {
            $statusClause = $this->getVisibilityClause('sp', $statusFilter === 'active');
            if ($statusClause !== null) {
                $where .= " AND $statusClause ";
            }
        }

        $from = " FROM san_pham sp
              LEFT JOIN thuong_hieu th ON sp.ma_thuong_hieu = th.ma_thuong_hieu
              LEFT JOIN danh_muc dm ON sp.ma_danh_muc = dm.ma_danh_muc
              LEFT JOIN xuat_xu xx ON sp.ma_xuat_xu = xx.ma_xuat_xu
              LEFT JOIN xuat_xu_thuong_hieu xxt ON sp.ma_xuat_xu = xxt.ma_xuat_xu
              LEFT JOIN noi_san_xuat nsx ON sp.ma_noi_san_xuat = nsx.ma_nsx";

        // count
        $sqlCount = "SELECT COUNT(*)::int AS total" . $from . $where;
        $st1 = $this->pdo->prepare($sqlCount);
        $st1->execute($params);
        $total = (int)($st1->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        // items
        $sqlItems = "SELECT sp.*, sp.ma_san_pham AS id,
                    th.ten_thuong_hieu AS thuong_hieu,
                    COALESCE(dm.ten_danh_muc, sp.danh_muc_day_du) AS danh_muc_day_du,
                    dm.ten_danh_muc AS loai_san_pham,
                          COALESCE(xx.ten_xuat_xu, xxt.ten_xuat_xu) AS xuat_xu_thuong_hieu,
                          COALESCE(nsx.ten_nsx, sp.ma_noi_san_xuat::text) AS noi_san_xuat
                 " . $from . $where . "
                 ORDER BY id DESC
                 LIMIT :limit OFFSET :offset";
        $st2 = $this->pdo->prepare($sqlItems);
        foreach ($params as $k => $v) $st2->bindValue($k, $v);
        $st2->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $st2->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st2->execute();
        $items = $st2->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map(fn(array $item): array => $this->normalizeProductRecord($item), $items);

        return ['items' => $items, 'total' => $total];
    }

    public function searchSuggestions(string $q, int $limit = 8, bool $onlyVisibleOnWebsite = false): array {
        $limit = max(1, min(20, $limit));
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        $where = " WHERE 1=1 ";
        $params = [];

        $this->appendKeywordFilters($where, $params, $q, $this->buildSuggestionColumns());
        if ($onlyVisibleOnWebsite) {
            $visibilityClause = $this->getVisibilityClause('sp', true);
            if ($visibilityClause !== null) {
                $where .= " AND $visibilityClause ";
            }
        }

        $sql = "SELECT sp.ma_san_pham AS id,
                       sp.ten_san_pham,
                       sp.gia_ban,
                       sp.link_hinh_anh,
                       COALESCE(th.ten_thuong_hieu, '') AS thuong_hieu
                FROM san_pham sp
                LEFT JOIN thuong_hieu th ON sp.ma_thuong_hieu = th.ma_thuong_hieu
                LEFT JOIN danh_muc dm ON sp.ma_danh_muc = dm.ma_danh_muc
                " . $where . "
                ORDER BY sp.ten_san_pham ASC
                LIMIT :limit";

        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTopTrending(int $limit = 5, bool $onlyVisibleOnWebsite = false): array {
        $limit = max(1, min(20, $limit));
        $visibilityWhere = '';
        if ($onlyVisibleOnWebsite) {
            $visibilityClause = $this->getVisibilityClause('sp', true);
            if ($visibilityClause !== null) {
                $visibilityWhere = ' WHERE ' . $visibilityClause;
            }
        }

        try {
            $sql = "SELECT sp.ma_san_pham AS id,
                           sp.ten_san_pham,
                           sp.gia_ban,
                           sp.link_hinh_anh,
                           COALESCE(th.ten_thuong_hieu, '') AS thuong_hieu,
                           COALESCE(sp.luot_xem, 0) AS luot_xem
                    FROM san_pham sp
                    LEFT JOIN thuong_hieu th ON sp.ma_thuong_hieu = th.ma_thuong_hieu
                    $visibilityWhere
                    ORDER BY COALESCE(sp.luot_xem, 0) DESC, sp.ten_san_pham ASC
                    LIMIT :limit";

            $st = $this->pdo->prepare($sql);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Fallback an toàn nếu DB chưa có cột luot_xem.
            $sqlFallback = "SELECT sp.ma_san_pham AS id,
                                   sp.ten_san_pham,
                                   sp.gia_ban,
                                   sp.link_hinh_anh,
                                   COALESCE(th.ten_thuong_hieu, '') AS thuong_hieu,
                                   COALESCE(sp.so_luong_danh_gia, 0) AS luot_xem
                            FROM san_pham sp
                            LEFT JOIN thuong_hieu th ON sp.ma_thuong_hieu = th.ma_thuong_hieu
                            $visibilityWhere
                            ORDER BY COALESCE(sp.so_luong_danh_gia, 0) DESC, sp.ten_san_pham ASC
                            LIMIT :limit";

            $st = $this->pdo->prepare($sqlFallback);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    public function searchLive(string $q, int $limit = 5, bool $onlyVisibleOnWebsite = false): array {
        $limit = max(1, min(20, $limit));
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        $where = " WHERE 1=1 ";
        $params = [];
        $this->appendKeywordFilters($where, $params, $q, $this->buildSuggestionColumns());
        if ($onlyVisibleOnWebsite) {
            $visibilityClause = $this->getVisibilityClause('sp', true);
            if ($visibilityClause !== null) {
                $where .= " AND $visibilityClause ";
            }
        }

        $sql = "SELECT sp.ma_san_pham AS id,
                       sp.ten_san_pham,
                       sp.gia_ban,
                       sp.link_hinh_anh,
                       COALESCE(th.ten_thuong_hieu, '') AS thuong_hieu
                FROM san_pham sp
                LEFT JOIN thuong_hieu th ON sp.ma_thuong_hieu = th.ma_thuong_hieu
                LEFT JOIN danh_muc dm ON sp.ma_danh_muc = dm.ma_danh_muc
                " . $where . "
                ORDER BY sp.ten_san_pham ASC
                LIMIT :limit";

        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listBrandOptions(): array {
        try {
            $sql = "SELECT ma_thuong_hieu, ten_thuong_hieu
                    FROM thuong_hieu
                    WHERE COALESCE(TRIM(ten_thuong_hieu), '') <> ''
                    ORDER BY ten_thuong_hieu ASC, ma_thuong_hieu ASC";
            $st = $this->pdo->query($sql);
            return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function listCategoryOptions(): array {
        try {
            $sql = "SELECT ma_danh_muc, ten_danh_muc
                    FROM danh_muc
                    WHERE COALESCE(TRIM(ten_danh_muc), '') <> ''
                    ORDER BY ten_danh_muc ASC, ma_danh_muc ASC";
            $st = $this->pdo->query($sql);
            return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getNextProductCode(): string {
        try {
            $sql = "SELECT COALESCE(MAX(CASE WHEN ma_san_pham::text ~ '^[0-9]+$' THEN ma_san_pham::bigint END), 0) + 1 AS next_code
                    FROM san_pham";
            $value = $this->pdo->query($sql)->fetchColumn();
            return (string)((int)$value);
        } catch (Throwable $e) {
            return (string)time();
        }
    }

    public function hasProductCode(string $code, ?string $excludeId = null): bool {
        $code = trim($code);
        if ($code === '') {
            return false;
        }

        $sql = 'SELECT 1 FROM san_pham WHERE ma_san_pham = :code';
        $params = [':code' => $code];

        if ($excludeId !== null && trim($excludeId) !== '') {
            $sql .= ' AND ma_san_pham <> :exclude_id';
            $params[':exclude_id'] = trim($excludeId);
        }

        $sql .= ' LIMIT 1';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return (bool)$st->fetchColumn();
    }

    public function hasProductName(string $name, ?string $excludeId = null): bool {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $sql = 'SELECT 1 FROM san_pham WHERE LOWER(TRIM(ten_san_pham)) = LOWER(TRIM(:name))';
        $params = [':name' => $name];

        if ($excludeId !== null && trim($excludeId) !== '') {
            $sql .= ' AND ma_san_pham <> :exclude_id';
            $params[':exclude_id'] = trim($excludeId);
        }

        $sql .= ' LIMIT 1';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return (bool)$st->fetchColumn();
    }

    public function ensureBrandByName(string $name): ?int {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $findSql = "SELECT ma_thuong_hieu
                    FROM thuong_hieu
                    WHERE LOWER(TRIM(ten_thuong_hieu)) = LOWER(TRIM(:name))
                    LIMIT 1";
        $find = $this->pdo->prepare($findSql);
        $find->execute([':name' => $name]);
        $found = $find->fetchColumn();
        if ($found !== false) {
            return (int)$found;
        }

        try {
            $insert = $this->pdo->prepare("INSERT INTO thuong_hieu (ten_thuong_hieu) VALUES (:name) RETURNING ma_thuong_hieu");
            $insert->execute([':name' => $name]);
            $created = $insert->fetchColumn();
            return $created !== false ? (int)$created : null;
        } catch (Throwable $e) {
            $find->execute([':name' => $name]);
            $found = $find->fetchColumn();
            return $found !== false ? (int)$found : null;
        }
    }

    public function ensureCategoryByName(string $name): ?int {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $findSql = "SELECT ma_danh_muc
                    FROM danh_muc
                    WHERE LOWER(TRIM(ten_danh_muc)) = LOWER(TRIM(:name))
                    LIMIT 1";
        $find = $this->pdo->prepare($findSql);
        $find->execute([':name' => $name]);
        $found = $find->fetchColumn();
        if ($found !== false) {
            return (int)$found;
        }

        try {
            $insert = $this->pdo->prepare("INSERT INTO danh_muc (ten_danh_muc) VALUES (:name) RETURNING ma_danh_muc");
            $insert->execute([':name' => $name]);
            $created = $insert->fetchColumn();
            return $created !== false ? (int)$created : null;
        } catch (Throwable $e) {
            $find->execute([':name' => $name]);
            $found = $find->fetchColumn();
            return $found !== false ? (int)$found : null;
        }
    }

    public function adminInsert($data): bool {
        $this->setError(null);

        $maSanPham = trim((string)($data['ma_san_pham'] ?? ''));
        $tenSanPham = trim((string)($data['ten_san_pham'] ?? ''));

        if ($maSanPham === '' || $tenSanPham === '') {
            $this->setError('Mã sản phẩm và tên sản phẩm là bắt buộc.');
            return false;
        }

        $data = $this->enrichDerivedPricingFields($data);
        $allowedColumns = $this->prepareProductColumnData($data);

        $fields = [];
        $placeholders = [];
        $params = [];

        foreach ($allowedColumns as $column => $rawValue) {
            $value = is_string($rawValue) ? trim($rawValue) : $rawValue;
            if ($value === '') {
                $value = null;
            }

            $fields[] = $column;
            $placeholders[] = ':' . $column;
            $params[':' . $column] = $value;
        }

        if (!in_array('ma_san_pham', $fields, true) || !in_array('ten_san_pham', $fields, true)) {
            $this->setError('Thiếu trường cột bắt buộc trong bảng sản phẩm.');
            return false;
        }

        $sql = 'INSERT INTO san_pham (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';

        try {
            $st = $this->pdo->prepare($sql);
            return $st->execute($params);
        } catch (Throwable $e) {
            $this->setError($this->mapProductDbError($e));
            return false;
        }
    }

    public function adminUpdate($id, $data): bool {
        $this->setError(null);

        $id = trim((string)$id);
        if ($id === '') {
            $this->setError('Mã sản phẩm không hợp lệ.');
            return false;
        }

        $data = $this->enrichDerivedPricingFields($data);
        $allowedColumns = $this->prepareProductColumnData($data);

        $setClauses = [];
        $params = [':id' => $id];

        foreach ($allowedColumns as $column => $rawValue) {
            $value = is_string($rawValue) ? trim($rawValue) : $rawValue;
            if ($value === '') {
                $value = null;
            }

            $paramName = ':' . $column;
            $setClauses[] = $column . ' = ' . $paramName;
            $params[$paramName] = $value;
        }

        if (empty($setClauses)) {
            $this->setError('Không có dữ liệu nào để cập nhật.');
            return false;
        }

        $sql = 'UPDATE san_pham SET ' . implode(', ', $setClauses) . ' WHERE ma_san_pham = :id';

        try {
            $st = $this->pdo->prepare($sql);
            $ok = $st->execute($params);
            if (!$ok) {
                $this->setError('Không thể cập nhật sản phẩm lúc này.');
                return false;
            }

            if ($st->rowCount() === 0) {
                $this->setError('Không tìm thấy sản phẩm cần cập nhật hoặc dữ liệu không thay đổi.');
                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->setError($this->mapProductDbError($e));
            return false;
        }
    }

    public function updateProductVisibility($id, string $status): bool {
        $this->setError(null);

        $id = trim((string)$id);
        if ($id === '') {
            $this->setError('Mã sản phẩm không hợp lệ.');
            return false;
        }

        $statusColumn = $this->getSanPhamStatusColumn();
        if ($statusColumn === null) {
            $this->setError('Bảng sản phẩm chưa hỗ trợ cột trạng thái hiển thị.');
            return false;
        }

        $rawStatus = strtolower(trim($status));
        if (!in_array($rawStatus, ['active', 'inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0'], true)) {
            $this->setError('Trạng thái hiển thị không hợp lệ.');
            return false;
        }

        $normalized = $this->normalizeProductVisibilityStatus($rawStatus);
        $sql = "UPDATE san_pham SET $statusColumn = :status WHERE ma_san_pham = :id";
        try {
            $st = $this->pdo->prepare($sql);
            $ok = $st->execute([
                ':status' => $normalized,
                ':id' => $id,
            ]);
            if (!$ok) {
                $this->setError('Không thể cập nhật trạng thái hiển thị sản phẩm.');
                return false;
            }

            if ($st->rowCount() === 0) {
                $this->setError('Không tìm thấy sản phẩm để cập nhật trạng thái.');
                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->setError($this->mapProductDbError($e));
            return false;
        }
    }

    public function adminDelete($id): bool {
        $this->setError(null);

        $id = trim((string)$id);
        if ($id === '') {
            $this->setError('Mã sản phẩm không hợp lệ.');
            return false;
        }

        $sql = 'DELETE FROM san_pham WHERE ma_san_pham = :id';
        try {
            $st = $this->pdo->prepare($sql);
            $ok = $st->execute([':id' => $id]);
            if (!$ok) {
                $this->setError('Không thể xóa sản phẩm lúc này.');
                return false;
            }

            if ($st->rowCount() === 0) {
                $this->setError('Không tìm thấy sản phẩm để xóa.');
                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->setError($this->mapProductDbError($e));
            return false;
        }
    }
}
