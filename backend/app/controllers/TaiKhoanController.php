<?php
// backend/app/controllers/TaiKhoanController.php

require_once __DIR__ . '/../models/TaiKhoan.php';
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/QuanTri.php';
require_once __DIR__ . '/../models/HoaDon.php';

class TaiKhoanController {
    private  $pdo;
    private TaiKhoan $model;
    private SanPham $sanPhamModel;
    private QuanTri $reviewModel;
    private HoaDon $hoaDonModel;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->model = new TaiKhoan($pdo);
        $this->sanPhamModel = new SanPham($pdo);
        $this->reviewModel = new QuanTri($pdo);
        $this->hoaDonModel = new HoaDon($pdo);
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

    private function isOrderEligibleForReview(?string $status): bool {
        $normalized = strtolower(trim((string)($status ?? '')));
        return in_array($normalized, ['hoan thanh', 'hoàn thành', 'da giao', 'đã giao'], true);
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

    private function buildSessionCartItems(): array {
        $cart = $_SESSION['gio_hang'] ?? [];
        if (empty($cart) || !is_array($cart)) {
            return [];
        }

        $items = [];
        foreach ($cart as $productId => $qty) {
            $productId = trim((string)$productId);
            if ($productId === '') {
                continue;
            }

            $product = $this->sanPhamModel->findById($productId);
            if (!$product || !is_array($product)) {
                continue;
            }

            $items[] = [
                'ma_san_pham' => (string)($product['ma_san_pham'] ?? $productId),
                'ten_san_pham' => (string)($product['ten_san_pham'] ?? ''),
                'gia_ban' => (int)($product['gia_ban'] ?? 0),
                'link_hinh_anh' => (string)($product['link_hinh_anh'] ?? $product['hinh_anh'] ?? ''),
                'so_luong' => max(1, (int)$qty),
            ];
        }

        return $items;
    }

    public function hoso(): void {
        $user = $this->requireLogin();
        $this->hoaDonModel->cancelExpiredQrOrders(24);
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
            $cartItems = $this->buildSessionCartItems();
            if (empty($cartItems)) {
                $cartItems = $this->model->getCartItems($maKh);
            }

            $productIds = [];
            foreach ($orders as $order) {
                foreach (($order['items'] ?? []) as $item) {
                    $productId = trim((string)($item['ma_san_pham'] ?? ''));
                    if ($productId !== '') {
                        $productIds[] = $productId;
                    }
                }
            }

            $eligibilityMap = $this->reviewModel->{'getCustomerReviewEligibility'}($maKh, $productIds);
            foreach ($orders as &$order) {
                $orderAllowsReview = $this->isOrderEligibleForReview((string)($order['trang_thai'] ?? ''));
                foreach (($order['items'] ?? []) as &$item) {
                    $productId = trim((string)($item['ma_san_pham'] ?? ''));
                    $eligibility = $eligibilityMap[$productId] ?? ['has_purchased' => false, 'has_reviewed' => false];
                    $item['detail_url'] = 'index.php?r=chitiet&id=' . rawurlencode($productId) . '&tab=danh-gia';
                    $item['has_purchased'] = !empty($eligibility['has_purchased']) || ($orderAllowsReview && $productId !== '');
                    $item['has_reviewed'] = !empty($eligibility['has_reviewed']);

                    $rawImage = trim((string)($item['link_hinh_anh'] ?? ''));
                    if ($rawImage === '' && $productId !== '') {
                        $product = $this->sanPhamModel->findById($productId);
                        if ($product && is_array($product)) {
                            $rawImage = (string)($product['link_hinh_anh'] ?? $product['hinh_anh'] ?? '');
                        }
                    }

                    $item['image_url'] = resolve_image_url($rawImage);
                }
                unset($item);
            }
            unset($order);
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
            'cancelReasonOptions' => $this->cancellationReasonOptions(),
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

        if ($matKhauMoi === '' || $xacNhan === '') {
            $this->json(['ok' => false, 'message' => 'Vui lòng nhập mật khẩu mới và xác nhận mật khẩu.'], 422);
        }

        if (strlen($matKhauMoi) < 6) {
            $this->json(['ok' => false, 'message' => 'Mật khẩu mới phải có ít nhất 6 ký tự.'], 422);
        }

        if ($matKhauMoi !== $xacNhan) {
            $this->json(['ok' => false, 'message' => 'Xác nhận mật khẩu không khớp.'], 422);
        }

        $email = trim((string)($user['email'] ?? ''));

        if ($email === '') {
            $result = ['ok' => false, 'message' => 'Không xác định được tài khoản để đổi mật khẩu.'];
        } else {
            $result = $this->model->capNhatMatKhauTheoEmail($email, $matKhauMoi);
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
