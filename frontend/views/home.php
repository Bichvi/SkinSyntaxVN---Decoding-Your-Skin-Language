<?php
// frontend/views/home.php - SkinSyntax Redesigned Homepage
$latest = isset($latest) && is_array($latest) ? $latest : [];
$cats = isset($cats) && is_array($cats) ? $cats : [];
$homepageSections = isset($homepageSections) && is_array($homepageSections) ? $homepageSections : [];
$dbUnavailableMessage = trim((string)($dbUnavailableMessage ?? ''));

$userProfile = is_array($userProfile ?? null) ? $userProfile : [];
$isLoggedIn = (bool)($isLoggedIn ?? false);
$hasSurvey = (bool)($hasSurvey ?? false);

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

$topRatedWeightedProducts = array_slice(array_values(array_filter($homepageSections['topRatedWeighted'] ?? [])), 0, 4);

$contentBasedProducts = array_slice(array_values(array_filter($homepageSections['contentBased'] ?? [])), 0, 4);

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
        'icon' => 'fa-magic',
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
$renderHomeProductCard = static function (array $p, string $tag = '', string $whyFitText = ''): void {
    $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
    $img = resolve_image_url((string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
    $giaBan = (string)($p['gia_ban'] ?? '');
    $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
    $phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
    $rawRating = isset($p['diem_danh_gia']) && is_numeric($p['diem_danh_gia']) ? (float)$p['diem_danh_gia'] : 0.0;
    $rawReviewCount = isset($p['so_luong_danh_gia']) && is_numeric($p['so_luong_danh_gia']) ? (int)$p['so_luong_danh_gia'] : 0;
    $hasRealReview = ($rawRating > 0 && $rawReviewCount > 0);
    $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($p) : false;

    // Grounded recommendation badge from recommender_meta
    $meta = $p['recommender_meta'] ?? null;
    $algoMode = strtoupper((string)($meta['algorithm_mode'] ?? ''));
    $dominantSignal = strtoupper((string)($meta['dominant_signal'] ?? ''));
    $isPersonalized = !empty($meta) && $algoMode !== 'SIMPLE' && $dominantSignal !== 'SIMPLE';
    $reasonTags = (array)($meta['reason_tags'] ?? []);
    ?>
    <div class="product-card product-card--editorial h-100 d-flex flex-column">
      <div class="product-thumb position-relative p-2" style="background: #F8FAF8; border-radius: 12px 12px 0 0;">
        <?php if ($phanTramGiam !== null && $phanTramGiam > 0): ?>
          <span class="badge-sale position-absolute" style="top: 10px; left: 10px; background: #183B2B; color: #FFFFFF; font-weight: 700; font-size: 0.72rem; padding: 3px 8px; border-radius: 4px; z-index: 3;">
            -<?= h((string)$phanTramGiam) ?>%
          </span>
        <?php endif; ?>

        <?php if ($tag !== ''): ?>
          <span class="market-card__tag position-absolute" style="top: 10px; right: 10px; background: #F1F5F9; color: #475569; font-size: 0.7rem; font-weight: 600; padding: 3px 8px; border-radius: 4px; z-index: 3; border: 1px solid #E2E8F0;">
            <?= h($tag) ?>
          </span>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" class="d-block w-100 overflow-hidden" style="border-radius: 8px; aspect-ratio: 1/1;">
          <img class="product-card-img" src="<?= h($img ?: default_placeholder_image()) ?>" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';" alt="<?= h(!empty($p['ten_san_pham']) ? $p['ten_san_pham'] : 'Sản phẩm SkinSyntax') ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
        </a>
      </div>

      <div class="product-meta p-3 d-flex flex-column flex-grow-1">
        <div class="mb-1 d-flex align-items-center justify-content-between">
          <span class="brand text-uppercase" style="font-size: 0.68rem; color: #183B2B; background: #EBF2EE; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.04em; font-weight: 700;">
            <?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?>
          </span>

          <span class="skin-match-status" style="font-size: 0.72rem; color: var(--muted);">
            <?php if ($isPersonalized && in_array('Khớp loại da', $reasonTags, true)): ?>
              <span class="text-success fw-semibold"><i class="bi bi-check2-circle"></i> Khớp loại da</span>
            <?php elseif ($isPersonalized && in_array('Phù hợp mọi loại da', $reasonTags, true)): ?>
              <span class="text-success fw-semibold"><i class="bi bi-check2-circle"></i> Mọi loại da</span>
            <?php elseif ($isPersonalized && in_array('Trong ngân sách', $reasonTags, true)): ?>
              <span class="text-success fw-semibold"><i class="bi bi-tag"></i> Trong ngân sách</span>
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

        <?php if ($hasRealReview): ?>
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
          <div class="price fw-bold" style="color: #183B2B; font-size: 1.05rem; font-variant-numeric: tabular-nums;"><?= vnd($giaBan) ?></div>
          <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)$giaBan): ?>
            <div class="price-market text-muted text-decoration-line-through" style="font-size: 0.8rem; font-variant-numeric: tabular-nums; color: #94A3B8 !important;"><?= vnd($giaThiTruong) ?></div>
          <?php endif; ?>
        </div>

        <div class="product-card-actions d-flex flex-column flex-sm-row gap-1 gap-sm-2">
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 flex-fill w-100">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="btn btn-sm w-100" type="submit" style="background: #F1F5F9; color: #0F172A; border: 1px solid #E2E8F0; border-radius: 6px; font-weight: 600; font-size: 0.78rem; padding: 7px 0; transition: all 0.2s ease;" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1"></i> <span class="d-none d-sm-inline">Thêm</span><span class="d-sm-none">Thêm vào giỏ</span>' ?></button>
          </form>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 flex-fill d-none d-sm-block">
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
  <section class="hero-skinsyntax mb-5" style="background: linear-gradient(120deg, #174C3A 0%, #215F47 58%, #174333 100%) !important; border-radius: 20px; color: #FFFFFF !important; padding: 40px 44px; position: relative; overflow: hidden; box-shadow: 0 12px 32px rgba(23, 76, 58, 0.18); min-height: 440px;">
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

      <!-- SYNA INTERACTIVE MASCOT STAGE COMPONENT (STATE-BASED) -->
      <div class="col-lg-5 col-md-5 mt-4 mt-md-0 text-center d-flex align-items-center justify-content-center">
        <div class="syna-mascot-stage">
          <div class="syna-visual-bg"></div>

          <div class="syna-speech-bubble-wrap">
            <div class="syna-speech-bubble">
              <span class="syna-typewriter-text" id="synaTypewriterText"></span><span class="syna-typewriter-cursor"></span>
            </div>
          </div>

          <div class="syna-mascot-img-wrap">
            <img src="<?= BASE_URL ?>/assets/images/syna_listening.png" alt="SYNA Mascot" class="syna-mascot-img state-idle" id="synaMascotImg">
          </div>
        </div>
      </div>
    </div>
  </section>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var textElem = document.getElementById('synaTypewriterText');
      var mascotImg = document.getElementById('synaMascotImg');
      if (!textElem || !mascotImg) return;

      var baseUrl = '<?= BASE_URL ?>/assets/images/';

      // 1. BUILD SYNA STATES MAPPING (10 NEW CLEAN MODEL POSES)
      var synaStates = {
        idle: baseUrl + 'syna_listening.png',
        greeting: baseUrl + 'syna_greeting.png',
        talking1: baseUrl + 'syna_talking_1.png',
        talking2: baseUrl + 'syna_talking_2.png',
        thinking: baseUrl + 'syna_thinking.png',
        listening: baseUrl + 'syna_listening.png',
        recommend: baseUrl + 'syna_recommend.png',
        processing: baseUrl + 'syna_processing.png',
        active: baseUrl + 'syna_active.png',
        happy: baseUrl + 'syna_happy.png',
        bye: baseUrl + 'syna_bye.png'
      };

      // 2. PRELOAD ALL POSE ASSETS (prevents white flash on pose switch)
      Object.values(synaStates).forEach(function (src) {
        var img = new Image();
        img.src = src;
      });

      // 3. SET INITIAL IDLE IMAGE & BIND ONERROR FALLBACK
      mascotImg.src = synaStates.idle;
      mascotImg.onerror = function () {
        if (!this.dataset.fallbackApplied) {
          this.dataset.fallbackApplied = '1';
          this.src = synaStates.idle;
        }
      };

      var currentState = 'idle';
      var talkingInterval = null;
      var talkingToggle = false;

      // 4. CENTRALIZED STATE MANAGER
      var setSynaState = function (stateName) {
        if (!synaStates[stateName] && stateName !== 'talking') return;
        currentState = stateName;

        // Reset talking loop if switching to non-talking state
        if (stateName !== 'talking' && talkingInterval) {
          clearInterval(talkingInterval);
          talkingInterval = null;
        }

        var targetSrc = synaStates[stateName];

        if (stateName === 'talking') {
          if (!talkingInterval) {
            talkingToggle = false;
            targetSrc = synaStates.talking1;
            talkingInterval = setInterval(function () {
              talkingToggle = !talkingToggle;
              var talkSrc = talkingToggle ? synaStates.talking2 : synaStates.talking1;
              mascotImg.src = talkSrc;
            }, 380);
          } else {
            return;
          }
        }

        if (targetSrc) {
          mascotImg.src = targetSrc;
        }

        // Apply matching CSS motion class
        mascotImg.className = 'syna-mascot-img state-' + (stateName === 'talking' ? 'talking' : stateName);
      };

      window.setSynaState = setSynaState;

      // 5. TYPEWRITER SEQUENCE & STATE SYNC
      var sentences = <?= json_encode($synaSpeechSentences, JSON_UNESCAPED_UNICODE) ?>;
      var currentSentenceIdx = 0;
      var currentCharIdx = 0;
      var timerId = null;

      var sentenceRestStates = ['greeting', 'thinking', 'listening', 'recommend'];

      var typeSentence = function () {
        if (currentSentenceIdx >= sentences.length) {
          currentSentenceIdx = 0;
          setSynaState('idle');
          timerId = setTimeout(typeSentence, 5000);
          return;
        }

        var fullText = sentences[currentSentenceIdx];
        var targetRestState = sentenceRestStates[currentSentenceIdx] || 'idle';

        if (currentCharIdx === 0) {
          // Sentence start: set initial rest pose, then start talking animation
          setSynaState(targetRestState);
          setTimeout(function () {
            setSynaState('talking');
          }, 120);
        }

        if (currentCharIdx < fullText.length) {
          textElem.textContent = fullText.substring(0, currentCharIdx + 1);
          currentCharIdx++;
          timerId = setTimeout(typeSentence, 45);
        } else {
          // Sentence finished typing: return to sentence rest pose
          currentCharIdx = 0;
          currentSentenceIdx++;
          setSynaState(targetRestState);
          timerId = setTimeout(typeSentence, 2500);
        }
      };

      // START TYPEWRITER SEQUENCE AFTER IMAGE INITIALIZATION
      typeSentence();

      window.addEventListener('beforeunload', function () {
        if (timerId) clearTimeout(timerId);
        if (talkingInterval) clearInterval(talkingInterval);
      });
    });
  </script>

  <!-- 2. SKIN CONCERN SECTION (DUY NHẤT 1 SECTION ON HOME PAGE) -->
  <section class="mb-5 mt-4">
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-1 mb-3">
      <div>
        <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.35rem;">
          <i class="fas fa-sliders-h text-success me-2"></i> Bạn đang quan tâm điều gì cho làn da?
        </h2>
        <span class="text-muted small" style="font-size: 0.82rem;">Chọn nhu cầu để xem sản phẩm phù hợp</span>
      </div>
      <a href="<?= BASE_URL ?>/index.php?r=tatca" class="text-decoration-none fw-semibold text-success small" style="font-size: 0.82rem;">Xem tất cả nhu cầu &rarr;</a>
    </div>

    <div class="skin-concern-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)); gap: 14px; width: 100%;">
      <?php foreach ($skinConcernCategories as $item): ?>
        <a href="<?= BASE_URL ?>/index.php?r=tatca&q=<?= urlencode($item['query']) ?>" class="skin-concern-card">
          <div class="concern-icon-wrapper">
            <i class="fas <?= h($item['icon']) ?>"></i>
          </div>
          <div class="concern-card-title"><?= h($item['title']) ?></div>
          <div class="concern-card-action">Xem sản phẩm &rarr;</div>
        </a>
      <?php endforeach; ?>
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
    ?>
    <section class="mb-5 p-4 bg-white border" style="border-radius: 16px; border-color: #E2E8F0 !important;">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3 pb-3 border-bottom">
        <div>
          <div class="d-inline-flex align-items-center gap-1.5 mb-1" style="color: #183B2B; font-weight: 700; font-size: 0.76rem; letter-spacing: 0.05em; text-transform: uppercase;">
            <i class="fas fa-sparkles text-warning"></i> <?= htmlspecialchars($sectionKicker) ?>
          </div>
          <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;"><?= htmlspecialchars($sectionTitle) ?></h2>
          <span class="text-muted small" style="font-size: 0.82rem;"><?= htmlspecialchars($sectionSub) ?></span>
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
              <i class="fas fa-clipboard-check me-1"></i> Hoàn thành khảo sát da để nhận gợi ý cá nhân hóa hơn &rarr;
            </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="row g-3">
        <?php foreach ($forYouProducts as $idx => $p): ?>
          <div class="col-6 col-md-3">
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

  <!-- 5.5 SIMPLE RECOMMENDER (ĐƯỢC YÊU THÍCH NHẤT - IMDB WEIGHTED RATING) -->
  <?php if (!empty($topRatedWeightedProducts)): ?>
    <section class="mb-5">
      <div class="d-flex justify-content-between align-items-end mb-3">
        <div>
          <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.72rem;">BẢNG XẾP HẠNG ĐÁNH GIÁ</span>
          <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;">Được Yêu Thích Nhất</h2>
          <span class="text-muted small" style="font-size: 0.82rem;">Những sản phẩm được cộng đồng đánh giá cao</span>
        </div>
        <a href="<?= BASE_URL ?>/index.php?r=tatca&sort=diem_danh_gia" class="fw-semibold text-decoration-none" style="color: #183B2B; font-size: 0.85rem;">Xem tất cả <i class="fas fa-arrow-right ms-1"></i></a>
      </div>

      <div class="row g-3">
        <?php foreach ($topRatedWeightedProducts as $p): ?>
          <div class="col-6 col-md-3">
            <?php $renderHomeProductCard($p, 'Yêu thích nhất'); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>


  <!-- 6. NEW PRODUCTS (MỸ PHẨM VỪA LÊN KỆ - BEAUTY COSMETICS ONLY) -->
  <?php if (!empty($newProducts)): ?>
    <section class="mb-5">
      <div class="d-flex justify-content-between align-items-end mb-3">
        <div>
          <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.72rem;">SẢN PHẨM MỚI</span>
          <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.45rem;">Mỹ Phẩm Vừa Lên Kệ</h2>
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

  <!-- 8. TRUSTED BRANDS (THƯƠNG HIỆU NỔI BẬT DƯỢC YÊU THÍCH) -->
  <?php if (!empty($brandNames)): ?>
    <section class="p-4 mb-5 bg-white border text-center" style="border-radius: 12px; border-color: #E2E8F0 !important;">
      <span class="text-uppercase fw-semibold small text-muted mb-2.5 d-block" style="letter-spacing: 0.05em; font-size: 0.72rem;">THƯƠNG HIỆU NỔI BẬT DƯỢC YÊU THÍCH</span>
      <div class="d-flex flex-wrap justify-content-center gap-2">
        <?php foreach ($brandNames as $brand): ?>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=<?= urlencode($brand) ?>" class="btn btn-sm btn-light px-3 py-1.5 fw-semibold" style="background: #F8FAF8; color: #0F172A; border: 1px solid #E2E8F0; border-radius: 6px; font-size: 0.8rem;"><?= h($brand) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- 9. CONCISE FINAL CTA -->
  <section class="mb-4 p-4 text-center rounded-4 text-white" style="background: linear-gradient(135deg, #183B2B 0%, #2D6A4F 100%);">
    <h3 class="fw-bold mb-1 text-white" style="font-size: 1.3rem;">Hiểu da hơn, chọn mỹ phẩm dễ hơn.</h3>
    <p class="text-white-80 small mx-auto mb-3" style="max-width: 480px; font-size: 0.86rem; color: rgba(255,255,255,0.85);">
      SkinSyntax — Nền tảng mỹ phẩm &amp; tư vấn da cá nhân hóa AI.
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
