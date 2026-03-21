<?php
$orders = $orders ?? [];
$orderDetail = $orderDetail ?? null;
$statusOptions = $statusOptions ?? [];
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

    <form class="row g-2 mb-4" method="get" action="index.php">
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
                            <form method="post" action="index.php?r=<?= h(strpos($pageTitle, 'Xử lý') === 0 ? 'staff_order_status' : 'admin_order_status') ?>" class="row g-2">
                                <input type="hidden" name="ma_hoa_don" value="<?= h($orderDetail['ma_hoa_don'] ?? '') ?>">
                                <div class="col-md-8">
                                    <select class="form-select" name="trang_thai">
                                        <?php $currentStatus = (string)($orderDetail['trang_thai'] ?? ''); ?>
                                        <?php foreach ($statusOptions as $value => $label): ?>
                                            <option value="<?= h($value) ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 d-grid">
                                    <button type="submit" class="btn btn-primary">Cập nhật</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>