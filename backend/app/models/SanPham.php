<?php
// backend/app/models/SanPham.php

class SanPham {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function latest(int $limit = 8): array {
        $sql = "SELECT *
                FROM sanpham
                ORDER BY created_at DESC NULLS LAST, id DESC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function topCategories(int $limit = 12): array {
        $sql = "SELECT danh_muc_day_du, COUNT(*) AS so_luong
                FROM sanpham
                WHERE danh_muc_day_du IS NOT NULL AND danh_muc_day_du <> ''
                GROUP BY danh_muc_day_du
                ORDER BY so_luong DESC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM sanpham WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function paginateAll(int $page, int $perPage, string $q = '', string $danh_muc = ''): array {
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($q !== '') {
            $where[] = "(ten_san_pham ILIKE :q OR thuong_hieu ILIKE :q)";
            $params[':q'] = "%{$q}%";
        }

        if ($danh_muc !== '') {
            $where[] = "danh_muc_day_du = :dm";
            $params[':dm'] = $danh_muc;
        }

        $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

        $sqlItems = "SELECT *
                    FROM sanpham
                    {$whereSql}
                    ORDER BY id DESC
                    LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sqlItems);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sqlTotal = "SELECT COUNT(*) FROM sanpham {$whereSql}";
        $stmt2 = $this->pdo->prepare($sqlTotal);
        foreach ($params as $k => $v) $stmt2->bindValue($k, $v);
        $stmt2->execute();
        $total = (int)$stmt2->fetchColumn();

        return [$items, $total];
    }
}
