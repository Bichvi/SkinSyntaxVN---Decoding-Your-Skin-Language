<?php
// backend/app/models/ThongKe.php

class ThongKe {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getTongSanPham(): int {
        return $this->db->san_pham->countDocuments([]);
    }

    public function getTongNguoiDung(): int {
        // Trong MongoDB có thể ko có cột vai_tro, nhưng ta dùng logic tương đương
        return $this->db->nguoidung->countDocuments(['vai_tro' => 'khach_hang']);
    }

    public function getDoanhThu(): float {
        $pipeline = [
            ['$match' => ['trang_thai' => 'Hoàn thành']],
            ['$group' => [
                '_id' => null,
                'total' => ['$sum' => '$tong_tien']
            ]]
        ];
        $result = $this->db->hoa_don->aggregate($pipeline)->toArray();
        return !empty($result) ? (float)$result[0]['total'] : 0.0;
    }

    public function getDonChoXuLy(): int {
        return $this->db->hoa_don->countDocuments(['trang_thai' => 'Chờ xử lý']);
    }

    public function getSanPhamMoi(int $limit = 5): array {
        $limit = max(1, min(50, $limit));
        $options = [
            'sort' => ['ngay_tao' => -1, 'created_at' => -1, 'ma_san_pham' => -1],
            'limit' => $limit
        ];
        
        $cursor = $this->db->san_pham->find([], $options);
        $items = [];
        
        foreach ($cursor as $doc) {
            $sp = (array) $doc;
            $items[] = [
                'ma_san_pham' => $sp['ma_san_pham'],
                'ten_san_pham' => $sp['ten_san_pham'],
                'hinh_anh' => $sp['link_hinh_anh'] ?? $sp['hinh_anh'] ?? '',
                'trang_thai' => $sp['trang_thai'] ?? 'active'
            ];
        }
        
        return $items;
    }

    public function getNguoiDungMoi(int $limit = 5): array {
        $limit = max(1, min(50, $limit));
        $options = [
            'sort' => ['_id' => -1],
            'limit' => $limit
        ];

        $cursor = $this->db->nguoidung->find([], $options);
        $items = [];

        foreach ($cursor as $doc) {
            $nd = (array) $doc;
            
            $ngayDangKy = null;
            if (isset($nd['ngay_tao']) && $nd['ngay_tao'] instanceof \MongoDB\BSON\UTCDateTime) {
                $ngayDangKy = $nd['ngay_tao']->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
            } elseif (isset($nd['created_at']) && $nd['created_at'] instanceof \MongoDB\BSON\UTCDateTime) {
                $ngayDangKy = $nd['created_at']->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
            } else {
                // Thử join với bảng khách hàng xem có ngày ko
                $regex = new \MongoDB\BSON\Regex('^' . preg_quote($nd['email'] ?? '') . '$', 'i');
                $kh = $this->db->khach_hang->findOne(['email' => $regex]);
                if ($kh && isset($kh['created_at']) && $kh['created_at'] instanceof \MongoDB\BSON\UTCDateTime) {
                    $ngayDangKy = $kh['created_at']->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
                }
            }

            $items[] = [
                'id' => (string) $nd['_id'],
                'ho_ten' => $nd['ho_ten'] ?? '',
                'email' => $nd['email'] ?? '',
                'ngay_dang_ky' => $ngayDangKy,
                'vai_tro' => $nd['vai_tro'] ?? $nd['role'] ?? $nd['quyen'] ?? 'khach_hang'
            ];
        }

        return $items;
    }
}