<?php
// backend/app/models/HoaDon.php

class HoaDon {
    private PDO $pdo;
    private array $columnCache = [];
    private const POINT_VALUE_VND = 1000;
    private const VIP_THRESHOLD = 500;
    private const DIAMOND_THRESHOLD = 1500;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ensureLoyaltyColumns();
    }

    private function ensureLoyaltyColumns(): void {
        $ddl = [
            "ALTER TABLE khach_hang ADD COLUMN IF NOT EXISTS diemtl INTEGER DEFAULT 0",
            "ALTER TABLE khach_hang ADD COLUMN IF NOT EXISTS loaikh VARCHAR(30) DEFAULT 'Thuong'",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS diem_cong INTEGER DEFAULT 0",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS da_tich_diem BOOLEAN DEFAULT FALSE",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS diem_su_dung INTEGER DEFAULT 0",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS tien_giam_diem INTEGER DEFAULT 0",
            "ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS da_hoan_diem BOOLEAN DEFAULT FALSE",
        ];

        foreach ($ddl as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (Throwable $e) {
                // Keep order flow resilient if DB user cannot alter schema.
            }
        }

        try {
            $this->pdo->exec("UPDATE khach_hang SET diemtl = COALESCE(diemtl, 0) WHERE diemtl IS NULL");
            $this->pdo->exec("UPDATE khach_hang SET loaikh = COALESCE(NULLIF(TRIM(loaikh), ''), 'Thuong') WHERE loaikh IS NULL OR TRIM(COALESCE(loaikh, '')) = ''");
            $this->pdo->exec("UPDATE hoa_don SET diem_cong = COALESCE(diem_cong, 0) WHERE diem_cong IS NULL");
            $this->pdo->exec("UPDATE hoa_don SET da_tich_diem = COALESCE(da_tich_diem, FALSE) WHERE da_tich_diem IS NULL");
            $this->pdo->exec("UPDATE hoa_don SET diem_su_dung = COALESCE(diem_su_dung, 0) WHERE diem_su_dung IS NULL");
            $this->pdo->exec("UPDATE hoa_don SET tien_giam_diem = COALESCE(tien_giam_diem, 0) WHERE tien_giam_diem IS NULL");
            $this->pdo->exec("UPDATE hoa_don SET da_hoan_diem = COALESCE(da_hoan_diem, FALSE) WHERE da_hoan_diem IS NULL");
        } catch (Throwable $e) {
            // Ignore normalization failures to avoid blocking checkout.
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

    private function getNextNumericId(string $table, string $column, ?string $preferredSequence = null): int {
        if ($preferredSequence) {
            $sqlSequence = 'SELECT to_regclass(:sequence_name)';
            $stSequence = $this->pdo->prepare($sqlSequence);
            $stSequence->execute([':sequence_name' => $preferredSequence]);
            $sequenceName = $stSequence->fetchColumn();

            if ($sequenceName) {
                $quotedSequence = $this->pdo->quote((string)$sequenceName);
                $nextId = $this->pdo->query('SELECT nextval(' . $quotedSequence . '::regclass)')->fetchColumn();
                if ($nextId !== false && $nextId !== null) {
                    return (int)$nextId;
                }
            }
        }

        $sql = 'SELECT COALESCE(MAX(' . $column . '), 0) + 1 FROM ' . $table;
        $nextId = $this->pdo->query($sql)->fetchColumn();
        return max(1, (int)$nextId);
    }

    private function getOrCreateKhachHangId(string $email, string $defaultName): int {
        $sqlFind = "SELECT ma_kh FROM khach_hang WHERE LOWER(email) = LOWER(:email) LIMIT 1";
        $stFind = $this->pdo->prepare($sqlFind);
        $stFind->execute([':email' => $email]);
        $maKh = $stFind->fetchColumn();

        if ($maKh) {
            return (int)$maKh;
        }

        $maKhMoi = $this->getNextNumericId('khach_hang', 'ma_kh', 'khach_hang_ma_kh_seq');

        $sqlInsert = "INSERT INTO khach_hang(ma_kh, ho_ten, email, created_at, updated_at)
                      VALUES (:ma_kh, :ho_ten, :email, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
        $stInsert = $this->pdo->prepare($sqlInsert);
        $stInsert->execute([
            ':ma_kh' => $maKhMoi,
            ':ho_ten' => ($defaultName !== '' ? $defaultName : 'Khach hang'),
            ':email' => $email,
        ]);

        return $maKhMoi;
    }

            private function hasColumn(string $table, string $column): bool {
                $columns = $this->getColumns($table);
                return isset($columns[$column]);
            }

    private function fetchProductPrice(string $productId): int {
        $sql = "SELECT gia_ban, gia_thi_truong
                FROM san_pham
                WHERE ma_san_pham = :id
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $productId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$row) {
            throw new RuntimeException('San pham khong ton tai: ' . $productId);
        }

        $giaBan = (int)($row['gia_ban'] ?? 0);
        if ($giaBan > 0) {
            return $giaBan;
        }

        $giaThiTruong = (int)($row['gia_thi_truong'] ?? 0);
        return max(0, $giaThiTruong);
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

    private function calculateOrderPoints(array $order): int {
        $baseAmount = 0;

        if (isset($order['tam_tinh'])) {
            $baseAmount = max(0, (int)($order['tam_tinh'] ?? 0) - (int)($order['so_tien_giam'] ?? 0));
        }

        if ($baseAmount <= 0) {
            $baseAmount = max(0, (int)($order['tong_tien'] ?? 0) - (int)($order['phi_van_chuyen'] ?? 0));
        }

        if ($baseAmount > 0) {
            $baseAmount = max(0, $baseAmount - (int)($order['tien_giam_diem'] ?? 0));
        }

        if ($baseAmount <= 0) {
            $baseAmount = max(0, (int)($order['tong_tien'] ?? 0));
        }

        return max(0, (int)floor($baseAmount / 10000));
    }

    private function getCustomerAvailablePoints(int $maKh): int {
        if ($maKh <= 0) {
            return 0;
        }

        $st = $this->pdo->prepare('SELECT COALESCE(diemtl, 0) FROM khach_hang WHERE ma_kh = :ma_kh LIMIT 1');
        $st->execute([':ma_kh' => $maKh]);
        return max(0, (int)$st->fetchColumn());
    }

    private function syncCustomerLoyaltyByMaKh(int $maKh): void {
        if ($maKh <= 0) {
            return;
        }

        $hoaDonColumns = $this->getColumns('hoa_don');
        $khColumns = $this->getColumns('khach_hang');
        if (!isset($hoaDonColumns['da_tich_diem'], $hoaDonColumns['diem_cong'], $khColumns['diemtl'], $khColumns['loaikh'])) {
            return;
        }

        $usedPointsExpr = isset($hoaDonColumns['diem_su_dung'])
            ? "CASE WHEN COALESCE(da_hoan_diem, FALSE) = FALSE THEN COALESCE(diem_su_dung, 0) ELSE 0 END"
            : '0';

        $reviewBonusPoints = 0;
        if ($this->hasColumn('danh_gia', 'ma_kh')) {
            $sqlReviewPoints = "SELECT COUNT(*) FROM danh_gia WHERE ma_kh = :ma_kh";
            $stReviewPoints = $this->pdo->prepare($sqlReviewPoints);
            $stReviewPoints->execute([':ma_kh' => $maKh]);
            $reviewBonusPoints = max(0, (int)$stReviewPoints->fetchColumn());
        }

        $sqlPoints = "SELECT COALESCE(SUM(CASE WHEN COALESCE(da_tich_diem, FALSE) = TRUE THEN COALESCE(diem_cong, 0) ELSE 0 END), 0)
                            - COALESCE(SUM($usedPointsExpr), 0)
                      FROM hoa_don
                      WHERE ma_kh = :ma_kh";
        $stPoints = $this->pdo->prepare($sqlPoints);
        $stPoints->execute([':ma_kh' => $maKh]);
        $totalPoints = max(0, (int)$stPoints->fetchColumn() + $reviewBonusPoints);

        $tier = $this->normalizeCustomerTier($totalPoints);
        $sqlUpdate = "UPDATE khach_hang
                      SET diemtl = :diemtl,
                          loaikh = :loaikh,
                          updated_at = CURRENT_TIMESTAMP
                      WHERE ma_kh = :ma_kh";
        $stUpdate = $this->pdo->prepare($sqlUpdate);
        $stUpdate->execute([
            ':diemtl' => $totalPoints,
            ':loaikh' => $tier,
            ':ma_kh' => $maKh,
        ]);
    }

    public function syncLoyaltyForOrder(int $orderId): void {
        if ($orderId <= 0) {
            return;
        }

        $hoaDonColumns = $this->getColumns('hoa_don');
        if (!isset($hoaDonColumns['da_tich_diem'], $hoaDonColumns['diem_cong'])) {
            return;
        }

        $order = $this->getOrderById($orderId);
        if (!$order) {
            return;
        }

        $maKh = (int)($order['ma_kh'] ?? 0);
        if ($maKh <= 0) {
            return;
        }

        $currentStatus = strtolower(trim((string)($order['trang_thai'] ?? '')));
        $alreadyAwarded = !empty($order['da_tich_diem']);
        $usedPoints = max(0, (int)($order['diem_su_dung'] ?? 0));
        $alreadyRefunded = !empty($order['da_hoan_diem']);
        $didChange = false;

        if ($currentStatus === 'da huy' && $usedPoints > 0 && !$alreadyRefunded) {
            $stRefund = $this->pdo->prepare('UPDATE hoa_don SET da_hoan_diem = TRUE, updated_at = CURRENT_TIMESTAMP WHERE ma_hoa_don = :id');
            $stRefund->execute([':id' => $orderId]);
            $didChange = true;
        }

        if ($currentStatus !== 'da huy' && $usedPoints > 0 && $alreadyRefunded) {
            $stUndoRefund = $this->pdo->prepare('UPDATE hoa_don SET da_hoan_diem = FALSE, updated_at = CURRENT_TIMESTAMP WHERE ma_hoa_don = :id');
            $stUndoRefund->execute([':id' => $orderId]);
            $didChange = true;
        }

        if ($currentStatus === 'hoan thanh' && !$alreadyAwarded) {
            $points = $this->calculateOrderPoints($order);
            $stAward = $this->pdo->prepare('UPDATE hoa_don SET diem_cong = :diem_cong, da_tich_diem = TRUE, updated_at = CURRENT_TIMESTAMP WHERE ma_hoa_don = :id');
            $stAward->execute([
                ':diem_cong' => $points,
                ':id' => $orderId,
            ]);
            $didChange = true;
        }

        if ($currentStatus !== 'hoan thanh' && $alreadyAwarded) {
            $stRevoke = $this->pdo->prepare('UPDATE hoa_don SET diem_cong = 0, da_tich_diem = FALSE, updated_at = CURRENT_TIMESTAMP WHERE ma_hoa_don = :id');
            $stRevoke->execute([':id' => $orderId]);
            $didChange = true;
        }

        if ($didChange) {
            $this->syncCustomerLoyaltyByMaKh($maKh);
        }
    }

    public function getOrderById(int $orderId, ?string $email = null): ?array {
        if ($orderId <= 0) {
            return null;
        }

        $sql = "SELECT hd.*, kh.ho_ten, kh.email
                FROM hoa_don hd
                LEFT JOIN khach_hang kh ON kh.ma_kh = hd.ma_kh
                WHERE hd.ma_hoa_don = :id";
        $params = [':id' => $orderId];

        if ($email !== null && trim($email) !== '') {
            $sql .= " AND LOWER(COALESCE(kh.email, '')) = LOWER(:email)";
            $params[':email'] = trim($email);
        }

        $sql .= ' LIMIT 1';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        return $row ?: null;
    }

    public function markPaidByTransfer(int $orderId, int $amount, ?string $transactionReference = null, ?string $transferContent = null): array {
        if ($orderId <= 0) {
            return [
                'ok' => false,
                'message' => 'Ma don hang khong hop le.',
            ];
        }

        $order = $this->getOrderById($orderId);
        if (!$order) {
            return [
                'ok' => false,
                'message' => 'Khong tim thay don hang.',
            ];
        }

        $paymentMethod = strtolower(trim((string)($order['hinh_thuc_thanh_toan'] ?? 'cod')));
        if ($paymentMethod !== 'bank_transfer_qr') {
            return [
                'ok' => false,
                'message' => 'Don hang khong su dung chuyen khoan QR.',
            ];
        }

        $paymentStatus = strtolower(trim((string)($order['status_thanh_toan'] ?? '')));
        if (in_array($paymentStatus, ['da thanh toan', 'paid', 'thanh cong'], true)) {
            return [
                'ok' => true,
                'message' => 'Don hang da duoc thanh toan truoc do.',
                'already_paid' => true,
                'order_id' => $orderId,
            ];
        }

        $orderStatus = strtolower(trim((string)($order['trang_thai'] ?? '')));
        if ($orderStatus === 'da huy') {
            return [
                'ok' => false,
                'message' => 'Don hang da huy, khong the doi soat thanh toan.',
            ];
        }

        $expectedAmount = (int)($order['tong_tien'] ?? 0);
        if ($amount < $expectedAmount || $expectedAmount <= 0) {
            return [
                'ok' => false,
                'message' => 'So tien chuyen khoan chua khop voi don hang.',
                'expected_amount' => $expectedAmount,
                'received_amount' => $amount,
            ];
        }

        $hoaDonColumns = $this->getColumns('hoa_don');
        $ctColumns = $this->getColumns('chi_tiet_hoa_don');

        $this->pdo->beginTransaction();
        try {
            $setHoaDon = ['status_thanh_toan = :status_thanh_toan'];
            $params = [
                ':status_thanh_toan' => 'Da thanh toan',
                ':id' => $orderId,
            ];

            if (isset($hoaDonColumns['trang_thai']) && in_array($orderStatus, ['cho xu ly', 'chờ xử lý', 'moi'], true)) {
                $setHoaDon[] = 'trang_thai = :trang_thai';
                $params[':trang_thai'] = 'Da xac nhan';
            }

            if (isset($hoaDonColumns['updated_at'])) {
                $setHoaDon[] = 'updated_at = CURRENT_TIMESTAMP';
            }

            if ($transactionReference !== null && $transactionReference !== '' && isset($hoaDonColumns['ghi_chu_thanh_toan'])) {
                $setHoaDon[] = 'ghi_chu_thanh_toan = :ghi_chu_thanh_toan';
                $params[':ghi_chu_thanh_toan'] = $transactionReference;
            } elseif ($transferContent !== null && $transferContent !== '' && isset($hoaDonColumns['ghi_chu_thanh_toan'])) {
                $setHoaDon[] = 'ghi_chu_thanh_toan = :ghi_chu_thanh_toan';
                $params[':ghi_chu_thanh_toan'] = $transferContent;
            }

            $sqlHoaDon = 'UPDATE hoa_don SET ' . implode(', ', $setHoaDon) . ' WHERE ma_hoa_don = :id';
            $stHoaDon = $this->pdo->prepare($sqlHoaDon);
            $stHoaDon->execute($params);

            if (isset($ctColumns['status_thanh_toan'])) {
                $setCt = ['status_thanh_toan = :ct_status_thanh_toan'];
                $paramsCt = [
                    ':ct_status_thanh_toan' => 'Da thanh toan',
                    ':id' => $orderId,
                ];

                $sqlCt = 'UPDATE chi_tiet_hoa_don SET ' . implode(', ', $setCt) . ' WHERE ma_hoa_don = :id';
                $stCt = $this->pdo->prepare($sqlCt);
                $stCt->execute($paramsCt);
            }

            $this->pdo->commit();

            return [
                'ok' => true,
                'message' => 'Da cap nhat don hang thanh cong.',
                'order_id' => $orderId,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function taoDonHang(array $payload): int {
        $email = trim((string)($payload['email'] ?? ''));
        $hoTenMacDinh = trim((string)($payload['ho_ten_mac_dinh'] ?? ''));
        $tenNguoiNhan = trim((string)($payload['ten_nguoi_nhan'] ?? ''));
        $sdtNguoiNhan = trim((string)($payload['sdt_nguoi_nhan'] ?? ''));
        $diaChiGiaoHang = trim((string)($payload['dia_chi_giao_hang'] ?? ''));
        $hinhThucThanhToan = trim((string)($payload['hinh_thuc_thanh_toan'] ?? 'cod'));
        $phiVanChuyen = max(0, (int)($payload['phi_van_chuyen'] ?? 30000));
        $tamTinhInput = max(0, (int)($payload['tam_tinh'] ?? 0));
        $soTienGiam = max(0, (int)($payload['so_tien_giam'] ?? 0));
        $maVoucher = isset($payload['ma_voucher']) && $payload['ma_voucher'] !== null ? (int)$payload['ma_voucher'] : null;
        $maGiamGia = trim((string)($payload['ma_giam_gia'] ?? ''));
        $diemSuDung = max(0, (int)($payload['diem_su_dung'] ?? 0));
        $tienGiamDiemInput = max(0, (int)($payload['tien_giam_diem'] ?? 0));
        $checkoutItems = $payload['checkout_items'] ?? [];

        if ($email === '' || !is_array($checkoutItems) || empty($checkoutItems)) {
            throw new InvalidArgumentException('Du lieu dat hang khong hop le.');
        }

        $maKh = $this->getOrCreateKhachHangId($email, $hoTenMacDinh);

        $lineItems = [];
        $subtotal = 0;

        foreach ($checkoutItems as $maSanPham => $soLuongRaw) {
            $maSanPham = trim((string)$maSanPham);
            if ($maSanPham === '') {
                continue;
            }

            $soLuong = max(1, (int)$soLuongRaw);
            $donGia = $this->fetchProductPrice($maSanPham);
            $lineTotal = $donGia * $soLuong;
            $subtotal += $lineTotal;

            $lineItems[] = [
                'ma_san_pham' => $maSanPham,
                'so_luong' => $soLuong,
                'don_gia' => $donGia,
            ];
        }

        if (empty($lineItems)) {
            throw new RuntimeException('Khong co san pham hop le de tao hoa don.');
        }

        $tamTinh = $tamTinhInput > 0 ? $tamTinhInput : $subtotal;
        $soTienGiam = min($tamTinh, $soTienGiam);
        $availablePoints = $this->getCustomerAvailablePoints($maKh);
        $maxDiscountableAmount = max(0, $tamTinh - $soTienGiam);
        $maxPointsByAmount = (int)floor($maxDiscountableAmount / self::POINT_VALUE_VND);
        $diemSuDung = min($diemSuDung, $availablePoints, $maxPointsByAmount);
        $tienGiamDiem = min($maxDiscountableAmount, $tienGiamDiemInput > 0 ? $tienGiamDiemInput : ($diemSuDung * self::POINT_VALUE_VND));
        $tongTien = max(0, $tamTinh - $soTienGiam - $tienGiamDiem) + $phiVanChuyen;
        $maHoaDonMoi = $this->getNextNumericId('hoa_don', 'ma_hoa_don', 'hoa_don_ma_hoa_don_seq');
        $statusThanhToan = $hinhThucThanhToan === 'bank_transfer_qr' ? 'Cho chuyen khoan' : 'Chua thanh toan';

        $columnsHoaDon = $this->getColumns('hoa_don');
        $dataHoaDon = [
            'ma_hoa_don' => $maHoaDonMoi,
            'ma_kh' => $maKh,
            'ten_nguoi_nhan' => $tenNguoiNhan,
            'sdt_nguoi_nhan' => $sdtNguoiNhan,
            'dia_chi_giao_hang' => $diaChiGiaoHang,
            'phi_van_chuyen' => $phiVanChuyen,
            'tam_tinh' => $tamTinh,
            'so_tien_giam' => $soTienGiam,
            'ma_giam_gia' => ($maGiamGia !== '' ? $maGiamGia : null),
            'ma_voucher' => $maVoucher,
            'tong_tien' => $tongTien,
            'hinh_thuc_thanh_toan' => $hinhThucThanhToan,
            'status_thanh_toan' => $statusThanhToan,
            'diem_cong' => 0,
            'da_tich_diem' => 'false',
            'diem_su_dung' => $diemSuDung,
            'tien_giam_diem' => $tienGiamDiem,
            'da_hoan_diem' => 'false',
            'trang_thai' => 'Cho xu ly',
            'ngay_dat' => date('Y-m-d H:i:s'),
        ];

        $insertColsHoaDon = [];
        $insertValuesHoaDon = [];
        $bindHoaDon = [];

        foreach ($dataHoaDon as $col => $value) {
            if (!isset($columnsHoaDon[$col])) {
                continue;
            }
            $insertColsHoaDon[] = $col;
            $insertValuesHoaDon[] = ':' . $col;
            $bindHoaDon[':' . $col] = $value;
        }

        if (empty($insertColsHoaDon)) {
            throw new RuntimeException('Khong tim thay cot hop le de insert hoa_don.');
        }

        $columnsCt = $this->getColumns('chi_tiet_hoa_don');
        $columnsVoucher = $this->getColumns('voucher');

        $this->pdo->beginTransaction();
        try {
            $sqlHoaDon = 'INSERT INTO hoa_don(' . implode(', ', $insertColsHoaDon) . ') VALUES(' . implode(', ', $insertValuesHoaDon) . ')';
            $stHoaDon = $this->pdo->prepare($sqlHoaDon);
            $stHoaDon->execute($bindHoaDon);

            $maHoaDon = (int)($bindHoaDon[':ma_hoa_don'] ?? 0);
            if ($maHoaDon <= 0) {
                $maHoaDon = (int)($this->pdo->lastInsertId('hoa_don_ma_hoa_don_seq') ?: $this->pdo->lastInsertId());
            }
            if ($maHoaDon <= 0) {
                throw new RuntimeException('Khong lay duoc ma_hoa_don sau khi insert.');
            }

            foreach ($lineItems as $item) {
                $dataCt = [
                    'id' => isset($columnsCt['id']) ? $this->getNextNumericId('chi_tiet_hoa_don', 'id', 'chi_tiet_hoa_don_id_seq') : null,
                    'ma_hoa_don' => (int)$maHoaDon,
                    'ma_san_pham' => $item['ma_san_pham'],
                    'so_luong' => $item['so_luong'],
                    'don_gia' => $item['don_gia'],
                    'status_thanh_toan' => $statusThanhToan,
                    'hinh_thuc_thanh_toan' => $hinhThucThanhToan,
                ];

                $insertColsCt = [];
                $insertValuesCt = [];
                $bindCt = [];

                foreach ($dataCt as $col => $value) {
                    if (!isset($columnsCt[$col]) || $value === null) {
                        continue;
                    }
                    $param = ':' . $col;
                    $insertColsCt[] = $col;
                    $insertValuesCt[] = $param;
                    $bindCt[$param] = $value;
                }

                if (empty($insertColsCt)) {
                    throw new RuntimeException('Khong tim thay cot hop le de insert chi_tiet_hoa_don.');
                }

                $sqlCt = 'INSERT INTO chi_tiet_hoa_don(' . implode(', ', $insertColsCt) . ') VALUES(' . implode(', ', $insertValuesCt) . ')';
                $stCt = $this->pdo->prepare($sqlCt);
                $stCt->execute($bindCt);
            }

            if ($maVoucher !== null && $maVoucher > 0 && isset($columnsVoucher['ma_voucher'])) {
                $setClauses = [];
                if (isset($columnsVoucher['so_luong_da_dung'])) {
                    $setClauses[] = 'so_luong_da_dung = COALESCE(so_luong_da_dung, 0) + 1';
                }
                if (isset($columnsVoucher['updated_at'])) {
                    $setClauses[] = 'updated_at = CURRENT_TIMESTAMP';
                }

                if (!empty($setClauses)) {
                    $sqlVoucher = 'UPDATE voucher SET ' . implode(', ', $setClauses) . ' WHERE ma_voucher = :ma_voucher';
                    if (isset($columnsVoucher['so_luong']) && isset($columnsVoucher['so_luong_da_dung'])) {
                        $sqlVoucher .= ' AND (so_luong IS NULL OR COALESCE(so_luong_da_dung, 0) < so_luong)';
                    }

                    $stVoucher = $this->pdo->prepare($sqlVoucher);
                    $stVoucher->execute([':ma_voucher' => $maVoucher]);

                    if ($stVoucher->rowCount() === 0) {
                        throw new RuntimeException('Voucher khong con hop le de ap dung.');
                    }
                }
            }

            $this->syncCustomerLoyaltyByMaKh($maKh);

            $this->pdo->commit();
            return (int)$maHoaDon;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
