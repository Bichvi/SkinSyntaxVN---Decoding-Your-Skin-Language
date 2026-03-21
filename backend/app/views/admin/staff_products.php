<?php
$items = $items ?? [];
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

<div class="container-fluid p-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Cập nhật thông tin sản phẩm</h1>
            <p class="text-muted mb-0">Nhân viên có thể chỉnh sửa nhanh thông tin hiển thị của sản phẩm.</p>
        </div>
    </div>

    <form class="row g-2 align-items-end mb-3" method="GET" action="index.php">
        <input type="hidden" name="r" value="staff_products">
        <div class="col-12 col-lg-8">
            <label class="form-label small text-muted mb-1">Tìm kiếm nhanh sản phẩm</label>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Nhập mã SP, tên sản phẩm, thương hiệu, danh mục, loại da...">
            </div>
        </div>
        <div class="col-12 col-lg-4 d-grid d-md-flex gap-2">
            <button type="submit" class="btn btn-primary w-100">Tìm kiếm</button>
            <a href="index.php?r=staff_products" class="btn btn-outline-secondary w-100">Xóa lọc</a>
        </div>
    </form>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Mã SP</th>
                        <th>Tên sản phẩm</th>
                        <th class="text-end">Giá bán</th>
                        <th>Loại da</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Không có sản phẩm phù hợp.</td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= h($item['ma_san_pham'] ?? $item['id'] ?? '') ?></td>
                                <td class="fw-semibold"><?= h($item['ten_san_pham'] ?? '') ?></td>
                                <td class="text-end"><?= vnd($item['gia_ban'] ?? 0) ?></td>
                                <td><?= h($item['loai_da'] ?? 'Chưa cập nhật') ?></td>
                                <td class="text-end">
                                    <a href="index.php?r=staff_product_edit&id=<?= urlencode((string)($item['ma_san_pham'] ?? $item['id'] ?? '')) ?>" class="btn btn-sm btn-outline-primary">Cập nhật</a>
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
                    <a class="page-link" href="index.php?r=staff_products&page=<?= max(1, $currentPage - 1) ?>&q=<?= urlencode($q) ?>">Trước</a>
                </li>
                <?php foreach ($paginationItems as $item): ?>
                    <?php if (is_string($item)): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php else: ?>
                        <li class="page-item <?= $item === $currentPage ? 'active' : '' ?>">
                            <a class="page-link" href="index.php?r=staff_products&page=<?= $item ?>&q=<?= urlencode($q) ?>"><?= $item ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="index.php?r=staff_products&page=<?= min($totalPages, $currentPage + 1) ?>&q=<?= urlencode($q) ?>">Sau</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>