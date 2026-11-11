<?php
// Khối mua kèm/routine dùng chung cho PDP và endpoint đổi gợi ý.
$routineItems = is_array($frequentlyBought ?? null) ? $frequentlyBought : [];
if ($routineItems === []) {
    return;
}

$routineTitle = trim((string)($sectionTitle ?? '')) !== '' ? (string)$sectionTitle : 'Gợi ý kết hợp cho routine';
$routineBadge = trim((string)($sectionBadge ?? '')) !== '' ? (string)$sectionBadge : 'Gợi ý chu trình';
$routineHasRealRule = !empty($hasRealRule);
$routineOffset = max(0, (int)($frequentlyBoughtOffset ?? 0));
$routineNextOffset = max(0, (int)($frequentlyBoughtNextOffset ?? ($routineOffset + count($routineItems))));
$routineRefreshUrl = (string)($frequentlyBoughtRefreshUrl ?? (BASE_URL . '/index.php?r=routine_recommendations'));
?>
<div class="frequently-bought-card mt-4 mb-2 p-4 rounded-4 bg-white border w-100"
     data-routine-recommendation-block
     data-routine-refresh-url="<?= h($routineRefreshUrl) ?>"
     data-routine-offset="<?= (int)$routineNextOffset ?>"
     style="border-radius: 20px !important; border-color: #E2EADF !important; box-shadow: 0 4px 20px rgba(24, 59, 43, 0.04);">
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
      <div class="d-flex align-items-center gap-2">
        <span class="badge px-2.5 py-1 fw-bold text-uppercase" style="background: #E8F5E9; color: #1B5E20; font-size: 0.72rem; letter-spacing: 0.05em; border-radius: 6px;">
          <i class="fa-solid fa-basket-shopping me-1"></i> Mua Kèm Tiết Kiệm
        </span>
        <span class="text-muted small"><?= h($routineBadge) ?></span>
      </div>
      <h5 class="fw-bold mt-1 mb-0" style="color: #0F172A; font-size: 1.15rem;"><?= h($routineTitle) ?></h5>
    </div>
    <div class="d-flex align-items-center gap-2">
      <?php if ($routineHasRealRule): ?>
        <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.78rem;">Phân tích giao dịch mua cùng</span>
      <?php else: ?>
        <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.78rem;">Gợi ý theo bước dưỡng</span>
      <?php endif; ?>
      <button type="button" class="detail-product-refresh" data-product-block-refresh aria-label="Đổi sản phẩm gợi ý" title="Xem sản phẩm gợi ý khác">
        <i class="fas fa-arrows-rotate" aria-hidden="true"></i>
        <span>Đổi sản phẩm</span>
      </button>
    </div>
  </div>

  <div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 g-3" data-routine-products>
    <?php foreach ($routineItems as $fbItem): ?>
      <?php
        if (!is_array($fbItem)) continue;
        $fbProductId = (string)($fbItem['ma_san_pham'] ?? $fbItem['id'] ?? '');
        if ($fbProductId === '') continue;
        $fbImg = resolve_image_url(function_exists('product_image_source') ? product_image_source($fbItem) : (string)($fbItem['hinh_anh'] ?? $fbItem['link_hinh_anh'] ?? ''));
        $fbUrl = BASE_URL . '/index.php?r=chitiet&id=' . urlencode($fbProductId);
        $fbSource = (string)($fbItem['recommendation_source'] ?? '');
        $fbIsGenuineRule = $fbSource === 'ASSOCIATION_RULE';
        $fbHasConfidence = $fbIsGenuineRule && isset($fbItem['confidence']) && $fbItem['confidence'] !== null && $fbItem['confidence'] > 0;
        $fbConfidence = $fbHasConfidence ? round((float)$fbItem['confidence'] * 100) : null;
        $fbIsOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($fbItem) : false;
      ?>
      <div class="col">
        <div class="card h-100 border p-2.5 d-flex flex-column justify-content-between position-relative" style="border-radius: 14px; border-color: #EEF2F6 !important; background: #FAFCF9; transition: transform 0.2s, box-shadow 0.2s;">
          <div>
            <a href="<?= h($fbUrl) ?>" class="d-block text-center mb-2 overflow-hidden" style="border-radius: 10px; background: #fff; aspect-ratio: 1/1;">
              <img src="<?= h($fbImg ?: default_placeholder_image()) ?>"
                   alt="<?= h($fbItem['ten_san_pham'] ?? '') ?>"
                   class="w-100 h-100 object-fit-cover"
                   loading="lazy"
                   decoding="async"
                   width="200"
                   height="200"
                   referrerpolicy="no-referrer"
                   data-image-fallbacks="<?= function_exists('product_image_fallbacks_json') ? product_image_fallbacks_json($fbItem, $fbImg) : '[]' ?>"
                   data-image-placeholder="<?= h(default_placeholder_image()) ?>"
                   onerror="window.SkinSyntaxImageFallback(this)">
            </a>
            <div class="mb-1">
              <?php if ($fbIsGenuineRule && $fbConfidence !== null): ?>
                <span class="badge" style="background: #E8F5E9; color: #2E7D32; font-size: 0.68rem; border-radius: 4px;"><?= (int)$fbConfidence ?>% khách chọn cùng</span>
              <?php elseif ($fbSource === 'ROUTINE_COMPLEMENT'): ?>
                <span class="badge" style="background: #E0F2FE; color: #0369A1; font-size: 0.68rem; border-radius: 4px;">Gợi ý kết hợp cho routine</span>
              <?php elseif ($fbSource === 'BRAND_COMPLEMENT'): ?>
                <span class="badge" style="background: #FEF3C7; color: #92400E; font-size: 0.68rem; border-radius: 4px;">Cùng thương hiệu</span>
              <?php else: ?>
                <span class="badge" style="background: #F1F5F9; color: #475569; font-size: 0.68rem; border-radius: 4px;">Có thể bạn cũng quan tâm</span>
              <?php endif; ?>
            </div>
            <a href="<?= h($fbUrl) ?>" class="text-decoration-none text-dark d-block fw-semibold text-truncate-2 mb-2" style="font-size: 0.85rem; line-height: 1.35; height: 2.7rem; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;" title="<?= h($fbItem['ten_san_pham'] ?? '') ?>">
              <?= h($fbItem['ten_san_pham'] ?? 'Sản phẩm liên quan') ?>
            </a>
          </div>
          <div>
            <div class="d-flex align-items-baseline justify-content-between mb-2">
              <span class="fw-bold" style="color: #183B2B; font-size: 0.92rem;"><?= vnd($fbItem['gia_ban'] ?? 0) ?></span>
              <?php if (!empty($fbItem['gia_thi_truong']) && $fbItem['gia_thi_truong'] > ($fbItem['gia_ban'] ?? 0)): ?>
                <span class="text-muted text-decoration-line-through small" style="font-size: 0.75rem;"><?= vnd($fbItem['gia_thi_truong']) ?></span>
              <?php endif; ?>
            </div>
            <div class="routine-recommend-actions">
              <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
                <input type="hidden" name="action" value="add_to_cart">
                <input type="hidden" name="product_id" value="<?= h($fbProductId) ?>">
                <input type="hidden" name="ma_san_pham" value="<?= h($fbProductId) ?>">
                <input type="hidden" name="quantity" value="1">
                <input type="hidden" name="qty" value="1">
                <button class="btn btn-sm w-100 routine-recommend-button routine-recommend-button--cart" type="submit" <?= $fbIsOutOfStock ? 'disabled' : '' ?> title="Thêm vào giỏ">
                  <?= $fbIsOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1" aria-hidden="true"></i> Thêm giỏ' ?>
                </button>
              </form>
              <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
                <input type="hidden" name="action" value="add_to_cart">
                <input type="hidden" name="buy_now" value="1">
                <input type="hidden" name="product_id" value="<?= h($fbProductId) ?>">
                <input type="hidden" name="ma_san_pham" value="<?= h($fbProductId) ?>">
                <input type="hidden" name="quantity" value="1">
                <input type="hidden" name="qty" value="1">
                <button class="btn btn-sm w-100 routine-recommend-button routine-recommend-button--buy" type="submit" <?= $fbIsOutOfStock ? 'disabled' : '' ?> title="Mua ngay">
                  <?= $fbIsOutOfStock ? 'Hết hàng' : 'Mua ngay' ?>
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
