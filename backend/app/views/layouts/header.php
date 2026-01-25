<?php
// backend/app/views/layouts/header.php
$flashSuccess = get_flash('success');
$flashError = get_flash('error');
$isLoggedIn = !empty($_SESSION['user']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SkinSyntax</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark topbar">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>/index.php?r=home">SkinSyntax</a>

        <form class="d-flex searchbar" method="get" action="<?= BASE_URL ?>/index.php">
            <input type="hidden" name="r" value="tatca">
            <input class="form-control" name="q" placeholder="Tìm sản phẩm, thương hiệu..." value="<?= h($_GET['q'] ?? '') ?>">
            <button class="btn btn-light ms-2" type="submit">Tìm</button>
        </form>

        <div class="ms-3 d-flex gap-2">
            <a class="btn btn-outline-light" href="<?= BASE_URL ?>/index.php?r=home">Home</a>
            <?php if ($isLoggedIn): ?>
                <a class="btn btn-outline-light" href="<?= BASE_URL ?>/index.php?r=dangxuat">Đăng xuất</a>
            <?php else: ?>
                <a class="btn btn-outline-light" href="<?= BASE_URL ?>/index.php?r=dangnhap">Đăng nhập</a>
                <a class="btn btn-outline-light" href="<?= BASE_URL ?>/index.php?r=dangky">Đăng ký</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="container mt-3">
    <?php if ($flashSuccess): ?>
        <div class="alert alert-success"><?= h($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert alert-danger"><?= h($flashError) ?></div>
    <?php endif; ?>
</div>
