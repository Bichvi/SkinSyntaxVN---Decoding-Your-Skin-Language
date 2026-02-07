<?php
// backend/app/controllers/HomeController.php
require_once __DIR__ . '/../models/SanPham.php';

class HomeController {
    private PDO $pdo;
    private SanPham $model;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->model = new SanPham($pdo);
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
        $sql = "SELECT danh_muc_day_du, COUNT(*)::int AS so_luong
                FROM sanpham
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
}
