<?php
// frontend/public/index.php - Primary Public Web Entry Point
ini_set('memory_limit', '256M');
@set_time_limit(120);
@ini_set('max_execution_time', '120');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Request GET chỉ-đọc do live-filter.js gửi khi gõ/đổi bộ lọc: nhả khóa session ngay để các request
// song song cùng phiên không phải xếp hàng. $_SESSION vẫn đọc được (đăng nhập, vai trò, giỏ hàng...).
// Enter/nút Tìm gửi thêm X-Live-Commit nên giữ khóa và ghi session bình thường (lịch sử tìm kiếm).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'live-filter'
    && !isset($_SERVER['HTTP_X_LIVE_COMMIT'])
    && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$backendRoot = is_dir(__DIR__ . '/backend/app')
    ? realpath(__DIR__ . '/backend')
    : (is_dir(__DIR__ . '/../../backend/app')
        ? realpath(__DIR__ . '/../../backend')
        : (is_dir(__DIR__ . '/../app')
            ? realpath(__DIR__ . '/..')
            : (is_dir('/var/www/html/app') ? '/var/www/html' : realpath(__DIR__))));

require_once $backendRoot . '/app/config/config.php';

$autoloadCandidates = [
    $backendRoot . '/vendor/autoload.php',
    dirname($backendRoot) . '/vendor/autoload.php',
];
foreach ($autoloadCandidates as $autoloadFile) {
    if (file_exists($autoloadFile)) {
        require_once $autoloadFile;
        break;
    }
}

$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$baseUrl = str_replace('\\', '/', $baseUrl);
if (is_dir(__DIR__ . '/frontend/public/assets') && !is_dir(__DIR__ . '/assets')) {
    $baseUrl .= '/frontend/public';
}
if (!defined('BASE_URL')) {
    define('BASE_URL', $baseUrl === '' ? '.' : $baseUrl);
}

require_once $backendRoot . '/app/config/db.php';
if (is_file($backendRoot . '/app/services/CacheService.php')) {
    require_once $backendRoot . '/app/services/CacheService.php';
}

$helpersFile = defined('VIEW_DIR') && is_file(VIEW_DIR . '/helpers.php')
    ? VIEW_DIR . '/helpers.php'
    : (is_file(__DIR__ . '/frontend/views/helpers.php')
        ? __DIR__ . '/frontend/views/helpers.php'
        : (is_file(__DIR__ . '/../views/helpers.php') ? __DIR__ . '/../views/helpers.php' : $backendRoot . '/app/helpers.php'));

if (is_file($helpersFile)) {
    require_once $helpersFile;
}

require_once $backendRoot . '/app/services/ProductEventTracker.php';
ProductEventTracker::ensureCookie();

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
ob_start(static function ($buffer) {
    return function_exists('fixMojibake') ? fixMojibake($buffer) : $buffer;
});

$r = $_GET['r'] ?? 'home';
if (in_array($r, [
    'admin_ai_idol', 'admin_ai_idol_campaigns', 'admin_ai_idol_campaign', 'admin_ai_idol_stream_campaign',
    'admin_ai_idol_create_campaign', 'admin_ai_idol_upload_asset', 'admin_ai_idol_retry_campaign',
    'admin_ai_idol_approve_script', 'admin_ai_idol_agent_runs', 'admin_ai_idol_agent_create',
    'admin_ai_idol_agent_run', 'admin_ai_idol_agent_confirm', 'admin_ai_idol_agent_retry',
    'admin_ai_idol_agent_cancel', 'admin_ai_idol_health', 'admin_ai_idol_cancel_campaign',
    'admin_ai_idol_reschedule_campaign', 'admin_ai_idol_products', 'admin_ai_idol_video_visibility',
    'admin_ai_idol_product_videos', 'admin_ai_idol_search_products',
    'admin_ai_idol_publish_video', 'admin_ai_idol_toggle_publish',
    'admin_ai_idol_reward_campaign', 'admin_ai_idol_save_reward_campaign', 'admin_ai_idol_toggle_reward_campaign',
    'admin_ai_idol_products_query', 'admin_ai_idol_approve_platform'
], true)) {
    if (require $backendRoot . '/app/routes/ai_idol.php') {
        return;
    }
}

require_once $backendRoot . '/app/controllers/HomeController.php';
require_once $backendRoot . '/app/controllers/SanPhamController.php';
require_once $backendRoot . '/app/controllers/AuthController.php';
require_once $backendRoot . '/app/controllers/TaiKhoanController.php';
require_once $backendRoot . '/app/controllers/AdminController.php';
require_once $backendRoot . '/app/controllers/QuanTriController.php';
require_once $backendRoot . '/app/controllers/AdminInventoryController.php';
require_once $backendRoot . '/app/controllers/LiveController.php';
if (is_file($backendRoot . '/app/services/VnpayService.php')) {
    require_once $backendRoot . '/app/services/VnpayService.php';
}

$r = $_GET['r'] ?? 'home';


switch ($r) {
    case 'home':
        (new HomeController($pdo))->index();
        break;

    case 'flash_sale':
        (new HomeController($pdo))->flashSale();
        break;

    case 'tatca':
        (new SanPhamController($pdo))->tatca();
        break;

    case 'danhsach':
        (new SanPhamController($pdo))->danhsach();
        break;

    case 'product_collection':
        (new HomeController($pdo))->productCollection();
        break;

    case 'chitiet':
        (new SanPhamController($pdo))->chitiet();
        break;

    case 'product_video_stream':
        (new SanPhamController($pdo))->streamProductVideo();
        break;

    case 'video_watch_start':
        require_once $backendRoot . '/app/controllers/VideoWatchController.php';
        (new VideoWatchController($pdo))->start();
        break;

    case 'video_watch_heartbeat':
        require_once $backendRoot . '/app/controllers/VideoWatchController.php';
        (new VideoWatchController($pdo))->heartbeat();
        break;

    case 'video_watch_status':
        require_once $backendRoot . '/app/controllers/VideoWatchController.php';
        (new VideoWatchController($pdo))->status();
        break;

    case 'video_watch_complete':
        require_once $backendRoot . '/app/controllers/VideoWatchController.php';
        (new VideoWatchController($pdo))->complete();
        break;

    case 'video_watch_claim_reward':
        require_once $backendRoot . '/app/controllers/VideoWatchController.php';
        (new VideoWatchController($pdo))->claimReward();
        break;

    case 'customer_vouchers':
        require_once $backendRoot . '/app/controllers/VideoWatchController.php';
        (new VideoWatchController($pdo))->customerVouchers();
        break;

    case 'them_gio_hang_ajax':
    case 'themgiohang':
    case 'them_gio_hang':
        (new SanPhamController($pdo))->addToCartAjax();
        break;

    case 'giohang':
        (new HomeController($pdo))->giohang();
        break;

    case 'chuandaithanhtoan':
        (new HomeController($pdo))->chuandaithanhtoan();
        break;

    case 'thanhtoan':
        (new HomeController($pdo))->thanhtoan();
        break;

    case 'dathang':
    case 'xulydathang':
        (new HomeController($pdo))->xulydathang();
        break;


    case 'capnhat_sl_giohang':
        (new HomeController($pdo))->capnhatSlGiohang();
        break;

    case 'xoa_gio_hang':
        (new HomeController($pdo))->xoaGioHang();
        break;

    case 'camon':
        (new HomeController($pdo))->camon();
        break;

    case 'vnpay_return':
        (new HomeController($pdo))->vnpayReturn();
        break;

    case 'vnpay_ipn':
        (new HomeController($pdo))->vnpayIpn();
        break;

    case 'vnpay_repay':
        (new HomeController($pdo))->vnpayRepay();
        break;



    case 'timkiem':
        (new SanPhamController($pdo))->tatca();
        break;

    case 'live_search':
        (new SanPhamController($pdo))->liveSearch();
        break;

    case 'api_smart_search':
        (new SanPhamController($pdo))->apiSmartSearch();
        break;

    case 'api_search_catalog_products':
        (new LiveController($pdo))->apiSearchCatalogProducts();
        break;


    case 'goiy':
        (new HomeController($pdo))->goiy();
        break;

    case 'goiy_api':
        (new HomeController($pdo))->goiyApi();
        break;

    case 'ai_chat_api':
    case 'ai_chat_assistant':
        (new HomeController($pdo))->aiChatApi();
        break;

    case 'ai_chat_stream':
        (new HomeController($pdo))->aiChatStream();
        break;

    case 'ai_clear_history':
        (new HomeController($pdo))->aiClearHistory();
        break;

    case 'ai_eval_score':
        (new HomeController($pdo))->aiEvalScore();
        break;

    case 'ai_live_metrics':
        (new HomeController($pdo))->aiLiveMetrics();
        break;

    case 'test_system':
        (new HomeController($pdo))->testSystem();
        break;

    case 'test_score_component':
        (new HomeController($pdo))->testScoreComponent();
        break;

    case 'test_match_component':
        (new HomeController($pdo))->testMatchComponent();
        break;

    case 'khaosat':
        (new AuthController($pdo))->khaosat();
        break;

    case 'khaosatnhanh':
        (new TaiKhoanController($pdo))->khaosatNhanh();
        break;

    case 'xulykhaosatnhanh':
        (new TaiKhoanController($pdo))->xulyKhaoSatNhanh();
        break;

    case 'luukhaosat':
    case 'xulykhaosat':
        (new AuthController($pdo))->xulykhaosat();
        break;

    case 'huong_dan_nhan_otp':
        (new HomeController($pdo))->otpGuide();
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

    case 'dieu_kien_giao_dich':
        (new HomeController($pdo))->termsReference();
        break;

    case 'chinh_sach_bao_mat':
        (new HomeController($pdo))->privacyReference();
        break;

    case 'xuly_du_lieu_ca_nhan':
        (new HomeController($pdo))->personalDataReference();
        break;

    case 'gui_captcha_dang_ky':
        (new AuthController($pdo))->guiCaptchaDangKy();
        break;

    case 'gui_otp_dang_ky':
        (new AuthController($pdo))->guiOtpDangKy();
        break;

    case 'xulydangky':
        (new AuthController($pdo))->xulydangky();
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

    case 'doimatkhau':
        (new TaiKhoanController($pdo))->doiMatKhau();
        break;

    case 'dangnhap':
        (new AuthController($pdo))->dangnhap();
        break;

    case 'xulydangnhap':
        (new AuthController($pdo))->xulydangnhap();
        break;

    case 'dangxuat':
        (new AuthController($pdo))->dangxuat();
        break;

    case 'hoso':
        (new TaiKhoanController($pdo))->hoso();
        break;

    case 'capnhathoso':
        (new TaiKhoanController($pdo))->capnhatHoso();
        break;

    case 'capnhat_loaida':
        (new TaiKhoanController($pdo))->capNhatLoaiDa();
        break;

    case 'diachi_macdinh':
        (new TaiKhoanController($pdo))->datDiaChiMacDinh();
        break;

    case 'xoa_diachi':
        (new TaiKhoanController($pdo))->xoaDiaChi();
        break;

    case 'huydon':
    case 'huydonhang':
        // Khách tự hủy đơn (form ở Hồ sơ → Lịch sử đơn hàng). TaiKhoanController không có huydon().
        (new QuanTriController($pdo))->customerOrderCancel();
        break;

    case 'danhgia_sanpham':
        (new TaiKhoanController($pdo))->danhgiaSanpham();
        break;

    case 'hoidap_sanpham':
        (new SanPhamController($pdo))->hoidapSanpham();
        break;

    case 'live':
        (new LiveController($pdo))->index();
        break;

    case 'api_live_token':
        (new LiveController($pdo))->apiLivekitToken();
        break;

    case 'api_live_products':
        (new LiveController($pdo))->apiLiveProducts();
        break;

    case 'admin_live_pin_product':
        (new LiveController($pdo))->adminLivePinProduct();
        break;

    case 'api_upload_live_recording':
        (new LiveController($pdo))->apiUploadLiveRecording();
        break;


    case 'admin_live_add_deal':
        (new LiveController($pdo))->adminLiveAddDeal();
        break;

    case 'admin_live_delete_deal':
        (new LiveController($pdo))->adminLiveDeleteDeal();
        break;

    case 'api_live_add_to_cart':
        (new LiveController($pdo))->apiLiveAddToCart();
        break;

    case 'api_live_chat':
        (new LiveController($pdo))->apiLiveChat();
        break;

    case 'api_live_chat_history':
        (new LiveController($pdo))->apiLiveChatHistory();
        break;

    case 'api_live_ping':
        (new LiveController($pdo))->apiLivePing();
        break;

    case 'api_live_active_viewers':
        (new LiveController($pdo))->apiLiveActiveViewers();
        break;

    case 'admin':
    case 'admin_dashboard':
        (new QuanTriController($pdo))->adminDashboard();
        break;

    case 'admin_login':
        (new AuthController($pdo))->dangnhap();
        break;

    case 'admin_logout':
        (new AuthController($pdo))->logout();
        break;

    case 'admin_sp':
        (new QuanTriController($pdo))->adminProducts();
        break;

    case 'admin_sp_them':
        (new QuanTriController($pdo))->adminProductCreate();
        break;

    case 'admin_sp_sua':
        (new QuanTriController($pdo))->adminProductEdit();
        break;

    case 'admin_sp_xoa':
        (new QuanTriController($pdo))->adminProductDelete();
        break;

    case 'admin_sp_visibility':
        (new QuanTriController($pdo))->adminProductVisibility();
        break;

    case 'admin_sp_stock':
        (new QuanTriController($pdo))->adminProductStock();
        break;

    case 'admin_inventory_batches':
        (new AdminInventoryController($pdo))->index();
        break;

    case 'admin_inventory_auto_seed':
        (new AdminInventoryController($pdo))->autoSeed();
        break;

    case 'admin_inventory_diverse_seed':
        (new AdminInventoryController($pdo))->seedDiverse();
        break;

    case 'admin_inventory_fixed_expiry_seed':
        (new AdminInventoryController($pdo))->seedFixedExpiry();
        break;

    case 'admin_inventory_batch_save':
        (new AdminInventoryController($pdo))->saveBatch();
        break;

    case 'admin_inventory_movement':
        (new AdminInventoryController($pdo))->addMovement();
        break;

    case 'admin_orders':
        (new QuanTriController($pdo))->adminOrders();
        break;

    case 'admin_order_update_status':
    case 'admin_order_status':
        (new QuanTriController($pdo))->adminOrderStatus();
        break;

    case 'admin_order_print':
        (new QuanTriController($pdo))->adminOrderPrint();
        break;

    case 'admin_flash_sales':
        (new QuanTriController($pdo))->adminFlashSales();
        break;

    case 'admin_flash_sale_create':
        (new QuanTriController($pdo))->adminFlashSaleCreate();
        break;

    case 'admin_flash_sale_edit':
        (new QuanTriController($pdo))->adminFlashSaleEdit();
        break;

    case 'admin_flash_sale_save':
        (new QuanTriController($pdo))->adminFlashSaleSave();
        break;

    case 'admin_flash_sale_toggle':
        (new QuanTriController($pdo))->adminFlashSaleToggle();
        break;

    case 'admin_flash_sale_delete':
        (new QuanTriController($pdo))->adminFlashSaleDelete();
        break;

    case 'admin_flash_sale_detail':
        (new QuanTriController($pdo))->adminFlashSaleDetail();
        break;

    case 'api_admin_flash_sale_search_products':
        (new QuanTriController($pdo))->apiAdminFlashSaleSearchProducts();
        break;

    case 'admin_vouchers':
        (new QuanTriController($pdo))->adminVouchers();
        break;

    case 'admin_lives':
        (new QuanTriController($pdo))->adminLives();
        break;

    case 'admin_live_create':
        (new QuanTriController($pdo))->adminLiveCreate();
        break;

    case 'admin_live_edit':
        (new QuanTriController($pdo))->adminLiveEdit();
        break;

    case 'admin_live_status':
        (new QuanTriController($pdo))->adminLiveStatus();
        break;

    case 'admin_live_delete':
        (new QuanTriController($pdo))->adminLiveDelete();
        break;

    case 'admin_live_update_recording':
        (new QuanTriController($pdo))->adminLiveUpdateRecording();
        break;

    case 'admin_voucher_them':
    case 'admin_voucher_save':
        (new QuanTriController($pdo))->adminVoucherSave();
        break;

    case 'admin_voucher_sua':
        (new QuanTriController($pdo))->adminVouchers();
        break;

    case 'admin_voucher_xoa':
    case 'admin_voucher_delete':
        (new QuanTriController($pdo))->adminVoucherDelete();
        break;

    case 'admin_categories':
        (new QuanTriController($pdo))->adminCategories();
        break;

    case 'admin_category_them':
    case 'admin_category_save':
        (new QuanTriController($pdo))->adminCategorySave();
        break;

    case 'admin_category_sua':
        (new QuanTriController($pdo))->adminCategories();
        break;

    case 'admin_category_xoa':
    case 'admin_category_delete':
        (new QuanTriController($pdo))->adminCategoryDelete();
        break;

    case 'admin_users':
        (new QuanTriController($pdo))->adminUsers();
        break;

    case 'admin_user_them':
    case 'admin_customer_save':
        (new QuanTriController($pdo))->adminCustomerSave();
        break;

    case 'admin_user_sua':
        (new QuanTriController($pdo))->adminUsers();
        break;

    case 'admin_user_xoa':
    case 'admin_customer_delete':
        (new QuanTriController($pdo))->adminCustomerDelete();
        break;

    case 'admin_staff_save':
        (new QuanTriController($pdo))->adminStaffSave();
        break;

    case 'admin_staff_delete':
        (new QuanTriController($pdo))->adminStaffDelete();
        break;

    case 'admin_reviews':
        (new QuanTriController($pdo))->adminReviews();
        break;

    case 'admin_review_duyet':
        (new QuanTriController($pdo))->adminReviewApprove();
        break;

    case 'admin_review_an':
        (new QuanTriController($pdo))->adminReviewHide();
        break;

    case 'admin_review_xoa':
        (new QuanTriController($pdo))->adminReviewDelete();
        break;

    case 'admin_review_reply':
        (new QuanTriController($pdo))->adminReviewReply();
        break;

    case 'admin_reports':
        (new QuanTriController($pdo))->adminReports();
        break;

    case 'admin_questions':
        (new QuanTriController($pdo))->adminQuestions();
        break;

    case 'admin_question_reply':
        (new QuanTriController($pdo))->adminQuestionReply();
        break;

    case 'admin_question_hide':
        (new QuanTriController($pdo))->adminQuestionHide();
        break;

    case 'admin_notifications_seen':
        (new QuanTriController($pdo))->markNotificationsSeen();
        break;

    case 'admin_sepay_webhook':
        (new HomeController($pdo))->paymentWebhook();
        break;

    case 'admin_sepay_manual_sync':
        (new HomeController($pdo))->adminSepayManualSync();
        break;

    case 'staff_dashboard':
        (new QuanTriController($pdo))->staffDashboard();
        break;

    case 'staff_orders':
        (new QuanTriController($pdo))->staffOrders();
        break;

    case 'staff_order_update_status':
    case 'staff_order_status':
        (new QuanTriController($pdo))->staffOrderStatus();
        break;

    case 'staff_products':
        (new QuanTriController($pdo))->staffProducts();
        break;

    case 'staff_sp_them':
    case 'staff_product_create':
        (new QuanTriController($pdo))->staffProductCreate();
        break;

    case 'staff_sp_sua':
    case 'staff_product_edit':
        (new QuanTriController($pdo))->staffProductEdit();
        break;

    case 'staff_sp_xoa':
    case 'staff_product_delete':
        (new QuanTriController($pdo))->staffProductDelete();
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
        (new QuanTriController($pdo))->staffChatState();
        break;

    case 'staff_chat_send':
        (new QuanTriController($pdo))->staffChatSend();
        break;

    default:
        // Route trợ giúp/hỗ trợ và chat mua hàng chỉ nhận các route chưa có `case` riêng ở trên.
        if (require $backendRoot . '/app/routes/store_assistance.php') {
            break;
        }
        http_response_code(404);
        $view404 = defined('VIEW_DIR') ? VIEW_DIR . '/404.php' : __DIR__ . '/../views/404.php';
        if (file_exists($view404)) {
            require $view404;
        } else {
            echo '404 - Trang khong ton tai';
        }
        break;
}
