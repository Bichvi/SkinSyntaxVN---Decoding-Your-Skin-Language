<?php
$latest = isset($latest) && is_array($latest) ? $latest : [];
$cats = isset($cats) && is_array($cats) ? $cats : [];
$homepageSections = isset($homepageSections) && is_array($homepageSections) ? $homepageSections : [];
$dbUnavailableMessage = trim((string)($dbUnavailableMessage ?? ''));

$heroProducts = array_slice($latest, 0, 4);
$newProducts = array_slice($latest, 0, 4);
$flashSaleProducts = array_slice(array_values(array_filter($homepageSections['flashDeals'] ?? [])), 0, 8);

$skinConcerns = [
  [
    'id' => 'oil',
    'num' => '01',
    'name' => 'Da Dầu Nhờn',
    'query' => 'da dầu',
    'desc' => 'Tăng tiết bã nhờn quá mức khiến bề mặt da bóng dầu, dễ bít tắc lỗ chân lông và hình thành mụn ẩn.',
    'goal' => 'Kiểm soát bã nhờn, thông thoáng cổ nang lông',
    'ingredients' => 'Niacinamide, Salicylic Acid (BHA), Zinc PCA',
  ],
  [
    'id' => 'acne',
    'num' => '02',
    'name' => 'Mụn & Thâm',
    'query' => 'mụn',
    'desc' => 'Tổn thương do vi khuẩn C.acnes gây viêm, để lại các vết thâm đỏ (PIE) hoặc thâm nâu (PIH) sau khi lành.',
    'goal' => 'Kháng viêm, giảm vi khuẩn mụn & làm mờ thâm',
    'ingredients' => 'Azelaic Acid, Centella Asiatica, Tea Tree Oil',
  ],
  [
    'id' => 'sensitive',
    'num' => '03',
    'name' => 'Da Nhạy Cảm',
    'query' => 'nhạy cảm',
    'desc' => 'Màng bảo vệ da bị mỏng yếu, dễ mẩn đỏ, châm chít khi tiếp xúc với thời tiết hoặc mỹ phẩm không phù hợp.',
    'goal' => 'Củng cố hàng rào lipid, xoa dịu kích ứng',
    'ingredients' => 'Ceramides, Panthenol (B5), Allantoin',
  ],
  [
    'id' => 'dull',
    'num' => '04',
    'name' => 'Xỉn Màu / Thâm',
    'query' => 'thâm',
    'desc' => 'Sắc tố melanin tích tụ không đều mảng, da thiếu sức sống do tế bào chết dư thừa và tác động từ tia UV.',
    'goal' => 'Ức chế sắc tố, dưỡng sáng & đều màu da',
    'ingredients' => 'Vitamin C (L-AA/EAA), Alpha Arbutin, Tranexamic Acid',
  ],
  [
    'id' => 'repair',
    'num' => '05',
    'name' => 'Phục Hồi Da',
    'query' => 'phục hồi',
    'desc' => 'Làn da vừa qua quá trình treatment nặng hoặc dermo-peel cần tái tạo tế bào và bù đắp dưỡng chất khẩn cấp.',
    'goal' => 'Tái thiết màng tế bào, gia tăng độ đàn hồi',
    'ingredients' => 'EGF Peptides, Madecassoside, Bifida Ferment',
  ],
  [
    'id' => 'hydrate',
    'num' => '06',
    'name' => 'Cấp Ẩm Đa Tầng',
    'query' => 'dưỡng ẩm',
    'desc' => 'Tình trạng thiếu nước từ sâu bên trong mô da làm nếp nhăn li ti xuất hiện và bề mặt da khô ráp, bong tróc.',
    'goal' => 'Cấp nước đa tầng, ngậm ẩm suốt 24h',
    'ingredients' => 'Multi-weight Hyaluronic Acid, Glycerin, Squalane',
  ],
  [
    'id' => 'aging',
    'num' => '07',
    'name' => 'Chống Lão Hóa',
    'query' => 'lão hóa',
    'desc' => 'Suy giảm collagen & elastin tự nhiên làm da mất độ săn chắc, xuất hiện nếp nhăn vùng mắt và rãnh cười.',
    'goal' => 'Kích thích tổng hợp Collagen, làm đầy nếp nhăn',
    'ingredients' => 'Retinol / Bakuchiol, Copper Tripeptide, Adenosine',
  ],
  [
    'id' => 'pores',
    'num' => '08',
    'name' => 'Se Lỗ Chân Lông',
    'query' => 'lỗ chân lông',
    'desc' => 'Lỗ chân lông dãn nở do tuyến dầu hoạt động mạnh kết hợp giảm độ đàn hồi ở thành nang lông.',
    'goal' => 'Thắt chặt cổ nang lông, mịn bề mặt da',
    'ingredients' => 'Niacinamide 10%, Hamamelis Water, LHA',
  ],
];

$brandNames = [];
foreach ($latest as $item) {
  $brand = trim((string)($item['thuong_hieu'] ?? ''));
  if ($brand !== '' && !in_array($brand, $brandNames, true)) {
    $brandNames[] = $brand;
  }
  if (count($brandNames) >= 6) {
    break;
  }
}

$userProfile = is_array($userProfile ?? null) ? $userProfile : [];
$isLoggedIn = (bool)($isLoggedIn ?? false);
$hasSurvey = (bool)($hasSurvey ?? false);

$skinTypeDisplay = !empty($userProfile['skin_type']) ? $userProfile['skin_type'] : ($hasSurvey ? 'Đã hoàn thành khảo sát' : '—');
$concernsDisplay = !empty($userProfile['concerns']) ? implode(', ', $userProfile['concerns']) : (!empty($userProfile['sensitivity']) ? $userProfile['sensitivity'] : '—');
$avoidDisplay = !empty($userProfile['avoid_ingredients']) ? implode(', ', $userProfile['avoid_ingredients']) : '—';

$renderHomeProductCard = static function (array $p, string $tag = '') use ($hasSurvey): void {
  $productId = (string)($p['id'] ?? $p['ma_san_pham'] ?? '');
  $img = resolve_image_url((string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''));
  $giaBan = (string)($p['gia_ban'] ?? '');
  $giaThiTruong = trim((string)($p['gia_thi_truong'] ?? ''));
  $phanTramGiam = function_exists('product_discount_percent') ? product_discount_percent($p) : null;
  $matchScore = isset($p['match_score']) && is_numeric($p['match_score']) ? (int)$p['match_score'] : null;
  $rating = isset($p['diem_danh_gia']) && (float)$p['diem_danh_gia'] > 0 ? (float)$p['diem_danh_gia'] : 4.9;
  $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($p) : false;
  ?>
    <div class="product-card product-card--editorial h-100 d-flex flex-column">
      <div class="product-thumb position-relative p-2" style="background: #F8FAF8; border-radius: 12px 12px 0 0;">
        <?php if ($phanTramGiam !== null): ?>
          <span class="badge-sale position-absolute" style="top: 12px; left: 12px; background: #183B2B; color: #FFFFFF; font-weight: 700; font-size: 0.72rem; padding: 3px 8px; border-radius: 4px; z-index: 3;">
            -<?= h((string)$phanTramGiam) ?>%
          </span>
        <?php endif; ?>

        <?php if ($matchScore !== null && $matchScore > 0): ?>
          <span class="badge-match position-absolute" style="top: 12px; right: 12px; background: #EBF2EE; color: #183B2B; border: 1px solid #C8DACF; font-size: 0.72rem; font-weight: 600; padding: 3px 8px; border-radius: 4px; z-index: 3;">
            <?= $matchScore ?>% MATCH
          </span>
        <?php elseif ($tag !== ''): ?>
          <span class="market-card__tag position-absolute" style="top: 12px; right: 12px; background: #F1F5F9; color: #475569; font-size: 0.7rem; font-weight: 600; padding: 3px 8px; border-radius: 4px; z-index: 3; border: 1px solid #E2E8F0;">
            <?= h($tag) ?>
          </span>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" class="d-block w-100 overflow-hidden" style="border-radius: 8px; aspect-ratio: 1/1;">
          <img class="product-card-img" src="<?= h($img ?: default_placeholder_image()) ?>" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';" alt="<?= h($p['ten_san_pham'] ?? '') ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
        </a>
      </div>

      <div class="product-meta p-3 d-flex flex-column flex-grow-1">
        <div class="mb-1.5 d-flex align-items-center justify-content-between">
          <span class="brand text-uppercase" style="font-size: 0.68rem; color: #183B2B; background: #EBF2EE; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.04em; font-weight: 700;">
            <?= h($p['thuong_hieu'] ?? 'SkinSyntax') ?>
          </span>

          <span class="skin-match-status" style="font-size: 0.72rem; color: var(--muted);">
            <?php if ($matchScore !== null && $matchScore > 0): ?>
              <span class="text-success fw-semibold"><i class="bi bi-check2-circle"></i> Tương thích</span>
            <?php elseif ($hasSurvey): ?>
              <span class="text-secondary"><i class="bi bi-shield-check"></i> Đã kiểm tra da</span>
            <?php else: ?>
              <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="text-decoration-none text-muted" style="font-size: 0.7rem;"><i class="bi bi-info-circle"></i> Kiểm tra độ hợp &rarr;</a>
            <?php endif; ?>
          </span>
        </div>

        <a class="name fw-semibold mb-2 text-decoration-none" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" style="color: #0F172A; font-size: 0.88rem; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.4em;">
          <?= h($p['ten_san_pham'] ?? '') ?>
        </a>

        <div class="d-flex align-items-center gap-1 mb-2" style="font-size: 0.76rem;">
          <span class="d-inline-flex align-items-center gap-1 text-warning font-weight-bold">
            <i class="fas fa-star" style="color: #F59E0B; font-size: 0.72rem;"></i> <?= number_format($rating, 1) ?>
          </span>
          <span class="text-muted small ms-1">(<?= (int)($p['so_luong_danh_gia'] ?? 128) ?>)</span>
        </div>

        <div class="price-wrap mb-3 mt-auto d-flex align-items-baseline gap-2">
          <div class="price fw-bold" style="color: #183B2B; font-size: 1.05rem; font-variant-numeric: tabular-nums;"><?= vnd($giaBan) ?></div>
          <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)$giaBan): ?>
            <div class="price-market text-muted text-decoration-line-through" style="font-size: 0.8rem; font-variant-numeric: tabular-nums; color: #94A3B8 !important;"><?= vnd($giaThiTruong) ?></div>
          <?php endif; ?>
        </div>

        <div class="product-card-actions d-grid gap-2" style="grid-template-columns: 1fr 1fr;">
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="btn btn-sm w-100" type="submit" style="background: #F1F5F9; color: #0F172A; border: 1px solid #E2E8F0; border-radius: 6px; font-weight: 600; font-size: 0.78rem; padding: 7px 0; transition: all 0.2s ease;" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1"></i> Thêm' ?></button>
          </form>
          <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0">
            <input type="hidden" name="action" value="add_to_cart">
            <input type="hidden" name="buy_now" value="1">
            <input type="hidden" name="product_id" value="<?= h($productId) ?>">
            <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
            <input type="hidden" name="quantity" value="1">
            <input type="hidden" name="qty" value="1">
            <button class="btn btn-sm w-100 text-white" type="submit" style="background: #183B2B; border-radius: 6px; font-weight: 600; font-size: 0.78rem; padding: 7px 0; border: none; transition: background 0.2s ease;" <?= $isOutOfStock ? 'disabled' : '' ?>><?= $isOutOfStock ? 'Hết hàng' : 'Mua ngay' ?></button>
          </form>
        </div>
      </div>
    </div>
  <?php
};
?>

<style>
/* DIGITAL SKIN LAB x BOTANICAL EDITORIAL STYLES */
.lab-hero-card {
  background: #183B2B;
  color: #FFFFFF;
  border-radius: 16px;
  border: 1px solid #2D6A4F;
  padding: 48px 40px;
  position: relative;
  overflow: hidden;
}
.lab-hero-kicker {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: rgba(255, 255, 255, 0.12);
  color: #E2E8F0;
  padding: 4px 14px;
  border-radius: 6px;
  font-size: 0.78rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
.lab-hero-title {
  font-size: 2.6rem;
  font-weight: 800;
  line-height: 1.2;
  letter-spacing: -0.02em;
  color: #FFFFFF;
  margin-top: 16px;
  margin-bottom: 16px;
}
.lab-hero-subtitle {
  color: #CBD5E1;
  font-size: 1rem;
  line-height: 1.65;
  max-width: 580px;
  margin-bottom: 28px;
}
.signal-chip-group {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.signal-chip {
  background: rgba(255, 255, 255, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.2);
  color: #FFFFFF;
  padding: 8px 16px;
  border-radius: 6px;
  font-size: 0.84rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s ease;
  user-select: none;
}
.signal-chip:hover, .signal-chip.active {
  background: #FFFFFF;
  color: #183B2B;
  border-color: #FFFFFF;
  font-weight: 600;
}
.decoding-preview-card {
  background: rgba(255, 255, 255, 0.96);
  color: #0F172A;
  border-radius: 12px;
  border: 1px solid #E2E8F0;
  padding: 24px;
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.15);
}
.decoding-metric-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 0;
  border-bottom: 1px solid #F1F5F9;
  font-size: 0.85rem;
}
.decoding-metric-row:last-child {
  border-bottom: none;
}
.decoding-metric-val {
  font-family: monospace;
  font-weight: 700;
  font-size: 0.82rem;
  letter-spacing: 0.05em;
  padding: 2px 8px;
  border-radius: 4px;
}
.val-high { background: #FEF2F2; color: #991B1B; }
.val-medium { background: #FEF3C7; color: #92400E; }
.val-low { background: #DCFCE7; color: #15803D; }
.val-neutral { background: #F1F5F9; color: #475569; }

/* SIGNATURE SKIN PROFILE REPORT */
.skin-profile-report {
  background: #FFFFFF;
  border: 1px solid #E2E8F0;
  border-radius: 14px;
  padding: 32px;
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
}
.report-header {
  border-bottom: 1px solid #E2E8F0;
  padding-bottom: 16px;
  margin-bottom: 24px;
}
.report-title {
  font-size: 0.76rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #183B2B;
}

/* ASYMMETRIC SKIN SIGNALS EXPLORER */
.signals-list-col {
  border-right: 1px solid #E2E8F0;
  padding-right: 24px;
}
@media (max-width: 991.98px) {
  .signals-list-col {
    border-right: none;
    border-bottom: 1px solid #E2E8F0;
    padding-right: 0;
    padding-bottom: 20px;
    margin-bottom: 20px;
  }
}
.signal-item-link {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  border-radius: 8px;
  border: 1px solid transparent;
  color: #0F172A;
  text-decoration: none;
  font-weight: 600;
  font-size: 0.9rem;
  transition: all 0.2s ease;
  margin-bottom: 6px;
}
.signal-item-link:hover, .signal-item-link.active {
  background: #EBF2EE;
  border-color: #C8DACF;
  color: #183B2B;
}
.signal-num {
  font-family: monospace;
  font-size: 0.8rem;
  color: var(--muted);
  font-weight: 700;
  margin-right: 12px;
}

/* VISUAL ROUTINE SEQUENCE PROGRESSION */
.routine-sequence-bar {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  position: relative;
  margin-bottom: 24px;
}
@media (max-width: 767.98px) {
  .routine-sequence-bar {
    grid-template-columns: 1fr 1fr;
  }
}
.sequence-step-card {
  background: #FFFFFF;
  border: 1px solid #E2E8F0;
  border-radius: 10px;
  padding: 18px;
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
}
.sequence-step-card:hover, .sequence-step-card.active {
  border-color: #183B2B;
  box-shadow: 0 4px 14px rgba(24, 59, 43, 0.08);
}
.sequence-step-card.active {
  background: #F4F7F5;
}
.step-number {
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  color: #183B2B;
  text-transform: uppercase;
  margin-bottom: 4px;
}
.step-name {
  font-size: 1rem;
  font-weight: 700;
  color: #0F172A;
}

/* EDITORIAL PRODUCT CARD SHOWCASE */
.product-card--editorial {
  border-radius: 12px !important;
  border: 1px solid #E2E8F0 !important;
  background: #FFFFFF !important;
  transition: all 0.25s ease !important;
}
.product-card--editorial:hover {
  border-color: #C8DACF !important;
  box-shadow: 0 8px 24px rgba(24, 59, 43, 0.08) !important;
}
</style>

<div class="container mt-4 home-shell">
  <?php if ($dbUnavailableMessage !== ''): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4" style="border-radius: 12px; background: #FFFBEB; color: #B45309;"><?= h($dbUnavailableMessage) ?></div>
  <?php endif; ?>

  <!-- SECTION 1: HERO — "DECODE YOUR SKIN" -->
  <section class="lab-hero-card mb-5">
    <div class="row align-items-center">
      <div class="col-lg-7 mb-4 mb-lg-0">
        <span class="lab-hero-kicker"><i class="bi bi-cpu me-1"></i> Digital Skin Lab Engine</span>
        <h1 class="lab-hero-title">DA BẠN ĐANG MUỐN NÓI GÌ?</h1>
        <p class="lab-hero-subtitle">
          SkinSyntax phiên dịch từng tín hiệu làn da — từ lượng dầu nhờn, độ ẩm đến mức độ nhạy cảm — giúp bạn thấu hiểu làn da, lựa chọn hoạt chất chuẩn y khoa và xây dựng quy trình skincare tương thích.
        </p>

        <div class="mb-4">
          <label class="d-block text-white-50 small fw-semibold text-uppercase mb-2.5" style="letter-spacing: 0.05em; font-size: 0.72rem;">Chọn tín hiệu làn da bạn đang gặp phải:</label>
          <div class="signal-chip-group" id="heroSignalGroup">
            <span class="signal-chip active" data-signal="oil" data-oil="HIGH" data-hydration="MEDIUM" data-sensitivity="NORMAL" data-ing="Niacinamide + Salicylic Acid">Đổ dầu</span>
            <span class="signal-chip" data-signal="dry" data-oil="LOW" data-hydration="LOW" data-sensitivity="NORMAL" data-ing="Hyaluronic Acid + Ceramides">Khô căng</span>
            <span class="signal-chip" data-signal="acne" data-oil="HIGH" data-hydration="LOW" data-sensitivity="HIGH" data-ing="Azelaic Acid + B5">Mụn</span>
            <span class="signal-chip" data-signal="redness" data-oil="MEDIUM" data-hydration="LOW" data-sensitivity="HIGH" data-ing="Centella + Madecassoside">Đỏ / Nhạy cảm</span>
            <span class="signal-chip" data-signal="dark" data-oil="MEDIUM" data-hydration="MEDIUM" data-sensitivity="NORMAL" data-ing="Vitamin C + Tranexamic">Thâm xỉn</span>
            <span class="signal-chip" data-signal="unknown" data-oil="UNKNOWN" data-hydration="UNKNOWN" data-sensitivity="UNKNOWN" data-ing="Cần khảo sát chi tiết">Chưa rõ</span>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-3">
          <a class="btn py-2.5 px-4 fw-semibold text-white" href="<?= BASE_URL ?>/index.php?r=goiy" style="background: #2D6A4F; border-radius: 6px; font-size: 0.88rem;">
            Phân tích làn da của bạn &rarr;
          </a>
          <a class="btn py-2.5 px-4 fw-semibold text-white" href="<?= BASE_URL ?>/index.php?r=tatca" style="background: transparent; border: 1px solid rgba(255,255,255,0.3); border-radius: 6px; font-size: 0.88rem;">
            Tra cứu mỹ phẩm
          </a>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="decoding-preview-card">
          <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
            <div>
              <div class="small text-muted fw-bold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.06em;">TÍN HIỆU GIẢI MÃ (PREVIEW)</div>
              <div class="fw-bold" style="color: #183B2B; font-size: 0.95rem;" id="previewSignalTitle">Da Đổ Dầu Nhiều</div>
            </div>
            <span class="badge px-2 py-1" style="background: #EBF2EE; color: #183B2B; border-radius: 4px; font-size: 0.72rem;">Live Signal</span>
          </div>

          <div class="decoding-metric-row">
            <span class="text-muted">Mức độ bã nhờn (Oil Level)</span>
            <span class="decoding-metric-val val-high" id="metricOil">HIGH</span>
          </div>
          <div class="decoding-metric-row">
            <span class="text-muted">Độ ẩm tầng biểu bì (Hydration)</span>
            <span class="decoding-metric-val val-medium" id="metricHydration">MEDIUM</span>
          </div>
          <div class="decoding-metric-row">
            <span class="text-muted">Độ nhạy cảm (Sensitivity)</span>
            <span class="decoding-metric-val val-neutral" id="metricSensitivity">NORMAL</span>
          </div>
          <div class="decoding-metric-row">
            <span class="text-muted">Hoạt chất mục tiêu</span>
            <strong class="small text-dark" id="metricIng">Niacinamide + BHA</strong>
          </div>

          <div class="mt-3 pt-2 border-top text-center">
            <small class="text-muted d-block mb-2" style="font-size: 0.76rem;">*Kết quả phân tích chính xác dựa trên bài khảo sát da cá nhân.</small>
            <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="btn btn-sm w-100 fw-semibold text-white" style="background: #183B2B; border-radius: 6px; font-size: 0.8rem;">Bắt đầu khảo sát da 1 phút &rarr;</a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- SECTION 2: SIGNATURE COMPONENT — SKIN PROFILE REPORT -->
  <section class="skin-profile-report mb-5">
    <div class="report-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
      <div>
        <div class="report-title">SKINSYNTAX DATA REPORT</div>
        <h3 class="fw-bold m-0" style="color: #0F172A; font-size: 1.4rem;">Hồ Sơ & Chỉ Số Làn Da</h3>
      </div>
      <div>
        <span class="badge px-3 py-1.5 fw-semibold" style="background: <?= $hasSurvey ? '#DCFCE7' : '#F1F5F9' ?>; color: <?= $hasSurvey ? '#15803D' : '#64748B' ?>; border-radius: 4px; font-size: 0.78rem; border: 1px solid <?= $hasSurvey ? '#BBF7D0' : '#E2E8F0' ?>;">
          Status: <?= $hasSurvey ? 'Đã hoàn thành phân tích' : 'Chưa phân tích' ?>
        </span>
      </div>
    </div>

    <div class="row g-4 align-items-center">
      <div class="col-md-3">
        <div class="p-3 bg-light rounded-3 border" style="border-radius: 8px !important;">
          <div class="small text-muted mb-1" style="font-size: 0.76rem;">Loại da xác định</div>
          <div class="fw-bold" style="color: #183B2B; font-size: 1.1rem;"><?= h($skinTypeDisplay) ?></div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="p-3 bg-light rounded-3 border" style="border-radius: 8px !important;">
          <div class="small text-muted mb-1" style="font-size: 0.76rem;">Vấn đề ưu tiên</div>
          <div class="fw-bold text-truncate" style="color: #0F172A; font-size: 1.1rem;"><?= h($concernsDisplay) ?></div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="p-3 bg-light rounded-3 border" style="border-radius: 8px !important;">
          <div class="small text-muted mb-1" style="font-size: 0.76rem;">Độ tương thích mỹ phẩm</div>
          <div class="fw-bold" style="color: <?= $hasSurvey ? '#15803D' : '#64748B' ?>; font-size: 1.1rem;">
            <?= $hasSurvey ? 'Khả dụng (Fit Data)' : 'Chưa tính toán' ?>
          </div>
        </div>
      </div>
      <div class="col-md-3 text-md-end">
        <?php if ($hasSurvey): ?>
          <a href="<?= BASE_URL ?>/index.php?r=goiy" class="btn text-white fw-semibold px-4 py-2" style="background: #183B2B; border-radius: 6px; font-size: 0.85rem;">Xem Routine đề xuất &rarr;</a>
        <?php else: ?>
          <a href="<?= BASE_URL ?>/index.php?r=khaosat" class="btn btn-outline-secondary fw-semibold px-4 py-2" style="border-radius: 6px; font-size: 0.85rem; border-color: #183B2B; color: #183B2B;">Hoàn thiện hồ sơ da &rarr;</a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- SECTION 3: SKIN SIGNALS EXPLORER (ASYMMETRIC EDITORIAL LAYOUT) -->
  <section class="mb-5 p-4 p-md-5 bg-white border" style="border-radius: 16px; border-color: var(--border) !important;">
    <div class="mb-4">
      <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.75rem;">SKIN SIGNALS EXPLORER</span>
      <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.7rem;">Khám Phá Tín Hiệu Làn Da</h2>
    </div>

    <div class="row g-4 align-items-stretch">
      <!-- Left Column: Numbered Concern List -->
      <div class="col-lg-5 signals-list-col">
        <?php foreach ($skinConcerns as $index => $concern): ?>
          <a href="javascript:void(0)" class="signal-item-link <?= $index === 0 ? 'active' : '' ?>" data-concern-index="<?= $index ?>">
            <span>
              <span class="signal-num"><?= $concern['num'] ?></span>
              <?= h($concern['name']) ?>
            </span>
            <i class="bi bi-arrow-right small text-muted"></i>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- Right Column: Asymmetric Detail Panel -->
      <div class="col-lg-7 d-flex flex-column justify-content-between">
        <div class="p-4 rounded-3 h-100 d-flex flex-column justify-content-between" style="background: #F8FAF8; border: 1px solid #C8DACF;" id="concernDetailPanel">
          <div>
            <div class="d-flex align-items-center justify-content-between mb-3">
              <span class="badge px-3 py-1 fw-bold" style="background: #183B2B; color: #FFF; border-radius: 4px; font-size: 0.78rem;" id="detailNum">01</span>
              <span class="text-muted small" style="font-size: 0.78rem;">Phân loại tín hiệu da</span>
            </div>

            <h3 class="fw-bold mb-3" style="color: #0F172A; font-size: 1.4rem;" id="detailName">Da Dầu Nhờn</h3>
            <p class="text-muted mb-4" style="line-height: 1.65; font-size: 0.92rem;" id="detailDesc">
              Tăng tiết bã nhờn quá mức khiến bề mặt da bóng dầu, dễ bít tắc lỗ chân lông và hình thành mụn ẩn.
            </p>

            <div class="p-3 bg-white rounded-3 border mb-3" style="border-radius: 8px !important;">
              <div class="small text-muted fw-semibold mb-1" style="font-size: 0.75rem;">MỤC TIÊU MỸ PHẨM KHUYẾN NGHỊ:</div>
              <div class="fw-bold" style="color: #183B2B; font-size: 0.88rem;" id="detailGoal">Kiểm soát bã nhờn, thông thoáng cổ nang lông</div>
            </div>

            <div class="p-3 bg-white rounded-3 border mb-4" style="border-radius: 8px !important;">
              <div class="small text-muted fw-semibold mb-1" style="font-size: 0.75rem;">HOẠT CHẤT PHÙ HỢP:</div>
              <div class="fw-bold" style="color: #0F172A; font-size: 0.88rem;" id="detailIng">Niacinamide, Salicylic Acid (BHA), Zinc PCA</div>
            </div>
          </div>

          <div>
            <a href="<?= BASE_URL ?>/index.php?r=tatca&q=da%20d%E1%BA%A7u" class="btn text-white fw-semibold px-4 py-2.5 w-100 text-center d-block" style="background: #183B2B; border-radius: 6px; font-size: 0.86rem;" id="detailCta">
              Tìm mỹ phẩm cho làn da này &rarr;
            </a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- SECTION 4: VISUAL ROUTINE PROGRESSION SEQUENCE -->
  <section class="mb-5 p-4 p-md-5 bg-white border" style="border-radius: 16px; border-color: var(--border) !important;">
    <div class="text-center mx-auto mb-4" style="max-width: 600px;">
      <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.75rem;">QUY TRÌNH SKINCARE CHUẨN Y KHOA</span>
      <h2 class="fw-bold" style="color: #0F172A; font-size: 1.6rem;">Trình Tự Routine 4 Bước</h2>
      <p class="text-muted small mb-0">Một chu trình chăm sóc da khoa học phải được thực hiện theo đúng trình tự để hoạt chất thẩm thấu tối đa.</p>
    </div>

    <div class="routine-sequence-bar" id="routineSequenceBar">
      <div class="sequence-step-card active" data-step="1" data-title="Làm Sạch Sâu (Cleanse)" data-desc="Loại bỏ tạp chất, bụi mịn và dầu thừa bằng nước tẩy trang dịu nhẹ và sữa rửa mặt cân bằng pH." data-query="làm sạch">
        <div class="step-number">STEP 01</div>
        <div class="step-name">CLEANSE</div>
      </div>
      <div class="sequence-step-card" data-step="2" data-title="Tinh Chất Đặc Trị (Treat)" data-desc="Sử dụng Serum/Ampoule chứa AHA, BHA, Niacinamide hoặc Vitamin C để giải quyết trực tiếp vấn đề da." data-query="serum">
        <div class="step-number">STEP 02</div>
        <div class="step-name">TREAT</div>
      </div>
      <div class="sequence-step-card" data-step="3" data-title="Dưỡng Ẩm Khóa Màng (Hydrate)" data-desc="Cung cấp độ ẩm và củng cố hàng rào màng lipid bằng kem dưỡng dạng Gel/Cream chứa Ceramides & B5." data-query="dưỡng ẩm">
        <div class="step-number">STEP 03</div>
        <div class="step-name">HYDRATE</div>
      </div>
      <div class="sequence-step-card" data-step="4" data-title="Bảo Vệ Màng Mỏng (Protect)" data-desc="Thoa kem chống nắng màng lọc kép phổ rộng bảo vệ tế bào da trước tác động của tia UVA/UVB." data-query="chống nắng">
        <div class="step-number">STEP 04</div>
        <div class="step-name">PROTECT</div>
      </div>
    </div>

    <div class="p-4 rounded-3 border" style="background: #F8FAF8; border-color: #C8DACF !important;" id="routineStepDetail">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
          <span class="badge mb-2" style="background: #183B2B; color: #FFF; border-radius: 4px; font-size: 0.72rem;" id="stepBadge">BƯỚC 01</span>
          <h4 class="fw-bold mb-1" style="color: #0F172A;" id="stepTitle">Làm Sạch Sâu (Cleanse)</h4>
          <p class="text-muted small mb-0" style="max-width: 680px;" id="stepDesc">Loại bỏ tạp chất, bụi mịn và dầu thừa bằng nước tẩy trang dịu nhẹ và sữa rửa mặt cân bằng pH.</p>
        </div>
        <div>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=l%C3%A0m+s%E1%BA%A1ch" class="btn text-white fw-semibold px-4 py-2 text-nowrap" style="background: #183B2B; border-radius: 6px; font-size: 0.84rem;" id="stepLink">
            Xem sản phẩm thuộc bước này &rarr;
          </a>
        </div>
      </div>
    </div>
  </section>

  <!-- SECTION 5: FLASH SALE (DISCOVERY BEFORE SELLING) -->
  <?php if (!empty($flashSaleProducts)): ?>
    <section class="mb-5 p-4 p-md-5 text-white" style="background: #183B2B; border-radius: 16px; border: 1px solid #2D6A4F;" data-flash-sale-countdown>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
          <span class="text-uppercase fw-semibold small text-white-50" style="letter-spacing: 0.05em; font-size: 0.72rem;">DEAL CHỚP NHOÁNG HOẠT CHẤT</span>
          <h2 class="fw-bold m-0 text-white" style="font-size: 1.7rem;"><i class="fas fa-bolt text-warning me-2"></i> Flash Sale Mỹ Phẩm</h2>
        </div>

        <div class="d-flex align-items-center gap-2" aria-label="Thời gian còn lại">
          <div class="text-center px-3 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 6px; min-width: 50px;">
            <strong class="d-block text-white fs-5 fw-bold tabular-nums" data-flash-days>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.62rem;">Ngày</small>
          </div>
          <span class="text-white fw-bold fs-5">:</span>
          <div class="text-center px-3 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 6px; min-width: 50px;">
            <strong class="d-block text-white fs-5 fw-bold tabular-nums" data-flash-hours>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.62rem;">Giờ</small>
          </div>
          <span class="text-white fw-bold fs-5">:</span>
          <div class="text-center px-3 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 6px; min-width: 50px;">
            <strong class="d-block text-white fs-5 fw-bold tabular-nums" data-flash-minutes>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.62rem;">Phút</small>
          </div>
          <span class="text-white fw-bold fs-5">:</span>
          <div class="text-center px-3 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 6px; min-width: 50px;">
            <strong class="d-block text-white fs-5 fw-bold tabular-nums" data-flash-seconds>00</strong>
            <small class="text-white-50 text-uppercase" style="font-size: 0.62rem;">Giây</small>
          </div>
        </div>

        <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn btn-light px-3.5 py-2 fw-semibold" style="color: #183B2B; border-radius: 6px; font-size: 0.84rem; background: #FFF; border: none;">Xem tất cả deal &rarr;</a>
      </div>

      <div class="row g-3">
        <?php foreach ($flashSaleProducts as $p): ?>
          <div class="col-6 col-md-3">
            <?php $renderHomeProductCard($p, 'Flash Sale'); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- SECTION 6: NEW PRODUCTS -->
  <?php if (!empty($newProducts)): ?>
    <section class="mb-5">
      <div class="d-flex justify-content-between align-items-end mb-3">
        <div>
          <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.72rem;">SẢN PHẨM MỚI</span>
          <h3 class="fw-bold m-0" style="color: #0F172A; font-size: 1.5rem;">Mỹ Phẩm Vừa Lên Kệ</h3>
        </div>
        <a href="<?= BASE_URL ?>/index.php?r=tatca" class="fw-semibold text-decoration-none" style="color: #183B2B; font-size: 0.85rem;">Xem tất cả <i class="fas fa-arrow-right ms-1"></i></a>
      </div>

      <div class="row g-3">
        <?php foreach (array_slice($newProducts, 0, 4) as $p): ?>
          <div class="col-6 col-md-3">
            <?php $renderHomeProductCard($p, 'Mới lên kệ'); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- SECTION 7: BRAND RIBBON -->
  <?php if (!empty($brandNames)): ?>
    <section class="p-4 mb-5 bg-white border text-center" style="border-radius: 12px; border-color: var(--border) !important;">
      <span class="text-uppercase fw-semibold small text-muted mb-2.5 d-block" style="letter-spacing: 0.05em; font-size: 0.72rem;">THƯƠNG HIỆU Y KHOA ĐƯỢC TIN DÙNG</span>
      <div class="d-flex flex-wrap justify-content-center gap-2">
        <?php foreach ($brandNames as $brand): ?>
          <a href="<?= BASE_URL ?>/index.php?r=tatca&q=<?= urlencode($brand) ?>" class="btn btn-sm btn-light px-3 py-1.5 fw-semibold" style="background: #F8FAF8; color: #0F172A; border: 1px solid var(--border); border-radius: 6px; font-size: 0.8rem;"><?= h($brand) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<script>
// Interactive Client-side Scripting for Digital Skin Lab Concept
document.addEventListener('DOMContentLoaded', function () {
  // 1. Hero Skin Signal Selector Interaction
  var chips = document.querySelectorAll('#heroSignalGroup .signal-chip');
  var metricOil = document.getElementById('metricOil');
  var metricHydration = document.getElementById('metricHydration');
  var metricSensitivity = document.getElementById('metricSensitivity');
  var metricIng = document.getElementById('metricIng');
  var previewSignalTitle = document.getElementById('previewSignalTitle');

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      chips.forEach(function (c) { c.classList.remove('active'); });
      chip.classList.add('active');

      var oil = chip.getAttribute('data-oil') || 'MEDIUM';
      var hyd = chip.getAttribute('data-hydration') || 'MEDIUM';
      var sen = chip.getAttribute('data-sensitivity') || 'NORMAL';
      var ing = chip.getAttribute('data-ing') || 'Niacinamide';
      var title = chip.textContent || 'Signal';

      if (previewSignalTitle) previewSignalTitle.textContent = 'Tín hiệu: ' + title;
      if (metricOil) {
        metricOil.textContent = oil;
        metricOil.className = 'decoding-metric-val ' + (oil === 'HIGH' ? 'val-high' : (oil === 'LOW' ? 'val-low' : 'val-medium'));
      }
      if (metricHydration) {
        metricHydration.textContent = hyd;
        metricHydration.className = 'decoding-metric-val ' + (hyd === 'LOW' ? 'val-high' : (hyd === 'HIGH' ? 'val-low' : 'val-medium'));
      }
      if (metricSensitivity) {
        metricSensitivity.textContent = sen;
        metricSensitivity.className = 'decoding-metric-val ' + (sen === 'HIGH' ? 'val-high' : 'val-neutral');
      }
      if (metricIng) metricIng.textContent = ing;
    });
  });

  // 2. Asymmetric Skin Signals Explorer Dynamic Switching
  var skinConcernsData = <?= json_encode($skinConcerns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var signalLinks = document.querySelectorAll('.signal-item-link');
  var detailNum = document.getElementById('detailNum');
  var detailName = document.getElementById('detailName');
  var detailDesc = document.getElementById('detailDesc');
  var detailGoal = document.getElementById('detailGoal');
  var detailIng = document.getElementById('detailIng');
  var detailCta = document.getElementById('detailCta');

  signalLinks.forEach(function (link) {
    link.addEventListener('click', function () {
      signalLinks.forEach(function (l) { l.classList.remove('active'); });
      link.classList.add('active');

      var idx = parseInt(link.getAttribute('data-concern-index'), 10);
      var item = skinConcernsData[idx];
      if (!item) return;

      if (detailNum) detailNum.textContent = item.num;
      if (detailName) detailName.textContent = item.name;
      if (detailDesc) detailDesc.textContent = item.desc;
      if (detailGoal) detailGoal.textContent = item.goal;
      if (detailIng) detailIng.textContent = item.ingredients;
      if (detailCta) detailCta.href = '<?= BASE_URL ?>/index.php?r=tatca&q=' + encodeURIComponent(item.query);
    });
  });

  // 3. Visual Routine Sequence Step Cards
  var stepCards = document.querySelectorAll('#routineSequenceBar .sequence-step-card');
  var stepBadge = document.getElementById('stepBadge');
  var stepTitle = document.getElementById('stepTitle');
  var stepDesc = document.getElementById('stepDesc');
  var stepLink = document.getElementById('stepLink');

  stepCards.forEach(function (card) {
    card.addEventListener('click', function () {
      stepCards.forEach(function (c) { c.classList.remove('active'); });
      card.classList.add('active');

      var step = card.getAttribute('data-step');
      var title = card.getAttribute('data-title');
      var desc = card.getAttribute('data-desc');
      var query = card.getAttribute('data-query');

      if (stepBadge) stepBadge.textContent = 'BƯỚC 0' + step;
      if (stepTitle) stepTitle.textContent = title;
      if (stepDesc) stepDesc.textContent = desc;
      if (stepLink) stepLink.href = '<?= BASE_URL ?>/index.php?r=tatca&q=' + encodeURIComponent(query);
    });
  });

  // 4. Flash Sale Countdown Timer
  var root = document.querySelector('[data-flash-sale-countdown]');
  if (root) {
    var dayEl = root.querySelector('[data-flash-days]');
    var hourEl = root.querySelector('[data-flash-hours]');
    var minuteEl = root.querySelector('[data-flash-minutes]');
    var secondEl = root.querySelector('[data-flash-seconds]');
    var target = new Date();
    target.setHours(23, 59, 59, 999);

    var pad = function (val) { return String(Math.max(0, val)).padStart(2, '0'); };

    var tick = function () {
      var diff = Math.max(0, target.getTime() - Date.now());
      var totalSeconds = Math.floor(diff / 1000);
      var days = Math.floor(totalSeconds / 86400);
      var hours = Math.floor((totalSeconds % 86400) / 3600);
      var minutes = Math.floor((totalSeconds % 3600) / 60);
      var seconds = totalSeconds % 60;

      if (dayEl) dayEl.textContent = pad(days);
      if (hourEl) hourEl.textContent = pad(hours);
      if (minuteEl) minuteEl.textContent = pad(minutes);
      if (secondEl) secondEl.textContent = pad(seconds);
    };

    tick();
    setInterval(tick, 1000);
  }
});
</script>
