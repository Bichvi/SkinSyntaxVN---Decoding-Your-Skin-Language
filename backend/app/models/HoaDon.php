<?php
// backend/app/models/HoaDon.php

class HoaDon {
    private PDO $pdo;
    private array $columnCache = [];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
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

    private function getOrCreateKhachHangId(string $email, string $defaultName): int {
        $sqlFind = "SELECT ma_kh FROM khach_hang WHERE LOWER(email) = LOWER(:email) LIMIT 1";
        $stFind = $this->pdo->prepare($sqlFind);
        $stFind->execute([':email' => $email]);
        $maKh = $stFind->fetchColumn();

        if ($maKh) {
            return (int)$maKh;
        }

        $sqlInsert = "INSERT INTO khach_hang(ho_ten, email, created_at, updated_at)
                      VALUES (:ho_ten, :email, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
        $stInsert = $this->pdo->prepare($sqlInsert);
        $stInsert->execute([
            ':ho_ten' => ($defaultName !== '' ? $defaultName : 'Khach hang'),
            ':email' => $email,
        ]);

        $newId = $this->pdo->lastInsertId('khach_hang_ma_kh_seq');
        if ($newId !== false && $newId !== '') {
            return (int)$newId;
        }

        $stFind->execute([':email' => $email]);
        $maKh = $stFind->fetchColumn();
        if (!$maKh) {
            throw new RuntimeException('Khong tao duoc khach hang.');
        }

        return (int)$maKh;
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

    public function taoDonHang(array $payload): int {
        $email = trim((string)($payload['email'] ?? ''));
        $hoTenMacDinh = trim((string)($payload['ho_ten_mac_dinh'] ?? ''));
        $tenNguoiNhan = trim((string)($payload['ten_nguoi_nhan'] ?? ''));
        $sdtNguoiNhan = trim((string)($payload['sdt_nguoi_nhan'] ?? ''));
        $diaChiGiaoHang = trim((string)($payload['dia_chi_giao_hang'] ?? ''));
        $hinhThucThanhToan = trim((string)($payload['hinh_thuc_thanh_toan'] ?? 'cod'));
        $phiVanChuyen = max(0, (int)($payload['phi_van_chuyen'] ?? 30000));
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

        $tongTien = $subtotal + $phiVanChuyen;

        $columnsHoaDon = $this->getColumns('hoa_don');
        $dataHoaDon = [
            'ma_kh' => $maKh,
            'ten_nguoi_nhan' => $tenNguoiNhan,
            'sdt_nguoi_nhan' => $sdtNguoiNhan,
            'dia_chi_giao_hang' => $diaChiGiaoHang,
            'phi_van_chuyen' => $phiVanChuyen,
            'tong_tien' => $tongTien,
            'hinh_thuc_thanh_toan' => $hinhThucThanhToan,
            'status_thanh_toan' => 'Chua thanh toan',
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

        $this->pdo->beginTransaction();
        try {
            $sqlHoaDon = 'INSERT INTO hoa_don(' . implode(', ', $insertColsHoaDon) . ') VALUES(' . implode(', ', $insertValuesHoaDon) . ')';
            $stHoaDon = $this->pdo->prepare($sqlHoaDon);
            $stHoaDon->execute($bindHoaDon);

            $maHoaDon = $this->pdo->lastInsertId('hoa_don_ma_hoa_don_seq');
            if ($maHoaDon === false || $maHoaDon === '') {
                $maHoaDon = $this->pdo->lastInsertId();
            }
            if ($maHoaDon === false || $maHoaDon === '') {
                throw new RuntimeException('Khong lay duoc ma_hoa_don sau khi insert.');
            }

            foreach ($lineItems as $item) {
                $dataCt = [
                    'ma_hoa_don' => (int)$maHoaDon,
                    'ma_san_pham' => $item['ma_san_pham'],
                    'so_luong' => $item['so_luong'],
                    'don_gia' => $item['don_gia'],
                    'status_thanh_toan' => 'Chua thanh toan',
                    'hinh_thuc_thanh_toan' => $hinhThucThanhToan,
                ];

                $insertColsCt = [];
                $insertValuesCt = [];
                $bindCt = [];

                foreach ($dataCt as $col => $value) {
                    if (!isset($columnsCt[$col])) {
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

            $this->pdo->commit();
            return (int)$maHoaDon;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
