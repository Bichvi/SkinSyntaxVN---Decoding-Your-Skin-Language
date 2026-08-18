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
    <!-- GREETING HERO BANNER -->
    <div class="admin-card mb-4 p-4" style="background: linear-gradient(135deg, var(--admin-surface) 0%, var(--admin-accent) 100%) !important;">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill mb-2 small fw-bold" style="background: var(--admin-surface); color: var(--admin-primary); border: 1px solid var(--admin-accent-border);">
                    <i class="fa-solid fa-sparkles"></i> SkinSyntaxVN Intelligence Center
                </div>
                <h2 class="fw-extrabold mb-1" style="color: var(--admin-text); font-weight: 800;">Chào buổi sáng, <?= h($adminName) ?> 👋</h2>
                <p class="text-muted mb-0 small">Tổng quan chỉ số hoạt động thương mại mỹ phẩm & chăm sóc da hôm nay.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="btn btn-sm rounded-pill px-3 py-2 fw-bold text-nowrap" style="background: var(--admin-surface); color: var(--admin-text-muted); border: 1px solid var(--admin-border);">
                    <i class="fa-regular fa-calendar-check me-1.5 text-success"></i> Hôm nay, <?= date('d/m/Y') ?>
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
                        <div class="kpi-value"><?= vnd($doanhThu) ?></div>
                    </div>
                    <div class="kpi-card-icon kpi-revenue">
                        <i class="bi bi-wallet2 fs-3"></i>
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
                            <div class="kpi-value" style="color: #D97706;"><?= number_format($donChoXuLy, 0, ',', '.') ?></div>
                        </div>
                        <div class="kpi-card-icon kpi-orders">
                            <i class="bi bi-hourglass-split fs-3"></i>
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
                        <div class="kpi-value" style="color: #2563EB;"><?= number_format($tongSP, 0, ',', '.') ?></div>
                    </div>
                    <div class="kpi-card-icon kpi-products">
                        <i class="bi bi-boxes fs-3"></i>
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
                        <div class="kpi-value" style="color: #9333EA;"><?= number_format($tongUser, 0, ',', '.') ?></div>
                    </div>
                    <div class="kpi-card-icon kpi-users">
                        <i class="bi bi-people-fill fs-3"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CUSTOMER CARE & ACTION QUICK WIDGETS -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <a href="index.php?r=staff_chats" class="text-decoration-none">
                <div class="admin-card mb-0 p-3.5 p-3 d-flex align-items-center justify-content-between" style="transition: transform 0.2s ease;">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: rgba(14, 165, 233, 0.12); color: #0EA5E9; font-size: 1.2rem;">
                            <i class="bi bi-chat-dots-fill"></i>
                        </div>
                        <div>
                            <div class="fw-bold small" style="color: var(--admin-text);">Chat Hỗ Trợ Chờ Trả Lời</div>
                            <div class="small text-muted"><?= $chatPending ?> cuộc hội thoại mới</div>
                        </div>
                    </div>
                    <span class="badge rounded-pill px-3 py-1.5 fw-bold" style="background: #E0F2FE; color: #0369A1;"><?= $chatPending ?></span>
                </div>
            </a>
        </div>

        <div class="col-12 col-md-4">
            <a href="index.php?r=staff_reviews" class="text-decoration-none">
                <div class="admin-card mb-0 p-3.5 p-3 d-flex align-items-center justify-content-between" style="transition: transform 0.2s ease;">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: rgba(245, 158, 11, 0.12); color: #F59E0B; font-size: 1.2rem;">
                            <i class="bi bi-star-fill"></i>
                        </div>
                        <div>
                            <div class="fw-bold small" style="color: var(--admin-text);">Đánh Giá Cần Phản Hồi</div>
                            <div class="small text-muted"><?= $reviewPending ?> đánh giá chưa trả lời</div>
                        </div>
                    </div>
                    <span class="badge rounded-pill px-3 py-1.5 fw-bold" style="background: #FEF3C7; color: #B45309;"><?= $reviewPending ?></span>
                </div>
            </a>
        </div>

        <div class="col-12 col-md-4">
            <a href="index.php?r=admin_orders&status=cho_xu_ly" class="text-decoration-none">
                <div class="admin-card mb-0 p-3.5 p-3 d-flex align-items-center justify-content-between" style="transition: transform 0.2s ease;">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 44px; height: 44px; background: rgba(33, 84, 39, 0.12); color: var(--admin-primary); font-size: 1.2rem;">
                            <i class="bi bi-truck"></i>
                        </div>
                        <div>
                            <div class="fw-bold small" style="color: var(--admin-text);">Cần Xử Lý Đơn Hàng</div>
                            <div class="small text-muted"><?= $donChoXuLy ?> đơn chờ duyệt ngay</div>
                        </div>
                    </div>
                    <span class="badge rounded-pill px-3 py-1.5 fw-bold" style="background: var(--admin-accent); color: var(--admin-primary);"><?= $donChoXuLy ?></span>
                </div>
            </a>
        </div>
    </div>

    <!-- LOW STOCK ALERT WIDGET -->
    <div class="admin-card mb-4 p-3.5 p-3 <?= !empty($lowStockProducts) ? 'border-danger border-opacity-25' : 'border-success border-opacity-25' ?>" style="background: <?= !empty($lowStockProducts) ? 'rgba(239, 68, 68, 0.04)' : 'rgba(34, 197, 94, 0.04)' ?>;">
        <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center <?= !empty($lowStockProducts) ? 'text-danger bg-danger bg-opacity-10' : 'text-success bg-success bg-opacity-10' ?>" style="width: 42px; height: 42px;">
                    <i class="bi <?= !empty($lowStockProducts) ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill' ?> fs-5"></i>
                </div>
                <div>
                    <h6 class="fw-bold mb-0 <?= !empty($lowStockProducts) ? 'text-danger' : 'text-success' ?>">
                        <?= !empty($lowStockProducts) ? 'Cảnh Báo Tồn Kho Sắp Hết (Dưới 5 sản phẩm)' : 'Trạng Thái Kho Hàng An Toàn' ?>
                    </h6>
                    <div class="small text-muted">
                        <?= !empty($lowStockProducts) ? 'Có ' . count($lowStockProducts) . ' sản phẩm cần nhập thêm hàng ngay.' : 'Tất cả 6.377 sản phẩm trong hệ thống hiện tại đều có số lượng tồn kho đầy đủ (> 5 sản phẩm).' ?>
                    </div>
                </div>
            </div>
            <a href="index.php?r=admin_sp" class="btn btn-sm <?= !empty($lowStockProducts) ? 'btn-outline-danger' : 'btn-outline-success' ?> rounded-pill px-3 fw-bold">
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

