<?php
// backend/app/controllers/SanPhamController.php
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/TaiKhoan.php';
require_once __DIR__ . '/../models/QuanTri.php';

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
        $scope = array_merge($data, ['menuCats' => $this->model->menuTree()]);
        require_frontend_view('layouts/header.php', $scope);
        require_frontend_view($view . '.php', $scope);
        require_frontend_view('layouts/footer.php', $scope);
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

    public function chitiet() {
        $q = $this->normalizeKeyword($_GET['q'] ?? '');

        // Xử lý thêm vào giỏ hàng
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'add_to_cart') {
            $id = trim((string)($_GET['id'] ?? ''));
            $qty = max(1, (int)($_POST['qty'] ?? 1));
            
            if ($id !== '') {
                $product = $this->model->findById($id, true);
                if (!$product || (method_exists($this->model, 'isProductAvailable') && !$this->model->isProductAvailable($product))) {
                    set_flash('error', 'San pham da het hang hoac tam ngung ban.');
                    redirect(BASE_URL . '/index.php?r=chitiet&id=' . urlencode($id));
                    return;
                }
                $stock = method_exists($this->model, 'getProductStock') ? $this->model->getProductStock($product) : null;
                $currentQty = (int)($_SESSION['gio_hang'][$id] ?? 0);
                if ($stock !== null && ($currentQty + $qty) > $stock) {
                    set_flash('error', 'So luong vuot qua ton kho hien co.');
                    redirect(BASE_URL . '/index.php?r=chitiet&id=' . urlencode($id));
                    return;
                }
                if (!isset($_SESSION['gio_hang'])) {
                    $_SESSION['gio_hang'] = [];
                }
                
                if (isset($_SESSION['gio_hang'][$id])) {
                    $_SESSION['gio_hang'][$id] += $qty;
                } else {
                    $_SESSION['gio_hang'][$id] = $qty;
                }
                
                set_flash('success', 'Đã thêm sản phẩm vào giỏ hàng!');
                redirect(BASE_URL . '/index.php?r=giohang');
                return;
            }
        }

        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') {
            $this->render('404');
            return;
        }

        $p = $this->model->find($id, true);
        if (!$p) {
            $this->render('404');
            return;
        }

        // Mỗi lần truy cập trang chi tiết sẽ tăng 1 lượt xem.
        $this->model->tangLuotXem($id);
        $p['luot_xem'] = (int)($p['luot_xem'] ?? 0) + 1;

        if ($q !== '') {
            $this->saveSearchHistory($q);
        }

        $reviewModel = new QuanTri($this->pdo);
        $reviewPermission = [
            'has_purchased' => false,
            'has_reviewed' => false,
        ];

        if (is_logged_in()) {
            $user = current_user() ?? [];
            $email = trim((string)($user['email'] ?? ''));
            if ($email !== '') {
                $customer = $reviewModel->getCustomerByEmail($email, trim((string)($user['ho_ten'] ?? '')) ?: 'Khach hang');
                if ($customer && !empty($customer['ma_kh'])) {
                    $reviewEligibility = $reviewModel->{'getCustomerReviewEligibility'}((int)$customer['ma_kh'], [$id]);
                    $reviewPermission = $reviewEligibility[$id] ?? $reviewPermission;
                }
            }
        }

        $this->render('chitiet', [
            'p' => $p,
            'reviews' => $reviewModel->getProductReviews($id),
            'reviewPermission' => $reviewPermission,
            'activeTab' => trim((string)($_GET['tab'] ?? '')),
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
