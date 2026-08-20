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
<script src="https://cdn.jsdelivr.net/npm/livekit-client@2.1.2/dist/livekit-client.umd.min.js"></script>

<div class="container py-4">
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
          <video controls autoplay class="w-100 h-100 position-absolute top-0 start-0" style="object-fit: cover; z-index: 25;" src="<?= h($currentLive['url_ban_ghi']) ?>" poster="<?= h($currentLive['thumbnail']) ?>"></video>
        <?php endif; ?>

        <!-- UNIFIED TOP VIDEO OVERLAY BAR & STREAMER CONTROLS -->
        <div class="position-absolute top-0 start-0 w-100 p-3 d-flex align-items-center justify-content-between text-white flex-wrap gap-2" style="background: linear-gradient(180deg, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0) 100%); z-index: 40;">
          <div class="d-flex align-items-center gap-2 flex-wrap">
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

          <div class="d-flex align-items-center gap-2 flex-wrap ms-auto">
            <span class="badge bg-success bg-opacity-90 rounded-pill px-2.5 py-1" style="font-size: 0.75rem;" title="Kênh phát sóng HD trực tiếp"><i class="fa-solid fa-signal text-light me-1"></i>Phát Sóng HD</span>

            <?php if (in_array(current_role(), ['admin', 'nhanvien'], true)): ?>
              <?php if ($currentLive['status'] === 'live'): ?>
                <a href="<?= BASE_URL ?>/index.php?r=admin_live_status&id=<?= urlencode($currentLive['id']) ?>&status=ketthuc" class="btn btn-danger btn-sm rounded-pill fw-bold shadow px-2.5 py-1" style="font-size: 0.78rem;" onclick="return confirm('Bạn có chắc chắn muốn DỪNG & KẾT THÚC VĨNH VIỄN phiên LiveStream này? Phiên sẽ lưu bản ghi và không thể phát lại.');">
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

              <!-- TÁCH BIỆT NÚT CAMERA VÀ MICRO RIÊNG DÀNH CHO STREAMER -->
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

      <!-- PINNED LIVE SALE PRODUCT BAR -->
      <div class="card border-0 rounded-4 shadow-sm p-3 mt-3" style="background: #F4F8F4; border: 1.5px solid #C5DAC8 !important;">
        <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-danger rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;"><i class="fa-solid fa-thumbtack me-1"></i>SẢN PHẨM GHIM TRONG LIVE</span>
            <span class="badge bg-warning text-dark rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;" id="flashDealCountdownBadge">
              <i class="fa-solid fa-stopwatch me-1 text-danger"></i>Hết Deal sau: <span id="flashDealTimerText">14:59</span>
            </span>
          </div>
          <?php if ($isLiveActive && in_array(current_role(), ['admin', 'nhanvien'], true)): ?>
            <button type="button" class="btn btn-outline-success btn-sm rounded-pill fw-bold" data-bs-toggle="modal" data-bs-target="#studioPinProductModal">
              <i class="fa-solid fa-map-pin me-1"></i>Ghim SP & Bật Deal
            </button>
          <?php endif; ?>
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
        <div class="card border-0 rounded-4 shadow-sm p-3 mt-3" style="background: #FFF; border: 1.5px solid #E2EADF !important;">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <strong class="text-dark small"><i class="fa-solid fa-store me-1 text-success"></i>DANH SÁCH SẢN PHẨM ƯU ĐÃI TRONG LIVESTREAM</strong>
            <span class="badge bg-light text-success rounded-pill px-2.5 py-1" style="font-size: 0.75rem; border: 1px solid #C5DAC8;"><?= count($allProducts) ?> sản phẩm</span>
          </div>
          <div class="live-products-vscroll pe-1" style="max-height: 340px; overflow-y: auto; scroll-behavior: smooth;">
            <div class="d-flex flex-column gap-2">
              <?php foreach ($allProducts as $pItem): ?>
                <?php
                  $pId = (string)($pItem['ma_san_pham'] ?? $pItem['id'] ?? '');
                  $pName = (string)($pItem['ten_san_pham'] ?? '');
                  $pBrand = (string)($pItem['thuong_hieu'] ?? 'SkinSyntax');
                  $pPrice = (float)($pItem['gia_ban'] ?? 0);
                  $pMarketPrice = (float)($pItem['gia_thi_truong'] ?? 0);
                  $pImg = resolve_image_url((string)($pItem['link_hinh_anh'] ?? $pItem['hinh_anh'] ?? ''));
                ?>
                <div class="d-flex align-items-center gap-3 p-2 rounded-3 border bg-light position-relative" style="border-color: #E2EADF !important; transition: background-color 0.2s ease;">
                  <img src="<?= h($pImg) ?>" class="rounded-3 flex-shrink-0" style="width: 60px; height: 60px; object-fit: cover; border: 1px solid #C5DAC8;">
                  <div class="flex-grow-1 overflow-hidden" style="min-width: 0;">
                    <span class="badge bg-success text-white rounded-pill extra-small mb-1" style="font-size: 0.65rem;"><?= h($pBrand) ?></span>
                    <h6 class="fw-bold text-dark text-truncate mb-1" style="font-size: 0.88rem;" title="<?= h($pName) ?>"><?= h($pName) ?></h6>
                    <div class="d-flex align-items-baseline gap-2">
                      <span class="fw-extrabold text-success small" style="color: #215427 !important; font-size: 0.9rem;"><?= vnd($pPrice) ?></span>
                      <?php if ($pMarketPrice > $pPrice): ?>
                        <span class="text-muted text-decoration-line-through extra-small" style="font-size: 0.72rem;"><?= vnd($pMarketPrice) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="flex-shrink-0 ms-2">
                    <?php if ($isLiveActive): ?>
                      <?php if (in_array(current_role(), ['admin', 'nhanvien'], true)): ?>
                        <button type="button" class="btn btn-danger btn-sm rounded-pill px-3 py-1 fw-bold" data-bs-toggle="modal" data-bs-target="#studioPinProductModal_<?= h($pId) ?>" style="font-size: 0.75rem;">
                          Ghim SP
                        </button>
                      <?php else: ?>
                        <form method="post" action="<?= BASE_URL ?>/index.php?r=api_live_add_to_cart" class="m-0 live-add-to-cart-form">
                          <input type="hidden" name="live_id" value="<?= h($currentLive['id']) ?>">
                          <input type="hidden" name="product_id" value="<?= h($pId) ?>">
                          <input type="hidden" name="quantity" value="1">
                          <button type="submit" class="btn btn-outline-success btn-sm rounded-pill px-3 py-1 fw-bold">+ Thêm</button>
                        </form>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="badge bg-secondary text-white rounded-pill px-2.5 py-1 extra-small opacity-75" title="Phiên Live đã kết thúc - Đóng khung giờ ưu đãi">
                        <i class="fa-solid fa-lock me-1"></i>Hết hạn
                      </span>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if (in_array(current_role(), ['admin', 'nhanvien'], true)): ?>
                  <!-- MODAL GHIM SP KÈM CHỌN THỜI GIAN FLASH DEAL TỪNG SP -->
                  <div class="modal fade text-start" id="studioPinProductModal_<?= h($pId) ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                      <div class="modal-content rounded-4 border-0 shadow-lg">
                        <div class="modal-header border-bottom p-3">
                          <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-thumbtack text-danger me-2"></i>Ghim Sản Phẩm & Kích Hoạt Deal Trực Tiếp</h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_pin_product">
                          <input type="hidden" name="live_id" value="<?= h($currentLive['id']) ?>">
                          <input type="hidden" name="product_id" value="<?= h($pId) ?>">
                          <input type="hidden" name="redirect" value="1">
                          <div class="modal-body p-4">
                            <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 mb-3 border">
                              <img src="<?= h($pImg) ?>" class="rounded-3" style="width: 55px; height: 55px; object-fit: cover;">
                              <div>
                                <span class="badge bg-secondary extra-small mb-1">Mã SP: <?= h($pId) ?></span>
                                <strong class="d-block text-dark small text-truncate" style="max-width: 280px;"><?= h($pName) ?></strong>
                                <small class="text-muted">Giá gốc: <?= vnd($pPrice) ?></small>
                              </div>
                            </div>
                            <div class="mb-3">
                              <label class="form-label fw-bold small">Giá Ưu Đãi Trực Tiếp Trong Live (VNĐ) <span class="text-danger">*</span></label>
                              <input type="number" name="gia_uu_dai_live" class="form-control" value="<?= (float)$pPrice ?>" min="1000" required>
                            </div>
                            <div class="mb-3">
                              <label class="form-label fw-bold small">Thời Gian Giảm Giá Flash Deal Đếm Ngược (Chọn TG) <span class="text-danger">*</span></label>
                              <select name="duration_minutes" class="form-select">
                                <option value="5"> 5 Phút (Flash Sale Ngắn)</option>
                                <option value="10"> 10 Phút (Flash Sale Siêu Ngắn)</option>
                                <option value="15" selected> 15 Phút (Khung Deal Chuẩn TikTok/Shopee)</option>
                                <option value="30"> 30 Phút (Khung Deal Standard)</option>
                                <option value="60"> 60 Phút (Ưu Đãi Suốt Buổi Live)</option>
                              </select>
                            </div>
                          </div>
                          <div class="modal-footer border-top p-3">
                            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Hủy</button>
                            <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">
                              Ghim SP & Bật Deal Ngay
                            </button>
                          </div>
                        </form>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
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
                    <select name="product_id" class="form-select" required>
                      <?php foreach ($allProducts as $p): ?>
                        <option value="<?= h($p['ma_san_pham'] ?? $p['id'] ?? '') ?>" <?= ($p['ma_san_pham'] == $pinnedId) ? 'selected' : '' ?>>
                          [<?= h($p['ma_san_pham'] ?? '') ?>] <?= h($p['ten_san_pham'] ?? '') ?> - Giá kho: <?= vnd($p['gia_ban'] ?? 0) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="mb-3">
                    <label class="form-label fw-bold small">Giá Ưu Đãi Trực Tiếp Trong Live (VNĐ) <span class="text-danger">*</span></label>
                    <input type="number" name="gia_uu_dai_live" class="form-control" value="<?= (float)$pinnedPrice ?>" min="1000" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label fw-bold small">Thời Gian Giảm Giá Flash Deal Đếm Ngược <span class="text-danger">*</span></label>
                    <select name="duration_minutes" class="form-select">                      <option value="10"> 10 Phút (Flash Sale Siêu Ngắn)</option>
                      <option value="5"> 5 Phút (Flash Sale Ngắn)</option>
                      <option value="10"> 10 Phút (Flash Sale Ngắn)</option>
                      <option value="15" selected> 15 Phút (Khung Deal)</option>
                      <option value="30"> 30 Phút (Flash Sale Khung Giờ Standard)</option>
                      <option value="60"> 60 Phút (Ưu Đãi Tuyệt Đối)</option>
                    </select>
                  </div>
                </div>
                <div class="modal-footer border-top p-3">
                  <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Hủy</button>
                  <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">
                    📌 Ghim Sản Phẩm Này Ngay
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
          <?php if ($isLiveActive): ?>
            <div class="chat-msg mb-2 p-2 rounded-3 bg-white border" style="border-color: #E2EADF !important;">
              <strong class="text-primary">Thu Trang (Hà Nội):</strong> Sản phẩm <?= h($pinnedName) ?> có ưu đãi giá tốt không ạ?
            </div>
            <div class="chat-msg mb-2 p-2.5 rounded-3 text-white" style="background: linear-gradient(135deg, #162F18 0%, #215427 100%);">
              <strong style="color: #6EE7B7;"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Trợ Lý AI SkinSyntax:</strong> Sản phẩm <strong><?= h($pinnedName) ?></strong> từ hãng <?= h($pinnedBrand) ?> đang có giá ưu đãi đặc quyền <strong><?= vnd($pinnedPrice) ?></strong> ngay trong phiên Live này ạ!
            </div>
          <?php else: ?>
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
                  $stBtnText = 'Đã kết thúc - Bấm để xem chi tiết bản ghi';
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
        <form id="createLiveForm">
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
            <label class="form-label fw-bold small">Tên Tiêu Đề Phiên Livestream</label>
            <input type="text" id="liveTitleInput" class="form-control rounded-3" placeholder="VD: Tư Vấn Routine Đẹp Da Đón Tết Với AI Co-Host..." required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold small">Streamer / Bác Sĩ / Host</label>
            <input type="text" id="liveStreamerInput" class="form-control rounded-3" value="SkinSyntax Streamer & AI Co-Host" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold small">Cấu Hình Kết Nối LiveKit Cloud</label>
            <input type="text" class="form-control rounded-3" value="wss://skinsyntax-live.livekit.cloud" readonly style="background: #F8FAF8;">
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold small">Sản Phẩm Ghim Độc Quyền Trực Tiếp</label>
            <select id="liveProductSelect" class="form-select rounded-3">
              <option value="5876">[Mini] Kem Nền Lancôme Che Phủ Màu 245C (Giảm 84%)</option>
              <option value="5689">[Mini] Nước Thần Lancôme Clarifique Dưỡng Sáng (Giảm 82%)</option>
              <option value="5933">Dầu Dưỡng Tóc Weilaiya Cánh Hoa Hồng (Giảm 80%)</option>
            </select>
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="aiCoHostSwitch" checked>
            <label class="form-check-input-label fw-bold small" for="aiCoHostSwitch">Bật AI Agent Co-Host (Trả lời RAG & Tự động chốt đơn)</label>
          </div>
          <button type="button" class="btn text-white w-100 py-2.5 rounded-pill fw-bold" style="background: linear-gradient(135deg, #215427 0%, #162F18 100%); border: none;" data-bs-dismiss="modal" onclick="alert('Đã khởi tạo thành công phòng LiveStream mới trên LiveKit Cloud với AI Agent Co-Host!');">
            🚀 Khởi Tạo & Bắt Đầu Stream ngay
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const chatForm = document.getElementById('liveChatForm');
  const chatInput = document.getElementById('liveChatInput');
  const chatBox = document.getElementById('liveChatBox');

  if (!chatForm || !chatInput || !chatBox) return;

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
        if (productSelect) productSelect.value = livePresets[val].productId;
      }
    });
  }

  const currentRoomId = '<?= h($currentLive['id']) ?>';
  let renderedMsgIds = new Set();

  function renderChatMessage(msg) {
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

    try {
      const response = await fetch('<?= BASE_URL ?>/index.php?r=api_live_token&id=<?= h($currentLive['id']) ?>');
      const res = await response.json();

      if (!res.ok || !res.data || !res.data.token) {
        if (statusBadge) {
          statusBadge.className = 'badge bg-danger rounded-pill px-2.5 py-1';
          statusBadge.textContent = '❌ Lỗi AccessToken';
        }
        return;
      }

      const { server_url, participant_token, roomName, can_publish } = res.data;

      if (typeof LivekitClient === 'undefined') {
        console.warn('[LiveKit] SDK client script is loading...');
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

      await room.connect(server_url, participant_token);
      updateParticipantCount();

      // Streamer Controls Setup (Admin / Staff)
      if (can_publish) {
        const btnCam = document.getElementById('btnToggleCam');
        const btnMic = document.getElementById('btnToggleMic');
        const localVideo = document.getElementById('livekitLocalVideo');

        let isCamOn = false;
        let isMicOn = false;

        if (btnCam) {
          btnCam.addEventListener('click', async () => {
            try {
              btnCam.disabled = true;
              if (!isCamOn) {
                btnCam.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>...';
                localVideoTrack = await LivekitClient.createLocalVideoTrack();
                await room.localParticipant.publishTrack(localVideoTrack);
                if (localVideo) {
                  localVideoTrack.attach(localVideo);
                  localVideo.style.display = 'block';
                }
                if (waitingOverlay) waitingOverlay.style.display = 'none';
                isCamOn = true;
                btnCam.className = 'btn btn-success btn-sm rounded-pill fw-bold shadow px-2.5 py-1';
                btnCam.innerHTML = '<i class="fa-solid fa-video me-1"></i>Tắt Cam';
              } else {
                if (localVideoTrack) {
                  localVideoTrack.stop();
                  await room.localParticipant.unpublishTrack(localVideoTrack);
                  localVideoTrack = null;
                }
                if (localVideo) localVideo.style.display = 'none';
                if (!isMicOn && waitingOverlay) waitingOverlay.style.display = 'flex';
                isCamOn = false;
                btnCam.className = 'btn btn-outline-light btn-sm rounded-pill fw-bold shadow px-2.5 py-1';
                btnCam.innerHTML = '<i class="fa-solid fa-video-slash me-1"></i>Bật Cam';
              }
            } catch (err) {
              console.error('[Cam Error]', err);
              alert('Lỗi mở Camera: ' + err.message);
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
                btnMic.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>...';
                localAudioTrack = await LivekitClient.createLocalAudioTrack();
                await room.localParticipant.publishTrack(localAudioTrack);
                isMicOn = true;
                btnMic.className = 'btn btn-success btn-sm rounded-pill fw-bold shadow px-2.5 py-1';
                btnMic.innerHTML = '<i class="fa-solid fa-microphone me-1"></i>Tắt Mic';
              } else {
                if (localAudioTrack) {
                  localAudioTrack.stop();
                  await room.localParticipant.unpublishTrack(localAudioTrack);
                  localAudioTrack = null;
                }
                isMicOn = false;
                btnMic.className = 'btn btn-outline-light btn-sm rounded-pill fw-bold shadow px-2.5 py-1';
                btnMic.innerHTML = '<i class="fa-solid fa-microphone-slash me-1"></i>Bật Mic';
              }
            } catch (err) {
              console.error('[Mic Error]', err);
              alert('Lỗi mở Micro: ' + err.message);
              btnMic.className = 'btn btn-outline-light btn-sm rounded-pill fw-bold shadow px-2.5 py-1';
              btnMic.innerHTML = '<i class="fa-solid fa-microphone me-1"></i>Bật Mic';
            } finally {
              btnMic.disabled = false;
            }
          });
        }
      }

    } catch (e) {
      console.error('[LiveKit Connect Error]', e);
      if (statusBadge) {
        statusBadge.className = 'badge bg-danger rounded-pill px-2.5 py-1';
        statusBadge.textContent = '❌ Lỗi kết nối WebRTC';
      }
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
