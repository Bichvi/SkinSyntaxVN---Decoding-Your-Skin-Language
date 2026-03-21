<?php
$summary = $summary ?? [];
$revenueByMonth = $revenueByMonth ?? [];
$topProducts = $topProducts ?? [];

$totalRevenueWindow = 0;
$totalOrdersWindow = 0;
$bestMonth = null;

foreach ($revenueByMonth as $row) {
    $monthRevenue = (int)($row['doanh_thu'] ?? 0);
    $monthOrders = (int)($row['so_don'] ?? 0);
    $totalRevenueWindow += $monthRevenue;
    $totalOrdersWindow += $monthOrders;

    if ($bestMonth === null || $monthRevenue > (int)($bestMonth['doanh_thu'] ?? 0)) {
        $bestMonth = $row;
    }
}

$averageRevenueWindow = !empty($revenueByMonth) ? (int)round($totalRevenueWindow / count($revenueByMonth)) : 0;
$topProduct = $topProducts[0] ?? null;
?>

<div class="container-fluid p-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Báo cáo và thống kê</h1>
        <p class="text-muted mb-0">Phân tích doanh thu theo giai đoạn và hiệu suất bán hàng, tách biệt với trang tổng quan hệ thống.</p>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
                <div class="text-muted small mb-2">Doanh thu trong kỳ báo cáo</div>
                <div class="fs-4 fw-bold text-danger"><?= vnd($totalRevenueWindow) ?></div>
                <div class="small text-muted mt-2">Tổng hợp từ <?= number_format(count($revenueByMonth), 0, ',', '.') ?> tháng gần nhất.</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
                <div class="text-muted small mb-2">Trung bình doanh thu / tháng</div>
                <div class="fs-4 fw-bold"><?= vnd($averageRevenueWindow) ?></div>
                <div class="small text-muted mt-2">Dựa trên doanh thu trong cửa sổ báo cáo hiện tại.</div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
                <div class="text-muted small mb-2">Tháng doanh thu cao nhất</div>
                <div class="fs-4 fw-bold"><?= h($bestMonth['thang'] ?? 'Chưa có') ?></div>
                <div class="small text-muted mt-2"><?= $bestMonth ? vnd($bestMonth['doanh_thu'] ?? 0) . ' / ' . number_format((int)($bestMonth['so_don'] ?? 0), 0, ',', '.') . ' đơn' : 'Chưa có dữ liệu để phân tích.' ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 p-3 h-100">
                <div class="text-muted small mb-2">Sản phẩm dẫn đầu</div>
                <div class="fs-6 fw-bold text-truncate"><?= h($topProduct['ten_san_pham'] ?? 'Chưa có dữ liệu') ?></div>
                <div class="small text-muted mt-2"><?= $topProduct ? number_format((int)($topProduct['so_don_vi'] ?? 0), 0, ',', '.') . ' lượt bán • ' . vnd($topProduct['doanh_thu'] ?? 0) : 'Chưa có dữ liệu doanh thu theo sản phẩm.' ?></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="fw-bold mb-1">Doanh thu theo tháng</h5>
                            <div class="small text-muted">Theo dõi biến động doanh thu và số lượng đơn theo từng tháng.</div>
                        </div>
                        <span class="badge rounded-pill text-bg-light border">Tổng đơn trong kỳ: <?= number_format($totalOrdersWindow, 0, ',', '.') ?></span>
                    </div>
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
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="fw-bold mb-1">Top sản phẩm theo doanh thu</h5>
                            <div class="small text-muted">Tập trung vào sản phẩm kéo doanh thu thay vì lặp lại số liệu tổng quan từ dashboard.</div>
                        </div>
                        <span class="badge rounded-pill text-bg-light border">Tổng SP toàn hệ thống: <?= number_format((int)($summary['tong_san_pham'] ?? 0), 0, ',', '.') ?></span>
                    </div>
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