<?php

class QuanTri {
    private PDO $pdo;
    private array $columnCache = [];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ensureFeatureColumns();
    }

    private function ensureFeatureColumns(): void {
        $ddl = [
            "ALTER TABLE danh_gia ADD COLUMN IF NOT EXISTS phan_hoi TEXT",
            "ALTER TABLE danh_gia ADD COLUMN IF NOT EXISTS ma_nv_phan_hoi INTEGER REFERENCES nhan_vien(ma_nv)",
            "ALTER TABLE danh_gia ADD COLUMN IF NOT EXISTS ngay_phan_hoi TIMESTAMP",
        ];

        foreach ($ddl as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (Throwable $e) {
                // Keep runtime resilient if the DB user cannot alter schema.
            }
        }
    }

    private function getColumns(string $table): array {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }

        $sql = "SELECT column_name
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = :table";
        $st = $this->pdo->prepare($sql);
        $st->execute([':table' => $table]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $columns = [];
        foreach ($rows as $row) {
            $name = (string)($row['column_name'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        $this->columnCache[$table] = $columns;
        return $columns;
    }

    private function hasColumn(string $table, string $column): bool {
        $columns = $this->getColumns($table);
        return isset($columns[$column]);
    }

    private function buildSearchClause(array $columns, string $keyword, string $prefix = 'kw'): array {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['', []];
        }

        $parts = preg_split('/\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($parts)) {
            $parts = [$keyword];
        }

        $clauses = [];
        $params = [];
        foreach ($parts as $idx => $part) {
            $or = [];
            $param = ':' . $prefix . $idx;
            foreach ($columns as $column) {
                $or[] = "CAST($column AS TEXT) ILIKE $param";
            }
            $clauses[] = '(' . implode(' OR ', $or) . ')';
            $params[$param] = '%' . $part . '%';
        }

        return [' AND ' . implode(' AND ', $clauses) . ' ', $params];
    }

    private function resolveKhachHangByEmail(string $email, string $defaultName = 'Khach hang'): ?array {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $sql = "SELECT * FROM khach_hang WHERE LOWER(email) = LOWER(:email) LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':email' => $email]);
        $kh = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($kh) {
            return $kh;
        }

        $insert = "INSERT INTO khach_hang(ma_kh, ho_ten, email, created_at, updated_at)
                   VALUES (
                       COALESCE((SELECT MAX(ma_kh) FROM khach_hang), 0) + 1,
                       :ho_ten,
                       :email,
                       CURRENT_TIMESTAMP,
                       CURRENT_TIMESTAMP
                   )";
        $stInsert = $this->pdo->prepare($insert);
        $ok = $stInsert->execute([
            ':ho_ten' => $defaultName,
            ':email' => $email,
        ]);

        if (!$ok) {
            return null;
        }

        $st->execute([':email' => $email]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getCustomerByEmail(string $email, string $defaultName = 'Khach hang'): ?array {
        return $this->resolveKhachHangByEmail($email, $defaultName);
    }

    public function getDashboardSummary(): array {
        $summary = [
            'tong_san_pham' => 0,
            'tong_danh_muc' => 0,
            'tong_khach_hang' => 0,
            'tong_nhan_vien' => 0,
            'tong_don_hang' => 0,
            'don_cho_xu_ly' => 0,
            'tong_doanh_thu' => 0,
            'chat_cho_tra_loi' => 0,
            'danh_gia_cho_phan_hoi' => 0,
        ];

        $queries = [
            'tong_san_pham' => 'SELECT COUNT(*) FROM san_pham',
            'tong_danh_muc' => 'SELECT COUNT(*) FROM danh_muc',
            'tong_khach_hang' => 'SELECT COUNT(*) FROM khach_hang',
            'tong_nhan_vien' => 'SELECT COUNT(*) FROM nhan_vien WHERE deleted_at IS NULL OR deleted_at IS NULL',
            'tong_don_hang' => 'SELECT COUNT(*) FROM hoa_don',
            'tong_doanh_thu' => "SELECT COALESCE(SUM(tong_tien), 0) FROM hoa_don WHERE LOWER(COALESCE(trang_thai, '')) NOT IN ('da huy', 'huy')",
            'chat_cho_tra_loi' => 'SELECT COUNT(DISTINCT ma_kh) FROM lich_su_chat WHERE ma_nv IS NULL',
        ];

        foreach ($queries as $key => $sql) {
            try {
                $summary[$key] = (float)$this->pdo->query($sql)->fetchColumn();
            } catch (Throwable $e) {
                $summary[$key] = 0;
            }
        }

        try {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM hoa_don WHERE LOWER(COALESCE(trang_thai, '')) IN ('cho xu ly', 'chờ xử lý', 'moi')");
            $st->execute();
            $summary['don_cho_xu_ly'] = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $summary['don_cho_xu_ly'] = 0;
        }

        try {
            $columnReply = $this->hasColumn('danh_gia', 'phan_hoi') ? 'phan_hoi' : 'NULL';
            $sql = "SELECT COUNT(*) FROM danh_gia WHERE COALESCE(TRIM(CAST($columnReply AS TEXT)), '') = ''";
            $summary['danh_gia_cho_phan_hoi'] = (int)$this->pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            $summary['danh_gia_cho_phan_hoi'] = 0;
        }

        return $summary;
    }

    public function getRevenueByMonth(int $limit = 6): array {
        $limit = max(1, min(24, $limit));
        $sql = "SELECT TO_CHAR(DATE_TRUNC('month', COALESCE(ngay_dat, created_at, CURRENT_TIMESTAMP)), 'MM/YYYY') AS thang,
                       COALESCE(SUM(tong_tien), 0) AS doanh_thu,
                       COUNT(*) AS so_don
                FROM hoa_don
                WHERE LOWER(COALESCE(trang_thai, '')) NOT IN ('da huy', 'huy')
                GROUP BY DATE_TRUNC('month', COALESCE(ngay_dat, created_at, CURRENT_TIMESTAMP))
                ORDER BY DATE_TRUNC('month', COALESCE(ngay_dat, created_at, CURRENT_TIMESTAMP)) DESC
                LIMIT :limit";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getTopProductsByRevenue(int $limit = 8): array {
        $limit = max(1, min(20, $limit));
        $sql = "SELECT sp.ma_san_pham,
                       sp.ten_san_pham,
                       COUNT(ct.id) AS so_don_vi,
                       COALESCE(SUM(ct.so_luong * ct.don_gia), 0) AS doanh_thu
                FROM chi_tiet_hoa_don ct
                INNER JOIN san_pham sp ON sp.ma_san_pham = ct.ma_san_pham
                GROUP BY sp.ma_san_pham, sp.ten_san_pham
                ORDER BY doanh_thu DESC, so_don_vi DESC
                LIMIT :limit";
        $st = $this->pdo->prepare($sql);
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listCategories(string $keyword = ''): array {
        [$searchSql, $params] = $this->buildSearchClause(['ma_danh_muc', 'ten_danh_muc', 'mo_ta', 'status'], $keyword, 'cat');
        $sql = "SELECT * FROM danh_muc WHERE 1=1 $searchSql ORDER BY ma_danh_muc DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getCategoryById(int $id): ?array {
        $st = $this->pdo->prepare('SELECT * FROM danh_muc WHERE ma_danh_muc = :id LIMIT 1');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveCategory(array $data, ?int $id = null): bool {
        $name = trim((string)($data['ten_danh_muc'] ?? ''));
        $desc = trim((string)($data['mo_ta'] ?? ''));
        $status = trim((string)($data['status'] ?? 'active'));
        if ($name === '') {
            return false;
        }

        if ($id !== null && $id > 0) {
            $sql = "UPDATE danh_muc
                    SET ten_danh_muc = :ten_danh_muc,
                        mo_ta = :mo_ta,
                        status = :status
                    WHERE ma_danh_muc = :id";
            $st = $this->pdo->prepare($sql);
            return $st->execute([
                ':ten_danh_muc' => $name,
                ':mo_ta' => ($desc !== '' ? $desc : null),
                ':status' => ($status !== '' ? $status : 'active'),
                ':id' => $id,
            ]);
        }

        $sql = "INSERT INTO danh_muc(ten_danh_muc, mo_ta, status)
                VALUES (:ten_danh_muc, :mo_ta, :status)";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            ':ten_danh_muc' => $name,
            ':mo_ta' => ($desc !== '' ? $desc : null),
            ':status' => ($status !== '' ? $status : 'active'),
        ]);
    }

    public function deleteCategory(int $id): bool {
        $st = $this->pdo->prepare('DELETE FROM danh_muc WHERE ma_danh_muc = :id');
        return $st->execute([':id' => $id]);
    }

    public function listCustomers(string $keyword = ''): array {
        [$searchSql, $params] = $this->buildSearchClause(['kh.ma_kh', 'kh.ho_ten', 'kh.email', 'kh.so_dien_thoai', 'kh.dia_chi'], $keyword, 'cus');
        $sql = "SELECT kh.*,
                       COUNT(DISTINCT hd.ma_hoa_don) AS tong_don,
                       COALESCE(SUM(hd.tong_tien), 0) AS tong_chi_tieu
                FROM khach_hang kh
                LEFT JOIN hoa_don hd ON hd.ma_kh = kh.ma_kh
                WHERE 1=1 $searchSql
                GROUP BY kh.ma_kh
                ORDER BY kh.ma_kh DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getCustomerById(int $id): ?array {
        $st = $this->pdo->prepare('SELECT * FROM khach_hang WHERE ma_kh = :id LIMIT 1');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveCustomer(array $data, ?int $id = null): bool {
        $name = trim((string)($data['ho_ten'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        if ($name === '') {
            return false;
        }

        $payload = [
            ':ho_ten' => $name,
            ':email' => ($email !== '' ? $email : null),
            ':so_dien_thoai' => trim((string)($data['so_dien_thoai'] ?? '')) ?: null,
            ':gioi_tinh' => trim((string)($data['gioi_tinh'] ?? '')) ?: null,
            ':nam_sinh' => trim((string)($data['nam_sinh'] ?? '')) !== '' ? (int)$data['nam_sinh'] : null,
            ':dia_chi' => trim((string)($data['dia_chi'] ?? '')) ?: null,
        ];

        if ($id !== null && $id > 0) {
            $sql = "UPDATE khach_hang
                    SET ho_ten = :ho_ten,
                        email = :email,
                        so_dien_thoai = :so_dien_thoai,
                        gioi_tinh = :gioi_tinh,
                        nam_sinh = :nam_sinh,
                        dia_chi = :dia_chi,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE ma_kh = :id";
            $payload[':id'] = $id;
            $st = $this->pdo->prepare($sql);
            return $st->execute($payload);
        }

        $sql = "INSERT INTO khach_hang(ma_kh, ho_ten, email, so_dien_thoai, gioi_tinh, nam_sinh, dia_chi, created_at, updated_at)
                VALUES (
                    COALESCE((SELECT MAX(ma_kh) FROM khach_hang), 0) + 1,
                    :ho_ten,
                    :email,
                    :so_dien_thoai,
                    :gioi_tinh,
                    :nam_sinh,
                    :dia_chi,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )";
        $st = $this->pdo->prepare($sql);
        return $st->execute($payload);
    }

    public function deleteCustomer(int $id): bool {
        $st = $this->pdo->prepare('DELETE FROM khach_hang WHERE ma_kh = :id');
        return $st->execute([':id' => $id]);
    }

    public function listRoles(): array {
        $st = $this->pdo->query('SELECT * FROM vai_tro ORDER BY ma_vai_tro ASC');
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function listStaff(string $keyword = ''): array {
        [$searchSql, $params] = $this->buildSearchClause(['nv.ma_nv', 'nv.ho_ten', 'nv.email', 'nv.so_dien_thoai', 'vt.ten_vai_tro'], $keyword, 'staff');
        $sql = "SELECT nv.*, vt.ten_vai_tro
                FROM nhan_vien nv
                LEFT JOIN vai_tro vt ON vt.ma_vai_tro = nv.ma_vai_tro
                WHERE nv.deleted_at IS NULL $searchSql
                ORDER BY nv.ma_nv DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getStaffById(int $id): ?array {
        $sql = "SELECT nv.*, vt.ten_vai_tro
                FROM nhan_vien nv
                LEFT JOIN vai_tro vt ON vt.ma_vai_tro = nv.ma_vai_tro
                WHERE nv.ma_nv = :id
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveStaff(array $data, ?int $id = null): bool {
        $name = trim((string)($data['ho_ten'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['mat_khau'] ?? '');
        $roleId = (int)($data['ma_vai_tro'] ?? 0);
        if ($name === '' || $email === '' || $roleId <= 0) {
            return false;
        }

        if ($id !== null && $id > 0) {
            $fields = [
                'ho_ten = :ho_ten',
                'email = :email',
                'so_dien_thoai = :so_dien_thoai',
                'ma_vai_tro = :ma_vai_tro',
                'trang_thai = :trang_thai',
                'updated_at = CURRENT_TIMESTAMP',
            ];
            $params = [
                ':ho_ten' => $name,
                ':email' => $email,
                ':so_dien_thoai' => trim((string)($data['so_dien_thoai'] ?? '')) ?: null,
                ':ma_vai_tro' => $roleId,
                ':trang_thai' => trim((string)($data['trang_thai'] ?? 'active')) ?: 'active',
                ':id' => $id,
            ];
            if ($password !== '') {
                $fields[] = 'mat_khau = :mat_khau';
                $params[':mat_khau'] = password_hash($password, PASSWORD_BCRYPT);
            }

            $sql = 'UPDATE nhan_vien SET ' . implode(', ', $fields) . ' WHERE ma_nv = :id';
            $st = $this->pdo->prepare($sql);
            return $st->execute($params);
        }

        if ($password === '') {
            return false;
        }

        $sql = "INSERT INTO nhan_vien(ho_ten, email, so_dien_thoai, mat_khau, ma_vai_tro, trang_thai, created_at, updated_at)
                VALUES (:ho_ten, :email, :so_dien_thoai, :mat_khau, :ma_vai_tro, :trang_thai, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            ':ho_ten' => $name,
            ':email' => $email,
            ':so_dien_thoai' => trim((string)($data['so_dien_thoai'] ?? '')) ?: null,
            ':mat_khau' => password_hash($password, PASSWORD_BCRYPT),
            ':ma_vai_tro' => $roleId,
            ':trang_thai' => trim((string)($data['trang_thai'] ?? 'active')) ?: 'active',
        ]);
    }

    public function deleteStaff(int $id): bool {
        $sql = "UPDATE nhan_vien
                SET deleted_at = CURRENT_TIMESTAMP,
                    trang_thai = 'deleted',
                    updated_at = CURRENT_TIMESTAMP
                WHERE ma_nv = :id";
        $st = $this->pdo->prepare($sql);
        return $st->execute([':id' => $id]);
    }

    public function listOrders(string $keyword = '', string $status = ''): array {
        [$searchSql, $params] = $this->buildSearchClause([
            'hd.ma_hoa_don',
            'kh.ho_ten',
            'kh.email',
            'hd.trang_thai',
            'hd.dia_chi_giao_hang'
        ], $keyword, 'ord');

        $statusSql = '';
        if (trim($status) !== '') {
            $statusSql = " AND LOWER(COALESCE(hd.trang_thai, '')) = LOWER(:status) ";
            $params[':status'] = trim($status);
        }

        $sql = "SELECT hd.*, kh.ho_ten, kh.email, kh.so_dien_thoai,
                       COALESCE(ct.so_dong_hang, 0) AS so_dong_hang
                FROM hoa_don hd
                LEFT JOIN khach_hang kh ON kh.ma_kh = hd.ma_kh
                LEFT JOIN (
                    SELECT ma_hoa_don, COUNT(id) AS so_dong_hang
                    FROM chi_tiet_hoa_don
                    GROUP BY ma_hoa_don
                ) ct ON ct.ma_hoa_don = hd.ma_hoa_don
                WHERE 1=1 $searchSql $statusSql
                ORDER BY COALESCE(hd.ngay_dat, hd.created_at) DESC NULLS LAST, hd.ma_hoa_don DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getOrderById(int $id): ?array {
        $sql = "SELECT hd.*, kh.ho_ten, kh.email, kh.so_dien_thoai
                FROM hoa_don hd
                LEFT JOIN khach_hang kh ON kh.ma_kh = hd.ma_kh
                WHERE hd.ma_hoa_don = :id
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['items'] = $this->getOrderItems($id);
        return $row;
    }

    public function getOrderItems(int $orderId): array {
        $sql = "SELECT ct.*, sp.ten_san_pham, sp.link_hinh_anh
                FROM chi_tiet_hoa_don ct
                LEFT JOIN san_pham sp ON sp.ma_san_pham = ct.ma_san_pham
                WHERE ct.ma_hoa_don = :id
                ORDER BY ct.id ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $orderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateOrderStatus(int $orderId, string $status): bool {
        $status = trim($status);
        if ($orderId <= 0 || $status === '') {
            return false;
        }

        $columns = $this->getColumns('hoa_don');
        $set = ['trang_thai = :status'];
        if (isset($columns['updated_at'])) {
            $set[] = 'updated_at = CURRENT_TIMESTAMP';
        }

        $sql = 'UPDATE hoa_don SET ' . implode(', ', $set) . ' WHERE ma_hoa_don = :id';
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            ':status' => $status,
            ':id' => $orderId,
        ]);
    }

    public function getOrderStatusOptions(): array {
        return [
            'Cho xu ly' => 'Chờ xử lý',
            'Da xac nhan' => 'Đã xác nhận',
            'Dang giao' => 'Đang giao',
            'Hoan thanh' => 'Hoàn thành',
            'Da huy' => 'Đã hủy',
        ];
    }

    public function listReviews(string $keyword = ''): array {
        $replyExpr = $this->hasColumn('danh_gia', 'phan_hoi') ? 'dg.phan_hoi' : 'NULL::text';
        $replyDateExpr = $this->hasColumn('danh_gia', 'ngay_phan_hoi') ? 'dg.ngay_phan_hoi' : 'NULL::timestamp';
        $replyStaffExpr = $this->hasColumn('danh_gia', 'ma_nv_phan_hoi') ? 'nv.ho_ten' : 'NULL::text';
        $replyStaffJoin = $this->hasColumn('danh_gia', 'ma_nv_phan_hoi')
            ? 'LEFT JOIN nhan_vien nv ON nv.ma_nv = dg.ma_nv_phan_hoi'
            : 'LEFT JOIN nhan_vien nv ON 1 = 0';
        $searchColumns = ['sp.ten_san_pham', 'kh.ho_ten', 'dg.noi_dung'];
        if ($this->hasColumn('danh_gia', 'phan_hoi')) {
            $searchColumns[] = 'dg.phan_hoi';
        }
        [$searchSql, $params] = $this->buildSearchClause($searchColumns, $keyword, 'rv');

        $sql = "SELECT dg.*, sp.ten_san_pham, kh.ho_ten AS ten_khach_hang,
                       $replyExpr AS phan_hoi,
                       $replyDateExpr AS ngay_phan_hoi,
                       $replyStaffExpr AS ten_nhan_vien_phan_hoi
                FROM danh_gia dg
                LEFT JOIN san_pham sp ON sp.ma_san_pham = dg.ma_san_pham
                LEFT JOIN khach_hang kh ON kh.ma_kh = dg.ma_kh
                $replyStaffJoin
                WHERE 1=1 $searchSql
                ORDER BY dg.ngay_danh_gia DESC NULLS LAST, dg.ma_danh_gia DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getProductReviews(string $productId): array {
        $replyExpr = $this->hasColumn('danh_gia', 'phan_hoi') ? 'dg.phan_hoi' : 'NULL::text';
        $sql = "SELECT dg.*, kh.ho_ten AS ten_khach_hang, $replyExpr AS phan_hoi
                FROM danh_gia dg
                LEFT JOIN khach_hang kh ON kh.ma_kh = dg.ma_kh
                WHERE dg.ma_san_pham = :product_id
                ORDER BY dg.ngay_danh_gia DESC NULLS LAST, dg.ma_danh_gia DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute([':product_id' => $productId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function refreshProductRating(string $productId): void {
        $sql = "UPDATE san_pham
                SET diem_danh_gia = src.avg_score,
                    so_luong_danh_gia = src.review_count
                FROM (
                    SELECT ma_san_pham,
                           ROUND(AVG(so_sao)::numeric, 1) AS avg_score,
                           COUNT(*)::int AS review_count
                    FROM danh_gia
                    WHERE ma_san_pham = :product_id
                    GROUP BY ma_san_pham
                ) src
                WHERE san_pham.ma_san_pham = src.ma_san_pham";
        $st = $this->pdo->prepare($sql);
        $st->execute([':product_id' => $productId]);
    }

    public function createReview(string $customerEmail, string $productId, int $stars, string $content): array {
        $kh = $this->resolveKhachHangByEmail($customerEmail, 'Khach hang');
        if (!$kh || empty($kh['ma_kh'])) {
            return ['ok' => false, 'message' => 'Không xác định được khách hàng để gửi đánh giá.'];
        }

        $stars = max(1, min(5, $stars));
        $content = trim($content);
        if ($content === '') {
            return ['ok' => false, 'message' => 'Nội dung đánh giá không được để trống.'];
        }

        $sql = "INSERT INTO danh_gia(ma_san_pham, ma_kh, so_sao, noi_dung, ngay_danh_gia)
                VALUES (:product_id, :ma_kh, :so_sao, :noi_dung, CURRENT_TIMESTAMP)";
        $st = $this->pdo->prepare($sql);
        $ok = $st->execute([
            ':product_id' => $productId,
            ':ma_kh' => (int)$kh['ma_kh'],
            ':so_sao' => $stars,
            ':noi_dung' => $content,
        ]);

        if ($ok) {
            $this->refreshProductRating($productId);
            return ['ok' => true, 'message' => 'Đã gửi đánh giá sản phẩm.'];
        }

        return ['ok' => false, 'message' => 'Không thể gửi đánh giá lúc này.'];
    }

    public function replyReview(int $reviewId, int $staffId, string $reply): bool {
        if (!$this->hasColumn('danh_gia', 'phan_hoi')) {
            return false;
        }

        $sql = "UPDATE danh_gia
                SET phan_hoi = :phan_hoi,
                    ma_nv_phan_hoi = :ma_nv,
                    ngay_phan_hoi = CURRENT_TIMESTAMP
                WHERE ma_danh_gia = :id";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            ':phan_hoi' => trim($reply),
            ':ma_nv' => $staffId > 0 ? $staffId : null,
            ':id' => $reviewId,
        ]);
    }

    public function listChatConversations(): array {
        $sql = "SELECT kh.ma_kh,
                       kh.ho_ten,
                       kh.email,
                       MAX(chat.thoi_gian) AS cap_nhat_cuoi,
                       SUM(CASE WHEN chat.ma_nv IS NULL THEN 1 ELSE 0 END) AS tin_chua_phan_hoi,
                       MAX(chat.noi_dung) AS tin_nhan_moi
                FROM khach_hang kh
                INNER JOIN lich_su_chat chat ON chat.ma_kh = kh.ma_kh
                GROUP BY kh.ma_kh, kh.ho_ten, kh.email
                ORDER BY MAX(chat.thoi_gian) DESC NULLS LAST, kh.ma_kh DESC";
        $st = $this->pdo->query($sql);
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function getChatMessages(int $maKh): array {
        $sql = "SELECT chat.*, nv.ho_ten AS ten_nhan_vien, kh.ho_ten AS ten_khach_hang
                FROM lich_su_chat chat
                LEFT JOIN nhan_vien nv ON nv.ma_nv = chat.ma_nv
                LEFT JOIN khach_hang kh ON kh.ma_kh = chat.ma_kh
                WHERE chat.ma_kh = :ma_kh
                ORDER BY chat.thoi_gian ASC, chat.ma_chat ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute([':ma_kh' => $maKh]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function sendCustomerChat(string $customerEmail, string $content): array {
        $kh = $this->resolveKhachHangByEmail($customerEmail, 'Khach hang');
        if (!$kh || empty($kh['ma_kh'])) {
            return ['ok' => false, 'message' => 'Không xác định được khách hàng để gửi tin nhắn.'];
        }

        $content = trim($content);
        if ($content === '') {
            return ['ok' => false, 'message' => 'Tin nhắn không được để trống.'];
        }

        $sql = "INSERT INTO lich_su_chat(ma_kh, ma_nv, noi_dung, thoi_gian)
                VALUES (:ma_kh, NULL, :noi_dung, CURRENT_TIMESTAMP)";
        $st = $this->pdo->prepare($sql);
        $ok = $st->execute([
            ':ma_kh' => (int)$kh['ma_kh'],
            ':noi_dung' => $content,
        ]);

        return $ok
            ? ['ok' => true, 'message' => 'Đã gửi tin nhắn hỗ trợ.']
            : ['ok' => false, 'message' => 'Không thể gửi tin nhắn lúc này.'];
    }

    public function sendStaffChat(int $maKh, int $staffId, string $content): bool {
        $content = trim($content);
        if ($maKh <= 0 || $staffId <= 0 || $content === '') {
            return false;
        }

        $sql = "INSERT INTO lich_su_chat(ma_kh, ma_nv, noi_dung, thoi_gian)
                VALUES (:ma_kh, :ma_nv, :noi_dung, CURRENT_TIMESTAMP)";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            ':ma_kh' => $maKh,
            ':ma_nv' => $staffId,
            ':noi_dung' => $content,
        ]);
    }
}