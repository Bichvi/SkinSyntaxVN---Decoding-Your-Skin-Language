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
        $this->render('giohang');
    }

    public function goiy() {
        $this->render('goiy');
    }
}
