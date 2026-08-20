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
  background: linear-gradient(135deg, var(--admin-surface, #ffffff) 0%, var(--admin-accent, #f0f4f1) 100%) !important;
  border: 1px solid var(--admin-border, #e2eadf);
  border-radius: 20px;
  box-shadow: 0 4px 20px rgba(45, 90, 39, 0.05);
}
.report-kpi-card {
  background: var(--admin-surface, #ffffff);
  border: 1px solid var(--admin-border, #e2eadf);
  border-radius: 18px;
  padding: 22px 24px;
  box-shadow: 0 4px 16px rgba(45, 90, 39, 0.04);
  transition: all 0.25s ease;
  height: 100%;
}
.report-kpi-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 25px rgba(45, 90, 39, 0.08);
  border-color: #84A98C;
}
.report-kpi-icon {
  width: 48px;
  height: 48px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.4rem;
  flex-shrink: 0;
}
.rank-badge {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: inline-grid;
  place-items: center;
  font-weight: 800;
  font-size: 0.82rem;
}
.rank-1 { background: #FEF3C7; color: #B45309; border: 1px solid #F59E0B; }
.rank-2 { background: #E0F2FE; color: #0369A1; border: 1px solid #0EA5E9; }
.rank-3 { background: #DCFCE7; color: #15803D; border: 1px solid #22C55E; }
.rank-other { background: #F1F5F9; color: #64748B; }
</style>

<div class="container-fluid px-4 py-4">
    <!-- HEADER HERO & FILTER PANEL -->
    <div class="report-hero-card p-4 mb-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill mb-2 small fw-bold" style="background: rgba(33,84,39,0.1); color: #215427;">
                    <i class="bi bi-graph-up-arrow me-1"></i> Báo Cáo Kinh Doanh & Doanh Thu
                </div>
                <h2 class="fw-extrabold mb-1" style="color: var(--admin-text); font-weight: 800;">Báo cáo & Thống kê hệ thống</h2>
                <p class="text-muted mb-0 small">Phân tích hiệu suất bán hàng, doanh thu theo tháng và top sản phẩm dẫn đầu.</p>
            </div>
            <div>
                <span class="badge rounded-pill px-3 py-2 fw-bold text-success border border-success-subtle bg-success-subtle">
                    <i class="bi bi-check-circle-fill me-1"></i> Dữ liệu tự động cập nhật
                </span>
            </div>
        </div>

        <form method="get" action="index.php" class="row g-3 align-items-end pt-3" style="border-top: 1px solid var(--admin-border);">
            <input type="hidden" name="r" value="admin_reports">
            <div class="col-12 col-md-3">
                <label class="form-label fw-bold small text-muted" for="reportStartDate">TỪ NGÀY</label>
                <input type="date" class="form-control rounded-3" id="reportStartDate" name="start_date" value="<?= h($reportStartDate) ?>">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label fw-bold small text-muted" for="reportEndDate">ĐẾN NGÀY</label>
                <input type="date" class="form-control rounded-3" id="reportEndDate" name="end_date" value="<?= h($reportEndDate) ?>">
            </div>
            <div class="col-12 col-md-6 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-success px-4 rounded-3 fw-bold">
                    <i class="bi bi-funnel-fill me-1.5"></i> Lọc Báo Cáo
                </button>
                <button type="submit" name="export" value="excel" class="btn btn-outline-success px-4 rounded-3 fw-bold">
                    <i class="bi bi-file-earmark-excel-fill me-1.5"></i> Xuất Excel
                </button>
                <?php if ($hasDateFilter): ?>
                    <a class="btn btn-light border px-3 rounded-3 fw-semibold" href="index.php?r=admin_reports">Xóa Lọc</a>
                <?php endif; ?>
            </div>
            <div class="col-12">
                <div class="small text-muted fw-semibold"><i class="bi bi-info-circle-fill me-1 text-primary"></i> <?= h($reportPeriodText) ?></div>
                <?php if ($reportError !== ''): ?>
                    <div class="alert alert-danger mt-2 mb-0 rounded-3"><?= h($reportError) ?></div>
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
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Doanh Thu Kỳ Này</div>
                    <div class="fs-3 fw-extrabold text-danger my-1" style="font-weight: 800;"><?= vnd($totalRevenueWindow) ?></div>
                    <div class="small text-muted fw-medium"><?= number_format($totalOrdersWindow) ?> đơn hàng hợp lệ</div>
                </div>
                <div class="report-kpi-icon" style="background: rgba(225, 29, 72, 0.12); color: #E11D48;">
                    <i class="bi bi-wallet2"></i>
                </div>
            </div>
        </div>

        <!-- 2. Average Monthly Revenue -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="report-kpi-card d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Trung Bình / Tháng</div>
                    <div class="fs-3 fw-extrabold text-success my-1" style="font-weight: 800;"><?= vnd($averageRevenueWindow) ?></div>
                    <div class="small text-muted fw-medium">Hiệu suất trung bình</div>
                </div>
                <div class="report-kpi-icon" style="background: rgba(34, 197, 94, 0.12); color: #22C55E;">
                    <i class="bi bi-graph-up-arrow"></i>
                </div>
            </div>
        </div>

        <!-- 3. Peak Revenue Month -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="report-kpi-card d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;">Tháng Đạt Đỉnh</div>
                    <div class="fs-3 fw-extrabold text-primary my-1" style="font-weight: 800;"><?= h($bestMonth['thang'] ?? 'Chưa có') ?></div>
                    <div class="small text-muted fw-medium"><?= $bestMonth ? (vnd($bestMonth['doanh_thu'] ?? 0) . ' (' . number_format((int)($bestMonth['so_don'] ?? 0)) . ' đơn)') : 'Chưa có dữ liệu' ?></div>
                </div>
                <div class="report-kpi-icon" style="background: rgba(14, 165, 233, 0.12); color: #0EA5E9;">
                    <i class="bi bi-trophy-fill"></i>
                </div>
            </div>
        </div>

        <!-- 4. Top Performing Product -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="report-kpi-card d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase" style="letter-spacing: 0.05em;">SP Bán Chạy Nhất</div>
                    <div class="fs-6 fw-bold text-dark text-truncate my-1" style="max-width: 170px;" title="<?= h($topProduct['ten_san_pham'] ?? '') ?>">
                        <?= h($topProduct['ten_san_pham'] ?? 'Chưa có dữ liệu') ?>
                    </div>
                    <div class="small text-muted fw-medium"><?= $topProduct ? (number_format((int)($topProduct['so_don_vi'] ?? 0)) . ' lượt bán • ' . vnd($topProduct['doanh_thu'] ?? 0)) : 'N/A' ?></div>
                </div>
                <div class="report-kpi-icon" style="background: rgba(245, 158, 11, 0.12); color: #F59E0B;">
                    <i class="bi bi-award-fill"></i>
                </div>
            </div>
        </div>
    </div>

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
