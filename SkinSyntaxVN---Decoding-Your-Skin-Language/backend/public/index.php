<?php
// backend/public/index.php
session_start();

$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
define('BASE_URL', $baseUrl === '' ? '' : $baseUrl);

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/helpers.php';

require_once __DIR__ . '/../app/controllers/HomeController.php';
require_once __DIR__ . '/../app/controllers/SanPhamController.php';
require_once __DIR__ . '/../app/controllers/AuthController.php';
require_once __DIR__ . '/../app/controllers/TaiKhoanController.php';

$pdo = $pdo ?? null;
if (!$pdo) {
    die("DB connection not found. Check app/config/db.php");
}

$r = $_GET['r'] ?? 'home';

switch ($r) {
    case 'home':
        (new HomeController($pdo))->index();
        break;

    case 'tatca':
        (new SanPhamController($pdo))->tatca();
        break;

    case 'chitiet':
        (new SanPhamController($pdo))->chitiet();
        break;

    case 'live_search':
        (new SanPhamController($pdo))->liveSearch();
        break;

    case 'api_smart_search':
        (new SanPhamController($pdo))->apiSmartSearch();
        break;

    case 'giohang':
        (new HomeController($pdo))->giohang();
        break;

    case 'goiy':
        (new HomeController($pdo))->goiy();
        break;

    case 'xulygoiy':
        (new HomeController($pdo))->xulygoiy();
        break;

    case 'hoso':
        (new TaiKhoanController($pdo))->hoso();
        break;

    case 'capnhathosoda':
        (new TaiKhoanController($pdo))->capNhatHoSoDa();
        break;

    case 'capnhatthongtin':
        (new TaiKhoanController($pdo))->capNhatThongTin();
        break;

    case 'doimatkhau':
        (new TaiKhoanController($pdo))->doiMatKhau();
        break;

    case 'dangnhap':
        (new AuthController($pdo))->dangnhap();
        break;

    case 'xulydangnhap':
        (new AuthController($pdo))->xulydangnhap();
        break;

    case 'dangky':
        (new AuthController($pdo))->dangky();
        break;

    case 'xulydangky':
        (new AuthController($pdo))->xulydangky();
        break;

    case 'khaosat':
        (new AuthController($pdo))->khaosat();
        break;

    case 'xulykhaosat':
        (new AuthController($pdo))->xulykhaosat();
        break;

    case 'dangxuat':
        (new AuthController($pdo))->dangxuat();
        break;

    default:
        // 404
        require __DIR__ . '/../app/views/layouts/header.php';
        require __DIR__ . '/../app/views/404.php';
        require __DIR__ . '/../app/views/layouts/footer.php';
        break;
}
