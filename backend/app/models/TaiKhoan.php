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

        $details = [];
        foreach ($this->db->chi_tiet_hoa_don->find(['ma_hoa_don' => ['$in' => $orderIds]], ['sort' => ['ma_hoa_don' => -1, 'id' => 1]]) as $doc) {
            $details[] = (array) $doc;
        }

        // Tra sản phẩm/thương hiệu theo lô ($in) thay vì 2-3 findOne mỗi dòng (mỗi lượt Atlas ~50ms).
        // Giữ đúng ngữ nghĩa cũ: khớp đúng kiểu trước, chuỗi số thì thử thêm dạng int; trùng mã thì lấy bản đầu như findOne.
        $productFields = ['ma_san_pham' => 1, 'ten_san_pham' => 1, 'link_hinh_anh' => 1, 'hinh_anh' => 1, 'ma_thuong_hieu' => 1];
        $products = $this->findFirstByValues('san_pham', 'ma_san_pham', array_map(fn($d) => $d['ma_san_pham'] ?? null, $details), $productFields);
        $numericRetry = [];
        foreach ($details as $detail) {
            $raw = $detail['ma_san_pham'] ?? null;
            if (!isset($products[$this->lookupKey($raw)]) && is_numeric($raw)) {
                $numericRetry[] = (int)$raw;
            }
        }
        $products += $this->findFirstByValues('san_pham', 'ma_san_pham', $numericRetry, $productFields);
        $brandIds = [];
        foreach ($products as $sp) {
            if (isset($sp['ma_thuong_hieu'])) $brandIds[] = $sp['ma_thuong_hieu'];
        }
        $brands = $this->findFirstByValues('thuong_hieu', 'ma_thuong_hieu', $brandIds, ['ma_thuong_hieu' => 1, 'ten_thuong_hieu' => 1]);

        $detailsByOrder = [];
        foreach ($details as $detail) {
            $raw = $detail['ma_san_pham'] ?? null;
            $sp = $products[$this->lookupKey($raw)] ?? (is_numeric($raw) ? ($products[$this->lookupKey((int)$raw)] ?? null) : null);
            if ($sp) {
                $detail['ten_san_pham'] = $sp['ten_san_pham'] ?? null;
                $detail['link_hinh_anh'] = $sp['link_hinh_anh'] ?? $sp['hinh_anh'] ?? '';
                if (isset($sp['ma_thuong_hieu'])) {
                    $th = $brands[$this->lookupKey($sp['ma_thuong_hieu'])] ?? null;
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

    /** Khoá tra cứu phân biệt kiểu như truy vấn Mongo khớp đúng kiểu: "93" (chuỗi) khác 93 (số). */
    private function lookupKey($value): string {
        if (is_int($value)) return 'i:' . $value;
        if (is_float($value)) return 'f:' . $value;
        if (is_string($value)) return 's:' . $value;
        return 'x:' . json_encode($value);
    }

    /**
     * Một truy vấn $in cho nhiều giá trị; mỗi giá trị lấy bản ghi đầu tiên theo thứ tự tự nhiên (giống findOne).
     * Trả [lookupKey(giá trị) => document dạng mảng].
     */
    private function findFirstByValues(string $collection, string $field, array $values, array $projection): array {
        $unique = [];
        foreach ($values as $value) {
            if ($value === null || is_bool($value)) continue;
            $unique[$this->lookupKey($value)] = $value;
        }
        if (!$unique) return [];
        $found = [];
        foreach ($this->db->{$collection}->find([$field => ['$in' => array_values($unique)]], ['projection' => $projection]) as $doc) {
            $row = (array)$doc;
            $key = $this->lookupKey($row[$field] ?? null);
            if (!isset($found[$key])) $found[$key] = $row;
        }
        return $found;
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

    private function asArray($value): array {
        if (is_array($value)) return $value;
        if (is_object($value)) return (array)$value;
        return [];
    }

    private function normalizeQuickIssues($issues): array {
        if (!is_array($issues)) {
            $issues = explode(',', (string)$issues);
        }

        $out = [];
        foreach ($issues as $issue) {
            $issue = trim((string)$issue);
            if ($issue !== '' && !in_array($issue, $out, true)) {
                $out[] = $issue;
            }
        }
        return $out;
    }

    private function quickStateFromProfile(array $profile): array {
        $state = $this->asArray($profile['current_state'] ?? null);
        $issues = $state['van_de_da'] ?? ($profile['van_de_da'] ?? []);

        return [
            'muc_do_nhay_cam' => trim((string)($state['muc_do_nhay_cam'] ?? ($profile['muc_do_nhay_cam'] ?? ''))),
            'van_de_da' => $this->normalizeQuickIssues($issues),
            'muc_tieu_cham_soc' => trim((string)($state['muc_tieu_cham_soc'] ?? ($profile['muc_tieu_cham_soc'] ?? ''))),
            'ngan_sach' => (int)($state['ngan_sach'] ?? ($profile['ngan_sach'] ?? 0)),
        ];
    }

    private function quickStateFingerprint(array $state): string {
        $issues = $this->normalizeQuickIssues($state['van_de_da'] ?? []);
        sort($issues, SORT_STRING);
        $normalized = [
            'muc_do_nhay_cam' => trim((string)($state['muc_do_nhay_cam'] ?? '')),
            'van_de_da' => $issues,
            'muc_tieu_cham_soc' => trim((string)($state['muc_tieu_cham_soc'] ?? '')),
            'ngan_sach' => (int)($state['ngan_sach'] ?? 0),
        ];
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function displayMongoDate($value): string {
        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i');
        }
        return trim((string)$value);
    }

    public function getSkinProfileByEmail(string $email): ?array {
        $kh = $this->getKhachHangByEmail($email);
        if (!$kh) return null;

        $base = $this->asArray($kh['skin_base_profile'] ?? null);
        $current = $this->asArray($kh['skin_current_state'] ?? null);
        $base += [
            'gioi_tinh' => $kh['gioi_tinh'] ?? null,
            'nam_sinh' => $kh['nam_sinh'] ?? null,
            'loai_da' => null,
            'tieu_chi_uu_tien' => $kh['tieu_chi_uu_tien'] ?? null,
            'thanh_phan_tranh' => $kh['thanh_phan_tranh'] ?? null,
            'kinh_nghiem_skincare' => $kh['kinh_nghiem_skincare'] ?? null,
            'so_buoc_skincare' => $kh['so_buoc_skincare'] ?? null,
            'ngan_sach' => $kh['ngan_sach'] ?? null,
            'tinh_trang_dac_biet' => $kh['tinh_trang_dac_biet'] ?? null,
        ];

        $maLoaiDa = $this->parseLoaiDaId($base['tinh_trang_dac_biet'] ?? null);
        $tenLoaiDa = trim((string)($base['loai_da'] ?? ''));
        if ($tenLoaiDa === '' && $maLoaiDa) {
            $ld = $this->db->loai_da->findOne(['ma_loai_da' => $maLoaiDa]);
            $tenLoaiDa = $ld ? (string)$ld['ten_loai_da'] : '';
        }

        $profile = [
            'profile_id' => 'self-' . (string)($kh['ma_kh'] ?? ''),
            'display_name' => 'Hồ sơ của tôi',
            'profile_type' => 'self',
            'is_primary' => true,
            'loai_da' => $tenLoaiDa !== '' ? $tenLoaiDa : null,
            'van_de_da' => $current['van_de_da'] ?? ($kh['van_de_da'] ?? null),
            'muc_do_nhay_cam' => $current['muc_do_nhay_cam'] ?? ($kh['muc_do_nhay_cam'] ?? null),
            'muc_tieu_cham_soc' => $current['muc_tieu_cham_soc'] ?? ($kh['muc_tieu_cham_soc'] ?? null),
            'ngan_sach' => $current['ngan_sach'] ?? ($kh['ngan_sach'] ?? null),
            'ma_kh' => $kh['ma_kh'] ?? null,
            'base_profile' => $base,
            'current_state' => [
                'muc_do_nhay_cam' => $current['muc_do_nhay_cam'] ?? ($kh['muc_do_nhay_cam'] ?? null),
                'van_de_da' => $current['van_de_da'] ?? ($kh['van_de_da'] ?? null),
                'muc_tieu_cham_soc' => $current['muc_tieu_cham_soc'] ?? ($kh['muc_tieu_cham_soc'] ?? null),
                'ngan_sach' => $current['ngan_sach'] ?? ($kh['ngan_sach'] ?? null),
                'updated_at' => $current['updated_at'] ?? ($kh['skin_profile_updated_at'] ?? null),
            ],
            'updated_at' => $current['updated_at'] ?? ($kh['skin_profile_updated_at'] ?? ($kh['updated_at'] ?? null)),
        ];

        $profile['updated_at_display'] = $this->displayMongoDate($profile['updated_at'] ?? null);
        return $profile;
    }

    public function getSkinProfilesByEmail(string $email): array {
        $email = trim($email);
        $primary = $this->getSkinProfileByEmail($email);
        if (!$primary) return [];

        $profiles = [$primary];
        try {
            $ownerEmail = strtolower($email);
            foreach ($this->db->skin_profiles->find(
                ['owner_email' => $ownerEmail],
                ['sort' => ['created_at' => 1, 'display_name' => 1]]
            ) as $doc) {
                $profile = (array)$doc;
                $profile['profile_id'] = (string)($profile['profile_id'] ?? '');
                if ($profile['profile_id'] === '') continue;
                $profile['profile_type'] = (($profile['profile_type'] ?? 'other') === 'self') ? 'self' : 'other';
                $profile['is_primary'] = false;
                $profile['base_profile'] = $this->asArray($profile['base_profile'] ?? null);
                $profile['current_state'] = $this->asArray($profile['current_state'] ?? null);
                $profile['loai_da'] = $profile['base_profile']['loai_da'] ?? null;
                $profile['van_de_da'] = $profile['current_state']['van_de_da'] ?? null;
                $profile['muc_do_nhay_cam'] = $profile['current_state']['muc_do_nhay_cam'] ?? null;
                $profile['muc_tieu_cham_soc'] = $profile['current_state']['muc_tieu_cham_soc'] ?? null;
                $profile['ngan_sach'] = $profile['current_state']['ngan_sach'] ?? null;
                $profile['updated_at_display'] = $this->displayMongoDate($profile['updated_at'] ?? null);
                $profiles[] = $profile;
            }
        } catch (Throwable $e) {
            // The legacy primary profile remains usable when the optional collection is unavailable.
        }
        return $profiles;
    }

    public function getSelectedSkinProfileByEmail(string $email): ?array {
        $profiles = $this->getSkinProfilesByEmail($email);
        if (!$profiles) return null;

        $selectedId = trim((string)($_SESSION['selected_skin_profile_id'] ?? ''));
        $selectedOwner = strtolower(trim((string)($_SESSION['selected_skin_profile_owner'] ?? '')));
        if ($selectedId !== '' && $selectedOwner === strtolower(trim($email))) {
            foreach ($profiles as $profile) {
                if ((string)($profile['profile_id'] ?? '') === $selectedId) {
                    return $profile;
                }
            }
        }

        return $profiles[0];
    }

    public function selectSkinProfileByEmail(string $email, string $profileId): bool {
        $profileId = trim($profileId);
        foreach ($this->getSkinProfilesByEmail($email) as $profile) {
            if ((string)($profile['profile_id'] ?? '') === $profileId) {
                $_SESSION['selected_skin_profile_id'] = $profileId;
                $_SESSION['selected_skin_profile_owner'] = strtolower(trim($email));
                return true;
            }
        }
        return false;
    }

    public function createSkinProfileByEmail(string $hoTen, string $email, string $displayName): ?array {
        $kh = $this->ensureKhachHangByEmail($hoTen, $email);
        if (!$kh || empty($kh['ma_kh'])) return null;
        $displayName = trim($displayName);
        if ($displayName === '') return null;

        try {
            $profile = [
                'profile_id' => 'sp_' . bin2hex(random_bytes(12)),
                'owner_ma_kh' => (int)$kh['ma_kh'],
                'owner_email' => strtolower(trim($email)),
                'display_name' => $displayName,
                'profile_type' => 'other',
                'base_profile' => [],
                'current_state' => [],
                'created_at' => new \MongoDB\BSON\UTCDateTime(),
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ];
            $this->db->skin_profiles->insertOne($profile);
            return $profile;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function getCustomerForSelectedProfileByEmail(string $email): ?array {
        $kh = $this->getKhachHangByEmail($email);
        $selected = $this->getSelectedSkinProfileByEmail($email);
        if (!$kh || !$selected || !empty($selected['is_primary'])) return $kh;

        // A profile for another person must not inherit the account owner's skin data.
        $base = $this->asArray($selected['base_profile'] ?? null);
        $current = $this->asArray($selected['current_state'] ?? null);
        return [
            'ma_kh' => $kh['ma_kh'] ?? null,
            'ho_ten' => (string)($selected['display_name'] ?? 'Hồ sơ khác'),
            'email' => $email,
            'gioi_tinh' => $base['gioi_tinh'] ?? null,
            'nam_sinh' => $base['nam_sinh'] ?? null,
            'tinh_trang_dac_biet' => $base['tinh_trang_dac_biet'] ?? null,
            'skin_type' => $base['loai_da'] ?? null,
            'loai_da' => $base['loai_da'] ?? null,
            'van_de_da' => $current['van_de_da'] ?? null,
            'muc_do_nhay_cam' => $current['muc_do_nhay_cam'] ?? null,
            'muc_tieu_cham_soc' => $current['muc_tieu_cham_soc'] ?? null,
            'ngan_sach' => $current['ngan_sach'] ?? ($base['ngan_sach'] ?? null),
            'tieu_chi_uu_tien' => $base['tieu_chi_uu_tien'] ?? null,
            'thanh_phan_tranh' => $base['thanh_phan_tranh'] ?? null,
            'kinh_nghiem_skincare' => $base['kinh_nghiem_skincare'] ?? null,
            'so_buoc_skincare' => $base['so_buoc_skincare'] ?? null,
            'skin_profile_id' => $selected['profile_id'] ?? null,
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

    public function saveQuickSkinProfileResultByEmail(string $hoTen, string $email, array $payload, ?string $profileId = null): array {
        $email = trim($email);
        if ($email === '') return ['ok' => false, 'changed' => false, 'message' => 'missing_email'];

        $sensitivity = trim((string)($payload['muc_do_nhay_cam'] ?? ''));
        $goal = trim((string)($payload['muc_tieu_cham_soc'] ?? ''));
        $issues = $this->normalizeQuickIssues($payload['van_de_da'] ?? []);
        $budget = isset($payload['ngan_sach']) ? (int)$payload['ngan_sach'] : 0;
        $requestId = trim((string)($payload['request_id'] ?? ''));
        $nextState = [
            'muc_do_nhay_cam' => $sensitivity !== '' ? $sensitivity : null,
            'van_de_da' => $issues,
            'muc_tieu_cham_soc' => $goal !== '' ? $goal : null,
            'ngan_sach' => $budget > 0 ? $budget : null,
        ];

        $kh = $this->ensureKhachHangByEmail($hoTen, $email);
        if (!$kh || empty($kh['ma_kh'])) return ['ok' => false, 'changed' => false, 'message' => 'missing_customer'];

        $selected = $this->getSelectedSkinProfileByEmail($email);
        $profileId = trim((string)($profileId ?? ($selected['profile_id'] ?? '')));
        $primaryProfileId = 'self-' . (string)$kh['ma_kh'];
        if ($profileId === '') $profileId = $primaryProfileId;

        $isPrimary = $profileId === $primaryProfileId;
        $targetProfile = $isPrimary ? $this->getSkinProfileByEmail($email) : null;
        if (!$isPrimary) {
            foreach ($this->getSkinProfilesByEmail($email) as $profile) {
                if ((string)($profile['profile_id'] ?? '') === $profileId && empty($profile['is_primary'])) {
                    $targetProfile = $profile;
                    break;
                }
            }
        }
        if (!$targetProfile) return ['ok' => false, 'changed' => false, 'message' => 'profile_not_found'];

        $fingerprint = $this->quickStateFingerprint($nextState);
        try {
            $historyFilter = [
                'owner_email' => strtolower($email),
                'profile_id' => $profileId,
                'source' => 'quick_survey',
            ];
            if ($requestId !== '') {
                $duplicate = $this->db->skin_profile_history->findOne($historyFilter + ['request_id' => $requestId]);
                if ($duplicate) {
                    return ['ok' => true, 'changed' => false, 'duplicate' => true];
                }
            }

            $currentState = $this->quickStateFromProfile($targetProfile);
            if ($this->quickStateFingerprint($currentState) === $fingerprint) {
                return ['ok' => true, 'changed' => false, 'duplicate' => false];
            }

            $now = new \MongoDB\BSON\UTCDateTime();
            $storedState = [
                'muc_do_nhay_cam' => $nextState['muc_do_nhay_cam'],
                'van_de_da' => $issues ? implode(', ', $issues) : null,
                'muc_tieu_cham_soc' => $nextState['muc_tieu_cham_soc'],
                'ngan_sach' => $nextState['ngan_sach'],
                'updated_at' => $now,
            ];

            if ($isPrimary) {
                $updateData = [
                    'muc_do_nhay_cam' => $storedState['muc_do_nhay_cam'],
                    'van_de_da' => $storedState['van_de_da'],
                    'muc_tieu_cham_soc' => $storedState['muc_tieu_cham_soc'],
                    'ngan_sach' => $storedState['ngan_sach'],
                    'skin_current_state' => $storedState,
                    'skin_profile_updated_at' => $now,
                    'updated_at' => $now,
                ];
                if ($hoTen !== '') $updateData['ho_ten'] = $hoTen;
                $result = $this->db->khach_hang->updateOne(['ma_kh' => $kh['ma_kh']], ['$set' => $updateData]);
                if ($result->getMatchedCount() < 1) {
                    return ['ok' => false, 'changed' => false, 'message' => 'update_failed'];
                }
            } else {
                $result = $this->db->skin_profiles->updateOne(
                    ['profile_id' => $profileId, 'owner_email' => strtolower($email)],
                    ['$set' => ['current_state' => $storedState, 'updated_at' => $now]]
                );
                if ($result->getMatchedCount() < 1) {
                    return ['ok' => false, 'changed' => false, 'message' => 'profile_update_failed'];
                }
            }

            $lastSnapshot = $this->db->skin_profile_history->findOne(
                ['owner_email' => strtolower($email), 'profile_id' => $profileId],
                ['sort' => ['version' => -1]]
            );
            $version = ((int)($lastSnapshot['version'] ?? 0)) + 1;
            $history = [
                'owner_email' => strtolower($email),
                'email' => $email,
                'ma_kh' => (int)$kh['ma_kh'],
                'profile_id' => $profileId,
                'profile_type' => $isPrimary ? 'self' : 'other',
                'profile_name' => (string)($targetProfile['display_name'] ?? ($isPrimary ? 'Hồ sơ của tôi' : 'Hồ sơ khác')),
                'skin_type' => $targetProfile['loai_da'] ?? null,
                'concerns' => $issues,
                'sensitivity' => $sensitivity !== '' ? $sensitivity : null,
                'goal' => $goal !== '' ? $goal : null,
                'budget' => $budget > 0 ? $budget : null,
                'fingerprint' => $fingerprint,
                'request_id' => $requestId !== '' ? $requestId : null,
                'source' => 'quick_survey',
                'survey_type' => 'quick',
                'version' => $version,
                'updated_at' => $now,
            ];
            if ($requestId !== '') {
                // A deterministic _id makes concurrent retries idempotent without a destructive migration.
                $history['_id'] = hash('sha256', 'skinsyntax.quick|' . strtolower($email) . '|' . $profileId . '|' . $requestId);
            }
            $this->db->skin_profile_history->insertOne($history);

            return ['ok' => true, 'changed' => true, 'duplicate' => false];
        } catch (Throwable $e) {
            if ($requestId !== '' && stripos((string)$e->getMessage(), 'duplicate') !== false) {
                return ['ok' => true, 'changed' => false, 'duplicate' => true];
            }
            error_log('[SKIN_PROFILE] quick update failed: ' . get_class($e));
            return ['ok' => false, 'changed' => false, 'message' => 'storage_failed'];
        }
    }

    public function saveQuickSkinProfileByEmail(string $hoTen, string $email, array $payload, ?string $profileId = null): bool {
        return !empty($this->saveQuickSkinProfileResultByEmail($hoTen, $email, $payload, $profileId)['ok']);
    }

    public function saveSkinTypeOnlyByEmail(string $hoTen, string $email, string $loaiDa): bool {
        $kh = $this->ensureKhachHangByEmail($hoTen, $email);
        if (!$kh) return false;

        $maLoaiDa = $this->layHoacTaoMaLoaiDa($loaiDa);
        if (!$maLoaiDa) return false;

        $tinhTrangDacBietMoi = $this->mergeTinhTrangDacBiet($kh['tinh_trang_dac_biet'] ?? null, $maLoaiDa);

        $updateData = [
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

    public function getLatestQuickSkinProfileUpdate(string $email, ?string $profileId = null): ?array {
        $email = trim($email);
        if ($email === '') return null;

        try {
            $profileId = trim((string)($profileId ?? ''));
            $filter = ['email' => $email, 'source' => 'quick_survey'];
            if ($profileId !== '') {
                $filter['profile_id'] = $profileId;
            }
            $snapshot = $this->db->skin_profile_history->findOne(
                $filter,
                ['sort' => ['updated_at' => -1, 'version' => -1]]
            );
            if (!$snapshot) return null;

            $snapshot = (array)$snapshot;
            if (($snapshot['updated_at'] ?? null) instanceof \MongoDB\BSON\UTCDateTime) {
                $snapshot['updated_at_display'] = $snapshot['updated_at']
                    ->toDateTime()
                    ->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))
                    ->format('d/m/Y H:i');
            }

            return $snapshot;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function getQuickSkinProfileHistory(string $email, ?string $profileId = null, int $limit = 8): array {
        $email = trim($email);
        if ($email === '') return [];
        $limit = max(1, min(20, $limit));
        try {
            $filter = ['owner_email' => strtolower($email), 'source' => 'quick_survey'];
            if (trim((string)$profileId) !== '') {
                $filter['profile_id'] = trim((string)$profileId);
            }
            $items = [];
            foreach ($this->db->skin_profile_history->find($filter, ['sort' => ['updated_at' => -1, 'version' => -1], 'limit' => $limit]) as $doc) {
                $row = (array)$doc;
                $row['updated_at_display'] = $this->displayMongoDate($row['updated_at'] ?? null);
                $items[] = $row;
            }
            return $items;
        } catch (Throwable $e) {
            return [];
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
