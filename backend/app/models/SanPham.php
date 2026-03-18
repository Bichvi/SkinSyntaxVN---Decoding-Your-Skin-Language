<?php
// backend/app/models/SanPham.php

class SanPham {
    private PDO $pdo;
    private ?array $sanPhamColumnsCache = null;
    private const VI_ACCENTS = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ';
    private const VI_ASCII = 'aaaaaaaaaaaaaaaaaeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyyd';

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
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
        return $this->sanPhamColumnsCache;
    }

    private function buildSearchColumns(): array {
        $columns = [
            'sp.ten_san_pham',
            "COALESCE(th.ten_thuong_hieu, '')",
            "COALESCE(dm.ten_danh_muc, '')",
            "COALESCE(sp.danh_muc_day_du, '')"
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
    public function latest(int $limit = 8): array {
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
                ORDER BY sp.ngay_tao DESC NULLS LAST, id DESC
                LIMIT :limit";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find($id) {
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
                WHERE sp.ma_san_pham = :id
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    // Alias cho find()
    public function findById($id) {
        return $this->find($id);
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
    public function paginate(int $page, int $perPage, string $q = '', string $cap1Val = '', string $cap2Val = ''): array {
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

        return ['items' => $items, 'total' => $total];
    }

    public function searchSuggestions(string $q, int $limit = 8): array {
        $limit = max(1, min(20, $limit));
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        $where = " WHERE 1=1 ";
        $params = [];

        $this->appendKeywordFilters($where, $params, $q, $this->buildSuggestionColumns());

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

    public function getTopTrending(int $limit = 5): array {
        $limit = max(1, min(20, $limit));

        try {
            $sql = "SELECT sp.ma_san_pham AS id,
                           sp.ten_san_pham,
                           sp.gia_ban,
                           sp.link_hinh_anh,
                           COALESCE(th.ten_thuong_hieu, '') AS thuong_hieu,
                           COALESCE(sp.luot_xem, 0) AS luot_xem
                    FROM san_pham sp
                    LEFT JOIN thuong_hieu th ON sp.ma_thuong_hieu = th.ma_thuong_hieu
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
                            ORDER BY COALESCE(sp.so_luong_danh_gia, 0) DESC, sp.ten_san_pham ASC
                            LIMIT :limit";

            $st = $this->pdo->prepare($sqlFallback);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    public function searchLive(string $q, int $limit = 5): array {
        $limit = max(1, min(20, $limit));
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        $where = " WHERE 1=1 ";
        $params = [];
        $this->appendKeywordFilters($where, $params, $q, $this->buildSuggestionColumns());

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

    public function adminInsert($data): bool {
        $maSanPham = trim((string)($data['ma_san_pham'] ?? ''));
        $tenSanPham = trim((string)($data['ten_san_pham'] ?? ''));

        if ($maSanPham === '' || $tenSanPham === '') {
            return false;
        }

        $allowedColumns = [
            'ma_san_pham',
            'ten_san_pham',
            'ma_loai',
            'ma_thuong_hieu',
            'ma_nsx',
            'ma_noi_san_xuat',
            'ma_xuat_xu',
            'ma_danh_muc',
            'gia_ban',
            'gia_thi_truong',
            'tien_tiet_kiem',
            'phan_tram_giam',
            'diem_danh_gia',
            'so_luong_danh_gia',
            'dung_tich',
            'loai_da',
            'link_hinh_anh',
            'mo_ta',
            'thanh_phan_chinh',
            'thanh_phan_day_du',
            'hdsd',
            'attribute',
            'danh_muc_day_du',
        ];

        $fields = [];
        $placeholders = [];
        $params = [];

        foreach ($allowedColumns as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $value = is_string($data[$column]) ? trim($data[$column]) : $data[$column];
            if ($value === '') {
                $value = null;
            }

            $fields[] = $column;
            $placeholders[] = ':' . $column;
            $params[':' . $column] = $value;
        }

        if (!in_array('ma_san_pham', $fields, true) || !in_array('ten_san_pham', $fields, true)) {
            return false;
        }

        $sql = 'INSERT INTO san_pham (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $st = $this->pdo->prepare($sql);
        return $st->execute($params);
    }

    public function adminUpdate($id, $data): bool {
        $id = trim((string)$id);
        if ($id === '') {
            return false;
        }

        $allowedColumns = [
            'ten_san_pham',
            'ma_loai',
            'ma_thuong_hieu',
            'ma_nsx',
            'ma_noi_san_xuat',
            'ma_xuat_xu',
            'ma_danh_muc',
            'gia_ban',
            'gia_thi_truong',
            'tien_tiet_kiem',
            'phan_tram_giam',
            'diem_danh_gia',
            'so_luong_danh_gia',
            'dung_tich',
            'loai_da',
            'link_hinh_anh',
            'mo_ta',
            'thanh_phan_chinh',
            'thanh_phan_day_du',
            'hdsd',
            'attribute',
            'danh_muc_day_du',
        ];

        $setClauses = [];
        $params = [':id' => $id];

        foreach ($allowedColumns as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $value = is_string($data[$column]) ? trim($data[$column]) : $data[$column];
            if ($value === '') {
                $value = null;
            }

            $paramName = ':' . $column;
            $setClauses[] = $column . ' = ' . $paramName;
            $params[$paramName] = $value;
        }

        if (empty($setClauses)) {
            return false;
        }

        $sql = 'UPDATE san_pham SET ' . implode(', ', $setClauses) . ' WHERE ma_san_pham = :id';
        $st = $this->pdo->prepare($sql);
        return $st->execute($params);
    }

    public function adminDelete($id): bool {
        $id = trim((string)$id);
        if ($id === '') {
            return false;
        }

        $sql = 'DELETE FROM san_pham WHERE ma_san_pham = :id';
        $st = $this->pdo->prepare($sql);
        return $st->execute([':id' => $id]);
    }
}
