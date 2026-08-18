<?php
require_once __DIR__ . '/HoaDon.php';
require_once __DIR__ . '/DanhGia.php';
require_once __DIR__ . '/SanPham.php';

class QuanTri {
    private $db;
    private ?string $lastErrorMessage = null;
    private const VIP_THRESHOLD = 500;
    private const DIAMOND_THRESHOLD = 1500;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getLastErrorMessage(): ?string {
        return $this->lastErrorMessage;
    }

    private function getNextNumericId(string $collection, string $column): int {
        $lastDoc = $this->db->{$collection}->findOne([], ['sort' => [$column => -1]]);
        return $lastDoc ? (int)$lastDoc[$column] + 1 : 1;
    }

    private function normalizeCustomerTier(int $points): string {
        if ($points >= self::DIAMOND_THRESHOLD) return 'Kim Cuong';
        if ($points >= self::VIP_THRESHOLD) return 'VIP';
        return 'Thuong';
    }

    private function grantReviewRewardPoint(int $customerId): void {
        if ($customerId <= 0) return;

        $kh = $this->db->khach_hang->findOne(['ma_kh' => $customerId]);
        if (!$kh) return;

        $updatedPoints = max(0, (int)($kh['diemtl'] ?? 0)) + 1;
        $tier = $this->normalizeCustomerTier($updatedPoints);

        $this->db->khach_hang->updateOne(
            ['ma_kh' => $customerId],
            ['$set' => [
                'diemtl' => $updatedPoints,
                'loaikh' => $tier,
                'updated_at' => new \MongoDB\BSON\UTCDateTime()
            ]]
        );
    }

    public function normalizeOrderStatus($status): string {
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

    private function orderStatusLabel(string $canonical): string {
        return [
            'pending' => 'Chờ xử lý',
            'confirmed' => 'Đã xác nhận',
            'shipping' => 'Đang giao',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã hủy',
        ][$canonical] ?? 'Chờ xử lý';
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

    private function formatMongoDateForStorage($value): string {
        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return $value->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
        }

        $text = trim((string)($value ?? ''));
        if ($text === '' || $text === '0') {
            return '';
        }

        $timestamp = strtotime($text);
        if ($timestamp === false || $timestamp <= 0) {
            return '';
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    public function formatDateTimeSafe($value, string $empty = 'Chưa có ngày'): string {
        $dateText = $this->formatMongoDateForStorage($value);
        if ($dateText === '') {
            return $empty;
        }
        $timestamp = strtotime($dateText);
        return ($timestamp !== false && $timestamp > 0) ? date('d/m/Y H:i', $timestamp) : $empty;
    }

    private function normalizeProductId($id): string {
        return trim((string)($id ?? ''));
    }

    private function productIdCandidates($id): array {
        $productId = $this->normalizeProductId($id);
        $ids = [$productId];
        if ($productId !== '' && is_numeric($productId)) {
            $ids[] = (int)$productId;
        }
        return array_values(array_unique($ids, SORT_REGULAR));
    }

    public function buildProductIdQuery($id): array {
        return ['ma_san_pham' => ['$in' => $this->productIdCandidates($id)]];
    }

    public function getProductBriefById($productId): array {
        return (new SanPham($this->db))->getProductBriefById($productId);
    }

    public function createNotification(array $data): void {
        try {
            $now = new \MongoDB\BSON\UTCDateTime();
            $payload = array_merge([
                'ma_thong_bao' => $this->getNextNumericId('thong_bao', 'ma_thong_bao'),
                'loai' => 'general',
                'tieu_de' => 'Thông báo',
                'noi_dung' => '',
                'da_doc' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ], $data);
            if (empty($payload['created_at'])) $payload['created_at'] = $now;
            if (empty($payload['updated_at'])) $payload['updated_at'] = $now;
            $this->db->thong_bao->insertOne($payload);
        } catch (Throwable $e) {
            error_log('createNotification error: ' . $e->getMessage());
        }
    }

    private function recordAdminOrderNotification(int $orderId, string $type, array $order = []): void {
        if ($orderId <= 0) return;

        $typeLabels = [
            'new_cod' => 'Đơn hàng mới COD',
            'new_qr' => 'Đơn hàng mới QR/chuyển khoản',
            'confirmed' => 'Đơn hàng đã xác nhận',
            'shipping' => 'Đơn hàng đang giao',
            'completed' => 'Đơn hàng hoàn thành',
            'cancelled' => 'Đơn hàng đã hủy',
        ];

        $now = new \MongoDB\BSON\UTCDateTime();
        $title = $typeLabels[$type] ?? 'Cập nhật đơn hàng';
        $payload = [
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
        ];

        try {
            $this->db->thong_bao->insertOne($payload);
        } catch (Throwable $e) {
            error_log('admin notification error: ' . $e->getMessage());
        }
    }

    public function getLowStockProducts(int $threshold = 5, int $limit = 6): array {
        $threshold = max(0, $threshold);
        $limit = max(1, min(20, $limit));
        $sanPhamModel = new SanPham($this->db);

        $stockFields = ['so_luong_ton_kho', 'so_luong_ton', 'ton_kho', 'stock', 'quantity'];
        $orFilters = [];
        foreach ($stockFields as $field) {
            $orFilters[] = [$field => ['$lte' => $threshold]];
            $orFilters[] = [$field => ['$lte' => (string)$threshold]];
        }
        $orFilters[] = ['trang_thai_kho' => 'het_hang'];

        $filter = ['$or' => $orFilters];
        $options = [
            'sort' => ['updated_at' => -1, 'ngay_tao' => -1, 'ma_san_pham' => -1],
            'limit' => 100
        ];

        $items = [];
        $existIds = [];
        $cursor = $this->db->san_pham->find($filter, $options);
        foreach ($cursor as $doc) {
            $p = (array)$doc;
            $stock = $sanPhamModel->getProductStock($p);
            if ($stock !== null && $stock <= $threshold) {
                $brief = $sanPhamModel->getProductBriefById($p['ma_san_pham'] ?? $p['_id'] ?? '');
                $brief['ton_kho'] = $stock;
                $items[] = $brief;
                $existIds[] = (string)($brief['id'] ?? '');
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        if (count($items) < $limit) {
            $allCursor = $this->db->san_pham->find([], [
                'sort' => ['so_luong_ton_kho' => 1, 'ton_kho' => 1, 'ma_san_pham' => -1],
                'limit' => 500
            ]);
            foreach ($allCursor as $doc) {
                $p = (array)$doc;
                $stock = $sanPhamModel->getProductStock($p);
                if ($stock !== null && $stock <= $threshold) {
                    $pId = (string)($p['ma_san_pham'] ?? $p['_id'] ?? '');
                    if (in_array($pId, $existIds, true)) continue;
                    $brief = $sanPhamModel->getProductBriefById($pId);
                    $brief['ton_kho'] = $stock;
                    $items[] = $brief;
                    $existIds[] = $pId;
                }
                if (count($items) >= $limit) break;
            }
        }

        return $items;
    }

    private function resolveKhachHangByEmail(string $email, string $defaultName = 'Khach hang'): ?array {
        $email = trim($email);
        if ($email === '') return null;

        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
        $kh = $this->db->khach_hang->findOne(['email' => $regex]);

        if ($kh) return (array)$kh;

        $maKhMoi = $this->getNextNumericId('khach_hang', 'ma_kh');
        $payload = [
            'ma_kh' => $maKhMoi,
            'ho_ten' => $defaultName,
            'email' => $email,
            'diemtl' => 0,
            'loaikh' => 'Thuong',
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];
        
        $this->db->khach_hang->insertOne($payload);
        return $payload;
    }

    public function getCustomerByEmail(string $email, string $defaultName = 'Khach hang'): ?array {
        return $this->resolveKhachHangByEmail($email, $defaultName);
    }

    private function syncNguoiDungByCustomer(int $id, array $data): void {
        $customer = $this->getCustomerById($id);
        if (!$customer) return;

        $newEmail = trim((string)($customer['email'] ?? ''));
        $oldEmail = trim((string)($data['__old_email'] ?? ''));
        $name = trim((string)($customer['ho_ten'] ?? ''));

        if ($newEmail === '' && $oldEmail === '') return;
        $targetEmail = $newEmail !== '' ? $newEmail : $oldEmail;
        if ($targetEmail === '') return;

        $regexOld = new \MongoDB\BSON\Regex('^' . preg_quote($oldEmail) . '$', 'i');
        $regexNew = new \MongoDB\BSON\Regex('^' . preg_quote($newEmail) . '$', 'i');

        $authDoc = null;
        if ($oldEmail !== '') $authDoc = $this->db->nguoidung->findOne(['email' => $regexOld]);
        if (!$authDoc && $newEmail !== '') $authDoc = $this->db->nguoidung->findOne(['email' => $regexNew]);

        if (!$authDoc) return;

        $updateData = ['email' => $targetEmail];
        if ($name !== '') $updateData['ho_ten'] = $name;

        $this->db->nguoidung->updateOne(
            ['_id' => $authDoc['_id']],
            ['$set' => $updateData]
        );
    }

    public function getDashboardSummary(): array {
        $summary = [
            'tong_san_pham' => $this->db->san_pham->countDocuments([]),
            'tong_danh_muc' => $this->db->danh_muc->countDocuments([]),
            'tong_khach_hang' => $this->db->khach_hang->countDocuments([]),
            'tong_nhan_vien' => $this->db->nhan_vien->countDocuments([
                '$or' => [
                    ['deleted_at' => null],
                    ['deleted_at' => ['$exists' => false]]
                ]
            ]),
            'tong_don_hang' => $this->db->hoa_don->countDocuments([]),
            'don_cho_xu_ly' => 0,
            'tong_doanh_thu' => 0,
            'chat_cho_tra_loi' => 0,
            'danh_gia_cho_phan_hoi' => 0,
        ];
        foreach ($this->db->hoa_don->find([]) as $orderDoc) {
            $order = (array)$orderDoc;
            if ($this->normalizeOrderStatus($order['trang_thai'] ?? '') === 'pending') {
                $summary['don_cho_xu_ly']++;
            }
            if ($this->isRevenueOrder($order)) {
                $summary['tong_doanh_thu'] += (int)($order['tong_tien'] ?? 0);
            }
        }

        // Đếm tin nhắn chat chờ trả lời
        $pipelineChat = [
            ['$sort' => ['thoi_gian' => -1]],
            ['$group' => [
                '_id' => '$ma_kh',
                'last_msg' => ['$first' => '$$ROOT']
            ]],
            ['$match' => ['last_msg.ma_nv' => null]]
        ];
        $chats = $this->db->lich_su_chat->aggregate($pipelineChat)->toArray();
        $summary['chat_cho_tra_loi'] = count($chats);

        // Đếm đánh giá chờ phản hồi trong collection chính.
        $summary['danh_gia_cho_phan_hoi'] = $this->db->danh_gia_san_pham->countDocuments([
            '$or' => [
                ['phan_hoi_shop' => null],
                ['phan_hoi_shop.noi_dung' => ''],
                ['phan_hoi_shop.noi_dung' => ['$exists' => false]]
            ]
        ]);

        return $summary;
    }

    public function getNotificationCenterData(int $orderLimit = 5, int $chatLimit = 5): array {
        $orderLimit = max(1, min(10, $orderLimit));
        $chatLimit = max(1, min(10, $chatLimit));

        $pendingOrdersCount = 0;
        foreach ($this->db->hoa_don->find([], ['projection' => ['trang_thai' => 1]]) as $orderDoc) {
            if ($this->normalizeOrderStatus(((array)$orderDoc)['trang_thai'] ?? '') === 'pending') {
                $pendingOrdersCount++;
            }
        }

        $pipelineChat = [
            ['$sort' => ['thoi_gian' => -1]],
            ['$group' => [
                '_id' => '$ma_kh',
                'last_msg' => ['$first' => '$$ROOT']
            ]],
            ['$match' => ['last_msg.ma_nv' => null]]
        ];
        $chats = $this->db->lich_su_chat->aggregate($pipelineChat)->toArray();
        $pendingChatsCount = count($chats);

        $orders = [];
        $notificationDocs = $this->db->thong_bao->find(
            ['ma_hoa_don' => ['$exists' => true]],
            ['sort' => ['created_at' => -1, 'ma_thong_bao' => -1], 'limit' => $orderLimit]
        );

        foreach ($notificationDocs as $doc) {
            $notice = (array)$doc;
            $orderId = (int)($notice['ma_hoa_don'] ?? 0);
            $order = $orderId > 0 ? (array)($this->db->hoa_don->findOne(['ma_hoa_don' => $orderId]) ?? []) : [];
            if (!empty($order['ma_kh'])) {
                $kh = $this->db->khach_hang->findOne(['ma_kh' => $order['ma_kh']]);
                if ($kh) {
                    $order['ho_ten'] = $kh['ho_ten'];
                    $order['email'] = $kh['email'];
                }
            }
            $order['ma_hoa_don'] = $orderId;
            $order['tieu_de_thong_bao'] = (string)($notice['tieu_de'] ?? 'Thông báo đơn hàng');
            $order['noi_dung_thong_bao'] = (string)($notice['noi_dung'] ?? '');
            $order['tong_tien'] = $order['tong_tien'] ?? ($notice['tong_tien'] ?? 0);
            $order['thoi_gian'] = $this->formatMongoDateForStorage($notice['created_at'] ?? null);
            $orders[] = $order;
        }

        $reviewNotices = [];
        foreach ($this->db->thong_bao->find(
            ['loai' => 'review'],
            ['sort' => ['created_at' => -1, 'ma_thong_bao' => -1], 'limit' => 5]
        ) as $doc) {
            $notice = (array)$doc;
            $notice['thoi_gian'] = $this->formatMongoDateForStorage($notice['created_at'] ?? null);
            $reviewNotices[] = $notice;
        }

        $questionNotices = [];
        foreach ($this->db->thong_bao->find(
            ['loai' => ['$in' => ['hoi_dap_moi', 'question']]],
            ['sort' => ['created_at' => -1, 'ma_thong_bao' => -1], 'limit' => 5]
        ) as $doc) {
            $notice = (array)$doc;
            $notice['thoi_gian'] = $this->formatMongoDateForStorage($notice['created_at'] ?? $notice['ngay_tao'] ?? null);
            $questionNotices[] = $notice;
        }

        $conversations = $this->listChatConversations(true, $chatLimit);
        $latestOrder = $orders[0] ?? [];
        $latestChat = $conversations[0] ?? [];

        return [
            'pending_orders_count' => $pendingOrdersCount,
            'pending_chats_count' => $pendingChatsCount,
            'unread_order_notifications_count' => $this->db->thong_bao->countDocuments([
                '$and' => [
                    ['$or' => [
                        ['ma_hoa_don' => ['$exists' => true]],
                        ['loai' => 'review'],
                        ['loai' => ['$in' => ['hoi_dap_moi', 'question']]],
                    ]],
                    ['$or' => [['da_doc' => false], ['da_doc' => ['$exists' => false]]]],
                ],
            ]),
            'orders' => $orders,
            'reviews' => $reviewNotices,
            'questions' => $questionNotices,
            'chats' => $conversations,
            'latest_order_marker' => ($latestOrder['ma_hoa_don'] ?? '') . '|' . ($latestOrder['thoi_gian'] ?? ''),
            'latest_chat_marker' => ($latestChat['ma_kh'] ?? '') . '|' . ($latestChat['cap_nhat_cuoi'] ?? ''),
        ];
    }

    public function markOrderNotificationsRead(): void {
        try {
            $this->db->thong_bao->updateMany(
                ['$or' => [
                    ['ma_hoa_don' => ['$exists' => true]],
                    ['loai' => 'review'],
                    ['loai' => ['$in' => ['hoi_dap_moi', 'question']]],
                ]],
                ['$set' => ['da_doc' => true, 'updated_at' => new \MongoDB\BSON\UTCDateTime()]]
            );
        } catch (Throwable $e) {
            error_log('markOrderNotificationsRead error: ' . $e->getMessage());
        }
    }

    private function getRevenueOrderDate(array $order): string {
        return $this->formatMongoDateForStorage($order['ngay_hoan_thanh'] ?? $order['thoi_gian_hoan_thanh'] ?? $order['ngay_dat'] ?? $order['created_at'] ?? null);
    }

    private function isOrderInReportRange(array $order, string $startDate = '', string $endDate = ''): bool {
        $dateText = $this->getRevenueOrderDate($order);
        if ($dateText === '') {
            return false;
        }

        $timestamp = strtotime($dateText);
        if ($timestamp === false || $timestamp <= 0) {
            return false;
        }

        if ($startDate !== '' && $timestamp < strtotime($startDate . ' 00:00:00')) {
            return false;
        }
        if ($endDate !== '' && $timestamp > strtotime($endDate . ' 23:59:59')) {
            return false;
        }

        return true;
    }

    public function getRevenueByMonth(int $limit = 6, string $startDate = '', string $endDate = ''): array {
        $limit = max(1, min(120, $limit));
        $months = [];

        foreach ($this->db->hoa_don->find([]) as $doc) {
            $order = (array)$doc;
            if (!$this->isRevenueOrder($order) || !$this->isOrderInReportRange($order, $startDate, $endDate)) {
                continue;
            }

            $dateText = $this->getRevenueOrderDate($order);
            if ($dateText === '') {
                continue;
            }

            $monthKey = date('Y-m', strtotime($dateText));
            if (!isset($months[$monthKey])) {
                $months[$monthKey] = [
                    'thang' => date('m/Y', strtotime($dateText)),
                    'so_don' => 0,
                    'doanh_thu' => 0,
                ];
            }
            $months[$monthKey]['so_don']++;
            $months[$monthKey]['doanh_thu'] += (int)($order['tong_tien'] ?? 0);
        }

        krsort($months);
        return array_slice(array_values($months), 0, $limit);
    }

    public function getTopProductsByRevenue(int $limit = 8, string $startDate = '', string $endDate = ''): array {
        $limit = max(1, min(30, $limit));
        $completedOrderIds = [];
        foreach ($this->db->hoa_don->find([]) as $doc) {
            $order = (array)$doc;
            if ($this->isRevenueOrder($order) && $this->isOrderInReportRange($order, $startDate, $endDate)) {
                $completedOrderIds[] = $order['ma_hoa_don'];
            }
        }

        if (empty($completedOrderIds)) {
            return [];
        }

        $items = [];
        $cursor = $this->db->chi_tiet_hoa_don->find(['ma_hoa_don' => ['$in' => $completedOrderIds]]);
        foreach ($cursor as $doc) {
            $row = (array)$doc;
            $productId = (string)($row['ma_san_pham'] ?? '');
            if ($productId === '') {
                continue;
            }
            if (!isset($items[$productId])) {
                $product = (array)($this->db->san_pham->findOne([
                    '$or' => [
                        ['ma_san_pham' => $productId],
                        ['ma_san_pham' => is_numeric($productId) ? (int)$productId : $productId],
                        ['id' => $productId],
                        ['id' => is_numeric($productId) ? (int)$productId : $productId],
                    ],
                ]) ?? []);
                $items[$productId] = [
                    'ma_san_pham' => $productId,
                    'ten_san_pham' => $product['ten_san_pham'] ?? $productId,
                    'so_don_vi' => 0,
                    'doanh_thu' => 0,
                ];
            }
            $qty = max(0, (int)($row['so_luong'] ?? 0));
            $items[$productId]['so_don_vi'] += $qty;
            $items[$productId]['doanh_thu'] += $qty * (int)($row['don_gia'] ?? 0);
        }

        usort($items, static fn($a, $b) => (int)$b['doanh_thu'] <=> (int)$a['doanh_thu']);
        return array_slice(array_values($items), 0, $limit);
    }

    public function listCategories(string $keyword = ''): array {
        $filter = [];
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $regex = new \MongoDB\BSON\Regex(preg_quote($keyword), 'i');
            $filter['$or'] = [
                ['ten_danh_muc' => $regex],
                ['mo_ta' => $regex]
            ];
        }

        $options = ['sort' => ['ma_danh_muc' => -1]];
        $cursor = $this->db->danh_muc->find($filter, $options);
        $items = [];
        
        foreach ($cursor as $doc) {
            $cat = (array) $doc;
            $cat['so_san_pham'] = $this->db->san_pham->countDocuments(['ma_danh_muc' => $cat['ma_danh_muc']]);
            $items[] = $cat;
        }
        return $items;
    }

    public function getCategoryById(int $id): ?array {
        $doc = $this->db->danh_muc->findOne(['ma_danh_muc' => $id]);
        return $doc ? (array) $doc : null;
    }

    public function saveCategory(array $data, ?int $id = null): bool {
        $this->lastErrorMessage = null;
        $name = trim((string)($data['ten_danh_muc'] ?? ''));
        $desc = trim((string)($data['mo_ta'] ?? ''));
        $status = trim((string)($data['status'] ?? 'active'));

        if ($name === '') {
            $this->lastErrorMessage = 'Vui lòng nhập tên danh mục.';
            return false;
        }

        $regex = new \MongoDB\BSON\Regex('^' . preg_quote($name) . '$', 'i');
        $filter = ['ten_danh_muc' => $regex];
        if ($id !== null) {
            $filter['ma_danh_muc'] = ['$ne' => $id];
        }

        if ($this->db->danh_muc->countDocuments($filter) > 0) {
            $this->lastErrorMessage = 'Tên danh mục đã tồn tại. Vui lòng nhập tên khác.';
            return false;
        }

        $payload = [
            'ten_danh_muc' => $name,
            'mo_ta' => $desc,
            'status' => $status
        ];

        try {
            if ($id !== null && $id > 0) {
                $this->db->danh_muc->updateOne(['ma_danh_muc' => $id], ['$set' => $payload]);
            } else {
                $payload['ma_danh_muc'] = $this->getNextNumericId('danh_muc', 'ma_danh_muc');
                $this->db->danh_muc->insertOne($payload);
            }
            return true;
        } catch (Throwable $e) {
            $this->lastErrorMessage = 'Không thể lưu danh mục lúc này.';
            return false;
        }
    }

    public function deleteCategory(int $id, bool $deleteProducts = false): bool {
        $this->lastErrorMessage = null;
        $referencedCount = $this->db->san_pham->countDocuments(['ma_danh_muc' => $id]);

        if ($referencedCount > 0 && !$deleteProducts) {
            $this->lastErrorMessage = 'Vui lòng xác nhận xóa danh mục. Toàn bộ ' . number_format($referencedCount, 0, ',', '.') . ' sản phẩm thuộc danh mục này sẽ bị xóa.';
            return false;
        }

        try {
            if ($referencedCount > 0) {
                $products = $this->db->san_pham->find(['ma_danh_muc' => $id]);
                $productIds = [];
                foreach ($products as $p) {
                    $productIds[] = $p['ma_san_pham'];
                }

                if (!empty($productIds)) {
                    $this->db->gio_hang->deleteMany(['ma_san_pham' => ['$in' => $productIds]]);
                    $this->db->danh_gia->deleteMany(['ma_san_pham' => ['$in' => $productIds]]);
                    $this->db->chi_tiet_hoa_don->updateMany(
                        ['ma_san_pham' => ['$in' => $productIds]],
                        ['$set' => ['ma_san_pham' => null]]
                    );
                    $this->db->san_pham->deleteMany(['ma_san_pham' => ['$in' => $productIds]]);
                }
            }

            $this->db->danh_muc->deleteOne(['ma_danh_muc' => $id]);
            return true;
        } catch (Throwable $e) {
            $this->lastErrorMessage = 'Không thể xóa danh mục lúc này.';
            return false;
        }
    }

    public function listCustomers(string $keyword = '', string $loaiKh = ''): array {
        $filter = [];
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $regex = new \MongoDB\BSON\Regex(preg_quote($keyword), 'i');
            $filter['$or'] = [
                ['ho_ten' => $regex],
                ['email' => $regex],
                ['so_dien_thoai' => $regex]
            ];
        }

        $loaiKh = trim($loaiKh);
        if ($loaiKh !== '') {
            $filter['loaikh'] = new \MongoDB\BSON\Regex('^' . preg_quote($loaiKh) . '$', 'i');
        }

        $options = ['sort' => ['ma_kh' => -1]];
        $cursor = $this->db->khach_hang->find($filter, $options);
        $items = [];

        foreach ($cursor as $doc) {
            $kh = (array) $doc;
            // Tính tổng đơn và chi tiêu
            $orders = $this->db->hoa_don->find(['ma_kh' => $kh['ma_kh']])->toArray();
            $kh['tong_don'] = count($orders);
            $kh['tong_chi_tieu'] = 0;
            foreach ($orders as $order) {
                $kh['tong_chi_tieu'] += (int)($order['tong_tien'] ?? 0);
            }
            $items[] = $kh;
        }
        return $items;
    }

    public function getCustomerById(int $id): ?array {
        $doc = $this->db->khach_hang->findOne(['ma_kh' => $id]);
        return $doc ? (array) $doc : null;
    }

    public function saveCustomer(array $data, ?int $id = null): bool {
        $name = trim((string)($data['ho_ten'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        if ($name === '') return false;

        $oldEmail = '';
        if ($id !== null && $id > 0) {
            $existing = $this->getCustomerById($id);
            $oldEmail = trim((string)($existing['email'] ?? ''));
        }

        $payload = [
            'ho_ten' => $name,
            'email' => $email !== '' ? $email : null,
            'so_dien_thoai' => trim((string)($data['so_dien_thoai'] ?? '')) ?: null,
            'gioi_tinh' => trim((string)($data['gioi_tinh'] ?? '')) ?: null,
            'nam_sinh' => trim((string)($data['nam_sinh'] ?? '')) !== '' ? (int)$data['nam_sinh'] : null,
            'dia_chi' => trim((string)($data['dia_chi'] ?? '')) ?: null,
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];

        try {
            if ($id !== null && $id > 0) {
                $this->db->khach_hang->updateOne(['ma_kh' => $id], ['$set' => $payload]);
                $data['__old_email'] = $oldEmail;
                $this->syncNguoiDungByCustomer($id, $data);
                return true;
            }

            $payload['ma_kh'] = $this->getNextNumericId('khach_hang', 'ma_kh');
            $payload['created_at'] = new \MongoDB\BSON\UTCDateTime();
            $this->db->khach_hang->insertOne($payload);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function deleteCustomer(int $id): bool {
        $customer = $this->getCustomerById($id);
        if (!$customer) return false;
        $email = trim((string)($customer['email'] ?? ''));

        try {
            $regex = $email !== '' ? new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i') : null;

            // 1. Delete customer document from khach_hang
            $this->db->khach_hang->deleteMany(['ma_kh' => $id]);
            if ($regex) {
                $this->db->khach_hang->deleteMany(['email' => $regex]);
            }

            // 2. Delete user account from nguoidung (unless staff)
            if ($regex) {
                $staff = $this->db->nhan_vien->findOne(['email' => $regex]);
                if (!$staff) {
                    $this->db->nguoidung->deleteMany(['email' => $regex]);
                }
            }

            // 3. Delete or disassociate all orders and order details for this ma_kh and email
            $orConds = [['ma_kh' => $id]];
            if ($regex) {
                $orConds[] = ['email' => $regex];
            }
            $orderDocs = iterator_to_array($this->db->hoa_don->find(['$or' => $orConds]));
            $orderIds = array_column($orderDocs, 'ma_hoa_don');
            if (!empty($orderIds)) {
                $this->db->chi_tiet_hoa_don->deleteMany(['ma_hoa_don' => ['$in' => $orderIds]]);
            }
            $this->db->hoa_don->deleteMany(['$or' => $orConds]);

            // 4. Clean up search history & chat history
            if ($regex) {
                $this->db->lich_su_chat->deleteMany(['$or' => [['email' => $regex], ['ma_kh' => $id]]]);
                $this->db->lich_su_tim_kiem->deleteMany(['$or' => [['email' => $regex], ['ma_kh' => $id]]]);
            }

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function listRoles(): array {
        $options = ['sort' => ['ten_vai_tro' => 1]];
        $cursor = $this->db->vai_tro->find([], $options);
        $items = [];
        foreach ($cursor as $doc) {
            $items[] = (array) $doc;
        }
        return $items;
    }

    public function listStaff(string $keyword = ''): array {
        $filter = [
            'trang_thai' => ['$ne' => 'deleted']
        ];

        $keyword = trim($keyword);
        if ($keyword !== '') {
            $regex = new \MongoDB\BSON\Regex(preg_quote($keyword), 'i');
            $filter['$and'] = [
                ['$or' => [
                    ['ho_ten' => $regex],
                    ['email' => $regex],
                    ['so_dien_thoai' => $regex]
                ]]
            ];
        }

        $options = ['sort' => ['ma_nv' => -1]];
        $cursor = $this->db->nhan_vien->find($filter, $options);
        $items = [];

        foreach ($cursor as $doc) {
            $nv = (array) $doc;
            if (isset($nv['ma_vai_tro'])) {
                $role = $this->db->vai_tro->findOne(['ma_vai_tro' => $nv['ma_vai_tro']]);
                $nv['ten_vai_tro'] = $role ? $role['ten_vai_tro'] : '';
            }
            $items[] = $nv;
        }
        return $items;
    }

    public function getStaffById(int $id): ?array {
        $doc = $this->db->nhan_vien->findOne(['ma_nv' => $id]);
        if (!$doc) return null;
        
        $nv = (array) $doc;
        if (isset($nv['ma_vai_tro'])) {
            $role = $this->db->vai_tro->findOne(['ma_vai_tro' => $nv['ma_vai_tro']]);
            $nv['ten_vai_tro'] = $role ? $role['ten_vai_tro'] : '';
        }
        return $nv;
    }

    public function isStaffAccountActive(int $id): bool {
        $nv = $this->getStaffById($id);
        if (!$nv) return false;

        $status = strtolower(trim((string)($nv['trang_thai'] ?? 'active')));
        if (in_array($status, ['inactive', 'deleted', 'locked', 'disabled', 'tam_khoa'], true)) {
            return false;
        }
        return empty($nv['deleted_at']);
    }

    public function saveStaff(array $data, ?int $id = null): bool {
        $this->lastErrorMessage = null;
        $name = trim((string)($data['ho_ten'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['mat_khau'] ?? '');
        $roleId = (int)($data['ma_vai_tro'] ?? 0);

        if ($name === '' || $email === '' || $roleId <= 0) {
            $this->lastErrorMessage = 'Vui lòng nhập đầy đủ họ tên, email và vai trò.';
            return false;
        }

        $payload = [
            'ho_ten' => $name,
            'email' => $email,
            'so_dien_thoai' => trim((string)($data['so_dien_thoai'] ?? '')) ?: null,
            'ma_vai_tro' => $roleId,
            'trang_thai' => trim((string)($data['trang_thai'] ?? 'active')) ?: 'active',
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];

        if ($password !== '') {
            $payload['mat_khau'] = password_hash($password, PASSWORD_BCRYPT);
        }

        try {
            if ($id !== null && $id > 0) {
                $this->db->nhan_vien->updateOne(['ma_nv' => $id], ['$set' => $payload]);
                return true;
            }

            if ($password === '') {
                $this->lastErrorMessage = 'Vui lòng nhập mật khẩu khi tạo nhân viên mới.';
                return false;
            }

            $payload['ma_nv'] = $this->getNextNumericId('nhan_vien', 'ma_nv');
            $payload['created_at'] = new \MongoDB\BSON\UTCDateTime();
            $this->db->nhan_vien->insertOne($payload);
            return true;
        } catch (Throwable $e) {
            $this->lastErrorMessage = 'Không thể lưu nhân viên lúc này.';
            return false;
        }
    }

    public function deleteStaff(int $id): bool {
        $this->lastErrorMessage = null;
        $nv = $this->getStaffById($id);
        if (!$nv) {
            $this->lastErrorMessage = 'Không tìm thấy nhân viên.';
            return false;
        }

        $currentStatus = strtolower(trim((string)($nv['trang_thai'] ?? 'active')));
        $newStatus = ($currentStatus === 'active') ? 'inactive' : 'active';

        try {
            $this->db->nhan_vien->updateOne(
                ['ma_nv' => $id],
                [
                    '$set' => [
                        'trang_thai' => $newStatus,
                        'updated_at' => new \MongoDB\BSON\UTCDateTime()
                    ],
                    '$unset' => [
                        'deleted_at' => ''
                    ]
                ]
            );
            return true;
        } catch (Throwable $e) {
            $this->lastErrorMessage = 'Không thể cập nhật trạng thái nhân viên.';
            return false;
        }
    }

    public function hardDeleteStaff(int $id): bool {
        $this->lastErrorMessage = null;
        if ($id <= 0) {
            $this->lastErrorMessage = 'Mã nhân viên không hợp lệ.';
            return false;
        }

        try {
            $this->db->lich_su_chat->updateMany(['ma_nv' => $id], ['$set' => ['ma_nv' => null]]);
            $this->db->danh_gia->updateMany(['ma_nv_phan_hoi' => $id], ['$set' => ['ma_nv_phan_hoi' => null]]);
            $this->db->nhan_vien->deleteOne(['ma_nv' => $id]);
            return true;
        } catch (Throwable $e) {
            $this->lastErrorMessage = 'Không thể xóa nhân viên lúc này.';
            return false;
        }
    }

    public function listOrders(string $keyword = '', string $status = ''): array {
        $filter = [];
        $keyword = trim($keyword);
        $status = trim($status);
        $statusCanonical = $status !== '' ? $this->normalizeOrderStatus($status) : '';

        $options = ['sort' => ['ngay_dat' => -1, 'created_at' => -1, 'ma_hoa_don' => -1]];
        $cursor = $this->db->hoa_don->find($filter, $options);
        $items = [];

        foreach ($cursor as $doc) {
            $order = (array) $doc;
            $order['trang_thai_normalized'] = $this->normalizeOrderStatus($order['trang_thai'] ?? '');
            $order['trang_thai_hien_thi'] = $this->orderStatusLabel($order['trang_thai_normalized']);
            $order['ngay_dat_hien_thi'] = $this->formatMongoDateForStorage($order['ngay_dat'] ?? $order['created_at'] ?? null);
            if ($statusCanonical !== '' && $order['trang_thai_normalized'] !== $statusCanonical) {
                continue;
            }
            
            $kh = $this->db->khach_hang->findOne(['ma_kh' => $order['ma_kh']]);
            if ($kh) {
                $order['ho_ten'] = $kh['ho_ten'];
                $order['email'] = $kh['email'];
                $order['so_dien_thoai'] = $kh['so_dien_thoai'] ?? null;
            }

            if ($keyword !== '') {
                $haystack = implode(' ', [
                    $order['ma_hoa_don'] ?? '',
                    $order['ho_ten'] ?? '',
                    $order['email'] ?? '',
                    $order['so_dien_thoai'] ?? '',
                    $order['trang_thai'] ?? '',
                    $order['trang_thai_hien_thi'] ?? '',
                    $order['hinh_thuc_thanh_toan'] ?? '',
                    $order['status_thanh_toan'] ?? '',
                    $order['dia_chi_giao_hang'] ?? '',
                ]);
                if (stripos($haystack, $keyword) === false) {
                    continue;
                }
            }

            $order['so_dong_hang'] = $this->db->chi_tiet_hoa_don->countDocuments(['ma_hoa_don' => $order['ma_hoa_don']]);
            $items[] = $order;
        }
        return $items;
    }

    public function getOrderById(int $id): ?array {
        $doc = $this->db->hoa_don->findOne(['ma_hoa_don' => $id]);
        if (!$doc) return null;

        $order = (array) $doc;
        $order['trang_thai_normalized'] = $this->normalizeOrderStatus($order['trang_thai'] ?? '');
        $order['trang_thai_hien_thi'] = $this->orderStatusLabel($order['trang_thai_normalized']);
        $order['ngay_dat_hien_thi'] = $this->formatMongoDateForStorage($order['ngay_dat'] ?? $order['created_at'] ?? null);
        $order['ngay_hoan_thanh_hien_thi'] = $this->formatMongoDateForStorage($order['ngay_hoan_thanh'] ?? $order['thoi_gian_hoan_thanh'] ?? null);
        $kh = $this->db->khach_hang->findOne(['ma_kh' => $order['ma_kh']]);
        if ($kh) {
            $order['ho_ten'] = $kh['ho_ten'];
            $order['email'] = $kh['email'];
            $order['so_dien_thoai'] = $kh['so_dien_thoai'] ?? null;
        }
        $order['items'] = $this->getOrderItems($id);
        return $order;
    }

    public function getOrderItems(int $orderId): array {
        $options = ['sort' => ['id' => 1]];
        $cursor = $this->db->chi_tiet_hoa_don->find(['ma_hoa_don' => $orderId], $options);
        $items = [];

        foreach ($cursor as $doc) {
            $ct = (array) $doc;
            $productId = (string)($ct['ma_san_pham'] ?? $ct['id_san_pham'] ?? $ct['product_id'] ?? '');
            $brief = $productId !== '' ? $this->getProductBriefById($productId) : [];
            $qty = max(1, (int)($ct['so_luong'] ?? $ct['quantity'] ?? 1));
            $unitPrice = (int)($ct['don_gia'] ?? $ct['gia_ban'] ?? $brief['gia_ban'] ?? 0);
            $ct['ma_san_pham'] = $productId !== '' ? $productId : (string)($brief['ma_san_pham'] ?? '');
            $ct['ten_san_pham'] = (string)($ct['ten_san_pham'] ?? $ct['ten_sp'] ?? $brief['ten_san_pham'] ?? $ct['ma_san_pham']);
            $ct['thuong_hieu'] = (string)($ct['thuong_hieu'] ?? $brief['thuong_hieu'] ?? '');
            $ct['link_hinh_anh'] = (string)($ct['link_hinh_anh'] ?? $ct['hinh_anh'] ?? $brief['link_hinh_anh'] ?? '');
            $ct['don_gia'] = $unitPrice;
            $ct['so_luong'] = $qty;
            $ct['thanh_tien'] = (int)($ct['thanh_tien'] ?? ($unitPrice * $qty));
            $ct['product_missing'] = empty($brief);
            $items[] = $ct;
        }
        return $items;
    }

    public function updateOrderStatus(int $orderId, string $status, string $cancelReason = '', bool $allowCancelledOverride = false): bool {
        $this->lastErrorMessage = null;
        $canonicalStatus = $this->normalizeOrderStatus($status);
        if ($orderId <= 0 || !in_array($canonicalStatus, ['pending', 'confirmed', 'shipping', 'completed', 'cancelled'], true)) {
            $this->lastErrorMessage = 'Dữ liệu cập nhật trạng thái đơn hàng không hợp lệ.';
            return false;
        }

        $order = $this->db->hoa_don->findOne(['ma_hoa_don' => $orderId]);
        if (!$order) {
            $this->lastErrorMessage = 'Không tìm thấy đơn hàng cần cập nhật.';
            return false;
        }
        $orderArray = (array)$order;
        $currentCanonical = $this->normalizeOrderStatus($orderArray['trang_thai'] ?? '');
        $isCancelled = $canonicalStatus === 'cancelled';
        $currentIsCancelled = $currentCanonical === 'cancelled';

        if (!$allowCancelledOverride && $currentIsCancelled && !$isCancelled) {
            $this->lastErrorMessage = 'Đơn hàng đã hủy không thể chuyển sang trạng thái khác.';
            return false;
        }

        $trimmedCancelReason = trim($cancelReason);
        if ($isCancelled && !$currentIsCancelled && $trimmedCancelReason === '') {
            $this->lastErrorMessage = 'Vui lòng chọn lý do hủy đơn hàng.';
            return false;
        }

        $displayStatus = $this->orderStatusLabel($canonicalStatus);
        $now = new \MongoDB\BSON\UTCDateTime();
        $updateData = [
            'trang_thai' => $displayStatus,
            'trang_thai_normalized' => $canonicalStatus,
            'updated_at' => $now,
        ];

        if ($canonicalStatus === 'completed') {
            if (empty($orderArray['ngay_hoan_thanh'])) {
                $updateData['ngay_hoan_thanh'] = $now;
            }
            if (empty($orderArray['thoi_gian_hoan_thanh'])) {
                $updateData['thoi_gian_hoan_thanh'] = $now;
            }
            $paymentMethod = strtolower(trim((string)($orderArray['hinh_thuc_thanh_toan'] ?? 'cod')));
            if ($paymentMethod === 'cod') {
                $updateData['status_thanh_toan'] = 'Đã thanh toán';
            }
        }

        if ($isCancelled && $trimmedCancelReason !== '') {
            $updateData['ly_do_huy'] = $trimmedCancelReason;
        } elseif (!$isCancelled) {
            $updateData['ly_do_huy'] = null;
        }

        try {
            $this->db->hoa_don->updateOne(['ma_hoa_don' => $orderId], ['$set' => $updateData]);
            if (($updateData['status_thanh_toan'] ?? '') === 'Đã thanh toán') {
                $this->db->chi_tiet_hoa_don->updateMany(['ma_hoa_don' => $orderId], ['$set' => ['status_thanh_toan' => 'Đã thanh toán']]);
            }

            $hoaDonModel = new HoaDon($this->db);
            if ($isCancelled && !$currentIsCancelled && method_exists($hoaDonModel, 'restoreStockForOrder')) {
                $hoaDonModel->restoreStockForOrder($orderId);
            }
            $hoaDonModel->syncLoyaltyForOrder($orderId);

            $updatedOrder = (array)($this->db->hoa_don->findOne(['ma_hoa_don' => $orderId]) ?? $orderArray);
            $noticeType = [
                'confirmed' => 'confirmed',
                'shipping' => 'shipping',
                'completed' => 'completed',
                'cancelled' => 'cancelled',
            ][$canonicalStatus] ?? '';
            if ($noticeType !== '' && $canonicalStatus !== $currentCanonical) {
                $this->recordAdminOrderNotification($orderId, $noticeType, $updatedOrder);
            }
            return true;
        } catch (Throwable $e) {
            error_log('updateOrderStatus error: ' . $e->getMessage());
            return false;
        }
    }

    public function getOrderStatusOptions(): array {
        return [
            'pending' => 'Chờ xử lý',
            'confirmed' => 'Đã xác nhận',
            'shipping' => 'Đang giao',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã hủy',
        ];
    }

    public function listReviews(string $keyword = '', array $filters = []): array {
        $keyword = trim($keyword);
        $star = max(0, min(5, (int)($filters['so_sao'] ?? 0)));
        $replyStatus = strtolower(trim((string)($filters['trang_thai_phan_hoi'] ?? '')));
        $maKhDigits = preg_replace('/\D+/', '', trim((string)($filters['ma_kh'] ?? '')));
        $limit = max(10, min(200, (int)($filters['limit'] ?? 60)));

        $filter = ['$or' => [
            ['trang_thai' => ['$exists' => false]],
            ['trang_thai' => 'hien_thi'],
            ['trang_thai' => 'active'],
        ]];
        if ($star > 0) $filter['so_sao'] = $star;
        if ($maKhDigits !== '') $filter['ma_khach_hang'] = (int)$maKhDigits;

        $items = [];
        foreach ($this->db->danh_gia_san_pham->find($filter, ['sort' => ['ngay_danh_gia' => 1, 'ma_danh_gia' => 1], 'limit' => 300]) as $doc) {
            $dg = (array)$doc;
            $dg['_source'] = 'danh_gia_san_pham';
            $items[] = $this->hydrateReviewForStaff($dg);
        }

        foreach ($this->db->danh_gia->find([], ['sort' => ['ngay_danh_gia' => 1, 'ma_danh_gia' => 1], 'limit' => 300]) as $doc) {
            $legacy = (array)$doc;
            if ($star > 0 && (int)($legacy['so_sao'] ?? 0) !== $star) continue;
            if ($maKhDigits !== '' && (int)($legacy['ma_kh'] ?? 0) !== (int)$maKhDigits) continue;
            if ($this->db->danh_gia_san_pham->findOne(['legacy_ma_danh_gia' => (int)($legacy['ma_danh_gia'] ?? 0)])) continue;
            $legacy['_source'] = 'danh_gia';
            $items[] = $this->hydrateReviewForStaff($legacy);
        }

        $items = array_values(array_filter($items, function ($review) use ($keyword, $replyStatus, $filters) {
            $hasReply = trim((string)($review['phan_hoi'] ?? '')) !== '';
            if ($replyStatus === 'pending' && $hasReply) return false;
            if ($replyStatus === 'replied' && !$hasReply) return false;

            $orderStatus = trim((string)($filters['trang_thai_don'] ?? ''));
            if ($orderStatus !== '' && $this->normalizeOrderStatus($review['trang_thai_don_hang'] ?? '') !== $this->normalizeOrderStatus($orderStatus)) return false;

            $dateRange = trim((string)($filters['khoang_ngay'] ?? ''));
            if ($dateRange !== '') {
                $days = ['1d' => 1, '3d' => 3, '7d' => 7, '30d' => 30][$dateRange] ?? 0;
                $ts = strtotime((string)($review['ngay_danh_gia'] ?? ''));
                if ($days > 0 && ($ts === false || $ts < strtotime('-' . $days . ' days'))) return false;
            }

            $maVanDon = preg_replace('/\D+/', '', trim((string)($filters['ma_van_don'] ?? '')));
            if ($maVanDon !== '' && (string)($review['ma_van_don'] ?? '') !== $maVanDon) return false;

            $phone = trim((string)($filters['sdt_khach_hang'] ?? ''));
            if ($phone !== '' && stripos((string)($review['sdt_khach_hang'] ?? ''), $phone) === false) return false;

            if ($keyword === '') return true;
            $haystack = implode(' ', [
                $review['ma_danh_gia'] ?? '',
                $review['ma_san_pham'] ?? '',
                $review['ten_san_pham'] ?? '',
                $review['thuong_hieu'] ?? '',
                $review['ten_khach_hang'] ?? '',
                $review['noi_dung'] ?? '',
                $review['so_sao'] ?? '',
                $review['trang_thai_phan_hoi'] ?? '',
            ]);
            return stripos($haystack, $keyword) !== false;
        }));

        usort($items, function ($a, $b) {
            $aPending = trim((string)($a['phan_hoi'] ?? '')) === '' ? 0 : 1;
            $bPending = trim((string)($b['phan_hoi'] ?? '')) === '' ? 0 : 1;
            if ($aPending !== $bPending) return $aPending <=> $bPending;
            $aBad = (int)($a['so_sao'] ?? 0) <= 2 ? 0 : 1;
            $bBad = (int)($b['so_sao'] ?? 0) <= 2 ? 0 : 1;
            if ($aBad !== $bBad) return $aBad <=> $bBad;
            return strtotime((string)($a['ngay_danh_gia'] ?? '')) <=> strtotime((string)($b['ngay_danh_gia'] ?? ''));
        });

        return array_slice($items, 0, $limit);
    }

    private function hydrateReviewForStaff(array $dg): array {
        $isNew = ($dg['_source'] ?? '') === 'danh_gia_san_pham';
        $customerId = (int)($dg['ma_khach_hang'] ?? $dg['ma_kh'] ?? 0);
        $dg['ma_kh'] = $customerId;
        $kh = $customerId > 0 ? $this->db->khach_hang->findOne(['ma_kh' => $customerId]) : null;
        $dg['ten_khach_hang'] = $dg['ten_khach_hang'] ?? ($kh['ho_ten'] ?? 'Khách hàng');
        $dg['email'] = $kh['email'] ?? '';
        $dg['sdt_khach_hang'] = $kh['so_dien_thoai'] ?? '';
        $dg['ma_san_pham'] = (string)($dg['ma_san_pham'] ?? '');

        $sp = $this->getProductBriefById($dg['ma_san_pham']);
        $dg['ten_san_pham'] = $sp['ten_san_pham'] ?? '';
        $dg['thuong_hieu'] = $sp['thuong_hieu'] ?? '';
        $dg['link_hinh_anh'] = $sp['link_hinh_anh'] ?? '';
        $dg['product_missing'] = empty($sp);

        $reply = $isNew ? ($dg['phan_hoi_shop'] ?? null) : null;
        if (is_object($reply)) $reply = (array)$reply;
        if ($isNew) {
            $dg['phan_hoi'] = is_array($reply) ? (string)($reply['noi_dung'] ?? '') : '';
            $dg['ma_nv_phan_hoi'] = is_array($reply) ? ($reply['ma_nhan_vien'] ?? null) : null;
            $dg['ngay_phan_hoi'] = is_array($reply) ? ($reply['ngay_phan_hoi'] ?? null) : null;
        } else {
            $dg['phan_hoi_shop'] = trim((string)($dg['phan_hoi'] ?? '')) !== '' ? ['noi_dung' => $dg['phan_hoi']] : null;
        }
        $dg['trang_thai_phan_hoi'] = trim((string)($dg['phan_hoi'] ?? '')) !== '' ? 'Đã phản hồi' : 'Chưa phản hồi';

        if (!empty($dg['ngay_danh_gia']) && $dg['ngay_danh_gia'] instanceof \MongoDB\BSON\UTCDateTime) {
            $dg['ngay_danh_gia'] = $dg['ngay_danh_gia']->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
        }
        if (!empty($dg['ngay_phan_hoi']) && $dg['ngay_phan_hoi'] instanceof \MongoDB\BSON\UTCDateTime) {
            $dg['ngay_phan_hoi'] = $dg['ngay_phan_hoi']->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');
        }

        if (!empty($dg['ma_nv_phan_hoi'])) {
            $nv = $this->db->nhan_vien->findOne(['ma_nv' => (int)$dg['ma_nv_phan_hoi']]);
            $dg['ten_nhan_vien_phan_hoi'] = $nv ? ($nv['ho_ten'] ?? '') : '';
        }

        $ct = $this->db->chi_tiet_hoa_don->findOne($this->buildProductIdQuery($dg['ma_san_pham']), ['sort' => ['ma_hoa_don' => -1]]);
        if ($ct) {
            $hd = $this->db->hoa_don->findOne(['ma_hoa_don' => $ct['ma_hoa_don'], 'ma_kh' => $customerId]);
            if ($hd) {
                $dg['ma_van_don'] = $hd['ma_hoa_don'];
                $dg['trang_thai_don_hang'] = $hd['trang_thai'];
            }
        }
        return $dg;
    }

    public function getReviewFilterOptions(): array {
        $orderStatusOptions = [
            'pending' => 'Chờ xử lý',
            'confirmed' => 'Đã xác nhận',
            'shipping' => 'Đang giao',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã hủy',
        ];

        return [
            'so_sao' => [1, 2, 3, 4, 5],
            'trang_thai_phan_hoi' => [
                'pending' => 'Chưa phản hồi',
                'replied' => 'Đã phản hồi',
            ],
            'trang_thai_don' => $orderStatusOptions,
            'khoang_ngay' => [
                '1d' => '1 ngày gần nhất',
                '3d' => '3 ngày gần nhất',
                '7d' => '1 tuần gần nhất',
                '30d' => '1 tháng gần nhất',
            ],
        ];
    }

    public function getProductReviews(string $productId): array {
        $options = ['sort' => ['ngay_danh_gia' => -1, 'ma_danh_gia' => -1]];
        $cursor = $this->db->danh_gia->find(['ma_san_pham' => $productId], $options);
        $items = [];
        
        foreach ($cursor as $doc) {
            $dg = (array) $doc;
            $kh = $this->db->khach_hang->findOne(['ma_kh' => $dg['ma_kh']]);
            $dg['ten_khach_hang'] = $kh ? $kh['ho_ten'] : '';
            $dg['da_mua_hang'] = $dg['da_mua_hang'] ?? true;
            if (!empty($dg['ngay_danh_gia']) && $dg['ngay_danh_gia'] instanceof \MongoDB\BSON\UTCDateTime) {
                $dg['ngay_danh_gia'] = $dg['ngay_danh_gia']->toDateTime()->format('Y-m-d H:i:s');
            }
            $items[] = $dg;
        }
        return $items;
    }

    public function getCustomerReviewEligibility(int $customerId, array $productIds = []): array {
        if ($customerId <= 0 || empty($productIds)) return [];

        $reviewModel = new DanhGia($this->db);
        $perPurchaseResult = [];
        foreach (array_unique(array_filter(array_map('strval', $productIds))) as $pid) {
            $perPurchaseResult[$pid] = $reviewModel->canUserReviewProduct($customerId, $pid);
        }
        return $perPurchaseResult;

        $result = [];
        foreach ($productIds as $pid) {
            $result[$pid] = ['has_purchased' => false, 'has_reviewed' => false];
        }

        // Tìm các HD đã mua
        $orders = $this->db->hoa_don->find([
            'ma_kh' => $customerId,
            'trang_thai' => ['$nin' => [new \MongoDB\BSON\Regex('^(da huy|đã hủy|huy)$', 'i')]]
        ]);
        
        $orderIds = [];
        foreach ($orders as $o) {
            $orderIds[] = $o['ma_hoa_don'];
        }

        if (!empty($orderIds)) {
            $cts = $this->db->chi_tiet_hoa_don->find(['ma_hoa_don' => ['$in' => $orderIds], 'ma_san_pham' => ['$in' => $productIds]]);
            foreach ($cts as $ct) {
                $result[$ct['ma_san_pham']]['has_purchased'] = true;
            }
        }

        // Tìm các review đã viết
        $reviews = $this->db->danh_gia->find([
            'ma_kh' => $customerId,
            'ma_san_pham' => ['$in' => $productIds]
        ]);
        foreach ($reviews as $rv) {
            $result[$rv['ma_san_pham']]['has_reviewed'] = true;
        }

        return $result;
    }

    private function refreshProductRating(string $productId): void {
        $pipeline = [
            ['$match' => ['ma_san_pham' => $productId]],
            ['$group' => [
                '_id' => null,
                'avg_score' => ['$avg' => '$so_sao'],
                'review_count' => ['$sum' => 1]
            ]]
        ];
        
        $stats = $this->db->danh_gia->aggregate($pipeline)->toArray();
        if (!empty($stats)) {
            $this->db->san_pham->updateOne(
                ['ma_san_pham' => $productId],
                ['$set' => [
                    'diem_danh_gia' => round($stats[0]['avg_score'], 1),
                    'so_luong_danh_gia' => $stats[0]['review_count']
                ]]
            );
        }
    }

    public function createReview(string $customerEmail, string $productId, int $stars, string $content): array {
        try {
            $reviewModel = new DanhGia($this->db);
            $kh = $reviewModel->resolveCustomerByEmail($customerEmail, 'Khách hàng');
            if (!$kh || empty($kh['ma_kh'])) {
                return ['ok' => false, 'message' => 'Không xác định được khách hàng để gửi đánh giá.'];
            }
            return $reviewModel->addReview($productId, (int)$kh['ma_kh'], [
                'so_sao' => $stars,
                'noi_dung' => $content,
            ]);
        } catch (Throwable $e) {
            error_log('createReview delegation error: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Không thể gửi đánh giá lúc này.'];
        }
    }

    public function replyReview(int $reviewId, int $staffId, string $reply, string $rowRef = ''): bool {
        if ($reviewId <= 0 || trim($reply) === '') return false;

        $staffName = '';
        if ($staffId > 0) {
            $staff = $this->db->nhan_vien->findOne(['ma_nv' => $staffId]);
            $staffName = $staff ? $staff['ho_ten'] : '';
        }

        try {
            $source = trim((string)$rowRef);
            $review = $source === 'danh_gia' ? null : $this->db->danh_gia_san_pham->findOne(['ma_danh_gia' => $reviewId]);
            if ($review) {
                $this->db->danh_gia_san_pham->updateOne(
                    ['ma_danh_gia' => $reviewId],
                    ['$set' => [
                        'phan_hoi_shop' => [
                            'noi_dung' => trim($reply),
                            'ngay_phan_hoi' => new \MongoDB\BSON\UTCDateTime(),
                            'ma_nhan_vien' => $staffId > 0 ? $staffId : null,
                            'ten_nhan_vien' => $staffName !== '' ? $staffName : 'SkinSyntax',
                        ],
                        'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                    ]]
                );
                return true;
            }

            $review = $this->db->danh_gia->findOne(['ma_danh_gia' => $reviewId]);
            if (!$review) return false;
            $existingReply = trim((string)($review['phan_hoi'] ?? ''));
            $header = '[' . date('d/m/Y H:i') . ' - ' . ($staffName !== '' ? $staffName : 'Nhân viên') . ']';
            $newEntry = $header . "\n" . trim($reply);
            $finalReply = $existingReply === '' ? $newEntry : ($existingReply . "\n\n--------------------\n" . $newEntry);
            $this->db->danh_gia->updateOne(
                ['ma_danh_gia' => $reviewId],
                ['$set' => [
                    'phan_hoi' => $finalReply,
                    'ma_nv_phan_hoi' => $staffId > 0 ? $staffId : null,
                    'ngay_phan_hoi' => new \MongoDB\BSON\UTCDateTime()
                ]]
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function listChatConversations(bool $pendingOnly = false, ?int $limit = null): array {
        $pipeline = [
            ['$sort' => ['thoi_gian' => -1]],
            ['$group' => [
                '_id' => '$ma_kh',
                'latest_msg' => ['$first' => '$$ROOT']
            ]]
        ];

        if ($pendingOnly) {
            $pipeline[] = ['$match' => ['latest_msg.ma_nv' => null]];
        }

        $pipeline[] = ['$sort' => ['latest_msg.thoi_gian' => -1]];

        if ($limit !== null) {
            $pipeline[] = ['$limit' => max(1, min(50, $limit))];
        }

        $cursor = $this->db->lich_su_chat->aggregate($pipeline);
        $results = [];

        foreach ($cursor as $doc) {
            $latestMsg = (array)$doc['latest_msg'];
            $kh = $this->db->khach_hang->findOne(['ma_kh' => $latestMsg['ma_kh']]);
            
            // Đếm tin chưa phản hồi
            $unansweredCount = $this->db->lich_su_chat->countDocuments([
                'ma_kh' => $latestMsg['ma_kh'],
                'ma_nv' => null
            ]);

            $dateObj = $latestMsg['thoi_gian'] ?? null;
            $timeStr = $dateObj instanceof \MongoDB\BSON\UTCDateTime ? $dateObj->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s') : '';

            $results[] = [
                'ma_kh' => $latestMsg['ma_kh'],
                'ho_ten' => $kh ? $kh['ho_ten'] : '',
                'email' => $kh ? $kh['email'] : '',
                'cap_nhat_cuoi' => $timeStr,
                'tin_nhan_moi' => $latestMsg['noi_dung'],
                'tin_chua_phan_hoi' => $unansweredCount,
                'dang_cho_phan_hoi' => $latestMsg['ma_nv'] === null ? 1 : 0
            ];
        }

        return $results;
    }

    public function getChatMessages(int $maKh): array {
        $options = ['sort' => ['thoi_gian' => 1]];
        $cursor = $this->db->lich_su_chat->find(['ma_kh' => $maKh], $options);
        $items = [];

        foreach ($cursor as $doc) {
            $chat = (array) $doc;
            
            if (!empty($chat['ma_nv'])) {
                $nv = $this->db->nhan_vien->findOne(['ma_nv' => $chat['ma_nv']]);
                $chat['ten_nhan_vien'] = $nv ? $nv['ho_ten'] : '';
            } else {
                $kh = $this->db->khach_hang->findOne(['ma_kh' => $chat['ma_kh']]);
                $chat['ten_khach_hang'] = $kh ? $kh['ho_ten'] : '';
            }

            $dateObj = $chat['thoi_gian'] ?? null;
            $chat['thoi_gian'] = $dateObj instanceof \MongoDB\BSON\UTCDateTime ? $dateObj->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s') : '';
            
            $items[] = $chat;
        }
        return $items;
    }

    public function sendCustomerChat(string $customerEmail, string $content): array {
        $kh = $this->resolveKhachHangByEmail($customerEmail, 'Khach hang');
        if (!$kh || empty($kh['ma_kh'])) {
            return ['ok' => false, 'message' => 'Không xác định được khách hàng để gửi tin nhắn.'];
        }

        if (trim($content) === '') {
            return ['ok' => false, 'message' => 'Tin nhắn không được để trống.'];
        }

        try {
            $this->db->lich_su_chat->insertOne([
                'ma_chat' => $this->getNextNumericId('lich_su_chat', 'ma_chat'),
                'ma_kh' => (int)$kh['ma_kh'],
                'ma_nv' => null,
                'noi_dung' => trim($content),
                'thoi_gian' => new \MongoDB\BSON\UTCDateTime()
            ]);
            return ['ok' => true, 'message' => 'Đã gửi tin nhắn hỗ trợ.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Không thể gửi tin nhắn lúc này.'];
        }
    }

    public function sendStaffChat(int $maKh, int $staffId, string $content): bool {
        if ($maKh <= 0 || $staffId <= 0 || trim($content) === '') return false;

        try {
            $this->db->lich_su_chat->insertOne([
                'ma_chat' => $this->getNextNumericId('lich_su_chat', 'ma_chat'),
                'ma_kh' => $maKh,
                'ma_nv' => $staffId,
                'noi_dung' => trim($content),
                'thoi_gian' => new \MongoDB\BSON\UTCDateTime()
            ]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getUnreadStaffRepliesCount(int $maKh): int {
        $messages = $this->getChatMessages($maKh);
        if (empty($messages)) return 0;

        // Get last read time
        $customer = $this->db->khach_hang->findOne(['ma_kh' => $maKh]);
        $lastReadTime = null;
        if ($customer && isset($customer['last_chat_read'])) {
            $dateObj = $customer['last_chat_read'];
            $lastReadTime = $dateObj instanceof \MongoDB\BSON\UTCDateTime ? $dateObj->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->getTimestamp() : null;
        }

        if ($lastReadTime === null) {
            // No last read, use last customer message logic
            $lastCustomerTime = null;
            foreach (array_reverse($messages) as $msg) {
                if (empty($msg['ma_nv'])) {
                    $lastCustomerTime = strtotime($msg['thoi_gian']);
                    break;
                }
            }
            if ($lastCustomerTime === null) {
                return count(array_filter($messages, fn($m) => !empty($m['ma_nv'])));
            }
            $count = 0;
            foreach ($messages as $msg) {
                if (!empty($msg['ma_nv']) && strtotime($msg['thoi_gian']) > $lastCustomerTime) {
                    $count++;
                }
            }
            return $count;
        }

        // Count staff messages after last read time
        $count = 0;
        foreach ($messages as $msg) {
            if (!empty($msg['ma_nv']) && strtotime($msg['thoi_gian']) > $lastReadTime) {
                $count++;
            }
        }
        return $count;
    }

    public function updateLastChatRead(int $maKh): bool {
        try {
            $this->db->khach_hang->updateOne(
                ['ma_kh' => $maKh],
                ['$set' => ['last_chat_read' => new \MongoDB\BSON\UTCDateTime()]]
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
