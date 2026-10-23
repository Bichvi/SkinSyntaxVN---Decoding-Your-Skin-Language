<?php
// frontend/views/partials/similar_products.php
// U08: khối "Sản phẩm tương tự" (carousel ngang) ở trang chi tiết. Được SanPhamController::similarProducts() render
// thành HTML rồi trang chi tiết nhúng bằng fetch (không làm chậm TTFB).
// Biến vào: $similarItems (array SP + reason_code/reason_text), $similarProductId (string), $similarDegraded (bool),
//           $similarTitle (string, tùy chọn). Mọi chuỗi đều được escape ở đây.
// Tracking dùng lại search-fallback.js: .ssf[data-ssf] > .ssf-section[data-ssf-section-key] > .ssf-card[data-ssf-section].
if (!isset($similarItems) || !is_array($similarItems) || count($similarItems) < 1) {
  return;
}
$ssimE = static function ($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$ssimPid = (string)($similarProductId ?? '');
$ssimTitle = trim((string)($similarTitle ?? '')) !== '' ? (string)$similarTitle : 'Sản phẩm tương tự';
$ssimCsrf = class_exists('SearchEventTracker') ? SearchEventTracker::csrfToken() : '';
?>
<div class="ssf ssf-similar" data-ssf data-ssf-degraded="<?= !empty($similarDegraded) ? '1' : '0' ?>"
  data-ssf-q="<?= $ssimE('similar:' . $ssimPid) ?>" data-ssf-csrf="<?= $ssimE($ssimCsrf) ?>"
  data-ssf-endpoint="<?= $ssimE(BASE_URL . '/index.php?r=search_event') ?>">
  <section class="ssf-section ssim-section" aria-labelledby="ssim-heading" data-ssf-section-key="similar">
    <div class="ssim-head">
      <h4 class="ssf-section-title ssim-title" id="ssim-heading"><?= $ssimE($ssimTitle) ?></h4>
      <div class="ssim-nav" aria-hidden="true">
        <button type="button" class="ssim-arrow" data-ssim-prev tabindex="-1" aria-label="Cuộn sang trái"><i class="fa-solid fa-chevron-left"></i></button>
        <button type="button" class="ssim-arrow" data-ssim-next tabindex="-1" aria-label="Cuộn sang phải"><i class="fa-solid fa-chevron-right"></i></button>
      </div>
    </div>
    <div class="ssim-track" data-ssim-track role="list" tabindex="0" aria-label="<?= $ssimE($ssimTitle) ?>">
      <?php foreach ($similarItems as $p):
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
        <div class="product-card ssf-card ssim-card" role="listitem" data-ssf-section="similar" data-ssf-reason="<?= $ssimE($reasonCode) ?>" data-product-id="<?= $ssimE($productId) ?>">
          <div class="ssf-thumb">
            <?php if ($reasonText !== ''): ?>
              <span class="ssf-reason" title="<?= $ssimE($reasonText) ?>" aria-label="Lý do gợi ý: <?= $ssimE($reasonText) ?>"><?= $ssimE($reasonText) ?></span>
            <?php endif; ?>
            <a href="<?= $ssimE($detailUrl) ?>" class="ssf-thumb-link">
              <img src="<?= $ssimE($img ?: default_placeholder_image()) ?>" loading="lazy" decoding="async" width="300" height="300" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='<?= $ssimE(default_placeholder_image()) ?>';" alt="<?= $ssimE($p['ten_san_pham'] ?? '') ?>">
            </a>
          </div>
          <div class="ssf-meta">
            <div class="ssf-brand"><?= $ssimE($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
            <a class="ssf-name" href="<?= $ssimE($detailUrl) ?>"><?= $ssimE($p['ten_san_pham'] ?? '') ?></a>
            <div class="ssf-price-wrap">
              <span class="ssf-price"><?= vnd($giaBan) ?></span>
              <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)$giaBan): ?>
                <span class="ssf-price-market"><?= vnd($giaThiTruong) ?></span>
              <?php endif; ?>
            </div>
            <div class="product-card-actions ssf-actions">
              <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
                <input type="hidden" name="action" value="add_to_cart">
                <input type="hidden" name="product_id" value="<?= $ssimE($productId) ?>">
                <input type="hidden" name="ma_san_pham" value="<?= $ssimE($productId) ?>">
                <input type="hidden" name="quantity" value="1">
                <input type="hidden" name="qty" value="1">
                <button class="btn btn-sm w-100 ssf-btn-cart" type="submit" <?= $isOutOfStock ? 'disabled' : '' ?> title="Thêm vào giỏ">
                  <?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1" aria-hidden="true"></i><span>Thêm vào giỏ</span>' ?>
                </button>
              </form>
              <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
                <input type="hidden" name="action" value="add_to_cart">
                <input type="hidden" name="buy_now" value="1">
                <input type="hidden" name="product_id" value="<?= $ssimE($productId) ?>">
                <input type="hidden" name="ma_san_pham" value="<?= $ssimE($productId) ?>">
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
</div>
