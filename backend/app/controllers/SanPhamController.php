<?php
// backend/app/controllers/SanPhamController.php
require_once __DIR__ . '/../models/SanPham.php';

class SanPhamController {
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

    public function tatca() {
        $page = (int)($_GET['page'] ?? 1);
        $q    = trim((string)($_GET['q'] ?? ''));
        $cap1 = trim((string)($_GET['cap1'] ?? ''));
        $cap2 = trim((string)($_GET['cap2'] ?? ''));

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

        $this->render('chitiet', [
            'p' => $p,
        ]);
    }
}
