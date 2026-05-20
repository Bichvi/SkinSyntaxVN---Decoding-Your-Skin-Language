<?php
// backend/app/controllers/TaiKhoanController.php

require_once __DIR__ . '/../models/TaiKhoan.php';
require_once __DIR__ . '/../models/SanPham.php';

class TaiKhoanController {
    private PDO $pdo;
    private TaiKhoan $model;
    private SanPham $sanPhamModel;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->model = new TaiKhoan($pdo);
        $this->sanPhamModel = new SanPham($pdo);
    }

    private function requireLogin(): array {
        if (!is_logged_in()) {
            set_flash('error', 'Vui lòng đăng nhập để truy cập trang tài khoản.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        return current_user() ?? [];
    }

    private function render(string $view, array $data = []): void {
        extract($data);
        $menuCats = $this->sanPhamModel->menuTree();
        require __DIR__ . '/../views/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/layouts/footer.php';
    }

    private function json(array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function hoso(): void {
        $user = $this->requireLogin();
        $nguoiDungId = (int)($user['id'] ?? 0);
        $email = trim((string)($user['email'] ?? ''));

        $account = null;
        if ($nguoiDungId > 0) {
            $account = $this->model->getAccountOverview($nguoiDungId);
        }

        if (!$account && $email !== '') {
            $account = $this->model->getAccountOverviewByEmail($email);
        }

        if (!$account) {
            set_flash('error', 'Không tìm thấy thông tin tài khoản.');
            redirect(BASE_URL . '/index.php?r=home');
        }

        // Đồng bộ session để các request sau dùng đúng id/email mới nhất.
        $_SESSION['user']['id'] = (int)($account['id'] ?? 0);
        $_SESSION['user']['email'] = (string)($account['email'] ?? ($user['email'] ?? ''));
        $_SESSION['user']['ho_ten'] = (string)($account['ho_ten'] ?? ($user['ho_ten'] ?? ''));

        $khachHang = $this->model->getKhachHangByEmail((string)$account['email']);
        $orders = [];
        $cartItems = [];

        if ($khachHang && !empty($khachHang['ma_kh'])) {
            $maKh = (int)$khachHang['ma_kh'];
            $orders = $this->model->getOrderHistory($maKh);
            $cartItems = $this->model->getCartItems($maKh);
        }

        $skinProfile = $this->model->getSkinProfileByEmail((string)$account['email']);
        $loaiDaOptions = $this->model->getLoaiDaOptions();

        $this->render('hoso', [
            'account' => $account,
            'khachHang' => $khachHang,
            'orders' => $orders,
            'cartItems' => $cartItems,
            'skinProfile' => $skinProfile,
            'loaiDaOptions' => $loaiDaOptions,
        ]);
    }

    public function capNhatThongTin(): void {
        $user = $this->requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Method not allowed'], 405);
        }

        $email = trim((string)($user['email'] ?? ''));
        if ($email === '') {
            $this->json(['ok' => false, 'message' => 'Không xác định được email tài khoản.'], 422);
        }

        $hoTen = trim((string)($_POST['ho_ten'] ?? ''));
        $gioiTinh = trim((string)($_POST['gioi_tinh'] ?? ''));
        $soDienThoai = trim((string)($_POST['so_dien_thoai'] ?? ''));
        $diaChi = trim((string)($_POST['dia_chi'] ?? ''));
        $namSinhRaw = trim((string)($_POST['nam_sinh'] ?? ''));

        if ($hoTen === '') {
            $this->json(['ok' => false, 'message' => 'Họ tên không được để trống.'], 422);
        }

        $namSinh = null;
        if ($namSinhRaw !== '') {
            if (!ctype_digit($namSinhRaw)) {
                $this->json(['ok' => false, 'message' => 'Năm sinh không hợp lệ.'], 422);
            }
            $year = (int)$namSinhRaw;
            $currentYear = (int)date('Y');
            if ($year < 1900 || $year > $currentYear) {
                $this->json(['ok' => false, 'message' => 'Năm sinh không hợp lệ.'], 422);
            }
            $namSinh = $year;
        }

        $ok = $this->model->saveThongTinKhachHang($email, [
            'ho_ten' => $hoTen,
            'gioi_tinh' => $gioiTinh,
            'so_dien_thoai' => $soDienThoai,
            'dia_chi' => $diaChi,
            'nam_sinh' => $namSinh,
        ]);

        if (!$ok) {
            $this->json(['ok' => false, 'message' => 'Không thể cập nhật thông tin tài khoản.'], 500);
        }

        $_SESSION['user']['ho_ten'] = $hoTen;

        $this->json(['ok' => true, 'message' => 'Đã cập nhật thông tin tài khoản.']);
    }

    public function doiMatKhau(): void {
        $user = $this->requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Method not allowed'], 405);
        }

        $matKhauHienTai = (string)($_POST['mat_khau_hien_tai'] ?? '');
        $matKhauMoi = (string)($_POST['mat_khau_moi'] ?? '');
        $xacNhan = (string)($_POST['xac_nhan_mat_khau'] ?? '');

        if ($matKhauHienTai === '' || $matKhauMoi === '' || $xacNhan === '') {
            $this->json(['ok' => false, 'message' => 'Vui lòng nhập đầy đủ thông tin mật khẩu.'], 422);
        }

        if (strlen($matKhauMoi) < 6) {
            $this->json(['ok' => false, 'message' => 'Mật khẩu mới phải có ít nhất 6 ký tự.'], 422);
        }

        if ($matKhauMoi !== $xacNhan) {
            $this->json(['ok' => false, 'message' => 'Xác nhận mật khẩu không khớp.'], 422);
        }

        $userId = (int)($user['id'] ?? 0);
        $email = trim((string)($user['email'] ?? ''));

        if ($userId > 0) {
            $result = $this->model->doiMatKhau($userId, $matKhauHienTai, $matKhauMoi);
            if (empty($result['ok']) && $email !== '') {
                $result = $this->model->doiMatKhauByEmail($email, $matKhauHienTai, $matKhauMoi);
            }
        } else {
            $result = ($email !== '')
                ? $this->model->doiMatKhauByEmail($email, $matKhauHienTai, $matKhauMoi)
                : ['ok' => false, 'message' => 'Không xác định được tài khoản để đổi mật khẩu.'];
        }

        $status = !empty($result['ok']) ? 200 : 422;
        $this->json($result, $status);
    }

    public function capNhatHoSoDa(): void {
        $user = $this->requireLogin();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Method not allowed'], 405);
        }

        $loaiDa = trim((string)($_POST['loai_da'] ?? ''));
        $vanDeDa = $_POST['van_de_da'] ?? [];
        $nganSachRaw = trim((string)($_POST['ngan_sach'] ?? ''));

        if ($loaiDa === '') {
            $this->json(['ok' => false, 'message' => 'Vui lòng chọn loại da.'], 422);
        }

        if (!is_array($vanDeDa)) {
            $vanDeDa = [$vanDeDa];
        }

        $nganSach = null;
        if ($nganSachRaw !== '') {
            $digits = preg_replace('/[^\d]/', '', $nganSachRaw);
            $nganSach = ($digits !== '') ? (int)$digits : null;
        }

        $hoTen = trim((string)($user['ho_ten'] ?? ''));
        $email = trim((string)($user['email'] ?? ''));

        if ($email === '') {
            $this->json(['ok' => false, 'message' => 'Không xác định được email tài khoản.'], 422);
        }

        $ok = $this->model->saveSkinProfileByEmail($hoTen, $email, $loaiDa, $vanDeDa, $nganSach);
        if (!$ok) {
            $this->json(['ok' => false, 'message' => 'Không thể cập nhật hồ sơ làn da.'], 500);
        }

        $this->json(['ok' => true, 'message' => 'Đã cập nhật hồ sơ làn da thành công.']);
    }
}
