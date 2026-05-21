<?php
// backend/app/models/TaiKhoan.php

class TaiKhoan {
    private $db;
    private array $columnCache = [];

    public function __construct($db) {
        $this->db = $db;
        // MongoDB không cần đảm bảo cột loyalty hay order
    }

    private function getNextNumericId(string $collection, string $column): int {
        $lastDoc = $this->db->{$collection}->findOne([], ['sort' => [$column => -1]]);
        return $lastDoc ? (int)$lastDoc[$column] + 1 : 1;
    }

    public function getAccountOverview(int $nguoiDungId): ?array {
        $nd = $this->db->nguoidung->findOne(['_id' => $nguoiDungId]);
        if (!$nd && is_numeric($nguoiDungId)) {
             $nd = $this->db->nguoidung->findOne(['id' => (int) $nguoiDungId]);
        }
        
        if (!$nd) return null;
        $row = (array) $nd;

        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($row['email'] ?? '') . '$', 'i');
        $kh = $this->db->khach_hang->findOne(['email' => $regex]);

        if ($kh) {
            $row['ngay_tao'] = $kh['created_at'] ?? null;
        }

        // Đổi thời gian sang định dạng chuỗi
        if (isset($row['created_at']) && $row['created_at'] instanceof \MongoDB\BSON\UTCDateTime) {
             $row['ngay_tao'] = $row['created_at']->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
        } elseif (isset($row['ngay_tao']) && $row['ngay_tao'] instanceof \MongoDB\BSON\UTCDateTime) {
             $row['ngay_tao'] = $row['ngay_tao']->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
        }

        return $row;
    }

    public function getAccountOverviewByEmail(string $email): ?array {
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
        $nd = $this->db->nguoidung->findOne(['email' => $regex]);
        
        if (!$nd) return null;
        $row = (array) $nd;

        $kh = $this->db->khach_hang->findOne(['email' => $regex]);
        if ($kh) {
            $row['ngay_tao'] = $kh['created_at'] ?? null;
        }

        if (isset($row['created_at']) && $row['created_at'] instanceof \MongoDB\BSON\UTCDateTime) {
             $row['ngay_tao'] = $row['created_at']->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
        } elseif (isset($row['ngay_tao']) && $row['ngay_tao'] instanceof \MongoDB\BSON\UTCDateTime) {
             $row['ngay_tao'] = $row['ngay_tao']->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
        }

        return $row;
    }

    private function xacThucMatKhau(string $matKhauNhap, string $matKhauLuu): bool {
        if ($matKhauLuu === '') return false;

        $info = password_get_info($matKhauLuu);
        if (!empty($info['algo'])) {
            return password_verify($matKhauNhap, $matKhauLuu);
        }

        if (hash_equals($matKhauLuu, $matKhauNhap)) return true;

        if (preg_match('/^[a-f0-9]{32}$/i', $matKhauLuu) === 1) {
            return hash_equals(strtolower($matKhauLuu), md5($matKhauNhap));
        }

        if (preg_match('/^[a-f0-9]{40}$/i', $matKhauLuu) === 1) {
            return hash_equals(strtolower($matKhauLuu), sha1($matKhauNhap));
        }

        return false;
    }

    public function getKhachHangByEmail(string $email): ?array {
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
        $row = $this->db->khach_hang->findOne(['email' => $regex]);
        return $row ? (array)$row : null;
    }

    private function ensureKhachHangByEmail(string $hoTen, string $email): ?array {
        $kh = $this->getKhachHangByEmail($email);
        if ($kh) return $kh;

        $maKhMoi = $this->getNextNumericId('khach_hang', 'ma_kh');
        $payload = [
            'ma_kh' => $maKhMoi,
            'ho_ten' => $hoTen,
            'email' => $email,
            'diemtl' => 0,
            'loaikh' => 'Thuong',
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];
        
        $this->db->khach_hang->insertOne($payload);
        return $payload;
    }

    public function getOrderHistory(int $maKh): array {
        $options = ['sort' => ['ngay_dat' => -1, 'ma_hoa_don' => -1]];
        $cursor = $this->db->hoa_don->find(['ma_kh' => $maKh], $options);
        $orders = [];
        $orderIds = [];

        foreach ($cursor as $doc) {
            $order = (array) $doc;
            
            $order['diem_cong'] = $order['diem_cong'] ?? 0;
            $order['da_tich_diem'] = $order['da_tich_diem'] ?? false;
            $order['diem_su_dung'] = $order['diem_su_dung'] ?? 0;
            $order['tien_giam_diem'] = $order['tien_giam_diem'] ?? 0;
            $order['ly_do_huy'] = $order['ly_do_huy'] ?? '';

            // Định dạng ngày
            if (isset($order['ngay_dat']) && $order['ngay_dat'] instanceof \MongoDB\BSON\UTCDateTime) {
                $order['ngay_dat'] = $order['ngay_dat']->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
            }

            $orders[] = $order;
            $orderIds[] = $order['ma_hoa_don'];
        }

        if (empty($orderIds)) return [];

        $detailsCursor = $this->db->chi_tiet_hoa_don->find(['ma_hoa_don' => ['$in' => $orderIds]], ['sort' => ['ma_hoa_don' => -1, 'id' => 1]]);
        $detailsByOrder = [];

        foreach ($detailsCursor as $doc) {
            $detail = (array) $doc;
            $sp = $this->db->san_pham->findOne(['ma_san_pham' => $detail['ma_san_pham']]);
            if (!$sp && is_numeric($detail['ma_san_pham'] ?? null)) {
                $sp = $this->db->san_pham->findOne(['ma_san_pham' => (int)$detail['ma_san_pham']]);
            }
            if ($sp) {
                $detail['ten_san_pham'] = $sp['ten_san_pham'];
                $detail['link_hinh_anh'] = $sp['link_hinh_anh'] ?? $sp['hinh_anh'] ?? '';
                if (isset($sp['ma_thuong_hieu'])) {
                    $th = $this->db->thuong_hieu->findOne(['ma_thuong_hieu' => $sp['ma_thuong_hieu']]);
                    $detail['thuong_hieu'] = $th ? $th['ten_thuong_hieu'] : '';
                } else {
                    $detail['thuong_hieu'] = '';
                }
            }
            $detailsByOrder[$detail['ma_hoa_don']][] = $detail;
        }

        foreach ($orders as &$order) {
            $order['items'] = $detailsByOrder[$order['ma_hoa_don']] ?? [];
        }

        return $orders;
    }

    public function getCartItems(int $maKh): array {
        $options = ['sort' => ['updated_at' => -1, 'id' => -1]];
        $cursor = $this->db->gio_hang->find(['ma_kh' => $maKh], $options);
        $items = [];

        foreach ($cursor as $doc) {
            $gh = (array) $doc;
            $sp = $this->db->san_pham->findOne(['ma_san_pham' => $gh['ma_san_pham']]);
            if (!$sp && is_numeric($gh['ma_san_pham'] ?? null)) {
                $sp = $this->db->san_pham->findOne(['ma_san_pham' => (int)$gh['ma_san_pham']]);
            }
            if ($sp) {
                $gh['ten_san_pham'] = $sp['ten_san_pham'];
                $gh['gia_ban'] = $sp['gia_ban'];
                $gh['link_hinh_anh'] = $sp['link_hinh_anh'] ?? $sp['hinh_anh'] ?? '';
            }

            if (isset($gh['updated_at']) && $gh['updated_at'] instanceof \MongoDB\BSON\UTCDateTime) {
                $gh['updated_at'] = $gh['updated_at']->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
            }

            $items[] = $gh;
        }
        return $items;
    }

    public function getLoaiDaOptions(): array {
        $options = ['sort' => ['ma_loai_da' => 1]];
        $cursor = $this->db->loai_da->find([], $options);
        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $doc['ten_loai_da'];
        }
        return $items;
    }

    private function layHoacTaoMaLoaiDa(string $tenLoaiDa): ?int {
        $ten = trim($tenLoaiDa);
        if ($ten === '') return null;

        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($ten) . '$', 'i');
        $loaiDa = $this->db->loai_da->findOne(['ten_loai_da' => $regex]);

        if ($loaiDa) {
            return (int)$loaiDa['ma_loai_da'];
        }

        $newId = $this->getNextNumericId('loai_da', 'ma_loai_da');
        $this->db->loai_da->insertOne([
            'ma_loai_da' => $newId,
            'ten_loai_da' => $ten
        ]);
        return $newId;
    }

    private function parseLoaiDaId(?string $tinhTrangDacBiet): ?int {
        $text = (string)($tinhTrangDacBiet ?? '');
        if ($text === '') return null;

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
        if (!$kh) return null;

        $maLoaiDa = $this->parseLoaiDaId($kh['tinh_trang_dac_biet'] ?? null);
        $tenLoaiDa = null;
        if ($maLoaiDa) {
            $ld = $this->db->loai_da->findOne(['ma_loai_da' => $maLoaiDa]);
            $tenLoaiDa = $ld ? $ld['ten_loai_da'] : null;
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

        $updateData = [
            'van_de_da' => ($vanDeDaText !== '' ? $vanDeDaText : null),
            'ngan_sach' => $nganSach,
            'tinh_trang_dac_biet' => $tinhTrangDacBietMoi,
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];
        if ($hoTen !== '') $updateData['ho_ten'] = $hoTen;

        try {
            $this->db->khach_hang->updateOne(['ma_kh' => $kh['ma_kh']], ['$set' => $updateData]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function saveThongTinKhachHang(string $email, array $data): bool {
        $hoTen = trim((string)($data['ho_ten'] ?? ''));
        $kh = $this->ensureKhachHangByEmail($hoTen, $email);
        if (!$kh) return false;

        $soDienThoai = trim((string)($data['so_dien_thoai'] ?? ''));
        $gioiTinh = trim((string)($data['gioi_tinh'] ?? ''));
        $diaChi = trim((string)($data['dia_chi'] ?? ''));
        $namSinh = $data['nam_sinh'] ?? null;

        $updateData = [
            'so_dien_thoai' => ($soDienThoai !== '' ? $soDienThoai : null),
            'gioi_tinh' => ($gioiTinh !== '' ? $gioiTinh : null),
            'nam_sinh' => $namSinh,
            'dia_chi' => ($diaChi !== '' ? $diaChi : null),
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];
        if ($hoTen !== '') $updateData['ho_ten'] = $hoTen;

        try {
            $this->db->khach_hang->updateOne(['ma_kh' => $kh['ma_kh']], ['$set' => $updateData]);

            if ($hoTen !== '') {
                $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
                $this->db->nguoidung->updateOne(['email' => $regex], ['$set' => ['ho_ten' => $hoTen]]);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function doiMatKhau(int $nguoiDungId, string $matKhauHienTai, string $matKhauMoi): array {
        $nd = $this->db->nguoidung->findOne(['_id' => $nguoiDungId]);
        if (!$nd && is_numeric($nguoiDungId)) {
             $nd = $this->db->nguoidung->findOne(['id' => (int) $nguoiDungId]);
        }

        if (!$nd) {
            return ['ok' => false, 'message' => 'Không tìm thấy tài khoản.'];
        }

        if (!$this->xacThucMatKhau($matKhauHienTai, (string)$nd['mat_khau'])) {
            return ['ok' => false, 'message' => 'Mật khẩu hiện tại không đúng.'];
        }

        $hashMoi = password_hash($matKhauMoi, PASSWORD_BCRYPT);
        $this->db->nguoidung->updateOne(['_id' => $nd['_id']], ['$set' => ['mat_khau' => $hashMoi]]);

        return ['ok' => true, 'message' => 'Đổi mật khẩu thành công.'];
    }

    public function doiMatKhauByEmail(string $email, string $matKhauHienTai, string $matKhauMoi): array {
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
        $nd = $this->db->nguoidung->findOne(['email' => $regex]);

        if (!$nd || empty($nd['mat_khau'])) {
            return ['ok' => false, 'message' => 'Không tìm thấy tài khoản.'];
        }

        if (!$this->xacThucMatKhau($matKhauHienTai, (string)$nd['mat_khau'])) {
            return ['ok' => false, 'message' => 'Mật khẩu hiện tại không đúng.'];
        }

        $hashMoi = password_hash($matKhauMoi, PASSWORD_BCRYPT);
        $this->db->nguoidung->updateOne(['_id' => $nd['_id']], ['$set' => ['mat_khau' => $hashMoi]]);

        return ['ok' => true, 'message' => 'Đổi mật khẩu thành công.'];
    }

    public function capNhatMatKhauTheoEmail(string $email, string $matKhauMoi): array {
        $email = trim($email);
        if ($email === '') {
            return ['ok' => false, 'message' => 'Không xác định được tài khoản.'];
        }

        $hashMoi = password_hash($matKhauMoi, PASSWORD_BCRYPT);
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
        $result = $this->db->nguoidung->updateOne(['email' => $regex], ['$set' => ['mat_khau' => $hashMoi]]);

        return $result->getModifiedCount() > 0
            ? ['ok' => true, 'message' => 'Đổi mật khẩu thành công.']
            : ['ok' => false, 'message' => 'Không thể đổi mật khẩu.'];
    }

    public function luuLichSuTimKiem(string $email, string $tuKhoa): bool {
        try {
            $email = trim($email);
            $tuKhoa = trim($tuKhoa);
            if ($email === '' || $tuKhoa === '') return false;

            $kh = $this->getKhachHangByEmail($email);
            if (!$kh) {
                $account = $this->getAccountOverviewByEmail($email);
                $hoTen = trim((string)($account['ho_ten'] ?? ''));
                if ($hoTen === '') $hoTen = strstr($email, '@', true) ?: $email;
                $kh = $this->ensureKhachHangByEmail($hoTen, $email);
            }

            if (!$kh || empty($kh['ma_kh'])) return false;

            $this->db->lich_su_tim_kiem->insertOne([
                'ma_kh' => $kh['ma_kh'],
                'tu_khoa' => $tuKhoa,
                'ngay_tim' => new \MongoDB\BSON\UTCDateTime()
            ]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getTuKhoaGanDay(string $email, int $limit = 3): array {
        try {
            $kh = $this->getKhachHangByEmail($email);
            if (!$kh) return [];

            $pipeline = [
                ['$match' => ['ma_kh' => $kh['ma_kh']]],
                ['$sort' => ['ngay_tim' => -1]],
                ['$group' => [
                    '_id' => '$tu_khoa',
                    'ngay_tim' => ['$max' => '$ngay_tim']
                ]],
                ['$sort' => ['ngay_tim' => -1]],
                ['$limit' => $limit]
            ];

            $cursor = $this->db->lich_su_tim_kiem->aggregate($pipeline);
            $items = [];
            foreach ($cursor as $doc) {
                $items[] = (string) $doc['_id'];
            }
            return $items;
        } catch (Throwable $e) {
            return [];
        }
    }
}
