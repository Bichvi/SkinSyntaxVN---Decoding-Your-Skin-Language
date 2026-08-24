<?php
$items = $items ?? [];
$editing = $editing ?? null;
$q = trim((string)($q ?? ''));
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);">Quản lý danh mục sản phẩm</h1>
            <p class="text-muted mb-0 small">Thêm mới, chỉnh sửa và phân loại ngành hàng mỹ phẩm.</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="admin-card mb-0 p-3.5" style="border-radius: 8px !important;">
                <h6 class="fw-bold mb-3" style="color: var(--admin-text);"><?= $editing ? 'Cập nhật danh mục' : 'Tạo danh mục mới' ?></h6>
                <form method="post" action="index.php?r=admin_category_save" class="row g-3">
                    <input type="hidden" name="ma_danh_muc" value="<?= h($editing['ma_danh_muc'] ?? '') ?>">
                    <div class="col-12">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Tên danh mục *</label>
                        <input type="text" class="form-control" name="ten_danh_muc" value="<?= h($editing['ten_danh_muc'] ?? '') ?>" required style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-sm text-white fw-semibold px-3" style="background: #183B2B; border-radius: 6px;"><?= $editing ? 'Lưu cập nhật' : 'Thêm danh mục' ?></button>
                        <?php if ($editing): ?>
                            <a href="index.php?r=admin_categories" class="btn btn-sm btn-outline-secondary px-3" style="border-radius: 6px;">Hủy bỏ</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="admin-card p-0 overflow-hidden mb-0" style="border-radius: 8px !important;">
                <div class="p-3 border-bottom background-subtle">
                    <form class="row g-2" method="get" action="index.php" data-live-filter="true">
                        <input type="hidden" name="r" value="admin_categories">
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Tìm danh mục theo mã hoặc tên..." style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
                        </div>
                        <div class="col-md-3 d-grid">
                            <button type="submit" class="btn btn-sm text-white fw-semibold" style="background: #183B2B; border-radius: 6px;">Tìm kiếm</button>
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 90px;">Mã</th>
                                <th>Tên danh mục</th>
                                <th class="text-end" style="width: 140px;">Thao tác</th>
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
                                        <td><code class="px-2 py-1 rounded fw-semibold" style="background: #F1F5F9; color: #0F172A; font-size: 0.78rem; border: 1px solid #E2E8F0;">#<?= h($item['ma_danh_muc'] ?? '') ?></code></td>
                                        <td>
                                            <div class="fw-semibold" style="color: var(--admin-text); font-size: 0.86rem;"><?= h($item['ten_danh_muc'] ?? '') ?></div>
                                            <?php if ($productCount > 0): ?>
                                                <div class="small text-muted" style="font-size: 0.76rem;">Có <?= number_format($productCount, 0, ',', '.') ?> sản phẩm thuộc danh mục này.</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <a href="index.php?r=admin_categories&edit=<?= (int)($item['ma_danh_muc'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" title="Sửa"><i class="bi bi-pencil-square me-1"></i>Sửa</a>
                                                <form method="post" action="index.php?r=admin_category_delete" class="d-inline" onsubmit="return confirm(<?= htmlspecialchars(json_encode($confirmMessage, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>);">
                                                    <input type="hidden" name="ma_danh_muc" value="<?= h($item['ma_danh_muc'] ?? '') ?>">
                                                    <input type="hidden" name="delete_products" value="<?= $productCount > 0 ? '1' : '0' ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" title="Xóa"><i class="bi bi-trash me-1"></i>Xóa</button>
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
        </div>
    </div>
</div>