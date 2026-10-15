<?php
$p = $p ?? [];
$imgs = split_image_urls($p['link_hinh_anh'] ?? '', 10);
$main = $imgs[0] ?? default_placeholder_image();
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
$giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
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

  /* Syna 3D Product Video Showcase */
  .syna-video-showcase-card {
    border-radius: 24px !important;
    border: 1px solid #dce8db !important;
    background: linear-gradient(180deg, #ffffff 0%, #f9fbf8 100%);
    box-shadow: 0 8px 28px rgba(24, 59, 43, 0.05);
  }
  .syna-video-viewport {
    position: relative;
    width: 100%;
    max-width: 860px;
    aspect-ratio: 16 / 9;
    margin: 0 auto;
    border-radius: 16px;
    overflow: hidden;
    background: #09130e;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
  }
  .syna-video-viewport video {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
    background: #000;
  }
  .syna-video-cover-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: linear-gradient(180deg, rgba(8, 20, 15, 0.3) 0%, rgba(8, 20, 15, 0.72) 100%);
    color: #fff;
    cursor: pointer;
    transition: opacity 0.25s ease, visibility 0.25s;
    z-index: 5;
  }
  .syna-video-cover-overlay.is-hidden {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
  }
  .syna-video-play-btn {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: #185334;
    border: 3px solid rgba(255, 255, 255, 0.9);
    color: #fff;
    display: grid;
    place-items: center;
    font-size: 1.5rem;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
    transition: transform 0.2s ease, background 0.2s ease;
  }
  .syna-video-cover-overlay:hover .syna-video-play-btn {
    transform: scale(1.08);
    background: #236f47;
  }
  .syna-video-loading-state,
  .syna-video-error-state {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    padding: 24px;
    text-align: center;
    background: rgba(8, 20, 15, 0.92);
    color: #fff;
    z-index: 6;
  }
  .syna-video-loading-state.is-active,
  .syna-video-error-state.is-active {
    display: flex;
  }
  .syna-video-playlist-item {
    cursor: pointer;
    border: 1px solid #e1ebe0;
    border-radius: 12px;
    padding: 10px 14px;
    background: #fff;
    transition: all 0.2s ease;
  }
  .syna-video-playlist-item:hover,
  .syna-video-playlist-item.is-active {
    border-color: #185334;
    background: #edf6ee;
  }
</style>
<div class="container my-4">
  <?php if ($detailUnavailableMessage !== ''): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4" style="border-radius: 8px; background: #FFFBEB; color: #B45309;"><?= h($detailUnavailableMessage) ?></div>
  <?php endif; ?>

  <div class="mb-3">
    <a href="javascript:history.back()" class="btn btn-sm px-3 fw-semibold border" style="background: #FAFAFA; color: #0F172A; border-radius: 6px; border-color: var(--border) !important;">
      <i class="fas fa-arrow-left me-1"></i> Quay lại
    </a>
  </div>

  <div class="row g-4 mb-5">
    <!-- Left: Gallery & Purchase Benefits -->
    <div class="col-12 col-lg-5">
      <div class="detail-img border bg-white p-2" style="border-radius: 12px; border-color: var(--border) !important;">
        <img id="mainImage"
             class="detail-main-img w-100 h-auto"
             src="<?= h($main) ?>"
             referrerpolicy="no-referrer"
             onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';"
             alt="<?= h($p['ten_san_pham'] ?? '') ?>"
             style="border-radius: 8px; aspect-ratio: 1/1; object-fit: cover;">
      </div>

      <?php if (count($imgs) > 1): ?>
        <div class="detail-thumbs mt-3 d-flex gap-2 overflow-x-auto pb-2">
          <?php foreach ($imgs as $i => $url): ?>
            <button type="button"
                    class="thumb-btn border p-1 <?= $i===0?'active':'' ?>"
                    data-src="<?= h($url) ?>"
                    style="width: 64px; height: 64px; flex-shrink: 0; background: #FFF; border-color: var(--border); border-radius: 6px;">
              <img src="<?= h($url) ?>"
                   referrerpolicy="no-referrer"
                   onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';"
                   alt="thumb"
                   style="width: 100%; height: 100%; object-fit: cover; border-radius: 4px;">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="purchase-policy-card mt-4 p-4 bg-white border" style="border-radius: 12px; border-color: var(--border) !important;">
        <h6 class="fw-semibold mb-3" style="color: #0F172A;"><i class="fas fa-shield-heart text-success me-2"></i> Quyền lợi mua hàng SkinSyntax</h6>
        <div class="purchase-policy-grid d-flex flex-column gap-2.5">
          <div class="d-flex align-items-start gap-3"><i class="fa-solid fa-truck text-success fs-6 mt-1"></i><div><strong class="d-block text-dark small fw-semibold">Miễn phí vận chuyển</strong><small class="text-muted">Cho đơn từ 300.000đ toàn quốc.</small></div></div>
          <div class="d-flex align-items-start gap-3"><i class="fa-solid fa-bolt text-warning fs-6 mt-1"></i><div><strong class="d-block text-dark small fw-semibold">Giao nhanh 2H</strong><small class="text-muted">Giao hỏa tốc trong nội thành.</small></div></div>
          <div class="d-flex align-items-start gap-3"><i class="fa-solid fa-shield-check text-success fs-6 mt-1"></i><div><strong class="d-block text-dark small fw-semibold">Cam kết 100% Thuần Chay & Chính Hãng</strong><small class="text-muted">Đền bù 100% nếu phát hiện hàng giả.</small></div></div>
        </div>
      </div>
    </div>

    <!-- Right: Title, Specs, Price, Actions -->
    <div class="col-12 col-lg-7">
      <div class="detail-box p-4 p-md-5 bg-white border" style="border-radius: 12px; border-color: var(--border) !important;">
        <div class="text-uppercase fw-semibold small mb-1" style="letter-spacing: 0.04em; color: #183B2B; font-size: 0.75rem;"><?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
        <h2 class="detail-title fw-bold mb-3" style="color: #0F172A; font-size: 1.6rem; line-height: 1.35;"><?= h($p['ten_san_pham'] ?? '') ?></h2>

        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="d-flex align-items-center gap-1 text-warning" style="font-size: 0.88rem;">
            <i class="fas fa-star" style="font-size: 0.8rem;"></i>
            <strong class="text-dark"><?= number_format((float)($p['diem_danh_gia'] ?? 4.9), 1) ?></strong>
          </div>
          <span class="text-muted small">|</span>
          <span class="text-muted small"><?= (int)($p['so_luong_danh_gia'] ?? 128) ?> đánh giá</span>
          <span class="text-muted small">|</span>
          <span class="badge fw-semibold px-2.5 py-1" style="background: #EBF2EE; color: #183B2B; border-radius: 4px; font-size: 0.74rem;">Chính hãng 100%</span>
        </div>

        <?php
          $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
          $effectivePricing = function_exists('get_effective_product_price')
              ? get_effective_product_price($productIdForForms, $p)
              : [
                  'is_flash_sale' => false,
                  'effective_price' => (int)($p['gia_ban'] ?? 0),
                  'regular_price' => (int)($p['gia_ban'] ?? 0),
                  'discount_percent' => $phanTramGiam,
              ];
          $isFlashSaleActive = !empty($effectivePricing['is_flash_sale']);
          $displayPrice = $isFlashSaleActive ? (int)$effectivePricing['effective_price'] : (int)($p['gia_ban'] ?? 0);
          $displayStruckPrice = $isFlashSaleActive
              ? (int)$effectivePricing['regular_price']
              : ((float)$giaThiTruong > 0 ? (int)$giaThiTruong : 0);
          $displayDiscountPercent = $isFlashSaleActive
              ? (int)$effectivePricing['discount_percent']
              : $phanTramGiam;
        ?>
        <div class="p-3 mb-4 border" style="background: <?= $isFlashSaleActive ? '#FFF5F5' : '#FAFAFA' ?>; border-radius: 8px; border-color: <?= $isFlashSaleActive ? '#FECACA' : 'var(--border)' ?> !important;">
          <?php if ($isFlashSaleActive): ?>
            <div class="d-flex align-items-center gap-2 mb-2">
              <span class="badge" style="background: #DC2626; color: #FFF; font-size: 0.76rem; font-weight: 700; padding: 4px 8px; border-radius: 4px;">
                <i class="fas fa-bolt text-warning me-1"></i> FLASH SALE: <?= h($effectivePricing['campaign_name']) ?>
              </span>
              <?php if (!empty($effectivePricing['valid_until'])): ?>
                <span class="text-danger small fw-semibold" style="font-size: 0.78rem;">
                  <i class="far fa-clock me-1"></i> Ưu đãi có hạn
                </span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="d-flex align-items-baseline gap-3">
            <span class="current fw-bold fs-3 tabular-nums" style="color: <?= $isFlashSaleActive ? '#DC2626' : '#183B2B' ?>;"><?= vnd($displayPrice) ?></span>
            <?php if ($displayStruckPrice > $displayPrice): ?>
              <span class="market text-muted text-decoration-line-through fs-6 tabular-nums"><?= vnd($displayStruckPrice) ?></span>
            <?php endif; ?>
            <?php if ($displayDiscountPercent !== null && $displayDiscountPercent > 0): ?>
              <span class="badge bg-danger fw-semibold ms-auto" style="font-size: 0.78rem; border-radius: 4px;">Giảm <?= h((string)$displayDiscountPercent) ?>%</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="row g-2 mb-4 small p-3 border" style="background: #FFF; border-radius: 8px; border-color: var(--border) !important;">
          <div class="col-6"><strong>Danh mục:</strong> <?= h($p['danh_muc_day_du'] ?? 'Chăm sóc da') ?></div>
          <div class="col-6"><strong>Dung tích:</strong> <?= h($p['dung_tich'] ?? 'Tiêu chuẩn') ?></div>
          <div class="col-6"><strong>Xuất xứ:</strong> <?= h($p['xuat_xu_thuong_hieu'] ?? 'Chính hãng') ?></div>
          <?php
            $rawLoaiDa = trim((string)($p['loai_da'] ?? ''));
            $isLoaiDaNa = ($rawLoaiDa === '' || mb_strtolower($rawLoaiDa, 'UTF-8') === 'unknown' || mb_strtolower($rawLoaiDa, 'UTF-8') === 'khong ap dung' || mb_strtolower($rawLoaiDa, 'UTF-8') === 'không áp dụng');
            $displayLoaiDa = $isLoaiDaNa ? 'Không áp dụng' : $rawLoaiDa;
          ?>
          <div class="col-6"><strong>Loại da:</strong> <?= h($displayLoaiDa) ?></div>
        </div>

        <?php
          $detailStock = $p['so_luong_ton_kho'] ?? $p['ton_kho_hien_thi'] ?? null;
          $detailStock = $detailStock === null || $detailStock === '' ? null : max(0, (int)$detailStock);
          $isOutOfStock = $detailStock !== null && $detailStock <= 0;
        ?>
        <div class="mb-3 small fw-semibold <?= $isOutOfStock ? 'text-danger' : 'text-success' ?>">
          <i class="fas <?= $isOutOfStock ? 'fa-circle-xmark' : 'fa-circle-check' ?> me-1"></i>
          <?= $isOutOfStock ? 'Tạm hết hàng' : ('Còn hàng' . ($detailStock !== null ? ' · Còn ' . h((string)$detailStock) . ' sản phẩm' : '')) ?>
        </div>

        <div class="d-flex gap-2 mb-4 flex-wrap align-items-center">
          <div style="width: 80px;">
            <input type="number" class="form-control text-center fw-semibold" value="<?= $isOutOfStock ? 0 : 1 ?>" min="1" max="<?= h((string)($detailStock ?? 999)) ?>" <?= $isOutOfStock ? 'disabled' : '' ?> style="border-radius: 6px; height: 44px; background: #FAFAFA; border-color: var(--border);">
          </div>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="flex-grow-1 m-0">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="product_id" value="<?= h((string)($p['ma_san_pham'] ?? $p['id'] ?? ($_GET['id'] ?? ''))) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h((string)($p['ma_san_pham'] ?? $p['id'] ?? ($_GET['id'] ?? ''))) ?>">
            <input type="hidden" name="quantity" value="<?= $isOutOfStock ? 0 : 1 ?>" class="qty-input">
            <input type="hidden" name="qty" value="<?= $isOutOfStock ? 0 : 1 ?>" class="qty-input">
            <button type="submit" class="btn w-100 fw-semibold" style="border-radius: 6px; height: 44px; font-size: 0.88rem; border: 1px solid #E2E8F0; color: #0F172A; background: #F1F5F9;" <?= $isOutOfStock ? 'disabled' : '' ?>>
              <i class="fas fa-shopping-cart me-1"></i> <?= $isOutOfStock ? 'Hết hàng' : '+ Thêm giỏ' ?>
            </button>
          </form>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="flex-grow-1 m-0">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="buy_now" value="1">
            <input type="hidden" name="product_id" value="<?= h((string)($p['ma_san_pham'] ?? $p['id'] ?? ($_GET['id'] ?? ''))) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h((string)($p['ma_san_pham'] ?? $p['id'] ?? ($_GET['id'] ?? ''))) ?>">
            <input type="hidden" name="quantity" value="<?= $isOutOfStock ? 0 : 1 ?>" class="qty-input">
            <input type="hidden" name="qty" value="<?= $isOutOfStock ? 0 : 1 ?>" class="qty-input">
            <button type="submit" class="btn text-white w-100 fw-semibold" style="background: #183B2B; border-radius: 6px; height: 44px; font-size: 0.88rem; border: none;" <?= $isOutOfStock ? 'disabled' : '' ?>>
               Mua Ngay
            </button>
          </form>
        </div>

        <!-- AI Assistant Banner CTA -->
        <div class="p-3 d-flex align-items-center justify-content-between text-white" style="background: #183B2B; border-radius: 8px;">
          <div class="d-flex align-items-center gap-2">
            <i class="fas fa-wand-magic-sparkles text-warning fs-5"></i>
            <div>
              <strong style="font-size: 0.86rem; display: block; font-weight: 600;">Tư Vấn Hoạt Chất Với AI</strong>
              <small style="color: #E2E8F0; font-size: 0.74rem;">Hỏi AI về độ phù hợp cho làn da của bạn</small>
            </div>
          </div>
          <a href="<?= BASE_URL ?>/index.php?r=goiy" class="btn btn-sm btn-light px-3 fw-semibold" style="color: #183B2B; border-radius: 6px; font-size: 0.78rem;">Hỏi AI &rarr;</a>
        </div>
      </div>
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

      <?php if (!empty($productVideos)): ?>
        <?php
          $primaryVid = $productVideos[0];
          $currentPid = (string)($p['ma_san_pham'] ?? $id);
          $initialStreamUrl = BASE_URL . '/index.php?r=product_video_stream&id=' . urlencode($primaryVid['id']) . '&product_id=' . urlencode($currentPid);
        ?>
        <!-- Syna 3D Product Video Showcase -->
        <div class="syna-video-showcase-card mt-4 mb-3 p-4 rounded-4 bg-white border w-100" id="synaVideoShowcase">
          <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div>
              <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge px-2.5 py-1 fw-bold text-uppercase" style="background: #eaf5ea; color: #185334; border: 1px solid #cce3cb; font-size: 0.74rem; letter-spacing: 0.04em; border-radius: 6px;">
                  <i class="fas fa-play-circle me-1"></i> Video AI Thuyết Minh
                </span>
                <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.74rem;">
                  <i class="fas fa-shield-alt text-success me-1"></i> Syna 3D · Đã kiểm duyệt
                </span>
              </div>
              <h5 class="fw-bold mb-0" id="synaVideoTitle" style="color: #0F172A; font-size: 1.15rem;">
                <?= h($primaryVid['title'] ?? 'Video giới thiệu & tư vấn sản phẩm') ?>
              </h5>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="text-muted small" id="synaVideoMetaInfo">
                Độ phân giải: <?= h($primaryVid['resolution'] ?? '1280x720') ?> · <?= round((float)($primaryVid['duration'] ?? 0)) ?>s
              </span>
            </div>
          </div>

          <div class="syna-video-viewport" id="synaVideoViewport">
            <!-- HTML5 Video Player -->
            <video id="synaVideoPlayer"
                   playsinline
                   controls
                   preload="metadata"
                   poster="<?= h($main) ?>"
                   src="<?= h($initialStreamUrl) ?>">
              Trình duyệt của bạn không hỗ trợ thẻ video HTML5.
            </video>

            <!-- Video Cover Overlay (Prevents unexpected sound autoplay & provides clean CTA) -->
            <div class="syna-video-cover-overlay" id="synaVideoOverlay">
              <div class="syna-video-play-btn mb-2">
                <i class="fas fa-play ps-1"></i>
              </div>
              <span class="fw-bold fs-6">Xem Syna 3D tư vấn sản phẩm</span>
              <small class="text-white-50 mt-1">Bấm để phát video có âm thanh thuyết minh</small>
            </div>

            <!-- Loading Spinner State -->
            <div class="syna-video-loading-state" id="synaVideoLoading">
              <div class="spinner-border text-light mb-2" role="status" style="width: 2.2rem; height: 2.2rem;"></div>
              <span class="small">Đang tải video thuyết minh...</span>
            </div>

            <!-- Error State Fallback -->
            <div class="syna-video-error-state" id="synaVideoError">
              <i class="fas fa-exclamation-triangle text-warning fs-3 mb-2"></i>
              <p class="mb-2 fw-semibold" id="synaVideoErrorMsg">Không thể phát video tại thời điểm này.</p>
              <button type="button" class="btn btn-sm btn-outline-light px-3" id="synaVideoRetryBtn">
                <i class="fas fa-redo me-1"></i> Thử lại
              </button>
            </div>
          </div>

          <?php
            $primaryReward = $primaryVid['reward_campaign'] ?? null;
          ?>
          <!-- Watch-to-Reward Campaign Card -->
          <div class="syna-reward-card mt-3 p-3 rounded-3 border <?= empty($primaryReward) ? 'd-none' : '' ?>"
               id="synaRewardCard"
               data-campaign-id="<?= h($primaryReward['campaign_id'] ?? '') ?>"
               data-publication-id="<?= h($primaryVid['id'] ?? '') ?>"
               data-video-id="<?= h($primaryVid['video_id'] ?? '') ?>"
               data-duration="<?= (float)($primaryVid['duration'] ?? 0) ?>"
               style="background: linear-gradient(135deg, #f7faf8 0%, #edf6f0 100%); border-color: #d1e7d8 !important;">
            
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
              <div class="d-flex align-items-center gap-2">
                <span class="syna-reward-icon-badge d-inline-flex align-items-center justify-content-center text-white"
                      style="width: 28px; height: 28px; border-radius: 8px; background: linear-gradient(135deg, #185334, #27ae60); font-size: 0.85rem;">
                  <i class="fas fa-gift"></i>
                </span>
                <div>
                  <span class="fw-bold small text-dark d-block" id="synaRewardCampaignName">
                    <?= h($primaryReward['campaign_name'] ?? 'Ưu đãi xem video Syna 3D') ?>
                  </span>
                  <small class="text-success fw-semibold" id="synaRewardOfferText">
                    <?php if (!empty($primaryReward)): ?>
                      <?php if (($primaryReward['discount_type'] ?? '') === 'percentage'): ?>
                        Giảm <?= (float)$primaryReward['discount_value'] ?>%<?= !empty($primaryReward['max_discount_amount']) ? ' (tối đa ' . number_format((float)$primaryReward['max_discount_amount']) . 'đ)' : '' ?>
                      <?php else: ?>
                        Giảm <?= number_format((float)$primaryReward['discount_value']) ?>đ
                      <?php endif; ?>
                      <?= !empty($primaryReward['min_order_amount']) ? ' cho đơn từ ' . number_format((float)$primaryReward['min_order_amount']) . 'đ' : '' ?>
                    <?php endif; ?>
                  </small>
                </div>
              </div>
              <div id="synaRewardBadgeContainer">
                <span class="badge px-2.5 py-1 fw-semibold text-uppercase" id="synaRewardBadge"
                      style="font-size: 0.72rem; letter-spacing: 0.03em; border-radius: 6px; background: #e2e8f0; color: #475569;">
                  <i class="fas fa-lock me-1"></i> Chưa mở khóa
                </span>
              </div>
            </div>

            <!-- Progress Bar -->
            <div class="syna-reward-progress-track rounded-pill overflow-hidden my-2" 
                 style="height: 10px; background: #e2e8f0; position: relative;">
              <div class="syna-reward-progress-bar rounded-pill" id="synaRewardProgressBar" 
                   style="height: 100%; width: 0%; background: linear-gradient(90deg, #27ae60 0%, #2ecc71 100%); transition: width 0.4s ease;"></div>
            </div>

            <div class="d-flex align-items-center justify-content-between flex-wrap gap-1 text-muted" style="font-size: 0.78rem;">
              <span id="synaRewardStatusText" class="fw-medium text-secondary">
                Xem hết video để mở khóa ưu đãi
              </span>
              <span id="synaRewardProgressTime" class="fw-semibold text-dark">
                0:00 / <?= gmdate((float)($primaryVid['duration'] ?? 0) >= 3600 ? "H:i:s" : "i:s", (int)round((float)($primaryVid['duration'] ?? 0))) ?> (0%)
              </span>
            </div>

            <!-- Action Area: Claim Reward Button, Guest Login prompt, Claimed Voucher Box -->
            <div id="synaRewardActionArea" class="mt-2.5 pt-2 border-top d-none" style="border-color: rgba(0,0,0,0.08) !important;">
              <!-- State 1: Eligible & Logged-in -> Claim Button -->
              <div id="synaRewardClaimBtnContainer" class="d-none">
                <button type="button" id="synaRewardClaimBtn" class="btn btn-success btn-sm w-100 fw-bold py-2 shadow-sm d-flex align-items-center justify-content-center gap-2" style="border-radius: 8px;">
                  <i class="fas fa-gift"></i> Nhận Voucher Ưu Đãi Ngay
                </button>
              </div>

              <!-- State 2: Eligible & Guest -> Require Login -->
              <div id="synaRewardGuestLoginContainer" class="d-none text-center p-2 rounded" style="background: #f8fafc; border: 1px dashed #cbd5e1;">
                <span class="small text-muted d-block mb-1.5" style="font-size: 0.78rem;">
                  <i class="fas fa-check-circle text-success me-1"></i> Bạn đã xem đủ thời lượng video! Đăng nhập để nhận voucher vào ví tài khoản.
                </span>
                <a href="<?= BASE_URL ?>/index.php?r=dangnhap" class="btn btn-outline-success btn-sm fw-bold px-3" style="font-size: 0.78rem; border-radius: 6px;">
                  <i class="fas fa-sign-in-alt me-1"></i> Đăng nhập nhận Voucher
                </a>
              </div>

              <!-- State 3: Already Claimed / Successfully Claimed -> Display Voucher Details -->
              <div id="synaRewardClaimedBox" class="d-none p-2.5 rounded text-start" style="background: #ecfdf5; border: 1px solid #a7f3d0;">
                <div class="d-flex align-items-center justify-content-between mb-1">
                  <span class="badge bg-success text-white fw-bold" style="font-size: 0.72rem;">
                    <i class="fas fa-check-circle me-1"></i> Đã nhận voucher
                  </span>
                  <span class="small text-muted" id="synaClaimedDate" style="font-size: 0.72rem;"></span>
                </div>
                <div class="d-flex align-items-center justify-content-between gap-2 mt-1.5 p-1.5 bg-white rounded border border-success-subtle">
                  <span class="fw-bold text-success font-monospace" id="synaClaimedVoucherCode" style="font-size: 0.95rem; letter-spacing: 0.05em;">---</span>
                  <button type="button" id="synaCopyVoucherBtn" class="btn btn-sm btn-outline-success py-0.5 px-2" style="font-size: 0.75rem;">
                    <i class="fas fa-copy"></i> Sao chép
                  </button>
                </div>
                <div class="mt-2 d-flex justify-content-between align-items-center small" style="font-size: 0.78rem;">
                  <span class="text-secondary" id="synaClaimedDetailsText">Đã lưu vào ví voucher của bạn</span>
                  <a href="<?= BASE_URL ?>/index.php?r=hoso#tab-vouchers" class="text-success fw-bold text-decoration-none">
                    Xem trong Ví <i class="fas fa-arrow-right ms-1"></i>
                  </a>
                </div>
              </div>

              <!-- Alert Box for Error / Rejection message -->
              <div id="synaRewardErrorAlert" class="d-none alert alert-warning p-2 small mt-2 mb-0 rounded" style="font-size: 0.76rem;">
                <i class="fas fa-exclamation-triangle me-1"></i> <span id="synaRewardErrorMsg"></span>
              </div>
            </div>
          </div>

          <?php if (count($productVideos) > 1): ?>
            <!-- Playlist Selector for Products with Multiple Videos -->
            <div class="mt-3 pt-3 border-top">
              <small class="text-muted fw-semibold d-block mb-2">Chọn video tư vấn khác của sản phẩm:</small>
              <div class="d-flex flex-wrap gap-2" id="synaVideoPlaylist">
                <?php foreach ($productVideos as $idx => $vItem): ?>
                  <?php
                    $vStreamUrl = BASE_URL . '/index.php?r=product_video_stream&id=' . urlencode($vItem['id']) . '&product_id=' . urlencode($currentPid);
                    $vRewardJson = !empty($vItem['reward_campaign']) ? json_encode($vItem['reward_campaign'], JSON_UNESCAPED_UNICODE) : '';
                  ?>
                  <div class="syna-video-playlist-item <?= $idx === 0 ? 'is-active' : '' ?>"
                       data-video-src="<?= h($vStreamUrl) ?>"
                       data-video-id="<?= h($vItem['video_id'] ?? '') ?>"
                       data-publication-id="<?= h($vItem['id'] ?? '') ?>"
                       data-duration="<?= (float)($vItem['duration'] ?? 0) ?>"
                       data-reward-campaign="<?= h($vRewardJson) ?>"
                       data-video-title="<?= h($vItem['title'] ?? 'Video ' . ($idx + 1)) ?>"
                       data-video-meta="Độ phân giải: <?= h($vItem['resolution'] ?? '1280x720') ?> · <?= round((float)($vItem['duration'] ?? 0)) ?>s">
                    <i class="fas fa-film me-1 text-success"></i>
                    <span class="small fw-semibold"><?= h($vItem['title'] ?? ('Video #' . ($idx + 1))) ?></span>
                    <small class="text-muted ms-1">(<?= round((float)($vItem['duration'] ?? 0)) ?>s)</small>
                    <?php if (!empty($vItem['reward_campaign'])): ?>
                      <span class="badge bg-warning-subtle text-warning-emphasis border ms-1" style="font-size: 0.65rem;">
                        <i class="fas fa-gift"></i> Voucher
                      </span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <script>
          (() => {
            const player = document.getElementById('synaVideoPlayer');
            const overlay = document.getElementById('synaVideoOverlay');
            const loading = document.getElementById('synaVideoLoading');
            const errorBox = document.getElementById('synaVideoError');
            const retryBtn = document.getElementById('synaVideoRetryBtn');
            const titleEl = document.getElementById('synaVideoTitle');
            const metaEl = document.getElementById('synaVideoMetaInfo');

            // Reward UI Elements
            const rewardCard = document.getElementById('synaRewardCard');
            const rewardCampaignName = document.getElementById('synaRewardCampaignName');
            const rewardOfferText = document.getElementById('synaRewardOfferText');
            const rewardBadge = document.getElementById('synaRewardBadge');
            const rewardProgressBar = document.getElementById('synaRewardProgressBar');
            const rewardStatusText = document.getElementById('synaRewardStatusText');
            const rewardProgressTime = document.getElementById('synaRewardProgressTime');

            // Action Area Elements
            const rewardActionArea = document.getElementById('synaRewardActionArea');
            const claimBtnContainer = document.getElementById('synaRewardClaimBtnContainer');
            const claimBtn = document.getElementById('synaRewardClaimBtn');
            const guestLoginContainer = document.getElementById('synaRewardGuestLoginContainer');
            const claimedBox = document.getElementById('synaRewardClaimedBox');
            const claimedDate = document.getElementById('synaClaimedDate');
            const claimedVoucherCode = document.getElementById('synaClaimedVoucherCode');
            const copyVoucherBtn = document.getElementById('synaCopyVoucherBtn');
            const claimedDetailsText = document.getElementById('synaClaimedDetailsText');
            const errorAlert = document.getElementById('synaRewardErrorAlert');
            const errorMsg = document.getElementById('synaRewardErrorMsg');

            const isUserLoggedIn = <?= is_logged_in() ? 'true' : 'false' ?>;

            if (!player) return;

            let currentWatchSessionId = null;
            let heartbeatTimer = null;
            let currentPubId = rewardCard ? rewardCard.dataset.publicationId : '';
            let currentVideoId = rewardCard ? rewardCard.dataset.videoId : '';
            let currentCampId = rewardCard ? rewardCard.dataset.campaignId : '';
            let videoDuration = rewardCard ? parseFloat(rewardCard.dataset.duration || '0') : 0;
            let isEligible = false;
            let isSessionStarting = false;
            let isClaiming = false;

            function formatTime(seconds) {
              const sec = Math.max(0, Math.floor(seconds || 0));
              const m = Math.floor(sec / 60);
              const s = sec % 60;
              return `${m}:${s < 10 ? '0' : ''}${s}`;
            }

            function updateRewardUI(data) {
              if (!rewardCard) return;

              const percent = Math.min(100, Math.max(0, parseFloat(data.watched_coverage_percent || 0)));
              const watchedSec = parseFloat(data.watched_seconds || 0);
              const totalSec = videoDuration > 0 ? videoDuration : parseFloat(data.video_duration || 0);

              if (rewardProgressBar) {
                rewardProgressBar.style.width = `${percent}%`;
              }
              if (rewardProgressTime) {
                rewardProgressTime.textContent = `${formatTime(watchedSec)} / ${formatTime(totalSec)} (${Math.round(percent)}%)`;
              }

              // Case 1: Already Claimed / Rewarded
              if (data.watch_state === 'REWARDED' || data.is_rewarded || data.reward_voucher_code) {
                isEligible = true;
                if (rewardBadge) {
                  rewardBadge.className = 'badge px-2.5 py-1 fw-bold text-uppercase';
                  rewardBadge.style.cssText = 'font-size: 0.72rem; letter-spacing: 0.03em; border-radius: 6px; background: #198754; color: #ffffff;';
                  rewardBadge.innerHTML = '<i class="fas fa-check-circle me-1"></i> Đã nhận voucher';
                }
                if (rewardStatusText) {
                  rewardStatusText.className = 'fw-bold text-success';
                  rewardStatusText.textContent = 'Bạn đã nhận thành công voucher ưu đãi của video này!';
                }
                if (rewardProgressBar) {
                  rewardProgressBar.style.width = '100%';
                  rewardProgressBar.style.background = 'linear-gradient(90deg, #198754 0%, #20c997 100%)';
                }

                if (rewardActionArea) rewardActionArea.classList.remove('d-none');
                if (claimedBox) claimedBox.classList.remove('d-none');
                if (claimBtnContainer) claimBtnContainer.classList.add('d-none');
                if (guestLoginContainer) guestLoginContainer.classList.add('d-none');
                if (errorAlert) errorAlert.classList.add('d-none');

                const vCode = data.reward_voucher_code || (data.voucher && data.voucher.voucher_code) || '';
                if (claimedVoucherCode && vCode) claimedVoucherCode.textContent = vCode;
                if (claimedDate && data.reward_claimed_at) claimedDate.textContent = 'Ngày nhận: ' + data.reward_claimed_at;
                return;
              }

              // Case 2: Eligible to Claim
              if (data.is_eligible || data.watch_state === 'ELIGIBLE') {
                isEligible = true;
                if (rewardBadge) {
                  rewardBadge.className = 'badge px-2.5 py-1 fw-bold text-uppercase';
                  rewardBadge.style.cssText = 'font-size: 0.72rem; letter-spacing: 0.03em; border-radius: 6px; background: #198754; color: #ffffff;';
                  rewardBadge.innerHTML = '<i class="fas fa-gift me-1"></i> Đủ điều kiện nhận voucher';
                }
                if (rewardStatusText) {
                  rewardStatusText.className = 'fw-bold text-success';
                  rewardStatusText.textContent = 'Đã đủ điều kiện nhận voucher!';
                }
                if (rewardProgressBar) {
                  rewardProgressBar.style.background = 'linear-gradient(90deg, #198754 0%, #20c997 100%)';
                }

                if (rewardActionArea) rewardActionArea.classList.remove('d-none');
                if (claimedBox) claimedBox.classList.add('d-none');

                if (isUserLoggedIn) {
                  if (claimBtnContainer) claimBtnContainer.classList.remove('d-none');
                  if (guestLoginContainer) guestLoginContainer.classList.add('d-none');
                } else {
                  if (claimBtnContainer) claimBtnContainer.classList.add('d-none');
                  if (guestLoginContainer) guestLoginContainer.classList.remove('d-none');
                }
              } else if (data.watch_state === 'EXPIRED' || data.code === 'CAMPAIGN_EXPIRED') {
                if (rewardBadge) {
                  rewardBadge.className = 'badge px-2.5 py-1 fw-semibold text-uppercase bg-danger text-white';
                  rewardBadge.style.cssText = 'font-size: 0.72rem; letter-spacing: 0.03em; border-radius: 6px;';
                  rewardBadge.innerHTML = '<i class="fas fa-clock me-1"></i> Đã kết thúc';
                }
                if (rewardStatusText) {
                  rewardStatusText.className = 'fw-medium text-danger';
                  rewardStatusText.textContent = 'Chiến dịch ưu đãi đã kết thúc hoặc hết hạn.';
                }
                if (rewardActionArea) rewardActionArea.classList.remove('d-none');
                if (claimBtnContainer) claimBtnContainer.classList.add('d-none');
                if (guestLoginContainer) guestLoginContainer.classList.add('d-none');
                if (errorAlert) {
                  errorAlert.classList.remove('d-none');
                  if (errorMsg) errorMsg.textContent = 'Chiến dịch ưu đãi đã kết thúc hoặc tạm dừng.';
                }
              } else {
                if (percent > 0) {
                  if (rewardBadge && !isEligible) {
                    rewardBadge.className = 'badge px-2.5 py-1 fw-semibold text-uppercase';
                    rewardBadge.style.cssText = 'font-size: 0.72rem; letter-spacing: 0.03em; border-radius: 6px; background: #e0f2fe; color: #0284c7;';
                    rewardBadge.innerHTML = `<i class="fas fa-spinner fa-spin me-1"></i> Đang xem (${Math.round(percent)}%)`;
                  }
                }
                if (rewardStatusText && !isEligible) {
                  rewardStatusText.className = 'fw-medium text-secondary';
                  rewardStatusText.textContent = 'Xem hết video để mở khóa ưu đãi';
                }
              }
            }

            // Claim Reward Handler with strict double-click prevention
            if (claimBtn) {
              claimBtn.addEventListener('click', async () => {
                if (isClaiming || claimBtn.disabled || !currentWatchSessionId) return;
                isClaiming = true;
                claimBtn.disabled = true;
                const originalHtml = claimBtn.innerHTML;
                claimBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Đang xử lý cấp voucher...';
                if (errorAlert) errorAlert.classList.add('d-none');

                try {
                  const res = await fetch('<?= BASE_URL ?>/index.php?r=video_watch_claim_reward', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ watch_session_id: currentWatchSessionId })
                  });
                  const data = await res.json();

                  if (data.ok && data.voucher) {
                    // Success! Display claimed voucher immediately
                    if (claimBtnContainer) claimBtnContainer.classList.add('d-none');
                    if (claimedBox) claimedBox.classList.remove('d-none');
                    if (claimedVoucherCode) claimedVoucherCode.textContent = data.voucher.voucher_code || '';
                    if (claimedDate) claimedDate.textContent = 'Ngày nhận: ' + (data.voucher.claimed_at || 'Vừa xong');
                    if (rewardBadge) {
                      rewardBadge.className = 'badge px-2.5 py-1 fw-bold text-uppercase';
                      rewardBadge.style.cssText = 'font-size: 0.72rem; letter-spacing: 0.03em; border-radius: 6px; background: #198754; color: #ffffff;';
                      rewardBadge.innerHTML = '<i class="fas fa-check-circle me-1"></i> Đã nhận voucher';
                    }
                    if (rewardStatusText) {
                      rewardStatusText.className = 'fw-bold text-success';
                      rewardStatusText.textContent = data.message || 'Chúc mừng bạn đã nhận voucher thành công!';
                    }
                  } else {
                    if (errorAlert) {
                      errorAlert.classList.remove('d-none');
                      if (errorMsg) errorMsg.textContent = data.message || 'Không thể nhận voucher lúc này.';
                    }
                    // If user already claimed, render the existing voucher
                    if (data.code === 'USER_LIMIT_REACHED' && data.voucher) {
                      if (claimBtnContainer) claimBtnContainer.classList.add('d-none');
                      if (claimedBox) claimedBox.classList.remove('d-none');
                      if (claimedVoucherCode) claimedVoucherCode.textContent = data.voucher.voucher_code || '';
                    } else {
                      claimBtn.disabled = false;
                      claimBtn.innerHTML = originalHtml;
                    }
                  }
                } catch (err) {
                  if (errorAlert) {
                    errorAlert.classList.remove('d-none');
                    if (errorMsg) errorMsg.textContent = 'Lỗi kết nối máy chủ. Vui lòng thử lại sau.';
                  }
                  claimBtn.disabled = false;
                  claimBtn.innerHTML = originalHtml;
                } finally {
                  isClaiming = false;
                }
              });
            }

            // Copy voucher code helper
            if (copyVoucherBtn) {
              copyVoucherBtn.addEventListener('click', () => {
                const code = claimedVoucherCode ? claimedVoucherCode.textContent.trim() : '';
                if (!code || code === '---') return;
                navigator.clipboard.writeText(code).then(() => {
                  const orig = copyVoucherBtn.innerHTML;
                  copyVoucherBtn.innerHTML = '<i class="fas fa-check"></i> Đã chép!';
                  setTimeout(() => { copyVoucherBtn.innerHTML = orig; }, 2000);
                });
              });
            }

            async function startWatchSession() {
              if (!currentCampId || !currentPubId || isSessionStarting) return;
              isSessionStarting = true;
              try {
                const res = await fetch('<?= BASE_URL ?>/index.php?r=video_watch_start', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    publication_id: currentPubId,
                    video_id: currentVideoId,
                    campaign_id: currentCampId
                  })
                });
                const data = await res.json();
                if (data.ok && data.session) {
                  currentWatchSessionId = data.session.watch_session_id;
                  if (data.session.video_duration > 0) {
                    videoDuration = data.session.video_duration;
                  }
                  updateRewardUI(data.session);
                }
              } catch (e) {
                // Silently handle network errors
              } finally {
                isSessionStarting = false;
              }
            }

            async function sendHeartbeat(isPaused = false) {
              if (!currentWatchSessionId) {
                await startWatchSession();
                if (!currentWatchSessionId) return;
              }

              try {
                const res = await fetch('<?= BASE_URL ?>/index.php?r=video_watch_heartbeat', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    watch_session_id: currentWatchSessionId,
                    current_time: player.currentTime || 0,
                    playback_rate: player.playbackRate || 1.0,
                    is_paused: isPaused
                  })
                });
                const data = await res.json();
                if (data.ok) {
                  updateRewardUI(data);
                } else if (data.code === 'CAMPAIGN_EXPIRED') {
                  updateRewardUI(data);
                }
              } catch (e) {
                // Ignore transient network errors
              }
            }

            async function verifyCompletion() {
              if (!currentWatchSessionId) return;
              try {
                const res = await fetch('<?= BASE_URL ?>/index.php?r=video_watch_complete', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    watch_session_id: currentWatchSessionId
                  })
                });
                const data = await res.json();
                if (data.ok && data.is_eligible) {
                  updateRewardUI(data);
                }
              } catch (e) {}
            }

            function startHeartbeatInterval() {
              if (heartbeatTimer) clearInterval(heartbeatTimer);
              heartbeatTimer = setInterval(() => {
                if (!player.paused && !player.seeking) {
                  sendHeartbeat(false);
                }
              }, 4000);
            }

            function stopHeartbeatInterval() {
              if (heartbeatTimer) {
                clearInterval(heartbeatTimer);
                heartbeatTimer = null;
              }
            }

            // Overlay click initiates playback with sound safely
            if (overlay) {
              overlay.addEventListener('click', () => {
                overlay.classList.add('is-hidden');
                player.play().catch(() => {});
              });
            }

            // Buffering / Loading states
            player.addEventListener('waiting', () => {
              if (loading) loading.classList.add('is-active');
            });
            player.addEventListener('playing', () => {
              if (loading) loading.classList.remove('is-active');
              if (errorBox) errorBox.classList.remove('is-active');
              if (overlay) overlay.classList.add('is-hidden');
              startHeartbeatInterval();
              sendHeartbeat(false);
            });
            player.addEventListener('pause', () => {
              stopHeartbeatInterval();
              sendHeartbeat(true);
            });
            player.addEventListener('ended', async () => {
              stopHeartbeatInterval();
              await sendHeartbeat(true);
              await verifyCompletion();
            });
            player.addEventListener('canplay', () => {
              if (loading) loading.classList.remove('is-active');
            });

            // Error handling state
            player.addEventListener('error', () => {
              if (loading) loading.classList.remove('is-active');
              if (errorBox) errorBox.classList.add('is-active');
              stopHeartbeatInterval();
            });

            if (retryBtn) {
              retryBtn.addEventListener('click', () => {
                if (errorBox) errorBox.classList.remove('is-active');
                if (loading) loading.classList.add('is-active');
                const src = player.src;
                player.src = '';
                player.src = src;
                player.load();
                player.play().catch(() => {});
              });
            }

            // Initial session start if reward card present
            if (rewardCard && currentCampId) {
              startWatchSession();
            }

            // Playlist switching
            const playlistItems = document.querySelectorAll('.syna-video-playlist-item');
            playlistItems.forEach(item => {
              item.addEventListener('click', () => {
                playlistItems.forEach(i => i.classList.remove('is-active'));
                item.classList.add('is-active');
                const newSrc = item.dataset.videoSrc;
                const newTitle = item.dataset.videoTitle;
                const newMeta = item.dataset.videoMeta;

                if (titleEl && newTitle) titleEl.textContent = newTitle;
                if (metaEl && newMeta) metaEl.textContent = newMeta;

                // Stop tracking for previous video
                stopHeartbeatInterval();
                currentWatchSessionId = null;
                isEligible = false;

                // Check reward campaign for new item
                const rawReward = item.dataset.rewardCampaign;
                if (rawReward && rawReward.trim() !== '') {
                  try {
                    const rewardData = JSON.parse(rawReward);
                    currentCampId = rewardData.campaign_id || '';
                    currentPubId = item.dataset.publicationId || '';
                    currentVideoId = item.dataset.videoId || '';
                    videoDuration = parseFloat(item.dataset.duration || '0');

                    if (rewardCard) {
                      rewardCard.classList.remove('d-none');
                      if (rewardCampaignName) rewardCampaignName.textContent = rewardData.campaign_name || 'Ưu đãi xem video';
                      if (rewardOfferText) {
                        if (rewardData.discount_type === 'percentage') {
                          rewardOfferText.textContent = `Giảm ${rewardData.discount_value}%` + (rewardData.max_discount_amount ? ` (tối đa ${Number(rewardData.max_discount_amount).toLocaleString()}đ)` : '');
                        } else {
                          rewardOfferText.textContent = `Giảm ${Number(rewardData.discount_value).toLocaleString()}đ`;
                        }
                        if (rewardData.min_order_amount) {
                          rewardOfferText.textContent += ` cho đơn từ ${Number(rewardData.min_order_amount).toLocaleString()}đ`;
                        }
                      }
                      // Reset UI
                      updateRewardUI({
                        watched_coverage_percent: 0,
                        watched_seconds: 0,
                        video_duration: videoDuration,
                        watch_state: 'IN_PROGRESS',
                        is_eligible: false
                      });
                      startWatchSession();
                    }
                  } catch (e) {
                    if (rewardCard) rewardCard.classList.add('d-none');
                  }
                } else {
                  if (rewardCard) rewardCard.classList.add('d-none');
                }

                if (overlay) overlay.classList.remove('is-hidden');
                if (errorBox) errorBox.classList.remove('is-active');
                player.pause();
                player.src = newSrc;
                player.load();
              });
            });
          })();
        </script>
      <?php endif; ?>

      <?php if (!empty($frequentlyBought)): ?>
        <?php
          $hasRealRule = false;
          foreach ($frequentlyBought as $fbCheck) {
            if (($fbCheck['recommendation_source'] ?? '') === 'ASSOCIATION_RULE') {
              $hasRealRule = true;
              break;
            }
          }
          $sectionTitle = $hasRealRule ? 'Sản phẩm khách hàng thường mua cùng' : 'Gợi ý kết hợp cho routine';
          $sectionBadge = $hasRealRule ? 'Thường được mua cùng' : 'Gợi ý chu trình';
        ?>
        <!-- Frequently Bought Together / Gợi Ý Mua Kèm (Pairwise Co-occurrence & Routine Fallback) -->
        <div class="frequently-bought-card mt-4 mb-2 p-4 rounded-4 bg-white border w-100" style="border-radius: 20px !important; border-color: #E2EADF !important; box-shadow: 0 4px 20px rgba(24, 59, 43, 0.04);">
          <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div>
              <div class="d-flex align-items-center gap-2">
                <span class="badge px-2.5 py-1 fw-bold text-uppercase" style="background: #E8F5E9; color: #1B5E20; font-size: 0.72rem; letter-spacing: 0.05em; border-radius: 6px;">
                  <i class="fa-solid fa-basket-shopping me-1"></i> Mua Kèm Tiết Kiệm
                </span>
                <span class="text-muted small"><?= $sectionBadge ?></span>
              </div>
              <h5 class="fw-bold mt-1 mb-0" style="color: #0F172A; font-size: 1.15rem;"><?= $sectionTitle ?></h5>
            </div>
            <?php if ($hasRealRule): ?>
              <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.78rem;">Phân tích giao dịch mua cùng</span>
            <?php else: ?>
              <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.78rem;">Gợi ý theo bước dưỡng</span>
            <?php endif; ?>
          </div>

          <div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 g-3">
            <?php foreach ($frequentlyBought as $fbItem): ?>
              <?php 
                $fbImg = !empty($fbItem['hinh_anh']) ? $fbItem['hinh_anh'] : default_placeholder_image();
                $fbUrl = BASE_URL . '/index.php?r=chitiet&id=' . urlencode((string)$fbItem['ma_san_pham']);
                $source = $fbItem['recommendation_source'] ?? '';
                $isGenuineRule = ($source === 'ASSOCIATION_RULE');
                $hasConfidence = $isGenuineRule && isset($fbItem['confidence']) && $fbItem['confidence'] !== null && $fbItem['confidence'] > 0;
                $fbConf = $hasConfidence ? round($fbItem['confidence'] * 100) : null;
              ?>
              <div class="col">
                <div class="card h-100 border p-2.5 d-flex flex-column justify-content-between position-relative" style="border-radius: 14px; border-color: #EEF2F6 !important; background: #FAFCF9; transition: transform 0.2s, box-shadow 0.2s;">
                  <div>
                    <a href="<?= $fbUrl ?>" class="d-block text-center mb-2 overflow-hidden" style="border-radius: 10px; background: #fff; aspect-ratio: 1/1;">
                      <img src="<?= h($fbImg) ?>" 
                           alt="<?= h($fbItem['ten_san_pham'] ?? '') ?>"
                           class="w-100 h-100 object-fit-cover"
                           referrerpolicy="no-referrer"
                           onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';">
                    </a>
                    <div class="mb-1">
                      <?php if ($isGenuineRule && $fbConf !== null): ?>
                        <span class="badge" style="background: #E8F5E9; color: #2E7D32; font-size: 0.68rem; border-radius: 4px;">
                          <?= $fbConf ?>% khách chọn cùng
                        </span>
                      <?php elseif ($source === 'ROUTINE_COMPLEMENT'): ?>
                        <span class="badge" style="background: #E0F2FE; color: #0369A1; font-size: 0.68rem; border-radius: 4px;">
                          Gợi ý kết hợp cho routine
                        </span>
                      <?php elseif ($source === 'BRAND_COMPLEMENT'): ?>
                        <span class="badge" style="background: #FEF3C7; color: #92400E; font-size: 0.68rem; border-radius: 4px;">
                          Cùng thương hiệu
                        </span>
                      <?php else: ?>
                        <span class="badge" style="background: #F1F5F9; color: #475569; font-size: 0.68rem; border-radius: 4px;">
                          Có thể bạn cũng quan tâm
                        </span>
                      <?php endif; ?>
                    </div>
                    <a href="<?= $fbUrl ?>" class="text-decoration-none text-dark d-block fw-semibold text-truncate-2 mb-2" style="font-size: 0.85rem; line-height: 1.35; height: 2.7rem; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;" title="<?= h($fbItem['ten_san_pham'] ?? '') ?>">
                      <?= h($fbItem['ten_san_pham'] ?? 'Sản phẩm liên quan') ?>
                    </a>
                  </div>
                  <div>
                    <div class="d-flex align-items-baseline justify-content-between mb-2">
                      <span class="fw-bold" style="color: #183B2B; font-size: 0.92rem;">
                        <?= vnd($fbItem['gia_ban'] ?? 0) ?>
                      </span>
                      <?php if (!empty($fbItem['gia_thi_truong']) && $fbItem['gia_thi_truong'] > ($fbItem['gia_ban'] ?? 0)): ?>
                        <span class="text-muted text-decoration-line-through small" style="font-size: 0.75rem;">
                          <?= vnd($fbItem['gia_thi_truong']) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                    <a href="<?= $fbUrl ?>" class="btn btn-sm w-100 fw-semibold" style="background: #183B2B; color: #fff; border-radius: 8px; font-size: 0.78rem;">
                      <i class="fa-solid fa-eye me-1"></i> Xem ngay
                    </a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="detail-tabs-card mt-4 p-4 p-md-5 rounded-4 bg-white border w-100" style="border-radius: 28px !important; border-color: #E2EADF !important; box-shadow: 0 10px 30px rgba(33, 84, 39, 0.04);">
        <div class="detail-tabs-header mb-4 pb-2 border-bottom">
          <ul class="nav nav-pills flex-nowrap overflow-x-auto gap-2" id="detailTabNav" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active px-3.5 py-2 fw-bold" id="tab-mo-ta" type="button" data-bs-toggle="tab" data-bs-target="#pane-mo-ta" data-tab="mo-ta" aria-controls="pane-mo-ta">Mô tả</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link px-3.5 py-2 fw-bold" id="tab-thong-so" type="button" data-bs-toggle="tab" data-bs-target="#pane-thong-so" data-tab="thong-so" aria-controls="pane-thong-so">Thông số</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link px-3.5 py-2 fw-bold" id="tab-thanh-phan" type="button" data-bs-toggle="tab" data-bs-target="#pane-thanh-phan" data-tab="thanh-phan" aria-controls="pane-thanh-phan">Thành phần</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link px-3.5 py-2 fw-bold" id="tab-hdsd" type="button" data-bs-toggle="tab" data-bs-target="#pane-hdsd" data-tab="hdsd" aria-controls="pane-hdsd">HDSD</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link px-3.5 py-2 fw-bold" id="tab-danh-gia" type="button" data-bs-toggle="tab" data-bs-target="#pane-danh-gia" data-tab="danh-gia" aria-controls="pane-danh-gia">Đánh giá (<?= h((string)$reviewCountDisplay) ?>)</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link px-3.5 py-2 fw-bold" id="tab-hoi-dap" type="button" data-bs-toggle="tab" data-bs-target="#pane-hoi-dap" data-tab="hoi-dap" aria-controls="pane-hoi-dap">Hỏi đáp</button>
            </li>
          </ul>
        </div>

        <div class="tab-content">
          <div class="box-text mb-3 tab-pane-content" id="pane-mo-ta" data-pane="mo-ta" role="tabpanel" aria-labelledby="tab-mo-ta">
            <?php if ($moTaHtml !== ''): ?>
              <div class="detail-rich-copy"><?= $moTaHtml ?></div>
            <?php else: ?>
              <div class="detail-empty-state">Chưa có mô tả chi tiết cho sản phẩm này.</div>
            <?php endif; ?>
          </div>

        <div class="box-text mb-3 tab-pane-content d-none" id="pane-thong-so" data-pane="thong-so" role="tabpanel" aria-labelledby="tab-thong-so">
          <h5>Thông số</h5>
          <div class="spec-grid">
            <div><b>Thương Hiệu</b><br><?= h($p['thuong_hieu'] ?? '') ?></div>
            <div><b>Xuất xứ thương hiệu</b><br><?= h($p['xuat_xu_thuong_hieu'] ?? '') ?></div>
            <div><b>Nơi sản xuất</b><br><?= h($p['noi_san_xuat'] ?? '') ?></div>
            <div><b>Loại da</b><br><?= h($displayLoaiDa ?? ($p['loai_da'] ?? 'Không áp dụng')) ?></div>
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
                <input type="hidden" name="ma_hoa_don" value="<?= h((string)($reviewPermission['ma_hoa_don'] ?? $reviewPermission['order_id'] ?? '')) ?>">
                <input type="hidden" name="ma_chi_tiet_hoa_don" value="<?= h((string)($reviewPermission['ma_chi_tiet_hoa_don'] ?? $reviewPermission['order_item_id'] ?? '')) ?>">
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



