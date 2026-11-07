<?php
// frontend/views/partials/search_fallback.php
// Smart fallback (Bước 07). Biến vào: $searchFallback (array|null), $q (string), $ssfHasExact (bool).
// Mọi chuỗi từ $searchFallback đều được escape ở đây (formatter phía PHP không escape).
if (!isset($searchFallback) || !is_array($searchFallback)) {
  return;
}
$ssfQ = trim((string)($q ?? ''));
$ssfHasExact = !empty($ssfHasExact);
$ssfChips = is_array($searchFallback['chips'] ?? null) ? $searchFallback['chips'] : [];
$ssfSections = is_array($searchFallback['sections'] ?? null) ? $searchFallback['sections'] : [];
$ssfE = static function ($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$ssfUrl = static function (string $url): string {
  $url = trim($url);
  if ($url === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url)) {
    return BASE_URL . '/index.php?r=tatca';
  }
  return BASE_URL . '/' . ltrim($url, '/');
};
$ssfPrompt = 'Mình đang tìm ' . $ssfQ . ', gợi ý giúp mình sản phẩm thay thế phù hợp';
// Bước 08: beacon sự kiện (search-fallback.js) -> r=search_event; token CSRF không trạng thái theo phiên.
$ssfCsrf = class_exists('SearchEventTracker') ? SearchEventTracker::csrfToken() : '';
?>
<div class="ssf" data-ssf data-ssf-degraded="<?= !empty($searchFallback['degraded']) ? '1' : '0' ?>"
  data-ssf-q="<?= $ssfE($ssfQ) ?>" data-ssf-csrf="<?= $ssfE($ssfCsrf) ?>"
  data-ssf-endpoint="<?= $ssfE(BASE_URL . '/index.php?r=search_event') ?>">
  <?php if ($ssfHasExact): ?>
    <h3 class="ssf-more-title">Gợi ý thêm cho bạn</h3>
  <?php else: ?>
    <div class="ssf-banner" role="status">
      <i class="fas fa-magnifying-glass ssf-banner-icon" aria-hidden="true"></i>
      <div>
        <h3 class="ssf-banner-title">Chưa có sản phẩm khớp chính xác với «<?= $ssfE($ssfQ) ?>»</h3>
        <p class="ssf-banner-desc">Đây là những lựa chọn gần nhất SkinSyntax gợi ý cho bạn.</p>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($ssfChips)): ?>
    <nav class="ssf-chips" aria-label="Lọc nhanh">
      <?php foreach ($ssfChips as $chip):
        if (!is_array($chip) || trim((string)($chip['label'] ?? '')) === '') continue; ?>
        <a class="ssf-chip" href="<?= $ssfE($ssfUrl((string)($chip['url'] ?? ''))) ?>" data-ssf-chip="<?= $ssfE($chip['label']) ?>" data-ssf-chip-kind="<?= $ssfE($chip['kind'] ?? '') ?>"><?= $ssfE($chip['label']) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <?php foreach ($ssfSections as $sIdx => $section):
    $ssfItems = is_array($section['items'] ?? null) ? $section['items'] : [];
    if (empty($ssfItems)) continue;
    $sKey = preg_replace('/[^a-z0-9_]/i', '', (string)($section['key'] ?? 'section')) ?: 'section';
    $headingId = 'ssf-h-' . $sKey . '-' . (int)$sIdx;
  ?>
    <section class="ssf-section" aria-labelledby="<?= $ssfE($headingId) ?>" data-ssf-section-key="<?= $ssfE($sKey) ?>">
      <h4 class="ssf-section-title" id="<?= $ssfE($headingId) ?>"><?= $ssfE($section['title'] ?? '') ?></h4>
      <div class="ssf-grid">
        <?php foreach ($ssfItems as $p):
          if (!is_array($p)) continue;
          $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
          if ($productId === '') continue;
          $img = resolve_image_url((string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
          $giaBan = (string)($p['gia_ban'] ?? '');
          $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
          $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($p) : false;
          $reasonText = trim((string)($p['reason_text'] ?? ''));
          $reasonCode = (string)($p['reason_code'] ?? '');
          $detailUrl = BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($productId);
        ?>
          <div class="product-card ssf-card" data-ssf-section="<?= $ssfE($sKey) ?>" data-ssf-reason="<?= $ssfE($reasonCode) ?>" data-product-id="<?= $ssfE($productId) ?>">
            <div class="ssf-thumb">
              <?php if ($reasonText !== ''): ?>
                <span class="ssf-reason" aria-label="Lý do gợi ý: <?= $ssfE($reasonText) ?>"><?= $ssfE($reasonText) ?></span>
              <?php endif; ?>
              <a href="<?= $ssfE($detailUrl) ?>" class="ssf-thumb-link">
                <img src="<?= $ssfE($img ?: default_placeholder_image()) ?>" loading="lazy" decoding="async" width="300" height="300" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='<?= $ssfE(default_placeholder_image()) ?>';" alt="<?= $ssfE($p['ten_san_pham'] ?? '') ?>">
              </a>
            </div>
            <div class="ssf-meta">
              <div class="ssf-brand"><?= $ssfE($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
              <a class="ssf-name" href="<?= $ssfE($detailUrl) ?>"><?= $ssfE($p['ten_san_pham'] ?? '') ?></a>
              <div class="ssf-price-wrap">
                <span class="ssf-price"><?= vnd($giaBan) ?></span>
                <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)$giaBan): ?>
                  <span class="ssf-price-market"><?= vnd($giaThiTruong) ?></span>
                <?php endif; ?>
              </div>
              <div class="product-card-actions ssf-actions">
                <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="product_id" value="<?= $ssfE($productId) ?>">
                  <input type="hidden" name="ma_san_pham" value="<?= $ssfE($productId) ?>">
                  <input type="hidden" name="quantity" value="1">
                  <input type="hidden" name="qty" value="1">
                  <button class="btn btn-sm w-100 ssf-btn-cart" type="submit" <?= $isOutOfStock ? 'disabled' : '' ?> title="Thêm vào giỏ">
                    <?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1" aria-hidden="true"></i><span>Thêm vào giỏ</span>' ?>
                  </button>
                </form>
                <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="buy_now" value="1">
                  <input type="hidden" name="product_id" value="<?= $ssfE($productId) ?>">
                  <input type="hidden" name="ma_san_pham" value="<?= $ssfE($productId) ?>">
                  <input type="hidden" name="quantity" value="1">
                  <input type="hidden" name="qty" value="1">
                  <button class="btn btn-sm w-100 ssf-btn-buy" type="submit" <?= $isOutOfStock ? 'disabled' : '' ?> title="Mua ngay">
                    <?= $isOutOfStock ? 'Hết hàng' : '<span>Mua ngay</span>' ?>
                  </button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <?php if (!$ssfHasExact): ?>
    <div class="ssf-cta">
      <div>
        <h4 class="ssf-cta-title">Chưa thấy sản phẩm ưng ý?</h4>
        <p class="ssf-cta-desc">Syna có thể hỏi thêm về làn da của bạn để tìm sản phẩm thay thế.</p>
      </div>
      <button type="button" class="ssf-cta-btn" data-ssf-ask-syna data-ssf-prompt="<?= $ssfE($ssfPrompt) ?>">
        <i class="fas fa-comments me-1" aria-hidden="true"></i> Hỏi Syna để tìm sản phẩm thay thế
      </button>
    </div>
    <div class="ssf-all-link-wrap">
      <a class="ssf-all-link" href="<?= BASE_URL ?>/index.php?r=tatca">Xem tất cả sản phẩm</a>
    </div>
  <?php endif; ?>
</div>
