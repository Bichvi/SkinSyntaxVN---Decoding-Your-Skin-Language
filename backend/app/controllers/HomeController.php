<?php
// backend/app/controllers/HomeController.php
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/GoiYContentBased.php';
require_once __DIR__ . '/../models/TaiKhoan.php';
require_once __DIR__ . '/../models/HoaDon.php';

class HomeController {
    private PDO $pdo;
    private SanPham $model;
    private GoiYContentBased $goiYModel;
    private HoaDon $hoaDonModel;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->model = new SanPham($pdo);
        $this->goiYModel = new GoiYContentBased($pdo);
        $this->hoaDonModel = new HoaDon($pdo);
    }

    private function render($view, $data = []) {
        extract($data);
        // menuCats dùng chung layout
        $menuCats = $this->model->menuTree();
        require __DIR__ . '/../views/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/layouts/footer.php';
    }

    public function index() {
        $latest = $this->model->latest(8);
        $cats = $this->getHighlightedCategories();
        $this->render('home', ['latest' => $latest, 'cats' => $cats]);
    }

    private function getHighlightedCategories(): array {
        // Lấy top 6 danh mục có nhiều sản phẩm nhất
        // Bảng hiện tại là "san_pham" (theo schema_new.sql),
        // đồng thời cột "danh_muc_day_du" nằm trong bảng này (dữ liệu import từ hasaki).
        $sql = "SELECT danh_muc_day_du, COUNT(*)::int AS so_luong
                FROM san_pham
                WHERE danh_muc_day_du IS NOT NULL AND danh_muc_day_du <> ''
                GROUP BY danh_muc_day_du
                ORDER BY so_luong DESC
                LIMIT 6";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function giohang() {
        // Xử lý POST requests
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? null;
            $product_id = $_POST['product_id'] ?? null;
            
            if ($action === 'update_qty' && $product_id) {
                $qty = max(1, (int)($_POST['qty'] ?? 1));
                $_SESSION['gio_hang'][$product_id] = $qty;
                http_response_code(200);
                exit;
            } elseif ($action === 'delete' && $product_id) {
                unset($_SESSION['gio_hang'][$product_id]);
                http_response_code(200);
                exit;
            }
        }
        
        // Hiển thị giỏ hàng
        $items = [];
        if (!empty($_SESSION['gio_hang'])) {
            foreach ($_SESSION['gio_hang'] as $product_id => $qty) {
                $product = $this->model->findById($product_id);
                if ($product) {
                    $items[$product_id] = [
                        'product' => $product,
                        'qty' => $qty
                    ];
                }
            }
        }
        $this->render('giohang', ['items' => $items]);
    }

    public function goiy() {
        $this->render('goiy');
    }

    public function chuandaithanhtoan() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $selectedIds = $_POST['selected_items'] ?? [];
        if (!is_array($selectedIds)) {
            $selectedIds = [$selectedIds];
        }

        $cart = $_SESSION['gio_hang'] ?? [];
        $checkoutItems = [];

        foreach ($selectedIds as $idSp) {
            $idSp = trim((string)$idSp);
            if ($idSp === '') {
                continue;
            }

            if (!isset($cart[$idSp])) {
                continue;
            }

            $checkoutItems[$idSp] = max(1, (int)$cart[$idSp]);
        }

        if (empty($checkoutItems)) {
            set_flash('error', 'Vui lòng chọn sản phẩm để thanh toán.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $_SESSION['checkout_items'] = $checkoutItems;
        redirect(BASE_URL . '/index.php?r=thanhtoan');
    }

    public function thanhtoan() {
        if (!is_logged_in()) {
            set_flash('error', 'Vui lòng đăng nhập để thanh toán.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $checkoutItems = $_SESSION['checkout_items'] ?? [];
        if (empty($checkoutItems) || !is_array($checkoutItems)) {
            set_flash('error', 'Không có sản phẩm để thanh toán.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $items = [];
        $subtotal = 0;

        foreach ($checkoutItems as $productId => $qty) {
            $product = $this->model->findById((string)$productId);
            if (!$product) {
                continue;
            }

            $qty = max(1, (int)$qty);
            $unitPrice = (int)($product['gia_ban'] ?? 0);
            if ($unitPrice <= 0) {
                $unitPrice = (int)($product['gia_thi_truong'] ?? 0);
            }

            $lineTotal = $unitPrice * $qty;
            $subtotal += $lineTotal;

            $items[] = [
                'id' => (string)$productId,
                'product' => $product,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        if (empty($items)) {
            unset($_SESSION['checkout_items']);
            set_flash('error', 'Sản phẩm trong danh sách thanh toán không còn tồn tại.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $shippingFee = 30000;
        $grandTotal = $subtotal + $shippingFee;

        $user = current_user() ?? [];
        $tkModel = new TaiKhoan($this->pdo);
        $kh = $tkModel->getKhachHangByEmail((string)($user['email'] ?? ''));

        $receiver = [
            'ten_nguoi_nhan' => (string)($kh['ho_ten'] ?? ($user['ho_ten'] ?? '')),
            'sdt_nguoi_nhan' => (string)($kh['so_dien_thoai'] ?? ''),
            'dia_chi_giao_hang' => (string)($kh['dia_chi'] ?? ''),
        ];

        $this->render('thanhtoan', [
            'items' => $items,
            'receiver' => $receiver,
            'subtotal' => $subtotal,
            'shippingFee' => $shippingFee,
            'grandTotal' => $grandTotal,
        ]);
    }

    public function xulydathang() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        if (!is_logged_in()) {
            set_flash('error', 'Vui lòng đăng nhập để đặt hàng.');
            redirect(BASE_URL . '/index.php?r=dangnhap');
        }

        $checkoutItems = $_SESSION['checkout_items'] ?? [];
        if (empty($checkoutItems) || !is_array($checkoutItems)) {
            set_flash('error', 'Không có sản phẩm để đặt hàng.');
            redirect(BASE_URL . '/index.php?r=giohang');
        }

        $tenNguoiNhan = trim((string)($_POST['ten_nguoi_nhan'] ?? ''));
        $sdtNguoiNhan = trim((string)($_POST['sdt_nguoi_nhan'] ?? ''));
        $diaChiGiaoHang = trim((string)($_POST['dia_chi_giao_hang'] ?? ''));
        $hinhThucThanhToan = trim((string)($_POST['hinh_thuc_thanh_toan'] ?? 'cod'));

        if ($tenNguoiNhan === '' || $sdtNguoiNhan === '' || $diaChiGiaoHang === '') {
            set_flash('error', 'Vui lòng điền đầy đủ thông tin nhận hàng.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }

        $user = current_user() ?? [];
        $email = (string)($user['email'] ?? '');

        try {
            $maHoaDon = $this->hoaDonModel->taoDonHang([ 
                'email' => $email,
                'ho_ten_mac_dinh' => (string)($user['ho_ten'] ?? ''),
                'ten_nguoi_nhan' => $tenNguoiNhan,
                'sdt_nguoi_nhan' => $sdtNguoiNhan,
                'dia_chi_giao_hang' => $diaChiGiaoHang,
                'hinh_thuc_thanh_toan' => ($hinhThucThanhToan !== '' ? $hinhThucThanhToan : 'cod'),
                'phi_van_chuyen' => 30000,
                'checkout_items' => $checkoutItems,
            ]);

            foreach ($checkoutItems as $idSp => $_qty) {
                unset($_SESSION['gio_hang'][$idSp]);
            }
            unset($_SESSION['checkout_items']);

            set_flash('success', 'Đặt hàng thành công. Cảm ơn bạn đã mua sắm tại SkinSyntax.');
            redirect(BASE_URL . '/index.php?r=camon&ma_hoa_don=' . urlencode((string)$maHoaDon));
        } catch (Throwable $e) {
            set_flash('error', 'Không thể đặt hàng lúc này. Vui lòng thử lại.');
            redirect(BASE_URL . '/index.php?r=thanhtoan');
        }
    }

    public function camon() {
        $maHoaDon = trim((string)($_GET['ma_hoa_don'] ?? ''));
        $this->render('camon', [
            'maHoaDon' => $maHoaDon,
        ]);
    }

    public function xulygoiy(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $gioiTinh = trim((string)($_POST['gioi_tinh'] ?? ''));
        $namSinhRaw = trim((string)($_POST['nam_sinh'] ?? ''));

        if ($gioiTinh === '') {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Vui lòng chọn giới tính (Câu 1).'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($namSinhRaw === '' || !ctype_digit($namSinhRaw)) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Vui lòng nhập năm sinh hợp lệ.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $year = (int)$namSinhRaw;
        $currentYear = (int)date('Y');
        if ($year < 1900 || $year > $currentYear) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Năm sinh không hợp lệ.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $results = $this->goiYModel->recommendFromPost($_POST, 12);

        if (function_exists('is_logged_in') && is_logged_in()) {
            $user = current_user() ?? [];
            $email = trim((string)($user['email'] ?? ''));
            if ($email !== '') {
                $taiKhoanModel = new TaiKhoan($this->pdo);
                $taiKhoanModel->saveThongTinKhachHang($email, [
                    'ho_ten' => trim((string)($user['ho_ten'] ?? '')),
                    'gioi_tinh' => $gioiTinh,
                    'nam_sinh' => $year,
                    'so_dien_thoai' => '',
                    'dia_chi' => '',
                ]);
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'count' => count($results),
            'data' => $results,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
