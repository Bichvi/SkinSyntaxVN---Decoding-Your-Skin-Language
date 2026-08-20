<?php
// backend/app/controllers/TaiKhoanController.php

require_once __DIR__ . '/../models/TaiKhoan.php';
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/QuanTri.php';
require_once __DIR__ . '/../models/HoaDon.php';
require_once __DIR__ . '/../models/DanhGia.php';

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
        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require $viewDir . '/layouts/header.php';
        require $viewDir . '/' . $view . '.php';
        require $viewDir . '/layouts/footer.php';
    }

    private function json(array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function hasRecommendationConsent(array $khachHang, array $account = []): bool {
        // Recommendation may use profile, behavior, cart, and order history only
        // when the user has consented. Missing consent falls back to guest mode.
        return !empty($khachHang['recommendation_consent'])
            || !empty($khachHang['privacy_consent'])
            || !empty($account['recommendation_consent'])
            || !empty($account['privacy_consent']);
    }

    private function fetchProfileRecommendationsFromAi(int $customerId): array {
        $base = defined('AI_PROFILE_RECOMMENDATION_ENDPOINT_BASE') ? trim((string)AI_PROFILE_RECOMMENDATION_ENDPOINT_BASE) : '';
        if ($base === '') {
            return ['ok' => false, 'message' => 'AI profile recommendation endpoint is not configured.'];
        }

        $url = rtrim($base, '/') . '/' . rawurlencode((string)$customerId) . '?limit=8';
        $timeout = defined('AI_PROFILE_RECOMMENDATION_TIMEOUT') ? (int)AI_PROFILE_RECOMMENDATION_TIMEOUT : 20;

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'cURL is not available.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return ['ok' => false, 'message' => $error ?: 'AI profile recommendation request failed.'];
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'AI profile recommendation response is invalid.'];
        }

        return $decoded;
    }

    public function apiProfileRecommendations(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->json(['ok' => false, 'message' => 'Method not allowed'], 405);
        }

        $user = $this->requireLogin();
        $email = trim((string)($user['email'] ?? ''));
        $account = $email !== '' ? ($this->model->getAccountOverviewByEmail($email) ?? []) : [];
        $khachHang = $email !== '' ? ($this->model->getKhachHangByEmail($email) ?? []) : [];
        $customerId = (int)($khachHang['ma_kh'] ?? 0);

        if ($customerId > 0 && $this->hasRecommendationConsent($khachHang, $account)) {
            $ai = $this->fetchProfileRecommendationsFromAi($customerId);
            $products = $ai['products'] ?? [];
            if (is_array($products) && !empty($products)) {
                foreach ($products as &$item) {
                    $item['image_url'] = resolve_image_url((string)($item['image_url'] ?? $item['link_hinh_anh'] ?? ''));
                }
                unset($item);

                $this->json([
                    'ok' => true,
                    'source' => (string)($ai['retrieval_mode'] ?? 'llamaindex_hybrid_rerank'),
                    'consent' => 'granted',
                    'summary' => (string)($ai['summary'] ?? ''),
                    'products' => array_values($products),
                ]);
            }
        }

        // Guest-safe fallback: no profile, no orders, no behavior are sent or used.
        $fallback = $this->sanPhamModel->getTopTrending(8, true);
        foreach ($fallback as &$item) {
            $item['id'] = (string)($item['id'] ?? $item['ma_san_pham'] ?? '');
            $item['image_url'] = resolve_image_url((string)($item['link_hinh_anh'] ?? $item['hinh_anh'] ?? ''));
            $item['score'] = 0;
            $item['reasons'] = ['Guest recommendation: sản phẩm phổ biến trong MongoDB.'];
        }
        unset($item);

        $this->json([
            'ok' => true,
            'source' => 'mongo_guest_fallback',
            'consent' => 'missing',
            'summary' => 'Tự động',
            'products' => $fallback,
        ]);
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

    private function handleReorder(int $maKh, string $email, int $orderId): void {
        if ($maKh <= 0 || $orderId <= 0) {
            set_flash('error', 'Không thể mua lại đơn hàng này.');
            redirect(BASE_URL . '/index.php?r=hoso');
        }

        $targetOrder = null;
        foreach ($this->model->getOrderHistory($maKh) as $order) {
            if ((int)($order['ma_hoa_don'] ?? 0) === $orderId) {
                $targetOrder = $order;
                break;
            }
        }

        if (!$targetOrder) {
            error_log('reorder blocked: customer=' . $maKh . ', email=' . $email . ', order=' . $orderId);
            set_flash('error', 'Không thể mua lại đơn hàng này.');
            redirect(BASE_URL . '/index.php?r=hoso');
        }

        $added = 0;
        $skipped = 0;
        $_SESSION['gio_hang'] = is_array($_SESSION['gio_hang'] ?? null) ? $_SESSION['gio_hang'] : [];

        foreach (($targetOrder['items'] ?? []) as $item) {
            $productId = trim((string)($item['ma_san_pham'] ?? ''));
            $oldQty = max(1, (int)($item['so_luong'] ?? 1));
            if ($productId === '') {
                $skipped++;
                continue;
            }

            $product = $this->sanPhamModel->findById($productId, true);
            if (!$product || !$this->sanPhamModel->isProductAvailable($product)) {
                $skipped++;
                continue;
            }

            $stock = $this->sanPhamModel->getProductStock((array)$product);
            $stock = $stock === null ? 300 : max(0, (int)$stock);
            $cartKey = (string)($product['ma_san_pham'] ?? $productId);
            $currentQty = max(0, (int)($_SESSION['gio_hang'][$cartKey] ?? 0));
            $canAdd = max(0, $stock - $currentQty);
            $qtyToAdd = min($oldQty, $canAdd);

            if ($qtyToAdd <= 0) {
                $skipped++;
                continue;
            }

            $_SESSION['gio_hang'][$cartKey] = $currentQty + $qtyToAdd;
            $added += $qtyToAdd;
            if ($qtyToAdd < $oldQty) {
                $skipped++;
            }
        }

        if ($added <= 0) {
            set_flash('error', 'Không có sản phẩm nào trong đơn hàng này còn khả dụng để mua lại.');
            redirect(BASE_URL . '/index.php?r=hoso');
        }

        set_flash('success', 'Đã thêm sản phẩm từ đơn hàng cũ vào giỏ hàng.');
        if ($skipped > 0) {
            set_flash('warning', 'Một số sản phẩm không còn khả dụng và chưa được thêm vào giỏ hàng.');
        }
        redirect(BASE_URL . '/index.php?r=giohang');
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
            if (isset($_GET['reorder'])) {
                $this->handleReorder($maKh, (string)$account['email'], max(0, (int)$_GET['reorder']));
            }
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
            $directReviewModel = new DanhGia($this->pdo);
            foreach ($orders as &$order) {
                $order['trang_thai_normalized'] = method_exists($this->reviewModel, 'normalizeOrderStatus')
                    ? $this->reviewModel->normalizeOrderStatus($order['trang_thai'] ?? '')
                    : strtolower(trim((string)($order['trang_thai'] ?? '')));
                $orderAllowsReview = (($order['trang_thai_normalized'] ?? '') === 'completed') || $this->isOrderEligibleForReview((string)($order['trang_thai'] ?? ''));
                foreach (($order['items'] ?? []) as &$item) {
                    $productId = trim((string)($item['ma_san_pham'] ?? ''));
                    $orderItemId = trim((string)($item['ma_chi_tiet_hoa_don'] ?? $item['id_chi_tiet_hoa_don'] ?? $item['idChiTietHoaDon'] ?? $item['order_item_id'] ?? $item['id'] ?? ''));
                    $eligibility = $productId !== ''
                        ? $directReviewModel->canUserReviewProduct($maKh, $productId, (string)($order['ma_hoa_don'] ?? ''), $orderItemId)
                        : ($eligibilityMap[$productId] ?? ['has_purchased' => false, 'has_reviewed' => false]);
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
