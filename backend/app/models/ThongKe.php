<?php
// backend/app/models/ThongKe.php

class ThongKe {
    private PDO $pdo;
    private array $columnCache = [];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    private function hasColumn(string $table, string $column): bool {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnCache)) {
            return $this->columnCache[$key];
        }

        $sql = "SELECT 1
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = :table
                  AND column_name = :column
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);

        $exists = (bool)$stmt->fetchColumn();
        $this->columnCache[$key] = $exists;
        return $exists;
    }

    private function getUserDateColumn(): ?string {
        if ($this->hasColumn('nguoidung', 'ngay_tao')) {
            return 'ngay_tao';
        }

        if ($this->hasColumn('nguoidung', 'created_at')) {
            return 'created_at';
        }

        return null;
    }

    private function getProductOrderExpr(): string {
        if ($this->hasColumn('san_pham', 'ngay_tao')) {
            return 'ngay_tao DESC NULLS LAST, ma_san_pham DESC';
        }

        if ($this->hasColumn('san_pham', 'created_at')) {
            return 'created_at DESC NULLS LAST, ma_san_pham DESC';
        }

        return 'ma_san_pham DESC';
    }

    private function getProductImageExpr(): string {
        if ($this->hasColumn('san_pham', 'hinh_anh')) {
            return 'hinh_anh';
        }

        if ($this->hasColumn('san_pham', 'link_hinh_anh')) {
            return 'link_hinh_anh';
        }

        return "''";
    }

    private function getProductStatusExpr(): string {
        if ($this->hasColumn('san_pham', 'trang_thai')) {
            return 'trang_thai';
        }

        return "''";
    }

    public function getTongSanPham(): int {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM san_pham');
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function getTongNguoiDung(): int {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM nguoidung WHERE vai_tro = :vai_tro");
        $stmt->execute([':vai_tro' => 'khach_hang']);
        return (int)$stmt->fetchColumn();
    }

    public function getDoanhThu(): float {
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(tong_tien), 0) FROM hoa_don WHERE trang_thai = :trang_thai");
        $stmt->execute([':trang_thai' => 'Hoàn thành']);
        return (float)$stmt->fetchColumn();
    }

    public function getDonChoXuLy(): int {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM hoa_don WHERE trang_thai = :trang_thai");
        $stmt->execute([':trang_thai' => 'Chờ xử lý']);
        return (int)$stmt->fetchColumn();
    }

    public function getSanPhamMoi(int $limit = 5): array {
        $limit = max(1, min(50, $limit));

        $imgExpr = $this->getProductImageExpr();
        $statusExpr = $this->getProductStatusExpr();
        $orderExpr = $this->getProductOrderExpr();

        $sql = "SELECT ma_san_pham,
                       ten_san_pham,
                       {$imgExpr} AS hinh_anh,
                       {$statusExpr} AS trang_thai
                FROM san_pham
                ORDER BY {$orderExpr}
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getNguoiDungMoi(int $limit = 5): array {
        $limit = max(1, min(50, $limit));
        $dateColumn = $this->getUserDateColumn();

        if ($dateColumn === null) {
            $sql = "SELECT id, ho_ten, email, NULL::timestamp AS ngay_dang_ky, vai_tro
                    FROM nguoidung
                    ORDER BY id DESC
                    LIMIT :limit";
        } else {
            $sql = "SELECT id, ho_ten, email, {$dateColumn} AS ngay_dang_ky, vai_tro
                    FROM nguoidung
                    ORDER BY {$dateColumn} DESC NULLS LAST, id DESC
                    LIMIT :limit";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
