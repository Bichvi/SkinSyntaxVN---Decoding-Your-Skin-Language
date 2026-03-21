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
        border: 1px solid rgba(15, 107, 62, 0.14);
        background: linear-gradient(180deg, rgba(15, 107, 62, 0.07), rgba(15, 107, 62, 0.02));
    }

    .staff-panel .staff-heading {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .staff-panel .staff-heading-badge {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: rgba(15, 107, 62, 0.12);
        color: #0f6b3e;
        font-weight: 700;
    }

    .staff-table thead th {
        white-space: nowrap;
    }

    .staff-name {
        font-weight: 700;
        color: #1f2937;
    }

    .staff-meta {
        font-size: 0.85rem;
        color: #6c757d;
    }

    .role-chip,
    .status-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        padding: 0.42rem 0.8rem;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.01em;
        white-space: nowrap;
    }

    .role-chip {
        background: rgba(15, 107, 62, 0.12);
        color: #0f6b3e;
    }

    .status-chip.status-active {
        background: rgba(15, 107, 62, 0.14);
        color: #0f6b3e;
        min-width: 138px;
    }

    .loyalty-chip {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        padding: 0.36rem 0.76rem;
        font-size: 0.78rem;
        font-weight: 700;
        white-space: nowrap;
        background: rgba(15, 107, 62, 0.1);
        color: #0f6b3e;
    }

    .loyalty-chip--diamond {
        background: rgba(22, 101, 52, 0.18);
        color: #14532d;
    }

    .loyalty-chip--vip {
        background: rgba(15, 107, 62, 0.12);
        color: #0f6b3e;
    }

    .loyalty-chip--regular {
        background: rgba(107, 114, 128, 0.14);
        color: #475569;
    }

    .loyalty-points {
        font-size: 0.86rem;
        color: #475569;
        margin-top: 0.3rem;
    }

    .status-chip.status-inactive {
        background: rgba(143, 169, 150, 0.24);
        color: #3f5e49;
        min-width: 138px;
    }

    .staff-actions {
        display: inline-flex;
        flex-wrap: nowrap;
        justify-content: flex-end;
        align-items: center;
        gap: 0.45rem;
        white-space: nowrap;
    }

    .staff-actions .btn {
        min-width: 68px;
    }

    .staff-table td:nth-child(3),
    .staff-table td:nth-child(4) {
        white-space: nowrap;
    }

    @media (max-width: 1399.98px) {
        .staff-actions {
            gap: 0.35rem;
        }

        .staff-actions .btn {
            min-width: 62px;
            padding-left: 0.55rem;
            padding-right: 0.55rem;
        }
    }

    .staff-form-note {
        margin-top: 0.75rem;
        padding: 0.75rem 0.9rem;
        border-radius: 0.9rem;
        background: rgba(15, 107, 62, 0.08);
        color: #1f5c3e;
        font-size: 0.9rem;
    }
</style>

<div class="container-fluid p-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Khách hàng, nhân viên và phân quyền</h1>
            <p class="text-muted mb-0">Quản lý thông tin khách hàng, nhân viên và vai trò trong hệ thống.</p>
        </div>
    </div>

    <form class="row g-2 mb-4" method="get" action="index.php">
        <input type="hidden" name="r" value="admin_users">
        <div class="col-md-6">
            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Tìm theo tên, email, số điện thoại...">
        </div>
        <div class="col-md-3">
            <select class="form-select" name="loai_kh">
                <option value="">Tất cả loại KH</option>
                <option value="Thuong" <?= $loaiKh === 'Thuong' ? 'selected' : '' ?>>Thường</option>
                <option value="VIP" <?= $loaiKh === 'VIP' ? 'selected' : '' ?>>VIP</option>
                <option value="Kim Cuong" <?= $loaiKh === 'Kim Cuong' ? 'selected' : '' ?>>Kim Cương</option>
            </select>
        </div>
        <div class="col-md-3 d-grid">
            <button type="submit" class="btn btn-outline-primary">Tìm kiếm</button>
        </div>
    </form>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100" id="customer-form-card">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><?= $customerEditing ? 'Cập nhật khách hàng' : 'Thêm khách hàng' ?></h5>
                    <form method="post" action="index.php?r=admin_customer_save" class="row g-3">
                        <input type="hidden" name="ma_kh" value="<?= h($customerEditing['ma_kh'] ?? '') ?>">
                        <div class="col-md-6">
                            <label class="form-label">Họ tên</label>
                            <input type="text" class="form-control" name="ho_ten" value="<?= h($customerEditing['ho_ten'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?= h($customerEditing['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">SĐT</label>
                            <input type="text" class="form-control" name="so_dien_thoai" value="<?= h($customerEditing['so_dien_thoai'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Giới tính</label>
                            <input type="text" class="form-control" name="gioi_tinh" value="<?= h($customerEditing['gioi_tinh'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Năm sinh</label>
                            <input type="number" class="form-control" name="nam_sinh" value="<?= h($customerEditing['nam_sinh'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Địa chỉ</label>
                            <input type="text" class="form-control" name="dia_chi" value="<?= h($customerEditing['dia_chi'] ?? '') ?>">
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Lưu khách hàng</button>
                            <?php if ($customerEditing): ?>
                                <a href="index.php?r=admin_users" class="btn btn-light border">Hủy</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100 staff-panel" id="staff-form-card">
                <div class="card-body p-4">
                    <div class="staff-heading">
                        <span class="staff-heading-badge">NV</span>
                        <div>
                            <h5 class="fw-bold mb-1"><?= $staffEditing ? 'Cập nhật nhân viên' : 'Thêm nhân viên / phân quyền' ?></h5>
                            <div class="text-muted small">Khu vực dành riêng cho quản trị nhân viên, vai trò và trạng thái hoạt động.</div>
                        </div>
                    </div>
                    <form method="post" action="index.php?r=admin_staff_save" class="row g-3">
                        <input type="hidden" name="ma_nv" value="<?= h($staffEditing['ma_nv'] ?? '') ?>">
                        <div class="col-md-6">
                            <label class="form-label">Họ tên</label>
                            <input type="text" class="form-control" name="ho_ten" value="<?= h($staffEditing['ho_ten'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" value="<?= h($staffEditing['email'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">SĐT</label>
                            <input type="text" class="form-control" name="so_dien_thoai" value="<?= h($staffEditing['so_dien_thoai'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mật khẩu <?= $staffEditing ? '(bỏ trống để giữ nguyên)' : '' ?></label>
                            <input type="password" class="form-control" name="mat_khau" <?= $staffEditing ? '' : 'required' ?>>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Vai trò</label>
                            <?php $selectedRole = (string)($staffEditing['ma_vai_tro'] ?? ''); ?>
                            <select class="form-select" name="ma_vai_tro" required>
                                <option value="">-- Chọn vai trò --</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= h($role['ma_vai_tro'] ?? '') ?>" <?= $selectedRole === (string)($role['ma_vai_tro'] ?? '') ? 'selected' : '' ?>>
                                        <?= h($role['ten_vai_tro'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Trạng thái</label>
                            <?php $staffStatus = (string)($staffEditing['trang_thai'] ?? 'active'); ?>
                            <select class="form-select" name="trang_thai">
                                <option value="active" <?= $staffStatus === 'active' ? 'selected' : '' ?>>Hoạt động</option>
                                <option value="inactive" <?= $staffStatus === 'inactive' ? 'selected' : '' ?>>Tạm khóa</option>
                            </select>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Lưu nhân viên</button>
                            <?php if ($staffEditing): ?>
                                <a href="index.php?r=admin_users" class="btn btn-light border">Hủy</a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <div class="staff-form-note">
                        Gợi ý: dùng <strong>Tạm khóa</strong> để chặn đăng nhập, dùng <strong>Xóa</strong> khi muốn loại bỏ hẳn nhân viên khỏi hệ thống.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Danh sách khách hàng</h5>
                    <div class="table-responsive">
                        <table class="table align-middle table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Khách hàng</th>
                                    <th>Liên hệ</th>
                                    <th>Hạng thành viên</th>
                                    <th>Đơn hàng</th>
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
                                                <div class="fw-semibold"><?= h($item['ho_ten'] ?? '') ?></div>
                                                <div class="small text-muted">#<?= h($item['ma_kh'] ?? '') ?></div>
                                            </td>
                                            <td>
                                                <div><?= h($item['email'] ?? 'Chưa có email') ?></div>
                                                <div class="small text-muted"><?= h($item['so_dien_thoai'] ?? 'Chưa có SĐT') ?></div>
                                            </td>
                                            <td>
                                                <span class="loyalty-chip <?= $loyaltyClass ?>"><?= h($loaiKh) ?></span>
                                                <div class="loyalty-points"><?= number_format((int)($item['diemtl'] ?? 0), 0, ',', '.') ?> điểm</div>
                                            </td>
                                            <td>
                                                <div><?= (int)($item['tong_don'] ?? 0) ?> đơn</div>
                                                <div class="small text-muted"><?= vnd($item['tong_chi_tieu'] ?? 0) ?></div>
                                            </td>
                                            <td class="text-end">
                                                <a href="index.php?r=admin_users&customer_edit=<?= (int)($item['ma_kh'] ?? 0) ?>#customer-form-card" class="btn btn-sm btn-outline-warning">Sửa</a>
                                                <form method="post" action="index.php?r=admin_customer_delete" class="d-inline" onsubmit="return confirm('Xóa khách hàng này?');">
                                                    <input type="hidden" name="ma_kh" value="<?= h($item['ma_kh'] ?? '') ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Xóa</button>
                                                </form>
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

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 staff-panel">
                <div class="card-body p-4">
                    <div class="staff-heading">
                        <span class="staff-heading-badge">QL</span>
                        <div>
                            <h5 class="fw-bold mb-1">Danh sách nhân viên và vai trò</h5>
                            <div class="text-muted small">Phân biệt rõ vai trò, trạng thái và các thao tác quản trị cho từng nhân viên.</div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle table-hover mb-0 staff-table">
                            <thead class="table-light">
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
                                        $statusLabel = $statusRaw === 'active' ? 'Hoạt động' : 'Tạm khóa';
                                        $statusClass = $statusRaw === 'active' ? 'status-active' : 'status-inactive';
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="staff-name"><?= h($item['ho_ten'] ?? '') ?></div>
                                                <div class="staff-meta">#<?= h($item['ma_nv'] ?? '') ?> · <?= h($item['email'] ?? '') ?></div>
                                            </td>
                                            <td><span class="role-chip"><?= h($item['ten_vai_tro'] ?? 'Chưa gán') ?></span></td>
                                            <td><span class="status-chip <?= $statusClass ?>"><?= h($statusLabel) ?></span></td>
                                            <td class="text-end">
                                                <div class="staff-actions">
                                                    <a href="index.php?r=admin_users&staff_edit=<?= (int)($item['ma_nv'] ?? 0) ?>#staff-form-card" class="btn btn-sm btn-outline-warning">Sửa</a>
                                                    <form method="post" action="index.php?r=admin_staff_delete" class="d-inline" onsubmit="return confirm('Ngừng kích hoạt nhân viên này?');">
                                                        <input type="hidden" name="ma_nv" value="<?= h($item['ma_nv'] ?? '') ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Ngừng</button>
                                                    </form>
                                                    <form method="post" action="index.php?r=admin_staff_hard_delete" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn xóa hẳn nhân viên này khỏi hệ thống không? Dữ liệu liên kết sẽ không thể khôi phục.');">
                                                        <input type="hidden" name="ma_nv" value="<?= h($item['ma_nv'] ?? '') ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger">Xóa</button>
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
</div>