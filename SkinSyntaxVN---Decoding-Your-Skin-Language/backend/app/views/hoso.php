<?php
$account = $account ?? [];
$orders = $orders ?? [];
$cartItems = $cartItems ?? [];
$skinProfile = $skinProfile ?? [];
$loaiDaOptions = $loaiDaOptions ?? [];
$khachHang = $khachHang ?? [];

$vanDeDaSaved = [];
if (!empty($skinProfile['van_de_da'])) {
  $vanDeDaSaved = array_map('trim', explode(',', (string)$skinProfile['van_de_da']));
}

$ngayThamGia = $account['ngay_tao'] ?? null;
?>

<div class="container mt-4 profile-shell">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <h3 class="section-title mb-2 mb-md-0">Tài khoản của tôi</h3>
    <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/index.php?r=tatca">Tiếp tục mua sắm</a>
  </div>

  <div class="auth-card">
    <ul class="nav nav-tabs" id="accountTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button" role="tab">Tổng quan</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-orders" type="button" role="tab">Lịch sử đơn hàng</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cart" type="button" role="tab">Giỏ hàng của tôi</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-skin" type="button" role="tab">Hồ sơ Làn da</button>
      </li>
    </ul>

    <div class="tab-content pt-3">
      <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">
        <div class="row g-3">
          <div class="col-md-4">
            <div class="profile-stat">
              <div class="label">Tên tài khoản</div>
              <div class="value"><?= h($account['ho_ten'] ?? '') ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="profile-stat">
              <div class="label">Email</div>
              <div class="value"><?= h($account['email'] ?? '') ?></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="profile-stat">
              <div class="label">Ngày tham gia</div>
              <div class="value"><?= h($ngayThamGia ? date('d/m/Y', strtotime((string)$ngayThamGia)) : 'N/A') ?></div>
            </div>
          </div>
        </div>

        <div class="mt-3">
          <div class="row g-3">
            <div class="col-12 col-lg-7">
              <div class="border rounded-3 p-3 bg-white">
                <h6 class="mb-3">Cập nhật thông tin tài khoản</h6>
                <form id="accountInfoForm" class="row g-2">
                  <div class="col-md-6">
                    <label class="form-label">Họ tên</label>
                    <input class="form-control" type="text" name="ho_ten" value="<?= h($khachHang['ho_ten'] ?? ($account['ho_ten'] ?? '')) ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input class="form-control" type="email" value="<?= h($account['email'] ?? '') ?>" disabled>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Giới tính</label>
                    <?php $selectedGender = (string)($khachHang['gioi_tinh'] ?? ''); ?>
                    <select class="form-select" name="gioi_tinh">
                      <option value="">-- Chọn giới tính --</option>
                      <option value="Nữ" <?= ($selectedGender === 'Nữ' ? 'selected' : '') ?>>Nữ</option>
                      <option value="Nam" <?= ($selectedGender === 'Nam' ? 'selected' : '') ?>>Nam</option>
                      <option value="Khác" <?= ($selectedGender === 'Khác' ? 'selected' : '') ?>>Khác</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Năm sinh</label>
                    <input class="form-control" type="number" min="1900" max="<?= date('Y') ?>" name="nam_sinh" value="<?= h($khachHang['nam_sinh'] ?? '') ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Số điện thoại</label>
                    <input class="form-control" type="text" name="so_dien_thoai" value="<?= h($khachHang['so_dien_thoai'] ?? '') ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Địa chỉ</label>
                    <input class="form-control" type="text" name="dia_chi" value="<?= h($khachHang['dia_chi'] ?? '') ?>">
                  </div>
                  <div class="col-12 d-flex align-items-center gap-2">
                    <button class="btn btn-brand" type="submit">Lưu thông tin</button>
                    <span id="accountInfoMsg" class="small"></span>
                  </div>
                </form>
              </div>
            </div>

            <div class="col-12 col-lg-5">
              <div class="border rounded-3 p-3 bg-white">
                <h6 class="mb-3">Đổi mật khẩu</h6>
                <form id="changePasswordForm" class="row g-2">
                  <div class="col-12">
                    <label class="form-label">Mật khẩu hiện tại</label>
                    <input class="form-control" type="password" name="mat_khau_hien_tai" required>
                  </div>
                  <div class="col-12">
                    <label class="form-label">Mật khẩu mới</label>
                    <input class="form-control" type="password" name="mat_khau_moi" required>
                  </div>
                  <div class="col-12">
                    <label class="form-label">Xác nhận mật khẩu mới</label>
                    <input class="form-control" type="password" name="xac_nhan_mat_khau" required>
                  </div>
                  <div class="col-12 d-flex align-items-center gap-2">
                    <button class="btn btn-outline-brand" type="submit">Đổi mật khẩu</button>
                    <span id="changePasswordMsg" class="small"></span>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade" id="tab-orders" role="tabpanel">
        <?php if (empty($orders)): ?>
          <div class="alert alert-info mb-0">Bạn chưa có đơn hàng nào.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Mã đơn</th>
                  <th>Ngày</th>
                  <th>Tổng tiền</th>
                  <th>Trạng thái</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($orders as $o): ?>
                  <tr>
                    <td>#<?= h($o['ma_hoa_don'] ?? '') ?></td>
                    <td><?= h(!empty($o['ngay_dat']) ? date('d/m/Y H:i', strtotime((string)$o['ngay_dat'])) : '') ?></td>
                    <td><?= vnd($o['tong_tien'] ?? 0) ?></td>
                    <td><span class="badge text-bg-secondary"><?= h($o['trang_thai'] ?? 'moi') ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="tab-pane fade" id="tab-cart" role="tabpanel">
        <?php if (empty($cartItems)): ?>
          <div class="alert alert-info">Giỏ hàng hiện trống.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Sản phẩm</th>
                  <th>Số lượng</th>
                  <th>Đơn giá</th>
                  <th>Tạm tính</th>
                </tr>
              </thead>
              <tbody>
                <?php $cartTotal = 0; ?>
                <?php foreach ($cartItems as $it): ?>
                  <?php
                    $gia = (int)($it['gia_ban'] ?? 0);
                    $qty = (int)($it['so_luong'] ?? 0);
                    $lineTotal = $gia * $qty;
                    $cartTotal += $lineTotal;
                  ?>
                  <tr>
                    <td><?= h($it['ten_san_pham'] ?? ('SP #' . ($it['ma_san_pham'] ?? ''))) ?></td>
                    <td><?= $qty ?></td>
                    <td><?= vnd($gia) ?></td>
                    <td><?= vnd($lineTotal) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="3" class="text-end">Tổng</th>
                  <th><?= vnd($cartTotal) ?></th>
                </tr>
              </tfoot>
            </table>
          </div>
          <a class="btn btn-brand" href="<?= BASE_URL ?>/index.php?r=giohang">Checkout</a>
        <?php endif; ?>
      </div>

      <div class="tab-pane fade" id="tab-skin" role="tabpanel">
        <div class="alert alert-light border mb-3">
          Hồ sơ làn da được lấy từ dữ liệu khảo sát lúc đăng ký và lưu trực tiếp trong bảng khách hàng.
        </div>
        <form id="skinProfileForm" class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Loại da của bạn là gì?</label>
            <select name="loai_da" class="form-select" required>
              <option value="">-- Chọn loại da --</option>
              <?php
                $selectedLoaiDa = trim((string)($skinProfile['loai_da'] ?? ''));
                $skinTypes = !empty($loaiDaOptions) ? $loaiDaOptions : ['Dầu', 'Khô', 'Hỗn hợp', 'Nhạy cảm'];
                foreach ($skinTypes as $st):
              ?>
                <option value="<?= h($st) ?>" <?= ($selectedLoaiDa === $st ? 'selected' : '') ?>><?= h($st) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Ngân sách mỹ phẩm trung bình</label>
            <?php $savedBudget = (string)($skinProfile['ngan_sach'] ?? ''); ?>
            <select name="ngan_sach" class="form-select">
              <option value="">-- Chọn ngân sách --</option>
              <option value="200000" <?= ($savedBudget === '200000' ? 'selected' : '') ?>>Dưới 200.000đ</option>
              <option value="500000" <?= ($savedBudget === '500000' ? 'selected' : '') ?>>200.000đ – 500.000đ</option>
              <option value="800000" <?= ($savedBudget === '800000' ? 'selected' : '') ?>>Trên 500.000đ</option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Vấn đề da đang gặp phải?</label>
            <?php $vanDeList = ['Mụn', 'Lão hóa', 'Sạm nám', 'Lỗ chân lông to', 'Da thiếu ẩm']; ?>
            <div class="row g-2">
              <?php foreach ($vanDeList as $vd): ?>
                <div class="col-sm-6 col-lg-4">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="van_de_da[]" value="<?= h($vd) ?>" id="vd_<?= md5($vd) ?>"
                      <?= in_array($vd, $vanDeDaSaved, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="vd_<?= md5($vd) ?>"><?= h($vd) ?></label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="col-12 d-flex align-items-center gap-2 flex-wrap">
            <button type="submit" class="btn btn-brand">Cập nhật hồ sơ</button>
            <span id="skinProfileMsg" class="small"></span>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const form = document.getElementById('skinProfileForm');
  const msgEl = document.getElementById('skinProfileMsg');
  if (!form || !msgEl) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    msgEl.textContent = 'Đang lưu...';
    msgEl.className = 'small text-muted';

    try {
      const formData = new FormData(form);
      const res = await fetch('<?= BASE_URL ?>/index.php?r=capnhathosoda', {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      const data = await res.json();
      if (!res.ok || !data.ok) {
        msgEl.textContent = data.message || 'Có lỗi xảy ra khi lưu hồ sơ.';
        msgEl.className = 'small text-danger';
        return;
      }

      msgEl.textContent = data.message || 'Đã cập nhật hồ sơ thành công.';
      msgEl.className = 'small text-success';
    } catch (err) {
      msgEl.textContent = 'Kết nối thất bại. Vui lòng thử lại.';
      msgEl.className = 'small text-danger';
    }
  });
})();

(function () {
  const form = document.getElementById('accountInfoForm');
  const msgEl = document.getElementById('accountInfoMsg');
  if (!form || !msgEl) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    msgEl.textContent = 'Đang lưu...';
    msgEl.className = 'small text-muted';

    try {
      const res = await fetch('<?= BASE_URL ?>/index.php?r=capnhatthongtin', {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      const data = await res.json();
      if (!res.ok || !data.ok) {
        msgEl.textContent = data.message || 'Không thể cập nhật thông tin.';
        msgEl.className = 'small text-danger';
        return;
      }

      msgEl.textContent = data.message || 'Đã cập nhật thông tin.';
      msgEl.className = 'small text-success';
    } catch (err) {
      msgEl.textContent = 'Kết nối thất bại. Vui lòng thử lại.';
      msgEl.className = 'small text-danger';
    }
  });
})();

(function () {
  const form = document.getElementById('changePasswordForm');
  const msgEl = document.getElementById('changePasswordMsg');
  if (!form || !msgEl) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    msgEl.textContent = 'Đang cập nhật...';
    msgEl.className = 'small text-muted';

    try {
      const res = await fetch('<?= BASE_URL ?>/index.php?r=doimatkhau', {
        method: 'POST',
        body: new FormData(form),
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      const data = await res.json();
      if (!res.ok || !data.ok) {
        msgEl.textContent = data.message || 'Không thể đổi mật khẩu.';
        msgEl.className = 'small text-danger';
        return;
      }

      msgEl.textContent = data.message || 'Đổi mật khẩu thành công.';
      msgEl.className = 'small text-success';
      form.reset();
    } catch (err) {
      msgEl.textContent = 'Kết nối thất bại. Vui lòng thử lại.';
      msgEl.className = 'small text-danger';
    }
  });
})();
</script>
