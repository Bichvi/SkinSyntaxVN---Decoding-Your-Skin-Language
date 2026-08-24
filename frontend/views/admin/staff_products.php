<?php
$items = $items ?? [];
$totalPages = max(1, (int)ceil(($total ?? 0) / max(1, $perPage ?? 20)));
$currentPage = max(1, (int)($page ?? 1));
$q = trim((string)($q ?? ''));
$status = strtolower(trim((string)($status ?? '')));

$paginationItems = [];
if ($totalPages <= 7) {
    $paginationItems = range(1, $totalPages);
} else {
    $paginationItems = [1];

    $windowStart = max(2, $currentPage - 1);
    $windowEnd = min($totalPages - 1, $currentPage + 1);

    if ($currentPage <= 3) {
        $windowStart = 2;
        $windowEnd = 4;
    } elseif ($currentPage >= $totalPages - 2) {
        $windowStart = $totalPages - 3;
        $windowEnd = $totalPages - 1;
    }

    if ($windowStart > 2) {
        $paginationItems[] = 'ellipsis-left';
    }

    for ($i = $windowStart; $i <= $windowEnd; $i++) {
        $paginationItems[] = $i;
    }

    if ($windowEnd < $totalPages - 1) {
        $paginationItems[] = 'ellipsis-right';
    }

    $paginationItems[] = $totalPages;
}
?>

<style>
    .staff-products-pagination .pagination {
        gap: 0.35rem;
    }

    .staff-products-pagination .page-link {
        min-width: 2.25rem;
        text-align: center;
        border-radius: 0.8rem;
    }

    @media (max-width: 575.98px) {
        .staff-products-pagination .pagination {
            justify-content: flex-start;
        }

        .staff-products-pagination .page-link {
            padding: 0.42rem 0.68rem;
            min-width: 2rem;
            font-size: 0.92rem;
        }
    }
</style>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);">Quản lý sản phẩm (Nhân viên)</h1>
            <p class="text-muted mb-0 small">Thêm mới, cập nhật thông tin và điều chỉnh ẩn/hiện sản phẩm trên website.</p>
        </div>
        <a href="index.php?r=staff_product_create" class="btn btn-primary btn-sm px-3 py-2 fw-semibold text-white" style="background: #183B2B; border: none; border-radius: 6px;">
            <i class="bi bi-plus-lg me-1"></i> Thêm sản phẩm mới
        </a>
    </div>

    <div class="admin-card mb-3 p-3" style="border-radius: 8px !important;">
        <form class="row g-2 align-items-end" method="GET" action="index.php" data-live-filter="true">
            <input type="hidden" name="r" value="staff_products">
            <div class="col-12 col-lg-6">
                <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.76rem;">Tìm kiếm nhanh sản phẩm</label>
                <div class="input-group">
                    <span class="input-group-text bg-white" style="border-color: var(--admin-border);"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Nhập mã SP, tên sản phẩm, thương hiệu, danh mục..." style="border-radius: 0 6px 6px 0; border-color: var(--admin-border); font-size: 0.85rem;">
                </div>
            </div>
            <div class="col-12 col-lg-2">
                <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.76rem;">Trạng thái</label>
                <select class="form-select" name="status" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
                    <option value="" <?= $status === '' ? 'selected' : '' ?>>Tất cả</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Đang hiển thị</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Tạm ẩn</option>
                </select>
            </div>
            <div class="col-12 col-lg-4 d-grid d-md-flex gap-2">
                <button type="submit" class="btn text-white fw-semibold w-100" style="background: #183B2B; border-radius: 6px; font-size: 0.85rem;">Tìm kiếm</button>
                <a href="index.php?r=staff_products" class="btn btn-outline-secondary fw-semibold w-100" style="border-radius: 6px; font-size: 0.85rem;">Xóa lọc</a>
            </div>
        </form>
    </div>

    <div class="admin-card p-0 overflow-hidden mb-0" style="border-radius: 8px !important;">
        <div class="table-responsive">
            <table class="table admin-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Mã SP</th>
                        <th>Tên sản phẩm</th>
                        <th class="text-end">Giá bán</th>
                        <th>Loại da</th>
                        <th>Trạng thái</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Không có sản phẩm phù hợp.</td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $rowStatus = strtolower(trim((string)($item['trang_thai'] ?? $item['status'] ?? 'active')));
                            $isHidden = in_array($rowStatus, ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0'], true);
                            ?>
                            <tr>
                                <td><code class="fw-bold" style="color: #183B2B;">#<?= h($item['ma_san_pham'] ?? $item['id'] ?? '') ?></code></td>
                                <td class="fw-semibold" style="color: var(--admin-text); font-size: 0.86rem;"><?= h($item['ten_san_pham'] ?? '') ?></td>
                                <td class="text-end tabular-nums fw-bold text-success" style="font-size: 0.86rem;"><?= vnd($item['gia_ban'] ?? 0) ?></td>
                                <td style="font-size: 0.82rem;"><?= h($item['loai_da'] ?? 'Mọi loại da') ?></td>
                                <td>
                                    <span class="status-pill <?= $isHidden ? 'status-pill-cancelled' : 'status-pill-completed' ?>">
                                        <?= $isHidden ? 'Tạm ẩn' : 'Hiển thị' ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="index.php?r=staff_product_edit&id=<?= urlencode((string)($item['ma_san_pham'] ?? $item['id'] ?? '')) ?>" class="btn btn-sm btn-outline-secondary px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" title="Sửa"><i class="bi bi-pencil-square me-1"></i> Sửa</a>
                                        <form method="POST" action="index.php?r=staff_product_visibility" class="d-inline">
                                            <input type="hidden" name="id" value="<?= h($item['ma_san_pham'] ?? $item['id'] ?? '') ?>">
                                            <input type="hidden" name="status" value="<?= $isHidden ? 'active' : 'inactive' ?>">
                                            <input type="hidden" name="q" value="<?= h($q) ?>">
                                            <input type="hidden" name="status_filter" value="<?= h($status) ?>">
                                            <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
                                            <button type="submit" class="btn btn-sm <?= $isHidden ? 'btn-outline-success' : 'btn-outline-secondary' ?> px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;">
                                                <i class="bi <?= $isHidden ? 'bi-eye me-1' : 'bi-eye-slash me-1' ?>"></i><?= $isHidden ? 'Hiện' : 'Ẩn' ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 staff-products-pagination">
            <div class="small text-muted">
                Trang <?= number_format($currentPage, 0, ',', '.') ?> / <?= number_format($totalPages, 0, ',', '.') ?>
            </div>
            <ul class="pagination pagination-sm mb-0 flex-wrap justify-content-start justify-content-md-end">
                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="index.php?r=staff_products&page=<?= max(1, $currentPage - 1) ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>">Trước</a>
                </li>
                <?php foreach ($paginationItems as $item): ?>
                    <?php if (is_string($item)): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php else: ?>
                        <li class="page-item <?= $item === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="index.php?r=staff_products&page=<?= $item ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>"><?= $item ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="index.php?r=staff_products&page=<?= min($totalPages, $currentPage + 1) ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>">Sau</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>