<?php
// backend/app/models/ThongKe.php

class ThongKe {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    private function normalizeOrderStatus($status): string {
        $raw = trim((string)($status ?? ''));
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
        $normalized = str_replace(['_', '-'], ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?: $normalized;
        $map = [
            'chờ xử lý' => 'pending',
            'cho xu ly' => 'pending',
            'chờ thanh toán' => 'pending',
            'cho thanh toan' => 'pending',
            'moi' => 'pending',
            'pending' => 'pending',
            'đã xác nhận' => 'confirmed',
            'da xac nhan' => 'confirmed',
            'confirmed' => 'confirmed',
            'đang giao' => 'shipping',
            'dang giao' => 'shipping',
            'shipping' => 'shipping',
            'hoàn thành' => 'completed',
            'hoan thanh' => 'completed',
            'completed' => 'completed',
            'đã hủy' => 'cancelled',
            'da huy' => 'cancelled',
            'huy' => 'cancelled',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
        ];
        return $map[$normalized] ?? $normalized;
    }

    private function normalizePaymentStatus($status): string {
        $raw = trim((string)($status ?? ''));
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
        $normalized = str_replace(['_', '-'], ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?: $normalized;
        return in_array($normalized, ['da thanh toan', 'đã thanh toán', 'paid', 'thanh cong', 'completed'], true)
            ? 'paid'
            : 'unpaid';
    }

    private function isRevenueOrder(array $order): bool {
        if ($this->normalizeOrderStatus($order['trang_thai'] ?? '') !== 'completed') {
            return false;
        }
        $method = strtolower(trim((string)($order['hinh_thuc_thanh_toan'] ?? 'cod')));
        if ($method === 'bank_transfer_qr' || str_contains($method, 'qr') || str_contains($method, 'transfer')) {
            return $this->normalizePaymentStatus($order['status_thanh_toan'] ?? '') === 'paid';
        }
        return true;
    }

    public function getTongSanPham(): int {
        return $this->db->san_pham->countDocuments([]);
    }

    public function getTongNguoiDung(): int {
        // Trong MongoDB có thể ko có cột vai_tro, nhưng ta dùng logic tương đương
        return $this->db->nguoidung->countDocuments(['vai_tro' => 'khach_hang']);
    }

    public function getDoanhThu(): float {
        $total = 0.0;
        foreach ($this->db->hoa_don->find([]) as $doc) {
            $order = (array)$doc;
            if ($this->isRevenueOrder($order)) {
                $total += (float)($order['tong_tien'] ?? 0);
            }
        }
        return $total;
    }

    public function getDonChoXuLy(): int {
        $count = 0;
        foreach ($this->db->hoa_don->find([], ['projection' => ['trang_thai' => 1]]) as $doc) {
            if ($this->normalizeOrderStatus(((array)$doc)['trang_thai'] ?? '') === 'pending') {
                $count++;
            }
        }
        return $count;
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

