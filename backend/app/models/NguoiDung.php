<?php
// backend/app/models/NguoiDung.php

class NguoiDung {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->ensureAuthTables();
    }

    private function ensureAuthTables(): void {
        $sql = "CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id BIGSERIAL PRIMARY KEY,
                    email VARCHAR(255) NOT NULL,
                    token_hash VARCHAR(255) NOT NULL,
                    expires_at TIMESTAMP NOT NULL,
                    used_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )";

        try {
            $this->pdo->exec($sql);
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_email ON password_reset_tokens (LOWER(email))');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_hash ON password_reset_tokens (token_hash)');
        } catch (Throwable $e) {
            // Keep auth flow resilient if the DB user cannot alter schema.
        }
    }

    public function timTheoEmail(string $email): ?array {
        $sql = "SELECT * FROM nguoidung WHERE LOWER(email) = LOWER(:email) LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute(['email' => $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function timNhanVienTheoEmail(string $email): ?array {
        $sql = "SELECT nv.*, vt.ten_vai_tro
                FROM nhan_vien nv
                LEFT JOIN vai_tro vt ON vt.ma_vai_tro = nv.ma_vai_tro
                WHERE LOWER(nv.email) = LOWER(:email)
                LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute(['email' => $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function taoMoi(string $hoTen, string $email, string $matKhauPlain): bool {
        $hash = password_hash($matKhauPlain, PASSWORD_BCRYPT);

        $sql = "INSERT INTO nguoidung(ho_ten, email, mat_khau) VALUES (:ho_ten, :email, :mat_khau)";
        $st = $this->pdo->prepare($sql);
        $ok = $st->execute([
            'ho_ten' => $hoTen,
            'email' => $email,
            'mat_khau' => $hash
        ]);

        if ($ok) {
            $this->ensureKhachHang($hoTen, $email);
        }

        return $ok;
    }

    public function findOrCreateCustomerAccount(string $hoTen, string $email): ?array {
        $account = $this->timTheoEmail($email);
        if (!$account) {
            $created = $this->taoMoi($hoTen, $email, bin2hex(random_bytes(16)));
            if (!$created) {
                return null;
            }
            $account = $this->timTheoEmail($email);
        }

        if ($account) {
            $this->ensureKhachHang($hoTen, $email);
        }

        return $account ?: null;
    }

    public function createPasswordResetToken(string $email, int $ttlMinutes = 30): ?string {
        $account = $this->timTheoEmail($email);
        if (!$account) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + max(5, $ttlMinutes) * 60);

        $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE LOWER(email) = LOWER(:email) OR expires_at < CURRENT_TIMESTAMP OR used_at IS NOT NULL')
            ->execute([':email' => $email]);

        $st = $this->pdo->prepare('INSERT INTO password_reset_tokens (email, token_hash, expires_at) VALUES (:email, :token_hash, :expires_at)');
        $ok = $st->execute([
            ':email' => $email,
            ':token_hash' => $hash,
            ':expires_at' => $expiresAt,
        ]);

        return $ok ? $token : null;
    }

    public function validatePasswordResetToken(string $token): ?array {
        $hash = hash('sha256', trim($token));
        $st = $this->pdo->prepare('SELECT * FROM password_reset_tokens WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at >= CURRENT_TIMESTAMP ORDER BY id DESC LIMIT 1');
        $st->execute([':token_hash' => $hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function consumePasswordResetToken(int $id): void {
        $st = $this->pdo->prepare('UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = :id');
        $st->execute([':id' => $id]);
    }

    public function capNhatMatKhauTheoEmail(string $email, string $matKhauMoi): array {
        $email = trim($email);
        if ($email === '') {
            return ['ok' => false, 'message' => 'Khong xac dinh duoc tai khoan.'];
        }

        $hashMoi = password_hash($matKhauMoi, PASSWORD_BCRYPT);
        $sql = 'UPDATE nguoidung SET mat_khau = :mat_khau WHERE LOWER(email) = LOWER(:email)';
        $st = $this->pdo->prepare($sql);
        $ok = $st->execute([
            ':mat_khau' => $hashMoi,
            ':email' => $email,
        ]);

        return $ok
            ? ['ok' => true, 'message' => 'Dat lai mat khau thanh cong.']
            : ['ok' => false, 'message' => 'Khong the cap nhat mat khau.'];
    }

    public function ensureKhachHang(string $hoTen, string $email): bool {
        $sqlFind = "SELECT ma_kh FROM khach_hang WHERE LOWER(email) = LOWER(:email) LIMIT 1";
        $stFind = $this->pdo->prepare($sqlFind);
        $stFind->execute(['email' => $email]);
        $maKh = $stFind->fetchColumn();

        if ($maKh) {
            $sqlUpdate = "UPDATE khach_hang
                          SET ho_ten = COALESCE(NULLIF(:ho_ten, ''), ho_ten),
                              updated_at = CURRENT_TIMESTAMP
                          WHERE ma_kh = :ma_kh";
            $stUpdate = $this->pdo->prepare($sqlUpdate);
            return $stUpdate->execute([
                'ho_ten' => $hoTen,
                'ma_kh' => $maKh,
            ]);
        }

        $sqlInsert = "INSERT INTO khach_hang(ma_kh, ho_ten, email, created_at, updated_at)
                      VALUES (
                          COALESCE((SELECT MAX(ma_kh) FROM khach_hang), 0) + 1,
                          :ho_ten,
                          :email,
                          CURRENT_TIMESTAMP,
                          CURRENT_TIMESTAMP
                      )";
        $stInsert = $this->pdo->prepare($sqlInsert);
        return $stInsert->execute([
            'ho_ten' => $hoTen,
            'email' => $email,
        ]);
    }

    private function layHoacTaoMaLoaiDa(?string $tenLoaiDa): ?int {
        $ten = trim((string)($tenLoaiDa ?? ''));
        if ($ten === '') {
            return null;
        }

        $sqlFind = "SELECT ma_loai_da FROM loai_da WHERE LOWER(ten_loai_da) = LOWER(:ten) LIMIT 1";
        $stFind = $this->pdo->prepare($sqlFind);
        $stFind->execute(['ten' => $ten]);
        $id = $stFind->fetchColumn();
        if ($id) {
            return (int)$id;
        }

        $sqlInsert = "INSERT INTO loai_da(ten_loai_da) VALUES (:ten) ON CONFLICT (ten_loai_da) DO NOTHING";
        $stInsert = $this->pdo->prepare($sqlInsert);
        $stInsert->execute(['ten' => $ten]);

        $stFind->execute(['ten' => $ten]);
        $id = $stFind->fetchColumn();
        return $id ? (int)$id : null;
    }
    public function luuKhaoSatKhachHang(string $hoTen, string $email, array $khaoSat): bool {
        $sqlFind = "SELECT ma_kh FROM khach_hang WHERE LOWER(email) = LOWER(:email) LIMIT 1";
        $stFind = $this->pdo->prepare($sqlFind);
        $stFind->execute(['email' => $email]);
        $maKh = $stFind->fetchColumn();

        $maLoaiDa = $this->layHoacTaoMaLoaiDa($khaoSat['loai_da'] ?? null);
        $tinhTrangDacBietRaw = trim((string)($khaoSat['tinh_trang_dac_biet'] ?? ''));
        $tinhTrangDacBiet = $tinhTrangDacBietRaw !== '' ? $tinhTrangDacBietRaw : null;
        if ($maLoaiDa) {
            $tinhTrangDacBiet = 'loaida:' . $maLoaiDa . ($tinhTrangDacBiet ? ' | ' . $tinhTrangDacBiet : '');
        }

        $payload = [
            'ho_ten' => $hoTen,
            'email' => $email,
            'gioi_tinh' => $khaoSat['gioi_tinh'] ?? null,
            'nam_sinh' => $khaoSat['nam_sinh'] ?? null,
            'muc_do_nhay_cam' => $khaoSat['muc_do_nhay_cam'] ?? null,
            'van_de_da' => $khaoSat['van_de_da'] ?? null,
            'muc_do_mun' => $khaoSat['muc_do_mun'] ?? null,
            'muc_tieu_cham_soc' => $khaoSat['muc_tieu_cham_soc'] ?? null,
            'tieu_chi_uu_tien' => $khaoSat['tieu_chi_uu_tien'] ?? null,
            'tinh_trang_dac_biet' => $tinhTrangDacBiet,
            'kinh_nghiem_skincare' => $khaoSat['kinh_nghiem_skincare'] ?? null,
            'so_buoc_skincare' => $khaoSat['so_buoc_skincare'] ?? null,
            'thanh_phan_tranh' => $khaoSat['thanh_phan_tranh'] ?? null,
            'ngan_sach' => $khaoSat['ngan_sach'] ?? null,
        ];

        if ($maKh) {
            $sqlUpdate = "UPDATE khach_hang
                          SET ho_ten = COALESCE(NULLIF(:ho_ten, ''), ho_ten),
                              gioi_tinh = :gioi_tinh,
                              nam_sinh = :nam_sinh,
                              muc_do_nhay_cam = :muc_do_nhay_cam,
                              van_de_da = :van_de_da,
                              muc_do_mun = :muc_do_mun,
                              muc_tieu_cham_soc = :muc_tieu_cham_soc,
                              tieu_chi_uu_tien = :tieu_chi_uu_tien,
                              tinh_trang_dac_biet = :tinh_trang_dac_biet,
                              kinh_nghiem_skincare = :kinh_nghiem_skincare,
                              so_buoc_skincare = :so_buoc_skincare,
                              thanh_phan_tranh = :thanh_phan_tranh,
                              ngan_sach = :ngan_sach,
                              updated_at = CURRENT_TIMESTAMP
                          WHERE ma_kh = :ma_kh";
            $stUpdate = $this->pdo->prepare($sqlUpdate);
            $updatePayload = $payload;
            unset($updatePayload['email']);
            $updatePayload['ma_kh'] = $maKh;
            return $stUpdate->execute($updatePayload);
        }

        $sqlInsert = "INSERT INTO khach_hang(
                          ma_kh, ho_ten, email, gioi_tinh, nam_sinh,
                          muc_do_nhay_cam, van_de_da, muc_do_mun,
                          muc_tieu_cham_soc, tieu_chi_uu_tien, tinh_trang_dac_biet,
                          kinh_nghiem_skincare, so_buoc_skincare, thanh_phan_tranh,
                          ngan_sach, created_at, updated_at
                      ) VALUES (
                          COALESCE((SELECT MAX(ma_kh) FROM khach_hang), 0) + 1, :ho_ten, :email, :gioi_tinh, :nam_sinh,
                          :muc_do_nhay_cam, :van_de_da, :muc_do_mun,
                          :muc_tieu_cham_soc, :tieu_chi_uu_tien, :tinh_trang_dac_biet,
                          :kinh_nghiem_skincare, :so_buoc_skincare, :thanh_phan_tranh,
                          :ngan_sach, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                      )";
        $stInsert = $this->pdo->prepare($sqlInsert);
        return $stInsert->execute($payload);
    }
}
