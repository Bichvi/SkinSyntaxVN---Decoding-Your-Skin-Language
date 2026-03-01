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

      <?php
        $sanitizeIngredientText = function ($text) {
          $value = trim((string)$text);
          if ($value === '') {
            return '';
          }

          $value = preg_replace('/\r\n?|\n/u', "\n", $value);
          $value = preg_replace('/\n{3,}/u', "\n\n", $value);
          return trim($value);
        };

        $keepFirstProductBlockOnly = function ($text) {
          $value = trim((string)$text);
          if ($value === '') {
            return '';
          }

          if (preg_match('/\s+2\.\s+[\p{L}A-Z]/u', $value, $marker, PREG_OFFSET_CAPTURE)) {
            $nextProductPos = $marker[0][1];
            if ($nextProductPos > 0) {
              return trim(substr($value, 0, $nextProductPos));
            }
          }

          return $value;
        };

        $keepFirstFullIngredientOnly = function ($text) {
          $value = trim((string)$text);
          if ($value === '') {
            return '';
          }

          if (preg_match_all('/thành\s*phần\s*đầy\s*đủ\s*:?/iu', $value, $allMatches, PREG_OFFSET_CAPTURE) >= 2) {
            $secondPos = $allMatches[0][1][1];
            return trim(substr($value, 0, $secondPos));
          }

          if (preg_match('/\s+2\.\s+/u', $value, $marker, PREG_OFFSET_CAPTURE)) {
            $nextProductPos = $marker[0][1];
            if ($nextProductPos > 0) {
              return trim(substr($value, 0, $nextProductPos));
            }
          }

          return $value;
        };

        $thanhPhanChinhRaw = $p['thanh_phan_chinh'] ?? ($p['thanh_phan'] ?? '');
        $thanhPhanDayDuRaw = $p['thanh_phan_day_du'] ?? ( ($p['thanh_phan_full'] ?? ''));
        $moTaRaw = $p['mo_ta'] ?? '';

        $thanhPhanChinh = $sanitizeIngredientText($thanhPhanChinhRaw);
        $thanhPhanDayDu = $sanitizeIngredientText($keepFirstFullIngredientOnly($thanhPhanDayDuRaw));
        $moTa = $sanitizeIngredientText($keepFirstProductBlockOnly($moTaRaw));
      ?>
      <div class="detail-tabs mt-3">
        <ul class="nav nav-tabs" id="detailTabNav" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" type="button" data-tab="mo-ta">Mô tả</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" type="button" data-tab="thong-so">Thông số</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" type="button" data-tab="thanh-phan">Thành phần</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" type="button" data-tab="hdsd">HDSD</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" type="button" data-tab="danh-gia">Đánh giá</button>
          </li>
        </ul>

        <div class="box-text mb-3 tab-pane-content" data-pane="mo-ta">
          <h5>Mô tả</h5>
          <div class="text-preline"><?= nl2br_safe($moTa) ?></div>
        </div>

        <div class="box-text mb-3 tab-pane-content d-none" data-pane="thong-so">
          <h5>Thông số</h5>
          <div class="spec-grid">
            <div><b>Thương Hiệu</b><br><?= h($p['thuong_hieu'] ?? '') ?></div>
            <div><b>Xuất xứ thương hiệu</b><br><?= h($p['xuat_xu_thuong_hieu'] ?? '') ?></div>
            <div><b>Nơi sản xuất</b><br><?= h($p['noi_san_xuat'] ?? '') ?></div>
            <div><b>Loại da</b><br><?= h($p['loai_da'] ?? '') ?></div>
            <div><b>Dung Tích</b><br><?= h($p['dung_tich'] ?? '') ?></div>
          </div>
        </div>

        <div class="box-text mb-3 tab-pane-content d-none" data-pane="thanh-phan">
          <h5>Thành phần</h5>
          <?php if (!empty($thanhPhanChinh)): ?>
            <div class="mb-2"><b>Thành phần chính</b></div>
            <div class="text-preline mb-3"><?= nl2br_safe($thanhPhanChinh) ?></div>
          <?php endif; ?>
          <?php if (!empty($thanhPhanDayDu)): ?>
            <div class="mb-2"><b>Thành phần đầy đủ</b></div>
            <div class="text-preline"><?= nl2br_safe($thanhPhanDayDu) ?></div>
          <?php endif; ?>
          <?php if (empty($thanhPhanChinh) && empty($thanhPhanDayDu)): ?>
            <div class="text-muted">Chưa có thông tin thành phần.</div>
          <?php endif; ?>
        </div>

        <div class="box-text mb-3 tab-pane-content d-none" data-pane="hdsd">
          <h5>Hướng dẫn sử dụng</h5>
          <div class="text-preline"><?= nl2br_safe($p['hdsd'] ?? '') ?></div>
        </div>

        <div class="box-text tab-pane-content d-none" data-pane="danh-gia">
          <h5>Đánh giá</h5>
          <div>
            <b>Điểm đánh giá:</b> <?= h($p['diem_danh_gia'] ?? '') ?>
            (<?= h($p['so_luong_danh_gia'] ?? 0) ?> lượt)
          </div>
        </div>
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

(function(){
  const tabButtons = document.querySelectorAll('#detailTabNav .nav-link');
  const tabPanes = document.querySelectorAll('.tab-pane-content');
  if (!tabButtons.length || !tabPanes.length) return;

  tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.getAttribute('data-tab');
      tabButtons.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      tabPanes.forEach(pane => {
        pane.classList.toggle('d-none', pane.getAttribute('data-pane') !== target);
      });
    });
  });
})();
</script>
