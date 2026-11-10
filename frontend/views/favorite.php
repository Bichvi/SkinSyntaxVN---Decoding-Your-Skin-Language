<?php
// Danh sách sản phẩm yêu thích của tài khoản hiện tại.
$favoriteProductIds = array_values(array_unique(array_map('strval', (array)($favoriteProductIds ?? []))));
$products = is_array($products ?? null) ? $products : [];
$renderHomeProductCard = require __DIR__ . '/partials/home_product_card.php';
?>

<div class="container py-4 favorite-page">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-2 mb-4">
    <div>
      <span class="home-section-kicker">DANH MỤC CỦA BẠN</span>
      <h1 class="fw-bold mb-1" style="color:#0F172A; font-size:1.65rem;">Sản phẩm yêu thích</h1>
      <p class="text-muted mb-0">Lưu lại những sản phẩm bạn muốn xem hoặc cân nhắc sau.</p>
    </div>
    <a class="btn btn-brand" href="<?= h(BASE_URL . '/index.php?r=tatca') ?>">Khám phá thêm sản phẩm</a>
  </div>

  <?php if (($errorMessage ?? '') !== ''): ?>
    <div class="alert alert-warning border-0" role="alert"><?= h($errorMessage) ?></div>
  <?php elseif (empty($products)): ?>
    <section class="favorite-empty-card text-center">
      <div class="favorite-empty-card__icon"><i class="far fa-heart"></i></div>
      <h2 class="h5 fw-bold mb-2">Danh sách yêu thích đang trống</h2>
      <p class="text-muted mb-3">Bấm trái tim trên sản phẩm để lưu lại lựa chọn phù hợp với bạn.</p>
      <a class="btn btn-brand" href="<?= h(BASE_URL . '/index.php?r=home') ?>">Về trang chủ</a>
    </section>
  <?php else: ?>
    <div class="favorite-page-grid">
      <?php foreach ($products as $product): ?>
        <div class="favorite-page-grid__item">
          <?php $renderHomeProductCard($product, 'Đã lưu'); ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
