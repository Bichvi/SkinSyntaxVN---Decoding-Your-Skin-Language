<?php
$customers = $customers ?? [];
$staffMembers = $staffMembers ?? [];
$roles = $roles ?? [];
$customerEditing = $customerEditing ?? null;
$staffEditing = $staffEditing ?? null;
$q = trim((string)($q ?? ''));
$loaiKh = trim((string)($loaiKh ?? ''));
?>

<style>
    .staff-panel {
        border: 1px solid var(--admin-border) !important;
        background: var(--admin-surface) !important;
    }

    .staff-panel .staff-heading {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .staff-panel .staff-heading-badge {
        width: 36px;
        height: 36px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #EBF2EE;
        color: #183B2B;
        font-weight: 700;
        font-size: 0.85rem;
        border: 1px solid #C8DACF;
    }

    .staff-table thead th {
        white-space: nowrap;
    }

    .staff-name {
        font-weight: 600;
        color: var(--admin-text);
        font-size: 0.86rem;
    }

    .staff-meta {
        font-size: 0.78rem;
        color: var(--admin-text-muted);
    }

    .role-chip,
    .status-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        padding: 0.25rem 0.6rem;
        font-size: 0.74rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .role-chip {
        background: #EBF2EE;
        color: #183B2B;
        border: 1px solid #C8DACF;
    }

    .status-chip.status-active {
        background: #DCFCE7;
        color: #15803D;
        border: 1px solid #BBF7D0;
    }

    .status-chip.status-inactive {
        background: #FEF3C7;
        color: #92400E;
        border: 1px solid #FDE68A;
    }

    .loyalty-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        padding: 0.25rem 0.6rem;
        font-size: 0.74rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .loyalty-chip--diamond {
        background: #F3E8FF;
        color: #6B21A8;
        border: 1px solid #E9D5FF;
    }

    .loyalty-chip--vip {
        background: #E0F2FE;
        color: #0369A1;
        border: 1px solid #BAE6FD;
    }

    .loyalty-chip--regular {
        background: #F1F5F9;
        color: #475569;
        border: 1px solid #CBD5E1;
    }

    .loyalty-points {
        font-size: 0.78rem;
        color: var(--admin-text-muted);
        margin-top: 0.2rem;
        font-variant-numeric: tabular-nums;
    }

    .staff-actions {
        display: inline-flex;
        flex-wrap: nowrap;
        justify-content: flex-end;
        align-items: center;
        gap: 0.35rem;
        white-space: nowrap;
    }

    .staff-actions .btn {
        padding: 0.25rem 0.55rem;
        font-size: 0.78rem;
        border-radius: 4px;
    }

    .staff-form-note {
        margin-top: 0.75rem;
        padding: 0.6rem 0.8rem;
        border-radius: 6px;
        background: #EBF2EE;
        color: #183B2B;
        font-size: 0.8rem;
        border: 1px solid #C8DACF;
    }
</style>

<div class="container-fluid px-4 py-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1" style="color: var(--admin-text);">Khách hàng & Phân quyền hệ thống</h1>
            <p class="text-muted mb-0 small">Quản lý thông tin khách hàng, tài khoản nhân viên và vai trò truy cập.</p>
        </div>
    </div>

    <div class="admin-card mb-3 p-3" style="border-radius: 8px !important;">
        <form class="row g-2" method="get" action="index.php" data-live-filter="true">
            <input type="hidden" name="r" value="admin_users">
            <div class="col-md-6">
                <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Tìm theo tên, email, số điện thoại..." style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="loai_kh" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.85rem;">
                    <option value="">Tất cả loại KH</option>
                    <option value="Thuong" <?= $loaiKh === 'Thuong' ? 'selected' : '' ?>>Thường</option>
                    <option value="VIP" <?= $loaiKh === 'VIP' ? 'selected' : '' ?>>VIP</option>
                    <option value="Kim Cuong" <?= $loaiKh === 'Kim Cuong' ? 'selected' : '' ?>>Kim Cương</option>
                </select>
            </div>
            <div class="col-md-3 d-grid">
                <button type="submit" class="btn text-white fw-semibold" style="background: #183B2B; border-radius: 6px; font-size: 0.85rem;">Tìm kiếm</button>
            </div>
        </form>
    </div>

    <div class="row g-4 mb-4">
        <!-- Customer Edit Form -->
        <div class="col-lg-6">
            <div class="admin-card mb-0 p-3.5 h-100" id="customer-form-card" style="border-radius: 8px !important;">
                <h6 class="fw-bold mb-3" style="color: var(--admin-text);"><?= $customerEditing ? 'Cập nhật thông tin khách hàng' : 'Thêm khách hàng mới' ?></h6>
                <form method="post" action="index.php?r=admin_customer_save" class="row g-2.5 g-2">
                    <input type="hidden" name="ma_kh" value="<?= h($customerEditing['ma_kh'] ?? '') ?>">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Họ tên *</label>
                        <input type="text" class="form-control" name="ho_ten" value="<?= h($customerEditing['ho_ten'] ?? '') ?>" required style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= h($customerEditing['email'] ?? '') ?>" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">SĐT</label>
                        <input type="text" class="form-control" name="so_dien_thoai" value="<?= h($customerEditing['so_dien_thoai'] ?? '') ?>" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Giới tính</label>
                        <input type="text" class="form-control" name="gioi_tinh" value="<?= h($customerEditing['gioi_tinh'] ?? '') ?>" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Năm sinh</label>
                        <input type="number" class="form-control" name="nam_sinh" value="<?= h($customerEditing['nam_sinh'] ?? '') ?>" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Địa chỉ giao hàng</label>
                        <input type="text" class="form-control" name="dia_chi" value="<?= h($customerEditing['dia_chi'] ?? '') ?>" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Mật khẩu <?= $customerEditing ? '(để trống nếu giữ nguyên)' : '<span class="text-danger">* (bắt buộc nếu nhập Email)</span>' ?></label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="customer_mat_khau" name="mat_khau" placeholder="<?= $customerEditing ? 'Nhập mật khẩu mới hoặc tạo ngẫu nhiên' : 'Nhập mật khẩu hoặc tạo ngẫu nhiên' ?>" style="border-radius: 6px 0 0 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                            <button class="btn btn-outline-secondary" type="button" id="btn-generate-customer-pw" style="border-radius: 0 6px 6px 0; font-size: 0.84rem; background: var(--admin-surface); border-color: var(--admin-border); color: var(--admin-text);">Tạo ngẫu nhiên</button>
                        </div>
                    </div>
                    <div class="col-12 d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm text-white fw-semibold px-3" style="background: #183B2B; border-radius: 6px;">Lưu thông tin KH</button>
                        <?php if ($customerEditing): ?>
                            <a href="index.php?r=admin_users" class="btn btn-sm btn-outline-secondary px-3" style="border-radius: 6px;">Hủy bỏ</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Staff Form -->
        <div class="col-lg-6">
            <div class="admin-card mb-0 p-3.5 h-100 staff-panel" id="staff-form-card" style="border-radius: 8px !important;">
                <div class="staff-heading">
                    <span class="staff-heading-badge">NV</span>
                    <div>
                        <h6 class="fw-bold mb-0" style="color: var(--admin-text);"><?= $staffEditing ? 'Cập nhật tài khoản nhân viên' : 'Thêm nhân viên / Phân quyền' ?></h6>
                        <div class="text-muted small" style="font-size: 0.78rem;">Tạo tài khoản quản trị và cấp quyền truy cập hệ thống.</div>
                    </div>
                </div>
                <form method="post" action="index.php?r=admin_staff_save" class="row g-2.5 g-2">
                    <input type="hidden" name="ma_nv" value="<?= h($staffEditing['ma_nv'] ?? '') ?>">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Họ tên *</label>
                        <input type="text" class="form-control" name="ho_ten" value="<?= h($staffEditing['ho_ten'] ?? '') ?>" required style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Email *</label>
                        <input type="email" class="form-control" name="email" value="<?= h($staffEditing['email'] ?? '') ?>" required style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">SĐT</label>
                        <input type="text" class="form-control" name="so_dien_thoai" value="<?= h($staffEditing['so_dien_thoai'] ?? '') ?>" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Mật khẩu <?= $staffEditing ? '(để trống nếu giữ cũ)' : '*' ?></label>
                        <input type="password" class="form-control" name="mat_khau" <?= $staffEditing ? '' : 'required' ?> style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Vai trò hệ thống *</label>
                        <?php $selectedRole = (string)($staffEditing['ma_vai_tro'] ?? ''); ?>
                        <select class="form-select" name="ma_vai_tro" required style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                            <option value="">-- Chọn vai trò --</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= h($role['ma_vai_tro'] ?? '') ?>" <?= $selectedRole === (string)($role['ma_vai_tro'] ?? '') ? 'selected' : '' ?>>
                                    <?= h($role['ten_vai_tro'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 0.78rem;">Trạng thái hoạt động</label>
                        <?php $staffStatus = (string)($staffEditing['trang_thai'] ?? 'active'); ?>
                        <select class="form-select" name="trang_thai" style="border-radius: 6px; border-color: var(--admin-border); font-size: 0.84rem;">
                            <option value="active" <?= $staffStatus === 'active' ? 'selected' : '' ?>>Kích hoạt (Hoạt động)</option>
                            <option value="inactive" <?= $staffStatus === 'inactive' ? 'selected' : '' ?>>Tạm khóa</option>
                        </select>
                    </div>
                    <div class="col-12 d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm text-white fw-semibold px-3" style="background: #183B2B; border-radius: 6px;">Lưu nhân viên</button>
                        <?php if ($staffEditing): ?>
                            <a href="index.php?r=admin_users" class="btn btn-sm btn-outline-secondary px-3" style="border-radius: 6px;">Hủy bỏ</a>
                        <?php endif; ?>
                    </div>
                </form>
                <div class="staff-form-note">
                    <strong>Gợi ý:</strong> Dùng <strong>Tạm khóa</strong> để dừng tạm thời quyền truy cập. Dùng <strong>Xóa</strong> khi loại bỏ hẳn khỏi công ty.
                </div>
            </div>
        </div>
    </div>

    <!-- Data Tables Row -->
    <div class="row g-4">
        <!-- Customers List Table -->
        <div class="col-lg-6">
            <div class="admin-card p-0 overflow-hidden mb-0" style="border-radius: 8px !important;">
                <div class="p-3 border-bottom background-subtle">
                    <h6 class="fw-bold mb-0" style="color: var(--admin-text);">Danh sách khách hàng</h6>
                </div>
                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Khách hàng</th>
                                <th>Liên hệ</th>
                                <th>Hạng & Điểm</th>
                                <th>Tổng đơn</th>
                                <th class="text-end">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customers)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">Chưa có khách hàng.</td></tr>
                            <?php else: ?>
                                <?php foreach ($customers as $item): ?>
                                    <?php
                                    $loaiKh = trim((string)($item['loaikh'] ?? 'Thuong'));
                                    $loyaltyClass = 'loyalty-chip--regular';
                                    if (strcasecmp($loaiKh, 'Kim Cuong') === 0) {
                                        $loyaltyClass = 'loyalty-chip--diamond';
                                    } elseif (strcasecmp($loaiKh, 'VIP') === 0) {
                                        $loyaltyClass = 'loyalty-chip--vip';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold" style="color: var(--admin-text); font-size: 0.85rem;"><?= h($item['ho_ten'] ?? '') ?></div>
                                            <div class="small text-muted" style="font-size: 0.76rem;">#<?= h($item['ma_kh'] ?? '') ?></div>
                                        </td>
                                        <td>
                                            <div style="font-size: 0.82rem;"><?= h($item['email'] ?? 'Chưa có email') ?></div>
                                            <div class="small text-muted" style="font-size: 0.76rem;"><?= h($item['so_dien_thoai'] ?? 'Chưa có SĐT') ?></div>
                                        </td>
                                        <td>
                                            <span class="loyalty-chip <?= $loyaltyClass ?>"><?= h($loaiKh) ?></span>
                                            <div class="loyalty-points"><?= number_format((int)($item['diemtl'] ?? 0), 0, ',', '.') ?> pts</div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold" style="font-size: 0.82rem;"><?= (int)($item['tong_don'] ?? 0) ?> đơn</div>
                                            <div class="small text-muted tabular-nums" style="font-size: 0.76rem;"><?= vnd($item['tong_chi_tieu'] ?? 0) ?></div>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <a href="index.php?r=admin_users&customer_edit=<?= (int)($item['ma_kh'] ?? 0) ?>#customer-form-card" class="btn btn-sm btn-outline-secondary px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" title="Sửa"><i class="bi bi-pencil-square me-1"></i>Sửa</a>
                                                <form method="post" action="index.php?r=admin_customer_delete" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn xóa khách hàng này không?');">
                                                    <input type="hidden" name="ma_kh" value="<?= h($item['ma_kh'] ?? '') ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" title="Xóa"><i class="bi bi-trash me-1"></i>Xóa</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Staff List Table -->
        <div class="col-lg-6">
            <div class="admin-card p-0 overflow-hidden mb-0 staff-panel" style="border-radius: 8px !important;">
                <div class="p-3 border-bottom background-subtle">
                    <h6 class="fw-bold mb-0" style="color: var(--admin-text);">Danh sách nhân viên & Vai trò</h6>
                </div>
                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0 staff-table">
                        <thead>
                            <tr>
                                <th>Nhân viên</th>
                                <th>Vai trò</th>
                                <th>Trạng thái</th>
                                <th class="text-end">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($staffMembers)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">Chưa có nhân viên.</td></tr>
                            <?php else: ?>
                                <?php foreach ($staffMembers as $item): ?>
                                    <?php
                                    $statusRaw = strtolower(trim((string)($item['trang_thai'] ?? 'active')));
                                    $isActive = ($statusRaw === 'active');
                                    $statusLabel = $isActive ? 'Kích hoạt' : 'Tạm khóa';
                                    $statusClass = $isActive ? 'status-active' : 'status-inactive';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="staff-name"><?= h($item['ho_ten'] ?? '') ?></div>
                                            <div class="staff-meta">#<?= h($item['ma_nv'] ?? '') ?> · <?= h($item['email'] ?? '') ?></div>
                                        </td>
                                        <td><span class="role-chip"><?= h($item['ten_vai_tro'] ?? 'Chưa gán') ?></span></td>
                                        <td><span class="status-chip <?= $statusClass ?>"><?= h($statusLabel) ?></span></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1 align-items-center">
                                                <a href="index.php?r=admin_users&staff_edit=<?= (int)($item['ma_nv'] ?? 0) ?>#staff-form-card" class="btn btn-sm btn-outline-secondary px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" title="Sửa"><i class="bi bi-pencil-square me-1"></i>Sửa</a>
                                                <form method="post" action="index.php?r=admin_staff_delete" class="d-inline" onsubmit="return confirm('<?= $isActive ? 'Tạm khóa nhân viên này?' : 'Mở khóa tài khoản nhân viên này?' ?>');">
                                                    <input type="hidden" name="ma_nv" value="<?= h($item['ma_nv'] ?? '') ?>">
                                                    <?php if ($isActive): ?>
                                                        <button type="submit" class="btn btn-sm btn-outline-warning px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" title="Tạm khóa">Khóa</button>
                                                    <?php else: ?>
                                                        <button type="submit" class="btn btn-sm btn-outline-success px-2 py-0.5 fw-semibold" style="border-radius: 4px; font-size: 0.78rem;" title="Kích hoạt lại">Mở</button>
                                                    <?php endif; ?>
                                                </form>
                                                <form method="post" action="index.php?r=admin_staff_hard_delete" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn xóa hẳn nhân viên này khỏi hệ thống không?');">
                                                    <input type="hidden" name="ma_nv" value="<?= h($item['ma_nv'] ?? '') ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger px-2 py-0.5" style="border-radius: 4px; font-size: 0.78rem;" title="Xóa vĩnh viễn"><i class="bi bi-trash me-1"></i>Xóa</button>
                                                </form>
                                            </div>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnGen = document.getElementById('btn-generate-customer-pw');
    const inputPw = document.getElementById('customer_mat_khau');
    if (btnGen && inputPw) {
        btnGen.addEventListener('click', function() {
            // Tạo mật khẩu ngẫu nhiên gồm chữ thường, chữ hoa và số dài 10 ký tự
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let password = '';
            for (let i = 0; i < 10; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            inputPw.value = password;
        });
    }
});
</script>