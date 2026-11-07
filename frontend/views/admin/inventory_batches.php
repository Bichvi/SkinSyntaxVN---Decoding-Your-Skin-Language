<?php
$filters = is_array($filters ?? null) ? $filters : [];
$batches = is_array($batches ?? null) ? $batches : [];
$productOptions = is_array($productOptions ?? null) ? $productOptions : [];
$autoSeedPreview = is_array($autoSeedPreview ?? null) ? $autoSeedPreview : [];
$diverseSeedPreview = is_array($diverseSeedPreview ?? null) ? $diverseSeedPreview : [];
$statusLabels = [
    'available' => 'Đang khả dụng',
    'quarantined' => 'Tạm cách ly',
    'expired' => 'Đã hết hạn',
    'depleted' => 'Đã hết tồn',
];
$movementOptions = [
    'sale' => 'Bán hàng',
    'reserve' => 'Giữ cho đơn/live',
    'release' => 'Hủy giữ hàng',
    'return' => 'Khách trả hàng',
    'damage' => 'Hư hỏng',
    'adjustment_increase' => 'Điều chỉnh tăng',
    'adjustment_decrease' => 'Điều chỉnh giảm',
];
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);">Kho & Lô hàng</h1>
            <div class="text-muted small">Quản lý ngày sản xuất, hạn sử dụng và tồn kho theo từng lô.</div>
        </div>
        <span class="badge rounded-pill text-bg-light border px-3 py-2">AI Idol đọc dữ liệu lô hiện có</span>
    </div>

    <div class="admin-card p-3 mb-3" style="border-radius: 8px !important; border-left:4px solid #d89b35;">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
                <h2 class="h6 fw-bold mb-1" style="color:var(--admin-text);">Tự bổ sung dữ liệu lô cho sản phẩm còn thiếu</h2>
                <div class="small text-muted mb-2">
                    Tạo dữ liệu mẫu có thể chạy ngay cho bộ lọc AI: ngày sản xuất cách hiện tại 6 tháng, hạn 24 tháng, còn khoảng 18 tháng.
                    Không ghi đè lô cũ và không sửa sản phẩm gốc.
                </div>
                <div class="d-flex flex-wrap gap-2 small">
                    <span class="badge text-bg-success">Sẵn sàng: <?= number_format((int)($autoSeedPreview['ready'] ?? 0), 0, ',', '.') ?></span>
                    <span class="badge text-bg-warning">Cần xác minh: <?= number_format((int)($autoSeedPreview['needs_verification'] ?? 0), 0, ',', '.') ?></span>
                    <span class="badge text-bg-light border">Kho mặc định: AUTO-DEFAULT</span>
                </div>
            </div>
            <form method="POST" action="index.php?r=admin_inventory_auto_seed" class="d-flex flex-column flex-sm-row align-items-sm-end gap-2" onsubmit="return confirm('Tạo dữ liệu mẫu cho các sản phẩm chưa có lô? Dữ liệu sẽ được gắn nhãn cần xác minh và không ghi đè lô cũ.');">
                <div>
                    <label class="form-label small fw-semibold text-muted mb-1">Kho nhận dữ liệu mẫu</label>
                    <input name="warehouse_id" value="AUTO-DEFAULT" maxlength="80" class="form-control form-control-sm" style="min-width:170px;font-size:.8rem;">
                </div>
                <input type="hidden" name="shelf_life_months" value="24">
                <input type="hidden" name="remaining_months" value="18">
                <button type="submit" class="btn btn-sm text-white fw-semibold" style="background:#a66d19;border-radius:6px;">Tự tạo dữ liệu mẫu</button>
            </form>
        </div>
        <div class="small text-muted mt-2">Lưu ý: ngày tháng tự sinh chỉ phục vụ demo/bộ lọc. Khi có phiếu nhập hoặc ảnh nhãn thật, hãy tạo lô chính thức để thay thế.</div>
    </div>

    <div class="admin-card p-3 mb-3" style="border-radius: 8px !important; border-left:4px solid #4d8f72;">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div class="flex-grow-1">
                <h2 class="h6 fw-bold mb-1" style="color:var(--admin-text);">Tạo bộ dữ liệu đa dạng để test bộ lọc</h2>
                <div class="small text-muted mb-2">
                    Sinh tối đa 1.000 lô test. Lô bình thường nằm ở <code>AUTO-SCENARIOS</code>; lô đã hết hạn tự tách vào kho cách ly <code>AUTO-EXPIRED</code>.
                    Không ghi đè dữ liệu thật; chạy lại cũng không tạo trùng.
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach (($diverseSeedPreview['profiles'] ?? []) as $profile): ?>
                        <span class="badge text-bg-light border" title="<?= h($profile['label'] ?? '') ?>"><?= h($profile['label'] ?? '') ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="small text-muted mt-2">
                    Sản phẩm đủ điều kiện: <?= number_format((int)($diverseSeedPreview['eligible_products'] ?? 0), 0, ',', '.') ?> ·
                    Chưa có trong bộ test: <?= number_format((int)($diverseSeedPreview['ready'] ?? 0), 0, ',', '.') ?>
                </div>
            </div>
            <form method="POST" action="index.php?r=admin_inventory_diverse_seed" class="d-flex flex-column flex-sm-row align-items-sm-end gap-2" onsubmit="return confirm('Tạo bộ dữ liệu đa dạng để test? Dữ liệu chỉ nằm trong kho AUTO-SCENARIOS và được gắn nhãn demo cần xác minh.');">
                <div>
                    <label class="form-label small fw-semibold text-muted mb-1">Kho dữ liệu test</label>
                    <input name="warehouse_id" value="AUTO-SCENARIOS" maxlength="80" class="form-control form-control-sm" style="min-width:170px;font-size:.8rem;">
                </div>
                <div>
                    <label class="form-label small fw-semibold text-muted mb-1">Số lô tối đa</label>
                    <select name="limit" class="form-select form-select-sm" style="min-width:110px;font-size:.8rem;">
                        <option value="500">500</option>
                        <option value="1000" selected>1.000</option>
                        <option value="2000">2.000</option>
                        <option value="3000">3.000</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-sm text-white fw-semibold" style="background:#3d765c;border-radius:6px;">Sinh nhiều dạng để test</button>
            </form>
        </div>
        <div class="small text-muted mt-2">Sau khi tạo, lô hết hạn không xuất hiện trong danh sách bán hàng mặc định. Dùng nút bên dưới để mở riêng kho cách ly. Các lô đều có nhãn “Dữ liệu tự tạo · cần xác minh”.</div>
        <div class="mt-2"><a class="btn btn-sm btn-outline-danger" href="index.php?r=admin_inventory_batches&amp;warehouse_id=AUTO-EXPIRED&amp;status=expired&amp;show_expired=1">Xem kho cách ly hết hạn</a></div>
    </div>

    <div class="admin-card p-3 mb-3" style="border-radius: 8px !important; border-left:4px solid #5877a8;">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
                <h2 class="h6 fw-bold mb-1" style="color:var(--admin-text);">Tạo đúng 10 lô theo ngày HSD cần test</h2>
                <div class="small text-muted mb-2">Sinh 10 lô demo ở kho riêng, dùng đúng các ngày: <strong>28/09/2028</strong>, <strong>29/03/2028</strong>, <strong>01/05/2028</strong> và <strong>02/03/2029</strong>.</div>
                <div class="d-flex flex-wrap gap-2 small">
                    <span class="badge text-bg-light border">28/09/2028 × 3</span>
                    <span class="badge text-bg-light border">29/03/2028 × 3</span>
                    <span class="badge text-bg-light border">01/05/2028 × 2</span>
                    <span class="badge text-bg-light border">02/03/2029 × 2</span>
                </div>
            </div>
            <form method="POST" action="index.php?r=admin_inventory_fixed_expiry_seed" class="d-flex flex-column flex-sm-row align-items-sm-end gap-2" onsubmit="return confirm('Tạo 10 lô demo theo đúng 4 ngày HSD này? Dữ liệu chỉ nằm trong kho AUTO-FIXED-EXPIRY.');">
                <div>
                    <label class="form-label small fw-semibold text-muted mb-1">Kho dữ liệu test</label>
                    <input name="warehouse_id" value="AUTO-FIXED-EXPIRY" maxlength="80" class="form-control form-control-sm" style="min-width:180px;font-size:.8rem;">
                </div>
                <button type="submit" class="btn btn-sm text-white fw-semibold" style="background:#5877a8;border-radius:6px;">Tạo 10 lô cố định</button>
            </form>
        </div>
        <div class="small text-muted mt-2">Mỗi sản phẩm được tạo một lô, số lượng demo 30–60; chạy lại không tạo trùng.</div>
    </div>

    <div class="admin-card p-3 mb-3" style="border-radius: 8px !important;">
        <h2 class="h6 fw-bold mb-3" style="color: var(--admin-text);">Thêm lô hàng mới</h2>
        <form method="POST" action="index.php?r=admin_inventory_batch_save" class="row g-3 align-items-end">
            <div class="col-12 col-lg-3">
                <label class="form-label small fw-semibold text-muted">Sản phẩm</label>
                <select name="product_id" required class="form-select" style="font-size:.85rem;">
                    <option value="">Chọn sản phẩm</option>
                    <?php foreach ($productOptions as $product): ?>
                        <option value="<?= h($product['product_id'] ?? '') ?>"><?= h(($product['product_id'] ?? '') . ' · ' . ($product['name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$productOptions): ?><div class="form-text">Chưa tải được danh sách sản phẩm.</div><?php endif; ?>
            </div>
            <div class="col-12 col-md-4 col-lg-2">
                <label class="form-label small fw-semibold text-muted">Mã lô</label>
                <input name="batch_code" required maxlength="80" class="form-control" placeholder="LOT-2026-09-A" style="font-size:.85rem;">
            </div>
            <div class="col-12 col-md-4 col-lg-2">
                <label class="form-label small fw-semibold text-muted">Mã kho</label>
                <input name="warehouse_id" required maxlength="80" class="form-control" placeholder="KHO-HN-01" style="font-size:.85rem;">
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small fw-semibold text-muted">Ngày sản xuất</label>
                <input type="date" name="manufactured_on" required class="form-control" style="font-size:.85rem;">
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label small fw-semibold text-muted">Hạn sử dụng</label>
                <input type="date" name="expires_on" required class="form-control" style="font-size:.85rem;">
            </div>
            <div class="col-6 col-md-4 col-lg-1">
                <label class="form-label small fw-semibold text-muted">Số lượng</label>
                <input type="number" name="quantity" required min="1" step="1" class="form-control" placeholder="0" style="font-size:.85rem;">
            </div>
            <div class="col-12 col-lg-12 d-flex justify-content-end">
                <button type="submit" class="btn btn-sm text-white fw-semibold px-4" style="background:#183B2B;border-radius:6px;">+ Thêm lô & ghi nhận nhập kho</button>
            </div>
        </form>
    </div>

    <div class="admin-card p-3 mb-3" style="border-radius: 8px !important;">
        <form method="GET" action="index.php" class="row g-2 align-items-end" data-live-filter="true" data-live-target="#batchResults">
            <input type="hidden" name="r" value="admin_inventory_batches">
            <?php if (($filters['show_expired'] ?? '') === '1'): ?><input type="hidden" name="show_expired" value="1"><?php endif; ?>
            <div class="col-12 col-lg-3"><label class="form-label small fw-semibold text-muted">Tìm mã SP, mã lô, mã kho</label><input name="q" autocomplete="off" value="<?= h($filters['q'] ?? '') ?>" class="form-control" placeholder="Ví dụ: 1188 hoặc LOT-2026" style="font-size:.85rem;"></div>
            <div class="col-12 col-md-4 col-lg-2"><label class="form-label small fw-semibold text-muted">Mã sản phẩm</label><input name="product_id" autocomplete="off" value="<?= h($filters['product_id'] ?? '') ?>" class="form-control" placeholder="1188" style="font-size:.85rem;"></div>
            <div class="col-12 col-md-4 col-lg-2"><label class="form-label small fw-semibold text-muted">Mã kho</label><input name="warehouse_id" autocomplete="off" value="<?= h($filters['warehouse_id'] ?? '') ?>" class="form-control" placeholder="KHO-HN-01" style="font-size:.85rem;"></div>
            <div class="col-6 col-md-4 col-lg-1"><label class="form-label small fw-semibold text-muted">HSD từ</label><input type="date" name="expires_from" value="<?= h($filters['expires_from'] ?? '') ?>" class="form-control" style="font-size:.8rem;"></div>
            <div class="col-6 col-md-4 col-lg-1"><label class="form-label small fw-semibold text-muted">HSD đến</label><input type="date" name="expires_to" value="<?= h($filters['expires_to'] ?? '') ?>" class="form-control" style="font-size:.8rem;"></div>
            <div class="col-12 col-md-4 col-lg-2"><label class="form-label small fw-semibold text-muted">Trạng thái</label><select name="status" class="form-select" style="font-size:.85rem;"><option value="">Tất cả</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?= h($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-lg-1 d-grid"><button class="btn btn-sm text-white fw-semibold" style="background:#183B2B;border-radius:6px;">Lọc</button></div>
        </form>
    </div>

    <div class="admin-card p-0 overflow-hidden live-region" id="batchResults" aria-live="polite" style="border-radius: 8px !important;">
        <div class="d-flex justify-content-between align-items-center px-3 py-3 border-bottom">
            <div class="fw-semibold" style="color:var(--admin-text);">Danh sách lô hàng</div>
            <div class="small text-muted"><?= number_format(count($batches), 0, ',', '.') ?> lô trong kết quả</div>
        </div>
        <div class="table-responsive">
            <table class="table admin-table align-middle mb-0">
                <thead><tr><th>Sản phẩm</th><th>Lô / Kho</th><th>Ngày sản xuất</th><th>Hạn sử dụng</th><th class="text-end">Khả dụng</th><th>Trạng thái</th><th style="min-width:290px;">Điều chỉnh</th></tr></thead>
                <tbody>
                <?php if ($batches): foreach ($batches as $batch): ?>
                    <?php $remainingDays = $batch['remaining_days']; $expired = $remainingDays !== null && $remainingDays <= 0; ?>
                    <tr>
                        <td><div class="fw-semibold" style="color:var(--admin-text);font-size:.84rem;max-width:260px;"><?= h($batch['product_name'] ?? '') ?></div><small class="text-muted">Mã <?= h($batch['product_id'] ?? '') ?></small></td>
                        <td><code><?= h($batch['batch_code'] ?? '') ?></code><br><small class="text-muted"><?= h($batch['warehouse_id'] ?? '') ?><?php if (($batch['source_warehouse_id'] ?? '') !== '' && ($batch['source_warehouse_id'] ?? '') !== ($batch['warehouse_id'] ?? '')): ?> · gốc <?= h($batch['source_warehouse_id']) ?><?php endif; ?></small></td>
                        <td class="small"><?= h($batch['manufactured_on'] ?? '—') ?></td>
                        <td class="small"><strong class="<?= $expired ? 'text-danger' : '' ?>"><?= h($batch['expires_on'] ?? '—') ?></strong><br><small class="text-muted"><?= $remainingDays === null ? 'Chưa rõ' : ($expired ? 'Đã hết hạn' : 'Còn ' . number_format($remainingDays, 0, ',', '.') . ' ngày') ?></small></td>
                        <td class="text-end"><strong><?= number_format((int)($batch['available_quantity'] ?? 0), 0, ',', '.') ?></strong><br><small class="text-muted">Tổng nhập <?= number_format((int)($batch['initial_quantity'] ?? 0), 0, ',', '.') ?></small></td>
                        <td>
                            <span class="status-pill <?= $expired || ($batch['status'] ?? '') !== 'available' ? 'status-pill-cancelled' : 'status-pill-completed' ?>"><?= h($statusLabels[$batch['status'] ?? ''] ?? ($batch['status'] ?? '')) ?></span>
                            <?php if (($batch['data_source'] ?? '') === 'auto_generated_demo' || !empty($batch['needs_verification'])): ?>
                                <br><small class="text-warning-emphasis">Dữ liệu tự tạo · cần xác minh</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" action="index.php?r=admin_inventory_movement" class="row g-1 align-items-center">
                                <input type="hidden" name="batch_id" value="<?= h($batch['id'] ?? '') ?>">
                                <div class="col-5"><select name="type" class="form-select form-select-sm" style="font-size:.74rem;"><?php foreach ($movementOptions as $value => $label): ?><option value="<?= h($value) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
                                <div class="col-2"><input type="number" name="quantity" min="1" step="1" required class="form-control form-control-sm" placeholder="SL" style="font-size:.74rem;"></div>
                                <div class="col-3"><input name="note" maxlength="120" class="form-control form-control-sm" placeholder="Ghi chú" style="font-size:.74rem;"></div>
                                <div class="col-2 d-grid"><button class="btn btn-sm btn-outline-success" style="font-size:.72rem;">Lưu</button></div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center text-muted py-5">Chưa có lô hàng phù hợp. Hãy thêm lô đầu tiên ở phía trên.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
