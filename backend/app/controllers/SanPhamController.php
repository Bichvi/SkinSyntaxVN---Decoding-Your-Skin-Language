<?php
// backend/app/controllers/SanPhamController.php
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/TaiKhoan.php';
require_once __DIR__ . '/../models/QuanTri.php';
require_once __DIR__ . '/../models/DanhGia.php';
require_once __DIR__ . '/../models/HoiDap.php';

class SanPhamController {
    private const SEARCH_KEYWORD_MAX_LEN = 250;
    private const SEARCH_HISTORY_LIMIT = 8;

    private $pdo;
    private SanPham $model;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->model = new SanPham($pdo);
    }

    private function render($view, $data = []) {
        extract($data);
        try {
            $menuCats = $this->model->menuTree();
        } catch (Throwable $e) {
            error_log('layout menu MongoDB error: ' . $e->getMessage());
            $menuCats = [];
        }
        require __DIR__ . '/../views/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/layouts/footer.php';
    }

    private function isAjaxRequest(): bool {
        return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    private function cartCount(): int {
        $count = 0;
        foreach (($_SESSION['gio_hang'] ?? []) as $qty) {
            $count += max(0, (int)$qty);
        }
        return $count;
    }

    private function readCartRequestData(): array {
        $data = $_POST;
        $raw = file_get_contents('php://input');
        $json = [];

        if (empty($data) && is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $json = $decoded;
                $data = $decoded;
            }
        }

        return [
            'data' => is_array($data) ? $data : [],
            'raw' => is_string($raw) ? $raw : '',
            'json' => $json,
        ];
    }

    /**
     * Tạo query tìm sản phẩm "linh hoạt" theo nhiều kiểu id khác nhau.
     * Đầu vào: $productId có thể là string/int, ma_san_pham/id/_id(ObjectId string).
     * Trả về: MongoDB filter dạng ['$or' => [...]] để tìm được cả "111" và 111.
     */
    private function buildProductIdQuery($productId): array {
        return $this->model->buildProductIdQuery($productId);
    }

    /**
     * Chuẩn hoá và đọc quantity từ nhiều tên field frontend có thể gửi.
     * Ưu tiên: qty / quantity / so_luong. Default: 1.
     */
    private function readRequestedQty(array $data = []): int {
        $raw = $data['quantity'] ?? $data['so_luong'] ?? $data['qty'] ?? $_GET['quantity'] ?? $_GET['so_luong'] ?? $_GET['qty'] ?? 1;
        return max(1, (int)$raw);
    }

    private function addToCartResult(string $id, int $qty, array $debugContext = []): array {
        $id = trim($id);
        $qty = max(1, $qty);
        if ($id === '') {
            error_log('add_to_cart missing_product_id: ' . json_encode([
                'route' => $_GET['r'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'get' => $_GET,
                'post' => $_POST,
                'raw_input' => $debugContext['raw_input'] ?? '',
                'parsed_data' => $debugContext['parsed_data'] ?? [],
                'product_id' => $id,
                'qty' => $qty,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ['ok' => false, 'message' => 'Thiếu mã sản phẩm.', 'cart_count' => $this->cartCount()];
        }

        $query = $this->buildProductIdQuery($id);
        $product = null;
        $stock = null;
        $stockStatus = null;
        $cartProductId = $id;
        try {
            // Tìm sản phẩm linh hoạt theo ma_san_pham/id/_id (string/int).
            // Không phụ thuộc duy nhất 1 field vì dữ liệu crawl có thể không đồng nhất kiểu.
            $product = $this->model->findById($id, true);
            if (!$product && isset($this->pdo->san_pham)) {
                // Fallback: query trực tiếp collection nếu model findById bị lệch field/kiểu.
                $product = $this->pdo->san_pham->findOne($query);
                if ($product) {
                    $product = (array)$product;
                }
            }

            if (!$product) {
                error_log('add_to_cart product not found: ' . json_encode([
                    'route' => $_GET['r'] ?? '',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                    'get' => $_GET,
                    'post' => $_POST,
                    'raw_input' => $debugContext['raw_input'] ?? '',
                    'parsed_data' => $debugContext['parsed_data'] ?? [],
                    'product_id' => $id,
                    'qty' => $qty,
                    'query' => $query,
                    'found' => false,
                    'so_luong_ton_kho' => null,
                    'trang_thai_kho' => null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return ['ok' => false, 'message' => 'Không tìm thấy sản phẩm.', 'cart_count' => $this->cartCount()];
            }

            $productArray = (array)$product;
            $cartProductId = (string)($productArray['ma_san_pham'] ?? $productArray['id'] ?? $id);

            // Add-cart chỉ đọc field kho chính của collection san_pham.
            // Nếu dữ liệu cũ thiếu so_luong_ton_kho thì tạm mặc định 300 như yêu cầu nghiệp vụ.
            $stock = array_key_exists('so_luong_ton_kho', $productArray) && $productArray['so_luong_ton_kho'] !== null && $productArray['so_luong_ton_kho'] !== ''
                ? max(0, (int)$productArray['so_luong_ton_kho'])
                : null;
            $stockEffective = ($stock === null) ? 300 : $stock;
            $stockStatus = strtolower(trim((string)($productArray['trang_thai_kho'] ?? '')));

            if ($stockStatus === 'het_hang' || $stockEffective <= 0) {
                error_log('add_to_cart out_of_stock: ' . json_encode([
                    'route' => $_GET['r'] ?? '',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                    'get' => $_GET,
                    'post' => $_POST,
                    'raw_input' => $debugContext['raw_input'] ?? '',
                    'parsed_data' => $debugContext['parsed_data'] ?? [],
                    'product_id' => $id,
                    'cart_product_id' => $cartProductId,
                    'qty' => $qty,
                    'query' => $query,
                    'found' => true,
                    'ma_san_pham' => $productArray['ma_san_pham'] ?? null,
                    'so_luong_ton_kho' => $stock,
                    'stock_effective' => $stockEffective,
                    'trang_thai_kho' => $stockStatus,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return ['ok' => false, 'message' => 'Sản phẩm hiện đã tạm hết hàng.', 'cart_count' => $this->cartCount()];
            }

            $currentQty = (int)($_SESSION['gio_hang'][$cartProductId] ?? 0);
            if (($currentQty + $qty) > $stockEffective) {
                error_log('add_to_cart exceed_stock: ' . json_encode([
                    'route' => $_GET['r'] ?? '',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                    'get' => $_GET,
                    'post' => $_POST,
                    'raw_input' => $debugContext['raw_input'] ?? '',
                    'parsed_data' => $debugContext['parsed_data'] ?? [],
                    'product_id' => $id,
                    'cart_product_id' => $cartProductId,
                    'qty' => $qty,
                    'current_qty' => $currentQty,
                    'query' => $query,
                    'found' => true,
                    'ma_san_pham' => $productArray['ma_san_pham'] ?? null,
                    'so_luong_ton_kho' => $stock,
                    'stock_effective' => $stockEffective,
                    'trang_thai_kho' => $stockStatus,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return ['ok' => false, 'message' => 'Số lượng vượt quá tồn kho hiện có.', 'cart_count' => $this->cartCount()];
            }

            $_SESSION['gio_hang'] = $_SESSION['gio_hang'] ?? [];
            $_SESSION['gio_hang'][$cartProductId] = $currentQty + $qty;
            error_log('add_to_cart ok: ' . json_encode([
                'route' => $_GET['r'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'product_id' => $id,
                'cart_product_id' => $cartProductId,
                'qty' => $qty,
                'current_qty' => $currentQty,
                'new_qty' => $_SESSION['gio_hang'][$cartProductId],
                'ma_san_pham' => $productArray['ma_san_pham'] ?? null,
                'so_luong_ton_kho' => $stock,
                'stock_effective' => $stockEffective,
                'trang_thai_kho' => $stockStatus,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ['ok' => true, 'message' => 'Đã thêm sản phẩm vào giỏ hàng', 'cart_count' => $this->cartCount()];
        } catch (Throwable $e) {
            error_log('add_to_cart exception: ' . json_encode([
                'route' => $_GET['r'] ?? '',
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'get' => $_GET,
                'post' => $_POST,
                'raw_input' => $debugContext['raw_input'] ?? '',
                'parsed_data' => $debugContext['parsed_data'] ?? [],
                'product_id' => $id,
                'cart_product_id' => $cartProductId,
                'qty' => $qty,
                'query' => $query,
                'found' => (bool)$product,
                'ma_san_pham' => is_array($product) ? ($product['ma_san_pham'] ?? null) : null,
                'so_luong_ton_kho' => $stock,
                'trang_thai_kho' => $stockStatus,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return ['ok' => false, 'message' => 'Không thể thêm sản phẩm lúc này. Vui lòng thử lại.', 'cart_count' => $this->cartCount()];
        }
    }

    private function respondCartResult(array $result, string $fallbackUrl): void {
        if ($this->isAjaxRequest()) {
            // Trả JSON cho AJAX: phải tránh mọi output buffering/filter (vd fixMojibake)
            // vì có thể làm hỏng JSON khiến frontend parse fail và rơi vào catch().
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        set_flash(!empty($result['ok']) ? 'success' : 'error', (string)($result['message'] ?? ''));
        redirect($fallbackUrl);
    }

    public function addToCartAjax(): void {
        $request = $this->readCartRequestData();
        $data = $request['data'];
        $id = trim((string)($data['product_id'] ?? $data['ma_san_pham'] ?? $data['id'] ?? $_GET['product_id'] ?? $_GET['ma_san_pham'] ?? $_GET['id'] ?? ''));
        $qty = $this->readRequestedQty($data);
        $this->respondCartResult(
            $this->addToCartResult($id, $qty, ['raw_input' => $request['raw'], 'parsed_data' => $data]),
            BASE_URL . '/index.php?r=tatca'
        );
    }

    private function normalizeKeyword(?string $keyword): string {
        return mb_substr(trim((string)$keyword), 0, self::SEARCH_KEYWORD_MAX_LEN);
    }

    private function saveSearchHistory(string $keyword): void {
        $keyword = $this->normalizeKeyword($keyword);
        if ($keyword === '') {
            return;
        }

        $sessionHistory = $_SESSION['search_history'] ?? [];
        if (!is_array($sessionHistory)) {
            $sessionHistory = [];
        }

        $normalizedKeyword = mb_strtolower($keyword);
        $sessionHistory = array_values(array_filter(
            $sessionHistory,
            static fn($item) => mb_strtolower(trim((string)$item)) !== $normalizedKeyword
        ));

        array_unshift($sessionHistory, $keyword);
        $_SESSION['search_history'] = array_slice($sessionHistory, 0, self::SEARCH_HISTORY_LIMIT);

        if (isset($_SESSION['user']['email'])) {
            $tkModel = new TaiKhoan($this->pdo);
            $tkModel->luuLichSuTimKiem((string)$_SESSION['user']['email'], $keyword);
        }
    }

    private function getSearchHistory(): array {
        $history = [];

        $sessionHistory = $_SESSION['search_history'] ?? [];
        if (is_array($sessionHistory)) {
            foreach ($sessionHistory as $keyword) {
                $keyword = $this->normalizeKeyword((string)$keyword);
                if ($keyword !== '') {
                    $history[] = $keyword;
                }
            }
        }

        if (isset($_SESSION['user']['email'])) {
            $tkModel = new TaiKhoan($this->pdo);
            $history = array_merge(
                $history,
                $tkModel->getTuKhoaGanDay((string)$_SESSION['user']['email'], self::SEARCH_HISTORY_LIMIT)
            );
        }

        $uniqueHistory = [];
        $seen = [];
        foreach ($history as $keyword) {
            $keyword = $this->normalizeKeyword((string)$keyword);
            if ($keyword === '') {
                continue;
            }

            $normalizedKeyword = mb_strtolower($keyword);
            if (isset($seen[$normalizedKeyword])) {
                continue;
            }

            $seen[$normalizedKeyword] = true;
            $uniqueHistory[] = $keyword;

            if (count($uniqueHistory) >= self::SEARCH_HISTORY_LIMIT) {
                break;
            }
        }

        return $uniqueHistory;
    }

    public function tatca() {
        $page = (int)($_GET['page'] ?? 1);
        $q    = $this->normalizeKeyword($_GET['q'] ?? '');
        $cap1 = trim((string)($_GET['cap1'] ?? ''));
        $cap2 = trim((string)($_GET['cap2'] ?? ''));

        if ($q !== '') {
            $this->saveSearchHistory($q);
        }

        $perPage = 24;
        $res = $this->model->paginate($page, $perPage, $q, $cap1, $cap2, '', true);

        $this->render('tatca', [
            'items' => $res['items'],
            'total' => $res['total'],
            'page' => $page,
            'perPage' => $perPage,
            'q' => $q,
            'cap1' => $cap1,
            'cap2' => $cap2
        ]);
    }

    public function danhsach() {
        $page = (int)($_GET['page'] ?? 1);
        $type = trim((string)($_GET['type'] ?? ''));
        $perPage = 24;
        $items = [];
        $total = 0;
        $dbUnavailableMessage = '';
        $titleMap = [
            'best-seller' => 'Sản phẩm bán chạy nhất',
            'high-rating' => 'Sản phẩm được đánh giá cao',
            'discount' => 'Sản phẩm đang giảm giá',
            'popular' => 'Sản phẩm được nhiều người quan tâm',
            'flash-sale' => 'Flash Sale',
            'new' => 'Sản phẩm mới',
        ];

        try {
            $res = method_exists($this->model, 'getProductsByType')
                ? $this->model->getProductsByType($type, $_GET, $page, $perPage)
                : $this->model->paginate($page, $perPage, '', '', '', '', true);
            $items = $res['items'] ?? [];
            $total = (int)($res['total'] ?? 0);
        } catch (Throwable $e) {
            error_log('danhsach MongoDB error: ' . $e->getMessage());
            $dbUnavailableMessage = 'Hiện chưa thể tải danh sách sản phẩm. Vui lòng kiểm tra MongoDB hoặc thử lại sau.';
        }

        $this->render('tatca', [
            'items' => $items,
            'total' => $total,
            'page' => max(1, $page),
            'perPage' => $perPage,
            'q' => '',
            'cap1' => '',
            'cap2' => '',
            'pageTitle' => $titleMap[$type] ?? 'Danh sách sản phẩm',
            'dbUnavailableMessage' => $dbUnavailableMessage,
            'listRoute' => 'danhsach',
            'listType' => $type,
        ]);
    }

    public function chitiet() {
        $q = $this->normalizeKeyword($_GET['q'] ?? '');

        // Xử lý thêm vào giỏ hàng.
        // - Nếu là AJAX: trả JSON (không redirect) để UI hiển thị toast + cập nhật badge.
        // - Nếu submit thường: giữ redirect/flash như cũ.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_to_cart') {
            $request = $this->readCartRequestData();
            $data = $request['data'];
            $id = trim((string)($data['product_id'] ?? $data['ma_san_pham'] ?? $data['id'] ?? $_GET['id'] ?? ''));
            $qty = $this->readRequestedQty($data);
            $this->respondCartResult(
                $this->addToCartResult($id, $qty, ['raw_input' => $request['raw'], 'parsed_data' => $data]),
                BASE_URL . '/index.php?r=chitiet&id=' . urlencode($id)
            );
            return;
        }
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') {
            $this->render('404');
            return;
        }

        try {
            $p = $this->model->find($id, true);
        } catch (Throwable $e) {
            error_log('product detail MongoDB error: ' . $e->getMessage());
            $this->render('chitiet', [
                'p' => [],
                'reviews' => [],
                'reviewPermission' => ['has_purchased' => false, 'has_reviewed' => false],
                'activeTab' => trim((string)($_GET['tab'] ?? '')),
                'detailUnavailableMessage' => 'Hiện chưa thể tải chi tiết sản phẩm. Vui lòng kiểm tra MongoDB hoặc thử lại sau.',
                'reviewErrorMessage' => '',
            ]);
            return;
        }
        if (!$p) {
            $this->render('404');
            return;
        }

        // Mỗi lần truy cập trang chi tiết sẽ tăng 1 lượt xem.
        try {
            $this->model->tangLuotXem($id);
        } catch (Throwable $e) {
            error_log('product view counter MongoDB error: ' . $e->getMessage());
        }
        $p['luot_xem'] = (int)($p['luot_xem'] ?? 0) + 1;

        if ($q !== '') {
            $this->saveSearchHistory($q);
        }

        $reviewModel = new DanhGia($this->pdo);
        $questionModel = new HoiDap($this->pdo);
        $reviewPermission = [
            'has_purchased' => false,
            'has_reviewed' => false,
        ];
        $reviews = [];
        $reviewStats = [
            'average' => (float)($p['diem_danh_gia'] ?? 0),
            'total' => (int)($p['so_luong_danh_gia'] ?? 0),
            'breakdown' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
            'stars' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
            'user_review_count' => 0,
            'crawl_review_count' => (int)($p['so_luong_danh_gia'] ?? 0),
            'with_images' => 0,
        ];
        $reviewErrorMessage = '';
        $questions = [];
        $questionCount = 0;
        $questionErrorMessage = '';

        try {
            if (is_logged_in()) {
                $user = current_user() ?? [];
                $email = trim((string)($user['email'] ?? ''));
                if ($email !== '') {
                    $customer = $reviewModel->resolveCustomerByEmail($email, trim((string)($user['ho_ten'] ?? '')) ?: 'Khách hàng');
                    if ($customer && !empty($customer['ma_kh'])) {
                        $reviewPermission = $reviewModel->canUserReviewProduct((int)$customer['ma_kh'], $id);
                    }
                }
            }
            $reviewFilter = [];
            $reviewStar = (int)($_GET['review_star'] ?? 0);
            if ($reviewStar >= 1 && $reviewStar <= 5) {
                $reviewFilter['star'] = $reviewStar;
            }
            if ((string)($_GET['review_filter'] ?? '') === 'images') {
                $reviewFilter['has_image'] = true;
            }
            $reviews = $reviewModel->getReviewsByProduct($id, $reviewFilter, 1, 8);
            $reviewStats = $reviewModel->getReviewStats($id, $p);
        } catch (Throwable $e) {
            error_log('product reviews MongoDB error: ' . $e->getMessage());
            $reviewErrorMessage = 'Hiện chưa thể tải đánh giá sản phẩm. Vui lòng thử lại sau.';
        }

        try {
            $questions = $questionModel->getQuestionsByProduct($id, 20);
            $questionCount = $questionModel->countQuestionsByProduct($id);
        } catch (Throwable $e) {
            error_log('product questions MongoDB error: ' . $e->getMessage());
            $questionErrorMessage = 'Hiện chưa thể tải hỏi đáp sản phẩm. Vui lòng thử lại sau.';
        }

        $this->render('chitiet', [
            'p' => $p,
            'reviews' => $reviews,
            'reviewStats' => $reviewStats,
            'reviewPermission' => $reviewPermission,
            'questions' => $questions,
            'questionCount' => $questionCount,
            'activeTab' => trim((string)($_GET['tab'] ?? '')),
            'reviewErrorMessage' => $reviewErrorMessage,
            'questionErrorMessage' => $questionErrorMessage,
        ]);
    }

    public function liveSearch() {
        header('Content-Type: application/json; charset=utf-8');

        $q = $this->normalizeKeyword($_GET['q'] ?? '');
        $limit = (int)($_GET['limit'] ?? 8);
        $limit = max(1, min(20, $limit));

        if (mb_strlen($q) < 2) {
            echo json_encode([
                'ok' => true,
                'items' => []
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $items = $this->model->searchSuggestions($q, $limit, true);
            echo json_encode([
                'ok' => true,
                'items' => $items
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'items' => [],
                'message' => 'Không thể tải gợi ý lúc này.'
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    public function apiSmartSearch() {
        header('Content-Type: application/json; charset=utf-8');

        $q = $this->normalizeKeyword($_GET['q'] ?? '');

        try {
            if ($q === '') {
                $history = $this->getSearchHistory();

                $trending = $this->model->getTopTrending(5, true);

                echo json_encode([
                    'type' => 'zero_query',
                    'history' => $history,
                    'trending' => $trending,
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $results = $this->model->searchLive($q, 5, true);
            echo json_encode([
                'type' => 'live_search',
                'results' => $results,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'type' => 'error',
                'message' => 'Không thể tải dữ liệu tìm kiếm lúc này.'
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}


