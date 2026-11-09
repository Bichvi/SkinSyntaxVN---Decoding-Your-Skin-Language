<?php
// frontend/views/home.php - SkinSyntax Redesigned Homepage
$latest = isset($latest) && is_array($latest) ? $latest : [];
$cats = isset($cats) && is_array($cats) ? $cats : [];
$homepageSections = isset($homepageSections) && is_array($homepageSections) ? $homepageSections : [];
$dbUnavailableMessage = trim((string)($dbUnavailableMessage ?? ''));

$userProfile = is_array($userProfile ?? null) ? $userProfile : [];
$isLoggedIn = (bool)($isLoggedIn ?? false);
$hasSurvey = (bool)($hasSurvey ?? false);
$favoriteProductIds = array_values(array_unique(array_map('strval', (array)($favoriteProductIds ?? []))));

// Extract user display name for mascot greeting (Priority: nickname -> display_name -> first_name -> preferred_name -> ten -> ho_ten -> 'bạn')
$sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
$displayNameCandidate = trim((string)(
    $sessionUser['nickname'] ?? 
    $sessionUser['display_name'] ?? 
    $sessionUser['first_name'] ?? 
    $sessionUser['preferred_name'] ?? 
    $sessionUser['ten'] ?? 
    $sessionUser['ho_ten'] ?? 
    $userProfile['ho_ten'] ?? 
    $sessionUser['name'] ?? ''
));

$synaGreetingName = 'bạn';
if ($displayNameCandidate !== '') {
    $parts = preg_split('/\s+/', $displayNameCandidate);
    if (count($parts) >= 3) {
        $synaGreetingName = implode(' ', array_slice($parts, -2));
    } else {
        $synaGreetingName = $displayNameCandidate;
    }
}

// Build speech bubble sentence sequence
if ($isLoggedIn && $synaGreetingName !== 'bạn') {
    $synaSpeechSentences = [
        "Hi " . $synaGreetingName . ", mừng bạn ghé SYNA nha!",
        "Hôm nay da bạn thế nào rồi?",
        "Da đang khô, nổi mụn hay cần phục hồi?",
        "Khám phá làn da của bạn, SYNA tìm vài món phù hợp nhé!"
    ];
} else {
    $synaSpeechSentences = [
        "Hi bạn, mừng bạn ghé SYNA nha!",
        "Hôm nay da bạn thế nào rồi?",
        "Da đang khô, nổi mụn hay cần phục hồi?",
        "Khám phá làn da của bạn, SYNA tìm vài món phù hợp nhé!"
    ];
}

// Prepare products for sections (Phase 2D.2: Real campaign-driven Flash Sale with Personalized Deal Ranking)
$flashSaleCampaign = $primaryFlashCampaign ?? null;
$flashSaleMeta = $flashSalePersonalizedMeta ?? ['is_personalized' => false, 'ranking_mode' => 'BASELINE', 'user_state' => 'STATE_A'];
$flashSaleProducts = [];
if (!empty($flashSaleCampaign) && !empty($flashSaleCampaign['hydrated_items'])) {
    // Phase 2E Carousel Extension: Retain full ranked candidate set from active campaign
    $flashSaleProducts = $flashSaleCampaign['hydrated_items'];
}

$homeProductId = static function ($product): string {
    if (!is_array($product)) {
        return '';
    }
    return trim((string)($product['ma_san_pham'] ?? $product['id'] ?? $product['_id'] ?? $product['product_id'] ?? ''));
};

// Product sections can be backed by different recommenders. Keep the ranking
// decisions intact, but make the final homepage presentation distinct so a SKU
// is not repeated in every row when two pools overlap.
$takeUniqueHomeProducts = static function (array $candidates, int $limit, array &$seenIds) use ($homeProductId): array {
    $items = [];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $id = $homeProductId($candidate);
        if ($id === '' || isset($seenIds[$id])) {
            continue;
        }
        $seenIds[$id] = true;
        $items[] = $candidate;
        if (count($items) >= $limit) {
            break;
        }
    }
    return $items;
};

$filterHomeProducts = static function ($items): array {
    return array_values(array_filter(is_array($items) ? $items : [], static fn($item): bool => is_array($item)));
};

$forYouCandidates = array_merge(
    $filterHomeProducts($homepageSections['forYou'] ?? $homepageSections['bestSellers'] ?? []),
    $filterHomeProducts($homepageSections['adaptiveForYou'] ?? []),
    $latest
);
$bestSellerCandidates = array_merge(
    $filterHomeProducts($homepageSections['bestSellers'] ?? [])
);
$todayCandidates = array_merge(
    $filterHomeProducts($homepageSections['today'] ?? []),
    $latest
);
$topRatedCandidates = array_merge(
    $filterHomeProducts($homepageSections['topRatedWeighted'] ?? []),
    $latest
);
$newCandidates = array_merge(
    $filterHomeProducts($homepageSections['newProducts'] ?? []),
    $latest
);

$homeSeenProductIds = [];
$forYouProducts = $takeUniqueHomeProducts($forYouCandidates, 4, $homeSeenProductIds);
$bestSellerProducts = $takeUniqueHomeProducts($bestSellerCandidates, 8, $homeSeenProductIds);
$todayProducts = $takeUniqueHomeProducts($todayCandidates, 4, $homeSeenProductIds);
$topRatedWeightedProducts = $takeUniqueHomeProducts($topRatedCandidates, 4, $homeSeenProductIds);
$newProducts = $takeUniqueHomeProducts($newCandidates, 4, $homeSeenProductIds);

// The discovery rail intentionally has its own cursor: it should not repeat
// the visible rows above, while still having enough candidates on a cold start.
$homeDiscoverySeenIds = $homeSeenProductIds;
$homeDiscoveryProducts = $takeUniqueHomeProducts(array_merge(
    $filterHomeProducts($homepageSections['contentBased'] ?? []),
    $filterHomeProducts($homepageSections['forYou'] ?? []),
    $filterHomeProducts($homepageSections['today'] ?? []),
    $filterHomeProducts($homepageSections['topRatedWeighted'] ?? []),
    $filterHomeProducts($homepageSections['newProducts'] ?? []),
    $latest
), 8, $homeDiscoverySeenIds);

// If a very small catalog cannot fill all rows, retain the old fallback for the
// primary recommendation section rather than rendering an empty homepage block.
if (empty($forYouProducts)) {
    $fallbackSeenIds = [];
    $forYouProducts = $takeUniqueHomeProducts(array_slice($latest, 3), 4, $fallbackSeenIds);
}

$contentBasedProducts = $filterHomeProducts($homepageSections['contentBased'] ?? []);
$topSearches = array_slice($filterHomeProducts($homepageSections['topSearches'] ?? []), 0, 8);

$todaySectionKicker = 'GỢI Ý HÔM NAY · Được Yêu Thích Nhất';
// Legacy guest copy retained for the homepage personalization contract; the
// visible heading is intentionally shorter below/after signal-based mapping.
// Gợi ý hôm nay, mỗi lần ghé là một lựa chọn mới
$todaySectionTitle = 'Gợi ý hôm nay dành riêng cho bạn';
$todaySectionSub = 'Làm mới theo mối quan tâm của bạn.';
$newSectionTitle = 'Mỹ Phẩm Vừa Lên Kệ Hôm Nay';
$newSectionSub = 'Sản phẩm mới được chọn lại mỗi lần ghé thăm.';

$homeCategoryCards = isset($homeCategoryCards) && is_array($homeCategoryCards) ? $homeCategoryCards : [];
$homeBrandCards = isset($homeBrandCards) && is_array($homeBrandCards) ? $homeBrandCards : [];

$topSearchesAreFallback = false;
if (empty($topSearches)) {
    // Search popularity may be empty on a fresh/local install. Keep the strip
    // useful without claiming a hardcoded concern list is a global ranking.
    $topSearches = array_map(static function (array $item): array {
        return ['q' => (string)($item['name'] ?? ''), 'score' => 0];
    }, array_slice($homeCategoryCards, 0, 6));
    $topSearchesAreFallback = !empty($topSearches);
}

// Product card renderer
$renderHomeProductCard = require __DIR__ . '/partials/home_product_card.php';

// Dedicated Flash Sale Card Renderer (Real campaign pricing & discount badge)
$renderFlashSaleProductCard = function (array $p) use ($favoriteProductIds): void {
    $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
    $img = resolve_image_url(function_exists('product_image_source') ? product_image_source($p) : (string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
    $flashPrice = (int)($p['flash_price'] ?? $p['effective_price'] ?? 0);
    $regularPrice = (int)($p['regular_price'] ?? $p['gia_ban'] ?? 0);
    $discountPct = (int)($p['discount_percent'] ?? $p['phan_tram_giam'] ?? 0);
    $rawRating = (float)($p['diem_danh_gia'] ?? 0.0);
    $rawReviewCount = (int)($p['so_luong_danh_gia'] ?? 0);
    $hasGoodRating = ($rawRating >= 4.5 && $rawReviewCount >= 5);
    $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($p['raw_product'] ?? $p) : false;
    $isFavorite = in_array($productId, $favoriteProductIds, true);
    $productBrand = function_exists('product_brand_label') ? product_brand_label($p) : trim((string)($p['thuong_hieu'] ?? $p['brand'] ?? 'SkinSyntax'));
    ?>
    <div class="product-card product-card--editorial h-100 d-flex flex-column" style="background: #FFFFFF; border-radius: 12px; border: 1px solid rgba(255,255,255,0.15); overflow: hidden; box-shadow: 0 4px 14px rgba(0,0,0,0.06);">
      <div class="product-thumb position-relative p-2" style="background: #F8FAF8; border-radius: 12px 12px 0 0;">
        <?php if ($discountPct > 0): ?>
          <span class="badge-sale position-absolute" style="top: 10px; left: 10px; background: #DC2626; color: #FFFFFF; font-weight: 700; font-size: 0.75rem; padding: 3px 8px; border-radius: 4px; z-index: 3;">
            -<?= $discountPct ?>%
          </span>
        <?php endif; ?>

        <span class="position-absolute" style="top: 10px; right: 10px; background: #FEF3C7; color: #92400E; font-size: 0.68rem; font-weight: 700; padding: 2px 7px; border-radius: 4px; z-index: 3; border: 1px solid #FDE68A;">
          <i class="fas fa-bolt text-warning me-1"></i>Flash Deal
        </span>

        <button type="button"
                class="product-favorite-button <?= $isFavorite ? 'is-favorite' : '' ?>"
                data-favorite-product-id="<?= h($productId) ?>"
                aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>"
                title="<?= $isFavorite ? 'Bỏ khỏi yêu thích' : 'Lưu sản phẩm yêu thích' ?>">
          <i class="<?= $isFavorite ? 'fas' : 'far' ?> fa-heart" aria-hidden="true"></i>
          <span class="visually-hidden"><?= $isFavorite ? 'Bỏ khỏi yêu thích' : 'Lưu sản phẩm yêu thích' ?></span>
        </button>

        <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" class="d-block w-100 overflow-hidden" style="border-radius: 8px; aspect-ratio: 1/1;">
          <img class="product-card-img" loading="lazy" decoding="async" src="<?= h($img ?: default_placeholder_image()) ?>" referrerpolicy="no-referrer" data-image-fallbacks="<?= function_exists('product_image_fallbacks_json') ? product_image_fallbacks_json($p, $img) : '[]' ?>" data-image-placeholder="<?= h(default_placeholder_image()) ?>" onerror="window.SkinSyntaxImageFallback(this)" alt="<?= h(!empty($p['ten_san_pham']) ? $p['ten_san_pham'] : 'Sản phẩm Flash Sale') ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
        </a>
      </div>

      <div class="product-meta p-3 d-flex flex-column flex-grow-1" style="background: #FFFFFF;">
        <div class="mb-1">
          <span class="brand text-uppercase d-inline-block" style="font-size: 0.68rem; color: #183B2B; background: #EBF2EE; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.04em; font-weight: 700;">
            <?= h($productBrand) ?>
          </span>
        </div>

        <?php
            $reasonTag = trim((string)($p['flash_sale_reason_tag'] ?? ''));
            if ($reasonTag === '' && $hasGoodRating) {
                $reasonTag = 'Được đánh giá tốt';
            }
        ?>
        <?php if ($reasonTag !== ''): ?>
          <div class="mb-1">
            <span class="badge" style="background: #ECFDF5; color: #047857; font-size: 0.68rem; font-weight: 600; border: 1px solid #A7F3D0;" title="Lý do gợi ý">
              <i class="fas fa-sparkles me-1 text-warning"></i><?= h($reasonTag) ?>
            </span>
          </div>
        <?php endif; ?>

        <a class="name fw-semibold mb-1 text-decoration-none" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" style="color: #0F172A; font-size: 0.88rem; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.4em;">
          <?= h($p['ten_san_pham'] ?? '') ?>
        </a>

        <?php if ($rawRating > 0 && $rawReviewCount > 0): ?>
          <div class="d-flex align-items-center gap-1 mb-2" style="font-size: 0.76rem;">
            <span class="d-inline-flex align-items-center gap-1 text-warning font-weight-bold">
              <i class="fas fa-star" style="color: #F59E0B; font-size: 0.72rem;"></i> <?= number_format($rawRating, 1) ?>
            </span>
            <span class="text-muted small ms-1">(<?= $rawReviewCount ?>)</span>
          </div>
        <?php else: ?>
          <div class="d-flex align-items-center gap-1 mb-2 text-muted" style="font-size: 0.76rem; color: #94A3B8;">
            <i class="far fa-star text-muted" style="font-size: 0.72rem;"></i>
            <span>Chưa có đánh giá</span>
          </div>
        <?php endif; ?>

        <div class="price-wrap mb-3 mt-auto d-flex align-items-baseline gap-2">
          <div class="price fw-bold" style="color: #DC2626; font-size: 1.15rem; font-variant-numeric: tabular-nums;"><?= vnd($flashPrice) ?></div>
          <?php if ($regularPrice > $flashPrice): ?>
            <div class="price-market text-muted text-decoration-line-through" style="font-size: 0.82rem; font-variant-numeric: tabular-nums; color: #94A3B8 !important;"><?= vnd($regularPrice) ?></div>
          <?php endif; ?>
        </div>

        <div class="product-card-actions d-grid gap-2" style="grid-template-columns: 1fr 1fr;">
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="btn btn-sm w-100 text-nowrap d-inline-flex align-items-center justify-content-center" type="submit" style="background: #F1F5F9; color: #183B2B; border: 1px solid #CBD5E1; border-radius: 8px; font-weight: 600; font-size: 0.78rem; height: 38px; padding: 0 4px; transition: all 0.2s ease;" <?= $isOutOfStock ? 'disabled' : '' ?> title="Thêm vào giỏ">
              <?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1" style="font-size: 0.74rem;"></i> <span>Thêm vào giỏ</span>' ?>
            </button>
          </form>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="buy_now" value="1">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="btn btn-sm w-100 text-white text-nowrap d-inline-flex align-items-center justify-content-center" type="submit" style="background: #183B2B; border-radius: 8px; font-weight: 600; font-size: 0.78rem; height: 38px; padding: 0 4px; border: none; transition: background 0.2s ease;" <?= $isOutOfStock ? 'disabled' : '' ?> title="Mua ngay">
              <?= $isOutOfStock ? 'Hết hàng' : '<span>Mua ngay</span>' ?>
            </button>
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
  <section class="hero-skinsyntax mb-5" data-syna-hero data-syna-base-url="<?= h(BASE_URL) ?>" data-syna-speech="<?= h(json_encode($synaSpeechSentences, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" data-syna-survey-completed="<?= !empty($synaSurveyCompleted) ? '1' : '0' ?>" style="background: linear-gradient(120deg, #174C3A 0%, #215F47 58%, #174333 100%) !important; border-radius: 20px; color: #FFFFFF !important; padding: 40px 44px; position: relative; overflow: hidden; box-shadow: 0 12px 32px rgba(23, 76, 58, 0.18); min-height: 440px;">
    <div class="row align-items-center">
      <div class="col-lg-7 col-md-7">
        <div class="hero-skincare-badge mb-3" style="background: rgba(255,255,255,0.14) !important; border: 1px solid rgba(255,255,255,0.28) !important; color: #FFFFFF !important;">
          <span class="badge-dot" style="background: #84A98C !important;"></span>
          <span>Skincare cho phiên bản tốt hơn mỗi ngày</span>
        </div>
        
        <h1 class="hero-headline mb-3" style="color: #FFFFFF !important;">
          Skincare hiểu<br>làn da của bạn.
        </h1>
        
        <p class="hero-description mb-4" style="color: rgba(255,255,255,0.85) !important;">
          Khám phá mỹ phẩm phù hợp dựa trên hồ sơ da, vấn đề da và routine cá nhân của bạn.
        </p>

        <div class="d-flex flex-wrap align-items-center gap-3 mb-4 pb-2">
          <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn btn-hero-primary" style="background: #FFFFFF !important; color: #174C3A !important; font-weight: 700; font-size: 0.92rem; padding: 11px 24px; border-radius: 10px; border: none; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(0,0,0,0.12);">
            Khám phá sản phẩm
          </a>
          <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="btn btn-hero-secondary" style="background: rgba(255,255,255,0.15) !important; color: #FFFFFF !important; font-weight: 700; font-size: 0.92rem; padding: 11px 24px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.35) !important; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">
            Khám phá làn da của bạn
          </a>
          <a href="<?= BASE_URL ?>/index.php?r=khaosatnhanh" class="btn btn-hero-quick" style="background: #D8F3DC !important; color: #174C3A !important; font-weight: 800; font-size: 0.92rem; padding: 11px 20px; border-radius: 10px; border: 1px solid rgba(216,243,220,0.9) !important; text-decoration: none; display: inline-flex; align-items: center; justify-content: center;" aria-label="Khảo sát nhanh tình trạng da hôm nay">
            <span aria-hidden="true">⚡</span>&nbsp;Khảo sát nhanh
          </a>
        </div>

        <div class="hero-trust-items row row-cols-1 row-cols-sm-3 g-3 pt-3 border-top" style="border-top-color: rgba(255,255,255,0.2) !important;">
          <div class="col d-flex align-items-center gap-2.5">
            <div class="trust-icon-box" style="background: rgba(255,255,255,0.16) !important; color: #FFFFFF !important;">
              <i class="fas fa-shield-halved"></i>
            </div>
            <div>
              <div class="trust-title" style="color: #FFFFFF !important;">Sản phẩm chính hãng</div>
              <div class="trust-subtitle" style="color: rgba(255,255,255,0.72) !important;">An tâm mua sắm</div>
            </div>
          </div>

          <div class="col d-flex align-items-center gap-2.5">
            <div class="trust-icon-box" style="background: rgba(255,255,255,0.16) !important; color: #FFFFFF !important;">
              <i class="fas fa-truck-fast"></i>
            </div>
            <div>
              <div class="trust-title" style="color: #FFFFFF !important;">Giao hàng nhanh</div>
              <div class="trust-subtitle" style="color: rgba(255,255,255,0.72) !important;">Toàn quốc</div>
            </div>
          </div>

          <div class="col d-flex align-items-center gap-2.5">
            <div class="trust-icon-box" style="background: rgba(255,255,255,0.16) !important; color: #FFFFFF !important;">
              <i class="fas fa-headset"></i>
            </div>
            <div>
              <div class="trust-title" style="color: #FFFFFF !important;">Hỗ trợ tận tâm</div>
              <div class="trust-subtitle" style="color: rgba(255,255,255,0.72) !important;">Luôn đồng hành cùng bạn</div>
            </div>
          </div>
        </div>
      </div>

      <!-- SYNA INTERACTIVE MASCOT STAGE COMPONENT -->
      <div class="col-lg-5 col-md-5 mt-4 mt-md-0 text-center d-flex align-items-center justify-content-center">
        <div class="syna-mascot-stage" data-syna-mascot>
          <div class="syna-visual-bg"></div>

          <div class="syna-speech-bubble-wrap">
            <div class="syna-speech-bubble" role="status" aria-live="polite">
              <span class="syna-typewriter-text" id="synaTypewriterText"></span><span class="syna-typewriter-cursor"></span>
            </div>
            <div class="syna-stage-actions">
              <button type="button" class="syna-ask-button" data-syna-open-chat aria-label="Mở chatbot và hỏi SYNA">
                <i class="fas fa-comment-dots" aria-hidden="true"></i>
                <span>Hỏi SYNA</span>
              </button>
              <span class="syna-stage-hint">Tư vấn theo nhu cầu của bạn</span>
            </div>
          </div>

          <div class="syna-mascot-img-wrap">
            <button type="button" class="syna-mascot-button" id="synaMascotButton" aria-label="Tương tác với SYNA">
              <img src="<?= BASE_URL ?>/assets/images/syna_talking_2.png" alt="SYNA, trợ lý chăm sóc da của SkinSyntax" class="syna-mascot-img state-idle" id="synaMascotImg" width="270" height="275" decoding="async" fetchpriority="high">
            </button>
          </div>
        </div>
      </div>
    </div>
  </section>

  <script src="<?= BASE_URL ?>/assets/js/syna-mascot.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/js/syna-mascot.js') ?: '1' ?>" defer></script>

  <!-- 2. DYNAMIC CATALOG CATEGORIES -->
  <?php if (!empty($homeCategoryCards)): ?>
  <section class="mb-5 mt-4 home-catalog-section" aria-labelledby="home-catalog-title" data-home-card-rail>
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-1 mb-3">
      <div>
        <span class="home-section-kicker">MUA SẮM THEO DANH MỤC</span>
        <h2 id="home-catalog-title" class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;">
          Danh mục chăm sóc da
        </h2>
        <span class="text-muted small" style="font-size: 0.82rem;">Danh mục đang có sản phẩm để bạn khám phá.</span>
      </div>
      <div class="home-card-rail__tools">
        <a href="<?= BASE_URL ?>/index.php?r=tatca" class="text-decoration-none fw-semibold text-success small" style="font-size: 0.82rem;">Xem tất cả danh mục &rarr;</a>
        <div class="home-rail-actions" aria-label="Điều hướng danh mục">
          <button type="button" class="home-rail-button" data-home-card-prev aria-label="Danh mục trước"><i class="fas fa-chevron-left"></i></button>
          <button type="button" class="home-rail-button" data-home-card-next aria-label="Danh mục tiếp theo"><i class="fas fa-chevron-right"></i></button>
        </div>
      </div>
    </div>

    <div class="home-category-visual-grid home-card-rail" data-home-card-viewport tabindex="0" role="region" aria-label="Danh mục sản phẩm">
      <?php foreach ($homeCategoryCards as $item): ?>
        <?php
          $categoryName = trim((string)($item['name'] ?? ''));
          $categoryId = (int)($item['id'] ?? 0);
          $categoryProduct = is_array($item['product'] ?? null) ? $item['product'] : [];
          $categoryImage = function_exists('product_image_source') ? product_image_source($categoryProduct) : (string)($categoryProduct['link_hinh_anh'] ?? $categoryProduct['hinh_anh'] ?? '');
          $categoryFallbackImage = function_exists('homepage_visual_asset') ? homepage_visual_asset('category', $categoryName) : '';
          $categoryResolvedImage = $categoryImage !== '' ? resolve_image_url($categoryImage) : '';
          if ($categoryResolvedImage === '' || $categoryResolvedImage === default_placeholder_image()) {
              $categoryResolvedImage = $categoryFallbackImage ?: default_placeholder_image();
          }
          $categoryUrl = BASE_URL . '/index.php?r=tatca&cap1=' . urlencode($categoryName);
          if ($categoryId > 0) {
              $categoryUrl .= '&ma_danh_muc=' . $categoryId;
          }
        ?>
        <a href="<?= h($categoryUrl) ?>" class="home-category-visual-card home-card-rail__item">
          <span class="home-category-visual-card__image">
            <img loading="lazy" decoding="async" src="<?= h($categoryResolvedImage) ?>" alt="<?= h($categoryName) ?>" data-image-fallbacks="<?= function_exists('product_image_fallbacks_json') ? product_image_fallbacks_json($categoryProduct, $categoryResolvedImage) : '[]' ?>" data-image-placeholder="<?= h($categoryFallbackImage ?: default_placeholder_image()) ?>" onerror="window.SkinSyntaxImageFallback(this)">
          </span>
          <span class="home-category-visual-card__title"><?= h($categoryName) ?></span>
          <span class="home-category-visual-card__action">
            <?= (int)($item['count'] ?? 0) > 0 ? number_format((int)$item['count'], 0, ',', '.') . ' sản phẩm' : 'Khám phá' ?>
            <i class="fas fa-arrow-right"></i>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- 2.5. SKINSYNTAX VALUE STRIP -->
  <section class="home-value-strip mb-5" aria-label="Điểm khác biệt của SkinSyntax">
    <div class="home-value-strip__heading">
      <span class="home-section-kicker">VÌ SAO CHỌN SKINSYNTAX?</span>
      <h2>Hiểu da trước, chọn đúng hơn.</h2>
    </div>
    <div class="home-value-strip__items">
      <div class="home-value-item">
        <span class="home-value-item__icon"><i class="fas fa-sliders-h"></i></span>
        <span><strong>Chọn theo làn da</strong><small>Hồ sơ da và nhu cầu của bạn</small></span>
      </div>
      <div class="home-value-item">
        <span class="home-value-item__icon"><i class="fas fa-flask"></i></span>
        <span><strong>Đọc thành phần dễ hiểu</strong><small>Biết sản phẩm có gì trước khi mua</small></span>
      </div>
      <div class="home-value-item">
        <span class="home-value-item__icon"><i class="fas fa-list-check"></i></span>
        <span><strong>Routine có thứ tự</strong><small>Gợi ý theo từng bước chăm da</small></span>
      </div>
      <div class="home-value-item">
        <span class="home-value-item__icon"><i class="fas fa-headset"></i></span>
        <span><strong>Luôn có SYNA hỗ trợ</strong><small>Hỏi nhanh khi cần chọn sản phẩm</small></span>
      </div>
    </div>
  </section>

  <!-- 3. UNIVERSAL ADAPTIVE FOR-YOU SECTION (PHASE A) -->
  <?php if (!empty($forYouProducts)): ?>
    <?php
      $firstRecMeta = $forYouProducts[0]['recommender_meta'] ?? [];
      $dominantSignal = strtoupper((string)($firstRecMeta['dominant_signal'] ?? 'SIMPLE'));
      $algoMode = strtoupper((string)($firstRecMeta['algorithm_mode'] ?? 'SIMPLE'));

      // Grounded dynamic header mapping based on actual recommender evidence
      $isPartialProfile = ($algoMode === 'PARTIAL_PROFILE_FALLBACK')
          || ($algoMode === 'SIMPLE' && $dominantSignal === 'SIMPLE' && $isLoggedIn && !empty($userProfile) && !$hasSurvey);

      if ($isPartialProfile) {
          $sectionKicker = 'GỢI Ý NỔI BẬT';
          $sectionTitle = 'Gợi ý nổi bật cho bạn';
          $sectionSub = 'Khám phá những sản phẩm nổi bật tại SkinSyntax.';
      } elseif ($algoMode === 'ADAPTIVE_HYBRID' || $dominantSignal === 'HYBRID') {
          $sectionKicker = 'DÀNH RIÊNG CHO BẠN';
          $sectionTitle = 'Gợi ý dành riêng cho bạn';
          $sectionSub = 'Kết hợp thông tin làn da và những sản phẩm bạn đang quan tâm.';
      } elseif ($algoMode === 'PROFILE_CONTENT' || $dominantSignal === 'PROFILE') {
          $sectionKicker = 'HỒ SƠ LÀN DA';
          $sectionTitle = 'Phù hợp với thông tin làn da của bạn';
          $sectionSub = 'Được gợi ý dựa trên thông tin làn da bạn đã chia sẻ.';
      } elseif ($algoMode === 'PURCHASE_CONTENT' || $dominantSignal === 'PURCHASE') {
          $sectionKicker = 'LỊCH SỬ MUA SẮM';
          $sectionTitle = 'Dựa trên sản phẩm bạn từng mua';
          $sectionSub = 'Gợi ý dựa trên những sản phẩm bạn từng mua.';
      } elseif ($dominantSignal === 'CART') {
          $sectionKicker = 'DỰA TRÊN GIỎ HÀNG';
          $sectionTitle = 'Có thể bạn cũng quan tâm';
          $sectionSub = 'Gợi ý dựa trên những sản phẩm bạn đang quan tâm trong giỏ hàng.';
      } elseif ($dominantSignal === 'VIEW') {
          $sectionKicker = 'VỪA XEM GẦN ĐÂY';
          $sectionTitle = 'Dựa trên sản phẩm bạn vừa xem';
          $sectionSub = 'Khám phá những lựa chọn có đặc điểm tương tự.';
      } elseif ($dominantSignal === 'SEARCH') {
          $sectionKicker = 'TÌM KIẾM GẦN ĐÂY';
          $sectionTitle = 'Có thể bạn đang quan tâm';
          $sectionSub = 'Gợi ý dựa trên những gì bạn vừa tìm kiếm.';
      } else {
          // SIMPLE
          $sectionKicker = 'GỢI Ý NỔI BẬT';
          $sectionTitle = 'Sản phẩm nổi bật tại SkinSyntax';
          $sectionSub = 'Khám phá những lựa chọn được đánh giá cao tại SkinSyntax.';
      }

      // Các hàng phụ cũng dùng cùng tín hiệu recommender, tránh tiêu đề cố định
      // khi danh sách thực tế đã được làm mới theo mỗi request/F5.
      if ($hasSurvey && !empty($userProfile) && $algoMode === 'ADAPTIVE_HYBRID') {
          $todaySectionTitle = 'Gợi ý hôm nay dành riêng cho bạn';
          $todaySectionSub = 'Kết hợp hồ sơ làn da với những gì bạn đang quan tâm để làm mới lựa chọn mỗi lần ghé thăm.';
          $newSectionTitle = 'Mỹ Phẩm Vừa Lên Kệ Hợp Với Làn Da Bạn';
          $newSectionSub = 'Sản phẩm mới được lọc lại theo hồ sơ da và tín hiệu quan tâm gần đây của bạn.';
      } elseif ($hasSurvey && !empty($userProfile)) {
          $todaySectionTitle = 'Gợi ý hôm nay cho làn da của bạn';
          $todaySectionSub = 'Dựa trên thông tin làn da bạn đã chia sẻ, danh sách sẽ được làm mới sau mỗi lần tải lại.';
          $newSectionTitle = 'Mỹ Phẩm Vừa Lên Kệ Hợp Với Bạn';
          $newSectionSub = 'Khám phá các sản phẩm mới nhưng vẫn ưu tiên độ phù hợp với hồ sơ da của bạn.';
      } elseif ($dominantSignal === 'SEARCH') {
          $todaySectionTitle = 'Có thể bạn đang quan tâm hôm nay';
          $todaySectionSub = 'Gợi ý dựa trên những gì bạn vừa tìm kiếm và được làm mới sau mỗi lần tải lại.';
          $newSectionTitle = 'Mỹ Phẩm Vừa Lên Kệ Theo Mối Quan Tâm Của Bạn';
          $newSectionSub = 'Các sản phẩm mới liên quan đến nhu cầu bạn vừa tìm kiếm.';
      } elseif (in_array($dominantSignal, ['VIEW', 'CART', 'PURCHASE'], true)) {
          $todaySectionTitle = 'Gợi ý hôm nay từ những gì bạn quan tâm';
          $todaySectionSub = 'Kết hợp lượt xem, giỏ hàng hoặc lịch sử mua sắm để mỗi lần F5 có thêm lựa chọn mới.';
          $newSectionTitle = 'Mỹ Phẩm Vừa Lên Kệ Có Thể Bạn Sẽ Thích';
          $newSectionSub = 'Khám phá sản phẩm mới dựa trên hành vi mua sắm gần đây của bạn.';
      } elseif ($isLoggedIn) {
          $todaySectionTitle = 'Gợi ý hôm nay dành cho bạn';
          $todaySectionSub = 'Bạn có thể hoàn thành hồ sơ da để hệ thống cá nhân hóa sâu hơn; danh sách hiện vẫn được làm mới mỗi lần ghé thăm.';
          $newSectionTitle = 'Mỹ Phẩm Vừa Lên Kệ Cho Bạn';
          $newSectionSub = 'Một cửa sổ sản phẩm mới được chọn lại mỗi lần tải trang, không đóng khung trong một danh sách cố định.';
      }
    ?>
    <section class="mb-5 p-4 bg-white border home-product-section" data-home-rail style="border-radius: 16px; border-color: #E2E8F0 !important;">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3 pb-3 border-bottom">
        <div>
          <div class="d-inline-flex align-items-center gap-1.5 mb-1" style="color: #183B2B; font-weight: 700; font-size: 0.76rem; letter-spacing: 0.05em; text-transform: uppercase;">
            <i class="fas fa-sparkles text-warning"></i> <?= htmlspecialchars($sectionKicker) ?>
          </div>
          <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;"><?= htmlspecialchars($sectionTitle) ?></h2>
          <span class="text-muted small" style="font-size: 0.82rem;"><?= htmlspecialchars($sectionSub) ?></span>
        </div>

        <div class="d-flex align-items-center gap-2">
          <?php if ($hasSurvey && !empty($userProfile)): ?>
            <span class="personalized-header-badge">
              <i class="fas fa-user-check"></i>
              Hồ sơ: <?= h(!empty($userProfile['skin_type']) ? $userProfile['skin_type'] : 'Đã phân tích') ?> 
              <?= !empty($userProfile['concerns']) ? '• ' . h(implode(', ', array_slice($userProfile['concerns'], 0, 2))) : '' ?>
            </span>
          <?php else: ?>
            <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="btn btn-sm btn-outline-success fw-semibold" style="border-radius: 6px; font-size: 0.8rem;">
              <i class="fas fa-clipboard-check me-1"></i> Hoàn thành khảo sát da để nhận gợi ý cá nhân hóa hơn &rarr;
            </a>
          <?php endif; ?>
          <div class="home-rail-actions" aria-label="Điều hướng sản phẩm">
            <button type="button" class="home-rail-button" data-home-rail-prev aria-label="Sản phẩm trước"><i class="fas fa-chevron-left"></i></button>
            <button type="button" class="home-rail-button" data-home-rail-next aria-label="Sản phẩm tiếp theo"><i class="fas fa-chevron-right"></i></button>
          </div>
        </div>
      </div>

      <div class="home-product-rail" data-home-product-rail tabindex="0" role="region" aria-label="Gợi ý dành cho bạn">
        <?php foreach ($forYouProducts as $idx => $p): ?>
          <div class="home-product-rail__item">
            <?php 
              $meta = $p['recommender_meta'] ?? [];
              $pMode = strtoupper((string)($meta['algorithm_mode'] ?? ''));
              $pSig = strtoupper((string)($meta['dominant_signal'] ?? ''));
              if ($pMode === 'SIMPLE' && $pSig === 'SIMPLE') {
                  $pBadge = 'Nổi bật';
              } else {
                  $reasonTags = (array)($meta['reason_tags'] ?? []);
                  if (in_array('Khớp loại da', $reasonTags, true)) {
                      $pBadge = 'Khớp loại da';
                  } elseif (in_array('Dựa trên tìm kiếm gần đây', $reasonTags, true)) {
                      $pBadge = 'Tìm kiếm gần đây';
                  } elseif (in_array('Tương tự sản phẩm vừa xem', $reasonTags, true)) {
                      $pBadge = 'Tương tự vừa xem';
                  } elseif (in_array('Phù hợp với giỏ hàng', $reasonTags, true)) {
                      $pBadge = 'Dựa trên giỏ hàng';
                  } elseif (in_array('Tương thích lịch sử mua sắm', $reasonTags, true)) {
                      $pBadge = 'Lịch sử mua sắm';
                  } elseif (!empty($reasonTags[0])) {
                      $pBadge = $reasonTags[0];
                  } else {
                      $pBadge = 'Gợi ý cho bạn';
                  }
              }
              $renderHomeProductCard($p, $pBadge); 
            ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (!$hasSurvey): ?>
        <div class="mt-3 pt-2 text-center text-md-start border-top d-flex flex-column flex-md-row align-items-center justify-content-between gap-2" style="border-color: #F1F5F9 !important;">
          <span class="text-muted small" style="font-size: 0.8rem;">
            <i class="fas fa-info-circle text-success me-1"></i> Gợi ý tự động thích ứng theo tìm kiếm, sản phẩm vừa xem và giỏ hàng của bạn.
          </span>
          <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="text-decoration-none fw-semibold text-success small" style="font-size: 0.8rem;">
            Hoàn thành khảo sát da &rarr;
          </a>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php include __DIR__ . '/partials/home_graph_seen.php'; ?>

  <!-- 4. FLASH SALE (Personalized Carousel UX Extension: All valid campaign products) -->
  <?php if (!empty($flashSaleCampaign) && !empty($flashSaleProducts)): ?>
    <section class="mb-5 p-4 text-white position-relative" style="background: #183B2B; border-radius: 16px; border: 1px solid #2D6A4F;" data-flash-sale-countdown data-flash-sale-carousel data-end-timestamp="<?= (int)($flashSaleCampaign['end_at_ts'] ?? 0) ?>">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
          <div class="d-flex align-items-center gap-2 mb-1">
            <span class="text-uppercase fw-semibold small text-white-50" style="letter-spacing: 0.05em; font-size: 0.72rem;">ƯU ĐẤI CHỚP NHOÁNG</span>
            <?php if (!empty($flashSaleMeta['is_personalized'])): ?>
              <span class="badge" style="background: rgba(254, 240, 138, 0.2); color: #FEF08A; border: 1px solid rgba(254, 240, 138, 0.4); font-size: 0.68rem; font-weight: 600;">
                <i class="fas fa-sparkles me-1"></i>Dành cho bạn
              </span>
            <?php endif; ?>
          </div>
          <h2 class="fw-bold m-0 text-white" style="font-size: 1.5rem;"><i class="fas fa-bolt text-warning me-2"></i> <?= h($flashSaleCampaign['ten_campaign']) ?></h2>
          <?php if (!empty($flashSaleMeta['is_personalized'])): ?>
            <p class="text-white-50 small mb-0 mt-1" style="font-size: 0.8rem; max-width: 520px;">
              <i class="fas fa-sliders me-1 text-warning"></i>Gợi ý được sắp xếp theo nhu cầu và hoạt động gần đây của bạn.
            </p>
          <?php elseif (!empty($flashSaleCampaign['mo_ta'])): ?>
            <p class="text-white-50 small mb-0 mt-1" style="font-size: 0.82rem; max-width: 520px;"><?= h($flashSaleCampaign['mo_ta']) ?></p>
          <?php endif; ?>
        </div>

        <!-- Countdown Timer -->
        <div class="d-flex align-items-center gap-2" aria-label="Thời gian còn lại">
          <span class="text-white-50 fw-semibold small text-uppercase me-1" style="font-size: 0.72rem; letter-spacing: 0.04em;">Kết thúc sau</span>
          <div class="text-center px-2.5 py-1.5" data-flash-day-box style="background: rgba(255,255,255,0.14); border-radius: 6px; min-width: 44px; display: none;">
            <strong class="d-block text-white fs-5 fw-bold tabular-nums" data-flash-days>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.6rem;">Ngày</small>
          </div>
          <span class="text-white fw-bold fs-5" data-flash-day-separator style="display: none;">:</span>
          <div class="text-center px-2.5 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 6px; min-width: 44px;">
            <strong class="d-block text-white fs-5 fw-bold tabular-nums" data-flash-hours>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.6rem;">Giờ</small>
          </div>
          <span class="text-white fw-bold fs-5">:</span>
          <div class="text-center px-2.5 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 6px; min-width: 44px;">
            <strong class="d-block text-white fs-5 fw-bold tabular-nums" data-flash-minutes>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.6rem;">Phút</small>
          </div>
          <span class="text-white fw-bold fs-5">:</span>
          <div class="text-center px-2.5 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 6px; min-width: 44px;">
            <strong class="d-block text-white fs-5 fw-bold tabular-nums" data-flash-seconds>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.6rem;">Giây</small>
          </div>
          <span class="badge bg-danger ms-2 py-1.5 px-2" data-flash-status style="display: none; font-size: 0.75rem;">Đã kết thúc</span>
        </div>

        <!-- Carousel Navigation & View All Link -->
        <div class="d-flex align-items-center gap-2">
          <!-- Page indicator & total count -->
          <div class="d-flex align-items-center gap-1 text-white-50 small me-1" style="font-size: 0.82rem; font-variant-numeric: tabular-nums;">
            <span>Trang</span> <strong class="text-white" id="flashCarouselCurrentPage">1</strong>/<span class="text-white" id="flashCarouselTotalPages">1</span>
            <span class="text-white-50 ms-1">(<span id="flashCarouselTotalCount"><?= count($flashSaleProducts) ?></span> SP)</span>
          </div>

          <!-- Carousel Prev / Next Controls -->
          <div class="btn-group" role="group" aria-label="Điều hướng danh sách Flash Sale">
            <button type="button" class="btn btn-sm btn-outline-light flash-carousel-nav-btn" id="btnFlashPrev" aria-label="Trang trước" title="Trang trước" disabled style="border-radius: 8px 0 0 8px; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-color: rgba(255,255,255,0.35);">
              <i class="fas fa-chevron-left"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-light flash-carousel-nav-btn" id="btnFlashNext" aria-label="Trang sau" title="Trang sau" style="border-radius: 0 8px 8px 0; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-color: rgba(255,255,255,0.35);">
              <i class="fas fa-chevron-right"></i>
            </button>
          </div>

          <a href="<?= BASE_URL ?>/index.php?r=flash_sale" class="btn btn-light px-3.5 py-2 fw-semibold text-nowrap ms-1" style="color: #183B2B; border-radius: 8px; font-size: 0.84rem; background: #FFF; border: none;">Xem tất cả deal &rarr;</a>
        </div>
      </div>

      <!-- Carousel Viewport & Responsive Grid -->
      <div class="flash-sale-carousel-viewport position-relative" id="flashSaleCarouselViewport" tabindex="0" role="region" aria-label="Băng chuyền sản phẩm Flash Sale" style="outline: none;">
        <div class="flash-sale-grid" id="flashSaleGrid">
          <?php foreach ($flashSaleProducts as $idx => $p): ?>
            <div class="flash-sale-item <?= $idx >= 8 ? 'd-none' : '' ?>" data-flash-index="<?= $idx ?>">
              <?php $renderFlashSaleProductCard($p); ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php include __DIR__ . '/partials/home_graph_skin.php'; ?>

  <!-- 4.5. BEST-SELLER RAIL -->
  <?php if (!empty($bestSellerProducts)): ?>
    <section class="mb-5 home-product-section" data-home-rail>
      <div class="d-flex justify-content-between align-items-end mb-3">
        <div>
          <span class="home-section-kicker">TOP BÁN CHẠY · 30 NGÀY</span>
          <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;">Được mua nhiều nhất gần đây</h2>
          <span class="text-muted small" style="font-size: 0.82rem;">Tổng hợp từ đơn hàng đã hoàn thành trong 30 ngày gần nhất.</span>
        </div>
        <div class="home-rail-actions" aria-label="Điều hướng sản phẩm bán chạy">
          <button type="button" class="home-rail-button" data-home-rail-prev aria-label="Sản phẩm trước"><i class="fas fa-chevron-left"></i></button>
          <button type="button" class="home-rail-button" data-home-rail-next aria-label="Sản phẩm tiếp theo"><i class="fas fa-chevron-right"></i></button>
        </div>
      </div>
      <div class="home-product-rail" data-home-product-rail tabindex="0" role="region" aria-label="Sản phẩm bán chạy">
        <?php foreach ($bestSellerProducts as $p): ?>
          <div class="home-product-rail__item">
            <?php $renderHomeProductCard($p, 'Bán chạy 30 ngày'); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php elseif ($dbUnavailableMessage === ''): ?>
    <section class="mb-5 home-product-section home-empty-state" aria-label="Dữ liệu bán hàng gần đây">
      <span class="home-section-kicker">DỮ LIỆU BÁN HÀNG GẦN ĐÂY</span>
      <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.25rem;">Chưa đủ dữ liệu để xếp hạng</h2>
      <p class="text-muted small mb-0 mt-1">SkinSyntax chỉ hiển thị bảng bán chạy khi có đơn hoàn thành trong 30 ngày gần nhất.</p>
    </section>
  <?php endif; ?>

  <!-- 4.6. POPULAR SEARCHES -->
  <?php if (!empty($topSearches) && !$topSearchesAreFallback): ?>
    <section class="home-search-trends mb-5" aria-label="Từ khóa tìm kiếm phổ biến">
      <div>
        <span class="home-section-kicker"><?= $topSearchesAreFallback ? 'KHÁM PHÁ NHANH' : 'TOP TÌM KIẾM' ?></span>
        <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.2rem;"><?= $topSearchesAreFallback ? 'Chọn nhu cầu của bạn' : 'Mọi người đang tìm' ?></h2>
      </div>
      <div class="home-search-trends__list">
        <?php foreach ($topSearches as $searchItem): ?>
          <?php $searchTerm = trim((string)($searchItem['q'] ?? '')); ?>
          <?php if ($searchTerm === '') continue; ?>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=<?= urlencode($searchTerm) ?>" class="home-search-trend">
            <i class="fas fa-magnifying-glass"></i><?= h($searchTerm) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- 5. SYNA AI LIVESTREAM SECTION (GỘP THÀNH 1 SECTION DUY NHẤT) -->
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
            <h4 class="fw-bold text-white mb-1" style="font-size: 1.25rem;">Livestream tư vấn cùng SYNA</h4>
            <p class="text-white-80 small mb-3" style="max-width: 360px; font-size: 0.84rem; color: rgba(255,255,255,0.85);">
              Tư vấn routine, giải đáp sản phẩm và khám phá ưu đãi trực tiếp trong phiên live.
            </p>
            <a href="<?= BASE_URL ?>/index.php?r=live" class="btn text-white px-4 py-2 fw-bold" style="border-radius: 999px; font-size: 0.85rem; background: #2D6A4F; border: 1px solid #84A98C;">
              <i class="fas fa-video me-1.5"></i> Vào phòng livestream
            </a>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="p-2">
          <div class="d-inline-flex align-items-center gap-1.5 mb-2 text-warning fw-bold small text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
            <i class="fas fa-broadcast-tower"></i> Phiên Live Trực Tiếp
          </div>
          <h3 class="fw-bold text-white mb-2" style="font-size: 1.5rem;">Livestream tư vấn cùng SYNA</h3>
          <p class="text-white-80 small mb-3" style="font-size: 0.88rem; line-height: 1.6; color: rgba(255,255,255,0.88);">
            Tư vấn quy trình chăm sóc da cá nhân hóa, giải đáp thắc mắc thành phần mỹ phẩm và nhận voucher ưu đãi thực tế theo nhu cầu da của bạn.
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
                <img src="<?= h(resolve_image_url(function_exists('product_image_source') ? product_image_source($featLiveProd) : (string)($featLiveProd['link_hinh_anh'] ?? $featLiveProd['hinh_anh'] ?? ''))) ?>" alt="<?= h($featLiveProd['ten_san_pham'] ?? 'Live Product') ?>" style="width: 58px; height: 58px; object-fit: cover; border-radius: 8px; background: #FFF;">
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

  <!-- 5.5 REFRESH-AWARE TODAY RECOMMENDATIONS -->
  <?php if (!empty($todayProducts)): ?>
    <section class="mb-5 home-product-section" data-home-rail>
      <div class="d-flex justify-content-between align-items-end mb-3">
        <div>
          <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.72rem;"><?= h($todaySectionKicker) ?></span>
          <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;"><?= h($todaySectionTitle) ?></h2>
          <span class="text-muted small" style="font-size: 0.82rem;"><?= h($todaySectionSub) ?></span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <div class="home-rail-actions" aria-label="Điều hướng gợi ý hôm nay">
            <button type="button" class="home-rail-button" data-home-rail-prev aria-label="Sản phẩm trước"><i class="fas fa-chevron-left"></i></button>
            <button type="button" class="home-rail-button" data-home-rail-next aria-label="Sản phẩm tiếp theo"><i class="fas fa-chevron-right"></i></button>
          </div>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&sort=diem_danh_gia" class="fw-semibold text-decoration-none" style="color: #183B2B; font-size: 0.85rem;">Khám phá thêm <i class="fas fa-arrow-right ms-1"></i></a>
        </div>
      </div>

      <div class="home-product-rail" data-home-product-rail tabindex="0" role="region" aria-label="Gợi ý hôm nay">
        <?php foreach ($todayProducts as $p): ?>
          <div class="home-product-rail__item">
            <?php $renderHomeProductCard($p, 'Gợi ý hôm nay'); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- 5.6 SIMPLE RECOMMENDER (ĐƯỢC YÊU THÍCH NHẤT - IMDB WEIGHTED RATING) -->
  <?php if (!empty($topRatedWeightedProducts)): ?>
    <section class="mb-5 home-product-section" data-home-rail>
      <div class="d-flex justify-content-between align-items-end mb-3">
        <div>
          <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.72rem;">BẢNG XẾP HẠNG ĐÁNH GIÁ</span>
          <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;">Được Yêu Thích Nhất</h2>
          <span class="text-muted small" style="font-size: 0.82rem;">Những sản phẩm được cộng đồng đánh giá cao</span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <div class="home-rail-actions" aria-label="Điều hướng bảng xếp hạng">
            <button type="button" class="home-rail-button" data-home-rail-prev aria-label="Sản phẩm trước"><i class="fas fa-chevron-left"></i></button>
            <button type="button" class="home-rail-button" data-home-rail-next aria-label="Sản phẩm tiếp theo"><i class="fas fa-chevron-right"></i></button>
          </div>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&sort=diem_danh_gia" class="fw-semibold text-decoration-none" style="color: #183B2B; font-size: 0.85rem;">Xem tất cả <i class="fas fa-arrow-right ms-1"></i></a>
        </div>
      </div>

      <div class="home-product-rail" data-home-product-rail tabindex="0" role="region" aria-label="Sản phẩm được yêu thích nhất">
        <?php foreach ($topRatedWeightedProducts as $p): ?>
          <div class="home-product-rail__item">
            <?php $renderHomeProductCard($p, 'Yêu thích nhất'); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>


  <?php include __DIR__ . '/partials/home_graph_concern.php'; ?>

  <!-- 6. NEW PRODUCTS (MỸ PHẨM VỪA LÊN KỆ - BEAUTY COSMETICS ONLY) -->
  <?php if (!empty($newProducts)): ?>
    <section class="mb-5 home-product-section" data-home-rail>
      <div class="d-flex justify-content-between align-items-end mb-3">
        <div>
          <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.72rem;">SẢN PHẨM MỚI</span>
          <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;"><?= h($newSectionTitle) ?></h2>
          <span class="text-muted small" style="font-size: 0.82rem;"><?= h($newSectionSub) ?></span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <div class="home-rail-actions" aria-label="Điều hướng sản phẩm mới">
            <button type="button" class="home-rail-button" data-home-rail-prev aria-label="Sản phẩm trước"><i class="fas fa-chevron-left"></i></button>
            <button type="button" class="home-rail-button" data-home-rail-next aria-label="Sản phẩm tiếp theo"><i class="fas fa-chevron-right"></i></button>
          </div>
          <a href="<?= BASE_URL ?>/index.php?r=tatca" class="fw-semibold text-decoration-none" style="color: #183B2B; font-size: 0.85rem;">Xem tất cả <i class="fas fa-arrow-right ms-1"></i></a>
        </div>
      </div>

      <div class="home-product-rail" data-home-product-rail tabindex="0" role="region" aria-label="Sản phẩm mới">
        <?php foreach ($newProducts as $p): ?>
          <div class="home-product-rail__item">
            <?php $renderHomeProductCard($p, 'Mới lên kệ'); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- 6.5. DISCOVERY RAIL (FOR-YOU BROWSING) -->
  <?php if (!empty($homeDiscoveryProducts)): ?>
    <section class="mb-5 home-discovery-section home-product-section" data-home-rail>
      <div class="d-flex justify-content-between align-items-end mb-3">
        <div>
          <span class="home-section-kicker">KHÁM PHÁ CHO BẠN</span>
          <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;">Lướt thêm, gặp đúng món</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
          <a href="<?= BASE_URL ?>/index.php?r=beauty_swipe" class="fw-semibold text-decoration-none" style="color: #183B2B; font-size: 0.82rem;">Lướt dạng thẻ <i class="fas fa-arrow-right ms-1"></i></a>
          <div class="home-rail-actions" aria-label="Điều hướng khám phá">
          <button type="button" class="home-rail-button" data-home-rail-prev aria-label="Sản phẩm trước"><i class="fas fa-chevron-left"></i></button>
          <button type="button" class="home-rail-button" data-home-rail-next aria-label="Sản phẩm tiếp theo"><i class="fas fa-chevron-right"></i></button>
          </div>
        </div>
      </div>
      <div class="home-product-rail" data-home-product-rail tabindex="0" role="region" aria-label="Khám phá sản phẩm cho bạn">
        <?php foreach ($homeDiscoveryProducts as $p): ?>
          <div class="home-product-rail__item">
            <?php $renderHomeProductCard($p, 'Khám phá cho bạn'); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- 7. PERSONAL ROUTINE (CONDITIONAL BASED ON SURVEY PROFILE) -->
  <section class="mb-5 p-4 bg-white border" style="border-radius: 16px; border-color: #E2E8F0 !important;">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
      <div>
        <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.72rem;">QUY TRÌNH CHĂM SÓC DA HẰNG NGÀY</span>
        <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.4rem;">Routine của bạn</h2>
      </div>
      <?php if ($hasSurvey): ?>
        <a href="<?= BASE_URL ?>/index.php?r=goiy" class="btn btn-sm text-white fw-semibold px-3 py-1.5" style="background: #183B2B; border-radius: 6px; font-size: 0.8rem;">
          Xem routine đầy đủ &rarr;
        </a>
      <?php endif; ?>
    </div>

    <?php if ($hasSurvey): ?>
      <div class="row g-3">
        <div class="col-6 col-md-3">
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=l%C3%A0m+s%E1%BA%A1ch" class="routine-step-pill text-decoration-none">
            <div class="routine-step-num">01</div>
            <div>
              <div class="text-uppercase text-muted fw-bold" style="font-size: 0.65rem;">BƯỚC 1</div>
              <div class="fw-bold text-dark" style="font-size: 0.9rem;">Làm sạch</div>
            </div>
          </a>
        </div>

        <div class="col-6 col-md-3">
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=serum" class="routine-step-pill text-decoration-none">
            <div class="routine-step-num">02</div>
            <div>
              <div class="text-uppercase text-muted fw-bold" style="font-size: 0.65rem;">BƯỚC 2</div>
              <div class="fw-bold text-dark" style="font-size: 0.9rem;">Đặc trị</div>
            </div>
          </a>
        </div>

        <div class="col-6 col-md-3">
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=d%C6%B0%E1%BB%A1ng+%E1%BA%A9m" class="routine-step-pill text-decoration-none">
            <div class="routine-step-num">03</div>
            <div>
              <div class="text-uppercase text-muted fw-bold" style="font-size: 0.65rem;">BƯỚC 3</div>
              <div class="fw-bold text-dark" style="font-size: 0.9rem;">Dưỡng ẩm</div>
            </div>
          </a>
        </div>

        <div class="col-6 col-md-3">
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=ch%E1%BB%91ng+n%E1%BA%AFng" class="routine-step-pill text-decoration-none">
            <div class="routine-step-num">04</div>
            <div>
              <div class="text-uppercase text-muted fw-bold" style="font-size: 0.65rem;">BƯỚC 4</div>
              <div class="fw-bold text-dark" style="font-size: 0.9rem;">Bảo vệ</div>
            </div>
          </a>
        </div>
      </div>
    <?php else: ?>
      <div class="p-4 text-center rounded-3" style="background: #F8FAF8; border: 1px dashed #C8DACF;">
        <h3 class="fw-bold mb-1" style="font-size: 1.05rem; color: #0F172A;">Khám phá routine dành riêng cho bạn</h3>
        <p class="text-muted small mx-auto mb-3" style="max-width: 460px; font-size: 0.84rem;">
          Hoàn thành khảo sát da để SkinSyntax giúp bạn thiết lập quy trình chăm sóc da 4 bước phù hợp.
        </p>
        <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="btn btn-sm text-white fw-bold px-4 py-2" style="background: #183B2B; border-radius: 6px; font-size: 0.84rem;">
          <i class="fas fa-clipboard-list me-1.5"></i> Hoàn thành khảo sát da &rarr;
        </a>
      </div>
    <?php endif; ?>
  </section>

  <!-- 8. DYNAMIC BRANDS -->
  <?php if (!empty($homeBrandCards)): ?>
    <section class="home-brand-visual-section p-4 mb-5 bg-white border" aria-label="Thương hiệu nổi bật" data-home-card-rail>
      <div class="d-flex justify-content-between align-items-end gap-3 mb-3">
        <div>
          <span class="home-section-kicker">THƯƠNG HIỆU CÓ SẢN PHẨM ĐANG BÁN</span>
          <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;">Thương hiệu nổi bật</h2>
        </div>
        <div class="home-card-rail__tools">
          <a href="<?= BASE_URL ?>/index.php?r=tatca" class="text-decoration-none fw-semibold text-success small">Xem tất cả &rarr;</a>
          <div class="home-rail-actions" aria-label="Điều hướng thương hiệu">
            <button type="button" class="home-rail-button" data-home-card-prev aria-label="Thương hiệu trước"><i class="fas fa-chevron-left"></i></button>
            <button type="button" class="home-rail-button" data-home-card-next aria-label="Thương hiệu tiếp theo"><i class="fas fa-chevron-right"></i></button>
          </div>
        </div>
      </div>
      <div class="home-brand-visual-grid home-card-rail" data-home-card-viewport tabindex="0" role="region" aria-label="Thương hiệu sản phẩm">
        <?php foreach ($homeBrandCards as $brandCard): ?>
          <?php
            $brandName = trim((string)($brandCard['name'] ?? ''));
            $brandProduct = is_array($brandCard['product'] ?? null) ? $brandCard['product'] : [];
            $brandImage = function_exists('product_image_source') ? product_image_source($brandProduct) : (string)($brandProduct['link_hinh_anh'] ?? $brandProduct['hinh_anh'] ?? '');
            $brandFallbackImage = function_exists('homepage_visual_asset') ? homepage_visual_asset('brand', $brandName) : '';
            $brandResolvedImage = $brandImage !== '' ? resolve_image_url($brandImage) : '';
            if ($brandResolvedImage === '' || $brandResolvedImage === default_placeholder_image()) {
                $brandResolvedImage = $brandFallbackImage ?: default_placeholder_image();
            }
          ?>
          <a href="<?= BASE_URL ?>/index.php?r=product_collection&type=best_seller&thuong_hieu=<?= urlencode($brandName) ?>" class="home-brand-visual-card home-card-rail__item">
            <span class="home-brand-visual-card__image"><img loading="lazy" decoding="async" src="<?= h($brandResolvedImage) ?>" alt="<?= h($brandName) ?>" data-image-fallbacks="<?= function_exists('product_image_fallbacks_json') ? product_image_fallbacks_json($brandProduct, $brandResolvedImage) : '[]' ?>" data-image-placeholder="<?= h($brandFallbackImage ?: default_placeholder_image()) ?>" onerror="window.SkinSyntaxImageFallback(this)"></span>
            <span class="home-brand-visual-card__copy"><strong><?= h($brandName) ?></strong><small><?= (int)($brandCard['count'] ?? 0) ?> sản phẩm</small></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- 9. CONCISE FINAL CTA -->
  <section class="mb-4 p-4 text-center rounded-4 text-white" style="background: linear-gradient(135deg, #183B2B 0%, #2D6A4F 100%);">
    <h3 class="fw-bold mb-1 text-white" style="font-size: 1.3rem;">Hiểu da hơn, chọn mỹ phẩm dễ hơn.</h3>
    <p class="text-white-80 small mx-auto mb-3" style="max-width: 480px; font-size: 0.86rem; color: rgba(255,255,255,0.85);">
      SkinSyntax — Nền tảng mỹ phẩm và tư vấn da dễ hiểu hơn.
    </p>
    <div class="d-flex flex-wrap justify-content-center gap-3">
      <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn btn-light fw-bold px-4 py-2" style="border-radius: 8px; font-size: 0.86rem; color: #183B2B;">
        <i class="fas fa-search me-1.5"></i> Khám phá sản phẩm
      </a>
      <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="btn text-white fw-bold px-4 py-2" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); border-radius: 8px; font-size: 0.86rem;">
        <i class="fas fa-clipboard-list me-1.5"></i> Khám phá làn da của bạn
      </a>
    </div>
  </section>
</div>
</div>

<script>
// Real Flash Sale Server-Driven Countdown Timer Script (Phase 2C)
document.addEventListener('DOMContentLoaded', function () {
  var root = document.querySelector('[data-flash-sale-countdown]');
  if (!root) return;

  var endTs = parseInt(root.getAttribute('data-end-timestamp') || '0', 10);
  if (!endTs) return;

  var dayEl = root.querySelector('[data-flash-days]');
  var dayBox = root.querySelector('[data-flash-day-box]');
  var daySep = root.querySelector('[data-flash-day-separator]');
  var hourEl = root.querySelector('[data-flash-hours]');
  var minuteEl = root.querySelector('[data-flash-minutes]');
  var secondEl = root.querySelector('[data-flash-seconds]');
  var statusEl = root.querySelector('[data-flash-status]');

  var pad = function (val) { return String(Math.max(0, val)).padStart(2, '0'); };
  var hasReloaded = false;

  var tick = function () {
    var nowSec = Math.floor(Date.now() / 1000);
    var remaining = Math.max(0, endTs - nowSec);

    if (remaining <= 0) {
      if (dayEl) dayEl.textContent = '00';
      if (hourEl) hourEl.textContent = '00';
      if (minuteEl) minuteEl.textContent = '00';
      if (secondEl) secondEl.textContent = '00';
      if (statusEl) statusEl.style.display = 'inline-block';

      if (!hasReloaded) {
        hasReloaded = true;
        setTimeout(function () {
          var reloadKey = 'ss_flash_ended_' + endTs;
          if (!sessionStorage.getItem(reloadKey)) {
            sessionStorage.setItem(reloadKey, '1');
            window.location.reload();
          }
        }, 2000);
      }
      return;
    }

    var days = Math.floor(remaining / 86400);
    var hours = Math.floor((remaining % 86400) / 3600);
    var minutes = Math.floor((remaining % 3600) / 60);
    var seconds = remaining % 60;

    if (dayEl) dayEl.textContent = pad(days);
    if (dayBox && daySep) {
      if (days > 0) {
        dayBox.style.display = 'inline-block';
        daySep.style.display = 'inline-block';
      } else {
        dayBox.style.display = 'none';
        daySep.style.display = 'none';
      }
    }
    if (hourEl) hourEl.textContent = pad(hours);
    if (minuteEl) minuteEl.textContent = pad(minutes);
    if (secondEl) secondEl.textContent = pad(seconds);
  };

  tick();
  var intervalId = setInterval(function () {
    var nowSec = Math.floor(Date.now() / 1000);
    if (endTs - nowSec <= 0) {
      clearInterval(intervalId);
      tick();
    } else {
      tick();
    }
  }, 1000);
});

// Flash Sale Personalized Carousel UX Extension Navigation
document.addEventListener('DOMContentLoaded', function () {
  var root = document.querySelector('[data-flash-sale-carousel]');
  if (!root) return;

  var items = Array.prototype.slice.call(root.querySelectorAll('.flash-sale-item'));
  var totalItems = items.length;
  if (totalItems === 0) return;

  var btnPrev = document.getElementById('btnFlashPrev');
  var btnNext = document.getElementById('btnFlashNext');
  var curPageEl = document.getElementById('flashCarouselCurrentPage');
  var totalPagesEl = document.getElementById('flashCarouselTotalPages');
  var totalCountEl = document.getElementById('flashCarouselTotalCount');
  var viewport = document.getElementById('flashSaleCarouselViewport');

  if (totalCountEl) totalCountEl.textContent = totalItems;

  var currentPage = 0;

  var getPageSize = function () {
    var w = window.innerWidth;
    if (w >= 1200) return 8; // Desktop >= 1200px: 4 cols x 2 rows
    if (w >= 768) return 4;  // Tablet 768-1199px: 2 cols x 2 rows
    return 2;                // Mobile < 768px: 2 cols x 1 row
  };

  var getTotalPages = function (pageSize) {
    return Math.max(1, Math.ceil(totalItems / pageSize));
  };

  var renderCarousel = function (targetPage, animate) {
    var pageSize = getPageSize();
    var totalPages = getTotalPages(pageSize);

    currentPage = Math.max(0, Math.min(targetPage, totalPages - 1));

    var startIndex = currentPage * pageSize;
    var endIndex = Math.min(startIndex + pageSize, totalItems);

    for (var i = 0; i < totalItems; i++) {
      var item = items[i];
      if (i >= startIndex && i < endIndex) {
        item.classList.remove('d-none');
        if (animate) {
          item.classList.remove('flash-card-entering');
          void item.offsetWidth;
          item.classList.add('flash-card-entering');
        }
      } else {
        item.classList.add('d-none');
        item.classList.remove('flash-card-entering');
      }
    }

    if (curPageEl) curPageEl.textContent = (currentPage + 1);
    if (totalPagesEl) totalPagesEl.textContent = totalPages;

    if (btnPrev) {
      btnPrev.disabled = (currentPage <= 0);
      btnPrev.setAttribute('aria-disabled', currentPage <= 0 ? 'true' : 'false');
    }
    if (btnNext) {
      btnNext.disabled = (currentPage >= totalPages - 1);
      btnNext.setAttribute('aria-disabled', currentPage >= totalPages - 1 ? 'true' : 'false');
    }
  };

  // Initial render
  renderCarousel(0, false);

  if (btnPrev) {
    btnPrev.addEventListener('click', function (e) {
      e.preventDefault();
      if (currentPage > 0) {
        renderCarousel(currentPage - 1, true);
      }
    });
  }

  if (btnNext) {
    btnNext.addEventListener('click', function (e) {
      e.preventDefault();
      var pageSize = getPageSize();
      var totalPages = getTotalPages(pageSize);
      if (currentPage < totalPages - 1) {
        renderCarousel(currentPage + 1, true);
      }
    });
  }

  // Keyboard navigation on carousel viewport
  if (viewport) {
    viewport.addEventListener('keydown', function (e) {
      var pageSize = getPageSize();
      var totalPages = getTotalPages(pageSize);
      if (e.key === 'ArrowLeft') {
        if (currentPage > 0) {
          e.preventDefault();
          renderCarousel(currentPage - 1, true);
        }
      } else if (e.key === 'ArrowRight') {
        if (currentPage < totalPages - 1) {
          e.preventDefault();
          renderCarousel(currentPage + 1, true);
        }
      }
    });
  }

  // Mobile swipe support
  if (viewport) {
    var touchStartX = 0;
    var touchStartY = 0;
    viewport.addEventListener('touchstart', function (e) {
      if (e.touches.length === 1) {
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
      }
    }, { passive: true });

    viewport.addEventListener('touchend', function (e) {
      if (e.changedTouches.length === 1) {
        var deltaX = e.changedTouches[0].clientX - touchStartX;
        var deltaY = e.changedTouches[0].clientY - touchStartY;
        if (Math.abs(deltaX) > 40 && Math.abs(deltaX) > Math.abs(deltaY) * 1.5) {
          var pageSize = getPageSize();
          var totalPages = getTotalPages(pageSize);
          if (deltaX < 0 && currentPage < totalPages - 1) {
            renderCarousel(currentPage + 1, true);
          } else if (deltaX > 0 && currentPage > 0) {
            renderCarousel(currentPage - 1, true);
          }
        }
      }
    }, { passive: true });
  }

  // Window resize handler: recalculate page count and clamp active page
  var lastWidth = window.innerWidth;
  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      var newWidth = window.innerWidth;
      if (newWidth !== lastWidth) {
        lastWidth = newWidth;
        renderCarousel(currentPage, false);
      }
    }, 100);
  });
});
</script>

<style>
/* Flash Sale Personalized Carousel Styles */
.flash-sale-carousel-viewport {
  position: relative;
  width: 100%;
  outline: none;
}
.flash-sale-carousel-viewport:focus-visible {
  outline: 2px solid rgba(254, 240, 138, 0.7);
  outline-offset: 4px;
  border-radius: 12px;
}
.flash-sale-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
  width: 100%;
}
.flash-sale-item {
  width: 100%;
  display: block;
}
.flash-sale-item.d-none {
  display: none !important;
}
.flash-sale-item.flash-card-entering {
  animation: flashCardFadeIn 0.22s ease-out forwards;
}
@keyframes flashCardFadeIn {
  from {
    opacity: 0;
    transform: translateY(6px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
.flash-carousel-nav-btn {
  background: rgba(255, 255, 255, 0.12);
  color: #FFFFFF;
  border: 1px solid rgba(255, 255, 255, 0.35);
  transition: all 0.2s ease;
}
.flash-carousel-nav-btn:hover:not(:disabled) {
  background: #FFFFFF;
  color: #183B2B;
  border-color: #FFFFFF;
}
.flash-carousel-nav-btn:disabled {
  opacity: 0.35;
  cursor: not-allowed;
  pointer-events: none;
  border-color: rgba(255, 255, 255, 0.15);
}
@media (max-width: 1199.98px) {
  .flash-sale-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
  }
}
@media (max-width: 767.98px) {
  .flash-sale-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }
}
</style>

<script src="<?= h(BASE_URL . '/assets/js/home-graph.js?v=' . (int)@filemtime(dirname(__DIR__) . '/public/assets/js/home-graph.js')) ?>" defer></script>
<script src="<?= h(BASE_URL . '/assets/js/homepage-discovery.js?v=' . (int)@filemtime(dirname(__DIR__) . '/public/assets/js/homepage-discovery.js')) ?>" defer></script>

<?php require __DIR__ . '/components/welcome_popup.php'; ?>
