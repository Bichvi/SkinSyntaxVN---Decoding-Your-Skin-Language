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
    $image = default_placeholder_image();
}

$discountProduct = array_merge($product, [
    'gia_ban' => $salePrice,
    'gia_thi_truong' => $marketPrice,
]);
$discount = function_exists('product_discount_percent') ? product_discount_percent($discountProduct) : null;
$isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($product) : false;
$sold = (int)($product['so_luong_da_ban'] ?? $product['so_luong_ban'] ?? 0);
$rating = trim((string)($product['diem_danh_gia'] ?? ''));
$reviewCount = (int)($product['so_luong_danh_gia'] ?? 0);
$matchPercentRaw = $product['match_percent'] ?? null;
$matchPercent = is_numeric($matchPercentRaw) ? max(0, min(100, (int)$matchPercentRaw)) : null;
$matchLabel = trim((string)($product['match_label'] ?? 'Phù hợp'));
$uniqueModalId = 'explainModal_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $productId) . '_' . mt_rand(1000, 9999);
$explanation = trim((string)($product['llm_explanation'] ?? $product['mo_ta'] ?? 'Sản phẩm được thuật toán RAG và LangChain phân tích trùng khớp với loại da, nhu cầu cải thiện da và ngân sách của bạn.'));
?>

<?php if ($cardVariant === 'rcm'): ?>
  <article class="rcm-product-card product-card h-100 d-flex flex-column" style="border-radius: 12px; border: 1px solid var(--border); background: #FFF; transition: border-color 0.2s ease, box-shadow 0.2s ease;">
    <a class="rcm-product-image-wrap position-relative d-block p-2" href="<?= h($detailUrl) ?>" style="aspect-ratio: 1/1; overflow: hidden; background: #F8FAF8; border-radius: 12px 12px 0 0;">
      <img src="<?= h($image) ?>" alt="<?= h($productName !== '' ? $productName : 'Sản phẩm SkinSyntax') ?>" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
      <?php if ($discount !== null): ?>
        <span class="rcm-discount-badge position-absolute" style="top: 12px; left: 12px; background: #E11D48; color: #FFF; font-weight: 700; font-size: 0.72rem; padding: 3px 8px; border-radius: 4px; z-index: 3;">-<?= h((string)$discount) ?>%</span>
      <?php endif; ?>
      <span class="rcm-match-badge position-absolute" style="top: 12px; right: 12px; background: #EBF2EE; color: #183B2B; border: 1px solid #C8DACF; font-size: 0.72rem; font-weight: 600; padding: 3px 8px; border-radius: 4px; z-index: 3;"><?= h($badgeLabel !== '' ? $badgeLabel : 'PHÙ HỢP') ?></span>
    </a>

    <div class="rcm-product-body p-3 d-flex flex-column flex-grow-1">
      <div class="mb-1.5">
        <span class="rcm-product-brand text-uppercase" style="font-size: 0.68rem; color: #183B2B; background: #EBF2EE; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.04em; font-weight: 700; display: inline-block;"><?= h($productBrand) ?></span>
      </div>
      <h3 class="rcm-product-name fw-semibold mb-2" style="font-size: 0.88rem; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.4em;">
        <a href="<?= h($detailUrl) ?>" style="color: #0F172A; text-decoration: none;"><?= h($productName) ?></a>
      </h3>
      <div class="rcm-product-price fw-bold" style="color: #183B2B; font-size: 1.05rem; font-variant-numeric: tabular-nums;"><?= h(vnd($salePrice)) ?></div>
      <?php if ((float)$marketPrice > (float)$salePrice): ?>
        <div class="rcm-product-market text-muted text-decoration-line-through" style="font-size: 0.8rem; font-variant-numeric: tabular-nums; color: #94A3B8 !important;"><?= h(vnd($marketPrice)) ?></div>
      <?php endif; ?>
      <?php if ($matchPercent !== null): ?>
        <div class="recommend-match my-2">
          <div class="recommend-match__head d-flex justify-content-between small mb-1">
            <span class="text-muted" style="font-size: 0.76rem;"><?= h($matchLabel !== '' ? $matchLabel : 'Phù hợp') ?></span>
            <strong style="color: #183B2B; font-size: 0.78rem;"><?= h((string)$matchPercent) ?>%</strong>
          </div>
          <div class="recommend-match__bar" aria-label="Độ phù hợp <?= h((string)$matchPercent) ?>%" style="height: 5px; background: #E2E8F0; border-radius: 4px; overflow: hidden;">
            <span class="recommend-match__fill d-block h-100" style="width: <?= h((string)$matchPercent) ?>%; background: #183B2B;"></span>
          </div>
        </div>
      <?php endif; ?>

      <button type="button" class="btn btn-sm w-100 mb-2 py-1 fw-semibold" style="font-size: 0.76rem; border: 1px solid #C8DACF; color: #183B2B; background: #FAFAFA; border-radius: 6px;" data-bs-toggle="modal" data-bs-target="#<?= $uniqueModalId ?>">
        <i class="fa-solid fa-circle-question me-1"></i> Vì sao phù hợp?
      </button>

      <div class="rcm-product-actions d-grid gap-2 mt-auto" style="grid-template-columns: 1fr 1fr; width: 100%;">
        <?php if ($productId !== ''): ?>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100" style="width: 100%;">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="rcm-product-cart btn btn-sm w-100" type="submit" style="background: #F1F5F9; color: #0F172A; border: 1px solid #E2E8F0; border-radius: 6px; font-weight: 600; font-size: 0.78rem; padding: 7px 0; display: block; width: 100%;" <?= $isOutOfStock ? 'disabled' : '' ?>>
              <?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1"></i> Thêm' ?>
            </button>
          </form>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100" style="width: 100%;">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="buy_now" value="1">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="rcm-product-buy btn btn-sm w-100 text-white" type="submit" style="background: #183B2B; border-radius: 6px; font-weight: 600; font-size: 0.78rem; padding: 7px 0; border: none; display: block; width: 100%;" <?= $isOutOfStock ? 'disabled' : '' ?>>
              <?= $isOutOfStock ? 'Hết hàng' : 'Mua ngay' ?>
            </button>
          </form>
        <?php else: ?>
          <a class="rcm-product-detail btn btn-sm w-100" href="<?= h($detailUrl) ?>" style="border-radius: 6px; font-weight: 600; font-size: 0.78rem; padding: 7px 0; background: #F1F5F9; color: #0F172A; border: 1px solid #E2E8F0; text-align: center; text-decoration: none; display: block; grid-column: span 2;">Chi tiết sản phẩm</a>
        <?php endif; ?>
      </div>
    </div>
  </article>

  <!-- Modal Giải thích độ phù hợp sản phẩm -->
  <div class="modal fade" id="<?= $uniqueModalId ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="border-radius: 12px; border: 1px solid var(--border); overflow: hidden;">
        <div class="modal-header text-white" style="background: #183B2B;">
          <h6 class="modal-title fw-semibold m-0" style="font-size: 0.92rem;"><i class="fa-solid fa-sparkles me-2"></i>Vì sao sản phẩm này phù hợp với bạn?</h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <div class="d-flex align-items-center gap-3 mb-3">
            <img src="<?= h($image) ?>" alt="<?= h($productName) ?>" style="width: 64px; height: 64px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border);">
            <div>
              <span class="badge mb-1" style="background: #EBF2EE; color: #183B2B; border-radius: 4px; font-size: 0.72rem; border: 1px solid #C8DACF;"><?= h($matchLabel !== '' ? $matchLabel : 'PHÙ HỢP') ?><?= $matchPercent !== null ? ' - ' . h((string)$matchPercent) . '%' : '' ?></span>
              <div class="fw-bold small text-uppercase" style="color: #183B2B; font-size: 0.7rem;"><?= h($productBrand) ?></div>
              <div class="fw-semibold text-dark" style="font-size: 0.88rem;"><?= h($productName) ?></div>
              <div class="fw-bold fs-6 mt-1 tabular-nums" style="color: #183B2B;"><?= h(vnd($salePrice)) ?></div>
            </div>
          </div>
          <hr class="my-3">
          <div class="mb-2 small">
            <strong><i class="fa-solid fa-droplet me-1" style="color: #183B2B;"></i> Loại da tương thích:</strong>
            <span class="ms-1"><?= h((string)($product['loai_da'] ?? 'Phù hợp đa số loại da')) ?></span>
          </div>
          <div class="mb-2 small">
            <strong><i class="fa-solid fa-vial me-1" style="color: #183B2B;"></i> Hoạt chất chính:</strong>
            <span class="ms-1"><?= h((string)($product['thanh_phan_chinh'] ?? $product['thanh_phan'] ?? 'Hoạt chất phục hồi và chăm sóc chuyên sâu')) ?></span>
          </div>
          <div class="mb-3 small">
            <strong><i class="fa-solid fa-shield-check me-1" style="color: #183B2B;"></i> Độ an toàn:</strong>
            <span class="ms-1">Không phát hiện thành phần cồn khô hay kích ứng theo hồ sơ da của bạn.</span>
          </div>
          
          <div class="p-3" style="background: #FAFAFA; border: 1px solid var(--border); border-radius: 8px;">
            <div class="fw-semibold small mb-1" style="color: #183B2B;"><i class="fa-solid fa-robot me-1"></i> Phân tích:</div>
            <div class="small text-dark" style="line-height: 1.6; font-size: 0.84rem;"><?= nl2br(h($explanation)) ?></div>
          </div>
        </div>
        <div class="modal-footer bg-light border-0">
          <button type="button" class="btn btn-sm btn-secondary px-3" data-bs-dismiss="modal" style="border-radius: 6px; font-size: 0.8rem;">Đóng</button>
          <a href="<?= h($detailUrl) ?>" class="btn btn-sm btn-brand px-3" style="border-radius: 6px; font-size: 0.8rem; background: #183B2B; color: #FFF;">Xem chi tiết sản phẩm</a>
        </div>
      </div>
    </div>
  </div>
  <?php return; ?>
<?php endif; ?>

<article class="flash-product goiy-product-card product-card h-100 d-flex flex-column" style="border-radius: 12px; border: 1px solid var(--border); background: #FFF; transition: border-color 0.2s ease;">
  <a class="flash-product__image position-relative d-block p-2" href="<?= h($detailUrl) ?>" style="aspect-ratio: 1/1; overflow: hidden; background: #F8FAF8; border-radius: 12px 12px 0 0;">
    <img src="<?= h($image) ?>" alt="<?= h($productName !== '' ? $productName : 'Sản phẩm SkinSyntax') ?>" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
    <?php if ($discount !== null): ?>
      <span class="flash-product__badge position-absolute" style="top: 12px; left: 12px; background: #E11D48; color: #FFF; font-weight: 700; font-size: 0.72rem; padding: 3px 8px; border-radius: 4px; z-index: 3;">-<?= h((string)$discount) ?>%</span>
    <?php endif; ?>
    <?php if ($badgeLabel !== ''): ?>
      <span class="goiy-product-card__group-badge position-absolute" style="top: 12px; right: 12px; background: #EBF2EE; color: #183B2B; border: 1px solid #C8DACF; font-size: 0.72rem; font-weight: 600; padding: 3px 8px; border-radius: 4px; z-index: 3;"><?= h($badgeLabel) ?></span>
    <?php endif; ?>
    <?php if ($isOutOfStock): ?>
      <span class="goiy-product-card__stock-badge position-absolute" style="bottom: 14px; left: 14px; background: rgba(15,23,42,0.78); color: #FFF; font-size: 0.72rem; font-weight: 700; padding: 5px 12px; border-radius: 999px; z-index: 3;">Tạm hết hàng</span>
    <?php endif; ?>
  </a>

  <div class="flash-product__body p-3 d-flex flex-column flex-grow-1">
    <div class="mb-1">
      <span class="flash-product__brand text-uppercase fw-extrabold" style="font-size: 0.7rem; color: #215427; background: #EAF0EB; padding: 3px 9px; border-radius: 6px; letter-spacing: 0.05em; font-weight: 800; display: inline-block;"><?= h($productBrand) ?></span>
    </div>
    <h3 class="flash-product__name fw-bold mb-2" style="font-size: 0.95rem; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.7em;">
      <a href="<?= h($detailUrl) ?>" style="color: #1A2F1A; text-decoration: none;"><?= h($productName) ?></a>
    </h3>
    <div class="flash-product__price fw-bold" style="color: #215427; font-size: 1.15rem; font-weight: 800;"><?= h(vnd($salePrice)) ?></div>
    <?php if ((float)$marketPrice > (float)$salePrice): ?>
      <div class="flash-product__market text-muted text-decoration-line-through" style="font-size: 0.82rem; color: #94A3B8 !important;"><?= h(vnd($marketPrice)) ?></div>
    <?php endif; ?>
    <div class="goiy-product-card__meta mb-3 mt-1 small" style="color: #5C705E;">
      <?php if ($rating !== ''): ?>
        <span class="d-inline-flex align-items-center gap-1 px-2 py-0.5 rounded-pill" style="background: #FFFBEB; color: #D97706; font-weight: 800; border: 1px solid #FEF3C7;"><i class="fas fa-star" style="color:#F59E0B;"></i> <?= h($rating) ?><?= $reviewCount > 0 ? ' · ' . h((string)$reviewCount) . ' đánh giá' : '' ?></span>
      <?php endif; ?>
      <?php if ($sold > 0): ?>
        <span class="ms-1">· Đã bán <?= h((string)$sold) ?></span>
      <?php endif; ?>
    </div>

    <button type="button" class="btn btn-sm btn-outline-success rounded-pill w-100 mb-2 py-1 fw-bold" style="font-size: 0.78rem; border-color: #84A98C; color: #215427;" data-bs-toggle="modal" data-bs-target="#<?= $uniqueModalId ?>">
      <i class="fa-solid fa-circle-question me-1"></i> Vì sao phù hợp?
    </button>

    <div class="flash-product__actions d-grid gap-1.5 mt-auto" style="grid-template-columns: 1fr 1fr; width: 100%;">
      <?php if ($productId !== ''): ?>
        <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100" style="width: 100%;">
          <input type="hidden" name="action" value="add_to_cart">
          <input type="hidden" name="product_id" value="<?= h($productId) ?>">
          <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
          <input type="hidden" name="quantity" value="1">
          <input type="hidden" name="qty" value="1">
          <button class="flash-product__cart btn btn-sm btn-product-add w-100" type="submit" style="background: #EAF0EB; color: #215427; border: 1px solid #C5DAC8; border-radius: 999px; font-weight: 700; font-size: 0.8rem; padding: 8px 0; display: block; width: 100%;" <?= $isOutOfStock ? 'disabled' : '' ?>>
            <?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1"></i> Giỏ hàng' ?>
          </button>
        </form>
        <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100" style="width: 100%;">
          <input type="hidden" name="action" value="add_to_cart">
          <input type="hidden" name="buy_now" value="1">
          <input type="hidden" name="product_id" value="<?= h($productId) ?>">
          <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
          <input type="hidden" name="quantity" value="1">
          <input type="hidden" name="qty" value="1">
          <button class="flash-product__buy btn btn-sm btn-product-buy w-100 text-white" type="submit" style="background: linear-gradient(135deg, #215427 0%, #162F18 100%); border-radius: 999px; font-weight: 800; font-size: 0.8rem; padding: 8px 0; border: none; box-shadow: 0 4px 14px rgba(33, 84, 39, 0.25); display: block; width: 100%;" <?= $isOutOfStock ? 'disabled' : '' ?>>
            <?= $isOutOfStock ? 'Hết hàng' : ' Mua ngay' ?>
          </button>
        </form>
      <?php else: ?>
        <a class="flash-product__detail btn btn-sm btn-product-detail w-100" href="<?= h($detailUrl) ?>" style="border-radius: 999px; font-weight: 700; font-size: 0.84rem; padding: 8px 0; background: #EAF0EB; color: #215427; border: 1px solid #C5DAC8; text-align: center; text-decoration: none; display: block; grid-column: span 2;">Chi tiết sản phẩm</a>
      <?php endif; ?>
    </div>
  </div>
</article>

<!-- Modal Giải thích độ phù hợp sản phẩm -->
<div class="modal fade" id="<?= $uniqueModalId ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius: 22px; border: 1px solid #84A98C; overflow: hidden;">
      <div class="modal-header text-white" style="background: linear-gradient(135deg, #162F18 0%, #215427 100%);">
        <h6 class="modal-title fw-bold m-0"><i class="fa-solid fa-sparkles me-2"></i>Vì sao sản phẩm này phù hợp với bạn?</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <div class="d-flex align-items-center gap-3 mb-3">
          <img src="<?= h($image) ?>" alt="<?= h($productName) ?>" style="width: 75px; height: 75px; object-fit: cover; border-radius: 14px; border: 1px solid #E2EADF;">
          <div>
            <span class="badge bg-success mb-1"><?= h($matchLabel !== '' ? $matchLabel : 'PHÙ HỢP') ?><?= $matchPercent !== null ? ' - ' . h((string)$matchPercent) . '%' : '' ?></span>
            <div class="fw-bold text-success small text-uppercase"><?= h($productBrand) ?></div>
            <div class="fw-bold text-dark" style="font-size: 0.95rem;"><?= h($productName) ?></div>
            <div class="fw-extrabold text-success fs-6 mt-1"><?= h(vnd($salePrice)) ?></div>
          </div>
        </div>
        <hr class="my-3">
        <div class="mb-2">
          <strong><i class="fa-solid fa-droplet text-success me-1"></i> Loại da tương thích:</strong>
          <span class="ms-1"><?= h((string)($product['loai_da'] ?? 'Phù hợp đa số loại da')) ?></span>
        </div>
        <div class="mb-2">
          <strong><i class="fa-solid fa-vial text-success me-1"></i> Hoạt chất chính:</strong>
          <span class="ms-1"><?= h((string)($product['thanh_phan_chinh'] ?? $product['thanh_phan'] ?? 'Hoạt chất phục hồi và chăm sóc chuyên sâu')) ?></span>
        </div>
        <div class="mb-3">
          <strong><i class="fa-solid fa-shield-check text-success me-1"></i> Độ an toàn:</strong>
          <span class="ms-1">Không phát hiện thành phần cồn khô hay kích ứng theo hồ sơ da của bạn.</span>
        </div>
        
        <div class="p-3 rounded-3" style="background: #F0F4F1; border: 1px solid #C5DAC8;">
          <div class="fw-bold text-success small mb-1"><i class="fa-solid fa-robot me-1"></i> Phân tích:</div>
          <div class="small text-dark" style="line-height: 1.6;"><?= nl2br(h($explanation)) ?></div>
        </div>
      </div>
      <div class="modal-footer bg-light border-0">
        <button type="button" class="btn btn-sm btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Đóng</button>
        <a href="<?= h($detailUrl) ?>" class="btn btn-sm btn-brand rounded-pill px-4">Xem chi tiết sản phẩm</a>
      </div>
    </div>
  </div>
</div>
