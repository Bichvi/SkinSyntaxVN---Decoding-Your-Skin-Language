<?php
$product = is_array($product ?? null) ? $product : [];
$badgeLabel = trim((string)($badgeLabel ?? ''));
$cardVariant = trim((string)($cardVariant ?? 'default'));

$productId = (string)($product['ma_san_pham'] ?? $product['id'] ?? $product['product_id'] ?? '');
$productName = (string)($product['ten_san_pham'] ?? $product['name'] ?? $product['title'] ?? '');
$productBrand = (string)($product['thuong_hieu'] ?? $product['brand'] ?? 'SkinSyntax');
$salePrice = $product['gia_ban'] ?? $product['price'] ?? 0;
$marketPrice = $product['gia_thi_truong'] ?? $product['original_price'] ?? $product['market_price'] ?? 0;
$detailUrl = trim((string)($product['detail_url'] ?? ''));
if ($detailUrl === '') {
    $detailUrl = BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($productId);
}

$image = resolve_image_url((string)($product['link_hinh_anh'] ?? $product['image_url'] ?? ''));
if ($image === '') {
    $image = 'https://via.placeholder.com/450x450?text=SkinSyntax';
}

$discountProduct = array_merge($product, [
    'gia_ban' => $salePrice,
    'gia_thi_truong' => $marketPrice,
]);
$discount = function_exists('product_discount_percent') ? product_discount_percent($discountProduct) : null;
$isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($product) : false;
$stock = function_exists('product_stock_quantity') ? product_stock_quantity($product) : null;
$sold = (int)($product['so_luong_da_ban'] ?? $product['so_luong_ban'] ?? 0);
$rating = trim((string)($product['diem_danh_gia'] ?? ''));
$reviewCount = (int)($product['so_luong_danh_gia'] ?? 0);
?>

<?php if ($cardVariant === 'rcm'): ?>
  <article class="rcm-product-card">
    <a class="rcm-product-image-wrap" href="<?= h($detailUrl) ?>">
      <img src="<?= h($image) ?>" alt="<?= h($productName !== '' ? $productName : 'Sản phẩm SkinSyntax') ?>" referrerpolicy="no-referrer" onerror="this.src='https://via.placeholder.com/450x450?text=SkinSyntax';">
      <?php if ($discount !== null): ?>
        <span class="rcm-discount-badge">-<?= h((string)$discount) ?>%</span>
      <?php endif; ?>
      <span class="rcm-match-badge"><?= h($badgeLabel !== '' ? $badgeLabel : 'PHÙ HỢP') ?></span>
    </a>

    <div class="rcm-product-body">
      <p class="rcm-product-brand"><?= h($productBrand) ?></p>
      <h3 class="rcm-product-name"><?= h($productName) ?></h3>
      <div class="rcm-product-price"><?= h(vnd($salePrice)) ?></div>
      <?php if ((float)$marketPrice > (float)$salePrice): ?>
        <div class="rcm-product-market"><?= h(vnd($marketPrice)) ?></div>
      <?php endif; ?>

      <div class="rcm-product-actions">
        <a class="rcm-product-detail" href="<?= h($detailUrl) ?>">Xem chi tiết</a>
        <?php if ($productId !== ''): ?>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="rcm-product-cart" type="submit" <?= $isOutOfStock ? 'disabled' : '' ?>>
              <?= $isOutOfStock ? 'Tạm hết hàng' : 'Thêm giỏ hàng' ?>
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </article>
  <?php return; ?>
<?php endif; ?>

<article class="flash-product goiy-product-card">
  <a class="flash-product__image" href="<?= h($detailUrl) ?>">
    <img src="<?= h($image) ?>" alt="<?= h($productName !== '' ? $productName : 'Sản phẩm SkinSyntax') ?>" referrerpolicy="no-referrer" onerror="this.src='https://via.placeholder.com/450x450?text=SkinSyntax';">
    <?php if ($discount !== null): ?>
      <span class="flash-product__badge">-<?= h((string)$discount) ?>%</span>
    <?php endif; ?>
    <?php if ($badgeLabel !== ''): ?>
      <span class="goiy-product-card__group-badge"><?= h($badgeLabel) ?></span>
    <?php endif; ?>
    <?php if ($isOutOfStock): ?>
      <span class="goiy-product-card__stock-badge">Tạm hết hàng</span>
    <?php endif; ?>
  </a>

  <div class="flash-product__body">
    <p class="flash-product__brand"><?= h($productBrand) ?></p>
    <h3 class="flash-product__name"><?= h($productName) ?></h3>
    <div class="flash-product__price"><?= h(vnd($salePrice)) ?></div>
    <?php if ((float)$marketPrice > (float)$salePrice): ?>
      <div class="flash-product__market"><?= h(vnd($marketPrice)) ?></div>
    <?php endif; ?>
    <div class="goiy-product-card__meta">
      <?php if ($rating !== ''): ?>
        <span>★ <?= h($rating) ?><?= $reviewCount > 0 ? ' · ' . h((string)$reviewCount) . ' đánh giá' : '' ?></span>
      <?php endif; ?>
      <?php if ($sold > 0): ?>
        <span>Đã bán <?= h((string)$sold) ?></span>
      <?php endif; ?>
    </div>
    <div class="flash-product__actions">
      <a class="flash-product__detail" href="<?= h($detailUrl) ?>">Xem chi tiết</a>
      <?php if ($productId !== ''): ?>
        <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
          <input type="hidden" name="action" value="add_to_cart">
          <input type="hidden" name="product_id" value="<?= h($productId) ?>">
          <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
          <input type="hidden" name="quantity" value="1">
          <input type="hidden" name="qty" value="1">
          <button class="flash-product__cart" type="submit" <?= $isOutOfStock ? 'disabled' : '' ?>>
            <?= $isOutOfStock ? 'Tạm hết hàng' : 'Thêm giỏ hàng' ?>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</article>
