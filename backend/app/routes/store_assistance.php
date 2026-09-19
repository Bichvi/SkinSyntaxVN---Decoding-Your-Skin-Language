<?php
// Shared by local XAMPP and Docker entry points. Keep legacy policy links canonical.
require_once __DIR__ . '/../controllers/SupportController.php';
require_once __DIR__ . '/../controllers/ShopAssistantController.php';

$supportAliases = [
    'huong_dan_nhan_otp'=>'otp', 'dieu_kien_giao_dich'=>'dieu-khoan',
    'chinh_sach_bao_mat'=>'bao-mat', 'xuly_du_lieu_ca_nhan'=>'bao-mat',
    'chinh_sach_xu_ly_du_lieu'=>'bao-mat', 'chinh_sach_cookie'=>'cookie',
    'quy_che_hoat_dong'=>'quy-che', 'khieu_nai'=>'khieu-nai',
    'chinh_sach_van_chuyen'=>'van-chuyen', 'doi_tra_hoan_tien'=>'doi-tra',
    'bao_hanh'=>'doi-tra', 'lien_he'=>'lien-he',
];
if (in_array($r, ['tro_giup', 'faq', 'ho_tro_khach_hang'], true) || isset($supportAliases[$r])) {
    (new SupportController($pdo))->show($supportAliases[$r] ?? null);
    return true;
}
if ($r === 'chat_purchase_action') {
    (new ShopAssistantController($pdo))->action();
    return true;
}
// Both password and social login land on home. Only a prior explicit guest
// checkout creates this short-lived flag; no arbitrary return URL is accepted.
if ($r === 'home' && is_logged_in() && isset($_SESSION['chat_checkout_resume_until'])) {
    $resume = $_SESSION['chat_checkout_resume_until'] >= time() && !empty($_SESSION['checkout_items']);
    unset($_SESSION['chat_checkout_resume_until']);
    if ($resume && !in_array(current_role(), ['admin', 'nhanvien'], true)) {
        header('Location: ' . BASE_URL . '/index.php?r=thanhtoan');
        return true;
    }
}
return false;
