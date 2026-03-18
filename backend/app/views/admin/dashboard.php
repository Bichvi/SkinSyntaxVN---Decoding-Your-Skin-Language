<?php require_once 'layouts/header.php'; ?>

<?php
$tongSP = (int)($tongSP ?? 0);
$tongUser = (int)($tongUser ?? 0);
$doanhThu = (float)($doanhThu ?? 0);
$donChoXuLy = (int)($donChoXuLy ?? 0);
$spMoi = is_array($spMoi ?? null) ? $spMoi : [];
$userMoi = is_array($userMoi ?? null) ? $userMoi : [];
?>

<div class="container-fluid p-4">
    <h3 class="fw-bold mb-4">Tổng quan hệ thống</h3>

    <div class="row">
        <div class="col-xl-3 col-sm-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-muted fs-6 mb-1">Tổng sản phẩm</p>
                        <h4 class="fs-2 fw-bold mb-0"><?= number_format($tongSP, 0, ',', '.') ?></h4>
                    </div>
                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                        <i class="fa-solid fa-boxes-stacked fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-muted fs-6 mb-1">Người dùng</p>
                        <h4 class="fs-2 fw-bold mb-0"><?= number_format($tongUser, 0, ',', '.') ?></h4>
                    </div>
                    <div class="rounded-circle bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                        <i class="fa-solid fa-users fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-muted fs-6 mb-1">Doanh thu</p>
                        <h4 class="fs-2 fw-bold mb-0"><?= number_format($doanhThu, 0, ',', '.') ?>đ</h4>
                    </div>
                    <div class="rounded-circle bg-warning bg-opacity-10 text-warning d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                        <i class="fa-solid fa-sack-dollar fs-4"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-muted fs-6 mb-1">Đơn chờ xử lý</p>
                        <h4 class="fs-2 fw-bold mb-0"><?= number_format($donChoXuLy, 0, ',', '.') ?></h4>
                    </div>
                    <div class="rounded-circle bg-danger bg-opacity-10 text-danger d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                        <i class="fa-solid fa-hourglass-half fs-4"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                <h5 class="fw-bold mb-3">Sản phẩm mới cập nhật</h5>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle mb-0">
                        <thead>
                            <tr class="text-muted">
                                <th>Ảnh</th>
                                <th>Tên</th>
                                <th>Mã SP</th>
                                <th>Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($spMoi)): ?>
                                <?php foreach ($spMoi as $index => $sp): ?>
                                    <?php
                                    $img = trim((string)($sp['hinh_anh'] ?? ''));
                                    if ($img !== '' && !preg_match('/^https?:\/\//i', $img)) {
                                        $img = BASE_URL . '/uploads/products/' . rawurlencode($img);
                                    }
                                    $isLast = $index === count($spMoi) - 1;
                                    $status = trim((string)($sp['trang_thai'] ?? ''));
                                    if ($status === '') {
                                        $status = 'Chưa cập nhật';
                                    }
                                    ?>
                                    <tr class="<?= $isLast ? '' : 'border-bottom' ?>">
                                        <td>
                                            <?php if ($img !== ''): ?>
                                                <img src="<?= h($img) ?>" alt="<?= h($sp['ten_san_pham'] ?? '') ?>" class="rounded-2" width="48" height="48" style="object-fit: cover;">
                                            <?php else: ?>
                                                <div class="rounded-2 bg-light d-inline-flex align-items-center justify-content-center text-muted" style="width: 48px; height: 48px;">
                                                    <i class="fa-regular fa-image"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-semibold"><?= h($sp['ten_san_pham'] ?? '') ?></td>
                                        <td><?= h($sp['ma_san_pham'] ?? '') ?></td>
                                        <td><span class="badge rounded-pill text-bg-secondary"><?= h($status) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">Chưa có dữ liệu sản phẩm.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                <h5 class="fw-bold mb-3">Người dùng đăng ký mới</h5>
                <div class="table-responsive">
                    <table class="table table-hover table-borderless align-middle mb-0">
                        <thead>
                            <tr class="text-muted">
                                <th>Tên</th>
                                <th>Email</th>
                                <th>Ngày</th>
                                <th>Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($userMoi)): ?>
                                <?php foreach ($userMoi as $index => $user): ?>
                                    <?php
                                    $isLast = $index === count($userMoi) - 1;
                                    $rawDate = $user['ngay_dang_ky'] ?? $user['ngay_tao'] ?? $user['created_at'] ?? null;
                                    $dateText = 'N/A';
                                    if (!empty($rawDate)) {
                                        $timestamp = strtotime((string)$rawDate);
                                        if ($timestamp !== false) {
                                            $dateText = date('d/m/Y', $timestamp);
                                        }
                                    }

                                    $role = strtolower(trim((string)($user['vai_tro'] ?? '')));
                                    $statusText = $role === 'khach_hang' ? 'Khách hàng' : ($role === '' ? 'Mới' : ucfirst($role));
                                    $statusClass = $role === 'khach_hang' ? 'text-bg-success' : 'text-bg-secondary';
                                    ?>
                                    <tr class="<?= $isLast ? '' : 'border-bottom' ?>">
                                        <td class="fw-semibold"><?= h($user['ho_ten'] ?? '') ?></td>
                                        <td><?= h($user['email'] ?? '') ?></td>
                                        <td><?= h($dateText) ?></td>
                                        <td><span class="badge rounded-pill <?= $statusClass ?>"><?= h($statusText) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-3">Chưa có dữ liệu người dùng.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'layouts/footer.php'; ?>
