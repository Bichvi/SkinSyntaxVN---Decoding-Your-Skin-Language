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
?>

<div class="container-fluid px-4 py-4">
<div class="container-fluid px-4 py-4">
    <!-- GREETING HERO BANNER -->
    <div class="admin-card mb-4 p-3.5" style="border-radius: 8px !important;">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 px-2.5 py-1 mb-2 small fw-semibold" style="background: #EBF2EE; color: #183B2B; border: 1px solid #C8DACF; border-radius: 4px;">
                    <i class="fa-solid fa-sparkles"></i> SkinSyntaxVN Intelligence Center
                </div>
                <h2 class="fw-bold mb-1" style="color: var(--admin-text); font-size: 1.5rem;">Chào buổi sáng, <?= h($adminName) ?> 👋</h2>
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
                            <div class="fw-semibold small" style="color: var(--admin-text);">Phiên LiveStream AI</div>
                            <div class="small text-muted">Tạo & Ghim sản phẩm</div>
                        </div>
                    </div>
                    <span class="badge px-2 py-1 fw-semibold" style="background: #FFE4E6; color: #E11D48; border: 1px solid #FECDD3; border-radius: 4px; font-size: 0.76rem;">Live</span>
                </div>
            </a>
        </div>
    </div>

    <!-- DASHBOARD CHARTS ROW (CHART.JS VISUALIZATIONS) -->
    <div class="row g-4 mb-4">
        <!-- Revenue Bar Chart -->
        <div class="col-12 col-lg-8">
            <div class="admin-card mb-0 p-3.5 h-100" style="border-radius: 8px !important;">
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <div>
                        <h6 class="fw-bold mb-0" style="color: var(--admin-text);"><i class="bi bi-bar-chart-line-fill text-success me-1.5"></i> Thống kê Doanh số & Đơn hàng 7 ngày gần nhất</h6>
                        <div class="small text-muted" style="font-size: 0.78rem;">Tổng hợp xu hướng bán hàng tuần hiện tại</div>
                    </div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1" style="border-radius: 4px; font-size: 0.75rem;">Tuần này</span>
                </div>
                <div style="height: 240px; position: relative;">
                    <canvas id="adminSalesChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Inventory / Categories Doughnut Chart -->
        <div class="col-12 col-lg-4">
            <div class="admin-card mb-0 p-3.5 h-100" style="border-radius: 8px !important;">
                <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                    <div>
                        <h6 class="fw-bold mb-0" style="color: var(--admin-text);"><i class="bi bi-pie-chart-fill text-warning me-1.5"></i> Tỷ lệ trạng thái sản phẩm</h6>
                        <div class="small text-muted" style="font-size: 0.78rem;">Tồn kho & hiển thị sản phẩm</div>
                    </div>
                </div>
                <div style="height: 240px; position: relative;" class="d-flex align-items-center justify-content-center">
                    <canvas id="adminCategoryChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Admin Sales Bar Chart
        var ctxSales = document.getElementById('adminSalesChart');
        if (ctxSales && typeof Chart !== 'undefined') {
            new Chart(ctxSales, {
                type: 'bar',
                data: {
                    labels: ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'],
                    datasets: [{
                        label: 'Doanh thu (Triệu VNĐ)',
                        data: [15.2, 22.4, 18.9, 29.5, 34.1, 41.8, 48.0],
                        backgroundColor: 'rgba(24, 59, 43, 0.85)',
                        borderColor: '#183B2B',
                        borderWidth: 1,
                        borderRadius: 6
                    }, {
                        label: 'Số đơn hoàn tất',
                        data: [12, 18, 15, 24, 28, 35, 42],
                        backgroundColor: 'rgba(217, 119, 6, 0.35)',
                        borderColor: '#D97706',
                        borderWidth: 1,
                        borderRadius: 6
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

        // Admin Category Doughnut Chart
        var ctxCat = document.getElementById('adminCategoryChart');
        if (ctxCat && typeof Chart !== 'undefined') {
            new Chart(ctxCat, {
                type: 'doughnut',
                data: {
                    labels: ['Đang bán', 'Chờ xử lý', 'Cảnh báo kho', 'Khách hàng mới'],
                    datasets: [{
                        data: [<?= max(1, $tongSP) ?>, <?= max(1, $donChoXuLy) ?>, <?= count($lowStockProducts ?? []) ?>, <?= max(1, $tongUser) ?>],
                        backgroundColor: ['#183B2B', '#B45309', '#E11D48', '#0369A1'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { font: { family: 'Quicksand', size: 11 }, boxWidth: 12 } }
                    },
                    cutout: '68%'
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

