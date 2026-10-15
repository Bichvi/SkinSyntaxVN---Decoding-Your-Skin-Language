<?php
// frontend/views/flash_sale.php - Dedicated All Deals Public Flash Sale Page (Phase 2C)
$campaign = $campaign ?? null;
$upcomingCampaign = $upcomingCampaign ?? null;
$deals = $deals ?? [];
$currentSort = $currentSort ?? 'featured';
$totalDeals = (int)($totalDeals ?? count($deals));
$hasActiveCampaign = !empty($campaign) && $totalDeals > 0;
?>

<div class="container my-4 flash-sale-page">
  <!-- Breadcrumbs -->
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small" style="background: transparent; padding: 0;">
      <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/index.php?r=home" class="text-decoration-none text-muted"><i class="fas fa-home me-1"></i>Trang chủ</a></li>
      <li class="breadcrumb-item active text-dark fw-semibold" aria-current="page">Flash Sale</li>
    </ol>
  </nav>

  <?php if ($hasActiveCampaign): ?>
    <!-- Campaign Header & Hero Banner -->
    <div class="p-4 p-md-5 mb-4 text-white position-relative overflow-hidden" style="background: linear-gradient(135deg, #11281D 0%, #183B2B 55%, #1B4332 100%); border-radius: 20px; border: 1px solid #2D6A4F; box-shadow: 0 12px 36px rgba(24, 59, 43, 0.18);">
      <div class="row align-items-center g-4">
        <div class="col-lg-7">
          <span class="badge mb-2 px-3 py-1.5 fw-bold text-uppercase" style="background: rgba(255, 255, 255, 0.18); border: 1px solid rgba(255, 255, 255, 0.3); font-size: 0.72rem; letter-spacing: 0.08em; border-radius: 999px;">
            <i class="fas fa-bolt text-warning me-1"></i> ƯU ĐÃI CHỚP NHOÁNG
          </span>
          <h1 class="fw-bold mb-2 text-white" style="font-size: clamp(1.6rem, 3vw, 2.3rem); line-height: 1.25;">
            <?= h($campaign['ten_campaign']) ?>
          </h1>
          <?php if (!empty($campaign['mo_ta'])): ?>
            <p class="text-white-80 mb-3" style="font-size: 0.95rem; line-height: 1.6; max-width: 580px; color: rgba(255, 255, 255, 0.88);">
              <?= h($campaign['mo_ta']) ?>
            </p>
          <?php endif; ?>
          <div class="d-inline-flex align-items-center gap-2 px-3 py-1.5 rounded-3" style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.18); font-size: 0.84rem;">
            <i class="fas fa-tags text-warning"></i>
            <span>Đang áp dụng cho <strong><?= $totalDeals ?></strong> sản phẩm chính hãng</span>
          </div>
        </div>

        <div class="col-lg-5 text-lg-end">
          <div class="d-inline-block text-start p-3.5 p-md-4 rounded-4" style="background: rgba(0, 0, 0, 0.28); border: 1px solid rgba(255, 255, 255, 0.2); backdrop-filter: blur(8px);" data-flash-sale-countdown data-end-timestamp="<?= (int)($campaign['end_at_ts'] ?? 0) ?>">
            <div class="text-white-50 text-uppercase fw-semibold small mb-2 text-center" style="letter-spacing: 0.06em; font-size: 0.74rem;">
              <i class="far fa-clock text-warning me-1"></i> KẾT THÚC SAU
            </div>
            <div class="d-flex align-items-center justify-content-center gap-2">
              <div class="text-center px-2.5 py-1.5" data-flash-day-box style="background: rgba(255,255,255,0.14); border-radius: 8px; min-width: 48px; display: none;">
                <strong class="d-block text-white fs-4 fw-bold tabular-nums" data-flash-days>00</strong>
                <small class="text-white-50 text-uppercase" style="font-size: 0.62rem;">Ngày</small>
              </div>
              <span class="text-white fw-bold fs-4" data-flash-day-separator style="display: none;">:</span>
              <div class="text-center px-2.5 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 8px; min-width: 48px;">
                <strong class="d-block text-white fs-4 fw-bold tabular-nums" data-flash-hours>00</strong>
                <small class="text-white-50 text-uppercase" style="font-size: 0.62rem;">Giờ</small>
              </div>
              <span class="text-white fw-bold fs-4">:</span>
              <div class="text-center px-2.5 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 8px; min-width: 48px;">
                <strong class="d-block text-white fs-4 fw-bold tabular-nums" data-flash-minutes>00</strong>
                <small class="text-white-50 text-uppercase" style="font-size: 0.62rem;">Phút</small>
              </div>
              <span class="text-white fw-bold fs-4">:</span>
              <div class="text-center px-2.5 py-1.5" style="background: rgba(255,255,255,0.14); border-radius: 8px; min-width: 48px;">
                <strong class="d-block text-white fs-4 fw-bold tabular-nums" data-flash-seconds>00</strong>
                <small class="text-white-50 text-uppercase" style="font-size: 0.62rem;">Giây</small>
              </div>
            </div>
            <div class="text-center mt-2">
              <span class="badge bg-danger py-1.5 px-3" data-flash-status style="display: none; font-size: 0.78rem;">Phiên ưu đãi đã kết thúc</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Filter & Sort Bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 p-3 mb-4 bg-white border" style="border-radius: 12px; border-color: #E2E8F0 !important;">
      <div class="d-flex align-items-center gap-2">
        <span class="fw-bold text-dark" style="font-size: 0.95rem;">Danh sách ưu đãi</span>
        <span class="badge bg-light text-muted border px-2.5 py-1"><?= $totalDeals ?> sản phẩm</span>
      </div>

      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="text-muted small fw-semibold me-1">Sắp xếp theo:</span>
        <div class="btn-group btn-group-sm" role="group" aria-label="Sắp xếp deal">
          <?php if (!empty($hasUsableContext)): ?>
            <a href="<?= BASE_URL ?>/index.php?r=flash_sale&sort=for_you" class="btn <?= $currentSort === 'for_you' ? 'btn-success fw-bold' : 'btn-outline-secondary' ?>" style="<?= $currentSort === 'for_you' ? 'background: #183B2B; border-color: #183B2B;' : '' ?>">
              <i class="fas fa-sparkles text-warning me-1"></i>Dành cho bạn
            </a>
          <?php endif; ?>
          <a href="<?= BASE_URL ?>/index.php?r=flash_sale&sort=featured" class="btn <?= $currentSort === 'featured' ? 'btn-success fw-bold' : 'btn-outline-secondary' ?>" style="<?= $currentSort === 'featured' ? 'background: #183B2B; border-color: #183B2B;' : '' ?>">
            Ưu đãi nổi bật
          </a>
          <a href="<?= BASE_URL ?>/index.php?r=flash_sale&sort=discount" class="btn <?= $currentSort === 'discount' ? 'btn-success fw-bold' : 'btn-outline-secondary' ?>" style="<?= $currentSort === 'discount' ? 'background: #183B2B; border-color: #183B2B;' : '' ?>">
            Giảm nhiều nhất
          </a>
          <a href="<?= BASE_URL ?>/index.php?r=flash_sale&sort=rating" class="btn <?= $currentSort === 'rating' ? 'btn-success fw-bold' : 'btn-outline-secondary' ?>" style="<?= $currentSort === 'rating' ? 'background: #183B2B; border-color: #183B2B;' : '' ?>">
            Đánh giá tốt
          </a>
          <a href="<?= BASE_URL ?>/index.php?r=flash_sale&sort=price_asc" class="btn <?= $currentSort === 'price_asc' ? 'btn-success fw-bold' : 'btn-outline-secondary' ?>" style="<?= $currentSort === 'price_asc' ? 'background: #183B2B; border-color: #183B2B;' : '' ?>">
            Giá thấp &rarr; cao
          </a>
          <a href="<?= BASE_URL ?>/index.php?r=flash_sale&sort=price_desc" class="btn <?= $currentSort === 'price_desc' ? 'btn-success fw-bold' : 'btn-outline-secondary' ?>" style="<?= $currentSort === 'price_desc' ? 'background: #183B2B; border-color: #183B2B;' : '' ?>">
            Giá cao &rarr; thấp
          </a>
        </div>
      </div>
    </div>

    <!-- Deals Grid -->
    <div class="row g-3 g-md-4 mb-5">
      <?php foreach ($deals as $deal): ?>
        <?php
          $productId = (string)($deal['id'] ?? $deal['ma_san_pham'] ?? '');
          $img = resolve_image_url((string)($deal['link_hinh_anh'] ?? $deal['hinh_anh'] ?? ''));
          $flashPrice = (int)($deal['flash_price'] ?? $deal['effective_price'] ?? 0);
          $regularPrice = (int)($deal['regular_price'] ?? $deal['gia_ban'] ?? 0);
          $discountPct = (int)($deal['discount_percent'] ?? $deal['phan_tram_giam'] ?? 0);
          $rawRating = (float)($deal['diem_danh_gia'] ?? 0.0);
          $rawReviewCount = (int)($deal['so_luong_danh_gia'] ?? 0);
          $hasGoodRating = ($rawRating >= 4.5 && $rawReviewCount >= 5);
          $isOutOfStock = function_exists('product_is_out_of_stock') ? product_is_out_of_stock($deal['raw_product'] ?? $deal) : false;
          $reasonTag = trim((string)($deal['flash_sale_reason_tag'] ?? ''));
          if ($reasonTag === '' && $hasGoodRating) {
              $reasonTag = 'Được đánh giá tốt';
          }
        ?>
        <div class="col-6 col-md-4 col-lg-3">
          <div class="product-card product-card--editorial h-100 d-flex flex-column" style="background: #FFFFFF; border-radius: 12px; border: 1px solid #E2E8F0; overflow: hidden; box-shadow: 0 4px 14px rgba(0,0,0,0.04); transition: transform 0.2s ease, box-shadow 0.2s ease;">
            <div class="product-thumb position-relative p-2" style="background: #F8FAF8; border-radius: 12px 12px 0 0;">
              <?php if ($discountPct > 0): ?>
                <span class="badge-sale position-absolute" style="top: 10px; left: 10px; background: #DC2626; color: #FFFFFF; font-weight: 700; font-size: 0.75rem; padding: 3px 8px; border-radius: 4px; z-index: 3;">
                  -<?= $discountPct ?>%
                </span>
              <?php endif; ?>

              <span class="position-absolute" style="top: 10px; right: 10px; background: #FEF3C7; color: #92400E; font-size: 0.68rem; font-weight: 700; padding: 2px 7px; border-radius: 4px; z-index: 3; border: 1px solid #FDE68A;">
                <i class="fas fa-bolt text-warning me-1"></i>Flash Deal
              </span>

              <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" class="d-block w-100 overflow-hidden" style="border-radius: 8px; aspect-ratio: 1/1;">
                <img class="product-card-img" src="<?= h($img ?: default_placeholder_image()) ?>" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';" alt="<?= h(!empty($deal['ten_san_pham']) ? $deal['ten_san_pham'] : 'Sản phẩm Flash Sale') ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
              </a>
            </div>

            <div class="product-meta p-3 d-flex flex-column flex-grow-1" style="background: #FFFFFF;">
              <div class="mb-1 d-flex align-items-center justify-content-between">
                <span class="brand text-uppercase" style="font-size: 0.68rem; color: #183B2B; background: #EBF2EE; padding: 2px 7px; border-radius: 4px; letter-spacing: 0.04em; font-weight: 700;">
                  <?= h($deal['thuong_hieu'] ?? 'SkinSyntax') ?>
                </span>

                <?php if ($reasonTag !== ''): ?>
                  <span class="badge" style="background: #ECFDF5; color: #047857; font-size: 0.68rem; font-weight: 600; border: 1px solid #A7F3D0;" title="Lý do gợi ý">
                    <i class="fas fa-sparkles me-1 text-warning"></i><?= h($reasonTag) ?>
                  </span>
                <?php endif; ?>
              </div>

              <a class="name fw-semibold mb-1 text-decoration-none" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($productId) ?>" style="color: #0F172A; font-size: 0.88rem; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.4em;">
                <?= h($deal['ten_san_pham'] ?? '') ?>
              </a>

              <?php if ($rawRating > 0 && $rawReviewCount > 0): ?>
                <div class="d-flex align-items-center gap-1 mb-2" style="font-size: 0.76rem;">
                  <span class="d-inline-flex align-items-center gap-1 text-warning font-weight-bold">
                    <i class="fas fa-star" style="color: #F59E0B; font-size: 0.72rem;"></i> <?= number_format($rawRating, 1) ?>
                  </span>
                  <span class="text-muted small ms-1">(<?= $rawReviewCount ?>)</span>
                </div>
              <?php else: ?>
                <div class="d-flex align-items-center gap-1 mb-2 text-muted" style="font-size: 0.76rem; color: #94A3B8;">
                  <i class="far fa-star text-muted" style="font-size: 0.72rem;"></i>
                  <span>Chưa có đánh giá</span>
                </div>
              <?php endif; ?>

              <div class="price-wrap mb-3 mt-auto d-flex align-items-baseline gap-2">
                <div class="price fw-bold" style="color: #DC2626; font-size: 1.15rem; font-variant-numeric: tabular-nums;"><?= vnd($flashPrice) ?></div>
                <?php if ($regularPrice > $flashPrice): ?>
                  <div class="price-market text-muted text-decoration-line-through" style="font-size: 0.82rem; font-variant-numeric: tabular-nums; color: #94A3B8 !important;"><?= vnd($regularPrice) ?></div>
                <?php endif; ?>
              </div>

              <div class="product-card-actions d-grid gap-2" style="grid-template-columns: 1fr 1fr;">
                <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="product_id" value="<?= h($productId) ?>">
                  <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
                  <input type="hidden" name="quantity" value="1">
                  <input type="hidden" name="qty" value="1">
                  <button class="btn btn-sm w-100 text-nowrap d-inline-flex align-items-center justify-content-center" type="submit" style="background: #F1F5F9; color: #183B2B; border: 1px solid #CBD5E1; border-radius: 8px; font-weight: 600; font-size: 0.78rem; height: 38px; padding: 0 4px; transition: all 0.2s ease;" <?= $isOutOfStock ? 'disabled' : '' ?> title="Thêm vào giỏ">
                    <?= $isOutOfStock ? 'Hết hàng' : '<i class="fa-solid fa-cart-plus me-1" style="font-size: 0.74rem;"></i> <span>Thêm vào giỏ</span>' ?>
                  </button>
                </form>
                <form method="post" action="<?= BASE_URL ?>/index.php?r=them_gio_hang_ajax" class="m-0 w-100">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="buy_now" value="1">
                  <input type="hidden" name="product_id" value="<?= h($productId) ?>">
                  <input type="hidden" name="ma_san_pham" value="<?= h($productId) ?>">
                  <input type="hidden" name="quantity" value="1">
                  <input type="hidden" name="qty" value="1">
                  <button class="btn btn-sm w-100 text-white text-nowrap d-inline-flex align-items-center justify-content-center" type="submit" style="background: #183B2B; border-radius: 8px; font-weight: 600; font-size: 0.78rem; height: 38px; padding: 0 4px; border: none; transition: background 0.2s ease;" <?= $isOutOfStock ? 'disabled' : '' ?> title="Mua ngay">
                    <?= $isOutOfStock ? 'Hết hàng' : '<span>Mua ngay</span>' ?>
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php else: ?>
    <!-- Honest Empty State -->
    <div class="text-center py-5 px-3 my-5 bg-white border rounded-4 shadow-sm" style="border-color: #E2E8F0 !important; max-width: 640px; margin-left: auto; margin-right: auto;">
      <div class="mb-3 d-inline-flex p-3.5 rounded-circle" style="background: #FEF3C7; color: #D97706;">
        <i class="fas fa-bolt fs-2"></i>
      </div>
      <h2 class="fw-bold mb-2 text-dark" style="font-size: 1.45rem;">
        Hiện chưa có chương trình Flash Sale đang diễn ra
      </h2>
      <p class="text-muted mb-4" style="font-size: 0.92rem; line-height: 1.6;">
        Các ưu đãi Flash Sale được mở theo khung giờ vàng giới hạn. Quý khách vui lòng quay lại sau hoặc khám phá toàn bộ bộ sưu tập mỹ phẩm chính hãng tại SkinSyntax.
      </p>

      <?php if (!empty($upcomingCampaign)): ?>
        <div class="p-3 mb-4 rounded-3 text-start mx-auto" style="background: #F0FDF4; border: 1px solid #BBF7D0; max-width: 480px;">
          <div class="d-flex align-items-center gap-2 text-success fw-bold small mb-1">
            <i class="fas fa-calendar-check"></i>
            <span>CHƯƠNG TRÌNH TIẾP THEO SẮP DIỄN RA</span>
          </div>
          <div class="fw-semibold text-dark mb-1" style="font-size: 0.92rem;">
            <?= h($upcomingCampaign['ten_campaign']) ?>
          </div>
          <div class="text-muted small" style="font-size: 0.82rem;">
            Dự kiến bắt đầu: <strong><?= h($upcomingCampaign['start_at']) ?></strong> (Giờ Việt Nam)
          </div>
        </div>
      <?php endif; ?>

      <div class="d-flex flex-wrap justify-content-center gap-2.5">
        <a href="<?= BASE_URL ?>/index.php?r=home" class="btn btn-outline-secondary px-4 py-2 fw-semibold" style="border-radius: 8px; font-size: 0.88rem;">
          <i class="fas fa-home me-1.5"></i> Về trang chủ
        </a>
        <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn text-white px-4 py-2 fw-semibold" style="background: #183B2B; border-radius: 8px; font-size: 0.88rem;">
          <i class="fas fa-shopping-bag me-1.5"></i> Khám phá sản phẩm
        </a>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
// Server-Driven Countdown Timer Script for All Deals Page
document.addEventListener('DOMContentLoaded', function () {
  var root = document.querySelector('[data-flash-sale-countdown]');
  if (!root) return;

  var endTs = parseInt(root.getAttribute('data-end-timestamp') || '0', 10);
  if (!endTs) return;

  var dayEl = root.querySelector('[data-flash-days]');
  var dayBox = root.querySelector('[data-flash-day-box]');
  var daySep = root.querySelector('[data-flash-day-separator]');
  var hourEl = root.querySelector('[data-flash-hours]');
  var minuteEl = root.querySelector('[data-flash-minutes]');
  var secondEl = root.querySelector('[data-flash-seconds]');
  var statusEl = root.querySelector('[data-flash-status]');

  var pad = function (val) { return String(Math.max(0, val)).padStart(2, '0'); };
  var hasReloaded = false;

  var tick = function () {
    var nowSec = Math.floor(Date.now() / 1000);
    var remaining = Math.max(0, endTs - nowSec);

    if (remaining <= 0) {
      if (dayEl) dayEl.textContent = '00';
      if (hourEl) hourEl.textContent = '00';
      if (minuteEl) minuteEl.textContent = '00';
      if (secondEl) secondEl.textContent = '00';
      if (statusEl) statusEl.style.display = 'inline-block';

      if (!hasReloaded) {
        hasReloaded = true;
        setTimeout(function () {
          var reloadKey = 'ss_all_deals_ended_' + endTs;
          if (!sessionStorage.getItem(reloadKey)) {
            sessionStorage.setItem(reloadKey, '1');
            window.location.reload();
          }
        }, 2000);
      }
      return;
    }

    var days = Math.floor(remaining / 86400);
    var hours = Math.floor((remaining % 86400) / 3600);
    var minutes = Math.floor((remaining % 3600) / 60);
    var seconds = remaining % 60;

    if (dayEl) dayEl.textContent = pad(days);
    if (dayBox && daySep) {
      if (days > 0) {
        dayBox.style.display = 'inline-block';
        daySep.style.display = 'inline-block';
      } else {
        dayBox.style.display = 'none';
        daySep.style.display = 'none';
      }
    }
    if (hourEl) hourEl.textContent = pad(hours);
    if (minuteEl) minuteEl.textContent = pad(minutes);
    if (secondEl) secondEl.textContent = pad(seconds);
  };

  tick();
  var intervalId = setInterval(function () {
    var nowSec = Math.floor(Date.now() / 1000);
    if (endTs - nowSec <= 0) {
      clearInterval(intervalId);
      tick();
    } else {
      tick();
    }
  }, 1000);
});
</script>
