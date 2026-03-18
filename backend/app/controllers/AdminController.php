<?php
// backend/app/controllers/AdminController.php

require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/ThongKe.php';

class AdminController {
    private PDO $pdo;
    private SanPham $model;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->model = new SanPham($pdo);
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
            header('Location: index.php');
            exit;
        }
    }

    private function render(string $view, array $data = []): void {
        extract($data);
        require __DIR__ . '/../views/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/layouts/footer.php';
    }

    private function collectFormData(array $source): array {
        return [
            'ma_san_pham' => trim((string)($source['ma_san_pham'] ?? '')),
            'ten_san_pham' => trim((string)($source['ten_san_pham'] ?? '')),
            'ma_thuong_hieu' => trim((string)($source['ma_thuong_hieu'] ?? '')),
            'ma_danh_muc' => trim((string)($source['ma_danh_muc'] ?? '')),
            'gia_ban' => trim((string)($source['gia_ban'] ?? '')),
            'gia_thi_truong' => trim((string)($source['gia_thi_truong'] ?? '')),
            'dung_tich' => trim((string)($source['dung_tich'] ?? '')),
            'loai_da' => trim((string)($source['loai_da'] ?? '')),
            'mo_ta' => trim((string)($source['mo_ta'] ?? '')),
            'thanh_phan_chinh' => trim((string)($source['thanh_phan_chinh'] ?? '')),
            'thanh_phan_day_du' => trim((string)($source['thanh_phan_day_du'] ?? '')),
            'hdsd' => trim((string)($source['hdsd'] ?? '')),
            'link_hinh_anh' => trim((string)($source['link_hinh_anh'] ?? '')),
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

    public function index(): void {
        $this->checkAdmin();

        $thongKe = new ThongKe($this->pdo);

        $tongSP = $thongKe->getTongSanPham();
        $tongUser = $thongKe->getTongNguoiDung();
        $doanhThu = $thongKe->getDoanhThu();
        $donChoXuLy = $thongKe->getDonChoXuLy();
        $spMoi = $thongKe->getSanPhamMoi(5);
        $userMoi = $thongKe->getNguoiDungMoi(5);

        require __DIR__ . '/../views/admin/dashboard.php';
    }

    public function create(): void {
        $this->checkAdmin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->render('admin/themSP', [
                'product' => [],
                'error' => null,
            ]);
            return;
        }

        $data = $this->collectFormData($_POST);
        if ($data['ma_san_pham'] === '' || $data['ten_san_pham'] === '') {
            $this->render('admin/themSP', [
                'product' => $data,
                'error' => 'Mã sản phẩm và tên sản phẩm là bắt buộc.',
            ]);
            return;
        }

        $uploadedFileName = $this->handleUpload('hinh_anh');
        if ($uploadedFileName !== null) {
            $data['link_hinh_anh'] = $uploadedFileName;
        }

        $ok = $this->model->adminInsert($data);
        if ($ok) {
            header('Location: index.php?r=admin_sp');
            exit;
        }

        $this->render('admin/themSP', [
            'product' => $data,
            'error' => 'Không thể thêm sản phẩm. Vui lòng thử lại.',
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

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->render('admin/suaSP', [
                'product' => $current,
                'error' => null,
            ]);
            return;
        }

        $data = $this->collectFormData($_POST);
        $data['ma_san_pham'] = $id;

        if ($data['ten_san_pham'] === '') {
            $this->render('admin/suaSP', [
                'product' => array_merge($current, $data),
                'error' => 'Tên sản phẩm là bắt buộc.',
            ]);
            return;
        }

        $uploadedFileName = $this->handleUpload('hinh_anh');
        if ($uploadedFileName !== null) {
            $data['link_hinh_anh'] = $uploadedFileName;
        } else {
            $data['link_hinh_anh'] = (string)($current['link_hinh_anh'] ?? '');
        }

        $ok = $this->model->adminUpdate($id, $data);
        if ($ok) {
            header('Location: index.php?r=admin_sp');
            exit;
        }

        $this->render('admin/suaSP', [
            'product' => array_merge($current, $data),
            'error' => 'Không thể cập nhật sản phẩm. Vui lòng thử lại.',
        ]);
    }

    public function delete(): void {
        $this->checkAdmin();

        $id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
        if ($id === '') {
            header('Location: index.php?r=admin_sp');
            exit;
        }

        $this->model->adminDelete($id);
        header('Location: index.php?r=admin_sp');
        exit;
    }
}
