<?php
$totalPages = max(1, (int)ceil(($total ?? 0) / max(1, $perPage ?? 20)));
$currentPage = max(1, (int)($page ?? 1));
$q = trim((string)($q ?? ''));
$status = strtolower(trim((string)($status ?? '')));
$stockStatus = strtolower(trim((string)($stockStatus ?? '')));
$pageLink = function (int $targetPage) use ($q, $status, $stockStatus): string {
    return 'index.php?' . http_build_query([
        'r' => 'admin_sp',
        'page' => $targetPage,
        'q' => $q,
        'status' => $status,
        'stock_status' => $stockStatus,
    ]);
};

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
    if ($windowStart > 2) $paginationItems[] = 'ellipsis-left';
    for ($i = $windowStart; $i <= $windowEnd; $i++) $paginationItems[] = $i;
    if ($windowEnd < $totalPages - 1) $paginationItems[] = 'ellipsis-right';
    $paginationItems[] = $totalPages;
}
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);">Quản lý sản phẩm</h1>
            <div class="text-muted small">Danh sách và kiểm soát tồn kho toàn bộ ngành hàng mỹ phẩm.</div>
        </div>
        <a href="index.php?r=admin_sp_create" class="btn btn-primary btn-sm px-3 py-2 fw-semibold" style="border-radius: 6px; background: #183B2B; border: none;">+ Thêm sản phẩm mới</a>
    </div>

    <div class="admin-card mb-3 p-3" style="border-radius: 8px !important;">
        <form class="row g-2 align-items-end" method="GET" action="index.php" data-live-filter="true">
            <input type="hidden" name="r" value="admin_sp">
            <div class="col-12 col-lg-5">
                <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Tìm kiếm nhanh sản phẩm</label>
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0" style="border-color: var(--admin-border);"><i class="fa-solid fa-magnifying-glass text-muted" style="font-size: 0.8rem;"></i></span>
                    <input type="text" class="form-control border-start-0" name="q" value="<?= h($q) ?>" placeholder="Nhập mã SP, tên sản phẩm, thương hiệu, danh mục..." style="border-color: var(--admin-border); border-radius: 0 6px 6px 0; font-size: 0.85rem;">
                </div>
            </div>
            <div class="col-12 col-lg-2">
                <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Trạng thái hiển thị</label>
                <select class="form-select" name="status" style="border-color: var(--admin-border); border-radius: 6px; font-size: 0.85rem;">
                    <option value="" <?= $status === '' ? 'selected' : '' ?>>Tất cả</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Đang hiển thị</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Tạm ẩn</option>
                </select>
            </div>
            <div class="col-12 col-lg-2">
                <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Tồn kho</label>
                <select class="form-select" name="stock_status" style="border-color: var(--admin-border); border-radius: 6px; font-size: 0.85rem;">
                    <option value="" <?= $stockStatus === '' ? 'selected' : '' ?>>Tất cả</option>
                    <option value="con_hang" <?= $stockStatus === 'con_hang' ? 'selected' : '' ?>>Còn hàng</option>
                    <option value="het_hang" <?= $stockStatus === 'het_hang' ? 'selected' : '' ?>>Hết hàng</option>
                </select>
            </div>
            <div class="col-12 col-lg-3 d-grid d-md-flex gap-2">
                <button type="submit" class="btn btn-sm text-white fw-semibold w-100" style="background: #183B2B; border-radius: 6px;">Tìm kiếm</button>
                <a href="index.php?r=admin_sp" class="btn btn-sm btn-outline-secondary fw-semibold w-100" style="border-radius: 6px;">Xóa bộ lọc</a>
            </div>
        </form>
    </div>

    <div class="admin-card p-0 overflow-hidden mb-3" style="border-radius: 8px !important;">
        <div class="table-responsive">
            <table class="table admin-table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 100px;">Mã SP</th>
                        <th style="min-width: 240px;">Sản phẩm</th>
                        <th class="text-end" style="width: 120px;">Giá bán</th>
                        <th style="width: 130px;">Hiển thị</th>
                        <th class="text-end" style="width: 180px;">Cập nhật Kho</th>
                        <th style="width: 120px;">Trạng thái kho</th>
                        <th style="width: 70px;">Ảnh</th>
                        <th class="text-end" style="width: 160px;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($items)): ?>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $productId = (string)($item['ma_san_pham'] ?? $item['id'] ?? '');
                            $rowStatus = strtolower(trim((string)($item['trang_thai'] ?? $item['status'] ?? 'active')));
                            $isHidden = in_array($rowStatus, ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0'], true);
                            $stock = max(0, (int)($item['so_luong_ton_kho'] ?? $item['ton_kho_hien_thi'] ?? 0));
                            $isOutOfStock = $stock <= 0 || (($item['trang_thai_kho'] ?? '') === 'het_hang');
                            $rawImg = trim((string)($item['link_hinh_anh'] ?? ''));
                            $img = '';
                            if ($rawImg !== '') {
                                $parts = preg_split('/\s*\|\s*/', $rawImg) ?: [];
                                foreach ($parts as $part) {
                                    $part = trim((string)$part);
                                    if ($part === '') continue;
                                    if (filter_var($part, FILTER_VALIDATE_URL)) {
                                        $img = $part;
                                        break;
                                    }
                                    if ($img === '') $img = BASE_URL . '/uploads/products/' . rawurlencode($part);
                                }
                            }
                            ?>
                            <tr>
                                <td><code class="px-2 py-1 rounded fw-semibold" style="background: #F1F5F9; color: #0F172A; font-size: 0.78rem; border: 1px solid #E2E8F0;"><?= h($productId) ?></code></td>
                                <td>
                                    <div class="fw-semibold text-truncate" style="max-width: 260px; color: var(--admin-text); font-size: 0.86rem;"><?= h($item['ten_san_pham'] ?? '') ?></div>
                                    <div class="small text-muted" style="font-size: 0.76rem;"><?= h($item['thuong_hieu'] ?? 'SkinSyntax') ?></div>
                                </td>
                                <td class="text-end fw-semibold tabular-nums" style="color: #183B2B; font-size: 0.88rem;"><?= vnd($item['gia_ban'] ?? 0) ?></td>
                                <td><span class="status-pill <?= $isHidden ? 'status-pill-cancelled' : 'status-pill-completed' ?>"><?= $isHidden ? 'Tạm ẩn' : 'Hiển thị' ?></span></td>
                                <td class="text-end">
                                    <form method="POST" action="index.php?r=admin_sp_stock" class="d-inline-flex gap-1 justify-content-end align-items-center">
                                        <input type="hidden" name="id" value="<?= h($productId) ?>">
                                        <input type="hidden" name="q" value="<?= h($q) ?>">
                                        <input type="hidden" name="status_filter" value="<?= h($status) ?>">
                                        <input type="hidden" name="stock_status_filter" value="<?= h($stockStatus) ?>">
                                        <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
                                        <input type="number" min="0" name="so_luong_ton_kho" value="<?= $stock ?>" class="form-control form-control-sm text-end tabular-nums" style="width:78px; border-radius: 4px; font-size: 0.82rem; border-color: var(--admin-border);">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;">Lưu</button>
                                    </form>
                                </td>
                                <td><span class="status-pill <?= $isOutOfStock ? 'status-pill-cancelled' : 'status-pill-completed' ?>"><?= $isOutOfStock ? 'Hết hàng' : 'Còn hàng' ?></span></td>
                                <td>
                                    <?php if ($img !== ''): ?>
                                        <img src="<?= h($img) ?>" alt="<?= h($item['ten_san_pham'] ?? '') ?>" style="width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid var(--admin-border);">
                                    <?php else: ?>
                                        <span class="text-muted small" style="font-size: 0.72rem;">No img</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="index.php?r=admin_sp_edit&id=<?= urlencode($productId) ?>" class="btn btn-sm btn-outline-secondary px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" title="Chỉnh sửa"><i class="bi bi-pencil-square me-1"></i>Sửa</a>
                                        <form method="POST" action="index.php?r=admin_sp_visibility" class="d-inline">
                                            <input type="hidden" name="id" value="<?= h($productId) ?>">
                                            <input type="hidden" name="status" value="<?= $isHidden ? 'active' : 'inactive' ?>">
                                            <input type="hidden" name="q" value="<?= h($q) ?>">
                                            <input type="hidden" name="status_filter" value="<?= h($status) ?>">
                                            <input type="hidden" name="stock_status_filter" value="<?= h($stockStatus) ?>">
                                            <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
                                            <button type="submit" class="btn btn-sm <?= $isHidden ? 'btn-outline-success' : 'btn-outline-secondary' ?> px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" title="<?= $isHidden ? 'Hiện web' : 'Tạm ẩn' ?>"><i class="bi <?= $isHidden ? 'bi-eye me-1' : 'bi-eye-slash me-1' ?>"></i><?= $isHidden ? 'Hiện' : 'Ẩn' ?></button>
                                        </form>
                                        <form method="POST" action="index.php?r=admin_sp_delete" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn xóa sản phẩm này không?');">
                                            <input type="hidden" name="id" value="<?= h($productId) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" title="Xóa"><i class="bi bi-trash me-1"></i>Xóa</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">Không có sản phẩm phù hợp.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div class="small text-muted" style="font-size: 0.8rem;">Trang <?= number_format($currentPage, 0, ',', '.') ?> / <?= number_format($totalPages, 0, ',', '.') ?></div>
            <ul class="pagination pagination-sm mb-0 flex-wrap justify-content-start justify-content-md-end gap-1">
                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($pageLink(max(1, $currentPage - 1))) ?>" style="border-radius: 4px; border-color: var(--admin-border);">«</a></li>
                <?php foreach ($paginationItems as $item): ?>
                    <?php if (is_string($item)): ?>
                        <li class="page-item disabled"><span class="page-link" style="border-radius: 4px;">...</span></li>
                    <?php else: ?>
                        <li class="page-item <?= $item === $currentPage ? 'active' : '' ?>"><a class="page-link" href="<?= h($pageLink((int)$item)) ?>" style="border-radius: 4px; <?= $item === $currentPage ? 'background: #183B2B; border-color: #183B2B; color: #FFF;' : '' ?>"><?= $item ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($pageLink(min($totalPages, $currentPage + 1))) ?>" style="border-radius: 4px; border-color: var(--admin-border);">»</a></li>
            </ul>
        </nav>
    <?php endif; ?>
</div>
