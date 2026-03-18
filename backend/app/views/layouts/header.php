<?php
// backend/app/views/layouts/header.php
$menuCats = $menuCats ?? [];
$currentUser = current_user();
$cartCount = 0;
foreach (($_SESSION['gio_hang'] ?? []) as $qty) {
  $cartCount += (int)$qty;
}
$quickCategories = array_slice(array_keys($menuCats), 0, 6);
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
<body class="site-body">

<header class="site-header">
  <div class="promo-strip">
    <div class="container promo-strip__inner">
      <span><i class="fas fa-truck-fast"></i> Giao diện chăm da thông minh cho routine cá nhân hóa</span>
      <span><i class="fas fa-shield-heart"></i> Bộ lọc theo loại da, vấn đề da và ngân sách</span>
      <span><i class="fas fa-sparkles"></i> Gợi ý sản phẩm theo khảo sát chỉ trong vài giây</span>
    </div>
  </div>
  <div class="utility-strip">
    <div class="container utility-strip__inner">
      <div class="utility-links">
        <a href="<?= BASE_URL ?>/index.php?r=tatca">Tra cứu thành phần mỹ phẩm</a>
        <a href="<?= BASE_URL ?>/index.php?r=goiy">Gợi ý routine AI</a>
      </div>
      <div class="utility-links utility-links--account">
        <?php if (is_logged_in()): ?>
          <span class="utility-greeting">Xin chào, <?= h($currentUser['ho_ten'] ?? 'User') ?></span>
          <a href="<?= BASE_URL ?>/index.php?r=hoso">Tài khoản</a>
          <a href="<?= BASE_URL ?>/index.php?r=dangxuat">Đăng xuất</a>
        <?php else: ?>
          <a href="<?= BASE_URL ?>/index.php?r=dangnhap">Đăng nhập</a>
          <a href="<?= BASE_URL ?>/index.php?r=dangky">Đăng ký</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="header-main">
    <div class="container header-main__grid">
      <a class="brand-lockup" href="<?= BASE_URL ?>/index.php">
        <span class="brand-lockup__mark">S</span>
        <span class="brand-lockup__copy">
          <strong>SkinSyntax</strong>
          <small>Decoding Your Skin Language</small>
        </span>
      </a>

      <form class="searchbar live-search-form header-search" method="get" action="<?= BASE_URL ?>/index.php" id="liveSearchForm" autocomplete="off" data-live-search-url="<?= BASE_URL ?>/index.php?r=live_search" data-smart-search-url="<?= BASE_URL ?>/index.php?r=api_smart_search">
        <input type="hidden" name="r" value="tatca">
        <div class="live-search-box header-search__box">
          <i class="fas fa-magnifying-glass header-search__icon"></i>
          <input class="form-control" name="q" id="search-input" placeholder="Tìm sản phẩm, thương hiệu, vấn đề da..." value="<?= h($_GET['q'] ?? '') ?>" aria-label="Tìm kiếm sản phẩm" aria-autocomplete="list" aria-expanded="false" aria-controls="smartSearchDropdown">
          <div class="smart-search-dropdown" id="smartSearchDropdown" role="listbox" aria-label="Gợi ý sản phẩm" hidden></div>
          </div>
        <button class="btn btn-brand header-search__submit" type="submit">Tìm kiếm</button>
      </form>

      <div class="header-actions">
        <a href="<?= BASE_URL ?>/index.php?r=goiy" class="header-action-card">
          <i class="fas fa-wand-magic-sparkles"></i>
          <span>
            <strong>Routine AI</strong>
            <small>Nhận gợi ý ngay</small>
          </span>
        </a>

        <a href="<?= BASE_URL ?>/index.php?r=hoso" class="header-icon-link" title="Tài khoản">
          <i class="fas fa-user"></i>
          <span>Tài khoản</span>
        </a>

        <a href="<?= BASE_URL ?>/index.php?r=giohang" class="header-icon-link header-icon-link--cart" title="Giỏ hàng">
          <i class="fas fa-bag-shopping"></i>
          <span>Giỏ hàng</span>
          <?php if ($cartCount > 0): ?>
            <em class="header-cart-badge"><?= $cartCount ?></em>
          <?php endif; ?>
        </a>
      </div>
    </div>
  </div>

  <div class="header-nav-shell">
    <div class="container header-nav">
      <div class="dropdown header-catalog">
        <a class="header-catalog__toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="fas fa-bars"></i>
          <span>Danh mục sản phẩm</span>
        </a>

        <div class="dropdown-menu mega-menu header-mega-menu p-3">
          <div class="row g-3 align-items-stretch">
            <div class="col-lg-3">
              <div class="mega-menu-highlight">
                <span class="mega-menu-highlight__eyebrow">Danh mục nổi bật</span>
                <h5>Khám phá từng nhóm skincare theo đúng nhu cầu da.</h5>
                <p>Từ làm sạch, dưỡng ẩm đến đặc trị, mọi danh mục đều được gom lại để bạn lọc nhanh hơn.</p>
                <a href="<?= BASE_URL ?>/index.php?r=goiy" class="mega-menu-highlight__link">Mở gợi ý routine</a>
              </div>
            </div>
            <div class="col-lg-9">
              <div class="row g-3">
            <?php foreach ($menuCats as $c1 => $c2s): ?>
              <div class="col-6 col-lg-4">
                <div class="col-title"><?= h($c1) ?></div>
                <?php if (!empty($c2s)): ?>
                  <ul class="list-unstyled mb-0">
                    <?php foreach ($c2s as $c2 => $cnt): ?>
                      <li>
                        <a class="dropdown-item" href="<?= BASE_URL ?>/index.php?r=tatca&cap1=<?= urlencode($c1) ?>&cap2=<?= urlencode($c2) ?>">
                          <?= h($c2) ?> (<?= (int)$cnt ?>)
                        </a>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <a class="dropdown-item" href="<?= BASE_URL ?>/index.php?r=tatca&cap1=<?= urlencode($c1) ?>">
                    Xem tất cả
                  </a>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <nav class="header-shortcuts" aria-label="Liên kết nhanh">
        <a class="header-shortcuts__link" href="<?= BASE_URL ?>/index.php?r=home">Trang chủ</a>
        <a class="header-shortcuts__link" href="<?= BASE_URL ?>/index.php?r=tatca">Tất cả sản phẩm</a>
        <?php foreach ($quickCategories as $category): ?>
          <a class="header-shortcuts__link" href="<?= BASE_URL ?>/index.php?r=tatca&cap1=<?= urlencode((string)$category) ?>">
            <?= h((string)$category) ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <a class="header-deal-pill" href="<?= BASE_URL ?>/index.php?r=goiy">
        <i class="fas fa-gift"></i>
        <span>Khảo sát da để mở gợi ý cá nhân</span>
      </a>
    </div>
  </div>
</header>

<div class="container mt-3 flash-stack">
  <?php if ($m = get_flash('success')): ?>
    <div class="alert alert-success alert-elevated"><?= h($m) ?></div>
  <?php endif; ?>
  <?php if ($m = get_flash('error')): ?>
    <div class="alert alert-danger alert-elevated"><?= h($m) ?></div>
  <?php endif; ?>
</div>

<main class="page-shell">
