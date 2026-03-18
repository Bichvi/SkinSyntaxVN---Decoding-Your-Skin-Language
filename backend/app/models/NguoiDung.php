<?php
// backend/app/models/NguoiDung.php

class NguoiDung {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function timTheoEmail(string $email): ?array {
        $sql = "SELECT * FROM nguoidung WHERE LOWER(email) = LOWER(:email) LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute(['email' => $email]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function taoMoi(string $hoTen, string $email, string $matKhauPlain): bool {
        $hash = password_hash($matKhauPlain, PASSWORD_BCRYPT);

        $sql = "INSERT INTO nguoidung(ho_ten, email, mat_khau) VALUES (:ho_ten, :email, :mat_khau)";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            'ho_ten' => $hoTen,
            'email' => $email,
            'mat_khau' => $hash
        ]);
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
