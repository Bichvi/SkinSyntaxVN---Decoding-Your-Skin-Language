<?php
// backend/public/index.php
session_start();

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/helpers.php';

require_once __DIR__ . '/../app/controllers/HomeController.php';
require_once __DIR__ . '/../app/controllers/SanPhamController.php';
require_once __DIR__ . '/../app/controllers/AuthController.php';

$r = $_GET['r'] ?? 'home';

$home = new HomeController($pdo);
$sp   = new SanPhamController($pdo);
$auth = new AuthController($pdo);

switch ($r) {
  case 'home':
    $home->index();
    break;

  case 'tatca':
    $sp->tatca();
    break;

  case 'chitiet':
    $sp->chitiet();
    break;

  case 'dangnhap':
    $auth->dangnhap();
    break;

  case 'xulydangnhap':
    $auth->xulydangnhap();
    break;

  case 'dangky':
    $auth->dangky();
    break;

  case 'xulydangky':
    $auth->xulydangky();
    break;

  case 'dangxuat':
    $auth->dangxuat();
    break;

  default:
    http_response_code(404);
    require __DIR__ . '/../app/views/layouts/header.php';
    require __DIR__ . '/../app/views/404.php';
    require __DIR__ . '/../app/views/layouts/footer.php';
    break;
}
