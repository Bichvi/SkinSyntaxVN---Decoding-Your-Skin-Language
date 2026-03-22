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

$splitProfileValues = static function (?string $raw, array $excludePrefixes = []): array {
  $text = trim((string)($raw ?? ''));
  if ($text === '') {
    return [];
  }

  $parts = preg_split('/\s*[,|]\s*/u', $text) ?: [];
  $values = [];
  foreach ($parts as $part) {
    $value = trim((string)$part);
    if ($value === '') {
      continue;
    }

    $skip = false;
    foreach ($excludePrefixes as $prefix) {
      if (stripos($value, $prefix) === 0) {
        $skip = true;
        break;
      }
    }

    if (!$skip) {
      $values[] = $value;
    }
  }

  return array_values(array_unique($values));
};

$renderTagList = static function (array $items, string $emptyText = 'Chưa có dữ liệu'): string {
  if (empty($items)) {
    return '<span class="text-muted">' . h($emptyText) . '</span>';
  }

  $html = [];
  foreach ($items as $item) {
    $html[] = '<span class="badge rounded-pill text-bg-light border me-2 mb-2 px-3 py-2">' . h((string)$item) . '</span>';
  }

  return implode('', $html);
};

$surveySpecialStates = $splitProfileValues((string)($khachHang['tinh_trang_dac_biet'] ?? ''), ['loaida:']);
$surveyPriority = $splitProfileValues((string)($khachHang['tieu_chi_uu_tien'] ?? ''));
$surveyAvoidIngredients = $splitProfileValues((string)($khachHang['thanh_phan_tranh'] ?? ''));
$surveyExperience = $splitProfileValues((string)($khachHang['kinh_nghiem_skincare'] ?? ''));
$surveyRoutineSteps = $splitProfileValues((string)($khachHang['so_buoc_skincare'] ?? ''));
$surveySkinIssues = !empty($vanDeDaSaved) ? $vanDeDaSaved : $splitProfileValues((string)($khachHang['van_de_da'] ?? ''));
$formattedBudget = !empty($skinProfile['ngan_sach'])
  ? number_format((int)$skinProfile['ngan_sach'], 0, ',', '.') . 'đ'
  : 'Chưa có dữ liệu';

$vipThreshold = 500;
$diamondThreshold = 1500;
$currentPoints = max(0, (int)($khachHang['diemtl'] ?? 0));
$currentTier = trim((string)($khachHang['loaikh'] ?? 'Thuong')) ?: 'Thuong';
$tierLabelMap = [
  'thuong' => 'Thường',
  'vip' => 'VIP',
  'kim cuong' => 'Kim Cương',
  'kim cương' => 'Kim Cương',
];
$normalizedTierKey = function_exists('mb_strtolower') ? mb_strtolower($currentTier, 'UTF-8') : strtolower($currentTier);
$displayTier = $tierLabelMap[$normalizedTierKey] ?? $currentTier;
$pointsToVip = max(0, $vipThreshold - $currentPoints);
$pointsToDiamond = max(0, $diamondThreshold - $currentPoints);
$loyaltyHint = 'Bạn đã ở hạng Thường.';
$loyaltyProgressPercent = 0;
$loyaltyProgressLabel = '';
$loyaltyProgressHint = '';
$nextTierLabel = '';

if ($currentPoints >= $diamondThreshold) {
  $loyaltyHint = 'Bạn đang ở hạng Kim Cương, đây là hạng thành viên cao nhất hiện tại.';
  $loyaltyProgressPercent = 100;
  $loyaltyProgressLabel = number_format($diamondThreshold, 0, ',', '.') . ' / ' . number_format($diamondThreshold, 0, ',', '.') . ' điểm';
  $loyaltyProgressHint = 'Đã mở khóa toàn bộ quyền lợi hạng thành viên hiện tại.';
} elseif ($currentPoints >= $vipThreshold) {
  $loyaltyHint = 'Bạn đang ở hạng VIP. Cần thêm ' . number_format($pointsToDiamond, 0, ',', '.') . ' điểm để lên Kim Cương.';
  $loyaltyProgressPercent = (($currentPoints - $vipThreshold) / max(1, $diamondThreshold - $vipThreshold)) * 100;
  $loyaltyProgressLabel = number_format($currentPoints, 0, ',', '.') . ' / ' . number_format($diamondThreshold, 0, ',', '.') . ' điểm';
  $loyaltyProgressHint = 'Còn ' . number_format($pointsToDiamond, 0, ',', '.') . ' điểm để lên Kim Cương.';
  $nextTierLabel = 'Kim Cương';
} else {
  $loyaltyHint = 'Cần thêm ' . number_format($pointsToVip, 0, ',', '.') . ' điểm để lên VIP và ' . number_format($pointsToDiamond, 0, ',', '.') . ' điểm để lên Kim Cương.';
  $loyaltyProgressPercent = ($currentPoints / max(1, $vipThreshold)) * 100;
  $loyaltyProgressLabel = number_format($currentPoints, 0, ',', '.') . ' / ' . number_format($vipThreshold, 0, ',', '.') . ' điểm';
  $loyaltyProgressHint = 'Còn ' . number_format($pointsToVip, 0, ',', '.') . ' điểm để lên VIP.';
  $nextTierLabel = 'VIP';
}

$loyaltyProgressPercent = max(0, min(100, (float)$loyaltyProgressPercent));

$ngayThamGia = $account['ngay_tao'] ?? null;
$membershipDurationHint = 'Mốc thời gian hệ thống bắt đầu ghi nhận tài khoản của bạn.';
if (!empty($ngayThamGia)) {
  $joinedTimestamp = strtotime((string)$ngayThamGia);
  if ($joinedTimestamp !== false) {
    $daysSinceJoined = max(0, (int)floor((time() - $joinedTimestamp) / 86400));
    if ($daysSinceJoined === 0) {
      $membershipDurationHint = 'Bạn vừa tham gia SkinSyntax hôm nay.';
    } elseif ($daysSinceJoined === 1) {
      $membershipDurationHint = 'Bạn đã đồng hành cùng SkinSyntax được 1 ngày.';
    } else {
      $membershipDurationHint = 'Bạn đã đồng hành cùng SkinSyntax được ' . number_format($daysSinceJoined, 0, ',', '.') . ' ngày.';
    }
  }
}

$orderCount = count($orders);
$reviewSentCount = 0;
foreach ($orders as $orderRow) {
  foreach (($orderRow['items'] ?? []) as $orderItem) {
    if (!empty($orderItem['has_reviewed'])) {
      $reviewSentCount++;
    }
  }
}

$accountVerificationLabel = !empty($account['email']) ? 'Đã xác thực' : 'Chưa xác thực';
$accountVerificationHint = !empty($account['email'])
  ? 'Email đăng nhập đã được ghi nhận trong hệ thống.'
  : 'Tài khoản chưa có đủ thông tin xác thực email.';
?>

<style>
  .loyalty-progress-card {
    margin-top: 14px;
    padding: 16px;
    border-radius: 22px;
    background: linear-gradient(180deg, #f7fbff 0%, #ffffff 100%);
    border: 1px solid #d8e8f6;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
  }

  .loyalty-progress-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 8px;
  }

  .loyalty-progress-title {
    font-size: 0.82rem;
    font-weight: 700;
    color: #36506c;
    letter-spacing: 0.01em;
  }

  .loyalty-progress-target {
    font-size: 0.8rem;
    font-weight: 700;
    color: #1f7a53;
    background: #eef8f1;
    border-radius: 999px;
    padding: 7px 12px;
  }

  .loyalty-tier-row {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 4px;
  }

  .profile-overview-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 18px;
    align-items: stretch;
  }

  .profile-overview-grid__wide {
    grid-column: span 2;
  }

  .profile-overview-grid__compact {
    grid-column: span 1;
  }

  .profile-overview-grid .profile-stat {
    height: 100%;
    min-height: 100%;
    padding: 16px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
  }

  .profile-overview-grid > div {
    min-width: 0;
    display: flex;
  }

  .profile-overview-grid .profile-stat .value {
    word-break: break-word;
  }

  .profile-stat-stack {
    display: grid;
    gap: 10px;
  }

  .profile-stat-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: fit-content;
    min-height: 34px;
    padding: 0 12px;
    border-radius: 999px;
    background: #eef8f1;
    color: #1d6b46;
    border: 1px solid #cae7d5;
    font-size: 0.82rem;
    font-weight: 800;
  }

  .profile-stat-note {
    color: #64748b;
    font-size: 0.84rem;
    line-height: 1.55;
  }

  .loyalty-tier-name {
    font-size: 1.8rem;
    line-height: 1;
    font-weight: 800;
    color: #10273d;
  }

  .loyalty-progress-bar {
    position: relative;
    overflow: hidden;
    height: 12px;
    border-radius: 999px;
    background: #dfeaf6;
    margin-top: 10px;
  }

  .loyalty-progress-fill {
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #3cc59d 0%, #65b1ff 100%);
  }

  .loyalty-progress-scale {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    margin-top: 8px;
    color: #7a8ea5;
    font-size: 0.76rem;
    font-weight: 600;
  }

  .loyalty-progress-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 12px;
    flex-wrap: wrap;
  }

  .loyalty-progress-points {
    font-size: 1rem;
    font-weight: 800;
    color: #16324d;
  }

  .loyalty-progress-note {
    color: #64748b;
    font-size: 0.84rem;
    line-height: 1.5;
  }

  .order-product-list {
    display: grid;
    gap: 12px;
  }

  .order-product-card {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    padding: 12px 14px;
    border: 1px solid #e5ecef;
    border-radius: 18px;
    background: #fff;
  }

  .order-product-card[data-detail-url] {
    cursor: pointer;
  }

  .order-product-main {
    display: grid;
    grid-template-columns: 56px minmax(0, 1fr);
    gap: 14px;
    align-items: center;
    min-width: 0;
  }

  .order-product-thumb {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    object-fit: cover;
    background: #f4f7f5;
  }

  .order-product-name {
    font-weight: 700;
    color: #16324d;
  }

  .order-product-link {
    display: block;
    color: inherit;
    text-decoration: none;
  }

  .order-product-link:hover {
    color: inherit;
  }

  .order-product-link:hover .order-product-name {
    color: #1f7a53;
  }

  .order-product-meta {
    color: #6b7280;
    font-size: 0.92rem;
    margin-top: 2px;
  }

  .order-product-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
    align-items: center;
  }

  .order-product-actions form {
    margin: 0;
  }

  .order-detail-toggle {
    white-space: nowrap;
  }

  .order-detail-panel {
    border-top: 1px solid #e5ecef;
  }

  .order-status-note {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    border-radius: 999px;
    background: #f6faf7;
    color: #236142;
    font-size: 0.84rem;
    font-weight: 700;
  }

  @media (max-width: 1199.98px) {
    .profile-overview-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .profile-overview-grid__wide,
    .profile-overview-grid__compact {
      grid-column: span 1;
    }
  }

  @media (max-width: 767.98px) {
    .profile-overview-grid {
      grid-template-columns: 1fr;
      gap: 12px;
    }

    .profile-overview-grid .profile-stat {
      min-height: 0;
    }

    .loyalty-progress-card {
      padding: 12px 14px;
    }

    .order-product-card {
      grid-template-columns: 1fr;
    }

    .order-product-actions {
      justify-content: flex-start;
    }
  }
</style>

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
      <li class="nav-item" role="presentation">
        <button class="nav-link" type="button" data-support-chat-toggle>Chat hỗ trợ</button>
      </li>
    </ul>

    <div class="tab-content pt-3">
      <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">
        <div class="profile-overview-grid">
          <div class="profile-overview-grid__wide">
            <div class="profile-stat">
              <div class="label">Loại KH</div>
              <div class="loyalty-tier-row">
                <div class="loyalty-tier-name"><?= h($displayTier) ?></div>
              </div>
              <div class="small text-muted mt-2"><?= h($loyaltyHint) ?></div>
              <div class="loyalty-progress-card">
                <div class="loyalty-progress-head">
                  <div class="loyalty-progress-title">Tiến độ hạng thành viên</div>
                  <?php if ($nextTierLabel !== ''): ?>
                    <div class="loyalty-progress-target">Mục tiêu: <?= h($nextTierLabel) ?></div>
                  <?php else: ?>
                    <div class="loyalty-progress-target">Đã đạt tối đa</div>
                  <?php endif; ?>
                </div>
                <div class="loyalty-progress-bar" aria-hidden="true">
                  <div class="loyalty-progress-fill" style="width: <?= h(number_format($loyaltyProgressPercent, 2, '.', '')) ?>%;"></div>
                </div>
                <div class="loyalty-progress-scale">
                  <span>0</span>
                  <span><?= number_format($vipThreshold, 0, ',', '.') ?></span>
                  <span><?= number_format($diamondThreshold, 0, ',', '.') ?></span>
                </div>
                <div class="loyalty-progress-meta">
                  <div class="loyalty-progress-points"><?= h($loyaltyProgressLabel) ?></div>
                  <div class="loyalty-progress-note"><?= h($loyaltyProgressHint) ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="profile-overview-grid__wide">
            <div class="profile-stat">
              <div class="label">Ngày tham gia</div>
              <div class="profile-stat-stack">
                <div class="value"><?= h($ngayThamGia ? date('d/m/Y', strtotime((string)$ngayThamGia)) : 'N/A') ?></div>
                <div class="profile-stat-badge">Tài khoản <?= h($accountVerificationLabel) ?></div>
              </div>
              <div class="profile-stat-note mt-2"><?= h($membershipDurationHint) ?></div>
              <div class="profile-stat-note"><?= h($accountVerificationHint) ?></div>
            </div>
          </div>
          <div class="profile-overview-grid__compact">
            <div class="profile-stat">
              <div class="label">Số đơn hàng</div>
              <div class="value"><?= number_format($orderCount, 0, ',', '.') ?></div>
              <div class="profile-stat-note mt-2">Tổng số đơn hàng đã được ghi nhận trên tài khoản của bạn.</div>
            </div>
          </div>
          <div class="profile-overview-grid__compact">
            <div class="profile-stat">
              <div class="label">Đánh giá đã gửi</div>
              <div class="value"><?= number_format($reviewSentCount, 0, ',', '.') ?></div>
              <div class="profile-stat-note mt-2">Số lượt đánh giá sản phẩm bạn đã gửi thành công từ lịch sử mua hàng.</div>
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
                    <input class="form-control" type="password" name="mat_khau_hien_tai" placeholder="Có thể bỏ trống khi đã đăng nhập">
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
                  <th>Thanh toán</th>
                  <th>Điểm</th>
                  <th>Chi tiết</th>
                  <th class="text-end">Tác vụ</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($orders as $o): ?>
                  <?php $orderStatus = strtolower(trim((string)($o['trang_thai'] ?? ''))); ?>
                  <?php $isCancelledOrder = in_array($orderStatus, ['da huy', 'đã hủy', 'huy', 'cancelled', 'canceled'], true); ?>
                  <?php $detailCollapseId = 'order-details-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($o['ma_hoa_don'] ?? '0')); ?>
                  <tr>
                    <td>#<?= h($o['ma_hoa_don'] ?? '') ?></td>
                    <td><?= h(!empty($o['ngay_dat']) ? date('d/m/Y H:i', strtotime((string)$o['ngay_dat'])) : '') ?></td>
                    <td><?= vnd($o['tong_tien'] ?? 0) ?></td>
                    <td><span class="badge text-bg-secondary"><?= h($o['trang_thai'] ?? 'moi') ?></span></td>
                    <td>
                      <div class="small fw-semibold"><?= strtolower(trim((string)($o['hinh_thuc_thanh_toan'] ?? 'cod'))) === 'bank_transfer_qr' ? 'QR chuyển khoản' : 'COD' ?></div>
                      <div class="small text-muted"><?= h($o['status_thanh_toan'] ?? 'Chua thanh toan') ?></div>
                    </td>
                    <td>
                      <?php if ($isCancelledOrder): ?>
                        <span class="badge text-bg-danger">Đơn đã hủy</span>
                      <?php elseif (!empty($o['da_tich_diem'])): ?>
                        <span class="badge text-bg-success">+<?= number_format((int)($o['diem_cong'] ?? 0), 0, ',', '.') ?></span>
                      <?php else: ?>
                        <span class="text-muted small">Chờ hoàn thành</span>
                      <?php endif; ?>
                      <?php if ($isCancelledOrder && !empty($o['ly_do_huy'])): ?>
                        <div class="small text-muted mt-1">Lý do: <?= h((string)($o['ly_do_huy'] ?? '')) ?></div>
                      <?php endif; ?>
                      <?php if ((int)($o['diem_su_dung'] ?? 0) > 0): ?>
                        <div class="small text-muted mt-1">Đã dùng <?= number_format((int)($o['diem_su_dung'] ?? 0), 0, ',', '.') ?> điểm</div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <button
                        class="btn btn-sm btn-outline-secondary order-detail-toggle"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#<?= h($detailCollapseId) ?>"
                        aria-expanded="false"
                        aria-controls="<?= h($detailCollapseId) ?>"
                      >
                        Xem chi tiết
                      </button>
                    </td>
                    <td class="text-end">
                      <?php if (!in_array($orderStatus, ['dang giao', 'đang giao', 'hoan thanh', 'hoàn thành'], true) && !$isCancelledOrder): ?>
                        <form method="post" action="<?= BASE_URL ?>/index.php?r=huydonhang" class="d-inline-flex flex-column gap-2" onsubmit="return confirm('Bạn chắc chắn muốn hủy đơn hàng này?');">
                          <input type="hidden" name="ma_hoa_don" value="<?= h($o['ma_hoa_don'] ?? '') ?>">
                          <select class="form-select form-select-sm" name="ly_do_huy" required>
                            <option value="">Chọn lý do hủy đơn</option>
                            <?php foreach (($cancelReasonOptions ?? []) as $value => $label): ?>
                              <option value="<?= h((string)$value) ?>"><?= h((string)$label) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <textarea class="form-control form-control-sm" name="ly_do_huy_bo_sung" rows="2" placeholder="Ghi chú thêm cho lý do hủy (nếu có)"></textarea>
                          <button type="submit" class="btn btn-sm btn-outline-danger">Hủy đơn</button>
                        </form>
                      <?php else: ?>
                        <span class="text-muted small">Không khả dụng</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <tr>
                    <td colspan="8" class="p-0 border-0">
                      <div id="<?= h($detailCollapseId) ?>" class="collapse order-detail-panel">
                        <div class="bg-light-subtle px-3 py-3">
                          <?php if (empty($o['items'])): ?>
                            <div class="small text-muted py-2">Đơn hàng này chưa có chi tiết sản phẩm hiển thị.</div>
                          <?php else: ?>
                            <div class="order-product-list">
                              <?php foreach ($o['items'] as $item): ?>
                                <?php $canReviewFromOrder = !empty($item['has_purchased']) || in_array($orderStatus, ['hoan thanh', 'hoàn thành', 'da giao', 'đã giao'], true); ?>
                                <?php $productId = trim((string)($item['ma_san_pham'] ?? '')); ?>
                                <?php $resolvedDetailUrl = $productId !== '' ? (BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($productId) . '&tab=danh-gia') : ''; ?>
                                <?php $productImage = trim((string)($item['image_url'] ?? '')); ?>
                                <?php if ($productImage === ''): ?>
                                  <?php $productImage = resolve_image_url((string)($item['link_hinh_anh'] ?? '')); ?>
                                <?php endif; ?>
                                <div class="order-product-card"<?= $resolvedDetailUrl !== '' ? ' data-detail-url="' . h($resolvedDetailUrl) . '"' : '' ?>>
                                  <a class="order-product-link order-product-main" href="<?= h($resolvedDetailUrl !== '' ? $resolvedDetailUrl : '#') ?>">
                                    <img class="order-product-thumb" src="<?= h($productImage !== '' ? $productImage : 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2256%22 height=%2256%22 viewBox=%220 0 56 56%22%3E%3Crect width=%2256%22 height=%2256%22 rx=%2214%22 fill=%22%23edf4ef%22/%3E%3Cpath d=%22M16 37l8-9 6 7 5-5 6 7H16z%22 fill=%22%2396aca0%22/%3E%3Ccircle cx=%2222%22 cy=%2221%22 r=%224%22 fill=%22%23bfd0c5%22/%3E%3C/svg%3E') ?>" referrerpolicy="no-referrer" crossorigin="anonymous" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2256%22 height=%2256%22 viewBox=%220 0 56 56%22%3E%3Crect width=%2256%22 height=%2256%22 rx=%2214%22 fill=%22%23edf4ef%22/%3E%3Cpath d=%22M16 37l8-9 6 7 5-5 6 7H16z%22 fill=%22%2396aca0%22/%3E%3Ccircle cx=%2222%22 cy=%2221%22 r=%224%22 fill=%22%23bfd0c5%22/%3E%3C/svg%3E';" alt="<?= h((string)($item['ten_san_pham'] ?? 'Sản phẩm')) ?>">
                                    <div class="min-w-0">
                                      <div class="order-product-name"><?= h((string)($item['ten_san_pham'] ?? ('SP #' . ($item['ma_san_pham'] ?? '')))) ?></div>
                                      <div class="order-product-meta">
                                        <?= h((string)($item['thuong_hieu'] ?? '')) ?>
                                        <?php if (!empty($item['thuong_hieu']) && !empty($item['so_luong'])): ?>•<?php endif; ?>
                                        SL: <?= number_format((int)($item['so_luong'] ?? 0), 0, ',', '.') ?>
                                        <?php if ((int)($item['don_gia'] ?? 0) > 0): ?>• <?= vnd($item['don_gia'] ?? 0) ?><?php endif; ?>
                                      </div>
                                    </div>
                                  </a>
                                  <div class="order-product-actions">
                                    <?php if (!empty($item['has_reviewed'])): ?>
                                      <?php if ($resolvedDetailUrl !== ''): ?>
                                        <form method="get" action="<?= BASE_URL ?>/index.php">
                                          <input type="hidden" name="r" value="chitiet">
                                          <input type="hidden" name="id" value="<?= h($productId) ?>">
                                          <input type="hidden" name="tab" value="danh-gia">
                                          <button type="submit" class="btn btn-sm btn-outline-secondary">Xem lại phần đánh giá</button>
                                        </form>
                                      <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Xem lại phần đánh giá</button>
                                      <?php endif; ?>
                                      <span class="order-status-note">Đã đánh giá và nhận ưu đãi</span>
                                    <?php elseif ($canReviewFromOrder): ?>
                                      <?php if ($resolvedDetailUrl !== ''): ?>
                                        <form method="get" action="<?= BASE_URL ?>/index.php">
                                          <input type="hidden" name="r" value="chitiet">
                                          <input type="hidden" name="id" value="<?= h($productId) ?>">
                                          <input type="hidden" name="tab" value="danh-gia">
                                          <button type="submit" class="btn btn-sm btn-outline-brand">Đánh giá ngay để nhận ưu đãi</button>
                                        </form>
                                      <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Đánh giá ngay để nhận ưu đãi</button>
                                      <?php endif; ?>
                                      <span class="order-status-note">Đủ điều kiện nhận thêm 1 điểm</span>
                                    <?php else: ?>
                                      <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Đánh giá ngay để nhận ưu đãi</button>
                                      <span class="order-status-note">Chờ đơn được xác nhận trước khi đánh giá</span>
                                    <?php endif; ?>
                                  </div>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
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

        <div class="row g-3 mb-4">
          <div class="col-md-6 col-xl-3">
            <div class="profile-stat h-100">
              <div class="label">Loại da</div>
              <div class="value"><?= h($skinProfile['loai_da'] ?? 'Chưa có dữ liệu') ?></div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="profile-stat h-100">
              <div class="label">Mức độ nhạy cảm</div>
              <div class="value"><?= h($khachHang['muc_do_nhay_cam'] ?? 'Chưa có dữ liệu') ?></div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="profile-stat h-100">
              <div class="label">Mục tiêu chăm sóc</div>
              <div class="value"><?= h($khachHang['muc_tieu_cham_soc'] ?? 'Chưa có dữ liệu') ?></div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="profile-stat h-100">
              <div class="label">Ngân sách trung bình</div>
              <div class="value"><?= h($formattedBudget) ?></div>
            </div>
          </div>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-lg-6">
            <div class="border rounded-3 p-3 bg-white h-100">
              <h6 class="mb-3">Vấn đề da và tình trạng hiện tại</h6>
              <div class="mb-3">
                <div class="small text-muted mb-2">Vấn đề da đang gặp phải</div>
                <?= $renderTagList($surveySkinIssues) ?>
              </div>
              <div>
                <div class="small text-muted mb-2">Tình trạng đặc biệt</div>
                <?= $renderTagList($surveySpecialStates, 'Chưa ghi nhận tình trạng đặc biệt') ?>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="border rounded-3 p-3 bg-white h-100">
              <h6 class="mb-3">Ưu tiên khi chọn sản phẩm</h6>
              <div class="mb-3">
                <div class="small text-muted mb-2">Tiêu chí ưu tiên</div>
                <?= $renderTagList($surveyPriority, 'Chưa có tiêu chí ưu tiên') ?>
              </div>
              <div>
                <div class="small text-muted mb-2">Thành phần muốn tránh</div>
                <?= $renderTagList($surveyAvoidIngredients, 'Không có / Không quan tâm') ?>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="border rounded-3 p-3 bg-white h-100">
              <h6 class="mb-3">Thói quen skincare</h6>
              <div class="mb-3">
                <div class="small text-muted mb-2">Kinh nghiệm skincare</div>
                <?= $renderTagList($surveyExperience, 'Chưa có dữ liệu kinh nghiệm') ?>
              </div>
              <div>
                <div class="small text-muted mb-2">Số bước skincare thường dùng</div>
                <?= $renderTagList($surveyRoutineSteps, 'Chưa có dữ liệu số bước') ?>
              </div>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="border rounded-3 p-3 bg-white h-100">
              <h6 class="mb-3">Thông tin khảo sát đã lưu</h6>
              <div class="row g-3">
                <div class="col-sm-6">
                  <div class="small text-muted">Giới tính</div>
                  <div class="fw-semibold"><?= h($khachHang['gioi_tinh'] ?? 'Chưa có dữ liệu') ?></div>
                </div>
                <div class="col-sm-6">
                  <div class="small text-muted">Năm sinh</div>
                  <div class="fw-semibold"><?= h($khachHang['nam_sinh'] ?? 'Chưa có dữ liệu') ?></div>
                </div>
              </div>
            </div>
          </div>
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
