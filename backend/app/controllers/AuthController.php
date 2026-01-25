<?php
// backend/app/controllers/AuthController.php

require_once __DIR__ . "/../models/NguoiDung.php";

class AuthController {
    private PDO $pdo;
    private NguoiDung $model;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->model = new NguoiDung($pdo);
    }

    private function render(string $view, array $data = []) {
        extract($data);
        require __DIR__ . '/../views/layouts/header.php';
        require __DIR__ . '/../views/' . $view . '.php';
        require __DIR__ . '/../views/layouts/footer.php';
    }

    public function dangnhap() {
        $this->render('auth/dangnhap');
    }

    public function xulydangnhap() {
        $email = trim($_POST['email'] ?? '');
        $matkhau = $_POST['mat_khau'] ?? '';

        $u = $this->model->timTheoEmail($email);

        if (!$u || !password_verify($matkhau, $u['mat_khau'])) {
            set_flash('error', 'Email hoặc mật khẩu không đúng.');
            header("Location: " . BASE_URL . "/index.php?r=dangnhap");
            exit;
        }

        $_SESSION['user'] = [
            'id'     => $u['id'],
            'ho_ten' => $u['ho_ten'],
            'email'  => $u['email']
        ];

        set_flash('success', 'Đăng nhập thành công.');
        header("Location: " . BASE_URL . "/index.php?r=home");
        exit;
    }

    public function dangky() {
        $this->render('auth/dangky');
    }

    public function xulydangky() {
        $hoTen = trim($_POST['ho_ten'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $matkhau = $_POST['mat_khau'] ?? '';
        $matkhau2 = $_POST['mat_khau2'] ?? '';

        if ($hoTen === '' || $email === '' || $matkhau === '' || $matkhau2 === '') {
            set_flash('error', 'Vui lòng nhập đầy đủ thông tin.');
            header("Location: " . BASE_URL . "/index.php?r=dangky");
            exit;
        }

        if ($matkhau !== $matkhau2) {
            set_flash('error', 'Mật khẩu nhập lại không khớp.');
            header("Location: " . BASE_URL . "/index.php?r=dangky");
            exit;
        }

        if ($this->model->timTheoEmail($email)) {
            set_flash('error', 'Email đã tồn tại.');
            header("Location: " . BASE_URL . "/index.php?r=dangky");
            exit;
        }

        $this->model->taoMoi($hoTen, $email, $matkhau);

        set_flash('success', 'Đăng ký thành công. Vui lòng đăng nhập.');
        header("Location: " . BASE_URL . "/index.php?r=dangnhap");
        exit;
    }

    public function dangxuat() {
        unset($_SESSION['user']);
        set_flash('success', 'Đã đăng xuất.');
        header("Location: " . BASE_URL . "/index.php?r=home");
        exit;
    }
}
