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
                    <form method="post" action="index.php?r=admin_voucher_save" class="row g-3" id="voucherForm" novalidate>
                        <input type="hidden" name="ma_voucher" value="<?= h($editing['ma_voucher'] ?? '') ?>">

                        <div class="col-12">
                            <label class="form-label">Mã voucher</label>
                            <input type="text" class="form-control" name="ma_code" value="<?= h($editing['ma_code'] ?? '') ?>" placeholder="Ví dụ: SKIN10" maxlength="50" required>
                            <div class="small text-danger mt-1 d-none" data-field-error="ma_code"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Tên voucher</label>
                            <input type="text" class="form-control" name="ten_voucher" value="<?= h($editing['ten_voucher'] ?? '') ?>" placeholder="Ví dụ: Giảm 10% đơn đầu tiên" maxlength="255" required>
                            <div class="small text-danger mt-1 d-none" data-field-error="ten_voucher"></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Mô tả</label>
                            <textarea class="form-control" name="mo_ta" rows="3" placeholder="Mô tả ngắn về điều kiện áp dụng" maxlength="2000"><?= h($editing['mo_ta'] ?? '') ?></textarea>
                            <div class="small text-danger mt-1 d-none" data-field-error="mo_ta"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Loại giảm</label>
                            <select class="form-select" name="loai_giam">
                                <option value="fixed" <?= $isPercent ? '' : 'selected' ?>>Giảm số tiền cố định</option>
                                <option value="percent" <?= $isPercent ? 'selected' : '' ?>>Giảm theo phần trăm</option>
                            </select>
                            <div class="small text-danger mt-1 d-none" data-field-error="loai_giam"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Giá trị giảm</label>
                            <input type="number" min="1" class="form-control" name="gia_tri_giam" value="<?= h($editing['gia_tri_giam'] ?? '') ?>" required>
                            <div class="small text-danger mt-1 d-none" data-field-error="gia_tri_giam"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Đơn tối thiểu</label>
                            <input type="number" min="0" class="form-control" name="gia_tri_don_toi_thieu" value="<?= h($editing['gia_tri_don_toi_thieu'] ?? '0') ?>">
                            <div class="small text-danger mt-1 d-none" data-field-error="gia_tri_don_toi_thieu"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Giảm tối đa</label>
                            <input type="number" min="0" class="form-control" name="giam_toi_da" value="<?= h($editing['giam_toi_da'] ?? '') ?>" placeholder="Bỏ trống nếu không giới hạn">
                            <div class="small text-danger mt-1 d-none" data-field-error="giam_toi_da"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Số lượt dùng</label>
                            <input type="number" min="0" class="form-control" name="so_luong" value="<?= h($editing['so_luong'] ?? '') ?>" placeholder="Bỏ trống nếu không giới hạn" data-used-count="<?= (int)($editing['so_luong_da_dung'] ?? 0) ?>">
                            <div class="small text-danger mt-1 d-none" data-field-error="so_luong"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Trạng thái</label>
                            <select class="form-select" name="trang_thai">
                                <option value="active" <?= (($editing['trang_thai'] ?? 'active') === 'active') ? 'selected' : '' ?>>Đang hoạt động</option>
                                <option value="inactive" <?= (($editing['trang_thai'] ?? '') === 'inactive') ? 'selected' : '' ?>>Tạm khóa</option>
                            </select>
                            <div class="small text-danger mt-1 d-none" data-field-error="trang_thai"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Bắt đầu áp dụng</label>
                            <input type="datetime-local" class="form-control" name="ngay_bat_dau" value="<?= h($startValue) ?>">
                            <div class="small text-danger mt-1 d-none" data-field-error="ngay_bat_dau"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Kết thúc áp dụng</label>
                            <input type="datetime-local" class="form-control" name="ngay_ket_thuc" value="<?= h($endValue) ?>">
                            <div class="small text-danger mt-1 d-none" data-field-error="ngay_ket_thuc"></div>
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
                    <form class="row g-2 mb-3" method="get" action="index.php" data-live-filter="true">
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('voucherForm');
    if (!form) {
        return;
    }

    var field = function (name) {
        return form.querySelector('[name="' + name + '"]');
    };

    var codeInput = field('ma_code');
    var nameInput = field('ten_voucher');
    var typeInput = field('loai_giam');
    var valueInput = field('gia_tri_giam');
    var descriptionInput = field('mo_ta');
    var minOrderInput = field('gia_tri_don_toi_thieu');
    var maxDiscountInput = field('giam_toi_da');
    var quantityInput = field('so_luong');
    var statusInput = field('trang_thai');
    var startInput = field('ngay_bat_dau');
    var endInput = field('ngay_ket_thuc');

    var errorBox = function (name) {
        return form.querySelector('[data-field-error="' + name + '"]');
    };

    var setError = function (name, message) {
        var box = errorBox(name);
        var inputEl = field(name);
        if (!box) {
            return;
        }

        if (message) {
            box.textContent = message;
            box.classList.remove('d-none');
            if (inputEl) {
                inputEl.classList.add('is-invalid');
            }
        } else {
            box.textContent = '';
            box.classList.add('d-none');
            if (inputEl) {
                inputEl.classList.remove('is-invalid');
            }
        }
    };

    var toNumber = function (value) {
        if (value === '' || value === null || value === undefined) {
            return null;
        }
        var num = Number(value);
        return Number.isFinite(num) ? num : NaN;
    };

    var validateCode = function () {
        if (!codeInput) return true;
        codeInput.value = String(codeInput.value || '').toUpperCase().trim();
        if (codeInput.value === '') {
            setError('ma_code', 'Vui lòng nhập mã voucher.');
            return false;
        }
        if (!/^[A-Z0-9_-]{3,50}$/.test(codeInput.value)) {
            setError('ma_code', 'Mã voucher chỉ gồm chữ in hoa, số, dấu gạch ngang hoặc gạch dưới (3-50 ký tự).');
            return false;
        }
        setError('ma_code', '');
        return true;
    };

    var validateName = function () {
        if (!nameInput) return true;
        var name = String(nameInput.value || '').trim();
        if (name === '') {
            setError('ten_voucher', 'Vui lòng nhập tên voucher.');
            return false;
        }
        if (name.length > 255) {
            setError('ten_voucher', 'Tên voucher tối đa 255 ký tự.');
            return false;
        }
        setError('ten_voucher', '');
        return true;
    };

    var validateDescription = function () {
        if (!descriptionInput) return true;
        var description = String(descriptionInput.value || '').trim();
        if (description.length > 2000) {
            setError('mo_ta', 'Mô tả tối đa 2000 ký tự.');
            return false;
        }
        setError('mo_ta', '');
        return true;
    };

    var validateType = function () {
        if (!typeInput) return true;
        if (!['fixed', 'percent'].includes(typeInput.value)) {
            setError('loai_giam', 'Loại giảm giá không hợp lệ.');
            return false;
        }
        setError('loai_giam', '');
        return true;
    };

    var validateValue = function () {
        if (!valueInput) return true;
        var value = toNumber(valueInput.value);
        if (value === null || Number.isNaN(value) || value <= 0) {
            setError('gia_tri_giam', 'Giá trị giảm phải lớn hơn 0.');
            return false;
        }
        if (!Number.isInteger(value)) {
            setError('gia_tri_giam', 'Giá trị giảm phải là số nguyên.');
            return false;
        }
        if (typeInput && typeInput.value === 'percent' && value > 100) {
            setError('gia_tri_giam', 'Voucher theo phần trăm chỉ được từ 1 đến 100.');
            return false;
        }
        setError('gia_tri_giam', '');
        return true;
    };

    var validateMinOrder = function () {
        if (!minOrderInput) return true;
        var value = toNumber(minOrderInput.value);
        if (value !== null && (Number.isNaN(value) || value < 0)) {
            setError('gia_tri_don_toi_thieu', 'Đơn tối thiểu phải từ 0 trở lên.');
            return false;
        }
        if (value !== null && !Number.isInteger(value)) {
            setError('gia_tri_don_toi_thieu', 'Đơn tối thiểu phải là số nguyên.');
            return false;
        }
        setError('gia_tri_don_toi_thieu', '');
        return true;
    };

    var validateMaxDiscount = function () {
        if (!maxDiscountInput) return true;
        var raw = String(maxDiscountInput.value || '').trim();
        if (raw === '') {
            setError('giam_toi_da', '');
            return true;
        }
        var value = toNumber(raw);
        if (Number.isNaN(value) || value < 0) {
            setError('giam_toi_da', 'Giảm tối đa phải từ 0 trở lên hoặc để trống.');
            return false;
        }
        if (!Number.isInteger(value)) {
            setError('giam_toi_da', 'Giảm tối đa phải là số nguyên.');
            return false;
        }
        setError('giam_toi_da', '');
        return true;
    };

    var validateQuantity = function () {
        if (!quantityInput) return true;
        var raw = String(quantityInput.value || '').trim();
        if (raw === '') {
            setError('so_luong', '');
            return true;
        }

        var value = toNumber(raw);
        if (Number.isNaN(value) || value < 0) {
            setError('so_luong', 'Số lượt dùng phải từ 0 trở lên hoặc để trống.');
            return false;
        }

        if (!Number.isInteger(value)) {
            setError('so_luong', 'Số lượt dùng phải là số nguyên.');
            return false;
        }

        var usedCount = Number(quantityInput.getAttribute('data-used-count') || '0');
        if (Number.isFinite(usedCount) && value > 0 && value < usedCount) {
            setError('so_luong', 'Số lượng không được nhỏ hơn số lượt đã dùng (' + usedCount + ').');
            return false;
        }

        setError('so_luong', '');
        return true;
    };

    var validateStatus = function () {
        if (!statusInput) return true;
        if (!['active', 'inactive'].includes(statusInput.value)) {
            setError('trang_thai', 'Trạng thái voucher không hợp lệ.');
            return false;
        }
        setError('trang_thai', '');
        return true;
    };

    var validateDateRange = function () {
        if (!startInput || !endInput) return true;

        setError('ngay_bat_dau', '');
        setError('ngay_ket_thuc', '');

        var startRaw = String(startInput.value || '').trim();
        var endRaw = String(endInput.value || '').trim();
        if (startRaw === '' || endRaw === '') {
            return true;
        }

        var startTs = Date.parse(startRaw);
        var endTs = Date.parse(endRaw);
        if (Number.isNaN(startTs) || Number.isNaN(endTs)) {
            setError('ngay_ket_thuc', 'Định dạng thời gian không hợp lệ.');
            return false;
        }

        if (startTs > endTs) {
            setError('ngay_ket_thuc', 'Thời gian bắt đầu phải sớm hơn hoặc bằng thời gian kết thúc.');
            return false;
        }

        return true;
    };

    var validators = [
        validateCode,
        validateName,
        validateDescription,
        validateType,
        validateValue,
        validateMinOrder,
        validateMaxDiscount,
        validateQuantity,
        validateStatus,
        validateDateRange
    ];

    var validateAll = function () {
        var ok = true;
        validators.forEach(function (fn) {
            if (!fn()) {
                ok = false;
            }
        });
        return ok;
    };

    var bindValidate = function (inputEl, validator, events) {
        if (!inputEl) {
            return;
        }
        (events || ['input', 'blur']).forEach(function (eventName) {
            inputEl.addEventListener(eventName, function () {
                validator();
            });
        });
    };

    bindValidate(codeInput, validateCode, ['input', 'blur', 'change']);
    bindValidate(nameInput, validateName, ['input', 'blur']);
    bindValidate(descriptionInput, validateDescription, ['input', 'blur']);
    bindValidate(valueInput, function () {
        validateValue();
        validateMaxDiscount();
    }, ['input', 'blur']);
    bindValidate(minOrderInput, validateMinOrder, ['input', 'blur']);
    bindValidate(maxDiscountInput, validateMaxDiscount, ['input', 'blur']);
    bindValidate(quantityInput, validateQuantity, ['input', 'blur']);
    bindValidate(startInput, validateDateRange, ['input', 'blur', 'change']);
    bindValidate(endInput, validateDateRange, ['input', 'blur', 'change']);

    [typeInput, statusInput]
        .filter(Boolean)
        .forEach(function (inputEl) {
            inputEl.addEventListener('change', validateAll);
        });

    if (codeInput) {
        codeInput.addEventListener('input', function () {
            var cursor = codeInput.selectionStart;
            var next = String(codeInput.value || '').toUpperCase().replace(/\s+/g, '');
            if (next !== codeInput.value) {
                codeInput.value = next;
                if (typeof codeInput.setSelectionRange === 'function' && cursor !== null) {
                    var fixedPos = Math.min(cursor, next.length);
                    codeInput.setSelectionRange(fixedPos, fixedPos);
                }
            }
        });
    }

    validateAll();

    form.addEventListener('submit', function (event) {
        if (!validateAll()) {
            event.preventDefault();
            var firstError = form.querySelector('[data-field-error]:not(.d-none)');
            if (firstError) {
                var name = firstError.getAttribute('data-field-error');
                var target = field(name);
                if (target && typeof target.focus === 'function') {
                    target.focus();
                }
            }
        }
    });
});
</script>