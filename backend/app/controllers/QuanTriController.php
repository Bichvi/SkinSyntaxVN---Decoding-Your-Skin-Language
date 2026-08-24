<?php

require_once __DIR__ . '/../models/QuanTri.php';
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/ThongKe.php';
require_once __DIR__ . '/../models/Voucher.php';
require_once __DIR__ . '/../models/DanhGia.php';
require_once __DIR__ . '/../models/HoiDap.php';

class QuanTriController {
    private  $pdo;
    private QuanTri $model;
    private SanPham $sanPhamModel;
    private ThongKe $thongKeModel;
    private Voucher $voucherModel;

    public function __construct($pdo) {
        global $db;
        $mongoDb = $db ?? (is_object($pdo) && method_exists($pdo, 'raw') ? $pdo->raw() : $pdo);
        $this->pdo = $pdo;
        $this->model = new QuanTri($mongoDb);
        $this->sanPhamModel = new SanPham($mongoDb);
        $this->thongKeModel = new ThongKe($mongoDb);
        $this->voucherModel = new Voucher($mongoDb);
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

        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require $viewDir . '/layouts/header.php';
        echo '<div class="container py-5"><div class="alert alert-danger">Bạn không có quyền truy cập chức năng này.</div></div>';
        require $viewDir . '/layouts/footer.php';
        exit;
    }

    private function requireRole(array $roles): array {
        if (!is_logged_in()) {
            set_flash('error', 'Vui lòng đăng nhập để truy cập khu vực quản trị.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $user = current_user() ?? [];
        $staffId = (int)($user['ma_nv'] ?? 0);
        if ($staffId > 0 && method_exists($this->model, 'isStaffAccountActive') && !$this->model->{'isStaffAccountActive'}($staffId)) {
            unset($_SESSION['user'], $_SESSION['admin_notifications_seen']);
            set_flash('error', 'Tài khoản nhân viên đã bị tạm khóa hoặc ngừng hoạt động.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $role = current_role();
        if (!in_array($role, $roles, true)) {
            $this->denyAccess();
        }

        return $user;
    }

    private function renderAdmin(string $view, array $data = []): void {
        $data['notificationCenter'] = $data['notificationCenter'] ?? $this->buildNotificationCenter();
        extract($data);
        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require $viewDir . '/admin/layouts/header.php';
        require $viewDir . '/admin/' . $view . '.php';
        require $viewDir . '/admin/layouts/footer.php';
    }

    private function buildNotificationCenter(): array {
        $center = $this->model->getNotificationCenterData();
        $seen = $_SESSION['admin_notifications_seen'] ?? [];
        $currentOrderMarker = (string)($center['latest_order_marker'] ?? '');
        $currentChatMarker = (string)($center['latest_chat_marker'] ?? '');
        $hasNewOrders = $currentOrderMarker !== '' && $currentOrderMarker !== (string)($seen['latest_order_marker'] ?? '');
        $hasNewChats = $currentChatMarker !== '' && $currentChatMarker !== (string)($seen['latest_chat_marker'] ?? '');

        $center['has_new_orders'] = $hasNewOrders;
        $center['has_new_chats'] = $hasNewChats;
        $center['unseen_count'] = (int)($center['unread_order_notifications_count'] ?? 0)
            + ($hasNewChats ? (int)($center['pending_chats_count'] ?? 0) : 0);

        return $center;
    }

    private function renderSite(string $view, array $data = []): void {
        extract($data);
        $menuCats = $this->sanPhamModel->menuTree();
        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require $viewDir . '/layouts/header.php';
        require $viewDir . '/' . $view . '.php';
        require $viewDir . '/layouts/footer.php';
    }

    private function isAjaxRequest(): bool {
        $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    private function respondJson(array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function buildStaffChatPayload(int $conversationId): array {
        return [
            'activeConversationId' => $conversationId,
            'pendingConversations' => $this->model->listChatConversations(true),
            'allConversations' => $this->model->listChatConversations(false),
            'messages' => $conversationId > 0 ? $this->model->getChatMessages($conversationId) : [],
        ];
    }

    private function countWords(string $text): int {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0;
        }

        $parts = preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? count($parts) : 0;
    }

    private function redirectBack(string $fallback): void {
        $target = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        if ($target === '') {
            $target = BASE_URL . '/index.php?r=' . $fallback;
        }
        redirect($target);
    }

    private function cancellationReasonOptions(): array {
        return [
            'Khach_doi_y' => 'Khách đổi ý',
            'Dat_sai_san_pham' => 'Đặt sai sản phẩm',
            'Tre_giao_hang' => 'Giao hàng chậm hơn dự kiến',
            'Khong_lien_he_duoc' => 'Không liên hệ được để xác nhận đơn',
            'Het_hang' => 'Hết hàng',
            'Khac' => 'Lý do khác',
        ];
    }

    private function isCancelledStatus(string $status): bool {
        return method_exists($this->model, 'normalizeOrderStatus')
            && $this->model->normalizeOrderStatus($status) === 'cancelled';
    }
    private function extractCancellationReasonFromPost(): array {
        $options = $this->cancellationReasonOptions();
        $reasonKey = trim((string)($_POST['ly_do_huy'] ?? ''));
        $extraNote = trim((string)($_POST['ly_do_huy_bo_sung'] ?? ''));

        if ($reasonKey === '' || !isset($options[$reasonKey])) {
            return ['ok' => false, 'message' => 'Vui lòng chọn lý do hủy đơn hàng.'];
        }

        if ($reasonKey === 'Khac' && $extraNote === '') {
            return ['ok' => false, 'message' => 'Vui lòng nhập lý do cụ thể khi chọn "Lý do khác".'];
        }

        $reasonText = $options[$reasonKey];
        if ($reasonKey === 'Khac') {
            $reasonText .= ': ' . $extraNote;
        } elseif ($extraNote !== '') {
            $reasonText .= ' - Ghi chú: ' . $extraNote;
        }

        return ['ok' => true, 'reason' => $reasonText];
    }

    private function handleProductUpload(string $inputName = 'hinh_anh'): ?string {
        if (empty($_FILES[$inputName]) || !is_array($_FILES[$inputName])) {
            return null;
        }

        $file = $_FILES[$inputName];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return null;
        }

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($ext, $allowed, true)) {
            return null;
        }

        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/products';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $fileName = uniqid('sp_', true) . '.' . $ext;
        $target = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file((string)($file['tmp_name'] ?? ''), $target)) {
            return null;
        }

        return $fileName;
    }

    private function handleReviewImageUploads(string $inputName = 'hinh_anh'): array {
        if (empty($_FILES[$inputName]) || !is_array($_FILES[$inputName])) {
            return [];
        }

        $files = $_FILES[$inputName];
        $names = is_array($files['name'] ?? null) ? $files['name'] : [$files['name'] ?? ''];
        $tmpNames = is_array($files['tmp_name'] ?? null) ? $files['tmp_name'] : [$files['tmp_name'] ?? ''];
        $errors = is_array($files['error'] ?? null) ? $files['error'] : [$files['error'] ?? UPLOAD_ERR_NO_FILE];
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/reviews';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $paths = [];
        foreach ($names as $index => $name) {
            if (($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                continue;
            }
            $fileName = uniqid('rv_', true) . '.' . $ext;
            $target = $uploadDir . '/' . $fileName;
            if (move_uploaded_file((string)($tmpNames[$index] ?? ''), $target)) {
                $paths[] = 'uploads/reviews/' . $fileName;
            }
        }
        return $paths;
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
            'lowStockProducts' => $this->model->getLowStockProducts(5, 6),
            'summary' => $summary,
        ]);
    }

    public function adminProducts(): void {
        $this->requireRole(['admin']);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $q = trim((string)($_GET['q'] ?? ''));
        $status = strtolower(trim((string)($_GET['status'] ?? '')));
        $stockStatus = strtolower(trim((string)($_GET['stock_status'] ?? '')));
        $perPage = 20;
        $res = $this->sanPhamModel->paginate($page, $perPage, $q, '', '', $status, false, $stockStatus);

        $this->renderAdmin('danhsachSP', [
            'items' => $res['items'] ?? [],
            'total' => $res['total'] ?? 0,
            'page' => $page,
            'perPage' => $perPage,
            'q' => $q,
            'status' => $status,
            'stockStatus' => $stockStatus,
        ]);
    }

    public function adminProductCreate(): void {
        $this->requireRole(['admin']);
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

    public function adminProductVisibility(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_sp');
        }

        $id = trim((string)($_POST['id'] ?? ''));
        $status = strtolower(trim((string)($_POST['status'] ?? 'active')));
        $ok = $this->sanPhamModel->updateProductVisibility($id, $status);
        $errorMessage = method_exists($this->sanPhamModel, 'getLastErrorMessage')
            ? ((string)($this->sanPhamModel->getLastErrorMessage() ?? '') ?: 'Không thể cập nhật trạng thái sản phẩm.')
            : 'Không thể cập nhật trạng thái sản phẩm.';
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã cập nhật trạng thái hiển thị sản phẩm.' : $errorMessage);

        $query = http_build_query([
            'r' => 'admin_sp',
            'q' => trim((string)($_POST['q'] ?? '')),
            'status' => trim((string)($_POST['status_filter'] ?? '')),
            'stock_status' => trim((string)($_POST['stock_status_filter'] ?? '')),
            'page' => max(1, (int)($_POST['page'] ?? 1)),
        ]);
        redirect(BASE_URL . '/index.php?' . $query);
    }

    public function adminProductStock(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_sp');
        }

        $id = trim((string)($_POST['id'] ?? ''));
        $stock = max(0, (int)($_POST['so_luong_ton_kho'] ?? 0));
        $ok = $id !== '' && $this->sanPhamModel->updateInventory($id, $stock);
        $errorMessage = method_exists($this->sanPhamModel, 'getLastErrorMessage')
            ? ((string)($this->sanPhamModel->getLastErrorMessage() ?? '') ?: 'Không thể cập nhật tồn kho sản phẩm.')
            : 'Không thể cập nhật tồn kho sản phẩm.';
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã cập nhật tồn kho sản phẩm.' : $errorMessage);

        $query = http_build_query([
            'r' => 'admin_sp',
            'q' => trim((string)($_POST['q'] ?? '')),
            'status' => trim((string)($_POST['status_filter'] ?? '')),
            'stock_status' => trim((string)($_POST['stock_status_filter'] ?? '')),
            'page' => max(1, (int)($_POST['page'] ?? 1)),
        ]);
        redirect(BASE_URL . '/index.php?' . $query);
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

    public function adminVouchers(): void {
        $this->requireRole(['admin']);
        $q = trim((string)($_GET['q'] ?? ''));
        $editId = max(0, (int)($_GET['edit'] ?? 0));
        $this->renderAdmin('vouchers', [
            'items' => $this->voucherModel->listVouchers($q),
            'editing' => $editId > 0 ? $this->voucherModel->getVoucherById($editId) : null,
            'q' => $q,
        ]);
    }

    public function adminVoucherSave(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_vouchers');
        }

        $id = max(0, (int)($_POST['ma_voucher'] ?? 0));
        $ok = $this->voucherModel->saveVoucher($_POST, $id > 0 ? $id : null);
        $message = $ok
            ? 'Đã lưu voucher.'
            : ((string)($this->voucherModel->getLastErrorMessage() ?? 'Không thể lưu voucher.'));
        set_flash($ok ? 'success' : 'error', $message);
        redirect(BASE_URL . '/index.php?r=admin_vouchers');
    }

    public function adminVoucherDelete(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_vouchers');
        }

        $id = max(0, (int)($_POST['ma_voucher'] ?? 0));
        $ok = $id > 0 ? $this->voucherModel->deleteVoucher($id) : false;
        $message = $ok
            ? 'Đã xóa voucher.'
            : ((string)($this->voucherModel->getLastErrorMessage() ?? 'Không thể xóa voucher.'));
        set_flash($ok ? 'success' : 'error', $message);
        redirect(BASE_URL . '/index.php?r=admin_vouchers');
    }

    public function adminCategorySave(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_categories');
        }

        $id = max(0, (int)($_POST['ma_danh_muc'] ?? 0));
        $ok = $this->model->saveCategory($_POST, $id > 0 ? $id : null);
        $errorMessage = method_exists($this->model, 'getLastErrorMessage')
            ? ((string)($this->model->getLastErrorMessage() ?? '') ?: 'Không thể lưu danh mục.')
            : 'Không thể lưu danh mục.';
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã lưu danh mục.' : $errorMessage);
        redirect(BASE_URL . '/index.php?r=admin_categories');
    }

    public function adminCategoryDelete(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_categories');
        }

        $id = max(0, (int)($_POST['ma_danh_muc'] ?? 0));
        $deleteProducts = (int)($_POST['delete_products'] ?? 0) === 1;
        $ok = $id > 0 ? $this->model->deleteCategory($id, $deleteProducts) : false;
        $errorMessage = method_exists($this->model, 'getLastErrorMessage')
            ? (($this->model->{'getLastErrorMessage'}() ?: 'Không thể xóa danh mục.'))
            : 'Không thể xóa danh mục.';
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã xóa danh mục.' : $errorMessage);
        redirect(BASE_URL . '/index.php?r=admin_categories');
    }

    public function adminUsers(): void {
        $this->requireRole(['admin']);
        $q = trim((string)($_GET['q'] ?? ''));
        $loaiKh = trim((string)($_GET['loai_kh'] ?? ''));
        $customerEditId = max(0, (int)($_GET['customer_edit'] ?? 0));
        $staffEditId = max(0, (int)($_GET['staff_edit'] ?? 0));

        $this->renderAdmin('users', [
            'customers' => $this->model->listCustomers($q, $loaiKh),
            'staffMembers' => $this->model->listStaff($q),
            'roles' => $this->model->listRoles(),
            'customerEditing' => $customerEditId > 0 ? $this->model->getCustomerById($customerEditId) : null,
            'staffEditing' => $staffEditId > 0 ? $this->model->getStaffById($staffEditId) : null,
            'q' => $q,
            'loaiKh' => $loaiKh,
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
        $errorMessage = method_exists($this->model, 'getLastErrorMessage')
            ? (($this->model->{'getLastErrorMessage'}() ?: 'Không thể lưu nhân viên.'))
            : 'Không thể lưu nhân viên.';
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã lưu thông tin nhân viên.' : $errorMessage);
        redirect(BASE_URL . '/index.php?r=admin_users');
    }

    public function adminStaffDelete(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_users');
        }

        $id = max(0, (int)($_POST['ma_nv'] ?? 0));
        $ok = $id > 0 ? $this->model->deleteStaff($id) : false;
        $errorMessage = method_exists($this->model, 'getLastErrorMessage')
            ? (($this->model->{'getLastErrorMessage'}() ?: 'Không thể cập nhật nhân viên.'))
            : 'Không thể cập nhật nhân viên.';
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã cập nhật trạng thái hoạt động của nhân viên.' : $errorMessage);
        redirect(BASE_URL . '/index.php?r=admin_users');
    }

    public function adminStaffHardDelete(): void {
        $user = $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_users');
        }

        $id = max(0, (int)($_POST['ma_nv'] ?? 0));
        $currentStaffId = (int)($user['ma_nv'] ?? 0);

        if ($currentStaffId > 0 && $currentStaffId === $id) {
            set_flash('error', 'Không thể tự xóa chính tài khoản admin đang đăng nhập.');
            redirect(BASE_URL . '/index.php?r=admin_users');
        }

        $ok = $id > 0 && method_exists($this->model, 'hardDeleteStaff')
            ? $this->model->{'hardDeleteStaff'}($id)
            : false;
        $errorMessage = method_exists($this->model, 'getLastErrorMessage')
            ? (($this->model->{'getLastErrorMessage'}() ?: 'Không thể xóa nhân viên.'))
            : 'Không thể xóa nhân viên.';
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã xóa nhân viên khỏi hệ thống.' : $errorMessage);
        redirect(BASE_URL . '/index.php?r=admin_users');
    }

    private function handleOrderPrintRequest(string $route): void {
        $invoiceId = isset($_GET['print_invoice']) ? max(0, (int)$_GET['print_invoice']) : 0;
        $deliveryId = isset($_GET['print_delivery']) ? max(0, (int)$_GET['print_delivery']) : 0;
        if ($invoiceId <= 0 && $deliveryId <= 0) {
            return;
        }

        $orderId = $invoiceId > 0 ? $invoiceId : $deliveryId;
        $documentType = $invoiceId > 0 ? 'invoice' : 'delivery';
        $order = $this->model->getOrderById($orderId);
        if (!$order) {
            set_flash('error', 'Không tìm thấy đơn hàng cần in.');
            redirect(BASE_URL . '/index.php?r=' . $route);
        }

        $status = (string)($order['trang_thai_normalized'] ?? '');
        if ($status === '' && method_exists($this->model, 'normalizeOrderStatus')) {
            $status = $this->model->normalizeOrderStatus($order['trang_thai'] ?? '');
        }

        if (!in_array($status, ['confirmed', 'shipping', 'completed'], true)) {
            set_flash('error', 'Không thể xuất hóa đơn cho đơn hàng chưa xác nhận hoặc đã bị hủy.');
            redirect(BASE_URL . '/index.php?r=' . $route . '&detail=' . $orderId);
        }

        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require $viewDir . '/admin/order_print.php';
        exit;
    }

    public function adminOrders(): void {
        $this->requireRole(['admin']);
        $this->handleOrderPrintRequest('admin_orders');
        $q = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $detailId = max(0, (int)($_GET['detail'] ?? 0));

        if (isset($_GET['export']) && in_array((string)$_GET['export'], ['excel', 'csv'], true)) {
            $this->exportAdminOrders($q, $status);
            return;
        }

        $this->renderAdmin('orders', [
            'orders' => $this->model->listOrders($q, $status),
            'orderDetail' => $detailId > 0 ? $this->model->getOrderById($detailId) : null,
            'statusOptions' => $this->model->getOrderStatusOptions(),
            'q' => $q,
            'status' => $status,
            'pageTitle' => 'Quản lý đơn hàng',
            'allowManage' => true,
            'cancelReasonOptions' => $this->cancellationReasonOptions(),
        ]);
    }

    private function exportAdminOrders(string $q = '', string $status = ''): void {
        $orders = $this->model->listOrders($q, $status);
        $rows = [
            ['Mã đơn', 'Khách hàng', 'Email', 'Số điện thoại', 'Ngày đặt', 'Hình thức thanh toán', 'Trạng thái thanh toán', 'Trạng thái đơn hàng', 'Tổng tiền (VND)', 'Địa chỉ giao hàng']
        ];
        foreach ($orders as $order) {
            $rows[] = [
                '#' . ($order['ma_hoa_don'] ?? ''),
                $order['ho_ten'] ?? 'Khách hàng',
                $order['email'] ?? '',
                $order['so_dien_thoai'] ?? '',
                $order['ngay_dat_hien_thi'] ?? ($order['ngay_dat'] ?? ''),
                strtolower(trim((string)($order['hinh_thuc_thanh_toan'] ?? 'cod'))) === 'bank_transfer_qr' ? 'QR chuyển khoản' : 'COD',
                $order['status_thanh_toan'] ?? 'Chưa thanh toán',
                $order['trang_thai_hien_thi'] ?? ($order['trang_thai'] ?? 'Chờ xử lý'),
                (int)($order['tong_tien'] ?? 0),
                $order['dia_chi_giao_hang'] ?? '',
            ];
        }
        $filenameBase = 'danh_sach_don_hang_' . date('Ymd_His');
        if (class_exists('ZipArchive')) {
            $this->sendXlsxReport($filenameBase . '.xlsx', [
                'Danh_sach_don_hang' => $rows
            ]);
            return;
        }
        $this->sendCsvReport($filenameBase . '.csv', $rows);
    }

    public function adminOrderStatus(): void {
        $this->requireRole(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_orders');
        }

        $id = max(0, (int)($_POST['ma_hoa_don'] ?? 0));
        $status = trim((string)($_POST['trang_thai'] ?? ''));
        $cancelReason = '';
        if ($this->isCancelledStatus($status)) {
            $payload = $this->extractCancellationReasonFromPost();
            if (empty($payload['ok'])) {
                set_flash('error', (string)($payload['message'] ?? 'Vui lòng chọn lý do hủy đơn hàng.'));
                redirect(BASE_URL . '/index.php?r=admin_orders&detail=' . $id);
            }
            $cancelReason = (string)($payload['reason'] ?? '');
        }

        $ok = $this->model->updateOrderStatus($id, $status, $cancelReason, true);
        $errorMessage = method_exists($this->model, 'getLastErrorMessage')
            ? ((string)($this->model->getLastErrorMessage() ?? '') ?: 'Không thể cập nhật trạng thái đơn hàng.')
            : 'Không thể cập nhật trạng thái đơn hàng.';
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã cập nhật trạng thái đơn hàng.' : $errorMessage);
        redirect(BASE_URL . '/index.php?r=admin_orders');
    }

    public function adminReports(): void {
        $this->requireRole(['admin']);
        $filters = $this->parseReportDateFilters();
        if (!empty($filters['error'])) {
            set_flash('error', (string)$filters['error']);
        }

        if (isset($_GET['export']) && (string)$_GET['export'] === 'excel') {
            if (!empty($filters['error'])) {
                redirect(BASE_URL . '/index.php?r=admin_reports');
            }
            $this->exportAdminReports($filters['start_date'], $filters['end_date']);
            return;
        }

        $this->renderAdmin('reports', [
            'summary' => $this->model->getDashboardSummary(),
            'revenueByMonth' => empty($filters['error']) ? $this->model->getRevenueByMonth(120, $filters['start_date'], $filters['end_date']) : [],
            'topProducts' => empty($filters['error']) ? $this->model->getTopProductsByRevenue(8, $filters['start_date'], $filters['end_date']) : [],
            'reportStartDate' => $filters['start_date'],
            'reportEndDate' => $filters['end_date'],
            'reportError' => $filters['error'],
        ]);
    }

    private function parseReportDateFilters(): array {
        $start = trim((string)($_GET['start_date'] ?? ''));
        $end = trim((string)($_GET['end_date'] ?? ''));
        $error = '';

        $isDate = static function (string $value): bool {
            if ($value === '') {
                return true;
            }
            $dt = DateTime::createFromFormat('Y-m-d', $value);
            return $dt instanceof DateTime && $dt->format('Y-m-d') === $value;
        };

        if (!$isDate($start) || !$isDate($end)) {
            $error = 'Ngày lọc báo cáo không hợp lệ.';
        } elseif ($start !== '' && $end !== '' && strtotime($end) < strtotime($start)) {
            $error = 'Khoảng thời gian chọn không hợp lệ.';
        }

        return [
            'start_date' => $start,
            'end_date' => $end,
            'error' => $error,
        ];
    }

    private function exportAdminReports(string $startDate = '', string $endDate = ''): void {
        $revenueByMonth = $this->model->getRevenueByMonth(120, $startDate, $endDate);
        $topProducts = $this->model->getTopProductsByRevenue(30, $startDate, $endDate);

        $totalRevenue = 0;
        $totalOrders = 0;
        $bestMonth = null;
        foreach ($revenueByMonth as $row) {
            $totalRevenue += (int)($row['doanh_thu'] ?? 0);
            $totalOrders += (int)($row['so_don'] ?? 0);
            if ($bestMonth === null || (int)($row['doanh_thu'] ?? 0) > (int)($bestMonth['doanh_thu'] ?? 0)) {
                $bestMonth = $row;
            }
        }

        $averageRevenue = !empty($revenueByMonth) ? (int)round($totalRevenue / count($revenueByMonth)) : 0;
        $topProduct = $topProducts[0] ?? [];
        $overviewRows = [
            ['Chỉ tiêu', 'Giá trị'],
            ['Từ ngày', $startDate !== '' ? $startDate : 'Toàn bộ dữ liệu'],
            ['Đến ngày', $endDate !== '' ? $endDate : 'Toàn bộ dữ liệu'],
            ['Doanh thu trong kỳ', $totalRevenue],
            ['Số đơn hợp lệ trong kỳ', $totalOrders],
            ['Trung bình doanh thu/tháng', $averageRevenue],
            ['Tháng doanh thu cao nhất', $bestMonth['thang'] ?? 'Chưa có dữ liệu'],
            ['Doanh thu tháng cao nhất', (int)($bestMonth['doanh_thu'] ?? 0)],
            ['Sản phẩm dẫn đầu', $topProduct['ten_san_pham'] ?? 'Chưa có dữ liệu'],
        ];
        $monthlyRows = [['Tháng', 'Số đơn', 'Doanh thu']];
        foreach ($revenueByMonth as $row) {
            $monthlyRows[] = [
                $row['thang'] ?? '',
                (int)($row['so_don'] ?? 0),
                (int)($row['doanh_thu'] ?? 0),
            ];
        }
        $productRows = [['Mã sản phẩm', 'Tên sản phẩm', 'Số lượng bán', 'Doanh thu']];
        foreach ($topProducts as $row) {
            $productRows[] = [
                $row['ma_san_pham'] ?? '',
                $row['ten_san_pham'] ?? '',
                (int)($row['so_don_vi'] ?? 0),
                (int)($row['doanh_thu'] ?? 0),
            ];
        }

        $filenameBase = 'bao_cao_doanh_thu_' . date('Ymd_His');
        if (class_exists('ZipArchive')) {
            $this->sendXlsxReport($filenameBase . '.xlsx', [
                'Tong_quan' => $overviewRows,
                'Doanh_thu_theo_thang' => $monthlyRows,
                'Top_san_pham' => $productRows,
            ]);
            return;
        }

        $this->sendCsvReport($filenameBase . '.csv', [
            ['Tong_quan'],
            ...$overviewRows,
            [],
            ['Doanh_thu_theo_thang'],
            ...$monthlyRows,
            [],
            ['Top_san_pham'],
            ...$productRows,
        ]);
    }

    private function sendCsvReport(string $filename, array $rows): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        foreach ($rows as $row) {
            fputcsv($out, array_map('strval', $row));
        }
        fclose($out);
        exit;
    }

    private function sendXlsxReport(string $filename, array $sheets): void {
        $tmp = tempnam(sys_get_temp_dir(), 'skinsyntax_report_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->xlsxRootRels());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbookXml(array_keys($sheets)));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRels(count($sheets)));
        $zip->addFromString('xl/styles.xml', $this->xlsxStylesXml());
        $index = 1;
        foreach ($sheets as $rows) {
            $zip->addFromString('xl/worksheets/sheet' . $index . '.xml', $this->xlsxSheetXml($rows));
            $index++;
        }
        $zip->close();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    private function xlsxContentTypes(int $sheetCount): string {
        $sheetOverrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $sheetOverrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $sheetOverrides . '</Types>';
    }

    private function xlsxRootRels(): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function xlsxWorkbookXml(array $sheetNames): string {
        $sheets = '';
        foreach (array_values($sheetNames) as $i => $name) {
            $sheets .= '<sheet name="' . htmlspecialchars($name, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            . $sheets . '</sheets></workbook>';
    }

    private function xlsxWorkbookRels(int $sheetCount): string {
        $rels = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    private function xlsxStylesXml(): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '</styleSheet>';
    }

    private function xlsxSheetXml(array $rows): string {
        $xmlRows = '';
        foreach (array_values($rows) as $rIndex => $row) {
            $rowNum = $rIndex + 1;
            $cells = '';
            foreach (array_values($row) as $cIndex => $value) {
                $cellRef = $this->xlsxColumnName($cIndex + 1) . $rowNum;
                $style = $rowNum === 1 ? ' s="1"' : '';
                if (is_int($value) || is_float($value)) {
                    $cells .= '<c r="' . $cellRef . '"' . $style . '><v>' . $value . '</v></c>';
                } else {
                    $text = htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $cells .= '<c r="' . $cellRef . '" t="inlineStr"' . $style . '><is><t>' . $text . '</t></is></c>';
                }
            }
            $xmlRows .= '<row r="' . $rowNum . '">' . $cells . '</row>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . $xmlRows . '</sheetData></worksheet>';
    }

    private function xlsxColumnName(int $index): string {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }
        return $name;
    }

    public function staffDashboard(): void {
        $user = $this->requireRole(['admin', 'nhanvien']);
        $summary = $this->model->getDashboardSummary();
        global $db;
        $hoiDapModel = new HoiDap($db ?? $this->pdo);
        $questions = [];
        try {
            $questions = $hoiDapModel->listAdminQuestions('pending', '', 6);
        } catch (Throwable $e) {}

        $this->renderAdmin('staff_dashboard', [
            'summary' => $summary,
            'user' => $user,
            'pendingOrders' => $this->model->listOrders('', 'pending'),
            'conversations' => $this->model->listChatConversations(true, 20),
            'reviews' => $this->model->listReviews(''),
            'questions' => $questions,
        ]);
    }

    public function staffOrders(): void {
        $this->requireRole(['admin', 'nhanvien']);
        $this->handleOrderPrintRequest('staff_orders');
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
            'cancelReasonOptions' => $this->cancellationReasonOptions(),
        ]);
    }

    public function staffOrderStatus(): void {
        $this->requireRole(['admin', 'nhanvien']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=staff_orders');
        }

        $id = max(0, (int)($_POST['ma_hoa_don'] ?? 0));
        $status = trim((string)($_POST['trang_thai'] ?? ''));
        $cancelReason = '';
        if ($this->isCancelledStatus($status)) {
            $payload = $this->extractCancellationReasonFromPost();
            if (empty($payload['ok'])) {
                set_flash('error', (string)($payload['message'] ?? 'Vui lòng chọn lý do hủy đơn hàng.'));
                redirect(BASE_URL . '/index.php?r=staff_orders&detail=' . $id);
            }
            $cancelReason = (string)($payload['reason'] ?? '');
        }

        $ok = $this->model->updateOrderStatus($id, $status, $cancelReason, false);
        $errorMessage = method_exists($this->model, 'getLastErrorMessage')
            ? ((string)($this->model->getLastErrorMessage() ?? '') ?: 'Không thể cập nhật trạng thái đơn hàng.')
            : 'Không thể cập nhật trạng thái đơn hàng.';
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã cập nhật trạng thái đơn hàng.' : $errorMessage);
        redirect(BASE_URL . '/index.php?r=staff_orders');
    }

    public function staffProducts(): void {
        $this->requireRole(['admin', 'nhanvien']);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $q = trim((string)($_GET['q'] ?? ''));
        $status = strtolower(trim((string)($_GET['status'] ?? '')));
        $perPage = 20;
        $res = $this->sanPhamModel->paginate($page, $perPage, $q, '', '', $status, false);
        $this->renderAdmin('staff_products', [
            'items' => $res['items'] ?? [],
            'total' => $res['total'] ?? 0,
            'page' => $page,
            'perPage' => $perPage,
            'q' => $q,
            'status' => $status,
        ]);
    }

    public function staffProductCreate(): void {
        $this->requireRole(['admin', 'nhanvien']);

        $brandOptions = $this->sanPhamModel->listBrandOptions();
        $categoryOptions = $this->sanPhamModel->listCategoryOptions();
        $nextProductCode = $this->sanPhamModel->getNextProductCode();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->renderAdmin('themSP', [
                'product' => ['ma_san_pham' => $nextProductCode, 'trang_thai' => 'active'],
                'error' => null,
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
                'nextProductCode' => $nextProductCode,
                'formAction' => 'staff_product_create',
                'backRoute' => 'staff_products',
            ]);
            return;
        }

        $data = [
            'ma_san_pham' => trim((string)($_POST['ma_san_pham'] ?? '')),
            'ten_san_pham' => trim((string)($_POST['ten_san_pham'] ?? '')),
            'ma_thuong_hieu' => trim((string)($_POST['ma_thuong_hieu'] ?? '')),
            'ma_danh_muc' => trim((string)($_POST['ma_danh_muc'] ?? '')),
            'ten_thuong_hieu_input' => trim((string)($_POST['ten_thuong_hieu_input'] ?? '')),
            'ten_danh_muc_input' => trim((string)($_POST['ten_danh_muc_input'] ?? '')),
            'gia_ban' => trim((string)($_POST['gia_ban'] ?? '')),
            'gia_thi_truong' => trim((string)($_POST['gia_thi_truong'] ?? '')),
            'dung_tich' => trim((string)($_POST['dung_tich'] ?? '')),
            'loai_da' => trim((string)($_POST['loai_da'] ?? '')),
            'mo_ta' => trim((string)($_POST['mo_ta'] ?? '')),
            'thanh_phan_chinh' => trim((string)($_POST['thanh_phan_chinh'] ?? '')),
            'thanh_phan_day_du' => trim((string)($_POST['thanh_phan_day_du'] ?? '')),
            'hdsd' => trim((string)($_POST['hdsd'] ?? '')),
            'link_hinh_anh' => trim((string)($_POST['link_hinh_anh'] ?? '')),
            'trang_thai' => trim((string)($_POST['trang_thai'] ?? 'active')),
        ];

        if ($data['ma_san_pham'] === '') {
            $data['ma_san_pham'] = $nextProductCode;
        }

        if ($data['ma_thuong_hieu'] === '' && $data['ten_thuong_hieu_input'] !== '') {
            $brandId = $this->sanPhamModel->ensureBrandByName($data['ten_thuong_hieu_input']);
            if ($brandId !== null) {
                $data['ma_thuong_hieu'] = (string)$brandId;
                $brandOptions = $this->sanPhamModel->listBrandOptions();
            }
        }

        if ($data['ma_danh_muc'] === '' && $data['ten_danh_muc_input'] !== '') {
            $categoryId = $this->sanPhamModel->ensureCategoryByName($data['ten_danh_muc_input']);
            if ($categoryId !== null) {
                $data['ma_danh_muc'] = (string)$categoryId;
                $categoryOptions = $this->sanPhamModel->listCategoryOptions();
            }
        }

        if ($data['ma_san_pham'] === '' || $data['ten_san_pham'] === '') {
            $this->renderAdmin('themSP', [
                'product' => $data,
                'error' => 'Mã sản phẩm và tên sản phẩm là bắt buộc.',
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
                'nextProductCode' => $nextProductCode,
                'formAction' => 'staff_product_create',
                'backRoute' => 'staff_products',
            ]);
            return;
        }

        $giaBan = trim((string)($data['gia_ban'] ?? ''));
        if ($giaBan === '' || !is_numeric($giaBan) || (float)$giaBan <= 0) {
            $this->renderAdmin('themSP', [
                'product' => $data,
                'error' => 'Giá bán phải lớn hơn 0.',
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
                'nextProductCode' => $nextProductCode,
                'formAction' => 'staff_product_create',
                'backRoute' => 'staff_products',
            ]);
            return;
        }

        $giaThiTruong = trim((string)($data['gia_thi_truong'] ?? ''));
        if ($giaThiTruong !== '' && (!is_numeric($giaThiTruong) || (float)$giaThiTruong <= 0)) {
            $this->renderAdmin('themSP', [
                'product' => $data,
                'error' => 'Giá thị trường phải lớn hơn 0 nếu được nhập.',
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
                'nextProductCode' => $nextProductCode,
                'formAction' => 'staff_product_create',
                'backRoute' => 'staff_products',
            ]);
            return;
        }

        if ($this->sanPhamModel->hasProductCode($data['ma_san_pham'])) {
            $data['ma_san_pham'] = $this->sanPhamModel->getNextProductCode();
            $nextProductCode = $data['ma_san_pham'];
        }

        if ($this->sanPhamModel->hasProductName($data['ten_san_pham'])) {
            $this->renderAdmin('themSP', [
                'product' => $data,
                'error' => 'Tên sản phẩm đã tồn tại. Vui lòng nhập tên khác.',
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
                'nextProductCode' => $nextProductCode,
                'formAction' => 'staff_product_create',
                'backRoute' => 'staff_products',
            ]);
            return;
        }

        $uploadedFileName = $this->handleProductUpload('hinh_anh');
        if ($uploadedFileName !== null) {
            $data['link_hinh_anh'] = $uploadedFileName;
        }

        $ok = $this->sanPhamModel->adminInsert($data);
        if ($ok) {
            set_flash('success', 'Đã thêm sản phẩm thành công.');
            redirect(BASE_URL . '/index.php?r=staff_products');
        }

        $errorMessage = method_exists($this->sanPhamModel, 'getLastErrorMessage')
            ? ((string)($this->sanPhamModel->getLastErrorMessage() ?? '') ?: 'Không thể thêm sản phẩm. Vui lòng thử lại.')
            : 'Không thể thêm sản phẩm. Vui lòng thử lại.';

        $this->renderAdmin('themSP', [
            'product' => $data,
            'error' => $errorMessage,
            'brandOptions' => $brandOptions,
            'categoryOptions' => $categoryOptions,
            'nextProductCode' => $nextProductCode,
            'formAction' => 'staff_product_create',
            'backRoute' => 'staff_products',
        ]);
    }

    public function staffProductVisibility(): void {
        $this->requireRole(['admin', 'nhanvien']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=staff_products');
        }

        $id = trim((string)($_POST['id'] ?? ''));
        $status = strtolower(trim((string)($_POST['status'] ?? 'active')));
        $ok = $this->sanPhamModel->updateProductVisibility($id, $status);
        $errorMessage = method_exists($this->sanPhamModel, 'getLastErrorMessage')
            ? ((string)($this->sanPhamModel->getLastErrorMessage() ?? '') ?: 'Không thể cập nhật trạng thái sản phẩm.')
            : 'Không thể cập nhật trạng thái sản phẩm.';
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã cập nhật trạng thái hiển thị sản phẩm.' : $errorMessage);

        $query = http_build_query([
            'r' => 'staff_products',
            'q' => trim((string)($_POST['q'] ?? '')),
            'status' => trim((string)($_POST['status_filter'] ?? '')),
            'page' => max(1, (int)($_POST['page'] ?? 1)),
        ]);
        redirect(BASE_URL . '/index.php?' . $query);
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

        $brandOptions = $this->sanPhamModel->listBrandOptions();
        $categoryOptions = $this->sanPhamModel->listCategoryOptions();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->renderAdmin('staff_product_edit', [
                'product' => $product,
                'error' => null,
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
            ]);
            return;
        }

        $data = [
            'ten_san_pham' => trim((string)($_POST['ten_san_pham'] ?? '')),
            'ma_thuong_hieu' => trim((string)($_POST['ma_thuong_hieu'] ?? '')),
            'ma_danh_muc' => trim((string)($_POST['ma_danh_muc'] ?? '')),
            'gia_ban' => trim((string)($_POST['gia_ban'] ?? '')),
            'dung_tich' => trim((string)($_POST['dung_tich'] ?? '')),
            'loai_da' => trim((string)($_POST['loai_da'] ?? '')),
            'mo_ta' => trim((string)($_POST['mo_ta'] ?? '')),
            'thanh_phan_chinh' => trim((string)($_POST['thanh_phan_chinh'] ?? '')),
            'thanh_phan_day_du' => trim((string)($_POST['thanh_phan_day_du'] ?? '')),
            'hdsd' => trim((string)($_POST['hdsd'] ?? '')),
            'link_hinh_anh' => trim((string)($_POST['link_hinh_anh'] ?? '')),
            'trang_thai' => trim((string)($_POST['trang_thai'] ?? 'active')),
        ];

        $uploadedFileName = $this->handleProductUpload('hinh_anh');
        if ($uploadedFileName !== null) {
            $data['link_hinh_anh'] = $uploadedFileName;
        } elseif ($data['link_hinh_anh'] === '') {
            $data['link_hinh_anh'] = (string)($product['link_hinh_anh'] ?? '');
        }

        $ok = $this->sanPhamModel->adminUpdate($id, $data);
        if ($ok) {
            set_flash('success', 'Đã cập nhật thông tin sản phẩm.');
            redirect(BASE_URL . '/index.php?r=staff_products');
        }

        $errorMessage = method_exists($this->sanPhamModel, 'getLastErrorMessage')
            ? ((string)($this->sanPhamModel->getLastErrorMessage() ?? '') ?: 'Không thể cập nhật sản phẩm.')
            : 'Không thể cập nhật sản phẩm.';

        $this->renderAdmin('staff_product_edit', [
            'product' => array_merge($product, $data),
            'error' => $errorMessage,
            'brandOptions' => $brandOptions,
            'categoryOptions' => $categoryOptions,
        ]);
    }

    public function staffReviews(): void {
        $this->requireRole(['admin', 'nhanvien']);
        $q = trim((string)($_GET['q'] ?? ''));
        $filters = [
            'so_sao' => max(0, min(5, (int)($_GET['so_sao'] ?? 0))),
            'trang_thai_phan_hoi' => trim((string)($_GET['trang_thai_phan_hoi'] ?? '')),
            'trang_thai_don' => trim((string)($_GET['trang_thai_don'] ?? '')),
            'khoang_ngay' => trim((string)($_GET['khoang_ngay'] ?? '')),
            'ma_kh' => trim((string)($_GET['ma_kh'] ?? '')),
            'ma_van_don' => trim((string)($_GET['ma_van_don'] ?? '')),
            'sdt_khach_hang' => trim((string)($_GET['sdt_khach_hang'] ?? '')),
            'limit' => max(10, min(200, (int)($_GET['limit'] ?? 60))),
        ];

        $reviews = $this->model->listReviews($q, $filters);
        $selectedReviewId = max(0, (int)($_GET['detail'] ?? 0));
        $selectedReview = null;
        foreach ($reviews as $review) {
            if ((int)($review['ma_danh_gia'] ?? 0) === $selectedReviewId) {
                $selectedReview = $review;
                break;
            }
        }
        if ($selectedReview === null && !empty($reviews)) {
            $selectedReview = $reviews[0];
            $selectedReviewId = (int)($selectedReview['ma_danh_gia'] ?? 0);
        }

        $this->renderAdmin('reviews', [
            'reviews' => $reviews,
            'selectedReview' => $selectedReview,
            'selectedReviewId' => $selectedReviewId,
            'filters' => $filters,
            'filterOptions' => $this->model->getReviewFilterOptions(),
            'q' => $q,
        ]);
    }

    public function staffReviewReply(): void {
        $user = $this->requireRole(['admin', 'nhanvien']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=staff_reviews');
        }

        $reviewId = max(0, (int)($_POST['ma_danh_gia'] ?? 0));
        $rowRef = trim((string)($_POST['row_ref'] ?? ''));
        $reply = trim((string)($_POST['phan_hoi'] ?? ''));
        $wordCount = $this->countWords($reply);

        if ($wordCount > 1000) {
            set_flash('error', 'Nội dung phản hồi không được vượt quá 1000 từ.');
            $returnQuery = trim((string)($_POST['return_query'] ?? ''));
            if ($returnQuery !== '') {
                redirect(BASE_URL . '/index.php?r=staff_reviews&' . $returnQuery);
            }
            redirect(BASE_URL . '/index.php?r=staff_reviews' . ($reviewId > 0 ? ('&detail=' . $reviewId) : ''));
        }

        $staffId = (int)($user['ma_nv'] ?? 0);
        $ok = $this->model->replyReview($reviewId, $staffId, $reply, $rowRef);
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã phản hồi đánh giá.' : 'Không thể phản hồi đánh giá.');

        $returnQuery = trim((string)($_POST['return_query'] ?? ''));
        if ($returnQuery !== '') {
            parse_str($returnQuery, $parsed);
            $allowed = ['q', 'so_sao', 'trang_thai_phan_hoi', 'trang_thai_don', 'khoang_ngay', 'ma_kh', 'ma_van_don', 'sdt_khach_hang', 'limit', 'detail'];
            $safe = ['r' => 'staff_reviews'];
            foreach ($allowed as $key) {
                if (array_key_exists($key, $parsed) && !is_array($parsed[$key])) {
                    $safe[$key] = (string)$parsed[$key];
                }
            }
            $query = http_build_query($safe);
            redirect(BASE_URL . '/index.php?' . $query);
        }

        redirect(BASE_URL . '/index.php?r=staff_reviews' . ($reviewId > 0 ? ('&detail=' . $reviewId) : ''));
    }

    public function staffChats(): void {
        $this->requireRole(['admin', 'nhanvien']);
        $conversationId = max(0, (int)($_GET['ma_kh'] ?? 0));
        $this->renderAdmin('chats', $this->buildStaffChatPayload($conversationId));
    }

    public function staffChatState(): void {
        $this->requireRole(['admin', 'nhanvien']);
        $conversationId = max(0, (int)($_GET['ma_kh'] ?? 0));
        $this->respondJson([
            'ok' => true,
            'data' => $this->buildStaffChatPayload($conversationId),
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

        if ($this->isAjaxRequest()) {
            $this->respondJson([
                'ok' => $ok,
                'message' => $ok ? 'Đã gửi phản hồi cho khách hàng.' : 'Không thể gửi phản hồi.',
                'data' => $this->buildStaffChatPayload($maKh),
            ], $ok ? 200 : 422);
        }

        set_flash($ok ? 'success' : 'error', $ok ? 'Đã gửi phản hồi cho khách hàng.' : 'Không thể gửi phản hồi.');
        redirect(BASE_URL . '/index.php?r=staff_chats&ma_kh=' . $maKh);
    }

    public function adminQuestions(): void {
        $this->requireRole(['admin', 'nhanvien']);
        $status = trim((string)($_GET['status'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));
        global $db;
        $model = new HoiDap($db ?? $this->pdo);
        $questions = [];
        $error = '';
        try {
            $questions = $model->listAdminQuestions($status, $q, 150);
        } catch (Throwable $e) {
            error_log('admin questions MongoDB error: ' . $e->getMessage());
            $error = 'Hiện chưa thể tải danh sách hỏi đáp. Vui lòng thử lại sau.';
        }
        $this->renderAdmin('questions', [
            'questions' => $questions,
            'status' => $status,
            'q' => $q,
            'error' => $error,
        ]);
    }

    public function adminQuestionReply(): void {
        $user = $this->requireRole(['admin', 'nhanvien']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_questions');
        }
        $questionId = max(0, (int)($_POST['ma_hoi_dap'] ?? 0));
        $answer = trim((string)($_POST['tra_loi'] ?? ''));
        global $db;
        try {
            $ok = (new HoiDap($db ?? $this->pdo))->answerQuestion(
                $questionId,
                (int)($user['ma_nv'] ?? 0),
                $answer,
                (string)($user['ho_ten'] ?? 'SkinSyntax')
            );
        } catch (Throwable $e) {
            error_log('admin question reply error: ' . $e->getMessage());
            $ok = false;
        }
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã trả lời câu hỏi.' : 'Không thể trả lời câu hỏi.');
        redirect(BASE_URL . '/index.php?r=admin_questions');
    }

    public function adminQuestionHide(): void {
        $this->requireRole(['admin', 'nhanvien']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=admin_questions');
        }
        $questionId = max(0, (int)($_POST['ma_hoi_dap'] ?? 0));
        global $db;
        try {
            $ok = (new HoiDap($db ?? $this->pdo))->hideQuestion($questionId);
        } catch (Throwable $e) {
            error_log('admin question hide error: ' . $e->getMessage());
            $ok = false;
        }
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã ẩn câu hỏi.' : 'Không thể ẩn câu hỏi.');
        redirect(BASE_URL . '/index.php?r=admin_questions');
    }

    public function markNotificationsSeen(): void {
        $this->requireRole(['admin', 'nhanvien']);
        $center = $this->model->getNotificationCenterData();
        if (method_exists($this->model, 'markOrderNotificationsRead')) {
            $this->model->markOrderNotificationsRead();
        }
        $_SESSION['admin_notifications_seen'] = [
            'latest_order_marker' => (string)($center['latest_order_marker'] ?? ''),
            'latest_chat_marker' => (string)($center['latest_chat_marker'] ?? ''),
            'seen_at' => date('c'),
        ];

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    public function customerChat(): void {
        $user = $this->requireRole(['khach_hang']);
        $email = (string)($user['email'] ?? '');
        $messages = [];
        
        if ($email !== '') {
            $customer = $this->model->getCustomerByEmail($email, (string)($user['ho_ten'] ?? ''));
            $customerId = (int)($customer['ma_kh'] ?? 0);
            if ($customerId > 0) {
                $messages = $this->model->getChatMessages($customerId);
            }
        }
        
        $this->renderSite('lichsuchat', [
            'messages' => $messages,
            'pageTitle' => 'Chat với nhân viên hỗ trợ',
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

    public function markChatRead(): void {
        $user = $this->requireRole(['khach_hang']);
        $customer = $this->model->getCustomerByEmail((string)($user['email'] ?? ''), (string)($user['ho_ten'] ?? ''));
        $maKh = (int)($customer['ma_kh'] ?? 0);
        if ($maKh > 0) {
            $this->model->updateLastChatRead($maKh);
        }
        // Return JSON response
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    public function customerReviewSave(): void {
        $user = $this->requireRole(['khach_hang']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectBack('home');
        }

        $productId = trim((string)($_POST['ma_san_pham'] ?? ''));
        $stars = max(1, min(5, (int)($_POST['so_sao'] ?? 5)));
        $content = trim((string)($_POST['noi_dung'] ?? ''));
        try {
            $reviewModel = new DanhGia($this->pdo);
            $customer = $reviewModel->resolveCustomerByEmail((string)($user['email'] ?? ''), (string)($user['ho_ten'] ?? 'Khách hàng'));
            $result = $reviewModel->addReview($productId, (int)($customer['ma_kh'] ?? 0), [
                'so_sao' => $stars,
                'noi_dung' => $content,
                'hinh_anh' => $this->handleReviewImageUploads('hinh_anh'),
                'ma_hoa_don' => trim((string)($_POST['ma_hoa_don'] ?? $_POST['order_id'] ?? '')),
                'ma_chi_tiet_hoa_don' => trim((string)($_POST['ma_chi_tiet_hoa_don'] ?? $_POST['order_item_id'] ?? '')),
            ]);
        } catch (Throwable $e) {
            error_log('customer review MongoDB error: ' . $e->getMessage());
            $result = ['ok' => false, 'message' => 'Hiện chưa thể gửi đánh giá. Vui lòng thử lại sau.'];
        }
        set_flash(!empty($result['ok']) ? 'success' : 'error', (string)($result['message'] ?? 'Không thể gửi đánh giá.'));
        redirect(BASE_URL . '/index.php?r=chitiet&id=' . urlencode($productId) . '&tab=danh-gia');
    }

    public function customerQuestionSave(): void {
        $user = $this->requireRole(['khach_hang']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirectBack('home');
        }

        $productId = trim((string)($_POST['ma_san_pham'] ?? ''));
        try {
            $questionModel = new HoiDap($this->pdo);
            $customer = $questionModel->resolveCustomerByEmail((string)($user['email'] ?? ''), (string)($user['ho_ten'] ?? 'Khách hàng'));
            $result = $questionModel->addQuestion($productId, (int)($customer['ma_kh'] ?? 0), [
                'cau_hoi' => trim((string)($_POST['cau_hoi'] ?? '')),
            ]);
        } catch (Throwable $e) {
            error_log('customer question MongoDB error: ' . $e->getMessage());
            $result = ['ok' => false, 'message' => 'Hiện chưa thể gửi câu hỏi. Vui lòng thử lại sau.'];
        }

        set_flash(!empty($result['ok']) ? 'success' : 'error', (string)($result['message'] ?? 'Không thể gửi câu hỏi.'));
        redirect(BASE_URL . '/index.php?r=chitiet&id=' . urlencode($productId) . '&tab=hoi-dap');
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
        $currentStatus = method_exists($this->model, 'normalizeOrderStatus')
            ? $this->model->normalizeOrderStatus($detail['trang_thai'] ?? '')
            : strtolower(trim((string)($detail['trang_thai'] ?? '')));

        if (!$detail || $email === '' || $ownerEmail !== $email) {
            set_flash('error', 'Không thể hủy đơn hàng này.');
            redirect(BASE_URL . '/index.php?r=hoso');
        }

        if (in_array($currentStatus, ['shipping', 'completed', 'cancelled'], true)) {
            set_flash('error', 'Đơn hàng đã ở trạng thái không thể hủy.');
            redirect(BASE_URL . '/index.php?r=hoso');
        }

        $payload = $this->extractCancellationReasonFromPost();
        if (empty($payload['ok'])) {
            set_flash('error', (string)($payload['message'] ?? 'Vui lòng chọn lý do hủy đơn hàng.'));
            redirect(BASE_URL . '/index.php?r=hoso');
        }

        $ok = $this->model->updateOrderStatus($orderId, 'cancelled', (string)($payload['reason'] ?? ''));
        set_flash($ok ? 'success' : 'error', $ok ? 'Đã hủy đơn hàng.' : 'Không thể hủy đơn hàng.');
        redirect(BASE_URL . '/index.php?r=hoso');
    }

    public function adminLives(): void {
        $user = $this->requireRole(['admin', 'nhanvien']);
        if (!user_can_access_route('admin_lives')) {
            $this->denyAccess();
        }

        require_once __DIR__ . '/../models/PhienLive.php';
        $phienLiveModel = new PhienLive($this->pdo);
        $lives = $phienLiveModel->getAllLives();
        $paginateRes = $this->sanPhamModel->paginate(1, 2000);
        $allProducts = $paginateRes['items'] ?? [];


        foreach ($lives as &$live) {
            $st = (string)($live['trang_thai'] ?? 'chuamoi');
            if ($st === 'tamdung' || $st === 'paused') {
                $st = 'ketthuc';
                $live['trang_thai'] = 'ketthuc';
            }
            if (!empty($live['ma_san_pham_ghim'])) {
                $p = $this->sanPhamModel->findById($live['ma_san_pham_ghim']);
                if ($p) {
                    $live['pinned_product'] = $p;
                }
            }
            $live['luot_xem'] = (int)($live['luot_xem'] ?? 0);
            $live['tong_doanh_thu'] = (float)($live['tong_doanh_thu'] ?? 0);
            $live['tong_don_hang'] = (int)($live['tong_don_hang'] ?? 0);
            $live['tong_san_pham_ban'] = (int)($live['tong_san_pham_ban'] ?? 0);
            $live['created_at'] = (string)($live['created_at'] ?? $live['khung_gio_bat_dau'] ?? date('Y-m-d H:i:s'));
        }
        unset($live);

        $this->renderAdmin('lives', [
            'pageTitle' => 'Quản lý Phiên LiveStream AI & Ưu Đãi Khung Giờ',
            'user' => $user,
            'lives' => $lives,
            'allProducts' => $allProducts
        ]);
    }

    public function adminLiveCreate(): void {
        $user = $this->requireRole(['admin', 'nhanvien']);
        if (!user_can_access_route('admin_lives')) {
            $this->denyAccess();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_once __DIR__ . '/../models/PhienLive.php';
            $phienLiveModel = new PhienLive($this->pdo);
            $ok = $phienLiveModel->taoPhienLive($_POST);
            set_flash($ok ? 'success' : 'error', $ok ? 'Đã tạo phiên LiveStream mới thành công!' : 'Không thể tạo phiên LiveStream.');
        }

        redirect(BASE_URL . '/index.php?r=admin_lives');
    }

    public function adminLiveEdit(): void {
        $user = $this->requireRole(['admin', 'nhanvien']);
        if (!user_can_access_route('admin_lives')) {
            $this->denyAccess();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id = trim((string)($_POST['live_id'] ?? $_POST['id'] ?? ''));
            if ($id !== '') {
                require_once __DIR__ . '/../models/PhienLive.php';
                $phienLiveModel = new PhienLive($this->pdo);
                $ok = $phienLiveModel->capNhatPhienLive($id, $_POST);
                set_flash($ok ? 'success' : 'error', $ok ? "Đã cập nhật thông tin phiên LiveStream #{$id} thành công!" : "Không thể cập nhật phiên LiveStream #{$id}.");
            }
        }

        $redirectUrl = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php?r=admin_lives');
        redirect($redirectUrl);
    }

    public function adminLiveStatus(): void {
        $user = $this->requireRole(['admin', 'nhanvien']);
        if (!user_can_access_route('admin_lives')) {
            $this->denyAccess();
        }

        $id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
        $status = trim((string)($_GET['status'] ?? $_POST['status'] ?? 'danglive'));

        if ($id !== '') {
            require_once __DIR__ . '/../models/PhienLive.php';
            $phienLiveModel = new PhienLive($this->pdo);

            $currentDoc = $phienLiveModel->findById($id);
            $currentStatus = (string)($currentDoc['trang_thai'] ?? '');

            if ($currentStatus === 'ketthuc' && $status !== 'ketthuc') {
                set_flash('error', '🚫 Phiên LiveStream này đã KẾT THÚC vĩnh viễn và lưu bản ghi. Không thể khởi động lại!');
                $redirectUrl = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php?r=admin_lives');
                redirect($redirectUrl);
                return;
            }

            $ok = $phienLiveModel->doiTrangThai($id, $status);

            $msg = 'Đã cập nhật trạng thái phiên Live!';
            if ($status === 'danglive') $msg = '🔴 Đã phát sóng trực tiếp phiên Live!';
            elseif ($status === 'tamdung') $msg = '⏸ Đã tạm dừng phát sóng phiên LiveStream!';
            elseif ($status === 'ketthuc') $msg = '⏹ Đã kết thúc vĩnh viễn phiên LiveStream & lưu bản ghi!';

            set_flash($ok ? 'success' : 'error', $ok ? $msg : 'Không thể cập nhật trạng thái.');
        }

        $redirectUrl = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php?r=admin_lives');
        redirect($redirectUrl);
    }

    public function adminLiveDelete(): void {
        $user = $this->requireRole(['admin', 'nhanvien']);
        if (!user_can_access_route('admin_lives')) {
            $this->denyAccess();
        }

        $id = trim((string)($_GET['id'] ?? ''));
        if ($id !== '') {
            require_once __DIR__ . '/../models/PhienLive.php';
            $phienLiveModel = new PhienLive($this->pdo);
            $ok = $phienLiveModel->xoaPhienLive($id);
            set_flash($ok ? 'success' : 'error', $ok ? 'Đã xóa phiên LiveStream!' : 'Không thể xóa phiên LiveStream.');
        }

        redirect(BASE_URL . '/index.php?r=admin_lives');
    }

    public function adminLiveUpdateRecording(): void {
        $user = $this->requireRole(['admin', 'nhanvien']);
        if (!user_can_access_route('admin_lives')) {
            $this->denyAccess();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id = trim((string)($_POST['live_id'] ?? ''));
            $urlBanGhi = trim((string)($_POST['url_ban_ghi'] ?? ''));
            $tomTat = trim((string)($_POST['tom_tat_phien_live'] ?? ''));

            if ($id !== '') {
                require_once __DIR__ . '/../models/PhienLive.php';
                $phienLiveModel = new PhienLive($this->pdo);
                $ok = $phienLiveModel->capNhatPhienLive($id, [
                    'url_ban_ghi' => $urlBanGhi,
                    'tom_tat_phien_live' => $tomTat
                ]);
                set_flash($ok ? 'success' : 'error', $ok ? '🎬 Đã cập nhật link video bản ghi & kịch bản AI thành công!' : 'Không thể cập nhật bản ghi.');
            }
        }

        $redirectUrl = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php?r=admin_lives');
        redirect($redirectUrl);
    }

    // --- Backward compatibility aliases ---
    public function dashboard(): void { $this->adminDashboard(); }
    public function danhSachSanPham(): void { $this->adminProducts(); }
    public function themSanPham(): void { $this->adminProductCreate(); }
    public function suaSanPham(): void { $this->adminProductEdit(); }
    public function xoaSanPham(): void { $this->adminProductDelete(); }
    public function danhSachDonHang(): void { $this->adminOrders(); }
    public function capNhatTrangThaiDonHang(): void { $this->adminOrderStatus(); }
    public function inDonHang(): void { $this->adminOrderPrint(); }
    public function danhSachVoucher(): void { $this->adminVouchers(); }
    public function themVoucher(): void { $this->adminVoucherSave(); }
    public function suaVoucher(): void { $this->adminVouchers(); }
    public function xoaVoucher(): void { $this->adminVoucherDelete(); }
    public function danhSachDanhMuc(): void { $this->adminCategories(); }
    public function themDanhMuc(): void { $this->adminCategorySave(); }
    public function suaDanhMuc(): void { $this->adminCategories(); }
    public function xoaDanhMuc(): void { $this->adminCategoryDelete(); }
    public function danhSachNguoiDung(): void { $this->adminUsers(); }
    public function themNguoiDung(): void { $this->adminCustomerSave(); }
    public function suaNguoiDung(): void { $this->adminUsers(); }
    public function xoaNguoiDung(): void { $this->adminCustomerDelete(); }
    public function danhSachDanhGia(): void { $this->adminReviews(); }
    public function duyetDanhGia(): void { $this->adminReviewApprove(); }
    public function anDanhGia(): void { $this->adminReviewHide(); }
    public function xoaDanhGia(): void { $this->adminReviewDelete(); }
    public function traLoiDanhGia(): void { $this->adminReviewReply(); }
    public function baoCaoDoanhThu(): void { $this->adminReports(); }
    public function staffDanhSachDonHang(): void { $this->staffOrders(); }
    public function staffCapNhatTrangThaiDonHang(): void { $this->staffOrderStatus(); }
    public function staffDanhSachSanPham(): void { $this->staffProducts(); }
    public function staffThemSanPham(): void { $this->staffProductCreate(); }
    public function staffSuaSanPham(): void { $this->staffProductEdit(); }
    public function staffXoaSanPham(): void { $this->staffProductDelete(); }
    public function staffDanhSachDanhGia(): void { $this->staffReviews(); }
    public function staffTraLoiDanhGia(): void { $this->staffReviewReply(); }
}
