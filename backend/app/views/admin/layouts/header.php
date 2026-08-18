<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SkinSyntaxVN Admin Center</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWix+LLJAJ9/2PKZ5QiAj6Ta86w+fsb2TkR4j8sQAtxTnRwE+XzQ+eJg4Q2pQ6J9iA9+6g==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/admin.css">
    <script>
        (function() {
            var theme = localStorage.getItem('skinsyntax_admin_theme') || 'light';
            if (theme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
            var collapsed = localStorage.getItem('skinsyntax_admin_collapsed') === 'true';
            if (collapsed) {
                document.documentElement.classList.add('sidebar-is-collapsed');
            }
        })();
    </script>
</head>
<body class="admin-body">
<?php
$adminName = $_SESSION['admin_name'] ?? $_SESSION['ho_ten'] ?? 'Admin';
$currentRoute = $_GET['r'] ?? 'admin_dashboard';
$currentRole = current_role();
$notificationCenter = $notificationCenter ?? [];
$pendingOrdersCount = (int)($notificationCenter['pending_orders_count'] ?? 0);
$pendingChatsCount = (int)($notificationCenter['pending_chats_count'] ?? 0);
$unseenCount = (int)($notificationCenter['unseen_count'] ?? 0);
$notificationOrders = $notificationCenter['orders'] ?? [];
$notificationReviews = $notificationCenter['reviews'] ?? [];
$notificationQuestions = $notificationCenter['questions'] ?? [];
$notificationChats = $notificationCenter['chats'] ?? [];
$orderNotificationRoute = user_can_access_route('admin_orders') ? 'admin_orders' : 'staff_orders';
$isAdmin = $currentRole === 'admin';

$formatAdminNoticeDate = static function ($value): string {
    if ($value instanceof \MongoDB\BSON\UTCDateTime) {
        return $value->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m H:i');
    }
    $text = trim((string)($value ?? ''));
    if ($text === '' || $text === '0') {
        return '';
    }
    $timestamp = strtotime($text);
    return ($timestamp !== false && $timestamp > 0) ? date('d/m H:i', $timestamp) : '';
};

// Grouped Menu Navigation Architecture
$menuGroups = [
    [
        'title' => 'Tổng quan',
        'items' => [
            [
                'route' => 'admin_dashboard',
                'icon' => 'bi bi-grid-1x2-fill',
                'label' => 'Dashboard System',
                'meta' => 'Tổng quan hệ thống',
                'active' => $currentRoute === 'admin_dashboard',
            ]
        ]
    ],
    [
        'title' => 'Kinh doanh',
        'items' => [
            [
                'route' => 'admin_sp',
                'icon' => 'bi bi-box-seam-fill',
                'label' => 'Sản phẩm',
                'meta' => 'Danh sách & Tồn kho',
                'active' => strpos($currentRoute, 'admin_sp') === 0,
            ],
            [
                'route' => 'admin_categories',
                'icon' => 'bi bi-layers-fill',
                'label' => 'Danh mục',
                'meta' => 'Cấu trúc ngành hàng',
                'active' => strpos($currentRoute, 'admin_categories') === 0,
            ],
            [
                'route' => 'admin_vouchers',
                'icon' => 'bi bi-ticket-perforated-fill',
                'label' => 'Voucher',
                'meta' => 'Mã giảm giá & ưu đãi',
                'active' => strpos($currentRoute, 'admin_voucher') === 0 || strpos($currentRoute, 'admin_vouchers') === 0,
            ],
            [
                'route' => 'admin_orders',
                'icon' => 'bi bi-receipt-cutoff',
                'label' => 'Đơn hàng',
                'meta' => 'Giám sát & xử lý đơn',
                'active' => strpos($currentRoute, 'admin_orders') === 0,
            ]
        ]
    ],
    [
        'title' => 'Khách hàng',
        'items' => [
            [
                'route' => 'admin_users',
                'icon' => 'bi bi-people-fill',
                'label' => 'Khách hàng & User',
                'meta' => 'Tài khoản & Phân quyền',
                'active' => strpos($currentRoute, 'admin_users') === 0,
            ],
            [
                'route' => 'staff_reviews',
                'icon' => 'bi bi-star-half',
                'label' => 'Đánh giá sản phẩm',
                'meta' => 'Chăm sóc phản hồi',
                'active' => strpos($currentRoute, 'staff_reviews') === 0 || strpos($currentRoute, 'staff_review_') === 0,
            ],
            [
                'route' => 'admin_questions',
                'icon' => 'bi bi-question-circle-fill',
                'label' => 'Hỏi đáp sản phẩm',
                'meta' => 'Giải đáp thắc mắc',
                'active' => strpos($currentRoute, 'admin_question') === 0,
            ],
            [
                'route' => 'staff_chats',
                'icon' => 'bi bi-chat-dots-fill',
                'label' => 'Chat hỗ trợ',
                'meta' => 'Tư vấn trực tiếp',
                'active' => strpos($currentRoute, 'staff_chats') === 0 || strpos($currentRoute, 'staff_chat_') === 0,
            ]
        ]
    ],
    [
        'title' => 'Hệ thống',
        'items' => [
            [
                'route' => 'staff_dashboard',
                'icon' => 'bi bi-headset',
                'label' => 'Dashboard nhân viên',
                'meta' => 'Hỗ trợ công việc',
                'active' => $currentRoute === 'staff_dashboard',
            ],
            [
                'route' => 'admin_reports',
                'icon' => 'bi bi-pie-chart-fill',
                'label' => 'Báo cáo thống kê',
                'meta' => 'Doanh thu & hiệu suất',
                'active' => strpos($currentRoute, 'admin_reports') === 0,
            ]
        ]
    ]
];

// Determine Current Page Label for Breadcrumb
$pageTitleDisplay = 'Tổng quan hệ thống';
foreach ($menuGroups as $group) {
    foreach ($group['items'] as $item) {
        if ($item['active']) {
            $pageTitleDisplay = $item['label'];
            break 2;
        }
    }
}
?>

<div class="admin-sidebar-backdrop" id="sidebarBackdrop"></div>

<aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-sidebar-header">
        <a href="index.php?r=admin_dashboard" class="admin-brand-logo">
            <div class="admin-brand-icon">
                <i class="bi bi-stars fs-5"></i>
            </div>
            <div class="admin-brand-text">
                <span class="admin-brand-title">SkinSyntaxVN</span>
                <span class="admin-brand-subtitle">Commerce Center</span>
            </div>
        </a>
    </div>

    <nav class="admin-sidebar-nav">
        <?php foreach ($menuGroups as $group): ?>
            <?php
            $accessibleItems = array_filter($group['items'], static function ($item) {
                return user_can_access_route($item['route']);
            });
            if (empty($accessibleItems)) continue;
            ?>
            <div class="sidebar-section-label"><?= h($group['title']) ?></div>
            <?php foreach ($accessibleItems as $item): ?>
                <a href="index.php?r=<?= h($item['route']) ?>" class="sidebar-menu-link <?= $item['active'] ? 'menu-active' : '' ?>" data-admin-sidebar-link="1" title="<?= h($item['label']) ?>">
                    <i class="<?= h($item['icon']) ?>"></i>
                    <span class="menu-link-text">
                        <span><?= h($item['label']) ?></span>
                        <span class="menu-link-meta"><?= h($item['meta']) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="p-3 border-top mt-auto">
        <a href="index.php?r=dangxuat" class="sidebar-menu-link text-danger m-0" style="border-radius: 12px;">
            <i class="bi bi-box-arrow-right fs-5"></i>
            <span class="menu-link-text">
                <span class="fw-bold">Đăng xuất</span>
                <span class="menu-link-meta">Kết thúc phiên</span>
            </span>
        </a>
    </div>
</aside>

<div class="admin-main">
    <header class="admin-top-header">
        <div class="d-flex align-items-center gap-3">
            <button type="button" class="admin-toggle-sidebar-btn" id="sidebarToggleBtn" aria-label="Toggle Sidebar" title="Thu gọn/Mở rộng Sidebar">
                <i class="bi bi-list fs-5"></i>
            </button>
            <div class="d-none d-sm-block">
                <div class="small text-muted fw-bold">Admin / <?= h($pageTitleDisplay) ?></div>
                <h1 class="admin-page-title"><?= h($pageTitleDisplay) ?></h1>
            </div>
            <!-- Header Quick Search Bar -->
            <form class="d-none d-lg-flex align-items-center ms-3" method="get" action="index.php" style="width: 240px;">
                <input type="hidden" name="r" value="admin_orders">
                <div class="input-group input-group-sm rounded-pill overflow-hidden border shadow-xs" style="background: var(--admin-card-bg);">
                    <span class="input-group-text bg-transparent border-0 pe-1 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control border-0 bg-transparent ps-1 small" name="q" placeholder="Tìm nhanh đơn..." aria-label="Quick Search">
                </div>
            </form>
        </div>

        <div class="d-flex align-items-center gap-3">
            <!-- AsmrProg Style Theme Toggler Pill -->
            <div class="theme-toggler" id="themeToggleBtn" title="Chuyển chế độ Sáng/Tối">
                <span class="theme-option active" id="themeSunOpt"><i class="bi bi-sun-fill"></i></span>
                <span class="theme-option" id="themeMoonOpt"><i class="bi bi-moon-stars-fill"></i></span>
            </div>

            <!-- Website Link -->
            <a href="<?= BASE_URL ?>/index.php?r=home" target="_blank" class="btn btn-sm rounded-pill px-3 fw-bold border" style="background: var(--admin-accent); color: var(--admin-primary); border-color: var(--admin-accent-border) !important;" title="Mở trang chủ khách hàng">
                <i class="bi bi-box-arrow-up-right me-1"></i>
                <span class="d-none d-md-inline">Xem Website</span>
            </a>

            <!-- Notification Bell Dropdown -->
            <div class="dropdown position-relative">
                <button type="button" class="admin-bell-btn" id="adminNotificationButton" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Thông báo">
                    <i class="bi bi-bell-fill fs-6"></i>
                    <?php if ($unseenCount > 0): ?>
                        <span class="admin-bell-badge" id="adminNotificationBadge"><?= h((string)($unseenCount > 99 ? '99+' : $unseenCount)) ?></span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end admin-notification-menu shadow-lg" aria-labelledby="adminNotificationButton">
                    <div class="admin-notification-header d-flex align-items-center justify-content-between">
                        <div>
                            <div class="fw-bold text-dark">Thông báo hệ thống</div>
                            <div class="small text-muted"><?= h((string)$pendingOrdersCount) ?> đơn chờ, <?= h((string)$pendingChatsCount) ?> chat hỗ trợ.</div>
                        </div>
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none fw-bold text-success" id="markAllReadBtn" style="font-size: 0.75rem;">
                            Đã đọc
                        </button>
                    </div>

                    <div class="admin-notification-body">
                        <div class="admin-notification-section">
                            <div class="small fw-semibold text-uppercase text-muted">Đơn hàng mới</div>
                        </div>
                        <?php if (empty($notificationOrders)): ?>
                            <div class="admin-notification-empty">Chưa có đơn hàng mới cần xử lý.</div>
                        <?php else: ?>
                            <?php foreach ($notificationOrders as $order): ?>
                                <a class="admin-notification-item" href="index.php?r=<?= h($orderNotificationRoute) ?>&detail=<?= (int)($order['ma_hoa_don'] ?? 0) ?>">
                                    <div class="d-flex justify-content-between gap-3">
                                        <div>
                                            <div class="fw-semibold"><?= h((string)($order['tieu_de_thong_bao'] ?? 'Đơn hàng')) ?> #<?= h((string)($order['ma_hoa_don'] ?? '')) ?></div>
                                            <div class="small text-muted"><?= h((string)($order['ho_ten'] ?? $order['email'] ?? 'Khách hàng')) ?></div>
                                        </div>
                                        <div class="small text-muted text-end"><?= h($formatAdminNoticeDate($order['thoi_gian'] ?? null)) ?></div>
                                    </div>
                                    <?php if (!empty($order['noi_dung_thong_bao'])): ?><div class="small text-muted mt-1"><?= h((string)$order['noi_dung_thong_bao']) ?></div><?php endif; ?>
                                    <div class="small mt-1 text-success fw-semibold"><?= vnd($order['tong_tien'] ?? 0) ?></div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="admin-notification-section">
                            <div class="small fw-semibold text-uppercase text-muted">Đánh giá mới</div>
                        </div>
                        <?php if (empty($notificationReviews)): ?>
                            <div class="admin-notification-empty">Chưa có đánh giá mới cần phản hồi.</div>
                        <?php else: ?>
                            <?php foreach ($notificationReviews as $notice): ?>
                                <a class="admin-notification-item" href="index.php?r=staff_reviews&detail=<?= (int)($notice['ma_danh_gia'] ?? 0) ?>">
                                    <div class="d-flex justify-content-between gap-3">
                                        <div>
                                            <div class="fw-semibold"><?= h((string)($notice['tieu_de'] ?? 'Đánh giá mới')) ?></div>
                                            <div class="small text-muted"><?= h((string)($notice['noi_dung'] ?? '')) ?></div>
                                        </div>
                                        <div class="small text-muted text-end"><?= h($formatAdminNoticeDate($notice['thoi_gian'] ?? null)) ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <div class="admin-notification-section">
                            <div class="small fw-semibold text-uppercase text-muted">Hỏi đáp mới</div>
                        </div>
                        <?php if (empty($notificationQuestions)): ?>
                            <div class="admin-notification-empty">Chưa có câu hỏi sản phẩm mới.</div>
                        <?php else: ?>
                            <?php foreach ($notificationQuestions as $notice): ?>
                                <a class="admin-notification-item" href="<?= h((string)($notice['link'] ?? 'index.php?r=admin_questions')) ?>">
                                    <div class="d-flex justify-content-between gap-3">
                                        <div>
                                            <div class="fw-semibold"><?= h((string)($notice['tieu_de'] ?? 'Hỏi đáp mới')) ?></div>
                                            <div class="small text-muted"><?= h((string)($notice['noi_dung'] ?? '')) ?></div>
                                        </div>
                                        <div class="small text-muted text-end"><?= h($formatAdminNoticeDate($notice['thoi_gian'] ?? null)) ?></div>
                                    </div>
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

            <!-- Admin Profile Dropdown -->
            <div class="dropdown">
                <button type="button" class="btn p-1 d-flex align-items-center gap-2 border-0 bg-transparent" id="adminProfileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle text-white fw-bold shadow-sm" style="width: 38px; height: 38px; background: linear-gradient(135deg, var(--admin-primary) 0%, var(--admin-primary-dark) 100%); font-size: 1rem;">
                        <?= htmlspecialchars(mb_substr((string)$adminName, 0, 1), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <div class="d-none d-md-flex flex-column text-start">
                        <span class="fw-bold small lh-1" style="color: var(--admin-text);"><?= htmlspecialchars((string)$adminName, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="badge rounded-pill mt-1" style="font-size: 0.65rem; background: var(--admin-accent); color: var(--admin-primary); border: 1px solid var(--admin-accent-border); width: fit-content;"><?= $isAdmin ? 'Administrator' : 'Staff Support' ?></span>
                    </div>
                    <i class="fa-solid fa-chevron-down small text-muted d-none d-md-inline ms-1"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2" aria-labelledby="adminProfileDropdown" style="min-width: 200px;">
                    <li class="px-3 py-2 border-bottom mb-1">
                        <div class="fw-bold text-dark small"><?= htmlspecialchars((string)$adminName, ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="small text-muted"><?= $isAdmin ? 'Quản trị viên hệ thống' : 'Nhân viên chăm sóc' ?></div>
                    </li>
                    <li><a class="dropdown-item rounded-3 py-2 small fw-semibold" href="<?= BASE_URL ?>/index.php?r=hoso"><i class="fa-solid fa-user me-2 text-muted"></i> Hồ sơ cá nhân</a></li>
                    <li><a class="dropdown-item rounded-3 py-2 small fw-semibold" href="<?= BASE_URL ?>/index.php?r=home" target="_blank"><i class="fa-solid fa-globe me-2 text-muted"></i> Trang chủ thương mại</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item rounded-3 py-2 small fw-bold text-danger" href="index.php?r=dangxuat"><i class="fa-solid fa-right-from-bracket me-2"></i> Đăng xuất</a></li>
                </ul>
            </div>
        </div>
    </header>

    <div class="px-4 pt-3">
        <?php if ($m = get_flash('success')): ?>
            <div class="alert alert-success border-0 shadow-sm rounded-4" style="background: #DCFCE7; color: #15803D;"><?= h($m) ?></div>
        <?php endif; ?>
        <?php if ($m = get_flash('error')): ?>
            <div class="alert alert-danger border-0 shadow-sm rounded-4" style="background: #FEE2E2; color: #B91C1C;"><?= h($m) ?></div>
        <?php endif; ?>
    </div>

    <script>
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


