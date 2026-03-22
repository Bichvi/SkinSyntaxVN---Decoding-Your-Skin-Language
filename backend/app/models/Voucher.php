<?php

class Voucher {
    private PDO $pdo;
    private array $columnCache = [];
    private ?string $lastErrorMessage = null;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public function getLastErrorMessage(): ?string {
        return $this->lastErrorMessage;
    }

    private function ensureSchema(): void {
        $ddl = [
            "CREATE TABLE IF NOT EXISTS voucher (
                ma_voucher BIGSERIAL PRIMARY KEY,
                ma_code VARCHAR(50) NOT NULL UNIQUE,
                ten_voucher VARCHAR(255) NOT NULL,
                mo_ta TEXT,
                loai_giam VARCHAR(20) NOT NULL DEFAULT 'fixed',
                gia_tri_giam BIGINT NOT NULL DEFAULT 0,
                gia_tri_don_toi_thieu BIGINT NOT NULL DEFAULT 0,
                giam_toi_da BIGINT,
                so_luong INTEGER,
                so_luong_da_dung INTEGER NOT NULL DEFAULT 0,
                ngay_bat_dau TIMESTAMP,
                ngay_ket_thuc TIMESTAMP,
                trang_thai VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS ma_code VARCHAR(50)",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS ten_voucher VARCHAR(255)",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS mo_ta TEXT",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS loai_giam VARCHAR(20) DEFAULT 'fixed'",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS gia_tri_giam BIGINT DEFAULT 0",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS gia_tri_don_toi_thieu BIGINT DEFAULT 0",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS giam_toi_da BIGINT",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS so_luong INTEGER",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS so_luong_da_dung INTEGER DEFAULT 0",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS ngay_bat_dau TIMESTAMP",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS ngay_ket_thuc TIMESTAMP",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS trang_thai VARCHAR(20) DEFAULT 'active'",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            "ALTER TABLE voucher ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            "CREATE INDEX IF NOT EXISTS idx_voucher_status ON voucher(trang_thai)",
            "CREATE INDEX IF NOT EXISTS idx_voucher_period ON voucher(ngay_bat_dau, ngay_ket_thuc)",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS tam_tinh BIGINT DEFAULT 0",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS so_tien_giam BIGINT DEFAULT 0",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS ma_giam_gia VARCHAR(50)",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS ma_voucher BIGINT",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS phi_van_chuyen BIGINT DEFAULT 0",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS ten_nguoi_nhan VARCHAR(255)",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS sdt_nguoi_nhan VARCHAR(30)",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS hinh_thuc_thanh_toan VARCHAR(100)",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS status_thanh_toan VARCHAR(50) DEFAULT 'Chua thanh toan'",
            "CREATE INDEX IF NOT EXISTS idx_hoa_don_ma_giam_gia ON hoa_don(ma_giam_gia)"
        ];

        foreach ($ddl as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (Throwable $e) {
                // Keep runtime resilient when the DB user cannot alter schema.
            }
        }

        $this->columnCache = [];
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

    private function normalizeCode(string $code): string {
        $code = preg_replace('/\s+/u', '', trim($code));
        return strtoupper((string)$code);
    }

    private function normalizeDateTime(?string $value): ?string {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeVoucherRow(array $row): array {
        $row['ma_voucher'] = (int)($row['ma_voucher'] ?? 0);
        $row['gia_tri_giam'] = (int)($row['gia_tri_giam'] ?? 0);
        $row['gia_tri_don_toi_thieu'] = (int)($row['gia_tri_don_toi_thieu'] ?? 0);
        $row['giam_toi_da'] = isset($row['giam_toi_da']) && $row['giam_toi_da'] !== null ? (int)$row['giam_toi_da'] : null;
        $row['so_luong'] = isset($row['so_luong']) && $row['so_luong'] !== null ? (int)$row['so_luong'] : null;
        $row['so_luong_da_dung'] = (int)($row['so_luong_da_dung'] ?? 0);
        $row['so_luong_con_lai'] = $row['so_luong'] === null ? null : max(0, $row['so_luong'] - $row['so_luong_da_dung']);
        $row['ma_code'] = $this->normalizeCode((string)($row['ma_code'] ?? ''));
        $row['loai_giam'] = trim((string)($row['loai_giam'] ?? 'fixed'));
        $row['trang_thai'] = trim((string)($row['trang_thai'] ?? 'active'));
        return $row;
    }

    private function buildSearchClause(string $keyword): array {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return ['', []];
        }

        $parts = preg_split('/\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [$keyword];
        $clauses = [];
        $params = [];

        foreach ($parts as $index => $part) {
            $param = ':kw' . $index;
            $clauses[] = '(CAST(ma_code AS TEXT) ILIKE ' . $param . ' OR CAST(ten_voucher AS TEXT) ILIKE ' . $param . ' OR CAST(COALESCE(mo_ta, \'\') AS TEXT) ILIKE ' . $param . ')';
            $params[$param] = '%' . $part . '%';
        }

        return [' AND ' . implode(' AND ', $clauses), $params];
    }

    public function listVouchers(string $keyword = ''): array {
        [$searchSql, $params] = $this->buildSearchClause($keyword);
        $sql = "SELECT *
                FROM voucher
                WHERE 1=1 $searchSql
                ORDER BY created_at DESC NULLS LAST, ma_voucher DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn(array $row): array => $this->normalizeVoucherRow($row), $rows);
    }

    public function getVoucherById(int $id): ?array {
        $st = $this->pdo->prepare('SELECT * FROM voucher WHERE ma_voucher = :id LIMIT 1');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ? $this->normalizeVoucherRow($row) : null;
    }

    public function getVoucherByCode(string $code): ?array {
        $normalizedCode = $this->normalizeCode($code);
        if ($normalizedCode === '') {
            return null;
        }

        $st = $this->pdo->prepare('SELECT * FROM voucher WHERE UPPER(ma_code) = :code LIMIT 1');
        $st->execute([':code' => $normalizedCode]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ? $this->normalizeVoucherRow($row) : null;
    }

    private function codeExists(string $code, ?int $excludeId = null): bool {
        $sql = 'SELECT ma_voucher FROM voucher WHERE UPPER(ma_code) = :code';
        $params = [':code' => $this->normalizeCode($code)];
        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND ma_voucher <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return (bool)$st->fetchColumn();
    }

    public function saveVoucher(array $data, ?int $id = null): bool {
        $this->lastErrorMessage = null;

        $code = $this->normalizeCode((string)($data['ma_code'] ?? ''));
        $name = trim((string)($data['ten_voucher'] ?? ''));
        $description = trim((string)($data['mo_ta'] ?? ''));
        $type = strtolower(trim((string)($data['loai_giam'] ?? 'fixed')));
        $value = (int)max(0, (float)($data['gia_tri_giam'] ?? 0));
        $minOrder = (int)max(0, (float)($data['gia_tri_don_toi_thieu'] ?? 0));
        $maxDiscountRaw = trim((string)($data['giam_toi_da'] ?? ''));
        $maxDiscount = $maxDiscountRaw === '' ? null : (int)max(0, (float)$maxDiscountRaw);
        $quantityRaw = trim((string)($data['so_luong'] ?? ''));
        $quantity = $quantityRaw === '' ? null : (int)max(0, (float)$quantityRaw);
        $status = strtolower(trim((string)($data['trang_thai'] ?? 'active')));
        $startAt = $this->normalizeDateTime($data['ngay_bat_dau'] ?? null);
        $endAt = $this->normalizeDateTime($data['ngay_ket_thuc'] ?? null);

        if ($code === '' || $name === '') {
            $this->lastErrorMessage = 'Mã voucher và tên voucher là bắt buộc.';
            return false;
        }

        if (mb_strlen($name) > 255) {
            $this->lastErrorMessage = 'Tên voucher tối đa 255 ký tự.';
            return false;
        }

        if ($description !== '' && mb_strlen($description) > 2000) {
            $this->lastErrorMessage = 'Mô tả voucher tối đa 2000 ký tự.';
            return false;
        }

        if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $code)) {
            $this->lastErrorMessage = 'Mã voucher chỉ được chứa chữ in hoa, số, dấu gạch ngang hoặc gạch dưới.';
            return false;
        }

        if (!in_array($type, ['fixed', 'percent'], true)) {
            $this->lastErrorMessage = 'Loại giảm giá không hợp lệ.';
            return false;
        }

        if ($value <= 0) {
            $this->lastErrorMessage = 'Giá trị giảm phải lớn hơn 0.';
            return false;
        }

        if (fmod((float)($data['gia_tri_giam'] ?? 0), 1.0) !== 0.0) {
            $this->lastErrorMessage = 'Giá trị giảm phải là số nguyên.';
            return false;
        }

        if ($type === 'percent' && $value > 100) {
            $this->lastErrorMessage = 'Voucher theo phần trăm chỉ được từ 1 đến 100.';
            return false;
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $this->lastErrorMessage = 'Trạng thái voucher không hợp lệ.';
            return false;
        }

        if ($startAt !== null && $endAt !== null && strtotime($startAt) > strtotime($endAt)) {
            $this->lastErrorMessage = 'Thời gian bắt đầu phải sớm hơn hoặc bằng thời gian kết thúc.';
            return false;
        }

        if ($minOrder < 0) {
            $this->lastErrorMessage = 'Giá trị đơn tối thiểu không hợp lệ.';
            return false;
        }

        if ($quantity !== null && $id !== null) {
            $current = $this->getVoucherById($id);
            if ($current && $quantity > 0 && $quantity < (int)($current['so_luong_da_dung'] ?? 0)) {
                $this->lastErrorMessage = 'Số lượng không được nhỏ hơn số lượt đã dùng.';
                return false;
            }
        }

        if ($this->codeExists($code, $id)) {
            $this->lastErrorMessage = 'Mã voucher đã tồn tại.';
            return false;
        }

        $payload = [
            'ma_code' => $code,
            'ten_voucher' => $name,
            'mo_ta' => ($description !== '' ? $description : null),
            'loai_giam' => $type,
            'gia_tri_giam' => $value,
            'gia_tri_don_toi_thieu' => $minOrder,
            'giam_toi_da' => $maxDiscount,
            'so_luong' => $quantity,
            'ngay_bat_dau' => $startAt,
            'ngay_ket_thuc' => $endAt,
            'trang_thai' => $status,
        ];

        if ($id !== null && $id > 0) {
            $setClauses = [];
            $params = [':id' => $id];
            foreach ($payload as $column => $valueItem) {
                $setClauses[] = $column . ' = :' . $column;
                $params[':' . $column] = $valueItem;
            }
            $setClauses[] = 'updated_at = CURRENT_TIMESTAMP';

            $sql = 'UPDATE voucher SET ' . implode(', ', $setClauses) . ' WHERE ma_voucher = :id';
            $st = $this->pdo->prepare($sql);
            try {
                return $st->execute($params);
            } catch (Throwable $e) {
                $this->lastErrorMessage = $this->mapPersistenceError($e, 'Không thể cập nhật voucher.');
                return false;
            }
        }

        $fields = array_keys($payload);
        $placeholders = array_map(fn(string $field): string => ':' . $field, $fields);
        $sql = 'INSERT INTO voucher(' . implode(', ', $fields) . ') VALUES(' . implode(', ', $placeholders) . ')';
        $st = $this->pdo->prepare($sql);

        $params = [];
        foreach ($payload as $column => $valueItem) {
            $params[':' . $column] = $valueItem;
        }

        try {
            return $st->execute($params);
        } catch (Throwable $e) {
            $this->lastErrorMessage = $this->mapPersistenceError($e, 'Không thể tạo voucher mới.');
            return false;
        }
    }

    public function deleteVoucher(int $id): bool {
        $this->lastErrorMessage = null;
        if ($id <= 0) {
            $this->lastErrorMessage = 'Voucher không hợp lệ.';
            return false;
        }

        $st = $this->pdo->prepare('DELETE FROM voucher WHERE ma_voucher = :id');
        try {
            return $st->execute([':id' => $id]);
        } catch (Throwable $e) {
            $this->lastErrorMessage = $this->mapPersistenceError($e, 'Không thể xóa voucher.');
            return false;
        }
    }

    private function mapPersistenceError(Throwable $e, string $fallback): string {
        $message = strtolower(trim((string)$e->getMessage()));

        if (str_contains($message, 'duplicate key') || str_contains($message, 'unique') || str_contains($message, '23505')) {
            return 'Mã voucher đã tồn tại. Vui lòng dùng mã khác.';
        }

        return $fallback;
    }

    public function validateForCheckout(string $code, int $subtotal): array {
        $normalizedCode = $this->normalizeCode($code);
        if ($normalizedCode === '') {
            return [
                'ok' => false,
                'message' => 'Vui lòng nhập mã giảm giá.',
            ];
        }

        $voucher = $this->getVoucherByCode($normalizedCode);
        if (!$voucher) {
            return [
                'ok' => false,
                'message' => 'Mã giảm giá không tồn tại.',
            ];
        }

        if (($voucher['trang_thai'] ?? 'inactive') !== 'active') {
            return [
                'ok' => false,
                'message' => 'Mã giảm giá hiện không hoạt động.',
            ];
        }

        $now = time();
        if (!empty($voucher['ngay_bat_dau']) && strtotime((string)$voucher['ngay_bat_dau']) > $now) {
            return [
                'ok' => false,
                'message' => 'Mã giảm giá chưa đến thời gian áp dụng.',
            ];
        }

        if (!empty($voucher['ngay_ket_thuc']) && strtotime((string)$voucher['ngay_ket_thuc']) < $now) {
            return [
                'ok' => false,
                'message' => 'Mã giảm giá đã hết hạn.',
            ];
        }

        if ($subtotal < (int)($voucher['gia_tri_don_toi_thieu'] ?? 0)) {
            return [
                'ok' => false,
                'message' => 'Đơn hàng chưa đạt giá trị tối thiểu để dùng mã này.',
            ];
        }

        $limit = $voucher['so_luong'];
        if ($limit !== null && (int)($voucher['so_luong_da_dung'] ?? 0) >= $limit) {
            return [
                'ok' => false,
                'message' => 'Mã giảm giá đã hết lượt sử dụng.',
            ];
        }

        $discount = 0;
        if (($voucher['loai_giam'] ?? 'fixed') === 'percent') {
            $discount = (int)round($subtotal * ((int)$voucher['gia_tri_giam']) / 100);
            if (!empty($voucher['giam_toi_da'])) {
                $discount = min($discount, (int)$voucher['giam_toi_da']);
            }
        } else {
            $discount = (int)$voucher['gia_tri_giam'];
        }

        $discount = min($subtotal, max(0, $discount));
        if ($discount <= 0) {
            return [
                'ok' => false,
                'message' => 'Mã giảm giá không hợp lệ cho đơn hàng hiện tại.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Áp dụng mã giảm giá thành công.',
            'voucher' => $voucher,
            'discount' => $discount,
        ];
    }

    public function consumeVoucher(int $voucherId): bool {
        if ($voucherId <= 0) {
            return true;
        }

        $sql = "UPDATE voucher
                SET so_luong_da_dung = COALESCE(so_luong_da_dung, 0) + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE ma_voucher = :id
                  AND (so_luong IS NULL OR COALESCE(so_luong_da_dung, 0) < so_luong)";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $voucherId]);
        return $st->rowCount() > 0;
    }
}