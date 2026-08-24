<?php
$pageTitle = '🔴 SkinSyntax Live - Sàn Thương Mại Điện Tử Livestream Tích Hợp AI Agent';
require_once __DIR__ . '/layouts/header.php';
?>

<div class="live-center-hero py-4" style="background: linear-gradient(135deg, #162F18 0%, #215427 100%); color: #FFF; border-bottom: 1px solid #C5DAC8;">
  <div class="container">
    <div class="row align-items-center g-3">
      <div class="col-lg-8">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge bg-danger rounded-pill px-3 py-1.5 fw-bold" style="font-size: 0.8rem; animation: pulseGlowBtn 2s infinite;"><i class="fa-solid fa-circle me-1"></i>LIVE STREAM</span>
          <span class="badge bg-white text-success rounded-pill px-3 py-1.5 fw-bold" style="font-size: 0.8rem;"><i class="fa-solid fa-brain me-1"></i>TRỢ LÝ TƯ VẤN AI 24/7</span>
          <span class="badge bg-dark text-light rounded-pill px-3 py-1.5 fw-bold" style="font-size: 0.8rem;"><i class="fa-solid fa-shield-heart me-1"></i>HÀNG CHÍNH HÃNG 100%</span>
        </div>
        <h1 class="fw-extrabold display-6 mb-2" style="font-weight: 800; letter-spacing: -0.02em;">SkinSyntax Live Commerce & Tư Vấn AI</h1>
        <p class="mb-0 text-light opacity-90" style="font-size: 1rem; line-height: 1.5; color: #EAF0EB !important;">
          Nền tảng Livestream Thương Mại Điện Tử trực tiếp kết hợp <strong>Trợ lý AI thông minh</strong> tự động tư vấn chăm sóc da và hỗ trợ chốt đơn ưu đãi 24/7.
        </p>
      </div>
      <?php if (in_array(current_role(), ['admin', 'nhanvien'], true)): ?>
        <div class="col-lg-4 text-lg-end">
          <a href="<?= BASE_URL ?>/index.php?r=admin_lives" class="btn btn-light rounded-pill px-4 py-2.5 fw-bold shadow-sm" style="color: #215427;">
            <i class="fa-solid fa-gear me-2 text-success"></i>Quản lý Phiên Live (Admin)
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
$pinnedP = $currentLive['pinned_product'] ?? null;
$pinnedId = (string)($pinnedP['ma_san_pham'] ?? $pinnedP['id'] ?? '5876');
$pinnedName = (string)($pinnedP['ten_san_pham'] ?? 'Sản phẩm ghim ưu đãi trong Live');
$pinnedBrand = (string)($pinnedP['thuong_hieu'] ?? 'SkinSyntax');
$pinnedPrice = (float)($currentLive['gia_uu_dai_live'] ?? $pinnedP['gia_ban'] ?? 78000);
$pinnedMarketPrice = (float)($pinnedP['gia_thi_truong'] ?? 0);
$pinnedImg = resolve_image_url((string)($pinnedP['link_hinh_anh'] ?? $pinnedP['image_url'] ?? ''));

$liveStatus = $currentLive['status'] ?? 'ended';
$isLiveActive = ($liveStatus === 'live');
$isUpcoming = ($liveStatus === 'upcoming');
?>
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2.1.2/dist/livekit-client.umd.min.js" crossorigin="anonymous"></script>

<div class="container py-4">
  <!-- MAIN INTERACTIVE LIVESTREAM STUDIO -->

  <!-- MAIN INTERACTIVE LIVESTREAM STUDIO -->
  <div class="row g-4 mb-5">
    <!-- LEFT: WEBRTC LIVE VIDEO PLAYER CONTAINER -->
    <div class="col-lg-8">
      <div class="live-player-card card border-0 rounded-4 overflow-hidden shadow-lg bg-dark position-relative" style="aspect-ratio: 16/9; border: 1.5px solid #215427 !important;">
        <!-- LiveKit WebRTC Real Video Elements -->
        <video id="livekitRemoteVideo" autoplay playsinline class="w-100 h-100 position-absolute top-0 start-0" style="object-fit: cover; display: none; z-index: 2;"></video>
        <video id="livekitLocalVideo" autoplay playsinline muted class="w-100 h-100 position-absolute top-0 start-0" style="object-fit: cover; display: none; z-index: 3;"></video>
        <audio id="livekitRemoteAudio" autoplay></audio>

        <!-- LiveKit WebRTC Video Stream Screen OR Video Replay Player -->
        <?php if (!$isLiveActive && !empty($currentLive['url_ban_ghi'])): ?>
          <video id="replayVideoPlayer" controls autoplay playsinline class="w-100 h-100 position-absolute top-0 start-0" style="object-fit: cover; z-index: 25;" src="<?= h($currentLive['url_ban_ghi']) ?>" poster="<?= h($currentLive['thumbnail']) ?>"></video>
        <?php endif; ?>

        <!-- UNIFIED TOP VIDEO OVERLAY BAR & STREAMER CONTROLS -->
        <div class="position-absolute top-0 start-0 w-100 p-3 d-flex align-items-center justify-content-between text-white flex-wrap gap-2" style="background: linear-gradient(180deg, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0) 100%); z-index: 40; pointer-events: none;">
          <div class="d-flex align-items-center gap-2 flex-wrap" style="pointer-events: auto;">

            <?php if ($isLiveActive): ?>
              <span class="badge bg-danger rounded-pill px-2.5 py-1 fw-bold" style="font-size: 0.75rem;"><i class="fa-solid fa-circle text-white me-1"></i>TRỰC TIẾP</span>
              <span class="badge bg-dark bg-opacity-75 rounded-pill px-2.5 py-1" style="font-size: 0.75rem;"><i class="bi bi-eye-fill text-warning me-1"></i><span id="liveViewerCount">0</span> người xem</span>
              <span id="livekitStatusBadge" class="badge bg-warning text-dark rounded-pill px-2.5 py-1" style="font-size: 0.75rem;"><i class="fa-solid fa-spinner fa-spin me-1"></i>Đang kết nối...</span>
            <?php elseif ($isUpcoming): ?>
              <span class="badge bg-warning text-dark rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;"><i class="fa-solid fa-clock me-1"></i> SẮP DIỄN RA (LỊCH PHÁT SÓNG)</span>
            <?php elseif (!empty($currentLive['url_ban_ghi'])): ?>
              <span class="badge bg-success rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-play me-1"></i>🎬 PHÁT BẢN GHI XEM LẠI</span>
            <?php else: ?>
              <span class="badge bg-secondary rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;"><i class="fa-solid fa-flag-checkered me-1"></i>⏹️ PHIÊN LIVE ĐÃ KẾT THÚC</span>
            <?php endif; ?>
          </div>

          <div class="d-flex align-items-center gap-2 flex-wrap ms-auto" style="pointer-events: auto;">

            <span class="badge bg-success bg-opacity-90 rounded-pill px-2.5 py-1" style="font-size: 0.75rem;" title="Kênh phát sóng HD trực tiếp"><i class="fa-solid fa-signal text-light me-1"></i>Phát Sóng</span>

            <?php if (in_array(current_role(), ['admin', 'nhanvien'], true)): ?>
              <?php if ($currentLive['status'] === 'live'): ?>
                <a id="btnEndLiveSession" href="<?= BASE_URL ?>/index.php?r=admin_live_status&id=<?= urlencode($currentLive['id']) ?>&status=ketthuc" class="btn btn-danger btn-sm rounded-pill fw-bold shadow px-2.5 py-1" style="font-size: 0.78rem;">
                  <i class="fa-solid fa-square me-1"></i>Kết Thúc
                </a>
              <?php elseif ($currentLive['status'] === 'upcoming'): ?>
                <a href="<?= BASE_URL ?>/index.php?r=admin_live_status&id=<?= urlencode($currentLive['id']) ?>&status=danglive" class="btn btn-success btn-sm rounded-pill fw-bold shadow px-2.5 py-1" style="font-size: 0.78rem;">
                  <i class="fa-solid fa-play me-1"></i>Bắt Đầu
                </a>
              <?php elseif ($currentLive['status'] === 'ended'): ?>
                <span class="badge bg-secondary rounded-pill px-2.5 py-1 fw-bold shadow" style="font-size: 0.78rem;">
                  <i class="fa-solid fa-lock me-1"></i>Phiên Đã Kết Thúc
                </span>
              <?php endif; ?>

              <!-- NÚT CAMERA VÀ MICRO RIÊNG DÀNH CHO STREAMER / HOST -->
              <button id="btnToggleCam" type="button" class="btn btn-outline-light btn-sm rounded-pill fw-bold shadow px-2.5 py-1" style="font-size: 0.78rem;" title="Bật/Tắt Camera phát sóng">
                <i class="fa-solid fa-video me-1"></i>Bật Cam
              </button>
              <button id="btnToggleMic" type="button" class="btn btn-outline-light btn-sm rounded-pill fw-bold shadow px-2.5 py-1" style="font-size: 0.78rem;" title="Bật/Tắt Micro thu âm">
                <i class="fa-solid fa-microphone me-1"></i>Bật Mic
              </button>
            <?php endif; ?>
          </div>
        </div>

        <div id="liveWaitingOverlay" class="live-video-stream w-100 h-100 position-relative d-flex flex-column align-items-center justify-content-center text-center p-4" style="background: radial-gradient(circle at center, #1E3A21 0%, #0B190D 100%); z-index: 1; <?= (!$isLiveActive && !empty($currentLive['url_ban_ghi'])) ? 'display: none !important;' : '' ?>">
          <div class="rounded-circle bg-success bg-opacity-20 p-4 mb-3 d-inline-flex align-items-center justify-content-center text-success" style="width: 80px; height: 80px;">
            <i class="fa-solid <?= !empty($currentLive['url_ban_ghi']) ? 'fa-play' : 'fa-tower-broadcast' ?> fs-1"></i>
          </div>
          <h5 class="fw-bold text-white mb-1"><?= h($currentLive['title'] ?? 'Phiên LiveStream AI') ?></h5>
          <p class="text-light opacity-75 small mb-0" id="liveWaitingMessage">
            <?php if ($isLiveActive): ?>
              <i class="fa-solid fa-spinner fa-spin me-1 text-warning"></i>Đang chờ Streamer bật Camera phát sóng...
            <?php elseif ($isUpcoming): ?>
               Phiên phát sóng chưa bắt đầu. Vui lòng quay lại vào lúc <?= h($currentLive['khung_gio_bat_dau'] ?? '') ?>
            <?php elseif (!empty($currentLive['url_ban_ghi'])): ?>
              🎬 Đang phát Video Xem Lại của phiên LiveStream này.
            <?php else: ?>
              ⏹️ Phiên LiveStream này đã kết thúc.
            <?php endif; ?>
          </p>

          <!-- STREAMER & AI CO-HOST BADGE -->
          <div class="position-absolute bottom-0 start-0 p-3 text-white text-start" style="background: linear-gradient(0deg, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0) 100%); width: 100%; z-index: 10;">
            <div class="d-flex align-items-center gap-2 mb-1">
              <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center fw-bold" style="width: 36px; height: 36px; border: 2px solid #FFF;">DS</div>
              <div>
                <strong class="d-block text-white" style="font-size: 0.95rem;"><?= h($currentLive['streamer'] ?? 'DS. Minh Trang & AI Skin Co-Host') ?></strong>
                <small class="text-light opacity-75" style="font-size: 0.75rem;"><?= h($currentLive['title'] ?? 'Routine Phục Hồi Da Dầu Mụn') ?></small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- PINNED LIVE SALE PRODUCT BAR -->
      <div class="card border-0 rounded-4 shadow-sm p-3 mt-3" style="background: #F4F8F4; border: 1.5px solid #C5DAC8 !important;">
        <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-danger rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;"><i class="fa-solid fa-thumbtack me-1"></i>SẢN PHẨM GHIM TRONG LIVE</span>
            <span class="badge bg-warning text-dark rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;" id="flashDealCountdownBadge">
              <i class="fa-solid fa-stopwatch me-1 text-danger"></i>Hết Deal sau: <span id="flashDealTimerText">14:59</span>
            </span>
          </div>
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
          <img id="livePinnedImg" src="<?= h($pinnedImg !== '' ? $pinnedImg : BASE_URL . '/assets/images/hero_campaign_ai_skin.png') ?>" alt="Pinned Product" class="rounded-3" style="width: 70px; height: 70px; object-fit: cover; border: 1px solid #C5DAC8;">
          <div class="flex-grow-1" style="min-width: 200px;">
            <span id="livePinnedBrand" class="badge bg-success text-white rounded-pill extra-small mb-1" style="font-size: 0.68rem;"><?= h($pinnedBrand) ?></span>
            <h6 id="livePinnedName" class="fw-bold text-dark mb-1 text-truncate" style="max-width: 380px; font-size: 0.95rem;"><?= h($pinnedName) ?></h6>
            <div class="d-flex align-items-baseline gap-2">
              <span id="livePinnedPrice" class="fw-extrabold text-success fs-5" style="color: #215427 !important;"><?= vnd($pinnedPrice) ?></span>
              <?php if ($pinnedMarketPrice > $pinnedPrice): ?>
                <span id="livePinnedMarketPrice" class="text-muted text-decoration-line-through small"><?= vnd($pinnedMarketPrice) ?></span>
                <span id="livePinnedDiscountBadge" class="badge bg-danger rounded-pill" style="font-size: 0.7rem;">-<?= (int)round((($pinnedMarketPrice - $pinnedPrice)/$pinnedMarketPrice)*100) ?>% OFF</span>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($isLiveActive): ?>
            <form id="liveBuyNowForm" method="post" action="<?= BASE_URL ?>/index.php?r=api_live_add_to_cart" class="m-0 ms-auto">
              <input type="hidden" name="live_id" value="<?= h($currentLive['id']) ?>">
              <input type="hidden" name="product_id" id="livePinnedProductId" value="<?= h($pinnedId) ?>">
              <input type="hidden" name="quantity" value="1">
              <button type="submit" id="btnLiveBuyNow" class="btn text-white fw-bold px-4 py-2.5 btn-buy-now-pulse rounded-pill" style="background: linear-gradient(135deg, #215427 0%, #162F18 100%); border: none; font-size: 0.9rem;">
                 MUA NGAY TRONG LIVE
              </button>
            </form>
          <?php elseif ($isUpcoming): ?>
            <div class="m-0 ms-auto text-end">
              <button type="button" class="btn btn-secondary disabled rounded-pill px-4 py-2 fw-bold" style="font-size: 0.85rem;" disabled>
                 Phiên Live Sắp Diễn Ra
              </button>
              <div class="extra-small text-muted mt-1"><i class="fa-solid fa-lock me-1"></i>Chưa đến giờ mở bán Live</div>
            </div>
          <?php else: ?>
            <div class="m-0 ms-auto text-end">
              <button type="button" class="btn btn-secondary disabled rounded-pill px-4 py-2 fw-bold" style="font-size: 0.85rem;" disabled>
                ⏹️ Phiên Live Đã Kết Thúc
              </button>
              <div class="extra-small text-muted mt-1"><i class="fa-solid fa-lock me-1"></i>Khung giờ ưu đãi đã đóng</div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- ALL FEATURED LIVE PRODUCTS SHOWCASE (VERTICAL SCROLL) -->
      <?php if (!empty($allProducts)): ?>
        <style>
        @keyframes cartShakePop {
          0% { transform: scale(1); }
          25% { transform: scale(1.35) rotate(-12deg); }
          50% { transform: scale(1.2) rotate(12deg); }
          75% { transform: scale(1.1) rotate(-5deg); }
          100% { transform: scale(1) rotate(0deg); }
        }
        .cart-shake-pop {
          animation: cartShakePop 0.55s ease-in-out !important;
        }
        .live-products-vscroll::-webkit-scrollbar {
          width: 6px;
        }
        .live-products-vscroll::-webkit-scrollbar-track {
          background: #F4F8F4;
          border-radius: 10px;
        }
        .live-products-vscroll::-webkit-scrollbar-thumb {
          background: #C5DAC8;
          border-radius: 10px;
        }
        .live-products-vscroll::-webkit-scrollbar-thumb:hover {
          background: #215427;
        }
        </style>
        <?php
        $roomDeals = $currentLive['danh_sach_deal'] ?? [];
        $displayProducts = [];

        foreach ($roomDeals as $dItem) {
            $pId = (string)($dItem['ma_san_pham'] ?? '');
            $found = null;
            foreach ($allProducts as $ap) {
                if ((string)($ap['ma_san_pham'] ?? $ap['id'] ?? '') === $pId) {
                    $found = $ap;
                    break;
                }
            }
            if (!$found) {
                $found = [
                    'ma_san_pham' => $pId,
                    'ten_san_pham' => 'Sản phẩm deal #' . $pId,
                    'thuong_hieu' => 'SkinSyntax',
                    'gia_ban' => (float)($dItem['gia_kho'] ?? 250000),
                    'so_luong_kho' => (int)($dItem['so_luong_kho_deal'] ?? 20)
                ];
            }
            $found['gia_uu_dai_live'] = (float)($dItem['gia_uu_dai_live'] ?? $found['gia_ban']);
            $found['so_luong_kho_deal'] = (int)($dItem['so_luong_kho_deal'] ?? 20);
            $displayProducts[] = $found;
        }

        if (empty($displayProducts) && !empty($currentLive['ma_san_pham_ghim'])) {
            $pId = (string)$currentLive['ma_san_pham_ghim'];
            foreach ($allProducts as $ap) {
                if ((string)($ap['ma_san_pham'] ?? $ap['id'] ?? '') === $pId) {
                    $ap['gia_uu_dai_live'] = (float)($currentLive['gia_uu_dai_live'] ?? $ap['gia_ban']);
                    $ap['so_luong_kho_deal'] = (int)($currentLive['so_luong_kho_deal'] ?? 20);
                    $displayProducts[] = $ap;
                    break;
                }
            }
        }
        ?>
        <div class="card border-0 rounded-4 shadow-sm p-3 mt-3" style="background: #FFF; border: 1.5px solid #E2EADF !important;">
          <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
            <div>
              <strong class="text-dark small"><i class="fa-solid fa-fire text-danger me-1"></i>SẢN PHẨM GIẢM GIÁ SÂU TRONG LIVESTREAM (#<?= h($currentLive['id']) ?>)</strong>
              <?php if (!empty($displayProducts)): ?>
                <span class="badge bg-danger text-white rounded-pill ms-1 extra-small"><?= count($displayProducts) ?> SP Ưu Đãi</span>
              <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2.5 py-1 extra-small fw-bold"> Khuyến mãi!</span>
            </div>
          </div>

          <?php if (in_array(current_role(), ['admin', 'nhanvien'], true)): ?>
            <!-- STREAMER SEARCH & PIN PRODUCTS INTO LIVESTREAM DEALS -->
            <div class="mb-3 position-relative p-2.5 rounded-3 bg-light border border-success border-opacity-25">
              <label class="form-label fw-bold small text-success m-0 mb-1">
                <i class="fa-solid fa-magnifying-glass me-1"></i>Tìm &amp; Bật Deal
              </label>
              <div class="input-group">
                <span class="input-group-text bg-white border-end-0" style="border-color: #C5DAC8;"><i class="fa-solid fa-search text-success"></i></span>
                <input type="text" id="liveCatalogSearchInput" class="form-control border-start-0 ps-0 rounded-end-3" placeholder="Gõ tên hoặc mã SP kho (VD: serum, lancome, b5, 5876...) để ghim &amp; bật deal ngay..." style="border-color: #C5DAC8; background: #FFFFFF;" autocomplete="off">
              </div>

              <!-- FLOATING SMART DROPDOWN SEARCH RESULTS -->
              <div id="liveCatalogSearchResults" class="dropdown-menu shadow-lg border rounded-3 p-2 w-100 mt-1" style="display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 1050; max-height: 380px; overflow-y: auto; background: #ffffff; border-color: #C5DAC8 !important;">
              </div>
            </div>
          <?php endif; ?>

          <!-- SEARCH RESULTS & LIVE DEALS LIST -->
          <div class="live-products-vscroll pe-1" id="liveProductListContainer" style="max-height: 420px; overflow-y: auto; scroll-behavior: smooth;">
            <div class="d-flex flex-column gap-2" id="liveProductListInner">
              <?php if (empty($displayProducts)): ?>
                <div class="text-center py-4 text-muted bg-light rounded-3 border" style="border-color: #E2EADF !important;">
                  <i class="fa-solid fa-tags fa-2x mb-2 text-success"></i>
                  <p class="small fw-bold text-dark mb-1">Chưa có sản phẩm giảm giá sâu nào được thêm vào phiên Live này.</p>
                  </div>
              <?php else: ?>
                <?php foreach ($displayProducts as $pItem): ?>
                  <?php
                    $pId = (string)($pItem['ma_san_pham'] ?? $pItem['id'] ?? '');
                    $pName = (string)($pItem['ten_san_pham'] ?? '');
                    $pBrand = (string)($pItem['thuong_hieu'] ?? 'SkinSyntax');
                    $pPrice = (float)($pItem['gia_ban'] ?? 0);
                    $pLivePrice = (float)($pItem['gia_uu_dai_live'] ?? $pPrice);
                    $pDealStock = (int)($pItem['so_luong_kho_deal'] ?? 20);
                    $pImg = resolve_image_url((string)($pItem['link_hinh_anh'] ?? $pItem['hinh_anh'] ?? ''));
                    $searchKey = mb_strtolower($pId . ' ' . $pName . ' ' . $pBrand, 'UTF-8');
                  ?>
                  <div class="live-product-item-row d-flex align-items-center gap-3 p-2.5 rounded-3 border bg-success bg-opacity-10 border-success position-relative" data-search="<?= h($searchKey) ?>" style="transition: all 0.2s ease;">
                    <img src="<?= h($pImg) ?>" class="rounded-3 flex-shrink-0" style="width: 58px; height: 58px; object-fit: cover; border: 1px solid #C5DAC8;">
                    <div class="flex-grow-1 overflow-hidden" style="min-width: 0;">
                      <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <span class="badge bg-success text-white rounded-pill extra-small" style="font-size: 0.65rem;"><?= h($pBrand) ?></span>
                        <span class="badge bg-secondary text-white rounded-pill extra-small" style="font-size: 0.65rem;">Mã: #<?= h($pId) ?></span>
                        <span class="badge bg-danger text-white rounded-pill extra-small fw-bold" style="font-size: 0.65rem;">🔥 GIẢM SÂU</span>
                        <span class="badge bg-info text-dark rounded-pill extra-small" style="font-size: 0.65rem;">
                          <i class="fa-solid fa-box me-1"></i>Kho Deal: <?= number_format($pDealStock) ?> SP
                        </span>
                      </div>
                      <h6 class="fw-bold text-dark text-truncate mb-1" style="font-size: 0.88rem;" title="<?= h($pName) ?>"><?= h($pName) ?></h6>
                      <div class="d-flex align-items-baseline gap-2">
                        <span class="text-danger extra-small fw-bold">Giá Live:</span>
                        <span class="fw-bold text-danger small"><?= vnd($pLivePrice) ?></span>
                        <?php if ($pPrice > $pLivePrice): ?>
                          <span class="text-muted text-decoration-line-through extra-small" style="font-size: 0.72rem;"><?= vnd($pPrice) ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="flex-shrink-0 ms-2 d-flex align-items-center gap-1.5">
                      <?php if (in_array(current_role(), ['admin', 'nhanvien'], true)): ?>
                        <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_pin_product" class="m-0">
                          <input type="hidden" name="live_id" value="<?= h($currentLive['id']) ?>">
                          <input type="hidden" name="product_id" value="<?= h($pId) ?>">
                          <input type="hidden" name="gia_uu_dai_live" value="<?= (float)$pLivePrice ?>">
                          <input type="hidden" name="redirect" value="1">
                          <button type="submit" class="btn btn-success btn-sm rounded-pill px-2.5 py-1 fw-bold text-nowrap" style="font-size: 0.75rem;" title="Ghim sản phẩm này lên video live ngay">
                            📌 Ghim SP
                          </button>
                        </form>
                      <?php endif; ?>

                      <?php if ($isLiveActive): ?>
                        <form method="post" action="<?= BASE_URL ?>/index.php?r=api_live_add_to_cart" class="m-0 live-add-to-cart-form">
                          <input type="hidden" name="live_id" value="<?= h($currentLive['id']) ?>">
                          <input type="hidden" name="product_id" value="<?= h($pId) ?>">
                          <input type="hidden" name="quantity" value="1">
                          <button type="submit" class="btn btn-danger btn-sm rounded-pill px-3 py-1 fw-bold text-nowrap">+ Mua Ngay</button>
                        </form>
                      <?php else: ?>
                        <span class="badge bg-secondary text-white rounded-pill px-2.5 py-1 extra-small opacity-75">Hết giờ</span>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!empty($currentLive['tom_tat_phien_live'])): ?>
        <!-- AI TRANSCRIPT & HIGHLIGHTS RECAP CARD -->
        <div class="card border-0 rounded-4 shadow-sm p-3 mt-3" style="background: #EBF3EC; border: 1.5px solid #A8C8AB !important;">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge bg-success rounded-pill px-3 py-1.5 fw-bold" style="font-size: 0.78rem;"><i class="fa-solid fa-file-contract me-1"></i>MÔ TẢ</span>
          </div>
          <div class="bg-white rounded-3 p-3 text-dark fs-6" style="line-height: 1.6; border: 1px solid #C5DAC8;">
            <?= nl2br(h($currentLive['tom_tat_phien_live'])) ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- ADMIN STUDIO QUICK PIN MODAL -->


      <?php if (in_array(current_role(), ['admin', 'nhanvien'], true)): ?>
        <div class="modal fade" id="studioPinProductModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow-lg">
              <div class="modal-header border-bottom p-3">
                <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-thumbtack text-danger me-2"></i>Đổi Sản Phẩm Ghim Trực Tiếp Khi Live</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_pin_product">
                <input type="hidden" name="live_id" value="<?= h($currentLive['id']) ?>">
                <input type="hidden" name="redirect" value="1">
                <div class="modal-body p-4">
                  <div class="mb-3">
                    <label class="form-label fw-bold small">Chọn Sản Phẩm Ghim Mới <span class="text-danger">*</span></label>
                    <select name="product_id" id="studioPinProductSelect" class="form-select" required>
                      <?php foreach ($allProducts as $p): ?>
                        <?php
                          $pIdOpt = (string)($p['ma_san_pham'] ?? $p['id'] ?? '');
                          $pPriceOpt = (float)($p['gia_ban'] ?? 0);
                          $pStockOpt = (int)($p['so_luong_kho'] ?? $p['so_luong_ton_kho'] ?? $p['so_luong'] ?? 20);
                        ?>
                        <option value="<?= h($pIdOpt) ?>" data-price="<?= $pPriceOpt ?>" data-stock="<?= $pStockOpt ?>" <?= ($pIdOpt == $pinnedId) ? 'selected' : '' ?>>
                          [<?= h($pIdOpt) ?>] <?= h($p['ten_san_pham'] ?? '') ?> - Giá kho: <?= vnd($pPriceOpt) ?> (Tồn: <?= $pStockOpt ?> SP)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <!-- PRODUCT STOCK & ORIGINAL PRICE INFO BADGE -->
                  <div class="row g-2 mb-3">
                    <div class="col-6">
                      <div class="p-2.5 rounded-3 bg-light border" style="border-color: #E2EADF !important;">
                        <small class="text-muted d-block extra-small"><i class="fa-solid fa-tag me-1 text-success"></i>Giá Gốc Niêm Yết Kho</small>
                        <strong class="text-dark fw-bold" id="studioPinOriginalPriceText" style="font-size: 0.95rem;"><?= vnd($pinnedMarketPrice > 0 ? $pinnedMarketPrice : $pinnedPrice) ?></strong>
                      </div>
                    </div>
                    <div class="col-6">
                      <div class="p-2.5 rounded-3 bg-light border" style="border-color: #E2EADF !important;">
                        <small class="text-muted d-block extra-small"><i class="fa-solid fa-boxes-stacked me-1 text-primary"></i>Tồn Kho Thực Tế</small>
                        <strong class="text-success fw-bold" id="studioPinStockText" style="font-size: 0.95rem;">20 SP</strong>
                      </div>
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-bold small">Giá Ưu Đãi Trực Tiếp Trong Live (VNĐ) <span class="text-danger">*</span></label>
                    <input type="number" name="gia_uu_dai_live" id="studioPinProductPriceInput" class="form-control" value="<?= (float)$pinnedPrice ?>" min="1000" required>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-bold small">Giới Hạn Lượt Bán Deal Trong Live <span class="text-danger">*</span></label>
                    <input type="number" name="so_luong_kho_deal" id="studioPinDealStockInput" class="form-control" value="20" min="1" max="500" required>
                    <small class="form-text text-muted extra-small">
                      <i class="fa-solid fa-shield-halved me-1 text-success"></i>Số lượng suất deal mở bán tối đa trong phiên live này.
                    </small>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-bold small">Thời Gian Giảm Giá Flash Deal Đếm Ngược <span class="text-danger">*</span></label>
                    <select name="duration_minutes" class="form-select">
                      <option value="5"> 5 Phút (Flash Sale Ngắn)</option>
                      <option value="10"> 10 Phút (Flash Sale Siêu Tốc)</option>
                      <option value="15" selected> 15 Phút (Khung Deal Tiêu Chuẩn)</option>
                      <option value="30"> 30 Phút (Flash Sale Khung Giờ Standard)</option>
                      <option value="60"> 60 Phút (Ưu Đãi Tuyệt Đối)</option>
                    </select>
                  </div>
                </div>
                <div class="modal-footer border-top p-3">
                  <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Hủy</button>
                  <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">
                    Ghim Sản Phẩm Này Ngay
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: LIVE CHAT SIDEBAR WITH AI AGENT INTEGRATION -->
    <div class="col-lg-4">
      <div class="card border-0 rounded-4 shadow-sm position-sticky" style="top: 90px; border: 1.5px solid #C5DAC8 !important; background: #FFF;">
        <div class="card-header bg-white p-3 border-bottom d-flex align-items-center justify-content-between" style="border-color: #E2EADF !important;">
          <div class="d-flex align-items-center gap-2">
            <i class="fa-solid fa-comments text-success fs-5"></i>
            <strong class="text-dark" style="font-size: 0.95rem;">Hỏi Đáp & Tư Vấn Trực Tiếp</strong>
          </div>
          <?php if ($isLiveActive): ?>
            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2.5 py-1 extra-small fw-bold"><i class="fa-solid fa-robot me-1"></i>Tư Vấn Viên AI Sẵn Sàng</span>
          <?php else: ?>
            <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2.5 py-1 extra-small fw-bold"><i class="fa-solid fa-lock me-1"></i>Chat Tạm Khóa</span>
          <?php endif; ?>
        </div>

        <!-- LIVE CHAT MESSAGES BODY -->
        <div class="card-body p-3 overflow-auto" id="liveChatBox" style="height: 440px; max-height: 480px; font-size: 0.85rem; background: #F8FAF8;">
          <div class="chat-msg mb-2 p-2 rounded-3 bg-white border" style="border-color: #E2EADF !important;">
            <strong class="text-success" style="color: #215427;"><i class="fa-solid fa-shield-halved me-1"></i>Hệ thống SkinSyntax:</strong> Chào mừng bạn đến với phiên Livestream! Tư vấn viên AI luôn sẵn sàng giải đáp thắc mắc và hỗ trợ chốt đơn ưu đãi cho sản phẩm <strong><?= h($pinnedName) ?></strong>.
          </div>
          <?php if (!$isLiveActive): ?>
            <div class="alert alert-warning text-center rounded-3 my-3 p-3 small" style="border: 1px dashed #F59E0B;">
              <i class="fa-solid fa-lock fs-5 d-block mb-1 text-warning"></i>
              <strong>Khung Chat Tạm Khóa</strong><br>
              <?= $isUpcoming ? 'Phiên LiveStream sắp diễn ra theo lịch. Vui lòng quay lại vào khung giờ phát sóng!' : 'Phiên LiveStream đã kết thúc. Cảm ơn bạn đã tham gia!' ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- LIVE CHAT INPUT FORM -->
        <div class="card-footer p-3 bg-white border-top" style="border-color: #E2EADF !important;">
          <form id="liveChatForm" class="d-flex gap-2">
            <?php if ($isLiveActive): ?>
              <input type="text" id="liveChatInput" class="form-control form-control-sm rounded-pill px-3" placeholder="Nhập tin nhắn hoặc 'chốt đơn'..." required style="border-color: #C5DAC8;">
              <button type="submit" class="btn text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; background: #215427; border: none;">
                <i class="fa-solid fa-paper-plane" style="font-size: 0.85rem;"></i>
              </button>
            <?php else: ?>
              <input type="text" class="form-control form-control-sm rounded-pill px-3" placeholder="🔒 Phiên Live chưa diễn ra / đã kết thúc. Khung chat bị khóa." disabled style="background: #F1F5F1; cursor: not-allowed;">
              <button type="button" class="btn btn-secondary rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" disabled style="width: 36px; height: 36px;">
                <i class="fa-solid fa-lock" style="font-size: 0.85rem;"></i>
              </button>
            <?php endif; ?>
          </form>
          <div class="extra-small text-muted mt-2" style="font-size: 0.72rem;">
            💡 <strong>Mẹo:</strong> Gõ <code>"chốt đơn"</code> hoặc hỏi về hoạt chất để thử phản hồi AI Agent!
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- LIVESTREAM ROOM LIST & ARCHIVES -->
  <div class="mb-5">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <div>
        <h3 class="fw-bold text-dark mb-1" style="color: #1A2F1A;">Các Phiên Live Stream Khác</h3>
        <p class="text-muted small mb-0">Khám phá các phòng LiveStream đang phát sóng, lịch hẹn sắp diễn ra và video bản ghi xem lại.</p>
      </div>
      <div class="d-flex align-items-center gap-2">
        <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-bold active" onclick="filterLiveSessions('all', this)">Tất Cả (<?= count($liveSessions) ?>)</button>
        <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-bold" onclick="filterLiveSessions('live', this)">Đang Live</button>
        <button type="button" class="btn btn-sm btn-outline-warning text-dark rounded-pill px-3 fw-bold" onclick="filterLiveSessions('upcoming', this)"> Sắp Diễn Ra</button>
        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-bold" onclick="filterLiveSessions('ended', this)">⏹ Đã Kết Thúc</button>
      </div>
    </div>

    <div class="row g-4" id="liveSessionGrid">
      <?php foreach ($liveSessions as $session): ?>
        <?php
          $st = $session['status'];
          $stBadgeClass = 'bg-secondary';
          $stBadgeText = '⏹️ ĐÃ KẾT THÚC';
          $stBtnClass = 'btn-outline-secondary';
          $stBtnText = '🎬 Đã kết thúc - Bấm để xem chi tiết';
          $stBtnStyle = 'background: #F8FAF8; color: #215427; border: 1px solid #C5DAC8;';

          if ($st === 'live') {
              $stBadgeClass = 'bg-danger';
              $stBadgeText = 'ĐANG LIVE';
              $stBtnText = 'Đang live - Bấm xem ngay';
              $stBtnStyle = 'background: linear-gradient(135deg, #215427 0%, #162F18 100%); color: #FFF; border: none;';
          } else if ($st === 'upcoming') {
              $stBadgeClass = 'bg-warning text-dark';
              $stBadgeText = 'SẮP DIỄN RA';
              $stBtnText = 'Sắp diễn ra - Bấm xem lịch & chi tiết';
              $stBtnStyle = 'background: #FFF9E6; color: #856404; border: 1px solid #FFEBAA;';
          } else {
              if (!empty($session['url_ban_ghi'])) {
                  $stBadgeText = '⏹ KẾT THÚC (REPLAY)';
                  $stBtnText = 'Đã kết thúc - Bấm để xem chi tiết';
              }
          }
        ?>
        <div class="col-md-6 col-lg-4 live-session-card-item" data-status="<?= h($st) ?>" data-room-id="<?= h($session['id']) ?>">
          <div class="card h-100 border-0 rounded-4 shadow-sm overflow-hidden card-elevated" style="border: 1px solid #E2EADF !important; background: #FFF;">
            <div class="position-relative" style="aspect-ratio: 16/9; overflow: hidden; background: #000;">
              <img src="<?= h($session['thumbnail']) ?>" alt="<?= h($session['title']) ?>" style="width: 100%; height: 100%; object-fit: cover; opacity: 0.9;">
              <span class="badge <?= $stBadgeClass ?> position-absolute top-0 start-0 m-3 px-3 py-1.5 rounded-pill fw-bold" style="font-size: 0.75rem;"><?= $stBadgeText ?></span>
              <?php if ($st === 'live'): ?>
                <span class="badge bg-dark bg-opacity-85 text-white position-absolute top-0 end-0 m-3 px-3 py-1.5 rounded-pill shadow-sm card-viewer-badge" style="font-size: 0.75rem; backdrop-filter: blur(4px);"><i class="bi bi-eye-fill text-warning me-1.5"></i><span class="card-viewer-count"><?= number_format($session['viewers']) ?></span> người xem</span>
              <?php elseif (!empty($session['url_ban_ghi'])): ?>
                <span class="badge bg-success bg-opacity-90 position-absolute top-0 end-0 m-3 px-2.5 py-1.5 rounded-pill" style="font-size: 0.72rem;"><i class="bi bi-film me-1"></i>Xem Replay</span>
              <?php endif; ?>
            </div>
            <div class="card-body p-3.5 p-3 d-flex flex-column">
              <div class="small fw-bold text-success mb-1" style="color: #215427 !important;"><i class="fa-solid fa-headset me-1"></i><?= h($session['streamer']) ?></div>
              <h5 class="card-title fw-bold text-dark mb-2" style="font-size: 0.95rem; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.7em;">
                <?= h($session['title']) ?>
              </h5>
              <p class="text-muted small mb-3 flex-grow-1" style="font-size: 0.8rem; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                <?= h($session['description']) ?>
              </p>
              <a href="<?= BASE_URL ?>/index.php?r=live&id=<?= urlencode((string)$session['id']) ?>" class="btn btn-sm w-100 rounded-pill fw-bold mt-auto" style="<?= $stBtnStyle ?>">
                <?= $stBtnText ?>
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
function filterLiveSessions(status, btn) {
  const buttons = btn.parentElement.querySelectorAll('button');
  buttons.forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  const cards = document.querySelectorAll('.live-session-card-item');
  cards.forEach(card => {
    if (status === 'all' || card.getAttribute('data-status') === status) {
      card.style.display = 'block';
    } else {
      card.style.display = 'none';
    }
  });
}
</script>

<!-- MODAL TẠO PHIÊN LIVE MỚI (DÀNH CHO ADMIN / STREAMER) -->
<div class="modal fade" id="createLiveModal" tabindex="-1" aria-labelledby="createLiveModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4 border-0 shadow-lg">
      <div class="modal-header border-bottom p-3" style="border-color: #E2EADF !important;">
        <h5 class="modal-header-title fw-bold text-dark m-0" id="createLiveModalLabel"><i class="fa-solid fa-video text-danger me-2"></i>Tạo Phiên Livestream AI Mới</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <form id="createLiveForm" method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_create">
          <div class="mb-3">
            <label class="form-label fw-bold small text-success"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Chọn Ý Tưởng / Chủ Đề Tự Động (Hoặc Tự Nhập)</label>
            <select id="livePresetSelect" class="form-select rounded-3" style="border-color: #C5DAC8;">
              <option value="">-- Chọn ý tưởng chủ đề có sẵn (Tự động điền) --</option>
              <option value="lancome">🏆 Thương Hiệu Lancôme (Nước Thần Clarifique & Kem Nền)</option>
              <option value="serum">💧 Chuyên Đề Serum Phục Hồi (HA, B5 & Peptide)</option>
              <option value="paulas_choice"> Thương Hiệu Paula's Choice (BHA 2% & Niacinamide)</option>
              <option value="la_roche_posay">🔴 Thương Hiệu La Roche-Posay (Workshop Phục Hồi B5)</option>
              <option value="sunscreen">☀️ Chuyên Đề Kem Chống Nắng (Chống UV & Ánh Sáng Xanh)</option>
              <option value="cleanser">🌿 Chuyên Đề Làm Sạch Sâu (Nước Tẩy Trang & Gel Rửa Mặt)</option>
              <option value="whitening">✨ Chuyên Đề Dưỡng Trắng & Trẻ Hóa (Retinol & Vitamin C)</option>
              <option value="acne_care">🔴 Routine Chăm Sóc Da Dầu Mụn (AI Co-Host & Dược Sĩ)</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold small">Tiêu Đề Phiên LiveStream <span class="text-danger">*</span></label>
            <input type="text" name="tieu_de" id="liveTitleInput" class="form-control rounded-3" placeholder="VD: Săn Sale Khung Giờ Vàng 19h - Niacinamide & B5..." required>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-bold small">Tên Streamer &amp; Bác Sĩ Đảm Nhận <span class="text-danger">*</span></label>
              <input type="text" name="streamer" id="liveStreamerInput" class="form-control rounded-3" placeholder="Nhập tên Streamer hoặc Bác sĩ..." required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Máy Chủ LiveStream</label>
              <input type="text" name="server_livekit_url" class="form-control rounded-3" value="wss://skinsyntax-live.livekit.cloud" readonly style="background: #F8FAF8;">
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-bold small">Thời Gian Bắt Đầu Khung Giờ</label>
              <input type="datetime-local" name="khung_gio_bat_dau" class="form-control rounded-3" value="<?= date('Y-m-d\TH:i') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Thời Gian Kết Thúc Khung Giờ</label>
              <input type="datetime-local" name="khung_gio_ket_thuc" class="form-control rounded-3" value="<?= date('Y-m-d\TH:i', strtotime('+2 hours')) ?>">
            </div>
          </div>

          <!-- SẢN PHẨM GHIM NỔI BẬT BAN ĐẦU KÈM TỒN KHO & GIÁ GỐC TỰ ĐỘNG -->
          <div class="mb-3">
            <label class="form-label fw-bold small">Sản Phẩm Ghim Nổi Bật Ban Đầu <span class="text-danger">*</span></label>
            <div class="input-group mb-1">
              <span class="input-group-text bg-light border-end-0" style="border-color: #C5DAC8;"><i class="fa-solid fa-search text-muted"></i></span>
              <input type="text" id="modalProductSearchInput" class="form-control border-start-0 ps-0 rounded-end-3" placeholder="Gõ tên hoặc mã SP để lọc nhanh kho hàng..." style="border-color: #C5DAC8;">
            </div>
            <select name="ma_san_pham_ghim" id="liveProductSelect" class="form-select rounded-3" style="border-color: #C5DAC8;" required size="4" style="max-height: 150px; overflow-y: auto;">
              <option value="" disabled selected>-- Bấm chọn sản phẩm trong danh sách --</option>
              <?php foreach ($allProducts as $p): ?>
                <?php
                  $pId = (string)($p['ma_san_pham'] ?? $p['id'] ?? '');
                  $pName = (string)($p['ten_san_pham'] ?? '');
                  $pPrice = (float)($p['gia_ban'] ?? 0);
                  $pStock = (int)($p['so_luong_kho'] ?? $p['so_luong'] ?? $p['ton_kho'] ?? 20);
                  $searchKey = mb_strtolower($pId . ' ' . $pName, 'UTF-8');
                ?>
                <option value="<?= h($pId) ?>" data-search="<?= h($searchKey) ?>" data-price="<?= $pPrice ?>" data-stock="<?= $pStock ?>" data-name="<?= h($pName) ?>">
                  [#<?= h($pId) ?>] <?= h($pName) ?> (Gốc: <?= vnd($pPrice) ?> | Tồn kho: <?= number_format($pStock) ?> SP)
                </option>
              <?php endforeach; ?>
            </select>
          </div>


          <!-- KHUNG HIỂN THỊ CHI TIẾT TỒN KHO VÀ GIÁ GỐC -->
          <div id="selectedProductInfoBox" class="p-3 rounded-3 mb-3 border bg-light" style="display: none; border-color: #C5DAC8 !important;">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
              <div>
                <span class="extra-small text-muted d-block">Sản phẩm được chọn:</span>
                <strong id="selectedProductNameText" class="text-dark small d-block">--</strong>
              </div>
              <div class="d-flex align-items-center gap-2">
                <span class="badge bg-secondary text-white rounded-pill px-2.5 py-1 extra-small">
                  Giá gốc: <strong id="selectedProductPriceText">0 đ</strong>
                </span>
                <span class="badge bg-success text-white rounded-pill px-2.5 py-1 extra-small" id="selectedProductStockBadge">
                  📦 Tồn kho: 0 SP
                </span>
              </div>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label fw-bold small">Giá Ưu Đãi Trong Live (VNĐ) <span class="text-danger">*</span></label>
              <input type="number" name="gia_uu_dai_live" id="livePriceInput" class="form-control rounded-3" placeholder="VD: 78000" min="1000" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small">Số Lượng SP Bán (Kho Deal) <span class="text-danger">*</span></label>
              <input type="number" name="so_luong_kho_deal" class="form-control rounded-3" placeholder="VD: 20" value="20" min="1" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small">Thời Gian Flash Deal (Phút)</label>
              <select name="duration_minutes" class="form-select rounded-3">
                <option value="15" selected>15 Phút (Khung Deal TikTok)</option>
                <option value="30">30 Phút (Khung Standard)</option>
                <option value="60">60 Phút (Suốt Live)</option>
              </select>
            </div>
          </div>


          <div class="mb-3">
            <label class="form-label fw-bold small">Link Video Bản Ghi Xem Lại (Tùy chọn cho phiên xem lại)</label>
            <input type="url" name="url_ban_ghi" class="form-control rounded-3" placeholder="https://domain.com/recording-video.mp4">
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold small">Kịch Bản &amp; Tóm Tắt Tư Vấn AI (Transcript Summary)</label>
            <textarea name="tom_tat_phien_live" class="form-control rounded-3" rows="2" placeholder="Tóm tắt lời khuyên skincare, giải đáp câu hỏi và ưu đãi trong phiên..."></textarea>
          </div>

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="bat_ai_cohost" id="aiCoHostSwitch" value="1" checked>
            <label class="form-check-label fw-bold small" for="aiCoHostSwitch">Bật AI Agent Co-Host (Tự động tư vấn RAG &amp; Chốt đơn khi khán giả gõ 'chốt đơn')</label>
          </div>

          <div class="d-flex align-items-center justify-content-end gap-2 mt-4">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn text-white rounded-pill px-4 fw-bold" style="background: linear-gradient(135deg, #215427 0%, #162F18 100%); border: none;">
            Lưu &amp; Khởi Tạo Phiên Live
            </button>
          </div>
        </form>
      </div>
    </div>
</div>

<!-- MODAL THÊM SẢN PHẨM DEAL VÀO PHIÊN LIVE DÀNH CHO ADMIN / STAFF -->
<div class="modal fade" id="addDealToLiveModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content rounded-4 border-0 shadow-lg">
      <div class="modal-header border-bottom p-3" style="border-color: #E2EADF !important;">
        <h5 class="modal-title fw-bold text-dark m-0"><i class="fa-solid fa-plus-circle text-success me-2"></i>Thêm Sản Phẩm Ưu Đãi Vào Phiên Live #<?= h($currentLive['id']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_add_deal">
        <input type="hidden" name="live_id" value="<?= h($currentLive['id']) ?>">
        <input type="hidden" name="redirect_live" value="1">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label fw-bold small">Chọn Sản Phẩm Từ Kho <span class="text-danger">*</span></label>
            <div class="input-group mb-1">
              <span class="input-group-text bg-light border-end-0" style="border-color: #C5DAC8;"><i class="fa-solid fa-search text-muted"></i></span>
              <input type="text" id="addDealSearchInput" class="form-control border-start-0 ps-0 rounded-end-3" placeholder="Gõ tên hoặc mã SP để lọc nhanh kho..." style="border-color: #C5DAC8;">
            </div>
            <select name="product_id" id="addDealProductSelect" class="form-select rounded-3" style="border-color: #C5DAC8;" required size="4" style="max-height: 150px; overflow-y: auto;">
              <option value="" disabled selected>-- Bấm chọn sản phẩm --</option>
              <?php foreach ($allProducts as $p): ?>
                <?php
                  $pId = (string)($p['ma_san_pham'] ?? $p['id'] ?? '');
                  $pName = (string)($p['ten_san_pham'] ?? '');
                  $pPrice = (float)($p['gia_ban'] ?? 0);
                  $pStock = (int)($p['so_luong_kho'] ?? $p['so_luong'] ?? $p['ton_kho'] ?? 20);
                  $searchKey = mb_strtolower($pId . ' ' . $pName, 'UTF-8');
                ?>
                <option value="<?= h($pId) ?>" data-search="<?= h($searchKey) ?>" data-price="<?= $pPrice ?>" data-stock="<?= $pStock ?>" data-name="<?= h($pName) ?>">
                  [#<?= h($pId) ?>] <?= h($pName) ?> (Gốc: <?= vnd($pPrice) ?> | Tồn kho: <?= number_format($pStock) ?> SP)
                </option>
              <?php endforeach; ?>
            </select>

          </div>

          <!-- DYNAMIC PRODUCT INFO BADGE IN ADD DEAL MODAL -->
          <div id="addDealProductInfoBox" class="p-3 rounded-3 mb-3 border bg-light" style="display: none; border-color: #C5DAC8 !important;">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
              <div>
                <span class="extra-small text-muted d-block">Sản phẩm được chọn:</span>
                <strong id="addDealProductNameText" class="text-dark small">--</strong>
              </div>
              <div class="d-flex align-items-center gap-2">
                <span class="badge bg-secondary text-white rounded-pill px-2.5 py-1 extra-small">Giá gốc: <strong id="addDealProductPriceText">0 đ</strong></span>
                <span class="badge bg-success text-white rounded-pill px-2.5 py-1 extra-small" id="addDealProductStockBadge">📦 Tồn kho cửa hàng: 0 SP</span>
              </div>
            </div>
          </div>


          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label fw-bold small">Giá Ưu Đãi Trong Live (VNĐ) <span class="text-danger">*</span></label>
              <input type="number" name="gia_uu_dai_live" id="addDealPriceInput" class="form-control rounded-3" placeholder="VD: 89000" min="1000" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small">Số Lượng Bán Ưu Đãi (Kho Deal) <span class="text-danger">*</span></label>
              <input type="number" name="so_luong_kho_deal" class="form-control rounded-3" value="20" min="1" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small">Khung Giờ Deal</label>
              <input type="text" name="khung_gio_bat_dau" class="form-control rounded-3" value="<?= date('H:i') ?>">
            </div>
          </div>
        </div>
        <div class="modal-footer border-top p-3">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Hủy</button>
          <button type="submit" class="btn text-white rounded-pill px-4 fw-bold" style="background: linear-gradient(135deg, #215427 0%, #162F18 100%); border: none;">
            ➕ Thêm Vào Phiên Live
          </button>
        </div>
      </form>
    </div>
  </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function() {
  // --- Live Product Search Filter ---
  const liveProductSearchInput = document.getElementById('liveProductSearchInput');

  if (liveProductSearchInput) {
    liveProductSearchInput.addEventListener('input', function() {
      const q = this.value.toLowerCase().trim();
      const rows = document.querySelectorAll('.live-product-item-row');
      rows.forEach(row => {
        const searchData = row.getAttribute('data-search') || '';
        if (!q || searchData.includes(q)) {
          row.style.display = 'flex';
        } else {
          row.style.display = 'none';
        }
      });
    });
  }

  // --- Live filter search in Add Deal Modal ---
  const addDealSearchInput = document.getElementById('addDealSearchInput');
  const addDealProductSelect = document.getElementById('addDealProductSelect');
  const addDealPriceInput = document.getElementById('addDealPriceInput');

  if (addDealSearchInput && addDealProductSelect) {
    addDealSearchInput.addEventListener('input', function() {
      const q = this.value.toLowerCase().trim();
      const options = addDealProductSelect.options;
      for (let i = 0; i < options.length; i++) {
        const opt = options[i];
        if (!opt.value) continue;
        const searchData = opt.getAttribute('data-search') || '';
        if (!q || searchData.includes(q)) {
          opt.style.display = '';
        } else {
          opt.style.display = 'none';
        }
      }
    });
  }

  const addDealInfoBox = document.getElementById('addDealProductInfoBox');
  const addDealNameText = document.getElementById('addDealProductNameText');
  const addDealPriceText = document.getElementById('addDealProductPriceText');
  const addDealStockBadge = document.getElementById('addDealProductStockBadge');

  if (addDealProductSelect) {
    addDealProductSelect.addEventListener('change', function() {
      const opt = this.options[this.selectedIndex];
      if (opt && opt.value) {
        const pName = opt.getAttribute('data-name') || opt.text;
        const price = parseFloat(opt.getAttribute('data-price') || '0');
        const stock = parseInt(opt.getAttribute('data-stock') || '0', 10);

        if (addDealInfoBox) addDealInfoBox.style.display = 'block';
        if (addDealNameText) addDealNameText.textContent = pName;
        if (addDealPriceText) addDealPriceText.textContent = new Intl.NumberFormat('vi-VN').format(price) + ' đ';
        if (addDealStockBadge) {
          addDealStockBadge.textContent = '📦 Tồn kho cửa hàng: ' + new Intl.NumberFormat('vi-VN').format(stock) + ' SP';
          addDealStockBadge.className = 'badge ' + (stock > 0 ? 'bg-success' : 'bg-danger') + ' text-white rounded-pill px-2.5 py-1 extra-small';
        }

        if (addDealPriceInput && (!addDealPriceInput.value || addDealPriceInput.value === '0')) {
          addDealPriceInput.value = Math.round(price * 0.85);
        }
      } else {
        if (addDealInfoBox) addDealInfoBox.style.display = 'none';
      }
    });
  }

  // --- IN-MEMORY SMART SEARCH DROPDOWN FOR STREAMER TO PICK SP & OPEN DEAL FORM POPUP ---
  const liveCatalogProducts = <?= json_encode(array_map(function($p) {
      return [
          'id' => (string)($p['ma_san_pham'] ?? $p['id'] ?? ''),
          'name' => (string)($p['ten_san_pham'] ?? ''),
          'brand' => (string)($p['thuong_hieu'] ?? 'SkinSyntax'),
          'price' => (float)($p['gia_ban'] ?? 0),
          'stock' => (int)($p['so_luong_kho'] ?? $p['so_luong'] ?? $p['ton_kho'] ?? 20),
          'img' => resolve_image_url((string)($p['link_hinh_anh'] ?? $p['hinh_anh'] ?? ''))
      ];
  }, is_array($allProducts) ? $allProducts : []), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

  function removeVietnameseTones(str) {
    if (!str) return '';
    str = str.replace(/à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ/g, "a");
    str = str.replace(/è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ/g, "e");
    str = str.replace(/ì|í|ị|ỉ|ĩ/g, "i");
    str = str.replace(/ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ/g, "o");
    str = str.replace(/ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ/g, "u");
    str = str.replace(/ỳ|ý|ỵ|ỷ|ỹ/g, "y");
    str = str.replace(/đ/g, "d");
    return str.toLowerCase();
  }

  function liveEscapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  const liveCatalogSearchInput = document.getElementById('liveCatalogSearchInput');
  const liveCatalogSearchResults = document.getElementById('liveCatalogSearchResults');
  const addDealToLiveModalEl = document.getElementById('addDealToLiveModal');


  if (liveCatalogSearchInput && liveCatalogSearchResults) {
    liveCatalogSearchInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
      }
    });

    let searchTimer = null;
    liveCatalogSearchInput.addEventListener('input', function() {
      clearTimeout(searchTimer);
      const rawQ = this.value.trim();
      const q = removeVietnameseTones(rawQ);

      if (!q) {
        liveCatalogSearchResults.style.display = 'none';
        liveCatalogSearchResults.innerHTML = '';
        return;
      }

      // 1. Fast Local Match
      const localMatches = liveCatalogProducts.filter(p => {
        const searchKey = removeVietnameseTones(p.id + ' ' + p.name + ' ' + p.brand);
        return searchKey.includes(q) || (p.id + ' ' + p.name).toLowerCase().includes(rawQ.toLowerCase());
      }).slice(0, 25);

      function renderSearchResults(items) {
        if (!items || items.length === 0) {
          liveCatalogSearchResults.innerHTML = `
            <div class="p-3 text-center text-muted small">
              <i class="fa-solid fa-circle-exclamation me-1 text-warning"></i>Không tìm thấy sản phẩm nào khớp với từ khóa "<strong>${liveEscapeHtml(rawQ)}</strong>"
            </div>
          `;
          liveCatalogSearchResults.style.display = 'block';
          return;
        }

        let html = `
          <div class="px-2 py-1 mb-2 text-muted fw-bold extra-small border-bottom d-flex align-items-center justify-content-between" style="letter-spacing: 0.5px;">
            <span>KẾT QUẢ TÌM KIẾM (${items.length} SP)</span>
            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2 py-0.5">Kho Sản Phẩm Store</span>
          </div>
        `;

        items.forEach(p => {
          const pId = p.id || p.ma_san_pham;
          const pName = p.name || p.ten_san_pham;
          const pBrand = p.brand || p.thuong_hieu || 'SkinSyntax';
          const pPrice = p.price !== undefined ? p.price : (p.gia_ban || 0);
          const pStock = p.stock !== undefined ? p.stock : (p.so_luong_kho || 20);
          const pImg = p.img || p.hinh_anh;

          const formattedPrice = new Intl.NumberFormat('vi-VN').format(pPrice) + ' đ';

          html += `
            <div class="catalog-search-row d-flex align-items-center justify-content-between p-2 rounded-3 mb-1.5" style="background: #F8FAF8; transition: background 0.15s ease;" onmouseover="this.style.background='#EBF3EC'" onmouseout="this.style.background='#F8FAF8'">
              <div class="d-flex align-items-center gap-2.5 overflow-hidden" style="min-width: 0;">
                <img src="${pImg}" class="rounded-2 flex-shrink-0" style="width: 44px; height: 44px; object-fit: cover; border: 1px solid #C5DAC8;">
                <div class="overflow-hidden" style="min-width: 0;">
                  <strong class="d-block text-dark extra-small text-truncate" style="font-size: 0.85rem;">[#${pId}] ${liveEscapeHtml(pName)}</strong>
                  <div class="d-flex align-items-center gap-2 extra-small text-muted mt-0.5" style="font-size: 0.75rem;">
                    <span class="text-success fw-bold">${formattedPrice}</span>
                    <span class="badge bg-secondary rounded-pill" style="font-size: 0.68rem;">Kho: ${pStock} SP</span>
                  </div>
                </div>
              </div>
              <button type="button" class="btn btn-success btn-sm rounded-pill px-3 py-1 fw-bold extra-small flex-shrink-0 ms-2 btn-trigger-pin-modal" data-id="${pId}" data-price="${pPrice}">
                📌 Chọn SP &amp; Bật Deal
              </button>
            </div>
          `;
        });

        liveCatalogSearchResults.innerHTML = html;
        liveCatalogSearchResults.style.display = 'block';
      }

      renderSearchResults(localMatches);

      // 2. Fetch server API if local matches are small
      if (localMatches.length < 5) {
        searchTimer = setTimeout(() => {
          fetch('<?= BASE_URL ?>/index.php?r=api_search_catalog_products&q=' + encodeURIComponent(rawQ))
            .then(res => res.json())
            .then(data => {
              if (data && data.ok && data.products && data.products.length > 0) {
                renderSearchResults(data.products);
              }
            })
            .catch(() => {});
        }, 300);
      }
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
      if (!liveCatalogSearchInput.contains(e.target) && !liveCatalogSearchResults.contains(e.target)) {
        liveCatalogSearchResults.style.display = 'none';
      }
    });
  }

  // Handle click on "📌 Chọn SP & Bật Deal" buttons in search dropdown
  document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-trigger-pin-modal') || e.target.closest('.catalog-search-row');
    if (btn) {
      const pId = btn.getAttribute('data-id') || (btn.querySelector('.btn-trigger-pin-modal') ? btn.querySelector('.btn-trigger-pin-modal').getAttribute('data-id') : null);
      const pPrice = btn.getAttribute('data-price') || (btn.querySelector('.btn-trigger-pin-modal') ? btn.querySelector('.btn-trigger-pin-modal').getAttribute('data-price') : null);

      if (!pId) return;

      if (liveCatalogSearchResults) liveCatalogSearchResults.style.display = 'none';

      const selectEl = document.getElementById('studioPinProductSelect');
      const priceEl = document.getElementById('studioPinProductPriceInput');
      const modalEl = document.getElementById('studioPinProductModal');

      if (selectEl) {
        selectEl.value = pId;
        selectEl.dispatchEvent(new Event('change'));
      }
      if (priceEl && pPrice) {
        priceEl.value = pPrice;
      }

      if (typeof bootstrap !== 'undefined' && modalEl) {
        const bsModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        bsModal.show();
      }
    }
  });

  // Dynamic Stock & Original Price Info Update on Product Select
  const studioPinProductSelectEl = document.getElementById('studioPinProductSelect');
  if (studioPinProductSelectEl) {
    function updateStudioPinProductMeta() {
      const selectedOpt = studioPinProductSelectEl.options[studioPinProductSelectEl.selectedIndex];
      if (!selectedOpt) return;

      const rawPrice = parseFloat(selectedOpt.getAttribute('data-price') || '0');
      const rawStock = parseInt(selectedOpt.getAttribute('data-stock') || '20', 10);

      const origPriceEl = document.getElementById('studioPinOriginalPriceText');
      const stockTextEl = document.getElementById('studioPinStockText');
      const dealStockInputEl = document.getElementById('studioPinDealStockInput');
      const priceInputEl = document.getElementById('studioPinProductPriceInput');

      if (origPriceEl) {
        origPriceEl.textContent = new Intl.NumberFormat('vi-VN').format(rawPrice) + ' đ';
      }
      if (stockTextEl) {
        stockTextEl.textContent = rawStock + ' SP';
      }
      if (dealStockInputEl) {
        dealStockInputEl.setAttribute('max', rawStock);
        if (parseInt(dealStockInputEl.value, 10) > rawStock) {
          dealStockInputEl.value = rawStock;
        }
      }
    }

    studioPinProductSelectEl.addEventListener('change', updateStudioPinProductMeta);
    updateStudioPinProductMeta();
  }









  // --- Theme Preset Quick Fill for Admin Create Live Modal ---


  const presetSelect = document.getElementById('livePresetSelect');
  const titleInput = document.getElementById('liveTitleInput');
  const streamerInput = document.getElementById('liveStreamerInput');
  const productSelect = document.getElementById('liveProductSelect');

  const livePresets = {
    lancome: { title: '🔴 LANCÔME BRAND DAY: Săn Nước Thần Clarifique & Kem Nền Che Phủ Giảm 84%', streamer: 'SkinSyntax Official & Lancôme Expert', productId: '5689' },
    serum: { title: '💧 ĐẠI CHIẾN SERUM PHỤC HỒI: Top 5 Hyaluronic Acid & Peptide Đáng Mua Nhất', streamer: 'Chuyên Gia Da Liễu Khánh Linh', productId: '5933' },
    paulas_choice: { title: ' PAULA\'S CHOICE SPECIAL: BHA 2% & Niacinamide Thu Nhỏ Lỗ Chân Lông', streamer: 'Beauty Editor Thu Thảo', productId: '5876' },
    la_roche_posay: { title: '🔴 LA ROCHE-POSAY WORKSHOP: Phục Hồi Màng Lipid B5 Chuẩn Y Khoa Cho Da Nhạy Cảm', streamer: 'Bác Sĩ Hoàng Nam (SkinLab)', productId: '5689' },
    sunscreen: { title: '☀️ SĂN DEAL KEM CHỐNG NẮNG: Bảo Vệ Da Toàn Diện Khỏi UV & Ánh Sáng Xanh', streamer: 'KOL Thanh Hà & AI Assistant', productId: '5933' },
    cleanser: { title: '🌿 LÀM SẠCH SÂU CHUẨN Y KHOA: Nước Tẩy Trang Micellar & Gel Rửa Mặt Cho Da Dầu Mụn', streamer: 'Dược Sĩ Phương Anh', productId: '5876' },
    whitening: { title: '✨ RETINOL & VITAMIN C: Bí Quyết Dưỡng Trắng, Mờ Thâm & Trẻ Hóa Đón Tết', streamer: 'Skincare Host Quỳnh Chi', productId: '5689' },
    acne_care: { title: '🔴 GỠ RỐI ROUTINE DA DẦU MỤN: Tự Động Tư Vấn 24/7 Với AI Co-Host & Dược Sĩ', streamer: 'DS. Minh Trang & AI Co-Host', productId: '5876' }
  };

  if (presetSelect) {
    presetSelect.addEventListener('change', function() {
      const val = presetSelect.value;
      if (livePresets[val]) {
        if (titleInput) titleInput.value = livePresets[val].title;
        if (streamerInput) streamerInput.value = livePresets[val].streamer;
        if (productSelect) {
          productSelect.value = livePresets[val].productId;
          productSelect.dispatchEvent(new Event('change'));
        }
      }
    });
  }

  // --- Dynamic Selected Product Stock & Price Info Banner ---
  const selInfoBox = document.getElementById('selectedProductInfoBox');
  const selNameText = document.getElementById('selectedProductNameText');
  const selPriceText = document.getElementById('selectedProductPriceText');
  const selStockBadge = document.getElementById('selectedProductStockBadge');
  const livePriceInput = document.getElementById('livePriceInput');

  if (productSelect) {
    productSelect.addEventListener('change', function() {
      const opt = this.options[this.selectedIndex];
      if (opt && opt.value) {
        const pName = opt.getAttribute('data-name') || opt.text;
        const price = parseFloat(opt.getAttribute('data-price') || '0');
        const stock = parseInt(opt.getAttribute('data-stock') || '0', 10);

        if (selInfoBox) selInfoBox.style.display = 'block';
        if (selNameText) selNameText.textContent = pName;
        if (selPriceText) selPriceText.textContent = new Intl.NumberFormat('vi-VN').format(price) + ' đ';
        if (selStockBadge) {
          selStockBadge.textContent = '📦 Tồn kho: ' + new Intl.NumberFormat('vi-VN').format(stock) + ' SP';
          selStockBadge.className = 'badge ' + (stock > 0 ? 'bg-success' : 'bg-danger') + ' text-white rounded-pill px-2.5 py-1 extra-small';
        }
        if (livePriceInput && (!livePriceInput.value || livePriceInput.value === '0')) {
          livePriceInput.value = Math.round(price * 0.85);
        }
      } else {
        if (selInfoBox) selInfoBox.style.display = 'none';
      }
    });

    if (productSelect.value) {
      productSelect.dispatchEvent(new Event('change'));
    }
  }

  // Live filter modal product select
  const modalProductSearchInput = document.getElementById('modalProductSearchInput');
  if (modalProductSearchInput && productSelect) {
    modalProductSearchInput.addEventListener('input', function() {
      const q = this.value.toLowerCase().trim();
      const options = productSelect.options;
      for (let i = 0; i < options.length; i++) {
        const opt = options[i];
        if (!opt.value) continue;
        const searchData = opt.getAttribute('data-search') || '';
        if (!q || searchData.includes(q)) {
          opt.style.display = '';
        } else {
          opt.style.display = 'none';
        }
      }
    });
  }



  const chatBox = document.getElementById('liveChatBox');
  const chatForm = document.getElementById('liveChatForm');
  const chatInput = document.getElementById('liveChatInput');
  const currentRoomId = '<?= h($currentLive['id']) ?>';
  let renderedMsgIds = new Set();

  function renderChatMessage(msg) {
    if (!chatBox) return;
    if (msg.id && renderedMsgIds.has(msg.id)) return;
    if (msg.id) renderedMsgIds.add(msg.id);

    const msgEl = document.createElement('div');
    if (msg.is_ai) {
      msgEl.className = 'chat-msg mb-2 p-2.5 rounded-3 text-white shadow-sm';
      msgEl.style.background = 'linear-gradient(135deg, #162F18 0%, #215427 100%)';
      msgEl.innerHTML = '<strong class="text-warning"><i class="fa-solid fa-robot me-1"></i>' + escapeHtml(msg.sender_name || 'AI Co-Host') + ':</strong> ' + escapeHtml(msg.message);
    } else {
      msgEl.className = 'chat-msg mb-2 p-2 rounded-3 bg-white border shadow-sm';
      msgEl.style.borderColor = '#E2EADF';
      msgEl.innerHTML = '<strong class="text-dark"><i class="fa-solid fa-user me-1 text-success"></i>' + escapeHtml(msg.sender_name || 'Khách hàng') + ':</strong> ' + escapeHtml(msg.message);
    }
    chatBox.appendChild(msgEl);
    chatBox.scrollTop = chatBox.scrollHeight;
  }

  function loadChatHistory() {
    if (!currentRoomId) return;
    fetch('<?= BASE_URL ?>/index.php?r=api_live_chat_history&room_id=' + currentRoomId)
      .then(res => res.json())
      .then(res => {
        if (res.ok && Array.isArray(res.data)) {
          res.data.forEach(msg => renderChatMessage(msg));
        }
      })
      .catch(e => console.log('Chat history load error', e));
  }

  // Load initial chat history immediately
  loadChatHistory();

  // Poll chat history every 2.5s for realtime cross-user view sync
  setInterval(loadChatHistory, 2500);

  if (chatForm && chatInput) {
    chatForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const text = chatInput.value.trim();
      if (!text) return;

      chatInput.value = '';

      // Call Backend AI Agent API & save chat to MongoDB
      const formData = new FormData();
      formData.append('message', text);
      formData.append('product_id', '<?= h($pinnedId) ?>');
      formData.append('pinned_price', '<?= (float)$pinnedPrice ?>');
      formData.append('room_id', '<?= h($currentLive['id']) ?>');

      fetch('<?= BASE_URL ?>/index.php?r=api_live_chat', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        // Reload chat history to show updated chat messages
        loadChatHistory();
        if (data.ok && data.is_order) {
          let cartLink = document.querySelector('.header-icon-link--cart');
          let badge = document.querySelector('.header-cart-badge, .cart-count-badge');
          if (!badge && cartLink) {
            badge = document.createElement('em');
            badge.className = 'header-cart-badge';
            cartLink.appendChild(badge);
          }
          if (badge) {
            badge.textContent = data.cart_count || '1';
          }
        }
      })
      .catch(err => console.log('Live chat AI error', err));
    });
  }

  function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // --- Realtime Auto-Sync Pinned Product ---
  function syncPinnedProduct() {
    fetch('<?= BASE_URL ?>/index.php?r=api_live_products&id=' + currentRoomId)
      .then(res => res.json())
      .then(res => {
        if (res.ok && res.data && res.data.pinned_product) {
          const p = res.data.pinned_product;
          const livePrice = res.data.gia_uu_dai_live;
          const marketPrice = res.data.gia_thi_truong;
          const pId = res.data.pinned_product_id;

          const nameEl = document.getElementById('livePinnedName');
          const brandEl = document.getElementById('livePinnedBrand');
          const priceEl = document.getElementById('livePinnedPrice');
          const marketPriceEl = document.getElementById('livePinnedMarketPrice');
          const imgEl = document.getElementById('livePinnedImg');
          const hiddenInputEl = document.getElementById('livePinnedProductId');

          if (nameEl && p.ten_san_pham) nameEl.textContent = p.ten_san_pham;
          if (brandEl && p.thuong_hieu) brandEl.textContent = p.thuong_hieu;
          if (priceEl && livePrice) priceEl.textContent = new Intl.NumberFormat('vi-VN').format(livePrice) + 'đ';
          if (marketPriceEl && marketPrice) marketPriceEl.textContent = new Intl.NumberFormat('vi-VN').format(marketPrice) + 'đ';
          if (imgEl && p.link_hinh_anh) imgEl.src = p.link_hinh_anh;
          if (hiddenInputEl && pId) hiddenInputEl.value = pId;
        }
      })
      .catch(e => console.log('Sync pinned product error', e));
  }

  // Poll pinned product every 4s so Viewers see Admin pin changes instantly
  setInterval(syncPinnedProduct, 4000);

  // --- LiveKit Real WebRTC Client Connection ---
  let livekitRoom = null;
  let localVideoTrack = null;
  let localAudioTrack = null;

  async function initLiveKitWebRTC() {
    const statusBadge = document.getElementById('livekitStatusBadge');
    const viewerCountEl = document.getElementById('liveViewerCount');
    const remoteVideo = document.getElementById('livekitRemoteVideo');
    const remoteAudio = document.getElementById('livekitRemoteAudio');
    const waitingOverlay = document.getElementById('liveWaitingOverlay');

    let canPublish = true;

    try {
      const response = await fetch('<?= BASE_URL ?>/index.php?r=api_live_token&id=<?= h($currentLive['id']) ?>');
      const res = await response.json();

      if (!res.ok || !res.data || !res.data.token) {
        if (statusBadge) {
          statusBadge.className = 'badge bg-secondary rounded-pill px-2.5 py-1';
          statusBadge.textContent = '⚡ Chế độ xem mượt (Cam / Mic sẵn sàng)';
        }
        setupStreamerControls(true, null);
        return;
      }

      const { server_url, participant_token, roomName, can_publish } = res.data;
      canPublish = can_publish !== undefined ? can_publish : true;

      if (typeof LivekitClient === 'undefined') {
        console.warn('[LiveKit] SDK client script is loading...');
        setupStreamerControls(canPublish, null);
        return;
      }

      const room = new LivekitClient.Room({
        adaptiveStream: true,
        dynacast: true,
      });
      livekitRoom = room;

      // Realtime Connection State Handlers
      room.on(LivekitClient.RoomEvent.ConnectionStateChanged, (state) => {
        console.log('[LiveKit WebRTC ConnectionState]', state);
        if (!statusBadge) return;
        if (state === LivekitClient.ConnectionState.Connected) {
          statusBadge.className = 'badge bg-success rounded-pill px-2.5 py-1';
          statusBadge.innerHTML = '<i class="fa-solid fa-circle text-white me-1"></i>Đã kết nối';
        } else if (state === LivekitClient.ConnectionState.Connecting) {
          statusBadge.className = 'badge bg-warning text-dark rounded-pill px-2.5 py-1';
          statusBadge.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Đang kết nối...';
        } else if (state === LivekitClient.ConnectionState.Reconnecting) {
          statusBadge.className = 'badge bg-warning text-dark rounded-pill px-2.5 py-1';
          statusBadge.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i>Đang thử kết nối lại...';
        } else if (state === LivekitClient.ConnectionState.Disconnected) {
          statusBadge.className = 'badge bg-secondary rounded-pill px-2.5 py-1';
          statusBadge.innerHTML = '⏹️ Đã ngắt kết nối';
        }
      });

      // Real Participant Counting
      function updateParticipantCount() {
        if (!viewerCountEl || !room) return;
        const total = room.remoteParticipants.size + (room.localParticipant ? 1 : 0);
        viewerCountEl.textContent = new Intl.NumberFormat('vi-VN').format(total);
      }

      room.on(LivekitClient.RoomEvent.ParticipantConnected, updateParticipantCount);
      room.on(LivekitClient.RoomEvent.ParticipantDisconnected, updateParticipantCount);

      // Track Subscribed (Viewer receives Streamer's Video/Audio)
      room.on(LivekitClient.RoomEvent.TrackSubscribed, (track, publication, participant) => {
        console.log('[LiveKit WebRTC Subscribed Track]', track.kind, 'from participant:', participant.identity);
        if (track.kind === LivekitClient.Track.Kind.Video) {
          if (remoteVideo) {
            track.attach(remoteVideo);
            remoteVideo.style.display = 'block';
          }
          if (waitingOverlay) waitingOverlay.style.display = 'none';
        } else if (track.kind === LivekitClient.Track.Kind.Audio) {
          if (remoteAudio) {
            track.attach(remoteAudio);
          }
        }
      });

      // Track Unsubscribed (Streamer stopped camera)
      room.on(LivekitClient.RoomEvent.TrackUnsubscribed, (track, publication, participant) => {
        console.log('[LiveKit WebRTC Unsubscribed Track]', track.kind);
        track.detach();
        if (track.kind === LivekitClient.Track.Kind.Video) {
          if (remoteVideo) remoteVideo.style.display = 'none';
          if (waitingOverlay) waitingOverlay.style.display = 'flex';
        }
      });

      // Setup Streamer Controls (Admin / Staff / Test)
      setupStreamerControls(canPublish, room);

      await room.connect(server_url, participant_token);
      updateParticipantCount();

    } catch (e) {
      console.error('[LiveKit Connect Error]', e);
      if (statusBadge) {
        statusBadge.className = 'badge bg-success rounded-pill px-2.5 py-1';
        statusBadge.textContent = '🎥 Chế độ Cam/Mic trực tiếp';
      }
      setupStreamerControls(true, null);
    }
  }

  function setupStreamerControls(canPublish, room) {
    const btnCam = document.getElementById('btnToggleCam');
    const btnMic = document.getElementById('btnToggleMic');
    const localVideo = document.getElementById('livekitLocalVideo');
    const btnEndLive = document.getElementById('btnEndLiveSession');
    const waitingOverlay = document.getElementById('liveWaitingOverlay');

    if (!btnCam && !btnMic) return;
    if (btnCam && btnCam.dataset.bound === '1') return; // avoid duplicate listeners
    if (btnCam) btnCam.dataset.bound = '1';
    if (btnMic) btnMic.dataset.bound = '1';

    let isCamOn = false;
    let isMicOn = false;
    let fallbackStream = null;
    let fallbackAudioStream = null;
    let mediaRecorder = null;
    let recordedChunks = [];

    function startAutoRecording(stream) {
      if (!stream) return;
      recordedChunks = [];
      try {
        let mime = 'video/webm;codecs=vp9,opus';
        if (typeof MediaRecorder === 'undefined') return;
        if (!MediaRecorder.isTypeSupported(mime)) mime = 'video/webm;codecs=vp8,opus';
        if (!MediaRecorder.isTypeSupported(mime)) mime = 'video/webm';

        mediaRecorder = new MediaRecorder(stream, { mimeType: mime });
        mediaRecorder.ondataavailable = function(e) {
          if (e.data && e.data.size > 0) {
            recordedChunks.push(e.data);
          }
        };
        mediaRecorder.start(1000);
        console.log('[AutoRecorder] Started auto-recording live stream!');
      } catch (e) {
        console.warn('[AutoRecorder] MediaRecorder init error:', e);
      }
    }

    if (btnEndLive) {
      btnEndLive.addEventListener('click', function(e) {
        if (!confirm('Bạn có chắc chắn muốn DỪNG & KẾT THÚC VĨNH VIỄN phiên LiveStream này? Hệ thống sẽ tự động lưu bản ghi video quay được.')) {
          e.preventDefault();
          return;
        }

        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
          e.preventDefault();
          btnEndLive.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Đang lưu video bản ghi...';
          btnEndLive.classList.add('disabled');

          mediaRecorder.onstop = function() {
            if (recordedChunks.length === 0) {
              window.location.href = btnEndLive.href;
              return;
            }
            let blob = new Blob(recordedChunks, { type: 'video/webm' });
            let formData = new FormData();
            formData.append('live_id', '<?= h($currentLive['id']) ?>');
            formData.append('video_blob', blob, 'live_recording.webm');

            fetch('<?= BASE_URL ?>/index.php?r=api_upload_live_recording', {
              method: 'POST',
              body: formData
            })
            .then(res => res.json())
            .then(data => {
              console.log('[AutoRecorder] Upload result:', data);
              window.location.href = btnEndLive.href;
            })
            .catch(err => {
              console.error('[AutoRecorder] Upload error:', err);
              window.location.href = btnEndLive.href;
            });
          };
          mediaRecorder.stop();
        }
      });
    }

    if (btnCam) {
      btnCam.addEventListener('click', async () => {
        try {
          btnCam.disabled = true;
          if (!isCamOn) {
            btnCam.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Đang mở...';

            let published = false;
            if (typeof LivekitClient !== 'undefined' && livekitRoom && livekitRoom.state === LivekitClient.ConnectionState.Connected) {
              try {
                localVideoTrack = await LivekitClient.createLocalVideoTrack();
                await livekitRoom.localParticipant.publishTrack(localVideoTrack);
                if (localVideo) {
                  localVideoTrack.attach(localVideo);
                  if (localVideoTrack.mediaStream) {
                    startAutoRecording(localVideoTrack.mediaStream);
                  }
                }
                published = true;
              } catch(lkErr) {
                console.warn('[LiveKit Track Fail] Fallback to HTML5 getUserMedia:', lkErr);
              }
            }

            if (!published) {
              if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                try {
                  fallbackStream = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 } } });
                } catch(camErr) {
                  console.warn('[getUserMedia height fail, fallback to basic video:true]', camErr);
                  fallbackStream = await navigator.mediaDevices.getUserMedia({ video: true });
                }
                if (localVideo) {
                  localVideo.srcObject = fallbackStream;
                  localVideo.play().catch(e => console.log('[Video Play Exception]', e));
                }
                startAutoRecording(fallbackStream);
              }
            }

            if (localVideo) localVideo.style.display = 'block';
            if (waitingOverlay) waitingOverlay.style.display = 'none';
            isCamOn = true;
            btnCam.className = 'btn btn-danger btn-sm rounded-pill fw-bold shadow px-2.5 py-1';
            btnCam.innerHTML = '<i class="fa-solid fa-video-slash me-1"></i>Tắt Cam';
          } else {
            if (localVideoTrack) {
              try { localVideoTrack.stop(); } catch(e){}
              if (livekitRoom && livekitRoom.localParticipant) {
                try { await livekitRoom.localParticipant.unpublishTrack(localVideoTrack); } catch(e){}
              }
              localVideoTrack = null;
            }
            if (fallbackStream) {
              fallbackStream.getTracks().forEach(t => t.stop());
              fallbackStream = null;
            }
            if (localVideo) {
              localVideo.srcObject = null;
              localVideo.style.display = 'none';
            }
            if (!isMicOn && waitingOverlay) waitingOverlay.style.display = 'flex';
            isCamOn = false;
            btnCam.className = 'btn btn-outline-light btn-sm rounded-pill fw-bold shadow px-2.5 py-1';
            btnCam.innerHTML = '<i class="fa-solid fa-video me-1"></i>Bật Cam';
          }
        } catch (err) {
          console.error('[Cam Error]', err);
          alert('Không thể mở Camera. Vui lòng cho phép quyền truy cập Camera trên trình duyệt!');
          btnCam.className = 'btn btn-outline-light btn-sm rounded-pill fw-bold shadow px-2.5 py-1';
          btnCam.innerHTML = '<i class="fa-solid fa-video me-1"></i>Bật Cam';
        } finally {
          btnCam.disabled = false;
        }
      });
    }

    if (btnMic) {
      btnMic.addEventListener('click', async () => {
        try {
          btnMic.disabled = true;
          if (!isMicOn) {
            btnMic.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Đang mở...';

            let published = false;
            if (typeof LivekitClient !== 'undefined' && livekitRoom && livekitRoom.state === LivekitClient.ConnectionState.Connected) {
              try {
                localAudioTrack = await LivekitClient.createLocalAudioTrack();
                await livekitRoom.localParticipant.publishTrack(localAudioTrack);
                published = true;
              } catch(e){
                console.warn('[LiveKit Audio Track Fail] Fallback to HTML5 getUserMedia:', e);
              }
            }

            if (!published) {
              if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                fallbackAudioStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                console.log('[HTML5 Mic] Micro stream active:', fallbackAudioStream);
              }
            }

            isMicOn = true;
            btnMic.className = 'btn btn-danger btn-sm rounded-pill fw-bold shadow px-2.5 py-1';
            btnMic.innerHTML = '<i class="fa-solid fa-microphone-slash me-1"></i>Tắt Mic';
          } else {
            if (localAudioTrack) {
              if (livekitRoom && livekitRoom.localParticipant) {
                try { await livekitRoom.localParticipant.unpublishTrack(localAudioTrack); } catch(e){}
              }
              try { localAudioTrack.stop(); } catch(e){}
              localAudioTrack = null;
            }
            if (fallbackAudioStream) {
              fallbackAudioStream.getTracks().forEach(t => t.stop());
              fallbackAudioStream = null;
            }
            isMicOn = false;
            btnMic.className = 'btn btn-outline-light btn-sm rounded-pill fw-bold shadow px-2.5 py-1';
            btnMic.innerHTML = '<i class="fa-solid fa-microphone me-1"></i>Bật Mic';
          }
        } catch (err) {
          console.error('[Mic Error]', err);
          alert('Không thể mở Micro: ' + err.message + '. Vui lòng cho phép quyền truy cập Micro!');
          btnMic.className = 'btn btn-outline-light btn-sm rounded-pill fw-bold shadow px-2.5 py-1';
          btnMic.innerHTML = '<i class="fa-solid fa-microphone me-1"></i>Bật Mic';
        } finally {
          btnMic.disabled = false;
        }
      });
    }
  }

  // --- FLY TO CART ANIMATION & HEADER BADGE UPDATE ---
  function animateFlyToCart(startImgEl) {
    const cartLink = document.getElementById('headerCartLink') || document.querySelector('.header-icon-link--cart');
    if (!startImgEl || !cartLink) return;

    const startRect = startImgEl.getBoundingClientRect();
    const endRect = cartLink.getBoundingClientRect();

    const clone = startImgEl.cloneNode(true);
    clone.style.position = 'fixed';
    clone.style.top = startRect.top + 'px';
    clone.style.left = startRect.left + 'px';
    clone.style.width = startRect.width + 'px';
    clone.style.height = startRect.height + 'px';
    clone.style.opacity = '0.9';
    clone.style.zIndex = '999999';
    clone.style.pointerEvents = 'none';
    clone.style.borderRadius = '12px';
    clone.style.boxShadow = '0 10px 25px rgba(33, 84, 39, 0.4)';
    clone.style.transition = 'all 0.85s cubic-bezier(0.18, 0.89, 0.32, 1.28)';

    document.body.appendChild(clone);

    requestAnimationFrame(() => {
      clone.style.top = (endRect.top + endRect.height / 2 - 15) + 'px';
      clone.style.left = (endRect.left + endRect.width / 2 - 15) + 'px';
      clone.style.width = '30px';
      clone.style.height = '30px';
      clone.style.opacity = '0.2';
      clone.style.transform = 'scale(0.3) rotate(360deg)';
    });

    setTimeout(() => {
      clone.remove();
      if (cartLink) {
        cartLink.classList.add('cart-shake-pop');
        setTimeout(() => cartLink.classList.remove('cart-shake-pop'), 600);
      }
    }, 850);
  }

  function updateHeaderCartBadge(count) {
    const badge = document.getElementById('headerCartCount') || document.querySelector('.header-cart-badge');
    if (badge) {
      badge.textContent = count;
      badge.style.display = 'inline-flex';
      badge.style.setProperty('display', 'inline-flex', 'important');
    }
  }

  // --- AJAX Live Buy Now Form Handler ---
  const liveBuyNowForm = document.getElementById('liveBuyNowForm');
  if (liveBuyNowForm) {
    liveBuyNowForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const btn = document.getElementById('btnLiveBuyNow');
      const pinnedImg = document.getElementById('livePinnedImg');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Đang xử lý...';
      }

      const formData = new FormData(liveBuyNowForm);
      fetch(liveBuyNowForm.action, {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = ' MUA NGAY TRONG LIVE';
        }
        if (data.ok) {
          if (pinnedImg) animateFlyToCart(pinnedImg);
          updateHeaderCartBadge(data.cart_count || '1');
        } else {
          alert(data.message || 'Không thể thêm sản phẩm vào giỏ hàng');
        }
      })
      .catch(err => {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = ' MUA NGAY TRONG LIVE';
        }
        console.error('Live Buy Now error', err);
      });
    });
  }

  // --- AJAX Live List Item Add to Cart ---
  document.querySelectorAll('.live-add-to-cart-form').forEach(form => {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      const btn = form.querySelector('button');
      const itemCard = form.closest('.d-flex');
      const itemImg = itemCard ? itemCard.querySelector('img') : null;
      if (btn) {
        btn.disabled = true;
        btn.textContent = '...';
      }
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form)
      })
      .then(res => res.json())
      .then(data => {
        if (btn) {
          btn.disabled = false;
          btn.textContent = '+ Thêm';
        }
        if (data.ok) {
          if (itemImg) animateFlyToCart(itemImg);
          updateHeaderCartBadge(data.cart_count || '1');
        } else {
          alert(data.message || 'Không thể thêm sản phẩm');
        }
      })
      .catch(() => {
        if (btn) {
          btn.disabled = false;
          btn.textContent = '+ Thêm';
        }
      });
    });
  });

  // REAL-TIME ACTIVE VIEWER HEARTBEAT TRACKER
  function startRealtimeViewerTracker() {
    const roomId = '<?= h($currentLive['id']) ?>';
    const isLiveActive = <?= $isLiveActive ? 'true' : 'false' ?>;
    const viewerCountEl = document.getElementById('liveViewerCount');

    function pingViewer() {
      if (!roomId) return;
      fetch('<?= BASE_URL ?>/index.php?r=api_live_ping&room=' + encodeURIComponent(roomId))
        .then(res => res.json())
        .then(data => {
          if (data && data.ok && viewerCountEl) {
            viewerCountEl.textContent = data.active_viewers;
          }
          fetchCardViewerCounts();
        })
        .catch(() => {});
    }

    function fetchCardViewerCounts() {
      fetch('<?= BASE_URL ?>/index.php?r=api_live_active_viewers')
        .then(res => res.json())
        .then(data => {
          if (data && data.ok && data.counts) {
            document.querySelectorAll('.live-session-card-item[data-status="live"]').forEach(card => {
              const rId = card.getAttribute('data-room-id');
              const cntEl = card.querySelector('.card-viewer-count');
              if (cntEl) {
                const activeCnt = data.counts[rId] !== undefined ? data.counts[rId] : (rId === roomId ? 1 : 0);
                cntEl.textContent = activeCnt;
              }
            });
          }
        })
        .catch(() => {});
    }

    pingViewer();
    fetchCardViewerCounts();
    setInterval(pingViewer, 5000);
  }
  startRealtimeViewerTracker();

  // FLASH DEAL COUNTDOWN TIMER
  let dealSecondsRemaining = 14 * 60 + 59;
  const timerTextEl = document.getElementById('flashDealTimerText');
  if (timerTextEl) {
    setInterval(() => {
      if (dealSecondsRemaining <= 0) {
        timerTextEl.textContent = 'Hết khung giờ';
        return;
      }
      dealSecondsRemaining--;
      const m = Math.floor(dealSecondsRemaining / 60);
      const s = dealSecondsRemaining % 60;
      timerTextEl.textContent = `${m < 10 ? '0' + m : m}:${s < 10 ? '0' + s : s}`;
    }, 1000);
  }

  initLiveKitWebRTC();
});
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
