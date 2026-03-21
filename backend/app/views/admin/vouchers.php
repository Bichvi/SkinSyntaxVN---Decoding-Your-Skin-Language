<?php
$items = $items ?? [];
$editing = $editing ?? null;
$q = trim((string)($q ?? ''));

$isPercent = (($editing['loai_giam'] ?? 'fixed') === 'percent');
$startValue = !empty($editing['ngay_bat_dau']) ? date('Y-m-d\TH:i', strtotime((string)$editing['ngay_bat_dau'])) : '';
$endValue = !empty($editing['ngay_ket_thuc']) ? date('Y-m-d\TH:i', strtotime((string)$editing['ngay_ket_thuc'])) : '';
?>

<div class="container-fluid p-4">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Quản lý voucher</h1>
            <p class="text-muted mb-0">Tạo, cập nhật và xóa mã giảm giá áp dụng ở bước thanh toán.</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><?= $editing ? 'Cập nhật voucher' : 'Tạo voucher mới' ?></h5>
                    <form method="post" action="index.php?r=admin_voucher_save" class="row g-3">
                        <input type="hidden" name="ma_voucher" value="<?= h($editing['ma_voucher'] ?? '') ?>">

                        <div class="col-12">
                            <label class="form-label">Mã voucher</label>
                            <input type="text" class="form-control" name="ma_code" value="<?= h($editing['ma_code'] ?? '') ?>" placeholder="Ví dụ: SKIN10" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Tên voucher</label>
                            <input type="text" class="form-control" name="ten_voucher" value="<?= h($editing['ten_voucher'] ?? '') ?>" placeholder="Ví dụ: Giảm 10% đơn đầu tiên" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Mô tả</label>
                            <textarea class="form-control" name="mo_ta" rows="3" placeholder="Mô tả ngắn về điều kiện áp dụng"><?= h($editing['mo_ta'] ?? '') ?></textarea>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Loại giảm</label>
                            <select class="form-select" name="loai_giam">
                                <option value="fixed" <?= $isPercent ? '' : 'selected' ?>>Giảm số tiền cố định</option>
                                <option value="percent" <?= $isPercent ? 'selected' : '' ?>>Giảm theo phần trăm</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Giá trị giảm</label>
                            <input type="number" min="1" class="form-control" name="gia_tri_giam" value="<?= h($editing['gia_tri_giam'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Đơn tối thiểu</label>
                            <input type="number" min="0" class="form-control" name="gia_tri_don_toi_thieu" value="<?= h($editing['gia_tri_don_toi_thieu'] ?? '0') ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Giảm tối đa</label>
                            <input type="number" min="0" class="form-control" name="giam_toi_da" value="<?= h($editing['giam_toi_da'] ?? '') ?>" placeholder="Bỏ trống nếu không giới hạn">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Số lượt dùng</label>
                            <input type="number" min="0" class="form-control" name="so_luong" value="<?= h($editing['so_luong'] ?? '') ?>" placeholder="Bỏ trống nếu không giới hạn">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Trạng thái</label>
                            <select class="form-select" name="trang_thai">
                                <option value="active" <?= (($editing['trang_thai'] ?? 'active') === 'active') ? 'selected' : '' ?>>Đang hoạt động</option>
                                <option value="inactive" <?= (($editing['trang_thai'] ?? '') === 'inactive') ? 'selected' : '' ?>>Tạm khóa</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Bắt đầu áp dụng</label>
                            <input type="datetime-local" class="form-control" name="ngay_bat_dau" value="<?= h($startValue) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Kết thúc áp dụng</label>
                            <input type="datetime-local" class="form-control" name="ngay_ket_thuc" value="<?= h($endValue) ?>">
                        </div>

                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><?= $editing ? 'Lưu cập nhật' : 'Thêm voucher' ?></button>
                            <?php if ($editing): ?>
                                <a href="index.php?r=admin_vouchers" class="btn btn-light border">Hủy</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <form class="row g-2 mb-3" method="get" action="index.php">
                        <input type="hidden" name="r" value="admin_vouchers">
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Tìm theo mã voucher, tên hoặc mô tả...">
                        </div>
                        <div class="col-md-3 d-grid">
                            <button type="submit" class="btn btn-outline-primary">Tìm kiếm</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table align-middle table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Mã</th>
                                    <th>Ưu đãi</th>
                                    <th>Điều kiện</th>
                                    <th>Trạng thái</th>
                                    <th class="text-end">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($items)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">Chưa có voucher nào.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($items as $item): ?>
                                        <?php
                                        $valueLabel = ($item['loai_giam'] ?? 'fixed') === 'percent'
                                            ? ((int)($item['gia_tri_giam'] ?? 0)) . '%'
                                            : vnd($item['gia_tri_giam'] ?? 0);
                                        $limitLabel = $item['so_luong'] === null
                                            ? 'Không giới hạn'
                                            : ((int)($item['so_luong_da_dung'] ?? 0)) . '/' . (int)$item['so_luong'];
                                        $badgeClass = (($item['trang_thai'] ?? 'inactive') === 'active') ? 'text-bg-success' : 'text-bg-secondary';
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold text-primary"><?= h($item['ma_code'] ?? '') ?></div>
                                                <div class="small text-muted">#<?= (int)($item['ma_voucher'] ?? 0) ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?= h($item['ten_voucher'] ?? '') ?></div>
                                                <div class="small text-muted">Giảm <?= h($valueLabel) ?><?= !empty($item['giam_toi_da']) ? ' · Tối đa ' . h(vnd($item['giam_toi_da'])) : '' ?></div>
                                                <?php if (!empty($item['mo_ta'])): ?>
                                                    <div class="small text-muted mt-1"><?= h($item['mo_ta']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small text-muted">Đơn tối thiểu: <?= vnd($item['gia_tri_don_toi_thieu'] ?? 0) ?></div>
                                                <div class="small text-muted">Lượt dùng: <?= h($limitLabel) ?></div>
                                                <div class="small text-muted">
                                                    <?= !empty($item['ngay_bat_dau']) ? date('d/m/Y H:i', strtotime((string)$item['ngay_bat_dau'])) : 'Ngay lập tức' ?>
                                                    -
                                                    <?= !empty($item['ngay_ket_thuc']) ? date('d/m/Y H:i', strtotime((string)$item['ngay_ket_thuc'])) : 'Không giới hạn' ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge rounded-pill <?= $badgeClass ?>"><?= (($item['trang_thai'] ?? 'inactive') === 'active') ? 'Đang hoạt động' : 'Tạm khóa' ?></span>
                                            </td>
                                            <td class="text-end">
                                                <a href="index.php?r=admin_vouchers&edit=<?= (int)($item['ma_voucher'] ?? 0) ?>" class="btn btn-sm btn-outline-warning">Sửa</a>
                                                <form method="post" action="index.php?r=admin_voucher_delete" class="d-inline" onsubmit="return confirm('Bạn có chắc muốn xóa voucher này không?');">
                                                    <input type="hidden" name="ma_voucher" value="<?= (int)($item['ma_voucher'] ?? 0) ?>">
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
    </div>
</div>