<?php
$latest = isset($latest) && is_array($latest) ? $latest : [];
$cats = isset($cats) && is_array($cats) ? $cats : [];
$homepageSections = isset($homepageSections) && is_array($homepageSections) ? $homepageSections : [];
$dbUnavailableMessage = trim((string)($dbUnavailableMessage ?? ''));

$heroProducts = array_slice($latest, 0, 4);
$newProducts = array_slice($latest, 0, 4);
$flashSaleProducts = array_slice(array_values(array_filter($homepageSections['flashDeals'] ?? [])), 0, 8);

$shortcutCards = [
  ['icon' => 'fa-wand-magic-sparkles', 'title' => 'Routine AI', 'desc' => 'Nhận gợi ý theo hồ sơ da đã lưu.', 'url' => BASE_URL . '/index.php?r=goiy'],
  ['icon' => 'fa-pump-soap', 'title' => 'Làm Sạch Sâu', 'desc' => 'Sữa rửa mặt, tẩy trang chuẩn y khoa.', 'url' => BASE_URL . '/index.php?r=tatca&q=' . urlencode('Làm sạch')],
  ['icon' => 'fa-sun', 'title' => 'Chống Nắng', 'desc' => 'Bảo vệ da khỏi tia UV & màng lọc kép.', 'url' => BASE_URL . '/index.php?r=tatca&q=' . urlencode('Chống nắng')],
  ['icon' => 'fa-droplet', 'title' => 'Phục Hồi Ẩm', 'desc' => 'Cấp nước, khóa ẩm & dịu kích ứng.', 'url' => BASE_URL . '/index.php?r=tatca&q=' . urlencode('Dưỡng ẩm')],
  ['icon' => 'fa-flask-vial', 'title' => 'Đặc Trị AI', 'desc' => 'Giải quyết mụn, thâm & lão hóa.', 'url' => BASE_URL . '/index.php?r=tatca&q=' . urlencode('Đặc trị')],
];

$skinConcerns = [
  ['name' => 'Da Dầu Nhờn', 'query' => 'da dầu', 'icon' => 'fa-droplet-slash', 'color' => '#E8F5E9', 'textColor' => '#1B5E20'],
  ['name' => 'Mụn & Thâm', 'query' => 'mụn', 'icon' => 'fa-shield-virus', 'color' => '#FFEBEE', 'textColor' => '#B71C1C'],
  ['name' => 'Da Nhạy Cảm', 'query' => 'nhạy cảm', 'icon' => 'fa-heart-pulse', 'color' => '#FFF3E0', 'textColor' => '#E65100'],
  ['name' => 'Xỉn Màu / Thâm', 'query' => 'thâm', 'icon' => 'fa-sparkles', 'color' => '#FFF8E1', 'textColor' => '#F57F17'],
  ['name' => 'Phục Hồi Da', 'query' => 'phục hồi', 'icon' => 'fa-hand-holding-medical', 'color' => '#E0F2F1', 'textColor' => '#004D40'],
  ['name' => 'Cấp Ẩm Đa Tầng', 'query' => 'dưỡng ẩm', 'icon' => 'fa-droplet', 'color' => '#E1F5FE', 'textColor' => '#01579B'],
  ['name' => 'Chống Lão Hóa', 'query' => 'lão hóa', 'icon' => 'fa-hourglass-half', 'color' => '#F3E5F5', 'textColor' => '#4A148C'],
  ['name' => 'Se Lỗ Chân Lông', 'query' => 'lỗ chân lông', 'color' => '#EFEBE9', 'textColor' => '#3E2723', 'icon' => 'fa-expand'],
];

$brandNames = [];
foreach ($latest as $item) {
  $brand = trim((string)($item['thuong_hieu'] ?? ''));
  if ($brand !== '' && !in_array($brand, $brandNames, true)) {
    $brandNames[] = $brand;
  }
  if (count($brandNames) >= 6) {
    break;
  }
}

$skinSyntaxSignals = [
  ['number' => 'AI 24/7', 'label' => 'Phân tích routine & hoạt chất'],
  ['number' => '100%', 'label' => 'Mỹ phẩm thuần chay y khoa'],
  ['number' => '1 Hồ Sơ', 'label' => 'Đồng bộ bài khảo sát & đơn hàng'],
];

$renderHomeProductCard = static function (array $p, string $tag = ''): void {
  $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
  $img = resolve_image_url((string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
  $giaBan = (string)($p['gia_ban'] ?? '');
  $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
  $phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
  $matchScore = isset($p['match_score']) && is_numeric($p['match_score']) ? (int)$p['match_score'] : null;
  $rating = isset($p['diem_danh_gia']) && (float)$p['diem_danh_gia'] > 0 ? (float)$p['diem_danh_gia'] : 4.9;
  $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($p) : false;
  ?>
    <div class="product-card product-card--showcase h-100 d-flex flex-column" style="border-radius: 22px; border: 1px solid #E2EADF; background: #FFFFFF; overflow: hidden; transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);">
      <div class="product-thumb position-relative p-2" style="background: #F8FAF8;">
        <?php if ($phanTramGiam !== null): ?>
          <span class="badge-sale position-absolute" style="top: 14px; left: 14px; background: linear-gradient(135deg, #E11D48 0%, #F43F5E 100%); color: #FFFFFF; font-weight: 800; font-size: 0.75rem; padding: 5px 12px; border-radius: 999px; z-index: 3; box-shadow: 0 4px 14px rgba(225, 29, 72, 0.35);">
            -<?= h((string)$phanTramGiam) ?>%
          </span>
        <?php endif; ?>

        <?php if ($matchScore !== null && $matchScore > 0): ?>
          <span class="badge-match position-absolute" style="top: 14px; right: 14px; background: linear-gradient(135deg, #162F18 0%, #215427 100%); color: #FFFFFF; font-size: 0.75rem; font-weight: 700; padding: 5px 12px; border-radius: 999px; z-index: 3; box-shadow: 0 4px 14px rgba(33, 84, 39, 0.28);">
            <i class="fas fa-wand-magic-sparkles me-1 text-warning"></i> <?= $matchScore ?>% Phù hợp
          </span>
        <?php elseif ($tag !== ''): ?>
          <span class="market-card__tag position-absolute" style="top: 14px; right: 14px; background: #EAF0EB; color: #215427; font-size: 0.72rem; font-weight: 800; padding: 4px 12px; border-radius: 999px; z-index: 3; border: 1px solid #C5DAC8;">
            <?= h($tag) ?>
          </span>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" class="d-block w-100 overflow-hidden" style="border-radius: 16px; aspect-ratio: 1/1;">
          <img class="product-card-img" src="<?= h($img ?: 'https://via.placeholder.com/450x450?text=SkinSyntax') ?>" referrerpolicy="no-referrer" onerror="this.src='https://via.placeholder.com/450x450?text=SkinSyntax';" alt="<?= h($p['ten_san_pham'] ?? '') ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);">
        </a>
      </div>

      <div class="product-meta p-3.5 p-3 d-flex flex-column flex-grow-1">
        <div class="mb-1">
          <span class="brand text-uppercase fw-extrabold" style="font-size: 0.7rem; color: #215427; background: #EAF0EB; padding: 3px 9px; border-radius: 6px; letter-spacing: 0.05em; font-weight: 800; display: inline-block;">
            <?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?>
          </span>
        </div>

        <a class="name fw-bold mb-2 text-decoration-none" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" style="color: #1A2F1A; font-size: 0.95rem; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.7em;">
          <?= h($p['ten_san_pham'] ?? '') ?>
        </a>

        <div class="d-flex align-items-center gap-1.5 mb-2.5" style="font-size: 0.78rem;">
          <span class="d-inline-flex align-items-center gap-1 px-2 py-0.5 rounded-pill" style="background: #FFFBEB; color: #D97706; font-weight: 800; border: 1px solid #FEF3C7;">
            <i class="fas fa-star" style="color: #F59E0B;"></i> <?= number_format($rating, 1) ?>
          </span>
          <span class="text-muted small ms-1">(<?= (int)($p['so_luong_danh_gia'] ?? 128) ?>)</span>
        </div>

        <div class="price-wrap mb-3 mt-auto d-flex align-items-baseline gap-2">
          <div class="price fw-extrabold" style="color: #215427; font-size: 1.15rem; font-weight: 800;"><?= vnd($giaBan) ?></div>
          <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)$giaBan): ?>
            <div class="price-market text-muted text-decoration-line-through" style="font-size: 0.82rem; color: #94A3B8 !important;"><?= vnd($giaThiTruong) ?></div>
          <?php endif; ?>
        </div>

        <div class="product-card-actions d-grid gap-1.5" style="grid-template-columns: 1fr 1fr;">
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="btn btn-sm btn-product-add w-100" type="submit" style="background: #EAF0EB; color: #215427; border: 1px solid #C5DAC8; border-radius: 999px; font-weight: 700; font-size: 0.8rem; padding: 8px 0; transition: all 0.25s ease;" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1"></i> Giỏ hàng' ?></button>
          </form>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="buy_now" value="1">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="btn btn-sm btn-product-buy btn-buy-now-pulse w-100 text-white" type="submit" style="background: linear-gradient(135deg, #215427 0%, #162F18 100%); border-radius: 999px; font-weight: 800; font-size: 0.8rem; padding: 8px 0; border: none; box-shadow: 0 4px 14px rgba(33, 84, 39, 0.25); transition: all 0.25s ease;" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Hết hàng' : ' Mua ngay' ?></button>
          </form>
        </div>
      </div>
    </div>
  <?php
};
?>

<style>
  /* Botanical Hero Auto-Slider */
  .hero-slider-container {
    position: relative;
    border-radius: 28px;
    overflow: hidden;
    box-shadow: 0 20px 45px rgba(33, 84, 39, 0.18);
  }
  .hero-slide {
    display: none;
    background: linear-gradient(135deg, #162F18 0%, #215427 60%, #3E7250 100%);
    color: #fff;
    padding: 48px 40px;
    min-height: 420px;
  }
  .hero-slide.active {
    display: flex;
    animation: fadeInSlide 0.6s cubic-bezier(0.16, 1, 0.3, 1);
  }
  @keyframes fadeInSlide {
    from { opacity: 0; transform: scale(0.99); }
    to { opacity: 1; transform: scale(1); }
  }
  .hero-slider-dots {
    position: absolute;
    bottom: 20px;
    right: 30px;
    display: flex;
    gap: 8px;
    z-index: 10;
  }
  .hero-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.35);
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
  }
  .hero-dot.active {
    width: 32px;
    border-radius: 999px;
    background: #FFFFFF;
  }

  /* Flash Sale Countdown Styling */
  .home-flash-sale {
    background: linear-gradient(135deg, #162F18 0%, #215427 58%, #3E7250 100%);
    border-radius: 28px;
    color: #fff;
    padding: 32px;
    box-shadow: 0 20px 45px rgba(33, 84, 39, 0.18);
    border: 1px solid rgba(226, 234, 223, 0.2);
  }

  /* Skin Concern Grid */
  .skin-concern-card {
    background: #FFF;
    border: 1px solid #E2EADF;
    border-radius: 20px;
    padding: 20px;
    text-decoration: none;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
  }
  .skin-concern-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 14px 30px rgba(33, 84, 39, 0.12);
    border-color: #8FAE94;
  }
</style>

<div class="container mt-4 home-shell">
  <?php if ($dbUnavailableMessage !== ''): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4" style="border-radius: 16px; background: #FFFBEB; color: #B45309;"><?= h($dbUnavailableMessage) ?></div>
  <?php endif; ?>

  <!-- SECTION 1: HERO BANNER EDITORIAL CAMPAIGN SLIDER (3 IMAGE-LED SLIDES) -->
  <section class="hero-slider-container mb-5" id="heroSlider">
    <!-- Slide 1: AI Advisor & Botanical Routine -->
    <article class="hero-slide active" data-slide="0" style="background-image: url('<?= BASE_URL ?>/assets/images/hero_campaign_ai_skin.png');">
      <div class="hero-slide-overlay"></div>
      <div class="hero-slide-content">
        <span class="hero-badge mb-3">
          <i class="fas fa-wand-magic-sparkles text-warning"></i> AI Skin Advisor & Routine Personalization
        </span>
        <h1 class="hero-title">
          Hiểu Làn Da.<br><span class="hero-title-accent">Chọn Đúng Sản Phẩm.</span>
        </h1>
        <p class="hero-desc">
          Khám phá sản phẩm chuẩn skincare botanical, làm khảo sát da thông minh và nhận gợi ý routine cá nhân hóa bởi Chuyên gia AI trên một nền tảng duy nhất.
        </p>
        <div class="hero-actions d-flex flex-wrap gap-3 mb-4">
          <a class="btn btn-hero-primary py-3 px-4 fw-bold" href="<?= BASE_URL ?>/index.php?r=goiy">
            <i class="fas fa-wand-magic-sparkles text-warning me-2"></i> Phân tích da với AI
          </a>
          <a class="btn btn-hero-secondary py-3 px-4 fw-bold" href="<?= BASE_URL ?>/index.php?r=tatca">
            <i class="fas fa-bag-shopping me-2"></i> Khám phá mỹ phẩm
          </a>
        </div>
        <div class="hero-floating-card">
          <div class="rounded-circle d-grid place-items-center bg-success-subtle text-success p-2" style="width: 36px; height: 36px; display: grid; place-items: center;">
            <i class="fas fa-shield-check fs-6"></i>
          </div>
          <div>
            <strong class="d-block text-dark small" style="font-size: 0.82rem;">SkinSyntax AI Personalization</strong>
            <span class="text-muted" style="font-size: 0.74rem;"><i class="fas fa-sparkles text-warning me-1"></i> Routine cá nhân hóa chuẩn y khoa</span>
          </div>
        </div>
      </div>
    </article>

    <!-- Slide 2: Personalized Skincare Profile -->
    <article class="hero-slide" data-slide="1" style="background-image: url('<?= BASE_URL ?>/assets/images/hero_campaign_personalized.png');">
      <div class="hero-slide-overlay"></div>
      <div class="hero-slide-content">
        <span class="hero-badge mb-3">
          <i class="fas fa-user-doctor text-success"></i> Personalized Skincare Profile
        </span>
        <h1 class="hero-title">
          Routine Mỹ Phẩm<br><span class="hero-title-accent">Được Cá Nhân Hóa Cho Bạn</span>
        </h1>
        <p class="hero-desc">
          Mỗi làn da có một ngôn ngữ riêng. Tạo hồ sơ da để lưu lịch sử khảo sát, theo dõi sự thay đổi của làn da và mở khóa ưu đãi thành viên.
        </p>
        <div class="hero-actions d-flex flex-wrap gap-3 mb-4">
          <a class="btn btn-hero-primary py-3 px-4 fw-bold" href="<?= BASE_URL ?>/index.php?r=khaosat">
            <i class="fas fa-clipboard-check me-2"></i> Khảo sát làn da ngay
          </a>
          <a class="btn btn-hero-secondary py-3 px-4 fw-bold" href="<?= BASE_URL ?>/index.php?r=hoso">
            <i class="fas fa-user me-2"></i> Xem Hồ sơ da
          </a>
        </div>
      </div>
    </article>

    <!-- Slide 3: Botanical Flash Sale Campaign -->
    <article class="hero-slide" data-slide="2" style="background-image: url('<?= BASE_URL ?>/assets/images/hero_campaign_flash_sale.png');">
      <div class="hero-slide-overlay"></div>
      <div class="hero-slide-content">
        <span class="hero-badge mb-3">
          <i class="fas fa-bolt text-warning"></i> Botanical Beauty Flash Sale
        </span>
        <h1 class="hero-title">
          Ưu Đãi Mỹ Phẩm<br><span class="hero-title-accent">Thuần Chay Chuẩn Y Khoa</span>
        </h1>
        <p class="hero-desc">
          Sưu tầm deal giảm giá chớp nhoáng cho các dòng sản phẩm serum, dưỡng ẩm và làm sạch hot nhất tuần này.
        </p>
        <div class="hero-actions d-flex flex-wrap gap-3 mb-4">
          <a class="btn btn-hero-primary py-3 px-4 fw-bold" href="<?= BASE_URL ?>/index.php?r=tatca&sort=bestseller">
            <i class="fas fa-fire text-danger me-2"></i> Săn Deal Flash Sale
          </a>
        </div>
      </div>
    </article>

    <!-- Arrow Navigation -->
    <button class="hero-nav-btn hero-nav-prev" id="heroPrev" aria-label="Previous slide"><i class="fas fa-chevron-left"></i></button>
    <button class="hero-nav-btn hero-nav-next" id="heroNext" aria-label="Next slide"><i class="fas fa-chevron-right"></i></button>

    <!-- Dots Navigation -->
    <div class="hero-slider-dots">
      <button class="hero-dot active" data-slide-target="0" aria-label="Slide 1"></button>
      <button class="hero-dot" data-slide-target="1" aria-label="Slide 2"></button>
      <button class="hero-dot" data-slide-target="2" aria-label="Slide 3"></button>
    </div>
  </section>

  <!-- SECTION 2: BRAND TRUST BAR -->
  <section class="row g-3 mb-5">
    <?php foreach ($shortcutCards as $shortcut): ?>
      <div class="col-6 col-md">
        <a href="<?= h($shortcut['url']) ?>" class="d-flex align-items-center gap-3 p-3 h-100 text-decoration-none" style="background: #FFF; border: 1px solid #E2EADF; border-radius: 18px; transition: all 0.2s ease;">
          <div class="rounded-circle d-grid place-items-center" style="width: 44px; height: 44px; background: #F3F7F1; color: #215427; flex-shrink: 0; display: grid; place-items: center;">
            <i class="fas <?= h($shortcut['icon']) ?> fs-5"></i>
          </div>
          <div>
            <strong style="color: #1A2F1A; font-size: 0.88rem; display: block; line-height: 1.2;"><?= h($shortcut['title']) ?></strong>
            <small style="color: #5C705E; font-size: 0.75rem;"><?= h($shortcut['desc']) ?></small>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- SECTION 3: SHOP BY SKIN CONCERN -->
  <section class="mb-5">
    <div class="d-flex justify-content-between align-items-end mb-4">
      <div>
        <span class="text-uppercase fw-bold small" style="color: #215427; letter-spacing: 0.08em;">Nhu cầu làn da</span>
        <h3 class="fw-bold m-0" style="color: #1A2F1A; font-size: 1.8rem;">Làn da của bạn đang cần điều gì?</h3>
      </div>
      <a href="<?= BASE_URL ?>/index.php?r=tatca" class="fw-bold text-decoration-none" style="color: #215427; font-size: 0.9rem;">Tất cả danh mục <i class="fas fa-arrow-right ms-1"></i></a>
    </div>

    <div class="row g-3">
      <?php foreach ($skinConcerns as $concern): ?>
        <div class="col-6 col-sm-4 col-md-3">
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=<?= urlencode($concern['query']) ?>" class="skin-concern-card">
            <div class="rounded-circle mb-3 d-grid place-items-center" style="width: 54px; height: 54px; background: <?= $concern['color'] ?>; color: <?= $concern['textColor'] ?>; display: grid; place-items: center; font-size: 1.3rem;">
              <i class="fas <?= h($concern['icon']) ?>"></i>
            </div>
            <strong style="color: #1A2F1A; font-size: 0.95rem; margin-bottom: 4px;"><?= h($concern['name']) ?></strong>
            <small style="color: #5C705E; font-size: 0.78rem;">Tìm sản phẩm phù hợp &rarr;</small>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- SECTION 4: SKINSYNTAX AI CORNER -->
  <?php
    $userProfile = is_array($userProfile ?? null) ? $userProfile : [];
    $isLoggedIn = (bool)($isLoggedIn ?? false);
    $hasSurvey = (bool)($hasSurvey ?? false);

    $skinTypeDisplay = !empty($userProfile['skin_type']) ? $userProfile['skin_type'] : ($hasSurvey ? 'Đã hoàn thành khảo sát' : 'Chưa có thông tin');
    $concernsDisplay = !empty($userProfile['concerns']) ? implode(', ', $userProfile['concerns']) : (!empty($userProfile['sensitivity']) ? $userProfile['sensitivity'] : 'Chưa ghi nhận');
    $avoidDisplay = !empty($userProfile['avoid_ingredients']) ? implode(', ', $userProfile['avoid_ingredients']) : 'Không có / Phù hợp nhiều hoạt chất';
  ?>
  <section class="p-4 p-md-5 mb-5 text-white position-relative overflow-hidden" style="background: linear-gradient(135deg, #162F18 0%, #215427 60%, #3E7250 100%); border-radius: 28px; box-shadow: 0 20px 45px rgba(33, 84, 39, 0.15);">
    <div class="row align-items-center position-relative" style="z-index: 2;">
      <div class="col-lg-7 mb-4 mb-lg-0">
        <span class="badge-pill mb-3" style="display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.18); color: #EAF2EC; padding: 6px 14px; border-radius: 999px; font-size: 0.8rem; font-weight: 700;">
          <i class="fas fa-brain text-warning"></i> Trợ Lý Tư Vấn AI
        </span>
        <h2 class="fw-bold mb-3" style="font-size: 2.2rem; color: #FFF;">SkinSyntax AI Hiểu Làn Da Của Bạn</h2>
        <p style="color: #EAF2EC; opacity: 0.92; font-size: 1rem; line-height: 1.6; max-width: 620px;" class="mb-4">
          Hệ thống Trợ lý AI phân tích hoạt chất mỹ phẩm, lọc độ phù hợp theo loại da (Dầu / Nhạy cảm / Hỗn hợp) và gợi ý chu trình skincare chuẩn y khoa.
        </p>
        <div class="d-flex flex-wrap gap-3">
          <a href="<?= BASE_URL ?>/index.php?r=goiy" class="btn btn-light py-3 px-4 fw-bold" style="border-radius: 999px; color: #215427; background: #FFF; border: none;">
            <i class="fas fa-wand-magic-sparkles text-warning me-2"></i> Khám phá Hồ sơ da AI
          </a>
          <a href="<?= BASE_URL ?>/index.php?r=<?= $hasSurvey ? 'hoso' : 'khaosat' ?>" class="btn btn-outline-light py-3 px-4 fw-bold" style="border-radius: 999px; border: 1.5px solid rgba(255,255,255,0.6);">
            <?= $hasSurvey ? 'Xem kết quả khảo sát' : 'Làm khảo sát ngay' ?>
          </a>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="p-4 rounded-4 bg-white text-dark shadow-lg" style="border-radius: 24px !important; border: 1px solid rgba(255,255,255,0.3);">
          <div class="d-flex align-items-center justify-content-between mb-3 pb-3 border-bottom">
            <div class="d-flex align-items-center gap-2">
              <span class="rounded-circle bg-success text-white d-grid place-items-center" style="width: 36px; height: 36px; display: grid; place-items: center; font-weight: 800;">AI</span>
              <div>
                <strong style="color: #1A2F1A; display: block; font-size: 0.9rem;">Hồ Sơ Da Chuẩn Y Khoa</strong>
                <small class="text-muted" style="font-size: 0.75rem;">
                  <?= $hasSurvey ? 'Phân tích theo dữ liệu thực' : ($isLoggedIn ? 'Bạn chưa làm khảo sát da' : 'Dành cho thành viên') ?>
                </small>
              </div>
            </div>
            <span class="badge bg-success-subtle text-success fw-bold px-3 py-1 rounded-pill">
              <?= $hasSurvey ? '100% Fit' : ($isLoggedIn ? 'Chưa khảo sát' : 'Dữ liệu AI') ?>
            </span>
          </div>

          <?php if ($hasSurvey): ?>
            <div class="d-flex flex-column gap-2 mb-3" style="font-size: 0.85rem;">
              <div class="d-flex justify-content-between">
                <span class="text-muted">Loại da:</span>
                <strong style="color: #1A2F1A;"><?= h($skinTypeDisplay) ?></strong>
              </div>
              <div class="d-flex justify-content-between">
                <span class="text-muted">Tình trạng da:</span>
                <strong class="text-danger"><?= h($concernsDisplay) ?></strong>
              </div>
              <div class="d-flex justify-content-between">
                <span class="text-muted">Thành phần tránh:</span>
                <strong style="color: #215427;"><?= h($avoidDisplay) ?></strong>
              </div>
            </div>

            <a href="<?= BASE_URL ?>/index.php?r=goiy" class="btn w-100 fw-bold py-2.5" style="background: #215427; color: #FFF; border-radius: 999px; font-size: 0.88rem;">Nhận Routine Cá Nhân Hóa &rarr;</a>
          <?php elseif ($isLoggedIn): ?>
            <div class="p-3 mb-3 rounded-3" style="background: #F0F4F1; border: 1px dashed #84A98C; font-size: 0.85rem;">
              <div class="fw-bold text-success mb-1"><i class="fa-solid fa-clipboard-question me-1"></i> Bạn chưa hoàn thành khảo sát hồ sơ da!</div>
              <div class="text-muted" style="line-height: 1.5;">Hãy dành 1 phút làm khảo sát để AI phân tích chuẩn xác loại da và xuất routine riêng cho bạn.</div>
            </div>
            <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="btn w-100 fw-bold py-2.5" style="background: #215427; color: #FFF; border-radius: 999px; font-size: 0.88rem;">Khảo sát ngay!! &rarr;</a>
          <?php else: ?>
            <div class="p-3 mb-3 rounded-3" style="background: #F0F4F1; border: 1px dashed #84A98C; font-size: 0.85rem;">
              <div class="fw-bold text-success mb-1"><i class="fa-solid fa-user-plus me-1"></i> Tham gia khảo sát da y khoa!</div>
              <div class="text-muted" style="line-height: 1.5;">Làm khảo sát 1 phút để nhận phân tích hoạt chất, lọc thành phần gây dị ứng và nhận gợi ý sản phẩm phù hợp 100%.</div>
            </div>
            <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="btn w-100 fw-bold py-2.5" style="background: #215427; color: #FFF; border-radius: 999px; font-size: 0.88rem;">Làm khảo sát ngay &rarr;</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- SECTION 5: FLASH SALE -->
  <?php if (!empty($flashSaleProducts)): ?>
    <section class="home-flash-sale mb-5" data-flash-sale-countdown>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
          <span class="text-uppercase fw-bold small text-white-50" style="letter-spacing: 0.08em;">DEAL CHỚP NHOÁNG</span>
          <h2 class="fw-bold m-0 text-white" style="font-size: 2.2rem;"><i class="fas fa-bolt text-warning me-2"></i> Flash Sale Botanicals</h2>
        </div>

        <div class="d-flex align-items-center gap-2" aria-label="Thời gian còn lại">
          <div class="text-center px-3 py-2 rounded-3" style="background: rgba(255,255,255,0.18); min-width: 64px;">
            <strong class="d-block text-white fs-5 fw-bold" data-flash-days>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.68rem;">Ngày</small>
          </div>
          <span class="text-white fw-bold fs-5">:</span>
          <div class="text-center px-3 py-2 rounded-3" style="background: rgba(255,255,255,0.18); min-width: 64px;">
            <strong class="d-block text-white fs-5 fw-bold" data-flash-hours>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.68rem;">Giờ</small>
          </div>
          <span class="text-white fw-bold fs-5">:</span>
          <div class="text-center px-3 py-2 rounded-3" style="background: rgba(255,255,255,0.18); min-width: 64px;">
            <strong class="d-block text-white fs-5 fw-bold" data-flash-minutes>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.68rem;">Phút</small>
          </div>
          <span class="text-white fw-bold fs-5">:</span>
          <div class="text-center px-3 py-2 rounded-3" style="background: rgba(255,255,255,0.18); min-width: 64px;">
            <strong class="d-block text-white fs-5 fw-bold" data-flash-seconds>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.68rem;">Giây</small>
          </div>
        </div>

        <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn btn-light rounded-pill px-4 fw-bold" style="color: #215427;">Xem tất cả deal &rarr;</a>
      </div>

      <div class="row g-3">
        <?php foreach ($flashSaleProducts as $p):
          $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
          $img = resolve_image_url((string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
          $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
          $phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
          $detailUrl = BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($productId);
          $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($p) : false;
        ?>
          <div class="col-6 col-md-3">
            <div class="bg-white rounded-4 overflow-hidden h-100 d-flex flex-column p-3 text-dark" style="border-radius: 20px !important;">
              <div class="position-relative mb-2" style="aspect-ratio: 1/1; overflow: hidden; border-radius: 14px; background: #F8FAF8;">
                <?php if ($phanTramGiam !== null): ?>
                  <span class="badge bg-danger position-absolute top-0 start-0 m-2 rounded-pill fw-bold">-<?= h((string)$phanTramGiam) ?>%</span>
                <?php endif; ?>
                <a href="<?= h($detailUrl) ?>" class="d-block w-100 h-100">
                  <img src="<?= h($img ?: 'https://via.placeholder.com/450x450?text=No+Image') ?>" referrerpolicy="no-referrer" onerror="this.src='https://via.placeholder.com/450x450?text=No+Image';" alt="<?= h($p['ten_san_pham'] ?? '') ?>" style="width:100%; height:100%; object-fit:cover;">
                </a>
              </div>
              <div class="fw-bold text-uppercase small text-muted mb-1"><?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
              <a href="<?= h($detailUrl) ?>" class="fw-bold text-dark text-decoration-none mb-2" style="font-size: 0.9rem; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.6em;">
                <?= h($p['ten_san_pham'] ?? '') ?>
              </a>
              <div class="fw-bold fs-5 mb-3" style="color: #215427;"><?= vnd($p['gia_ban'] ?? 0) ?></div>
              <div class="mt-auto d-grid gap-2 grid-cols-2" style="display: grid; grid-template-columns: 1fr 1fr;">
                <a class="btn btn-sm btn-light fw-bold" href="<?= h($detailUrl) ?>" style="border-radius: 999px; color: #215427;">Chi tiết</a>
                <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="product_id" value="<?= h($productId) ?>">
                  <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
                  <input type="hidden" name="quantity" value="1">
                  <input type="hidden" name="qty" value="1">
                  <button class="btn btn-sm w-100 text-white fw-bold" type="submit" style="background: #215427; border-radius: 999px;" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1"></i> Thêm giỏ' ?></button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- SECTION 6: ROUTINE BUILDER STEPPER -->
  <section class="mb-5 p-4 p-md-5 bg-white border rounded-4 shadow-sm" style="border-radius: 28px !important; border-color: #E2EADF !important;">
    <div class="text-center max-w-600 mx-auto mb-4" style="max-width: 600px;">
      <span class="text-uppercase fw-bold small" style="color: #215427; letter-spacing: 0.08em;">Chu trình Skincare Y Khoa</span>
      <h3 class="fw-bold" style="color: #1A2F1A; font-size: 1.8rem;">Xây Dựng Routine 4 Bước Chuẩn Chỉnh</h3>
      <p class="text-muted small">SkinSyntax sắp xếp routine khoa học từ bước làm sạch đến bảo vệ da khỏi tia UV.</p>
    </div>

    <div class="row g-3">
      <div class="col-6 col-md-3">
        <div class="p-3 text-center rounded-4 h-100" style="background: #F8FAF8; border: 1px solid #E2EADF;">
          <span class="badge rounded-circle bg-success mb-2" style="width: 32px; height: 32px; display: inline-grid; place-items: center;">1</span>
          <h5 class="fw-bold" style="color: #1A2F1A; font-size: 1rem;">Làm Sạch Sâu</h5>
          <p class="text-muted small mb-3">Tẩy trang & Sữa rửa mặt dịu nhẹ.</p>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=làm sạch" class="btn btn-sm btn-outline-success rounded-pill fw-bold w-100">Khám phá</a>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="p-3 text-center rounded-4 h-100" style="background: #F8FAF8; border: 1px solid #E2EADF;">
          <span class="badge rounded-circle bg-success mb-2" style="width: 32px; height: 32px; display: inline-grid; place-items: center;">2</span>
          <h5 class="fw-bold" style="color: #1A2F1A; font-size: 1rem;">Tinh Chất Treatment</h5>
          <p class="text-muted small mb-3">Serum Niacinamide, B5, Vitamin C.</p>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=serum" class="btn btn-sm btn-outline-success rounded-pill fw-bold w-100">Khám phá</a>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="p-3 text-center rounded-4 h-100" style="background: #F8FAF8; border: 1px solid #E2EADF;">
          <span class="badge rounded-circle bg-success mb-2" style="width: 32px; height: 32px; display: inline-grid; place-items: center;">3</span>
          <h5 class="fw-bold" style="color: #1A2F1A; font-size: 1rem;">Dưỡng Ẩm Khóa Màng</h5>
          <p class="text-muted small mb-3">Kem dưỡng phục hồi màng lipid.</p>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=dưỡng ẩm" class="btn btn-sm btn-outline-success rounded-pill fw-bold w-100">Khám phá</a>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="p-3 text-center rounded-4 h-100" style="background: #F8FAF8; border: 1px solid #E2EADF;">
          <span class="badge rounded-circle bg-success mb-2" style="width: 32px; height: 32px; display: inline-grid; place-items: center;">4</span>
          <h5 class="fw-bold" style="color: #1A2F1A; font-size: 1rem;">Chống Nắng Bảo Vệ</h5>
          <p class="text-muted small mb-3">KEM CHỐNG NẮNG phổ rộng UV.</p>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=chống nắng" class="btn btn-sm btn-outline-success rounded-pill fw-bold w-100">Khám phá</a>
        </div>
      </div>
    </div>
  </section>

  <!-- SECTION 7: NEW PRODUCTS -->
  <?php if (!empty($newProducts)): ?>
    <section class="mb-5">
      <div class="d-flex justify-content-between align-items-end mb-4">
        <div>
          <span class="text-uppercase fw-bold small" style="color: #215427; letter-spacing: 0.08em;">VỪA CẬP NHẬT</span>
          <h3 class="fw-bold m-0" style="color: #1A2F1A; font-size: 1.8rem;">Sản Phẩm Mới Lên Kệ</h3>
        </div>
        <a href="<?= BASE_URL ?>/index.php?r=tatca" class="fw-bold text-decoration-none" style="color: #215427; font-size: 0.9rem;">Xem tất cả <i class="fas fa-arrow-right ms-1"></i></a>
      </div>

      <div class="row g-3">
        <?php foreach (array_slice($newProducts, 0, 4) as $p): ?>
          <div class="col-6 col-md-3">
            <?php $renderHomeProductCard($p, 'Mới lên kệ'); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- SECTION 8: HOMEPAGE CATEGORY BLOCKS -->
  <?php
    $homeBlocks = [
      'bestSellers' => ['kicker' => 'ĐANG ĐƯỢC MUA NHIỀU', 'title' => 'Sản Phẩm Bán Chạy', 'tag' => 'Bán chạy', 'url' => BASE_URL . '/index.php?r=tatca&sort=bestseller'],
      'topSearches' => ['kicker' => 'NHIỀU LƯỢT XEM', 'title' => 'Top Tìm Kiếm', 'tag' => 'Top tìm kiếm', 'url' => BASE_URL . '/index.php?r=tatca&sort=trend'],
      'forYou' => ['kicker' => 'GỢI Ý AI NHA NHÓM', 'title' => 'Dành Cho Làn Da Của Bạn', 'tag' => 'Phù hợp', 'url' => BASE_URL . '/index.php?r=goiy'],
    ];
  ?>
  <?php foreach ($homeBlocks as $blockKey => $blockMeta): ?>
    <?php $blockItems = array_slice(array_values(array_filter($homepageSections[$blockKey] ?? [])), 0, 4); ?>
    <?php if (!empty($blockItems)): ?>
      <section class="mb-5">
        <div class="d-flex justify-content-between align-items-end mb-4">
          <div>
            <span class="text-uppercase fw-bold small" style="color: #215427; letter-spacing: 0.08em;"><?= h($blockMeta['kicker']) ?></span>
            <h3 class="fw-bold m-0" style="color: #1A2F1A; font-size: 1.8rem;"><?= h($blockMeta['title']) ?></h3>
          </div>
          <a href="<?= h($blockMeta['url']) ?>" class="fw-bold text-decoration-none" style="color: #215427; font-size: 0.9rem;">Xem tất cả <i class="fas fa-arrow-right ms-1"></i></a>
        </div>

        <div class="row g-3">
          <?php foreach ($blockItems as $p): ?>
            <div class="col-6 col-md-3">
              <?php $renderHomeProductCard($p, (string)$blockMeta['tag']); ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  <?php endforeach; ?>

  <!-- SECTION 9: BRAND RIBBON -->
  <?php if (!empty($brandNames)): ?>
    <section class="p-4 mb-5 rounded-4 bg-white border text-center" style="border-radius: 24px !important; border-color: #E2EADF !important;">
      <span class="text-uppercase fw-bold small text-muted mb-2 d-block" style="letter-spacing: 0.08em;">THƯƠNG HIỆU MỸ PHẨM UY TÍN</span>
      <div class="d-flex flex-wrap justify-content-center gap-2">
        <?php foreach ($brandNames as $brand): ?>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=<?= urlencode($brand) ?>" class="btn btn-sm btn-light rounded-pill px-3 fw-bold" style="background: #F8FAF8; color: #1A2F1A; border: 1px solid #E2EADF;"><?= h($brand) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<script>
// Hero Auto-Slider JS with Arrows & Swipe Gestures
(() => {
  const container = document.getElementById('heroSlider');
  if (!container) return;

  const slides = container.querySelectorAll('.hero-slide');
  const dots = container.querySelectorAll('.hero-dot');
  const prevBtn = document.getElementById('heroPrev');
  const nextBtn = document.getElementById('heroNext');
  let currentSlide = 0;
  let timer = null;

  const showSlide = (index) => {
    slides.forEach((slide, i) => slide.classList.toggle('active', i === index));
    dots.forEach((dot, i) => dot.classList.toggle('active', i === index));
    currentSlide = index;
  };

  const nextSlide = () => showSlide((currentSlide + 1) % slides.length);
  const prevSlide = () => showSlide((currentSlide - 1 + slides.length) % slides.length);

  const startTimer = () => {
    stopTimer();
    timer = setInterval(nextSlide, 7000);
  };

  const stopTimer = () => {
    if (timer) clearInterval(timer);
  };

  if (prevBtn) prevBtn.addEventListener('click', () => { prevSlide(); startTimer(); });
  if (nextBtn) nextBtn.addEventListener('click', () => { nextSlide(); startTimer(); });

  dots.forEach((dot) => {
    dot.addEventListener('click', () => {
      const target = parseInt(dot.getAttribute('data-slide-target'), 10);
      showSlide(target);
      startTimer();
    });
  });

  // Touch Swipe for Mobile
  let touchStartX = 0;
  let touchEndX = 0;
  container.addEventListener('touchstart', (e) => {
    touchStartX = e.changedTouches[0].screenX;
  }, { passive: true });

  container.addEventListener('touchend', (e) => {
    touchEndX = e.changedTouches[0].screenX;
    if (touchStartX - touchEndX > 50) { nextSlide(); startTimer(); }
    else if (touchEndX - touchStartX > 50) { prevSlide(); startTimer(); }
  }, { passive: true });

  container.addEventListener('mouseenter', stopTimer);
  container.addEventListener('mouseleave', startTimer);

  startTimer();
})();

// Flash Sale Countdown JS
(() => {
  const root = document.querySelector('[data-flash-sale-countdown]');
  if (!root) return;

  const dayEl = root.querySelector('[data-flash-days]');
  const hourEl = root.querySelector('[data-flash-hours]');
  const minuteEl = root.querySelector('[data-flash-minutes]');
  const secondEl = root.querySelector('[data-flash-seconds]');
  const target = new Date();
  target.setHours(23, 59, 59, 999);

  const pad = (val) => String(Math.max(0, val)).padStart(2, '0');

  const tick = () => {
    const diff = Math.max(0, target.getTime() - Date.now());
    const totalSeconds = Math.floor(diff / 1000);
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (dayEl) dayEl.textContent = pad(days);
    if (hourEl) hourEl.textContent = pad(hours);
    if (minuteEl) minuteEl.textContent = pad(minutes);
    if (secondEl) secondEl.textContent = pad(seconds);
  };

  tick();
  setInterval(tick, 1000);
})();
</script>
