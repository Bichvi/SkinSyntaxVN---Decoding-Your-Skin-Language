<?php
$items = $items ?? [];
$editing = $editing ?? null;
$q = trim((string)($q ?? ''));

$parentOptions = [];
foreach ($items as $row) {
    $rowId = (int)($row['ma_danh_muc'] ?? 0);
    $rowLevel = (int)($row['level'] ?? 1);
    $rowDirectProducts = (int)($row['direct_product_count'] ?? 0);
    if ($rowLevel < 3 && $rowDirectProducts === 0) {
        if ($editing && (int)($editing['ma_danh_muc'] ?? 0) === $rowId) {
            continue;
        }
        $parentOptions[] = $row;
    }
}
$selectedParentId = $editing ? ($editing['parent_id'] ?? null) : 1001;
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);">Quản lý danh mục sản phẩm</h1>
            <p class="text-muted mb-0 small">Cây danh mục chuẩn hóa (Single Source of Truth từ <code>danh_muc</code>). Sản phẩm chỉ được gán vào danh mục lá (Leaf).</p>
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
                    <div class="col-12">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Danh mục cha</label>
                        <select class="form-select" name="parent_id" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
                            <option value="" <?= ($selectedParentId === null || $selectedParentId === '') ? 'selected' : '' ?>>— Gốc (Cấp 1) —</option>
                            <?php foreach ($parentOptions as $pOpt): ?>
                                <?php
                                $pId = (int)($pOpt['ma_danh_muc'] ?? 0);
                                $pLevel = (int)($pOpt['level'] ?? 1);
                                $prefix = $pLevel === 2 ? '└─ ' : '';
                                ?>
                                <option value="<?= $pId ?>" <?= ((int)$selectedParentId === $pId) ? 'selected' : '' ?>>
                                    <?= h($prefix . ($pOpt['ten_danh_muc'] ?? '') . ' (#' . $pId . ' - Cấp ' . $pLevel . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Tìm danh mục theo mã, tên hoặc danh mục cha..." style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
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
                                <th style="width: 85px;">Mã</th>
                                <th>Tên danh mục (Cây phân cấp)</th>
                                <th style="width: 85px;">Cấp</th>
                                <th style="width: 160px;">Danh mục cha</th>
                                <th class="text-end" style="width: 150px;">Số SP (Trực tiếp / Tổng)</th>
                                <th class="text-end" style="width: 135px;">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Chưa có danh mục nào.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $item): ?>
                                    <?php
                                    $id = (int)($item['ma_danh_muc'] ?? 0);
                                    $level = (int)($item['level'] ?? 1);
                                    $isLeaf = !empty($item['is_leaf']);
                                    $childrenCount = (int)($item['children_count'] ?? 0);
                                    $directCount = (int)($item['direct_product_count'] ?? 0);
                                    $recursiveCount = (int)($item['recursive_product_count'] ?? ($item['so_san_pham'] ?? 0));
                                    $parentName = trim((string)($item['parent_name'] ?? ''));
                                    $indentPx = max(0, ($level - 1) * 22);
                                    $treePrefix = $level === 1 ? '' : ($level === 2 ? '├─ ' : '└── ');

                                    $canDelete = ($childrenCount === 0 && $directCount === 0);
                                    $confirmMessage = 'Bạn có chắc muốn xóa danh mục #' . $id . ' không?';
                                    ?>
                                    <tr style="<?= $level === 1 ? 'background: #F8FAFC;' : ($level === 2 && !$isLeaf ? 'background: #FCFDFD;' : '') ?>">
                                        <td>
                                            <code class="px-2 py-1 rounded fw-semibold" style="background: #F1F5F9; color: #0F172A; font-size: 0.78rem; border: 1px solid #E2E8F0;">#<?= h($id) ?></code>
                                        </td>
                                        <td>
                                            <div style="padding-left: <?= $indentPx ?>px;">
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span class="<?= $level < 3 && !$isLeaf ? 'fw-bold' : 'fw-semibold' ?>" style="color: var(--admin-text); font-size: <?= $level === 1 ? '0.92rem' : '0.86rem' ?>;">
                                                        <span class="text-muted fw-normal"><?= h($treePrefix) ?></span><?= h($item['ten_danh_muc'] ?? '') ?>
                                                    </span>
                                                    <?php if ($isLeaf): ?>
                                                        <span class="badge rounded-pill" style="background: #DCFCE7; color: #166534; font-size: 0.68rem; font-weight: 600;">Leaf</span>
                                                    <?php else: ?>
                                                        <span class="badge rounded-pill" style="background: #E0F2FE; color: #075985; font-size: 0.68rem; font-weight: 600;">Nhóm (<?= $childrenCount ?> con)</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="small fw-semibold text-muted" style="font-size: 0.78rem;">Cấp <?= $level ?></span>
                                        </td>
                                        <td>
                                            <?php if ($parentName !== ''): ?>
                                                <span class="small" style="color: var(--admin-text); font-size: 0.80rem;"><?= h($parentName) ?></span>
                                            <?php else: ?>
                                                <span class="small text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($isLeaf): ?>
                                                <span class="fw-semibold" style="color: var(--admin-text); font-size: 0.84rem;"><?= number_format($directCount, 0, ',', '.') ?></span>
                                                <span class="small text-muted" style="font-size: 0.74rem;">SP</span>
                                            <?php else: ?>
                                                <span class="small text-muted" style="font-size: 0.76rem;"><?= number_format($directCount, 0, ',', '.') ?> / </span>
                                                <span class="fw-bold" style="color: #183B2B; font-size: 0.85rem;"><?= number_format($recursiveCount, 0, ',', '.') ?></span>
                                                <span class="small text-muted" style="font-size: 0.74rem;">SP</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <a href="index.php?r=admin_categories&edit=<?= $id ?>" class="btn btn-sm btn-outline-secondary px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" title="Sửa"><i class="bi bi-pencil-square me-1"></i>Sửa</a>
                                                <form method="post" action="index.php?r=admin_category_delete" class="d-inline" onsubmit="return confirm(<?= htmlspecialchars(json_encode($confirmMessage, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>);">
                                                    <input type="hidden" name="ma_danh_muc" value="<?= $id ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" <?= !$canDelete ? 'title="Không thể xóa danh mục đang có danh mục con hoặc sản phẩm"' : 'title="Xóa"' ?>><i class="bi bi-trash me-1"></i>Xóa</button>
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