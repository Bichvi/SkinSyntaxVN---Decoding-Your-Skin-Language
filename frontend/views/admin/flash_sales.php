<?php
// frontend/views/admin/flash_sales.php
$campaigns = $campaigns ?? [];
$now = $now ?? time();

function get_flash_sale_status_badge(string $status): array {
    return match ($status) {
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
}
?>

<div class="container-fluid px-4 py-4">
    <!-- Header -->
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);">
                <i class="bi bi-lightning-charge-fill text-warning me-2"></i>Quản lý Flash Sale
            </h1>
            <p class="text-muted mb-0 small">Thiết lập các khung giờ vàng ưu đãi chớp nhoáng, kiểm soát danh sách SKU và phân giải giá hiệu lực tự động.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="index.php?r=admin_flash_sale_create" class="btn btn-sm text-white fw-semibold px-3 py-2 d-inline-flex align-items-center gap-1 shadow-sm" style="background: #183B2B; border-radius: 8px;">
                <i class="bi bi-plus-lg"></i>
                <span>Tạo chiến dịch mới</span>
            </a>
        </div>
    </div>

    <!-- Soft recommendation banner -->
    <div class="alert alert-light border d-flex align-items-center gap-2 py-2.5 px-3 mb-4 shadow-sm" style="border-radius: 8px; font-size: 0.86rem; background: #FAFDFB;">
        <i class="bi bi-info-circle-fill text-primary fs-5"></i>
        <div>
            Khuyến nghị <strong>12–20 sản phẩm</strong> mỗi chiến dịch để đảm bảo đủ lựa chọn danh mục cho trải nghiệm cá nhân hóa mượt mà.
        </div>
    </div>

    <!-- Campaign Listing Card -->
    <div class="admin-card p-0 shadow-sm" style="border-radius: 8px !important; overflow: hidden;">
        <div class="p-3 border-bottom d-flex align-items-center justify-content-between bg-light">
            <h6 class="fw-bold mb-0 text-dark">
                Danh sách chiến dịch (<?= count($campaigns) ?>)
            </h6>
            <div class="text-muted small">
                Thời gian máy chủ: <strong><?= date('H:i d/m/Y', $now) ?></strong>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                <thead class="table-light text-muted small text-uppercase fw-semibold" style="letter-spacing: 0.5px;">
                    <tr>
                        <th style="width: 70px;" class="text-center">Mã</th>
                        <th>Tên chiến dịch</th>
                        <th style="width: 170px;">Bắt đầu (VN)</th>
                        <th style="width: 170px;">Kết thúc (VN)</th>
                        <th style="width: 140px;" class="text-center">Trạng thái</th>
                        <th style="width: 110px;" class="text-center">Số SP</th>
                        <th style="width: 140px;" class="text-center">Mức giảm</th>
                        <th style="width: 180px;" class="text-end pe-3">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($campaigns)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                Chưa có chiến dịch Flash Sale nào được thiết lập.<br>
                                <a href="index.php?r=admin_flash_sale_create" class="btn btn-sm btn-outline-success mt-2">
                                    <i class="bi bi-plus-lg me-1"></i>Tạo chiến dịch đầu tiên
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($campaigns as $c): ?>
                            <?php
                            $badge = get_flash_sale_status_badge($c['status'] ?? '');
                            $items = is_array($c['items'] ?? null) ? $c['items'] : [];
                            $itemCount = count($items);
                            
                            // Calculate discount range
                            $minPct = 100;
                            $maxPct = 0;
                            foreach ($items as $it) {
                                $pct = (int)($it['phan_tram_giam'] ?? 0);
                                if ($pct > 0) {
                                    if ($pct < $minPct) $minPct = $pct;
                                    if ($pct > $maxPct) $maxPct = $pct;
                                }
                            }
                            $pctRangeStr = ($itemCount > 0 && $maxPct > 0)
                                ? ($minPct === $maxPct ? "{$minPct}%" : "{$minPct}% - {$maxPct}%")
                                : "—";
                            ?>
                            <tr>
                                <td class="text-center fw-bold text-muted">
                                    #<?= (int)$c['ma_campaign'] ?>
                                </td>
                                <td>
                                    <a href="index.php?r=admin_flash_sale_detail&id=<?= (int)$c['ma_campaign'] ?>" class="fw-bold text-decoration-none text-dark d-block">
                                        <?= h($c['ten_campaign']) ?>
                                    </a>
                                    <?php if (!empty($c['mo_ta'])): ?>
                                        <div class="text-muted text-truncate small" style="max-width: 320px;">
                                            <?= h($c['mo_ta']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="tabular-nums small text-nowrap">
                                    <?= date('H:i - d/m/Y', strtotime($c['start_at'])) ?>
                                </td>
                                <td class="tabular-nums small text-nowrap">
                                    <?= date('H:i - d/m/Y', strtotime($c['end_at'])) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $badge['class'] ?> px-2.5 py-1.5 fw-medium d-inline-flex align-items-center gap-1" style="font-size: 0.78rem;">
                                        <i class="bi <?= $badge['icon'] ?>"></i>
                                        <?= h($badge['label']) ?>
                                    </span>
                                </td>
                                <td class="text-center tabular-nums fw-semibold">
                                    <?= $itemCount ?> SP
                                </td>
                                <td class="text-center tabular-nums">
                                    <?php if ($pctRangeStr !== '—'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold">
                                            -<?= $pctRangeStr ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3 text-nowrap">
                                    <div class="btn-group btn-group-sm">
                                        <a href="index.php?r=admin_flash_sale_detail&id=<?= (int)$c['ma_campaign'] ?>" class="btn btn-outline-secondary" title="Xem chi tiết">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="index.php?r=admin_flash_sale_edit&id=<?= (int)$c['ma_campaign'] ?>" class="btn btn-outline-primary" title="Chỉnh sửa">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <a href="index.php?r=admin_flash_sale_toggle&id=<?= (int)$c['ma_campaign'] ?>" class="btn <?= $c['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $c['is_active'] ? 'Tắt chiến dịch' : 'Bật chiến dịch' ?>" onclick="return confirm('Bạn có chắc chắn muốn <?= $c['is_active'] ? 'TẮT' : 'BẬT' ?> chiến dịch này?');">
                                            <i class="bi <?= $c['is_active'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i>
                                        </a>
                                        <a href="index.php?r=admin_flash_sale_delete&id=<?= (int)$c['ma_campaign'] ?>" class="btn btn-outline-danger" title="Xóa" onclick="return confirm('Bạn có chắc chắn muốn xóa chiến dịch này? Hành động này không thể hoàn tác.');">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
