<?php
// frontend/views/live_studio.php - Dedicated Live Studio for Admins & Streamers
$pageTitle = 'Live Studio - ' . ($live['tieu_de'] ?? 'SkinSyntax Live');
$role = current_role();
$user = current_user() ?? [];
$isStaff = in_array($role, ['admin', 'nhanvien'], true);
if (!$isStaff) {
    header('Location: ' . BASE_URL . '/index.php?r=admin_login');
    exit;
}

$liveId = (string)($live['id'] ?? '');
$displayStreamer = trim((string)preg_replace('/\s*&?\s*AI\s*Co[-\s]?Host\b/iu', '', (string)($live['streamer'] ?? '')));
$displayStreamer = $displayStreamer !== '' ? $displayStreamer : 'SkinSyntax Host';
$liveStatus = (string)($live['status'] ?? 'scheduled');
$activeVoucher = $live['active_voucher'] ?? null;
$deals = $live['danh_sach_deal'] ?? [];
$pinnedProduct = $pinnedProduct ?? null;
$csrfToken = $_SESSION['live_csrf'] ?? bin2hex(random_bytes(24));
$_SESSION['live_csrf'] = $csrfToken;
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/images/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.min.css">
  <style>
    :root {
      --studio-bg: #f3f6f2;
      --studio-panel: #ffffff;
      --studio-card: #f7faf7;
      --studio-border: #d7e1da;
      --studio-primary: #2d6a4f;
      --studio-primary-strong: #174c38;
      --studio-accent: #40916c;
      --studio-text: #17251e;
      --studio-muted: #607268;
      --studio-danger: #d92d45;
      --studio-warning: #a76700;
      --studio-focus: #f1b843;
    }
    body {
      background: var(--studio-bg);
      color: var(--studio-text);
      font-family: 'Quicksand', -apple-system, BlinkMacSystemFont, sans-serif;
      min-height: 100vh;
      margin: 0;
      padding: 0;
      overflow-x: hidden;
    }
    .studio-topbar {
      background: var(--studio-panel);
      border-bottom: 1px solid var(--studio-border);
      padding: 10px 20px;
      position: sticky;
      top: 0;
      z-index: 100;
    }
    .studio-video-container {
      position: relative;
      background: #000;
      aspect-ratio: 16/9;
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid var(--studio-border);
    }
    .studio-video-container video {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .studio-panel {
      background: var(--studio-panel);
      border: 1px solid var(--studio-border);
      border-radius: 12px;
      padding: 16px;
      box-shadow: 0 10px 28px rgba(23, 76, 56, 0.07);
    }
    .studio-chat-container {
      display: flex;
      flex-direction: column;
      height: 520px;
      background: var(--studio-panel);
      border: 1px solid var(--studio-border);
      border-radius: 12px;
      overflow: hidden;
    }
    .studio-chat-messages {
      flex: 1;
      overflow-y: auto;
      padding: 12px;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .chat-msg-row {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      font-size: 0.85rem;
      padding: 4px 6px;
      border-radius: 6px;
      transition: background 0.15s ease;
      position: relative;
    }
    .chat-msg-row:hover {
      background: #f1f6f2;
    }
    .chat-msg-row:hover .chat-msg-actions {
      display: flex !important;
    }
    .chat-msg-actions {
      display: none;
      position: absolute;
      right: 6px;
      top: 4px;
      gap: 4px;
      background: var(--studio-card);
      padding: 2px 4px;
      border-radius: 4px;
      border: 1px solid var(--studio-border);
    }
    .chat-badge {
      font-size: 0.68rem;
      font-weight: 700;
      padding: 1px 6px;
      border-radius: 999px;
    }
    .chat-badge-admin { background: #b45309; color: #fff; }
    .chat-badge-streamer { background: #15803d; color: #fff; }
    .chat-badge-ai { background: #0369a1; color: #fff; }
    .chat-badge-guest { background: #475569; color: #cbd5e1; }
    .studio-dock-tabs .nav-link {
      color: var(--studio-muted);
      border: 1px solid transparent;
      border-radius: 8px;
      font-weight: 600;
      padding: 8px 14px;
    }
    .studio-dock-tabs .nav-link.active {
      color: #fff;
      background: var(--studio-primary);
      border-color: var(--studio-primary);
    }
    .vu-meter-bar {
      height: 4px;
      border-radius: 2px;
      background: rgba(255, 255, 255, 0.22);
      overflow: hidden;
      margin-top: 4px;
    }
    .vu-meter-fill {
      height: 100%;
      width: 0%;
      background: linear-gradient(90deg, #52b788, #ffb703, #e63946);
      transition: width 0.08s linear;
    }
    .studio-badge-pulse {
      animation: pulse 1.5s infinite;
    }
    @keyframes pulse {
      0% { opacity: 1; }
      50% { opacity: 0.4; }
      100% { opacity: 1; }
    }
    .pin-comment-banner {
      background: #edf7f0;
      border: 1px solid #b8d9c3;
      padding: 6px 10px;
      font-size: 0.8rem;
      margin: 6px;
      border-radius: 4px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .studio-topbar strong,
    .studio-topbar .text-light,
    .studio-panel .text-light,
    .studio-chat-container .text-light { color: var(--studio-text) !important; }
    .studio-topbar .text-muted,
    .studio-panel .text-muted,
    .studio-chat-container .text-muted { color: var(--studio-muted) !important; }
    .studio-panel .bg-dark,
    .studio-chat-container .bg-dark,
    .studio-panel .alert-dark { background: #f7faf7 !important; }
    .studio-panel .border-secondary,
    .studio-chat-container .border-secondary { border-color: var(--studio-border) !important; }
    .studio-panel .form-control,
    .studio-panel .form-select,
    .studio-chat-container .form-control {
      min-height: 42px;
      color: var(--studio-text) !important;
      background: #fff !important;
      border-color: #b9c8be !important;
      font-size: 0.92rem;
    }
    .studio-panel .form-control::placeholder,
    .studio-chat-container .form-control::placeholder { color: #75867c; opacity: 1; }
    .studio-panel .form-control:focus,
    .studio-panel .form-select:focus,
    .studio-chat-container .form-control:focus {
      border-color: var(--studio-primary) !important;
      box-shadow: 0 0 0 3px rgba(45, 106, 79, 0.15);
    }
    .studio-panel .btn-outline-light,
    .studio-topbar .btn-outline-light {
      color: var(--studio-primary-strong);
      border-color: #9fb6a8;
      background: #fff;
    }
    .studio-panel .btn-outline-light:hover,
    .studio-topbar .btn-outline-light:hover {
      color: #fff;
      background: var(--studio-primary-strong);
      border-color: var(--studio-primary-strong);
    }
    .studio-panel .table-dark {
      --bs-table-color: var(--studio-text);
      --bs-table-bg: #fff;
      --bs-table-border-color: var(--studio-border);
      --bs-table-hover-color: var(--studio-text);
      --bs-table-hover-bg: #f2f7f3;
    }
    .studio-panel code { color: #9b2450; }
    .studio-topbar .bg-dark { background: #f5f8f5 !important; }
    .studio-topbar .badge.bg-dark { color: var(--studio-text) !important; }
    .studio-dock-tabs { overflow-x: auto; flex-wrap: nowrap; scrollbar-width: thin; }
    .studio-dock-tabs .nav-link { white-space: nowrap; }
    .studio-chat-messages { background: #fff; }
    .studio-chat-compose { background: #f7faf7; }
    .studio-chat-empty { color: var(--studio-muted); text-align: center; padding: 48px 24px; }
    .chat-avatar {
      width: 32px;
      height: 32px;
      flex: 0 0 32px;
      object-fit: cover;
      border-radius: 50%;
      background: #dcebe1;
    }
    .catalog-combobox { position: relative; }
    .catalog-results {
      position: absolute;
      z-index: 40;
      inset-inline: 0;
      top: calc(100% + 6px);
      max-height: 360px;
      overflow-y: auto;
      padding: 6px;
      background: #fff;
      border: 1px solid var(--studio-border);
      border-radius: 12px;
      box-shadow: 0 18px 40px rgba(19, 51, 38, 0.16);
    }
    .catalog-result {
      display: grid;
      grid-template-columns: 48px minmax(0, 1fr) auto;
      gap: 12px;
      align-items: center;
      width: 100%;
      min-height: 64px;
      padding: 8px;
      color: var(--studio-text);
      background: transparent;
      border: 0;
      border-radius: 9px;
      text-align: left;
    }
    .catalog-result:hover,
    .catalog-result:focus-visible { background: #edf6f0; outline: 2px solid var(--studio-primary); outline-offset: -2px; }
    .catalog-result img,
    .deal-product-image { width: 48px; height: 48px; object-fit: contain; background: #fff; border: 1px solid #e0e7e2; border-radius: 9px; }
    .catalog-result__name { display: block; font-weight: 700; line-height: 1.3; }
    .catalog-result__meta { display: block; margin-top: 3px; color: var(--studio-muted); font-size: 0.78rem; }
    .selected-product {
      display: grid;
      grid-template-columns: 56px minmax(0, 1fr) auto;
      gap: 12px;
      align-items: center;
      padding: 12px;
      background: #eef7f1;
      border: 1px solid #bdd9c5;
      border-radius: 12px;
    }
    .studio-field-label { display: block; margin-bottom: 6px; color: #3f5147; font-size: 0.8rem; font-weight: 700; }
    .deal-product-cell { display: grid; grid-template-columns: 48px minmax(180px, 1fr); gap: 10px; align-items: center; min-width: 260px; }
    .pin-countdown { font-variant-numeric: tabular-nums; }
    :focus-visible { outline: 3px solid var(--studio-focus); outline-offset: 2px; }
    ::selection { color: #fff; background: var(--studio-primary); }

    @media (max-width: 991.98px) {
      .studio-chat-container { height: 460px; margin-top: 16px; }
      .studio-topbar { position: static; }
    }
    @media (max-width: 767.98px) {
      .studio-panel { padding: 13px; }
      .selected-product { grid-template-columns: 48px minmax(0, 1fr); }
      .selected-product > button { grid-column: 1 / -1; }
      .catalog-result { grid-template-columns: 42px minmax(0, 1fr); }
      .catalog-result > span:last-child { grid-column: 2; }
    }
    @media (prefers-reduced-motion: reduce) {
      .studio-badge-pulse { animation: none; }
      * { scroll-behavior: auto !important; }
    }
  </style>
</head>
<body>

  <!-- ==================== TOP NAVIGATION ==================== -->
  <header class="studio-topbar d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-3">
      <a href="<?= BASE_URL ?>/index.php?r=admin_lives" class="btn btn-sm btn-outline-light rounded-pill px-3">
        <i class="bi bi-chevron-left me-1"></i>Về Admin Live
      </a>
      <div>
        <strong class="d-block" style="font-size: 1rem; color: #fff;">
          <i class="bi bi-broadcast text-danger me-2"></i><?= h($live['tieu_de'] ?? 'Live Studio') ?>
        </strong>
        <small class="text-muted" style="font-size: 0.78rem;">
          Mã phòng: <code>#<?= h($liveId) ?></code> · Người dẫn: <strong class="text-light"><?= h($displayStreamer) ?></strong>
        </small>
      </div>
    </div>

    <!-- METRICS & STATUS BADGES -->
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span id="badgeConnection" class="badge bg-secondary rounded-pill px-3 py-1.5 fw-bold">
        <i class="bi bi-circle-fill me-1" style="font-size: 0.6rem;"></i><span id="txtConnection">Chưa kết nối</span>
      </span>

      <span id="badgeTimer" class="badge bg-dark border rounded-pill px-3 py-1.5 text-light fw-bold" style="border-color: var(--studio-border) !important;">
        <i class="bi bi-clock me-1 text-warning"></i><span id="txtTimer">00:00:00</span>
      </span>

      <span id="badgeViewers" class="badge bg-dark border rounded-pill px-3 py-1.5 text-light fw-bold" style="border-color: var(--studio-border) !important;">
        <i class="bi bi-eye-fill me-1 text-primary"></i><span id="txtViewers">0</span> người xem
      </span>

      <span id="badgeRecording" class="badge bg-dark border rounded-pill px-3 py-1.5 text-muted fw-bold" style="border-color: var(--studio-border) !important;">
        <i class="bi bi-record-circle me-1 text-danger"></i><span id="txtRecording">Ghi hình: Chờ phát</span>
      </span>

      <a href="<?= BASE_URL ?>/index.php?r=live&amp;id=<?= urlencode($liveId) ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-bold" title="Mở trang khách xem">
        <i class="bi bi-box-arrow-up-right me-1"></i>Trang khách
      </a>
    </div>
  </header>

  <!-- ==================== MAIN STUDIO INTERFACE ==================== -->
  <main class="container-fluid p-3">
    <!-- ALERT NOTIFICATIONS -->
    <div id="studioAlertBox" style="display: none;" class="alert alert-warning alert-dismissible fade show rounded-3 p-3 mb-3" role="alert">
      <i class="bi bi-exclamation-triangle-fill me-2"></i><span id="studioAlertText"></span>
      <button type="button" class="btn-close" onclick="document.getElementById('studioAlertBox').style.display='none'"></button>
    </div>

    <div class="row g-3">
      <!-- ==================== CỘT TRÁI: VIDEO PREVIEW & ĐIỀU KHIỂN ==================== -->
      <div class="col-lg-8">
        <!-- 16:9 PREVIEW BOX -->
        <div class="studio-video-container mb-3 position-relative">
          <video id="studioPreviewVideo" autoplay playsinline muted></video>

          <!-- OVERLAY LIVE BADGE -->
          <div class="position-absolute top-0 start-0 p-3 d-flex align-items-center gap-2">
            <span id="studioLiveTag" class="badge bg-danger rounded-pill px-3 py-1.5 fw-bold studio-badge-pulse" style="display: none;">
              🔴 TRỰC TIẾP
            </span>
            <span id="studioPreviewTag" class="badge bg-dark bg-opacity-75 text-light rounded-pill px-3 py-1.5 fw-semibold border border-secondary">
              <i class="bi bi-camera me-1"></i>XEM TRƯỚC (PREVIEW)
            </span>
          </div>

          <!-- AUDIO VU METER OVERLAY -->
          <div class="position-absolute bottom-0 start-0 end-0 p-2" style="background: linear-gradient(0deg, rgba(0,0,0,0.8), transparent);">
            <div class="d-flex align-items-center justify-content-between text-white extra-small mb-1 px-1">
              <span><i class="bi bi-mic-fill me-1 text-success"></i>Âm lượng Microphone</span>
              <span id="vuLevelTxt">0%</span>
            </div>
            <div class="vu-meter-bar">
              <div id="vuMeterFill" class="vu-meter-fill"></div>
            </div>
          </div>
        </div>

        <!-- TOOLBAR ĐIỀU KHIỂN THIẾT BỊ & NGUỒN PHÁT -->
        <div class="studio-panel mb-3">
          <div class="row g-2 align-items-center mb-3">
            <div class="col-md-5">
              <label class="form-label text-muted extra-small fw-bold mb-1"><i class="bi bi-camera-video me-1"></i>Camera</label>
              <select id="studioCameraSelect" class="form-select form-select-sm bg-dark text-light border-secondary">
                <option value="">Đang dò tìm camera...</option>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label text-muted extra-small fw-bold mb-1"><i class="bi bi-mic me-1"></i>Microphone</label>
              <select id="studioMicSelect" class="form-select form-select-sm bg-dark text-light border-secondary">
                <option value="">Đang dò tìm micro...</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label text-muted extra-small fw-bold mb-1">Thiết bị</label>
              <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="refreshMediaDevices()" title="Quét lại thiết bị">
                <i class="bi bi-arrow-repeat"></i> Quét lại
              </button>
            </div>
          </div>

          <!-- BUTTONS ROW -->
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top border-secondary">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <button type="button" id="btnToggleCam" class="btn btn-sm btn-outline-light rounded-pill px-3 fw-semibold" onclick="toggleCamera()">
                <i class="bi bi-camera-video-fill me-1 text-success"></i><span id="lblToggleCam">Tắt Camera</span>
              </button>

              <button type="button" id="btnToggleMic" class="btn btn-sm btn-outline-light rounded-pill px-3 fw-semibold" onclick="toggleMicrophone()">
                <i class="bi bi-mic-fill me-1 text-success"></i><span id="lblToggleMic">Tắt Mic</span>
              </button>

              <button type="button" id="btnScreenShare" class="btn btn-sm btn-outline-light rounded-pill px-3 fw-semibold" onclick="toggleScreenShare()">
                <i class="bi bi-display me-1 text-info"></i><span id="lblScreenShare">Chia sẻ màn hình</span>
              </button>

              <div class="dropdown d-inline">
                <button class="btn btn-sm btn-outline-light rounded-pill px-3 dropdown-toggle fw-semibold" type="button" data-bs-toggle="dropdown">
                  <i class="bi bi-sliders me-1 text-warning"></i>Nguồn: <span id="lblActiveSource">Camera</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-dark">
                  <li><a class="dropdown-item active" href="javascript:void(0)" onclick="switchMediaSource('camera')"><i class="bi bi-camera-video me-2"></i>Webcam trực tiếp</a></li>
                  <li><a class="dropdown-item" href="javascript:void(0)" onclick="switchMediaSource('screen')"><i class="bi bi-display me-2"></i>Màn hình máy tính</a></li>
                  <li><a class="dropdown-item" href="javascript:void(0)" onclick="openFilePickerForSource('video')"><i class="bi bi-file-play me-2"></i>Tải video MP4/WebM...</a></li>
                </ul>
              </div>
              <input type="file" id="studioFileInput" accept="video/mp4,video/webm" style="display: none;" onchange="handleFileSourceSelected(this)">
            </div>

            <!-- CHÍNH NÚT PHÁT / DỪNG -->
            <div class="d-flex align-items-center gap-2">
              <button type="button" id="btnGoLive" class="btn btn-success fw-bold px-4 py-2 rounded-pill shadow" onclick="startStreaming()" style="background: #2D6A4F; border: none;">
                <i class="bi bi-broadcast me-1.5"></i>Bắt đầu phát trực tiếp
              </button>
              <button type="button" id="btnPauseLive" class="btn btn-warning fw-bold px-3 py-2 rounded-pill" onclick="pauseStreaming()" style="display: none;">
                <i class="bi bi-pause-fill me-1"></i>Tạm dừng
              </button>
              <button type="button" id="btnEndLive" class="btn btn-danger fw-bold px-3 py-2 rounded-pill" onclick="endStreaming()" style="display: none;">
                <i class="bi bi-stop-circle-fill me-1"></i>Kết thúc phát
              </button>
            </div>
          </div>
        </div>

        <!-- ==================== KHU VỰC ĐIỀU HÀNH (BOTTOM DOCK) ==================== -->
        <div class="studio-panel">
          <ul class="nav nav-pills studio-dock-tabs mb-3 gap-1 border-bottom border-secondary pb-2" id="studioDockTabs" role="tablist">
            <li class="nav-item">
              <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tabDockProducts"><i class="bi bi-bag-check me-1"></i>Sản phẩm (<?= count($deals) ?>)</button>
            </li>
            <li class="nav-item">
              <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tabDockVouchers"><i class="bi bi-ticket-perforated me-1"></i>Voucher / Ưu đãi</button>
            </li>
            <li class="nav-item">
              <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tabDockSources"><i class="bi bi-collection-play me-1"></i>Nguồn video</button>
            </li>
            <li class="nav-item">
              <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tabDockSyna"><i class="bi bi-robot me-1"></i>Trợ lý SYNA</button>
            </li>
            <li class="nav-item">
              <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tabDockMetrics"><i class="bi bi-graph-up me-1"></i>Thống kê</button>
            </li>
            <li class="nav-item">
              <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tabDockRecordings"><i class="bi bi-film me-1"></i>Bản ghi</button>
            </li>
          </ul>

          <div class="tab-content" id="studioDockContent">
            <!-- TAB SẢN PHẨM -->
            <div class="tab-pane fade show active" id="tabDockProducts">
              <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                  <span class="text-muted small">Đang giới thiệu:</span>
                  <span id="currentPinnedTxt" class="badge bg-success rounded-pill px-3 py-1">
                    <?= $pinnedProduct ? h($pinnedProduct['ten_san_pham']) : 'Chưa có sản phẩm ghim' ?>
                  </span>
                  <span id="pinCountdown" class="badge bg-light border text-dark pin-countdown" <?= empty($live['ma_san_pham_ghim']) ? 'hidden' : '' ?>></span>
                  <button type="button" id="btnUnpinProduct" class="btn btn-sm btn-outline-danger" onclick="unpinProduct()" <?= empty($live['ma_san_pham_ghim']) ? 'hidden' : '' ?>>Bỏ ghim</button>
                </div>
                <div>
                  <label for="pinDurationSelect" class="studio-field-label mb-1">Thời lượng ghim mặc định</label>
                  <select id="pinDurationSelect" class="form-select form-select-sm" aria-label="Thời lượng ghim sản phẩm">
                    <option value="5">5 phút</option>
                    <option value="10" selected>10 phút</option>
                    <option value="15">15 phút</option>
                    <option value="0">Không giới hạn</option>
                  </select>
                </div>
              </div>

              <!-- FORM THÊM NHANH DEAL SẢN PHẨM -->
              <div class="p-3 mb-3 rounded-3 border" style="background: #f8faf8; border-color: var(--studio-border) !important;">
                <strong class="d-block mb-3 text-light"><i class="bi bi-plus-circle me-1 text-success"></i>Thêm sản phẩm vào phiên</strong>
                <form id="formAddDeal" onsubmit="submitAddDeal(event)">
                  <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                  <input type="hidden" name="live_id" value="<?= h($liveId) ?>">
                  <input type="hidden" name="product_id" id="dealProductId" required>

                  <div class="catalog-combobox mb-3">
                    <label for="catalogSearchInput" class="studio-field-label">Tìm sản phẩm theo tên, thương hiệu hoặc mã</label>
                    <div class="input-group">
                      <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                      <input type="search" id="catalogSearchInput" class="form-control" placeholder="Ví dụ: serum, L'Oréal hoặc 6376" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="catalogSearchResults" aria-expanded="false">
                    </div>
                    <div id="catalogSearchResults" class="catalog-results" role="listbox" hidden></div>
                  </div>

                  <div id="selectedProductCard" class="selected-product mb-3" hidden aria-live="polite">
                    <img id="selectedProductImage" src="" alt="">
                    <div class="min-w-0">
                      <strong id="selectedProductName" class="d-block"></strong>
                      <span id="selectedProductMeta" class="text-muted small"></span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSelectedProduct()">Chọn sản phẩm khác</button>
                  </div>

                  <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                      <label for="dealOriginalPrice" class="studio-field-label">Giá bán hiện tại</label>
                      <input type="text" id="dealOriginalPrice" class="form-control" value="Chọn sản phẩm trước" readonly>
                    </div>
                    <div class="col-md-4">
                      <label for="dealPriceInput" class="studio-field-label">Giá ưu đãi trong Live (VNĐ)</label>
                      <input type="number" name="gia_uu_dai_live" id="dealPriceInput" class="form-control" placeholder="Nhập giá ưu đãi" min="1000" required disabled>
                    </div>
                    <div class="col-md-2">
                      <label for="dealStockInput" class="studio-field-label">Số lượng giới hạn</label>
                      <input type="number" name="so_luong_kho_deal" id="dealStockInput" class="form-control" placeholder="Số lượng" min="1" required disabled>
                    </div>
                    <div class="col-md-2">
                      <button type="submit" id="btnAddDeal" class="btn btn-success w-100 fw-bold" disabled>Thêm vào Live</button>
                    </div>
                    <div class="col-md-6">
                      <label for="studioDealDuration" class="studio-field-label">Thời gian mở bán kể từ lúc thêm</label>
                      <select class="form-select" id="studioDealDuration" name="duration_minutes">
                        <option value="15">15 phút</option>
                        <option value="30" selected>30 phút</option>
                        <option value="60">60 phút</option>
                        <option value="0">Đến khi kết thúc live</option>
                      </select>
                    </div>
                  </div>
                </form>
              </div>

              <!-- DANH SÁCH DEALS TRONG PHIÊN -->
              <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0 extra-small">
                  <thead>
                    <tr class="text-muted border-secondary">
                      <th>Sản phẩm</th>
                      <th>Giá gốc</th>
                      <th>Giá Live</th>
                      <th>Số lượng</th>
                      <th>Hiển thị</th>
                      <th class="text-end">Hành động</th>
                    </tr>
                  </thead>
                  <tbody id="studioDealsTableBody">
                    <?php if (!empty($deals)): ?>
                      <?php foreach ($deals as $dl): ?>
                        <?php
                          $isThisPinned = ($live['ma_san_pham_ghim'] ?? '') == ($dl['ma_san_pham'] ?? '');
                        ?>
                        <tr id="dealRow_<?= h($dl['deal_id'] ?? $dl['ma_san_pham']) ?>">
                          <td>
                            <div class="deal-product-cell">
                              <img class="deal-product-image" src="<?= h($dl['hinh_anh'] ?? '') ?>" alt="">
                              <div>
                                <strong class="d-block"><?= h($dl['ten_san_pham'] ?? $dl['ma_san_pham']) ?></strong>
                                <small class="text-muted"><?= h($dl['thuong_hieu'] ?? '') ?> · Mã <?= h($dl['ma_san_pham']) ?></small>
                                <?php if ($isThisPinned): ?>
                                  <span class="badge bg-danger rounded-pill mt-1">Đang ghim</span>
                                <?php endif; ?>
                              </div>
                            </div>
                          </td>
                          <td><?= vnd($dl['gia_kho'] ?? 0) ?></td>
                          <td class="text-success fw-bold"><?= vnd($dl['gia_uu_dai_live'] ?? 0) ?></td>
                          <td><span class="badge bg-light border text-dark"><?= (int)($dl['so_luong_kho_deal'] ?? 0) ?> SP</span><small class="d-block text-muted mt-1">Tồn: <?= $dl['ton_kho'] === null ? 'Chưa rõ' : number_format((int)$dl['ton_kho']) ?></small></td>
                          <td class="text-muted"><?= $isThisPinned ? '<span data-pin-row-countdown>Đang tính…</span>' : 'Trong danh sách' ?></td>
                          <td class="text-end">
                            <button type="button" class="btn btn-sm btn-success fw-bold" onclick="pinProduct('<?= h($dl['ma_san_pham']) ?>', <?= (float)$dl['gia_uu_dai_live'] ?>, <?= (int)($dl['so_luong_kho_deal'] ?? 0) ?>, '<?= h(addslashes((string)($dl['ten_san_pham'] ?? $dl['ma_san_pham']))) ?>')">
                              <i class="bi bi-pin-angle-fill me-1"></i>Ghim
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" aria-label="Gỡ sản phẩm khỏi phiên" onclick="deleteDeal('<?= h($dl['deal_id'] ?? '') ?>')">
                              <i class="bi bi-trash"></i>
                            </button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr id="dealsEmptyRow">
                        <td colspan="6" class="text-center text-muted p-4">Chưa có sản phẩm trong phiên. Hãy tìm và thêm sản phẩm ở phía trên.</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- TAB VOUCHER / ƯU ĐÃI -->
            <div class="tab-pane fade" id="tabDockVouchers">
              <div class="row g-3">
                <!-- FORM TẠO NHANH VOUCHER VÀ PHÁT NGAY -->
                <div class="col-md-7">
                  <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.03); border: 1px solid var(--studio-border);">
                    <h6 class="text-warning fw-bold mb-3"><i class="bi bi-lightning-charge-fill me-1"></i>+ Tạo &amp; Phát Voucher chớp nhoáng (Flash Drop)</h6>
                    <form id="formQuickVoucher" onsubmit="submitQuickVoucher(event)" class="row g-2">
                      <div class="col-md-8">
                        <label class="form-label extra-small text-muted mb-1">Tên Voucher</label>
                        <input type="text" name="ten_voucher" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="VD: Tri ân khách Live 50K" value="Flash Deal Live 50K" required>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label extra-small text-muted mb-1">Mã Code (In hoa)</label>
                        <input type="text" name="ma_code" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="VD: LIVE50K" value="LIVE<?= strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)) ?>" required>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label extra-small text-muted mb-1">Loại giảm</label>
                        <select name="loai_giam" class="form-select form-select-sm bg-dark text-light border-secondary">
                          <option value="fixed">Số tiền cố định (VNĐ)</option>
                          <option value="percent">Phần trăm (%)</option>
                        </select>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label extra-small text-muted mb-1">Giá trị giảm</label>
                        <input type="number" name="gia_tri_giam" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="VD: 50000 hoặc 20" value="50000" required>
                      </div>
                      <div class="col-md-4">
                        <label class="form-label extra-small text-muted mb-1">Thời lượng phát</label>
                        <select name="thoi_luong_phut" class="form-select form-select-sm bg-dark text-light border-secondary">
                          <option value="5">5 Phút</option>
                          <option value="10" selected>10 Phút</option>
                          <option value="15">15 Phút</option>
                          <option value="30">30 Phút</option>
                        </select>
                      </div>
                      <div class="col-md-6">
                        <label class="form-label extra-small text-muted mb-1">Đơn tối thiểu (VNĐ)</label>
                        <input type="number" name="gia_tri_don_toi_thieu" class="form-control form-control-sm bg-dark text-light border-secondary" value="200000">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label extra-small text-muted mb-1">Số lượng voucher</label>
                        <input type="number" name="so_luong" class="form-control form-control-sm bg-dark text-light border-secondary" value="100">
                      </div>
                      <div class="col-12 text-end mt-3">
                        <button type="submit" class="btn btn-sm btn-warning fw-bold px-3 rounded-pill text-dark">
                          <i class="bi bi-broadcast me-1"></i>Tạo &amp; Phát Lên Màn Hình Ngay
                        </button>
                      </div>
                    </form>
                  </div>
                </div>

                <!-- TRẠNG THÁI VOUCHER ĐANG PHÁT & DANH SÁCH CÓ SẴN -->
                <div class="col-md-5">
                  <div class="p-3 rounded-3 mb-3 border border-secondary" style="background: rgba(255,255,255,0.03);">
                    <h6 class="fw-bold text-light mb-2"><i class="bi bi-broadcast text-danger me-1"></i>Voucher đang phát trên màn hình:</h6>
                    <div id="activeVoucherBox">
                      <?php if (!empty($activeVoucher)): ?>
                        <div class="p-2.5 bg-success bg-opacity-25 border border-success rounded-3 mb-2">
                          <strong class="d-block text-success fs-6"><i class="bi bi-ticket-perforated-fill me-1"></i>[<?= h($activeVoucher['ma_code']) ?>] <?= h($activeVoucher['ten_voucher']) ?></strong>
                          <small class="text-light d-block">Giảm: <strong><?= $activeVoucher['loai_giam'] === 'percent' ? $activeVoucher['gia_tri_giam'] . '%' : vnd($activeVoucher['gia_tri_giam']) ?></strong> · Hiệu lực đến: <?= h($activeVoucher['ket_thuc']) ?></small>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger w-100 rounded-pill" onclick="endVoucherDrop()">
                          <i class="bi bi-x-circle me-1"></i>Kết thúc phát voucher này
                        </button>
                      <?php else: ?>
                        <p class="text-muted small mb-0">Hiện chưa có voucher nào đang phát trên màn hình.</p>
                      <?php endif; ?>
                    </div>
                  </div>

                  <!-- PHÁT VOUCHER CÓ SẴN TỪ HỆ THỐNG -->
                  <div class="p-3 rounded-3 border border-secondary" style="background: rgba(255,255,255,0.03);">
                    <label class="form-label extra-small text-muted mb-1">Hoặc chọn Voucher có sẵn:</label>
                    <div class="d-flex gap-2">
                      <select id="selectExistingVoucher" class="form-select form-select-sm bg-dark text-light border-secondary">
                        <option value="">-- Chọn voucher hệ thống --</option>
                        <?php foreach ($availableVouchers ?? [] as $av): ?>
                          <option value="<?= h($av['ma_code'] ?? '') ?>">[<?= h($av['ma_code'] ?? '') ?>] <?= h($av['ten_voucher'] ?? '') ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="button" class="btn btn-sm btn-outline-warning text-nowrap fw-bold" onclick="dropExistingVoucher()">
                        Phát ngay
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- TAB NGUỒN VIDEO -->
            <div class="tab-pane fade" id="tabDockSources">
              <div class="row g-3">
                <div class="col-md-6">
                  <div class="p-3 rounded-3 border border-secondary" style="background: rgba(255,255,255,0.03);">
                    <strong class="d-block text-light small mb-2"><i class="bi bi-collection-play me-1 text-primary"></i>Chọn video tải lên để phát:</strong>
                    <p class="text-muted extra-small mb-3">Chọn video MP4/WebM có sẵn trên máy để phát cho người xem.</p>
                    <button type="button" class="btn btn-sm btn-outline-light rounded-pill px-3" onclick="openFilePickerForSource('video')">
                      <i class="bi bi-upload me-1"></i>Chọn tệp video từ máy tính
                    </button>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="p-3 rounded-3 border border-secondary bg-white">
                    <strong class="d-block text-light mb-2"><i class="bi bi-display me-1 text-warning"></i>OBS Studio (tùy chọn nâng cao)</strong>
                    <span class="badge bg-secondary mb-2">Chưa cấu hình</span>
                    <p class="text-muted small mb-0">Bạn không cần OBS khi phát bằng camera, microphone hoặc chia sẻ màn hình trên trình duyệt. Mục này chỉ dùng khi hệ thống đã được cấu hình riêng cho OBS.</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- TAB TRỢ LÝ SYNA -->
            <div class="tab-pane fade" id="tabDockSyna">
              <div class="p-3 rounded-3 border border-secondary" style="background: rgba(255,255,255,0.03);">
                <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                  <div>
                    <strong class="d-block text-light"><i class="bi bi-robot text-success me-2"></i>Trợ lý SYNA trong phiên livestream</strong>
                    <small class="text-muted">SYNA chỉ trả lời khi người xem gọi @syna kèm câu hỏi.</small>
                  </div>
                  <button type="button" class="btn btn-sm btn-success rounded-pill px-3 fw-bold" onclick="sendSynaReminder()">
                    <i class="bi bi-chat-heart-fill me-1"></i>Gửi lời nhắc hỏi SYNA vào chat
                  </button>
                </div>
                <div class="alert alert-dark border-secondary small text-light mb-0">
                  <i class="bi bi-info-circle me-1 text-info"></i>
                  Khi người xem chỉ gõ <code>@syna</code> mà không kèm câu hỏi, hệ thống gửi một lời chào ngắn và không xử lý tư vấn.
                </div>
              </div>
            </div>

            <!-- TAB THỐNG KÊ -->
            <div class="tab-pane fade" id="tabDockMetrics">
              <div class="row g-3">
                <div class="col-md-3">
                  <div class="p-3 bg-dark rounded-3 border border-secondary text-center">
                    <span class="text-muted extra-small d-block">Người xem hiện tại</span>
                    <strong class="fs-4 text-primary" id="metricActiveViewers">0</strong>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="p-3 bg-dark rounded-3 border border-secondary text-center">
                    <span class="text-muted extra-small d-block">Tổng lượt xem</span>
                    <strong class="fs-4 text-light"><?= number_format((int)($live['luot_xem'] ?? 0)) ?></strong>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="p-3 bg-dark rounded-3 border border-secondary text-center">
                    <span class="text-muted extra-small d-block">Đơn hàng tạo ra</span>
                    <strong class="fs-4 text-warning"><?= (int)($live['tong_don_hang'] ?? 0) ?></strong>
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="p-3 bg-dark rounded-3 border border-secondary text-center">
                    <span class="text-muted extra-small d-block">Doanh thu Live</span>
                    <strong class="fs-4 text-success"><?= vnd($live['tong_doanh_thu'] ?? 0) ?></strong>
                  </div>
                </div>
              </div>
            </div>

            <!-- TAB BẢN GHI -->
            <div class="tab-pane fade" id="tabDockRecordings">
              <div class="p-3 rounded-3 border border-secondary" style="background: rgba(255,255,255,0.03);">
                <strong class="d-block text-light small mb-2"><i class="bi bi-film me-1 text-info"></i>Tình trạng bản ghi:</strong>
                <p class="text-muted extra-small mb-2">Nếu phiên này bật ghi hình, video xem lại chỉ xuất hiện sau khi hệ thống kiểm tra tệp đã hoàn tất và phát được.</p>
                <div id="recordingDetailsBox">
                  <?php $studioRecordingStatus = (string)($live['recording_status'] ?? 'not_started'); $studioRecordingAvailable = !empty($live['recording_available']) && trim((string)($live['recording_url'] ?? '')) !== ''; ?>
                  <span class="badge bg-secondary rounded-pill px-3 py-1">Trạng thái: <?= h($studioRecordingStatus) ?></span>
                  <?php if ($studioRecordingAvailable): ?>
                    <div class="mt-2">
                      <a href="<?= h($live['recording_url']) ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3">
                        <i class="bi bi-play-circle me-1"></i>Xem lại video bản ghi
                      </a>
                    </div>
                  <?php elseif ($studioRecordingStatus === 'replay_ready'): ?>
                    <div class="small text-warning mt-2">Bản ghi không khả dụng.</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ==================== CỘT PHẢI: CHAT REALTIME (YOUTUBE LIVE STYLE) ==================== -->
      <div class="col-lg-4">
        <div class="studio-chat-container">
          <!-- CHAT HEADER -->
          <div class="p-2.5 border-bottom border-secondary d-flex align-items-center justify-content-between bg-dark">
            <div class="d-flex align-items-center gap-2">
              <strong class="text-light small"><i class="bi bi-chat-left-text-fill text-success me-1.5"></i>Trò chuyện trực tiếp</strong>
              <span id="chatCountBadge" class="badge bg-secondary rounded-pill extra-small">0</span>
              <span id="chatLiveStatus" class="badge <?= $liveStatus === 'live' ? 'bg-success' : 'bg-light text-dark border' ?>"><?= $liveStatus === 'live' ? 'Đang nhận tin' : 'Mở khi bắt đầu Live' ?></span>
            </div>
            <div class="btn-group btn-group-sm">
              <button type="button" class="btn btn-outline-secondary btn-sm active extra-small px-2 py-0.5" id="btnFilterAllChat" onclick="filterChat('all')">Tất cả</button>
              <button type="button" class="btn btn-outline-secondary btn-sm extra-small px-2 py-0.5" id="btnFilterSynaChat" onclick="filterChat('syna')">@syna</button>
            </div>
          </div>

          <!-- PINNED COMMENT BANNER (NẾU CÓ) -->
          <div id="studioPinnedCommentBanner" class="pin-comment-banner" style="display: none;">
            <div class="d-flex align-items-center gap-2 text-truncate">
              <i class="bi bi-pin-angle-fill text-warning"></i>
              <span id="studioPinnedCommentText" class="text-truncate"></span>
            </div>
            <button type="button" class="btn btn-link text-dark p-0 ms-2" onclick="unpinComment()" title="Bỏ ghim">
              <i class="bi bi-x"></i>
            </button>
          </div>

          <!-- CHAT MESSAGES BODY -->
          <div id="studioChatBody" class="studio-chat-messages">
            <div class="studio-chat-empty" data-chat-empty>
              <i class="bi bi-chat-dots d-block fs-3 mb-1 text-secondary"></i>
              Chưa có bình luận. Tin nhắn mới sẽ xuất hiện tại đây mà không cần tải lại trang.
            </div>
          </div>

          <!-- CHAT INPUT FOOTER -->
          <div class="p-2.5 border-top border-secondary studio-chat-compose">
            <form id="studioChatForm" onsubmit="sendStudioChat(event)" class="d-flex gap-2">
              <input type="text" id="studioChatInput" class="form-control form-control-sm bg-dark text-light border-secondary" placeholder="Nhập tin nhắn với tư cách Admin..." autocomplete="off">
              <button type="submit" class="btn btn-sm btn-primary px-3 fw-bold" style="background: var(--studio-primary); border: none;">
                <i class="bi bi-send-fill"></i>
              </button>
            </form>
            <div class="mt-1 text-muted extra-small">Nhấn Enter để gửi. Tin nhắn được lưu trong đúng phiên này.</div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <!-- SCRIPTS -->
  <script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/livekit-client@2.6.0/dist/livekit-client.umd.min.js" crossorigin="anonymous"></script>
  <script src="<?= BASE_URL ?>/assets/js/live-studio-media.js?v=<?= time() ?>"></script>

  <script>
    // GLOBAL STATE
    const LIVE_ID = '<?= addslashes($liveId) ?>';
    const CSRF_TOKEN = '<?= addslashes($csrfToken) ?>';
    const BASE_URL = '<?= addslashes(BASE_URL) ?>';
    let room = null;
    let studioMedia = null;
    let publisherToken = '';
    let activeStudioSource = 'camera';
    let isStreaming = false;
    let streamTimer = null;
    let streamSeconds = 0;
    let vuInterval = null;
    let currentPinnedProductId = '<?= addslashes((string)($live['ma_san_pham_ghim'] ?? '')) ?>';
    let lastChatTimestamp = 0;
    let chatMessages = [];
    let activeChatFilter = 'all';
    let selectedCatalogProduct = null;
    let catalogSearchTimer = null;
    let catalogSearchController = null;
    let pinEndsAt = <?= (int)(strtotime((string)($live['deal_ket_thuc'] ?? '')) ?: 0) ?>;
    let pinServerOffsetMs = 0;

    async function fetchStudioJson(url, options = {}) {
      const response = await fetch(url, options);
      const contentType = (response.headers.get('content-type') || '').toLowerCase();
      const raw = await response.text();
      if (!contentType.includes('application/json')) {
        console.error('[SkinSyntax Live] non_json_response', {url: response.url, status: response.status, contentType});
        throw new Error(`Máy chủ trả về dữ liệu không hợp lệ (HTTP ${response.status}). Vui lòng thử lại hoặc gửi mã lỗi này cho người quản trị.`);
      }
      let data;
      try {
        data = raw ? JSON.parse(raw) : {};
      } catch (error) {
        console.error('[SkinSyntax Live] invalid_json_response', {url: response.url, status: response.status});
        throw new Error(`Không đọc được phản hồi từ máy chủ (HTTP ${response.status}).`);
      }
      if (!response.ok && !data.message) data.message = `Yêu cầu thất bại (HTTP ${response.status}).`;
      return data;
    }

    // 1. KHỞI TẠO STUDIO
    document.addEventListener('DOMContentLoaded', async () => {
      initPreviewMedia();
      refreshMediaDevices();
      startHeartbeatPoll();
      loadChatHistory();
      bindCatalogSearch();
      refreshStudioProducts();
      updatePinCountdown();
      setInterval(updatePinCountdown, 1000);
    });

    // 2. KHỞI TẠO MEDIA PREVIEW
    async function initPreviewMedia() {
      const videoEl = document.getElementById('studioPreviewVideo');
      try {
        studioMedia = new LiveStudioMedia(videoEl, (sourceKind) => {
          if (sourceKind === 'screen') {
            activeStudioSource = '';
            document.getElementById('lblActiveSource').textContent = 'Đã dừng chia sẻ';
            showStudioAlert('Chia sẻ màn hình đã kết thúc. Camera và microphone vẫn giữ nguyên trạng thái tắt; hãy chủ động chọn nguồn phát tiếp theo.');
          } else {
            showStudioAlert('Nguồn phát đã kết thúc. Hãy chủ động chọn một nguồn khác để tiếp tục.');
          }
        });
        await studioMedia.select('camera', null, false);
        document.getElementById('lblActiveSource').textContent = 'Camera';
        startVuMeter();
      } catch (err) {
        console.warn('initPreviewMedia error:', err);
        showStudioAlert('Không thể mở Camera/Mic mặc định: ' + (err.message || 'Hãy kiểm tra quyền trình duyệt'));
      }
    }

    // VU METER
    function startVuMeter() {
      if (vuInterval) clearInterval(vuInterval);
      vuInterval = setInterval(() => {
        if (!studioMedia) return;
        const level = studioMedia.getAudioLevel();
        const fillEl = document.getElementById('vuMeterFill');
        const txtEl = document.getElementById('vuLevelTxt');
        if (fillEl) fillEl.style.width = level + '%';
        if (txtEl) txtEl.textContent = level + '%';
      }, 100);
    }

    // QUÉT THIẾT BỊ MEDIA
    async function refreshMediaDevices() {
      try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const camSelect = document.getElementById('studioCameraSelect');
        const micSelect = document.getElementById('studioMicSelect');

        camSelect.innerHTML = '<option value="">-- Mặc định --</option>';
        micSelect.innerHTML = '<option value="">-- Mặc định --</option>';

        devices.forEach((d, idx) => {
          if (d.kind === 'videoinput') {
            const opt = document.createElement('option');
            opt.value = d.deviceId;
            opt.textContent = d.label || `Camera ${idx + 1}`;
            camSelect.appendChild(opt);
          } else if (d.kind === 'audioinput') {
            const opt = document.createElement('option');
            opt.value = d.deviceId;
            opt.textContent = d.label || `Microphone ${idx + 1}`;
            micSelect.appendChild(opt);
          }
        });

        camSelect.onchange = () => {
          studioMedia.select('camera', null, false, camSelect.value, micSelect.value);
        };
        micSelect.onchange = () => {
          studioMedia.select('camera', null, false, camSelect.value, micSelect.value);
        };
      } catch (e) {
        console.warn('enumerateDevices error:', e);
      }
    }

    // TẮT / BẬT CAMERA
    function toggleCamera() {
      if (!studioMedia) return;
      const isMuted = studioMedia.toggleCameraMute();
      const btn = document.getElementById('btnToggleCam');
      const lbl = document.getElementById('lblToggleCam');
      if (isMuted) {
        btn.classList.replace('btn-outline-light', 'btn-danger');
        lbl.textContent = 'Bật Camera';
      } else {
        btn.classList.replace('btn-danger', 'btn-outline-light');
        lbl.textContent = 'Tắt Camera';
      }
    }

    // TẮT / BẬT MIC
    function toggleMicrophone() {
      if (!studioMedia) return;
      const isMuted = studioMedia.toggleMicMute();
      const btn = document.getElementById('btnToggleMic');
      const lbl = document.getElementById('lblToggleMic');
      if (isMuted) {
        btn.classList.replace('btn-outline-light', 'btn-danger');
        lbl.textContent = 'Bật Mic';
      } else {
        btn.classList.replace('btn-danger', 'btn-outline-light');
        lbl.textContent = 'Tắt Mic';
      }
    }

    // CHIA SẺ MÀN HÌNH
    async function toggleScreenShare() {
      if (!studioMedia) return;
      try {
        await studioMedia.select('screen');
        activeStudioSource = 'screen';
        document.getElementById('lblActiveSource').textContent = 'Màn hình';
      } catch (err) {
        showStudioAlert('Không thể chia sẻ màn hình: ' + err.message);
      }
    }

    // CHUYỂN NGUỒN MEDIA
    async function switchMediaSource(kind) {
      if (!studioMedia) return;
      try {
        await studioMedia.select(kind);
        activeStudioSource = kind === 'file' ? 'video' : kind;
        document.getElementById('lblActiveSource').textContent = kind === 'camera' ? 'Camera' : (kind === 'screen' ? 'Màn hình' : 'Video AI');
      } catch (err) {
        showStudioAlert('Không thể đổi nguồn phát: ' + err.message);
      }
    }

    function openFilePickerForSource(kind) {
      document.getElementById('studioFileInput').click();
    }

    async function handleFileSourceSelected(input) {
      const file = input.files?.[0];
      if (!file || !studioMedia) return;
      try {
        await studioMedia.select('file', file, true);
        activeStudioSource = 'video';
        document.getElementById('lblActiveSource').textContent = 'Video: ' + file.name.substring(0, 15) + '...';
      } catch (e) {
        showStudioAlert('Lỗi mở file video: ' + e.message);
      }
    }

    // ==================== LIVE STREAMING CONTROLS ====================
    async function startStreaming() {
      const btnGo = document.getElementById('btnGoLive');
      btnGo.disabled = true;
      btnGo.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang kết nối phòng phát...';

      try {
        // 1. LẤY TOKEN LIVEKIT PUBLISHER
        const dataToken = await fetchStudioJson(BASE_URL + '/index.php?r=api_live_token&id=' + encodeURIComponent(LIVE_ID) + '&studio=1', {cache: 'no-store'});
        if (!dataToken.ok || !dataToken.data?.participant_token) {
          throw new Error(dataToken.message || 'Không thể cấp quyền phát trực tiếp');
        }

        const livekitUrl = dataToken.data.livekit_url || dataToken.data.server_url || dataToken.data.url;
        const participantToken = dataToken.data.participant_token;
        if (!livekitUrl) throw new Error('Hệ thống chưa có địa chỉ phòng phát.');
        publisherToken = participantToken;

        // 2. KẾT NỐI LIVEKIT ROOM
        room = new LivekitClient.Room({ adaptiveStream: true, dynacast: true });

        room.on(LivekitClient.RoomEvent.ConnectionStateChanged, (state) => {
          console.info('[SkinSyntax Live] studio_room_state', state);
        });
        room.on(LivekitClient.RoomEvent.LocalTrackPublished, (publication) => {
          console.info('[SkinSyntax Live] studio_local_track_published', {kind: publication?.kind || 'unknown', source: publication?.source || 'unknown'});
        });
        room.on(LivekitClient.RoomEvent.TrackSubscribed, (track, publication, participant) => {
          console.info('[SkinSyntax Live] studio_track_subscribed', {kind: track?.kind || publication?.kind || 'unknown', participant: participant?.identity || 'unknown'});
        });

        // LẮNG NGHE SỰ KIỆN DATA CHANNEL (REALTIME ZERO-LAG CHAT, PIN, VOUCHER)
        room.on(LivekitClient.RoomEvent.DataReceived, (payload, participant) => {
          try {
            const str = new TextDecoder().decode(payload);
            const msg = JSON.parse(str);
            handleDataChannelMessage(msg);
          } catch (e) {}
        });

        room.on(LivekitClient.RoomEvent.Disconnected, () => {
          updateConnectionState('disconnected', 'Mất kết nối phòng phát');
        });

        await room.connect(livekitUrl, participantToken);
        updateConnectionState('connected', 'Đã kết nối phòng phát');

        // 3. PUBLISH TRACKS TỪ STUDIO MEDIA
        if (studioMedia) {
          if (!studioMedia.videoOutput || studioMedia.videoOutput.readyState !== 'live') {
            throw new Error('Camera chưa có luồng hình ảnh thật.');
          }
          console.info('[SkinSyntax Live] studio_publish_check', {video: studioMedia.videoOutput.readyState, audioFallback: Boolean(studioMedia.source?.audio_fallback), audioTracks: studioMedia.audioOutput?.readyState === 'live' ? 1 : 0});
          if (studioMedia.videoOutput) {
            const videoSource = activeStudioSource === 'screen' ? LivekitClient.Track.Source.ScreenShare : LivekitClient.Track.Source.Camera;
            const videoPublication = await room.localParticipant.publishTrack(studioMedia.videoOutput, { name: activeStudioSource === 'screen' ? 'screen-share' : 'camera', source: videoSource });
            if (!videoPublication?.trackSid) throw new Error('Hệ thống chưa xác nhận hình ảnh đã được gửi.');
          }
          const sourceHasRealAudio = studioMedia.source?.kind === 'file'
            || (!studioMedia.source?.audio_fallback && Number(studioMedia.source?.audio_track_count || 0) > 0);
          if (studioMedia.audioOutput && sourceHasRealAudio) {
            const audioSource = activeStudioSource === 'screen'
              ? (LivekitClient.Track.Source.ScreenShareAudio || LivekitClient.Track.Source.Microphone)
              : LivekitClient.Track.Source.Microphone;
            const audioPublication = await room.localParticipant.publishTrack(studioMedia.audioOutput, { name: activeStudioSource === 'screen' ? 'screen-audio' : 'microphone', source: audioSource });
            if (!audioPublication?.trackSid) throw new Error('Hệ thống chưa xác nhận âm thanh đã được gửi.');
          } else {
            console.info('[SkinSyntax Live] studio_audio_not_published', {source: activeStudioSource || 'none', reason: 'no_real_audio_track'});
          }
        }

        // 4. GỌI BACKEND CẬP NHẬT TRẠNG THÁI 'live' (ĐỒNG THỜI KÍCH HOẠT LIVEKIT EGRESS GHI HÌNH)
        const dataState = await fetchStudioJson(BASE_URL + '/index.php?r=api_live_stream_state', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf: CSRF_TOKEN, live_id: LIVE_ID, state: 'live', source: activeStudioSource, publisher_token: publisherToken })
        });
        if (!dataState.ok) {
          throw new Error(dataState.message || 'Không thể đồng bộ trạng thái phát');
        }

        // 5. CẬP NHẬT GIAO DIỆN PHÁT THẬT
        isStreaming = true;
        document.getElementById('studioLiveTag').style.display = 'inline-block';
        document.getElementById('studioPreviewTag').style.display = 'none';
        btnGo.style.display = 'none';
        document.getElementById('btnPauseLive').style.display = 'inline-block';
        document.getElementById('btnEndLive').style.display = 'inline-block';
        const chatStatus = document.getElementById('chatLiveStatus');
        chatStatus.className = 'badge bg-success';
        chatStatus.textContent = 'Đang nhận tin';
        document.getElementById('txtRecording').textContent = studioMedia?.source?.audio_fallback ? 'Đang phát hình · Chưa có âm thanh thật' : 'Đang phát · Đang chờ bản ghi';

        startStreamTimer();
      } catch (err) {
        console.error('startStreaming error:', err);
        try { await room?.disconnect(); } catch (cleanupError) {}
        room = null;
        publisherToken = '';
        isStreaming = false;
        showStudioAlert('Lỗi bắt đầu phát: ' + err.message);
        btnGo.disabled = false;
        btnGo.innerHTML = '<i class="bi bi-broadcast me-1.5"></i>Bắt đầu phát trực tiếp';
        updateConnectionState('error', 'Lỗi kết nối');
      }
    }

    async function pauseStreaming() {
      if (!isStreaming) return;
      try {
        await fetch(BASE_URL + '/index.php?r=api_live_stream_state', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf: CSRF_TOKEN, live_id: LIVE_ID, state: 'interrupted' })
        });
        document.getElementById('txtConnection').textContent = 'Đang tạm dừng';
        document.getElementById('btnPauseLive').textContent = 'Tiếp tục phát';
        document.getElementById('btnPauseLive').onclick = resumeStreaming;
      } catch (e) {}
    }

    async function resumeStreaming() {
      try {
        await fetch(BASE_URL + '/index.php?r=api_live_stream_state', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf: CSRF_TOKEN, live_id: LIVE_ID, state: 'live', source: activeStudioSource, publisher_token: publisherToken })
        });
        document.getElementById('txtConnection').textContent = 'Đang phát sóng';
        document.getElementById('btnPauseLive').innerHTML = '<i class="bi bi-pause-fill me-1"></i>Tạm dừng';
        document.getElementById('btnPauseLive').onclick = pauseStreaming;
      } catch (e) {}
    }

    async function endStreaming() {
      if (!confirm('Bạn có chắc chắn muốn kết thúc phiên phát trực tiếp này?')) return;
      const btnEnd = document.getElementById('btnEndLive');
      btnEnd.disabled = true;
      btnEnd.textContent = 'Đang đóng...';

      try {
        // DỪNG RECORDING & CẬP NHẬT TRẠNG THÁI 'ended'
        await fetch(BASE_URL + '/index.php?r=api_live_stream_state', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf: CSRF_TOKEN, live_id: LIVE_ID, state: 'ended' })
        });

        if (room) {
          await room.disconnect();
        }
        clearInterval(streamTimer);
        await waitForRecordingCompletion();
        alert('Phiên phát trực tiếp đã kết thúc thành công!');
        window.location.href = BASE_URL + '/index.php?r=admin_lives';
      } catch (e) {
        window.location.href = BASE_URL + '/index.php?r=admin_lives';
      }
    }

    async function waitForRecordingCompletion() {
      const label = document.getElementById('txtRecording');
      for (let attempt = 0; attempt < 15; attempt++) {
        try {
          const response = await fetch(BASE_URL + '/index.php?r=api_live_recording_status&id=' + encodeURIComponent(LIVE_ID), {cache: 'no-store'});
          const data = await response.json();
          if (data.recording_available) {
            if (label) label.textContent = 'Bản ghi đã sẵn sàng';
            return data;
          }
          if (label) label.textContent = data.recording_status === 'recording_failed' ? 'Không tạo được bản ghi' : 'Đang hoàn tất bản ghi…';
        } catch (e) {}
        await new Promise(resolve => setTimeout(resolve, 2000));
      }
      if (label) label.textContent = 'Bản ghi chưa xác minh được';
      return null;
    }

    function updateConnectionState(status, text) {
      const badge = document.getElementById('badgeConnection');
      const txt = document.getElementById('txtConnection');
      txt.textContent = text;
      if (status === 'connected') {
        badge.className = 'badge bg-success rounded-pill px-3 py-1.5 fw-bold';
      } else if (status === 'error' || status === 'disconnected') {
        badge.className = 'badge bg-danger rounded-pill px-3 py-1.5 fw-bold';
      } else {
        badge.className = 'badge bg-secondary rounded-pill px-3 py-1.5 fw-bold';
      }
    }

    function startStreamTimer() {
      streamSeconds = 0;
      if (streamTimer) clearInterval(streamTimer);
      streamTimer = setInterval(() => {
        streamSeconds++;
        const h = String(Math.floor(streamSeconds / 3600)).padStart(2, '0');
        const m = String(Math.floor((streamSeconds % 3600) / 60)).padStart(2, '0');
        const s = String(streamSeconds % 60).padStart(2, '0');
        document.getElementById('txtTimer').textContent = `${h}:${m}:${s}`;
      }, 1000);
    }

    // ==================== REALTIME CHAT (YOUTUBE LIVE STYLE) ====================
    async function loadChatHistory(reset = false) {
      try {
        const after = !reset && lastChatTimestamp ? Math.max(0, lastChatTimestamp - 1) : 0;
        const data = await fetchStudioJson(BASE_URL + '/index.php?r=api_live_chat_history&room_id=' + encodeURIComponent(LIVE_ID) + (after ? '&after=' + encodeURIComponent(after) : ''), {cache: 'no-store'});
        const messages = Array.isArray(data.chats) ? data.chats : (Array.isArray(data.data) ? data.data : []);
        if (data.ok) {
          if (reset) {
            chatMessages.length = 0;
            lastChatTimestamp = 0;
            document.getElementById('studioChatBody').querySelectorAll('.chat-msg-row').forEach(row => row.remove());
            document.getElementById('chatCountBadge').textContent = '0';
          }
          messages.forEach(c => appendChatMessage(c));
          if (reset || !studioPinnedMessageId) {
            const pinned = messages.filter(message => message.is_pinned).pop();
            studioPinnedMessageId = pinned?.id || '';
            document.getElementById('studioPinnedCommentText').textContent = pinned?.message || '';
            document.getElementById('studioPinnedCommentBanner').style.display = pinned ? 'flex' : 'none';
          }
        }
      } catch (error) {
        console.warn('[SkinSyntax Live] chat_history_failed', {name: error?.name || 'Error'});
      }
    }

    function appendChatMessage(c) {
      if (!c || !c.id || chatMessages.some(m => m.id === c.id)) return;
      chatMessages.push(c);
      lastChatTimestamp = Math.max(lastChatTimestamp, Number(c.ts || 0));

      const body = document.getElementById('studioChatBody');
      body.querySelector('[data-chat-empty]')?.remove();
      const isSyna = Boolean(c.is_ai || c.sender_type === 'ai' || (c.message && c.message.toLowerCase().includes('@syna')));

      const row = document.createElement('div');
      row.className = 'chat-msg-row';
      row.dataset.msgId = c.id;
      row.dataset.isSyna = isSyna ? '1' : '0';

      let roleBadge = '<span class="chat-badge chat-badge-guest">Khách</span>';
      if (c.sender_type === 'admin') roleBadge = '<span class="chat-badge chat-badge-admin">Admin</span>';
      else if (c.sender_type === 'streamer') roleBadge = '<span class="chat-badge chat-badge-streamer">Host</span>';
      else if (c.sender_type === 'employee') roleBadge = '<span class="chat-badge chat-badge-streamer">Nhân viên</span>';
      else if (c.sender_type === 'customer') roleBadge = '<span class="chat-badge chat-badge-guest">Khách hàng</span>';
      else if (c.sender_type === 'ai' || c.is_ai) roleBadge = '<span class="chat-badge chat-badge-ai">SYNA</span>';

      const createdAt = c.created_at ? new Date(c.created_at) : null;
      const displayTime = createdAt && !Number.isNaN(createdAt.getTime())
        ? createdAt.toLocaleTimeString('vi-VN', {hour: '2-digit', minute: '2-digit'})
        : '';

      row.innerHTML = `
        <div data-chat-avatar style="flex-shrink:0;">
          <div class="chat-avatar d-flex align-items-center justify-content-center text-white fw-bold" style="font-size:0.75rem;background:#2d6a4f;">
            ${(c.sender_name || 'K').charAt(0).toUpperCase()}
          </div>
        </div>
        <div style="flex:1;min-width:0;">
          <div class="d-flex align-items-center gap-1.5 flex-wrap">
            <strong class="text-light" style="font-size:0.82rem;">${escapeHtml(c.sender_name || 'Khách')}</strong>
            ${roleBadge}
            <small class="text-muted extra-small ms-auto">${displayTime}</small>
          </div>
          <div class="text-light-50 mt-0.5" style="font-size:0.85rem;word-break:break-word;">
            ${escapeHtml(c.message || '')}
          </div>
        </div>
        <div class="chat-msg-actions">
          <button type="button" data-chat-pin class="btn btn-sm btn-link text-warning p-0" aria-label="Ghim bình luận"><i class="bi bi-pin-angle"></i></button>
          <button type="button" data-chat-delete class="btn btn-sm btn-link text-danger p-0" aria-label="Ẩn bình luận"><i class="bi bi-trash"></i></button>
        </div>
      `;
      if (c.sender_avatar) {
        const image = document.createElement('img');
        image.className = 'chat-avatar';
        image.alt = '';
        image.src = c.sender_avatar;
        image.addEventListener('error', () => {
          image.remove();
          const fallback = row.querySelector('[data-chat-avatar] > div');
          if (fallback) fallback.hidden = false;
        }, {once: true});
        row.querySelector('[data-chat-avatar]').prepend(image);
        row.querySelector('[data-chat-avatar] > div').hidden = true;
      }
      row.querySelector('[data-chat-pin]')?.addEventListener('click', () => pinComment(c));
      row.querySelector('[data-chat-delete]')?.addEventListener('click', () => deleteComment(c.id));

      if (activeChatFilter === 'syna' && !isSyna) {
        row.style.display = 'none';
      }

      body.appendChild(row);
      body.scrollTop = body.scrollHeight;

      document.getElementById('chatCountBadge').textContent = chatMessages.length;
    }

    async function sendStudioChat(e) {
      e.preventDefault();
      const input = document.getElementById('studioChatInput');
      const text = input.value.trim();
      if (!text) return;

      input.value = '';
      try {
        const clientMessageId = window.crypto?.randomUUID?.() || ('studio-' + Date.now() + '-' + Math.random().toString(36).slice(2));
        const data = await fetchStudioJson(BASE_URL + '/index.php?r=api_live_chat', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            csrf: CSRF_TOKEN,
            room_id: LIVE_ID,
            message: text,
            client_message_id: clientMessageId,
          })
        });
        if (data.ok && data.chat) {
          appendChatMessage(data.chat);
          broadcastDataChannel({ type: 'chat_message' });
          if (data.syna_chat) {
            appendChatMessage(data.syna_chat);
          } else if (data.ai_status === 'pending') {
            const reply = await fetchStudioJson(BASE_URL + '/index.php?r=api_live_syna', {
              method: 'POST',
              body: new URLSearchParams({ csrf: CSRF_TOKEN, room_id: LIVE_ID, message_id: data.id }),
            });
            if (reply.ok && reply.syna_chat) {
              appendChatMessage(reply.syna_chat);
              broadcastDataChannel({ type: 'chat_message' });
            }
          }
        } else {
          input.value = text;
          showStudioAlert(data.message || 'Không gửi được tin nhắn.');
        }
      } catch (err) {
        if (!input.value) input.value = text;
        console.warn('sendStudioChat error:', err);
      }
    }

    function filterChat(filter) {
      activeChatFilter = filter;
      document.getElementById('btnFilterAllChat').classList.toggle('active', filter === 'all');
      document.getElementById('btnFilterSynaChat').classList.toggle('active', filter === 'syna');

      document.querySelectorAll('.chat-msg-row').forEach(row => {
        if (filter === 'all') {
          row.style.display = 'flex';
        } else {
          row.style.display = (row.dataset.isSyna === '1') ? 'flex' : 'none';
        }
      });
    }

    let studioPinnedMessageId = '';

    async function saveChatAction(action, id) {
      const result = await fetchStudioJson(BASE_URL + '/index.php?r=api_live_manage', {
        method: 'POST',
        body: new URLSearchParams({ csrf: CSRF_TOKEN, action, message_id: id, room_id: LIVE_ID }),
      });
      if (!result.ok) showStudioAlert('Chưa cập nhật được bình luận. Hãy thử lại.');
      return result.ok;
    }

    async function pinComment(message) {
      if (!await saveChatAction('pin', message.id)) return;
      studioPinnedMessageId = message.id;
      const banner = document.getElementById('studioPinnedCommentBanner');
      const txtEl = document.getElementById('studioPinnedCommentText');
      txtEl.textContent = message.message;
      banner.style.display = 'flex';
      broadcastDataChannel({ type: 'chat_moderated' });
    }

    async function unpinComment() {
      if (!studioPinnedMessageId || !await saveChatAction('unpin', studioPinnedMessageId)) return;
      studioPinnedMessageId = '';
      document.getElementById('studioPinnedCommentBanner').style.display = 'none';
      broadcastDataChannel({ type: 'chat_moderated' });
    }

    async function deleteComment(msgId) {
      try {
        if (!await saveChatAction('hide', msgId)) return;
        const el = document.querySelector(`[data-msg-id="${msgId}"]`);
        if (el) el.remove();
        broadcastDataChannel({ type: 'chat_moderated' });
      } catch (e) {}
    }

    function insertEmoji(emoji) {
      const input = document.getElementById('studioChatInput');
      input.value += emoji;
      input.focus();
    }

    // ==================== MULTI-PRODUCT & PINNING ====================
    const formatVnd = value => new Intl.NumberFormat('vi-VN').format(Number(value || 0)) + ' đ';

    function bindCatalogSearch() {
      const input = document.getElementById('catalogSearchInput');
      const results = document.getElementById('catalogSearchResults');
      input.addEventListener('input', () => {
        window.clearTimeout(catalogSearchTimer);
        catalogSearchTimer = window.setTimeout(() => searchCatalogProducts(input.value), 280);
      });
      input.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown' && !results.hidden) {
          event.preventDefault();
          results.querySelector('button')?.focus();
        }
        if (event.key === 'Escape') hideCatalogResults();
      });
      document.addEventListener('click', event => {
        if (!event.target.closest('.catalog-combobox')) hideCatalogResults();
      });
    }

    function hideCatalogResults() {
      const input = document.getElementById('catalogSearchInput');
      const results = document.getElementById('catalogSearchResults');
      results.hidden = true;
      input.setAttribute('aria-expanded', 'false');
    }

    async function searchCatalogProducts(rawQuery) {
      const query = rawQuery.trim();
      const input = document.getElementById('catalogSearchInput');
      const results = document.getElementById('catalogSearchResults');
      catalogSearchController?.abort();
      if (query.length < 2) {
        results.innerHTML = '<div class="p-3 text-muted small">Nhập ít nhất 2 ký tự để tìm trong catalog.</div>';
        results.hidden = query.length === 0;
        input.setAttribute('aria-expanded', results.hidden ? 'false' : 'true');
        return;
      }
      catalogSearchController = new AbortController();
      results.hidden = false;
      results.innerHTML = '<div class="p-3 text-muted small">Đang tìm sản phẩm…</div>';
      input.setAttribute('aria-expanded', 'true');
      try {
        const data = await fetchStudioJson(BASE_URL + '/index.php?r=api_search_catalog_products&live_id=' + encodeURIComponent(LIVE_ID) + '&q=' + encodeURIComponent(query), {cache: 'no-store', signal: catalogSearchController.signal});
        const products = Array.isArray(data.products) ? data.products : [];
        if (!products.length) {
          results.innerHTML = '<div class="p-3 text-muted small">Không tìm thấy sản phẩm phù hợp.</div>';
          return;
        }
        results.replaceChildren(...products.map(product => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'catalog-result';
          button.setAttribute('role', 'option');
          const image = document.createElement('img');
          image.src = product.img || product.hinh_anh || '';
          image.alt = '';
          const copy = document.createElement('span');
          const name = document.createElement('span');
          name.className = 'catalog-result__name';
          name.textContent = product.name || product.ten_san_pham || ('Sản phẩm ' + product.id);
          const meta = document.createElement('span');
          meta.className = 'catalog-result__meta';
          meta.textContent = `${product.thuong_hieu || 'Chưa có thương hiệu'} · Mã ${product.id} · Tồn ${product.stock_known ? Number(product.stock).toLocaleString('vi-VN') : 'chưa rõ'}`;
          copy.append(name, meta);
          const price = document.createElement('strong');
          price.textContent = product.formatted_price || formatVnd(product.price);
          button.append(image, copy, price);
          button.addEventListener('click', () => selectCatalogProduct(product));
          return button;
        }));
      } catch (error) {
        if (error.name === 'AbortError') return;
        results.innerHTML = '<div class="p-3 text-danger small">Không tải được kết quả. Hãy thử lại.</div>';
      }
    }

    function selectCatalogProduct(product) {
      selectedCatalogProduct = product;
      document.getElementById('dealProductId').value = product.id || product.ma_san_pham || '';
      document.getElementById('selectedProductImage').src = product.img || product.hinh_anh || '';
      document.getElementById('selectedProductImage').alt = product.name || product.ten_san_pham || '';
      document.getElementById('selectedProductName').textContent = product.name || product.ten_san_pham || '';
      document.getElementById('selectedProductMeta').textContent = `${product.thuong_hieu || 'Chưa có thương hiệu'} · Mã ${product.id} · Tồn ${product.stock_known ? Number(product.stock).toLocaleString('vi-VN') : 'chưa rõ'}`;
      document.getElementById('dealOriginalPrice').value = formatVnd(product.price || product.gia_ban);
      const price = document.getElementById('dealPriceInput');
      const stock = document.getElementById('dealStockInput');
      price.disabled = false;
      price.value = Number(product.price || product.gia_ban || 0) || '';
      stock.disabled = false;
      stock.value = '';
      if (product.stock_known) stock.max = String(Math.max(0, Number(product.stock)));
      else stock.removeAttribute('max');
      document.getElementById('btnAddDeal').disabled = false;
      document.getElementById('selectedProductCard').hidden = false;
      document.getElementById('catalogSearchInput').value = product.name || product.ten_san_pham || '';
      hideCatalogResults();
      price.focus();
    }

    function clearSelectedProduct() {
      selectedCatalogProduct = null;
      document.getElementById('formAddDeal').reset();
      document.getElementById('dealProductId').value = '';
      document.getElementById('selectedProductCard').hidden = true;
      document.getElementById('dealOriginalPrice').value = 'Chọn sản phẩm trước';
      document.getElementById('dealPriceInput').disabled = true;
      document.getElementById('dealStockInput').disabled = true;
      document.getElementById('btnAddDeal').disabled = true;
      document.getElementById('catalogSearchInput').focus();
    }

    function getPinDuration() {
      return document.getElementById('pinDurationSelect')?.value || '10';
    }

    function updatePinCountdown() {
      const badge = document.getElementById('pinCountdown');
      const rowCountdown = document.querySelector('[data-pin-row-countdown]');
      if (!currentPinnedProductId) {
        badge.hidden = true;
        return;
      }
      if (!pinEndsAt) {
        badge.hidden = false;
        badge.textContent = 'Không giới hạn';
        if (rowCountdown) rowCountdown.textContent = 'Không giới hạn';
        return;
      }
      const serverNow = Math.floor((Date.now() + pinServerOffsetMs) / 1000);
      const remaining = Math.max(0, pinEndsAt - serverNow);
      const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
      const seconds = String(remaining % 60).padStart(2, '0');
      badge.hidden = false;
      badge.textContent = remaining > 0 ? `${minutes}:${seconds}` : 'Đã hết thời gian';
      if (rowCountdown) rowCountdown.textContent = badge.textContent;
    }

    async function refreshStudioProducts() {
      try {
        const payload = await fetchStudioJson(BASE_URL + '/index.php?r=api_live_products&id=' + encodeURIComponent(LIVE_ID), {cache: 'no-store'});
        if (!payload.ok || !payload.data) return;
        const data = payload.data;
        pinServerOffsetMs = Number(data.server_time || 0) * 1000 - Date.now();
        pinEndsAt = Number(data.pin_ends_at || 0);
        currentPinnedProductId = data.pinned_product_id || data.expired_pinned_product_id || '';
        document.getElementById('btnUnpinProduct').hidden = !currentPinnedProductId;
        const pinnedName = data.pinned_product?.ten_san_pham || (data.expired_pinned_product_id ? 'Đã hết thời gian ghim' : 'Chưa có sản phẩm ghim');
        document.getElementById('currentPinnedTxt').textContent = pinnedName;
        document.getElementById('currentPinnedTxt').className = 'badge rounded-pill px-3 py-1 ' + (data.pin_active ? 'bg-success' : 'bg-secondary');
        renderStudioDeals(data.deals || [], data.pinned_product_id || '');
        updatePinCountdown();
      } catch (error) {
        console.warn('[SkinSyntax Live] product_refresh_failed', {name: error?.name || 'Error'});
      }
    }

    function renderStudioDeals(deals, pinnedId) {
      const tbody = document.getElementById('studioDealsTableBody');
      if (!deals.length) {
        tbody.innerHTML = '<tr id="dealsEmptyRow"><td colspan="6" class="text-center text-muted p-4">Chưa có sản phẩm trong phiên. Hãy tìm và thêm sản phẩm ở phía trên.</td></tr>';
        return;
      }
      tbody.innerHTML = deals.map(deal => {
        const isPinned = String(deal.product_id) === String(pinnedId);
        const stockText = deal.stock === null || typeof deal.stock === 'undefined' ? 'Chưa rõ' : Number(deal.stock).toLocaleString('vi-VN');
        return `<tr id="dealRow_${escapeHtml(deal.id)}">
          <td><div class="deal-product-cell"><img class="deal-product-image" src="${escapeHtml(deal.image || '')}" alt=""><div><strong class="d-block">${escapeHtml(deal.name || deal.product_id)}</strong><small class="text-muted">${escapeHtml(deal.brand || '')} · Mã ${escapeHtml(deal.product_id)}</small>${isPinned ? '<span class="badge bg-danger rounded-pill mt-1">Đang ghim</span>' : ''}</div></div></td>
          <td>${formatVnd(deal.original_price)}</td>
          <td class="text-success fw-bold">${formatVnd(deal.price)}</td>
          <td><span class="badge bg-light border text-dark">${Number(deal.limit || 0).toLocaleString('vi-VN')} SP</span><small class="d-block text-muted mt-1">Tồn: ${stockText}</small></td>
          <td class="text-muted">${isPinned ? '<span data-pin-row-countdown>Đang tính…</span>' : 'Trong danh sách'}</td>
          <td class="text-end"><button type="button" class="btn btn-sm btn-success fw-bold me-1" data-pin-product><i class="bi bi-pin-angle-fill me-1"></i>Ghim</button><button type="button" class="btn btn-sm btn-outline-danger" data-delete-deal aria-label="Gỡ sản phẩm khỏi phiên"><i class="bi bi-trash"></i></button></td>
        </tr>`;
      }).join('');
      deals.forEach(deal => {
        const row = document.getElementById('dealRow_' + deal.id);
        row?.querySelector('[data-pin-product]')?.addEventListener('click', () => pinProduct(deal.product_id, deal.price, deal.limit, deal.name));
        row?.querySelector('[data-delete-deal]')?.addEventListener('click', () => deleteDeal(deal.id));
      });
    }

    async function pinProduct(productId, price, stock, productName = '') {
      try {
        const data = await fetchStudioJson(BASE_URL + '/index.php?r=admin_live_pin_product', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            csrf: CSRF_TOKEN,
            live_id: LIVE_ID,
            product_id: productId,
            gia_uu_dai_live: price,
            so_luong_kho_deal: stock,
            duration_minutes: getPinDuration(),
            ajax: '1'
          })
        });
        if (data.ok) {
          currentPinnedProductId = productId;
          pinEndsAt = Number(data.pin_ends_at || 0);
          document.getElementById('currentPinnedTxt').textContent = productName || ('Sản phẩm ' + productId);
          broadcastDataChannel({ type: 'pin_product', product_id: productId, price: price, pin_ends_at: pinEndsAt });
          await refreshStudioProducts();
          showStudioNotice(data.message || 'Đã ghim sản phẩm.');
        } else {
          showStudioAlert(data.message || 'Không thể ghim sản phẩm.');
        }
      } catch (e) {
        showStudioAlert(e.message || 'Không thể ghim sản phẩm.');
      }
    }

    async function unpinProduct() {
      if (!currentPinnedProductId) return;
      try {
        const data = await fetchStudioJson(BASE_URL + '/index.php?r=api_live_manage', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({csrf: CSRF_TOKEN, room_id: LIVE_ID, action: 'product_unpin'})
        });
        if (!data.ok) throw new Error(data.message || 'Không thể bỏ ghim sản phẩm.');
        currentPinnedProductId = '';
        pinEndsAt = 0;
        await refreshStudioProducts();
        broadcastDataChannel({type: 'products_changed'});
        showStudioNotice('Đã bỏ ghim. Sản phẩm vẫn được giữ trong danh sách của phiên.');
      } catch (error) {
        showStudioAlert(error.message || 'Không thể bỏ ghim sản phẩm.');
      }
    }

    async function submitAddDeal(e) {
      e.preventDefault();
      const form = e.target;
      const formData = new FormData(form);
      const params = new URLSearchParams(formData);
      params.set('ajax', '1');
      params.set('csrf', CSRF_TOKEN);

      try {
        const submitButton = document.getElementById('btnAddDeal');
        submitButton.disabled = true;
        const data = await fetchStudioJson(BASE_URL + '/index.php?r=admin_live_add_deal', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: params
        });
        if (data.ok) {
          clearSelectedProduct();
          await refreshStudioProducts();
          broadcastDataChannel({type: 'products_changed'});
          showStudioNotice('Đã thêm sản phẩm vào phiên. Nguồn video hiện tại được giữ nguyên.');
        } else {
          showStudioAlert(data.message || 'Không thể thêm sản phẩm');
          submitButton.disabled = false;
        }
      } catch (e) {
        document.getElementById('btnAddDeal').disabled = false;
        showStudioAlert(e.message || 'Không thể thêm sản phẩm.');
      }
    }

    async function deleteDeal(dealId) {
      if (!confirm('Xóa deal này khỏi phiên?')) return;
      try {
        const data = await fetchStudioJson(BASE_URL + '/index.php?r=admin_live_delete_deal', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf: CSRF_TOKEN, live_id: LIVE_ID, deal_id: dealId, ajax: '1' })
        });
        if (data.ok) {
          await refreshStudioProducts();
          broadcastDataChannel({type: 'products_changed'});
        }
      } catch (e) {
        showStudioAlert(e.message || 'Không thể gỡ sản phẩm.');
      }
    }

    // ==================== LIVE VOUCHER DROPS ====================
    async function submitQuickVoucher(e) {
      e.preventDefault();
      const form = e.target;
      const params = new URLSearchParams(new FormData(form));
      params.set('room_id', LIVE_ID);
      params.set('csrf', CSRF_TOKEN);

      try {
        const res = await fetch(BASE_URL + '/index.php?r=api_live_quick_voucher', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: params
        });
        const data = await res.json();
        if (data.ok) {
          alert(data.message);
          renderActiveVoucher(data.voucher);
          broadcastDataChannel({ type: 'voucher_dropped', voucher: data.voucher });
        } else {
          showStudioAlert(data.message || 'Lỗi tạo voucher');
        }
      } catch (err) {
        showStudioAlert('Lỗi kết nối tạo voucher');
      }
    }

    async function dropExistingVoucher() {
      const code = document.getElementById('selectExistingVoucher').value;
      if (!code) return;
      try {
        const res = await fetch(BASE_URL + '/index.php?r=api_live_drop_voucher', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf: CSRF_TOKEN, room_id: LIVE_ID, ma_code: code, thoi_luong_phut: 15 })
        });
        const data = await res.json();
        if (data.ok) {
          alert(data.message);
          renderActiveVoucher(data.voucher);
          broadcastDataChannel({ type: 'voucher_dropped', voucher: data.voucher });
        }
      } catch (e) {}
    }

    async function endVoucherDrop() {
      try {
        await fetch(BASE_URL + '/index.php?r=api_live_end_voucher', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf: CSRF_TOKEN, room_id: LIVE_ID })
        });
        document.getElementById('activeVoucherBox').innerHTML = '<p class="text-muted small mb-0">Hiện chưa có voucher nào đang phát trên màn hình.</p>';
        broadcastDataChannel({ type: 'voucher_ended' });
      } catch (e) {}
    }

    function renderActiveVoucher(v) {
      const box = document.getElementById('activeVoucherBox');
      box.innerHTML = `
        <div class="p-2.5 bg-success bg-opacity-25 border border-success rounded-3 mb-2">
          <strong class="d-block text-success fs-6"><i class="bi bi-ticket-perforated-fill me-1"></i>[${escapeHtml(v.ma_code)}] ${escapeHtml(v.ten_voucher)}</strong>
          <small class="text-light d-block">Giảm: <strong>${v.loai_giam === 'percent' ? v.gia_tri_giam + '%' : Number(v.gia_tri_giam).toLocaleString('vi-VN') + ' đ'}</strong> · Hiệu lực ${v.duration_minutes || 10} phút</small>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger w-100 rounded-pill" onclick="endVoucherDrop()">
          <i class="bi bi-x-circle me-1"></i>Kết thúc phát voucher này
        </button>
      `;
    }

    // ==================== SYNA REMINDER ====================
    async function sendSynaReminder() {
      try {
        const res = await fetch(BASE_URL + '/index.php?r=api_live_syna_reminder', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ csrf: CSRF_TOKEN, room_id: LIVE_ID })
        });
        const data = await res.json();
        if (data.ok) {
          alert('Đã gửi lời nhắc hỏi SYNA vào phòng chat!');
          loadChatHistory();
        } else {
          showStudioAlert(data.message || 'Không thể gửi lời nhắc');
        }
      } catch (e) {
        showStudioAlert('Lỗi mạng gửi lời nhắc SYNA');
      }
    }

    // ==================== WEBRTC DATA CHANNEL BROADCAST ====================
    function broadcastDataChannel(msg) {
      if (!room || !room.localParticipant) return;
      try {
        const bytes = new TextEncoder().encode(JSON.stringify(msg));
        room.localParticipant.publishData(bytes, { reliable: true });
      } catch (e) {
        console.warn('broadcastDataChannel failed:', e);
      }
    }

    function handleDataChannelMessage(msg) {
      if (msg.type === 'chat_message') {
        void loadChatHistory();
      } else if (msg.type === 'chat_moderated') {
        void loadChatHistory(true);
      } else if (msg.type === 'pin_product' || msg.type === 'products_changed') {
        refreshStudioProducts();
      }
    }

    // ==================== HEARTBEAT & VIEWER POLL ====================
    function startHeartbeatPoll() {
      setInterval(async () => {
        try {
          const res = await fetch(BASE_URL + '/index.php?r=api_live_ping&id=' + encodeURIComponent(LIVE_ID));
          const data = await res.json();
          if (data.ok) {
            const count = data.active_viewers || 0;
            document.getElementById('txtViewers').textContent = count;
            document.getElementById('metricActiveViewers').textContent = count;
          }
        } catch (e) {}
      }, 5000);

      // Chat polling fallback
      setInterval(() => {
        loadChatHistory();
      }, 3000);
    }

    function showStudioAlert(text) {
      const box = document.getElementById('studioAlertBox');
      const txt = document.getElementById('studioAlertText');
      txt.textContent = text;
      box.className = 'alert alert-warning alert-dismissible fade show rounded-3 p-3 mb-3';
      box.style.display = 'block';
    }

    function showStudioNotice(text) {
      const box = document.getElementById('studioAlertBox');
      const txt = document.getElementById('studioAlertText');
      txt.textContent = text;
      box.className = 'alert alert-success alert-dismissible fade show rounded-3 p-3 mb-3';
      box.style.display = 'block';
    }

    function escapeHtml(str) {
      if (!str) return '';
      return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
  </script>
</body>
</html>
