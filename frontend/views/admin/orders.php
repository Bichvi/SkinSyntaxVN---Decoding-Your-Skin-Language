<?php
$orders = $orders ?? [];
$orderDetail = $orderDetail ?? null;
$statusOptions = $statusOptions ?? [];
$cancelReasonOptions = $cancelReasonOptions ?? [];
$q = trim((string)($q ?? ''));
$status = trim((string)($status ?? ''));
$pageTitle = trim((string)($pageTitle ?? 'Quản lý đơn hàng'));
$allowManage = !empty($allowManage);
?>

<div class="container-fluid p-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1"><?= h($pageTitle) ?></h1>
            <p class="text-muted mb-0">Theo dõi, xác nhận, hủy và cập nhật trạng thái đơn hàng.</p>
        </div>
    </div>

    <form class="row g-2 mb-4" method="get" action="index.php" data-live-filter="true">
        <input type="hidden" name="r" value="<?= h(strpos($pageTitle, 'Xử lý') === 0 ? 'staff_orders' : 'admin_orders') ?>">
        <div class="col-md-6">
            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Tìm theo mã đơn, tên khách, email...">
        </div>
        <div class="col-md-3">
            <select class="form-select" name="status">
                <option value="">Tất cả trạng thái</option>
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-grid">
            <button type="submit" class="btn btn-outline-primary">Lọc đơn hàng</button>
        </div>
    </form>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Mã đơn</th>
                                    <th>Khách hàng</th>
                                    <th>Ngày đặt</th>
                                    <th>Tổng tiền</th>
                                    <th>Trạng thái</th>
                                    <th class="text-end">Chi tiết</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">Chưa có đơn hàng.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td>#<?= h($order['ma_hoa_don'] ?? '') ?></td>
                                            <td>
                                                <div class="fw-semibold"><?= h($order['ho_ten'] ?? 'Khách hàng') ?></div>
                                                <div class="small text-muted"><?= h($order['email'] ?? '') ?></div>
                                            </td>
                                            <td><?= h(!empty($order['ngay_dat']) ? date('d/m/Y H:i', strtotime((string)$order['ngay_dat'])) : '') ?></td>
                                            <td>
                                                <div class="fw-semibold text-danger"><?= vnd($order['tong_tien'] ?? 0) ?></div>
                                                <div class="small text-muted"><?= strtolower(trim((string)($order['hinh_thuc_thanh_toan'] ?? 'cod'))) === 'bank_transfer_qr' ? 'QR chuyển khoản' : 'COD' ?></div>
                                            </td>
                                            <td>
                                                <span class="badge rounded-pill text-bg-secondary d-inline-block mb-1"><?= h($order['trang_thai'] ?? 'moi') ?></span>
                                                <div class="small text-muted"><?= h($order['status_thanh_toan'] ?? 'Chua thanh toan') ?></div>
                                            </td>
                                            <td class="text-end">
                                                <a href="index.php?r=<?= h(strpos($pageTitle, 'Xử lý') === 0 ? 'staff_orders' : 'admin_orders') ?>&detail=<?= (int)($order['ma_hoa_don'] ?? 0) ?>" class="btn btn-sm btn-outline-primary">Xem</a>
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

        <div class="col-xl-5">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <?php if (!$orderDetail): ?>
                        <div class="text-center text-muted py-5">Chọn một đơn hàng để xem chi tiết.</div>
                    <?php else: ?>
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <h5 class="fw-bold mb-1">Đơn hàng #<?= h($orderDetail['ma_hoa_don'] ?? '') ?></h5>
                                <div class="text-muted"><?= h($orderDetail['ho_ten'] ?? '') ?> · <?= h($orderDetail['email'] ?? '') ?></div>
                            </div>
                            <span class="badge rounded-pill text-bg-secondary"><?= h($orderDetail['trang_thai'] ?? '') ?></span>
                        </div>

                        <?php if (!empty($orderDetail['ly_do_huy'])): ?>
                            <div class="alert alert-warning py-2 px-3 mb-3">
                                <div class="small text-uppercase fw-semibold">Lý do hủy đơn</div>
                                <div><?= h((string)$orderDetail['ly_do_huy']) ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="small text-muted mb-2">Địa chỉ giao hàng</div>
                        <div class="mb-3"><?= h($orderDetail['dia_chi_giao_hang'] ?? 'Chưa cập nhật') ?></div>

                        <div class="small text-muted mb-2">Thanh toán và ưu đãi</div>
                        <div class="list-group mb-3">
                            <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
                                <span>Phương thức thanh toán</span>
                                <strong><?= strtolower(trim((string)($orderDetail['hinh_thuc_thanh_toan'] ?? 'cod'))) === 'bank_transfer_qr' ? 'Chuyển khoản qua QR' : 'COD' ?></strong>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
                                <span>Trạng thái thanh toán</span>
                                <strong><?= h($orderDetail['status_thanh_toan'] ?? 'Chua thanh toan') ?></strong>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
                                <span>Tạm tính</span>
                                <strong><?= vnd($orderDetail['tam_tinh'] ?? (($orderDetail['tong_tien'] ?? 0) - ($orderDetail['phi_van_chuyen'] ?? 0))) ?></strong>
                            </div>
                            <?php if ((int)($orderDetail['so_tien_giam'] ?? 0) > 0): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
                                    <span>Mã giảm giá<?= !empty($orderDetail['ma_giam_gia']) ? ' (' . h($orderDetail['ma_giam_gia']) . ')' : '' ?></span>
                                    <strong class="text-success">-<?= vnd($orderDetail['so_tien_giam'] ?? 0) ?></strong>
                                </div>
                            <?php endif; ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
                                <span>Phí vận chuyển</span>
                                <strong><?= vnd($orderDetail['phi_van_chuyen'] ?? 0) ?></strong>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
                                <span>Tổng thanh toán</span>
                                <strong class="text-danger"><?= vnd($orderDetail['tong_tien'] ?? 0) ?></strong>
                            </div>
                        </div>

                        <div class="small text-muted mb-2">Sản phẩm trong đơn</div>
                        <div class="list-group mb-3">
                            <?php foreach (($orderDetail['items'] ?? []) as $item): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
                                    <div>
                                        <div class="fw-semibold"><?= h($item['ten_san_pham'] ?? ($item['ma_san_pham'] ?? 'Sản phẩm')) ?></div>
                                        <div class="small text-muted">SL: <?= (int)($item['so_luong'] ?? 0) ?></div>
                                    </div>
                                    <div class="fw-semibold text-danger"><?= vnd(((int)($item['don_gia'] ?? 0)) * ((int)($item['so_luong'] ?? 0))) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($allowManage): ?>
                            <?php $isStaffOrderPage = strpos($pageTitle, 'Xử lý') === 0; ?>
                            <form method="post" action="index.php?r=<?= h(strpos($pageTitle, 'Xử lý') === 0 ? 'staff_order_status' : 'admin_order_status') ?>" class="row g-2" data-order-status-form>
                                <input type="hidden" name="ma_hoa_don" value="<?= h($orderDetail['ma_hoa_don'] ?? '') ?>">
                                <div class="col-md-8">
                                    <select class="form-select" name="trang_thai" data-order-status-select>
                                        <?php $currentStatus = (string)($orderDetail['trang_thai'] ?? ''); ?>
                                        <?php $currentStatusNormalized = strtolower(trim($currentStatus)); ?>
                                        <?php $orderIsCancelled = in_array($currentStatusNormalized, ['da huy', 'đã hủy', 'huy', 'cancelled', 'canceled'], true); ?>
                                        <?php foreach ($statusOptions as $value => $label): ?>
                                            <?php $optionIsCancelled = in_array(strtolower(trim((string)$value)), ['da huy', 'đã hủy', 'huy', 'cancelled', 'canceled'], true); ?>
                                            <option value="<?= h($value) ?>" <?= $currentStatus === $value ? 'selected' : '' ?> <?= ($isStaffOrderPage && $orderIsCancelled && !$optionIsCancelled) ? 'disabled' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 d-grid">
                                    <button type="submit" class="btn btn-primary" <?= ($isStaffOrderPage && !empty($orderIsCancelled)) ? 'disabled' : '' ?>>Cập nhật</button>
                                </div>
                                <?php if (!empty($isStaffOrderPage) && !empty($orderIsCancelled)): ?>
                                    <div class="col-12">
                                        <div class="small text-danger">Đơn đã hủy nên không thể chuyển sang trạng thái khác.</div>
                                    </div>
                                <?php endif; ?>
                                <div class="col-12 d-none" data-cancel-reason-group>
                                    <label class="form-label small mb-1">Lý do hủy <span class="text-danger">*</span></label>
                                    <select class="form-select" name="ly_do_huy" data-cancel-reason-select>
                                        <option value="">Chọn lý do hủy đơn</option>
                                        <?php foreach ($cancelReasonOptions as $value => $label): ?>
                                            <option value="<?= h((string)$value) ?>"><?= h((string)$label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 d-none" data-cancel-note-group>
                                    <label class="form-label small mb-1">Ghi chú thêm cho lý do hủy</label>
                                    <textarea class="form-control" rows="2" name="ly_do_huy_bo_sung" data-cancel-note-input placeholder="Nhập thêm lý do (bắt buộc nếu chọn Lý do khác)"></textarea>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-order-status-form]');
    if (!form) {
        return;
    }

    var statusSelect = form.querySelector('[data-order-status-select]');
    var reasonGroup = form.querySelector('[data-cancel-reason-group]');
    var noteGroup = form.querySelector('[data-cancel-note-group]');
    var reasonSelect = form.querySelector('[data-cancel-reason-select]');
    var noteInput = form.querySelector('[data-cancel-note-input]');

    if (!statusSelect || !reasonGroup || !noteGroup || !reasonSelect || !noteInput) {
        return;
    }

    var isCancelled = function (value) {
        var normalized = (value || '').toString().trim().toLowerCase();
        return normalized === 'da huy' || normalized === 'đã hủy' || normalized === 'huy';
    };

    var toggleCancelFields = function () {
        var cancelled = isCancelled(statusSelect.value);
        reasonGroup.classList.toggle('d-none', !cancelled);
        noteGroup.classList.toggle('d-none', !cancelled);
        reasonSelect.required = cancelled;

        var reasonIsOther = (reasonSelect.value || '') === 'Khac';
        noteInput.required = cancelled && reasonIsOther;
    };

    statusSelect.addEventListener('change', toggleCancelFields);
    reasonSelect.addEventListener('change', toggleCancelFields);
    toggleCancelFields();
});
</script>