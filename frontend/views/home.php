<?php
// frontend/views/home.php - SkinSyntax Redesigned Homepage
$latest = isset($latest) && is_array($latest) ? $latest : [];
$cats = isset($cats) && is_array($cats) ? $cats : [];
$homepageSections = isset($homepageSections) && is_array($homepageSections) ? $homepageSections : [];
$dbUnavailableMessage = trim((string)($dbUnavailableMessage ?? ''));

$userProfile = is_array($userProfile ?? null) ? $userProfile : [];
$isLoggedIn = (bool)($isLoggedIn ?? false);
$hasSurvey = (bool)($hasSurvey ?? false);

// Prepare products for sections (exactly 4 products each for single-row layout)
$flashSaleProducts = array_slice(array_values(array_filter($homepageSections['flashDeals'] ?? [])), 0, 4);
if (empty($flashSaleProducts)) {
    $flashSaleProducts = array_slice($latest, 0, 4);
}

$newProducts = array_slice($latest, 0, 4);

$forYouProducts = array_slice(array_values(array_filter($homepageSections['forYou'] ?? $homepageSections['bestSellers'] ?? [])), 0, 4);
if (empty($forYouProducts)) {
    $forYouProducts = array_slice($latest, 3, 4);
}

// Brand names
$brandNames = [];
foreach ($latest as $item) {
    $brand = trim((string)($item['thuong_hieu'] ?? ''));
    if ($brand !== '' && !in_array($brand, $brandNames, true)) {
        $brandNames[] = $brand;
    }
    if (count($brandNames) >= 8) {
        break;
    }
}

// Skin concern categories for quick shortcuts (8 items total for 2 full rows of 4)
$skinConcernCategories = [
    [
        'id' => 'oil_acne',
        'title' => 'Da dầu & mụn',
        'icon' => 'fa-droplet-slash',
        'query' => 'mụn',
        'why' => 'Chứa Niacinamide & BHA kiểm soát nhờn, ngừa mụn',
    ],
    [
        'id' => 'sensitive',
        'title' => 'Da nhạy cảm',
        'icon' => 'fa-shield-heart',
        'query' => 'nhạy cảm',
        'why' => 'Công thức dịu nhẹ, củng cố hàng rào bảo vệ da',
    ],
    [
        'id' => 'hydrate',
        'title' => 'Cấp ẩm',
        'icon' => 'fa-water',
        'query' => 'dưỡng ẩm',
        'why' => 'Cấp nước đa tầng, phục hồi làn da khô căng',
    ],
    [
        'id' => 'repair',
        'title' => 'Phục hồi',
        'icon' => 'fa-hand-holding-medical',
        'query' => 'phục hồi',
        'why' => 'Tái tạo da tổn thương với Ceramides & B5',
    ],
    [
        'id' => 'sunscreen',
        'title' => 'Chống nắng',
        'icon' => 'fa-sun',
        'query' => 'chống nắng',
        'why' => 'Bảo vệ màng lọc phổ rộng trước tia UVA/UVB',
    ],
    [
        'id' => 'dark_spots',
        'title' => 'Thâm / xỉn màu',
        'icon' => 'fa-sparkles',
        'query' => 'thâm',
        'why' => 'Ức chế sắc tố Melanin, dưỡng da sáng mịn',
    ],
    [
        'id' => 'anti_aging',
        'title' => 'Chống lão hóa',
        'icon' => 'fa-hourglass-half',
        'query' => 'lão hóa',
        'why' => 'Kích thích tổng hợp Collagen, giảm nếp nhăn',
    ],
    [
        'id' => 'pores',
        'title' => 'Se lỗ chân lông',
        'icon' => 'fa-compress',
        'query' => 'lỗ chân lông',
        'why' => 'Thu nhỏ lỗ chân lông, thông thoáng bề mặt da',
    ],
];

// Product card renderer
$renderHomeProductCard = static function (array $p, string $tag = '', string $whyFitText = '') use ($hasSurvey): void {
    $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
    $img = resolve_image_url((string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
    $giaBan = (string)($p['gia_ban'] ?? '');
    $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
    $phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
    $matchScore = isset($p['match_score']) && is_numeric($p['match_score']) ? (int)$p['match_score'] : null;
    $rating = isset($p['diem_danh_gia']) && (float)$p['diem_danh_gia'] > 0 ? (float)$p['diem_danh_gia'] : 4.9;
    $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($p) : false;
    ?>
    <div class="product-card product-card--editorial h-100 d-flex flex-column">
      <div class="product-thumb position-relative p-2" style="background: #F8FAF8; border-radius: 12px 12px 0 0;">
        <?php if ($phanTramGiam !== null && $phanTramGiam > 0): ?>
          <span class="badge-sale position-absolute" style="top: 10px; left: 10px; background: #183B2B; color: #FFFFFF; font-weight: 700; font-size: 0.72rem; padding: 3px 8px; border-radius: 4px; z-index: 3;">
            -<?= h((string)$phanTramGiam) ?>%
          </span>
        <?php endif; ?>

        <?php if ($matchScore !== null && $matchScore > 0): ?>
          <span class="badge-match position-absolute" style="top: 10px; right: 10px; background: #EBF2EE; color: #183B2B; border: 1px solid #C8DACF; font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 4px; z-index: 3;">
            <?= $matchScore ?>% MATCH
          </span>
        <?php elseif ($tag !== ''): ?>
          <span class="market-card__tag position-absolute" style="top: 10px; right: 10px; background: #F1F5F9; color: #475569; font-size: 0.7rem; font-weight: 600; padding: 3px 8px; border-radius: 4px; z-index: 3; border: 1px solid #E2E8F0;">
            <?= h($tag) ?>
          </span>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" class="d-block w-100 overflow-hidden" style="border-radius: 8px; aspect-ratio: 1/1;">
          <img class="product-card-img" src="<?= h($img ?: default_placeholder_image()) ?>" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';" alt="<?= h($p['ten_san_pham'] ?? '') ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
        </a>
      </div>

      <div class="product-meta p-3 d-flex flex-column flex-grow-1">
        <div class="mb-1 d-flex align-items-center justify-content-between">
          <span class="brand text-uppercase" style="font-size: 0.68rem; color: #183B2B; background: #EBF2EE; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.04em; font-weight: 700;">
            <?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?>
          </span>

          <span class="skin-match-status" style="font-size: 0.72rem; color: var(--muted);">
            <?php if ($matchScore !== null && $matchScore > 0): ?>
              <span class="text-success fw-semibold"><i class="bi bi-check2-circle"></i> Phù hợp da</span>
            <?php elseif ($hasSurvey): ?>
              <span class="text-success fw-semibold" style="font-size: 0.7rem;"><i class="bi bi-shield-check"></i> Đã khảo sát</span>
            <?php else: ?>
              <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="text-decoration-none text-muted" style="font-size: 0.7rem;"><i class="bi bi-info-circle"></i> Độ hợp &rarr;</a>
            <?php endif; ?>
          </span>
        </div>

        <a class="name fw-semibold mb-1 text-decoration-none" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" style="color: #0F172A; font-size: 0.88rem; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.4em;">
          <?= h($p['ten_san_pham'] ?? '') ?>
        </a>

        <?php if ($whyFitText !== ''): ?>
          <div class="mb-2 p-1.5 rounded" style="background: #F1F8F4; border: 1px solid #D8EADE; font-size: 0.72rem; color: #1C4D36;">
            <i class="bi bi-lightbulb-fill text-warning me-1"></i><strong>Vì sao phù hợp:</strong> <?= h($whyFitText) ?>
          </div>
        <?php endif; ?>

        <div class="d-flex align-items-center gap-1 mb-2" style="font-size: 0.76rem;">
          <span class="d-inline-flex align-items-center gap-1 text-warning font-weight-bold">
            <i class="fas fa-star" style="color: #F59E0B; font-size: 0.72rem;"></i> <?= number_format($rating, 1) ?>
          </span>
          <span class="text-muted small ms-1">(<?= (int)($p['so_luong_danh_gia'] ?? 128) ?>)</span>
        </div>

        <div class="price-wrap mb-3 mt-auto d-flex align-items-baseline gap-2">
          <div class="price fw-bold" style="color: #183B2B; font-size: 1.05rem; font-variant-numeric: tabular-nums;"><?= vnd($giaBan) ?></div>
          <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)$giaBan): ?>
            <div class="price-market text-muted text-decoration-line-through" style="font-size: 0.8rem; font-variant-numeric: tabular-nums; color: #94A3B8 !important;"><?= vnd($giaThiTruong) ?></div>
          <?php endif; ?>
        </div>

        <div class="product-card-actions d-grid gap-2" style="grid-template-columns: 1fr 1fr;">
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="btn btn-sm w-100" type="submit" style="background: #F1F5F9; color: #0F172A; border: 1px solid #E2E8F0; border-radius: 6px; font-weight: 600; font-size: 0.78rem; padding: 7px 0; transition: all 0.2s ease;" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1"></i> Thêm' ?></button>
          </form>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="buy_now" value="1">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="btn btn-sm w-100 text-white" type="submit" style="background: #183B2B; border-radius: 6px; font-weight: 600; font-size: 0.78rem; padding: 7px 0; border: none; transition: background 0.2s ease;" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Hết hàng' : 'Mua ngay' ?></button>
          </form>
        </div>
      </div>
    </div>
    <?php
};
?>

<div class="container mt-4 home-shell">
  <?php if ($dbUnavailableMessage !== ''): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4" style="border-radius: 12px; background: #FFFBEB; color: #B45309;"><?= h($dbUnavailableMessage) ?></div>
  <?php endif; ?>

  <!-- 1. HERO SECTION -->
  <section class="hero-skinsyntax mb-4" style="background: linear-gradient(135deg, #183B2B 0%, #2D6A4F 60%, #11281D 100%) !important; border-radius: 20px; color: #FFFFFF !important; padding: 40px 36px; position: relative; overflow: hidden; box-shadow: 0 12px 32px rgba(24, 59, 43, 0.15);">
    <div class="row align-items-center">
      <div class="col-lg-7 col-md-8">
        <div class="d-inline-flex align-items-center gap-2 px-3 py-1.5 mb-3" style="background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.25); border-radius: 999px; font-size: 0.78rem; font-weight: 700; color: #FFFFFF;">
          <span style="width: 8px; height: 8px; background: #84A98C; border-radius: 50%;"></span>
          <span>SkinSyntax AI Skincare Platform</span>
        </div>
        
        <h1 class="fw-bold mb-3" style="color: #FFFFFF !important; font-size: clamp(1.8rem, 4vw, 2.7rem); line-height: 1.2; letter-spacing: -0.02em;">
          Skincare hiểu làn da của bạn.
        </h1>
        
        <p class="mb-4" style="font-size: 1.02rem; line-height: 1.6; max-width: 580px; color: rgba(255,255,255,0.92) !important;">
          Khám phá mỹ phẩm phù hợp dựa trên hồ sơ da, vấn đề da và routine cá nhân của bạn.
        </p>

        <div class="d-flex flex-wrap gap-3">
          <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn text-white fw-bold px-4 py-2.5 shadow-sm" style="background: #2D6A4F; border-radius: 8px; font-size: 0.92rem; border: none;">
            <i class="fas fa-search me-1.5"></i> Khám phá sản phẩm
          </a>
          <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="btn fw-semibold px-4 py-2.5" style="background: rgba(255,255,255,0.18); color: #FFFFFF !important; border: 1px solid rgba(255,255,255,0.35); border-radius: 8px; font-size: 0.92rem;">
            <i class="fas fa-clipboard-list me-1.5"></i> Khảo sát da 1 phút
          </a>
        </div>
      </div>

      <!-- SYNA AI MASCOT COMPONENT PLACEHOLDER -->
      <div class="col-lg-5 col-md-4 mt-4 mt-md-0 text-center">
        <div class="syna-mascot-container p-3">
          <div class="syna-badge-glow"></div>
          
          <div class="position-relative d-inline-block p-4 rounded-4" style="background: #132E22; border: 1px solid rgba(255,255,255,0.2); box-shadow: 0 20px 40px rgba(0,0,0,0.3);">
            <!-- SYNA Mascot Vector Placeholder (Cute Cream Cat in Dark Green SkinSyntax Outfit & Mint Accents) -->
            <svg width="150" height="150" viewBox="0 0 200 200" fill="none" xmlns="http://www.w3.org/2000/svg">
              <!-- Background Aura -->
              <circle cx="100" cy="100" r="85" fill="#2D6A4F" fill-opacity="0.35"/>
              
              <!-- Cat Tail -->
              <path d="M145 135 C165 130 170 110 160 100 C155 95 145 105 148 115" stroke="#FFF7EE" stroke-width="10" stroke-linecap="round"/>
              
              <!-- Cat Body (Dark Green Outfit) -->
              <path d="M60 145 C60 115 80 105 100 105 C120 105 140 115 140 145 L140 160 C140 165 135 170 130 170 L70 170 C65 170 60 165 60 160 Z" fill="#183B2B"/>
              <!-- Collar/Mint Trim -->
              <path d="M80 106 Q100 120 120 106 Q100 112 80 106 Z" fill="#84A98C"/>
              <circle cx="100" cy="116" r="4" fill="#C8DACF"/>
              
              <!-- Cat Head (Cream White) -->
              <ellipse cx="100" cy="72" rx="42" ry="36" fill="#FFF7EE"/>
              
              <!-- Left Ear -->
              <path d="M64 54 L52 24 L78 44 Z" fill="#FFF7EE"/>
              <path d="M66 50 L57 32 L75 44 Z" fill="#F9D7DA"/>
              
              <!-- Right Ear -->
              <path d="M136 54 L148 24 L122 44 Z" fill="#FFF7EE"/>
              <path d="M134 50 L143 32 L125 44 Z" fill="#F9D7DA"/>
              
              <!-- Eyes (Cute Dark Emerald Green) -->
              <ellipse cx="84" cy="70" rx="6" ry="8" fill="#183B2B"/>
              <circle cx="86" cy="68" r="2.5" fill="#FFFFFF"/>
              
              <ellipse cx="116" cy="70" rx="6" ry="8" fill="#183B2B"/>
              <circle cx="118" cy="68" r="2.5" fill="#FFFFFF"/>
              
              <!-- Pink Nose & Cute Mouth -->
              <path d="M97 78 L103 78 L100 82 Z" fill="#F497A9"/>
              <path d="M95 84 Q100 88 105 84" stroke="#7A6858" stroke-width="2" stroke-linecap="round"/>
              
              <!-- Soft Pink Cheeks -->
              <ellipse cx="76" cy="78" rx="5" ry="3" fill="#FFC0CB" opacity="0.6"/>
              <ellipse cx="124" cy="78" rx="5" ry="3" fill="#FFC0CB" opacity="0.6"/>
              
              <!-- Skincare Bottle Asset in Paws -->
              <rect x="94" y="125" width="12" height="22" rx="3" fill="#84A98C"/>
              <rect x="97" y="121" width="6" height="4" rx="1" fill="#183B2B"/>
              <path d="M72 135 Q90 138 94 135" stroke="#FFF7EE" stroke-width="7" stroke-linecap="round"/>
              <path d="M128 135 Q110 138 106 135" stroke="#FFF7EE" stroke-width="7" stroke-linecap="round"/>
            </svg>

            <div class="mt-2 text-white fw-bold" style="font-size: 0.95rem;">SYNA AI Advisor</div>
            <div class="small" style="color: #C8DACF; font-size: 0.76rem;">Bác sĩ da liễu AI & Chuyên gia Routine</div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- 2. QUICK SHOP BY SKIN CONCERN -->
  <section class="mb-5">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.15rem;">
        <i class="fas fa-sliders-h text-success me-2"></i> Tra cứu nhanh theo nhu cầu da
      </h2>
      <span class="text-muted small" style="font-size: 0.78rem;">Chọn để xem sản phẩm phù hợp</span>
    </div>

    <div class="concern-shortcut-bar">
      <?php foreach ($skinConcernCategories as $item): ?>
        <a href="<?= BASE_URL ?>/index.php?r=tatca&q=<?= urlencode($item['query']) ?>" class="concern-chip">
          <i class="fas <?= h($item['icon']) ?>"></i>
          <span><?= h($item['title']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- 3. PERSONALIZED PRODUCTS (DÀNH RIÊNG CHO LÀN DA CỦA BẠN) -->
  <section class="mb-5 p-4 bg-white border" style="border-radius: 16px; border-color: #E2E8F0 !important;">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3 pb-3 border-bottom">
      <div>
        <div class="d-inline-flex align-items-center gap-1.5 mb-1" style="color: #183B2B; font-weight: 700; font-size: 0.76rem; letter-spacing: 0.05em; text-transform: uppercase;">
          <i class="fas fa-sparkles text-warning"></i> SkinSyntax Personalization Engine
        </div>
        <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;">Dành riêng cho làn da của bạn</h2>
      </div>

      <div>
        <?php if ($hasSurvey && !empty($userProfile)): ?>
          <span class="personalized-header-badge">
            <i class="fas fa-user-check"></i>
            Hồ sơ: <?= h(!empty($userProfile['skin_type']) ? $userProfile['skin_type'] : 'Đã phân tích') ?> 
            <?= !empty($userProfile['concerns']) ? '• ' . h(implode(', ', array_slice($userProfile['concerns'], 0, 2))) : '' ?>
          </span>
        <?php else: ?>
          <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="btn btn-sm btn-outline-success fw-semibold" style="border-radius: 6px; font-size: 0.8rem;">
            <i class="fas fa-clipboard-check me-1"></i> Làm khảo sát da để nhận gợi ý
          </a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($hasSurvey): ?>
      <div class="row g-3">
        <?php foreach ($forYouProducts as $idx => $p): ?>
          <div class="col-6 col-md-3">
            <?php 
              $whyFit = $skinConcernCategories[$idx % count($skinConcernCategories)]['why'] ?? 'Phù hợp độ ẩm & chỉ số da';
              $renderHomeProductCard($p, 'Gợi ý riêng', $whyFit); 
            ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="p-4 text-center rounded-3" style="background: #F8FAF8; border: 1px dashed #C8DACF;">
        <div class="d-inline-flex align-items-center justify-content-center mb-2" style="width: 50px; height: 50px; background: #EBF2EE; color: #183B2B; border-radius: 50%; font-size: 1.3rem;">
          <i class="fas fa-fingerprint"></i>
        </div>
        <h3 class="fw-bold mb-1" style="font-size: 1.1rem; color: #0F172A;">Khảo sát da để nhận gợi ý riêng</h3>
        <p class="text-muted small mx-auto mb-3" style="max-width: 480px; font-size: 0.85rem;">
          Chỉ mất 1 phút hoàn thành câu hỏi về loại da, tình trạng mụn và ngân sách, SkinSyntax sẽ tính toán mức độ tương thích mỹ phẩm chuẩn xác.
        </p>
        <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="btn text-white fw-bold px-4 py-2" style="background: #183B2B; border-radius: 6px; font-size: 0.86rem;">
          Bắt đầu bài khảo sát da &rarr;
        </a>
      </div>
    <?php endif; ?>
  </section>

  <!-- 4. FLASH SALE -->
  <?php if (!empty($flashSaleProducts)): ?>
    <section class="mb-5 p-4 text-white" style="background: #183B2B; border-radius: 16px; border: 1px solid #2D6A4F;" data-flash-sale-countdown>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
          <span class="text-uppercase fw-semibold small text-white-50" style="letter-spacing: 0.05em; font-size: 0.72rem;">ƯU ĐẤI CHỚP NHOÁNG</span>
          <h2 class="fw-bold m-0 text-white" style="font-size: 1.5rem;"><i class="fas fa-bolt text-warning me-2"></i> Flash Sale Mỹ Phẩm</h2>
        </div>

        <div class="d-flex align-items-center gap-2" aria-label="Thời gian còn lại">
          <div class="text-center px-3 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 6px; min-width: 46px;">
            <strong class="d-block text-white fs-5 fw-bold tabular-nums" data-flash-days>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.6rem;">Ngày</small>
          </div>
          <span class="text-white fw-bold fs-5">:</span>
          <div class="text-center px-3 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 6px; min-width: 46px;">
            <strong class="d-block text-white fs-5 fw-bold tabular-nums" data-flash-hours>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.6rem;">Giờ</small>
          </div>
          <span class="text-white fw-bold fs-5">:</span>
          <div class="text-center px-3 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 6px; min-width: 46px;">
            <strong class="d-block text-white fs-5 fw-bold tabular-nums" data-flash-minutes>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.6rem;">Phút</small>
          </div>
          <span class="text-white fw-bold fs-5">:</span>
          <div class="text-center px-3 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 6px; min-width: 46px;">
            <strong class="d-block text-white fs-5 fw-bold tabular-nums" data-flash-seconds>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.6rem;">Giây</small>
          </div>
        </div>

        <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn btn-light px-3.5 py-2 fw-semibold" style="color: #183B2B; border-radius: 6px; font-size: 0.84rem; background: #FFF; border: none;">Xem tất cả deal &rarr;</a>
      </div>

      <div class="row g-3">
        <?php foreach ($flashSaleProducts as $p): ?>
          <div class="col-6 col-md-3">
            <?php $renderHomeProductCard($p, 'Flash Sale'); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- 5. SYNA AI LIVESTREAM SECTION (CLEAN HIGH-CONTRAST AUTHENTIC LAYOUT) -->
  <section class="mb-5 p-4 text-white" style="background: #183B2B; border-radius: 20px; border: 1px solid #2D6A4F; box-shadow: 0 12px 32px rgba(24, 59, 43, 0.15);">
    <div class="row align-items-center g-4">
      <div class="col-lg-6">
        <div class="position-relative rounded-4 overflow-hidden border border-white border-opacity-20" style="aspect-ratio: 16/9; background: linear-gradient(135deg, #11281D 0%, #183B2B 100%);">
          <!-- Live Indicator Badge -->
          <div class="position-absolute top-0 start-0 m-3 z-3 d-flex align-items-center gap-2">
            <span class="badge bg-danger text-white fw-bold px-3 py-1.5" style="border-radius: 999px; font-size: 0.72rem; letter-spacing: 0.05em;">
              <span class="live-indicator-pulse me-1"></span> LIVE SESSION
            </span>
          </div>

          <!-- Video Stream Placeholder Frame -->
          <div class="w-100 h-100 d-flex flex-column align-items-center justify-content-center text-center p-3">
            <div class="mb-3 position-relative">
              <div class="p-3 rounded-circle" style="background: rgba(132, 169, 140, 0.25); border: 2px solid #84A98C;">
                <i class="fas fa-play text-white fs-3 ms-1"></i>
              </div>
            </div>
            <h4 class="fw-bold text-white mb-1" style="font-size: 1.25rem;">SYNA AI Skincare Stream</h4>
            <p class="text-white-80 small mb-3" style="max-width: 360px; font-size: 0.84rem; color: rgba(255,255,255,0.85);">
              Tư vấn quy trình skincare cá nhân hóa &amp; nhận ưu đãi độc quyền trên sóng trực tiếp.
            </p>
            <a href="<?= BASE_URL ?>/index.php?r=live" class="btn text-white px-4 py-2 fw-bold" style="border-radius: 999px; font-size: 0.85rem; background: #2D6A4F; border: 1px solid #84A98C;">
              <i class="fas fa-video me-1.5"></i> Tham gia Livestream ngay
            </a>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="p-2">
          <div class="d-inline-flex align-items-center gap-1.5 mb-2 text-warning fw-bold small text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
            <i class="fas fa-broadcast-tower"></i> Tính năng nổi bật
          </div>
          <h3 class="fw-bold text-white mb-2" style="font-size: 1.5rem;">SYNA AI Skincare Livestream</h3>
          <p class="text-white-80 small mb-3" style="font-size: 0.88rem; line-height: 1.6; color: rgba(255,255,255,0.88);">
            Kênh tư vấn trực tiếp chuẩn y khoa với SYNA — trợ lý AI phân tích hoạt chất mỹ phẩm, giải đáp thắc mắc routine và đề xuất voucher thực tế theo nhu cầu da của bạn.
          </p>

          <!-- Featured Product in Live -->
          <?php 
            $featLiveProd = null;
            foreach ($latest as $itemCandidate) {
                $cId = trim((string)($itemCandidate['ma_san_pham'] ?? $itemCandidate['id'] ?? $itemCandidate['_id'] ?? ''));
                if ($cId !== '') {
                    $featLiveProd = $itemCandidate;
                    break;
                }
            }
            $featProdId = $featLiveProd ? trim((string)($featLiveProd['ma_san_pham'] ?? $featLiveProd['id'] ?? $featLiveProd['_id'] ?? '')) : '';
          ?>
          <?php if ($featLiveProd && $featProdId !== ''): ?>
            <div class="p-3 rounded-3 mb-3 d-flex align-items-center gap-3" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.18);">
              <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= rawurlencode($featProdId) ?>" class="d-block flex-shrink-0">
                <img src="<?= h(resolve_image_url((string)($featLiveProd['link_hinh_anh'] ?? $featLiveProd['hinh_anh'] ?? ''))) ?>" alt="<?= h($featLiveProd['ten_san_pham'] ?? 'Live Product') ?>" style="width: 58px; height: 58px; object-fit: cover; border-radius: 8px; background: #FFF;">
              </a>
              <div class="overflow-hidden flex-grow-1">
                <span class="badge bg-success mb-1" style="font-size: 0.65rem; background: #2D6A4F !important;">Sản phẩm ghim trong live</span>
                <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= rawurlencode($featProdId) ?>" class="text-white fw-semibold text-truncate small d-block text-decoration-none">
                  <?= h($featLiveProd['ten_san_pham'] ?? '') ?>
                </a>
                <div class="text-warning fw-bold small"><?= vnd($featLiveProd['gia_ban'] ?? 0) ?></div>
              </div>
              <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= rawurlencode($featProdId) ?>" class="btn btn-sm btn-light text-nowrap fw-semibold" style="font-size: 0.78rem; border-radius: 6px; color: #183B2B;">Xem sản phẩm</a>
            </div>
          <?php endif; ?>

          <div class="d-flex align-items-center gap-3 mt-3">
            <a href="<?= BASE_URL ?>/index.php?r=live" class="btn btn-light fw-bold text-nowrap px-4 py-2" style="color: #183B2B; border-radius: 8px; font-size: 0.86rem; background: #FFFFFF; border: none;">
              Vào phòng livestream &rarr;
            </a>
            <span class="text-white-50 small" style="font-size: 0.78rem;"><i class="fas fa-shield-alt text-success me-1"></i> Tư vấn AI thời gian thực</span>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- 6. NEW PRODUCTS (MỸ PHẨM VỪA LÊN KỆ) -->
  <?php if (!empty($newProducts)): ?>
    <section class="mb-5">
      <div class="d-flex justify-content-between align-items-end mb-3">
        <div>
          <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.72rem;">SẢN PHẨM MỚI</span>
          <h3 class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;">Mỹ Phẩm Vừa Lên Kệ</h3>
        </div>
        <a href="<?= BASE_URL ?>/index.php?r=tatca" class="fw-semibold text-decoration-none" style="color: #183B2B; font-size: 0.85rem;">Xem tất cả <i class="fas fa-arrow-right ms-1"></i></a>
      </div>

      <div class="row g-3">
        <?php foreach ($newProducts as $p): ?>
          <div class="col-6 col-md-3">
            <?php $renderHomeProductCard($p, 'Mới lên kệ'); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- 7. SHOP BY SKIN CONCERN (MUA THEO VẤN ĐỀ DA - 8 Ô CHUẨN 2 HÀNG FULL) -->
  <section class="mb-5 p-4 bg-white border" style="border-radius: 16px; border-color: #E2E8F0 !important;">
    <div class="d-flex justify-content-between align-items-end mb-3">
      <div>
        <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.72rem;">DISCOVERY CATEGORIES</span>
        <h3 class="fw-bold m-0" style="color: #0F172A; font-size: 1.4rem;">Mua theo vấn đề da</h3>
      </div>
    </div>

    <div class="row g-3">
      <?php foreach ($skinConcernCategories as $concern): ?>
        <div class="col-6 col-md-4 col-lg-3">
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=<?= urlencode($concern['query']) ?>" class="d-block p-3 rounded-3 border text-decoration-none h-100" style="background: #F8FAF8; border-color: #E2E8F0 !important; transition: all 0.2s ease;">
            <div class="d-flex align-items-center gap-3">
              <div class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 44px; height: 44px; background: #EBF2EE; color: #183B2B; font-size: 1.1rem;">
                <i class="fas <?= h($concern['icon']) ?>"></i>
              </div>
              <div>
                <h4 class="fw-bold mb-0 text-dark" style="font-size: 0.92rem;"><?= h($concern['title']) ?></h4>
                <small class="text-muted" style="font-size: 0.74rem;">Khám phá sản phẩm &rarr;</small>
              </div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- 8. PERSONAL ROUTINE (ROUTINE CỦA BẠN - COMPACT & COMMERCE ORIENTED) -->
  <section class="mb-5 p-4 bg-white border" style="border-radius: 16px; border-color: #E2E8F0 !important;">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
      <div>
        <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.72rem;">DAILY SKINCARE REGIMEN</span>
        <h3 class="fw-bold m-0" style="color: #0F172A; font-size: 1.4rem;">Routine của bạn</h3>
      </div>
      <a href="<?= BASE_URL ?>/index.php?r=goiy" class="btn btn-sm text-white fw-semibold px-3 py-1.5" style="background: #183B2B; border-radius: 6px; font-size: 0.8rem;">
        Xem routine đầy đủ &rarr;
      </a>
    </div>

    <div class="row g-3">
      <div class="col-6 col-md-3">
        <a href="<?= BASE_URL ?>/index.php?r=tatca&q=l%C3%A0m+s%E1%BA%A1ch" class="routine-step-pill text-decoration-none">
          <div class="routine-step-num">01</div>
          <div>
            <div class="text-uppercase text-muted fw-bold" style="font-size: 0.65rem;">STEP 1</div>
            <div class="fw-bold text-dark" style="font-size: 0.9rem;">Cleanse (Làm sạch)</div>
          </div>
        </a>
      </div>

      <div class="col-6 col-md-3">
        <a href="<?= BASE_URL ?>/index.php?r=tatca&q=serum" class="routine-step-pill text-decoration-none">
          <div class="routine-step-num">02</div>
          <div>
            <div class="text-uppercase text-muted fw-bold" style="font-size: 0.65rem;">STEP 2</div>
            <div class="fw-bold text-dark" style="font-size: 0.9rem;">Treat (Đặc trị)</div>
          </div>
        </a>
      </div>

      <div class="col-6 col-md-3">
        <a href="<?= BASE_URL ?>/index.php?r=tatca&q=d%C6%B0%E1%BB%A1ng+%E1%BA%A9m" class="routine-step-pill text-decoration-none">
          <div class="routine-step-num">03</div>
          <div>
            <div class="text-uppercase text-muted fw-bold" style="font-size: 0.65rem;">STEP 3</div>
            <div class="fw-bold text-dark" style="font-size: 0.9rem;">Hydrate (Dưỡng ẩm)</div>
          </div>
        </a>
      </div>

      <div class="col-6 col-md-3">
        <a href="<?= BASE_URL ?>/index.php?r=tatca&q=ch%E1%BB%91ng+n%E1%BA%AFng" class="routine-step-pill text-decoration-none">
          <div class="routine-step-num">04</div>
          <div>
            <div class="text-uppercase text-muted fw-bold" style="font-size: 0.65rem;">STEP 4</div>
            <div class="fw-bold text-dark" style="font-size: 0.9rem;">Protect (Bảo vệ)</div>
          </div>
        </a>
      </div>
    </div>
  </section>

  <!-- 9. TRUSTED BRANDS -->
  <?php if (!empty($brandNames)): ?>
    <section class="p-4 mb-5 bg-white border text-center" style="border-radius: 12px; border-color: #E2E8F0 !important;">
      <span class="text-uppercase fw-semibold small text-muted mb-2.5 d-block" style="letter-spacing: 0.05em; font-size: 0.72rem;">THƯƠNG HIỆU Y KHOA ĐƯỢC TIN DÙNG</span>
      <div class="d-flex flex-wrap justify-content-center gap-2">
        <?php foreach ($brandNames as $brand): ?>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=<?= urlencode($brand) ?>" class="btn btn-sm btn-light px-3 py-1.5 fw-semibold" style="background: #F8FAF8; color: #0F172A; border: 1px solid #E2E8F0; border-radius: 6px; font-size: 0.8rem;"><?= h($brand) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<script>
// Flash Sale Countdown Timer Script
document.addEventListener('DOMContentLoaded', function () {
  var root = document.querySelector('[data-flash-sale-countdown]');
  if (root) {
    var dayEl = root.querySelector('[data-flash-days]');
    var hourEl = root.querySelector('[data-flash-hours]');
    var minuteEl = root.querySelector('[data-flash-minutes]');
    var secondEl = root.querySelector('[data-flash-seconds]');
    var target = new Date();
    target.setHours(23, 59, 59, 999);

    var pad = function (val) { return String(Math.max(0, val)).padStart(2, '0'); };

    var tick = function () {
      var diff = Math.max(0, target.getTime() - Date.now());
      var totalSeconds = Math.floor(diff / 1000);
      var days = Math.floor(totalSeconds / 86400);
      var hours = Math.floor((totalSeconds % 86400) / 3600);
      var minutes = Math.floor((totalSeconds % 3600) / 60);
      var seconds = totalSeconds % 60;

      if (dayEl) dayEl.textContent = pad(days);
      if (hourEl) hourEl.textContent = pad(hours);
      if (minuteEl) minuteEl.textContent = pad(minutes);
      if (secondEl) secondEl.textContent = pad(seconds);
    };

    tick();
    setInterval(tick, 1000);
  }
});
</script>
