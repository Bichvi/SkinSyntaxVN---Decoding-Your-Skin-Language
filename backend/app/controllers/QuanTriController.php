<?php

require_once __DIR__ . '/../models/QuanTri.php';
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/ThongKe.php';

class QuanTriController {
    private PDO $pdo;
    private QuanTri $model;
    private SanPham $sanPhamModel;
    private ThongKe $thongKeModel;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->model = new QuanTri($pdo);
        $this->sanPhamModel = new SanPham($pdo);
        $this->thongKeModel = new ThongKe($pdo);
    }

    private function denyAccess(): void {
        http_response_code(403);
        $user = current_user() ?? [];
        $role = current_role();

        if (in_array($role, ['admin', 'nhanvien'], true)) {
            $this->renderAdmin('403', [
                'pageTitle' => 'Không có quyền truy cập',
                'user' => $user,
            ]);
            exit;
        }

        require __DIR__ . '/../views/layouts/header.php';
        echo '<div class="container py-5"><div class="alert alert-danger">Bạn không có quyền truy cập chức năng này.</div></div>';
        require __DIR__ . '/../views/layouts/footer.php';
        exit;
    }

    private function requireRole(array $roles): array {
        if (!is_logged_in()) {
            set_flash('error', 'Vui lòng đăng nhập để truy cập khu vực quản trị.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $user = current_user() ?? [];
        $role = current_role();
        if (!in_array($role, $roles, true)) {
            $this->denyAccess();
        }

        return $user;
    }

    private function renderAdmin(string $view, array $data = []): void {
        extract($data);
        require __DIR__ . '/../views/admin/layouts/header.php';
        require __DIR__ . '/../views/admin/' . $view . '.php';
        require __DIR__ . '/../views/admin/layouts/footer.php';
    }

    private function renderSite(string $view, array $data = []): void {
        extract($data);
        $menuCats = $this->sanPhamModel->menuTree();
        require __DIR__ . '/../views/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/layouts/footer.php';
    }

    private function redirectBack(string $fallback): void {
        $target = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($target === '') {
            $target = BASE_URL . '/index.php?r=' . $fallback;
        }
        redirect($target);
    }

    public function adminDashboard(): void {
        $this->requireRole(['admin']);

        $summary = $this->model->getDashboardSummary();
        $this->renderAdmin('dashboard', [
            'tongSP' => (int)($summary['tong_san_pham'] ?? 0),
            'tongUser' => (int)($summary['tong_khach_hang'] ?? 0),
            'doanhThu' => (float)($summary['tong_doanh_thu'] ?? 0),
            'donChoXuLy' => (int)($summary['don_cho_xu_ly'] ?? 0),
            'spMoi' => $this->thongKeModel->getSanPhamMoi(5),
            'userMoi' => $this->thongKeModel->getNguoiDungMoi(5),
            'summary' => $summary,
        ]);
    }

    public function adminProducts(): void {
        $this->requireRole(['admin']);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $q = trim((string)($_GET['q'] ?? ''));
        $perPage = 20;
        $res = $this->sanPhamModel->paginate($page, $perPage, $q);

        $this->renderAdmin('danhsachSP', [
            'items' => $res['items'] ?? [],
            'total' => $res['total'] ?? 0,
            'page' => $page,
            'perPage' => $perPage,
            'q' => $q,
        ]);
    }

    public function adminProductCreate(): void {
        $this->requireRole(['admin']);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->renderAdmin('themSP', ['product' => [], 'error' => null]);
            return;
        }

        $controller = new AdminController($this->pdo);
        $controller->create();
    }

    public function adminProductEdit(): void {
        $this->requireRole(['admin']);
        $controller = new AdminController($this->pdo);
        $controller->edit();
    }

    public function adminProductDelete(): void {
        $this->requireRole(['admin']);
        $controller = new AdminController($this->pdo);
        $controller->delete();
    }

    public function adminCategories(): void {
        $this->requireRole(['admin']);
        $q = trim((string)($_GET['q'] ?? ''));
        $editId = max(0, (int)($_GET['edit'] ?? 0));
        $this->renderAdmin('categories', [
            'items' => $this->model->listCategories($q),
            'editing' => $editId > 0 ? $this->model->getCategoryById($editId) : null,
            'q' => $q,
        ]);
    }

    public function adminCategorySave(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_categories');
        }

        $id = max(0, (int)($_POST['ma_danh_muc'] ?? 0));
        $ok = $this->model->saveCategory($_POST, $id > 0 ? $id : null);
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã lưu danh mục.' : 'Không thể lưu danh mục.');
        redirect(BASE_URL . '/index.php?r=admin_categories');
    }

    public function adminCategoryDelete(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_categories');
        }

        $id = max(0, (int)($_POST['ma_danh_muc'] ?? 0));
        $ok = $id > 0 ? $this->model->deleteCategory($id) : false;
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã xóa danh mục.' : 'Không thể xóa danh mục.');
        redirect(BASE_URL . '/index.php?r=admin_categories');
    }

    public function adminUsers(): void {
        $this->requireRole(['admin']);
        $q = trim((string)($_GET['q'] ?? ''));
        $customerEditId = max(0, (int)($_GET['customer_edit'] ?? 0));
        $staffEditId = max(0, (int)($_GET['staff_edit'] ?? 0));

        $this->renderAdmin('users', [
            'customers' => $this->model->listCustomers($q),
            'staffMembers' => $this->model->listStaff($q),
            'roles' => $this->model->listRoles(),
            'customerEditing' => $customerEditId > 0 ? $this->model->getCustomerById($customerEditId) : null,
            'staffEditing' => $staffEditId > 0 ? $this->model->getStaffById($staffEditId) : null,
            'q' => $q,
        ]);
    }

    public function adminCustomerSave(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_users');
        }

        $id = max(0, (int)($_POST['ma_kh'] ?? 0));
        $ok = $this->model->saveCustomer($_POST, $id > 0 ? $id : null);
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã lưu thông tin khách hàng.' : 'Không thể lưu khách hàng.');
        redirect(BASE_URL . '/index.php?r=admin_users');
    }

    public function adminCustomerDelete(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_users');
        }

        $id = max(0, (int)($_POST['ma_kh'] ?? 0));
        $ok = $id > 0 ? $this->model->deleteCustomer($id) : false;
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã xóa khách hàng.' : 'Không thể xóa khách hàng.');
        redirect(BASE_URL . '/index.php?r=admin_users');
    }

    public function adminStaffSave(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_users');
        }

        $id = max(0, (int)($_POST['ma_nv'] ?? 0));
        $ok = $this->model->saveStaff($_POST, $id > 0 ? $id : null);
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã lưu thông tin nhân viên.' : 'Không thể lưu nhân viên.');
        redirect(BASE_URL . '/index.php?r=admin_users');
    }

    public function adminStaffDelete(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_users');
        }

        $id = max(0, (int)($_POST['ma_nv'] ?? 0));
        $ok = $id > 0 ? $this->model->deleteStaff($id) : false;
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã ngừng kích hoạt nhân viên.' : 'Không thể cập nhật nhân viên.');
        redirect(BASE_URL . '/index.php?r=admin_users');
    }

    public function adminOrders(): void {
        $this->requireRole(['admin']);
        $q = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $detailId = max(0, (int)($_GET['detail'] ?? 0));
        $this->renderAdmin('orders', [
            'orders' => $this->model->listOrders($q, $status),
            'orderDetail' => $detailId > 0 ? $this->model->getOrderById($detailId) : null,
            'statusOptions' => $this->model->getOrderStatusOptions(),
            'q' => $q,
            'status' => $status,
            'pageTitle' => 'Quản lý đơn hàng',
            'allowManage' => true,
        ]);
    }

    public function adminOrderStatus(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_orders');
        }

        $id = max(0, (int)($_POST['ma_hoa_don'] ?? 0));
        $status = trim((string)($_POST['trang_thai'] ?? ''));
        $ok = $this->model->updateOrderStatus($id, $status);
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã cập nhật trạng thái đơn hàng.' : 'Không thể cập nhật trạng thái đơn hàng.');
        redirect(BASE_URL . '/index.php?r=admin_orders');
    }

    public function adminReports(): void {
        $this->requireRole(['admin']);
        $this->renderAdmin('reports', [
            'summary' => $this->model->getDashboardSummary(),
            'revenueByMonth' => $this->model->getRevenueByMonth(6),
            'topProducts' => $this->model->getTopProductsByRevenue(8),
        ]);
    }

    public function staffDashboard(): void {
        $user = $this->requireRole(['admin', 'nhanvien']);
        $summary = $this->model->getDashboardSummary();
        $this->renderAdmin('staff_dashboard', [
            'summary' => $summary,
            'user' => $user,
            'pendingOrders' => $this->model->listOrders('', 'Cho xu ly'),
            'conversations' => $this->model->listChatConversations(),
            'reviews' => $this->model->listReviews(''),
        ]);
    }

    public function staffOrders(): void {
        $this->requireRole(['admin', 'nhanvien']);
        $q = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $detailId = max(0, (int)($_GET['detail'] ?? 0));
        $this->renderAdmin('orders', [
            'orders' => $this->model->listOrders($q, $status),
            'orderDetail' => $detailId > 0 ? $this->model->getOrderById($detailId) : null,
            'statusOptions' => $this->model->getOrderStatusOptions(),
            'q' => $q,
            'status' => $status,
            'pageTitle' => 'Xử lý đơn hàng',
            'allowManage' => true,
        ]);
    }

    public function staffOrderStatus(): void {
        $this->requireRole(['admin', 'nhanvien']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=staff_orders');
        }

        $id = max(0, (int)($_POST['ma_hoa_don'] ?? 0));
        $status = trim((string)($_POST['trang_thai'] ?? ''));
        $ok = $this->model->updateOrderStatus($id, $status);
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã cập nhật trạng thái đơn hàng.' : 'Không thể cập nhật trạng thái đơn hàng.');
        redirect(BASE_URL . '/index.php?r=staff_orders');
    }

    public function staffProducts(): void {
        $this->requireRole(['admin', 'nhanvien']);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $q = trim((string)($_GET['q'] ?? ''));
        $perPage = 20;
        $res = $this->sanPhamModel->paginate($page, $perPage, $q);
        $this->renderAdmin('staff_products', [
            'items' => $res['items'] ?? [],
            'total' => $res['total'] ?? 0,
            'page' => $page,
            'perPage' => $perPage,
            'q' => $q,
        ]);
    }

    public function staffProductEdit(): void {
        $this->requireRole(['admin', 'nhanvien']);
        $id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
        if ($id === '') {
            redirect(BASE_URL . '/index.php?r=staff_products');
        }

        $product = $this->sanPhamModel->findById($id);
        if (!$product) {
            set_flash('error', 'Không tìm thấy sản phẩm.');
            redirect(BASE_URL . '/index.php?r=staff_products');
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->renderAdmin('staff_product_edit', ['product' => $product, 'error' => null]);
            return;
        }

        $data = [
            'ten_san_pham' => trim((string)($_POST['ten_san_pham'] ?? '')),
            'gia_ban' => trim((string)($_POST['gia_ban'] ?? '')),
            'dung_tich' => trim((string)($_POST['dung_tich'] ?? '')),
            'loai_da' => trim((string)($_POST['loai_da'] ?? '')),
            'mo_ta' => trim((string)($_POST['mo_ta'] ?? '')),
            'thanh_phan_chinh' => trim((string)($_POST['thanh_phan_chinh'] ?? '')),
            'thanh_phan_day_du' => trim((string)($_POST['thanh_phan_day_du'] ?? '')),
            'hdsd' => trim((string)($_POST['hdsd'] ?? '')),
            'link_hinh_anh' => trim((string)($_POST['link_hinh_anh'] ?? '')),
        ];

        $ok = $this->sanPhamModel->adminUpdate($id, $data);
        if ($ok) {
            set_flash('success', 'Đã cập nhật thông tin sản phẩm.');
            redirect(BASE_URL . '/index.php?r=staff_products');
        }

        $this->renderAdmin('staff_product_edit', [
            'product' => array_merge($product, $data),
            'error' => 'Không thể cập nhật sản phẩm.',
        ]);
    }

    public function staffReviews(): void {
        $this->requireRole(['admin', 'nhanvien']);
        $q = trim((string)($_GET['q'] ?? ''));
        $this->renderAdmin('reviews', [
            'reviews' => $this->model->listReviews($q),
            'q' => $q,
        ]);
    }

    public function staffReviewReply(): void {
        $user = $this->requireRole(['admin', 'nhanvien']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=staff_reviews');
        }

        $reviewId = max(0, (int)($_POST['ma_danh_gia'] ?? 0));
        $reply = trim((string)($_POST['phan_hoi'] ?? ''));
        $staffId = (int)($user['ma_nv'] ?? 0);
        $ok = $this->model->replyReview($reviewId, $staffId, $reply);
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã phản hồi đánh giá.' : 'Không thể phản hồi đánh giá.');
        redirect(BASE_URL . '/index.php?r=staff_reviews');
    }

    public function staffChats(): void {
        $this->requireRole(['admin', 'nhanvien']);
        $conversationId = max(0, (int)($_GET['ma_kh'] ?? 0));
        $this->renderAdmin('chats', [
            'conversations' => $this->model->listChatConversations(),
            'activeConversationId' => $conversationId,
            'messages' => $conversationId > 0 ? $this->model->getChatMessages($conversationId) : [],
        ]);
    }

    public function staffChatSend(): void {
        $user = $this->requireRole(['admin', 'nhanvien']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=staff_chats');
        }

        $maKh = max(0, (int)($_POST['ma_kh'] ?? 0));
        $content = trim((string)($_POST['noi_dung'] ?? ''));
        $staffId = (int)($user['ma_nv'] ?? 0);
        $ok = $this->model->sendStaffChat($maKh, $staffId, $content);
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã gửi phản hồi cho khách hàng.' : 'Không thể gửi phản hồi.');
        redirect(BASE_URL . '/index.php?r=staff_chats&ma_kh=' . $maKh);
    }

    public function customerChat(): void {
        $user = $this->requireRole(['admin', 'nhanvien', 'khach_hang']);
        $customer = $this->model->getCustomerByEmail((string)($user['email'] ?? ''), (string)($user['ho_ten'] ?? ''));
        $maKh = (int)($customer['ma_kh'] ?? 0);

        $this->renderSite('lichsuchat', [
            'messages' => $maKh > 0 ? $this->model->getChatMessages($maKh) : [],
        ]);
    }

    public function customerChatSend(): void {
        $user = $this->requireRole(['khach_hang']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=lichsuchat');
        }

        $result = $this->model->sendCustomerChat((string)($user['email'] ?? ''), trim((string)($_POST['noi_dung'] ?? '')));
        set_flash(!empty($result['ok']) ? 'success' : 'error', (string)($result['message'] ?? 'Không thể gửi tin nhắn.'));
        redirect(BASE_URL . '/index.php?r=lichsuchat');
    }

    public function customerReviewSave(): void {
        $user = $this->requireRole(['khach_hang']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectBack('home');
        }

        $productId = trim((string)($_POST['ma_san_pham'] ?? ''));
        $stars = max(1, min(5, (int)($_POST['so_sao'] ?? 5)));
        $content = trim((string)($_POST['noi_dung'] ?? ''));
        $result = $this->model->createReview((string)($user['email'] ?? ''), $productId, $stars, $content);
        set_flash(!empty($result['ok']) ? 'success' : 'error', (string)($result['message'] ?? 'Không thể gửi đánh giá.'));
        redirect(BASE_URL . '/index.php?r=chitiet&id=' . urlencode($productId));
    }

    public function customerOrderCancel(): void {
        $user = $this->requireRole(['khach_hang']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=hoso');
        }

        $orderId = max(0, (int)($_POST['ma_hoa_don'] ?? 0));
        $detail = $this->model->getOrderById($orderId);
        $email = strtolower(trim((string)($user['email'] ?? '')));
        $ownerEmail = strtolower(trim((string)($detail['email'] ?? '')));
        $currentStatus = strtolower(trim((string)($detail['trang_thai'] ?? '')));

        if (!$detail || $email === '' || $ownerEmail !== $email) {
            set_flash('error', 'Không thể hủy đơn hàng này.');
            redirect(BASE_URL . '/index.php?r=hoso');
        }

        if (in_array($currentStatus, ['dang giao', 'hoan thanh', 'da huy'], true)) {
            set_flash('error', 'Đơn hàng đã ở trạng thái không thể hủy.');
            redirect(BASE_URL . '/index.php?r=hoso');
        }

        $ok = $this->model->updateOrderStatus($orderId, 'Da huy');
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã hủy đơn hàng.' : 'Không thể hủy đơn hàng.');
        redirect(BASE_URL . '/index.php?r=hoso');
    }
}