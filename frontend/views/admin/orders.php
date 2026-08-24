<?php
$orders = $orders ?? [];
$orderDetail = $orderDetail ?? null;
$statusOptions = $statusOptions ?? [];
$cancelReasonOptions = $cancelReasonOptions ?? [];
$q = trim((string)($q ?? ''));
$status = trim((string)($status ?? ''));
$pageTitle = trim((string)($pageTitle ?? 'Quản lý đơn hàng'));
$allowManage = !empty($allowManage);

$formatAdminOrderDate = static function ($value, string $emptyText = 'Chưa có ngày đặt'): string {
    if ($value instanceof \MongoDB\BSON\UTCDateTime) {
        return $value->toDateTime()->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('d/m/Y H:i');
    }
    $text = trim((string)($value ?? ''));
    if ($text === '' || $text === '0') {
        return $emptyText;
    }
    $timestamp = strtotime($text);
    if ($timestamp === false || $timestamp <= 0) {
        return $emptyText;
    }
    return date('d/m/Y H:i', $timestamp);
};

$resolveAdminItemImg = static function (array $item): string {
    $raw = trim((string)($item['link_hinh_anh'] ?? $item['hinh_anh'] ?? $item['image'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $parts = preg_split('/\s*\|\s*/', $raw) ?: [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        if ($part === '') continue;
        if (filter_var($part, FILTER_VALIDATE_URL)) {
            return $part;
        }
        if (strpos($part, 'uploads/') === 0 || strpos($part, '/uploads/') === 0) {
            return BASE_URL . '/' . ltrim($part, '/');
        }
        return BASE_URL . '/uploads/products/' . rawurlencode($part);
    }
    return '';
};

$isFilteringPending = ($status === 'cho_xu_ly' || $status === 'pending');
$pendingCountInList = 0;
foreach ($orders as $oItem) {
    $stN = trim((string)($oItem['trang_thai_normalized'] ?? ''));
    if ($stN === 'pending' || $stN === 'cho_xu_ly') {
        $pendingCountInList++;
    }
}
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);"><?= h($pageTitle) ?></h1>
            <p class="text-muted mb-0 small">Theo dõi, xác nhận, hủy và cập nhật trạng thái đơn hàng mua sắm.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="index.php?r=admin_orders&export=excel<?= $q !== '' ? '&q=' . urlencode($q) : '' ?><?= $status !== '' ? '&status=' . urlencode($status) : '' ?>" class="btn btn-outline-secondary btn-sm px-3 py-1.5 fw-semibold" style="border-radius: 6px; font-size: 0.82rem;" title="Tải toàn bộ danh sách đơn hàng ra file Excel / CSV">
                <i class="bi bi-file-earmark-excel me-1 text-success"></i> Xuất CSV / Excel
            </a>
            <?php if ($pendingCountInList > 0): ?>
                <a href="index.php?r=<?= h(strpos($pageTitle, 'Xử lý') === 0 ? 'staff_orders' : 'admin_orders') ?>&status=cho_xu_ly" class="btn btn-warning btn-sm px-3 py-1.5 fw-semibold" style="border-radius: 6px; font-size: 0.82rem;">
                    <i class="bi bi-hourglass-split me-1"></i> Có <?= $pendingCountInList ?> đơn chờ duyệt
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isFilteringPending): ?>
        <div class="alert border-0 mb-3 d-flex align-items-center justify-content-between p-3" style="background: #FEF3C7; color: #92400E; border-radius: 6px; border: 1px solid #FDE68A !important;">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <div>
                    <strong style="font-size: 0.88rem;">Đang lọc: Danh sách các đơn hàng đang chờ bạn duyệt xử lý</strong>
                    <div class="small opacity-75" style="font-size: 0.78rem;">Bấm "Xử lý ngay" ở cột bên phải để xem chi tiết và xác nhận đơn.</div>
                </div>
            </div>
            <a href="index.php?r=<?= h(strpos($pageTitle, 'Xử lý') === 0 ? 'staff_orders' : 'admin_orders') ?>" class="btn btn-sm btn-warning px-3 fw-semibold text-nowrap" style="border-radius: 6px; font-size: 0.8rem;">
                Xem tất cả đơn hàng
            </a>
        </div>
    <?php endif; ?>

    <div class="admin-card mb-3 p-3" style="border-radius: 8px !important;">
        <form class="row g-2" method="get" action="index.php" data-live-filter="true">
            <input type="hidden" name="r" value="<?= h(strpos($pageTitle, 'Xử lý') === 0 ? 'staff_orders' : 'admin_orders') ?>">
            <div class="col-md-6">
                <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Tìm theo mã đơn, tên khách, email..." style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
                    <option value="">Tất cả trạng thái</option>
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= ($status === $value || ($status === 'cho_xu_ly' && $value === 'pending')) ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-grid">
                <button type="submit" class="btn text-white fw-semibold" style="background: #183B2B; border-radius: 6px; font-size: 0.85rem;">Lọc đơn hàng</button>
            </div>
        </form>
    </div>

    <div class="row g-4">
        <!-- Order List Table (Left 7 Cols) -->
        <div class="col-xl-7">
            <div class="admin-card mb-0 p-0 overflow-hidden">
                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Mã Đơn</th>
                                <th>Khách Hàng</th>
                                <th>Ngày Đặt</th>
                                <th>Tổng Tiền</th>
                                <th>Trạng Thái</th>
                                <th class="text-end">Chi Tiết</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($orders)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-5">Chưa có đơn hàng phù hợp điều kiện lọc.</td></tr>
                            <?php else: ?>
                                <?php foreach ($orders as $order): ?>
                                    <?php
                                    $stNorm = trim((string)($order['trang_thai_normalized'] ?? ''));
                                    $stLabel = trim((string)($order['trang_thai_hien_thi'] ?? ($order['trang_thai'] ?? 'Chờ xử lý')));
                                    $isPendingOrder = ($stNorm === 'pending' || $stNorm === 'cho_xu_ly' || mb_strtolower($stLabel) === 'chờ xử lý');

                                    $pillClass = 'status-pill-processing';
                                    if ($isPendingOrder) {
                                        $pillClass = 'status-pill-pending';
                                    } elseif ($stNorm === 'completed') {
                                        $pillClass = 'status-pill-completed';
                                    } elseif ($stNorm === 'cancelled') {
                                        $pillClass = 'status-pill-cancelled';
                                    }
                                    $isCurrentSelected = $orderDetail && (int)($orderDetail['ma_hoa_don'] ?? 0) === (int)($order['ma_hoa_don'] ?? 0);
                                    ?>
                                    <tr class="<?= $isCurrentSelected ? 'table-active' : '' ?> <?= $isPendingOrder ? 'bg-warning bg-opacity-10' : '' ?>">
                                        <td>
                                            <span class="fw-bold">#<?= h($order['ma_hoa_don'] ?? '') ?></span>
                                            <?php if ($isPendingOrder): ?>
                                                <span class="badge bg-warning text-dark ms-1" style="font-size: 0.65rem;">CẦN DUYỆT</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold" style="color: var(--admin-text);"><?= h($order['ho_ten'] ?? 'Khách hàng') ?></div>
                                            <div class="small text-muted"><?= h($order['email'] ?? '') ?></div>
                                        </td>
                                        <td class="small fw-semibold"><?= h($formatAdminOrderDate($order['ngay_dat_hien_thi'] ?? ($order['ngay_dat'] ?? null))) ?></td>
                                        <td>
                                            <div class="fw-bold text-danger"><?= vnd($order['tong_tien'] ?? 0) ?></div>
                                            <div class="small text-muted"><?= strtolower(trim((string)($order['hinh_thuc_thanh_toan'] ?? 'cod'))) === 'bank_transfer_qr' ? 'QR chuyển khoản' : 'COD' ?></div>
                                        </td>
                                        <td>
                                            <span class="status-pill <?= $pillClass ?>">
                                                <?php if ($isPendingOrder): ?><i class="bi bi-hourglass-split me-1"></i><?php endif; ?>
                                                <?= h($stLabel) ?>
                                            </span>
                                            <div class="small text-muted mt-1"><?= h($order['status_thanh_toan'] ?? 'Chưa thanh toán') ?></div>
                                        </td>
                                        <td class="text-end">
                                            <a href="index.php?r=<?= h(strpos($pageTitle, 'Xử lý') === 0 ? 'staff_orders' : 'admin_orders') ?>&detail=<?= (int)($order['ma_hoa_don'] ?? 0) ?><?= $status !== '' ? '&status=' . h($status) : '' ?>" class="btn btn-sm rounded-pill px-3 fw-bold <?= $isPendingOrder ? 'btn-warning text-dark' : 'btn-outline-primary' ?>">
                                                <?= $isPendingOrder ? 'Xử lý ngay' : 'Xem' ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Order Detail Panel (Right 5 Cols) -->
        <div class="col-xl-5">
            <div class="admin-card h-100 mb-0">
                <?php if (!$orderDetail): ?>
                    <div class="text-center text-muted py-5 d-flex flex-column align-items-center justify-content-center h-100">
                        <i class="bi bi-receipt fs-1 text-muted mb-2"></i>
                        <h6 class="fw-bold mb-1">Chưa chọn đơn hàng nào</h6>
                        <p class="small text-muted mb-0">Bấm nút "Xem" hoặc "Xử lý ngay" từ danh sách bên trái để kiểm tra thông tin.</p>
                    </div>
                <?php else: ?>
                    <?php
                    $detailStNorm = trim((string)($orderDetail['trang_thai_normalized'] ?? ''));
                    $detailStLabel = trim((string)($orderDetail['trang_thai_hien_thi'] ?? ($orderDetail['trang_thai'] ?? '')));
                    $isDetailPending = ($detailStNorm === 'pending' || mb_strtolower($detailStLabel) === 'chờ xử lý');
                    $detailPillClass = $isDetailPending ? 'status-pill-pending' : ($detailStNorm === 'completed' ? 'status-pill-completed' : ($detailStNorm === 'cancelled' ? 'status-pill-cancelled' : 'status-pill-processing'));
                    $orderRoute = strpos($pageTitle, 'Xử lý') === 0 ? 'staff_orders' : 'admin_orders';
                    $actionRoute = strpos($pageTitle, 'Xử lý') === 0 ? 'staff_order_status' : 'admin_order_status';
                    $canPrintOrder = in_array($detailStNorm, ['confirmed', 'shipping', 'completed'], true);
                    ?>

                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3 pb-3 border-bottom">
                        <div>
                            <div class="small text-muted">Chi tiết đơn hàng</div>
                            <h4 class="fw-bold mb-1" style="color: var(--admin-text);">Đơn #<?= h($orderDetail['ma_hoa_don'] ?? '') ?></h4>
                            <div class="small text-muted"><?= h($orderDetail['ho_ten'] ?? '') ?> · <?= h($orderDetail['email'] ?? '') ?></div>
                        </div>
                        <span class="status-pill <?= $detailPillClass ?>"><?= h($detailStLabel) ?></span>
                    </div>

                    <?php if ($isDetailPending && $allowManage): ?>
                        <div class="alert alert-warning border-0 shadow-sm rounded-4 p-3 mb-4" style="background: #FEF3C7; color: #B45309;">
                            <div class="fw-bold mb-2"><i class="bi bi-hourglass-split me-1"></i> Đơn hàng này đang chờ duyệt!</div>
                            <div class="d-flex gap-2">
                                <form method="post" action="index.php?r=<?= h($actionRoute) ?>" class="d-inline">
                                    <input type="hidden" name="ma_hoa_don" value="<?= (int)($orderDetail['ma_hoa_don'] ?? 0) ?>">
                                    <input type="hidden" name="trang_thai" value="confirmed">
                                    <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 fw-bold">
                                        <i class="bi bi-check-circle-fill me-1"></i> Xác Nhận Duyệt Đơn
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($canPrintOrder): ?>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <a class="btn btn-sm rounded-pill px-3 fw-bold" style="background: var(--admin-accent); color: var(--admin-primary); border: 1px solid var(--admin-accent-border);" target="_blank" href="index.php?r=<?= h($orderRoute) ?>&print_invoice=<?= (int)($orderDetail['ma_hoa_don'] ?? 0) ?>"><i class="bi bi-printer me-1"></i> In hóa đơn</a>
                            <a class="btn btn-sm rounded-pill px-3 fw-bold border" target="_blank" href="index.php?r=<?= h($orderRoute) ?>&print_delivery=<?= (int)($orderDetail['ma_hoa_don'] ?? 0) ?>"><i class="bi bi-truck me-1"></i> In phiếu giao hàng</a>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($orderDetail['ly_do_huy'])): ?>
                        <div class="alert alert-danger py-2 px-3 mb-3 rounded-3 small">
                            <div class="fw-bold text-uppercase">Lý do hủy đơn</div>
                            <div><?= h((string)$orderDetail['ly_do_huy']) ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <div class="small text-muted fw-bold text-uppercase mb-1">Thông tin nhận hàng</div>
                        <div class="fw-semibold" style="color: var(--admin-text);"><?= h($orderDetail['ho_ten'] ?? '') ?> (<?= h($orderDetail['so_dien_thoai'] ?? 'N/A') ?>)</div>
                        <div class="small text-muted"><?= h($orderDetail['dia_chi_giao_hang'] ?? 'Chưa cập nhật') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="small text-muted fw-bold text-uppercase mb-2">Sản phẩm trong đơn</div>
                        <div class="list-group list-group-flush border rounded-3 overflow-hidden">
                            <?php foreach (($orderDetail['items'] ?? []) as $item): ?>
                                <?php $itemImg = $resolveAdminItemImg($item); ?>
                                <div class="list-group-item p-2.5 p-2 bg-transparent d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-2.5 gap-2">
                                        <?php if ($itemImg !== ''): ?>
                                            <img src="<?= h($itemImg) ?>" alt="<?= h($item['ten_san_pham'] ?? '') ?>" width="42" height="42" class="rounded-3 border flex-shrink-0" style="object-fit: cover; background: #fff;">
                                        <?php else: ?>
                                            <div class="rounded-3 bg-light border d-inline-flex align-items-center justify-content-center text-muted flex-shrink-0" style="width: 42px; height: 42px;">
                                                <i class="bi bi-box-seam fs-5"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold small text-truncate" style="max-width: 180px; color: var(--admin-text);"><?= h($item['ten_san_pham'] ?? '') ?></div>
                                            <div class="small text-muted">x<?= (int)($item['so_luong'] ?? 1) ?> · <?= vnd($item['don_gia'] ?? 0) ?></div>
                                        </div>
                                    </div>
                                    <div class="fw-bold small text-danger"><?= vnd($item['thanh_tien'] ?? 0) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Status Update Form -->
                    <?php if ($allowManage): ?>
                        <div class="pt-3 border-top mt-auto">
                            <form method="post" action="index.php?r=<?= h($actionRoute) ?>">
                                <input type="hidden" name="ma_hoa_don" value="<?= (int)($orderDetail['ma_hoa_don'] ?? 0) ?>">
                                <div class="mb-2">
                                    <label class="form-label small fw-bold text-muted">Cập nhật trạng thái đơn hàng</label>
                                    <select class="form-select rounded-3" name="trang_thai" id="orderStatusSelect">
                                        <?php foreach ($statusOptions as $val => $lbl): ?>
                                            <option value="<?= h($val) ?>" <?= ($detailStNorm === $val || ($detailStNorm === 'pending' && $val === 'cho_xu_ly')) ? 'selected' : '' ?>><?= h($lbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3 d-none" id="cancelReasonWrapper">
                                    <label class="form-label small fw-bold text-danger">Lý do hủy đơn</label>
                                    <select class="form-select rounded-3" name="ly_do_huy">
                                        <option value="">-- Chọn lý do hủy --</option>
                                        <?php foreach ($cancelReasonOptions as $reasonVal => $reasonLbl): ?>
                                            <option value="<?= h($reasonVal) ?>"><?= h($reasonLbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 rounded-3 fw-bold">Cập Nhật Đơn Hàng</button>
                            </form>
                        </div>

                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            var statusSelect = document.getElementById('orderStatusSelect');
                            var cancelWrapper = document.getElementById('cancelReasonWrapper');
                            if (statusSelect && cancelWrapper) {
                                var checkCancel = function() {
                                    if (statusSelect.value === 'cancelled' || statusSelect.value === 'da_huy') {
                                        cancelWrapper.classList.remove('d-none');
                                    } else {
                                        cancelWrapper.classList.add('d-none');
                                    }
                                };
                                statusSelect.addEventListener('change', checkCancel);
                                checkCancel();
                            }
                        });
                        </script>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
