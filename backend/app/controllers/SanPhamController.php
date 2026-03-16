<?php
// backend/app/controllers/SanPhamController.php
require_once __DIR__ . '/../models/SanPham.php';
require_once __DIR__ . '/../models/TaiKhoan.php';

class SanPhamController {
    private const SEARCH_KEYWORD_MAX_LEN = 250;

    private PDO $pdo;
    private SanPham $model;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->model = new SanPham($pdo);
    }

    private function render($view, $data = []) {
        extract($data);
        $menuCats = $this->model->menuTree();
        require __DIR__ . '/../views/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/layouts/footer.php';
    }

    private function normalizeKeyword(?string $keyword): string {
        return mb_substr(trim((string)$keyword), 0, self::SEARCH_KEYWORD_MAX_LEN);
    }

    public function tatca() {
        $page = (int)($_GET['page'] ?? 1);
        $q    = $this->normalizeKeyword($_GET['q'] ?? '');
        $cap1 = trim((string)($_GET['cap1'] ?? ''));
        $cap2 = trim((string)($_GET['cap2'] ?? ''));

        if ($q !== '' && isset($_SESSION['user']['email'])) {
            $tkModel = new TaiKhoan($this->pdo);
            $tkModel->luuLichSuTimKiem($_SESSION['user']['email'], $q);
        }

        $perPage = 24;
        $res = $this->model->paginate($page, $perPage, $q, $cap1, $cap2);

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

        $p = $this->model->find($id);
        if (!$p) {
            $this->render('404');
            return;
        }

        // Mỗi lần truy cập trang chi tiết sẽ tăng 1 lượt xem.
        $this->model->tangLuotXem($id);
        $p['luot_xem'] = (int)($p['luot_xem'] ?? 0) + 1;

        if ($q !== '' && isset($_SESSION['user']['email'])) {
            $tkModel = new TaiKhoan($this->pdo);
            $tkModel->luuLichSuTimKiem($_SESSION['user']['email'], $q);
        }

        $this->render('chitiet', [
            'p' => $p,
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
            $items = $this->model->searchSuggestions($q, $limit);
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
                $history = [];
                if (isset($_SESSION['user']['email'])) {
                    $tkModel = new TaiKhoan($this->pdo);
                    $history = $tkModel->getTuKhoaGanDay((string)$_SESSION['user']['email'], 8);
                }

                $trending = $this->model->getTopTrending(5);

                echo json_encode([
                    'type' => 'zero_query',
                    'history' => $history,
                    'trending' => $trending,
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $results = $this->model->searchLive($q, 5);
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
