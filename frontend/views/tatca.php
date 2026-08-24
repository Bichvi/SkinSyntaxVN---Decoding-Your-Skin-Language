<?php
// backend/app/views/tatca.php
$total = $total ?? 0;
$perPage = $perPage ?? 24;
$page = $page ?? 1;
$q = $q ?? '';
$cap1 = $cap1 ?? '';
$cap2 = $cap2 ?? '';
$items = $items ?? [];
$pageTitle = trim((string)($pageTitle ?? 'Tất cả sản phẩm'));
$dbUnavailableMessage = trim((string)($dbUnavailableMessage ?? ''));
$listRoute = trim((string)($listRoute ?? 'tatca'));
$listType = trim((string)($listType ?? ''));
$paginationBase = BASE_URL . '/index.php?r=' . rawurlencode($listRoute);
if ($listType !== '') {
  $paginationBase .= '&type=' . urlencode($listType);
}

$totalPages = max(1, (int)ceil($total / $perPage));
$maxVisible = 10;
$startPage = max(1, (int)$page - (int)floor($maxVisible / 2));
$endPage = min($totalPages, $startPage + $maxVisible - 1);
$startPage = max(1, $endPage - $maxVisible + 1);
?>

<div class="container my-4">
  <div class="p-4 mb-4 bg-white border" style="border-radius: 12px; border-color: var(--border) !important;">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
      <div>
        <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.75rem;">SKINSYNTAX CATALOG</span>
        <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.8rem;"><?= h($pageTitle) ?></h2>
        <div class="text-muted small mt-1">Tổng cộng: <strong><?= (int)$total ?></strong> sản phẩm chuẩn skincare</div>
        <?php if (!empty($cap1) || !empty($cap2)): ?>
          <div class="badge fw-semibold px-2.5 py-1.5 mt-2" style="background: #EBF2EE; color: #183B2B; border: 1px solid #C8DACF; border-radius: 4px; font-size: 0.78rem;">
            Đang lọc: <?= h($cap1) ?><?= $cap2 ? ' / ' . h($cap2) : '' ?>
          </div>
        <?php endif; ?>
      </div>
      <a class="btn py-2 px-3.5 fw-semibold" href="<?= BASE_URL ?>/index.php?r=goiy" style="border: 1px solid #183B2B; color: #183B2B; border-radius: 6px; font-size: 0.86rem;">
        <i class="fas fa-wand-magic-sparkles me-1.5"></i> Khám phá bằng AI
      </a>
    </div>

    <form class="row g-2 pt-3 border-top" method="get" action="<?= BASE_URL ?>/index.php">
      <input type="hidden" name="r" value="tatca">
      <?php if ($cap1): ?><input type="hidden" name="cap1" value="<?= h($cap1) ?>"><?php endif; ?>
      <?php if ($cap2): ?><input type="hidden" name="cap2" value="<?= h($cap2) ?>"><?php endif; ?>

      <div class="col-12 col-md-8">
        <div class="position-relative">
          <input class="form-control" name="q" placeholder="Tìm tên sản phẩm, thương hiệu (La Roche-Posay, Paula's Choice...)" value="<?= h($q) ?>" style="border-radius: 6px; padding: 10px 16px 10px 38px; background: #FAFAFA; border-color: var(--border); font-size: 0.88rem;">
          <i class="fas fa-magnifying-glass position-absolute top-50 start-0 translate-middle-y ms-3 text-muted" style="font-size: 0.85rem;"></i>
        </div>
      </div>
      <div class="col-6 col-md-2 d-grid">
        <button class="btn text-white fw-semibold" type="submit" style="background: #183B2B; border-radius: 6px; font-size: 0.88rem;">Lọc sản phẩm</button>
      </div>
      <div class="col-6 col-md-2 d-grid">
        <a class="btn btn-outline-secondary fw-semibold" href="<?= BASE_URL ?>/index.php?r=tatca" style="border-radius: 6px; font-size: 0.88rem;">Xóa bộ lọc</a>
      </div>
    </form>
  </div>

  <?php if ($dbUnavailableMessage !== ''): ?>
    <div class="alert alert-warning border-0 shadow-sm" style="border-radius: 8px;"><?= h($dbUnavailableMessage) ?></div>
  <?php elseif (empty($items)): ?>
    <div class="p-5 text-center bg-white border text-muted" style="border-radius: 12px; border-color: var(--border) !important;">
      <i class="fas fa-box-open fs-1 mb-3" style="color: #183B2B;"></i>
      <h4 class="fw-bold text-dark">Không tìm thấy sản phẩm phù hợp</h4>
      <p style="font-size: 0.9rem;">Thử tìm kiếm với từ khóa khác hoặc bấm Xóa bộ lọc để quay lại danh mục đầy đủ.</p>
      <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn text-white fw-semibold px-4 mt-2" style="background: #183B2B; border-radius: 6px; font-size: 0.88rem;">Xem tất cả sản phẩm</a>
    </div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($items as $p):
        $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
        $img = resolve_image_url((string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
        $giaBan = (string)($p['gia_ban'] ?? '');
        $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
        $phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
        $rating = isset($p['diem_danh_gia']) && (float)$p['diem_danh_gia'] > 0 ? (float)$p['diem_danh_gia'] : 4.9;
        $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($p) : false;
      ?>
        <div class="col-6 col-md-3">
          <div class="product-card h-100 d-flex flex-column" style="border-radius: 12px; border: 1px solid var(--border); background: #FFF; transition: border-color 0.2s ease, box-shadow 0.2s ease;">
            <div class="product-thumb position-relative p-2" style="aspect-ratio: 1/1; overflow: hidden; background: #F8FAF8; border-radius: 12px 12px 0 0;">
              <?php if ($phanTramGiam !== null): ?>
                <span class="badge-sale position-absolute" style="top: 12px; left: 12px; background: #E11D48; color: #FFF; font-weight: 700; font-size: 0.72rem; padding: 3px 8px; border-radius: 4px; z-index: 2;">
                  -<?= h((string)$phanTramGiam) ?>%
                </span>
              <?php endif; ?>

              <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" class="d-block w-100 h-100 overflow-hidden" style="border-radius: 8px;">
                <img src="<?= h($img ?: 'https://via.placeholder.com/450x450?text=SkinSyntax') ?>" referrerpolicy="no-referrer" onerror="this.src='https://via.placeholder.com/450x450?text=SkinSyntax';" alt="<?= h($p['ten_san_pham'] ?? '') ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
              </a>
            </div>

            <div class="product-meta p-3 d-flex flex-column flex-grow-1">
              <div class="brand text-uppercase fw-bold mb-1.5" style="font-size: 0.68rem; color: #183B2B; background: #EBF2EE; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.04em; font-weight: 700; display: inline-block;"><?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
              <a class="name fw-semibold mb-2 text-decoration-none" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" style="color: #0F172A; font-size: 0.88rem; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.4em;">
                <?= h($p['ten_san_pham'] ?? '') ?>
              </a>

              <div class="d-flex align-items-center gap-1 mb-2" style="font-size: 0.76rem;">
                <span class="d-inline-flex align-items-center gap-1 text-warning font-weight-bold">
                  <i class="fas fa-star" style="color: #F59E0B; font-size: 0.72rem;"></i> <?= number_format($rating, 1) ?>
                </span>
                <span class="text-muted small ms-1">(<?= (int)($p['so_luong_danh_gia'] ?? 128) ?>)</span>
              </div>

              <div class="price-wrap mb-3 mt-auto d-flex align-items-baseline gap-2">
                <div class="price fw-bold" style="color: #183B2B; font-size: 1.05rem; font-variant-numeric: tabular-nums;"><?= vnd($giaBan) ?></div>
                <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)$giaBan): ?>
                  <div class="price-market text-muted text-decoration-line-through" style="font-size: 0.8rem; font-variant-numeric: tabular-nums; color: #94A3B8 !important;"><?= vnd($giaThiTruong) ?></div>
                <?php endif; ?>
              </div>

              <div class="product-card-actions d-grid gap-2" style="grid-template-columns: 1fr 1fr;">
                <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="product_id" value="<?= h($productId) ?>">
                  <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
                  <input type="hidden" name="quantity" value="1">
                  <input type="hidden" name="qty" value="1">
                  <button class="btn btn-sm w-100" type="submit" style="background: #F1F5F9; color: #0F172A; border: 1px solid #E2E8F0; border-radius: 6px; font-weight: 600; font-size: 0.78rem; padding: 7px 0;" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1"></i> Thêm' ?></button>
                </form>
                <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="buy_now" value="1">
                  <input type="hidden" name="product_id" value="<?= h($productId) ?>">
                  <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
                  <input type="hidden" name="quantity" value="1">
                  <input type="hidden" name="qty" value="1">
                  <button class="btn btn-sm w-100 text-white" type="submit" style="background: #183B2B; border-radius: 6px; font-weight: 600; font-size: 0.78rem; padding: 7px 0; border: none;" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Hết hàng' : 'Mua ngay' ?></button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($totalPages > 1): ?>
    <nav class="mt-5">
      <ul class="pagination justify-content-center gap-1">
        <?php if ($page > 1): ?>
          <li class="page-item">
            <a class="page-link d-grid place-items-center" href="<?= $paginationBase ?>&page=1&q=<?= urlencode($q) ?>&cap1=<?= urlencode($cap1) ?>&cap2=<?= urlencode($cap2) ?>" style="width: 36px; height: 36px; border-radius: 6px; border-color: var(--border); color: #183B2B;">
              «
            </a>
          </li>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
          <li class="page-item <?= ($i === (int)$page) ? 'active' : '' ?>">
            <a class="page-link d-grid place-items-center fw-semibold" href="<?= $paginationBase ?>&page=<?= $i ?>&q=<?= urlencode($q) ?>&cap1=<?= urlencode($cap1) ?>&cap2=<?= urlencode($cap2) ?>" style="width: 36px; height: 36px; border-radius: 6px; border-color: var(--border); <?= ($i === (int)$page) ? 'background: #183B2B; color: #FFF; border-color: #183B2B;' : 'color: #0F172A;' ?>">
              <?= $i ?>
            </a>
          </li>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
          <li class="page-item">
            <a class="page-link d-grid place-items-center" href="<?= $paginationBase ?>&page=<?= $totalPages ?>&q=<?= urlencode($q) ?>&cap1=<?= urlencode($cap1) ?>&cap2=<?= urlencode($cap2) ?>" style="width: 36px; height: 36px; border-radius: 6px; border-color: var(--border); color: #183B2B;">
              »
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </nav>
  <?php endif; ?>
</div>
