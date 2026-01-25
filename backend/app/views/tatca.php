<?php
$totalPages = max(1, (int)ceil($total / $perPage));
?>
<div class="container mt-4">

  <div class="d-flex justify-content-between align-items-end mb-3">
    <div>
      <h3 class="mb-1">Tất cả sản phẩm</h3>
      <div class="text-muted">Tổng: <?= (int)$total ?> sản phẩm</div>
    </div>
  </div>

  <form class="row g-2 mb-3" method="get" action="<?= BASE_URL ?>/index.php">
    <input type="hidden" name="r" value="tatca">

    <div class="col-12 col-md-6">
      <input class="form-control" name="q" placeholder="Tìm theo tên, thương hiệu..." value="<?= h($q) ?>">
    </div>

    <div class="col-12 col-md-4">
      <input class="form-control" name="danh_muc" placeholder="Nhập đúng danh_muc_day_du (nếu cần)" value="<?= h($danh_muc ?? '') ?>">
    </div>

    <div class="col-12 col-md-2 d-grid">
      <button class="btn btn-success">Lọc</button>
    </div>
  </form>

  <div class="row g-3">
    <?php foreach ($items as $p):
      $img = first_image_url($p['link_hinh_anh'] ?? '');
    ?>
      <div class="col-6 col-md-3">
        <a class="product-card card h-100 text-decoration-none"
           href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= (int)$p['id'] ?>">
          <div class="ratio ratio-1x1 bg-light rounded-top overflow-hidden">
            <img src="<?= h($img ?: 'https://via.placeholder.com/450x450?text=No+Image') ?>"
                 class="w-100 h-100 object-fit-cover"
                 referrerpolicy="no-referrer"
                 onerror="this.src='https://via.placeholder.com/450x450?text=No+Image';"
                 alt="<?= h($p['ten_san_pham'] ?? '') ?>">
          </div>
          <div class="card-body">
            <?php if (!empty($p['phan_tram_giam'])): ?>
              <span class="badge badge-sale">-<?= h($p['phan_tram_giam']) ?>%</span>
            <?php endif; ?>

            <div class="brand"><?= h($p['thuong_hieu'] ?? '') ?></div>
            <div class="name"><?= h($p['ten_san_pham'] ?? '') ?></div>
            <div class="price mt-2"><?= vnd($p['gia_ban'] ?? 0) ?></div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <nav class="mt-4">
    <ul class="pagination justify-content-center">
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <li class="page-item <?= ($i === (int)$page) ? 'active' : '' ?>">
          <a class="page-link"
             href="<?= BASE_URL ?>/index.php?r=tatca&page=<?= $i ?>&q=<?= urlencode($q) ?>&danh_muc=<?= urlencode($danh_muc ?? '') ?>">
            <?= $i ?>
          </a>
        </li>
      <?php endfor; ?>
    </ul>
  </nav>
</div>
