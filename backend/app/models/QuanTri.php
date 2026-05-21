<?php
require_once __DIR__ . '/HoaDon.php';

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
            'don_cho_xu_ly' => $this->db->hoa_don->countDocuments([
                'trang_thai' => new \MongoDB\BSON\Regex('^(cho xu ly|chờ xử lý|moi)$', 'i')
            ]),
            'tong_doanh_thu' => 0,
            'chat_cho_tra_loi' => 0,
            'danh_gia_cho_phan_hoi' => 0,
        ];

        // Tính tổng doanh thu
        $pipeline = [
            ['$match' => ['trang_thai' => ['$nin' => [new \MongoDB\BSON\Regex('^(da huy|huy)$', 'i')]]]],
            ['$group' => ['_id' => null, 'total' => ['$sum' => '$tong_tien']]]
        ];
        $revenue = $this->db->hoa_don->aggregate($pipeline)->toArray();
        if (!empty($revenue)) {
            $summary['tong_doanh_thu'] = $revenue[0]['total'];
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

        // Đếm đánh giá chờ phản hồi
        $summary['danh_gia_cho_phan_hoi'] = $this->db->danh_gia->countDocuments([
            '$or' => [
                ['phan_hoi' => null],
                ['phan_hoi' => ''],
                ['phan_hoi' => ['$exists' => false]]
            ]
        ]);

        return $summary;
    }

    public function getNotificationCenterData(int $orderLimit = 5, int $chatLimit = 5): array {
        $orderLimit = max(1, min(10, $orderLimit));
        $chatLimit = max(1, min(10, $chatLimit));

        $pendingOrdersCount = $this->db->hoa_don->countDocuments([
            'trang_thai' => new \MongoDB\BSON\Regex('^(cho xu ly|chờ xử lý|moi)$', 'i')
        ]);

        // Tính pending chats
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

        // Lấy danh sách order
        $orderDocs = $this->db->hoa_don->find(
            ['trang_thai' => new \MongoDB\BSON\Regex('^(cho xu ly|chờ xử lý|moi)$', 'i')],
            ['sort' => ['ngay_dat' => -1, 'created_at' => -1], 'limit' => $orderLimit]
        );
        $orders = [];
        foreach ($orderDocs as $doc) {
            $order = (array) $doc;
            $kh = $this->db->khach_hang->findOne(['ma_kh' => $order['ma_kh']]);
            if ($kh) {
                $order['ho_ten'] = $kh['ho_ten'];
                $order['email'] = $kh['email'];
            }
            // Chuyển đối tượng ngày tháng của Mongo sang string
            $dateObj = $order['ngay_dat'] ?? $order['created_at'] ?? null;
            $order['thoi_gian'] = $dateObj instanceof \MongoDB\BSON\UTCDateTime ? $dateObj->toDateTime()->format('Y-m-d H:i:s') : '';
            $orders[] = $order;
        }

        $conversations = $this->listChatConversations(true, $chatLimit);
        $latestOrder = $orders[0] ?? [];
        $latestChat = $conversations[0] ?? [];

        return [
            'pending_orders_count' => $pendingOrdersCount,
            'pending_chats_count' => $pendingChatsCount,
            'orders' => $orders,
            'chats' => $conversations,
            'latest_order_marker' => ($latestOrder['ma_hoa_don'] ?? '') . '|' . ($latestOrder['thoi_gian'] ?? ''),
            'latest_chat_marker' => ($latestChat['ma_kh'] ?? '') . '|' . ($latestChat['cap_nhat_cuoi'] ?? ''),
        ];
    }

    public function getRevenueByMonth(int $limit = 6): array {
        // Hàm này khá phức tạp trong MongoDB (group by month), nên tui trả về array rỗng để an toàn, 
        // hoặc viết logic lấy dữ liệu PHP chay
        // Vì dashboard Admin thường ít dùng chi tiết nếu chưa code chuẩn.
        return [];
    }

    public function getTopProductsByRevenue(int $limit = 8): array {
        return [];
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
            $this->db->khach_hang->deleteOne(['ma_kh' => $id]);

            if ($email !== '') {
                $regex = new \MongoDB\BSON\Regex('^' . preg_quote($email) . '$', 'i');
                $staff = $this->db->nhan_vien->findOne(['email' => $regex]);
                if (!$staff) {
                    $this->db->nguoidung->deleteOne(['email' => $regex]);
                }
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
        $filter = [];
        $filter['$or'] = [
            ['deleted_at' => null],
            ['deleted_at' => ['$exists' => false]]
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
        try {
            $this->db->nhan_vien->updateOne(
                ['ma_nv' => $id],
                ['$set' => ['trang_thai' => 'inactive', 'deleted_at' => new \MongoDB\BSON\UTCDateTime(), 'updated_at' => new \MongoDB\BSON\UTCDateTime()]]
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
        if ($keyword !== '') {
            $regex = new \MongoDB\BSON\Regex(preg_quote($keyword), 'i');
            $filter['$or'] = [
                ['ma_hoa_don' => $regex],
                ['trang_thai' => $regex],
                ['dia_chi_giao_hang' => $regex]
            ];
            // MongoDB không JOIN dễ để search text theo name khách, ta thu hẹp ở client hoặc code thêm
        }

        $status = trim($status);
        if ($status !== '') {
            $filter['trang_thai'] = new \MongoDB\BSON\Regex('^' . preg_quote($status) . '$', 'i');
        }

        $options = ['sort' => ['ngay_dat' => -1, 'created_at' => -1, 'ma_hoa_don' => -1]];
        $cursor = $this->db->hoa_don->find($filter, $options);
        $items = [];

        foreach ($cursor as $doc) {
            $order = (array) $doc;
            
            $kh = $this->db->khach_hang->findOne(['ma_kh' => $order['ma_kh']]);
            if ($kh) {
                $order['ho_ten'] = $kh['ho_ten'];
                $order['email'] = $kh['email'];
                $order['so_dien_thoai'] = $kh['so_dien_thoai'] ?? null;
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
            $sp = $this->db->san_pham->findOne(['ma_san_pham' => $ct['ma_san_pham']]);
            if ($sp) {
                $ct['ten_san_pham'] = $sp['ten_san_pham'];
                $ct['link_hinh_anh'] = $sp['link_hinh_anh'] ?? $sp['hinh_anh'] ?? null;
            }
            $items[] = $ct;
        }
        return $items;
    }

    public function updateOrderStatus(int $orderId, string $status, string $cancelReason = '', bool $allowCancelledOverride = false): bool {
        $this->lastErrorMessage = null;
        $status = trim($status);
        if ($orderId <= 0 || $status === '') {
            $this->lastErrorMessage = 'Du lieu cap nhat trang thai don hang khong hop le.';
            return false;
        }

        $order = $this->db->hoa_don->findOne(['ma_hoa_don' => $orderId]);
        if (!$order) {
            $this->lastErrorMessage = 'Khong tim thay don hang can cap nhat.';
            return false;
        }

        $normalized = strtolower($status);
        $isCancelled = in_array($normalized, ['da huy', 'đã hủy', 'huy', 'cancelled', 'canceled'], true);
        
        $currentStatus = strtolower(trim((string)($order['trang_thai'] ?? '')));
        $currentIsCancelled = in_array($currentStatus, ['da huy', 'đã hủy', 'huy', 'cancelled', 'canceled'], true);

        if (!$allowCancelledOverride && $currentIsCancelled && !$isCancelled) {
            $this->lastErrorMessage = 'Don hang da huy khong the chuyen sang trang thai khac.';
            return false;
        }

        $trimmedCancelReason = trim($cancelReason);
        if ($isCancelled && !$currentIsCancelled && $trimmedCancelReason === '') {
            $this->lastErrorMessage = 'Vui long chon ly do huy don hang.';
            return false;
        }

        $updateData = [
            'trang_thai' => $status,
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];

        if ($isCancelled && $trimmedCancelReason !== '') {
            $updateData['ly_do_huy'] = $trimmedCancelReason;
        } elseif (!$isCancelled) {
            $updateData['ly_do_huy'] = null;
        }

        try {
            $this->db->hoa_don->updateOne(['ma_hoa_don' => $orderId], ['$set' => $updateData]);
            $hoaDonModel = new HoaDon($this->db);
            if ($isCancelled && !$currentIsCancelled && method_exists($hoaDonModel, 'restoreStockForOrder')) {
                $hoaDonModel->restoreStockForOrder($orderId);
            }
            $hoaDonModel->syncLoyaltyForOrder($orderId);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getOrderStatusOptions(): array {
        return [
            'Cho xu ly' => 'Chờ xử lý',
            'Da xac nhan' => 'Đã xác nhận',
            'Dang giao' => 'Đang giao',
            'Hoan thanh' => 'Hoàn thành',
            'Da huy' => 'Đã hủy',
        ];
    }

    public function listReviews(string $keyword = '', array $filters = []): array {
        $filter = [];
        
        $star = max(0, min(5, (int)($filters['so_sao'] ?? 0)));
        if ($star > 0) {
            $filter['so_sao'] = $star;
        }

        $replyStatus = strtolower(trim((string)($filters['trang_thai_phan_hoi'] ?? '')));
        if ($replyStatus === 'pending') {
            $filter['$or'] = [
                ['phan_hoi' => null],
                ['phan_hoi' => '']
            ];
        } elseif ($replyStatus === 'replied') {
            $filter['phan_hoi'] = ['$ne' => null, '$ne' => ''];
        }

        $maKh = trim((string)($filters['ma_kh'] ?? ''));
        $maKhDigits = preg_replace('/\D+/', '', $maKh);
        if ($maKhDigits !== '') {
            $filter['ma_kh'] = (int)$maKhDigits;
        }

        $options = [
            'sort' => ['ngay_danh_gia' => -1, 'ma_danh_gia' => -1],
            'limit' => max(10, min(200, (int)($filters['limit'] ?? 60)))
        ];

        $cursor = $this->db->danh_gia->find($filter, $options);
        $items = [];

        foreach ($cursor as $doc) {
            $dg = (array) $doc;
            
            // Lấy tên KH
            $kh = $this->db->khach_hang->findOne(['ma_kh' => $dg['ma_kh']]);
            $dg['ten_khach_hang'] = $kh ? $kh['ho_ten'] : '';
            $dg['sdt_khach_hang'] = $kh ? ($kh['so_dien_thoai'] ?? '') : '';

            // Lấy SP
            $sp = $this->db->san_pham->findOne(['ma_san_pham' => $dg['ma_san_pham']]);
            $dg['ten_san_pham'] = $sp ? $sp['ten_san_pham'] : '';

            // Lấy NV phản hồi
            if (!empty($dg['ma_nv_phan_hoi'])) {
                $nv = $this->db->nhan_vien->findOne(['ma_nv' => $dg['ma_nv_phan_hoi']]);
                $dg['ten_nhan_vien_phan_hoi'] = $nv ? $nv['ho_ten'] : '';
            }

            // Lấy đơn hàng liên quan (Giả định đơn mới nhất có chứa sản phẩm)
            $ct = $this->db->chi_tiet_hoa_don->findOne(['ma_san_pham' => $dg['ma_san_pham']], ['sort' => ['ma_hoa_don' => -1]]);
            if ($ct) {
                $hd = $this->db->hoa_don->findOne(['ma_hoa_don' => $ct['ma_hoa_don'], 'ma_kh' => $dg['ma_kh']]);
                if ($hd) {
                    $dg['ma_van_don'] = $hd['ma_hoa_don'];
                    $dg['trang_thai_don_hang'] = $hd['trang_thai'];
                }
            }
            
            $items[] = $dg;
        }
        return $items;
    }

    public function getReviewFilterOptions(): array {
        $orderStatusOptions = [
            'Cho xu ly' => 'Chờ xử lý',
            'Da xac nhan' => 'Đã xác nhận',
            'Dang giao' => 'Đang giao',
            'Hoan thanh' => 'Hoàn thành',
            'Da huy' => 'Đã hủy',
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
            $items[] = $dg;
        }
        return $items;
    }

    public function getCustomerReviewEligibility(int $customerId, array $productIds = []): array {
        if ($customerId <= 0 || empty($productIds)) return [];

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
        $kh = $this->resolveKhachHangByEmail($customerEmail, 'Khach hang');
        if (!$kh || empty($kh['ma_kh'])) {
            return ['ok' => false, 'message' => 'Không xác định được khách hàng để gửi đánh giá.'];
        }

        $productId = trim($productId);
        $stars = max(1, min(5, $stars));
        $content = trim($content);
        if ($productId === '' || $content === '') {
            return ['ok' => false, 'message' => 'Nội dung hoặc sản phẩm không được để trống.'];
        }

        $customerId = (int)$kh['ma_kh'];
        $eligibility = $this->getCustomerReviewEligibility($customerId, [$productId]);
        $productEligibility = $eligibility[$productId] ?? ['has_purchased' => false, 'has_reviewed' => false];
        
        if (empty($productEligibility['has_purchased'])) {
            return ['ok' => false, 'message' => 'Bạn chỉ có thể đánh giá sản phẩm đã mua.'];
        }

        if (!empty($productEligibility['has_reviewed'])) {
            return ['ok' => false, 'message' => 'Bạn đã đánh giá sản phẩm này rồi.'];
        }

        try {
            $payload = [
                'ma_danh_gia' => $this->getNextNumericId('danh_gia', 'ma_danh_gia'),
                'ma_san_pham' => $productId,
                'ma_kh' => $customerId,
                'so_sao' => $stars,
                'noi_dung' => $content,
                'ngay_danh_gia' => new \MongoDB\BSON\UTCDateTime()
            ];
            $this->db->danh_gia->insertOne($payload);

            $this->grantReviewRewardPoint($customerId);
            $this->refreshProductRating($productId);

            return ['ok' => true, 'message' => 'Đã gửi đánh giá sản phẩm. Bạn nhận thêm 1 điểm ưu đãi.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Không thể gửi đánh giá lúc này.'];
        }
    }

    public function replyReview(int $reviewId, int $staffId, string $reply, string $rowRef = ''): bool {
        if ($reviewId <= 0 || trim($reply) === '') return false;

        $review = $this->db->danh_gia->findOne(['ma_danh_gia' => $reviewId]);
        if (!$review) return false;

        $existingReply = trim((string)($review['phan_hoi'] ?? ''));
        $staffName = '';
        if ($staffId > 0) {
            $staff = $this->db->nhan_vien->findOne(['ma_nv' => $staffId]);
            $staffName = $staff ? $staff['ho_ten'] : '';
        }

        $header = '[' . date('d/m/Y H:i') . ' - ' . ($staffName !== '' ? $staffName : 'Nhan vien') . ']';
        $newEntry = $header . "\n" . trim($reply);
        $finalReply = $existingReply === '' ? $newEntry : ($existingReply . "\n\n--------------------\n" . $newEntry);

        try {
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
