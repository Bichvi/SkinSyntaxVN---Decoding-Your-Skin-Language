<?php
// backend/app/models/Voucher.php

class Voucher {
    private $db;
    private ?string $lastErrorMessage = null;

    public function __construct($db) {
        $this->db = $db;
        // MongoDB không cần CREATE hay ALTER TABLE để khai báo cấu trúc bảng
    }

    public function getLastErrorMessage(): ?string {
        return $this->lastErrorMessage;
    }

    private function getNextNumericId(string $collection, string $column): int {
        $lastDoc = $this->db->{$collection}->findOne([], ['sort' => [$column => -1]]);
        return $lastDoc ? (int)$lastDoc[$column] + 1 : 1;
    }

    private function normalizeCode(string $code): string {
        $code = preg_replace('/\s+/u', '', trim($code));
        return strtoupper((string)$code);
    }

    private function normalizeDateTime(?string $value): ?string {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;

        $timestamp = strtotime($value);
        if ($timestamp === false) return null;

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeVoucherRow($doc): array {
        if (!$doc) return [];
        $row = (array) $doc;

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

        // Chuyển BSON Date thành chuỗi chuẩn để validate PHP
        foreach (['ngay_bat_dau', 'ngay_ket_thuc', 'created_at', 'updated_at'] as $dateField) {
            if (isset($row[$dateField]) && $row[$dateField] instanceof \MongoDB\BSON\UTCDateTime) {
                $row[$dateField] = $row[$dateField]->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
            }
        }

        return $row;
    }

    public function listVouchers(string $keyword = ''): array {
        $filter = [];
        $keyword = trim($keyword);
        
        if ($keyword !== '') {
            $parts = preg_split('/\s+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [$keyword];
            $andClauses = [];
            foreach ($parts as $part) {
                $regex = new \MongoDB\BSON\Regex(preg_quote($part), 'i');
                $andClauses[] = [
                    '$or' => [
                        ['ma_code' => $regex],
                        ['ten_voucher' => $regex],
                        ['mo_ta' => $regex]
                    ]
                ];
            }
            $filter['$and'] = $andClauses;
        }

        $options = [
            'sort' => ['created_at' => -1, 'ma_voucher' => -1]
        ];

        $cursor = $this->db->voucher->find($filter, $options);
        $items = [];
        foreach ($cursor as $doc) {
            $items[] = $this->normalizeVoucherRow($doc);
        }
        return $items;
    }

    public function getVoucherById(int $id): ?array {
        $doc = $this->db->voucher->findOne(['ma_voucher' => $id]);
        return $doc ? $this->normalizeVoucherRow($doc) : null;
    }

    public function getVoucherByCode(string $code): ?array {
        $normalizedCode = $this->normalizeCode($code);
        if ($normalizedCode === '') return null;

        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($normalizedCode) . '$', 'i');
        $doc = $this->db->voucher->findOne(['ma_code' => $regex]);
        return $doc ? $this->normalizeVoucherRow($doc) : null;
    }

    private function codeExists(string $code, ?int $excludeId = null): bool {
        $normalizedCode = $this->normalizeCode($code);
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($normalizedCode) . '$', 'i');
        
        $filter = ['ma_code' => $regex];
        if ($excludeId !== null && $excludeId > 0) {
            $filter['ma_voucher'] = ['$ne' => $excludeId];
        }

        return $this->db->voucher->countDocuments($filter) > 0;
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
            'ngay_bat_dau' => $startAt ? new \MongoDB\BSON\UTCDateTime(strtotime($startAt) * 1000) : null,
            'ngay_ket_thuc' => $endAt ? new \MongoDB\BSON\UTCDateTime(strtotime($endAt) * 1000) : null,
            'trang_thai' => $status,
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        try {
            if ($id !== null && $id > 0) {
                // Update
                $result = $this->db->voucher->updateOne(
                    ['ma_voucher' => $id],
                    ['$set' => $payload]
                );
                return true;
            }

            // Insert
            $payload['ma_voucher'] = $this->getNextNumericId('voucher', 'ma_voucher');
            $payload['so_luong_da_dung'] = 0;
            $payload['created_at'] = new \MongoDB\BSON\UTCDateTime();

            $this->db->voucher->insertOne($payload);
            return true;

        } catch (Throwable $e) {
            $this->lastErrorMessage = 'Không thể lưu voucher: ' . $e->getMessage();
            return false;
        }
    }

    public function deleteVoucher(int $id): bool {
        $this->lastErrorMessage = null;
        if ($id <= 0) {
            $this->lastErrorMessage = 'Voucher không hợp lệ.';
            return false;
        }

        try {
            $result = $this->db->voucher->deleteOne(['ma_voucher' => $id]);
            return $result->getDeletedCount() > 0;
        } catch (Throwable $e) {
            $this->lastErrorMessage = 'Không thể xóa voucher.';
            return false;
        }
    }

    public function validateForCheckout(string $code, int $subtotal): array {
        $normalizedCode = $this->normalizeCode($code);
        if ($normalizedCode === '') {
            return ['ok' => false, 'message' => 'Vui lòng nhập mã giảm giá.'];
        }

        $voucher = $this->getVoucherByCode($normalizedCode);
        if (!$voucher) {
            return ['ok' => false, 'message' => 'Mã giảm giá không tồn tại.'];
        }

        if (($voucher['trang_thai'] ?? 'inactive') !== 'active') {
            return ['ok' => false, 'message' => 'Mã giảm giá hiện không hoạt động.'];
        }

        $now = time();
        if (!empty($voucher['ngay_bat_dau']) && strtotime((string)$voucher['ngay_bat_dau']) > $now) {
            return ['ok' => false, 'message' => 'Mã giảm giá chưa đến thời gian áp dụng.'];
        }

        if (!empty($voucher['ngay_ket_thuc']) && strtotime((string)$voucher['ngay_ket_thuc']) < $now) {
            return ['ok' => false, 'message' => 'Mã giảm giá đã hết hạn.'];
        }

        if ($subtotal < (int)($voucher['gia_tri_don_toi_thieu'] ?? 0)) {
            return ['ok' => false, 'message' => 'Đơn hàng chưa đạt giá trị tối thiểu để dùng mã này.'];
        }

        $limit = $voucher['so_luong'];
        if ($limit !== null && (int)($voucher['so_luong_da_dung'] ?? 0) >= $limit) {
            return ['ok' => false, 'message' => 'Mã giảm giá đã hết lượt sử dụng.'];
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
            return ['ok' => false, 'message' => 'Mã giảm giá không hợp lệ cho đơn hàng hiện tại.'];
        }

        return [
            'ok' => true,
            'message' => 'Áp dụng mã giảm giá thành công.',
            'voucher' => $voucher,
            'discount' => $discount,
        ];
    }

    public function consumeVoucher(int $voucherId): bool {
        if ($voucherId <= 0) return true;

        $filter = [
            'ma_voucher' => $voucherId,
            '$or' => [
                ['so_luong' => null],
                ['so_luong' => ['$exists' => false]],
                ['$expr' => ['$lt' => ['$so_luong_da_dung', '$so_luong']]]
            ]
        ];

        $update = [
            '$inc' => ['so_luong_da_dung' => 1],
            '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()]
        ];

        try {
            $result = $this->db->voucher->updateOne($filter, $update);
            return $result->getModifiedCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}