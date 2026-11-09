<?php
// Duyệt nhãn công dụng do LLM gợi ý (U07). Biến từ AdminTagController::reviewIndex().
$items = $items ?? [];
$counts = $counts ?? ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$status = $status ?? 'pending';
$onlyDiff = !empty($onlyDiff);
$page = max(1, (int)($page ?? 1));
$perPage = (int)($perPage ?? 25);
$csrf = (string)($csrf ?? '');
$groups = $groups ?? [];
$labels = $labels ?? [];
$fields = $fields ?? [];

$tabs = ['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Đã từ chối'];
$names = static function (string $group, $keys) use ($groups): array {
    $out = [];
    foreach (is_array($keys) ? $keys : [] as $key) {
        $out[] = (string)($groups[$group][$key] ?? $key);
    }
    return $out;
};
$badges = static function (string $group, $keys, string $class) use ($names): string {
    $list = $names($group, $keys);
    if ($list === []) {
        return '<span class="text-muted small">&mdash;</span>';
    }
    $html = '';
    foreach ($list as $label) {
        $html .= '<span class="badge ' . $class . ' me-1 mb-1">' . h($label) . '</span>';
    }
    return $html;
};
$formatDate = static function ($value): string {
    if (is_object($value) && method_exists($value, 'toDateTime')) {
        return $value->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i');
    }
    return '';
};
$sourceLabels = ['manual' => 'Nhập tay', 'llm_approved' => 'AI đã duyệt'];
$baseQuery = ['r' => 'admin_tag_review', 'status' => $status] + ($onlyDiff ? ['diff' => '1'] : []);
$total = (int)($counts[$status] ?? 0);
$lastPage = max(1, (int)ceil($total / max(1, $perPage)));
?>

<div class="container-fluid px-4 py-4" data-testid="tag-review">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);">Duyệt nhãn công dụng do AI gợi ý</h1>
            <p class="text-muted mb-0 small">AI chỉ <strong>đề xuất</strong> từ thành phần và mô tả; nhãn chỉ có hiệu lực sau khi bạn duyệt. Nhãn nhập tay luôn được ưu tiên và không bị ghi đè.</p>
        </div>
    </div>

    <ul class="nav nav-pills mb-3">
        <?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item">
                <a class="nav-link <?= $status === $key ? 'active' : '' ?>" href="index.php?<?= h(http_build_query(['r' => 'admin_tag_review', 'status' => $key])) ?>">
                    <?= h($label) ?> <span class="badge text-bg-light ms-1"><?= (int)($counts[$key] ?? 0) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
        <li class="nav-item ms-lg-auto">
            <a class="nav-link <?= $onlyDiff ? 'active' : '' ?>" href="index.php?<?= h(http_build_query(['r' => 'admin_tag_review', 'status' => $status] + ($onlyDiff ? [] : ['diff' => '1']))) ?>">
                Chỉ hiện đề xuất khác nhãn hiện tại
            </a>
        </li>
    </ul>

    <?php if ($items === []): ?>
        <div class="admin-card p-5 text-center text-muted" style="border-radius: 8px !important;">
            Không có gợi ý nào ở trạng thái &ldquo;<?= h($tabs[$status] ?? $status) ?>&rdquo;.
            <?php if ($status === 'pending'): ?>
                <div class="small mt-2">Chưa có gợi ý chờ duyệt.</div>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <form method="POST" action="index.php?r=admin_tag_review_act" id="tagReviewForm">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="status" value="<?= h($status) ?>">

        <?php if ($status === 'pending'): ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <label class="form-check-label small me-2"><input type="checkbox" class="form-check-input me-1" id="tagSelectAll"> Chọn tất cả</label>
            <button type="submit" name="act" value="approve_bulk" class="btn btn-sm btn-success" data-testid="bulk-approve" onclick="return confirm('Duyệt tất cả gợi ý đã chọn?');">Duyệt các mục đã chọn</button>
            <button type="submit" name="act" value="reject_bulk" class="btn btn-sm btn-outline-danger" data-testid="bulk-reject" onclick="return confirm('Từ chối tất cả gợi ý đã chọn?');">Từ chối các mục đã chọn</button>
            <span class="text-muted small">Tối đa 100 mục mỗi lần.</span>
        </div>
        <?php endif; ?>

        <div class="admin-card p-0 overflow-hidden" style="border-radius: 8px !important;">
            <div class="table-responsive">
                <table class="table admin-table align-middle mb-0">
                    <thead>
                        <tr>
                            <?php if ($status === 'pending'): ?><th style="width:34px"></th><?php endif; ?>
                            <th style="min-width:220px">Sản phẩm</th>
                            <th style="min-width:230px">Đề xuất của AI</th>
                            <th style="min-width:200px">Suy luận từ thành phần</th>
                            <th style="min-width:200px">Hiện tại (đã lưu)</th>
                            <th style="min-width:230px">Căn cứ AI</th>
                            <?php if ($status === 'pending'): ?><th style="min-width:200px">Thao tác</th><?php else: ?><th>Xử lý</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $row): ?>
                        <?php
                        $sid = (string)($row['id'] ?? '');
                        $pid = (string)($row['ma_san_pham'] ?? '');
                        $product = $row['product'] ?? [];
                        $suggested = $row['suggested'] ?? [];
                        $rule = $row['rule'] ?? [];
                        $diff = $row['diff'] ?? [];
                        $locked = ($product['tag_source'] ?? '') === 'manual';
                        ?>
                        <tr data-suggestion="<?= h($sid) ?>" data-product="<?= h($pid) ?>">
                            <?php if ($status === 'pending'): ?>
                                <td><input type="checkbox" class="form-check-input tag-pick" name="ids[]" value="<?= h($sid) ?>" aria-label="Chọn gợi ý sản phẩm <?= h($pid) ?>"></td>
                            <?php endif; ?>
                            <td>
                                <a href="index.php?r=admin_sp_sua&amp;id=<?= urlencode($pid) ?>" class="fw-semibold text-decoration-none"><?= h($product['name'] ?? ('#' . $pid)) ?></a>
                                <div class="text-muted small">Mã #<?= h($pid) ?></div>
                                <?php if (!empty($product['tag_source'])): ?>
                                    <span class="badge text-bg-warning mt-1"><?= h($sourceLabels[$product['tag_source']] ?? $product['tag_source']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($diff['differs'])): ?><span class="badge text-bg-info mt-1">Khác gợi ý</span><?php endif; ?>
                            </td>
                            <td>
                                <?php foreach ($groups as $group => $_items): ?>
                                    <div class="mb-1"><span class="small text-muted"><?= h($labels[$group] ?? $group) ?>:</span><br><?= $badges($group, $suggested[$group] ?? [], 'text-bg-success') ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php foreach ($groups as $group => $_items): ?>
                                    <div class="mb-1"><span class="small text-muted"><?= h($labels[$group] ?? $group) ?>:</span><br><?= $badges($group, $rule[$group] ?? [], 'text-bg-secondary') ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php foreach ($groups as $group => $_items): ?>
                                    <div class="mb-1"><span class="small text-muted"><?= h($labels[$group] ?? $group) ?>:</span><br><?= $badges($group, $product['current'][$group] ?? [], 'text-bg-primary') ?></div>
                                <?php endforeach; ?>
                            </td>
                            <td class="small">
                                <?php $evidence = is_array($row['evidence'] ?? null) ? $row['evidence'] : []; ?>
                                <?php if ($evidence === []): ?>
                                    <span class="text-muted">Không có</span>
                                <?php else: ?>
                                    <ul class="mb-0 ps-3">
                                        <?php foreach (array_slice($evidence, 0, 4) as $line): ?><li><?= h($line) ?></li><?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <?php if (!empty($row['dropped'])): ?><div class="text-muted mt-1">AI trả key lạ đã bị loại: <?= h(implode('; ', array_slice($row['dropped'], 0, 2))) ?></div><?php endif; ?>
                                <div class="text-muted mt-1"><?= h($row['model'] ?? '') ?> &middot; <?= h($formatDate($row['created_at'] ?? null)) ?></div>
                            </td>
                            <td>
                                <?php if ($status === 'pending'): ?>
                                    <?php if ($locked): ?>
                                        <div class="small text-danger mb-1">Sản phẩm đã có nhãn nhập tay: không thể ghi đè.</div>
                                    <?php endif; ?>
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        <button type="submit" name="act" value="approve:<?= h($sid) ?>" class="btn btn-sm btn-success" data-testid="approve" <?= $locked ? 'disabled' : '' ?>>Duyệt</button>
                                        <button type="submit" name="act" value="reject:<?= h($sid) ?>" class="btn btn-sm btn-outline-danger" data-testid="reject">Từ chối</button>
                                    </div>
                                    <?php if (!$locked): ?>
                                    <details>
                                        <summary class="small">Sửa rồi duyệt</summary>
                                        <?php foreach ($groups as $group => $items2): ?>
                                            <div class="mt-2">
                                                <div class="small fw-semibold"><?= h($labels[$group] ?? $group) ?></div>
                                                <?php foreach ($items2 as $key => $label): ?>
                                                    <?php $field = (string)($fields[$group] ?? $group); ?>
                                                    <div class="form-check form-check-inline">
                                                        <input class="form-check-input" type="checkbox" name="edit[<?= h($sid) ?>][<?= h($field) ?>][]" value="<?= h($key) ?>" id="e_<?= h($sid . '_' . $field . '_' . $key) ?>" <?= in_array($key, $suggested[$group] ?? [], true) ? 'checked' : '' ?>>
                                                        <label class="form-check-label small" for="e_<?= h($sid . '_' . $field . '_' . $key) ?>"><?= h($label) ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <button type="submit" name="act" value="approve_edit:<?= h($sid) ?>" class="btn btn-sm btn-primary mt-2" data-testid="approve-edit">Lưu bản sửa &amp; duyệt</button>
                                    </details>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="small">
                                        <?= $status === 'approved' ? 'Đã duyệt' : 'Đã từ chối' ?><?= !empty($row['edited']) ? ' (có chỉnh sửa)' : '' ?><br>
                                        <span class="text-muted"><?= h($formatDate($row['reviewed_at'] ?? null)) ?></span>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <?php if ($lastPage > 1): ?>
        <nav class="mt-3" aria-label="Phân trang">
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $lastPage; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="index.php?<?= h(http_build_query($baseQuery + ['page' => $p])) ?>"><?= $p ?></a></li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function () {
    var all = document.getElementById('tagSelectAll');
    if (!all) { return; }
    all.addEventListener('change', function () {
        document.querySelectorAll('.tag-pick').forEach(function (box) { box.checked = all.checked; });
    });
})();
</script>
