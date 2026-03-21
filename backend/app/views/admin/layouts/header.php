<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SkinSyntax Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWix+LLJAJ9/2PKZ5QiAj6Ta86w+fsb2TkR4j8sQAtxTnRwE+XzQ+eJg4Q2pQ6J9iA9+6g==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        :root {
            --admin-green: #0f6b3e;
            --admin-green-dark: #0b5a34;
            --admin-green-soft: #eaf7ef;
            --admin-green-border: #c9e7d2;
            --admin-green-text: #1f5c3e;
        }

        body {
            font-family: 'Quicksand', sans-serif;
            background: #f5faf6 !important;
        }

        .admin-sidebar {
            width: 260px;
            overflow: hidden;
        }

        .admin-sidebar-nav {
            flex: 1 1 auto;
            overflow-y: auto;
            padding-bottom: 1.25rem;
        }

        .admin-main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .menu-active {
            background: linear-gradient(180deg, #eef9f1 0%, #e3f4e9 100%);
            color: var(--admin-green) !important;
            font-weight: 600;
            box-shadow: inset 0 0 0 1px rgba(15, 107, 62, 0.12);
        }

        .menu-active i {
            color: var(--admin-green);
        }

        .sidebar-section-label {
            padding: 0 1.5rem;
            margin: 1rem 0 0.55rem;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6c757d;
        }

        .sidebar-role-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.28rem 0.7rem;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.03em;
        }

        .sidebar-role-chip.role-admin {
            background: rgba(15, 107, 62, 0.12);
            color: var(--admin-green);
        }

        .sidebar-role-chip.role-staff {
            background: rgba(111, 181, 132, 0.18);
            color: var(--admin-green-text);
        }

        .sidebar-divider {
            height: 1px;
            background: #edf2f7;
            margin: 0.9rem 1.5rem;
        }

        .sidebar-menu-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.65rem 0.9rem;
            margin: 0 0.75rem 0.4rem;
            border-radius: 0.9rem;
            text-decoration: none;
            color: #6c757d;
        }

        .sidebar-menu-link:hover {
            background: #f3fbf6;
            color: #1f2937;
            box-shadow: inset 0 0 0 1px rgba(15, 107, 62, 0.08);
        }

        .sidebar-menu-link .menu-link-text {
            line-height: 1.25;
        }

        .sidebar-menu-link .menu-link-meta {
            display: block;
            font-size: 0.76rem;
            color: #94a3b8;
            margin-top: 0.12rem;
        }

        .admin-main .card {
            border: 1px solid rgba(15, 107, 62, 0.08) !important;
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.06) !important;
        }

        .admin-main .card .list-group-item {
            border-color: #edf5ef;
        }

        .admin-main .badge.bg-primary,
        .admin-main .text-bg-primary {
            background: var(--admin-green) !important;
        }

        .admin-main a.small.text-decoration-none {
            color: var(--admin-green);
            font-weight: 700;
        }

        .admin-bell-btn {
            position: relative;
            width: 42px;
            height: 42px;
            color: #2f6f4f;
            background: #f3fbf6 !important;
            border-color: var(--admin-green-border) !important;
        }

        .admin-bell-btn:hover {
            background: #e4f5ea !important;
            border-color: #7fbe98 !important;
            color: #0f6b3e;
        }

        .admin-bell-icon {
            width: 18px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 0;
        }

        .admin-bell-icon svg {
            width: 18px;
            height: 18px;
            display: block;
            stroke: currentColor;
        }

        .admin-btn-green,
        .admin-main .btn.btn-primary {
            background: var(--admin-green);
            border-color: var(--admin-green);
            color: #fff;
            box-shadow: 0 10px 20px rgba(15, 107, 62, 0.14);
        }

        .admin-btn-green:hover,
        .admin-main .btn.btn-primary:hover,
        .admin-main .btn.btn-primary:focus,
        .admin-main .btn.btn-primary:active {
            background: var(--admin-green-dark);
            border-color: var(--admin-green-dark);
            color: #fff;
        }

        .admin-btn-outline-green,
        .admin-main .btn.btn-outline-primary {
            background: #fff;
            border-color: var(--admin-green);
            color: var(--admin-green);
        }

        .admin-btn-outline-green:hover,
        .admin-main .btn.btn-outline-primary:hover,
        .admin-main .btn.btn-outline-primary:focus,
        .admin-main .btn.btn-outline-primary:active {
            background: var(--admin-green);
            border-color: var(--admin-green);
            color: #fff;
        }

        .admin-main .btn.btn-success {
            background: var(--admin-green);
            border-color: var(--admin-green);
        }

        .admin-main .btn.btn-success:hover,
        .admin-main .btn.btn-success:focus,
        .admin-main .btn.btn-success:active {
            background: var(--admin-green-dark);
            border-color: var(--admin-green-dark);
        }

        .admin-bell-badge {
            position: absolute;
            top: -5px;
            right: -2px;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            background: #dc3545;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
        }

        .admin-notification-menu {
            width: 360px;
            max-height: min(78vh, 620px);
            padding: 0;
            border: 0;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.16);
            background: #fff;
        }

        .admin-notification-header {
            padding: 16px 18px 12px;
            border-bottom: 1px solid #edf2f7;
            background: linear-gradient(180deg, #ffffff 0%, #f2fbf5 100%);
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .admin-notification-body {
            max-height: calc(min(78vh, 620px) - 76px);
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        .admin-notification-body::-webkit-scrollbar {
            width: 8px;
        }

        .admin-notification-body::-webkit-scrollbar-thumb {
            background: #cfe4d6;
            border-radius: 999px;
        }

        .admin-notification-body::-webkit-scrollbar-track {
            background: #f4f8f5;
        }

        .admin-notification-section {
            padding: 12px 18px 8px;
            position: sticky;
            top: 0;
            z-index: 1;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(6px);
        }

        .admin-notification-item {
            display: block;
            padding: 12px 18px;
            text-decoration: none;
            color: #1f2937;
            border-top: 1px solid #f1f5f9;
        }

        .admin-notification-item:hover {
            background: #f4fbf6;
        }

        .admin-notification-empty {
            padding: 18px;
            color: #64748b;
            text-align: center;
        }

        @media (max-width: 991.98px) {
            .admin-sidebar {
                position: static !important;
                width: 100%;
                height: auto !important;
            }

            .admin-main {
                margin-left: 0;
            }
        }
    </style>
</head>
<body class="bg-light">
<?php
$adminName = $_SESSION['admin_name'] ?? $_SESSION['ho_ten'] ?? 'Admin';
$currentRoute = $_GET['r'] ?? 'admin_dashboard';
$currentRole = current_role();
$notificationCenter = $notificationCenter ?? [];
$pendingOrdersCount = (int)($notificationCenter['pending_orders_count'] ?? 0);
$pendingChatsCount = (int)($notificationCenter['pending_chats_count'] ?? 0);
$unseenCount = (int)($notificationCenter['unseen_count'] ?? 0);
$notificationOrders = $notificationCenter['orders'] ?? [];
$notificationChats = $notificationCenter['chats'] ?? [];
$orderNotificationRoute = user_can_access_route('admin_orders') ? 'admin_orders' : 'staff_orders';
$isAdmin = $currentRole === 'admin';

$adminMenuItems = [
    [
        'route' => 'admin_dashboard',
        'icon' => 'fa-chart-line',
        'label' => 'Dashboard',
        'meta' => 'Tổng quan hệ thống',
        'active' => $currentRoute === 'admin_dashboard',
    ],
    [
        'route' => 'admin_sp',
        'icon' => 'fa-box-open',
        'label' => 'Quản lý sản phẩm',
        'meta' => 'CRUD và kiểm soát dữ liệu',
        'active' => strpos($currentRoute, 'admin_sp') === 0,
    ],
    [
        'route' => 'admin_categories',
        'icon' => 'fa-layer-group',
        'label' => 'Danh mục',
        'meta' => 'Nhóm và cấu trúc sản phẩm',
        'active' => strpos($currentRoute, 'admin_categories') === 0,
    ],
    [
        'route' => 'admin_vouchers',
        'icon' => 'fa-ticket',
        'label' => 'Voucher',
        'meta' => 'Mã giảm giá và ưu đãi',
        'active' => strpos($currentRoute, 'admin_voucher') === 0 || strpos($currentRoute, 'admin_vouchers') === 0,
    ],
    [
        'route' => 'admin_users',
        'icon' => 'fa-users',
        'label' => 'Quản lý người dùng',
        'meta' => 'Khách hàng, nhân viên, phân quyền',
        'active' => strpos($currentRoute, 'admin_users') === 0,
    ],
    [
        'route' => 'admin_orders',
        'icon' => 'fa-file-invoice-dollar',
        'label' => 'Đơn hàng',
        'meta' => 'Giám sát và xử lý đơn',
        'active' => strpos($currentRoute, 'admin_orders') === 0,
    ],
    [
        'route' => 'admin_reports',
        'icon' => 'fa-chart-pie',
        'label' => 'Báo cáo',
        'meta' => 'Doanh thu và hiệu suất bán hàng',
        'active' => strpos($currentRoute, 'admin_reports') === 0,
    ],
];

$staffMenuItems = [
    [
        'route' => 'staff_dashboard',
        'icon' => 'fa-headset',
        'label' => 'Dashboard nhân viên',
        'meta' => 'Công việc hỗ trợ đang chờ',
        'active' => $currentRoute === 'staff_dashboard',
    ],
    [
        'route' => 'staff_orders',
        'icon' => 'fa-truck-fast',
        'label' => 'Xử lý đơn hàng',
        'meta' => 'Theo dõi và cập nhật trạng thái',
        'active' => strpos($currentRoute, 'staff_orders') === 0,
    ],
    [
        'route' => 'staff_products',
        'icon' => 'fa-pen-to-square',
        'label' => 'Cập nhật sản phẩm',
        'meta' => 'Sửa nội dung và thông tin bán hàng',
        'active' => strpos($currentRoute, 'staff_products') === 0 || strpos($currentRoute, 'staff_product_') === 0,
    ],
    [
        'route' => 'staff_reviews',
        'icon' => 'fa-star-half-stroke',
        'label' => 'Phản hồi đánh giá',
        'meta' => 'Chăm sóc phản hồi khách hàng',
        'active' => strpos($currentRoute, 'staff_reviews') === 0 || strpos($currentRoute, 'staff_review_') === 0,
    ],
    [
        'route' => 'staff_chats',
        'icon' => 'fa-comments',
        'label' => 'Chat hỗ trợ',
        'meta' => 'Tin nhắn và hỗ trợ trực tiếp',
        'active' => strpos($currentRoute, 'staff_chats') === 0 || strpos($currentRoute, 'staff_chat_') === 0,
    ],
];
?>

<aside class="admin-sidebar position-fixed top-0 start-0 vh-100 bg-white border-end d-flex flex-column">
    <div class="p-4 border-bottom">
        <div class="fs-4 fw-bold text-dark">
            <i class="fa-solid fa-gem me-2" style="color: var(--admin-green);"></i>SkinSyntax Admin
        </div>
        <div class="mt-3 d-flex flex-wrap gap-2">
            <?php if ($isAdmin): ?>
                <span class="sidebar-role-chip role-admin">Quản trị</span>
                <span class="sidebar-role-chip role-staff">Nhân viên</span>
            <?php else: ?>
                <span class="sidebar-role-chip role-staff">Nhân viên hỗ trợ</span>
            <?php endif; ?>
        </div>
    </div>

    <nav class="py-3 admin-sidebar-nav">
        <?php if ($isAdmin): ?>
            <div class="sidebar-section-label">Khu vực quản trị</div>
            <?php foreach ($adminMenuItems as $item): ?>
                <?php if (!user_can_access_route($item['route'])) continue; ?>
                <a href="index.php?r=<?= h($item['route']) ?>" class="sidebar-menu-link <?= $item['active'] ? 'menu-active' : '' ?>" data-admin-sidebar-link="1">
                    <i class="fa-solid <?= h($item['icon']) ?>"></i>
                    <span class="menu-link-text">
                        <span><?= h($item['label']) ?></span>
                        <span class="menu-link-meta"><?= h($item['meta']) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
            <div class="sidebar-divider"></div>
            <div class="sidebar-section-label">Khu vực nhân viên hỗ trợ</div>
        <?php else: ?>
            <div class="sidebar-section-label">Chức năng nhân viên</div>
        <?php endif; ?>

        <?php foreach ($staffMenuItems as $item): ?>
            <?php if (!user_can_access_route($item['route'])) continue; ?>
            <a href="index.php?r=<?= h($item['route']) ?>" class="sidebar-menu-link <?= $item['active'] ? 'menu-active' : '' ?>" data-admin-sidebar-link="1">
                <i class="fa-solid <?= h($item['icon']) ?>"></i>
                <span class="menu-link-text">
                    <span><?= h($item['label']) ?></span>
                    <span class="menu-link-meta"><?= h($item['meta']) ?></span>
                </span>
            </a>
        <?php endforeach; ?>

        <a href="index.php?r=dangxuat" class="sidebar-menu-link">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span class="menu-link-text">
                <span>Đăng xuất</span>
                <span class="menu-link-meta">Kết thúc phiên làm việc</span>
            </span>
        </a>
    </nav>
</aside>

<div class="admin-main">
    <header class="sticky-top bg-white shadow-sm py-3 px-4 d-flex align-items-center justify-content-end gap-3">
        <div class="d-flex align-items-center gap-3">
            <a href="<?= BASE_URL ?>/index.php?r=home" class="btn admin-btn-outline-green rounded-pill d-inline-flex align-items-center gap-2">
                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                <span>Xem website</span>
            </a>
            <div class="dropdown">
                <button type="button" class="btn btn-light border admin-bell-btn rounded-circle d-inline-flex align-items-center justify-content-center" id="adminNotificationButton" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Thông báo">
                    <span class="admin-bell-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M15 18H9M17 8C17 6.67392 16.4732 5.40215 15.5355 4.46447C14.5979 3.52678 13.3261 3 12 3C10.6739 3 9.40215 3.52678 8.46447 4.46447C7.52678 5.40215 7 6.67392 7 8V10.421C7 10.9524 6.78857 11.4621 6.41252 11.8382L5.29289 12.9578C4.66271 13.588 5.10889 14.6667 6 14.6667H18C18.8911 14.6667 19.3373 13.588 18.7071 12.9578L17.5875 11.8382C17.2114 11.4621 17 10.9524 17 10.421V8Z" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M13.73 18C13.5542 18.3031 13.3018 18.5547 12.9981 18.7295C12.6945 18.9043 12.3502 18.9962 12 18.9962C11.6498 18.9962 11.3055 18.9043 11.0019 18.7295C10.6982 18.5547 10.4458 18.3031 10.27 18" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                    <?php if ($unseenCount > 0): ?>
                        <span class="admin-bell-badge" id="adminNotificationBadge"><?= h((string)($unseenCount > 99 ? '99+' : $unseenCount)) ?></span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end admin-notification-menu" aria-labelledby="adminNotificationButton">
                    <div class="admin-notification-header">
                        <div class="fw-bold text-dark">Thông báo mới</div>
                        <div class="small text-muted"><?= h((string)$pendingOrdersCount) ?> đơn hàng chờ xử lý, <?= h((string)$pendingChatsCount) ?> cuộc chat cần hỗ trợ.</div>
                    </div>

                    <div class="admin-notification-body">
                        <div class="admin-notification-section">
                            <div class="small fw-semibold text-uppercase text-muted">Đơn hàng mới</div>
                        </div>
                        <?php if (empty($notificationOrders)): ?>
                            <div class="admin-notification-empty">Chưa có đơn hàng mới cần xử lý.</div>
                        <?php else: ?>
                            <?php foreach ($notificationOrders as $order): ?>
                                <a class="admin-notification-item" href="index.php?r=<?= h($orderNotificationRoute) ?>">
                                    <div class="d-flex justify-content-between gap-3">
                                        <div>
                                            <div class="fw-semibold">Đơn #<?= h((string)($order['ma_hoa_don'] ?? '')) ?></div>
                                            <div class="small text-muted"><?= h((string)($order['ho_ten'] ?? $order['email'] ?? 'Khách hàng')) ?></div>
                                        </div>
                                        <div class="small text-muted text-end"><?= h(!empty($order['thoi_gian']) ? date('d/m H:i', strtotime((string)$order['thoi_gian'])) : '') ?></div>
                                    </div>
                                    <div class="small mt-1 text-success fw-semibold"><?= vnd($order['tong_tien'] ?? 0) ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="admin-notification-section">
                            <div class="small fw-semibold text-uppercase text-muted">Chat cần hỗ trợ</div>
                        </div>
                        <?php if (empty($notificationChats)): ?>
                            <div class="admin-notification-empty">Chưa có cuộc chat mới cần phản hồi.</div>
                        <?php else: ?>
                            <?php foreach ($notificationChats as $chat): ?>
                                <a class="admin-notification-item" href="index.php?r=staff_chats&ma_kh=<?= (int)($chat['ma_kh'] ?? 0) ?>">
                                    <div class="d-flex justify-content-between gap-3">
                                        <div class="fw-semibold"><?= h((string)($chat['ho_ten'] ?? 'Khách hàng')) ?></div>
                                        <div class="small text-muted text-end"><?= h(!empty($chat['cap_nhat_cuoi']) ? date('d/m H:i', strtotime((string)$chat['cap_nhat_cuoi'])) : '') ?></div>
                                    </div>
                                    <div class="small text-muted mt-1"><?= h((string)($chat['tin_nhan_moi'] ?? '')) ?></div>
                                    <div class="small mt-1 text-danger fw-semibold"><?= h((string)($chat['tin_chua_phan_hoi'] ?? 0)) ?> tin chưa phản hồi</div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle text-white fw-semibold" style="width: 34px; height: 34px; background: var(--admin-green);">
                    <?= htmlspecialchars(mb_substr((string)$adminName, 0, 1), ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="fw-semibold text-dark small"><?= htmlspecialchars((string)$adminName, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </header>

    <div class="px-4 pt-3">
        <?php if ($m = get_flash('success')): ?>
            <div class="alert alert-success shadow-sm"><?= h($m) ?></div>
        <?php endif; ?>
        <?php if ($m = get_flash('error')): ?>
            <div class="alert alert-danger shadow-sm"><?= h($m) ?></div>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var sidebarScrollKey = 'skinsyntaxAdminSidebarScroll';
        var pageScrollKey = 'skinsyntaxAdminPageScroll';
        var sidebarNav = document.querySelector('.admin-sidebar-nav');
        var sidebarLinks = document.querySelectorAll('[data-admin-sidebar-link]');

        if (typeof history !== 'undefined' && 'scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }

        try {
            var storedPageScroll = window.sessionStorage.getItem(pageScrollKey);
            if (storedPageScroll !== null) {
                var y = parseInt(storedPageScroll, 10);
                window.requestAnimationFrame(function () {
                    window.requestAnimationFrame(function () {
                        window.scrollTo(0, Number.isNaN(y) ? 0 : y);
                    });
                });
                window.sessionStorage.removeItem(pageScrollKey);
            }

            if (sidebarNav) {
                var storedSidebarScroll = window.sessionStorage.getItem(sidebarScrollKey);
                if (storedSidebarScroll !== null) {
                    sidebarNav.scrollTop = parseInt(storedSidebarScroll, 10) || 0;
                }
            }
        } catch (error) {
            // Ignore storage errors and keep default navigation behavior.
        }

        sidebarLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                try {
                    window.sessionStorage.setItem(pageScrollKey, String(window.scrollY || window.pageYOffset || 0));
                    if (sidebarNav) {
                        window.sessionStorage.setItem(sidebarScrollKey, String(sidebarNav.scrollTop || 0));
                    }
                } catch (error) {
                    // Ignore storage errors and continue navigation.
                }
            });
        });

        var bellButton = document.getElementById('adminNotificationButton');
        if (!bellButton) {
            return;
        }

        var markedSeen = false;
        var markSeen = function () {
            if (markedSeen) {
                return;
            }
            markedSeen = true;

            var badge = document.getElementById('adminNotificationBadge');
            if (badge) {
                badge.remove();
            }

            fetch('index.php?r=admin_notifications_seen', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).catch(function () {
                markedSeen = false;
            });
        };

        bellButton.addEventListener('click', markSeen, { once: true });
    });
    </script>
