<?php
// Thẻ sản phẩm trang chủ (chuyển nguyên văn từ home.php); require trả về closure.
return static function (array $p, string $tag = '', string $whyFitText = ''): void {
    $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
    $img = resolve_image_url(function_exists('product_image_source') ? product_image_source($p) : (string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
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
          <img class="product-card-img" loading="lazy" decoding="async" src="<?= h($img ?: default_placeholder_image()) ?>" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';" alt="<?= h(!empty($p['ten_san_pham']) ? $p['ten_san_pham'] : 'Sản phẩm SkinSyntax') ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
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
