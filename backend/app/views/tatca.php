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
  <div class="p-4 mb-4 rounded-4 bg-white border" style="border-radius: 28px !important; border-color: #E2EADF !important; box-shadow: 0 10px 30px rgba(33, 84, 39, 0.04);">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
      <div>
        <span class="text-uppercase fw-bold text-success small" style="letter-spacing: 0.08em;">SKINSYNTAX CATALOG</span>
        <h2 class="fw-bold m-0" style="color: #1A2F1A; font-size: 2rem;"><?= h($pageTitle) ?></h2>
        <div class="text-muted small mt-1">Tổng cộng: <strong><?= (int)$total ?></strong> sản phẩm chuẩn skincare</div>
        <?php if (!empty($cap1) || !empty($cap2)): ?>
          <div class="badge bg-success-subtle text-success fw-bold px-3 py-1.5 rounded-pill mt-2">
            Đang lọc: <?= h($cap1) ?><?= $cap2 ? ' / ' . h($cap2) : '' ?>
          </div>
        <?php endif; ?>
      </div>
      <a class="btn btn-outline-success rounded-pill px-4 fw-bold" href="<?= BASE_URL ?>/index.php?r=goiy">
        <i class="fas fa-wand-magic-sparkles me-2"></i> Khám phá bằng AI
      </a>
    </div>

    <form class="row g-2 pt-3 border-top" method="get" action="<?= BASE_URL ?>/index.php">
      <input type="hidden" name="r" value="tatca">
      <?php if ($cap1): ?><input type="hidden" name="cap1" value="<?= h($cap1) ?>"><?php endif; ?>
      <?php if ($cap2): ?><input type="hidden" name="cap2" value="<?= h($cap2) ?>"><?php endif; ?>

      <div class="col-12 col-md-8">
        <div class="position-relative">
          <input class="form-control" name="q" placeholder="Tìm tên sản phẩm, thương hiệu (La Roche-Posay, Paula's Choice...)" value="<?= h($q) ?>" style="border-radius: 999px; padding: 12px 20px 12px 42px; background: #F8FAF8; border-color: #E2EADF;">
          <i class="fas fa-magnifying-glass position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
        </div>
      </div>
      <div class="col-6 col-md-2 d-grid">
        <button class="btn text-white fw-bold" type="submit" style="background: #215427; border-radius: 999px;">Lọc sản phẩm</button>
      </div>
      <div class="col-6 col-md-2 d-grid">
        <a class="btn btn-outline-secondary fw-bold" href="<?= BASE_URL ?>/index.php?r=tatca" style="border-radius: 999px;">Xóa bộ lọc</a>
      </div>
    </form>
  </div>

  <?php if ($dbUnavailableMessage !== ''): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-4"><?= h($dbUnavailableMessage) ?></div>
  <?php elseif (empty($items)): ?>
    <div class="p-5 text-center bg-white rounded-4 border text-muted" style="border-radius: 28px !important; border-color: #E2EADF !important;">
      <i class="fas fa-box-open fs-1 mb-3 text-success"></i>
      <h4 class="fw-bold text-dark">Không tìm thấy sản phẩm phù hợp</h4>
      <p>Thử tìm kiếm với từ khóa khác hoặc bấm Xóa bộ lọc để quay lại danh mục đầy đủ.</p>
      <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn text-white fw-bold px-4 mt-2" style="background: #215427; border-radius: 999px;">Xem tất cả sản phẩm</a>
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
          <div class="product-card card-elevated h-100 d-flex flex-column" style="border-radius: 20px; border: 1px solid #E2EADF; background: #FFF; overflow: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);">
            <div class="product-thumb position-relative" style="aspect-ratio: 1/1; overflow: hidden; background: #F8FAF8;">
              <?php if ($phanTramGiam !== null): ?>
                <span class="badge-sale position-absolute" style="top: 12px; left: 12px; background: #E11D48; color: #FFF; font-weight: 800; font-size: 0.75rem; padding: 4px 10px; border-radius: 999px; z-index: 2;">
                  -<?= h((string)$phanTramGiam) ?>%
                </span>
              <?php endif; ?>

              <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" style="display: block; width: 100%; height: 100%;">
                <img src="<?= h($img ?: 'https://via.placeholder.com/450x450?text=SkinSyntax') ?>" referrerpolicy="no-referrer" onerror="this.src='https://via.placeholder.com/450x450?text=SkinSyntax';" alt="<?= h($p['ten_san_pham'] ?? '') ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease;">
              </a>
            </div>

            <div class="product-meta p-3 d-flex flex-column flex-grow-1">
              <div class="brand text-uppercase fw-bold mb-1" style="font-size: 0.72rem; color: #5C705E; letter-spacing: 0.05em;"><?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
              <a class="name fw-bold mb-2 text-decoration-none" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" style="color: #1A2F1A; font-size: 0.95rem; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.7em;">
                <?= h($p['ten_san_pham'] ?? '') ?>
              </a>

              <div class="d-flex align-items-center gap-1 mb-2" style="font-size: 0.78rem; color: #F59E0B;">
                <i class="fas fa-star"></i>
                <span class="fw-bold" style="color: #1A2F1A;"><?= number_format($rating, 1) ?></span>
                <span class="text-muted ms-1">(<?= (int)($p['so_luong_danh_gia'] ?? 128) ?>)</span>
              </div>

              <div class="price-wrap mb-3 mt-auto">
                <div class="price fw-bold" style="color: #215427; font-size: 1.1rem;"><?= vnd($giaBan) ?></div>
                <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)$giaBan): ?>
                  <div class="price-market text-muted text-decoration-line-through" style="font-size: 0.82rem;"><?= vnd($giaThiTruong) ?></div>
                <?php endif; ?>
              </div>

              <div class="product-card-actions d-grid gap-1.5" style="grid-template-columns: 1fr 1fr;">
                <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="product_id" value="<?= h($productId) ?>">
                  <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
                  <input type="hidden" name="quantity" value="1">
                  <input type="hidden" name="qty" value="1">
                  <button class="btn btn-sm w-100" type="submit" style="background: #EAF0EB; color: #215427; border: 1px solid #C5DAC8; border-radius: 999px; font-weight: 700; font-size: 0.8rem; padding: 8px 0;" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1"></i> Giỏ hàng' ?></button>
                </form>
                <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="buy_now" value="1">
                  <input type="hidden" name="product_id" value="<?= h($productId) ?>">
                  <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
                  <input type="hidden" name="quantity" value="1">
                  <input type="hidden" name="qty" value="1">
                  <button class="btn btn-sm btn-buy-now-pulse w-100 text-white" type="submit" style="background: linear-gradient(135deg, #215427 0%, #162F18 100%); border-radius: 999px; font-weight: 800; font-size: 0.8rem; padding: 8px 0; border: none; box-shadow: 0 4px 14px rgba(33, 84, 39, 0.25);" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Hết hàng' : '⚡ Mua ngay' ?></button>
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
            <a class="page-link rounded-circle d-grid place-items-center" href="<?= $paginationBase ?>&page=1&q=<?= urlencode($q) ?>&cap1=<?= urlencode($cap1) ?>&cap2=<?= urlencode($cap2) ?>" style="width: 40px; height: 40px; border-color: #E2EADF; color: #215427;">
              «
            </a>
          </li>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
          <li class="page-item <?= ($i === (int)$page) ? 'active' : '' ?>">
            <a class="page-link rounded-circle d-grid place-items-center fw-bold" href="<?= $paginationBase ?>&page=<?= $i ?>&q=<?= urlencode($q) ?>&cap1=<?= urlencode($cap1) ?>&cap2=<?= urlencode($cap2) ?>" style="width: 40px; height: 40px; border-color: #E2EADF; <?= ($i === (int)$page) ? 'background: #215427; color: #FFF; border-color: #215427;' : 'color: #1A2F1A;' ?>">
              <?= $i ?>
            </a>
          </li>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
          <li class="page-item">
            <a class="page-link rounded-circle d-grid place-items-center" href="<?= $paginationBase ?>&page=<?= $totalPages ?>&q=<?= urlencode($q) ?>&cap1=<?= urlencode($cap1) ?>&cap2=<?= urlencode($cap2) ?>" style="width: 40px; height: 40px; border-color: #E2EADF; color: #215427;">
              »
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </nav>
  <?php endif; ?>
</div>
