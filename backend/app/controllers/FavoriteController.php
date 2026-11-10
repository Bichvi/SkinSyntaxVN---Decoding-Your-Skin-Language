<?php
// backend/app/controllers/FavoriteController.php
declare(strict_types=1);

require_once __DIR__ . '/../models/ProductFavorite.php';
require_once __DIR__ . '/../models/SanPham.php';

class FavoriteController {
    private $pdo;
    private ProductFavorite $favoriteModel;
    private SanPham $productModel;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->favoriteModel = new ProductFavorite($pdo);
        $this->productModel = new SanPham($pdo);
    }

    private function json(array $payload, int $status = 200): void {
        http_response_code($status);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function readInput(): array {
        $input = is_array($_POST) ? $_POST : [];
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $input = array_merge($input, $decoded);
            }
        }
        return $input;
    }

    private function accountKey(): string {
        $user = function_exists('current_user') ? (current_user() ?? []) : ($_SESSION['user'] ?? []);
        $email = trim((string)($user['email'] ?? ''));
        if ($email !== '') {
            return 'email:' . strtolower($email);
        }
        $id = trim((string)($user['id'] ?? $user['ma_nd'] ?? $user['ma_kh'] ?? ''));
        return $id !== '' ? 'user:' . $id : '';
    }

    public function toggle(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->json(['ok' => false, 'message' => 'Phương thức không được hỗ trợ.'], 405);
        }

        if (!function_exists('is_logged_in') || !is_logged_in()) {
            $redirect = (string)($_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/index.php?r=home'));
            $this->json([
                'ok' => false,
                'code' => 'login_required',
                'message' => 'Đăng nhập để lưu sản phẩm yêu thích.',
                'login_url' => BASE_URL . '/index.php?r=dangnhap&redirect=' . rawurlencode($redirect),
            ], 401);
        }

        $input = $this->readInput();
        $productId = trim((string)($input['product_id'] ?? $input['ma_san_pham'] ?? ''));
        if ($productId === '' || strlen($productId) > 80) {
            $this->json(['ok' => false, 'message' => 'Mã sản phẩm không hợp lệ.'], 422);
        }

        try {
            $product = $this->productModel->findById($productId, true);
            if (!$product) {
                $this->json(['ok' => false, 'message' => 'Sản phẩm không còn hiển thị.'], 404);
            }

            $result = $this->favoriteModel->toggle($this->accountKey(), $productId);
            $this->json([
                'ok' => true,
                'favorite' => (bool)$result['favorite'],
                'product_id' => (string)$result['product_id'],
                'message' => $result['favorite'] ? 'Đã lưu vào yêu thích.' : 'Đã bỏ khỏi yêu thích.',
            ]);
        } catch (Throwable $e) {
            error_log('favorite_toggle error: ' . $e->getMessage());
            $this->json(['ok' => false, 'message' => 'Chưa thể cập nhật yêu thích. Vui lòng thử lại.'], 503);
        }
    }

    public function index(): void {
        if (!function_exists('is_logged_in') || !is_logged_in()) {
            header('Location: ' . BASE_URL . '/index.php?r=home&auth=login');
            exit;
        }

        $favoriteProductIds = [];
        $products = [];
        $errorMessage = '';
        try {
            $favoriteProductIds = $this->favoriteModel->idsForAccount($this->accountKey());
            $products = $this->productModel->findVisibleByIds($favoriteProductIds);
        } catch (Throwable $e) {
            error_log('favorite_list error: ' . $e->getMessage());
            $errorMessage = 'Chưa thể tải danh sách yêu thích. Vui lòng thử lại sau.';
        }

        $menuCats = [];
        try {
            $menuCats = $this->productModel->menuTree();
        } catch (Throwable $e) {
            error_log('favorite layout menu MongoDB error: ' . $e->getMessage());
        }

        $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
        require $viewDir . '/layouts/header.php';
        require $viewDir . '/favorite.php';
        require $viewDir . '/layouts/footer.php';
    }
}
