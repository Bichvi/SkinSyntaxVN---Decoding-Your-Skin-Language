<?php
$customers = $customers ?? [];
$staffMembers = $staffMembers ?? [];
$roles = $roles ?? [];
$customerEditing = $customerEditing ?? null;
$staffEditing = $staffEditing ?? null;
$q = trim((string)($q ?? ''));
?>

<div class="container-fluid p-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Khách hàng, nhân viên và phân quyền</h1>
            <p class="text-muted mb-0">Quản lý thông tin khách hàng, nhân viên và vai trò trong hệ thống.</p>
        </div>
    </div>

    <form class="row g-2 mb-4" method="get" action="index.php">
        <input type="hidden" name="r" value="admin_users">
        <div class="col-md-9">
            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Tìm theo tên, email, số điện thoại...">
        </div>
        <div class="col-md-3 d-grid">
            <button type="submit" class="btn btn-outline-primary">Tìm kiếm</button>
        </div>
    </form>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
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
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><?= $staffEditing ? 'Cập nhật nhân viên' : 'Thêm nhân viên / phân quyền' ?></h5>
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
                                <option value="active" <?= $staffStatus === 'active' ? 'selected' : '' ?>>Đang hoạt động</option>
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
                                    <th>Đơn hàng</th>
                                    <th class="text-end">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($customers)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">Chưa có khách hàng.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($customers as $item): ?>
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
                                                <div><?= (int)($item['tong_don'] ?? 0) ?> đơn</div>
                                                <div class="small text-muted"><?= vnd($item['tong_chi_tieu'] ?? 0) ?></div>
                                            </td>
                                            <td class="text-end">
                                                <a href="index.php?r=admin_users&customer_edit=<?= (int)($item['ma_kh'] ?? 0) ?>" class="btn btn-sm btn-outline-warning">Sửa</a>
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
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Danh sách nhân viên và vai trò</h5>
                    <div class="table-responsive">
                        <table class="table align-middle table-hover mb-0">
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
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= h($item['ho_ten'] ?? '') ?></div>
                                                <div class="small text-muted"><?= h($item['email'] ?? '') ?></div>
                                            </td>
                                            <td><span class="badge rounded-pill text-bg-primary"><?= h($item['ten_vai_tro'] ?? 'Chưa gán') ?></span></td>
                                            <td><span class="badge rounded-pill <?= (($item['trang_thai'] ?? 'active') === 'active') ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= h($item['trang_thai'] ?? 'active') ?></span></td>
                                            <td class="text-end">
                                                <a href="index.php?r=admin_users&staff_edit=<?= (int)($item['ma_nv'] ?? 0) ?>" class="btn btn-sm btn-outline-warning">Sửa</a>
                                                <form method="post" action="index.php?r=admin_staff_delete" class="d-inline" onsubmit="return confirm('Ngừng kích hoạt nhân viên này?');">
                                                    <input type="hidden" name="ma_nv" value="<?= h($item['ma_nv'] ?? '') ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Ngừng</button>
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
    </div>
</div>