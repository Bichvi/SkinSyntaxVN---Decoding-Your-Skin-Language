<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SkinSyntax Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWix+LLJAJ9/2PKZ5QiAj6Ta86w+fsb2TkR4j8sQAtxTnRwE+XzQ+eJg4Q2pQ6J9iA9+6g==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        .admin-sidebar {
            width: 260px;
        }

        .admin-main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .menu-active {
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd !important;
            font-weight: 600;
        }

        .menu-active i {
            color: #0d6efd;
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
?>

<aside class="admin-sidebar position-fixed top-0 start-0 vh-100 bg-white border-end d-flex flex-column">
    <div class="p-4 fs-4 fw-bold text-dark border-bottom">
        <i class="fa-solid fa-gem me-2 text-primary"></i>SkinSyntax Admin
    </div>

    <nav class="py-3">
        <a href="index.php?r=admin_dashboard" class="d-flex align-items-center gap-3 px-3 py-2 mx-3 mb-2 rounded-3 text-decoration-none text-secondary <?= $currentRoute === 'admin_dashboard' ? 'menu-active' : '' ?>">
            <i class="fa-solid fa-chart-line"></i>
            <span>Dashboard</span>
        </a>
        <a href="index.php?r=admin_sp" class="d-flex align-items-center gap-3 px-3 py-2 mx-3 mb-2 rounded-3 text-decoration-none text-secondary <?= strpos($currentRoute, 'admin_sp') === 0 ? 'menu-active' : '' ?>">
            <i class="fa-solid fa-box-open"></i>
            <span>Quản lý Sản phẩm</span>
        </a>
        <a href="index.php?r=admin_users" class="d-flex align-items-center gap-3 px-3 py-2 mx-3 mb-2 rounded-3 text-decoration-none text-secondary <?= strpos($currentRoute, 'admin_users') === 0 ? 'menu-active' : '' ?>">
            <i class="fa-solid fa-users"></i>
            <span>Quản lý Người dùng</span>
        </a>
        <a href="index.php?r=admin_orders" class="d-flex align-items-center gap-3 px-3 py-2 mx-3 mb-2 rounded-3 text-decoration-none text-secondary <?= strpos($currentRoute, 'admin_orders') === 0 ? 'menu-active' : '' ?>">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            <span>Đơn hàng</span>
        </a>
        <a href="index.php?r=logout" class="d-flex align-items-center gap-3 px-3 py-2 mx-3 mb-2 rounded-3 text-decoration-none text-secondary">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Đăng xuất</span>
        </a>
    </nav>
</aside>

<div class="admin-main">
    <header class="sticky-top bg-white shadow-sm py-3 px-4 d-flex align-items-center justify-content-between gap-3">
        <div class="w-100" style="max-width: 420px;">
            <div class="input-group">
                <span class="input-group-text bg-light border-0 rounded-start-pill text-muted">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </span>
                <input type="search" class="form-control border-0 bg-light rounded-end-pill" placeholder="Tìm kiếm sản phẩm, người dùng...">
            </div>
        </div>

        <div class="d-flex align-items-center gap-3">
            <button type="button" class="btn btn-light border-0 rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px;" aria-label="Thông báo">
                <i class="fa-regular fa-bell text-secondary"></i>
            </button>

            <div class="d-flex align-items-center gap-2">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white fw-semibold" style="width: 34px; height: 34px;">
                    <?= htmlspecialchars(mb_substr((string)$adminName, 0, 1), ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="fw-semibold text-dark small"><?= htmlspecialchars((string)$adminName, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </header>
