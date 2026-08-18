<?php
// backend/app/models/NguoiDung.php

class NguoiDung {
    private $db;

    public function __construct($db) {
        $this->db = $db;
        // MongoDB không cần CREATE TABLE (schema-less), nên hàm ensureAuthTables không cần chạy SQL nữa
    }

    public function timTheoEmail(string $email): ?array {
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i'); // So sánh email không phân biệt hoa thường
        $user = $this->db->nguoidung->findOne(['email' => $regex]);
        return $user ? (array) $user : null;
    }

    public function timNhanVienTheoEmail(string $email): ?array {
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
        
        // MongoDB không có JOIN dễ dàng như SQL, ta tìm nhân viên trước
        $nhanVien = $this->db->nhan_vien->findOne(['email' => $regex]);
        
        if ($nhanVien) {
            $nhanVien = (array) $nhanVien;
            // Nếu có ma_vai_tro, tìm tên vai trò bên bảng vai_tro (giả lập LEFT JOIN)
            if (isset($nhanVien['ma_vai_tro'])) {
                $vaiTro = $this->db->vai_tro->findOne(['ma_vai_tro' => $nhanVien['ma_vai_tro']]);
                if ($vaiTro) {
                    $nhanVien['ten_vai_tro'] = $vaiTro['ten_vai_tro'];
                }
            }
            return $nhanVien;
        }
        return null;
    }

    public function taoMoi(string $hoTen, string $email, string $matKhauPlain, array $consents = []): bool {
        $email = trim($email);
        $hash = password_hash($matKhauPlain, PASSWORD_BCRYPT);
        $privacyConsent = !empty($consents['privacy_consent']);
        $termsAgree = !empty($consents['terms_agree']);
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');

        // PURGE LEFTOVER ACCOUNT, SURVEY, & ORDERS DATA FOR THIS EMAIL TO ENSURE A FRESH START
        $this->db->nguoidung->deleteMany(['email' => $regex]);

        $oldKhList = iterator_to_array($this->db->khach_hang->find(['email' => $regex]));
        foreach ($oldKhList as $oldKh) {
            $oldMaKh = (int)($oldKh['ma_kh'] ?? 0);
            if ($oldMaKh > 0) {
                $orderDocs = iterator_to_array($this->db->hoa_don->find(['$or' => [['ma_kh' => $oldMaKh], ['email' => $regex]]]));
                $orderIds = array_column($orderDocs, 'ma_hoa_don');
                if (!empty($orderIds)) {
                    $this->db->chi_tiet_hoa_don->deleteMany(['ma_hoa_don' => ['$in' => $orderIds]]);
                }
                $this->db->hoa_don->deleteMany(['$or' => [['ma_kh' => $oldMaKh], ['email' => $regex]]]);
            }
        }
        $this->db->khach_hang->deleteMany(['email' => $regex]);

        $result = $this->db->nguoidung->insertOne([
            'ho_ten' => $hoTen,
            'email' => $email,
            'mat_khau' => $hash,
            'terms_agree' => $termsAgree,
            'privacy_consent' => $privacyConsent,
            'recommendation_consent' => $privacyConsent,
            'created_at' => new \MongoDB\BSON\UTCDateTime()
        ]);

        $ok = $result->getInsertedCount() > 0;
        if ($ok) {
            $this->ensureKhachHang($hoTen, $email);
            $this->db->khach_hang->updateOne(
                ['email' => $regex],
                ['$set' => [
                    'terms_agree' => $termsAgree,
                    'privacy_consent' => $privacyConsent,
                    'recommendation_consent' => $privacyConsent,
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                ]]
            );
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
        $expiresAt = new \MongoDB\BSON\UTCDateTime((time() + max(5, $ttlMinutes) * 60) * 1000);

        // Xóa token cũ
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
        $this->db->password_reset_tokens->deleteMany([
            '$or' => [
                ['email' => $regex],
                ['expires_at' => ['$lt' => new \MongoDB\BSON\UTCDateTime()]],
                ['used_at' => ['$ne' => null]]
            ]
        ]);

        // TỰ ĐỘNG TĂNG ID ĐỂ KHÔNG BỊ TRÙNG NULL NỮA
        $lastToken = $this->db->password_reset_tokens->findOne([], ['sort' => ['id' => -1]]);
        $newId = $lastToken && isset($lastToken['id']) ? (int)$lastToken['id'] + 1 : 1;

        $result = $this->db->password_reset_tokens->insertOne([
            'id' => $newId,
            'email' => $email,
            'token_hash' => $hash,
            'expires_at' => $expiresAt,
            'used_at' => null,
            'created_at' => new \MongoDB\BSON\UTCDateTime()
        ]);

        return $result->getInsertedCount() > 0 ? $token : null;
    }

    public function validatePasswordResetToken(string $token): ?array {
        $hash = hash('sha256', trim($token));
        
        $options = [
            'sort' => ['id' => -1], // Sort theo id
            'limit' => 1
        ];

        $filter = [
            'token_hash' => $hash,
            'used_at' => null,
            'expires_at' => ['$gte' => new \MongoDB\BSON\UTCDateTime()]
        ];

        $tokenDoc = $this->db->password_reset_tokens->findOne($filter, $options);
        return $tokenDoc ? (array) $tokenDoc : null;
    }

    public function consumePasswordResetToken($id): void {
        // Cập nhật token theo đúng id tự tăng
        $this->db->password_reset_tokens->updateOne(
            ['id' => (int)$id],
            ['$set' => ['used_at' => new \MongoDB\BSON\UTCDateTime()]]
        );
    }

    public function capNhatMatKhauTheoEmail(string $email, string $matKhauMoi): array {
        $email = trim($email);
        if ($email === '') {
            return ['ok' => false, 'message' => 'Khong xac dinh duoc tai khoan.'];
        }

        $hashMoi = password_hash($matKhauMoi, PASSWORD_BCRYPT);
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');

        $result = $this->db->nguoidung->updateOne(
            ['email' => $regex],
            ['$set' => ['mat_khau' => $hashMoi]]
        );

        return $result->getModifiedCount() > 0
            ? ['ok' => true, 'message' => 'Dat lai mat khau thanh cong.']
            : ['ok' => false, 'message' => 'Khong the cap nhat mat khau.'];
    }

    public function ensureKhachHang(string $hoTen, string $email): bool {
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
        $khachHang = $this->db->khach_hang->findOne(['email' => $regex]);

        if ($khachHang) {
            $updateData = ['updated_at' => new \MongoDB\BSON\UTCDateTime()];
            if (!empty($hoTen)) {
                $updateData['ho_ten'] = $hoTen;
            }
            $result = $this->db->khach_hang->updateOne(
                ['_id' => $khachHang['_id']],
                ['$set' => $updateData]
            );
            return $result->getMatchedCount() > 0;
        }

        // Auto-increment ma_kh (Mô phỏng MAX(ma_kh) + 1 của SQL)
        $lastKh = $this->db->khach_hang->findOne([], ['sort' => ['ma_kh' => -1]]);
        $newMaKh = $lastKh ? (int)$lastKh['ma_kh'] + 1 : 1;

        $result = $this->db->khach_hang->insertOne([
            'ma_kh' => $newMaKh,
            'ho_ten' => $hoTen,
            'email' => $email,
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ]);

        return $result->getInsertedCount() > 0;
    }

    private function layHoacTaoMaLoaiDa(?string $tenLoaiDa): ?int {
        $ten = trim((string)($tenLoaiDa ?? ''));
        if ($ten === '') return null;

        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($ten) . '$', 'i');
        $loaiDa = $this->db->loai_da->findOne(['ten_loai_da' => $regex]);

        if ($loaiDa) {
            return (int) $loaiDa['ma_loai_da'];
        }

        // Auto-increment
        $lastLoai = $this->db->loai_da->findOne([], ['sort' => ['ma_loai_da' => -1]]);
        $newMa = $lastLoai ? (int)$lastLoai['ma_loai_da'] + 1 : 1;

        $this->db->loai_da->insertOne([
            'ma_loai_da' => $newMa,
            'ten_loai_da' => $ten
        ]);

        return $newMa;
    }

    public function luuKhaoSatKhachHang(string $hoTen, string $email, array $khaoSat): bool {
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
        $khachHang = $this->db->khach_hang->findOne(['email' => $regex]);

        $maLoaiDa = $this->layHoacTaoMaLoaiDa($khaoSat['loai_da'] ?? null);
        $tinhTrangDacBietRaw = trim((string)($khaoSat['tinh_trang_dac_biet'] ?? ''));
        $tinhTrangDacBiet = $tinhTrangDacBietRaw !== '' ? $tinhTrangDacBietRaw : null;
        if ($maLoaiDa) {
            $tinhTrangDacBiet = 'loaida:' . $maLoaiDa . ($tinhTrangDacBiet ? ' | ' . $tinhTrangDacBiet : '');
        }

        $payload = [
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
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];

        if (!empty($hoTen)) {
            $payload['ho_ten'] = $hoTen;
        }

        if ($khachHang) {
            $result = $this->db->khach_hang->updateOne(
                ['_id' => $khachHang['_id']],
                ['$set' => $payload]
            );
            return true; // Trả về true luôn để tránh lỗi nếu dữ liệu y hệt không update
        }

        // Tạo mới nếu chưa có
        $lastKh = $this->db->khach_hang->findOne([], ['sort' => ['ma_kh' => -1]]);
        $newMaKh = $lastKh ? (int)$lastKh['ma_kh'] + 1 : 1;

        $payload['ma_kh'] = $newMaKh;
        $payload['email'] = $email;
        $payload['created_at'] = new \MongoDB\BSON\UTCDateTime();

        $result = $this->db->khach_hang->insertOne($payload);
        return $result->getInsertedCount() > 0;
    }
}
