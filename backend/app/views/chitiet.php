<?php
function split_image_urls($str) {
    // Split by comma, semicolon, or whitespace, and trim each URL
    if (!is_string($str) || trim($str) === '') return [];
    $urls = preg_split('/[\s,;]+/', $str, -1, PREG_SPLIT_NO_EMPTY);
    return array_map('trim', $urls);
}
$imgs = split_image_urls($p['link_hinh_anh'] ?? '');
$main = $imgs[0] ?? 'https://via.placeholder.com/600x600?text=No+Image';
?>
<div class="container mt-4">
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
      </div>

      <div class="detail-tabs mt-3">
        <div class="box-text mb-3">
          <h5>Mô tả</h5>
          <div class="text-preline"><?= nl2br_safe($p['mo_ta'] ?? '') ?></div>
        </div>

        <div class="box-text mb-3">
          <h5>Thành phần chính</h5>
          <div class="text-preline"><?= nl2br_safe($p['thanh_phan_chinh'] ?? '') ?></div>
        </div>

        <div class="box-text mb-3">
          <h5>Thành phần đầy đủ</h5>
          <div class="text-preline"><?= nl2br_safe($p['thanh_phan_day_du'] ?? '') ?></div>
        </div>

        <div class="box-text">
          <h5>Hướng dẫn sử dụng</h5>
          <div class="text-preline"><?= nl2br_safe($p['hdsd'] ?? '') ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const main = document.getElementById('mainImage');
  const btns = document.querySelectorAll('.thumb-btn');
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
