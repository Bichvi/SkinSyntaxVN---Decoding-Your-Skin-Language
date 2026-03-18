<?php
$summary = $summary ?? [];
$revenueByMonth = $revenueByMonth ?? [];
$topProducts = $topProducts ?? [];
?>

<div class="container-fluid p-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Báo cáo và thống kê</h1>
        <p class="text-muted mb-0">Tổng hợp nhanh tình hình doanh thu, đơn hàng và sản phẩm.</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4 col-xl-2"><div class="card border-0 shadow-sm rounded-4 p-3"><div class="text-muted small">Sản phẩm</div><div class="fs-4 fw-bold"><?= number_format((int)($summary['tong_san_pham'] ?? 0), 0, ',', '.') ?></div></div></div>
        <div class="col-md-4 col-xl-2"><div class="card border-0 shadow-sm rounded-4 p-3"><div class="text-muted small">Danh mục</div><div class="fs-4 fw-bold"><?= number_format((int)($summary['tong_danh_muc'] ?? 0), 0, ',', '.') ?></div></div></div>
        <div class="col-md-4 col-xl-2"><div class="card border-0 shadow-sm rounded-4 p-3"><div class="text-muted small">Khách hàng</div><div class="fs-4 fw-bold"><?= number_format((int)($summary['tong_khach_hang'] ?? 0), 0, ',', '.') ?></div></div></div>
        <div class="col-md-4 col-xl-2"><div class="card border-0 shadow-sm rounded-4 p-3"><div class="text-muted small">Nhân viên</div><div class="fs-4 fw-bold"><?= number_format((int)($summary['tong_nhan_vien'] ?? 0), 0, ',', '.') ?></div></div></div>
        <div class="col-md-4 col-xl-2"><div class="card border-0 shadow-sm rounded-4 p-3"><div class="text-muted small">Đơn hàng</div><div class="fs-4 fw-bold"><?= number_format((int)($summary['tong_don_hang'] ?? 0), 0, ',', '.') ?></div></div></div>
        <div class="col-md-4 col-xl-2"><div class="card border-0 shadow-sm rounded-4 p-3"><div class="text-muted small">Doanh thu</div><div class="fs-5 fw-bold text-danger"><?= vnd($summary['tong_doanh_thu'] ?? 0) ?></div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Doanh thu theo tháng</h5>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th>Tháng</th><th>Số đơn</th><th class="text-end">Doanh thu</th></tr></thead>
                            <tbody>
                                <?php if (empty($revenueByMonth)): ?>
                                    <tr><td colspan="3" class="text-center text-muted py-4">Chưa có dữ liệu.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($revenueByMonth as $row): ?>
                                        <tr>
                                            <td><?= h($row['thang'] ?? '') ?></td>
                                            <td><?= number_format((int)($row['so_don'] ?? 0), 0, ',', '.') ?></td>
                                            <td class="text-end fw-semibold text-danger"><?= vnd($row['doanh_thu'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Top sản phẩm theo doanh thu</h5>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th>Sản phẩm</th><th>Số lượng</th><th class="text-end">Doanh thu</th></tr></thead>
                            <tbody>
                                <?php if (empty($topProducts)): ?>
                                    <tr><td colspan="3" class="text-center text-muted py-4">Chưa có dữ liệu.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($topProducts as $row): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= h($row['ten_san_pham'] ?? '') ?></div>
                                                <div class="small text-muted">#<?= h($row['ma_san_pham'] ?? '') ?></div>
                                            </td>
                                            <td><?= number_format((int)($row['so_don_vi'] ?? 0), 0, ',', '.') ?></td>
                                            <td class="text-end fw-semibold text-danger"><?= vnd($row['doanh_thu'] ?? 0) ?></td>
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