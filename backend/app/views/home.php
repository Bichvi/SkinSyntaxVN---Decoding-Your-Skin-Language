<?php
// backend/app/views/home.php
?>
<div class="container mt-4">
  <div class="hero">
    <h1>Tra cứu thành phần mỹ phẩm</h1>
    <p>Hiểu làn da của bạn. Chọn đúng sản phẩm. Giảm mua nhầm.</p>
    <a class="btn btn-brand" href="<?= BASE_URL ?>/index.php?r=tatca">Khám phá sản phẩm</a>
  </div>

  <div class="mt-4">
    <div class="d-flex align-items-center justify-content-between">
      <h4 class="section-title mb-0">Danh mục nổi bật</h4>
    </div>
    <div class="category-wrap">
      <?php foreach (($cats ?? []) as $c): ?>
        <a class="category-pill"
           href="<?= BASE_URL ?>/index.php?r=tatca&danh_muc=<?= urlencode($c['danh_muc_day_du']) ?>">
          <?= h($c['danh_muc_day_du']) ?> (<?= (int)$c['so_luong'] ?>)
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="mt-4">
    <div class="d-flex align-items-center justify-content-between">
      <h4 class="section-title mb-0">Sản phẩm mới cập nhật</h4>
      <a class="link-more" href="<?= BASE_URL ?>/index.php?r=tatca">Xem tất cả</a>
    </div>

    <div class="row g-3 mt-2">
      <?php foreach (($latest ?? []) as $p):
        $img = first_image_url($p['link_hinh_anh']);
      ?>
        <div class="col-6 col-md-3">
          <a class="product-card" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= (int)$p['id'] ?>">
            <div class="product-thumb">
              <?php if (!empty($p['phan_tram_giam'])): ?>
                <span class="badge-sale">-<?= h($p['phan_tram_giam']) ?>%</span>
              <?php endif; ?>
              <img src="<?= h($img ?: 'https://via.placeholder.com/450x450?text=No+Image') ?>"
                   referrerpolicy="no-referrer"
                   onerror="this.src='https://via.placeholder.com/450x450?text=No+Image';"
                   alt="<?= h($p['ten_san_pham']) ?>">
            </div>
            <div class="product-meta">
              <div class="brand"><?= h($p['thuong_hieu'] ?? '') ?></div>
              <div class="name"><?= h($p['ten_san_pham']) ?></div>
              <div class="price"><?= vnd($p['gia_ban']) ?></div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
