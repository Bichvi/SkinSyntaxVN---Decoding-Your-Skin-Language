<?php
$summary = $summary ?? [];
$revenueByMonth = $revenueByMonth ?? [];
$topProducts = $topProducts ?? [];
$reportStartDate = trim((string)($reportStartDate ?? ''));
$reportEndDate = trim((string)($reportEndDate ?? ''));
$reportError = trim((string)($reportError ?? ''));
$hasDateFilter = $reportStartDate !== '' || $reportEndDate !== '';
$reportPeriodText = $hasDateFilter
    ? 'Dữ liệu đang lọc' . ($reportStartDate !== '' ? ' từ ' . date('d/m/Y', strtotime($reportStartDate)) : '')
        . ($reportEndDate !== '' ? ' đến ' . date('d/m/Y', strtotime($reportEndDate)) : '')
    : 'Dữ liệu toàn bộ kỳ báo cáo hiện tại';

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

<style>
.report-hero-card {
  background: var(--admin-surface, #ffffff) !important;
  border: 1px solid var(--admin-border, #e2e8f0);
  border-radius: 8px;
  box-shadow: var(--admin-shadow);
}
.report-kpi-card {
  background: var(--admin-surface, #ffffff);
  border: 1px solid var(--admin-border, #e2e8f0);
  border-radius: 8px;
  padding: 1.1rem 1.25rem;
  box-shadow: var(--admin-shadow);
  transition: all 0.2s ease;
  height: 100%;
}
.report-kpi-card:hover {
  border-color: var(--admin-accent-border);
  box-shadow: var(--admin-shadow-hover);
}
.report-kpi-icon {
  width: 40px;
  height: 40px;
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
  flex-shrink: 0;
}
.rank-badge {
  width: 24px;
  height: 24px;
  border-radius: 4px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.76rem;
}
.rank-1 { background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A; }
.rank-2 { background: #F1F5F9; color: #475569; border: 1px solid #E2E8F0; }
.rank-3 { background: #FFEDD5; color: #C2410C; border: 1px solid #FED7AA; }
.rank-other { background: #F8FAFC; color: #64748B; border: 1px solid #F1F5F9; }
</style>

<div class="container-fluid px-4 py-4">
    <!-- PAGE HEADER -->
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);"><i class="bi bi-pie-chart-fill text-success me-2"></i>Báo Cáo Doanh Thu & Hiệu Suất Kinh Doanh</h1>
            <p class="text-muted mb-0 small">Thống kê doanh số bán hàng, phân tích xu hướng và top sản phẩm chạy nhất.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge px-3 py-2 fw-semibold text-dark border" style="background: var(--admin-surface-subtle); border-radius: 6px; font-size: 0.8rem;">
                <i class="bi bi-clock me-1 text-success"></i> <?= date('d/m/Y - H:i') ?>
            </span>
        </div>
    </div>

    <!-- DATE FILTER TOOLBAR -->
    <div class="report-hero-card p-3 mb-4">
        <form class="row g-2 align-items-end" method="get" action="index.php">
            <input type="hidden" name="r" value="admin_reports">
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold small text-muted mb-1" for="reportStartDate" style="font-size: 0.76rem;">TỪ NGÀY</label>
                <input type="date" class="form-control" id="reportStartDate" name="start_date" value="<?= h($reportStartDate) ?>" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-semibold small text-muted mb-1" for="reportEndDate" style="font-size: 0.76rem;">ĐẾN NGÀY</label>
                <input type="date" class="form-control" id="reportEndDate" name="end_date" value="<?= h($reportEndDate) ?>" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
            </div>
            <div class="col-12 col-md-6 d-flex flex-wrap gap-2">
                <button type="submit" class="btn text-white px-3 py-1.5 fw-semibold" style="background: #183B2B; border-radius: 6px; font-size: 0.85rem;">
                    <i class="bi bi-funnel-fill me-1"></i> Lọc Báo Cáo
                </button>
                <button type="submit" name="export" value="excel" class="btn btn-outline-success px-3 py-1.5 fw-semibold" style="border-radius: 6px; font-size: 0.85rem;">
                    <i class="bi bi-file-earmark-excel-fill me-1"></i> Xuất Excel
                </button>
                <?php if ($hasDateFilter): ?>
                    <a class="btn btn-outline-secondary px-3 py-1.5 fw-semibold" href="index.php?r=admin_reports" style="border-radius: 6px; font-size: 0.85rem;">Xóa Lọc</a>
                <?php endif; ?>
            </div>
            <div class="col-12 mt-2">
                <div class="small text-muted fw-semibold" style="font-size: 0.78rem;"><i class="bi bi-info-circle me-1 text-success"></i> <?= h($reportPeriodText) ?></div>
                <?php if ($reportError !== ''): ?>
                    <div class="alert alert-danger mt-2 mb-0 p-2 rounded small"><?= h($reportError) ?></div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- 4 RICH KPI METRIC CARDS -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Window Revenue -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="report-kpi-card d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Doanh Thu Kỳ Này</div>
                    <div class="fs-3 fw-bold text-danger my-1 tabular-nums"><?= vnd($totalRevenueWindow) ?></div>
                    <div class="small text-muted fw-medium" style="font-size: 0.76rem;"><?= number_format($totalOrdersWindow) ?> đơn hàng hợp lệ</div>
                </div>
                <div class="report-kpi-icon" style="background: #FFE4E6; color: #E11D48; border: 1px solid #FECDD3;">
                    <i class="bi bi-wallet2"></i>
                </div>
            </div>
        </div>

        <!-- 2. Average Monthly Revenue -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="report-kpi-card d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Trung Bình / Tháng</div>
                    <div class="fs-3 fw-bold text-success my-1 tabular-nums"><?= vnd($averageRevenueWindow) ?></div>
                    <div class="small text-muted fw-medium" style="font-size: 0.76rem;">Hiệu suất trung bình</div>
                </div>
                <div class="report-kpi-icon" style="background: #DCFCE7; color: #15803D; border: 1px solid #BBF7D0;">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
            </div>
        </div>

        <!-- 3. Peak Revenue Month -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="report-kpi-card d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">Tháng Đạt Đỉnh</div>
                    <div class="fs-3 fw-bold text-primary my-1 tabular-nums"><?= h($bestMonth['thang'] ?? 'Chưa có') ?></div>
                    <div class="small text-muted fw-medium" style="font-size: 0.76rem;"><?= $bestMonth ? (vnd($bestMonth['doanh_thu'] ?? 0) . ' (' . number_format((int)($bestMonth['so_don'] ?? 0)) . ' đơn)') : 'Chưa có dữ liệu' ?></div>
                </div>
                <div class="report-kpi-icon" style="background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD;">
                    <i class="bi bi-trophy-fill"></i>
                </div>
            </div>
        </div>

        <!-- 4. Top Performing Product -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="report-kpi-card d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.04em;">SP Bán Chạy Nhất</div>
                    <div class="fs-6 fw-bold text-truncate my-1" style="max-width: 160px; color: var(--admin-text);" title="<?= h($topProduct['ten_san_pham'] ?? '') ?>">
                        <?= h($topProduct['ten_san_pham'] ?? 'Chưa có dữ liệu') ?>
                    </div>
                    <div class="small text-muted fw-medium" style="font-size: 0.74rem;"><?= $topProduct ? (number_format((int)($topProduct['so_don_vi'] ?? 0)) . ' lượt bán • ' . vnd($topProduct['doanh_thu'] ?? 0)) : 'N/A' ?></div>
                </div>
                <div class="report-kpi-icon" style="background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A;">
                    <i class="bi bi-award-fill"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- REVENUE CHART CARD -->
    <div class="admin-card mb-4 p-3.5" style="border-radius: 8px !important;">
        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
            <div>
                <h6 class="fw-bold mb-0" style="color: var(--admin-text);"><i class="bi bi-graph-up text-success me-1.5"></i> Biểu đồ Doanh Thu Theo Tháng</h6>
                <div class="small text-muted" style="font-size: 0.78rem;">Phân tích xu hướng tăng trưởng theo chu kỳ</div>
            </div>
        </div>
        <div style="height: 260px; position: relative;">
            <canvas id="adminReportsChart"></canvas>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var ctxRep = document.getElementById('adminReportsChart');
        if (ctxRep && typeof Chart !== 'undefined') {
            <?php
            $monthsArr = [];
            $revsArr = [];
            $ordersArr = [];
            foreach ($revenueByMonth as $r) {
                $monthsArr[] = (string)($r['thang'] ?? '');
                $revsArr[] = (float)round(($r['doanh_thu'] ?? 0) / 1000000, 2);
                $ordersArr[] = (int)($r['so_don'] ?? 0);
            }
            ?>
            new Chart(ctxRep, {
                type: 'line',
                data: {
                    labels: <?= json_encode($monthsArr, JSON_UNESCAPED_UNICODE) ?>,
                    datasets: [{
                        label: 'Doanh Thu (Triệu VNĐ)',
                        data: <?= json_encode($revsArr) ?>,
                        borderColor: '#183B2B',
                        backgroundColor: 'rgba(24, 59, 43, 0.08)',
                        fill: true,
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: '#183B2B'
                    }, {
                        label: 'Số Lượng Đơn',
                        data: <?= json_encode($ordersArr) ?>,
                        borderColor: '#D97706',
                        backgroundColor: 'rgba(217, 119, 6, 0.05)',
                        fill: false,
                        tension: 0.3,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#D97706'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { font: { family: 'Quicksand', size: 12 } } }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#F1F5F9' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }
    });
    </script>

    <!-- TABLES SECTION GRID -->
    <div class="row g-4">
        <!-- Monthly Revenue Table -->
        <div class="col-12 col-lg-6">
            <div class="admin-card h-100 p-0 overflow-hidden">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: rgba(240,244,241,0.5);">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-calendar-range-fill me-2 text-success"></i>Doanh Thu Theo Tháng</h5>
                        <div class="small text-muted">Biến động số lượng đơn và doanh thu chi tiết</div>
                    </div>
                    <span class="badge bg-success-subtle text-success-emphasis rounded-pill px-3 py-1.5 fw-bold">
                        <?= number_format($totalOrdersWindow) ?> đơn trong kỳ
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Tháng</th>
                                <th>Số Lượng Đơn</th>
                                <th class="text-end pe-4">Doanh Thu Tổng</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($revenueByMonth)): ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-5">
                                        <i class="bi bi-inbox-fill fs-2 mb-2 d-block opacity-50"></i>
                                        Chưa có dữ liệu doanh thu trong thời gian này.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($revenueByMonth as $row): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-dark">
                                            <i class="bi bi-calendar-event text-secondary me-2"></i><?= h($row['thang'] ?? '') ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border px-2.5 py-1 rounded-pill">
                                                <?= number_format((int)($row['so_don'] ?? 0)) ?> đơn
                                            </span>
                                        </td>
                                        <td class="text-end pe-4 fw-bold text-danger">
                                            <?= vnd($row['doanh_thu'] ?? 0) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Top Products Table -->
        <div class="col-12 col-lg-6">
            <div class="admin-card h-100 p-0 overflow-hidden">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: rgba(240,244,241,0.5);">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-stars me-2 text-warning"></i>Top Sản Phẩm Doanh Thu</h5>
                        <div class="small text-muted">Những sản phẩm dẫn đầu doanh số bán ra</div>
                    </div>
                    <span class="badge bg-light border text-dark rounded-pill px-3 py-1.5 fw-bold">
                        Hệ thống: <?= number_format((int)($summary['tong_san_pham'] ?? 0)) ?> SP
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4" style="width: 50px;">Hạng</th>
                                <th>Sản Phẩm</th>
                                <th>Số Lượng</th>
                                <th class="text-end pe-4">Doanh Thu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topProducts)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-5">
                                        <i class="bi bi-box-seam-fill fs-2 mb-2 d-block opacity-50"></i>
                                        Chưa có dữ liệu sản phẩm bán ra.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($topProducts as $index => $row): ?>
                                    <?php $rank = $index + 1; ?>
                                    <tr>
                                        <td class="ps-4">
                                            <span class="rank-badge <?= $rank === 1 ? 'rank-1' : ($rank === 2 ? 'rank-2' : ($rank === 3 ? 'rank-3' : 'rank-other')) ?>">
                                                <?= $rank ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark text-truncate" style="max-width: 230px;" title="<?= h($row['ten_san_pham'] ?? '') ?>">
                                                <?= h($row['ten_san_pham'] ?? '') ?>
                                            </div>
                                            <div class="small text-muted">SP #<?= h($row['ma_san_pham'] ?? '') ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-success-subtle text-success-emphasis rounded-pill px-2.5 py-1">
                                                <?= number_format((int)($row['so_don_vi'] ?? 0)) ?> đã bán
                                            </span>
                                        </td>
                                        <td class="text-end pe-4 fw-bold text-danger">
                                            <?= vnd($row['doanh_thu'] ?? 0) ?>
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
