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
require_once __DIR__ . '/../app/controllers/AdminController.php';
require_once __DIR__ . '/../app/controllers/QuanTriController.php';

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

    case 'chuandaithanhtoan':
        (new HomeController($pdo))->chuandaithanhtoan();
        break;

    case 'thanhtoan':
        (new HomeController($pdo))->thanhtoan();
        break;

    case 'xulydathang':
        (new HomeController($pdo))->xulydathang();
        break;

    case 'camon':
        (new HomeController($pdo))->camon();
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

    case 'admin_dashboard':
        (new QuanTriController($pdo))->adminDashboard();
        break;

    case 'admin_sp':
        (new QuanTriController($pdo))->adminProducts();
        break;

    case 'admin_sp_create':
        (new QuanTriController($pdo))->adminProductCreate();
        break;

    case 'admin_sp_edit':
        (new QuanTriController($pdo))->adminProductEdit();
        break;

    case 'admin_sp_delete':
        (new QuanTriController($pdo))->adminProductDelete();
        break;

    case 'admin_categories':
        (new QuanTriController($pdo))->adminCategories();
        break;

    case 'admin_category_save':
        (new QuanTriController($pdo))->adminCategorySave();
        break;

    case 'admin_category_delete':
        (new QuanTriController($pdo))->adminCategoryDelete();
        break;

    case 'admin_users':
        (new QuanTriController($pdo))->adminUsers();
        break;

    case 'admin_customer_save':
        (new QuanTriController($pdo))->adminCustomerSave();
        break;

    case 'admin_customer_delete':
        (new QuanTriController($pdo))->adminCustomerDelete();
        break;

    case 'admin_staff_save':
        (new QuanTriController($pdo))->adminStaffSave();
        break;

    case 'admin_staff_delete':
        (new QuanTriController($pdo))->adminStaffDelete();
        break;

    case 'admin_orders':
        (new QuanTriController($pdo))->adminOrders();
        break;

    case 'admin_order_status':
        (new QuanTriController($pdo))->adminOrderStatus();
        break;

    case 'admin_reports':
        (new QuanTriController($pdo))->adminReports();
        break;

    case 'staff_dashboard':
        (new QuanTriController($pdo))->staffDashboard();
        break;

    case 'staff_orders':
        (new QuanTriController($pdo))->staffOrders();
        break;

    case 'staff_order_status':
        (new QuanTriController($pdo))->staffOrderStatus();
        break;

    case 'staff_products':
        (new QuanTriController($pdo))->staffProducts();
        break;

    case 'staff_product_edit':
        (new QuanTriController($pdo))->staffProductEdit();
        break;

    case 'staff_reviews':
        (new QuanTriController($pdo))->staffReviews();
        break;

    case 'staff_review_reply':
        (new QuanTriController($pdo))->staffReviewReply();
        break;

    case 'staff_chats':
        (new QuanTriController($pdo))->staffChats();
        break;

    case 'staff_chat_send':
        (new QuanTriController($pdo))->staffChatSend();
        break;

    case 'lichsuchat':
        (new QuanTriController($pdo))->customerChat();
        break;

    case 'chat_send':
        (new QuanTriController($pdo))->customerChatSend();
        break;

    case 'guidanhgia':
        (new QuanTriController($pdo))->customerReviewSave();
        break;

    case 'huydonhang':
        (new QuanTriController($pdo))->customerOrderCancel();
        break;

    default:
        // 404
        require __DIR__ . '/../app/views/layouts/header.php';
        require __DIR__ . '/../app/views/404.php';
        require __DIR__ . '/../app/views/layouts/footer.php';
        break;
}
