<?php
$p = $p ?? [];
$imgs = split_image_urls($p['link_hinh_anh'] ?? '', 10);
$main = $imgs[0] ?? 'https://via.placeholder.com/600x600?text=No+Image';
$reviews = $reviews ?? [];
$reviewPermission = isset($reviewPermission) && is_array($reviewPermission) ? $reviewPermission : ['has_purchased' => false, 'has_reviewed' => false];
$activeTab = trim((string)($activeTab ?? ''));
$detailUnavailableMessage = trim((string)($detailUnavailableMessage ?? ''));
$reviewErrorMessage = trim((string)($reviewErrorMessage ?? ''));
$questions = $questions ?? [];
$questionCount = (int)($questionCount ?? count($questions));
$questionErrorMessage = trim((string)($questionErrorMessage ?? ''));
$reviewStats = isset($reviewStats) && is_array($reviewStats) ? $reviewStats : [];
$reviewStats['stars'] = isset($reviewStats['stars']) && is_array($reviewStats['stars']) ? $reviewStats['stars'] : [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
$reviewCount = (int)($reviewStats['total'] ?? count($reviews));
$reviewAverage = (float)($reviewStats['average'] ?? 0);
$reviewCountDisplay = $reviewCount > 0 ? $reviewCount : (int)($p['so_luong_danh_gia'] ?? 0);
$userReviewCount = (int)($reviewStats['user_review_count'] ?? count($reviews));
$crawlReviewCount = (int)($reviewStats['crawl_review_count'] ?? max(0, $reviewCountDisplay - $userReviewCount));
$activeReviewStar = max(0, min(5, (int)($_GET['review_star'] ?? 0)));
$activeReviewFilter = trim((string)($_GET['review_filter'] ?? ''));
$productIdForForms = (string)($p['ma_san_pham'] ?? $p['id'] ?? '');
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

  .review-item__badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 5px;
  }

  .review-item__badge {
    border-radius: 999px;
    background: #e7f5ef;
    color: #0f7b55;
    font-size: 0.78rem;
    font-weight: 800;
    padding: 4px 8px;
  }

  .review-item__images {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
  }

  .review-item__images img {
    width: 72px;
    height: 72px;
    border-radius: 10px;
    object-fit: cover;
    border: 1px solid #dfe8ef;
  }


  .purchase-policy-card { margin-top: 16px; border: 1px solid #dfece8; border-radius: 20px; background: linear-gradient(180deg, #ffffff 0%, #f8fffb 100%); padding: 16px; box-shadow: 0 12px 24px rgba(15, 107, 62, 0.06); }
  .purchase-policy-card__title { margin: 0 0 12px; font-size: 1rem; font-weight: 800; color: #123044; }
  .purchase-policy-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
  .purchase-policy-item { display: grid; grid-template-columns: 34px minmax(0, 1fr); gap: 10px; align-items: start; padding: 10px; border-radius: 14px; background: #ffffff; border: 1px solid #e3eee9; }
  .purchase-policy-item__icon { width: 34px; height: 34px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; background: #eaf8f2; color: #0f8d63; }
  .purchase-policy-item__title { font-weight: 800; color: #1d3447; font-size: 0.9rem; margin-bottom: 2px; }
  .purchase-policy-item__text { margin: 0; color: #64748b; font-size: 0.82rem; line-height: 1.45; }
  .review-breakdown { min-width: 260px; flex: 1 1 320px; display: grid; gap: 8px; }
  .review-breakdown__row { display: grid; grid-template-columns: 48px minmax(0, 1fr) 34px; gap: 8px; align-items: center; color: #475569; font-size: 0.9rem; }
  .review-breakdown__bar { height: 8px; border-radius: 999px; background: #e7edf4; overflow: hidden; }
  .review-breakdown__fill { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #f59e0b 0%, #f97316 100%); }
  .review-filter-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 18px 0; }
  .review-filter-tabs a { border: 1px solid #d8e4ea; border-radius: 999px; padding: 8px 14px; color: #385268; background: #fff; text-decoration: none; font-weight: 700; font-size: 0.92rem; }
  .review-filter-tabs a.is-active { background: #0f8d63; color: #fff; border-color: #0f8d63; }
  .review-upload-box { border: 1px dashed #bdd6cc; background: #fbfffd; border-radius: 14px; padding: 12px; }
  .qa-panel { border-radius: 20px; border: 1px solid #e5edf5; background: #fff; padding: 22px; }
  .qa-form { display: grid; gap: 10px; margin-bottom: 18px; padding: 16px; border-radius: 16px; background: #f8fbff; border: 1px solid #dde9f4; }
  .qa-item { border: 1px solid #e5ecef; border-radius: 16px; padding: 16px; background: #fff; }
  .qa-item + .qa-item { margin-top: 12px; }
  .qa-item__meta { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; color: #64748b; font-size: 0.86rem; margin-bottom: 8px; }
  .qa-answer { margin-top: 12px; border-radius: 14px; background: #eef8f3; padding: 12px 14px; color: #26453a; line-height: 1.65; }

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
  <?php if ($detailUnavailableMessage !== ''): ?>
    <div class="alert alert-warning border-0 shadow-sm"><?= h($detailUnavailableMessage) ?></div>
  <?php endif; ?>

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

      <div class="purchase-policy-card">
        <h6 class="purchase-policy-card__title">Quyền lợi mua hàng</h6>
        <div class="purchase-policy-grid">
          <div class="purchase-policy-item"><span class="purchase-policy-item__icon"><i class="fa-solid fa-truck"></i></span><div><div class="purchase-policy-item__title">Miễn phí vận chuyển</div><p class="purchase-policy-item__text">Cho đơn từ 300.000đ hoặc theo chính sách hiện tại.</p></div></div>
          <div class="purchase-policy-item"><span class="purchase-policy-item__icon"><i class="fa-solid fa-bolt"></i></span><div><div class="purchase-policy-item__title">Giao nhanh 2H</div><p class="purchase-policy-item__text">Nội thành, trễ tặng voucher nếu áp dụng.</p></div></div>
          <div class="purchase-policy-item"><span class="purchase-policy-item__icon"><i class="fa-solid fa-shield-heart"></i></span><div><div class="purchase-policy-item__title">Cam kết chính hãng</div><p class="purchase-policy-item__text">Đền bù 100% nếu phát hiện hàng giả.</p></div></div>
          <div class="purchase-policy-item"><span class="purchase-policy-item__icon"><i class="fa-solid fa-rotate-left"></i></span><div><div class="purchase-policy-item__title">Đổi trả 30 ngày</div><p class="purchase-policy-item__text">Hỗ trợ nếu sản phẩm lỗi. <a href="<?= BASE_URL ?>/index.php?r=bao_hanh">Xem thêm</a></p></div></div>
          <div class="purchase-policy-item"><span class="purchase-policy-item__icon"><i class="fa-solid fa-user-doctor"></i></span><div><div class="purchase-policy-item__title">Tư vấn da miễn phí</div><p class="purchase-policy-item__text">SkinSyntax hỗ trợ chọn sản phẩm phù hợp.</p></div></div>
        </div>
      </div>
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
        <?php
          $detailStock = $p['so_luong_ton_kho'] ?? $p['ton_kho_hien_thi'] ?? null;
          $detailStock = $detailStock === null || $detailStock === '' ? null : max(0, (int)$detailStock);
          $isOutOfStock = $detailStock !== null && $detailStock <= 0;
        ?>
        <div class="mt-3 small <?= $isOutOfStock ? 'text-danger' : 'text-success' ?>">
          <?= $isOutOfStock ? 'Tạm hết hàng' : ('Còn hàng' . ($detailStock !== null ? ' · Còn ' . h((string)$detailStock) . ' sản phẩm trong kho' : '')) ?>
        </div>
        <div class="mt-4 d-flex gap-2">
          <div style="width: 100px;">
            <input type="number" class="form-control" value="<?= $isOutOfStock ? 0 : 1 ?>" min="1" max="<?= h((string)($detailStock ?? 999)) ?>" <?= $isOutOfStock ? 'disabled' : '' ?>>
          </div>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="flex-grow-1">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="product_id" value="<?= h((string)($p['ma_san_pham'] ?? $p['id'] ?? ($_GET['id'] ?? ''))) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h((string)($p['ma_san_pham'] ?? $p['id'] ?? ($_GET['id'] ?? ''))) ?>">
            <input type="hidden" name="quantity" value="<?= $isOutOfStock ? 0 : 1 ?>" class="qty-input">
            <input type="hidden" name="qty" value="<?= $isOutOfStock ? 0 : 1 ?>" class="qty-input">
            <button type="submit" class="btn btn-brand w-100" <?= $isOutOfStock ? 'disabled' : '' ?>>
              <i class="fas fa-shopping-cart"></i> <?= $isOutOfStock ? 'Tạm hết hàng' : 'Thêm vào giỏ hàng' ?>
            </button>
          </form>
        </div>
        <script>
          const qtyInput = document.querySelector('.form-control[type="number"]');
          const hiddenQtyInputs = document.querySelectorAll('.qty-input');
          if (qtyInput && hiddenQtyInputs.length) {
            const syncQty = () => {
              const max = parseInt(qtyInput.getAttribute('max') || '999', 10);
              let value = parseInt(qtyInput.value || '1', 10);
              if (Number.isNaN(value) || value < 1) value = 1;
              if (value > max) value = max;
              qtyInput.value = value;
              hiddenQtyInputs.forEach((hiddenQty) => { hiddenQty.value = qtyInput.value; });
            };
            qtyInput.addEventListener('change', syncQty);
            qtyInput.addEventListener('input', syncQty);
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
            <button class="nav-link active" id="tab-mo-ta" type="button" data-bs-toggle="tab" data-bs-target="#pane-mo-ta" data-tab="mo-ta" aria-controls="pane-mo-ta">Mô tả</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-thong-so" type="button" data-bs-toggle="tab" data-bs-target="#pane-thong-so" data-tab="thong-so" aria-controls="pane-thong-so">Thông số</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-thanh-phan" type="button" data-bs-toggle="tab" data-bs-target="#pane-thanh-phan" data-tab="thanh-phan" aria-controls="pane-thanh-phan">Thành phần</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-hdsd" type="button" data-bs-toggle="tab" data-bs-target="#pane-hdsd" data-tab="hdsd" aria-controls="pane-hdsd">HDSD</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-danh-gia" type="button" data-bs-toggle="tab" data-bs-target="#pane-danh-gia" data-tab="danh-gia" aria-controls="pane-danh-gia">Đánh giá</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-hoi-dap" type="button" data-bs-toggle="tab" data-bs-target="#pane-hoi-dap" data-tab="hoi-dap" aria-controls="pane-hoi-dap">Hỏi đáp</button>
          </li>
        </ul>

        <div class="box-text mb-3 tab-pane-content" id="pane-mo-ta" data-pane="mo-ta" role="tabpanel" aria-labelledby="tab-mo-ta">
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

        <div class="box-text mb-3 tab-pane-content d-none" id="pane-thong-so" data-pane="thong-so" role="tabpanel" aria-labelledby="tab-thong-so">
          <h5>Thông số</h5>
          <div class="spec-grid">
            <div><b>Thương Hiệu</b><br><?= h($p['thuong_hieu'] ?? '') ?></div>
            <div><b>Xuất xứ thương hiệu</b><br><?= h($p['xuat_xu_thuong_hieu'] ?? '') ?></div>
            <div><b>Nơi sản xuất</b><br><?= h($p['noi_san_xuat'] ?? '') ?></div>
            <div><b>Loại da</b><br><?= h($p['loai_da'] ?? '') ?></div>
            <div><b>Dung Tích</b><br><?= h($p['dung_tich'] ?? '') ?></div>
          </div>
        </div>

        <div class="box-text mb-3 tab-pane-content d-none" id="pane-thanh-phan" data-pane="thanh-phan" role="tabpanel" aria-labelledby="tab-thanh-phan">
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

        <div class="box-text mb-3 tab-pane-content d-none" id="pane-hdsd" data-pane="hdsd" role="tabpanel" aria-labelledby="tab-hdsd">
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

        <div class="box-text tab-pane-content d-none" id="pane-danh-gia" data-pane="danh-gia" role="tabpanel" aria-labelledby="tab-danh-gia">
          <h5>Đánh giá (<?= h((string)$reviewCountDisplay) ?>)</h5>
          <div class="review-panel">
            <div class="review-summary-card">
              <div>
                <div class="review-summary-card__label">Điểm trung bình</div>
                <div class="review-summary-card__score">
                  <div class="review-summary-card__score-value"><?= h($reviewAverage > 0 ? (string)$reviewAverage : '0') ?></div>
                  <span class="review-stars-display" aria-label="<?= h((string)$reviewAverage) ?> sao">
                    <?php $roundedAverage = (int)round($reviewAverage); for ($star = 1; $star <= 5; $star++): ?>
                      <i class="fa-solid fa-star <?= $star > $roundedAverage ? 'is-empty' : '' ?>"></i>
                    <?php endfor; ?>
                  </span>
                  <div class="review-summary-card__count"><?= h((string)$reviewCountDisplay) ?> đánh giá</div>
                  <div class="small text-muted mt-1">
                    <?= h((string)$crawlReviewCount) ?> đánh giá tổng quan<?= $userReviewCount > 0 ? ' · ' . h((string)$userReviewCount) . ' đánh giá SkinSyntax' : '' ?>
                  </div>
                </div>
              </div>
              <div class="review-breakdown">
                <?php $breakdownTotal = max(0, (int)($reviewStats['total'] ?? array_sum(array_map('intval', $reviewStats['stars'])))); ?>
                <?php for ($star = 5; $star >= 1; $star--): ?>
                  <?php
                    $starCount = (int)($reviewStats['stars'][$star] ?? 0);
                    $percent = $breakdownTotal > 0 ? min(100, round($starCount * 100 / $breakdownTotal, 1)) : 0;
                    $fillStyle = 'width: ' . $percent . '%;' . ($starCount > 0 && $percent > 0 ? ' min-width: 8px;' : '');
                  ?>
                  <div class="review-breakdown__row">
                    <span><?= $star ?> sao</span>
                    <span class="review-breakdown__bar"><span class="review-breakdown__fill" style="<?= h($fillStyle) ?>"></span></span>
                    <span><?= $starCount ?></span>
                  </div>
                <?php endfor; ?>
              </div>
            </div>

            <div class="review-filter-tabs" aria-label="Lọc đánh giá">
              <?php $baseReviewUrl = BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($productIdForForms) . '&tab=danh-gia'; ?>
              <a class="<?= $activeReviewStar === 0 && $activeReviewFilter !== 'images' ? 'is-active' : '' ?>" href="<?= h($baseReviewUrl) ?>">Tất cả</a>
              <?php for ($star = 5; $star >= 1; $star--): ?>
                <a class="<?= $activeReviewStar === $star ? 'is-active' : '' ?>" href="<?= h($baseReviewUrl . '&review_star=' . $star) ?>"><?= $star ?> sao</a>
              <?php endfor; ?>
              <a class="<?= $activeReviewFilter === 'images' ? 'is-active' : '' ?>" href="<?= h($baseReviewUrl . '&review_filter=images') ?>">Có hình ảnh</a>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
              <strong>Viết đánh giá</strong>
              <?php if (!is_logged_in()): ?>
                <span class="text-muted small">Vui lòng đăng nhập để đánh giá sản phẩm.</span>
              <?php elseif (empty($reviewPermission['has_purchased'])): ?>
                <span class="text-muted small">Bạn chỉ có thể đánh giá sản phẩm sau khi đã mua sản phẩm này.</span>
              <?php elseif (!empty($reviewPermission['has_reviewed'])): ?>
                <span class="text-success small fw-semibold">Bạn đã đánh giá sản phẩm này rồi.</span>
              <?php endif; ?>
            </div>

            <?php if (is_logged_in() && !empty($reviewPermission['has_purchased']) && empty($reviewPermission['has_reviewed'])): ?>
              <form method="post" action="<?= BASE_URL ?>/index.php?r=guidanhgia" class="review-form-shell" enctype="multipart/form-data">
                <input type="hidden" name="ma_san_pham" value="<?= h($productIdForForms) ?>">
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
                    <span class="order-status-note">Chỉ đơn Hoàn thành mới được đánh giá</span>
                  </div>
                  <div class="review-form-message">
                    <label class="form-label">Nội dung đánh giá</label>
                    <textarea class="form-control" name="noi_dung" rows="3" placeholder="Chia sẻ cảm nhận của bạn về sản phẩm này..." required></textarea>
                  </div>
                  <div class="review-upload-box">
                    <label class="form-label fw-semibold">Hình ảnh đánh giá</label>
                    <input class="form-control" type="file" name="hinh_anh[]" multiple accept="image/*">
                    <div class="small text-muted mt-1">Có thể chọn nhiều ảnh</div>
                  </div>
                  <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-brand px-4">Gửi đánh giá</button>
                  </div>
                </div>
              </form>
            <?php elseif (!is_logged_in()): ?>
              <div class="alert alert-info">Vui lòng đăng nhập để đánh giá sản phẩm.</div>
            <?php elseif (empty($reviewPermission['has_purchased'])): ?>
              <div class="alert alert-info">Bạn chỉ có thể đánh giá sản phẩm sau khi đã mua sản phẩm này.</div>
            <?php endif; ?>

            <?php if ($reviewErrorMessage !== ''): ?>
              <div class="alert alert-warning"><?= h($reviewErrorMessage) ?></div>
            <?php elseif (empty($reviews)): ?>
              <?php $emptyStarLabels = [5 => 'Rất hài lòng', 4 => 'Hài lòng', 3 => 'Bình thường', 2 => 'Không hài lòng', 1 => 'Rất không hài lòng']; ?>
              <?php if ($activeReviewStar >= 1 && $activeReviewStar <= 5): ?>
                <div class="detail-empty-state">Chưa có đánh giá <?= (int)$activeReviewStar ?> sao cho sản phẩm này<?= isset($emptyStarLabels[$activeReviewStar]) ? ' (' . h($emptyStarLabels[$activeReviewStar]) . ')' : '' ?>.</div>
              <?php elseif ($activeReviewFilter === 'images'): ?>
                <div class="detail-empty-state">Chưa có đánh giá kèm hình ảnh cho sản phẩm này.</div>
              <?php elseif ($crawlReviewCount > 0): ?>
                <div class="detail-empty-state">Sản phẩm đã có điểm đánh giá tổng quan từ dữ liệu bán hàng, nhưng chưa có đánh giá chi tiết từ người dùng SkinSyntax.</div>
              <?php else: ?>
                <div class="detail-empty-state">Sản phẩm này chưa có đánh giá chi tiết. Hãy là người đầu tiên chia sẻ trải nghiệm sau khi mua hàng.</div>
              <?php endif; ?>
            <?php else: ?>
              <div class="d-flex flex-column gap-3">
                <?php foreach ($reviews as $review): ?>
                  <div class="review-item">
                    <div class="review-item__head">
                      <div class="review-item__author">
                        <div class="review-item__name"><?= h($review['ten_khach_hang'] ?? 'Khách hàng') ?></div>
                        <?php if (!empty($review['da_mua_hang'])): ?><div class="review-item__badges"><span class="review-item__badge">Đã mua hàng</span></div><?php endif; ?>
                        <?php
                          $reviewStars = max(0, min(5, (int)($review['so_sao'] ?? 0)));
                          $starLabels = [5 => 'Rất hài lòng', 4 => 'Hài lòng', 3 => 'Bình thường', 2 => 'Không hài lòng', 1 => 'Rất không hài lòng'];
                        ?>
                        <span class="review-stars-display" aria-label="<?= $reviewStars ?> sao">
                          <?php for ($star = 1; $star <= 5; $star++): ?><i class="fa-solid fa-star <?= $star > $reviewStars ? 'is-empty' : '' ?>"></i><?php endfor; ?>
                        </span>
                        <?php if ($reviewStars > 0): ?><span class="small fw-semibold text-warning ms-2"><?= h($starLabels[$reviewStars] ?? '') ?></span><?php endif; ?>
                      </div>
                      <div class="review-item__date"><?= h(!empty($review['ngay_danh_gia']) ? date('d/m/Y H:i', strtotime((string)$review['ngay_danh_gia'])) : '') ?></div>
                    </div>
                    <div class="review-item__content"><?= nl2br_safe($review['noi_dung'] ?? '') ?></div>
                    <?php $reviewImagesRaw = $review['hinh_anh'] ?? []; $reviewImages = is_array($reviewImagesRaw) ? array_values(array_filter(array_map('strval', $reviewImagesRaw))) : split_image_urls((string)$reviewImagesRaw, 6); ?>
                    <?php if (!empty($reviewImages)): ?>
                      <div class="review-item__images"><?php foreach ($reviewImages as $reviewImage): ?><img src="<?= h(resolve_image_url($reviewImage)) ?>" alt="Ảnh đánh giá" referrerpolicy="no-referrer" onerror="this.remove();"><?php endforeach; ?></div>
                    <?php endif; ?>
                    <?php $shopReply = $review['phan_hoi_shop'] ?? null; if (is_object($shopReply)) $shopReply = (array)$shopReply; ?>
                    <?php if (!empty($shopReply['noi_dung'])): ?>
                      <div class="review-item__reply"><div class="small text-muted mb-1">Phản hồi từ SkinSyntax</div><div class="review-item__reply-box"><?= nl2br_safe($shopReply['noi_dung'] ?? '') ?></div></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="box-text tab-pane-content d-none" id="pane-hoi-dap" data-pane="hoi-dap" role="tabpanel" aria-labelledby="tab-hoi-dap">
          <h5>Hỏi đáp (<?= h((string)$questionCount) ?>)</h5>
          <div class="qa-panel">
            <?php if (is_logged_in()): ?>
              <form method="post" action="<?= BASE_URL ?>/index.php?r=guicauhoi" class="qa-form">
                <input type="hidden" name="ma_san_pham" value="<?= h($productIdForForms) ?>">
                <textarea class="form-control" name="cau_hoi" rows="3" placeholder="Bạn có câu hỏi với sản phẩm này? Đặt câu hỏi ngay..." required></textarea>
                <div class="d-flex justify-content-end"><button class="btn btn-brand px-4" type="submit">Gửi</button></div>
              </form>
            <?php else: ?>
              <div class="alert alert-info">Vui lòng đăng nhập để gửi câu hỏi về sản phẩm.</div>
            <?php endif; ?>

            <?php if ($questionErrorMessage !== ''): ?>
              <div class="alert alert-warning"><?= h($questionErrorMessage) ?></div>
            <?php elseif (empty($questions)): ?>
              <div class="detail-empty-state">Chưa có câu hỏi nào cho sản phẩm này. Hãy đặt câu hỏi đầu tiên.</div>
            <?php else: ?>
              <?php foreach ($questions as $question): ?>
                <?php $answer = $question['tra_loi'] ?? null; if (is_object($answer)) $answer = (array)$answer; ?>
                <div class="qa-item">
                  <div class="qa-item__meta"><strong><?= h((string)($question['ten_khach_hang'] ?? 'Khách hàng')) ?></strong><span><?= h(!empty($question['ngay_hoi']) ? date('d/m/Y H:i', strtotime((string)$question['ngay_hoi'])) : '') ?></span></div>
                  <div><?= nl2br_safe((string)($question['cau_hoi'] ?? '')) ?></div>
                  <div class="small text-muted mt-2"><i class="fa-regular fa-thumbs-up"></i> <?= (int)($question['so_luot_thich'] ?? 0) ?> lượt thích</div>
                  <?php if (is_array($answer) && trim((string)($answer['noi_dung'] ?? '')) !== ''): ?>
                    <div class="qa-answer"><div class="fw-semibold mb-1">SkinSyntax trả lời</div><?= nl2br_safe((string)$answer['noi_dung']) ?><div class="small text-muted mt-2"><?= h(!empty($answer['ngay_tra_loi']) ? date('d/m/Y H:i', strtotime((string)$answer['ngay_tra_loi'])) : '') ?></div></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
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
      const isActive = btn.getAttribute('data-tab') === target;
      btn.classList.toggle('active', isActive);
      btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    tabPanes.forEach(pane => {
      const isActive = pane.getAttribute('data-pane') === target;
      pane.classList.toggle('d-none', !isActive);
      pane.classList.toggle('active', isActive);
      pane.classList.toggle('show', isActive);
    });
  };

  tabButtons.forEach(btn => {
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const target = btn.getAttribute('data-tab');
      activateTab(target);
    });
  });

  const params = new URLSearchParams(window.location.search);
  const hashMap = {
    '#danhgia': 'danh-gia',
    '#tab-danh-gia': 'danh-gia',
    '#hoidap': 'hoi-dap',
    '#tab-hoi-dap': 'hoi-dap'
  };
  const hashTab = hashMap[window.location.hash] || '';
  const initialTab = hashTab || params.get('tab') || <?= json_encode($activeTab !== '' ? $activeTab : '') ?>;
  if (initialTab) {
    activateTab(initialTab);
    if (hashTab) {
      const nav = document.getElementById('detailTabNav');
      if (nav) nav.scrollIntoView({behavior: 'smooth', block: 'start'});
    }
  }
})();
</script>



