<?php
// backend/app/views/admin/lives.php
$pageTitle = 'Quản lý Phiên LiveStream & Giá Ưu Đãi Khung Giờ';
require_once __DIR__ . '/layouts/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h2 class="h4 fw-bold text-dark mb-1">
      <i class="bi bi-camera-video-fill text-success me-2"></i>Quản lý Phiên LiveStream & Ưu Đãi Khung Giờ
    </h2>
    <p class="text-muted small mb-0">Quản lý lịch phát sóng LiveStream trực tiếp, đổi sản phẩm ghim ưu đãi, video bản ghi xem lại & tóm tắt kịch bản AI.</p>
  </div>
  <button type="button" class="btn btn-primary rounded-pill px-4 py-2 fw-bold" style="background: var(--admin-primary); border: none;" data-bs-toggle="modal" data-bs-target="#adminCreateLiveModal">
    <i class="bi bi-plus-lg me-1"></i>Tạo Phiên Live Mới
  </button>
</div>

<?php if ($msg = get_flash('success')): ?>
  <div class="alert alert-success alert-dismissible fade show rounded-3" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i><?= h($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php if ($msg = get_flash('error')): ?>
  <div class="alert alert-danger alert-dismissible fade show rounded-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<!-- HƯỚNG DẪN TÍNH NĂNG GHIM MÃ VÀ BẢN GHI -->
<div class="alert alert-info border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center justify-content-between p-3" style="background: #E8F5E9; color: #1B5E20;">
  <div class="d-flex align-items-center gap-3">
    <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; font-size: 1.2rem;">
      <i class="bi bi-pin-angle-fill"></i>
    </div>
    <div>
      <strong class="d-block text-dark" style="font-size: 0.9rem;">Mẹo Quản Lý LiveStream & Ghim Sản Phẩm Linh Hoạt</strong>
      <small class="text-secondary" style="font-size: 0.8rem;">
        • Bạn có thể bấm nút <strong>Ghim SP</strong> trên từng phiên Live để thay đổi sản phẩm nổi bật active trong lúc đang phát live.<br>
        • Phiên Live khi kết thúc có thể đính kèm <strong>Link Video Xem Lại (Recording)</strong> và <strong>Tóm Tắt Tư Vấn AI (Transcript)</strong> cho khách hàng tra cứu.
      </small>
    </div>
  </div>
</div>

<?php
  $sessions = $lives ?? $liveSessions ?? [];
  $totalLiveRooms = count($sessions);
  $activeRooms = 0;
  $totalViewsAcc = 0;
  $totalRevenueAcc = 0;
  $totalOrdersAcc = 0;
  $totalUnitsAcc = 0;

  foreach ($sessions as $ls) {
      $st = (string)($ls['trang_thai'] ?? $ls['status'] ?? '');
      if (in_array($st, ['danglive', 'live'], true)) $activeRooms++;
      $totalViewsAcc += (int)($ls['luot_xem'] ?? 0);
      $totalRevenueAcc += (float)($ls['tong_doanh_thu'] ?? 0);
      $totalOrdersAcc += (int)($ls['tong_don_hang'] ?? 0);
      $totalUnitsAcc += (int)($ls['tong_san_pham_ban'] ?? 0);
  }
?>

<!-- NAVIGATION TABS: DANH SÁCH LIVE VS BÁO CÁO DOANH THU -->
<div class="d-flex align-items-center gap-2 mb-4 border-bottom pb-3">
  <button type="button" id="btnTabList" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm" onclick="switchAdminLiveTab('list')">
    <i class="bi bi-camera-video-fill me-2"></i>Danh Sách Phiên Live (<?= $totalLiveRooms ?>)
  </button>
  <button type="button" id="btnTabReport" class="btn btn-outline-success rounded-pill px-4 py-2 fw-bold" onclick="switchAdminLiveTab('report')">
    <i class="bi bi-bar-chart-line-fill me-2"></i>Báo Cáo Doanh Thu Ngày / Tháng
  </button>
</div>

<div id="liveAdminTabContainer">
  <!-- TAB 1: DANH SÁCH PHIÊN LIVE -->
  <div id="live-list-pane" style="display: block;">

    <!-- TỔNG QUAN BÁO CÁO HIỆU QUẢ LIVESTREAM (KPI CARDS) -->
    <div class="row g-3 mb-4">
      <div class="col-md-3">
        <div class="card border-0 rounded-4 shadow-sm p-3 bg-white border-start border-4 border-danger">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <span class="text-muted extra-small text-uppercase fw-bold">Phiên Đang Phát</span>
              <h3 class="fw-extrabold text-danger mb-0 mt-1" id="kpiActiveRooms"><?= $activeRooms ?> <small class="fs-6 text-muted">/ <?= $totalLiveRooms ?></small></h3>
            </div>
            <div class="rounded-circle bg-danger bg-opacity-10 text-danger p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
              <i class="bi bi-broadcast fs-4"></i>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card border-0 rounded-4 shadow-sm p-3 bg-white border-start border-4 border-primary">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <span class="text-muted extra-small text-uppercase fw-bold">Tổng Lượt Xem (Tích Lũy)</span>
              <h3 class="fw-extrabold text-primary mb-0 mt-1" id="kpiTotalViews" title="Tổng lượt xem tích lũy từ tất cả các phiên Live"><?= number_format($totalViewsAcc) ?> <small class="fs-6 text-muted">lượt</small></h3>
            </div>
            <div class="rounded-circle bg-primary bg-opacity-10 text-primary p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
              <i class="bi bi-eye-fill fs-4"></i>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card border-0 rounded-4 shadow-sm p-3 bg-white border-start border-4 border-success">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <span class="text-muted extra-small text-uppercase fw-bold">Tổng Doanh Thu Live</span>
              <h3 class="fw-extrabold text-success mb-0 mt-1" id="kpiTotalRevenue"><?= vnd($totalRevenueAcc) ?></h3>
            </div>
            <div class="rounded-circle bg-success bg-opacity-10 text-success p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
              <i class="bi bi-cash-stack fs-4"></i>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card border-0 rounded-4 shadow-sm p-3 bg-white border-start border-4 border-warning">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <span class="text-muted extra-small text-uppercase fw-bold">Đơn Hàng / SP Bán</span>
              <h3 class="fw-extrabold text-warning mb-0 mt-1" id="kpiTotalOrders"><?= number_format($totalOrdersAcc) ?> <small class="fs-6 text-muted">(<?= number_format($totalUnitsAcc) ?> SP)</small></h3>
            </div>
            <div class="rounded-circle bg-warning bg-opacity-10 text-warning p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
              <i class="bi bi-bag-check-fill fs-4"></i>
            </div>
          </div>
        </div>
      </div>
    </div>

<!-- DANH SÁCH CÁC PHIÊN LIVE -->
<div class="card border-0 rounded-4 shadow-sm mb-4" style="border: 1px solid var(--admin-border) !important;">
  <div class="card-header bg-white p-3 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div class="d-flex align-items-center gap-2">
      <strong class="text-dark me-2"><i class="bi bi-broadcast me-2 text-danger"></i>Danh Sách Phiên LiveStream</strong>
      <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-1 fw-bold"><?= count($lives) ?> Phiên Live</span>
    </div>

    <!-- BỘ LỌC LOẠI LIVE & TÌM KIẾM TÍCH HỢP -->
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <div class="input-group input-group-sm" style="width: 220px;">
        <span class="input-group-text bg-light text-muted"><i class="bi bi-search"></i></span>
        <input type="text" id="liveSearchInput" class="form-control" placeholder="Tìm tiêu đề, streamer..." onkeyup="filterLiveList()">
      </div>
      <select id="liveStatusFilter" class="form-select form-select-sm rounded-pill fw-bold" style="width: 175px;" onchange="filterLiveList()">
        <option value="all">🌐 Tất Cả Trạng Thái</option>
        <option value="danglive">🔴 Đang Phát Live</option>
        <option value="chuamoi"> Sắp Diễn Ra</option>
        <option value="ketthuc">⏹️ Đã Kết Thúc</option>
      </select>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="bg-light">
        <tr>
          <th style="width: 70px;">Mã</th>
          <th>Tiêu Đề Phiên Live</th>
          <th>Streamer & AI</th>
          <th>Khung Giờ Ưu Đãi</th>
          <th>Sản Phẩm Ghim Hiện Tại</th>
          <th>Giá Ưu Đãi Live</th>
          <th>Trạng Thái</th>
          <th class="text-end" style="width: 220px;">Thao Tác</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lives as $live): ?>
          <?php
            $pinnedProduct = $live['pinned_product'] ?? null;
            $statusClass = 'bg-secondary';
            $statusLabel = '⏹ Kết thúc';
            if ($live['trang_thai'] === 'danglive' || $live['trang_thai'] === 'live') {
                $statusClass = 'bg-danger';
                $statusLabel = 'Đang Live';
            } else if ($live['trang_thai'] === 'chuamoi' || $live['trang_thai'] === 'upcoming') {
                $statusClass = 'bg-warning text-dark';
                $statusLabel = 'Chuẩn bị';
            }
          ?>
          <tr class="live-table-row" data-status="<?= h($live['trang_thai']) ?>">
            <td><code class="fw-bold">#<?= h($live['id']) ?></code></td>
            <td>
              <strong class="text-dark d-block" style="font-size: 0.9rem;"><?= h($live['tieu_de']) ?></strong>
              <?php if (!empty($live['url_ban_ghi'])): ?>
                <small class="text-success fw-bold me-2" style="font-size: 0.75rem;"><i class="bi bi-camera-reels me-1"></i>Có thể xem lại</small>
              <?php endif; ?>
              <?php if (!empty($live['tom_tat_phien_live'])): ?>
                <small class="text-primary fw-bold" style="font-size: 0.75rem;"><i class="bi bi-file-text me-1"></i>Có mô tả</small>
              <?php endif; ?>
            </td>
            <td>
              <span class="fw-semibold text-dark small"><?= h($live['streamer']) ?></span>
              <?php if (!empty($live['bat_ai_cohost'])): ?>
                <span class="badge bg-success bg-opacity-10 text-success rounded-pill extra-small ms-1"><i class="bi bi-robot me-1"></i>AI Co-Host</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="small fw-semibold text-dark"><i class="bi bi-clock me-1 text-muted"></i><?= h($live['khung_gio_bat_dau']) ?></div>
              <small class="text-muted" style="font-size: 0.75rem;">đến <?= h($live['khung_gio_ket_thuc']) ?></small>
            </td>
            <td>
              <?php if ($pinnedProduct): ?>
                <div class="d-flex align-items-center gap-2">
                  <img src="<?= h(resolve_image_url($pinnedProduct['link_hinh_anh'] ?? '')) ?>" class="rounded-2" style="width: 38px; height: 38px; object-fit: cover;">
                  <div style="line-height: 1.2;">
                    <div class="small fw-bold text-dark text-truncate" style="max-width: 160px;"><?= h($pinnedProduct['ten_san_pham'] ?? '') ?></div>
                    <small class="text-muted" style="font-size: 0.7rem;">Mã SP: <?= h($live['ma_san_pham_ghim']) ?></small>
                  </div>
                </div>
              <?php else: ?>
                <span class="text-muted small">Mã SP: <?= h($live['ma_san_pham_ghim']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <strong class="text-success" style="color: var(--admin-primary) !important;"><?= vnd($live['gia_uu_dai_live']) ?></strong>
            </td>
            <td>
              <span class="badge <?= $statusClass ?> rounded-pill px-3 py-1 fw-bold"><?= $statusLabel ?></span>
            </td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-secondary fw-bold" data-bs-toggle="modal" data-bs-target="#editLiveModal_<?= h($live['id']) ?>" title="Chỉnh sửa thông tin tiêu đề, streamer phiên Live">
                  <i class="bi bi-pencil-square"></i> Sửa
                </button>
                <button type="button" class="btn btn-outline-primary fw-bold" data-bs-toggle="modal" data-bs-target="#reportModal_<?= h($live['id']) ?>" title="Xem báo cáo chi tiết doanh thu & chốt đơn">
                  <i class="bi bi-bar-chart-line-fill"></i> Báo Cáo
                </button>
                <button type="button" class="btn btn-outline-warning text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#dealsModal_<?= h($live['id']) ?>" title="Quản lý danh sách Deals & Khung giờ giảm giá từng SP">
                  <i class="bi bi-cart-check-fill"></i> Deals (<?= count($live['danh_sach_deal'] ?? []) ?>)
                </button>
                <button type="button" class="btn btn-outline-success fw-bold" data-bs-toggle="modal" data-bs-target="#pinProductModal_<?= h($live['id']) ?>" title="Đổi ghim sản phẩm nổi bật mới">
                  <i class="bi bi-pin-angle-fill"></i> Ghim SP
                </button>
                <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#recordingModal_<?= h($live['id']) ?>" title="Xem bản ghi & transcript">
                  <i class="bi bi-film"></i> Bản Ghi
                </button>
                <?php if ($live['trang_thai'] === 'danglive' || $live['trang_thai'] === 'live'): ?>
                  <a href="<?= BASE_URL ?>/index.php?r=admin_live_status&id=<?= urlencode($live['id']) ?>&status=ketthuc" class="btn btn-outline-danger" title="Dừng & Kết thúc vĩnh viễn phiên Live" onclick="return confirm('Dừng & kết thúc vĩnh viễn phiên LiveStream này? Phiên sẽ chuyển sang bản ghi và không thể phát lại.');">
                    <i class="bi bi-stop-fill"></i> Dừng Live
                  </a>
                <?php elseif ($live['trang_thai'] === 'chuamoi' || $live['trang_thai'] === 'upcoming'): ?>
                  <a href="<?= BASE_URL ?>/index.php?r=admin_live_status&id=<?= urlencode($live['id']) ?>&status=danglive" class="btn btn-outline-success" title="Bắt đầu phát Live">
                    <i class="bi bi-play-fill"></i> Phát Live
                  </a>
                <?php else: ?>
                  <button type="button" class="btn btn-outline-secondary disabled" title="Phiên đã kết thúc & lưu bản ghi (Không thể bắt đầu lại)" disabled>
                    <i class="bi bi-lock-fill"></i> Đã Kết Thúc
                  </button>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/index.php?r=admin_live_delete&id=<?= urlencode($live['id']) ?>" class="btn btn-outline-danger" onclick="return confirm('Bạn có chắc chắn muốn xóa phiên LiveStream này?');" title="Xóa">
                  <i class="bi bi-trash-fill"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</div> <!-- END TAB 1 -->

<!-- TAB 2: BÁO CÁO DOANH THU LIVESTREAM RIÊNG BIỆT (DEDICATED LIVESTREAM REPORT & ANALYTICS) -->
<div id="live-report-pane" style="display: none;">
  <div class="card border-0 rounded-4 shadow-sm p-4 mb-4" style="border: 1px solid var(--admin-border) !important;">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
      <div>
        <h5 class="fw-bold text-dark mb-1"><i class="bi bi-bar-chart-line-fill text-success me-2"></i>Báo Cáo Tổng Hợp Doanh Thu & Hiệu Quả LiveStream</h5>
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
        <div class="p-3 bg-light rounded-3 border border-success border-2 text-center">
          <span class="text-secondary extra-small fw-bold text-uppercase d-block mb-1">Doanh Thu Tích Lũy</span>
          <h4 class="fw-extrabold text-success mb-0" id="reportSummaryRevenue"><?= vnd($totalRevenueAcc) ?></h4>
        </div>
      </div>
      <div class="col-md-3">
        <div class="p-3 bg-light rounded-3 border text-center">
          <span class="text-secondary extra-small fw-bold text-uppercase d-block mb-1">Tổng Số Đơn Live</span>
          <h4 class="fw-bold text-dark mb-0" id="reportSummaryOrders"><?= number_format($totalOrdersAcc) ?> đơn</h4>
        </div>
      </div>
      <div class="col-md-3">
        <div class="p-3 bg-light rounded-3 border text-center">
          <span class="text-secondary extra-small fw-bold text-uppercase d-block mb-1">Sản Phẩm Đã Bán</span>
          <h4 class="fw-bold text-warning mb-0" id="reportSummaryUnits"><?= number_format($totalUnitsAcc) ?> SP</h4>
        </div>
      </div>
      <div class="col-md-3">
        <div class="p-3 bg-light rounded-3 border text-center">
          <span class="text-secondary extra-small fw-bold text-uppercase d-block mb-1">Doanh Thu TB / Phiên</span>
          <h4 class="fw-bold text-primary mb-0" id="reportSummaryAvg"><?= vnd($totalLiveRooms > 0 ? $totalRevenueAcc / $totalLiveRooms : 0) ?></h4>
        </div>
      </div>
    </div>

    <!-- BẢNG CHI TIẾT BÁO CÁO PHIÊN LIVE -->
    <div class="table-responsive">
      <table class="table table-hover align-middle small mb-0 border rounded-3">
        <thead class="bg-light text-secondary">
          <tr>
            <th>Mã Phiên</th>
            <th>Tiêu Đề & Streamer</th>
            <th>Khung Giờ Phát</th>
            <th class="text-center">Trạng Thái</th>
            <th class="text-center">Lượt Xem</th>
            <th class="text-center">Đơn Hàng / SP</th>
            <th class="text-end">Doanh Thu VNĐ</th>
            <th class="text-end">Báo Cáo</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sessions as $ls): ?>
            <?php 
              $lsSt = (string)($ls['trang_thai'] ?? $ls['status'] ?? '');
              $stBadge = ($lsSt === 'danglive' || $lsSt === 'live') ? 'bg-danger' : (($lsSt === 'chuamoi' || $lsSt === 'upcoming') ? 'bg-warning text-dark' : 'bg-secondary');
              $stText = ($lsSt === 'danglive' || $lsSt === 'live') ? 'Đang Live' : (($lsSt === 'chuamoi' || $lsSt === 'upcoming') ? ' Sắp diễn ra' : '⏹️ Đã kết thúc');
              $rowDate = date('Y-m-d', strtotime($ls['created_at'] ?? $ls['khung_gio_bat_dau'] ?? 'now'));
            ?>
            <tr class="report-row-item" data-date="<?= $rowDate ?>" data-revenue="<?= (float)($ls['tong_doanh_thu'] ?? 0) ?>" data-orders="<?= (int)($ls['tong_don_hang'] ?? 0) ?>" data-units="<?= (int)($ls['tong_san_pham_ban'] ?? 0) ?>" data-views="<?= (int)($ls['luot_xem'] ?? 0) ?>">
              <td><span class="badge bg-light text-dark border">#<?= h($ls['id'] ?? $ls['ma_phong'] ?? '') ?></span></td>
              <td>
                <strong class="text-dark d-block"><?= h($ls['title'] ?? $ls['tieu_de'] ?? '') ?></strong>
                <small class="text-muted"><i class="bi bi-person me-1"></i><?= h($ls['streamer'] ?? '') ?></small>
              </td>
              <td>
                <span class="extra-small text-muted d-block"><i class="bi bi-calendar3 me-1"></i><?= h($ls['khung_gio_bat_dau'] ?? date('Y-m-d H:i')) ?></span>
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
              <td class="text-end fw-extrabold text-success fs-6">
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
              <span>Chưa có phiên LiveStream nào trong khoảng thời gian này. Bấm <strong class="text-success" style="cursor: pointer;" onclick="filterReportTime('all', document.querySelector('.btn-report-all'))">'📅 Tất Cả'</strong> để xem toàn bộ lịch sử.</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div> <!-- END TAB 2 -->
</div> <!-- END TAB CONTENT -->

<?php foreach ($lives as $live): ?>
  <!-- 1. MODAL CHỈNH SỬA THÔNG TIN PHIÊN LIVESTREAM -->
  <div class="modal fade text-start" id="editLiveModal_<?= h($live['id']) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content rounded-4 border-0 shadow-lg">
        <div class="modal-header border-bottom p-3 bg-light">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-warning me-2"></i>Chỉnh Sửa Thông Tin Phiên LiveStream #<?= h($live['id']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_edit">
          <input type="hidden" name="live_id" value="<?= h($live['id']) ?>">
          <div class="modal-body p-4">
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label fw-bold small">Tiêu Đề Phiên LiveStream <span class="text-danger">*</span></label>
                <input type="text" name="tieu_de" class="form-control" value="<?= h($live['tieu_de'] ?? $live['title'] ?? '') ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold small">Tên Streamer & Bác Sĩ Đảm Nhận <span class="text-danger">*</span></label>
                <input type="text" name="streamer" class="form-control" value="<?= h($live['streamer'] ?? '') ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold small">Trạng Thái Phát Sóng</label>
                <select name="trang_thai" class="form-select">
                  <option value="chuamoi" <?= (in_array($live['trang_thai'], ['chuamoi', 'upcoming'], true)) ? 'selected' : '' ?>> Sắp diễn ra (Chuẩn bị)</option>
                  <option value="danglive" <?= (in_array($live['trang_thai'], ['danglive', 'live'], true)) ? 'selected' : '' ?>>🔴 Đang phát Live (Active)</option>
                  <option value="ketthuc" <?= (in_array($live['trang_thai'], ['ketthuc', 'ended'], true)) ? 'selected' : '' ?>>⏹️ Đã kết thúc (Lưu bản ghi)</option>
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
                <label class="form-label fw-bold small">Sản Phẩm Ghim Nổi Bật <span class="text-danger">*</span></label>
                <select name="ma_san_pham_ghim" class="form-select" required>
                  <?php foreach ($allProducts as $p): ?>
                    <option value="<?= h($p['ma_san_pham'] ?? $p['id'] ?? '') ?>" <?= (($p['ma_san_pham'] ?? '') == $live['ma_san_pham_ghim']) ? 'selected' : '' ?>>
                      [<?= h($p['ma_san_pham'] ?? '') ?>] <?= h($p['ten_san_pham'] ?? '') ?> - Giá kho: <?= vnd($p['gia_ban'] ?? 0) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-bold small">Giá Ưu Đãi Trong Live (VNĐ) <span class="text-danger">*</span></label>
                <input type="number" name="gia_uu_dai_live" class="form-control" value="<?= (float)($live['gia_uu_dai_live'] ?? 0) ?>" min="1000" required>
              </div>
              <div class="col-12">
                <label class="form-label fw-bold small">Máy Chủ LiveKit (Server URL)</label>
                <input type="text" name="server_livekit_url" class="form-control" value="<?= h($live['server_livekit_url'] ?? 'wss://skinsyntax-live.livekit.cloud') ?>" required>
              </div>
              <div class="col-12">
                <label class="form-label fw-bold small">Link Video Bản Ghi Xem Lại (Replay URL)</label>
                <input type="url" name="url_ban_ghi" class="form-control" value="<?= h($live['url_ban_ghi'] ?? '') ?>" placeholder="https://domain.com/recording.mp4">
              </div>
              <div class="col-12">
                <label class="form-label fw-bold small">Tóm Tắt Kịch Bản & Lời Khuyên AI (Transcript Summary)</label>
                <textarea name="tom_tat_phien_live" class="form-control" rows="3"><?= h($live['tom_tat_phien_live'] ?? '') ?></textarea>
              </div>
              <div class="col-12">
                <div class="form-check form-switch mt-1">
                  <input class="form-check-input" type="checkbox" name="bat_ai_cohost" id="editAiSwitch_<?= h($live['id']) ?>" value="1" <?= !empty($live['bat_ai_cohost']) ? 'checked' : '' ?>>
                  <label class="form-check-label fw-bold small" for="editAiSwitch_<?= h($live['id']) ?>">Bật AI Agent Co-Host (Tự động tư vấn RAG & Chốt đơn)</label>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer border-top p-3">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn btn-warning text-dark rounded-pill px-4 fw-bold">
              <i class="bi bi-floppy-fill me-1"></i>💾 Cập Nhật Phiên Live
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 2. MODAL BÁO CÁO CHI TIẾT PHIÊN LIVESTREAM (ANALYTICS & REVENUE REPORT) -->
  <div class="modal fade text-start" id="reportModal_<?= h($live['id']) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content rounded-4 border-0 shadow-lg">
        <div class="modal-header border-bottom p-3 bg-light">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-bar-chart-line-fill text-primary me-2"></i>Báo Cáo Hiệu Quả & Doanh Thu Phiên LiveStream #<?= h($live['id']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
          <!-- PHẦN ĐẦU THÔNG TIN PHIÊN -->
          <div class="card border-0 bg-light rounded-3 p-3 mb-4 border">
            <h6 class="fw-bold text-dark mb-1"><?= h($live['tieu_de'] ?? $live['title'] ?? 'Phiên LiveStream AI') ?></h6>
            <div class="extra-small text-secondary">
              <span><i class="bi bi-person-fill text-success me-1"></i>Streamer: <?= h($live['streamer'] ?? 'SkinSyntax Streamer') ?></span>
              <span class="ms-3"><i class="bi bi-clock-history me-1"></i>Trạng thái: <strong class="text-uppercase"><?= h($live['trang_thai'] ?? 'danglive') ?></strong></span>
            </div>
          </div>

          <!-- 4 THẺ CHỈ SỐ KPI CHÍNH -->
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
                <span class="text-danger extra-small fw-bold text-uppercase d-block">Tỷ Lệ Chốt Đơn (CR)</span>
                <strong class="fs-5 text-danger d-block mt-1"><?= (float)($live['ty_le_chot_don'] ?? 0) ?>%</strong>
              </div>
            </div>
          </div>

          <!-- BẢNG TOP SẢN PHẨM BÁN CHẠY NHẤT TRONG PHIÊN LIVE -->
          <h6 class="fw-bold text-dark mb-3"><i class="bi bi-trophy-fill text-warning me-1"></i>Bảng Sản Phẩm Bán Chạy Nhất Trong LiveStream:</h6>
          <?php if (!empty($live['top_san_pham'])): ?>
            <div class="table-responsive">
              <table class="table table-striped align-middle small mb-0 border">
                <thead class="bg-dark text-white">
                  <tr>
                    <th>Mã SP</th>
                    <th>Tên Sản Phẩm</th>
                    <th>Giá Ưu Đãi Live</th>
                    <th class="text-center">Số Lượng Bán</th>
                    <th class="text-end">Doanh Thu Tạo Ra</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($live['top_san_pham'] as $topP): ?>
                    <tr>
                      <td><span class="badge bg-secondary"><?= h($topP['ma_san_pham'] ?? '') ?></span></td>
                      <td class="fw-bold text-dark"><?= h($topP['ten_san_pham'] ?? '') ?></td>
                      <td class="text-success fw-bold"><?= vnd($topP['gia_live'] ?? 0) ?></td>
                      <td class="text-center"><span class="badge bg-danger rounded-pill px-3"><?= (int)($topP['so_luong_ban'] ?? 0) ?> sp</span></td>
                      <td class="text-end fw-extrabold text-success"><?= vnd($topP['doanh_thu'] ?? 0) ?></td>
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

  <!-- 3. MODAL QUẢN LÝ DANH SÁCH DEALS & LỊCH GIẢM GIÁ TỪNG SP -->
  <div class="modal fade text-start" id="dealsModal_<?= h($live['id']) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content rounded-4 border-0 shadow-lg">
        <div class="modal-header border-bottom p-3 bg-light">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-lightning-charge-fill text-warning me-2"></i>Danh Sách Deal & Lịch Giảm Giá Nổi Bật Phiên Live #<?= h($live['id']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
          <!-- BẢNG DEAL ĐÃ ĐĂNG KÝ -->
          <h6 class="fw-bold text-dark mb-3"><i class="bi bi-list-check me-1 text-primary"></i>Các Sản Phẩm Giảm Giá Đã Lên Lịch Trong Live:</h6>
          <?php if (!empty($live['danh_sach_deal'])): ?>
            <div class="table-responsive mb-4">
              <table class="table table-bordered align-middle small">
                <thead class="bg-light text-secondary">
                  <tr>
                    <th>Mã SP</th>
                    <th>Khung Giờ Flash Deal</th>
                    <th>Giá Kho</th>
                    <th>Giá Ưu Đãi Live</th>
                    <th>Kho Deal</th>
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
                          <input type="hidden" name="live_id" value="<?= h($live['id']) ?>">
                          <input type="hidden" name="product_id" value="<?= h($dItem['ma_san_pham']) ?>">
                          <input type="hidden" name="gia_uu_dai_live" value="<?= (float)$dItem['gia_uu_dai_live'] ?>">
                          <input type="hidden" name="redirect" value="1">
                          <button type="submit" class="btn btn-sm btn-success rounded-pill px-2 py-0.5" style="font-size: 0.75rem;" title="Kích hoạt ghim deal ngay">
                            📌 Ghim Deal Ngay
                          </button>
                        </form>
                        <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_delete_deal" class="d-inline" onsubmit="return confirm('Xóa deal này khỏi phiên live?');">
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
              Chưa có deal sản phẩm riêng được lên lịch. Bạn có thể thêm sản phẩm giảm giá bên dưới!
            </div>
          <?php endif; ?>

          <!-- FORM THÊM DEAL MỚI VÀO PHIÊN LIVE -->
          <div class="card border-0 bg-light rounded-3 p-3 border">
            <strong class="text-dark d-block mb-2 small"><i class="bi bi-plus-circle-fill text-success me-1"></i>➕ Thêm Sản Phẩm Deal Mới Vào Phiên Live</strong>
            <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_add_deal">
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
                  <label class="form-label extra-small fw-bold mb-1">Số Lượng Kho Deal</label>
                  <input type="number" name="so_luong_kho_deal" class="form-control form-control-sm" value="20" min="1">
                </div>
              </div>
              <div class="text-end mt-3">
                <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold" style="background: var(--admin-primary); border: none;">
                  <i class="bi bi-plus-lg me-1"></i>Thêm Lịch Deal SP Này
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

  <!-- 4. MODAL GHIM SẢN PHẨM MỚI KÈM THỜI GIAN ĐẾM NGƯỢC -->
  <div class="modal fade text-start" id="pinProductModal_<?= h($live['id']) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rounded-4 border-0 shadow-lg">
        <div class="modal-header border-bottom p-3">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pin-angle-fill text-danger me-2"></i>📌 Ghim Sản Phẩm & Kích Hoạt Deal Trực Tiếp</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_pin_product">
          <input type="hidden" name="live_id" value="<?= h($live['id']) ?>">
          <input type="hidden" name="redirect" value="1">
          <div class="modal-body p-4">
            <div class="mb-3">
              <label class="form-label fw-bold small">Chọn Sản Phẩm Cần Ghim Trực Tiếp <span class="text-danger">*</span></label>
              <select name="product_id" class="form-select" required>
                <?php foreach ($allProducts as $p): ?>
                  <option value="<?= h($p['ma_san_pham'] ?? $p['id'] ?? '') ?>" <?= ($p['ma_san_pham'] == $live['ma_san_pham_ghim']) ? 'selected' : '' ?>>
                    [<?= h($p['ma_san_pham'] ?? '') ?>] <?= h($p['ten_san_pham'] ?? '') ?> - Giá gốc: <?= vnd($p['gia_ban'] ?? 0) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted d-block mt-1">Khi bấm Xác Nhận, sản phẩm này sẽ được ghim nổi bật kèm đếm ngược Flash Deal trên màn hình người xem.</small>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold small">Giá Ưu Đãi Trực Tiếp Trong Live (VNĐ) <span class="text-danger">*</span></label>
              <input type="number" name="gia_uu_dai_live" class="form-control" value="<?= (float)$live['gia_uu_dai_live'] ?>" min="1000" required>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold small">Thời Gian Giảm Giá Flash Deal Đếm Ngược <span class="text-danger">*</span></label>
              <select name="duration_minutes" class="form-select">
                <option value="10"> 10 Phút (Flash Sale Siêu Ngắn)</option>
                <option value="15" selected> 15 Phút (Khung Deal Chuẩn TikTok Shop)</option>
                <option value="30"> 30 Phút (Flash Sale Khung Giờ Standard)</option>
                <option value="60"> 60 Phút (Ưu Đãi Suốt Buổi Live)</option>
              </select>
            </div>
          </div>
          <div class="modal-footer border-top p-3">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">
              <i class="bi bi-lightning-charge-fill me-1"></i>📌 Ghim & Bật Deal Ngay
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 5. MODAL BẢN GHI & AI TRANSCRIPT -->
  <div class="modal fade text-start" id="recordingModal_<?= h($live['id']) ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content rounded-4 border-0 shadow-lg">
        <div class="modal-header border-bottom p-3 bg-light">
          <h5 class="modal-title fw-bold text-dark"><i class="bi bi-film text-info me-2"></i>Bản Ghi Video Xem Lại & Tóm Tắt Kịch Bản AI - Phiên #<?= h($live['id']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_update_recording">
          <input type="hidden" name="live_id" value="<?= h($live['id']) ?>">
          <div class="modal-body p-4">
            <?php 
              $recUrl = !empty($live['url_ban_ghi']) ? $live['url_ban_ghi'] : 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4';
              $thumbUrl = $live['thumbnail'] ?? '';
            ?>
            
            <!-- VIDEO REPLAY PREVIEW PLAYER INSIDE MODAL -->
            <div class="mb-4 text-center bg-black rounded-3 overflow-hidden position-relative shadow-sm" style="aspect-ratio: 16/9; max-height: 280px; margin: 0 auto;">
              <video controls class="w-100 h-100" style="object-fit: cover;" src="<?= h($recUrl) ?>" poster="<?= h($thumbUrl) ?>"></video>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold small">Link Video Xem Lại (Recording Replay URL) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text bg-light text-secondary"><i class="bi bi-link-45deg"></i></span>
                <input type="text" name="url_ban_ghi" class="form-control" value="<?= h($recUrl) ?>" placeholder="Nhập link video MP4/HLS phát lại..." required>
              </div>
              <small class="text-muted d-block mt-1">Đã tự động gán video bản ghi mẫu. Bạn có thể sửa thành link video phát lại tùy chỉnh.</small>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold small">Mô tả</label>
              <textarea name="tom_tat_phien_live" class="form-control" rows="4" placeholder="Nhập mô tả."><?= h($live['tom_tat_phien_live'] ?: "📌 Tóm tắt phiên Live:\n- Phiên tư vấn giải đáp routine da chuẩn y khoa từ Bác sĩ/Dược sĩ.\n- Đã hỗ trợ tự động giải đáp 100+ câu hỏi và hỗ trợ khách hàng chốt đơn ưu đãi.") ?></textarea>
            </div>
          </div>
          <div class="modal-footer border-top p-3">
            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Hủy</button>
            <button type="submit" class="btn btn-success rounded-pill px-4 fw-bold">
              <i class="bi bi-floppy-fill me-1"></i>💾 Lưu Link Bản Ghi & Transcript
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<!-- MODAL TẠO PHIÊN LIVE MỚI DÀNH CHO ADMIN -->
<div class="modal fade" id="adminCreateLiveModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content rounded-4 border-0 shadow-lg">
      <div class="modal-header border-bottom p-3">
        <h5 class="modal-title fw-bold text-dark"><i class="bi bi-camera-video-fill text-success me-2"></i>Khởi Tạo Phiên LiveStream Mới</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="<?= BASE_URL ?>/index.php?r=admin_live_create">
        <div class="modal-body p-4">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-bold small">Tiêu Đề Phiên LiveStream <span class="text-danger">*</span></label>
              <input type="text" name="tieu_de" class="form-control" placeholder="VD: Săn Sale Khung Giờ Vàng 19h - Niacinamide & B5..." required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Tên Streamer & Bác Sĩ Đảm Nhận</label>
              <input type="text" name="streamer" class="form-control" value="DS. Minh Trang & AI Co-Host" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Máy Chủ LiveStream</label>
              <input type="text" name="server_livekit_url" class="form-control" value="wss://skinsyntax-live.livekit.cloud" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Thời Gian Bắt Đầu Khung Giờ Ưu Đãi</label>
              <input type="datetime-local" name="khung_gio_bat_dau" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Thời Gian Kết Thúc Khung Giờ</label>
              <input type="datetime-local" name="khung_gio_ket_thuc" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime('+2 hours')) ?>" required>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-bold small">Sản Phẩm Ghim Nổi Bật Ban Đầu <span class="text-danger">*</span></label>
              <select name="ma_san_pham_ghim" class="form-select" required>
                <?php foreach ($allProducts as $p): ?>
                  <option value="<?= h($p['ma_san_pham'] ?? $p['id'] ?? '') ?>">
                    [<?= h($p['ma_san_pham'] ?? '') ?>] <?= h($p['ten_san_pham'] ?? '') ?> - Giá kho: <?= vnd($p['gia_ban'] ?? 0) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-bold small">Giá Ưu Đãi Trong Live (VNĐ) <span class="text-danger">*</span></label>
              <input type="number" name="gia_uu_dai_live" class="form-control" placeholder="VD: 78000" min="1000" required>
            </div>
            <div class="col-12">
              <label class="form-label fw-bold small">Link Video Bản Ghi Xem Lại (Tùy chọn cho phiên xem lại)</label>
              <input type="url" name="url_ban_ghi" class="form-control" placeholder="https://domain.com/recording-video.mp4">
            </div>
            <div class="col-12">
              <label class="form-label fw-bold small">Kịch Bản & Tóm Tắt Tư Vấn AI (Transcript Summary)</label>
              <textarea name="tom_tat_phien_live" class="form-control" rows="3" placeholder="Tóm tắt lời khuyên skincare, giải đáp câu hỏi và ưu đãi trong phiên..."></textarea>
            </div>
            <div class="col-12">
              <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" name="bat_ai_cohost" id="adminAiSwitch" value="1" checked>
                <label class="form-check-label fw-bold small" for="adminAiSwitch">Bật AI Agent Co-Host (Tự động tư vấn RAG & Chốt đơn khi khán giả gõ 'chốt đơn')</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer border-top p-3">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Hủy</button>
          <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold" style="background: var(--admin-primary); border: none;">
            🚀 Lưu & Khởi Tạo Phiên Live
          </button>
        </div>
      </form>
    </div>
  </div>
<script>
function switchAdminLiveTab(tabName) {
  const paneList = document.getElementById('live-list-pane');
  const paneReport = document.getElementById('live-report-pane');
  const btnList = document.getElementById('btnTabList');
  const btnReport = document.getElementById('btnTabReport');

  if (tabName === 'report') {
    if (paneList) paneList.style.display = 'none';
    if (paneReport) paneReport.style.display = 'block';
    
    if (btnList) {
      btnList.className = 'btn btn-outline-primary rounded-pill px-4 py-2 fw-bold';
    }
    if (btnReport) {
      btnReport.className = 'btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm';
    }
    const allBtn = document.querySelector('.btn-report-all');
    if (typeof filterReportTime === 'function') {
      filterReportTime('all', allBtn);
    }
  } else {
    if (paneList) paneList.style.display = 'block';
    if (paneReport) paneReport.style.display = 'none';

    if (btnList) {
      btnList.className = 'btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm';
    }
    if (btnReport) {
      btnReport.className = 'btn btn-outline-success rounded-pill px-4 py-2 fw-bold';
    }
  }
}

function filterLiveList() {
  const q = (document.getElementById('liveSearchInput')?.value || '').toLowerCase().trim();
  const st = document.getElementById('liveStatusFilter')?.value || 'all';

  document.querySelectorAll('.live-table-row').forEach(row => {
    const text = row.textContent.toLowerCase();
    const rowStatus = row.getAttribute('data-status') || '';

    let matchesSearch = q === '' || text.includes(q);
    let matchesStatus = st === 'all' || rowStatus === st || (st === 'danglive' && rowStatus === 'live') || (st === 'chuamoi' && rowStatus === 'upcoming');

    row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
  });
}

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

  // Đồng bộ thời gian thực các thẻ KPI Tổng Quan trên đầu trang
  const kpiViews = document.getElementById('kpiTotalViews');
  const kpiRev = document.getElementById('kpiTotalRevenue');
  const kpiOrders = document.getElementById('kpiTotalOrders');

  if (kpiViews) kpiViews.innerHTML = new Intl.NumberFormat('vi-VN').format(viewSum) + ' <small class="fs-6 text-muted">lượt</small>';
  if (kpiRev) kpiRev.textContent = new Intl.NumberFormat('vi-VN').format(revSum) + ' đ';
  if (kpiOrders) kpiOrders.innerHTML = new Intl.NumberFormat('vi-VN').format(orderSum) + ' <small class="fs-6 text-muted">(' + unitSum + ' SP)</small>';
}
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
