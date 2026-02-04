<?php
$imgs = split_image_urls($p['link_hinh_anh'] ?? '', 10);
$main = $imgs[0] ?? 'https://via.placeholder.com/600x600?text=No+Image';
?>
<div class="container mt-4">
  <div class="mb-3">
    <a href="javascript:history.back()" class="btn btn-sm btn-outline-secondary">
      <i class="fas fa-arrow-left"></i> Quay lại
    </a>
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-5">
      <div class="detail-img">
        <img id="mainImage"
             class="detail-main-img"
             src="<?= h($main) ?>"
             referrerpolicy="no-referrer"
             onerror="this.src='https://via.placeholder.com/600x600?text=No+Image';"
             alt="<?= h($p['ten_san_pham'] ?? '') ?>">
      </div>

      <?php if (count($imgs) > 1): ?>
        <div class="detail-thumbs mt-2">
          <?php foreach ($imgs as $i => $url): ?>
            <button type="button"
                    class="thumb-btn <?= $i===0?'active':'' ?>"
                    data-src="<?= h($url) ?>">
              <img src="<?= h($url) ?>"
                   referrerpolicy="no-referrer"
                   onerror="this.src='https://via.placeholder.com/80x80?text=No';"
                   alt="thumb">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-12 col-lg-7">
      <div class="detail-box">
        <div class="text-muted small"><?= h($p['thuong_hieu'] ?? '') ?></div>
        <h3 class="detail-title"><?= h($p['ten_san_pham'] ?? '') ?></h3>

        <div class="detail-price">
          <?= vnd($p['gia_ban'] ?? 0) ?>
          <?php if (!empty($p['phan_tram_giam'])): ?>
            <span class="sale ms-2">-<?= h($p['phan_tram_giam']) ?>%</span>
          <?php endif; ?>
        </div>

        <hr>

        <div class="spec-grid">
          <div><b>Danh mục:</b> <?= h($p['danh_muc_day_du'] ?? '') ?></div>
          <div><b>Loại sản phẩm:</b> <?= h($p['loai_san_pham'] ?? '') ?></div>
          <div><b>Xuất xứ:</b> <?= h($p['xuat_xu_thuong_hieu'] ?? '') ?></div>
          <div><b>Dung tích:</b> <?= h($p['dung_tich'] ?? '') ?></div>
          <div><b>Loại da:</b> <?= h($p['loai_da'] ?? '') ?></div>
          <div><b>Đánh giá:</b> <?= h($p['diem_danh_gia'] ?? '') ?> (<?= h($p['so_luong_danh_gia'] ?? '') ?>)</div>
        </div>

        <div class="mt-4 d-flex gap-2">
          <div style="width: 100px;">
            <input type="number" class="form-control" value="1" min="1" max="999">
          </div>
          <form method="post" class="flex-grow-1">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="qty" value="1" class="qty-input">
            <button type="submit" class="btn btn-brand w-100">
              <i class="fas fa-shopping-cart"></i> Thêm vào giỏ hàng
            </button>
          </form>
        </div>
        <script>
          const qtyInput = document.querySelector('.form-control[type="number"]');
          const hiddenQty = document.querySelector('input[name="qty"]');
          if (qtyInput && hiddenQty) {
            qtyInput.addEventListener('change', () => {
              hiddenQty.value = qtyInput.value;
            });
          }
        </script>
      </div>

      <div class="detail-tabs mt-3">
        <div class="box-text mb-3">
          <h5>Mô tả</h5>
          <div class="text-preline"><?= nl2br_safe($p['mo_ta'] ?? '') ?></div>
        </div>

        <?php if (!empty($p['thanh_phan_chinh'])): ?>
        <div class="box-text mb-3">
          <h5>Thành phần chính</h5>
          <div class="text-preline"><?= nl2br_safe($p['thanh_phan_chinh']) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($p['thanh_phan_day_du'])): ?>
        <div class="box-text mb-3">
          <h5>Thành phần đầy đủ</h5>
          <div class="text-preline"><?= nl2br_safe($p['thanh_phan_day_du']) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($p['hdsd'])): ?>
        <div class="box-text">
          <h5>Hướng dẫn sử dụng</h5>
          <div class="text-preline"><?= nl2br_safe($p['hdsd']) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const main = document.getElementById('mainImage');
  const btns = document.querySelectorAll('.thumb-btn');
  if (!main || !btns.length) return;

  btns.forEach(btn => {
    btn.addEventListener('click', () => {
      const src = btn.getAttribute('data-src');
      if (!src) return;
      main.src = src;

      btns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    });
  });
})();
</script>
