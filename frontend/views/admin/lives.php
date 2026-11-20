<?php
// frontend/views/admin/lives.php
$pageTitle = 'Quản lý phiên phát trực tiếp & ưu đãi';
$_SESSION['live_csrf'] ??= bin2hex(random_bytes(24));
$canAdministerLive = current_role() === 'admin';
require_once __DIR__ . '/layouts/header.php';

$sessions = $lives ?? $liveSessions ?? [];
$totalLiveRooms = count($sessions);
$activeRooms = 0;
$scheduledRooms = 0;
$endedRooms = 0;
$totalViewsAcc = 0;
$totalRevenueAcc = 0;
$totalOrdersAcc = 0;
$totalUnitsAcc = 0;

foreach ($sessions as $ls) {
    $st = (string)($ls['status'] ?? $ls['trang_thai'] ?? 'scheduled');
    if ($st === 'live') {
        $activeRooms++;
    } elseif (in_array($st, ['scheduled', 'ready', 'interrupted'], true)) {
        $scheduledRooms++;
    } else {
        $endedRooms++;
    }
    $totalViewsAcc += (int)($ls['luot_xem'] ?? 0);
    $totalRevenueAcc += (float)($ls['tong_doanh_thu'] ?? 0);
    $totalOrdersAcc += (int)($ls['tong_don_hang'] ?? 0);
    $totalUnitsAcc += (int)($ls['tong_san_pham_ban'] ?? 0);
}
?>

<div class="container-fluid px-4 py-4">
  <!-- PAGE HEADER -->
  <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
    <div>
      <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);"><i class="bi bi-camera-video-fill text-danger me-2"></i>Quản lý phiên phát trực tiếp (Live Commerce)</h1>
      <p class="text-muted mb-0 small">Điều phối phòng phát LiveKit Cloud, quản lý sản phẩm ghim realtime, phát voucher và theo dõi bản ghi video.</p>
    </div>
    <div class="d-flex align-items-center gap-2">
      <?php if ($canAdministerLive): ?>
        <button type="button" class="btn btn-primary btn-sm px-3 py-2 fw-semibold text-white" style="background: #183B2B; border: none; border-radius: 6px;" onclick="switchAdminLiveTab('create')">
          <i class="bi bi-plus-lg me-1"></i>Tạo phiên phát mới
        </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($msg = get_flash('success')): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-3 p-3" role="alert">
      <i class="bi bi-check-circle-fill me-2"></i><?= h($msg) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php if ($msg = get_flash('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show rounded-3 p-3" role="alert">
      <i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($msg) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <!-- 5 CHÍNH TABS: TỔNG QUAN / DANH SÁCH / TẠO LIVE / VIDEO ĐÃ LIVE / BÁO CÁO -->
  <div class="d-flex align-items-center gap-2 mb-3 border-bottom pb-2 flex-wrap">
    <button type="button" id="tabBtn_overview" class="btn btn-sm px-3 py-2 fw-semibold tab-nav-btn text-white" style="background: #183B2B; border-radius: 6px;" onclick="switchAdminLiveTab('overview')">
      <i class="bi bi-grid-fill me-1.5"></i>1. Tổng quan
    </button>
    <button type="button" id="tabBtn_list" class="btn btn-sm btn-outline-secondary px-3 py-2 fw-semibold tab-nav-btn" style="border-radius: 6px;" onclick="switchAdminLiveTab('list')">
      <i class="bi bi-camera-video me-1.5"></i>2. Danh sách Live (<?= $totalLiveRooms ?>)
    </button>
    <button type="button" id="tabBtn_create" class="btn btn-sm btn-outline-secondary px-3 py-2 fw-semibold tab-nav-btn" style="border-radius: 6px;" onclick="switchAdminLiveTab('create')">
      <i class="bi bi-plus-circle-fill me-1.5"></i>3. Tạo Live
    </button>
    <button type="button" id="tabBtn_recordings" class="btn btn-sm btn-outline-secondary px-3 py-2 fw-semibold tab-nav-btn" style="border-radius: 6px;" onclick="switchAdminLiveTab('recordings')">
      <i class="bi bi-film me-1.5"></i>4. Video đã Live
    </button>
    <button type="button" id="tabBtn_reports" class="btn btn-sm btn-outline-secondary px-3 py-2 fw-semibold tab-nav-btn" style="border-radius: 6px;" onclick="switchAdminLiveTab('reports')">
      <i class="bi bi-bar-chart-line-fill me-1.5"></i>5. Báo cáo & Doanh thu
    </button>
  </div>

  <div id="liveAdminTabContainer">
    <!-- ==================== TAB 1: TỔNG QUAN ==================== -->
    <div id="pane_overview" class="tab-pane-content" style="display: block;">
      <!-- KPI CARDS -->
      <div class="row g-3 mb-4">
        <div class="col-md-2 col-6">
          <div class="kpi-card h-100 p-3 bg-white rounded-3 border">
            <span class="text-muted small d-block">Phiên Đang Phát</span>
            <div class="fs-4 fw-bold text-danger mt-1">
              <i class="bi bi-broadcast me-1"></i><?= $activeRooms ?>
            </div>
            <small class="text-muted extra-small">trên tổng <?= $totalLiveRooms ?> phiên</small>
          </div>
        </div>
        <div class="col-md-2 col-6">
          <div class="kpi-card h-100 p-3 bg-white rounded-3 border">
            <span class="text-muted small d-block">Sắp Diễn Ra</span>
            <div class="fs-4 fw-bold text-warning mt-1">
              <i class="bi bi-calendar-event me-1"></i><?= $scheduledRooms ?>
            </div>
            <small class="text-muted extra-small">đã lên lịch chuẩn bị</small>
          </div>
        </div>
        <div class="col-md-2 col-6">
          <div class="kpi-card h-100 p-3 bg-white rounded-3 border">
            <span class="text-muted small d-block">Đã Kết Thúc</span>
            <div class="fs-4 fw-bold text-secondary mt-1">
              <i class="bi bi-stop-circle me-1"></i><?= $endedRooms ?>
            </div>
            <small class="text-muted extra-small">có lưu trữ lịch sử</small>
          </div>
        </div>
        <div class="col-md-2 col-6">
          <div class="kpi-card h-100 p-3 bg-white rounded-3 border">
            <span class="text-muted small d-block">Tổng Lượt Xem</span>
            <div class="fs-4 fw-bold text-primary mt-1">
              <i class="bi bi-eye-fill me-1"></i><?= number_format($totalViewsAcc) ?>
            </div>
            <small class="text-muted extra-small">lượt xem tích lũy</small>
          </div>
        </div>
        <div class="col-md-2 col-6">
          <div class="kpi-card h-100 p-3 bg-white rounded-3 border">
            <span class="text-muted small d-block">Đơn Hàng Live</span>
            <div class="fs-4 fw-bold text-warning mt-1">
              <i class="bi bi-bag-check-fill me-1"></i><?= number_format($totalOrdersAcc) ?>
            </div>
            <small class="text-muted extra-small"><?= number_format($totalUnitsAcc) ?> sản phẩm bán</small>
          </div>
        </div>
        <div class="col-md-2 col-6">
          <div class="kpi-card h-100 p-3 bg-white rounded-3 border">
            <span class="text-muted small d-block">Doanh Thu Live</span>
            <div class="fs-4 fw-bold text-success mt-1">
              <i class="bi bi-cash-stack me-1"></i><?= vnd($totalRevenueAcc) ?>
            </div>
            <small class="text-muted extra-small">doanh thu xác thực</small>
          </div>
        </div>
      </div>

      <!-- BANNER TRỢ GIÚP LIVE STUDIO -->
      <div class="admin-card mb-4 p-3" style="background: #EBF2EE !important; border: 1px solid #C8DACF !important; border-radius: 8px !important;">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
          <div class="d-flex align-items-center gap-3">
            <div class="rounded-3 text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; font-size: 1.25rem; background: #183B2B;">
              <i class="bi bi-camera-reels-fill"></i>
            </div>
            <div>
              <strong class="d-block" style="font-size: 0.95rem; color: #183B2B;">Livestream</strong>
              <small class="text-muted" style="font-size: 0.82rem;">
                Livestream bán hàng trực tiếp
            </small>
            </div>
          </div>
          <button type="button" class="btn btn-sm px-3 py-2 text-white fw-bold rounded-pill" style="background: #183B2B;" onclick="switchAdminLiveTab('create')">
            <i class="bi bi-plus-lg me-1"></i>Khởi tạo phiên mới ngay
          </button>
        </div>
      </div>

      <!-- PHIÊN PHÁT GẦN ĐÂY -->
      <div class="card border-0 rounded-4 shadow-sm mb-4 border">
        <div class="card-header bg-white p-3 border-bottom d-flex align-items-center justify-content-between">
          <strong class="text-dark"><i class="bi bi-clock-history text-success me-2"></i>Phiên phát gần đây</strong>
          <button type="button" class="btn btn-link text-decoration-none btn-sm fw-semibold" onclick="switchAdminLiveTab('list')">Xem tất cả phiên &rarr;</button>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="bg-light small">
              <tr>
                <th style="width: 70px;">Mã</th>
                <th>Tiêu đề phiên</th>
                <th>Streamer</th>
                <th>Lịch phát</th>
                <th>Trạng thái</th>
                <th class="text-end">Hành động nhanh</th>
              </tr>
            </thead>
            <tbody>
              <?php $recentLives = array_slice($lives, 0, 5); ?>
              <?php foreach ($recentLives as $rl): ?>
                <?php
                  $rlStatus = (string)($rl['status'] ?? $rl['trang_thai'] ?? 'scheduled');
                  $rlBadge = 'bg-secondary';
                  $rlText = '⏹ Đã kết thúc';
                  if ($rlStatus === 'live') {
                      $rlBadge = 'bg-danger';
                      $rlText = '🔴 Đang phát';
                  } elseif (in_array($rlStatus, ['scheduled', 'ready'], true)) {
                      $rlBadge = 'bg-warning text-dark';
                      $rlText = $rlStatus === 'ready' ? '✅ Sẵn sàng' : ' Sắp diễn ra';
                  }
                ?>
                <tr>
                  <td><code>#<?= h($rl['id']) ?></code></td>
                  <td>
                    <strong class="text-dark d-block"><?= h($rl['tieu_de']) ?></strong>
                    <small class="text-muted"><?= count($rl['danh_sach_deal'] ?? []) ?> sản phẩm ưu đãi</small>
                  </td>
                  <td><span class="small fw-semibold"><?= h($rl['streamer'] ?? 'SkinSyntax') ?></span></td>
                  <td>
                    <span class="small text-muted"><i class="bi bi-calendar-event me-1"></i><?= h($rl['khung_gio_bat_dau']) ?></span>
                  </td>
                  <td><span class="badge <?= $rlBadge ?> rounded-pill px-2.5 py-1"><?= $rlText ?></span></td>
                  <td class="text-end">
                    <a href="<?= BASE_URL ?>/index.php?r=live_studio&amp;id=<?= urlencode($rl['id']) ?>" class="btn btn-sm btn-success fw-bold px-3 rounded-pill">
                      <i class="bi bi-camera-video-fill me-1"></i>Vào Studio
                    </a>
                    <a href="<?= BASE_URL ?>/index.php?r=live&amp;id=<?= urlencode($rl['id']) ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-2" target="_blank" title="Xem như khách">
                      <i class="bi bi-eye"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ==================== TAB 2: DANH SÁCH LIVE ==================== -->
    <div id="pane_list" class="tab-pane-content" style="display: none;">
      <div class="card border-0 rounded-4 shadow-sm mb-4 border">
        <div class="card-header bg-white p-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-3">
          <div class="d-flex align-items-center gap-2">
            <strong class="text-dark me-2"><i class="bi bi-broadcast me-2 text-danger"></i>Danh sách tất cả phiên phát</strong>
            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-1 fw-bold"><?= count($lives) ?> phiên</span>
          </div>

          <!-- BỘ LỌC VÀ TÌM KIẾM -->
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="input-group input-group-sm" style="width: 220px;">
              <span class="input-group-text bg-light text-muted"><i class="bi bi-search"></i></span>
              <input type="text" id="liveSearchInput" class="form-control" placeholder="Tìm tiêu đề, người dẫn..." onkeyup="filterLiveList()">
            </div>
            <select id="liveStatusFilter" class="form-select form-select-sm rounded-pill fw-bold" style="width: 175px;" onchange="filterLiveList()">
              <option value="all">🌐 Tất Cả Trạng Thái</option>
              <option value="live">🔴 Đang phát</option>
              <option value="scheduled"> Sắp Diễn Ra</option>
              <option value="ready">✅ Sẵn sàng phát</option>
              <option value="replay_ready">🎬 Có bản ghi</option>
              <option value="ended">⏹️ Đã Kết Thúc</option>
            </select>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="bg-light">
              <tr>
                <th style="width: 60px;">Mã</th>
                <th>Thumbnail & Tiêu đề</th>
                <th>Người dẫn</th>
                <th>Lịch phát</th>
                <th>Sản phẩm / Deal</th>
                <th>Người xem</th>
                <th>Recording</th>
                <th>Trạng Thái</th>
                <th class="text-end" style="min-width: 260px;">Thao Tác</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lives as $live): ?>
                <?php
                  $pinnedProduct = $live['pinned_product'] ?? null;
                  $statusClass = 'bg-secondary';
                  $statusLabel = '⏹ Kết thúc';
                  $liveStatus = (string)($live['status'] ?? $live['trang_thai'] ?? 'scheduled');
                  $displayStreamer = trim((string)preg_replace('/\s*&?\s*AI\s*Co[-\s]?Host\b/iu', '', (string)($live['streamer'] ?? '')));
                  $displayStreamer = $displayStreamer !== '' ? $displayStreamer : 'SkinSyntax';
                  if ($liveStatus === 'live') {
                      $statusClass = 'bg-danger';
                      $statusLabel = 'Đang phát';
                  } elseif (in_array($liveStatus, ['scheduled', 'ready'], true)) {
                      $statusClass = 'bg-warning text-dark';
                      $statusLabel = $liveStatus === 'ready' ? 'Sẵn sàng' : 'Đã lên lịch';
                  }

                  $thumbImg = !empty($live['thumbnail']) ? resolve_image_url($live['thumbnail']) : (!empty($pinnedProduct['link_hinh_anh']) ? resolve_image_url($pinnedProduct['link_hinh_anh']) : BASE_URL . '/assets/images/logo.png');
                  $recStatus = (string)($live['recording_status'] ?? 'not_started');
                  $hasRec = !empty($live['recording_available']);
                  $dealsCount = count($live['danh_sach_deal'] ?? []);
                ?>
                <tr class="live-table-row" data-status="<?= h($liveStatus) ?>">
                  <td><code class="fw-bold">#<?= h($live['id']) ?></code></td>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <img src="<?= h($thumbImg) ?>" class="rounded-2 border flex-shrink-0" style="width: 44px; height: 44px; object-fit: cover;" alt="Thumbnail">
                      <div>
                        <strong class="text-dark d-block" style="font-size: 0.9rem;"><?= h($live['tieu_de']) ?></strong>
                        <?php if ($hasRec): ?>
                          <small class="text-success fw-bold me-2" style="font-size: 0.75rem;"><i class="bi bi-camera-reels me-1"></i>Bản ghi R2</small>
                        <?php endif; ?>
                        <?php if (!empty($live['tom_tat_phien_live'])): ?>
                          <small class="text-muted" style="font-size: 0.75rem;"><i class="bi bi-file-text me-1"></i><?= h(mb_substr($live['tom_tat_phien_live'], 0, 40)) ?>...</small>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="fw-semibold text-dark small"><i class="bi bi-person me-1 text-muted"></i><?= h($displayStreamer) ?></span>
                  </td>
                  <td>
                    <div class="small fw-semibold text-dark"><i class="bi bi-clock me-1 text-muted"></i><?= h($live['khung_gio_bat_dau']) ?></div>
                    <small class="text-muted" style="font-size: 0.75rem;">đến <?= h($live['khung_gio_ket_thuc']) ?></small>
                  </td>
                  <td>
                    <?php if ($pinnedProduct): ?>
                      <div class="d-flex align-items-center gap-1">
                        <span class="badge bg-light text-dark border extra-small">Ghim: <?= h(mb_substr($pinnedProduct['ten_san_pham'] ?? '', 0, 20)) ?>...</span>
                      </div>
                    <?php endif; ?>
                    <small class="text-primary fw-semibold d-block mt-0.5"><i class="bi bi-cart me-1"></i><?= $dealsCount ?> sản phẩm</small>
                  </td>
                  <td>
                    <span class="small fw-bold text-dark"><i class="bi bi-eye text-primary me-1"></i><?= number_format((int)($live['luot_xem'] ?? 0)) ?></span>
                  </td>
                  <td>
                    <?php if ($hasRec): ?>
                      <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2 py-0.5 extra-small"><i class="bi bi-check-circle-fill me-1"></i>Sẵn sàng</span>
                    <?php elseif ($recStatus === 'recording'): ?>
                      <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-2 py-0.5 extra-small"><i class="bi bi-record-circle me-1"></i>Đang ghi</span>
                    <?php elseif ($recStatus === 'replay_ready'): ?>
                      <span class="badge bg-warning bg-opacity-10 text-warning-emphasis rounded-pill px-2 py-0.5 extra-small">Bản ghi không khả dụng</span>
                    <?php elseif ($recStatus === 'recording_failed'): ?>
                      <span class="badge bg-danger rounded-pill px-2 py-0.5 extra-small">Lỗi ghi</span>
                    <?php else: ?>
                      <span class="badge bg-light text-muted border rounded-pill px-2 py-0.5 extra-small">Chưa ghi</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge <?= $statusClass ?> rounded-pill px-3 py-1 fw-bold"><?= $statusLabel ?></span>
                  </td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <!-- VÀO STUDIO -->
                      <a href="<?= BASE_URL ?>/index.php?r=live_studio&amp;id=<?= urlencode($live['id']) ?>" class="btn btn-success fw-bold" title="Mở Live Studio thực tế">
                        <i class="bi bi-camera-video-fill me-1"></i>Studio
                      </a>

                      <!-- XEM TRỰC TIẾP / XEM NHƯ KHÁCH -->
                      <a href="<?= BASE_URL ?>/index.php?r=live&amp;id=<?= urlencode($live['id']) ?>" target="_blank" class="btn btn-outline-primary" title="Xem như khách hàng">
                        <i class="bi bi-broadcast"></i> Khách
                      </a>

                      <!-- QUẢN LÝ SẢN PHẨM -->
                      <button type="button" class="btn btn-outline-warning text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#dealsModal_<?= h($live['id']) ?>" title="Thêm & quản lý sản phẩm">
                        <i class="bi bi-cart-check-fill"></i> SP (<?= $dealsCount ?>)
                      </button>

                      <!-- SỬA THÔNG TIN -->
                      <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editLiveModal_<?= h($live['id']) ?>" title="Sửa thông tin phiên">
                        <i class="bi bi-pencil-square"></i>
                      </button>

                      <!-- BÁO CÁO -->
                      <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#reportModal_<?= h($live['id']) ?>" title="Báo cáo phiên">
                        <i class="bi bi-bar-chart-line"></i>
                      </button>

                      <!-- XEM LẠI BẢN GHI NẾU CÓ -->
                      <?php if ($hasRec): ?>
                        <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#recordingModal_<?= h($live['id']) ?>" title="Xem video bản ghi">
                          <i class="bi bi-film"></i>
                        </button>
                      <?php endif; ?>

                      <!-- XÓA -->
                      <?php if ($canAdministerLive): ?>
                        <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_delete" class="d-inline" onsubmit="return confirm('Bạn có chắc chắn muốn xóa phiên phát này?');">
                          <input type="hidden" name="id" value="<?= h($live['id']) ?>">
                          <input type="hidden" name="csrf" value="<?= h($_SESSION['live_csrf']) ?>">
                          <button class="btn btn-outline-danger" title="Xóa phiên"><i class="bi bi-trash"></i></button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ==================== TAB 3: TẠO LIVE (FORM CHUẨN SECTION IV) ==================== -->
    <div id="pane_create" class="tab-pane-content" style="display: none;">
      <div class="card border-0 rounded-4 shadow-sm mb-4 border">
        <div class="card-header bg-white p-3 border-bottom d-flex align-items-center justify-content-between">
          <div>
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-plus-circle-fill text-success me-2"></i>Khởi tạo phiên livestream mới</h5>
            <small class="text-muted">Không bắt buộc chọn sản phẩm ngay; bạn có thể chọn hoặc đổi sản phẩm bất cứ lúc nào trong Studio.</small>
          </div>
          <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="switchAdminLiveTab('list')">
            <i class="bi bi-arrow-left me-1"></i>Về danh sách
          </button>
        </div>

        <form method="post" enctype="multipart/form-data" action="<?= BASE_URL ?>/index.php?r=admin_live_create">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['live_csrf']) ?>">
          <div class="card-body p-4">
            <div class="row g-4">
              <!-- KHỐI 1: THÔNG TIN CƠ BẢN -->
              <div class="col-12">
                <div class="p-3 bg-light rounded-3 border">
                  <h6 class="fw-bold text-dark mb-3"><i class="bi bi-info-circle-fill text-primary me-2"></i>1. Thông tin cơ bản</h6>
                  <div class="row g-3">
                    <div class="col-md-8">
                      <label class="form-label fw-bold small">Tiêu đề phiên livestream <span class="text-danger">*</span></label>
                      <input type="text" name="tieu_de" class="form-control" placeholder="VD: Săn Deal Khung Giờ Vàng 20h - Phục hồi da B5 & Niacinamide" required>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label fw-bold small">Người dẫn / Chuyên gia</label>
                      <input type="text" name="streamer" class="form-control" placeholder="VD: DS. Minh Trang, Chuyên gia SkinSyntax..." value="DS. Minh Trang" required>
                    </div>
                    <div class="col-12">
                      <label class="form-label fw-bold small">Mô tả tóm tắt nội dung buổi phát</label>
                      <textarea name="tom_tat_phien_live" class="form-control" rows="2" placeholder="Tóm tắt nội dung tư vấn skincare, giải đáp câu hỏi và các deal độc quyền trong buổi live..."></textarea>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label fw-bold small">Ảnh bìa phiên live</label>
                      <input type="file" name="thumbnail_file" class="form-control" accept="image/jpeg,image/png,image/webp">
                      <input type="url" name="thumbnail" class="form-control mt-2" placeholder="Hoặc dán URL ảnh công khai">
                      <small class="text-muted extra-small">Có thể tải ảnh từ máy lên (JPG, PNG, WebP, tối đa 5 MB), không bắt buộc URL công khai.</small>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label fw-bold small">Người phụ trách phát</label>
                      <select name="publisher_staff_id" class="form-select">
                        <option value="">-- Chọn sau trong Studio --</option>
                        <?php foreach ((array)($staffMembers ?? []) as $staff): ?>
                          <?php $staffId = (string)($staff['ma_nv'] ?? $staff['id'] ?? $staff['username'] ?? ''); ?>
                          <option value="<?= h($staffId) ?>"><?= h($staff['ho_ten'] ?? $staff['ten_nv'] ?? $staff['username'] ?? $staffId) ?> · <?= h($staffId) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <small class="text-muted extra-small">Có thể mời thêm nhân viên trong Studio để cùng quản lý sản phẩm, chat và voucher.</small>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label fw-bold small">Hình thức phát</label>
                      <select name="hinh_thuc_phat" class="form-select">
                        <option value="schedule" selected> Lên lịch hẹn giờ</option>
                        <option value="now">🔴 Phát ngay lập tức</option>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label fw-bold small">Thời gian bắt đầu</label>
                      <input type="datetime-local" name="khung_gio_bat_dau" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label fw-bold small">Thời gian kết thúc dự kiến</label>
                      <input type="datetime-local" name="khung_gio_ket_thuc" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime('+2 hours')) ?>" required>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label fw-bold small">Quyền xem</label>
                      <select name="quyen_xem" class="form-select">
                        <option value="public" selected>🌐 Công khai (Tất cả người xem)</option>
                        <option value="private">🔒 Riêng tư (Chỉ thành viên nội bộ)</option>
                      </select>
                    </div>
                  </div>
                </div>
              </div>

              <!-- KHỐI 2: CHỌN NGUỒN PHÁT -->
              <div class="col-12">
                <div class="p-3 bg-light rounded-3 border">
                  <h6 class="fw-bold text-dark mb-3"><i class="bi bi-broadcast-pin text-success me-2"></i>2. Chọn nguồn phát sóng</h6>
                  <div class="row g-3">
                    <div class="col-md-3">
                      <label class="card p-3 h-100 border text-center cursor-pointer shadow-sm">
                        <input type="radio" name="stream_source" value="camera" class="form-check-input mx-auto mb-2" checked>
                        <strong class="d-block text-dark small"><i class="bi bi-camera-video me-1 text-danger"></i>Camera & Mic</strong>
                        <span class="text-muted extra-small">Webcam & Micro trực tiếp từ trình duyệt</span>
                      </label>
                    </div>
                    <div class="col-md-3">
                      <label class="card p-3 h-100 border text-center cursor-pointer shadow-sm">
                        <input type="radio" name="stream_source" value="video" class="form-check-input mx-auto mb-2">
                        <strong class="d-block text-dark small"><i class="bi bi-file-play me-1 text-primary"></i>Video có sẵn</strong>
                        <span class="text-muted extra-small">Phát video MP4/WebM quay sẵn vào room</span>
                      </label>
                    </div>
                    <div class="col-md-3">
                      <label class="card p-3 h-100 border text-center cursor-pointer shadow-sm">
                        <input type="radio" name="stream_source" value="ai_video" class="form-check-input mx-auto mb-2">
                        <strong class="d-block text-dark small"><i class="bi bi-robot me-1 text-success"></i>Video AI Idol</strong>
                        <span class="text-muted extra-small">Phát video Syna AI đã render sẵn</span>
                      </label>
                    </div>
                    <div class="col-md-3">
                      <label class="card p-3 h-100 border text-center cursor-pointer shadow-sm">
                        <input type="radio" name="stream_source" value="obs" class="form-check-input mx-auto mb-2">
                        <strong class="d-block text-dark small"><i class="bi bi-display me-1 text-warning"></i>OBS / Nguồn ngoài</strong>
                        <span class="text-muted extra-small">Kết nối phần mềm OBS qua LiveKit Ingress</span>
                      </label>
                    </div>
                  </div>
                </div>
              </div>

              <!-- KHỐI 3: CẤU HÌNH TƯƠNG TÁC -->
              <div class="col-12">
                <div class="p-3 bg-light rounded-3 border">
                  <h6 class="fw-bold text-dark mb-3"><i class="bi bi-chat-dots-fill text-warning me-2"></i>3. Cấu hình tương tác & Tính năng</h6>
                  <div class="row g-3">
                    <div class="col-md-6 col-lg-4">
                      <p class="small mb-0">Khách có thể xem ngay. Người xem đăng nhập để bình luận.</p>
                    </div>
                    <div class="col-md-6 col-lg-4">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="bat_ai_cohost" id="cfgSynaAi" value="1" checked>
                        <label class="form-check-label fw-bold small text-dark" for="cfgSynaAi">Kích hoạt SYNA AI Trợ lý Live</label>
                        <small class="text-muted d-block extra-small">Người xem gõ @syna để được AI tư vấn sản phẩm và thành phần da.</small>
                      </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="cho_phep_tha_tim" id="cfgHearts" value="1" checked>
                        <label class="form-check-label fw-bold small text-dark" for="cfgHearts">Cho phép thả tim / biểu cảm</label>
                        <small class="text-muted d-block extra-small">Hiệu ứng tim bay trực tiếp trên màn hình xem live.</small>
                      </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="cho_phep_voucher" id="cfgVouchers" value="1" checked>
                        <label class="form-check-label fw-bold small text-dark" for="cfgVouchers">Cho phép nhận voucher trong live</label>
                        <small class="text-muted d-block extra-small">Hiển thị thẻ popup voucher và nút "Nhận voucher" vào ví.</small>
                      </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="tu_dong_recording" id="cfgRecording" value="1" checked>
                        <label class="form-check-label fw-bold small text-dark" for="cfgRecording">Tự động ghi hình (LiveKit Egress)</label>
                        <small class="text-muted d-block extra-small">Tự động kích hoạt composite recording và lưu trữ Cloudflare R2.</small>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- KHỐI 4: SẢN PHẨM KHỞI ĐẦU (TÙY CHỌN - KHÔNG BẮT BUỘC) -->
              <div class="col-12">
                <div class="p-3 bg-light rounded-3 border">
                  <div class="d-flex align-items-center justify-content-between mb-3">
                    <h6 class="fw-bold text-dark mb-0"><i class="bi bi-bag-check-fill text-danger me-2"></i>4. Sản phẩm ghim khởi đầu <small class="text-muted fw-normal">(Không bắt buộc)</small></h6>
                    <span class="badge bg-secondary text-white rounded-pill px-2.5 py-1 extra-small">Tùy chọn</span>
                  </div>
                  <div class="row g-3">
                    <div class="col-md-8">
                      <label class="form-label fw-bold small">Chọn sản phẩm giới thiệu đầu tiên</label>
                      <select name="ma_san_pham_ghim" class="form-select">
                        <option value="">-- Để trống (Sẽ thêm sản phẩm sau trong Live Studio) --</option>
                        <?php foreach ($allProducts as $p): ?>
                          <option value="<?= h($p['ma_san_pham'] ?? $p['id'] ?? '') ?>">
                            [#<?= h($p['ma_san_pham'] ?? '') ?>] <?= h($p['ten_san_pham'] ?? '') ?> - Giá gốc: <?= vnd($p['gia_ban'] ?? 0) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <small class="text-muted extra-small">Bạn có thể tìm kiếm, ghim nhiều sản phẩm và thay đổi sản phẩm chính bất cứ lúc nào trong Live Studio mà không cần reload trang.</small>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label fw-bold small">Giá ưu đãi trong Live (VNĐ)</label>
                      <input type="number" name="gia_uu_dai_live" class="form-control" placeholder="VD: 79000 (để trống nếu chưa rõ)" min="0">
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- FOOTER ACTIONS: VỀ DANH SÁCH HOẶC VÀO STUDIO -->
          <div class="card-footer bg-white p-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
            <button type="submit" name="action" value="create_only" class="btn btn-outline-secondary px-4 fw-semibold rounded-pill">
              <i class="bi bi-list me-1"></i>Lưu & Về danh sách
            </button>
            <button type="submit" name="action" value="create_and_studio" class="btn btn-primary px-4 py-2 fw-bold rounded-pill text-white shadow-sm" style="background: #183B2B; border: none;">
              <i class="bi bi-camera-video-fill me-1.5"></i>Lưu & Mở Live Studio ngay &rarr;
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ==================== TAB 4: VIDEO ĐÃ LIVE (RECORDINGS ARCHIVE) ==================== -->
    <div id="pane_recordings" class="tab-pane-content" style="display: none;">
      <div class="card border-0 rounded-4 shadow-sm mb-4 border">
        <div class="card-header bg-white p-3 border-bottom d-flex align-items-center justify-content-between">
          <strong class="text-dark"><i class="bi bi-film text-info me-2"></i>Lịch sử bản ghi video phiên đã kết thúc</strong>
          <span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3 py-1 fw-bold">LiveKit Egress &amp; Cloudflare R2</span>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="bg-light small">
              <tr>
                <th style="width: 70px;">Mã</th>
                <th>Tên phiên phát</th>
                <th>Streamer</th>
                <th>Lịch phát</th>
                <th>Trạng thái bản ghi</th>
                <th>Dung lượng / MIME</th>
                <th class="text-end">Thao tác</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $hasRecordingsCount = 0;
                foreach ($lives as $lrec):
                  $urlRec = trim((string)($lrec['recording_url'] ?? ''));
                  $recStatus = (string)($lrec['recording_status'] ?? 'not_started');
                  $hasValidRec = !empty($lrec['recording_available']) && $urlRec !== '';
                  if (!$hasValidRec && ($lrec['status'] ?? '') !== 'ended' && ($lrec['status'] ?? '') !== 'replay_ready') continue;
                  $hasRecordingsCount++;
              ?>
                <tr>
                  <td><code>#<?= h($lrec['id']) ?></code></td>
                  <td>
                    <strong class="text-dark d-block"><?= h($lrec['tieu_de']) ?></strong>
                    <small class="text-muted">Doanh thu: <?= vnd($lrec['tong_doanh_thu'] ?? 0) ?> | <?= (int)($lrec['tong_don_hang'] ?? 0) ?> đơn hàng</small>
                  </td>
                  <td><span class="small fw-semibold"><?= h($lrec['streamer'] ?? 'SkinSyntax') ?></span></td>
                  <td>
                    <span class="small text-muted"><i class="bi bi-clock me-1"></i><?= h($lrec['khung_gio_bat_dau']) ?></span>
                  </td>
                  <td>
                    <?php if ($hasValidRec): ?>
                      <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2.5 py-1 fw-bold"><i class="bi bi-check-circle-fill me-1"></i>Sẵn sàng xem</span>
                    <?php elseif ($recStatus === 'recording' || $recStatus === 'processing'): ?>
                      <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-2.5 py-1 fw-bold"><i class="bi bi-record-circle me-1"></i>Đang xử lý</span>
                    <?php elseif ($recStatus === 'replay_ready'): ?>
                      <span class="badge bg-warning bg-opacity-10 text-warning-emphasis rounded-pill px-2.5 py-1">Bản ghi không khả dụng</span>
                    <?php else: ?>
                      <span class="badge bg-light text-muted border rounded-pill px-2.5 py-1">Không có bản ghi</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($lrec['recording_size'])): ?>
                      <span class="small text-muted"><?= round($lrec['recording_size'] / 1048576, 1) ?> MB</span>
                    <?php else: ?>
                      <span class="small text-muted">--</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <?php if ($hasValidRec): ?>
                      <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#recordingModal_<?= h($lrec['id']) ?>">
                        <i class="bi bi-play-circle-fill me-1"></i>Xem lại
                      </button>
                      <a href="<?= h($urlRec) ?>" download class="btn btn-sm btn-outline-secondary rounded-pill px-2" target="_blank" title="Tải video">
                        <i class="bi bi-download"></i>
                      </a>
                    <?php else: ?>
                      <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#recordingModal_<?= h($lrec['id']) ?>">
                        <i class="bi bi-pencil me-1"></i>Cập nhật URL
                      </button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if ($hasRecordingsCount === 0): ?>
                <tr>
                  <td colspan="7" class="text-center text-muted p-4">
                    <i class="bi bi-film fs-2 d-block mb-1 text-secondary"></i>
                    <span>Chưa có phiên phát nào hoàn tất bản ghi video. Khi phát livestream với LiveKit Egress bật, video sẽ tự động hiển thị tại đây.</span>
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ==================== TAB 5: BÁO CÁO DOANH THU & TƯƠNG TÁC ==================== -->
    <div id="pane_reports" class="tab-pane-content" style="display: none;">
      <div class="card border-0 rounded-4 shadow-sm p-4 mb-4 border">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
          <div>
            <h5 class="fw-bold text-dark mb-1"><i class="bi bi-bar-chart-line-fill text-success me-2"></i>Báo cáo tổng hợp doanh thu & hiệu quả phiên phát</h5>
            <p class="text-muted small mb-0">Theo dõi doanh thu chốt đơn theo Ngày, Tháng và phân tích hiệu suất từng phiên phát sóng.</p>
          </div>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-success btn-sm rounded-pill px-3 active btn-report-all fw-bold" onclick="filterReportTime('all', this)">📅 Tất Cả</button>
            <button type="button" class="btn btn-outline-success btn-sm rounded-pill px-3 fw-bold" onclick="filterReportTime('today', this)">☀️ Hôm Nay</button>
            <button type="button" class="btn btn-outline-success btn-sm rounded-pill px-3 fw-bold" onclick="filterReportTime('month', this)">🗓️ Tháng Này</button>
          </div>
        </div>

        <!-- HIGHLIGHT SUMMARY METRICS -->
        <div class="row g-3 mb-4">
          <div class="col-md-3">
            <div class="p-3 bg-light rounded-3 border">
              <span class="text-muted extra-small fw-bold text-uppercase d-block">Doanh Thu Trong Kỳ</span>
              <strong class="fs-5 text-success d-block mt-1" id="reportSummaryRevenue"><?= vnd($totalRevenueAcc) ?></strong>
            </div>
          </div>
          <div class="col-md-3">
            <div class="p-3 bg-light rounded-3 border">
              <span class="text-muted extra-small fw-bold text-uppercase d-block">Tổng Đơn Hàng</span>
              <strong class="fs-5 text-dark d-block mt-1" id="reportSummaryOrders"><?= number_format($totalOrdersAcc) ?> đơn</strong>
            </div>
          </div>
          <div class="col-md-3">
            <div class="p-3 bg-light rounded-3 border">
              <span class="text-muted extra-small fw-bold text-uppercase d-block">Sản Phẩm Đã Bán</span>
              <strong class="fs-5 text-warning d-block mt-1" id="reportSummaryUnits"><?= number_format($totalUnitsAcc) ?> SP</strong>
            </div>
          </div>
          <div class="col-md-3">
            <div class="p-3 bg-light rounded-3 border">
              <span class="text-muted extra-small fw-bold text-uppercase d-block">Trung Bình Mỗi Phiên</span>
              <strong class="fs-5 text-primary d-block mt-1" id="reportSummaryAvg"><?= vnd($totalLiveRooms > 0 ? round($totalRevenueAcc / $totalLiveRooms) : 0) ?></strong>
            </div>
          </div>
        </div>

        <!-- BẢNG BÁO CÁO TỪNG PHIÊN -->
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="bg-light small">
              <tr>
                <th style="width: 70px;">Mã</th>
                <th>Tiêu đề phiên</th>
                <th>Thời gian</th>
                <th class="text-center">Trạng thái</th>
                <th class="text-center">Lượt xem</th>
                <th class="text-center">Đơn hàng</th>
                <th class="text-end">Doanh thu</th>
                <th class="text-end">Chi tiết</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sessions as $ls): ?>
                <?php
                  $st = (string)($ls['status'] ?? $ls['trang_thai'] ?? '');
                  $stBadge = 'bg-secondary';
                  $stText = '⏹ Kết thúc';
                  if ($st === 'live') {
                      $stBadge = 'bg-danger';
                      $stText = '🔴 Đang phát';
                  } elseif (in_array($st, ['scheduled', 'ready'], true)) {
                      $stBadge = 'bg-warning text-dark';
                      $stText = 'Sắp diễn ra';
                  }
                  $rowDateRaw = $ls['created_at'] ?? $ls['khung_gio_bat_dau'] ?? '';
                  $rowDateTs = $rowDateRaw !== '' ? strtotime((string)$rowDateRaw) : false;
                  $rowDate = $rowDateTs !== false ? date('Y-m-d', $rowDateTs) : '';
                  $lsStreamer = trim((string)preg_replace('/\s*&?\s*AI\s*Co[-\s]?Host\b/iu', '', (string)($ls['streamer'] ?? '')));
                  $lsStreamer = $lsStreamer !== '' ? $lsStreamer : 'SkinSyntax';
                ?>
                <tr class="report-row-item" data-date="<?= $rowDate ?>" data-revenue="<?= (float)($ls['tong_doanh_thu'] ?? 0) ?>" data-orders="<?= (int)($ls['tong_don_hang'] ?? 0) ?>" data-units="<?= (int)($ls['tong_san_pham_ban'] ?? 0) ?>" data-views="<?= (int)($ls['luot_xem'] ?? 0) ?>">
                  <td><span class="badge bg-light text-dark border">#<?= h($ls['id'] ?? $ls['ma_phong'] ?? '') ?></span></td>
                  <td>
                    <strong class="text-dark d-block"><?= h($ls['title'] ?? $ls['tieu_de'] ?? '') ?></strong>
                    <small class="text-muted"><i class="bi bi-person me-1"></i><?= h($lsStreamer) ?></small>
                  </td>
                  <td>
                    <span class="extra-small text-muted d-block"><i class="bi bi-calendar3 me-1"></i><?= h($ls['khung_gio_bat_dau'] ?? 'Chưa có lịch phát') ?></span>
                  </td>
                  <td class="text-center">
                    <span class="badge <?= $stBadge ?> rounded-pill px-2.5 py-1"><?= $stText ?></span>
                  </td>
                  <td class="text-center fw-bold text-primary">
                    <?= number_format((int)($ls['luot_xem'] ?? 0)) ?>
                  </td>
                  <td class="text-center">
                    <span class="badge bg-warning text-dark rounded-pill px-2.5 py-1"><?= (int)($ls['tong_don_hang'] ?? 0) ?> đơn (<?= (int)($ls['tong_san_pham_ban'] ?? 0) ?> sp)</span>
                  </td>
                  <td class="text-end fw-bold text-success fs-6">
                    <?= vnd((float)($ls['tong_doanh_thu'] ?? 0)) ?>
                  </td>
                  <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#reportModal_<?= h($ls['id'] ?? $ls['ma_phong'] ?? '') ?>">
                      <i class="bi bi-file-earmark-text me-1"></i>Chi Tiết
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
              <tr id="reportEmptyRow" style="display: none;">
                <td colspan="8" class="text-center text-muted p-4">
                  <i class="bi bi-inbox fs-3 d-block mb-1 text-secondary"></i>
                  <span>Chưa có phiên phát nào trong khoảng thời gian này. Bấm <strong class="text-success" style="cursor: pointer;" onclick="filterReportTime('all', document.querySelector('.btn-report-all'))">'📅 Tất Cả'</strong> để xem toàn bộ lịch sử.</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ==================== CÁC MODALS CHO TỪNG PHIÊN ==================== -->
<?php foreach ($lives as $live): ?>
  <?php
    $displayStreamer = trim((string)preg_replace('/\s*&?\s*AI\s*Co[-\s]?Host\b/iu', '', (string)($live['streamer'] ?? '')));
    $displayStreamer = $displayStreamer !== '' ? $displayStreamer : 'SkinSyntax';
  ?>

  <!-- 1. MODAL CHỈNH SỬA THÔNG TIN PHIÊN LIVESTREAM -->
  <div class="modal fade text-start" id="editLiveModal_<?= h($live['id']) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content rounded-4 border-0 shadow-lg">
        <div class="modal-header border-bottom p-3 bg-light">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-warning me-2"></i>Chỉnh sửa phiên phát #<?= h($live['id']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_edit">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['live_csrf']) ?>">
          <input type="hidden" name="live_id" value="<?= h($live['id']) ?>">
          <div class="modal-body p-4">
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label fw-bold small">Tiêu đề phiên phát <span class="text-danger">*</span></label>
                <input type="text" name="tieu_de" class="form-control" value="<?= h($live['tieu_de'] ?? $live['title'] ?? '') ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold small">Người dẫn / chuyên gia <span class="text-danger">*</span></label>
                <input type="text" name="streamer" class="form-control" value="<?= h($displayStreamer) ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold small">Trạng Thái Phát Sóng</label>
                <select name="trang_thai" class="form-select">
                  <option value="scheduled" <?= in_array(($live['status'] ?? ''), ['scheduled'], true) ? 'selected' : '' ?>> Sắp diễn ra (Chuẩn bị)</option>
                  <option value="ready" <?= in_array(($live['status'] ?? ''), ['ready'], true) ? 'selected' : '' ?>>✅ Sẵn sàng phát</option>
                  <option value="ended" <?= in_array(($live['status'] ?? ''), ['ended', 'replay_ready', 'recording_failed'], true) ? 'selected' : '' ?>>⏹️ Đã kết thúc</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold small">Thời Gian Bắt Đầu Khung Giờ</label>
                <input type="datetime-local" name="khung_gio_bat_dau" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime($live['khung_gio_bat_dau'] ?? 'now')) ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold small">Thời Gian Kết Thúc Khung Giờ</label>
                <input type="datetime-local" name="khung_gio_ket_thuc" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime($live['khung_gio_ket_thuc'] ?? '+2 hours')) ?>" required>
              </div>
              <div class="col-md-8">
                <label class="form-label fw-bold small">Sản Phẩm Ghim Nổi Bật</label>
                <select name="ma_san_pham_ghim" class="form-select">
                  <option value="">-- Không ghim sản phẩm --</option>
                  <?php foreach ($allProducts as $p): ?>
                    <option value="<?= h($p['ma_san_pham'] ?? $p['id'] ?? '') ?>" <?= (($p['ma_san_pham'] ?? '') == $live['ma_san_pham_ghim']) ? 'selected' : '' ?>>
                      [<?= h($p['ma_san_pham'] ?? '') ?>] <?= h($p['ten_san_pham'] ?? '') ?> - Giá kho: <?= vnd($p['gia_ban'] ?? 0) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-bold small">Giá Ưu Đãi Trong Live (VNĐ)</label>
                <input type="number" name="gia_uu_dai_live" class="form-control" value="<?= (float)($live['gia_uu_dai_live'] ?? 0) ?>" min="0">
              </div>
              <div class="col-12">
                <label class="form-label fw-bold small">Đường dẫn video xem lại (Cloudflare R2 / S3)</label>
                <input type="url" name="url_ban_ghi" class="form-control" value="<?= h($live['url_ban_ghi'] ?? '') ?>" placeholder="Để trống nếu video được lưu từ LiveKit Egress">
              </div>
              <div class="col-12">
                <label class="form-label fw-bold small">Ghi chú nội dung buổi phát</label>
                <textarea name="tom_tat_phien_live" class="form-control" rows="3"><?= h($live['tom_tat_phien_live'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
          <div class="modal-footer border-top p-3">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn btn-warning text-dark rounded-pill px-4 fw-bold">
              <i class="bi bi-floppy-fill me-1"></i>💾 Cập nhật phiên phát
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 2. MODAL BÁO CÁO CHI TIẾT PHIÊN LIVESTREAM -->
  <div class="modal fade text-start" id="reportModal_<?= h($live['id']) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content rounded-4 border-0 shadow-lg">
        <div class="modal-header border-bottom p-3 bg-light">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-bar-chart-line-fill text-primary me-2"></i>Báo cáo hiệu quả phiên #<?= h($live['id']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
          <div class="card border-0 bg-light rounded-3 p-3 mb-4 border">
            <h6 class="fw-bold text-dark mb-1"><?= h($live['tieu_de'] ?? 'Phiên phát trực tiếp') ?></h6>
            <div class="extra-small text-secondary">
              <span><i class="bi bi-person-fill text-success me-1"></i>Người dẫn: <?= h($displayStreamer) ?></span>
              <span class="ms-3"><i class="bi bi-clock-history me-1"></i>Trạng thái: <strong class="text-uppercase"><?= h($live['status'] ?? '') ?></strong></span>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
              <div class="p-3 bg-success bg-opacity-10 rounded-3 text-center border border-success border-opacity-25">
                <span class="text-success extra-small fw-bold text-uppercase d-block">Tổng Doanh Thu</span>
                <strong class="fs-5 text-success d-block mt-1"><?= vnd($live['tong_doanh_thu'] ?? 0) ?></strong>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="p-3 bg-warning bg-opacity-10 rounded-3 text-center border border-warning border-opacity-25">
                <span class="text-dark extra-small fw-bold text-uppercase d-block">Đơn Hàng / SP Bán</span>
                <strong class="fs-5 text-dark d-block mt-1"><?= (int)($live['tong_don_hang'] ?? 0) ?> đơn <small class="fs-6 text-muted">(<?= (int)($live['tong_san_pham_ban'] ?? 0) ?> SP)</small></strong>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="p-3 bg-primary bg-opacity-10 rounded-3 text-center border border-primary border-opacity-25">
                <span class="text-primary extra-small fw-bold text-uppercase d-block">Lượt Xem / Đỉnh</span>
                <strong class="fs-5 text-primary d-block mt-1"><?= (int)($live['luot_xem'] ?? 0) ?> lượt <small class="fs-6 text-muted">(<?= (int)($live['mat_do_nguoi_xem_dinh'] ?? 0) ?> đỉnh)</small></strong>
              </div>
            </div>
            <div class="col-6 col-md-3">
              <div class="p-3 bg-danger bg-opacity-10 rounded-3 text-center border border-danger border-opacity-25">
                <span class="text-danger extra-small fw-bold text-uppercase d-block">Tỷ Lệ Chốt Đơn</span>
                <strong class="fs-5 text-danger d-block mt-1"><?= (float)($live['ty_le_chot_don'] ?? 0) ?>%</strong>
              </div>
            </div>
          </div>

          <!-- TOP SẢN PHẨM BÁN CHẠY -->
          <h6 class="fw-bold text-dark mb-3"><i class="bi bi-trophy-fill text-warning me-1"></i>Sản phẩm bán chạy nhất trong phiên:</h6>
          <?php if (!empty($live['top_san_pham'])): ?>
            <div class="table-responsive">
              <table class="table table-striped align-middle small mb-0 border">
                <thead class="bg-dark text-white">
                  <tr>
                    <th>Mã SP</th>
                    <th>Tên Sản Phẩm</th>
                    <th>Giá Live</th>
                    <th class="text-center">Số Lượng Bán</th>
                    <th class="text-end">Doanh Thu</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($live['top_san_pham'] as $topP): ?>
                    <tr>
                      <td><span class="badge bg-secondary"><?= h($topP['ma_san_pham'] ?? '') ?></span></td>
                      <td class="fw-bold text-dark"><?= h($topP['ten_san_pham'] ?? '') ?></td>
                      <td class="text-success fw-bold"><?= vnd($topP['gia_live'] ?? 0) ?></td>
                      <td class="text-center"><span class="badge bg-danger rounded-pill px-3"><?= (int)($topP['so_luong_ban'] ?? 0) ?> sp</span></td>
                      <td class="text-end fw-bold text-success"><?= vnd($topP['doanh_thu'] ?? 0) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="alert alert-light text-center border rounded-3 p-3 text-muted small">
              <i class="bi bi-info-circle me-1"></i>Chưa ghi nhận sản phẩm chốt đơn trong phiên phát sóng này.
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer border-top p-3">
          <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Đóng Báo Cáo</button>
        </div>
      </div>
    </div>
  </div>

  <!-- 3. MODAL QUẢN LÝ SẢN PHẨM TRONG PHIÊN (MULTI-PRODUCT) -->
  <div class="modal fade text-start" id="dealsModal_<?= h($live['id']) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content rounded-4 border-0 shadow-lg">
        <div class="modal-header border-bottom p-3 bg-light">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Sản phẩm và ưu đãi trong phiên #<?= h($live['id']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
          <!-- BẢNG DEAL ĐÃ ĐĂNG KÝ -->
          <h6 class="fw-bold text-dark mb-3"><i class="bi bi-list-check me-1 text-primary"></i>Danh sách sản phẩm đang bán trong phiên:</h6>
          <?php if (!empty($live['danh_sach_deal'])): ?>
            <div class="table-responsive mb-4">
              <table class="table table-bordered align-middle small">
                <thead class="bg-light text-secondary">
                  <tr>
                    <th>Mã SP</th>
                    <th>Thời gian ưu đãi</th>
                    <th>Giá Kho</th>
                    <th>Giá Live</th>
                    <th>Số lượng</th>
                    <th class="text-end">Thao Tác</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($live['danh_sach_deal'] as $dItem): ?>
                    <tr>
                      <td><span class="badge bg-secondary"><?= h($dItem['ma_san_pham']) ?></span></td>
                      <td><i class="bi bi-clock me-1 text-warning"></i><?= h($dItem['khung_gio_bat_dau'] ?? '') ?> - <?= h($dItem['khung_gio_ket_thuc'] ?? '') ?></td>
                      <td class="text-muted text-decoration-line-through"><?= vnd($dItem['gia_kho'] ?? 0) ?></td>
                      <td class="text-danger fw-bold"><?= vnd($dItem['gia_uu_dai_live'] ?? 0) ?></td>
                      <td><span class="badge bg-light text-dark border"><?= (int)($dItem['so_luong_kho_deal'] ?? 0) ?> SP</span></td>
                      <td class="text-end">
                        <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_pin_product" class="d-inline">
                          <input type="hidden" name="csrf" value="<?= h($_SESSION['live_csrf']) ?>">
                          <input type="hidden" name="live_id" value="<?= h($live['id']) ?>">
                          <input type="hidden" name="product_id" value="<?= h($dItem['ma_san_pham']) ?>">
                          <input type="hidden" name="gia_uu_dai_live" value="<?= (float)$dItem['gia_uu_dai_live'] ?>">
                          <input type="hidden" name="so_luong_kho_deal" value="<?= (int)($dItem['so_luong_kho_deal'] ?? 0) ?>">
                          <input type="hidden" name="redirect" value="1">
                          <button type="submit" class="btn btn-sm btn-success rounded-pill px-2.5 py-0.5 extra-small" title="Ghim sản phẩm này làm sản phẩm chính đang giới thiệu">
                            📌 Ghim chính
                          </button>
                        </form>
                        <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_delete_deal" class="d-inline" onsubmit="return confirm('Xóa sản phẩm này khỏi phiên phát?');">
                          <input type="hidden" name="csrf" value="<?= h($_SESSION['live_csrf']) ?>">
                          <input type="hidden" name="live_id" value="<?= h($live['id']) ?>">
                          <input type="hidden" name="deal_id" value="<?= h($dItem['deal_id']) ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle p-1" style="width: 24px; height: 24px; line-height: 1;" title="Xóa deal">
                            <i class="bi bi-x"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="alert alert-light text-center border rounded-3 p-3 text-muted small mb-4">
              <i class="bi bi-inbox d-block fs-4 text-secondary mb-1"></i>
              Chưa có sản phẩm nào được thêm vào phiên. Bạn có thể thêm sản phẩm bằng form dưới đây hoặc trong Live Studio.
            </div>
          <?php endif; ?>

          <!-- FORM THÊM DEAL MỚI VÀO PHIÊN LIVE -->
          <div class="card border-0 bg-light rounded-3 p-3 border">
            <strong class="text-dark d-block mb-2 small"><i class="bi bi-plus-circle-fill text-success me-1"></i>Thêm sản phẩm vào phiên phát</strong>
            <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_add_deal">
              <input type="hidden" name="csrf" value="<?= h($_SESSION['live_csrf']) ?>">
              <input type="hidden" name="live_id" value="<?= h($live['id']) ?>">
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label extra-small fw-bold mb-1">Chọn Sản Phẩm <span class="text-danger">*</span></label>
                  <select name="product_id" class="form-select form-select-sm" required>
                    <?php foreach ($allProducts as $p): ?>
                      <option value="<?= h($p['ma_san_pham'] ?? $p['id'] ?? '') ?>">
                        [<?= h($p['ma_san_pham'] ?? '') ?>] <?= h($p['ten_san_pham'] ?? '') ?> - Gốc: <?= vnd($p['gia_ban'] ?? 0) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label extra-small fw-bold mb-1">Giá Ưu Đãi Trong Live (VNĐ) <span class="text-danger">*</span></label>
                  <input type="number" name="gia_uu_dai_live" class="form-control form-control-sm" placeholder="Ví dụ: 78000" min="1000" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label extra-small fw-bold mb-1">Giờ Bắt Đầu Deal</label>
                  <input type="time" name="khung_gio_bat_dau" class="form-control form-control-sm" value="<?= date('H:i') ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label extra-small fw-bold mb-1">Giờ Kết Thúc Deal</label>
                  <input type="time" name="khung_gio_ket_thuc" class="form-control form-control-sm" value="<?= date('H:i', strtotime('+30 minutes')) ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label extra-small fw-bold mb-1">Số lượng tối đa</label>
                  <input type="number" name="so_luong_kho_deal" class="form-control form-control-sm" value="" min="1" placeholder="Nhập số lượng thật">
                </div>
              </div>
              <div class="text-end mt-3">
                <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold" style="background: var(--admin-primary); border: none;">
                  <i class="bi bi-plus-lg me-1"></i>Thêm vào phiên phát
                </button>
              </div>
            </form>
          </div>
        </div>
        <div class="modal-footer border-top p-3">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Đóng</button>
        </div>
      </div>
    </div>
  </div>

  <!-- 4. MODAL VIDEO XEM LẠI & BẢN GHI R2 -->
  <div class="modal fade text-start" id="recordingModal_<?= h($live['id']) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content rounded-4 border-0 shadow-lg">
        <div class="modal-header border-bottom p-3 bg-light">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-film text-info me-2"></i>Video xem lại và bản ghi - Phiên #<?= h($live['id']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_update_recording">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['live_csrf']) ?>">
          <input type="hidden" name="live_id" value="<?= h($live['id']) ?>">
          <div class="modal-body p-4">
            <?php 
              $recUrl = (string)($live['recording_url'] ?? '');
              $thumbUrl = $live['thumbnail'] ?? '';
            ?>
            <div class="mb-4 text-center bg-black rounded-3 overflow-hidden position-relative shadow-sm" style="aspect-ratio: 16/9; max-height: 280px; margin: 0 auto;">
              <?php if ($recUrl !== '' && ($live['recording_status'] ?? '') === 'replay_ready'): ?>
                <video controls class="w-100 h-100" style="object-fit: cover;" src="<?= h($recUrl) ?>" poster="<?= h($thumbUrl) ?>"></video>
              <?php else: ?>
                <div class="d-flex flex-column align-items-center justify-content-center h-100 text-white-50">
                  <i class="bi bi-camera-reels fs-2 mb-2"></i>
                  <span>Bản ghi Cloudflare R2 / S3 sẽ hiển thị tại đây khi hoàn tất xử lý.</span>
                </div>
              <?php endif; ?>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold small">URL Bản ghi Video (MP4 / HLS)</label>
              <input type="url" name="url_ban_ghi" class="form-control" value="<?= h($recUrl) ?>" placeholder="https://pub-...r2.dev/recordings/live-....mp4">
              <small class="text-muted extra-small">LiveKit Egress tự động cập nhật URL này khi kết thúc phiên ghi hình.</small>
            </div>
          </div>
          <div class="modal-footer border-top p-3 d-flex justify-content-between">
            <div>
              <?php if ($recUrl !== ''): ?>
                <a href="<?= h($recUrl) ?>" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill px-3">
                  <i class="bi bi-box-arrow-up-right me-1"></i>Mở trực tiếp
                </a>
              <?php endif; ?>
            </div>
            <div>
              <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Đóng</button>
              <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" style="background: #183B2B; border: none;">
                <i class="bi bi-floppy-fill me-1"></i>Lưu URL
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<script>
// QUẢN LÝ TAB MODULE LIVE
function switchAdminLiveTab(tabId) {
  const tabs = ['overview', 'list', 'create', 'recordings', 'reports'];
  tabs.forEach(t => {
    const pane = document.getElementById('pane_' + t);
    const btn = document.getElementById('tabBtn_' + t);
    if (pane) pane.style.display = (t === tabId) ? 'block' : 'none';
    if (btn) {
      if (t === tabId) {
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('text-white');
        btn.style.background = '#183B2B';
      } else {
        btn.classList.add('btn-outline-secondary');
        btn.classList.remove('text-white');
        btn.style.background = '';
      }
    }
  });
  try {
    history.replaceState(null, '', '#' + tabId);
  } catch (e) {}
}

// KHỞI TẠO TAB TỪ URL HASH
document.addEventListener('DOMContentLoaded', function() {
  const hash = window.location.hash.replace('#', '');
  if (['overview', 'list', 'create', 'recordings', 'reports'].includes(hash)) {
    switchAdminLiveTab(hash);
  }
});

// BỘ LỌC TÌM KIẾM DANH SÁCH LIVE
function filterLiveList() {
  const q = (document.getElementById('liveSearchInput')?.value || '').toLowerCase().trim();
  const st = document.getElementById('liveStatusFilter')?.value || 'all';

  document.querySelectorAll('.live-table-row').forEach(row => {
    const text = row.innerText.toLowerCase();
    const rowStatus = row.getAttribute('data-status') || '';

    let matchesSearch = q === '' || text.includes(q);
    let matchesStatus = st === 'all' || rowStatus === st || (st === 'live' && rowStatus === 'live');

    row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
  });
}

// BỘ LỌC THỜI GIAN BÁO CÁO
function filterReportTime(range, btn) {
  if (btn && btn.parentElement) {
    btn.parentElement.querySelectorAll('button').forEach(b => {
      b.classList.remove('active', 'btn-success');
      b.classList.add('btn-outline-success');
    });
    btn.classList.add('active', 'btn-success');
    btn.classList.remove('btn-outline-success');
  }

  const todayStr = '<?= date('Y-m-d') ?>';
  const monthStr = '<?= date('Y-m') ?>';

  let revSum = 0;
  let orderSum = 0;
  let unitSum = 0;
  let viewSum = 0;
  let countRooms = 0;

  document.querySelectorAll('.report-row-item').forEach(row => {
    const rowDate = row.getAttribute('data-date') || '';
    const rev = parseFloat(row.getAttribute('data-revenue') || '0');
    const orders = parseInt(row.getAttribute('data-orders') || '0');
    const units = parseInt(row.getAttribute('data-units') || '0');
    const views = parseInt(row.getAttribute('data-views') || '0');

    let show = false;
    if (range === 'all') {
      show = true;
    } else if (range === 'today') {
      show = (rowDate === todayStr);
    } else if (range === 'month') {
      show = rowDate.startsWith(monthStr);
    }

    if (show) {
      row.style.display = '';
      revSum += rev;
      orderSum += orders;
      unitSum += units;
      viewSum += views;
      countRooms++;
    } else {
      row.style.display = 'none';
    }
  });

  const emptyRow = document.getElementById('reportEmptyRow');
  if (emptyRow) {
    emptyRow.style.display = (countRooms === 0) ? '' : 'none';
  }

  const revEl = document.getElementById('reportSummaryRevenue');
  const orderEl = document.getElementById('reportSummaryOrders');
  const unitEl = document.getElementById('reportSummaryUnits');
  const avgEl = document.getElementById('reportSummaryAvg');

  if (revEl) revEl.textContent = new Intl.NumberFormat('vi-VN').format(revSum) + ' đ';
  if (orderEl) orderEl.textContent = new Intl.NumberFormat('vi-VN').format(orderSum) + ' đơn';
  if (unitEl) unitEl.textContent = new Intl.NumberFormat('vi-VN').format(unitSum) + ' SP';
  if (avgEl) avgEl.textContent = new Intl.NumberFormat('vi-VN').format(countRooms > 0 ? Math.round(revSum / countRooms) : 0) + ' đ';
}
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
