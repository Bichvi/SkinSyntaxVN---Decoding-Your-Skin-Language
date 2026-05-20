<?php
// backend/public/index.php
ini_set('memory_limit', '256M');
session_start();

require_once __DIR__ . '/../app/config/config.php';

// Load Composer autoload if present (needed for MongoDB client library)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if (!defined('BASE_URL')) {
    define('BASE_URL', $baseUrl === '' ? '' : $baseUrl);
}

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

    case 'ai_chat_assistant':
        $controller = new HomeController($pdo);
        $controller->{'aiChatAssistant'}();
        break;

    case 'huong_dan_nhan_otp':
        (new HomeController($pdo))->otpGuide();
        break;

    case 'dieu_kien_giao_dich':
        (new HomeController($pdo))->termsReference();
        break;

    case 'chinh_sach_bao_mat':
        (new HomeController($pdo))->privacyReference();
        break;

    case 'chinh_sach_xu_ly_du_lieu':
        (new HomeController($pdo))->personalDataReference();
        break;

    case 'he_thong_cua_hang':
        (new HomeController($pdo))->storeNetwork();
        break;

    case 'bao_hanh':
        (new HomeController($pdo))->warrantyCenter();
        break;

    case 'ho_tro_khach_hang':
        (new HomeController($pdo))->customerSupport();
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

    case 'apdung_voucher':
        (new HomeController($pdo))->apDungVoucher();
        break;

    case 'bo_voucher':
        (new HomeController($pdo))->boVoucher();
        break;

    case 'apdung_diem':
        (new HomeController($pdo))->apDungDiem();
        break;

    case 'bo_diem':
        (new HomeController($pdo))->boDiem();
        break;

    case 'xulydathang':
        (new HomeController($pdo))->xulydathang();
        break;

    case 'camon':
        (new HomeController($pdo))->camon();
        break;

    case 'payment_autocheck':
        (new HomeController($pdo))->paymentAutoCheck();
        break;

    case 'payment_webhook':
        (new HomeController($pdo))->paymentWebhook();
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

    case 'quen_mat_khau':
        (new AuthController($pdo))->quenMatKhau();
        break;

    case 'gui_lien_ket_dat_lai':
        (new AuthController($pdo))->guiLienKetDatLai();
        break;

    case 'dat_lai_mat_khau':
        (new AuthController($pdo))->datLaiMatKhau();
        break;

    case 'auth_social':
        (new AuthController($pdo))->authSocial();
        break;

    case 'auth_social_callback':
        (new AuthController($pdo))->authSocialCallback();
        break;

    case 'dangky':
        (new AuthController($pdo))->dangky();
        break;

    case 'gui_otp_dang_ky':
        (new AuthController($pdo))->guiOtpDangKy();
        break;

    case 'gui_captcha_dang_ky':
        $controller = new AuthController($pdo);
        $controller->{'guiCaptchaDangKy'}();
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

    case 'admin_sp_visibility':
        (new QuanTriController($pdo))->adminProductVisibility();
        break;

    case 'admin_categories':
        (new QuanTriController($pdo))->adminCategories();
        break;

    case 'admin_vouchers':
        (new QuanTriController($pdo))->adminVouchers();
        break;

    case 'admin_category_save':
        (new QuanTriController($pdo))->adminCategorySave();
        break;

    case 'admin_voucher_save':
        (new QuanTriController($pdo))->adminVoucherSave();
        break;

    case 'admin_category_delete':
        (new QuanTriController($pdo))->adminCategoryDelete();
        break;

    case 'admin_voucher_delete':
        (new QuanTriController($pdo))->adminVoucherDelete();
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

    case 'admin_staff_hard_delete':
        (new QuanTriController($pdo))->adminStaffHardDelete();
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

    case 'admin_notifications_seen':
        $controller = new QuanTriController($pdo);
        $controller->{'markNotificationsSeen'}();
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

    case 'staff_product_create':
        (new QuanTriController($pdo))->staffProductCreate();
        break;

    case 'staff_product_edit':
        (new QuanTriController($pdo))->staffProductEdit();
        break;

    case 'staff_product_visibility':
        (new QuanTriController($pdo))->staffProductVisibility();
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

    case 'staff_chat_state':
        $controller = new QuanTriController($pdo);
        $controller->{'staffChatState'}();
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

    case 'mark_chat_read':
        (new QuanTriController($pdo))->markChatRead();
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
