<?php
$pageTitle = trim((string)($pageTitle ?? 'Không có quyền truy cập'));
$viewerRole = strtolower(trim((string)(($_SESSION['user']['role'] ?? $_SESSION['user']['vai_tro'] ?? 'guest'))));
?>

<div class="container-fluid p-4">
    <div class="card border-0 shadow-sm rounded-4" style="max-width: 760px;">
        <div class="card-body p-5">
            <div class="text-danger small fw-semibold mb-2">403 Forbidden</div>
            <h1 class="h3 mb-3"><?= h($pageTitle) ?></h1>
            <p class="text-muted mb-4">Tài khoản hiện tại không được cấp quyền cho chức năng này. Liên hệ quản trị viên nếu bạn cần được cấp lại quyền.</p>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($viewerRole === 'nhanvien'): ?>
                    <a href="index.php?r=staff_dashboard" class="btn btn-primary">Về bảng làm việc</a>
                <?php elseif ($viewerRole === 'admin'): ?>
                    <a href="index.php?r=admin_dashboard" class="btn btn-primary">Về dashboard</a>
                <?php endif; ?>
                <a href="index.php?r=dangxuat" class="btn btn-light border">Đăng xuất</a>
            </div>
        </div>
    </div>
</div>