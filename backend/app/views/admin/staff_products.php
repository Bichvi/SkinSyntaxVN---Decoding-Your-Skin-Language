<?php
$items = $items ?? [];
$totalPages = max(1, (int)ceil(($total ?? 0) / max(1, $perPage ?? 20)));
$currentPage = max(1, (int)($page ?? 1));
$q = trim((string)($q ?? ''));
?>

<div class="container-fluid p-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Cập nhật thông tin sản phẩm</h1>
            <p class="text-muted mb-0">Nhân viên có thể chỉnh sửa nhanh thông tin hiển thị của sản phẩm.</p>
        </div>
    </div>

    <form class="row g-2 mb-3" method="GET" action="index.php">
        <input type="hidden" name="r" value="staff_products">
        <div class="col-12 col-md-8">
            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Tìm theo mã hoặc tên sản phẩm...">
        </div>
        <div class="col-12 col-md-4 d-grid">
            <button type="submit" class="btn btn-outline-primary">Tìm kiếm</button>
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
        <nav class="mt-3">
            <ul class="pagination mb-0">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                        <a class="page-link" href="index.php?r=staff_products&page=<?= $i ?>&q=<?= urlencode($q) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>