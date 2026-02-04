<?php
// backend/app/models/SanPham.php

class SanPham {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // Lấy sản phẩm mới (dùng id desc nếu created_at null)
    public function latest(int $limit = 8): array {
        $sql = "SELECT * FROM sanpham
                ORDER BY created_at DESC NULLS LAST, id DESC
                LIMIT :limit";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id) {
        $st = $this->pdo->prepare("SELECT * FROM sanpham WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC);
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
                FROM sanpham
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

        if ($q !== '') {
            $where .= " AND (ten_san_pham ILIKE :q OR thuong_hieu ILIKE :q) ";
            $params[':q'] = '%' . $q . '%';
        }
        if ($cap1Val !== '') {
            $where .= " AND ($cap1) = :cap1 ";
            $params[':cap1'] = $cap1Val;
        }
        if ($cap2Val !== '') {
            $where .= " AND ($cap2) = :cap2 ";
            $params[':cap2'] = $cap2Val;
        }

        // count
        $sqlCount = "SELECT COUNT(*)::int AS total FROM sanpham $where";
        $st1 = $this->pdo->prepare($sqlCount);
        $st1->execute($params);
        $total = (int)($st1->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        // items
        $sqlItems = "SELECT * FROM sanpham
                     $where
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
}
