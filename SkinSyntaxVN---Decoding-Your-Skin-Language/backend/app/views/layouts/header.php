<?php
// backend/app/views/layouts/header.php
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SkinSyntax</title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<div class="topbar">
  <div class="container d-flex justify-content-between">
    <div>
      <a href="<?= BASE_URL ?>/index.php?r=tatca" style="text-decoration: none; color: inherit;">Tra cứu thành phần mỹ phẩm</a> | 
      <a href="<?= BASE_URL ?>/index.php?r=goiy" style="text-decoration: none; color: inherit;">Gợi ý routine</a>
    </div>
    <div>
      <?php if (is_logged_in()): ?>
        Xin chào, <?= h(current_user()['ho_ten'] ?? 'User') ?> |
        <a href="<?= BASE_URL ?>/index.php?r=hoso">Tài khoản</a> |
        <a href="<?= BASE_URL ?>/index.php?r=dangxuat">Đăng xuất</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/index.php?r=dangnhap">Đăng nhập</a> |
        <a href="<?= BASE_URL ?>/index.php?r=dangky">Đăng ký</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<nav class="navbar navbar-expand-lg navbar-main">
  <div class="container">
    <a class="navbar-brand brand" href="<?= BASE_URL ?>/index.php">SkinSyntax</a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-3">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle fw-bold" href="#" role="button" data-bs-toggle="dropdown">
            DANH MỤC
          </a>

          <div class="dropdown-menu mega-menu p-3">
            <div class="row g-3">
              <?php foreach (($menuCats ?? []) as $c1 => $c2s): ?>
                <div class="col-6 col-lg-3">
                  <div class="col-title"><?= h($c1) ?></div>
                  <?php if (!empty($c2s)): ?>
                    <ul class="list-unstyled mb-0">
                      <?php foreach ($c2s as $c2 => $cnt): ?>
                        <li>
                          <a class="dropdown-item"
                             href="<?= BASE_URL ?>/index.php?r=tatca&cap1=<?= urlencode($c1) ?>&cap2=<?= urlencode($c2) ?>">
                            <?= h($c2) ?> (<?= (int)$cnt ?>)
                          </a>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    <a class="dropdown-item"
                       href="<?= BASE_URL ?>/index.php?r=tatca&cap1=<?= urlencode($c1) ?>">
                      Xem tất cả
                    </a>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </li>
      </ul>

      <form class="d-flex searchbar ms-auto live-search-form" method="get" action="<?= BASE_URL ?>/index.php" id="liveSearchForm" autocomplete="off" data-live-search-url="<?= BASE_URL ?>/index.php?r=live_search" data-smart-search-url="<?= BASE_URL ?>/index.php?r=api_smart_search">
        <input type="hidden" name="r" value="tatca">
        <div class="live-search-box">
          <input class="form-control" name="q" id="search-input" placeholder="Tìm sản phẩm, thương hiệu, thành phần..." value="<?= h($_GET['q'] ?? '') ?>" aria-label="Tìm kiếm sản phẩm" aria-autocomplete="list" aria-expanded="false" aria-controls="smartSearchDropdown">
          <div class="smart-search-dropdown" id="smartSearchDropdown" role="listbox" aria-label="Gợi ý sản phẩm" hidden></div>
        </div>
        <button class="btn btn-brand" type="submit">Tìm</button>
      </form>

      <div class="ms-3">
        <a href="<?= BASE_URL ?>/index.php?r=giohang" class="btn btn-outline-secondary btn-sm" title="Giỏ hàng">
          <i class="fas fa-shopping-cart"></i>
        </a>
      </div>
    </div>
  </div>
</nav>

<div class="container mt-3">
  <?php if ($m = get_flash('success')): ?>
    <div class="alert alert-success"><?= h($m) ?></div>
  <?php endif; ?>
  <?php if ($m = get_flash('error')): ?>
    <div class="alert alert-danger"><?= h($m) ?></div>
  <?php endif; ?>
</div>
