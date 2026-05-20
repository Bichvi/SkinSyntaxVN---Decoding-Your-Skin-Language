<?php
// backend/app/controllers/AdminController.php

require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/ThongKe.php';
require_once __DIR__ . '/../models/QuanTri.php';

class AdminController {
    private  $pdo;
    private SanPham $model;

    public function __construct( $pdo) {
        $this->pdo = $pdo;
        $this->model = new SanPham($pdo);
    }

    private function denyAccess(): void {
        http_response_code(403);
        require __DIR__ . '/../views/admin/layouts/header.php';
        require __DIR__ . '/../views/admin/403.php';
        require __DIR__ . '/../views/admin/layouts/footer.php';
        exit;
    }

    private function checkAdmin(): void {
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            header('Location: index.php');
            exit;
        }

        $role = strtolower((string)($user['role'] ?? $user['vai_tro'] ?? $user['quyen'] ?? ''));
        $isAdmin = $role === 'admin' || (int)($user['is_admin'] ?? 0) === 1;
        if (!$isAdmin) {
            $this->denyAccess();
        }
    }

    private function render(string $view, array $data = []): void {
        $data['notificationCenter'] = $data['notificationCenter'] ?? $this->buildNotificationCenter();
        extract($data);
        require __DIR__ . '/../views/admin/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/admin/layouts/footer.php';
    }

    private function buildNotificationCenter(): array {
        $center = (new QuanTri($this->pdo))->getNotificationCenterData();
        $seen = $_SESSION['admin_notifications_seen'] ?? [];
        $currentOrderMarker = (string)($center['latest_order_marker'] ?? '');
        $currentChatMarker = (string)($center['latest_chat_marker'] ?? '');
        $hasNewOrders = $currentOrderMarker !== '' && $currentOrderMarker !== (string)($seen['latest_order_marker'] ?? '');
        $hasNewChats = $currentChatMarker !== '' && $currentChatMarker !== (string)($seen['latest_chat_marker'] ?? '');

        $center['has_new_orders'] = $hasNewOrders;
        $center['has_new_chats'] = $hasNewChats;
        $center['unseen_count'] = ($hasNewOrders ? (int)($center['pending_orders_count'] ?? 0) : 0)
            + ($hasNewChats ? (int)($center['pending_chats_count'] ?? 0) : 0);

        return $center;
    }

    private function collectFormData(array $source): array {
        return [
            'ma_san_pham' => trim((string)($source['ma_san_pham'] ?? '')),
            'ten_san_pham' => trim((string)($source['ten_san_pham'] ?? '')),
            'ma_thuong_hieu' => trim((string)($source['ma_thuong_hieu'] ?? '')),
            'ma_danh_muc' => trim((string)($source['ma_danh_muc'] ?? '')),
            'ten_thuong_hieu_input' => trim((string)($source['ten_thuong_hieu_input'] ?? '')),
            'ten_danh_muc_input' => trim((string)($source['ten_danh_muc_input'] ?? '')),
            'gia_ban' => trim((string)($source['gia_ban'] ?? '')),
            'gia_thi_truong' => trim((string)($source['gia_thi_truong'] ?? '')),
            'dung_tich' => trim((string)($source['dung_tich'] ?? '')),
            'loai_da' => trim((string)($source['loai_da'] ?? '')),
            'mo_ta' => trim((string)($source['mo_ta'] ?? '')),
            'thanh_phan_chinh' => trim((string)($source['thanh_phan_chinh'] ?? '')),
            'thanh_phan_day_du' => trim((string)($source['thanh_phan_day_du'] ?? '')),
            'hdsd' => trim((string)($source['hdsd'] ?? '')),
            'link_hinh_anh' => trim((string)($source['link_hinh_anh'] ?? '')),
            'trang_thai' => trim((string)($source['trang_thai'] ?? 'active')),
        ];
    }

    private function handleUpload(string $inputName = 'hinh_anh'): ?string {
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

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
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

        if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
            return null;
        }

        return $fileName;
    }

    private function productCodeExists(string $code, ?string $excludeId = null): bool {
        if (!method_exists($this->model, 'hasProductCode')) {
            return false;
        }

        return (bool)$this->model->hasProductCode($code, $excludeId);
    }

    private function productNameExists(string $name, ?string $excludeId = null): bool {
        if (!method_exists($this->model, 'hasProductName')) {
            return false;
        }

        return (bool)$this->model->hasProductName($name, $excludeId);
    }

    public function index(): void {
        $this->checkAdmin();

        $thongKe = new ThongKe($this->pdo);

        $tongSP = $thongKe->getTongSanPham();
        $tongUser = $thongKe->getTongNguoiDung();
        $doanhThu = $thongKe->getDoanhThu();
        $donChoXuLy = $thongKe->getDonChoXuLy();
        $spMoi = $thongKe->getSanPhamMoi(5);
        $userMoi = $thongKe->getNguoiDungMoi(5);

        $this->render('admin/dashboard', compact('tongSP', 'tongUser', 'doanhThu', 'donChoXuLy', 'spMoi', 'userMoi'));
    }

    public function create(): void {
        $this->checkAdmin();

        $brandOptions = $this->model->listBrandOptions();
        $categoryOptions = $this->model->listCategoryOptions();
        $nextProductCode = $this->model->getNextProductCode();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->render('admin/themSP', [
                'product' => ['ma_san_pham' => $nextProductCode],
                'error' => null,
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
                'nextProductCode' => $nextProductCode,
            ]);
            return;
        }

        $data = $this->collectFormData($_POST);
        if ($data['ma_san_pham'] === '') {
            $data['ma_san_pham'] = $nextProductCode;
        }

        if ($data['ma_thuong_hieu'] === '' && $data['ten_thuong_hieu_input'] !== '') {
            $brandId = $this->model->ensureBrandByName($data['ten_thuong_hieu_input']);
            if ($brandId !== null) {
                $data['ma_thuong_hieu'] = (string)$brandId;
                $brandOptions = $this->model->listBrandOptions();
            }
        }

        if ($data['ma_danh_muc'] === '' && $data['ten_danh_muc_input'] !== '') {
            $categoryId = $this->model->ensureCategoryByName($data['ten_danh_muc_input']);
            if ($categoryId !== null) {
                $data['ma_danh_muc'] = (string)$categoryId;
                $categoryOptions = $this->model->listCategoryOptions();
            }
        }

        if ($data['ma_san_pham'] === '' || $data['ten_san_pham'] === '') {
            $this->render('admin/themSP', [
                'product' => $data,
                'error' => 'Mã sản phẩm và tên sản phẩm là bắt buộc.',
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
                'nextProductCode' => $nextProductCode,
            ]);
            return;
        }

        $giaBan = trim((string)($data['gia_ban'] ?? ''));
        if ($giaBan === '' || !is_numeric($giaBan) || (float)$giaBan <= 0) {
            $this->render('admin/themSP', [
                'product' => $data,
                'error' => 'Giá bán phải lớn hơn 0.',
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
                'nextProductCode' => $nextProductCode,
            ]);
            return;
        }

        $giaThiTruong = trim((string)($data['gia_thi_truong'] ?? ''));
        if ($giaThiTruong !== '' && (!is_numeric($giaThiTruong) || (float)$giaThiTruong <= 0)) {
            $this->render('admin/themSP', [
                'product' => $data,
                'error' => 'Giá thị trường phải lớn hơn 0 nếu được nhập.',
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
                'nextProductCode' => $nextProductCode,
            ]);
            return;
        }

        if ($this->productCodeExists($data['ma_san_pham'])) {
            $data['ma_san_pham'] = $this->model->getNextProductCode();
            $nextProductCode = $data['ma_san_pham'];
        }

        if ($this->productNameExists($data['ten_san_pham'])) {
            $this->render('admin/themSP', [
                'product' => $data,
                'error' => 'Tên sản phẩm đã tồn tại. Vui lòng nhập tên khác.',
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
                'nextProductCode' => $nextProductCode,
            ]);
            return;
        }

        $uploadedFileName = $this->handleUpload('hinh_anh');
        if ($uploadedFileName !== null) {
            $data['link_hinh_anh'] = $uploadedFileName;
        }

        $ok = $this->model->adminInsert($data);
        if ($ok) {
            set_flash('success', 'Đã thêm sản phẩm thành công.');
            header('Location: index.php?r=admin_sp');
            exit;
        }

        $errorMessage = method_exists($this->model, 'getLastErrorMessage')
            ? ((string)($this->model->getLastErrorMessage() ?? '') ?: 'Không thể thêm sản phẩm. Vui lòng thử lại.')
            : 'Không thể thêm sản phẩm. Vui lòng thử lại.';

        $this->render('admin/themSP', [
            'product' => $data,
            'error' => $errorMessage,
            'brandOptions' => $brandOptions,
            'categoryOptions' => $categoryOptions,
            'nextProductCode' => $nextProductCode,
        ]);
    }

    public function edit(): void {
        $this->checkAdmin();

        $id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
        if ($id === '') {
            header('Location: index.php?r=admin_sp');
            exit;
        }

        $current = $this->model->findById($id);
        if (!$current) {
            header('Location: index.php?r=admin_sp');
            exit;
        }

        $brandOptions = $this->model->listBrandOptions();
        $categoryOptions = $this->model->listCategoryOptions();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->render('admin/suaSP', [
                'product' => $current,
                'error' => null,
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
            ]);
            return;
        }

        $data = $this->collectFormData($_POST);
        $data['ma_san_pham'] = $id;

        if ($data['ten_san_pham'] === '') {
            $this->render('admin/suaSP', [
                'product' => array_merge($current, $data),
                'error' => 'Tên sản phẩm là bắt buộc.',
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
            ]);
            return;
        }

        $giaBan = trim((string)($data['gia_ban'] ?? ''));
        if ($giaBan === '' || !is_numeric($giaBan) || (float)$giaBan <= 0) {
            $this->render('admin/suaSP', [
                'product' => array_merge($current, $data),
                'error' => 'Giá bán phải lớn hơn 0.',
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
            ]);
            return;
        }

        $giaThiTruong = trim((string)($data['gia_thi_truong'] ?? ''));
        if ($giaThiTruong !== '' && (!is_numeric($giaThiTruong) || (float)$giaThiTruong <= 0)) {
            $this->render('admin/suaSP', [
                'product' => array_merge($current, $data),
                'error' => 'Giá thị trường phải lớn hơn 0 nếu được nhập.',
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
            ]);
            return;
        }

        if ($this->productNameExists($data['ten_san_pham'], $id)) {
            $this->render('admin/suaSP', [
                'product' => array_merge($current, $data),
                'error' => 'Tên sản phẩm đã tồn tại. Vui lòng nhập tên khác.',
                'brandOptions' => $brandOptions,
                'categoryOptions' => $categoryOptions,
            ]);
            return;
        }

        $uploadedFileName = $this->handleUpload('hinh_anh');
        if ($uploadedFileName !== null) {
            $data['link_hinh_anh'] = $uploadedFileName;
        } elseif (trim((string)($data['link_hinh_anh'] ?? '')) === '') {
            $data['link_hinh_anh'] = (string)($current['link_hinh_anh'] ?? '');
        }

        $ok = $this->model->adminUpdate($id, $data);
        if ($ok) {
            set_flash('success', 'Đã cập nhật sản phẩm thành công.');
            header('Location: index.php?r=admin_sp');
            exit;
        }

        $errorMessage = method_exists($this->model, 'getLastErrorMessage')
            ? ((string)($this->model->getLastErrorMessage() ?? '') ?: 'Không thể cập nhật sản phẩm. Vui lòng thử lại.')
            : 'Không thể cập nhật sản phẩm. Vui lòng thử lại.';

        $this->render('admin/suaSP', [
            'product' => array_merge($current, $data),
            'error' => $errorMessage,
            'brandOptions' => $brandOptions,
            'categoryOptions' => $categoryOptions,
        ]);
    }

    public function delete(): void {
        $this->checkAdmin();

        $id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
        if ($id === '') {
            header('Location: index.php?r=admin_sp');
            exit;
        }

        $ok = $this->model->adminDelete($id);
        $message = $ok
            ? 'Đã xóa sản phẩm.'
            : ((string)($this->model->getLastErrorMessage() ?? 'Không thể xóa sản phẩm.'));
        set_flash($ok ? 'success' : 'error', $message);
        header('Location: index.php?r=admin_sp');
        exit;
    }
}
