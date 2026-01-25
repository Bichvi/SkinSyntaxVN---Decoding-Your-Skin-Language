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
        require __DIR__ . '/../views/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/layouts/footer.php';
    }

    public function index() {
        $latest = $this->model->latest(8);
        $cats   = $this->model->topCategories(12);
        $this->render('home', [
            'latest' => $latest,
            'cats'   => $cats
        ]);
    }
}
