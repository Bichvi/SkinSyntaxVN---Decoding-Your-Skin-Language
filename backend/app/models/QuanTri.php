<?php

require_once __DIR__ . '/HoaDon.php';

class QuanTri {
    private PDO $pdo;
    private array $columnCache = [];
    private ?string $lastErrorMessage = null;
    private const VIP_THRESHOLD = 500;
    private const DIAMOND_THRESHOLD = 1500;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ensureFeatureColumns();
        $this->ensureLoyaltyColumns();
    }

    private function ensureFeatureColumns(): void {
        $ddl = [
            "ALTER TABLE danh_gia ADD COLUMN IF NOT EXISTS phan_hoi TEXT",
            "ALTER TABLE danh_gia ADD COLUMN IF NOT EXISTS ma_nv_phan_hoi INTEGER REFERENCES nhan_vien(ma_nv)",
            "ALTER TABLE danh_gia ADD COLUMN IF NOT EXISTS ngay_phan_hoi TIMESTAMP",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS ly_do_huy TEXT",
        ];

        foreach ($ddl as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (Throwable $e) {
                // Keep runtime resilient if the DB user cannot alter schema.
            }
        }
    }

    private function ensureLoyaltyColumns(): void {
        $ddl = [
            "ALTER TABLE khach_hang ADD COLUMN IF NOT EXISTS diemtl INTEGER DEFAULT 0",
            "ALTER TABLE khach_hang ADD COLUMN IF NOT EXISTS loaikh VARCHAR(30) DEFAULT 'Thuong'",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS diem_cong INTEGER DEFAULT 0",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS da_tich_diem BOOLEAN DEFAULT FALSE",
        ];

        foreach ($ddl as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (Throwable $e) {
                // Keep admin screens resilient if schema update cannot run.
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

    private function normalizeCustomerTier(int $points): string {
        if ($points >= self::DIAMOND_THRESHOLD) {
            return 'Kim Cuong';
        }

        if ($points >= self::VIP_THRESHOLD) {
            return 'VIP';
        }

        return 'Thuong';
    }

    private function grantReviewRewardPoint(int $customerId): void {
        if ($customerId <= 0) {
            return;
        }

        $khColumns = $this->getColumns('khach_hang');
        if (!isset($khColumns['diemtl'], $khColumns['loaikh'])) {
            return;
        }

        $stCurrent = $this->pdo->prepare('SELECT COALESCE(diemtl, 0) FROM khach_hang WHERE ma_kh = :ma_kh LIMIT 1');
        $stCurrent->execute([':ma_kh' => $customerId]);
        $updatedPoints = max(0, (int)$stCurrent->fetchColumn()) + 1;
        $tier = $this->normalizeCustomerTier($updatedPoints);

        $sqlUpdate = "UPDATE khach_hang
                      SET diemtl = :diemtl,
                          loaikh = :loaikh,
                          updated_at = CURRENT_TIMESTAMP
                      WHERE ma_kh = :ma_kh";
        $stUpdate = $this->pdo->prepare($sqlUpdate);
        $stUpdate->execute([
            ':diemtl' => $updatedPoints,
            ':loaikh' => $tier,
            ':ma_kh' => $customerId,
        ]);
    }

    private function buildInPlaceholders(array $values, string $prefix): array {
        $placeholders = [];
        $params = [];

        foreach (array_values($values) as $index => $value) {
            $name = ':' . $prefix . $index;
            $placeholders[] = $name;
            $params[$name] = $value;
        }

        return [$placeholders, $params];
    }

    private function getCustomerScopeIds(int $customerId): array {
        $customerId = max(0, $customerId);
        if ($customerId <= 0) {
            return [];
        }

        $scopeIds = [$customerId];

        $sqlCustomer = "SELECT email FROM khach_hang WHERE ma_kh = :ma_kh LIMIT 1";
        $stCustomer = $this->pdo->prepare($sqlCustomer);
        $stCustomer->execute([':ma_kh' => $customerId]);
        $email = trim((string)$stCustomer->fetchColumn());
        if ($email === '') {
            return $scopeIds;
        }

        $sqlByEmail = "SELECT ma_kh FROM khach_hang WHERE LOWER(email) = LOWER(:email)";
        $stByEmail = $this->pdo->prepare($sqlByEmail);
        $stByEmail->execute([':email' => $email]);

        foreach ($stByEmail->fetchAll(PDO::FETCH_COLUMN) ?: [] as $maKh) {
            $maKh = (int)$maKh;
            if ($maKh > 0) {
                $scopeIds[] = $maKh;
            }
        }

        return array_values(array_unique($scopeIds));
    }

    private function deleteRowsByProductIds(string $table, string $column, array $productIds): void {
        if (empty($productIds) || !$this->hasColumn($table, $column)) {
            return;
        }

        [$placeholders, $params] = $this->buildInPlaceholders($productIds, $table . '_product_');
        $sql = 'DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (' . implode(', ', $placeholders) . ')';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    private function nullProductReferences(string $table, string $column, array $productIds): void {
        if (empty($productIds) || !$this->hasColumn($table, $column)) {
            return;
        }

        [$placeholders, $params] = $this->buildInPlaceholders($productIds, $table . '_product_ref_');
        $sql = 'UPDATE ' . $table . ' SET ' . $column . ' = NULL WHERE ' . $column . ' IN (' . implode(', ', $placeholders) . ')';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    private function countProductsByCategory(int $id): int {
        if (!$this->hasColumn('san_pham', 'ma_danh_muc')) {
            return 0;
        }

        $st = $this->pdo->prepare('SELECT COUNT(*) FROM san_pham WHERE ma_danh_muc = :id');
        $st->execute([':id' => $id]);
        return (int)$st->fetchColumn();
    }

    private function getProductIdsByCategory(int $id): array {
        if (!$this->hasColumn('san_pham', 'ma_danh_muc') || !$this->hasColumn('san_pham', 'ma_san_pham')) {
            return [];
        }

        $st = $this->pdo->prepare('SELECT ma_san_pham FROM san_pham WHERE ma_danh_muc = :id');
        $st->execute([':id' => $id]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_values(array_filter(array_map('strval', $rows), static fn(string $value): bool => trim($value) !== ''));
    }

    public function getLastErrorMessage(): ?string {
        return $this->lastErrorMessage;
    }

    private function normalizeCategoryRecord(array $row): array {
        if (!array_key_exists('mo_ta', $row)) {
            $row['mo_ta'] = '';
        }

        if (!array_key_exists('status', $row) || trim((string)($row['status'] ?? '')) === '') {
            $row['status'] = 'active';
        }

        $row['so_san_pham'] = (int)($row['so_san_pham'] ?? 0);

        return $row;
    }

    private function getCategorySearchColumns(): array {
        $columns = ['ma_danh_muc', 'ten_danh_muc'];

        if ($this->hasColumn('danh_muc', 'mo_ta')) {
            $columns[] = 'mo_ta';
        }

        if ($this->hasColumn('danh_muc', 'status')) {
            $columns[] = 'status';
        }

        return $columns;
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

    private function syncCustomerAccountsFromNguoiDung(): void {
        try {
            $sql = "SELECT nd.ho_ten, nd.email
                    FROM nguoidung nd
                    LEFT JOIN khach_hang kh ON LOWER(kh.email) = LOWER(nd.email)
                    LEFT JOIN nhan_vien nv ON LOWER(nv.email) = LOWER(nd.email)
                    WHERE kh.ma_kh IS NULL
                      AND nv.ma_nv IS NULL
                      AND COALESCE(TRIM(nd.email), '') <> ''
                    ORDER BY nd.id DESC";
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $email = trim((string)($row['email'] ?? ''));
                if ($email === '') {
                    continue;
                }

                $this->resolveKhachHangByEmail($email, trim((string)($row['ho_ten'] ?? '')) ?: 'Khach hang');
            }
        } catch (Throwable $e) {
            // Keep admin listing resilient if sync cannot run.
        }
    }

    private function syncNguoiDungByCustomer(int $id, array $data): void {
        $customer = $this->getCustomerById($id);
        if (!$customer) {
            return;
        }

        $newEmail = trim((string)($customer['email'] ?? ''));
        $oldEmail = trim((string)($data['__old_email'] ?? ''));
        $name = trim((string)($customer['ho_ten'] ?? ''));

        if ($newEmail === '' && $oldEmail === '') {
            return;
        }

        $targetEmail = $newEmail !== '' ? $newEmail : $oldEmail;
        if ($targetEmail === '') {
            return;
        }

        $findSql = 'SELECT id FROM nguoidung WHERE LOWER(email) = LOWER(:email) LIMIT 1';
        $find = $this->pdo->prepare($findSql);

        $authId = null;
        if ($oldEmail !== '') {
            $find->execute([':email' => $oldEmail]);
            $authId = $find->fetchColumn() ?: null;
        }

        if (!$authId && $newEmail !== '') {
            $find->execute([':email' => $newEmail]);
            $authId = $find->fetchColumn() ?: null;
        }

        if (!$authId) {
            return;
        }

        $update = $this->pdo->prepare('UPDATE nguoidung SET ho_ten = :ho_ten, email = :email WHERE id = :id');
        $update->execute([
            ':ho_ten' => $name !== '' ? $name : null,
            ':email' => $targetEmail,
            ':id' => $authId,
        ]);
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
            $sql = "SELECT COUNT(*)
                    FROM (
                        SELECT DISTINCT ON (chat.ma_kh) chat.ma_kh, chat.ma_nv
                        FROM lich_su_chat chat
                        ORDER BY chat.ma_kh, chat.thoi_gian DESC NULLS LAST, chat.ma_chat DESC
                    ) latest_chat
                    WHERE latest_chat.ma_nv IS NULL";
            $summary['chat_cho_tra_loi'] = (int)$this->pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            $summary['chat_cho_tra_loi'] = 0;
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

    public function getNotificationCenterData(int $orderLimit = 5, int $chatLimit = 5): array {
        $orderLimit = max(1, min(10, $orderLimit));
        $chatLimit = max(1, min(10, $chatLimit));

        $orders = [];
        $conversations = [];
        $pendingOrdersCount = 0;
        $pendingChatsCount = 0;

        try {
            $sql = "SELECT COUNT(*)
                    FROM hoa_don hd
                    WHERE LOWER(COALESCE(hd.trang_thai, '')) IN ('cho xu ly', 'chờ xử lý', 'moi')";
            $pendingOrdersCount = (int)$this->pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            $pendingOrdersCount = 0;
        }

        try {
            $sql = "SELECT COUNT(*)
                    FROM (
                        SELECT DISTINCT ON (chat.ma_kh) chat.ma_kh, chat.ma_nv
                        FROM lich_su_chat chat
                        ORDER BY chat.ma_kh, chat.thoi_gian DESC NULLS LAST, chat.ma_chat DESC
                    ) pending_chat";
            $pendingChatsCount = (int)$this->pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            $pendingChatsCount = 0;
        }

        try {
            $sql = "SELECT COUNT(*)
                    FROM (
                        SELECT DISTINCT ON (chat.ma_kh) chat.ma_kh, chat.ma_nv
                        FROM lich_su_chat chat
                        ORDER BY chat.ma_kh, chat.thoi_gian DESC NULLS LAST, chat.ma_chat DESC
                    ) pending_chat
                    WHERE pending_chat.ma_nv IS NULL";
            $pendingChatsCount = (int)$this->pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            $pendingChatsCount = 0;
        }

        try {
            $sql = "SELECT hd.ma_hoa_don,
                           hd.trang_thai,
                           COALESCE(hd.ngay_dat, hd.created_at) AS thoi_gian,
                           COALESCE(hd.tong_tien, 0) AS tong_tien,
                           kh.ho_ten,
                           kh.email
                    FROM hoa_don hd
                    LEFT JOIN khach_hang kh ON kh.ma_kh = hd.ma_kh
                    WHERE LOWER(COALESCE(hd.trang_thai, '')) IN ('cho xu ly', 'chờ xử lý', 'moi')
                    ORDER BY COALESCE(hd.ngay_dat, hd.created_at) DESC NULLS LAST, hd.ma_hoa_don DESC
                    LIMIT :limit";
            $st = $this->pdo->prepare($sql);
            $st->bindValue(':limit', $orderLimit, PDO::PARAM_INT);
            $st->execute();
            $orders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $orders = [];
        }

        $conversations = $this->listChatConversations(true, $chatLimit);

        $latestOrder = $orders[0] ?? [];
        $latestChat = $conversations[0] ?? [];

        return [
            'pending_orders_count' => $pendingOrdersCount,
            'pending_chats_count' => $pendingChatsCount,
            'orders' => $orders,
            'chats' => $conversations,
            'latest_order_marker' => ($latestOrder['ma_hoa_don'] ?? '') . '|' . ($latestOrder['thoi_gian'] ?? ''),
            'latest_chat_marker' => ($latestChat['ma_kh'] ?? '') . '|' . ($latestChat['cap_nhat_cuoi'] ?? ''),
        ];
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
        [$searchSql, $params] = $this->buildSearchClause($this->getCategorySearchColumns(), $keyword, 'cat');
        $productCountSql = $this->hasColumn('san_pham', 'ma_danh_muc')
            ? '(SELECT COUNT(*) FROM san_pham sp WHERE sp.ma_danh_muc = danh_muc.ma_danh_muc)'
            : '0';
        $sql = "SELECT danh_muc.*, $productCountSql AS so_san_pham
                FROM danh_muc
                WHERE 1=1 $searchSql
                ORDER BY ma_danh_muc DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn(array $row): array => $this->normalizeCategoryRecord($row), $rows);
    }

    public function getCategoryById(int $id): ?array {
        $st = $this->pdo->prepare('SELECT * FROM danh_muc WHERE ma_danh_muc = :id LIMIT 1');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeCategoryRecord($row) : null;
    }

    public function saveCategory(array $data, ?int $id = null): bool {
        $this->lastErrorMessage = null;

        $name = trim((string)($data['ten_danh_muc'] ?? ''));
        $desc = trim((string)($data['mo_ta'] ?? ''));
        $status = trim((string)($data['status'] ?? 'active'));
        if ($name === '') {
            $this->lastErrorMessage = 'Vui lòng nhập tên danh mục.';
            return false;
        }

        // Prevent duplicate names before write to avoid SQLSTATE[23505].
        $duplicateSql = 'SELECT ma_danh_muc FROM danh_muc WHERE LOWER(TRIM(ten_danh_muc)) = LOWER(TRIM(:name))';
        $duplicateParams = [':name' => $name];
        if ($id !== null && $id > 0) {
            $duplicateSql .= ' AND ma_danh_muc <> :id';
            $duplicateParams[':id'] = $id;
        }
        $duplicateSql .= ' LIMIT 1';

        try {
            $stDuplicate = $this->pdo->prepare($duplicateSql);
            $stDuplicate->execute($duplicateParams);
            if ($stDuplicate->fetchColumn() !== false) {
                $this->lastErrorMessage = 'Tên danh mục đã tồn tại. Vui lòng nhập tên khác.';
                return false;
            }
        } catch (Throwable $e) {
            // Continue to save path; DB constraints will still protect consistency.
        }

        $categoryColumns = $this->getColumns('danh_muc');

        $payload = [
            'ten_danh_muc' => $name,
        ];

        if (isset($categoryColumns['mo_ta'])) {
            $payload['mo_ta'] = ($desc !== '' ? $desc : null);
        }

        if (isset($categoryColumns['status'])) {
            $payload['status'] = ($status !== '' ? $status : 'active');
        }

        if ($id !== null && $id > 0) {
            $setClauses = [];
            $params = [':id' => $id];
            foreach ($payload as $column => $value) {
                $setClauses[] = $column . ' = :' . $column;
                $params[':' . $column] = $value;
            }

            $sql = 'UPDATE danh_muc SET ' . implode(', ', $setClauses) . ' WHERE ma_danh_muc = :id';
            try {
                $st = $this->pdo->prepare($sql);
                return $st->execute($params);
            } catch (Throwable $e) {
                $code = (string)($e->getCode() ?? '');
                $message = strtolower((string)$e->getMessage());
                if ($code === '23505' || str_contains($message, 'danh_muc_ten_danh_muc_key')) {
                    $this->lastErrorMessage = 'Tên danh mục đã tồn tại. Vui lòng nhập tên khác.';
                    return false;
                }

                $this->lastErrorMessage = 'Không thể lưu danh mục lúc này.';
                return false;
            }
        }

        $fields = array_keys($payload);
        $placeholders = array_map(fn(string $field): string => ':' . $field, $fields);
        $sql = 'INSERT INTO danh_muc(' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $params = [];
        foreach ($payload as $column => $value) {
            $params[':' . $column] = $value;
        }

        try {
            $st = $this->pdo->prepare($sql);
            return $st->execute($params);
        } catch (Throwable $e) {
            $code = (string)($e->getCode() ?? '');
            $message = strtolower((string)$e->getMessage());
            if ($code === '23505' || str_contains($message, 'danh_muc_ten_danh_muc_key')) {
                $this->lastErrorMessage = 'Tên danh mục đã tồn tại. Vui lòng nhập tên khác.';
                return false;
            }

            $this->lastErrorMessage = 'Không thể lưu danh mục lúc này.';
            return false;
        }
    }

    public function deleteCategory(int $id, bool $deleteProducts = false): bool {
        $this->lastErrorMessage = null;

        $referencedCount = 0;

        try {
            $referencedCount = $this->countProductsByCategory($id);
        } catch (Throwable $e) {
            $referencedCount = 0;
        }

        if ($referencedCount > 0 && !$deleteProducts) {
            $this->lastErrorMessage = 'Vui lòng xác nhận xóa danh mục. Toàn bộ ' . number_format($referencedCount, 0, ',', '.') . ' sản phẩm thuộc danh mục này sẽ bị xóa.';
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            if ($referencedCount > 0) {
                $productIds = $this->getProductIdsByCategory($id);

                if (!empty($productIds)) {
                    $this->deleteRowsByProductIds('gio_hang', 'ma_san_pham', $productIds);
                    $this->deleteRowsByProductIds('danh_gia', 'ma_san_pham', $productIds);
                    $this->nullProductReferences('chi_tiet_hoa_don', 'ma_san_pham', $productIds);

                    [$placeholders, $params] = $this->buildInPlaceholders($productIds, 'product_delete_');
                    $productSql = 'DELETE FROM san_pham WHERE ma_san_pham IN (' . implode(', ', $placeholders) . ')';
                    $productSt = $this->pdo->prepare($productSql);
                    $productSt->execute($params);
                }
            }

            $st = $this->pdo->prepare('DELETE FROM danh_muc WHERE ma_danh_muc = :id');
            $deleted = $st->execute([':id' => $id]);

            if ($deleted) {
                $this->pdo->commit();
                return true;
            }

            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->lastErrorMessage = 'Không thể xóa danh mục lúc này.';
            return false;
        } catch (Throwable $e) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable $inner) {
                // Ignore rollback failures and report the main delete error.
            }

            if ($referencedCount > 0) {
                $this->lastErrorMessage = 'Không thể xóa danh mục và sản phẩm liên quan lúc này.';
            } else {
                $this->lastErrorMessage = 'Không thể xóa danh mục lúc này.';
            }

            return false;
        }
    }

    public function listCustomers(string $keyword = '', string $loaiKh = ''): array {
        $this->syncCustomerAccountsFromNguoiDung();

        [$searchSql, $params] = $this->buildSearchClause(['kh.ma_kh', 'kh.ho_ten', 'kh.email', 'kh.so_dien_thoai', 'kh.dia_chi'], $keyword, 'cus');
        $loaiKhSql = '';
        $loaiKh = trim($loaiKh);
        if ($loaiKh !== '') {
            $loaiKhSql = " AND LOWER(COALESCE(kh.loaikh, 'Thuong')) = LOWER(:loai_kh) ";
            $params[':loai_kh'] = $loaiKh;
        }

        $sql = "SELECT kh.*,
                       COUNT(DISTINCT hd.ma_hoa_don) AS tong_don,
                       COALESCE(SUM(hd.tong_tien), 0) AS tong_chi_tieu
                FROM khach_hang kh
                LEFT JOIN hoa_don hd ON hd.ma_kh = kh.ma_kh
                WHERE 1=1 $searchSql $loaiKhSql
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

        $oldEmail = '';
        if ($id !== null && $id > 0) {
            $existing = $this->getCustomerById($id);
            $oldEmail = trim((string)($existing['email'] ?? ''));
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
            $ok = $st->execute($payload);

            if ($ok) {
                $data['__old_email'] = $oldEmail;
                $this->syncNguoiDungByCustomer($id, $data);
            }

            return $ok;
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
        $ok = $st->execute($payload);

        if ($ok && $email !== '') {
            $this->resolveKhachHangByEmail($email, $name);
        }

        return $ok;
    }

    public function deleteCustomer(int $id): bool {
        $customer = $this->getCustomerById($id);
        $email = trim((string)($customer['email'] ?? ''));

        try {
            $this->pdo->beginTransaction();

            $st = $this->pdo->prepare('DELETE FROM khach_hang WHERE ma_kh = :id');
            $ok = $st->execute([':id' => $id]);

            if (!$ok) {
                $this->pdo->rollBack();
                return false;
            }

            if ($email !== '') {
                $staffCheck = $this->pdo->prepare('SELECT ma_nv FROM nhan_vien WHERE LOWER(email) = LOWER(:email) LIMIT 1');
                $staffCheck->execute([':email' => $email]);
                $staffId = $staffCheck->fetchColumn();

                if (!$staffId) {
                    $deleteAuth = $this->pdo->prepare('DELETE FROM nguoidung WHERE LOWER(email) = LOWER(:email)');
                    $deleteAuth->execute([':email' => $email]);
                }
            }

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return false;
        }
    }

    public function listRoles(): array {
        $sql = "SELECT DISTINCT ON (LOWER(TRIM(COALESCE(ten_vai_tro, ''))))
                       ma_vai_tro,
                       ten_vai_tro,
                       mo_ta
                FROM vai_tro
                WHERE COALESCE(TRIM(ten_vai_tro), '') <> ''
                ORDER BY LOWER(TRIM(COALESCE(ten_vai_tro, ''))) ASC, ma_vai_tro ASC";
        $st = $this->pdo->query($sql);
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function listStaff(string $keyword = ''): array {
        [$searchSql, $params] = $this->buildSearchClause(['nv.ma_nv', 'nv.ho_ten', 'nv.email', 'nv.so_dien_thoai', 'vt.ten_vai_tro'], $keyword, 'staff');
        $where = [];

        if ($this->hasColumn('nhan_vien', 'deleted_at')) {
            $where[] = 'nv.deleted_at IS NULL';
        } elseif ($this->hasColumn('nhan_vien', 'trang_thai')) {
            $where[] = "LOWER(COALESCE(nv.trang_thai, '')) <> 'deleted'";
        }

        $sql = "SELECT nv.*, vt.ten_vai_tro
                FROM nhan_vien nv
                LEFT JOIN vai_tro vt ON vt.ma_vai_tro = nv.ma_vai_tro
                WHERE " . (!empty($where) ? implode(' AND ', $where) : '1=1') . " $searchSql
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

    public function isStaffAccountActive(int $id): bool {
        $columns = $this->getColumns('nhan_vien');
        $select = ['ma_nv'];

        if (isset($columns['trang_thai'])) {
            $select[] = 'trang_thai';
        }
        if (isset($columns['deleted_at'])) {
            $select[] = 'deleted_at';
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM nhan_vien WHERE ma_nv = :id LIMIT 1';
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $status = strtolower(trim((string)($row['trang_thai'] ?? 'active')));
        if ($status !== '' && in_array($status, ['inactive', 'deleted', 'locked', 'disabled', 'tam_khoa'], true)) {
            return false;
        }

        return empty($row['deleted_at']);
    }

    public function saveStaff(array $data, ?int $id = null): bool {
        $this->lastErrorMessage = null;
        $name = trim((string)($data['ho_ten'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['mat_khau'] ?? '');
        $roleId = (int)($data['ma_vai_tro'] ?? 0);
        if ($name === '' || $email === '' || $roleId <= 0) {
            $this->lastErrorMessage = 'Vui lòng nhập đầy đủ họ tên, email và vai trò.';
            return false;
        }

        $columns = $this->getColumns('nhan_vien');
        $phone = trim((string)($data['so_dien_thoai'] ?? '')) ?: null;
        $status = trim((string)($data['trang_thai'] ?? 'active')) ?: 'active';

        try {
            if ($id !== null && $id > 0) {
                $fields = [];
                $params = [':id' => $id];

                if (isset($columns['ho_ten'])) {
                    $fields[] = 'ho_ten = :ho_ten';
                    $params[':ho_ten'] = $name;
                }
                if (isset($columns['email'])) {
                    $fields[] = 'email = :email';
                    $params[':email'] = $email;
                }
                if (isset($columns['so_dien_thoai'])) {
                    $fields[] = 'so_dien_thoai = :so_dien_thoai';
                    $params[':so_dien_thoai'] = $phone;
                }
                if (isset($columns['ma_vai_tro'])) {
                    $fields[] = 'ma_vai_tro = :ma_vai_tro';
                    $params[':ma_vai_tro'] = $roleId;
                }
                if (isset($columns['trang_thai'])) {
                    $fields[] = 'trang_thai = :trang_thai';
                    $params[':trang_thai'] = $status;
                }
                if ($password !== '' && isset($columns['mat_khau'])) {
                    $fields[] = 'mat_khau = :mat_khau';
                    $params[':mat_khau'] = password_hash($password, PASSWORD_BCRYPT);
                }
                if (isset($columns['updated_at'])) {
                    $fields[] = 'updated_at = CURRENT_TIMESTAMP';
                }

                if (empty($fields)) {
                    $this->lastErrorMessage = 'Không có dữ liệu nào có thể cập nhật cho nhân viên.';
                    return false;
                }

                $sql = 'UPDATE nhan_vien SET ' . implode(', ', $fields) . ' WHERE ma_nv = :id';
                $st = $this->pdo->prepare($sql);
                return $st->execute($params);
            }

            if ($password === '') {
                $this->lastErrorMessage = 'Vui lòng nhập mật khẩu khi tạo nhân viên mới.';
                return false;
            }

            $insertFields = [];
            $insertPlaceholders = [];
            $params = [];

            if (isset($columns['ho_ten'])) {
                $insertFields[] = 'ho_ten';
                $insertPlaceholders[] = ':ho_ten';
                $params[':ho_ten'] = $name;
            }
            if (isset($columns['email'])) {
                $insertFields[] = 'email';
                $insertPlaceholders[] = ':email';
                $params[':email'] = $email;
            }
            if (isset($columns['so_dien_thoai'])) {
                $insertFields[] = 'so_dien_thoai';
                $insertPlaceholders[] = ':so_dien_thoai';
                $params[':so_dien_thoai'] = $phone;
            }
            if (isset($columns['mat_khau'])) {
                $insertFields[] = 'mat_khau';
                $insertPlaceholders[] = ':mat_khau';
                $params[':mat_khau'] = password_hash($password, PASSWORD_BCRYPT);
            }
            if (isset($columns['ma_vai_tro'])) {
                $insertFields[] = 'ma_vai_tro';
                $insertPlaceholders[] = ':ma_vai_tro';
                $params[':ma_vai_tro'] = $roleId;
            }
            if (isset($columns['trang_thai'])) {
                $insertFields[] = 'trang_thai';
                $insertPlaceholders[] = ':trang_thai';
                $params[':trang_thai'] = $status;
            }
            if (isset($columns['created_at'])) {
                $insertFields[] = 'created_at';
                $insertPlaceholders[] = 'CURRENT_TIMESTAMP';
            }
            if (isset($columns['updated_at'])) {
                $insertFields[] = 'updated_at';
                $insertPlaceholders[] = 'CURRENT_TIMESTAMP';
            }

            $sql = 'INSERT INTO nhan_vien(' . implode(', ', $insertFields) . ') VALUES (' . implode(', ', $insertPlaceholders) . ')';
            $st = $this->pdo->prepare($sql);
            return $st->execute($params);
        } catch (Throwable $e) {
            $message = trim((string)$e->getMessage());
            $this->lastErrorMessage = $message !== '' ? $message : 'Không thể lưu nhân viên lúc này.';
            return false;
        }
    }

    public function deleteStaff(int $id): bool {
        $this->lastErrorMessage = null;
        $columns = $this->getColumns('nhan_vien');
        $setClauses = [];

        if (isset($columns['trang_thai'])) {
            $setClauses[] = "trang_thai = 'inactive'";
        } elseif (isset($columns['deleted_at'])) {
            $setClauses[] = 'deleted_at = CURRENT_TIMESTAMP';
        }

        if (isset($columns['updated_at'])) {
            $setClauses[] = 'updated_at = CURRENT_TIMESTAMP';
        }

        if (empty($setClauses)) {
            $this->lastErrorMessage = 'Bảng nhân viên hiện không hỗ trợ ngừng kích hoạt.';
            return false;
        }

        try {
            $sql = 'UPDATE nhan_vien SET ' . implode(', ', $setClauses) . ' WHERE ma_nv = :id';
            $st = $this->pdo->prepare($sql);
            return $st->execute([':id' => $id]);
        } catch (Throwable $e) {
            $message = trim((string)$e->getMessage());
            $this->lastErrorMessage = $message !== '' ? $message : 'Không thể cập nhật trạng thái nhân viên.';
            return false;
        }
    }

    public function hardDeleteStaff(int $id): bool {
        $this->lastErrorMessage = null;

        if ($id <= 0) {
            $this->lastErrorMessage = 'Mã nhân viên không hợp lệ.';
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            if ($this->hasColumn('lich_su_chat', 'ma_nv')) {
                $chatSt = $this->pdo->prepare('UPDATE lich_su_chat SET ma_nv = NULL WHERE ma_nv = :id');
                $chatSt->execute([':id' => $id]);
            }

            if ($this->hasColumn('danh_gia', 'ma_nv_phan_hoi')) {
                $reviewSt = $this->pdo->prepare('UPDATE danh_gia SET ma_nv_phan_hoi = NULL WHERE ma_nv_phan_hoi = :id');
                $reviewSt->execute([':id' => $id]);
            }

            $deleteSt = $this->pdo->prepare('DELETE FROM nhan_vien WHERE ma_nv = :id');
            $deleteSt->execute([':id' => $id]);

            if ($deleteSt->rowCount() < 1) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->lastErrorMessage = 'Không tìm thấy nhân viên để xóa.';
                return false;
            }

            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable $inner) {
                // Ignore rollback failures and report the main delete error.
            }

            $message = trim((string)$e->getMessage());
            $this->lastErrorMessage = $message !== '' ? $message : 'Không thể xóa nhân viên lúc này.';
            return false;
        }
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

    public function updateOrderStatus(int $orderId, string $status, string $cancelReason = '', bool $allowCancelledOverride = false): bool {
        $this->lastErrorMessage = null;
        $status = trim($status);
        if ($orderId <= 0 || $status === '') {
            $this->lastErrorMessage = 'Du lieu cap nhat trang thai don hang khong hop le.';
            return false;
        }

        $normalized = strtolower($status);
        $isCancelled = in_array($normalized, ['da huy', 'đã hủy', 'huy', 'cancelled', 'canceled'], true);

        $currentSt = $this->pdo->prepare('SELECT trang_thai FROM hoa_don WHERE ma_hoa_don = :id LIMIT 1');
        $currentSt->execute([':id' => $orderId]);
        $currentStatusRaw = $currentSt->fetchColumn();
        if ($currentStatusRaw === false || $currentStatusRaw === null) {
            $this->lastErrorMessage = 'Khong tim thay don hang can cap nhat.';
            return false;
        }

        $currentStatus = strtolower(trim((string)$currentStatusRaw));
        $currentIsCancelled = in_array($currentStatus, ['da huy', 'đã hủy', 'huy', 'cancelled', 'canceled'], true);

        if (!$allowCancelledOverride && $currentIsCancelled && !$isCancelled) {
            $this->lastErrorMessage = 'Don hang da huy khong the chuyen sang trang thai khac.';
            return false;
        }

        $trimmedCancelReason = trim($cancelReason);
        if ($isCancelled && !$currentIsCancelled && $trimmedCancelReason === '') {
            $this->lastErrorMessage = 'Vui long chon ly do huy don hang.';
            return false;
        }

        $columns = $this->getColumns('hoa_don');
        $set = ['trang_thai = :status'];
        $params = [
            ':status' => $status,
            ':id' => $orderId,
        ];

        if (isset($columns['ly_do_huy'])) {
            if ($isCancelled) {
                if ($trimmedCancelReason !== '') {
                    $set[] = 'ly_do_huy = :ly_do_huy';
                    $params[':ly_do_huy'] = $trimmedCancelReason;
                }
            } else {
                $set[] = 'ly_do_huy = NULL';
            }
        }

        if (isset($columns['updated_at'])) {
            $set[] = 'updated_at = CURRENT_TIMESTAMP';
        }

        $sql = 'UPDATE hoa_don SET ' . implode(', ', $set) . ' WHERE ma_hoa_don = :id';
        $st = $this->pdo->prepare($sql);
        $ok = $st->execute($params);

        if ($ok) {
            try {
                $hoaDonModel = new HoaDon($this->pdo);
                $hoaDonModel->syncLoyaltyForOrder($orderId);
            } catch (Throwable $e) {
                // Avoid breaking order status updates if loyalty sync fails.
            }
        }

        return $ok;
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

    public function listReviews(string $keyword = '', array $filters = []): array {
        $replyExpr = $this->hasColumn('danh_gia', 'phan_hoi') ? 'dg.phan_hoi' : 'NULL::text';
        $replyDateExpr = $this->hasColumn('danh_gia', 'ngay_phan_hoi') ? 'dg.ngay_phan_hoi' : 'NULL::timestamp';
        $replyStaffExpr = $this->hasColumn('danh_gia', 'ma_nv_phan_hoi') ? 'nv.ho_ten' : 'NULL::text';
        $replyStaffJoin = $this->hasColumn('danh_gia', 'ma_nv_phan_hoi')
            ? 'LEFT JOIN nhan_vien nv ON nv.ma_nv = dg.ma_nv_phan_hoi'
            : 'LEFT JOIN nhan_vien nv ON 1 = 0';
        $phoneExpr = $this->hasColumn('khach_hang', 'so_dien_thoai') ? 'COALESCE(kh.so_dien_thoai, \'\')' : "''";
        $searchColumns = ['sp.ten_san_pham', 'kh.ho_ten', 'dg.noi_dung', $phoneExpr, 'dg.ma_danh_gia', 'latest_order.ma_hoa_don', 'latest_order.trang_thai'];
        if ($this->hasColumn('danh_gia', 'phan_hoi')) {
            $searchColumns[] = 'dg.phan_hoi';
        }
        [$searchSql, $params] = $this->buildSearchClause($searchColumns, $keyword, 'rv');

        $extraWhere = '';
        $star = max(0, min(5, (int)($filters['so_sao'] ?? 0)));
        if ($star > 0) {
            $extraWhere .= ' AND dg.so_sao = :so_sao';
            $params[':so_sao'] = $star;
        }

        $replyStatus = strtolower(trim((string)($filters['trang_thai_phan_hoi'] ?? '')));
        if ($replyStatus === 'pending') {
            $extraWhere .= ' AND COALESCE(TRIM(' . $replyExpr . '), \'\') = \'\'';
        } elseif ($replyStatus === 'replied') {
            $extraWhere .= ' AND COALESCE(TRIM(' . $replyExpr . '), \'\') <> \'\'';
        }

        $orderStatus = trim((string)($filters['trang_thai_don'] ?? ''));
        if ($orderStatus !== '') {
            $extraWhere .= ' AND LOWER(COALESCE(latest_order.trang_thai, \'\')) = LOWER(:trang_thai_don)';
            $params[':trang_thai_don'] = $orderStatus;
        }

        $maKh = trim((string)($filters['ma_kh'] ?? ''));
        $maKhDigits = preg_replace('/\D+/', '', $maKh);
        if ($maKh !== '' && $maKhDigits !== '') {
            $extraWhere .= ' AND CAST(dg.ma_kh AS TEXT) = :ma_kh';
            $params[':ma_kh'] = $maKhDigits;
        }

        $maVanDon = trim((string)($filters['ma_van_don'] ?? ''));
        $maVanDonDigits = preg_replace('/\D+/', '', $maVanDon);
        if ($maVanDon !== '' && $maVanDonDigits !== '') {
            $extraWhere .= ' AND CAST(latest_order.ma_hoa_don AS TEXT) = :ma_van_don';
            $params[':ma_van_don'] = $maVanDonDigits;
        }

        $sdt = trim((string)($filters['sdt_khach_hang'] ?? ''));
        if ($sdt !== '') {
            $extraWhere .= ' AND ' . $phoneExpr . ' ILIKE :sdt_khach_hang';
            $params[':sdt_khach_hang'] = '%' . $sdt . '%';
        }

        $khoangNgay = strtolower(trim((string)($filters['khoang_ngay'] ?? '')));
        $dateIntervalMap = [
            '1d' => '1 day',
            '3d' => '3 days',
            '7d' => '7 days',
            '30d' => '30 days',
        ];
        if (isset($dateIntervalMap[$khoangNgay])) {
            $extraWhere .= " AND dg.ngay_danh_gia >= (CURRENT_TIMESTAMP - INTERVAL '" . $dateIntervalMap[$khoangNgay] . "')";
        }

        $limit = max(10, min(200, (int)($filters['limit'] ?? 60)));

        $sql = "SELECT dg.*, dg.ctid::text AS row_ref, sp.ten_san_pham, kh.ho_ten AS ten_khach_hang,
                       " . $phoneExpr . " AS sdt_khach_hang,
                       latest_order.ma_hoa_don AS ma_van_don,
                       latest_order.trang_thai AS trang_thai_don_hang,
                       $replyExpr AS phan_hoi,
                       $replyDateExpr AS ngay_phan_hoi,
                       $replyStaffExpr AS ten_nhan_vien_phan_hoi
                FROM danh_gia dg
                LEFT JOIN san_pham sp ON sp.ma_san_pham = dg.ma_san_pham
                LEFT JOIN khach_hang kh ON kh.ma_kh = dg.ma_kh
                LEFT JOIN LATERAL (
                    SELECT hd.ma_hoa_don, hd.trang_thai
                    FROM chi_tiet_hoa_don ct
                    INNER JOIN hoa_don hd ON hd.ma_hoa_don = ct.ma_hoa_don
                    WHERE hd.ma_kh = dg.ma_kh
                      AND ct.ma_san_pham = dg.ma_san_pham
                    ORDER BY COALESCE(hd.ngay_dat, hd.created_at) DESC NULLS LAST, hd.ma_hoa_don DESC
                    LIMIT 1
                ) latest_order ON TRUE
                $replyStaffJoin
                WHERE 1=1 $searchSql $extraWhere
                ORDER BY dg.ngay_danh_gia DESC NULLS LAST, dg.ma_danh_gia DESC
                LIMIT " . (int)$limit;
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getReviewFilterOptions(): array {
        $orderStatusOptions = [];
        try {
            $sql = "SELECT DISTINCT TRIM(COALESCE(trang_thai, '')) AS trang_thai
                    FROM hoa_don
                    WHERE COALESCE(TRIM(trang_thai), '') <> ''
                    ORDER BY trang_thai ASC";
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($rows as $status) {
                $status = trim((string)$status);
                if ($status !== '') {
                    $orderStatusOptions[$status] = $status;
                }
            }
        } catch (Throwable $e) {
            $orderStatusOptions = [];
        }

        return [
            'so_sao' => [1, 2, 3, 4, 5],
            'trang_thai_phan_hoi' => [
                'pending' => 'Chưa phản hồi',
                'replied' => 'Đã phản hồi',
            ],
            'trang_thai_don' => $orderStatusOptions,
            'khoang_ngay' => [
                '1d' => '1 ngày gần nhất',
                '3d' => '3 ngày gần nhất',
                '7d' => '1 tuần gần nhất',
                '30d' => '1 tháng gần nhất',
            ],
        ];
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

    public function getCustomerReviewEligibility(int $customerId, array $productIds = []): array {
        $customerId = max(0, $customerId);
        if ($customerId <= 0) {
            return [];
        }

        $customerScopeIds = $this->getCustomerScopeIds($customerId);
        if (empty($customerScopeIds)) {
            return [];
        }

        $result = [];
        [$customerPlaceholders, $customerParams] = $this->buildInPlaceholders($customerScopeIds, 'review_customer_');
        $params = $customerParams;
        $productFilter = '';

        $productIds = array_values(array_unique(array_filter(array_map('trim', $productIds), static function (string $value): bool {
            return $value !== '';
        })));

        if (!empty($productIds)) {
            [$placeholders, $productParams] = $this->buildInPlaceholders($productIds, 'review_product_');
            $productFilter = ' AND ct.ma_san_pham IN (' . implode(', ', $placeholders) . ')';
            $params = array_merge($params, $productParams);
            foreach ($productIds as $productId) {
                $result[$productId] = [
                    'has_purchased' => false,
                    'has_reviewed' => false,
                ];
            }
        }

                $purchaseSql = "SELECT DISTINCT ct.ma_san_pham
                        FROM chi_tiet_hoa_don ct
                        INNER JOIN hoa_don hd ON hd.ma_hoa_don = ct.ma_hoa_don
                                                WHERE hd.ma_kh IN (" . implode(', ', $customerPlaceholders) . ")
                          AND ct.ma_san_pham IS NOT NULL
                          AND LOWER(TRIM(COALESCE(hd.trang_thai, ''))) NOT IN ('da huy', 'đã hủy', 'huy', 'cancelled', 'canceled')"
                        . $productFilter;
        $purchaseSt = $this->pdo->prepare($purchaseSql);
        $purchaseSt->execute($params);
        foreach ($purchaseSt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $productId) {
            $productId = trim((string)$productId);
            if ($productId === '') {
                continue;
            }
            $result[$productId] = $result[$productId] ?? ['has_purchased' => false, 'has_reviewed' => false];
            $result[$productId]['has_purchased'] = true;
        }

        $reviewSql = "SELECT DISTINCT ma_san_pham
                      FROM danh_gia
                      WHERE ma_kh IN (" . implode(', ', $customerPlaceholders) . ")
                        AND ma_san_pham IS NOT NULL";
        if (!empty($productIds)) {
            [$reviewPlaceholders, $reviewParams] = $this->buildInPlaceholders($productIds, 'reviewed_product_');
            $reviewSql .= ' AND ma_san_pham IN (' . implode(', ', $reviewPlaceholders) . ')';
            $reviewSt = $this->pdo->prepare($reviewSql);
            $reviewSt->execute(array_merge($customerParams, $reviewParams));
        } else {
            $reviewSt = $this->pdo->prepare($reviewSql);
            $reviewSt->execute($customerParams);
        }

        foreach ($reviewSt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $productId) {
            $productId = trim((string)$productId);
            if ($productId === '') {
                continue;
            }
            $result[$productId] = $result[$productId] ?? ['has_purchased' => false, 'has_reviewed' => false];
            $result[$productId]['has_reviewed'] = true;
        }

        return $result;
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

        $productId = trim($productId);
        if ($productId === '') {
            return ['ok' => false, 'message' => 'Không xác định được sản phẩm cần đánh giá.'];
        }

        $stars = max(1, min(5, $stars));
        $content = trim($content);
        if ($content === '') {
            return ['ok' => false, 'message' => 'Nội dung đánh giá không được để trống.'];
        }

        $eligibility = $this->getCustomerReviewEligibility((int)$kh['ma_kh'], [$productId]);
        $productEligibility = $eligibility[$productId] ?? ['has_purchased' => false, 'has_reviewed' => false];
        if (empty($productEligibility['has_purchased'])) {
            return ['ok' => false, 'message' => 'Bạn chỉ có thể đánh giá sản phẩm đã mua.'];
        }

        if (!empty($productEligibility['has_reviewed'])) {
            return ['ok' => false, 'message' => 'Bạn đã đánh giá sản phẩm này rồi.'];
        }

        $customerId = (int)$kh['ma_kh'];

        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }

            $sql = "INSERT INTO danh_gia(ma_danh_gia, ma_san_pham, ma_kh, so_sao, noi_dung, ngay_danh_gia)
                VALUES (
                    COALESCE((SELECT MAX(ma_danh_gia) FROM danh_gia), 0) + 1,
                    :product_id,
                    :ma_kh,
                    :so_sao,
                    :noi_dung,
                    CURRENT_TIMESTAMP
                )";
            $st = $this->pdo->prepare($sql);
            $ok = $st->execute([
                ':product_id' => $productId,
                ':ma_kh' => $customerId,
                ':so_sao' => $stars,
                ':noi_dung' => $content,
            ]);

            if (!$ok) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                return ['ok' => false, 'message' => 'Không thể gửi đánh giá lúc này.'];
            }

            $this->grantReviewRewardPoint($customerId);
            $this->refreshProductRating($productId);

            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            return ['ok' => true, 'message' => 'Đã gửi đánh giá sản phẩm. Bạn nhận thêm 1 điểm ưu đãi.'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }

        return ['ok' => false, 'message' => 'Không thể gửi đánh giá lúc này.'];
    }

    public function replyReview(int $reviewId, int $staffId, string $reply, string $rowRef = ''): bool {
        $rowRef = trim($rowRef);
        if ($reviewId <= 0 && $rowRef === '') {
            return false;
        }

        $reply = trim($reply);
        if ($reply === '') {
            return false;
        }

        $existingReply = '';
        try {
            if ($reviewId > 0) {
                $existingSt = $this->pdo->prepare('SELECT COALESCE(phan_hoi, \'\') AS phan_hoi FROM danh_gia WHERE ma_danh_gia = :id LIMIT 1');
                $existingSt->execute([':id' => $reviewId]);
                $existingReply = trim((string)($existingSt->fetchColumn() ?: ''));
            } else {
                $existingSt = $this->pdo->prepare('SELECT COALESCE(phan_hoi, \'\') AS phan_hoi FROM danh_gia WHERE ctid = CAST(:row_ref AS tid) LIMIT 1');
                $existingSt->execute([':row_ref' => $rowRef]);
                $existingReply = trim((string)($existingSt->fetchColumn() ?: ''));
            }
        } catch (Throwable $e) {
            $existingReply = '';
        }

        $staffName = '';
        if ($staffId > 0) {
            try {
                $staffSt = $this->pdo->prepare('SELECT ho_ten FROM nhan_vien WHERE ma_nv = :id LIMIT 1');
                $staffSt->execute([':id' => $staffId]);
                $staffName = trim((string)($staffSt->fetchColumn() ?: ''));
            } catch (Throwable $e) {
                $staffName = '';
            }
        }

        $header = '[' . date('d/m/Y H:i') . ' - ' . ($staffName !== '' ? $staffName : 'Nhan vien') . ']';
        $newEntry = $header . "\n" . $reply;
        $finalReply = $existingReply === '' ? $newEntry : ($existingReply . "\n\n--------------------\n" . $newEntry);

        $setClauses = ['phan_hoi = :phan_hoi'];
        $params = [
            ':phan_hoi' => $finalReply,
        ];

        if ($this->hasColumn('danh_gia', 'ma_nv_phan_hoi')) {
            $setClauses[] = 'ma_nv_phan_hoi = :ma_nv';
            $params[':ma_nv'] = $staffId > 0 ? $staffId : null;
        }

        if ($this->hasColumn('danh_gia', 'ngay_phan_hoi')) {
            $setClauses[] = 'ngay_phan_hoi = CURRENT_TIMESTAMP';
        }

        if ($reviewId > 0) {
            $whereClause = 'ma_danh_gia = :id';
            $params[':id'] = $reviewId;
        } else {
            $whereClause = 'ctid = CAST(:row_ref AS tid)';
            $params[':row_ref'] = $rowRef;
        }

        $sql = "UPDATE danh_gia
                SET " . implode(",\n                    ", $setClauses) . "
                WHERE {$whereClause}";

        try {
            $st = $this->pdo->prepare($sql);
            return $st->execute($params);
        } catch (Throwable $e) {
            try {
                if ($reviewId > 0) {
                    $fallback = $this->pdo->prepare("UPDATE danh_gia SET phan_hoi = :phan_hoi WHERE ma_danh_gia = :id");
                    return $fallback->execute([
                        ':phan_hoi' => $finalReply,
                        ':id' => $reviewId,
                    ]);
                }

                $fallback = $this->pdo->prepare("UPDATE danh_gia SET phan_hoi = :phan_hoi WHERE ctid = CAST(:row_ref AS tid)");
                return $fallback->execute([
                    ':phan_hoi' => $finalReply,
                    ':row_ref' => $rowRef,
                ]);
            } catch (Throwable $inner) {
                return false;
            }
        }
    }

    public function listChatConversations(bool $pendingOnly = false, ?int $limit = null): array {
        $limitSql = '';
        if ($limit !== null) {
            $limit = max(1, min(50, $limit));
            $limitSql = ' LIMIT ' . (int)$limit;
        }

        $pendingWhere = $pendingOnly ? 'WHERE latest_chat.ma_nv IS NULL' : '';

        $sql = "WITH latest_chat AS (
                    SELECT DISTINCT ON (chat.ma_kh)
                           chat.ma_kh,
                           chat.ma_nv,
                           chat.noi_dung,
                           chat.thoi_gian,
                           chat.ma_chat
                    FROM lich_su_chat chat
                    ORDER BY chat.ma_kh, chat.thoi_gian DESC NULLS LAST, chat.ma_chat DESC
                ),
                unanswered AS (
                    SELECT customer_msgs.ma_kh,
                           COUNT(*) AS tin_chua_phan_hoi
                    FROM lich_su_chat customer_msgs
                    LEFT JOIN (
                        SELECT ma_kh, MAX(thoi_gian) AS last_staff_time
                        FROM lich_su_chat
                        WHERE ma_nv IS NOT NULL
                        GROUP BY ma_kh
                    ) last_staff ON last_staff.ma_kh = customer_msgs.ma_kh
                    WHERE customer_msgs.ma_nv IS NULL
                      AND (last_staff.last_staff_time IS NULL OR customer_msgs.thoi_gian > last_staff.last_staff_time)
                    GROUP BY customer_msgs.ma_kh
                )
                SELECT kh.ma_kh,
                       kh.ho_ten,
                       kh.email,
                       latest_chat.thoi_gian AS cap_nhat_cuoi,
                       latest_chat.noi_dung AS tin_nhan_moi,
                       COALESCE(unanswered.tin_chua_phan_hoi, 0) AS tin_chua_phan_hoi,
                       CASE WHEN latest_chat.ma_nv IS NULL THEN 1 ELSE 0 END AS dang_cho_phan_hoi
                FROM khach_hang kh
                INNER JOIN latest_chat ON latest_chat.ma_kh = kh.ma_kh
                LEFT JOIN unanswered ON unanswered.ma_kh = kh.ma_kh
                $pendingWhere
                ORDER BY latest_chat.thoi_gian DESC NULLS LAST, kh.ma_kh DESC$limitSql";
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