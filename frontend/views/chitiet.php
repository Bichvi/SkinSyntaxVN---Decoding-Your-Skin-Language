<?php
$p = $p ?? [];
$imgs = function_exists('product_image_urls') ? product_image_urls($p, 10) : split_image_urls($p['link_hinh_anh'] ?? '', 10);
$main = $imgs[0] ?? default_placeholder_image();
$detailImageFallbacks = function_exists('product_image_fallbacks_json') ? product_image_fallbacks_json($p, $main) : '[]';
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
    line-height: 1.72;
    overflow-wrap: anywhere;
  }

  .detail-rich-copy__list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 9px;
  }

  .detail-rich-copy__list li {
    position: relative;
    margin: 0;
    padding: 0 0 0 22px;
    color: #425466;
    line-height: 1.68;
    overflow-wrap: anywhere;
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
    line-height: 1.68;
    overflow-wrap: anywhere;
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


  .syna-policy-row .purchase-policy-card { margin-top: 0; height: 100%; }
  .syna-policy-row .syna-video-strip--row { margin-top: 0; height: 100%; width: 100%; padding: 1rem; box-shadow: none; border-radius: 12px; }
  @media (min-width: 768px) { .syna-policy-row .syna-video-strip--row { padding: 1.5rem; } }
  .detail-img, .detail-box { box-shadow: none; }
  .detail-img { display: flex; flex-direction: column; margin: 0; }
  .detail-img .detail-gallery-stage { flex: 1 1 auto; display: flex; align-items: center; justify-content: center; min-height: 0; }
  .detail-img .detail-gallery-stage .detail-main-img { height: auto; max-height: 440px; aspect-ratio: 1 / 1; }
  .syna-policy-row .purchase-policy-card { box-shadow: none; background: #fff; }
  .syna-policy-row .syna-video-strip__title, .syna-policy-row .purchase-policy-card h6 { font-size: 1rem; font-weight: 600; line-height: 1.3; margin: 0 0 12px; }
  .syna-policy-row .syna-video-strip__head { margin-bottom: 0; }
  .detail-chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 14px; padding: 0; list-style: none; }
  .detail-chip { display: inline-flex; align-items: center; max-width: 100%; padding: 3px 10px; border: 1px solid #dfe8ef; border-radius: 999px; background: #f6faf8; color: #334155; font-size: 0.78rem; line-height: 1.5; }
  .detail-chip b { margin-right: 4px; color: #183B2B; font-weight: 700; }
  .detail-stockline { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 6px 12px; font-size: 0.84rem; }
  .detail-ai-link { color: #183B2B; font-weight: 600; text-decoration: none; }
  .detail-ai-link:hover, .detail-ai-link:focus-visible { text-decoration: underline; }
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

  @media (max-width: 767.98px) {
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
  }

  /* Syna 3D Product Video Showcase */
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

  /* Voucher bar: slim one-line entry point under the AI CTA. */
  .syna-voucher-bar {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 12px;
    padding: 10px 12px 13px;
    border: 1px solid #f3dd9a;
    border-radius: 10px;
    background: #fff8e6;
    color: #6b4306;
    overflow: hidden;
  }
  .syna-voucher-bar__icon {
    flex: 0 0 auto;
    color: #c98a0b;
    font-size: 1.05rem;
  }
  .syna-voucher-bar__text {
    flex: 1 1 auto;
    min-width: 0;
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    column-gap: 10px;
    row-gap: 0;
  }
  .syna-voucher-bar__status {
    font-size: 0.84rem;
    font-weight: 700;
    line-height: 1.35;
  }
  .syna-voucher-bar__time {
    font-size: 0.74rem;
    font-weight: 500;
    color: #92702f;
    white-space: nowrap;
  }
  .syna-voucher-bar__btn {
    flex: 0 0 auto;
    border: 0;
    border-radius: 7px;
    padding: 7px 12px;
    color: #fff;
    background: #183b2b;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
    transition: transform 0.2s ease, background 0.2s ease;
  }
  .syna-voucher-bar__btn:hover,
  .syna-voucher-bar__btn:focus-visible {
    color: #fff;
    background: #236f47;
    transform: translateY(-1px);
  }
  .syna-voucher-bar__progress {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 3px;
    background: #f3e3b0;
  }
  .syna-voucher-bar__progress-bar {
    width: 0;
    height: 100%;
    background: linear-gradient(90deg, #1b7a48, #47c778);
    transition: width 0.4s ease;
  }

  /* Product video strip (horizontal, under the policy card / in the buy box on mobile). */
  .syna-video-strip {
    margin-top: 24px;
    padding: 18px 20px;
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 12px;
    background: #fff;
  }
  .syna-video-strip--inline {
    margin-top: 12px;
    padding: 14px;
  }
  .syna-video-strip__head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
  }
  .syna-video-strip__title {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #0f172a;
  }
  .syna-video-strip__title i {
    margin-right: 8px;
    color: #185334;
  }
  .syna-video-strip__count {
    margin-right: auto;
    font-size: 0.8rem;
    color: #64748b;
  }
  .syna-video-strip__nav {
    display: inline-flex;
    gap: 6px;
  }
  .syna-video-strip__nav[hidden] {
    display: none;
  }
  .syna-video-strip__arrow {
    display: inline-grid;
    place-items: center;
    width: 30px;
    height: 30px;
    padding: 0;
    border: 1px solid #d5e0da;
    border-radius: 50%;
    color: #183b2b;
    background: #fff;
    font-size: 0.75rem;
    transition: background 0.2s ease, border-color 0.2s ease, opacity 0.2s ease;
  }
  .syna-video-strip__arrow:hover:not(:disabled),
  .syna-video-strip__arrow:focus-visible {
    border-color: #185334;
    background: #edf6ee;
  }
  .syna-video-strip__arrow:disabled {
    opacity: 0.4;
    cursor: default;
  }
  .syna-video-strip__scroller {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    overflow-x: auto;
    overscroll-behavior-x: contain;
    scroll-snap-type: x proximity;
    scrollbar-width: thin;
    scrollbar-color: #c3d4ca transparent;
    padding-bottom: 6px;
  }
  .syna-video-strip__scroller::-webkit-scrollbar {
    height: 5px;
  }
  .syna-video-strip__scroller::-webkit-scrollbar-thumb {
    border-radius: 99px;
    background: #c3d4ca;
  }
  .syna-video-card {
    flex: 0 0 clamp(150px, 42%, 220px);
    min-width: 0;
    padding: 0;
    border: 0;
    border-radius: 10px;
    background: transparent;
    text-align: left;
    scroll-snap-align: start;
    cursor: pointer;
  }
  .syna-video-card.is-portrait {
    flex-basis: clamp(96px, 24%, 130px);
  }
  .syna-video-card:focus-visible {
    outline: 3px solid #185334;
    outline-offset: 2px;
  }
  .syna-video-card__media {
    position: relative;
    display: block;
    overflow: hidden;
    aspect-ratio: 16 / 9;
    border-radius: 10px;
    background: #163e2b;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12);
    transition: box-shadow 0.25s ease;
  }
  .syna-video-card.is-portrait .syna-video-card__media {
    aspect-ratio: 9 / 16;
  }
  .syna-video-card__media img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.35s ease;
  }
  .syna-video-card:hover .syna-video-card__media,
  .syna-video-card:focus-visible .syna-video-card__media {
    box-shadow: 0 8px 20px rgba(24, 59, 43, 0.22);
  }
  .syna-video-card:hover .syna-video-card__media img,
  .syna-video-card:focus-visible .syna-video-card__media img {
    transform: scale(1.05);
  }
  .syna-video-card__play {
    position: absolute;
    top: 50%;
    left: 50%;
    display: grid;
    place-items: center;
    width: 38px;
    height: 38px;
    transform: translate(-50%, -50%);
    border-radius: 50%;
    color: #fff;
    background: rgba(8, 20, 15, 0.55);
    border: 1.5px solid rgba(255, 255, 255, 0.85);
    font-size: 0.85rem;
    pointer-events: none;
  }
  .syna-video-card__play i {
    margin-left: 2px;
  }
  .syna-video-card__duration,
  .syna-video-card__new,
  .syna-video-card__gift {
    position: absolute;
    border-radius: 5px;
    color: #fff;
    font-size: 0.68rem;
    font-weight: 700;
    line-height: 1;
    pointer-events: none;
  }
  .syna-video-card__duration {
    right: 6px;
    bottom: 6px;
    padding: 3px 5px;
    background: rgba(8, 20, 15, 0.72);
  }
  .syna-video-card__new {
    top: 6px;
    left: 6px;
    padding: 4px 6px;
    background: #d9480f;
  }
  .syna-video-card__gift {
    left: 6px;
    bottom: 6px;
    display: grid;
    place-items: center;
    width: 22px;
    height: 22px;
    background: #e8a010;
    font-size: 0.65rem;
  }
  .syna-video-card__title {
    display: -webkit-box;
    overflow: hidden;
    margin-top: 7px;
    color: #1e293b;
    font-size: 0.8rem;
    font-weight: 600;
    line-height: 1.35;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
  }

  /* Floating mini player of the newest video. */
  .syna-mini-player {
    position: fixed;
    z-index: 1050;
    right: 24px;
    bottom: 164px;
    width: 220px;
    border-radius: 12px;
    overflow: hidden;
    background: #09130e;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.3);
  }
  .syna-mini-player.is-portrait {
    width: 128px;
  }
  .syna-mini-player[hidden] {
    display: none;
  }
  .syna-mini-player__body {
    position: relative;
    display: block;
    aspect-ratio: 16 / 9;
    cursor: pointer;
  }
  .syna-mini-player.is-portrait .syna-mini-player__body {
    aspect-ratio: 9 / 16;
  }
  .syna-mini-player__body:focus-visible {
    outline: 3px solid #fff;
    outline-offset: -3px;
  }
  .syna-mini-player__video {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    background: #09130e;
  }
  .syna-mini-player__shade {
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(0,0,0,0.25) 0%, rgba(0,0,0,0) 30%, rgba(0,0,0,0) 50%, rgba(0,0,0,0.72) 100%);
    pointer-events: none;
  }
  .syna-mini-player__badge {
    position: absolute;
    top: 6px;
    left: 6px;
    padding: 4px 6px;
    border-radius: 5px;
    color: #fff;
    background: #d9480f;
    font-size: 0.66rem;
    font-weight: 700;
    line-height: 1;
    pointer-events: none;
  }
  .syna-mini-player__caption {
    position: absolute;
    left: 8px;
    right: 34px;
    bottom: 7px;
    display: -webkit-box;
    overflow: hidden;
    color: #fff;
    font-size: 0.7rem;
    font-weight: 600;
    line-height: 1.3;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6);
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    pointer-events: none;
  }
  .syna-mini-player__close,
  .syna-mini-player__mute {
    position: absolute;
    display: grid;
    place-items: center;
    width: 26px;
    height: 26px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    color: #fff;
    background: rgba(8, 20, 15, 0.6);
    font-size: 0.7rem;
  }
  .syna-mini-player__close {
    top: 6px;
    right: 6px;
  }
  .syna-mini-player__mute {
    right: 6px;
    bottom: 6px;
  }
  .syna-mini-player__close:hover,
  .syna-mini-player__mute:hover,
  .syna-mini-player__close:focus-visible,
  .syna-mini-player__mute:focus-visible {
    background: rgba(8, 20, 15, 0.9);
  }
  .syna-mini-pill {
    position: fixed;
    z-index: 1050;
    right: 0;
    bottom: 164px;
    padding: 8px 12px 8px 14px;
    border: 0;
    border-radius: 999px 0 0 999px;
    color: #fff;
    background: #183b2b;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.25);
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
  }
  .syna-mini-pill[hidden] {
    display: none;
  }
  .syna-mini-pill:hover,
  .syna-mini-pill:focus-visible {
    background: #236f47;
  }
  @media (max-width: 575.98px) {
    .syna-mini-player {
      right: 16px;
      bottom: 136px;
      width: 168px;
    }
    .syna-mini-player.is-portrait {
      width: 96px;
    }
    .syna-mini-pill {
      bottom: 136px;
    }
  }
  @media (prefers-reduced-motion: reduce) {
    .syna-video-card__media,
    .syna-video-card__media img,
    .syna-voucher-bar__btn,
    .syna-voucher-bar__progress-bar {
      transition: none;
    }
    .syna-video-card:hover .syna-video-card__media img,
    .syna-video-card:focus-visible .syna-video-card__media img {
      transform: none;
    }
  }
  /* ===== Syna immersive video viewer (desktop: stage + side panel, mobile: full-screen + bottom sheet) ===== */
  /* Rendered inside a Bootstrap .row: the id selector cancels the `.row > *` gutter margin/width. Above the chat launchers (1084/1085), below toasts (1200). */
  #synaVideoModal {
    margin: 0;
    max-width: none;
  }
  .sv-block--reward.is-empty {
    display: none;
  }
  .syna-video-modal {
    position: fixed;
    z-index: 1090;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(4, 12, 8, 0.84);
  }
  .syna-video-modal.is-open {
    display: flex;
  }
  .syna-video-modal [hidden] {
    display: none !important;
  }
  body.syna-video-modal-open {
    overflow: hidden;
  }
  .sv-viewer {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1fr) 340px;
    gap: 14px;
    width: min(100%, 1240px);
    height: 88vh;
    max-height: 900px;
    color: #fff;
  }
  .sv-stage {
    position: relative;
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    overflow: hidden;
    border: 1px solid rgba(201, 162, 74, 0.2);
    border-radius: 18px;
    background: #0f1d15;
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
  }
  .sv-bg,
  .sv-tap,
  .sv-tapicon,
  .sv-chip,
  .sv-bottom,
  .sv-progress,
  .sv-sheet-backdrop,
  .sv-sheet-head {
    display: none;
  }
  .sv-head {
    position: relative;
    z-index: 10;
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    background: #1f2e25;
  }
  .sv-avatar {
    display: grid;
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    place-items: center;
    border-radius: 50%;
    color: #183b2b;
    background: linear-gradient(135deg, #e8cf8c, #c9a24a);
    font-size: 0.78rem;
    font-weight: 800;
  }
  .sv-head__text {
    flex: 1 1 auto;
    min-width: 0;
  }
  .sv-title {
    margin: 0;
    overflow: hidden;
    color: #fff;
    font-size: 0.98rem;
    font-weight: 700;
    line-height: 1.3;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .sv-sub {
    display: block;
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.72rem;
  }
  .sv-iconbtn {
    display: inline-grid;
    flex: 0 0 auto;
    width: 36px;
    height: 36px;
    place-items: center;
    border: 0;
    border-radius: 50%;
    color: #fff;
    background: rgba(255, 255, 255, 0.1);
    cursor: pointer;
    transition: background 0.15s ease;
  }
  .sv-iconbtn:hover {
    background: rgba(201, 162, 74, 0.35);
  }
  .sv-iconbtn:focus-visible,
  .sv-nav button:focus-visible,
  .sv-next:focus-visible,
  .sv-btn-cart:focus-visible,
  .sv-btn-buy:focus-visible,
  .sv-chip__btn:focus-visible,
  .sv-chip-btn:focus-visible,
  .sv-ring__claim:focus-visible {
    outline: 2px solid #e8cf8c;
    outline-offset: 2px;
  }
  .sv-mobile-only {
    display: none !important;
  }
  .sv-videobox {
    position: relative;
    z-index: 1;
    flex: 1 1 auto;
    min-height: 0;
    background: #09130e;
  }
  .sv-videobox video {
    position: absolute;
    inset: 0;
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: #000;
  }
  .sv-pill {
    position: absolute;
    z-index: 7;
    top: 12px;
    right: 12px;
    padding: 5px 12px;
    border: 1px solid #c9a24a;
    border-radius: 999px;
    color: #e8cf8c;
    background: rgba(15, 29, 21, 0.78);
    font-size: 0.76rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    pointer-events: none;
  }
  .sv-nav {
    position: absolute;
    z-index: 9;
    top: 50%;
    right: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    transform: translateY(-50%);
  }
  .sv-nav button {
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    border: 1px solid rgba(232, 207, 140, 0.45);
    border-radius: 50%;
    color: #e8cf8c;
    background: rgba(15, 29, 21, 0.74);
    cursor: pointer;
    transition: background 0.15s ease;
  }
  .sv-nav button:hover:not(:disabled) {
    background: rgba(201, 162, 74, 0.35);
  }
  .sv-nav button:disabled {
    opacity: 0.35;
    cursor: default;
  }
  .sv-toast {
    position: absolute;
    z-index: 12;
    top: 66px;
    left: 50%;
    padding: 7px 14px;
    border-radius: 999px;
    color: #183b2b;
    background: #e8cf8c;
    font-size: 0.78rem;
    font-weight: 700;
    transform: translateX(-50%);
    pointer-events: none;
  }
  .sv-toast--xu {
    white-space: nowrap;
    pointer-events: auto;
    animation: svToastRise 0.45s ease-out;
  }
  .sv-toast a {
    margin-left: 8px;
    color: #183b2b;
    font-weight: 800;
    text-decoration: underline;
  }
  @keyframes svToastRise {
    from { opacity: 0; transform: translate(-50%, 14px); }
    to { opacity: 1; transform: translate(-50%, 0); }
  }

  /* Side panel (desktop) / bottom sheet (mobile) */
  .sv-panel {
    min-width: 0;
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    border-radius: 18px;
    color: #12261b;
    background: #fff;
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.35);
  }
  .sv-panel__body {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 14px;
  }
  .sv-block + .sv-block {
    padding-top: 14px;
    border-top: 1px solid #edf2ee;
  }
  .sv-block__title {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 10px;
    color: #183b2b;
    font-size: 0.82rem;
    font-weight: 800;
  }
  .sv-block__title i {
    color: #c9a24a;
  }
  .sv-reward {
    display: block;
  }
  .sv-ticket-stub {
    display: none;
  }
  .sv-reward__row {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .sv-reward__text {
    flex: 1 1 auto;
    min-width: 0;
  }
  .sv-reward__name {
    overflow: hidden;
    color: #7a8a80;
    font-size: 0.68rem;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .sv-reward__offer {
    color: #183b2b;
    font-size: 0.9rem;
    font-weight: 800;
    line-height: 1.3;
  }
  .sv-reward__status {
    margin-top: 2px;
    color: #53675b;
    font-size: 0.76rem;
    line-height: 1.35;
  }
  .sv-reward__paused {
    display: inline-block;
    margin-top: 3px;
    color: #8a6a1c;
    font-size: 0.7rem;
    font-weight: 700;
  }
  .sv-badge {
    display: inline-block;
    margin-bottom: 3px;
    padding: 1px 8px;
    border-radius: 999px;
    color: #53675b;
    background: #eef3ef;
    font-size: 0.66rem;
    font-weight: 700;
  }
  .sv-badge.is-progress {
    color: #8a6a1c;
    background: #faf1d6;
  }
  .sv-badge.is-ready {
    color: #183b2b;
    background: #e8cf8c;
  }
  .sv-badge.is-done {
    color: #fff;
    background: #183b2b;
  }
  .sv-badge.is-expired {
    color: #b42318;
    background: #fde8e6;
  }
  .sv-reward__bar {
    display: none;
    height: 5px;
    margin-top: 10px;
    overflow: hidden;
    border-radius: 999px;
    background: #eadfbd;
  }
  .sv-reward__bar span {
    display: block;
    width: 0;
    height: 100%;
    border-radius: 999px;
    background: #c9a24a;
    transition: width 0.3s linear;
  }
  .sv-reward__time {
    margin-top: 4px;
    color: #7a8a80;
    font-size: 0.68rem;
    font-variant-numeric: tabular-nums;
  }
  .sv-reward__actions {
    margin-top: 10px;
  }
  .sv-guest {
    padding: 10px;
    border: 1px dashed #d8c48a;
    border-radius: 10px;
    background: #fffaf0;
    text-align: center;
  }
  .sv-guest p {
    margin: 0 0 8px;
    color: #53675b;
    font-size: 0.76rem;
  }
  .sv-link-btn {
    display: inline-block;
    padding: 7px 16px;
    border-radius: 999px;
    color: #183b2b;
    background: #c9a24a;
    font-size: 0.8rem;
    font-weight: 800;
    text-decoration: none;
  }
  .sv-link-btn:hover {
    color: #183b2b;
    background: #d8b45c;
  }
  .sv-claimed {
    padding: 10px;
    border: 1px solid #cfe3d2;
    border-radius: 10px;
    background: #f0f7f1;
  }
  .sv-claimed__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    color: #183b2b;
    font-size: 0.72rem;
    font-weight: 700;
  }
  .sv-claimed__code {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-top: 8px;
    padding: 6px 8px;
    border: 1px dashed #c9a24a;
    border-radius: 8px;
    background: #fff;
  }
  .sv-claimed__code strong {
    color: #183b2b;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 0.9rem;
    letter-spacing: 0.04em;
    word-break: break-all;
  }
  .sv-claimed__copy {
    flex: 0 0 auto;
    padding: 4px 10px;
    border: 1px solid #183b2b;
    border-radius: 999px;
    color: #183b2b;
    background: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    cursor: pointer;
  }
  .sv-claimed__foot {
    margin-top: 6px;
    color: #53675b;
    font-size: 0.72rem;
  }
  .sv-claimed__foot a {
    color: #183b2b;
    font-weight: 700;
    text-decoration: none;
  }
  .sv-alert {
    margin-top: 8px;
    padding: 7px 10px;
    border-radius: 8px;
    color: #8a4b0b;
    background: #fff3e0;
    font-size: 0.74rem;
  }
  .sv-reward__row--xu {
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px dashed #e4dcc2;
    color: #8a6a1c;
    font-size: 0.82rem;
    font-weight: 700;
  }
  .sv-reward__row--xu.is-done {
    color: #183b2b;
  }
  .sv-reward__row[hidden],
  .sv-checkin[hidden] {
    display: none;
  }
  /* Daily check-in row */
  .sv-checkin {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border: 1px solid #ead9a5;
    border-radius: 12px;
    background: #fffaf0;
  }
  .sv-checkin + .sv-checkin {
    margin-top: 10px;
  }
  .sv-ms-note {
    margin-top: 4px;
    color: #7a8a82;
    font-size: 0.68rem;
    line-height: 1.35;
  }
  .sv-reward:not(.d-none) + .sv-checkin {
    margin-top: 10px;
  }
  .sv-checkin .sv-ring__claim {
    font-size: 0.62rem;
  }

  /* Countdown ring */
  .sv-ring {
    position: relative;
    flex: 0 0 auto;
    width: 64px;
    height: 64px;
  }
  .sv-ring svg {
    display: block;
    width: 100%;
    height: 100%;
  }
  .sv-ring__track,
  .sv-ring__bar {
    fill: none;
    stroke-width: 4;
  }
  .sv-ring__track {
    stroke: rgba(201, 162, 74, 0.25);
  }
  .sv-ring__bar {
    stroke: #c9a24a;
    stroke-linecap: round;
    transition: stroke-dashoffset 0.3s linear;
  }
  .sv-ring__center {
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
  }
  .sv-ring__time {
    color: #183b2b;
    font-size: 0.8rem;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
  }
  .sv-ring__claimwrap {
    width: 72%;
    height: 72%;
  }
  .sv-ring__claim {
    width: 100%;
    height: 100%;
    padding: 0;
    border: 0;
    border-radius: 50%;
    color: #183b2b;
    background: #c9a24a;
    font-size: 0.74rem;
    font-weight: 800;
    cursor: pointer;
    animation: svPulse 1.6s ease-in-out infinite;
  }
  .sv-ring__claim:disabled {
    opacity: 0.7;
    cursor: default;
    animation: none;
  }
  .sv-ring__check {
    display: none;
    place-items: center;
    width: 72%;
    height: 72%;
    border-radius: 50%;
    color: #183b2b;
    background: #e8cf8c;
    font-size: 0.9rem;
  }
  .sv-ring.is-ready .sv-ring__time,
  .sv-ring.is-done .sv-ring__time {
    display: none;
  }
  .sv-ring.is-done .sv-ring__check {
    display: grid;
  }
  .sv-ring.is-expired .sv-ring__bar {
    stroke: #b9b3a0;
  }
  @keyframes svPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(201, 162, 74, 0.55); }
    50% { box-shadow: 0 0 0 7px rgba(201, 162, 74, 0); }
  }

  /* Product block */
  .sv-product {
    display: grid;
    grid-template-columns: 64px minmax(0, 1fr);
    gap: 10px;
    align-items: start;
  }
  .sv-product__img {
    width: 64px;
    height: 64px;
    border-radius: 10px;
    object-fit: cover;
    background: #f1f5f2;
  }
  .sv-product__brand {
    color: #7a8a80;
    font-size: 0.68rem;
  }
  .sv-product__name {
    display: -webkit-box;
    overflow: hidden;
    color: #12261b;
    font-size: 0.82rem;
    font-weight: 700;
    line-height: 1.35;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
  }
  .sv-product__price {
    color: #b42318;
    font-size: 0.9rem;
    font-weight: 800;
  }
  .sv-product__old {
    margin-left: 6px;
    color: #94a39a;
    font-size: 0.72rem;
    font-weight: 500;
    text-decoration: line-through;
  }
  .sv-product__actions {
    display: flex;
    grid-column: 1 / -1;
    gap: 8px;
  }
  .sv-cartform {
    margin: 0;
  }
  .sv-cartform--buy {
    flex: 1 1 auto;
  }
  .sv-btn-cart {
    width: 42px;
    height: 38px;
    border: 1px solid #cfe0d4;
    border-radius: 9px;
    color: #183b2b;
    background: #f3f8f4;
    cursor: pointer;
  }
  .sv-btn-buy {
    width: 100%;
    height: 38px;
    border: 0;
    border-radius: 9px;
    color: #fff;
    background: #183b2b;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
  }
  .sv-btn-buy:hover:not(:disabled) {
    background: #236f47;
  }
  .sv-btn-cart:disabled,
  .sv-btn-buy:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  /* Next videos */
  .sv-nextlist {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .sv-next {
    display: grid;
    grid-template-columns: 96px minmax(0, 1fr);
    gap: 10px;
    align-items: center;
    width: 100%;
    padding: 6px;
    border: 1px solid transparent;
    border-radius: 12px;
    background: transparent;
    text-align: left;
    cursor: pointer;
  }
  .sv-next:hover {
    background: #f4f8f5;
  }
  .sv-next.is-active {
    border-color: #c9a24a;
    background: #fbf6e8;
  }
  .sv-next__thumb {
    position: relative;
    aspect-ratio: 16 / 10;
    overflow: hidden;
    border-radius: 8px;
    background: #0f1d15;
  }
  .sv-next__thumb img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .sv-next__dur {
    position: absolute;
    right: 4px;
    bottom: 4px;
    padding: 1px 6px;
    border-radius: 6px;
    color: #fff;
    background: rgba(0, 0, 0, 0.65);
    font-size: 0.66rem;
    font-variant-numeric: tabular-nums;
  }
  .sv-next__title {
    display: -webkit-box;
    overflow: hidden;
    color: #12261b;
    font-size: 0.78rem;
    font-weight: 700;
    line-height: 1.35;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
  }
  .sv-next__tag {
    display: inline-block;
    margin-top: 3px;
    color: #8a6a1c;
    font-size: 0.66rem;
    font-weight: 700;
  }

  @media (max-width: 991.98px) {
    .syna-video-modal {
      align-items: stretch;
      padding: 0;
      background: #000;
    }
    .sv-viewer {
      display: block;
      width: 100%;
      height: 100vh;
      height: 100dvh;
      max-height: none;
    }
    .sv-stage {
      position: absolute;
      inset: 0;
      display: block;
      border: 0;
      border-radius: 0;
      background: #000;
      box-shadow: none;
    }
    .sv-bg {
      position: absolute;
      z-index: 0;
      inset: -30px;
      display: block;
      background-position: center;
      background-size: cover;
      filter: blur(26px) brightness(0.42);
    }
    .sv-videobox {
      position: absolute;
      inset: 0;
      background: transparent;
    }
    .sv-videobox video {
      background: transparent;
    }
    .sv-videobox .syna-video-cover-overlay {
      pointer-events: none;
      background: rgba(0, 0, 0, 0.28);
    }
    .sv-head {
      position: absolute;
      top: 0;
      right: 0;
      left: 0;
      padding: calc(10px + env(safe-area-inset-top)) 12px 26px;
      background: linear-gradient(180deg, rgba(0, 0, 0, 0.66), rgba(0, 0, 0, 0));
      pointer-events: none;
    }
    .sv-head > * {
      pointer-events: auto;
    }
    .sv-head__text {
      pointer-events: none;
    }
    .sv-iconbtn {
      background: rgba(0, 0, 0, 0.38);
    }
    .sv-mobile-only {
      display: inline-grid !important;
    }
    .sv-desktop-only {
      display: none !important;
    }
    .sv-tap {
      position: absolute;
      z-index: 4;
      inset: 0;
      display: block;
      touch-action: none;
      cursor: pointer;
    }
    .sv-tapicon {
      position: absolute;
      z-index: 3;
      inset: 0;
      display: grid;
      place-items: center;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.15s ease;
    }
    .sv-tapicon span {
      display: grid;
      width: 72px;
      height: 72px;
      place-items: center;
      border-radius: 50%;
      color: #fff;
      background: rgba(0, 0, 0, 0.45);
      font-size: 1.6rem;
    }
    .sv-viewer.has-started.is-paused .sv-tapicon {
      opacity: 1;
    }
    .sv-chip {
      position: absolute;
      z-index: 10;
      top: calc(62px + env(safe-area-inset-top));
      right: 12px;
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 6px 12px 6px 6px;
      border: 1px solid #c9a24a;
      border-radius: 12px;
      color: #e8cf8c;
      background: rgba(15, 29, 21, 0.74);
      -webkit-backdrop-filter: blur(6px);
      backdrop-filter: blur(6px);
      font-size: 0.78rem;
      font-weight: 700;
    }
    .sv-chip .sv-ring {
      width: 44px;
      height: 44px;
    }
    .sv-chip .sv-ring__track {
      stroke: rgba(232, 207, 140, 0.25);
    }
    .sv-chip .sv-ring__time {
      color: #e8cf8c;
      font-size: 0.66rem;
    }
    .sv-chip .sv-ring__check {
      font-size: 0.7rem;
    }
    .sv-chip .sv-ring.is-ready .sv-ring__check {
      display: grid;
    }
    .sv-chip__btn {
      padding: 5px 14px;
      border: 0;
      border-radius: 999px;
      color: #183b2b;
      background: #c9a24a;
      font-size: 0.78rem;
      font-weight: 800;
      text-decoration: none;
      cursor: pointer;
    }
    .sv-chip__btn:hover {
      color: #183b2b;
    }
    .sv-bottom {
      position: absolute;
      z-index: 10;
      right: 12px;
      bottom: calc(14px + env(safe-area-inset-bottom));
      left: 12px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      pointer-events: none;
    }
    .sv-bottom > * {
      pointer-events: auto;
    }
    .sv-pcard {
      position: relative;
      display: grid;
      grid-template-columns: 56px minmax(0, 1fr) auto;
      gap: 10px;
      align-items: center;
      padding: 8px;
      border-radius: 14px;
      color: #12261b;
      background: #fff;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.38);
    }
    .sv-viewer.is-card-dismissed .sv-pcard {
      display: none;
    }
    .sv-pcard__img {
      width: 56px;
      height: 56px;
      border-radius: 10px;
      object-fit: cover;
      background: #f1f5f2;
    }
    .sv-pcard__name {
      overflow: hidden;
      font-size: 0.8rem;
      font-weight: 700;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .sv-pcard__price {
      color: #b42318;
      font-size: 0.84rem;
      font-weight: 800;
    }
    .sv-pcard__actions {
      display: flex;
      gap: 6px;
    }
    .sv-pcard .sv-btn-buy {
      padding: 0 12px;
      white-space: nowrap;
    }
    .sv-pcard__close {
      position: absolute;
      top: -9px;
      right: -4px;
      display: grid;
      width: 24px;
      height: 24px;
      place-items: center;
      padding: 0;
      border: 0;
      border-radius: 50%;
      color: #fff;
      background: rgba(15, 29, 21, 0.9);
      font-size: 0.7rem;
      cursor: pointer;
    }
    .sv-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .sv-chip-btn {
      padding: 6px 14px;
      border: 0;
      border-radius: 999px;
      font-size: 0.78rem;
      font-weight: 800;
      cursor: pointer;
    }
    .sv-chip-btn--gold {
      color: #183b2b;
      background: #c9a24a;
      cursor: default;
    }
    .sv-chip-btn--ghost {
      color: #fff;
      background: rgba(255, 255, 255, 0.18);
      -webkit-backdrop-filter: blur(6px);
      backdrop-filter: blur(6px);
    }
    .sv-progress {
      position: absolute;
      z-index: 11;
      right: 0;
      bottom: 0;
      left: 0;
      display: block;
      height: 3px;
      background: rgba(255, 255, 255, 0.2);
    }
    .sv-progress span {
      display: block;
      width: 0;
      height: 100%;
      background: #c9a24a;
    }
    .sv-toast {
      top: calc(62px + env(safe-area-inset-top));
    }

    .sv-sheet-backdrop {
      position: fixed;
      z-index: 29;
      inset: 0;
      display: block;
      background: rgba(0, 0, 0, 0.55);
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.25s ease, visibility 0s linear 0.25s;
    }
    .sv-viewer.is-sheet-open .sv-sheet-backdrop {
      opacity: 1;
      visibility: visible;
      transition: opacity 0.25s ease;
    }
    .sv-panel {
      position: fixed;
      z-index: 30;
      right: 0;
      bottom: 0;
      left: 0;
      height: 68%;
      max-height: calc(100dvh - 56px);
      border-radius: 16px 16px 0 0;
      visibility: hidden;
      transform: translateY(105%);
      transition: transform 0.28s ease, visibility 0s linear 0.28s;
    }
    .sv-viewer.is-sheet-open .sv-panel {
      visibility: visible;
      transform: none;
      transition: transform 0.28s ease;
    }
    .sv-sheet-head {
      position: sticky;
      z-index: 2;
      top: 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 16px;
      border-bottom: 1px solid #edf2ee;
      color: #183b2b;
      background: #fff;
      font-size: 0.92rem;
      font-weight: 800;
    }
    .sv-sheet-head .sv-iconbtn {
      color: #183b2b;
      background: #eef3ef;
    }
    .sv-panel__body {
      padding: 14px 16px calc(20px + env(safe-area-inset-bottom));
    }
    .sv-block--next {
      display: none;
    }
    .sv-block--reward .sv-block__title {
      display: none;
    }
    .sv-reward {
      display: flex;
      overflow: hidden;
      border: 1px solid #ead9a5;
      border-radius: 12px;
      background: #fffaf0;
    }
    .sv-reward.d-none {
      display: none;
    }
    .sv-ticket-stub {
      display: grid;
      flex: 0 0 46px;
      place-items: center;
      color: #e8cf8c;
      background: #183b2b;
      font-size: 1.1rem;
    }
    .sv-reward__main {
      flex: 1 1 auto;
      min-width: 0;
      padding: 10px 12px;
      border-left: 2px dashed #d8c48a;
    }
    .sv-reward__row {
      flex-direction: row-reverse;
    }
    .sv-reward__bar {
      display: block;
    }
    .sv-ring {
      width: 52px;
      height: 52px;
    }
    .sv-ring__time {
      font-size: 0.7rem;
    }
    .sv-ring__claim {
      font-size: 0.66rem;
    }
  }
  @media (min-width: 992px) {
    .sv-block--reward .sv-block__title + .sv-reward {
      margin-top: 0;
    }
  }
  @media (prefers-reduced-motion: reduce) {
    .sv-ring__bar,
    .sv-reward__bar span,
    .sv-panel,
    .sv-sheet-backdrop,
    .sv-tapicon {
      transition: none !important;
    }
    .sv-ring__claim {
      animation: none;
    }
    .sv-toast--xu {
      animation: none;
    }
  }

  .detail-gallery-stage {
    position: relative;
  }

  .detail-gallery-arrow {
    position: absolute;
    top: 50%;
    z-index: 3;
    display: inline-flex;
    width: 40px;
    height: 40px;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(24, 59, 43, .18);
    border-radius: 50%;
    color: #183B2B;
    background: rgba(255, 255, 255, .94);
    box-shadow: 0 8px 18px rgba(15, 23, 42, .14);
    cursor: pointer;
    opacity: .92;
    transform: translateY(-50%);
    transition: transform .18s ease, background .18s ease, color .18s ease;
  }

  .detail-gallery-arrow:hover,
  .detail-gallery-arrow:focus-visible {
    color: #fff;
    background: #183B2B;
    outline: none;
    transform: translateY(-50%) scale(1.06);
  }

  .detail-gallery-arrow--prev { left: 12px; }
  .detail-gallery-arrow--next { right: 12px; }

  .detail-thumbs-shell {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr) 34px;
    align-items: center;
    gap: 6px;
    margin-top: 12px;
  }

  .detail-thumbs-shell .detail-thumbs {
    margin-top: 0 !important;
    min-width: 0;
    flex-wrap: nowrap;
    overflow-x: auto;
    scroll-behavior: smooth;
    scrollbar-width: thin;
  }

  .detail-thumbs-nav {
    display: inline-flex;
    width: 34px;
    height: 34px;
    align-items: center;
    justify-content: center;
    border: 1px solid #d9e7dd;
    border-radius: 50%;
    color: #183B2B;
    background: #fff;
    cursor: pointer;
  }

  .detail-thumbs-nav:hover,
  .detail-thumbs-nav:focus-visible {
    color: #fff;
    background: #183B2B;
    outline: none;
  }

  .detail-product-refresh {
    display: inline-flex;
    min-height: 32px;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border: 1px solid #c8dacF;
    border-radius: 999px;
    color: #183B2B;
    background: #fff;
    font-size: .75rem;
    font-weight: 700;
    white-space: nowrap;
    cursor: pointer;
    transition: transform .18s ease, color .18s ease, background .18s ease;
  }

  .detail-product-refresh:hover,
  .detail-product-refresh:focus-visible {
    color: #fff;
    background: #183B2B;
    outline: none;
    transform: translateY(-1px);
  }

  .detail-product-refresh.is-loading i {
    animation: detail-refresh-spin .75s linear infinite;
  }

  .detail-product-refresh:disabled {
    cursor: wait;
    opacity: .62;
  }

  .routine-recommend-actions {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 6px;
  }

  .routine-recommend-button {
    min-height: 35px;
    padding: 5px 4px;
    border-radius: 8px;
    font-size: .72rem;
    font-weight: 700;
    line-height: 1.15;
    white-space: nowrap;
  }

  .routine-recommend-button--cart {
    color: #183B2B;
    border: 1px solid #cbd5e1;
    background: #f1f5f9;
  }

  .routine-recommend-button--buy {
    color: #fff;
    border: 1px solid #183B2B;
    background: #183B2B;
  }

  .routine-recommend-button:disabled {
    cursor: not-allowed;
    opacity: .58;
  }

  @keyframes detail-refresh-spin {
    to { transform: rotate(360deg); }
  }

  @media (max-width: 575.98px) {
    .detail-gallery-arrow { width: 34px; height: 34px; }
    .detail-gallery-arrow--prev { left: 8px; }
    .detail-gallery-arrow--next { right: 8px; }
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

  <div class="row g-4 mb-4 align-items-stretch">
    <!-- Left: Gallery & Purchase Benefits -->
    <div class="col-12 col-lg-5 d-flex">
      <div class="detail-img border bg-white p-3 p-md-4 flex-fill w-100" style="border-radius: 12px; border-color: var(--border) !important;">
        <div class="detail-gallery-stage">
          <img id="mainImage"
               class="detail-main-img w-100"
               src="<?= h($main) ?>"
               fetchpriority="high"
               decoding="async"
               width="500"
               height="500"
               referrerpolicy="no-referrer"
               data-image-fallbacks="<?= $detailImageFallbacks ?>"
               data-image-placeholder="<?= h(default_placeholder_image()) ?>"
               onerror="window.SkinSyntaxImageFallback(this)"
               alt="<?= h($p['ten_san_pham'] ?? '') ?>"
               style="border-radius: 8px;">
          <?php if (count($imgs) > 1): ?>
            <button type="button" class="detail-gallery-arrow detail-gallery-arrow--prev" data-gallery-prev aria-label="Ảnh trước">
              <i class="fas fa-chevron-left" aria-hidden="true"></i>
            </button>
            <button type="button" class="detail-gallery-arrow detail-gallery-arrow--next" data-gallery-next aria-label="Ảnh tiếp theo">
              <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </button>
          <?php endif; ?>
        </div>

      <?php if (count($imgs) > 1): ?>
        <div class="detail-thumbs-shell" aria-label="Thư viện hình ảnh sản phẩm">
          <button type="button" class="detail-thumbs-nav" data-thumbs-prev aria-label="Lướt ảnh sản phẩm sang trái">
            <i class="fas fa-chevron-left" aria-hidden="true"></i>
          </button>
          <div class="detail-thumbs d-flex gap-2 overflow-x-auto pb-2">
            <?php foreach ($imgs as $i => $url): ?>
              <button type="button"
                      class="thumb-btn border p-1 <?= $i===0?'active':'' ?>"
                      data-src="<?= h($url) ?>"
                      data-fallbacks="<?= function_exists('product_image_fallbacks_json') ? product_image_fallbacks_json($p, $url) : '[]' ?>"
                      aria-label="Xem ảnh sản phẩm <?= (int)$i + 1 ?>"
                      aria-current="<?= $i === 0 ? 'true' : 'false' ?>"
                      style="width: 64px; height: 64px; flex-shrink: 0; background: #FFF; border-color: var(--border); border-radius: 6px;">
                <img src="<?= h($url) ?>"
                     loading="lazy"
                     decoding="async"
                     width="64"
                     height="64"
                     referrerpolicy="no-referrer"
                     data-image-fallbacks="<?= function_exists('product_image_fallbacks_json') ? product_image_fallbacks_json($p, $url) : '[]' ?>"
                     data-image-placeholder="<?= h(default_placeholder_image()) ?>"
                     onerror="window.SkinSyntaxImageFallback(this)"
                     alt="Ảnh sản phẩm <?= (int)$i + 1 ?>"
                     style="width: 100%; height: 100%; object-fit: cover; border-radius: 4px;">
              </button>
            <?php endforeach; ?>
          </div>
          <button type="button" class="detail-thumbs-nav" data-thumbs-next aria-label="Lướt ảnh sản phẩm sang phải">
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
          </button>
        </div>
      <?php endif; ?>
      </div>

<?php
  // Product video data shared by the strip (x2), voucher bar, mini player and modal.
  $synaVideoCards = [];
  $synaCurrentPid = (string)($p['ma_san_pham'] ?? ($id ?? ''));
  $synaFmtDur = static function ($seconds): string {
    $s = max(0, (int)round((float)$seconds));
    return intdiv($s, 60) . ':' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT);
  };
  if (!empty($productVideos)) {
    foreach (array_values($productVideos) as $synaIdx => $synaV) {
      $synaProxy = BASE_URL . '/index.php?r=product_video_stream&id=' . urlencode((string)($synaV['id'] ?? '')) . '&product_id=' . urlencode($synaCurrentPid);
      $synaDirect = trim((string)($synaV['video_url'] ?? ''));
      $synaTitle = trim((string)($synaV['title'] ?? ''));
      if ($synaTitle === '') {
        $synaTitle = $synaIdx === 0 ? 'Video giới thiệu & tư vấn sản phẩm' : 'Video ' . ($synaIdx + 1);
      }
      $synaPoster = trim((string)($synaV['poster_url'] ?? ''));
      $synaCardReward = !empty($synaV['reward_campaign']) ? $synaV['reward_campaign'] : null;
      $synaVideoCards[] = [
        'index' => $synaIdx,
        'title' => $synaTitle,
        'poster' => $synaPoster !== '' ? $synaPoster : $main,
        'portrait' => (string)($synaV['format'] ?? '') === '9:16',
        'duration' => (float)($synaV['duration'] ?? 0),
        'duration_label' => $synaFmtDur($synaV['duration'] ?? 0),
        'src' => $synaDirect !== '' ? $synaDirect : $synaProxy,
        'video_id' => (string)($synaV['video_id'] ?? ''),
        'publication_id' => (string)($synaV['id'] ?? ''),
        'reward' => $synaCardReward,
        'meta' => 'Độ phân giải: ' . (string)($synaV['resolution'] ?? '1280x720') . ' · ' . round((float)($synaV['duration'] ?? 0)) . 's',
      ];
    }
  }
  $renderVideoStrip = static function (string $uid, bool $inline) use ($synaVideoCards, $main): void {
    $count = count($synaVideoCards);
    $titleId = 'synaStripTitle-' . $uid;
    ?>
    <section class="syna-video-strip<?= $inline ? ' syna-video-strip--inline' : '' ?><?= $uid === 'row' ? ' syna-video-strip--row' : '' ?>" data-syna-strip aria-labelledby="<?= h($titleId) ?>">
      <div class="syna-video-strip__head">
        <h6 class="syna-video-strip__title" id="<?= h($titleId) ?>"><i class="fas fa-circle-play" aria-hidden="true"></i>Video về sản phẩm</h6>
        <span class="syna-video-strip__count"><?= (int)$count ?> video</span>
        <div class="syna-video-strip__nav" data-strip-nav hidden>
          <button type="button" class="syna-video-strip__arrow" data-strip-prev aria-label="Lướt về các video trước"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
          <button type="button" class="syna-video-strip__arrow" data-strip-next aria-label="Lướt tới các video sau"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
        </div>
      </div>
      <div class="syna-video-strip__scroller" data-strip-scroller>
        <?php foreach ($synaVideoCards as $card): ?>
          <button type="button"
                  class="syna-video-card<?= $card['portrait'] ? ' is-portrait' : '' ?>"
                  data-syna-strip-index="<?= (int)$card['index'] ?>"
                  aria-controls="synaVideoModal"
                  aria-label="Xem video: <?= h($card['title']) ?>">
            <span class="syna-video-card__media">
              <img src="<?= h($card['poster']) ?>"
                   alt=""
                   loading="lazy"
                   decoding="async"
                   referrerpolicy="no-referrer"
                   data-fallback="<?= h($main) ?>"
                   onerror="if(this.dataset.fallback&amp;&amp;this.src!==this.dataset.fallback){this.src=this.dataset.fallback;}">
              <span class="syna-video-card__play" aria-hidden="true"><i class="fas fa-play"></i></span>
              <span class="syna-video-card__duration"><?= h($card['duration_label']) ?></span>
              <?php if ($card['index'] === 0): ?><span class="syna-video-card__new">Mới nhất</span><?php endif; ?>
              <?php if (!empty($card['reward'])): ?><span class="syna-video-card__gift" title="Có voucher"><i class="fas fa-gift" aria-hidden="true"></i></span><?php endif; ?>
            </span>
            <span class="syna-video-card__title"><?= h($card['title']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
  };
?>

    </div>

    <!-- Right: Title, Specs, Price, Actions -->
    <div class="col-12 col-lg-7 d-flex">
      <div class="detail-box d-flex flex-column flex-fill w-100 p-3 p-md-4 bg-white border" style="border-radius: 12px; border-color: var(--border) !important;">
        <div class="text-uppercase fw-semibold small mb-1" style="letter-spacing: 0.04em; color: #183B2B; font-size: 0.75rem;"><?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
        <h2 class="detail-title fw-bold mb-2" style="color: #0F172A; font-size: 1.6rem; line-height: 1.35;"><?= h($p['ten_san_pham'] ?? '') ?></h2>

        <div class="d-flex align-items-center gap-3 mb-2">
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
        <div class="px-3 py-2 mb-3 border" style="background: <?= $isFlashSaleActive ? '#FFF5F5' : '#FAFAFA' ?>; border-radius: 8px; border-color: <?= $isFlashSaleActive ? '#FECACA' : 'var(--border)' ?> !important;">
          <?php if ($isFlashSaleActive): ?>
            <div class="d-flex align-items-center gap-2 mb-1">
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
            <span class="current fw-bold fs-4 tabular-nums" style="color: <?= $isFlashSaleActive ? '#DC2626' : '#183B2B' ?>;"><?= vnd($displayPrice) ?></span>
            <?php if ($displayStruckPrice > $displayPrice): ?>
              <span class="market text-muted text-decoration-line-through fs-6 tabular-nums"><?= vnd($displayStruckPrice) ?></span>
            <?php endif; ?>
            <?php if ($displayDiscountPercent !== null && $displayDiscountPercent > 0): ?>
              <span class="badge bg-danger fw-semibold ms-auto" style="font-size: 0.78rem; border-radius: 4px;">Giảm <?= h((string)$displayDiscountPercent) ?>%</span>
            <?php endif; ?>
          </div>
        </div>

        <?php
          $rawLoaiDa = trim((string)($p['loai_da'] ?? ''));
          $rawLoaiDaLower = mb_strtolower($rawLoaiDa, 'UTF-8');
          $isLoaiDaNa = ($rawLoaiDa === '' || in_array($rawLoaiDaLower, ['unknown', 'khong ap dung', 'không áp dụng'], true));
          $chipDanhMucFull = trim((string)($p['danh_muc_day_du'] ?? 'Chăm sóc da'));
          $chipDanhMucParts = array_values(array_filter(array_map('trim', explode('->', $chipDanhMucFull)), static fn($seg) => $seg !== ''));
          $chipDanhMuc = $chipDanhMucParts ? end($chipDanhMucParts) : '';
        ?>
        <ul class="detail-chips" aria-label="Thông tin sản phẩm">
          <li class="detail-chip"><b>Dung tích</b> <?= h($p['dung_tich'] ?? 'Tiêu chuẩn') ?></li>
          <li class="detail-chip"><b>Xuất xứ</b> <?= h($p['xuat_xu_thuong_hieu'] ?? 'Chính hãng') ?></li>
          <?php if (!$isLoaiDaNa): ?><li class="detail-chip"><b>Loại da</b> <?= h($rawLoaiDa) ?></li><?php endif; ?>
          <?php if ($chipDanhMuc !== ''): ?><li class="detail-chip" title="<?= h($chipDanhMucFull) ?>"><?= h($chipDanhMuc) ?></li><?php endif; ?>
        </ul>

        <?php
          $detailStock = $p['so_luong_ton_kho'] ?? $p['ton_kho_hien_thi'] ?? null;
          $detailStock = $detailStock === null || $detailStock === '' ? null : max(0, (int)$detailStock);
          $isOutOfStock = $detailStock !== null && $detailStock <= 0;
        ?>
        <div class="detail-buy mt-auto">
        <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
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

        <div class="detail-stockline mb-3">
          <span class="fw-semibold <?= $isOutOfStock ? 'text-danger' : 'text-success' ?>"><?= $isOutOfStock ? '● Tạm hết hàng' : ('● Còn hàng' . ($detailStock !== null ? ' · Còn ' . h((string)$detailStock) . ' sản phẩm' : '')) ?></span>
          <a href="<?= BASE_URL ?>/index.php?r=goiy" class="detail-ai-link">✦ Hỏi AI độ phù hợp →</a>
        </div>

        <?php if (!empty($productVideos)): ?>
          <?php
            $inlinePrimaryVideo = $productVideos[0];
            $inlinePrimaryReward = $inlinePrimaryVideo['reward_campaign'] ?? null;
            $inlineVideoDuration = (float)($inlinePrimaryVideo['duration'] ?? 0);
            $inlineDurFmt = gmdate($inlineVideoDuration >= 3600 ? 'H:i:s' : 'i:s', (int)round($inlineVideoDuration));
          ?>
          <?php if (!empty($inlinePrimaryReward)): ?>
            <!-- Optional video reward entry point: does not block purchase or product details. -->
            <div class="syna-voucher-bar" id="synaVideoOffer">
              <i class="fas fa-gift syna-voucher-bar__icon" aria-hidden="true"></i>
              <div class="syna-voucher-bar__text">
                <span class="syna-voucher-bar__status" id="synaVideoOfferStatus">Còn <?= h($inlineDurFmt) ?> để mở khóa voucher</span>
                <span class="syna-voucher-bar__time" id="synaVideoOfferTime">0:00 / <?= h($inlineDurFmt) ?> · còn <?= h($inlineDurFmt) ?></span>
              </div>
              <button type="button" class="syna-voucher-bar__btn" data-syna-video-open data-syna-strip-index="0" aria-controls="synaVideoModal">
                <i class="fas fa-play me-1" aria-hidden="true"></i> Xem ngay
              </button>
              <div class="syna-voucher-bar__progress" aria-hidden="true">
                <div class="syna-voucher-bar__progress-bar" id="synaVideoOfferProgressBar"></div>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4 syna-policy-row align-items-stretch">
    <div class="col-12 col-lg-5 d-flex">
      <div class="purchase-policy-card h-100 w-100 p-3 p-md-4 bg-white border" style="border-radius: 12px; border-color: var(--border) !important;">
        <h6 class="fw-semibold mb-3" style="color: #0F172A;"><i class="fas fa-shield-heart text-success me-2"></i> Quyền lợi mua hàng SkinSyntax</h6>
        <div class="purchase-policy-grid d-flex flex-column gap-2.5">
          <div class="d-flex align-items-start gap-3"><i class="fa-solid fa-truck text-success fs-6 mt-1"></i><div><strong class="d-block text-dark small fw-semibold">Miễn phí vận chuyển</strong><small class="text-muted">Cho đơn từ 300.000đ toàn quốc.</small></div></div>
          <div class="d-flex align-items-start gap-3"><i class="fa-solid fa-bolt text-warning fs-6 mt-1"></i><div><strong class="d-block text-dark small fw-semibold">Giao nhanh 2H</strong><small class="text-muted">Giao hỏa tốc trong nội thành.</small></div></div>
          <div class="d-flex align-items-start gap-3"><i class="fa-solid fa-shield-check text-success fs-6 mt-1"></i><div><strong class="d-block text-dark small fw-semibold">Cam kết 100% Thuần Chay & Chính Hãng</strong><small class="text-muted">Đền bù 100% nếu phát hiện hàng giả.</small></div></div>
        </div>
      </div>
    </div>
    <?php if (!empty($synaVideoCards)): ?>
      <div class="col-12 col-lg-7 d-flex"><?php $renderVideoStrip('row', false); ?></div>
    <?php endif; ?>
  </div>

  <div class="row g-4 mb-5">
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

            // Dữ liệu crawl đôi khi xuống dòng ở giữa một câu. Chỉ giữ cấu trúc
            // khi dòng thật sự là bullet/đánh số, còn câu thường sẽ được nối bằng khoảng trắng.
            $groups = [];
            $currentType = null;
            $currentLines = [];
            $flushGroup = static function () use (&$groups, &$currentType, &$currentLines): void {
              if ($currentType !== null && $currentLines !== []) {
                $groups[] = ['type' => $currentType, 'lines' => $currentLines];
              }
              $currentType = null;
              $currentLines = [];
            };

            foreach ($lines as $line) {
              $type = preg_match('/^(?:[-*•]|–)\s*/u', $line) === 1
                ? 'bullet'
                : (preg_match('/^(?:\d+[\.)]|bước\s*\d+)\s*/iu', $line) === 1 ? 'ordered' : 'text');
              if ($currentType !== null && $type !== $currentType) {
                $flushGroup();
              }
              $currentType = $type;
              $currentLines[] = $line;
            }
            $flushGroup();

            foreach ($groups as $group) {
              $groupLines = (array)($group['lines'] ?? []);
              if ($groupLines === []) {
                continue;
              }
              $type = (string)($group['type'] ?? 'text');
              if ($type === 'ordered' || $type === 'bullet') {
                $items = array_map(static function ($line) use ($type): string {
                  $pattern = $type === 'ordered'
                    ? '/^(?:\d+[\.)]|bước\s*\d+)\s*/iu'
                    : '/^(?:[-*•]|–)\s*/u';
                  $clean = preg_replace($pattern, '', (string)$line);
                  $clean = preg_replace('/\s+/u', ' ', trim((string)$clean));
                  return '<li>' . nl2br_safe($clean) . '</li>';
                }, $groupLines);
                $class = $type === 'ordered' ? 'detail-rich-copy__ordered' : 'detail-rich-copy__list';
                $html[] = '<' . ($type === 'ordered' ? 'ol' : 'ul') . ' class="' . $class . '">' . implode('', $items) . '</' . ($type === 'ordered' ? 'ol' : 'ul') . '>';
                continue;
              }

              $content = preg_replace('/\s+/u', ' ', trim(implode(' ', $groupLines)));
              $content = nl2br_safe((string)$content);
              if (!$hasLead && $mode === 'lead-first') {
                $html[] = '<p class="detail-rich-copy__lead">' . $content . '</p>';
                $hasLead = true;
              } else {
                $html[] = '<p class="detail-rich-copy__paragraph">' . $content . '</p>';
              }
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
          $initialProxyUrl = BASE_URL . '/index.php?r=product_video_stream&id=' . urlencode($primaryVid['id']) . '&product_id=' . urlencode($currentPid);
          $initialVideoUrl = trim((string)($primaryVid['video_url'] ?? ''));
          $initialStreamUrl = $initialVideoUrl !== '' ? $initialVideoUrl : $initialProxyUrl;
        ?>
        <?php
          $svCount = count($synaVideoCards);
          $svPoster0 = (string)($synaVideoCards[0]['poster'] ?? $main);
          $svPid = h((string)($p['ma_san_pham'] ?? $p['id'] ?? ($_GET['id'] ?? '')));
          $svOut = !empty($isOutOfStock);
          $svPrice = (int)($displayPrice ?? ($p['gia_ban'] ?? 0));
          $svOld = (int)($displayStruckPrice ?? 0);
          $primaryReward = $primaryVid['reward_campaign'] ?? null;
          $svOfferText = '';
          if (!empty($primaryReward)) {
            if (($primaryReward['discount_type'] ?? '') === 'percentage') {
              $svOfferText = 'Giảm ' . (float)$primaryReward['discount_value'] . '%'
                . (!empty($primaryReward['max_discount_amount']) ? ' (tối đa ' . number_format((float)$primaryReward['max_discount_amount']) . 'đ)' : '');
            } else {
              $svOfferText = 'Giảm ' . number_format((float)$primaryReward['discount_value']) . 'đ';
            }
            if (!empty($primaryReward['min_order_amount'])) {
              $svOfferText .= ' cho đơn từ ' . number_format((float)$primaryReward['min_order_amount']) . 'đ';
            }
          }
          $svPrimaryDur = (float)($primaryVid['duration'] ?? 0);
          $svPrimaryDurFmt = gmdate($svPrimaryDur >= 3600 ? 'H:i:s' : 'i:s', (int)round($svPrimaryDur));
          // Add-to-cart / buy-now form, posted like the page's own forms (handled by the global cart submit handler).
          $svCartForm = static function (bool $buyNow) use ($svPid, $svOut): void {
            ?>
            <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="sv-cartform<?= $buyNow ? ' sv-cartform--buy' : '' ?>">
              <input type="hidden" name="action" value="add_to_cart">
              <?php if ($buyNow): ?><input type="hidden" name="buy_now" value="1"><?php endif; ?>
              <input type="hidden" name="product_id" value="<?= $svPid ?>">
              <input type="hidden" name="ma_san_pham" value="<?= $svPid ?>">
              <input type="hidden" name="quantity" value="<?= $svOut ? 0 : 1 ?>">
              <input type="hidden" name="qty" value="<?= $svOut ? 0 : 1 ?>">
              <?php if ($buyNow): ?>
                <button type="submit" class="sv-btn-buy"<?= $svOut ? ' disabled' : '' ?>><?= $svOut ? 'Hết hàng' : 'Mua ngay' ?></button>
              <?php else: ?>
                <button type="submit" class="sv-btn-cart" aria-label="Thêm vào giỏ hàng"<?= $svOut ? ' disabled' : '' ?>><i class="fas fa-cart-plus" aria-hidden="true"></i></button>
              <?php endif; ?>
            </form>
            <?php
          };
          $svProductImg = static function (string $cls) use ($p, $main, $detailImageFallbacks): void {
            ?>
            <img class="<?= h($cls) ?>" src="<?= h($main) ?>" alt="<?= h($p['ten_san_pham'] ?? '') ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                 data-image-fallbacks="<?= $detailImageFallbacks ?>"
                 data-image-placeholder="<?= h(default_placeholder_image()) ?>"
                 onerror="window.SkinSyntaxImageFallback(this)">
            <?php
          };
        ?>
        <!-- Syna immersive product video viewer -->
        <div class="syna-video-modal" id="synaVideoModal" aria-hidden="true">
          <div class="sv-viewer" id="synaVideoViewer" data-reward-csrf="<?= h($rewardCsrf ?? '') ?>" role="dialog" aria-modal="true" aria-labelledby="synaVideoTitle">
            <div class="sv-stage">
              <div class="sv-bg" id="synaVideoBg" aria-hidden="true" style="background-image: url('<?= h($svPoster0) ?>');"></div>
              <header class="sv-head">
                <span class="sv-avatar" aria-hidden="true">S</span>
                <div class="sv-head__text">
                  <h2 class="sv-title" id="synaVideoTitle"><?= h($primaryVid['title'] ?? 'Video giới thiệu & tư vấn sản phẩm') ?></h2>
                  <span class="sv-sub" id="synaVideoMetaInfo">Syna · AI Idol · 1/<?= (int)$svCount ?></span>
                </div>
                <button type="button" class="sv-iconbtn sv-mobile-only" id="synaVideoMute" aria-label="Tắt tiếng"><i class="fas fa-volume-high" aria-hidden="true"></i></button>
                <button type="button" class="sv-iconbtn" id="synaVideoShare" aria-label="Chia sẻ video"><i class="fas fa-share-nodes" aria-hidden="true"></i></button>
                <button type="button" class="sv-iconbtn" id="synaVideoModalClose" aria-label="Đóng video"><i class="fas fa-times" aria-hidden="true"></i></button>
              </header>
              <div class="sv-toast" id="synaVideoToast" role="status" hidden></div>

              <div class="sv-videobox" id="synaVideoViewport" data-format="<?= !empty($synaVideoCards[0]['portrait']) ? '9:16' : '16:9' ?>">
                <video id="synaVideoPlayer"
                       playsinline
                       controls
                       preload="metadata"
                       poster="<?= h($svPoster0) ?>"
                       src="<?= h($initialStreamUrl) ?>">
                  Trình duyệt của bạn không hỗ trợ thẻ video HTML5.
                </video>

                <div class="syna-video-cover-overlay" id="synaVideoOverlay">
                  <div class="syna-video-play-btn mb-2"><i class="fas fa-play ps-1"></i></div>
                  <span class="fw-bold fs-6">Xem Syna tư vấn sản phẩm</span>
                  <small class="text-white-50 mt-1">Bấm để phát video có âm thanh thuyết minh</small>
                </div>
                <div class="sv-tap" id="synaVideoTap" aria-hidden="true"></div>
                <div class="sv-tapicon" aria-hidden="true"><span><i class="fas fa-play ps-1"></i></span></div>

                <div class="syna-video-loading-state" id="synaVideoLoading">
                  <div class="spinner-border text-light mb-2" role="status" style="width: 2.2rem; height: 2.2rem;"></div>
                  <span class="small">Đang tải video thuyết minh...</span>
                </div>
                <div class="syna-video-error-state" id="synaVideoError">
                  <i class="fas fa-exclamation-triangle text-warning fs-3 mb-2"></i>
                  <p class="mb-2 fw-semibold" id="synaVideoErrorMsg">Không thể phát video tại thời điểm này.</p>
                  <button type="button" class="btn btn-sm btn-outline-light px-3" id="synaVideoRetryBtn"><i class="fas fa-redo me-1"></i> Thử lại</button>
                </div>

                <div class="sv-pill sv-desktop-only" id="synaVideoPill" hidden>
                  <span id="synaVideoModalCountdown">Còn <?= h($svPrimaryDurFmt) ?> để nhận thưởng</span>
                </div>
              </div>

              <?php if ($svCount > 1): ?>
                <div class="sv-nav sv-desktop-only">
                  <button type="button" id="synaVideoPrev" aria-label="Video trước"><i class="fas fa-chevron-up" aria-hidden="true"></i></button>
                  <button type="button" id="synaVideoNext" aria-label="Video tiếp theo"><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
                </div>
              <?php endif; ?>

              <!-- Mobile: compact reward chip -->
              <div class="sv-chip" id="synaRewardChip" <?= empty($primaryReward) ? 'hidden' : '' ?>>
                <div class="sv-ring" id="synaRewardChipRing">
                  <svg viewBox="0 0 44 44" aria-hidden="true"><circle class="sv-ring__track" cx="22" cy="22" r="19"/><circle class="sv-ring__bar" cx="22" cy="22" r="19" transform="rotate(-90 22 22)"/></svg>
                  <div class="sv-ring__center"><span class="sv-ring__time"><?= h($svPrimaryDurFmt) ?></span><span class="sv-ring__check"><i class="fas fa-check" aria-hidden="true"></i></span></div>
                </div>
                <span id="synaRewardChipLabel">+Voucher</span>
                <button type="button" class="sv-chip__btn" id="synaRewardChipClaim" hidden>Nhận</button>
                <a class="sv-chip__btn" id="synaRewardChipLogin" href="<?= BASE_URL ?>/index.php?r=dangnhap" hidden>Đăng nhập</a>
              </div>

              <!-- Mobile: floating product card + chips -->
              <div class="sv-bottom">
                <div class="sv-pcard" id="synaProductCard">
                  <?php $svProductImg('sv-pcard__img'); ?>
                  <div class="min-w-0">
                    <div class="sv-pcard__name"><?= h($p['ten_san_pham'] ?? '') ?></div>
                    <div class="sv-pcard__price"><?= vnd($svPrice) ?></div>
                  </div>
                  <div class="sv-pcard__actions">
                    <?php $svCartForm(false); ?>
                    <?php $svCartForm(true); ?>
                  </div>
                  <button type="button" class="sv-pcard__close" id="synaProductCardClose" aria-label="Ẩn thẻ sản phẩm"><i class="fas fa-times" aria-hidden="true"></i></button>
                </div>
                <div class="sv-chips">
                  <span class="sv-chip-btn sv-chip-btn--gold" id="synaChipDiscount" <?= empty($primaryReward) ? 'hidden' : '' ?>>Giảm</span>
                  <button type="button" class="sv-chip-btn sv-chip-btn--ghost" id="synaSheetOpen" aria-controls="synaVideoPanel" aria-expanded="false">Xem sản phẩm (1) <i class="fas fa-chevron-up ms-1" aria-hidden="true"></i></button>
                </div>
              </div>
              <div class="sv-progress" aria-hidden="true"><span id="synaVideoPlayProgress"></span></div>
            </div>

            <div class="sv-sheet-backdrop" id="synaSheetBackdrop"></div>

            <aside class="sv-panel" id="synaVideoPanel" aria-label="Thông tin video">
              <div class="sv-sheet-head">
                <span>Ưu đãi &amp; sản phẩm</span>
                <button type="button" class="sv-iconbtn" id="synaSheetClose" aria-label="Đóng"><i class="fas fa-times" aria-hidden="true"></i></button>
              </div>
              <div class="sv-panel__body">
                <section class="sv-block sv-block--reward<?= empty($primaryReward) ? ' is-empty' : '' ?>" id="synaRewardBlock">
                  <h3 class="sv-block__title"><i class="fas fa-gift" aria-hidden="true"></i> Phần thưởng</h3>
                  <div class="sv-reward <?= empty($primaryReward) ? 'd-none' : '' ?>"
                       id="synaRewardCard"
                       data-campaign-id="<?= h($primaryReward['campaign_id'] ?? '') ?>"
                       data-publication-id="<?= h($primaryVid['id'] ?? '') ?>"
                       data-video-id="<?= h($primaryVid['video_id'] ?? '') ?>"
                       data-duration="<?= $svPrimaryDur ?>">
                    <div class="sv-ticket-stub" aria-hidden="true"><i class="fas fa-ticket"></i></div>
                    <div class="sv-reward__main">
                      <div class="sv-reward__row">
                        <div class="sv-ring" id="synaRewardRing" role="img" aria-label="Tiến độ xem để nhận thưởng">
                          <svg viewBox="0 0 44 44" aria-hidden="true"><circle class="sv-ring__track" cx="22" cy="22" r="19"/><circle class="sv-ring__bar" cx="22" cy="22" r="19" transform="rotate(-90 22 22)"/></svg>
                          <div class="sv-ring__center">
                            <span class="sv-ring__time"><?= h($svPrimaryDurFmt) ?></span>
                            <span class="sv-ring__claimwrap d-none" id="synaRewardClaimBtnContainer"><button type="button" class="sv-ring__claim" id="synaRewardClaimBtn">Nhận</button></span>
                            <span class="sv-ring__check"><i class="fas fa-check" aria-hidden="true"></i></span>
                          </div>
                        </div>
                        <div class="sv-reward__text">
                          <div class="sv-reward__name" id="synaRewardCampaignName"><?= h($primaryReward['campaign_name'] ?? 'Ưu đãi xem video Syna') ?></div>
                          <span class="sv-badge" id="synaRewardBadge"><i class="fas fa-lock me-1"></i> Chưa mở khóa</span>
                          <div class="sv-reward__offer">Voucher <span id="synaRewardOfferText"><?= h($svOfferText) ?></span></div>
                          <div class="sv-reward__status" id="synaRewardStatusText">Còn <?= h($svPrimaryDurFmt) ?> để mở khóa voucher</div>
                          <span class="sv-reward__paused" id="synaRewardPaused" hidden>Đang tạm dừng</span>
                        </div>
                      </div>
                      <div class="sv-reward__bar" aria-hidden="true"><span id="synaRewardProgressBar"></span></div>
                      <div class="sv-reward__time" id="synaRewardProgressTime">0:00 / <?= h($svPrimaryDurFmt) ?></div>

                      <div id="synaRewardActionArea" class="sv-reward__actions d-none">
                        <div id="synaRewardGuestLoginContainer" class="sv-guest d-none">
                          <p><i class="fas fa-check-circle me-1" style="color:#183b2b"></i> Bạn đã xem đủ thời lượng! Đăng nhập để nhận voucher vào ví.</p>
                          <a href="<?= BASE_URL ?>/index.php?r=dangnhap" class="sv-link-btn">Đăng nhập để nhận</a>
                        </div>
                        <div id="synaRewardClaimedBox" class="sv-claimed d-none">
                          <div class="sv-claimed__head">
                            <span><i class="fas fa-check-circle me-1"></i> Đã nhận voucher</span>
                            <span id="synaClaimedDate" class="fw-normal"></span>
                          </div>
                          <div class="sv-claimed__code">
                            <strong id="synaClaimedVoucherCode">---</strong>
                            <button type="button" id="synaCopyVoucherBtn" class="sv-claimed__copy"><i class="fas fa-copy"></i> Sao chép</button>
                          </div>
                          <div class="sv-claimed__foot">
                            <span id="synaClaimedDetailsText">Đã lưu vào ví voucher của bạn</span>
                            · <a href="<?= BASE_URL ?>/index.php?r=hoso#tab-vouchers">Xem trong Ví</a>
                          </div>
                        </div>
                        <div id="synaRewardErrorAlert" class="sv-alert d-none"><i class="fas fa-exclamation-triangle me-1"></i> <span id="synaRewardErrorMsg"></span></div>
                      </div>

                      <!-- "+N xu" for this video's campaign (shares the voucher progress) -->
                      <div class="sv-reward__row sv-reward__row--xu" data-reward-row="xu" hidden>
                        <i class="fas fa-coins" aria-hidden="true"></i> <span id="synaRewardXuLabel">+0 xu</span>
                      </div>
                    </div>
                  </div>

                  <?php if (!empty($isLoggedIn)): ?>
                  <!-- Daily check-in (needs 60s of video today) -->
                  <div class="sv-checkin" id="synaCheckinRow" hidden>
                    <div class="sv-ring" id="synaCheckinRing" role="img" aria-label="Tiến độ xem để điểm danh">
                      <svg viewBox="0 0 44 44" aria-hidden="true"><circle class="sv-ring__track" cx="22" cy="22" r="19"/><circle class="sv-ring__bar" cx="22" cy="22" r="19" transform="rotate(-90 22 22)"/></svg>
                      <div class="sv-ring__center">
                        <span class="sv-ring__time">1:00</span>
                        <span class="sv-ring__claimwrap d-none" id="synaCheckinBtnWrap"><button type="button" class="sv-ring__claim" id="synaCheckinBtn">+0 xu</button></span>
                        <span class="sv-ring__check"><i class="fas fa-check" aria-hidden="true"></i></span>
                      </div>
                    </div>
                    <div class="sv-reward__text">
                      <div class="sv-reward__offer">Điểm danh hôm nay</div>
                      <div class="sv-reward__name" id="synaCheckinSub"></div>
                      <div class="sv-reward__status" id="synaCheckinStatus">Xem video 1 phút để điểm danh</div>
                      <div class="sv-alert d-none" id="synaCheckinError" role="alert"></div>
                    </div>
                  </div>

                  <!-- Next watch milestone (5 / 15 / 30 min of verified watch time today) -->
                  <div class="sv-checkin sv-milestone" id="synaMsRow" hidden>
                    <div class="sv-ring" id="synaMsRing" role="img" aria-label="Tiến độ xem tới mốc thưởng">
                      <svg viewBox="0 0 44 44" aria-hidden="true"><circle class="sv-ring__track" cx="22" cy="22" r="19"/><circle class="sv-ring__bar" cx="22" cy="22" r="19" transform="rotate(-90 22 22)"/></svg>
                      <div class="sv-ring__center">
                        <span class="sv-ring__time">5:00</span>
                        <span class="sv-ring__claimwrap d-none" id="synaMsBtnWrap"><button type="button" class="sv-ring__claim" id="synaMsBtn">Nhận</button></span>
                        <span class="sv-ring__check"><i class="fas fa-check" aria-hidden="true"></i></span>
                      </div>
                    </div>
                    <div class="sv-reward__text">
                      <div class="sv-reward__offer" id="synaMsTitle">Mốc 5 phút</div>
                      <div class="sv-reward__status" id="synaMsStatus"></div>
                      <div class="sv-ms-note">Xem video khác để tích thêm phút · xem lại trong ngày không tính</div>
                      <div class="sv-alert d-none" id="synaMsError" role="alert"></div>
                    </div>
                  </div>
                  <?php endif; ?>
                </section>

                <section class="sv-block sv-block--products">
                  <h3 class="sv-block__title"><i class="fas fa-bag-shopping" aria-hidden="true"></i> Sản phẩm trong video</h3>
                  <div class="sv-product">
                    <?php $svProductImg('sv-product__img'); ?>
                    <div class="min-w-0">
                      <div class="sv-product__brand"><?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?></div>
                      <div class="sv-product__name"><?= h($p['ten_san_pham'] ?? '') ?></div>
                      <div class="sv-product__price"><?= vnd($svPrice) ?><?php if ($svOld > $svPrice): ?><span class="sv-product__old"><?= vnd($svOld) ?></span><?php endif; ?></div>
                    </div>
                    <div class="sv-product__actions">
                      <?php $svCartForm(false); ?>
                      <?php $svCartForm(true); ?>
                    </div>
                  </div>
                </section>

                <?php if ($svCount > 1): ?>
                  <section class="sv-block sv-block--next">
                    <h3 class="sv-block__title"><i class="fas fa-film" aria-hidden="true"></i> Video tiếp theo</h3>
                    <div class="sv-nextlist" id="synaVideoNextList">
                      <?php foreach ($synaVideoCards as $svCard): ?>
                        <button type="button" class="sv-next<?= (int)$svCard['index'] === 0 ? ' is-active' : '' ?>" data-sv-index="<?= (int)$svCard['index'] ?>"<?= (int)$svCard['index'] === 0 ? ' aria-current="true"' : '' ?>>
                          <span class="sv-next__thumb">
                            <img src="<?= h($svCard['poster']) ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                            <span class="sv-next__dur"><?= h($svCard['duration_label']) ?></span>
                          </span>
                          <span class="min-w-0">
                            <span class="sv-next__title"><?= h($svCard['title']) ?></span>
                            <?php if (!empty($svCard['reward'])): ?><span class="sv-next__tag"><i class="fas fa-gift" aria-hidden="true"></i> Có voucher</span><?php endif; ?>
                          </span>
                        </button>
                      <?php endforeach; ?>
                    </div>
                  </section>
                <?php endif; ?>
              </div>
            </aside>
          </div>
        </div>

        <?php
          $synaListJson = json_encode(array_map(static function ($c) {
            return [
              'src' => $c['src'],
              'video_id' => $c['video_id'],
              'publication_id' => $c['publication_id'],
              'duration' => $c['duration'],
              'reward' => $c['reward'],
              'title' => $c['title'],
              'meta' => $c['meta'],
              'poster' => $c['poster'],
              'portrait' => $c['portrait'],
            ];
          }, $synaVideoCards), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
          $miniCard = $synaVideoCards[0];
        ?>
        <script type="application/json" id="synaVideoListData"><?= $synaListJson !== false ? $synaListJson : '[]' ?></script>

        <!-- Floating mini player of the newest video; opens the shared modal. -->
        <div class="syna-mini-player<?= $miniCard['portrait'] ? ' is-portrait' : '' ?>"
             id="synaMiniPlayer"
             data-product-id="<?= h($synaCurrentPid) ?>"
             data-src="<?= h($miniCard['src']) ?>">
          <div class="syna-mini-player__body" role="button" tabindex="0" data-mini-open aria-controls="synaVideoModal" aria-label="Xem video mới nhất: <?= h($miniCard['title']) ?>">
            <video class="syna-mini-player__video" muted loop playsinline preload="none" poster="<?= h($miniCard['poster']) ?>"></video>
            <span class="syna-mini-player__shade" aria-hidden="true"></span>
            <span class="syna-mini-player__badge">Mới nhất</span>
            <span class="syna-mini-player__caption"><?= h($miniCard['title']) ?></span>
          </div>
          <button type="button" class="syna-mini-player__close" data-mini-close aria-label="Ẩn video"><i class="fas fa-xmark" aria-hidden="true"></i></button>
          <button type="button" class="syna-mini-player__mute" data-mini-mute aria-label="Bật tiếng"><i class="fas fa-volume-xmark" aria-hidden="true"></i></button>
        </div>
        <button type="button" class="syna-mini-pill" id="synaMiniPill" hidden aria-label="Xem video"><i class="fas fa-play me-1" aria-hidden="true"></i> Xem video</button>

        <script>
          (() => {
            const byId = (id) => document.getElementById(id);
            const player = byId('synaVideoPlayer');
            if (!player) return;

            const overlay = byId('synaVideoOverlay');
            const loading = byId('synaVideoLoading');
            const errorBox = byId('synaVideoError');
            const retryBtn = byId('synaVideoRetryBtn');
            const titleEl = byId('synaVideoTitle');
            const metaEl = byId('synaVideoMetaInfo');
            const videoModal = byId('synaVideoModal');
            const viewer = byId('synaVideoViewer');
            const panel = byId('synaVideoPanel');
            const videoModalClose = byId('synaVideoModalClose');
            const videoOpenButtons = document.querySelectorAll('[data-syna-video-open]:not([data-syna-strip-index])');
            const offerProgressBar = byId('synaVideoOfferProgressBar');
            const offerStatus = byId('synaVideoOfferStatus');
            const offerProgressTime = byId('synaVideoOfferTime');
            const modalCountdown = byId('synaVideoModalCountdown');
            const pillEl = byId('synaVideoPill');
            const videoBox = byId('synaVideoViewport');
            const bgEl = byId('synaVideoBg');
            const shareBtn = byId('synaVideoShare');
            const muteBtn = byId('synaVideoMute');
            const toastEl = byId('synaVideoToast');
            const prevBtn = byId('synaVideoPrev');
            const nextBtn = byId('synaVideoNext');
            const tapLayer = byId('synaVideoTap');
            const playProgress = byId('synaVideoPlayProgress');
            const nextItems = Array.from(document.querySelectorAll('#synaVideoNextList [data-sv-index]'));
            const sheetOpenBtn = byId('synaSheetOpen');
            const sheetCloseBtn = byId('synaSheetClose');
            const sheetBackdrop = byId('synaSheetBackdrop');
            const productCardClose = byId('synaProductCardClose');

            // Reward UI elements
            const rewardCard = byId('synaRewardCard');
            const rewardCampaignName = byId('synaRewardCampaignName');
            const rewardOfferText = byId('synaRewardOfferText');
            const rewardBadge = byId('synaRewardBadge');
            const rewardProgressBar = byId('synaRewardProgressBar');
            const rewardStatusText = byId('synaRewardStatusText');
            const rewardProgressTime = byId('synaRewardProgressTime');
            const rewardPaused = byId('synaRewardPaused');
            const rewardActionArea = byId('synaRewardActionArea');
            const claimBtnContainer = byId('synaRewardClaimBtnContainer');
            const claimBtn = byId('synaRewardClaimBtn');
            const guestLoginContainer = byId('synaRewardGuestLoginContainer');
            const claimedBox = byId('synaRewardClaimedBox');
            const claimedDate = byId('synaClaimedDate');
            const claimedVoucherCode = byId('synaClaimedVoucherCode');
            const copyVoucherBtn = byId('synaCopyVoucherBtn');
            const errorAlert = byId('synaRewardErrorAlert');
            const errorMsg = byId('synaRewardErrorMsg');
            const chipEl = byId('synaRewardChip');
            const chipLabel = byId('synaRewardChipLabel');
            const chipClaim = byId('synaRewardChipClaim');
            const chipLogin = byId('synaRewardChipLogin');
            const discountChip = byId('synaChipDiscount');
            const rewardBlock = byId('synaRewardBlock');
            const xuRow = document.querySelector('[data-reward-row="xu"]');
            const xuLabel = byId('synaRewardXuLabel');
            const checkinRow = byId('synaCheckinRow');
            const checkinBtnWrap = byId('synaCheckinBtnWrap');
            const checkinBtn = byId('synaCheckinBtn');
            const checkinSub = byId('synaCheckinSub');
            const checkinStatus = byId('synaCheckinStatus');
            const checkinError = byId('synaCheckinError');
            const msRow = byId('synaMsRow');
            const msBtnWrap = byId('synaMsBtnWrap');
            const msBtn = byId('synaMsBtn');
            const msTitle = byId('synaMsTitle');
            const msStatus = byId('synaMsStatus');
            const msError = byId('synaMsError');
            const rewardCsrf = viewer ? (viewer.dataset.rewardCsrf || '') : '';
            const CHECKIN_REQUIRED = 60;

            const isUserLoggedIn = <?= is_logged_in() ? 'true' : 'false' ?>;
            const reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
            const desktopMq = window.matchMedia ? window.matchMedia('(min-width: 992px)') : { matches: true };

            let currentWatchSessionId = null;
            let heartbeatTimer = null;
            let currentPubId = rewardCard ? (rewardCard.dataset.publicationId || '') : '';
            let currentVideoId = rewardCard ? (rewardCard.dataset.videoId || '') : '';
            let currentCampId = rewardCard ? (rewardCard.dataset.campaignId || '') : '';
            let videoDuration = rewardCard ? parseFloat(rewardCard.dataset.duration || '0') : 0;
            let isEligible = false;
            let isSessionStarting = false;
            let sessionToken = 0;
            let isClaiming = false;
            let lastVideoOpenControl = null;
            let currentIndex = 0;
            let waitingFlag = false;
            let tickTimer = null;
            let lastTick = 0;
            let toastTimer = null;
            let sessionMode = '';
            let timeStartFailed = false;
            let summaryRequested = false;
            let videoList = [];
            try {
              const listEl = byId('synaVideoListData');
              videoList = JSON.parse(listEl ? listEl.textContent : '[]') || [];
            } catch (e) {
              videoList = [];
            }

            function formatTime(seconds) {
              const sec = Math.max(0, Math.floor(seconds || 0));
              const m = Math.floor(sec / 60);
              const s = sec % 60;
              return `${m}:${s < 10 ? '0' : ''}${s}`;
            }

            function setText(el, text) {
              if (el && el.textContent !== text) el.textContent = text;
            }
            function setHtml(el, html) {
              if (el && el.dataset.svHtml !== html) {
                el.innerHTML = html;
                el.dataset.svHtml = html;
              }
            }
            function setHidden(el, hide) {
              if (el && el.hidden !== hide) el.hidden = hide;
            }
            function setDNone(el, hide) {
              if (el) el.classList.toggle('d-none', hide);
            }

            /* ---------- Countdown ring (clockwise from 12 o'clock) ---------- */
            function createRing(el) {
              if (!el) return { set() {} };
              const bar = el.querySelector('.sv-ring__bar');
              const timeEl = el.querySelector('.sv-ring__time');
              const radius = bar ? (parseFloat(bar.getAttribute('r')) || 19) : 19;
              const circumference = 2 * Math.PI * radius;
              if (bar) {
                bar.style.strokeDasharray = circumference.toFixed(2);
                bar.style.strokeDashoffset = circumference.toFixed(2);
              }
              return {
                // state: playing | paused | ready | done | expired
                set(progress, remainingSec, state) {
                  const p = Math.min(1, Math.max(0, Number(progress) || 0));
                  if (bar) bar.style.strokeDashoffset = (circumference * (1 - p)).toFixed(2);
                  el.classList.toggle('is-ready', state === 'ready');
                  el.classList.toggle('is-done', state === 'done');
                  el.classList.toggle('is-expired', state === 'expired');
                  el.classList.toggle('is-paused', state === 'paused');
                  setText(timeEl, state === 'expired' ? '--' : formatTime(remainingSec));
                }
              };
            }
            const panelRing = createRing(byId('synaRewardRing'));
            const chipRing = createRing(byId('synaRewardChipRing'));
            const checkinRing = createRing(byId('synaCheckinRing'));
            const msRing = createRing(byId('synaMsRing'));

            /* ---------- Reward state (server is the source of truth; client interpolates between heartbeats) ---------- */
            const rw = {
              active: !!currentCampId,
              watched: 0,
              total: videoDuration,
              accum: 0,
              eligible: false,
              rewarded: false,
              expired: false,
              hasPlayed: false
            };
            // "+N xu" of the current video's campaign: pending | done | claimed | cap
            const xu = { amount: 0, got: 0, state: 'pending' };
            // Daily check-in (server seconds today + interpolation between heartbeats)
            const ci = { show: false, checked: false, today: 0, accum: 0, day: 1, nextXu: 0, busy: false };
            // Watch milestones: items = [{minutes, xu, claimed}], capped = daily xu cap reached
            const ms = { show: false, items: [], capped: false, cap: 300, busy: false };
            function nextMilestone() {
              return ms.items.find((m) => !m.claimed) || null;
            }

            // Locally watched intervals [start, end] (video seconds) for the current video. Mirrors the
            // server rule: only continuous forward playback at 1x earns time; seeks, rewinds and
            // re-watched footage never advance the counters.
            let watchedIv = [];
            let lastSampleT = null;
            function coverInterval(a, b) {
              if (!(b > a)) return 0;
              let overlap = 0;
              let s = a;
              let e = b;
              const out = [];
              for (let i = 0; i < watchedIv.length; i++) {
                const x = watchedIv[i][0];
                const y = watchedIv[i][1];
                if (y < s || x > e) { out.push([x, y]); continue; }
                overlap += Math.max(0, Math.min(y, b) - Math.max(x, a));
                s = Math.min(s, x);
                e = Math.max(e, y);
              }
              out.push([s, e]);
              out.sort((m, n) => m[0] - n[0]);
              watchedIv = out;
              return Math.max(0, (b - a) - overlap);
            }
            // Seconds of NEW (never covered) footage since the previous sample; 0 on any jump or freeze.
            function sampleNewCoverage() {
              const t = player.currentTime;
              if (!Number.isFinite(t)) { lastSampleT = null; return 0; }
              let gained = 0;
              if (isPlayingForward() && lastSampleT !== null) {
                const d = t - lastSampleT;
                if (d > 0 && d <= 1.5) gained = coverInterval(lastSampleT, t);
              }
              lastSampleT = t;
              return gained;
            }

            function isPlayingForward() {
              return !player.paused && !player.ended && !player.seeking && !waitingFlag
                && player.readyState >= 3 && Math.abs((player.playbackRate || 1) - 1) < 0.01;
            }

            function renderReward() {
              const total = rw.total > 0 ? rw.total : videoDuration;
              const shown = rw.eligible ? total : Math.min(total, rw.watched + rw.accum);
              const progress = rw.eligible ? 1 : (total > 0 ? shown / total : 0);
              const remaining = rw.eligible ? 0 : Math.max(0, total - shown);
              const pct = Math.round(progress * 100);
              const paused = rw.hasPlayed && player.paused && !rw.eligible && !rw.expired;
              let state;
              if (rw.expired && !rw.eligible) state = 'expired';
              else if (rw.rewarded) state = 'done';
              else if (rw.eligible) state = isUserLoggedIn ? 'ready' : 'done';
              else state = paused ? 'paused' : 'playing';

              panelRing.set(progress, remaining, state);
              chipRing.set(progress, remaining, state);
              const chipRingEl = byId('synaRewardChipRing');
              if (chipRingEl) chipRingEl.classList.toggle('is-done', state === 'done' || state === 'ready');

              // Pill over the video (desktop)
              setHidden(pillEl, !(rw.active && !rw.eligible && !rw.expired));
              setText(modalCountdown, `Còn ${formatTime(remaining)} để nhận thưởng`);

              // Panel reward block
              setText(rewardPaused ? rewardPaused : null, 'Đang tạm dừng');
              setHidden(rewardPaused, state !== 'paused');
              if (rewardProgressBar) rewardProgressBar.style.width = `${Math.min(100, pct)}%`;
              setText(rewardProgressTime, rw.eligible
                ? `${formatTime(total)} / ${formatTime(total)} · Đủ thời lượng`
                : `${formatTime(shown)} / ${formatTime(total)} · còn ${formatTime(remaining)}`);

              if (state === 'done' && rw.rewarded) {
                setHtml(rewardBadge, '<i class="fas fa-check-circle me-1"></i> Đã nhận voucher');
                if (rewardBadge) rewardBadge.className = 'sv-badge is-done';
                setText(rewardStatusText, 'Bạn đã nhận voucher của video này!');
              } else if (rw.eligible) {
                setHtml(rewardBadge, '<i class="fas fa-gift me-1"></i> Đủ điều kiện');
                if (rewardBadge) rewardBadge.className = 'sv-badge is-ready';
                setText(rewardStatusText, isUserLoggedIn ? 'Đã đủ điều kiện, nhận voucher ngay!' : 'Đã đủ điều kiện, đăng nhập để nhận voucher.');
              } else if (rw.expired) {
                setHtml(rewardBadge, '<i class="fas fa-clock me-1"></i> Đã kết thúc');
                if (rewardBadge) rewardBadge.className = 'sv-badge is-expired';
                setText(rewardStatusText, 'Chiến dịch ưu đãi đã kết thúc hoặc hết hạn.');
              } else {
                if (pct > 0) {
                  setHtml(rewardBadge, `<i class="fas fa-circle-notch me-1"></i> Đang xem (${pct}%)`);
                  if (rewardBadge) rewardBadge.className = 'sv-badge is-progress';
                } else {
                  setHtml(rewardBadge, '<i class="fas fa-lock me-1"></i> Chưa mở khóa');
                  if (rewardBadge) rewardBadge.className = 'sv-badge';
                }
                setText(rewardStatusText, `Còn ${formatTime(remaining)} để mở khóa voucher`);
              }

              setDNone(claimBtnContainer, state !== 'ready');
              setDNone(guestLoginContainer, !(rw.eligible && !rw.rewarded && !isUserLoggedIn));
              setDNone(claimedBox, !rw.rewarded);
              const alertVisible = errorAlert && !errorAlert.classList.contains('d-none');
              setDNone(rewardActionArea, !(rw.eligible || rw.rewarded || rw.expired || alertVisible));

              // Mobile chip
              setHidden(chipEl, !rw.active);
              setHidden(chipClaim, state !== 'ready');
              setHidden(chipLogin, !(rw.eligible && !rw.rewarded && !isUserLoggedIn));
              const chipText = state === 'done' && rw.rewarded ? 'Đã nhận'
                : state === 'expired' ? 'Đã hết hạn'
                : state === 'paused' ? 'Đang tạm dừng'
                : '+Voucher';
              setText(chipLabel, chipText);
              setHidden(chipLabel, state === 'ready' || (rw.eligible && !rw.rewarded && !isUserLoggedIn));

              renderXu();
              renderCheckin();
              renderMilestone();
              const voucherVisible = !!(rewardCard && !rewardCard.classList.contains('d-none'));
              if (rewardBlock) rewardBlock.classList.toggle('is-empty', !(voucherVisible || ci.show));
            }

            function renderXu() {
              const show = rw.active && xu.amount > 0;
              setHidden(xuRow, !show);
              if (!show) return;
              let text;
              if (xu.state === 'done') text = `Đã nhận +${xu.got || xu.amount} xu`;
              else if (xu.state === 'claimed') text = 'Đã nhận';
              else if (xu.state === 'cap') text = 'Đủ xu hôm nay';
              else text = isUserLoggedIn ? `+${xu.amount} xu` : `+${xu.amount} xu · đăng nhập để nhận`;
              setText(xuLabel, text);
              if (xuRow) xuRow.classList.toggle('is-done', xu.state !== 'pending');
            }

            function renderCheckin() {
              setHidden(checkinRow, !ci.show);
              if (!ci.show) return;
              const shown = Math.min(CHECKIN_REQUIRED, ci.today + ci.accum);
              // 'Ready' follows the server-confirmed seconds, never the interpolated ones.
              const serverReady = ci.today >= CHECKIN_REQUIRED;
              const remaining = serverReady ? 0 : Math.max(1, CHECKIN_REQUIRED - shown);
              const ready = !ci.checked && serverReady;
              const paused = rw.hasPlayed && player.paused;
              const state = ci.checked ? 'done' : ready ? 'ready' : (paused ? 'paused' : 'playing');
              checkinRing.set(ci.checked ? 1 : shown / CHECKIN_REQUIRED, remaining, state);
              setDNone(checkinBtnWrap, state !== 'ready');
              setText(checkinBtn, `+${ci.nextXu} xu`);
              if (checkinBtn) checkinBtn.setAttribute('aria-label', `Điểm danh +${ci.nextXu} xu`);
              setText(checkinSub, ci.checked ? '' : `Ngày ${ci.day} · +${ci.nextXu} xu`);
              setText(checkinStatus, ci.checked ? `Đã điểm danh · Ngày ${ci.day}`
                : ready ? (ci.capped ? 'Bấm để điểm danh giữ chuỗi (hôm nay đã đủ xu)' : `Bấm để điểm danh +${ci.nextXu} xu`)
                : `Xem thêm ${formatTime(remaining)} để điểm danh`);
            }

            function renderMilestone() {
              setHidden(msRow, !ms.show);
              if (!ms.show) return;
              const next = nextMilestone();
              if (!next) {
                msRing.set(1, 0, 'done');
                setDNone(msBtnWrap, true);
                setText(msTitle, 'Quà xem video hôm nay');
                setText(msStatus, 'Đã nhận đủ quà xem hôm nay');
                return;
              }
              const total = next.minutes * 60;
              setText(msTitle, `Mốc ${next.minutes} phút · +${next.xu} xu`);
              if (ms.capped) {
                msRing.set(Math.min(1, ci.today / total), 0, 'expired');
                setDNone(msBtnWrap, true);
                setText(msStatus, `Đủ ${ms.cap} xu hôm nay`);
                return;
              }
              // 'Ready' follows the server-confirmed seconds, never the interpolated ones.
              const serverReady = ci.today >= total;
              const shown = Math.min(total, ci.today + ci.accum);
              const remaining = serverReady ? 0 : Math.max(1, total - shown);
              const paused = rw.hasPlayed && player.paused;
              const state = serverReady ? 'ready' : (paused ? 'paused' : 'playing');
              msRing.set(shown / total, remaining, state);
              setDNone(msBtnWrap, state !== 'ready');
              if (msBtn) msBtn.setAttribute('aria-label', `Nhận +${next.xu} xu mốc ${next.minutes} phút`);
              setText(msStatus, serverReady ? `Sẵn sàng nhận +${next.xu} xu` : `Còn ${formatTime(remaining)}`);
            }

            function noteTodaySeconds(value) {
              const n = Number(value);
              if (value === undefined || value === null || !Number.isFinite(n) || n < 0) return;
              // Never show less than already displayed: keep the lead (<= 4s) over the server value.
              const prevShown = ci.today + ci.accum;
              ci.today = Math.floor(n);
              ci.accum = Math.min(4, Math.max(0, prevShown - ci.today));
            }

            function handleXuReward(xr) {
              if (!xr || typeof xr !== 'object') return;
              const gained = Number(xr.xu_awarded) || 0;
              if (xr.code === 'OK') {
                const first = xu.state !== 'done';
                xu.state = 'done';
                if (gained > 0) xu.got = gained;
                if (first && gained > 0) showXuToast(gained);
              } else if (xr.code === 'ALREADY_CLAIMED') {
                if (xu.state !== 'done') xu.state = 'claimed';
              } else if (xr.code === 'DAILY_CAP_REACHED') {
                if (xu.state !== 'done') xu.state = 'cap';
              }
              renderReward();
            }

            function tick() {
              lastTick = performance.now();
              const gained = sampleNewCoverage();
              if (gained > 0 && rw.active && !rw.eligible && !rw.expired) {
                // Never run more than 4s ahead of the last server value.
                rw.accum = Math.min(4, rw.accum + gained);
              }
              const msNext = ms.show && !ms.capped ? nextMilestone() : null;
              const accumTarget = Math.max(ci.show && !ci.checked ? CHECKIN_REQUIRED : 0, msNext ? msNext.minutes * 60 : 0);
              if (gained > 0 && ci.show && ci.today + ci.accum < accumTarget) {
                ci.accum = Math.min(4, ci.accum + gained);
              }
              renderReward();
            }
            function startTick() {
              if (tickTimer) return;
              lastTick = 0;
              tickTimer = setInterval(tick, 250);
            }
            function stopTick() {
              if (tickTimer) clearInterval(tickTimer);
              tickTimer = null;
            }

            function updateOfferProgress(percent, watchedSec, totalSec, eligible = false, rewarded = false) {
              const remaining = Math.max(0, totalSec - watchedSec);
              // The voucher bar belongs to the newest video (index 0) only.
              if (currentIndex !== 0) return;
              if (offerProgressBar) offerProgressBar.style.width = `${Math.min(100, Math.max(0, percent))}%`;
              if (offerProgressTime) {
                offerProgressTime.textContent = eligible
                  ? 'Đã đủ thời lượng'
                  : `${formatTime(watchedSec)} / ${formatTime(totalSec)} · còn ${formatTime(remaining)}`;
              }
              if (offerStatus) {
                offerStatus.textContent = rewarded
                  ? 'Voucher đã được cấp vào ví tài khoản'
                  : eligible
                    ? (isUserLoggedIn ? 'Đã đủ điều kiện · đang cấp voucher' : 'Đã đủ điều kiện · đăng nhập để nhận voucher')
                    : `Còn ${formatTime(Math.max(0, totalSec - watchedSec))} để mở khóa voucher`;
              }
            }

            function updateRewardUI(data) {
              if (!data) return;
              const percent = Math.min(100, Math.max(0, parseFloat(data.watched_coverage_percent || 0)));
              const watchedSec = parseFloat(data.watched_seconds || 0);
              const prevRwShown = rw.watched + rw.accum;
              const totalSec = videoDuration > 0 ? videoDuration : parseFloat(data.video_duration || 0);
              const rewarded = data.watch_state === 'REWARDED' || data.is_rewarded || data.reward_voucher_code;
              const eligible = Boolean(data.is_eligible || data.watch_state === 'ELIGIBLE' || rewarded);

              updateOfferProgress(percent, watchedSec, totalSec, eligible, rewarded);

              rw.watched = watchedSec;
              rw.total = totalSec;
              // Keep any lead over the server value (<= 4s) so the display never moves backwards.
              rw.accum = Math.min(4, Math.max(0, prevRwShown - watchedSec));
              rw.rewarded = Boolean(rewarded);
              rw.eligible = eligible;
              rw.expired = !eligible && (data.watch_state === 'EXPIRED' || data.code === 'CAMPAIGN_EXPIRED');
              if (eligible) isEligible = true;

              if (rw.rewarded) {
                const vCode = data.reward_voucher_code || (data.voucher && data.voucher.voucher_code) || '';
                if (claimedVoucherCode && vCode) claimedVoucherCode.textContent = vCode;
                if (claimedDate && data.reward_claimed_at) claimedDate.textContent = 'Ngày nhận: ' + data.reward_claimed_at;
                if (errorAlert) errorAlert.classList.add('d-none');
              } else if (rw.expired) {
                if (errorAlert) {
                  errorAlert.classList.remove('d-none');
                  if (errorMsg) errorMsg.textContent = 'Chiến dịch ưu đãi đã kết thúc hoặc tạm dừng.';
                }
              } else if (rw.eligible && errorAlert) {
                errorAlert.classList.add('d-none');
              }
              renderReward();
            }

            /* ---------- Modal open / close ---------- */
            function showToast(message) {
              if (!toastEl) return;
              toastEl.className = 'sv-toast';
              toastEl.textContent = message;
              toastEl.hidden = false;
              if (toastTimer) clearTimeout(toastTimer);
              toastTimer = setTimeout(() => { toastEl.hidden = true; }, 2200);
            }

            function showXuToast(amount) {
              if (!toastEl || !(amount > 0)) return;
              toastEl.className = 'sv-toast';
              toastEl.textContent = '';
              toastEl.appendChild(document.createTextNode(`+${amount} xu`));
              const link = document.createElement('a');
              link.href = '<?= BASE_URL ?>/index.php?r=phan_thuong';
              link.textContent = 'Xem phần thưởng';
              toastEl.appendChild(link);
              toastEl.hidden = false;
              void toastEl.offsetWidth; // restart the rise animation
              toastEl.className = 'sv-toast sv-toast--xu';
              if (toastTimer) clearTimeout(toastTimer);
              toastTimer = setTimeout(() => { toastEl.hidden = true; }, 3000);
            }

            /* ---------- Daily check-in ---------- */
            async function loadRewardSummary() {
              if (!isUserLoggedIn || !checkinRow || summaryRequested) return;
              summaryRequested = true;
              try {
                const res = await fetch('<?= BASE_URL ?>/index.php?r=reward_summary', { headers: { 'Accept': 'application/json' } });
                if (!res.ok) { summaryRequested = false; return; }
                const data = await res.json();
                const c = data && data.checkin;
                if (!data || data.ok === false || data.code !== 'OK' || !c) { summaryRequested = false; return; }
                ci.checked = !!c.checked_in_today;
                ci.day = Math.max(1, parseInt(c.next_day, 10) || 1);
                ci.nextXu = Math.max(0, parseInt(c.next_xu, 10) || 0);
                ci.today = Math.max(ci.today, Math.floor(Number(data.watched_seconds_today) || 0));
                ci.accum = 0;
                ci.capped = Number(data.daily_cap) > 0 && Number(data.xu_today) >= Number(data.daily_cap);
                ci.show = true;
                ms.cap = Number(data.daily_cap) > 0 ? Math.floor(Number(data.daily_cap)) : 300;
                ms.capped = ci.capped;
                ms.items = (Array.isArray(data.milestones) ? data.milestones : [])
                  .map((m) => ({ minutes: parseInt(m && m.minutes, 10) || 0, xu: parseInt(m && m.xu, 10) || 0, claimed: !!m && m.state === 'claimed' }))
                  .filter((m) => m.minutes > 0)
                  .sort((a, b) => a.minutes - b.minutes);
                ms.show = ms.items.length > 0;
                renderReward();
              } catch (e) {
                summaryRequested = false; // retry on the next viewer open
              }
            }

            async function doCheckin() {
              if (!checkinBtn || ci.busy || ci.checked) return;
              ci.busy = true;
              checkinBtn.disabled = true;
              setDNone(checkinError, true);
              try {
                const res = await fetch('<?= BASE_URL ?>/index.php?r=reward_checkin', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': rewardCsrf },
                  body: '{}'
                });
                let data = {};
                try { data = await res.json(); } catch (e) { data = {}; }
                if (res.ok && data.ok) {
                  ci.checked = true;
                  const gained = Number(data.xu_awarded) || 0;
                  if (gained > 0) showXuToast(gained);
                } else if (data.code === 'ALREADY_CHECKED_IN') {
                  ci.checked = true;
                } else {
                  setText(checkinError, data.message || 'Không thể điểm danh lúc này.');
                  setDNone(checkinError, false);
                }
              } catch (e) {
                setText(checkinError, 'Lỗi kết nối máy chủ. Vui lòng thử lại sau.');
                setDNone(checkinError, false);
              } finally {
                ci.busy = false;
                checkinBtn.disabled = false;
                renderReward();
              }
            }
            if (checkinBtn) checkinBtn.addEventListener('click', doCheckin);

            async function doMilestoneClaim() {
              const next = nextMilestone();
              if (!msBtn || ms.busy || !next) return;
              ms.busy = true;
              msBtn.disabled = true;
              setDNone(msError, true);
              try {
                const res = await fetch('<?= BASE_URL ?>/index.php?r=reward_milestone_claim', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': rewardCsrf },
                  body: JSON.stringify({ minutes: next.minutes })
                });
                let data = {};
                try { data = await res.json(); } catch (e) { data = {}; }
                if (res.ok && data.ok) {
                  next.claimed = true;
                  const gained = Number(data.xu_awarded) || 0;
                  if (gained > 0) showXuToast(gained);
                } else if (data.code === 'ALREADY_CLAIMED') {
                  next.claimed = true;
                } else if (data.code === 'DAILY_CAP_REACHED') {
                  ms.capped = true;
                } else {
                  setText(msError, data.message || 'Không thể nhận quà lúc này.');
                  setDNone(msError, false);
                }
              } catch (e) {
                setText(msError, 'Lỗi kết nối máy chủ. Vui lòng thử lại sau.');
                setDNone(msError, false);
              } finally {
                ms.busy = false;
                msBtn.disabled = false;
                renderReward();
              }
            }
            if (msBtn) msBtn.addEventListener('click', doMilestoneClaim);

            function sheetIsOpen() {
              return !!(viewer && viewer.classList.contains('is-sheet-open'));
            }
            function openSheet() {
              if (!viewer || desktopMq.matches) return;
              viewer.classList.add('is-sheet-open');
              if (sheetOpenBtn) sheetOpenBtn.setAttribute('aria-expanded', 'true');
              if (panel) panel.scrollTop = 0;
              if (sheetCloseBtn) setTimeout(() => sheetCloseBtn.focus(), 30);
            }
            function closeSheet(returnFocus = true) {
              if (!viewer || !sheetIsOpen()) return;
              viewer.classList.remove('is-sheet-open');
              if (sheetOpenBtn) {
                sheetOpenBtn.setAttribute('aria-expanded', 'false');
                if (returnFocus) sheetOpenBtn.focus();
              }
            }

            function modalIsOpen() {
              return !!(videoModal && videoModal.classList.contains('is-open'));
            }

            function openVideoModal(control) {
              if (!videoModal) return;
              lastVideoOpenControl = control || document.activeElement;
              videoModal.classList.add('is-open');
              videoModal.setAttribute('aria-hidden', 'false');
              document.body.classList.add('syna-video-modal-open');
              if (panel) panel.scrollTop = 0;
              startTick();
              renderReward();
              loadRewardSummary();
              if (videoModalClose) videoModalClose.focus();
              document.dispatchEvent(new CustomEvent('syna-video-modal', { detail: { open: true } }));
            }

            function closeVideoModal() {
              if (!videoModal) return;
              closeSheet(false);
              videoModal.classList.remove('is-open');
              videoModal.setAttribute('aria-hidden', 'true');
              document.body.classList.remove('syna-video-modal-open');
              if (player && !player.paused) player.pause();
              stopTick();
              if (lastVideoOpenControl && typeof lastVideoOpenControl.focus === 'function') {
                lastVideoOpenControl.focus();
              }
              document.dispatchEvent(new CustomEvent('syna-video-modal', { detail: { open: false } }));
            }

            videoOpenButtons.forEach((button) => {
              button.addEventListener('click', () => openVideoModal(button));
            });
            if (videoModalClose) videoModalClose.addEventListener('click', closeVideoModal);
            if (videoModal) {
              videoModal.addEventListener('click', (event) => {
                if (event.target === videoModal) closeVideoModal();
              });
            }
            if (sheetOpenBtn) sheetOpenBtn.addEventListener('click', openSheet);
            if (sheetCloseBtn) sheetCloseBtn.addEventListener('click', () => closeSheet());
            if (sheetBackdrop) sheetBackdrop.addEventListener('click', () => closeSheet());
            if (productCardClose) {
              productCardClose.addEventListener('click', () => {
                if (viewer) viewer.classList.add('is-card-dismissed');
              });
            }

            function focusables(root) {
              if (!root) return [];
              return Array.from(root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])'))
                .filter((el) => !el.closest('[hidden], .d-none') && el.getClientRects().length > 0 && window.getComputedStyle(el).visibility !== 'hidden');
            }

            document.addEventListener('keydown', (event) => {
              if (!modalIsOpen()) return;
              if (event.key === 'Escape') {
                event.preventDefault();
                if (sheetIsOpen()) closeSheet();
                else closeVideoModal();
                return;
              }
              const tag = (event.target && event.target.tagName) || '';
              if ((event.key === 'ArrowUp' || event.key === 'ArrowDown') && !/^(INPUT|TEXTAREA|SELECT)$/.test(tag)) {
                event.preventDefault();
                goRelative(event.key === 'ArrowDown' ? 1 : -1);
                return;
              }
              if (event.key === 'Tab') {
                const root = sheetIsOpen() ? panel : viewer;
                const items = focusables(root);
                if (!items.length) return;
                const first = items[0];
                const last = items[items.length - 1];
                const active = document.activeElement;
                if (!root.contains(active)) {
                  event.preventDefault();
                  first.focus();
                } else if (event.shiftKey && active === first) {
                  event.preventDefault();
                  last.focus();
                } else if (!event.shiftKey && active === last) {
                  event.preventDefault();
                  first.focus();
                }
              }
            });

            /* ---------- Claim ---------- */
            async function doClaim() {
              if (!claimBtn || isClaiming || claimBtn.disabled || !currentWatchSessionId) return;
              isClaiming = true;
              claimBtn.disabled = true;
              const originalHtml = claimBtn.innerHTML;
              claimBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
              if (errorAlert) errorAlert.classList.add('d-none');

              try {
                const res = await fetch('<?= BASE_URL ?>/index.php?r=video_watch_claim_reward', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ watch_session_id: currentWatchSessionId })
                });
                const data = await res.json();
                handleXuReward(data.xu_reward);

                if (data.ok && data.voucher) {
                  rw.eligible = true;
                  rw.rewarded = true;
                  if (claimedVoucherCode) claimedVoucherCode.textContent = data.voucher.voucher_code || '';
                  if (claimedDate) claimedDate.textContent = 'Ngày nhận: ' + (data.voucher.claimed_at || 'Vừa xong');
                  claimBtn.innerHTML = originalHtml;
                  claimBtn.disabled = false;
                } else {
                  if (errorAlert) {
                    errorAlert.classList.remove('d-none');
                    if (errorMsg) errorMsg.textContent = data.message || 'Không thể nhận voucher lúc này.';
                  }
                  if (data.code === 'USER_LIMIT_REACHED' && data.voucher) {
                    rw.eligible = true;
                    rw.rewarded = true;
                    if (claimedVoucherCode) claimedVoucherCode.textContent = data.voucher.voucher_code || '';
                  }
                  claimBtn.disabled = false;
                  claimBtn.innerHTML = originalHtml;
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
                renderReward();
              }
            }
            if (claimBtn) claimBtn.addEventListener('click', doClaim);
            if (chipClaim) chipClaim.addEventListener('click', () => { if (claimBtn) claimBtn.click(); });

            if (copyVoucherBtn) {
              copyVoucherBtn.addEventListener('click', () => {
                const code = claimedVoucherCode ? claimedVoucherCode.textContent.trim() : '';
                if (!code || code === '---' || !navigator.clipboard) return;
                navigator.clipboard.writeText(code).then(() => {
                  const orig = copyVoucherBtn.innerHTML;
                  copyVoucherBtn.innerHTML = '<i class="fas fa-check"></i> Đã chép!';
                  setTimeout(() => { copyVoucherBtn.innerHTML = orig; }, 2000);
                }).catch(() => {});
              });
            }

            /* ---------- Watch session ---------- */
            async function startWatchSession() {
              if (!currentPubId || isSessionStarting) return;
              // Without a campaign only logged-in users get a campaign-less 'time' session.
              if (!currentCampId && (!isUserLoggedIn || timeStartFailed)) return;
              isSessionStarting = true;
              const token = sessionToken;
              const timeOnly = !currentCampId;
              try {
                const payload = { publication_id: currentPubId, video_id: currentVideoId };
                if (currentCampId) payload.campaign_id = currentCampId;
                const res = await fetch('<?= BASE_URL ?>/index.php?r=video_watch_start', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (token !== sessionToken) return; // another video was selected meanwhile
                if (data.ok && data.session) {
                  currentWatchSessionId = data.session.watch_session_id;
                  sessionMode = data.session.mode === 'time' ? 'time' : 'campaign';
                  handleXuReward(data.xu_reward);
                  if (sessionMode === 'time') {
                    noteTodaySeconds(data.session.today_seconds);
                  } else {
                    if (data.session.video_duration > 0) {
                      videoDuration = data.session.video_duration;
                    }
                    updateRewardUI(data.session);
                  }
                } else if (timeOnly) {
                  timeStartFailed = true; // do not retry on every heartbeat
                }
              } catch (e) {
                // Silently handle network errors
              } finally {
                if (token === sessionToken) isSessionStarting = false;
              }
            }

            async function sendHeartbeat(isPaused = false) {
              if (!currentWatchSessionId) {
                await startWatchSession();
                if (!currentWatchSessionId) return;
              }
              const sid = currentWatchSessionId;
              try {
                const res = await fetch('<?= BASE_URL ?>/index.php?r=video_watch_heartbeat', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({
                    watch_session_id: sid,
                    current_time: player.currentTime || 0,
                    playback_rate: player.playbackRate || 1.0,
                    is_paused: isPaused
                  })
                });
                const data = await res.json();
                if (sid !== currentWatchSessionId) return;
                if (data.ok) {
                  noteTodaySeconds(data.today_seconds);
                  handleXuReward(data.xu_reward);
                  if (sessionMode !== 'time') updateRewardUI(data);
                  else renderReward();
                } else if (data.code === 'CAMPAIGN_EXPIRED' && sessionMode !== 'time') {
                  updateRewardUI(data);
                }
              } catch (e) {
                // Ignore transient network errors
              }
            }

            async function verifyCompletion() {
              if (!currentWatchSessionId || sessionMode === 'time') return;
              const sid = currentWatchSessionId;
              try {
                const res = await fetch('<?= BASE_URL ?>/index.php?r=video_watch_complete', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ watch_session_id: sid })
                });
                const data = await res.json();
                if (sid !== currentWatchSessionId) return;
                handleXuReward(data.xu_reward);
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

            /* ---------- Player events ---------- */
            if (overlay) {
              overlay.addEventListener('click', () => {
                overlay.classList.add('is-hidden');
                player.play().catch(() => {});
              });
            }

            player.addEventListener('seeking', () => { lastSampleT = null; });
            player.addEventListener('seeked', () => { lastSampleT = null; });
            player.addEventListener('ratechange', () => { lastSampleT = null; });
            player.addEventListener('waiting', () => {
              waitingFlag = true;
              if (loading) loading.classList.add('is-active');
            });
            player.addEventListener('playing', () => {
              waitingFlag = false;
              rw.hasPlayed = true;
              if (viewer) {
                viewer.classList.add('has-started');
                viewer.classList.remove('is-paused');
              }
              if (loading) loading.classList.remove('is-active');
              if (errorBox) errorBox.classList.remove('is-active');
              if (overlay) overlay.classList.add('is-hidden');
              startHeartbeatInterval();
              sendHeartbeat(false);
              renderReward();
            });
            player.addEventListener('pause', () => {
              if (viewer) viewer.classList.add('is-paused');
              stopHeartbeatInterval();
              sendHeartbeat(true);
              renderReward();
            });
            player.addEventListener('ended', async () => {
              const idxAtEnd = currentIndex;
              stopHeartbeatInterval();
              await sendHeartbeat(true);
              await verifyCompletion();
              const claimPending = rw.eligible && !rw.rewarded && isUserLoggedIn;
              if (idxAtEnd === currentIndex && modalIsOpen() && !claimPending && currentIndex + 1 < videoList.length) {
                selectVideo(currentIndex + 1, { autoplay: true });
              }
            });
            player.addEventListener('canplay', () => {
              waitingFlag = false;
              if (loading) loading.classList.remove('is-active');
            });
            player.addEventListener('timeupdate', () => {
              if (playProgress && player.duration > 0) {
                playProgress.style.width = `${Math.min(100, (player.currentTime / player.duration) * 100)}%`;
              }
            });
            player.addEventListener('volumechange', renderMute);
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

            function renderMute() {
              if (!muteBtn) return;
              const icon = muteBtn.querySelector('i');
              muteBtn.setAttribute('aria-label', player.muted || player.volume === 0 ? 'Bật tiếng' : 'Tắt tiếng');
              if (icon) icon.className = player.muted || player.volume === 0 ? 'fas fa-volume-xmark' : 'fas fa-volume-high';
            }
            if (muteBtn) {
              muteBtn.addEventListener('click', () => { player.muted = !player.muted; renderMute(); });
            }

            // Native controls only on desktop; mobile uses tap-to-play and the mute button.
            function applyControls() {
              player.controls = !!desktopMq.matches;
              if (desktopMq.matches) closeSheet(false);
            }
            applyControls();
            if (desktopMq.addEventListener) desktopMq.addEventListener('change', applyControls);
            else if (desktopMq.addListener) desktopMq.addListener(applyControls);

            function togglePlay() {
              if (player.paused) player.play().catch(() => {});
              else player.pause();
            }

            // Tap = play/pause, vertical swipe = previous/next video (mobile layer).
            if (tapLayer) {
              let sx = 0;
              let sy = 0;
              let tracking = false;
              tapLayer.addEventListener('pointerdown', (event) => {
                tracking = true;
                sx = event.clientX;
                sy = event.clientY;
                try { tapLayer.setPointerCapture(event.pointerId); } catch (e) {}
              });
              tapLayer.addEventListener('pointerup', (event) => {
                if (!tracking) return;
                tracking = false;
                const dx = event.clientX - sx;
                const dy = event.clientY - sy;
                if (Math.abs(dy) > 50 && Math.abs(dy) > Math.abs(dx)) {
                  goRelative(dy < 0 ? 1 : -1);
                } else if (Math.abs(dx) < 12 && Math.abs(dy) < 12) {
                  togglePlay();
                }
              });
              tapLayer.addEventListener('pointercancel', () => { tracking = false; });
            }

            if (shareBtn) {
              shareBtn.addEventListener('click', async () => {
                const url = window.location.href;
                const title = titleEl ? titleEl.textContent.trim() : document.title;
                try {
                  if (navigator.share) {
                    await navigator.share({ title, url });
                    return;
                  }
                } catch (e) {
                  if (e && e.name === 'AbortError') return;
                }
                try {
                  await navigator.clipboard.writeText(url);
                  showToast('Đã chép liên kết');
                } catch (e) {
                  showToast('Không thể chia sẻ lúc này');
                }
              });
            }

            // Campaign videos start a campaign session; logged-in users also start a 'time' session
            // up front so the first heartbeat is not the session start (no lost seconds).
            if (rewardCard && (currentCampId || isUserLoggedIn)) {
              startWatchSession();
            }

            /* ---------- Video switching ---------- */
            function shortOffer(r) {
              if (!r) return '';
              if (r.discount_type === 'percentage') return `Giảm ${Number(r.discount_value)}%`;
              return `Giảm ${Number(r.discount_value).toLocaleString('vi-VN')}đ`;
            }
            function fullOffer(r) {
              if (!r) return '';
              let text = r.discount_type === 'percentage'
                ? `Giảm ${r.discount_value}%` + (r.max_discount_amount ? ` (tối đa ${Number(r.max_discount_amount).toLocaleString()}đ)` : '')
                : `Giảm ${Number(r.discount_value).toLocaleString()}đ`;
              if (r.min_order_amount) text += ` cho đơn từ ${Number(r.min_order_amount).toLocaleString()}đ`;
              return text;
            }

            function applyItemUI(idx) {
              const item = videoList[idx];
              if (!item) return;
              if (titleEl && item.title) titleEl.textContent = item.title;
              if (metaEl) metaEl.textContent = `Syna · AI Idol · ${idx + 1}/${videoList.length}`;
              nextItems.forEach((el) => {
                const on = parseInt(el.getAttribute('data-sv-index'), 10) === idx;
                el.classList.toggle('is-active', on);
                if (on) el.setAttribute('aria-current', 'true'); else el.removeAttribute('aria-current');
              });
              if (prevBtn) prevBtn.disabled = idx <= 0;
              if (nextBtn) nextBtn.disabled = idx >= videoList.length - 1;
              if (item.poster) {
                player.poster = item.poster;
                if (bgEl) bgEl.style.backgroundImage = `url("${String(item.poster).replace(/"/g, '%22')}")`;
              }
              if (videoBox) videoBox.dataset.format = item.portrait ? '9:16' : '16:9';
              const reward = item.reward && typeof item.reward === 'object' ? item.reward : null;
              if (discountChip) {
                discountChip.textContent = shortOffer(reward);
                discountChip.hidden = !reward;
              }
            }

            // Switch the shared player to videoList[index] (no-op for the video already loaded).
            function selectVideo(index, opts) {
              const idx = Number(index);
              const item = videoList[idx];
              if (!item || idx === currentIndex) return;
              currentIndex = idx;
              applyItemUI(idx);

              // Stop tracking for previous video
              stopHeartbeatInterval();
              sessionToken += 1;
              isSessionStarting = false;
              currentWatchSessionId = null;
              isEligible = false;
              sessionMode = '';
              timeStartFailed = false;
              ci.accum = 0;
              watchedIv = [];
              lastSampleT = null;
              xu.got = 0;
              xu.state = 'pending';

              const rewardData = item.reward && typeof item.reward === 'object' ? item.reward : null;
              currentPubId = String(item.publication_id || '');
              currentVideoId = String(item.video_id || '');
              videoDuration = parseFloat(item.duration || '0');
              rw.watched = 0;
              rw.total = videoDuration;
              rw.accum = 0;
              rw.eligible = false;
              rw.rewarded = false;
              rw.expired = false;
              rw.hasPlayed = false;
              if (viewer) {
                viewer.classList.remove('has-started');
                viewer.classList.add('is-paused');
              }
              if (errorAlert) errorAlert.classList.add('d-none');
              if (claimedVoucherCode) claimedVoucherCode.textContent = '---';
              if (claimedDate) claimedDate.textContent = '';
              if (claimBtn) claimBtn.disabled = false;

              if (rewardData) {
                currentCampId = rewardData.campaign_id || '';
                xu.amount = Math.max(0, parseInt(rewardData.reward_xu, 10) || 0);
                rw.active = true;
                setDNone(rewardCard, false);
                if (rewardCampaignName) rewardCampaignName.textContent = rewardData.campaign_name || 'Ưu đãi xem video';
                if (rewardOfferText) rewardOfferText.textContent = fullOffer(rewardData);
                updateRewardUI({
                  watched_coverage_percent: 0,
                  watched_seconds: 0,
                  video_duration: videoDuration,
                  watch_state: 'IN_PROGRESS',
                  is_eligible: false
                });
                startWatchSession();
              } else {
                currentCampId = '';
                xu.amount = 0;
                rw.active = false;
                setDNone(rewardCard, true);
                renderReward();
                if (isUserLoggedIn) startWatchSession();
              }

              waitingFlag = false;
              if (errorBox) errorBox.classList.remove('is-active');
              if (playProgress) playProgress.style.width = '0%';
              player.pause();
              player.src = item.src;
              player.load();
              if (opts && opts.autoplay) {
                if (overlay) overlay.classList.add('is-hidden');
                player.play().catch(() => {
                  if (overlay) overlay.classList.remove('is-hidden');
                });
              } else if (overlay) {
                overlay.classList.remove('is-hidden');
              }
            }

            function goRelative(delta) {
              const target = currentIndex + delta;
              if (target < 0 || target >= videoList.length) return;
              selectVideo(target, { autoplay: true });
            }
            if (prevBtn) prevBtn.addEventListener('click', () => goRelative(-1));
            if (nextBtn) nextBtn.addEventListener('click', () => goRelative(1));
            nextItems.forEach((el) => {
              el.addEventListener('click', () => {
                const i = parseInt(el.getAttribute('data-sv-index'), 10);
                if (!Number.isNaN(i)) selectVideo(i, { autoplay: true });
              });
            });

            // Entry point for strip cards, voucher bar and mini player.
            function openVideoAt(index, control) {
              selectVideo(index);
              openVideoModal(control);
            }

            {
              const first = videoList[0] && videoList[0].reward;
              xu.amount = first && typeof first === 'object' ? Math.max(0, parseInt(first.reward_xu, 10) || 0) : 0;
            }
            applyItemUI(0);
            renderMute();
            renderReward();

            window.SynaProductVideo = { openVideoAt, selectVideo };
          })();
        </script>

        <script>
          (() => {
            const reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
            const openAt = (index, control) => {
              if (window.SynaProductVideo) window.SynaProductVideo.openVideoAt(index, control);
            };

            // Horizontal strips (one on the left column for desktop, one in the buy box for mobile).
            document.querySelectorAll('[data-syna-strip]').forEach((strip) => {
              const scroller = strip.querySelector('[data-strip-scroller]');
              const nav = strip.querySelector('[data-strip-nav]');
              const prev = strip.querySelector('[data-strip-prev]');
              const next = strip.querySelector('[data-strip-next]');
              if (!scroller || !nav || !prev || !next) return;

              const update = () => {
                const max = scroller.scrollWidth - scroller.clientWidth;
                nav.hidden = max <= 2;
                prev.disabled = scroller.scrollLeft <= 2;
                next.disabled = scroller.scrollLeft >= max - 2;
              };
              const page = (dir) => {
                scroller.scrollBy({ left: dir * scroller.clientWidth, behavior: reduceMotion ? 'auto' : 'smooth' });
              };
              prev.addEventListener('click', () => page(-1));
              next.addEventListener('click', () => page(1));
              scroller.addEventListener('scroll', update, { passive: true });
              window.addEventListener('resize', update);
              if (window.ResizeObserver) new ResizeObserver(update).observe(scroller);
              update();
            });

            // Strip cards and the voucher bar button carry data-syna-strip-index.
            document.addEventListener('click', (event) => {
              const trigger = event.target.closest('[data-syna-strip-index]');
              if (!trigger) return;
              const index = parseInt(trigger.getAttribute('data-syna-strip-index'), 10);
              openAt(Number.isNaN(index) ? 0 : index, trigger);
            });

            // Floating mini player of the newest video.
            const mini = document.getElementById('synaMiniPlayer');
            const pill = document.getElementById('synaMiniPill');
            if (!mini || !pill) return;
            const video = mini.querySelector('video');
            const body = mini.querySelector('[data-mini-open]');
            const closeBtn = mini.querySelector('[data-mini-close]');
            const muteBtn = mini.querySelector('[data-mini-mute]');
            const storageKey = 'syna-mini-hidden:' + (mini.dataset.productId || '');
            let hidden = false;
            let modalOpen = false;
            let loaded = false;

            const readFlag = () => {
              try { return window.sessionStorage.getItem(storageKey) === '1'; } catch (e) { return false; }
            };
            const writeFlag = (on) => {
              try {
                if (on) window.sessionStorage.setItem(storageKey, '1');
                else window.sessionStorage.removeItem(storageKey);
              } catch (e) { /* storage unavailable */ }
            };
            const playMini = () => {
              if (!video || reduceMotion || !loaded || hidden || modalOpen) return;
              const result = video.play();
              if (result && typeof result.catch === 'function') result.catch(() => {});
            };
            const pauseMini = () => {
              if (video && !video.paused) video.pause();
            };
            const setHidden = (on, persist) => {
              hidden = on;
              mini.hidden = on;
              pill.hidden = !on;
              if (persist) writeFlag(on);
              if (on) pauseMini(); else playMini();
            };
            const renderMute = () => {
              if (!video || !muteBtn) return;
              const icon = muteBtn.querySelector('i');
              muteBtn.setAttribute('aria-label', video.muted ? 'Bật tiếng' : 'Tắt tiếng');
              if (icon) icon.className = video.muted ? 'fas fa-volume-xmark' : 'fas fa-volume-high';
            };

            setHidden(readFlag(), false);
            renderMute();
            if (reduceMotion && muteBtn) muteBtn.hidden = true;

            const startMini = () => {
              loaded = true;
              if (!video || reduceMotion || !mini.dataset.src) return;
              video.src = mini.dataset.src;
              playMini();
            };
            if (document.readyState === 'complete') startMini();
            else window.addEventListener('load', startMini, { once: true });

            if (closeBtn) closeBtn.addEventListener('click', () => setHidden(true, true));
            pill.addEventListener('click', () => setHidden(false, true));
            if (muteBtn) {
              muteBtn.addEventListener('click', () => {
                if (!video) return;
                video.muted = !video.muted;
                renderMute();
                if (!video.muted) playMini();
              });
            }
            if (body) {
              body.addEventListener('click', () => openAt(0, body));
              body.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                  event.preventDefault();
                  openAt(0, body);
                }
              });
            }
            document.addEventListener('syna-video-modal', (event) => {
              modalOpen = !!(event.detail && event.detail.open);
              if (modalOpen) pauseMini(); else playMini();
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
          $routineRefreshPid = trim((string)($p['ma_san_pham'] ?? $id ?? ''));
          $routineRefreshUrl = BASE_URL . '/index.php?r=routine_recommendations&id=' . rawurlencode($routineRefreshPid);
        ?>
        <!-- Frequently Bought Together / Gợi Ý Mua Kèm (Pairwise Co-occurrence & Routine Fallback) -->
        <div class="frequently-bought-card mt-4 mb-2 p-4 rounded-4 bg-white border w-100"
             data-routine-recommendation-block
             data-routine-refresh-url="<?= h($routineRefreshUrl) ?>"
             data-routine-offset="4"
             style="border-radius: 20px !important; border-color: #E2EADF !important; box-shadow: 0 4px 20px rgba(24, 59, 43, 0.04);">
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
            <div class="d-flex align-items-center gap-2">
              <?php if ($hasRealRule): ?>
                <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.78rem;">Phân tích giao dịch mua cùng</span>
              <?php else: ?>
                <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.78rem;">Gợi ý theo bước dưỡng</span>
              <?php endif; ?>
              <button type="button" class="detail-product-refresh" data-product-block-refresh aria-label="Đổi sản phẩm gợi ý" title="Xem sản phẩm gợi ý khác">
                <i class="fas fa-arrows-rotate" aria-hidden="true"></i>
                <span>Đổi sản phẩm</span>
              </button>
            </div>
          </div>

          <div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 g-3">
            <?php foreach ($frequentlyBought as $fbItem): ?>
              <?php 
                $fbImg = resolve_image_url(function_exists('product_image_source') ? product_image_source($fbItem) : (string)($fbItem['hinh_anh'] ?? $fbItem['link_hinh_anh'] ?? ''));
                $fbProductId = (string)($fbItem['ma_san_pham'] ?? $fbItem['id'] ?? '');
                $fbUrl = BASE_URL . '/index.php?r=chitiet&id=' . urlencode($fbProductId);
                $source = $fbItem['recommendation_source'] ?? '';
                $isGenuineRule = ($source === 'ASSOCIATION_RULE');
                $hasConfidence = $isGenuineRule && isset($fbItem['confidence']) && $fbItem['confidence'] !== null && $fbItem['confidence'] > 0;
                $fbConf = $hasConfidence ? round($fbItem['confidence'] * 100) : null;
                $fbIsOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($fbItem) : false;
              ?>
              <div class="col">
                <div class="card h-100 border p-2.5 d-flex flex-column justify-content-between position-relative" style="border-radius: 14px; border-color: #EEF2F6 !important; background: #FAFCF9; transition: transform 0.2s, box-shadow 0.2s;">
                  <div>
                    <a href="<?= $fbUrl ?>" class="d-block text-center mb-2 overflow-hidden" style="border-radius: 10px; background: #fff; aspect-ratio: 1/1;">
                      <img src="<?= h($fbImg) ?>" 
                           alt="<?= h($fbItem['ten_san_pham'] ?? '') ?>"
                           class="w-100 h-100 object-fit-cover"
                           loading="lazy"
                           decoding="async"
                           width="200"
                           height="200"
                           referrerpolicy="no-referrer"
                           data-image-fallbacks="<?= function_exists('product_image_fallbacks_json') ? product_image_fallbacks_json($fbItem, $fbImg) : '[]' ?>"
                           data-image-placeholder="<?= h(default_placeholder_image()) ?>"
                           onerror="window.SkinSyntaxImageFallback(this)">
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
                    <div class="routine-recommend-actions">
                      <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
                        <input type="hidden" name="action" value="add_to_cart">
                        <input type="hidden" name="product_id" value="<?= h($fbProductId) ?>">
                        <input type="hidden" name="ma_san_pham" value="<?= h($fbProductId) ?>">
                        <input type="hidden" name="quantity" value="1">
                        <input type="hidden" name="qty" value="1">
                        <button class="btn btn-sm w-100 routine-recommend-button routine-recommend-button--cart" type="submit" <?= $fbIsOutOfStock ? 'disabled' : '' ?> title="Thêm vào giỏ">
                          <?= $fbIsOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1" aria-hidden="true"></i> Thêm giỏ' ?>
                        </button>
                      </form>
                      <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
                        <input type="hidden" name="action" value="add_to_cart">
                        <input type="hidden" name="buy_now" value="1">
                        <input type="hidden" name="product_id" value="<?= h($fbProductId) ?>">
                        <input type="hidden" name="ma_san_pham" value="<?= h($fbProductId) ?>">
                        <input type="hidden" name="quantity" value="1">
                        <input type="hidden" name="qty" value="1">
                        <button class="btn btn-sm w-100 routine-recommend-button routine-recommend-button--buy" type="submit" <?= $fbIsOutOfStock ? 'disabled' : '' ?> title="Mua ngay">
                          <?= $fbIsOutOfStock ? 'Hết hàng' : 'Mua ngay' ?>
                        </button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <script src="<?= BASE_URL ?>/assets/js/detail-recommendation-refresh.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/js/detail-recommendation-refresh.js') ?: '1' ?>" defer></script>

      <?php
        // U08: "Sản phẩm tương tự" (SIMILAR_TO trong Neo4j) - tải lười bằng fetch, nằm cạnh khối mua kèm cũ (không thay thế).
        $similarEnabled = !in_array(strtolower(function_exists('ss_env') ? ss_env('SIMILAR_PRODUCTS_ENABLED', '1') : '1'), ['0', 'false', 'off', 'no'], true);
        $similarPid = trim((string)($p['ma_san_pham'] ?? $id ?? ''));
      ?>
      <?php if ($similarEnabled && $similarPid !== '' && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $similarPid)): ?>
        <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/search-fallback.css?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/css/search-fallback.css') ?: '1' ?>">
        <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/similar-products.css?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/css/similar-products.css') ?: '1' ?>">
        <div class="ssim-wrap w-100" data-similar-products data-offset="0" aria-live="polite" aria-busy="true"
             data-url="<?= h(BASE_URL . '/index.php?r=similar_products&id=' . rawurlencode($similarPid)) ?>">
          <div class="ssim-skeleton" aria-hidden="true">
            <div class="ssim-skel-title"></div>
            <div class="ssim-skel-row"><div class="ssim-skel-card"></div><div class="ssim-skel-card"></div><div class="ssim-skel-card d-none d-lg-block"></div><div class="ssim-skel-card d-none d-lg-block"></div></div>
          </div>
        </div>
        <script src="<?= BASE_URL ?>/assets/js/search-fallback.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/js/search-fallback.js') ?: '1' ?>" defer></script>
        <script src="<?= BASE_URL ?>/assets/js/similar-products.js?v=<?= @filemtime(dirname(__DIR__) . '/public/assets/js/similar-products.js') ?: '1' ?>" defer></script>
      <?php endif; ?>

      <?php include __DIR__ . '/partials/recent_viewed_products.php'; ?>

      <div class="detail-tabs-card mt-4 p-4 p-md-5 rounded-4 bg-white border w-100"style="border-radius: 28px !important; border-color: #E2EADF !important; box-shadow: 0 10px 30px rgba(33, 84, 39, 0.04);">
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
                      <div class="review-item__images"><?php foreach ($reviewImages as $reviewImage): ?><img src="<?= h(resolve_image_url($reviewImage)) ?>" alt="Ảnh đánh giá" loading="lazy" decoding="async" width="80" height="80" referrerpolicy="no-referrer" onerror="this.remove();"><?php endforeach; ?></div>
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
  const btns = Array.from(document.querySelectorAll('.thumb-btn'));
  const stage = document.querySelector('.detail-gallery-stage');
  const thumbTrack = document.querySelector('.detail-thumbs-shell .detail-thumbs');
  const prev = document.querySelector('[data-gallery-prev]');
  const next = document.querySelector('[data-gallery-next]');
  const thumbsPrev = document.querySelector('[data-thumbs-prev]');
  const thumbsNext = document.querySelector('[data-thumbs-next]');
  if (!main || !btns.length) return;

  let currentIndex = Math.max(0, btns.findIndex(btn => btn.classList.contains('active')));
  let autoRotateTimer = null;

  const reducedMotion = () => window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const selectImage = (requestedIndex, shouldScrollThumb = true) => {
    currentIndex = (requestedIndex + btns.length) % btns.length;
    const btn = btns[currentIndex];
    const src = btn.getAttribute('data-src');
    if (!src) return;

    main.src = src;
    main.setAttribute('data-image-fallbacks', btn.getAttribute('data-fallbacks') || '[]');
    btns.forEach((item, index) => {
      const isActive = index === currentIndex;
      item.classList.toggle('active', isActive);
      item.setAttribute('aria-current', isActive ? 'true' : 'false');
    });
    if (shouldScrollThumb && typeof btn.scrollIntoView === 'function') {
      btn.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'nearest', inline: 'center' });
    }
  };

  const stopAutoRotate = () => {
    if (autoRotateTimer) {
      window.clearInterval(autoRotateTimer);
      autoRotateTimer = null;
    }
  };

  const startAutoRotate = () => {
    stopAutoRotate();
    if (btns.length < 2 || reducedMotion()) return;
    // Tự đổi ảnh không được kéo cả trang về khu vực thumbnail khi người dùng
    // đang đọc phần nội dung bên dưới sản phẩm.
    autoRotateTimer = window.setInterval(() => selectImage(currentIndex + 1, false), 4500);
  };

  const goTo = (direction) => {
    selectImage(currentIndex + direction);
    startAutoRotate();
  };

  btns.forEach((btn, index) => btn.addEventListener('click', () => {
    selectImage(index);
    startAutoRotate();
  }));
  if (prev) prev.addEventListener('click', () => goTo(-1));
  if (next) next.addEventListener('click', () => goTo(1));
  if (thumbsPrev && thumbTrack) thumbsPrev.addEventListener('click', () => thumbTrack.scrollBy({ left: -220, behavior: reducedMotion() ? 'auto' : 'smooth' }));
  if (thumbsNext && thumbTrack) thumbsNext.addEventListener('click', () => thumbTrack.scrollBy({ left: 220, behavior: reducedMotion() ? 'auto' : 'smooth' }));

  if (stage) {
    stage.addEventListener('mouseenter', stopAutoRotate);
    stage.addEventListener('mouseleave', startAutoRotate);
    stage.addEventListener('focusin', stopAutoRotate);
    stage.addEventListener('focusout', startAutoRotate);
    stage.addEventListener('touchstart', stopAutoRotate, { passive: true });
    stage.addEventListener('touchend', startAutoRotate, { passive: true });
  }
  document.addEventListener('visibilitychange', () => document.hidden ? stopAutoRotate() : startAutoRotate());

  selectImage(currentIndex, false);
  startAutoRotate();
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



