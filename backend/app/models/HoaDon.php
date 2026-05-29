<?php
// backend/app/models/HoaDon.php

class HoaDon {
    private $db;
    private string $lastStockError = '';
    private const POINT_VALUE_VND = 1000;
    private const VIP_THRESHOLD = 500;
    private const DIAMOND_THRESHOLD = 1500;

    public function __construct($db) {
        $this->db = $db;
        // MongoDB không cần CREATE hay ALTER TABLE để thêm cột mới
    }

    // Hàm tự động tăng ID (Giả lập Auto-increment của SQL)
    private function getNextNumericId(string $collection, string $column): int {
        $lastDoc = $this->db->{$collection}->findOne([], ['sort' => [$column => -1]]);
        return $lastDoc ? (int)$lastDoc[$column] + 1 : 1;
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

    private function recordAdminOrderNotification(int $orderId, string $type, array $order = []): void {
        if ($orderId <= 0) return;
        $labels = [
            'new_cod' => 'Đơn hàng mới COD',
            'new_qr' => 'Đơn hàng mới QR/chuyển khoản',
            'confirmed' => 'Đơn hàng đã xác nhận',
            'shipping' => 'Đơn hàng đang giao',
            'completed' => 'Đơn hàng hoàn thành',
            'cancelled' => 'Đơn hàng đã hủy',
        ];
        $now = new \MongoDB\BSON\UTCDateTime();
        $title = $labels[$type] ?? 'Cập nhật đơn hàng';
        try {
            $this->db->thong_bao->insertOne([
                'ma_thong_bao' => $this->getNextNumericId('thong_bao', 'ma_thong_bao'),
                'loai' => $type,
                'tieu_de' => $title,
                'noi_dung' => $title . ' #' . $orderId,
                'ma_hoa_don' => $orderId,
                'tong_tien' => (int)($order['tong_tien'] ?? 0),
                'trang_thai' => (string)($order['trang_thai'] ?? ''),
                'hinh_thuc_thanh_toan' => (string)($order['hinh_thuc_thanh_toan'] ?? ''),
                'da_doc' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            error_log('order notification error: ' . $e->getMessage());
        }
    }

    private function getOrCreateKhachHangId(string $email, string $defaultName): int {
        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
        $kh = $this->db->khach_hang->findOne(['email' => $regex]);

        if ($kh) {
            return (int)$kh['ma_kh'];
        }

        $maKhMoi = $this->getNextNumericId('khach_hang', 'ma_kh');

        $this->db->khach_hang->insertOne([
            'ma_kh' => $maKhMoi,
            'ho_ten' => $defaultName !== '' ? $defaultName : 'Khach hang',
            'email' => $email,
            'diemtl' => 0,
            'loaikh' => 'Thuong',
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ]);

        return $maKhMoi;
    }

    private function fetchProductPrice(string $productId): int {
        $product = $this->db->san_pham->findOne(['$or' => [['ma_san_pham' => $productId], ['id' => $productId]]]);
        
        // Fallback thử ép kiểu int
        if (!$product && is_numeric($productId)) {
            $product = $this->db->san_pham->findOne(['$or' => [['ma_san_pham' => (int)$productId], ['id' => (int)$productId]]]);
        }

        if (!$product) {
            throw new RuntimeException('San pham khong ton tai: ' . $productId);
        }

        $giaBan = (int)($product['gia_ban'] ?? 0);
        if ($giaBan > 0) {
            return $giaBan;
        }

        $giaThiTruong = (int)($product['gia_thi_truong'] ?? 0);
        return max(0, $giaThiTruong);
    }

    private function findProductForStock(string $productId): ?array {
        $filters = [['ma_san_pham' => $productId], ['id' => $productId]];
        if (is_numeric($productId)) {
            $filters[] = ['ma_san_pham' => (int)$productId];
            $filters[] = ['id' => (int)$productId];
        }
        foreach ($filters as $filter) {
            $product = $this->db->san_pham->findOne($filter);
            if ($product) return (array)$product;
        }
        return null;
    }

    private function productStockField(array $product): ?string {
        foreach (['so_luong_ton_kho', 'so_luong_ton', 'ton_kho', 'stock', 'quantity'] as $field) {
            if (array_key_exists($field, $product)) return $field;
        }
        return null;
    }

    private function reserveStockForItems(array $items): bool {
        $this->lastStockError = '';
        foreach ($items as $item) {
            $productId = (string)($item['ma_san_pham'] ?? '');
            $quantity = max(1, (int)($item['so_luong'] ?? 0));
            $product = $this->findProductForStock($productId);
            if (!$product) {
                $this->lastStockError = 'Không tìm thấy sản phẩm #' . $productId . ' trong kho.';
                return false;
            }
            $field = $this->productStockField($product);
            if ($field === null) continue;
            $stock = (int)($product[$field] ?? 0);
            if ($stock < $quantity) {
                $this->lastStockError = 'Sản phẩm ' . (string)($product['ten_san_pham'] ?? ('#' . $productId)) . ' chỉ còn ' . $stock . ' sản phẩm trong kho.';
                return false;
            }
        }

        foreach ($items as $item) {
            $productId = (string)($item['ma_san_pham'] ?? '');
            $quantity = max(1, (int)($item['so_luong'] ?? 0));
            $product = $this->findProductForStock($productId);
            $field = $product ? $this->productStockField($product) : null;
            if ($field === null) continue;
            $filter = ['$and' => [
                $this->productLookupFilter($productId),
                [$field => ['$gte' => $quantity]],
            ]];
            $before = (int)($product[$field] ?? 0);
            $after = max(0, $before - $quantity);
            $set = [
                $field => $after,
                'so_luong_ton_kho' => $after,
                'trang_thai_kho' => $after > 0 ? 'con_hang' : 'het_hang',
                'da_khoi_tao_kho' => true,
                'updated_at' => new \MongoDB\BSON\UTCDateTime()
            ];
            $this->db->san_pham->updateOne($filter, ['$set' => $set]);
            $this->notifyLowStock((string)($product['ma_san_pham'] ?? $productId), (string)($product['ten_san_pham'] ?? ''), $after);
        }
        return true;
    }

    private function restoreStockForItems(array $items): void {
        foreach ($items as $item) {
            $productId = (string)($item['ma_san_pham'] ?? '');
            $quantity = max(1, (int)($item['so_luong'] ?? 0));
            $product = $this->findProductForStock($productId);
            $field = $product ? $this->productStockField($product) : null;
            if ($field === null) continue;
            $after = max(0, (int)($product[$field] ?? 0) + $quantity);
            $set = [
                $field => $after,
                'so_luong_ton_kho' => $after,
                'trang_thai_kho' => $after > 0 ? 'con_hang' : 'het_hang',
                'da_khoi_tao_kho' => true,
                'updated_at' => new \MongoDB\BSON\UTCDateTime()
            ];
            $this->db->san_pham->updateOne($this->productLookupFilter($productId), ['$set' => $set]);
        }
    }

    private function productLookupFilter(string $productId): array {
        $filters = [['ma_san_pham' => $productId], ['id' => $productId]];
        if (is_numeric($productId)) {
            $filters[] = ['ma_san_pham' => (int)$productId];
            $filters[] = ['id' => (int)$productId];
        }
        return ['$or' => $filters];
    }

    private function notifyLowStock(string $productId, string $productName, int $stock): void {
        if ($stock > 5) return;
        $productName = $productName !== '' ? $productName : ('#' . $productId);
        $title = $stock <= 0 ? 'Sản phẩm hết hàng' : 'Sản phẩm sắp hết hàng';
        $content = $stock <= 0
            ? 'Sản phẩm ' . $productName . ' đã hết hàng.'
            : 'Sản phẩm ' . $productName . ' sắp hết hàng, còn ' . $stock . ' sản phẩm.';
        try {
            $this->db->thong_bao->insertOne([
                'ma_thong_bao' => $this->getNextNumericId('thong_bao', 'ma_thong_bao'),
                'loai' => $stock <= 0 ? 'inventory_out' : 'inventory_low',
                'ma_san_pham' => $productId,
                'tieu_de' => $title,
                'noi_dung' => $content,
                'da_doc' => false,
                'created_at' => new \MongoDB\BSON\UTCDateTime(),
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ]);
        } catch (Throwable $e) {
            error_log('inventory notification error: ' . $e->getMessage());
        }
    }

    public function restoreStockForOrder(int $orderId): void {
        $order = $this->db->hoa_don->findOne(['ma_hoa_don' => $orderId]);
        if (!$order || empty($order['da_tru_ton_kho']) || !empty($order['da_hoan_ton_kho']) || !empty($order['da_hoan_kho'])) return;
        $items = iterator_to_array($this->db->chi_tiet_hoa_don->find(['ma_hoa_don' => $orderId]));
        $this->restoreStockForItems($items);
        $this->db->hoa_don->updateOne(['ma_hoa_don' => $orderId], ['$set' => ['da_hoan_ton_kho' => true, 'da_hoan_kho' => true, 'updated_at' => new \MongoDB\BSON\UTCDateTime()]]);
    }

    private function normalizeCustomerTier(int $points): string {
        if ($points >= self::DIAMOND_THRESHOLD) {
            return 'Kim Cuong';
        }
        if ($points >= self::VIP_THRESHOLD) {
            return 'VIP';
        }
        return 'Thuong';
    }

    private function calculateOrderPoints(array $order): int {
        $baseAmount = 0;

        if (isset($order['tam_tinh'])) {
            $baseAmount = max(0, (int)($order['tam_tinh'] ?? 0) - (int)($order['so_tien_giam'] ?? 0));
        }
        if ($baseAmount <= 0) {
            $baseAmount = max(0, (int)($order['tong_tien'] ?? 0) - (int)($order['phi_van_chuyen'] ?? 0));
        }
        if ($baseAmount > 0) {
            $baseAmount = max(0, $baseAmount - (int)($order['tien_giam_diem'] ?? 0));
        }
        if ($baseAmount <= 0) {
            $baseAmount = max(0, (int)($order['tong_tien'] ?? 0));
        }

        return max(0, (int)floor($baseAmount / 10000));
    }

    private function getCustomerAvailablePoints(int $maKh): int {
        if ($maKh <= 0) return 0;
        $kh = $this->db->khach_hang->findOne(['ma_kh' => $maKh]);
        return $kh ? max(0, (int)($kh['diemtl'] ?? 0)) : 0;
    }

    private function syncCustomerLoyaltyByMaKh(int $maKh): void {
        if ($maKh <= 0) return;

        $orders = $this->db->hoa_don->find(['ma_kh' => $maKh]);
        $totalPoints = 0;

        foreach ($orders as $order) {
            $daTich = !empty($order['da_tich_diem']) && $order['da_tich_diem'] !== 'false' && $order['da_tich_diem'] !== false;
            $daHoan = !empty($order['da_hoan_diem']) && $order['da_hoan_diem'] !== 'false' && $order['da_hoan_diem'] !== false;
            
            $diemCong = (int)($order['diem_cong'] ?? 0);
            $diemSuDung = (int)($order['diem_su_dung'] ?? 0);

            if ($daTich) {
                $totalPoints += $diemCong;
            }
            if (!$daHoan) {
                $totalPoints -= $diemSuDung;
            }
        }

        $reviewBonusPoints = $this->db->danh_gia->countDocuments(['ma_kh' => $maKh]);
        $totalPoints = max(0, $totalPoints + $reviewBonusPoints);

        $tier = $this->normalizeCustomerTier($totalPoints);
        
        $this->db->khach_hang->updateOne(
            ['ma_kh' => $maKh],
            ['$set' => [
                'diemtl' => $totalPoints,
                'loaikh' => $tier,
                'updated_at' => new \MongoDB\BSON\UTCDateTime()
            ]]
        );
    }

    public function syncLoyaltyForOrder(int $orderId): void {
        if ($orderId <= 0) return;

        $order = $this->getOrderById($orderId);
        if (!$order) return;

        $maKh = (int)($order['ma_kh'] ?? 0);
        if ($maKh <= 0) return;

        $currentStatus = $this->normalizeOrderStatus($order['trang_thai'] ?? '');
        $alreadyAwarded = !empty($order['da_tich_diem']) && $order['da_tich_diem'] !== 'false';
        $usedPoints = max(0, (int)($order['diem_su_dung'] ?? 0));
        $alreadyRefunded = !empty($order['da_hoan_diem']) && $order['da_hoan_diem'] !== 'false';
        $didChange = false;

        $updateFields = [];

        if ($currentStatus === 'cancelled' && $usedPoints > 0 && !$alreadyRefunded) {
            $updateFields['da_hoan_diem'] = true;
            $didChange = true;
        }

        if ($currentStatus !== 'cancelled' && $usedPoints > 0 && $alreadyRefunded) {
            $updateFields['da_hoan_diem'] = false;
            $didChange = true;
        }

        if ($currentStatus === 'completed' && !$alreadyAwarded) {
            $points = $this->calculateOrderPoints($order);
            $updateFields['diem_cong'] = $points;
            $updateFields['da_tich_diem'] = true;
            $didChange = true;
        }

        if ($currentStatus !== 'completed' && $alreadyAwarded) {
            $updateFields['diem_cong'] = 0;
            $updateFields['da_tich_diem'] = false;
            $didChange = true;
        }

        if ($didChange) {
            $updateFields['updated_at'] = new \MongoDB\BSON\UTCDateTime();
            $this->db->hoa_don->updateOne(
                ['ma_hoa_don' => $orderId],
                ['$set' => $updateFields]
            );
            $this->syncCustomerLoyaltyByMaKh($maKh);
        }
    }

    public function getOrderById(int $orderId, ?string $email = null): ?array {
        if ($orderId <= 0) return null;

        $order = $this->db->hoa_don->findOne(['ma_hoa_don' => $orderId]);
        if (!$order) return null;
        $order = (array) $order;

        $kh = $this->db->khach_hang->findOne(['ma_kh' => $order['ma_kh']]);
        if ($kh) {
            $order['ho_ten'] = $kh['ho_ten'];
            $order['email'] = $kh['email'];
        }

        if ($email !== null && trim($email) !== '') {
            if (strtolower(trim($email)) !== strtolower(trim($order['email'] ?? ''))) {
                return null;
            }
        }

        return $order;
    }

    public function markPaidByTransfer(int $orderId, int $amount, ?string $transactionReference = null, ?string $transferContent = null): array {
        if ($orderId <= 0) {
            return ['ok' => false, 'message' => 'Ma don hang khong hop le.'];
        }

        $order = $this->getOrderById($orderId);
        if (!$order) {
            return ['ok' => false, 'message' => 'Khong tim thay don hang.'];
        }

        $paymentMethod = strtolower(trim((string)($order['hinh_thuc_thanh_toan'] ?? 'cod')));
        if ($paymentMethod !== 'bank_transfer_qr') {
            return ['ok' => false, 'message' => 'Don hang khong su dung chuyen khoan QR.'];
        }

        $paymentStatus = $this->normalizePaymentStatus($order['status_thanh_toan'] ?? '');
        if ($paymentStatus === 'paid') {
            return [
                'ok' => true,
                'message' => 'Don hang da duoc thanh toan truoc do.',
                'already_paid' => true,
                'order_id' => $orderId,
            ];
        }

        $orderStatus = $this->normalizeOrderStatus($order['trang_thai'] ?? '');
        if ($orderStatus === 'cancelled') {
            return ['ok' => false, 'message' => 'Don hang da huy, khong the doi soat thanh toan.'];
        }

        $expectedAmount = (int)($order['tong_tien'] ?? 0);
        if ($amount < $expectedAmount || $expectedAmount <= 0) {
            return [
                'ok' => false,
                'message' => 'So tien chuyen khoan chua khop voi don hang.',
                'expected_amount' => $expectedAmount,
                'received_amount' => $amount,
            ];
        }

        try {
            $updateHoaDon = [
                'status_thanh_toan' => 'Đã thanh toán',
                'updated_at' => new \MongoDB\BSON\UTCDateTime()
            ];

            if ($orderStatus === 'pending') {
                $updateHoaDon['trang_thai'] = 'Đã xác nhận';
                $updateHoaDon['trang_thai_normalized'] = 'confirmed';
            }

            if ($transactionReference !== null && $transactionReference !== '') {
                $updateHoaDon['ghi_chu_thanh_toan'] = $transactionReference;
            } elseif ($transferContent !== null && $transferContent !== '') {
                $updateHoaDon['ghi_chu_thanh_toan'] = $transferContent;
            }

            $this->db->hoa_don->updateOne(['ma_hoa_don' => $orderId], ['$set' => $updateHoaDon]);
            $this->db->chi_tiet_hoa_don->updateMany(['ma_hoa_don' => $orderId], ['$set' => ['status_thanh_toan' => 'Đã thanh toán']]);
            if (($updateHoaDon['trang_thai_normalized'] ?? '') === 'confirmed') {
                $updatedOrder = (array)($this->db->hoa_don->findOne(['ma_hoa_don' => $orderId]) ?? $order);
                $this->recordAdminOrderNotification($orderId, 'confirmed', $updatedOrder);
            }

            return [
                'ok' => true,
                'message' => 'Da cap nhat don hang thanh cong.',
                'order_id' => $orderId,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function taoDonHang(array $payload): int {
        $email = trim((string)($payload['email'] ?? ''));
        $hoTenMacDinh = trim((string)($payload['ho_ten_mac_dinh'] ?? ''));
        $tenNguoiNhan = trim((string)($payload['ten_nguoi_nhan'] ?? ''));
        $sdtNguoiNhan = trim((string)($payload['sdt_nguoi_nhan'] ?? ''));
        $diaChiGiaoHang = trim((string)($payload['dia_chi_giao_hang'] ?? ''));
        $hinhThucThanhToan = trim((string)($payload['hinh_thuc_thanh_toan'] ?? 'cod'));
        $phiVanChuyen = max(0, (int)($payload['phi_van_chuyen'] ?? 30000));
        $tamTinhInput = max(0, (int)($payload['tam_tinh'] ?? 0));
        $soTienGiam = max(0, (int)($payload['so_tien_giam'] ?? 0));
        $maVoucher = isset($payload['ma_voucher']) && $payload['ma_voucher'] !== null ? (int)$payload['ma_voucher'] : null;
        $maGiamGia = trim((string)($payload['ma_giam_gia'] ?? ''));
        $diemSuDung = max(0, (int)($payload['diem_su_dung'] ?? 0));
        $tienGiamDiemInput = max(0, (int)($payload['tien_giam_diem'] ?? 0));
        $checkoutItems = $payload['checkout_items'] ?? [];

        if ($email === '' || !is_array($checkoutItems) || empty($checkoutItems)) {
            throw new InvalidArgumentException('Du lieu dat hang khong hop le.');
        }

        $maKh = $this->getOrCreateKhachHangId($email, $hoTenMacDinh);
        $lineItems = [];
        $subtotal = 0;

        foreach ($checkoutItems as $maSanPham => $soLuongRaw) {
            $maSanPham = trim((string)$maSanPham);
            if ($maSanPham === '') continue;

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

        $tamTinh = $tamTinhInput > 0 ? $tamTinhInput : $subtotal;
        $soTienGiam = min($tamTinh, $soTienGiam);
        $availablePoints = $this->getCustomerAvailablePoints($maKh);
        $maxDiscountableAmount = max(0, $tamTinh - $soTienGiam);
        $maxPointsByAmount = (int)floor($maxDiscountableAmount / self::POINT_VALUE_VND);
        $diemSuDung = min($diemSuDung, $availablePoints, $maxPointsByAmount);
        $tienGiamDiem = min($maxDiscountableAmount, $tienGiamDiemInput > 0 ? $tienGiamDiemInput : ($diemSuDung * self::POINT_VALUE_VND));
        $tongTien = max(0, $tamTinh - $soTienGiam - $tienGiamDiem) + $phiVanChuyen;
        
        $maHoaDonMoi = $this->getNextNumericId('hoa_don', 'ma_hoa_don');
        $statusThanhToan = $hinhThucThanhToan === 'bank_transfer_qr' ? 'Chờ chuyển khoản' : 'Chưa thanh toán';
        $orderStatus = 'Chờ xử lý';
        $stockReserved = $this->reserveStockForItems($lineItems);
        if (!$stockReserved) {
            throw new RuntimeException($this->lastStockError !== '' ? $this->lastStockError : 'Một số sản phẩm đã hết hàng hoặc không đủ số lượng tồn kho.');
        }

        $dataHoaDon = [
            'ma_hoa_don' => $maHoaDonMoi,
            'ma_kh' => $maKh,
            'ten_nguoi_nhan' => $tenNguoiNhan,
            'sdt_nguoi_nhan' => $sdtNguoiNhan,
            'dia_chi_giao_hang' => $diaChiGiaoHang,
            'phi_van_chuyen' => $phiVanChuyen,
            'tam_tinh' => $tamTinh,
            'so_tien_giam' => $soTienGiam,
            'ma_giam_gia' => ($maGiamGia !== '' ? $maGiamGia : null),
            'ma_voucher' => $maVoucher,
            'tong_tien' => $tongTien,
            'hinh_thuc_thanh_toan' => $hinhThucThanhToan,
            'status_thanh_toan' => $statusThanhToan,
            'diem_cong' => 0,
            'da_tich_diem' => false,
            'diem_su_dung' => $diemSuDung,
            'tien_giam_diem' => $tienGiamDiem,
            'da_hoan_diem' => false,
            'trang_thai' => $orderStatus,
            'trang_thai_normalized' => 'pending',
            'payment_expires_at' => $hinhThucThanhToan === 'bank_transfer_qr' ? new \MongoDB\BSON\UTCDateTime((time() + 86400) * 1000) : null,
            'da_tru_ton_kho' => true,
            'da_hoan_ton_kho' => false,
            'da_hoan_kho' => false,
            'ngay_dat' => new \MongoDB\BSON\UTCDateTime(),
        ];

        try {
            // 1. Lưu hóa đơn
            $this->db->hoa_don->insertOne($dataHoaDon);

            // 2. Lưu chi tiết hóa đơn
            foreach ($lineItems as $item) {
                $idCt = $this->getNextNumericId('chi_tiet_hoa_don', 'id');
                $this->db->chi_tiet_hoa_don->insertOne([
                    'id' => $idCt,
                    'ma_hoa_don' => $maHoaDonMoi,
                    'ma_san_pham' => $item['ma_san_pham'],
                    'so_luong' => $item['so_luong'],
                    'don_gia' => $item['don_gia'],
                    'status_thanh_toan' => $statusThanhToan,
                    'hinh_thuc_thanh_toan' => $hinhThucThanhToan,
                ]);
            }

            // 3. Cập nhật Voucher
            if ($maVoucher !== null && $maVoucher > 0) {
                $this->db->voucher->updateOne(
                    ['ma_voucher' => $maVoucher],
                    ['$inc' => ['so_luong_da_dung' => 1], '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()]]
                );
            }

            // 4. Đồng bộ điểm Loyalty
            $this->syncCustomerLoyaltyByMaKh($maKh);
            $this->recordAdminOrderNotification(
                $maHoaDonMoi,
                $hinhThucThanhToan === 'bank_transfer_qr' ? 'new_qr' : 'new_cod',
                $dataHoaDon
            );

            return $maHoaDonMoi;

        } catch (Throwable $e) {
            $this->restoreStockForItems($lineItems);
            throw $e;
        }
    }

    public function cancelExpiredQrOrders(int $ttlHours = 24): int {
        $deadline = new \MongoDB\BSON\UTCDateTime((time() - max(1, $ttlHours) * 3600) * 1000);
        $cursor = $this->db->hoa_don->find([
            'hinh_thuc_thanh_toan' => 'bank_transfer_qr',
            'status_thanh_toan' => ['$in' => ['Chờ chuyển khoản', 'Cho chuyen khoan', 'Chưa thanh toán', 'Chua thanh toan', 'pending']],
            'trang_thai' => ['$in' => ['Chờ xử lý', 'Chờ thanh toán', 'Cho thanh toan', 'Cho xu ly']],
            'ngay_dat' => ['$lt' => $deadline],
        ]);

        $count = 0;
        foreach ($cursor as $order) {
            $orderId = (int)($order['ma_hoa_don'] ?? 0);
            if ($orderId <= 0) continue;
            $items = iterator_to_array($this->db->chi_tiet_hoa_don->find(['ma_hoa_don' => $orderId]));
            if (!empty($order['da_tru_ton_kho']) && empty($order['da_hoan_ton_kho'])) {
                $this->restoreStockForItems($items);
            }
            $this->db->hoa_don->updateOne(['ma_hoa_don' => $orderId], ['$set' => [
                'trang_thai' => 'Đã hủy',
                'trang_thai_normalized' => 'cancelled',
                'status_thanh_toan' => 'Quá hạn thanh toán',
                'ly_do_huy' => 'Qua han thanh toan QR 24 gio',
                'cancel_reason' => 'Qua han thanh toan QR 24 gio',
                'cancelled_at' => new \MongoDB\BSON\UTCDateTime(),
                'da_hoan_ton_kho' => true,
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ]]);
            $this->recordAdminOrderNotification($orderId, 'cancelled', (array)$order);
            $count++;
        }
        return $count;
    }
}
