<?php
$items = $items ?? [];
$editing = $editing ?? null;
$q = trim((string)($q ?? ''));
?>

<div class="container-fluid p-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Quản lý danh mục</h1>
            <p class="text-muted mb-0">Thêm, cập nhật và xóa danh mục sản phẩm.</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><?= $editing ? 'Cập nhật danh mục' : 'Tạo danh mục mới' ?></h5>
                    <form method="post" action="index.php?r=admin_category_save" class="row g-3">
                        <input type="hidden" name="ma_danh_muc" value="<?= h($editing['ma_danh_muc'] ?? '') ?>">
                        <div class="col-12">
                            <label class="form-label">Tên danh mục</label>
                            <input type="text" class="form-control" name="ten_danh_muc" value="<?= h($editing['ten_danh_muc'] ?? '') ?>" required>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><?= $editing ? 'Lưu cập nhật' : 'Thêm danh mục' ?></button>
                            <?php if ($editing): ?>
                                <a href="index.php?r=admin_categories" class="btn btn-light border">Hủy</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <form class="row g-2 mb-3" method="get" action="index.php" data-live-filter="true">
                        <input type="hidden" name="r" value="admin_categories">
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Tìm danh mục theo mã hoặc tên...">
                        </div>
                        <div class="col-md-3 d-grid">
                            <button type="submit" class="btn btn-outline-primary">Tìm kiếm</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table align-middle table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Mã</th>
                                    <th>Tên danh mục</th>
                                    <th class="text-end">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($items)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-4">Chưa có danh mục nào.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($items as $item): ?>
                                        <?php
                                        $productCount = (int)($item['so_san_pham'] ?? 0);
                                        $confirmMessage = $productCount > 0
                                            ? 'Bạn có chắc muốn xóa danh mục này không? Tất cả ' . number_format($productCount, 0, ',', '.') . ' sản phẩm thuộc danh mục này đều sẽ bị xóa.'
                                            : 'Bạn có chắc muốn xóa danh mục này không?';
                                        ?>
                                        <tr>
                                            <td>#<?= h($item['ma_danh_muc'] ?? '') ?></td>
                                            <td>
                                                <div class="fw-semibold"><?= h($item['ten_danh_muc'] ?? '') ?></div>
                                                <?php if ($productCount > 0): ?>
                                                    <small class="text-muted">Có <?= number_format($productCount, 0, ',', '.') ?> sản phẩm sẽ bị xóa cùng danh mục.</small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <a href="index.php?r=admin_categories&edit=<?= (int)($item['ma_danh_muc'] ?? 0) ?>" class="btn btn-sm btn-outline-warning">Sửa</a>
                                                <form method="post" action="index.php?r=admin_category_delete" class="d-inline" onsubmit="return confirm(<?= htmlspecialchars(json_encode($confirmMessage, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>);">
                                                    <input type="hidden" name="ma_danh_muc" value="<?= h($item['ma_danh_muc'] ?? '') ?>">
                                                    <input type="hidden" name="delete_products" value="<?= $productCount > 0 ? '1' : '0' ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Xóa</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>