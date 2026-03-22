<?php
// backend/app/models/TaiKhoan.php

class TaiKhoan {
    private PDO $pdo;
    private array $columnCache = [];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ensureLoyaltyColumns();
        $this->ensureOrderColumns();
    }

    private function ensureLoyaltyColumns(): void {
        $ddl = [
            "ALTER TABLE khach_hang ADD COLUMN IF NOT EXISTS diemtl INTEGER DEFAULT 0",
            "ALTER TABLE khach_hang ADD COLUMN IF NOT EXISTS loaikh VARCHAR(30) DEFAULT 'Thuong'",
        ];

        foreach ($ddl as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (Throwable $e) {
                // Keep account pages resilient if schema updates are unavailable.
            }
        }
    }

    private function ensureOrderColumns(): void {
        try {
            $this->pdo->exec("ALTER TABLE hoa_don ADD COLUMN IF NOT EXISTS ly_do_huy TEXT");
        } catch (Throwable $e) {
            // Keep account page resilient if schema updates are unavailable.
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

    public function getAccountOverview(int $nguoiDungId): ?array {
        $sql = "SELECT nd.id,
                       nd.ho_ten,
                       nd.email,
                       COALESCE(nd.ngay_tao, kh.created_at) AS ngay_tao
                FROM nguoidung nd
                LEFT JOIN khach_hang kh ON LOWER(kh.email) = LOWER(nd.email)
                WHERE nd.id = :id
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $nguoiDungId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAccountOverviewByEmail(string $email): ?array {
        $sql = "SELECT nd.id,
                       nd.ho_ten,
                       nd.email,
                       COALESCE(nd.ngay_tao, kh.created_at) AS ngay_tao
                FROM nguoidung nd
                LEFT JOIN khach_hang kh ON LOWER(kh.email) = LOWER(nd.email)
                WHERE LOWER(nd.email) = LOWER(:email)
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':email' => $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function xacThucMatKhau(string $matKhauNhap, string $matKhauLuu): bool {
        if ($matKhauLuu === '') {
            return false;
        }

        $info = password_get_info($matKhauLuu);
        if (!empty($info['algo'])) {
            return password_verify($matKhauNhap, $matKhauLuu);
        }

        if (hash_equals($matKhauLuu, $matKhauNhap)) {
            return true;
        }

        if (preg_match('/^[a-f0-9]{32}$/i', $matKhauLuu) === 1) {
            return hash_equals(strtolower($matKhauLuu), md5($matKhauNhap));
        }

        if (preg_match('/^[a-f0-9]{40}$/i', $matKhauLuu) === 1) {
            return hash_equals(strtolower($matKhauLuu), sha1($matKhauNhap));
        }

        return false;
    }

    public function getKhachHangByEmail(string $email): ?array {
        $sql = "SELECT * FROM khach_hang WHERE LOWER(email) = LOWER(:email) LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':email' => $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function ensureKhachHangByEmail(string $hoTen, string $email): ?array {
        $kh = $this->getKhachHangByEmail($email);
        if ($kh) {
            return $kh;
        }

        $sql = "INSERT INTO khach_hang(ma_kh, ho_ten, email, created_at, updated_at)
            VALUES (
                COALESCE((SELECT MAX(ma_kh) FROM khach_hang), 0) + 1,
                :ho_ten,
                :email,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )";
        $st = $this->pdo->prepare($sql);
        $ok = $st->execute([
            ':ho_ten' => $hoTen,
            ':email' => $email,
        ]);

        if (!$ok) {
            return null;
        }

        return $this->getKhachHangByEmail($email);
    }

    public function getOrderHistory(int $maKh): array {
        $selectParts = [
            'ma_hoa_don',
            'ngay_dat',
            'tong_tien',
            'trang_thai',
            'hinh_thuc_thanh_toan',
            'status_thanh_toan',
            'COALESCE(diem_cong, 0) AS diem_cong',
            'COALESCE(da_tich_diem, FALSE) AS da_tich_diem',
            'COALESCE(diem_su_dung, 0) AS diem_su_dung',
            'COALESCE(tien_giam_diem, 0) AS tien_giam_diem',
        ];

        if ($this->hasColumn('hoa_don', 'ly_do_huy')) {
            $selectParts[] = 'COALESCE(ly_do_huy, \'\') AS ly_do_huy';
        }

        $sql = "SELECT " . implode(', ', $selectParts) . "
            FROM hoa_don
                WHERE ma_kh = :ma_kh
                ORDER BY ngay_dat DESC, ma_hoa_don DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute([':ma_kh' => $maKh]);
        $orders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($orders)) {
            return [];
        }

        $orderIds = array_values(array_filter(array_map(static function (array $order): int {
            return (int)($order['ma_hoa_don'] ?? 0);
        }, $orders)));

        if (empty($orderIds)) {
            return $orders;
        }

        $placeholders = [];
        $params = [];
        foreach ($orderIds as $index => $orderId) {
            $key = ':order_id_' . $index;
            $placeholders[] = $key;
            $params[$key] = $orderId;
        }

        $detailSql = "SELECT ct.ma_hoa_don,
                             ct.ma_san_pham,
                             ct.so_luong,
                             ct.don_gia,
                             sp.ten_san_pham,
                             sp.link_hinh_anh,
                             COALESCE(th.ten_thuong_hieu, '') AS thuong_hieu
                      FROM chi_tiet_hoa_don ct
                      LEFT JOIN san_pham sp ON sp.ma_san_pham = ct.ma_san_pham
                      LEFT JOIN thuong_hieu th ON sp.ma_thuong_hieu = th.ma_thuong_hieu
                      WHERE ct.ma_hoa_don IN (" . implode(', ', $placeholders) . ")
                      ORDER BY ct.ma_hoa_don DESC, ct.id ASC";
        $detailSt = $this->pdo->prepare($detailSql);
        $detailSt->execute($params);
        $details = $detailSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $detailsByOrder = [];
        foreach ($details as $detail) {
            $orderId = (int)($detail['ma_hoa_don'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $detailsByOrder[$orderId][] = $detail;
        }

        foreach ($orders as &$order) {
            $orderId = (int)($order['ma_hoa_don'] ?? 0);
            $order['items'] = $detailsByOrder[$orderId] ?? [];
        }
        unset($order);

        return $orders;
    }

    public function getCartItems(int $maKh): array {
        $sql = "SELECT gh.id, gh.ma_san_pham, gh.so_luong, gh.updated_at,
                       sp.ten_san_pham, sp.gia_ban, sp.link_hinh_anh
                FROM gio_hang gh
                LEFT JOIN san_pham sp ON sp.ma_san_pham = gh.ma_san_pham
                WHERE gh.ma_kh = :ma_kh
                ORDER BY gh.updated_at DESC, gh.id DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute([':ma_kh' => $maKh]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getLoaiDaOptions(): array {
        $sql = "SELECT ten_loai_da FROM loai_da ORDER BY ma_loai_da ASC";
        $st = $this->pdo->query($sql);
        $rows = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
        return $rows ?: [];
    }

    private function layHoacTaoMaLoaiDa(string $tenLoaiDa): ?int {
        $ten = trim($tenLoaiDa);
        if ($ten === '') {
            return null;
        }

        $sqlFind = "SELECT ma_loai_da FROM loai_da WHERE LOWER(ten_loai_da) = LOWER(:ten) LIMIT 1";
        $stFind = $this->pdo->prepare($sqlFind);
        $stFind->execute([':ten' => $ten]);
        $id = $stFind->fetchColumn();
        if ($id) {
            return (int)$id;
        }

        $sqlInsert = "INSERT INTO loai_da(ten_loai_da) VALUES (:ten) ON CONFLICT (ten_loai_da) DO NOTHING";
        $stInsert = $this->pdo->prepare($sqlInsert);
        $stInsert->execute([':ten' => $ten]);

        $stFind->execute([':ten' => $ten]);
        $id = $stFind->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function parseLoaiDaId(?string $tinhTrangDacBiet): ?int {
        $text = (string)($tinhTrangDacBiet ?? '');
        if ($text === '') {
            return null;
        }

        if (preg_match('/loaida:(\d+)/i', $text, $m)) {
            return (int)$m[1];
        }

        return null;
    }

    private function mergeTinhTrangDacBiet(?string $current, int $maLoaiDa): string {
        $text = trim((string)($current ?? ''));
        if ($text === '') {
            return 'loaida:' . $maLoaiDa;
        }

        $text = preg_replace('/\s*\|?\s*loaida:\d+/i', '', $text);
        $text = trim((string)$text, " |\t\n\r\0\x0B");

        return $text === ''
            ? ('loaida:' . $maLoaiDa)
            : ('loaida:' . $maLoaiDa . ' | ' . $text);
    }

    public function getSkinProfileByEmail(string $email): ?array {
        $kh = $this->getKhachHangByEmail($email);
        if (!$kh) {
            return null;
        }

        $maLoaiDa = $this->parseLoaiDaId($kh['tinh_trang_dac_biet'] ?? null);
        $tenLoaiDa = null;
        if ($maLoaiDa) {
            $st = $this->pdo->prepare("SELECT ten_loai_da FROM loai_da WHERE ma_loai_da = :id LIMIT 1");
            $st->execute([':id' => $maLoaiDa]);
            $tenLoaiDa = $st->fetchColumn() ?: null;
        }

        return [
            'loai_da' => $tenLoaiDa,
            'van_de_da' => $kh['van_de_da'] ?? null,
            'ngan_sach' => $kh['ngan_sach'] ?? null,
            'ma_kh' => $kh['ma_kh'] ?? null,
        ];
    }

    public function saveSkinProfileByEmail(string $hoTen, string $email, string $loaiDa, array $vanDeDa, ?int $nganSach): bool {
        $kh = $this->ensureKhachHangByEmail($hoTen, $email);
        if (!$kh) return false;

        $maLoaiDa = $this->layHoacTaoMaLoaiDa($loaiDa);
        $vanDeDaText = implode(', ', array_values(array_filter(array_map('trim', $vanDeDa), fn($v) => $v !== '')));
        $tinhTrangDacBietMoi = $kh['tinh_trang_dac_biet'] ?? null;
        if ($maLoaiDa) {
            $tinhTrangDacBietMoi = $this->mergeTinhTrangDacBiet($kh['tinh_trang_dac_biet'] ?? null, $maLoaiDa);
        }

        $sql = "UPDATE khach_hang
                SET ho_ten = COALESCE(NULLIF(:ho_ten, ''), ho_ten),
                    van_de_da = :van_de_da,
                    ngan_sach = :ngan_sach,
                    tinh_trang_dac_biet = :tinh_trang_dac_biet,
                    updated_at = CURRENT_TIMESTAMP
                WHERE ma_kh = :ma_kh";

        $st = $this->pdo->prepare($sql);
        return $st->execute([
            ':ho_ten' => $hoTen,
            ':van_de_da' => ($vanDeDaText !== '' ? $vanDeDaText : null),
            ':ngan_sach' => $nganSach,
            ':tinh_trang_dac_biet' => $tinhTrangDacBietMoi,
            ':ma_kh' => $kh['ma_kh'],
        ]);
    }

    public function saveThongTinKhachHang(string $email, array $data): bool {
        $hoTen = trim((string)($data['ho_ten'] ?? ''));
        $kh = $this->ensureKhachHangByEmail($hoTen, $email);
        if (!$kh) return false;

        $soDienThoai = trim((string)($data['so_dien_thoai'] ?? ''));
        $gioiTinh = trim((string)($data['gioi_tinh'] ?? ''));
        $diaChi = trim((string)($data['dia_chi'] ?? ''));
        $namSinh = $data['nam_sinh'] ?? null;

        $sqlKh = "UPDATE khach_hang
                  SET ho_ten = COALESCE(NULLIF(:ho_ten, ''), ho_ten),
                      so_dien_thoai = :so_dien_thoai,
                      gioi_tinh = :gioi_tinh,
                      nam_sinh = :nam_sinh,
                      dia_chi = :dia_chi,
                      updated_at = CURRENT_TIMESTAMP
                  WHERE ma_kh = :ma_kh";
        $stKh = $this->pdo->prepare($sqlKh);
        $okKh = $stKh->execute([
            ':ho_ten' => ($hoTen !== '' ? $hoTen : null),
            ':so_dien_thoai' => ($soDienThoai !== '' ? $soDienThoai : null),
            ':gioi_tinh' => ($gioiTinh !== '' ? $gioiTinh : null),
            ':nam_sinh' => $namSinh,
            ':dia_chi' => ($diaChi !== '' ? $diaChi : null),
            ':ma_kh' => $kh['ma_kh'],
        ]);

        if (!$okKh) return false;

        if ($hoTen !== '') {
            $sqlNd = "UPDATE nguoidung SET ho_ten = :ho_ten WHERE LOWER(email) = LOWER(:email)";
            $stNd = $this->pdo->prepare($sqlNd);
            $stNd->execute([
                ':ho_ten' => $hoTen,
                ':email' => $email,
            ]);
        }

        return true;
    }

    public function doiMatKhau(int $nguoiDungId, string $matKhauHienTai, string $matKhauMoi): array {
        $sql = "SELECT mat_khau FROM nguoidung WHERE id = :id LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':id' => $nguoiDungId]);
        $hash = $st->fetchColumn();

        if (!$hash) {
            return ['ok' => false, 'message' => 'Không tìm thấy tài khoản.'];
        }

        if (!$this->xacThucMatKhau($matKhauHienTai, (string)$hash)) {
            return ['ok' => false, 'message' => 'Mật khẩu hiện tại không đúng.'];
        }

        $hashMoi = password_hash($matKhauMoi, PASSWORD_BCRYPT);
        $sqlUpdate = "UPDATE nguoidung SET mat_khau = :mat_khau WHERE id = :id";
        $stUpdate = $this->pdo->prepare($sqlUpdate);
        $ok = $stUpdate->execute([
            ':mat_khau' => $hashMoi,
            ':id' => $nguoiDungId,
        ]);

        return $ok
            ? ['ok' => true, 'message' => 'Đổi mật khẩu thành công.']
            : ['ok' => false, 'message' => 'Không thể đổi mật khẩu.'];
    }

    public function doiMatKhauByEmail(string $email, string $matKhauHienTai, string $matKhauMoi): array {
        $sql = "SELECT id, mat_khau FROM nguoidung WHERE LOWER(email) = LOWER(:email) LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':email' => $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$row || empty($row['mat_khau'])) {
            return ['ok' => false, 'message' => 'Không tìm thấy tài khoản.'];
        }

        if (!$this->xacThucMatKhau($matKhauHienTai, (string)$row['mat_khau'])) {
            return ['ok' => false, 'message' => 'Mật khẩu hiện tại không đúng.'];
        }

        $hashMoi = password_hash($matKhauMoi, PASSWORD_BCRYPT);
        $sqlUpdate = "UPDATE nguoidung SET mat_khau = :mat_khau WHERE id = :id";
        $stUpdate = $this->pdo->prepare($sqlUpdate);
        $ok = $stUpdate->execute([
            ':mat_khau' => $hashMoi,
            ':id' => (int)$row['id'],
        ]);

        return $ok
            ? ['ok' => true, 'message' => 'Đổi mật khẩu thành công.']
            : ['ok' => false, 'message' => 'Không thể đổi mật khẩu.'];
    }

    public function capNhatMatKhauTheoEmail(string $email, string $matKhauMoi): array {
        $email = trim($email);
        if ($email === '') {
            return ['ok' => false, 'message' => 'Không xác định được tài khoản.'];
        }

        $hashMoi = password_hash($matKhauMoi, PASSWORD_BCRYPT);
        $sql = "UPDATE nguoidung SET mat_khau = :mat_khau WHERE LOWER(email) = LOWER(:email)";
        $st = $this->pdo->prepare($sql);
        $ok = $st->execute([
            ':mat_khau' => $hashMoi,
            ':email' => $email,
        ]);

        return $ok
            ? ['ok' => true, 'message' => 'Đổi mật khẩu thành công.']
            : ['ok' => false, 'message' => 'Không thể đổi mật khẩu.'];
    }


    // --- CÁC HÀM XỬ LÝ LỊCH SỬ TÌM KIẾM CHO AI ---

    public function luuLichSuTimKiem(string $email, string $tuKhoa): bool {
        try {
            $email = trim($email);
            $tuKhoa = trim($tuKhoa);
            if ($email === '' || $tuKhoa === '') {
                return false;
            }

            $kh = $this->getKhachHangByEmail($email);
            if (!$kh) {
                $account = $this->getAccountOverviewByEmail($email);
                $hoTen = trim((string)($account['ho_ten'] ?? ''));
                if ($hoTen === '') {
                    $hoTen = strstr($email, '@', true) ?: $email;
                }

                $kh = $this->ensureKhachHangByEmail($hoTen, $email);
            }

            if (!$kh || empty($kh['ma_kh'])) {
                return false;
            }

            $sql = "INSERT INTO lich_su_tim_kiem (ma_kh, tu_khoa, ngay_tim) 
                    VALUES (:ma_kh, :tu_khoa, CURRENT_TIMESTAMP)";
            $st = $this->pdo->prepare($sql);
            return $st->execute([
                ':ma_kh' => $kh['ma_kh'],
                ':tu_khoa' => $tuKhoa
            ]);
        } catch (Throwable $e) {
            error_log('luuLichSuTimKiem error: ' . $e->getMessage());
            return false;
        }
    }

    public function getTuKhoaGanDay(string $email, int $limit = 3): array {
        try {
            $kh = $this->getKhachHangByEmail($email);
            if (!$kh) return [];

            // Lấy các từ khóa mới nhất, loại bỏ trùng lặp
            $sql = "SELECT tu_khoa FROM lich_su_tim_kiem
                    WHERE ma_kh = :ma_kh
                    GROUP BY tu_khoa
                    ORDER BY MAX(ngay_tim) DESC
                    LIMIT :limit";
            $st = $this->pdo->prepare($sql);
            $st->bindValue(':ma_kh', $kh['ma_kh'], PDO::PARAM_INT);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->execute();
            
            return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            error_log('getTuKhoaGanDay error: ' . $e->getMessage());
            return [];
        }
    }
}
