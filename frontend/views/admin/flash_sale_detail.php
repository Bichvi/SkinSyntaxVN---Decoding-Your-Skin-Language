<?php
// frontend/views/admin/flash_sale_detail.php
$campaign = $campaign ?? [];
$items = $items ?? [];
$now = $now ?? time();

$cid = (int)($campaign['ma_campaign'] ?? 0);
$name = (string)($campaign['ten_campaign'] ?? '');
$desc = (string)($campaign['mo_ta'] ?? '');
$status = (string)($campaign['status'] ?? '');
$isActive = !empty($campaign['is_active']);

$badge = match ($status) {
    FlashSaleCampaign::STATUS_ACTIVE => [
        'label' => 'Đang diễn ra',
        'class' => 'bg-success text-white',
        'icon' => 'bi-lightning-charge-fill'
    ],
    FlashSaleCampaign::STATUS_UPCOMING => [
        'label' => 'Sắp diễn ra',
        'class' => 'bg-info text-dark',
        'icon' => 'bi-clock-history'
    ],
    FlashSaleCampaign::STATUS_ENDED => [
        'label' => 'Đã kết thúc',
        'class' => 'bg-secondary text-white',
        'icon' => 'bi-calendar-check'
    ],
    default => [
        'label' => 'Đã tắt',
        'class' => 'bg-danger text-white',
        'icon' => 'bi-pause-circle'
    ]
};

$endTs = (int)($campaign['end_at_ts'] ?? strtotime($campaign['end_at'] ?? 'now'));
$remainingSeconds = max(0, $endTs - $now);
?>

<div class="container-fluid px-4 py-4">
    <!-- Breadcrumb & Top Bar -->
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small">
                    <li class="breadcrumb-item"><a href="index.php?r=admin_flash_sales" class="text-decoration-none text-muted">Flash Sale</a></li>
                    <li class="breadcrumb-item active text-dark fw-semibold" aria-current="page">Chi tiết chiến dịch #<?= $cid ?></li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h1 class="h4 fw-bold mb-0" style="color: var(--admin-text);"><?= h($name) ?></h1>
                <span class="badge <?= $badge['class'] ?> px-2.5 py-1.5 fw-medium d-inline-flex align-items-center gap-1" style="font-size: 0.8rem;">
                    <i class="bi <?= $badge['icon'] ?>"></i>
                    <?= h($badge['label']) ?>
                </span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php?r=admin_flash_sales" class="btn btn-sm btn-outline-secondary px-3 py-2" style="border-radius: 6px;">
                <i class="bi bi-arrow-left me-1"></i>Danh sách
            </a>
            <a href="index.php?r=admin_flash_sale_edit&id=<?= $cid ?>" class="btn btn-sm btn-outline-primary px-3 py-2" style="border-radius: 6px;">
                <i class="bi bi-pencil me-1"></i>Chỉnh sửa
            </a>
            <a href="index.php?r=admin_flash_sale_toggle&id=<?= $cid ?>" class="btn btn-sm <?= $isActive ? 'btn-outline-warning' : 'btn-outline-success' ?> px-3 py-2" style="border-radius: 6px;" onclick="return confirm('Bạn có chắc chắn muốn <?= $isActive ? 'TẮT' : 'BẬT' ?> chiến dịch này?');">
                <i class="bi <?= $isActive ? 'bi-pause-fill' : 'bi-play-fill' ?> me-1"></i><?= $isActive ? 'Tắt chiến dịch' : 'Bật chiến dịch' ?>
            </a>
        </div>
    </div>

    <!-- Countdown banner if ACTIVE -->
    <?php if ($status === FlashSaleCampaign::STATUS_ACTIVE): ?>
        <div class="card border-0 text-white mb-4 shadow-sm" style="background: linear-gradient(135deg, #183B2B 0%, #295B43 100%); border-radius: 8px;">
            <div class="card-body p-3.5 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-white bg-opacity-20 p-2.5 rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="bi bi-lightning-charge-fill text-warning fs-4"></i>
                    </div>
                    <div>
                        <div class="text-uppercase small fw-bold text-warning" style="letter-spacing: 1px;">Chiến dịch đang diễn ra trực tiếp</div>
                        <div class="small opacity-75">Giá Flash Sale đang được áp dụng xuyên suốt Giỏ hàng và Thanh toán.</div>
                    </div>
                </div>
                <div class="text-md-end">
                    <div class="small opacity-75 mb-1" id="countdownLabel">Kết thúc sau:</div>
                    <div class="d-flex align-items-center gap-1.5 justify-content-md-end tabular-nums fw-bold fs-5" id="countdownTimer" data-remaining="<?= $remainingSeconds ?>">
                        <span class="bg-dark bg-opacity-40 px-2.5 py-1 rounded" id="timerHours">00</span> :
                        <span class="bg-dark bg-opacity-40 px-2.5 py-1 rounded" id="timerMins">00</span> :
                        <span class="bg-dark bg-opacity-40 px-2.5 py-1 rounded" id="timerSecs">00</span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Overview Details Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="admin-card p-3 h-100 shadow-sm" style="border-radius: 8px !important;">
                <div class="text-muted small mb-1">Mã chiến dịch</div>
                <div class="fw-bold fs-5 text-dark">#<?= $cid ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="admin-card p-3 h-100 shadow-sm" style="border-radius: 8px !important;">
                <div class="text-muted small mb-1">Số sản phẩm tham gia</div>
                <div class="fw-bold fs-5 text-success"><?= count($items) ?> SP</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="admin-card p-3 h-100 shadow-sm" style="border-radius: 8px !important;">
                <div class="text-muted small mb-1">Thời gian bắt đầu (VN)</div>
                <div class="fw-semibold text-dark tabular-nums small"><?= date('H:i - d/m/Y', strtotime($campaign['start_at'])) ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="admin-card p-3 h-100 shadow-sm" style="border-radius: 8px !important;">
                <div class="text-muted small mb-1">Thời gian kết thúc (VN)</div>
                <div class="fw-semibold text-dark tabular-nums small"><?= date('H:i - d/m/Y', strtotime($campaign['end_at'])) ?></div>
            </div>
        </div>
    </div>

    <?php if (!empty($desc)): ?>
        <div class="admin-card p-3 mb-4 shadow-sm" style="border-radius: 8px !important;">
            <div class="text-muted small fw-semibold mb-1">Mô tả chiến dịch:</div>
            <div class="text-dark small"><?= nl2br(h($desc)) ?></div>
        </div>
    <?php endif; ?>

    <!-- Products Deal Table -->
    <div class="admin-card p-0 shadow-sm" style="border-radius: 8px !important; overflow: hidden;">
        <div class="p-3 border-bottom d-flex align-items-center justify-content-between bg-light">
            <h6 class="fw-bold mb-0 text-dark">
                Danh sách Deal trong chiến dịch (<?= count($items) ?>)
            </h6>
            <div class="small text-muted">
                Tất cả khách hàng đều nhận cùng một mức giá Flash Sale cho từng sản phẩm.
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                <thead class="table-light text-muted small text-uppercase fw-semibold" style="letter-spacing: 0.5px;">
                    <tr>
                        <th style="width: 50px;">Ảnh</th>
                        <th style="width: 70px;" class="text-center">Mã SP</th>
                        <th>Tên sản phẩm</th>
                        <th>Danh mục</th>
                        <th style="width: 140px;" class="text-end">Giá niêm yết</th>
                        <th style="width: 150px;" class="text-end">Giá Flash Sale</th>
                        <th style="width: 110px;" class="text-center">Mức giảm</th>
                        <th style="width: 140px;" class="text-end pe-3">Tiết kiệm</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                Chưa có sản phẩm nào trong chiến dịch này.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td>
                                    <img src="<?= h($it['hinh_anh'] ?: 'assets/images/sp-placeholder.png') ?>" alt="" class="rounded border" style="width: 40px; height: 40px; object-fit: cover;" onerror="this.src='assets/images/sp-placeholder.png'">
                                </td>
                                <td class="text-center fw-bold text-muted tabular-nums">
                                    #<?= h($it['ma_san_pham']) ?>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= h($it['ten_san_pham']) ?></div>
                                    <div class="small text-muted"><?= h($it['thuong_hieu']) ?></div>
                                </td>
                                <td class="small text-muted">
                                    <?= h($it['danh_muc']) ?>
                                </td>
                                <td class="text-end tabular-nums text-muted text-decoration-line-through">
                                    <?= number_format($it['regular_price'], 0, ',', '.') ?> đ
                                </td>
                                <td class="text-end tabular-nums fw-bold text-danger">
                                    <?= number_format($it['flash_price'], 0, ',', '.') ?> đ
                                </td>
                                <td class="text-center tabular-nums">
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold">
                                        -<?= (int)$it['phan_tram_giam'] ?>%
                                    </span>
                                </td>
                                <td class="text-end tabular-nums fw-semibold text-success pe-3">
                                    <?= number_format($it['savings_amount'], 0, ',', '.') ?> đ
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Non-resetting presentation countdown timer -->
<?php if ($status === FlashSaleCampaign::STATUS_ACTIVE): ?>
<script>
(function() {
    const timerElem = document.getElementById('countdownTimer');
    if (!timerElem) return;

    let remaining = parseInt(timerElem.getAttribute('data-remaining'), 10) || 0;
    const hSpan = document.getElementById('timerHours');
    const mSpan = document.getElementById('timerMins');
    const sSpan = document.getElementById('timerSecs');
    const label = document.getElementById('countdownLabel');

    function renderTime() {
        if (remaining <= 0) {
            hSpan.textContent = '00';
            mSpan.textContent = '00';
            sSpan.textContent = '00';
            label.textContent = 'Trạng thái:';
            timerElem.innerHTML = '<span class="badge bg-secondary fs-6 py-2 px-3">Đã kết thúc</span>';
            return;
        }

        const hrs = Math.floor(remaining / 3600);
        const mins = Math.floor((remaining % 3600) / 60);
        const secs = remaining % 60;

        hSpan.textContent = String(hrs).padStart(2, '0');
        mSpan.textContent = String(mins).padStart(2, '0');
        sSpan.textContent = String(secs).padStart(2, '0');

        remaining--;
    }

    renderTime();
    const interval = setInterval(function() {
        if (remaining <= 0) {
            renderTime();
            clearInterval(interval);
        } else {
            renderTime();
        }
    }, 1000);
})();
</script>
<?php endif; ?>
