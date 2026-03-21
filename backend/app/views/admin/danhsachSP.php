<?php
$totalPages = max(1, (int)ceil(($total ?? 0) / max(1, $perPage ?? 20)));
$currentPage = max(1, (int)($page ?? 1));
$q = trim((string)($q ?? ''));

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

<div class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <h1 class="h3 mb-0">Quản lý sản phẩm</h1>
        <a href="index.php?r=admin_sp_create" class="btn btn-primary">+ Thêm sản phẩm</a>
    </div>

    <form class="row g-2 align-items-end mb-3" method="GET" action="index.php">
        <input type="hidden" name="r" value="admin_sp">
        <div class="col-12 col-lg-8">
            <label class="form-label small text-muted mb-1">Tìm kiếm nhanh sản phẩm</label>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input
                    type="text"
                    class="form-control"
                    name="q"
                    value="<?= h($q) ?>"
                    placeholder="Nhập mã SP, tên sản phẩm, thương hiệu, danh mục, loại da..."
                >
            </div>
        </div>
        <div class="col-12 col-lg-4 d-grid d-md-flex gap-2">
            <button type="submit" class="btn btn-primary w-100">Tìm kiếm</button>
            <a href="index.php?r=admin_sp" class="btn btn-outline-secondary w-100">Xóa lọc</a>
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
                        <th>Ảnh</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($items)): ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= h($item['ma_san_pham'] ?? $item['id'] ?? '') ?></td>
                                <td class="fw-semibold"><?= h($item['ten_san_pham'] ?? '') ?></td>
                                <td class="text-end"><?= vnd($item['gia_ban'] ?? 0) ?></td>
                                <td>
                                    <?php
                                    $rawImg = trim((string)($item['link_hinh_anh'] ?? ''));
                                    $img = '';
                                    if ($rawImg !== '') {
                                        $parts = preg_split('/\s*\|\s*/', $rawImg) ?: [];
                                        foreach ($parts as $part) {
                                            $part = trim((string)$part);
                                            if ($part === '') {
                                                continue;
                                            }
                                            if (filter_var($part, FILTER_VALIDATE_URL)) {
                                                $img = $part;
                                                break;
                                            }
                                            if ($img === '') {
                                                $img = BASE_URL . '/uploads/products/' . rawurlencode($part);
                                            }
                                        }
                                    }
                                    ?>
                                    <?php if ($img !== ''): ?>
                                        <img src="<?= h($img) ?>" alt="<?= h($item['ten_san_pham'] ?? '') ?>" style="width:52px;height:52px;object-fit:cover;border-radius:8px;">
                                    <?php else: ?>
                                        <span class="text-muted small">Không có ảnh</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="index.php?r=admin_sp_edit&id=<?= urlencode((string)($item['ma_san_pham'] ?? $item['id'] ?? '')) ?>" class="btn btn-sm btn-outline-warning">Sửa</a>
                                    <form method="POST" action="index.php?r=admin_sp_delete" class="d-inline" onsubmit="return confirm('Xóa sản phẩm này?');">
                                        <input type="hidden" name="id" value="<?= h($item['ma_san_pham'] ?? $item['id'] ?? '') ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Xóa</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">Không có sản phẩm phù hợp.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div class="small text-muted">
                Trang <?= number_format($currentPage, 0, ',', '.') ?> / <?= number_format($totalPages, 0, ',', '.') ?>
            </div>
            <ul class="pagination pagination-sm mb-0 flex-wrap justify-content-start justify-content-md-end">
                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="index.php?r=admin_sp&page=<?= max(1, $currentPage - 1) ?>&q=<?= urlencode($q) ?>">Trước</a>
                </li>
                <?php foreach ($paginationItems as $item): ?>
                    <?php if (is_string($item)): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php else: ?>
                        <li class="page-item <?= $item === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="index.php?r=admin_sp&page=<?= $item ?>&q=<?= urlencode($q) ?>"><?= $item ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="index.php?r=admin_sp&page=<?= min($totalPages, $currentPage + 1) ?>&q=<?= urlencode($q) ?>">Sau</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>
