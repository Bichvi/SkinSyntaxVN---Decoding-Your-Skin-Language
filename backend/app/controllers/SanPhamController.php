<?php
require_once __DIR__ . '/../models/SanPham.php';

class SanPhamController {
    private PDO $pdo;
    private SanPham $model;

    public function __construct(PDO $pdo){
        $this->pdo = $pdo;
        $this->model = new SanPham($pdo);
    }

    private function render($view, $data = []) {
        extract($data);
        require __DIR__ . '/../views/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/layouts/footer.php';
    }

    public function chitiet() {
        $id = (int)($_GET['id'] ?? 0);
        $p  = $this->model->findById($id);
        if (!$p) {
            $this->render('404');
            return;
        }
        $this->render('chitiet', ['p' => $p]);
    }

    public function tatca() {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 24;
        $q = trim($_GET['q'] ?? '');
        $danh_muc = trim($_GET['danh_muc'] ?? '');

        [$items, $total] = $this->model->paginateAll($page, $perPage, $q, $danh_muc);

        $this->render('tatca', compact('items','total','page','perPage','q','danh_muc'));
    }
}
