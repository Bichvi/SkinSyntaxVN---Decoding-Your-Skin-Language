<?php
// backend/app/views/home.php
?>
<div class="container mt-4">
  <div class="mt-5">
    <div class="alert alert-info" role="alert">
      <div class="d-flex align-items-center gap-3">
        <div style="font-size: 2rem;">
          <i class="fas fa-wand-magic-sparkles"></i>
        </div>
        <div>
          <h5 class="mb-1">Cần gợi ý sản phẩm phù hợp?</h5>
          <p class="mb-0">Hãy cho chúng tôi biết loại da và vấn đề da của bạn để nhận được các gợi ý sản phẩm tốt nhất.</p>
        </div>
        <div class="ms-auto">
          <a href="<?= BASE_URL ?>/index.php?r=goiy" class="btn btn-info">
            Nhận gợi ý
          </a>
        </div>
      </div>
    </div>
  </div>

  <div class="hero">
    <h1>Tra cứu thành phần mỹ phẩm</h1>
    <p>Hiểu làn da của bạn. Chọn đúng sản phẩm. Giảm mua nhầm.</p>
    <a class="btn btn-brand" href="<?= BASE_URL ?>/index.php?r=tatca">Khám phá sản phẩm</a>
  </div>

  <div class="mt-4">
    <div class="mb-4">
      <h4 class="section-title mb-0">Danh mục nổi bật</h4>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Chăm+Sóc+Da+Mặt&cap2=Bộ+Chăm+Sóc+Da+Mặt" class="btn btn-sm btn-outline-secondary">Chăm sóc da</a>
      <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Chăm+Sóc+Da+Mặt&cap2=Làm+Sạch+Da" class="btn btn-sm btn-outline-secondary">Làm sạch da</a>
      <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Chăm+Sóc+Da+Mặt&cap2=Dưỡng+Ẩm" class="btn btn-sm btn-outline-secondary">Dưỡng ẩm</a>
      <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Chăm+Sóc+Da+Mặt&cap2=Chống+Nắng+Da+Mặt" class="btn btn-sm btn-outline-secondary">Chống nắng</a>
      <a href="<?= BASE_URL ?>/index.php?r=tatca&cap1=Chăm+Sóc+Da+Mặt&cap2=Đặc+Trị" class="btn btn-sm btn-outline-secondary">Đặc trị</a>
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

