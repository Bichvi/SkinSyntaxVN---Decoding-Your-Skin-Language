<?php
// backend/app/views/tatca.php
$total = $total ?? 0;
$perPage = $perPage ?? 24;
$page = $page ?? 1;
$q = $q ?? '';
$cap1 = $cap1 ?? '';
$cap2 = $cap2 ?? '';
$items = $items ?? [];

$totalPages = max(1, (int)ceil($total / $perPage));

// Giới hạn hiển thị 10 trang tối đa, xoay quanh trang hiện tại
$maxVisible = 10;
$startPage = max(1, (int)$page - (int)floor($maxVisible / 2));
$endPage = min($totalPages, $startPage + $maxVisible - 1);
$startPage = max(1, $endPage - $maxVisible + 1);
?>
<div class="container mt-4">

  <div class="d-flex justify-content-between align-items-end mb-3">
    <div>
      <h3 class="mb-1">Tất cả sản phẩm</h3>
      <div class="text-muted">Tổng: <?= (int)$total ?> sản phẩm</div>
      <?php if (!empty($cap1) || !empty($cap2)): ?>
        <div class="text-muted small">
          Đang lọc:
          <b><?= h($cap1) ?></b>
          <?= $cap2 ? ' / <b>'.h($cap2).'</b>' : '' ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <form class="row g-2 mb-3" method="get" action="<?= BASE_URL ?>/index.php">
    <input type="hidden" name="r" value="tatca">
    <?php if ($cap1): ?><input type="hidden" name="cap1" value="<?= h($cap1) ?>"><?php endif; ?>
    <?php if ($cap2): ?><input type="hidden" name="cap2" value="<?= h($cap2) ?>"><?php endif; ?>

    <!-- <div class="col-12 col-md-8">
      <input class="form-control" name="q" placeholder="Tìm tên, thương hiệu, danh mục, thành phần..." value="<?= h($q) ?>">
    </div>
    <div class="col-12 col-md-2 d-grid">
      <button class="btn btn-success">Lọc</button>
    </div>
    <div class="col-12 col-md-2 d-grid">
      <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/index.php?r=tatca">Reset</a>
    </div> -->
  </form>

  <?php if (empty($items)): ?>
    <div class="alert alert-warning">
      Không tìm thấy sản phẩm phù hợp
      <?php if ($q !== ''): ?>
        với từ khóa <b><?= h($q) ?></b>
      <?php endif; ?>.
    </div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($items as $p):
        $img = resolve_image_url((string)($p['link_hinh_anh'] ?? ''));
        $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
        $phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
      ?>
        <div class="col-6 col-md-3">
          <a class="product-card" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= (int)$p['id'] ?>">
            <div class="product-thumb">
              <?php if ($phanTramGiam !== null): ?>
                <span class="badge-sale">-<?= h((string)$phanTramGiam) ?>%</span>
              <?php endif; ?>
              <img
                src="<?= h($img ?: 'https://via.placeholder.com/450x450?text=No+Image') ?>"
                referrerpolicy="no-referrer"
                onerror="this.src='https://via.placeholder.com/450x450?text=No+Image';"
                alt="<?= h($p['ten_san_pham'] ?? '') ?>"
              >
            </div>
            <div class="product-meta">
              <div class="brand"><?= h($p['thuong_hieu'] ?? '') ?></div>
              <div class="name"><?= h($p['ten_san_pham'] ?? '') ?></div>
              <div class="price-wrap price-wrap--inline">
                <div class="price"><?= vnd($p['gia_ban'] ?? 0) ?></div>
                <?php if ($giaThiTruong !== '' && (float)$giaThiTruong > 0): ?>
                  <div class="price-market"><?= vnd($giaThiTruong) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <nav class="mt-4">
    <ul class="pagination justify-content-center">
      <!-- Nút trang đầu -->
      <?php if ($page > 1): ?>
        <li class="page-item">
          <a class="page-link" href="<?= BASE_URL ?>/index.php?r=tatca&page=1&q=<?= urlencode($q) ?>&cap1=<?= urlencode($cap1) ?>&cap2=<?= urlencode($cap2) ?>">
            « Đầu
          </a>
        </li>
      <?php endif; ?>

      <!-- Các trang số -->
      <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
        <li class="page-item <?= ($i === (int)$page) ? 'active' : '' ?>">
          <a class="page-link"
             href="<?= BASE_URL ?>/index.php?r=tatca&page=<?= $i ?>&q=<?= urlencode($q) ?>&cap1=<?= urlencode($cap1) ?>&cap2=<?= urlencode($cap2) ?>">
            <?= $i ?>
          </a>
        </li>
      <?php endfor; ?>

      <!-- Nút trang cuối -->
      <?php if ($page < $totalPages): ?>
        <li class="page-item">
          <a class="page-link" href="<?= BASE_URL ?>/index.php?r=tatca&page=<?= $totalPages ?>&q=<?= urlencode($q) ?>&cap1=<?= urlencode($cap1) ?>&cap2=<?= urlencode($cap2) ?>">
            Cuối »
          </a>
        </li>
      <?php endif; ?>
    </ul>
  </nav>

</div>
