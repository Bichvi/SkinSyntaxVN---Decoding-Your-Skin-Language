<?php
// backend/app/views/admin/lives.php
$pageTitle = 'Quản lý Phiên LiveStream AI & Giá Ưu Đãi Khung Giờ';
require_once __DIR__ . '/layouts/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h2 class="h4 fw-bold text-dark mb-1">
      <i class="bi bi-camera-video-fill text-success me-2"></i>Quản lý Phiên LiveStream AI & Ưu Đãi Khung Giờ
    </h2>
    <p class="text-muted small mb-0">Quản lý lịch phát sóng WebRTC LiveKit Cloud, sản phẩm ghim độc quyền & giá ưu đãi trong phiên Live.</p>
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

<!-- DANH SÁCH CÁC PHIÊN LIVE -->
<div class="card border-0 rounded-4 shadow-sm mb-4" style="border: 1px solid var(--admin-border) !important;">
  <div class="card-header bg-white p-3 border-bottom d-flex align-items-center justify-content-between">
    <strong class="text-dark"><i class="bi bi-broadcast me-2 text-danger"></i>Danh Sách Phiên LiveStream (MongoDB Collection: `phien_live`)</strong>
    <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-1 fw-bold"><?= count($lives) ?> Phiên Live</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="bg-light">
        <tr>
          <th style="width: 70px;">Mã</th>
          <th>Tiêu Đề Phiên Live</th>
          <th>Streamer & AI</th>
          <th>Khung Giờ Ưu Đãi</th>
          <th>Sản Phẩm Ghim</th>
          <th>Giá Ưu Đãi Live</th>
          <th>Trạng Thái</th>
          <th class="text-end" style="width: 180px;">Thao Tác</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lives as $live): ?>
          <?php
            $pinnedProduct = $live['pinned_product'] ?? null;
            $statusClass = 'bg-secondary';
            $statusLabel = '⏹️ Kết thúc';
            if ($live['trang_thai'] === 'danglive' || $live['trang_thai'] === 'live') {
                $statusClass = 'bg-danger';
                $statusLabel = '🔴 Đang Live';
            } else if ($live['trang_thai'] === 'chuamoi' || $live['trang_thai'] === 'upcoming') {
                $statusClass = 'bg-warning text-dark';
                $statusLabel = '⏰ Chuẩn bị';
            }
          ?>
          <tr>
            <td><code class="fw-bold">#<?= h($live['id']) ?></code></td>
            <td>
              <strong class="text-dark d-block" style="font-size: 0.9rem;"><?= h($live['tieu_de']) ?></strong>
              <small class="text-muted" style="font-size: 0.75rem;"><i class="bi bi-hdd-network me-1"></i><?= h($live['server_livekit_url']) ?></small>
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
                <?php if ($live['trang_thai'] !== 'danglive' && $live['trang_thai'] !== 'live'): ?>
                  <a href="<?= BASE_URL ?>/index.php?r=admin_live_status&id=<?= urlencode($live['id']) ?>&status=danglive" class="btn btn-outline-danger" title="Bắt đầu phát Live">
                    <i class="bi bi-play-fill"></i> Phát Live
                  </a>
                <?php else: ?>
                  <a href="<?= BASE_URL ?>/index.php?r=admin_live_status&id=<?= urlencode($live['id']) ?>&status=ketthuc" class="btn btn-outline-secondary" title="Dừng phiên Live">
                    <i class="bi bi-stop-fill"></i> Dừng
                  </a>
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

<!-- MODAL TẠO PHIÊN LIVE MỚI DÀNH CHO ADMIN -->
<div class="modal fade" id="adminCreateLiveModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content rounded-4 border-0 shadow-lg">
      <div class="modal-header border-bottom p-3">
        <h5 class="modal-title fw-bold text-dark"><i class="bi bi-camera-video-fill text-success me-2"></i>Khởi Tạo Phiên LiveStream AI Mới</h5>
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
              <input type="text" name="streamer" class="form-control" value="DS. Minh Trang & AI Skin Co-Host" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold small">Server LiveKit WebRTC Cloud</label>
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
              <label class="form-label fw-bold small">Sản Phẩm Ghim Độc Quyền Trong Live <span class="text-danger">*</span></label>
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
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
