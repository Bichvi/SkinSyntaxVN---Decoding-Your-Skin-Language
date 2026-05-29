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

<div class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <h1 class="h3 mb-0">Quản lý sản phẩm</h1>
        <a href="index.php?r=admin_sp_create" class="btn btn-primary">+ Thêm sản phẩm</a>
    </div>

    <form class="row g-2 align-items-end mb-3" method="GET" action="index.php" data-live-filter="true">
        <input type="hidden" name="r" value="admin_sp">
        <div class="col-12 col-lg-5">
            <label class="form-label small text-muted mb-1">Tìm kiếm nhanh sản phẩm</label>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Nhập mã SP, tên sản phẩm, thương hiệu, danh mục, barcode...">
            </div>
        </div>
        <div class="col-12 col-lg-2">
            <label class="form-label small text-muted mb-1">Trạng thái</label>
            <select class="form-select" name="status">
                <option value="" <?= $status === '' ? 'selected' : '' ?>>Tất cả</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Đang hiển thị</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Tạm ẩn</option>
            </select>
        </div>
        <div class="col-12 col-lg-2">
            <label class="form-label small text-muted mb-1">Tồn kho</label>
            <select class="form-select" name="stock_status">
                <option value="" <?= $stockStatus === '' ? 'selected' : '' ?>>Tất cả</option>
                <option value="con_hang" <?= $stockStatus === 'con_hang' ? 'selected' : '' ?>>Còn hàng</option>
                <option value="het_hang" <?= $stockStatus === 'het_hang' ? 'selected' : '' ?>>Hết hàng</option>
            </select>
        </div>
        <div class="col-12 col-lg-3 d-grid d-md-flex gap-2">
            <button type="submit" class="btn btn-primary w-100">Tìm kiếm</button>
            <a href="index.php?r=admin_sp" class="btn btn-outline-secondary w-100">Xóa</a>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Mã SP</th>
                        <th>Tên sản phẩm</th>
                        <th class="text-end">Giá bán</th>
                        <th>Trạng thái</th>
                        <th class="text-end">Tồn kho</th>
                        <th>Trạng thái kho</th>
                        <th>Ảnh</th>
                        <th class="text-end">Thao tác</th>
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
                                <td><?= h($productId) ?></td>
                                <td class="fw-semibold"><?= h($item['ten_san_pham'] ?? '') ?></td>
                                <td class="text-end"><?= vnd($item['gia_ban'] ?? 0) ?></td>
                                <td><span class="badge <?= $isHidden ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= $isHidden ? 'Tạm ẩn' : 'Đang hiển thị' ?></span></td>
                                <td class="text-end">
                                    <form method="POST" action="index.php?r=admin_sp_stock" class="d-inline-flex gap-1 justify-content-end align-items-center">
                                        <input type="hidden" name="id" value="<?= h($productId) ?>">
                                        <input type="hidden" name="q" value="<?= h($q) ?>">
                                        <input type="hidden" name="status_filter" value="<?= h($status) ?>">
                                        <input type="hidden" name="stock_status_filter" value="<?= h($stockStatus) ?>">
                                        <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
                                        <input type="number" min="0" name="so_luong_ton_kho" value="<?= $stock ?>" class="form-control form-control-sm text-end" style="width:88px;">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Lưu</button>
                                    </form>
                                </td>
                                <td><span class="badge <?= $isOutOfStock ? 'text-bg-danger' : 'text-bg-success' ?>"><?= $isOutOfStock ? 'Hết hàng' : 'Còn hàng' ?></span></td>
                                <td>
                                    <?php if ($img !== ''): ?>
                                        <img src="<?= h($img) ?>" alt="<?= h($item['ten_san_pham'] ?? '') ?>" style="width:52px;height:52px;object-fit:cover;border-radius:8px;">
                                    <?php else: ?>
                                        <span class="text-muted small">Không có ảnh</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="index.php?r=admin_sp_edit&id=<?= urlencode($productId) ?>" class="btn btn-sm btn-outline-warning">Sửa</a>
                                    <form method="POST" action="index.php?r=admin_sp_visibility" class="d-inline">
                                        <input type="hidden" name="id" value="<?= h($productId) ?>">
                                        <input type="hidden" name="status" value="<?= $isHidden ? 'active' : 'inactive' ?>">
                                        <input type="hidden" name="q" value="<?= h($q) ?>">
                                        <input type="hidden" name="status_filter" value="<?= h($status) ?>">
                                        <input type="hidden" name="stock_status_filter" value="<?= h($stockStatus) ?>">
                                        <input type="hidden" name="page" value="<?= (int)$currentPage ?>">
                                        <button type="submit" class="btn btn-sm <?= $isHidden ? 'btn-outline-success' : 'btn-outline-secondary' ?>"><?= $isHidden ? 'Hiện web' : 'Tạm ẩn' ?></button>
                                    </form>
                                    <form method="POST" action="index.php?r=admin_sp_delete" class="d-inline" onsubmit="return confirm('Xóa sản phẩm này?');">
                                        <input type="hidden" name="id" value="<?= h($productId) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Xóa</button>
                                    </form>
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
            <div class="small text-muted">Trang <?= number_format($currentPage, 0, ',', '.') ?> / <?= number_format($totalPages, 0, ',', '.') ?></div>
            <ul class="pagination pagination-sm mb-0 flex-wrap justify-content-start justify-content-md-end">
                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($pageLink(max(1, $currentPage - 1))) ?>">Trước</a></li>
                <?php foreach ($paginationItems as $item): ?>
                    <?php if (is_string($item)): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php else: ?>
                        <li class="page-item <?= $item === $currentPage ? 'active' : '' ?>"><a class="page-link" href="<?= h($pageLink((int)$item)) ?>"><?= $item ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="<?= h($pageLink(min($totalPages, $currentPage + 1))) ?>">Sau</a></li>
            </ul>
        </nav>
    <?php endif; ?>
</div>
