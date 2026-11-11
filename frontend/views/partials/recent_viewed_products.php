<?php
$recentViewedProducts = is_array($recentViewedProducts ?? null) ? $recentViewedProducts : [];
if ($recentViewedProducts === []) {
    return;
}
$renderRecentProductCard = require __DIR__ . '/home_product_card.php';
?>
<section class="recent-viewed-products mt-4 mb-2 p-4 rounded-4 bg-white border w-100" aria-labelledby="recent-viewed-heading" style="border-radius: 20px !important; border-color: #E2EADF !important; box-shadow: 0 4px 20px rgba(24, 59, 43, 0.04);">
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
      <span class="badge px-2.5 py-1 fw-bold text-uppercase" style="background: #E8F5E9; color: #1B5E20; font-size: 0.72rem; letter-spacing: 0.05em; border-radius: 6px;">
        <i class="fa-solid fa-clock-rotate-left me-1"></i> Lịch sử xem
      </span>
      <h5 id="recent-viewed-heading" class="fw-bold mt-1 mb-0" style="color: #0F172A; font-size: 1.15rem;">Sản phẩm đã xem gần đây</h5>
    </div>
    <span class="text-muted small">Xem lại những sản phẩm bạn vừa quan tâm</span>
  </div>
  <div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 g-3">
    <?php foreach (array_slice($recentViewedProducts, 0, 4) as $recentProduct): ?>
      <?php if (is_array($recentProduct)): ?>
        <div class="col">
          <?php $renderRecentProductCard($recentProduct, 'Đã xem gần đây'); ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</section>
