<?php
$tongSP = (int)($tongSP ?? 0);
$tongUser = (int)($tongUser ?? 0);
$doanhThu = (float)($doanhThu ?? 0);
$donChoXuLy = (int)($donChoXuLy ?? 0);
$spMoi = isset($spMoi) && is_array($spMoi) ? $spMoi : [];
$userMoi = isset($userMoi) && is_array($userMoi) ? $userMoi : [];
$summary = isset($summary) && is_array($summary) ? $summary : [];
$chatPending = (int)($summary['chat_cho_tra_loi'] ?? 0);
$reviewPending = (int)($summary['danh_gia_cho_phan_hoi'] ?? 0);
$adminName = $_SESSION['admin_name'] ?? $_SESSION['ho_ten'] ?? 'Admin';
$salesTrend = isset($salesTrend) && is_array($salesTrend) ? $salesTrend : [];
$productStatusSummary = isset($productStatusSummary) && is_array($productStatusSummary) ? $productStatusSummary : [];
$dashboardStartDate = trim((string)($dashboardStartDate ?? date('Y-m-d', strtotime('-6 days'))));
$dashboardEndDate = trim((string)($dashboardEndDate ?? date('Y-m-d')));
$dashboardError = trim((string)($dashboardError ?? ''));
$dashboardPeriodText = 'Từ ' . date('d/m/Y', strtotime($dashboardStartDate)) . ' đến ' . date('d/m/Y', strtotime($dashboardEndDate));
$periodRevenue = 0;
$periodOrders = 0;
$bestSalesDay = null;
foreach ($salesTrend as $salesRow) {
    $periodRevenue += (int)($salesRow['doanh_thu'] ?? 0);
    $periodOrders += (int)($salesRow['so_don'] ?? 0);
    if ((int)($salesRow['doanh_thu'] ?? 0) > 0 && ($bestSalesDay === null || (int)($salesRow['doanh_thu'] ?? 0) > (int)($bestSalesDay['doanh_thu'] ?? 0))) {
        $bestSalesDay = $salesRow;
    }
}
$periodAverageOrder = $periodOrders > 0 ? (int)round($periodRevenue / $periodOrders) : 0;
$salesLabels = array_map(static fn(array $row): string => (string)($row['nhan'] ?? ''), $salesTrend);
$salesLabelsFull = array_map(static fn(array $row): string => (string)($row['nhan_day_du'] ?? ''), $salesTrend);
$salesRevenue = array_map(static fn(array $row): float => round(((float)($row['doanh_thu'] ?? 0)) / 1000000, 2), $salesTrend);
$salesOrders = array_map(static fn(array $row): int => (int)($row['so_don'] ?? 0), $salesTrend);
$productStatusLabels = ['Đang bán', 'Sắp hết hàng', 'Hết hàng', 'Đang ẩn'];
$productStatusData = [
    (int)($productStatusSummary['dang_ban'] ?? 0),
    (int)($productStatusSummary['sap_het_hang'] ?? 0),
    (int)($productStatusSummary['het_hang'] ?? 0),
    (int)($productStatusSummary['dang_an'] ?? 0),
];
?>

<div class="container-fluid px-4 py-4">
    <!-- GREETING HERO BANNER -->
    <div class="admin-card mb-4 p-3.5" style="border-radius: 8px !important;">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 px-2.5 py-1 mb-2 small fw-semibold" style="background: #EBF2EE; color: #183B2B; border: 1px solid #C8DACF; border-radius: 4px;">
                    <i class="fa-solid fa-sparkles"></i> SkinSyntaxVN Intelligence Center
                </div>
                <h2 class="fw-bold mb-1" style="color: var(--admin-text); font-size: 1.5rem;">Chào buổi sáng, <?= h($adminName) ?></h2>
                <p class="text-muted mb-0 small">Tổng quan chỉ số hoạt động thương mại mỹ phẩm & chăm sóc da hôm nay.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="btn btn-sm px-3 py-1.5 fw-semibold text-nowrap" style="background: var(--admin-surface); color: var(--admin-text-muted); border: 1px solid var(--admin-border); border-radius: 6px; font-size: 0.82rem;">
                    <i class="fa-regular fa-calendar-check me-1.5" style="color: #183B2B;"></i> Hôm nay, <?= date('d/m/Y') ?>
                </span>
            </div>
        </div>
    </div>

    <!-- KPI CARDS GRID -->
    <div class="row g-3 mb-4">
        <!-- Revenue Card -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-label">Tổng Doanh Thu</span>
                        <div class="kpi-value" style="color: #183B2B;"><?= vnd($doanhThu) ?></div>
                    </div>
                    <div class="kpi-card-icon kpi-revenue">
                        <i class="bi bi-wallet2 fs-5"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Orders Card -->
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="index.php?r=admin_orders&status=cho_xu_ly" class="text-decoration-none">
                <div class="kpi-card h-100">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="kpi-label">Đơn Chờ Xử Lý</span>
                            <div class="kpi-value" style="color: #B45309;"><?= number_format($donChoXuLy, 0, ',', '.') ?></div>
                        </div>
                        <div class="kpi-card-icon kpi-orders">
                            <i class="bi bi-hourglass-split fs-5"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <!-- Total Products Card -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-label">Tổng Sản Phẩm</span>
                        <div class="kpi-value" style="color: #0369A1;"><?= number_format($tongSP, 0, ',', '.') ?></div>
                    </div>
                    <div class="kpi-card-icon kpi-products">
                        <i class="bi bi-boxes fs-5"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Users Card -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-card h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="kpi-label">Tổng Khách Hàng</span>
                        <div class="kpi-value" style="color: #6B21A8;"><?= number_format($tongUser, 0, ',', '.') ?></div>
                    </div>
                    <div class="kpi-card-icon kpi-users">
                        <i class="bi bi-people-fill fs-5"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CUSTOMER CARE & ACTION QUICK WIDGETS -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
            <a href="index.php?r=staff_chats" class="text-decoration-none">
                <div class="admin-card mb-0 p-3 d-flex align-items-center justify-content-between h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px; background: #E0F2FE; color: #0369A1; font-size: 1.1rem; border: 1px solid #BAE6FD;">
                            <i class="bi bi-chat-dots-fill"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small" style="color: var(--admin-text);">Chat Hỗ Trợ</div>
                            <div class="small text-muted"><?= $chatPending ?> chờ trả lời</div>
                        </div>
                    </div>
                    <span class="badge px-2 py-1 fw-semibold" style="background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD; border-radius: 4px; font-size: 0.76rem;"><?= $chatPending ?></span>
                </div>
            </a>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <a href="index.php?r=staff_reviews" class="text-decoration-none">
                <div class="admin-card mb-0 p-3 d-flex align-items-center justify-content-between h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px; background: #FEF3C7; color: #B45309; font-size: 1.1rem; border: 1px solid #FDE68A;">
                            <i class="bi bi-star-fill"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small" style="color: var(--admin-text);">Đánh Giá Mới</div>
                            <div class="small text-muted"><?= $reviewPending ?> chưa phản hồi</div>
                        </div>
                    </div>
                    <span class="badge px-2 py-1 fw-semibold" style="background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A; border-radius: 4px; font-size: 0.76rem;"><?= $reviewPending ?></span>
                </div>
            </a>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <a href="index.php?r=admin_orders&status=cho_xu_ly" class="text-decoration-none">
                <div class="admin-card mb-0 p-3 d-flex align-items-center justify-content-between h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px; background: #EBF2EE; color: #183B2B; font-size: 1.1rem; border: 1px solid #C8DACF;">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small" style="color: var(--admin-text);">Xử Lý Đơn Hàng</div>
                            <div class="small text-muted"><?= $donChoXuLy ?> đơn chờ duyệt</div>
                        </div>
                    </div>
                    <span class="badge px-2 py-1 fw-semibold" style="background: #EBF2EE; color: #183B2B; border: 1px solid #C8DACF; border-radius: 4px; font-size: 0.76rem;"><?= $donChoXuLy ?></span>
                </div>
            </a>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <a href="index.php?r=admin_lives" class="text-decoration-none">
                <div class="admin-card mb-0 p-3 d-flex align-items-center justify-content-between h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px; background: #FFE4E6; color: #E11D48; font-size: 1.1rem; border: 1px solid #FECDD3;">
                            <i class="bi bi-camera-reels-fill"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small" style="color: var(--admin-text);">Quản lý phiên Live</div>
                            <div class="small text-muted">Tạo & Ghim sản phẩm</div>
                        </div>
                    </div>
                    <span class="badge px-2 py-1 fw-semibold" style="background: #FFE4E6; color: #E11D48; border: 1px solid #FECDD3; border-radius: 4px; font-size: 0.76rem;">Live</span>
                </div>
            </a>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
            <a href="index.php?r=admin_ai_idol" class="text-decoration-none">
                <div class="admin-card mb-0 p-3 d-flex align-items-center justify-content-between h-100" style="border-color: #B9DCC8; background: linear-gradient(135deg, #F4FBF6 0%, #FFFFFF 100%);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px; background: #DDF4E5; color: #17633D; font-size: 1.1rem; border: 1px solid #B9DCC8;">
                            <i class="bi bi-stars"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small" style="color: var(--admin-text);">AI Idol Studio</div>
                            <div class="small text-muted">Tạo Syna, kịch bản & video</div>
                        </div>
                    </div>
                    <span class="badge px-2 py-1 fw-semibold" style="background: #DDF4E5; color: #17633D; border: 1px solid #B9DCC8; border-radius: 4px; font-size: 0.76rem;">Mở Studio</span>
                </div>
            </a>
        </div>
    </div>

    <!-- DASHBOARD CHARTS ROW (REAL DATA + DATE RANGE) -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-8">
            <div class="admin-card mb-0 p-3.5" style="border-radius: 8px !important;">
                <div class="d-flex flex-column flex-lg-row align-items-lg-start justify-content-between gap-3 mb-3 pb-2 border-bottom">
                    <div>
                        <h6 class="fw-bold mb-1" style="color: var(--admin-text);"><i class="bi bi-bar-chart-line-fill text-success me-1.5"></i> Thống kê Doanh số & Đơn hàng theo ngày</h6>
                        <div class="small text-muted" style="font-size: 0.78rem;"><?= h($dashboardPeriodText) ?> · chỉ tính đơn hoàn thành hợp lệ</div>
                    </div>
                    <a href="index.php?r=admin_reports&start_date=<?= h($dashboardStartDate) ?>&end_date=<?= h($dashboardEndDate) ?>" class="btn btn-sm btn-outline-success px-3 fw-semibold text-nowrap" style="border-radius: 6px; font-size: 0.78rem;">
                        <i class="bi bi-file-bar-graph me-1"></i> Báo cáo chi tiết
                    </a>
                </div>

                <form method="get" action="index.php" class="row g-2 align-items-end mb-3">
                    <input type="hidden" name="r" value="admin_dashboard">
                    <div class="col-12 col-md-4">
                        <label for="dashboardStartDate" class="form-label small text-muted fw-semibold mb-1">Từ ngày</label>
                        <input type="date" id="dashboardStartDate" name="start_date" value="<?= h($dashboardStartDate) ?>" class="form-control form-control-sm" style="border-color: var(--admin-border); border-radius: 6px;">
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="dashboardEndDate" class="form-label small text-muted fw-semibold mb-1">Đến ngày</label>
                        <input type="date" id="dashboardEndDate" name="end_date" value="<?= h($dashboardEndDate) ?>" class="form-control form-control-sm" style="border-color: var(--admin-border); border-radius: 6px;">
                    </div>
                    <div class="col-12 col-md-4 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-sm text-white px-3 fw-semibold" style="background: #183B2B; border-radius: 6px;">Xem dữ liệu</button>
                        <a href="index.php?r=admin_dashboard&start_date=<?= date('Y-m-d', strtotime('-6 days')) ?>&end_date=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-secondary px-2" style="border-radius: 6px;">7 ngày</a>
                        <a href="index.php?r=admin_dashboard&start_date=<?= date('Y-m-d', strtotime('-29 days')) ?>&end_date=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-secondary px-2" style="border-radius: 6px;">30 ngày</a>
                    </div>
                </form>

                <?php if ($dashboardError !== ''): ?>
                    <div class="alert alert-warning py-2 px-3 small mb-3"><?= h($dashboardError) ?></div>
                <?php endif; ?>

                <div class="row g-2 mb-3">
                    <div class="col-6 col-md-3"><div class="rounded-3 border p-2 h-100"><div class="small text-muted">Doanh thu kỳ</div><div class="fw-bold text-success tabular-nums"><?= vnd($periodRevenue) ?></div></div></div>
                    <div class="col-6 col-md-3"><div class="rounded-3 border p-2 h-100"><div class="small text-muted">Đơn hoàn tất</div><div class="fw-bold text-warning tabular-nums"><?= number_format($periodOrders) ?></div></div></div>
                    <div class="col-6 col-md-3"><div class="rounded-3 border p-2 h-100"><div class="small text-muted">Giá trị đơn TB</div><div class="fw-bold text-primary tabular-nums"><?= vnd($periodAverageOrder) ?></div></div></div>
                    <div class="col-6 col-md-3"><div class="rounded-3 border p-2 h-100"><div class="small text-muted">Ngày cao nhất</div><div class="fw-bold text-danger"><?= h((string)($bestSalesDay['nhan_day_du'] ?? 'Chưa có')) ?></div></div></div>
                </div>

                <div style="height: 285px; position: relative;">
                    <canvas id="adminSalesChart"></canvas>
                </div>

                <div class="table-responsive mt-3" style="max-height: 310px; overflow-y: auto;">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                            <tr><th>Ngày</th><th class="text-end">Đơn hoàn tất</th><th class="text-end">Doanh thu</th><th class="text-end">Đơn TB</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($salesTrend as $salesRow): ?>
                            <tr>
                                <td><?= h((string)($salesRow['nhan_day_du'] ?? '')) ?></td>
                                <td class="text-end"><?= number_format((int)($salesRow['so_don'] ?? 0)) ?></td>
                                <td class="text-end fw-semibold text-success"><?= vnd($salesRow['doanh_thu'] ?? 0) ?></td>
                                <td class="text-end text-muted"><?= vnd($salesRow['gia_tri_trung_binh'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="admin-card mb-0 p-3.5 h-100" style="border-radius: 8px !important;">
                <div class="d-flex align-items-start justify-content-between mb-3 pb-2 border-bottom">
                    <div>
                        <h6 class="fw-bold mb-1" style="color: var(--admin-text);"><i class="bi bi-pie-chart-fill text-warning me-1.5"></i> Tỷ lệ trạng thái sản phẩm</h6>
                        <div class="small text-muted" style="font-size: 0.78rem;">Phân loại theo hiển thị và tồn kho thực tế</div>
                    </div>
                    <span class="badge bg-light text-dark border"><?= number_format((int)($productStatusSummary['tong'] ?? 0)) ?> SP</span>
                </div>
                <div style="height: 240px; position: relative;" class="d-flex align-items-center justify-content-center">
                    <?php if (array_sum($productStatusData) > 0): ?>
                        <canvas id="adminCategoryChart"></canvas>
                    <?php else: ?>
                        <div class="text-muted small text-center"><i class="bi bi-box-seam fs-2 d-block mb-2"></i>Chưa có dữ liệu sản phẩm</div>
                    <?php endif; ?>
                </div>
                <div class="border-top pt-2 mt-2">
                    <?php foreach ($productStatusLabels as $statusIndex => $statusLabel): ?>
                        <div class="d-flex justify-content-between align-items-center small py-1">
                            <span><span class="d-inline-block rounded-circle me-2" style="width: 9px; height: 9px; background: <?= ['#183B2B', '#D97706', '#E11D48', '#64748B'][$statusIndex] ?>;"></span><?= h($statusLabel) ?></span>
                            <strong><?= number_format($productStatusData[$statusIndex]) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var formatMoney = function(value) {
            return new Intl.NumberFormat('vi-VN').format(Math.round(Number(value || 0) * 1000000)) + ' đ';
        };
        var ctxSales = document.getElementById('adminSalesChart');
        if (ctxSales && typeof Chart !== 'undefined') {
            new Chart(ctxSales, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($salesLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                    datasets: [{
                        label: 'Doanh thu (triệu VNĐ)',
                        data: <?= json_encode($salesRevenue) ?>,
                        yAxisID: 'revenue',
                        backgroundColor: 'rgba(24, 59, 43, 0.82)',
                        borderColor: '#183B2B',
                        borderWidth: 1,
                        borderRadius: 5,
                        maxBarThickness: 34
                    }, {
                        type: 'line',
                        label: 'Đơn hoàn tất',
                        data: <?= json_encode($salesOrders) ?>,
                        yAxisID: 'orders',
                        borderColor: '#D97706',
                        backgroundColor: '#D97706',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0.25
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { font: { family: 'Quicksand', size: 12 } } },
                        tooltip: {
                            callbacks: {
                                title: function(items) { return <?= json_encode($salesLabelsFull, JSON_UNESCAPED_UNICODE) ?>[items[0].dataIndex] || ''; },
                                label: function(context) {
                                    return context.dataset.yAxisID === 'revenue'
                                        ? ' Doanh thu: ' + formatMoney(context.raw)
                                        : ' Đơn hoàn tất: ' + new Intl.NumberFormat('vi-VN').format(context.raw || 0) + ' đơn';
                                }
                            }
                        }
                    },
                    scales: {
                        revenue: { beginAtZero: true, position: 'left', title: { display: true, text: 'Triệu VNĐ' }, grid: { color: '#F1F5F9' } },
                        orders: { beginAtZero: true, position: 'right', title: { display: true, text: 'Đơn hàng' }, grid: { drawOnChartArea: false } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        var ctxCat = document.getElementById('adminCategoryChart');
        if (ctxCat && typeof Chart !== 'undefined') {
            new Chart(ctxCat, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($productStatusLabels, JSON_UNESCAPED_UNICODE) ?>,
                    datasets: [{
                        data: <?= json_encode($productStatusData) ?>,
                        backgroundColor: ['#183B2B', '#D97706', '#E11D48', '#64748B'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { font: { family: 'Quicksand', size: 11 }, boxWidth: 12 } } },
                    cutout: '66%'
                }
            });
        }
    });
    </script>

    <!-- LOW STOCK ALERT WIDGET -->
    <div class="admin-card mb-4 p-3 <?= !empty($lowStockProducts) ? 'border-danger border-opacity-25' : 'border-success border-opacity-25' ?>" style="background: <?= !empty($lowStockProducts) ? '#FEF2F2' : '#F0FDF4' ?>;">
        <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 d-inline-flex align-items-center justify-content-center <?= !empty($lowStockProducts) ? 'text-danger bg-white border border-danger-subtle' : 'text-success bg-white border border-success-subtle' ?>" style="width: 38px; height: 38px;">
                    <i class="bi <?= !empty($lowStockProducts) ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill' ?> fs-5"></i>
                </div>
                <div>
                    <h6 class="fw-semibold mb-0 <?= !empty($lowStockProducts) ? 'text-danger' : 'text-success' ?>" style="font-size: 0.92rem;">
                        <?= !empty($lowStockProducts) ? 'Cảnh Báo Tồn Kho Sắp Hết (Dưới 5 sản phẩm)' : 'Trạng Thái Kho Hàng An Toàn' ?>
                    </h6>
                    <div class="small text-muted" style="font-size: 0.8rem;">
                        <?= !empty($lowStockProducts) ? 'Có ' . count($lowStockProducts) . ' sản phẩm cần nhập thêm hàng ngay.' : 'Tất cả sản phẩm trong hệ thống hiện tại đều có số lượng tồn kho đầy đủ (> 5 sản phẩm).' ?>
                    </div>
                </div>
            </div>
            <a href="index.php?r=admin_sp" class="btn btn-sm <?= !empty($lowStockProducts) ? 'btn-outline-danger' : 'btn-outline-success' ?> px-3 fw-semibold" style="border-radius: 6px; font-size: 0.8rem;">
                Quản lý kho hàng
            </a>
        </div>
        <?php if (!empty($lowStockProducts)): ?>
            <div class="row g-2 mt-2 pt-2 border-top">
                <?php foreach ($lowStockProducts as $pLow): ?>
                    <?php
                    $pLowImg = '';
                    $rawP = trim((string)($pLow['link_hinh_anh'] ?? $pLow['hinh_anh'] ?? ''));
                    if ($rawP !== '') {
                        $partsP = preg_split('/\s*\|\s*/', $rawP) ?: [];
                        foreach ($partsP as $partP) {
                            $partP = trim((string)$partP);
                            if ($partP === '') continue;
                            if (filter_var($partP, FILTER_VALIDATE_URL)) {
                                $pLowImg = $partP;
                                break;
                            }
                            if (strpos($partP, 'uploads/') === 0 || strpos($partP, '/uploads/') === 0) {
                                $pLowImg = BASE_URL . '/' . ltrim($partP, '/');
                                break;
                            }
                            $pLowImg = BASE_URL . '/uploads/products/' . rawurlencode($partP);
                            break;
                        }
                    }
                    ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="d-flex align-items-center justify-content-between p-2 rounded-3 border bg-white shadow-xs">
                            <div class="d-flex align-items-center gap-2 text-truncate me-2">
                                <?php if ($pLowImg !== ''): ?>
                                    <img src="<?= h($pLowImg) ?>" alt="" width="36" height="36" class="rounded border flex-shrink-0" style="object-fit: cover;">
                                <?php else: ?>
                                    <div class="rounded bg-light border d-inline-flex align-items-center justify-content-center text-muted flex-shrink-0" style="width: 36px; height: 36px;"><i class="bi bi-box-seam"></i></div>
                                <?php endif; ?>
                                <div class="text-truncate">
                                    <div class="fw-bold small text-truncate" style="color: var(--admin-text);"><?= h($pLow['ten_san_pham'] ?? '') ?></div>
                                    <div class="small text-muted">Mã: #<?= h($pLow['id'] ?? '') ?></div>
                                </div>
                            </div>
                            <span class="badge bg-danger rounded-pill px-2.5 py-1 text-nowrap">Còn <?= (int)($pLow['ton_kho'] ?? 0) ?> sp</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- RECENT ACTIVITY TABLES -->
    <div class="row g-4">
        <!-- Recent Products Panel -->
        <div class="col-12 col-lg-6">
            <div class="admin-card h-100 mb-0">
                <div class="admin-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-box-open text-success fs-5"></i>
                        <h5 class="admin-card-title">Sản Phẩm Mới Cập Nhật</h5>
                    </div>
                    <a href="index.php?r=admin_sp" class="btn btn-sm rounded-pill px-3 fw-bold small text-decoration-none" style="background: var(--admin-accent); color: var(--admin-primary); border: 1px solid var(--admin-accent-border);">Xem tất cả <i class="fa-solid fa-arrow-right ms-1"></i></a>
                </div>

                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Hình Ảnh</th>
                                <th>Tên Sản Phẩm</th>
                                <th>Mã SP</th>
                                <th>Trạng Thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($spMoi)): ?>
                                <?php foreach ($spMoi as $sp): ?>
                                    <?php
                                    $rawImg = trim((string)($sp['hinh_anh'] ?? ''));
                                    $img = '';
                                    if ($rawImg !== '') {
                                        $parts = preg_split('/\s*\|\s*/', $rawImg) ?: [];
                                        foreach ($parts as $part) {
                                            $part = trim((string)$part);
                                            if ($part === '') continue;
                                            if (filter_var($part, FILTER_VALIDATE_URL)) {
                                                $img = $part;
                                                break;
                                            }
                                            if ($img === '') {
                                                $img = BASE_URL . '/uploads/products/' . rawurlencode($part);
                                            }
                                        }
                                    }
                                    $status = trim((string)($sp['trang_thai'] ?? 'Hiển thị'));
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($img !== ''): ?>
                                                <img src="<?= h($img) ?>" alt="<?= h($sp['ten_san_pham'] ?? '') ?>" class="rounded-3 border" width="46" height="46" style="object-fit: cover; background: #fff;">
                                            <?php else: ?>
                                                <div class="rounded-3 bg-light border d-inline-flex align-items-center justify-content-center text-muted" style="width: 46px; height: 46px;">
                                                    <i class="fa-regular fa-image"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-truncate" style="max-width: 220px; color: var(--admin-text);"><?= h($sp['ten_san_pham'] ?? '') ?></div>
                                            <div class="small text-muted"><?= h($sp['thuong_hieu'] ?? 'SkinSyntax') ?></div>
                                        </td>
                                        <td><code class="px-2 py-1 rounded bg-light fw-bold" style="color: var(--admin-primary);"><?= h($sp['ma_san_pham'] ?? '') ?></code></td>
                                        <td><span class="status-pill status-pill-completed"><?= h($status) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">Chưa có dữ liệu sản phẩm mới.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Registrations Panel -->
        <div class="col-12 col-lg-6">
            <div class="admin-card h-100 mb-0">
                <div class="admin-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-users text-primary fs-5"></i>
                        <h5 class="admin-card-title">Người Dùng Đăng Ký Mới</h5>
                    </div>
                    <a href="index.php?r=admin_users" class="btn btn-sm rounded-pill px-3 fw-bold small text-decoration-none" style="background: var(--admin-accent); color: var(--admin-primary); border: 1px solid var(--admin-accent-border);">Xem tất cả <i class="fa-solid fa-arrow-right ms-1"></i></a>
                </div>

                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Khách Hàng</th>
                                <th>Email</th>
                                <th>Ngày Đăng Ký</th>
                                <th>Vai Trò</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($userMoi)): ?>
                                <?php foreach ($userMoi as $user): ?>
                                    <?php
                                    $rawDate = $user['ngay_dang_ky'] ?? $user['ngay_tao'] ?? $user['created_at'] ?? null;
                                    $dateText = 'N/A';
                                    if (!empty($rawDate)) {
                                        $timestamp = strtotime((string)$rawDate);
                                        if ($timestamp !== false) {
                                            $dateText = date('d/m/Y', $timestamp);
                                        }
                                    }
                                    $role = strtolower(trim((string)($user['vai_tro'] ?? 'khach_hang')));
                                    $roleLabel = $role === 'khach_hang' ? 'Khách hàng' : ($role === 'admin' ? 'Quản trị' : 'Nhân viên');
                                    $rolePillClass = $role === 'khach_hang' ? 'status-pill-completed' : 'status-pill-processing';
                                    $initial = mb_substr(trim((string)($user['ho_ten'] ?? 'U')), 0, 1);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2.5 gap-2">
                                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle fw-bold text-white shadow-sm" style="width: 38px; height: 38px; background: linear-gradient(135deg, var(--admin-primary) 0%, var(--admin-primary-light) 100%); font-size: 0.9rem;">
                                                    <?= h($initial) ?>
                                                </span>
                                                <div>
                                                    <div class="fw-bold" style="color: var(--admin-text);"><?= h($user['ho_ten'] ?? 'Khách hàng') ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="small text-muted"><?= h($user['email'] ?? 'N/A') ?></td>
                                        <td class="small fw-semibold"><?= h($dateText) ?></td>
                                        <td><span class="status-pill <?= $rolePillClass ?>"><?= h($roleLabel) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">Chưa có người dùng mới.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

