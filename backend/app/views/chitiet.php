<?php
$p = $p ?? [];
$imgs = split_image_urls($p['link_hinh_anh'] ?? '', 10);
$main = $imgs[0] ?? default_placeholder_image();
$reviews = $reviews ?? [];
$reviewPermission = isset($reviewPermission) && is_array($reviewPermission) ? $reviewPermission : ['has_purchased' => false, 'has_reviewed' => false];
$activeTab = trim((string)($activeTab ?? ''));
$phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
?>
<style>
  .detail-tabs {
    margin-top: 20px;
  }

  #detailTabNav {
    gap: 0;
    border-bottom: 1px solid #dbe4ee;
    margin-bottom: 0;
    flex-wrap: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
  }

  #detailTabNav .nav-item {
    flex: 0 0 auto;
  }

  #detailTabNav .nav-link {
    position: relative;
    border: 0;
    border-radius: 16px 16px 0 0;
    color: #46627f;
    font-weight: 700;
    font-size: 1.02rem;
    padding: 14px 20px 13px;
    background: transparent;
    transition: color .18s ease, background .18s ease;
  }

  #detailTabNav .nav-link:hover {
    color: #1d4f7a;
    background: rgba(236, 244, 252, 0.85);
  }

  #detailTabNav .nav-link.active {
    color: #1670b8;
    background: linear-gradient(180deg, #f5faff 0%, #ffffff 100%);
  }

  #detailTabNav .nav-link.active::after {
    content: '';
    position: absolute;
    left: 16px;
    right: 16px;
    bottom: 0;
    height: 3px;
    border-radius: 999px;
    background: linear-gradient(90deg, #1f8fff 0%, #33c39f 100%);
  }

  .detail-content-panel {
    border: 1px solid #dfe7f0;
    border-radius: 24px;
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    box-shadow: 0 16px 34px rgba(15, 23, 42, 0.05);
    overflow: hidden;
  }

  .detail-content-panel__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    padding: 22px 24px 18px;
    border-bottom: 1px solid #e8eef5;
    background: linear-gradient(135deg, #f8fbff 0%, #ffffff 100%);
    flex-wrap: wrap;
  }

  .detail-content-panel__eyebrow {
    display: inline-flex;
    align-items: center;
    min-height: 30px;
    padding: 0 12px;
    border-radius: 999px;
    background: #eef6ff;
    color: #1d6cb1;
    font-size: 0.78rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    margin-bottom: 10px;
  }

  .detail-content-panel__title {
    margin: 0;
    font-size: 1.7rem;
    line-height: 1.2;
    font-weight: 800;
    color: #1b2c40;
  }

  .detail-content-panel__subtitle {
    margin: 10px 0 0;
    max-width: 720px;
    color: #66778a;
    font-size: 0.98rem;
    line-height: 1.7;
  }

  .detail-content-panel__body {
    padding: 24px;
  }

  .detail-rich-copy {
    display: grid;
    gap: 16px;
    color: #334155;
  }

  .detail-rich-copy__lead {
    margin: 0;
    padding: 18px 20px;
    border-radius: 20px;
    background: linear-gradient(135deg, #f6fbff 0%, #ffffff 100%);
    border: 1px solid #dce8f4;
    color: #27415d;
    font-size: 1.04rem;
    line-height: 1.9;
  }

  .detail-rich-copy__paragraph {
    margin: 0;
    color: #445468;
    font-size: 1rem;
    line-height: 1.95;
  }

  .detail-rich-copy__list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 12px;
  }

  .detail-rich-copy__list li {
    position: relative;
    margin: 0;
    padding: 0 0 0 22px;
    color: #425466;
    line-height: 1.9;
  }

  .detail-rich-copy__list li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.78em;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1f8fff 0%, #37caa0 100%);
    box-shadow: 0 0 0 4px rgba(31, 143, 255, 0.08);
  }

  .detail-rich-copy__ordered {
    counter-reset: detail-step;
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 14px;
  }

  .detail-rich-copy__ordered li {
    position: relative;
    padding: 0 0 0 48px;
    line-height: 1.9;
    color: #425466;
  }

  .detail-rich-copy__ordered li::before {
    counter-increment: detail-step;
    content: counter(detail-step);
    position: absolute;
    left: 0;
    top: 2px;
    width: 32px;
    height: 32px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: linear-gradient(135deg, #edf7ff 0%, #eefaf4 100%);
    border: 1px solid #d6e8f7;
    color: #1f6fad;
    font-weight: 800;
    font-size: 0.95rem;
  }

  .detail-section-grid {
    display: grid;
    gap: 16px;
  }

  .detail-section-card {
    border-radius: 20px;
    border: 1px solid #e2eaf2;
    background: #ffffff;
    padding: 20px;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
  }

  .detail-section-card--accent {
    background: linear-gradient(135deg, #f8fbff 0%, #ffffff 100%);
    border-color: #d8e8f6;
  }

  .detail-section-card__label {
    display: inline-flex;
    align-items: center;
    min-height: 30px;
    padding: 0 11px;
    border-radius: 999px;
    background: #eff7f0;
    color: #1f7a53;
    font-size: 0.78rem;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    margin-bottom: 12px;
  }

  .detail-section-card__title {
    margin: 0 0 10px;
    color: #203348;
    font-size: 1.08rem;
    font-weight: 800;
  }

  .detail-empty-state {
    padding: 22px;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px dashed #d6e2ec;
    color: #64748b;
    line-height: 1.8;
  }

  .review-panel {
    border-radius: 20px;
    border: 1px solid #e5edf5;
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    padding: 22px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
  }

  .review-summary-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 18px;
    padding: 18px 20px;
    border-radius: 18px;
    background: linear-gradient(135deg, #f6fbff 0%, #ffffff 100%);
    border: 1px solid #dbe9f6;
  }

  .review-summary-card__label {
    margin: 0 0 4px;
    color: #64748b;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.01em;
    text-transform: uppercase;
  }

  .review-summary-card__score {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
  }

  .review-summary-card__score-value {
    font-size: 2rem;
    line-height: 1;
    font-weight: 800;
    color: #13283d;
  }

  .review-summary-card__count {
    color: #64748b;
    font-size: 0.92rem;
  }

  .review-form-shell {
    margin-bottom: 20px;
    padding: 18px;
    border-radius: 18px;
    border: 1px solid #e5ecef;
    background: #ffffff;
  }

  .review-form-grid {
    display: grid;
    gap: 16px;
  }

  .review-form-top {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
  }

  .review-form-stars {
    min-width: 220px;
  }

  .review-form-stars .form-label,
  .review-form-message .form-label {
    margin-bottom: 8px;
    font-weight: 700;
    color: #334155;
  }

  .review-stars-input {
    display: inline-flex;
    flex-direction: row-reverse;
    gap: 0.45rem;
  }

  .review-stars-input input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
  }

  .review-stars-input label {
    width: 2.4rem;
    height: 2.4rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    cursor: pointer;
    color: #cbd5e1;
    background: #f8fafc;
    border: 1px solid #e6edf5;
    transition: transform .18s ease, color .18s ease, background .18s ease, border-color .18s ease, box-shadow .18s ease;
    font-size: 1.18rem;
  }

  .review-stars-input label:hover,
  .review-stars-input label:hover ~ label,
  .review-stars-input input:checked ~ label {
    color: #f59e0b;
    background: #fff7e6;
    border-color: #ffd27a;
    box-shadow: 0 6px 12px rgba(245, 158, 11, 0.12);
  }

  .review-stars-input label:hover {
    transform: translateY(-1px);
  }

  .review-stars-display {
    display: inline-flex;
    gap: 0.18rem;
    color: #f59e0b;
    vertical-align: middle;
    flex-wrap: wrap;
  }

  .review-stars-display i.is-empty {
    color: #d5dde8;
  }

  .review-summary-line {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }

  .review-item {
    border: 1px solid #e5ecef;
    border-radius: 16px;
    padding: 16px;
    background: #fff;
  }

  .review-item__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
    flex-wrap: wrap;
  }

  .review-item__author {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    min-width: 0;
  }

  .review-item__name {
    font-weight: 700;
    color: #16324d;
  }

  .review-item__date {
    color: #64748b;
    font-size: 0.84rem;
    white-space: nowrap;
  }

  .review-item__content {
    color: #334155;
    line-height: 1.7;
    word-break: break-word;
    overflow-wrap: anywhere;
  }

  .review-item__reply {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #e5ecef;
  }

  .review-item__reply-box {
    background: #f8fafc;
    border-radius: 14px;
    padding: 12px 14px;
    color: #334155;
    line-height: 1.7;
    word-break: break-word;
    overflow-wrap: anywhere;
  }

  @media (max-width: 767.98px) {
    #detailTabNav .nav-link {
      font-size: 0.96rem;
      padding: 12px 16px;
    }

    .detail-content-panel {
      border-radius: 18px;
    }

    .detail-content-panel__head,
    .detail-content-panel__body,
    .detail-section-card {
      padding: 16px;
    }

    .detail-content-panel__title {
      font-size: 1.4rem;
    }

    .detail-rich-copy__lead {
      padding: 14px 16px;
      font-size: 0.98rem;
    }

    .review-panel {
      padding: 16px;
    }

    .review-summary-card {
      padding: 16px;
    }

    .review-summary-card__score-value {
      font-size: 1.6rem;
    }

    .review-form-shell {
      padding: 16px;
    }

    .review-form-top {
      align-items: stretch;
    }

    .review-stars-input {
      gap: 0.3rem;
    }

    .review-stars-input label {
      width: 2.15rem;
      height: 2.15rem;
      font-size: 1rem;
    }
  }
</style>
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
             onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';"
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
                   onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';"
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
          <span class="current"><?= vnd($p['gia_ban'] ?? 0) ?></span>
          <?php $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? '')); ?>
          <?php if ($giaThiTruong !== '' && (float)$giaThiTruong > 0): ?>
            <span class="market"><?= vnd($giaThiTruong) ?></span>
          <?php endif; ?>
          <?php if ($phanTramGiam !== null): ?>
            <span class="sale ms-2">-<?= h((string)$phanTramGiam) ?>%</span>
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
        $hdsd = $sanitizeIngredientText($p['hdsd'] ?? '');

        $renderRichTextBlocks = function ($text, string $mode = 'default') {
          $value = trim((string)$text);
          if ($value === '') {
            return '';
          }

          $blocks = preg_split('/\n\s*\n/u', $value) ?: [];
          $html = [];
          $hasLead = false;

          foreach ($blocks as $block) {
            $block = trim((string)$block);
            if ($block === '') {
              continue;
            }

            $lines = array_values(array_filter(array_map('trim', preg_split('/\n/u', $block) ?: []), static function ($line) {
              return $line !== '';
            }));

            if (empty($lines)) {
              continue;
            }

            $isBulletList = count(array_filter($lines, static function ($line) {
              return preg_match('/^(?:[-*•]|–)\s*/u', $line) === 1;
            })) === count($lines);

            $isOrderedList = count(array_filter($lines, static function ($line) {
              return preg_match('/^(?:\d+[\.)]|bước\s*\d+)\s*/iu', $line) === 1;
            })) === count($lines);

            if ($isOrderedList) {
              $items = array_map(static function ($line) {
                $clean = preg_replace('/^(?:\d+[\.)]|bước\s*\d+)\s*/iu', '', $line);
                return '<li>' . nl2br_safe(trim((string)$clean)) . '</li>';
              }, $lines);
              $html[] = '<ol class="detail-rich-copy__ordered">' . implode('', $items) . '</ol>';
              continue;
            }

            if ($isBulletList) {
              $items = array_map(static function ($line) {
                $clean = preg_replace('/^(?:[-*•]|–)\s*/u', '', $line);
                return '<li>' . nl2br_safe(trim((string)$clean)) . '</li>';
              }, $lines);
              $html[] = '<ul class="detail-rich-copy__list">' . implode('', $items) . '</ul>';
              continue;
            }

            $content = nl2br_safe(implode("\n", $lines));
            if (!$hasLead && $mode === 'lead-first') {
              $html[] = '<p class="detail-rich-copy__lead">' . $content . '</p>';
              $hasLead = true;
            } else {
              $html[] = '<p class="detail-rich-copy__paragraph">' . $content . '</p>';
            }
          }

          return implode('', $html);
        };

        $moTaHtml = $renderRichTextBlocks($moTa, 'lead-first');
        $thanhPhanChinhHtml = $renderRichTextBlocks($thanhPhanChinh);
        $thanhPhanDayDuHtml = $renderRichTextBlocks($thanhPhanDayDu);
        $hdsdHtml = $renderRichTextBlocks($hdsd, 'lead-first');
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
          <div class="detail-content-panel">
            <div class="detail-content-panel__head">
              <div>
                <div class="detail-content-panel__eyebrow">Mô tả sản phẩm</div>
                <h5 class="detail-content-panel__title">Thông tin nổi bật</h5>
              </div>
            </div>
            <div class="detail-content-panel__body">
              <?php if ($moTaHtml !== ''): ?>
                <div class="detail-rich-copy"><?= $moTaHtml ?></div>
              <?php else: ?>
                <div class="detail-empty-state">Chưa có mô tả chi tiết cho sản phẩm này.</div>
              <?php endif; ?>
            </div>
          </div>
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
          <div class="detail-content-panel">
            <div class="detail-content-panel__head">
              <div>
                <div class="detail-content-panel__eyebrow">Ingredients</div>
                <h5 class="detail-content-panel__title">Thành phần & nền công thức</h5>
              </div>
            </div>
            <div class="detail-content-panel__body">
              <?php if (!empty($thanhPhanChinh) || !empty($thanhPhanDayDu)): ?>
                <div class="detail-section-grid">
                  <?php if (!empty($thanhPhanChinh)): ?>
                    <section class="detail-section-card detail-section-card--accent">
                      <div class="detail-section-card__label">Thành phần chính</div>
                      <h6 class="detail-section-card__title">Nhóm hoạt chất nổi bật</h6>
                      <div class="detail-rich-copy"><?= $thanhPhanChinhHtml ?></div>
                    </section>
                  <?php endif; ?>
                  <?php if (!empty($thanhPhanDayDu)): ?>
                    <section class="detail-section-card">
                      <div class="detail-section-card__label">Thành phần đầy đủ</div>
                      <h6 class="detail-section-card__title">Bảng thành phần công bố</h6>
                      <div class="detail-rich-copy"><?= $thanhPhanDayDuHtml ?></div>
                    </section>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="detail-empty-state">Chưa có thông tin thành phần cho sản phẩm này.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="box-text mb-3 tab-pane-content d-none" data-pane="hdsd">
          <div class="detail-content-panel">
            <div class="detail-content-panel__head">
              <div>
                <div class="detail-content-panel__eyebrow">How To Use</div>
                <h5 class="detail-content-panel__title">Hướng dẫn sử dụng</h5>
              </div>
            </div>
            <div class="detail-content-panel__body">
              <?php if ($hdsdHtml !== ''): ?>
                <div class="detail-rich-copy"><?= $hdsdHtml ?></div>
              <?php else: ?>
                <div class="detail-empty-state">Chưa có hướng dẫn sử dụng chi tiết cho sản phẩm này.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="box-text tab-pane-content d-none" data-pane="danh-gia">
          <h5>Đánh giá</h5>
          <div class="review-panel">
            <div class="review-summary-card">
              <div>
                <div class="review-summary-card__label">Điểm đánh giá</div>
                <div class="review-summary-card__score">
                  <div class="review-summary-card__score-value"><?= h($p['diem_danh_gia'] ?? '') ?></div>
                  <div class="review-summary-card__count">(<?= h($p['so_luong_danh_gia'] ?? 0) ?> lượt)</div>
                </div>
              </div>
              <span class="order-status-note">Chia sẻ trải nghiệm thực tế sau khi sử dụng</span>
            </div>

            <?php if (is_logged_in() && !empty($reviewPermission['has_purchased']) && empty($reviewPermission['has_reviewed'])): ?>
              <form method="post" action="<?= BASE_URL ?>/index.php?r=guidanhgia" class="review-form-shell">
                <input type="hidden" name="ma_san_pham" value="<?= h($p['ma_san_pham'] ?? $p['id'] ?? '') ?>">
                <div class="review-form-grid">
                  <div class="review-form-top">
                    <div class="review-form-stars">
                      <label class="form-label">Số sao</label>
                      <div class="review-stars-input" aria-label="Chọn số sao">
                        <?php for ($star = 5; $star >= 1; $star--): ?>
                          <input type="radio" id="reviewStar<?= $star ?>" name="so_sao" value="<?= $star ?>" <?= $star === 5 ? 'checked' : '' ?>>
                          <label for="reviewStar<?= $star ?>" title="<?= $star ?> sao"><i class="fa-solid fa-star"></i></label>
                        <?php endfor; ?>
                      </div>
                    </div>
                    <span class="order-status-note">Đánh giá xong sẽ nhận thêm 1 điểm</span>
                  </div>
                  <div class="review-form-message">
                    <label class="form-label">Nội dung đánh giá</label>
                    <textarea class="form-control" name="noi_dung" rows="3" placeholder="Chia sẻ cảm nhận của bạn về sản phẩm này..." required></textarea>
                  </div>
                  <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-brand px-4">Gửi đánh giá</button>
                  </div>
                </div>
              </form>
            <?php elseif (is_logged_in() && !empty($reviewPermission['has_reviewed'])): ?>
              <div class="alert alert-success">Bạn đã đánh giá sản phẩm này rồi.</div>
            <?php elseif (is_logged_in()): ?>
              <div class="alert alert-info">Bạn chỉ có thể đánh giá sản phẩm sau khi đã mua sản phẩm này.</div>
            <?php else: ?>
              <div class="alert alert-info">Đăng nhập để gửi đánh giá sản phẩm.</div>
            <?php endif; ?>

            <?php if (empty($reviews)): ?>
              <div class="text-muted">Chưa có đánh giá nào cho sản phẩm này.</div>
            <?php else: ?>
              <div class="d-flex flex-column gap-3">
                <?php foreach ($reviews as $review): ?>
                  <div class="review-item">
                    <div class="review-item__head">
                      <div class="review-item__author">
                        <div class="review-item__name"><?= h($review['ten_khach_hang'] ?? 'Khách hàng') ?></div>
                        <?php $reviewStars = max(0, min(5, (int)($review['so_sao'] ?? 0))); ?>
                        <span class="review-stars-display" aria-label="<?= $reviewStars ?> sao">
                          <?php for ($star = 1; $star <= 5; $star++): ?>
                            <i class="fa-solid fa-star <?= $star > $reviewStars ? 'is-empty' : '' ?>"></i>
                          <?php endfor; ?>
                        </span>
                      </div>
                      <div class="review-item__date"><?= h(!empty($review['ngay_danh_gia']) ? date('d/m/Y H:i', strtotime((string)$review['ngay_danh_gia'])) : '') ?></div>
                    </div>
                    <div class="review-item__content"><?= nl2br_safe($review['noi_dung'] ?? '') ?></div>
                    <?php if (!empty($review['phan_hoi'])): ?>
                      <div class="review-item__reply">
                        <div class="small text-muted mb-1">Phản hồi từ nhân viên hỗ trợ</div>
                        <div class="review-item__reply-box"><?= nl2br_safe($review['phan_hoi'] ?? '') ?></div>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
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

  const activateTab = function (target) {
    if (!target) return;
    tabButtons.forEach(btn => {
      btn.classList.toggle('active', btn.getAttribute('data-tab') === target);
    });

    tabPanes.forEach(pane => {
      pane.classList.toggle('d-none', pane.getAttribute('data-pane') !== target);
    });
  };

  tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.getAttribute('data-tab');
      activateTab(target);
    });
  });

  const params = new URLSearchParams(window.location.search);
  const initialTab = params.get('tab') || <?= json_encode($activeTab !== '' ? $activeTab : '') ?>;
  if (initialTab) {
    activateTab(initialTab);
  }
})();
</script>
