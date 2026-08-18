<?php
$items = $items ?? [];
$receiver = $receiver ?? [];
$subtotal = (int)($subtotal ?? 0);
$shippingFee = (int)($shippingFee ?? 30000);
$discountAmount = (int)($discountAmount ?? 0);
$grandTotal = (int)($grandTotal ?? ($subtotal + $shippingFee));
$appliedVoucher = $appliedVoucher ?? null;
$voucherDiscountAmount = (int)($voucherDiscountAmount ?? $discountAmount ?? 0);
$pointRedemption = $pointRedemption ?? ['points' => 0, 'discount' => 0, 'available_points' => 0, 'point_value_vnd' => 1000];
$voucherCode = trim((string)($appliedVoucher['voucher']['ma_code'] ?? ''));
$selectedPaymentMethod = trim((string)($selectedPaymentMethod ?? 'cod'));
$qrTransfer = $qrTransfer ?? ['enabled' => false];
$transferPreview = $transferPreview ?? null;
$pointsToUse = max(0, (int)($pointRedemption['points'] ?? 0));
$pointsDiscountAmount = max(0, (int)($pointRedemption['discount'] ?? 0));
$availablePoints = max(0, (int)($pointRedemption['available_points'] ?? 0));
$pointValueVnd = max(1, (int)($pointRedemption['point_value_vnd'] ?? 1000));
$maxPointsByOrder = (int)floor(max(0, $subtotal - $voucherDiscountAmount) / $pointValueVnd);
$maxUsablePoints = min($availablePoints, $maxPointsByOrder);
$defaultReceiver = $defaultReceiver ?? [];
$newReceiver = $newReceiver ?? [];
$selectedReceiver = $selectedReceiver ?? $receiver;
$addressChoice = trim((string)($addressChoice ?? 'default'));
$hasDefaultReceiver = !empty($hasDefaultReceiver);
?>

<div class="container checkout-page py-4 py-lg-5">
  <div class="checkout-head mb-3 d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
    <div>
      <h2 class="mb-1">Thanh toán</h2>
      <div class="text-muted">Xác nhận thông tin nhận hàng, rà soát đơn và đặt mua trong một bước.</div>
    </div>
    <div class="checkout-head__actions d-flex flex-wrap gap-2">
      <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/index.php?r=giohang">Quay lại giỏ hàng</a>
      <button class="btn btn-brand" type="button" data-support-chat-toggle>
        <i class="fa-regular fa-comments me-2"></i>Chat hỗ trợ
      </button>
    </div>
  </div>

  <?php if (in_array(current_role(), ['admin', 'nhanvien'], true)): ?>
    <div class="alert alert-warning border-0 rounded-4 p-3 mb-4 shadow-sm" style="background: #FEF3C7; color: #92400E;">
      <div class="d-flex align-items-center gap-3">
        <i class="fa-solid fa-shield-halved fs-4 text-warning"></i>
        <div>
          <h6 class="fw-bold mb-1">Tài khoản Quản trị / Nhân viên (Admin Mode)</h6>
          <div class="small opacity-90">Tài khoản Admin/Staff bị khóa chức năng đặt hàng và áp dụng Voucher để tránh xung đột lợi ích &amp; đảm bảo tính minh bạch hệ thống. Vui lòng đăng nhập tài khoản Khách hàng để thực hiện mua sắm.</div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="address-card mb-3">
    <div class="stripe-top"></div>
    <div class="address-content">
      <div class="address-head">
        <div>
          <div class="address-title"><i class="fa-solid fa-location-dot me-2"></i>Địa chỉ nhận hàng</div>
          <div class="address-subtitle">Chọn địa chỉ mặc định trong hồ sơ hoặc tạo một địa chỉ giao hàng chi tiết cho đơn này.</div>
        </div>
        <a class="btn btn-outline-brand btn-sm" href="<?= BASE_URL ?>/index.php?r=hoso">Quản lý hồ sơ</a>
      </div>

      <form method="post" action="<?= BASE_URL ?>/index.php?r=xulydathang" id="checkoutForm" class="row g-3 mt-1">
        <div class="col-12">
          <div class="address-selector-grid">
            <label class="address-option-card <?= $addressChoice === 'default' ? 'is-active' : '' ?> <?= $hasDefaultReceiver ? '' : 'is-disabled' ?>" data-address-choice-card="default">
              <input type="radio" name="address_choice" value="default" <?= $addressChoice === 'default' ? 'checked' : '' ?> <?= $hasDefaultReceiver ? '' : 'disabled' ?>>
              <div class="address-option-card__top">
                <div>
                  <div class="address-option-card__title">Địa chỉ mặc định</div>
                  <div class="address-option-card__meta">Lấy từ thông tin tài khoản của bạn</div>
                </div>
                <span class="address-option-badge">Mặc định</span>
              </div>
              <?php if ($hasDefaultReceiver): ?>
                <div class="address-option-card__contact"><?= h($defaultReceiver['ten_nguoi_nhan'] ?? '') ?> <span>•</span> <?= h($defaultReceiver['sdt_nguoi_nhan'] ?? '') ?></div>
                <div class="address-option-card__text"><?= h($defaultReceiver['dia_chi_giao_hang'] ?? '') ?></div>
              <?php else: ?>
                <div class="address-option-card__empty">Hồ sơ của bạn chưa có đủ họ tên, số điện thoại và địa chỉ để dùng làm địa chỉ mặc định.</div>
              <?php endif; ?>
            </label>

            <label class="address-option-card <?= $addressChoice === 'new' ? 'is-active' : '' ?>" data-address-choice-card="new">
              <input type="radio" name="address_choice" value="new" <?= $addressChoice === 'new' ? 'checked' : '' ?>>
              <div class="address-option-card__top">
                <div>
                  <div class="address-option-card__title">Địa chỉ mới</div>
                  <div class="address-option-card__meta">Tạo địa chỉ giao hàng riêng cho đơn này</div>
                </div>
                <span class="address-option-badge address-option-badge--soft">Linh hoạt</span>
              </div>
              <div class="address-option-card__text">Phù hợp khi giao tới văn phòng, ký túc xá, địa chỉ người nhận khác hoặc cần ghi chú giao hàng chi tiết.</div>
            </label>
          </div>

          <div class="address-detail-panel <?= $addressChoice === 'default' ? '' : 'd-none' ?>" data-address-panel="default">
            <div class="address-summary-card">
              <div class="address-summary-card__icon"><i class="fa-solid fa-house-circle-check"></i></div>
              <div>
                <div class="address-summary-card__title">Đơn hàng sẽ được giao tới địa chỉ mặc định</div>
                <?php if ($hasDefaultReceiver): ?>
                  <div class="address-summary-card__contact"><?= h($defaultReceiver['ten_nguoi_nhan'] ?? '') ?> <span>•</span> <?= h($defaultReceiver['sdt_nguoi_nhan'] ?? '') ?></div>
                  <div class="address-summary-card__text"><?= h($defaultReceiver['dia_chi_giao_hang'] ?? '') ?></div>
                <?php else: ?>
                  <div class="address-summary-card__text">Vui lòng chọn địa chỉ mới hoặc cập nhật hồ sơ trước khi đặt hàng.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="address-detail-panel <?= $addressChoice === 'new' ? '' : 'd-none' ?>" data-address-panel="new">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Tên người nhận</label>
                <input class="form-control" name="ten_nguoi_nhan" value="<?= h($newReceiver['ten_nguoi_nhan'] ?? '') ?>" data-address-required="new">
              </div>
              <div class="col-md-6">
                <label class="form-label">Số điện thoại</label>
                <input class="form-control" name="sdt_nguoi_nhan" value="<?= h($newReceiver['sdt_nguoi_nhan'] ?? '') ?>" data-address-required="new">
              </div>
              <div class="col-md-6">
                <label class="form-label">Tỉnh / Thành phố</label>
                <input class="form-control" name="tinh_thanh" value="<?= h($newReceiver['tinh_thanh'] ?? '') ?>" placeholder="Ví dụ: TPHCM, Tây Ninh" list="checkoutProvinceList" autocomplete="off" data-address-required="new" data-address-preview-input="city" data-address-field="province">
                <datalist id="checkoutProvinceList"></datalist>
              </div>
              <div class="col-md-6">
                <label class="form-label">Quận / Huyện</label>
                <input class="form-control" name="quan_huyen" value="<?= h($newReceiver['quan_huyen'] ?? '') ?>" placeholder="Ví dụ: Gò Vấp, Tân Biên" list="checkoutDistrictList" autocomplete="off" data-address-required="new" data-address-preview-input="district" data-address-field="district">
                <datalist id="checkoutDistrictList"></datalist>
              </div>
              <div class="col-md-6">
                <label class="form-label">Phường / Xã</label>
                <input class="form-control" name="phuong_xa" value="<?= h($newReceiver['phuong_xa'] ?? '') ?>" placeholder="Ví dụ: Phường 10, Xã Trường Tây" list="checkoutWardList" autocomplete="off" data-address-required="new" data-address-preview-input="ward" data-address-field="ward">
                <datalist id="checkoutWardList"></datalist>
              </div>
              <div class="col-md-6">
                <label class="form-label">Số nhà, tên đường, tòa nhà</label>
                <input class="form-control" name="dia_chi_chi_tiet" value="<?= h($newReceiver['dia_chi_chi_tiet'] ?? '') ?>" placeholder="Ví dụ: 123 Nguyễn Oanh, Chung cư A1" data-address-required="new" data-address-preview-input="detail">
              </div>
              <div class="col-12">
                <label class="form-label">Ghi chú giao hàng</label>
                <textarea class="form-control" name="ghi_chu_giao_hang" rows="2" placeholder="Ví dụ: Gọi trước khi giao, giao giờ hành chính..." data-address-preview-input="note"><?= h($newReceiver['ghi_chu_giao_hang'] ?? '') ?></textarea>
              </div>
              <div class="col-12">
                <label class="address-save-toggle">
                  <input type="checkbox" name="save_as_default" value="1" <?= !empty($newReceiver['save_as_default']) ? 'checked' : '' ?>>
                  <span>Lưu địa chỉ này làm mặc định cho lần mua sau</span>
                </label>
              </div>
              <div class="col-12">
                <div class="address-autocomplete-note" data-address-feedback>
                  Nhập để tìm nhanh tỉnh, quận huyện và phường xã theo dữ liệu hành chính Việt Nam.
                </div>
              </div>
            </div>

            <div class="address-preview-card mt-3">
              <div class="address-preview-card__label">Địa chỉ đầy đủ</div>
              <div class="address-preview-card__value" data-new-address-preview>
                <?= h($newReceiver['dia_chi_giao_hang'] ?? 'Thông tin đầy đủ sẽ hiển thị tại đây khi bạn nhập địa chỉ mới.') ?>
              </div>
            </div>

            <div class="address-map-card mt-3">
              <div class="address-preview-card__label">Bản đồ giao hàng</div>
              <div class="address-map-card__hint" data-address-map-hint>Chọn địa chỉ cụ thể để xem vị trí tương ứng trên bản đồ.</div>
              <div class="address-map-frame-wrap d-none" data-address-map-wrap>
                <iframe
                  class="address-map-frame"
                  data-address-map
                  title="Bản đồ địa chỉ giao hàng"
                  loading="lazy"
                  referrerpolicy="no-referrer-when-downgrade"
                  allowfullscreen></iframe>
              </div>
              <a class="address-map-link d-none" data-address-map-link target="_blank" rel="noopener noreferrer">Mở bản đồ trong tab mới</a>
            </div>
          </div>
        </div>

        <div class="col-12">
          <div class="checkout-table-wrap">
            <table class="table checkout-table align-middle mb-0">
              <thead>
                <tr>
                  <th>Sản phẩm</th>
                  <th class="text-end">Đơn giá</th>
                  <th class="text-center">Số lượng</th>
                  <th class="text-end">Thành tiền</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($items as $it): ?>
                  <?php
                    $p = $it['product'] ?? [];
                    $img = resolve_image_url((string)($p['link_hinh_anh'] ?? ''));
                  ?>
                  <tr>
                    <td>
                      <div class="checkout-product">
                        <img src="<?= h($img ?: 'https://via.placeholder.com/92x92?text=No+Image') ?>" alt="<?= h($p['ten_san_pham'] ?? '') ?>" referrerpolicy="no-referrer" onerror="this.src='https://via.placeholder.com/92x92?text=No+Image';">
                        <div>
                          <div class="name"><?= h($p['ten_san_pham'] ?? '') ?></div>
                          <div class="brand"><?= h($p['thuong_hieu'] ?? '') ?></div>
                        </div>
                      </div>
                    </td>
                    <td class="text-end"><?= vnd($it['unit_price'] ?? 0) ?></td>
                    <td class="text-center"><?= (int)($it['qty'] ?? 1) ?></td>
                    <td class="text-end fw-semibold text-danger"><?= vnd($it['line_total'] ?? 0) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="payment-card mb-3">
            <h5 class="mb-3">Phương thức thanh toán</h5>
            <label class="payment-option <?= $selectedPaymentMethod === 'cod' ? 'active' : '' ?>">
              <input type="radio" name="hinh_thuc_thanh_toan" value="cod" <?= $selectedPaymentMethod === 'cod' ? 'checked' : '' ?>>
              <span>
                <strong>Thanh toán khi nhận hàng (COD)</strong>
                <small>Thanh toán trực tiếp cho shipper khi đơn giao tới nơi.</small>
              </span>
            </label>

            <label class="payment-option <?= $selectedPaymentMethod === 'bank_transfer_qr' ? 'active' : '' ?> <?= !empty($qrTransfer['enabled']) ? '' : 'payment-option--disabled' ?>">
              <input type="radio" name="hinh_thuc_thanh_toan" value="bank_transfer_qr" <?= $selectedPaymentMethod === 'bank_transfer_qr' ? 'checked' : '' ?> <?= !empty($qrTransfer['enabled']) ? '' : 'disabled' ?>>
              <span>
                <strong>Chuyển khoản qua QR</strong>
                <small><?= !empty($qrTransfer['enabled']) ? 'Đặt hàng trước, sau đó quét QR theo đúng mã đơn để hoàn tất thanh toán.' : 'Chưa cấu hình thông tin nhận chuyển khoản trong hệ thống.' ?></small>
              </span>
            </label>

            <?php if (!empty($qrTransfer['enabled']) && $transferPreview): ?>
              <div class="qr-transfer-box <?= $selectedPaymentMethod === 'bank_transfer_qr' ? '' : 'd-none' ?>" data-qr-transfer-box>
                <div class="qr-transfer-box__head">
                  <div>
                    <div class="qr-transfer-box__title">Thanh toán chuyển khoản</div>
                    <div class="qr-transfer-box__subtitle">Sau khi bấm đặt hàng, hệ thống sẽ tạo nội dung chuyển khoản theo mã đơn để bạn quét và thanh toán chính xác.</div>
                  </div>
                  <span class="qr-transfer-badge">QR</span>
                </div>
                <div class="qr-transfer-grid">
                  <div class="qr-transfer-image-wrap">
                    <img src="<?= h($transferPreview['qr_url'] ?? '') ?>" alt="QR chuyển khoản" class="qr-transfer-image">
                  </div>
                  <div class="qr-transfer-info">
                    <div><strong>Ngân hàng:</strong> <?= h($transferPreview['bank_name'] ?? '') ?></div>
                    <div><strong>Số tài khoản:</strong> <?= h($transferPreview['account_no'] ?? '') ?></div>
                    <div><strong>Chủ tài khoản:</strong> <?= h($transferPreview['account_name'] ?? '') ?></div>
                    <div><strong>Số tiền tạm tính để quét:</strong> <?= vnd($grandTotal) ?></div>
                    <div><strong>Nội dung mẫu:</strong> <?= h($transferPreview['content'] ?? '') ?></div>
                    <div class="qr-transfer-note">Nội dung thanh toán chính thức sẽ đổi sang mã đơn ngay sau khi bạn bấm đặt hàng.</div>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>

          <div class="checkout-note-card">
            <div class="checkout-note-card__title">Bạn cần gì trước khi đặt hàng?</div>
            <div class="checkout-note-list">
              <div><i class="fa-solid fa-box-open"></i><span>Kiểm tra kỹ tên người nhận, số điện thoại và địa chỉ để tránh giao sai.</span></div>
              <div><i class="fa-solid fa-shield-heart"></i><span>Nếu cần tư vấn sản phẩm hoặc routine, mở chat hỗ trợ ngay trên trang này.</span></div>
              <div><i class="fa-solid fa-truck"></i><span>Phí vận chuyển được cộng cố định vào đơn trước khi xác nhận.</span></div>
            </div>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="voucher-card mb-3" id="voucher-card-box">
            <div class="voucher-card__title">Mã giảm giá</div>
            <div class="input-group mb-2">
              <input class="form-control" type="text" name="voucher_code" value="<?= h($voucherCode) ?>" placeholder="Nhập mã voucher">
              <button class="btn btn-outline-brand" type="submit" formaction="<?= BASE_URL ?>/index.php?r=apdung_voucher#voucher-card-box" formmethod="post" formnovalidate>Áp dụng</button>
            </div>
            <?php if ($appliedVoucher): ?>
              <div class="voucher-pill-row">
                <div>
                  <div class="voucher-pill-code"><?= h($appliedVoucher['voucher']['ma_code'] ?? '') ?></div>
                  <div class="voucher-pill-meta"><?= h($appliedVoucher['voucher']['ten_voucher'] ?? 'Voucher đang áp dụng') ?></div>
                </div>
                <button class="btn btn-sm btn-light border" type="submit" formaction="<?= BASE_URL ?>/index.php?r=bo_voucher#voucher-card-box" formmethod="post" formnovalidate>Gỡ mã</button>
              </div>
            <?php else: ?>
              <div class="voucher-note">Mã giảm giá được tính trên giá trị sản phẩm trước phí vận chuyển.</div>
            <?php endif; ?>
          </div>

          <div class="voucher-card mb-3" id="points-card-box">
            <div class="voucher-card__title">Dùng điểm tích lũy</div>
            <div class="voucher-note mb-2">Bạn hiện có <strong><?= number_format($availablePoints, 0, ',', '.') ?> điểm</strong>. Quy đổi: 1 điểm = <?= number_format($pointValueVnd, 0, ',', '.') ?>đ.</div>
            <div class="input-group mb-2">
              <input class="form-control" type="number" min="0" max="<?= $maxUsablePoints ?>" step="1" name="points_to_use" value="<?= $pointsToUse > 0 ? $pointsToUse : '' ?>" placeholder="Nhập số điểm muốn dùng">
              <button class="btn btn-outline-brand" type="submit" formaction="<?= BASE_URL ?>/index.php?r=apdung_diem#points-card-box" formmethod="post" formnovalidate>Đổi điểm</button>
            </div>
            <?php if ($pointsToUse > 0): ?>
              <div class="voucher-pill-row">
                <div>
                  <div class="voucher-pill-code"><?= number_format($pointsToUse, 0, ',', '.') ?> điểm</div>
                  <div class="voucher-pill-meta">Giảm trực tiếp <?= vnd($pointsDiscountAmount) ?> trên giá trị sản phẩm.</div>
                </div>
                <button class="btn btn-sm btn-light border" type="submit" formaction="<?= BASE_URL ?>/index.php?r=bo_diem#points-card-box" formmethod="post" formnovalidate>Gỡ điểm</button>
              </div>
            <?php else: ?>
              <div class="voucher-note">Tối đa có thể dùng cho đơn này: <?= number_format($maxUsablePoints, 0, ',', '.') ?> điểm.</div>
            <?php endif; ?>
          </div>

          <div class="summary-card">
            <div class="summary-row"><span>Tạm tính</span><strong><?= vnd($subtotal) ?></strong></div>
            <?php if ($voucherDiscountAmount > 0): ?>
              <div class="summary-row summary-row--discount"><span>Giảm voucher</span><strong>-<?= vnd($voucherDiscountAmount) ?></strong></div>
            <?php endif; ?>
            <?php if ($pointsDiscountAmount > 0): ?>
              <div class="summary-row summary-row--discount"><span>Giảm bằng điểm</span><strong>-<?= vnd($pointsDiscountAmount) ?></strong></div>
            <?php endif; ?>
            <div class="summary-row"><span>Phí vận chuyển</span><strong><?= vnd($shippingFee) ?></strong></div>
            <div class="summary-divider"></div>
            <div class="summary-row total"><span>Tổng thanh toán</span><strong><?= vnd($grandTotal) ?></strong></div>
            <button class="btn btn-order w-100 mt-2" type="submit">ĐẶT HÀNG</button>
            <p class="summary-caption mb-0 mt-3">Bằng việc đặt hàng, bạn xác nhận thông tin giao nhận là chính xác và đồng ý để SkinSyntax liên hệ khi cần hỗ trợ đơn.</p>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
  .checkout-page {
    font-family: 'Quicksand', system-ui, sans-serif;
  }

  .checkout-head__actions .btn {
    min-width: 170px;
  }

  .address-card {
    background: #fff;
    border: 1px solid #e9edf4;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 14px 28px rgba(15, 23, 42, 0.06);
  }

  .stripe-top {
    height: 6px;
    background: repeating-linear-gradient(
      45deg,
      #d0011b,
      #d0011b 12px,
      #ffffff 12px,
      #ffffff 24px,
      #00a0ff 24px,
      #00a0ff 36px,
      #ffffff 36px,
      #ffffff 48px
    );
  }

  .address-content {
    padding: 18px;
  }

  .address-title {
    color: #ee4d2d;
    font-weight: 800;
    font-size: 18px;
  }

  .address-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 16px;
  }

  .address-subtitle {
    color: #64748b;
    margin-top: 4px;
    line-height: 1.6;
  }

  .address-selector-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 16px;
  }

  .address-option-card {
    position: relative;
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    padding: 18px;
    background: linear-gradient(180deg, #ffffff 0%, #fffaf8 100%);
    cursor: pointer;
    transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    min-height: 190px;
  }

  .address-option-card:hover {
    border-color: #f2b5a9;
    box-shadow: 0 16px 30px rgba(238, 77, 45, 0.08);
    transform: translateY(-2px);
  }

  .address-option-card.is-active {
    border-color: #ee4d2d;
    box-shadow: 0 18px 34px rgba(238, 77, 45, 0.14);
  }

  .address-option-card.is-disabled {
    cursor: not-allowed;
    background: #f8fafc;
    opacity: .82;
  }

  .address-option-card input[type='radio'] {
    position: absolute;
    top: 18px;
    right: 18px;
    accent-color: #ee4d2d;
  }

  .address-option-card__top {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
    padding-right: 28px;
  }

  .address-option-card__title {
    font-size: 16px;
    font-weight: 800;
    color: #111827;
  }

  .address-option-card__meta {
    color: #6b7280;
    font-size: 13px;
    margin-top: 4px;
  }

  .address-option-badge {
    align-self: flex-start;
    border-radius: 999px;
    padding: 6px 10px;
    background: #fee2e2;
    color: #b91c1c;
    font-size: 12px;
    font-weight: 800;
  }

  .address-option-badge--soft {
    background: #fff1d6;
    color: #9a6700;
  }

  .address-option-card__contact,
  .address-summary-card__contact {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 8px;
  }

  .address-option-card__contact span,
  .address-summary-card__contact span {
    color: #cbd5e1;
  }

  .address-option-card__text,
  .address-option-card__empty,
  .address-summary-card__text {
    color: #475569;
    line-height: 1.7;
  }

  .address-detail-panel {
    border: 1px solid #edf1f6;
    border-radius: 16px;
    padding: 18px;
    background: linear-gradient(180deg, #fbfdff 0%, #ffffff 100%);
    margin-bottom: 8px;
  }

  .address-summary-card {
    display: grid;
    grid-template-columns: 52px 1fr;
    gap: 16px;
    align-items: flex-start;
  }

  .address-summary-card__icon {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    display: grid;
    place-items: center;
    background: linear-gradient(135deg, #fff0ea 0%, #ffe5dd 100%);
    color: #ee4d2d;
    font-size: 22px;
  }

  .address-summary-card__title,
  .address-preview-card__label {
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 8px;
  }

  .address-save-toggle {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-weight: 600;
    color: #334155;
    border: 1px dashed #f0c1b8;
    border-radius: 12px;
    padding: 12px 14px;
    background: #fff7f5;
  }

  .address-save-toggle input {
    accent-color: #ee4d2d;
  }

  .address-preview-card {
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    padding: 16px;
    background: #ffffff;
  }

  .address-preview-card__value {
    color: #475569;
    line-height: 1.7;
    min-height: 28px;
  }

  .address-map-card {
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    padding: 16px;
    background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
  }

  .address-map-card__hint {
    color: #64748b;
    font-size: 13px;
    line-height: 1.6;
    margin-bottom: 12px;
  }

  .address-map-frame-wrap {
    overflow: hidden;
    border-radius: 14px;
    border: 1px solid #dbe4f0;
    background: #fff;
  }

  .address-map-frame {
    width: 100%;
    height: 280px;
    border: 0;
    display: block;
  }

  .address-map-link {
    display: inline-flex;
    align-items: center;
    margin-top: 12px;
    color: #0f766e;
    font-weight: 700;
    text-decoration: none;
  }

  .address-map-link:hover {
    color: #115e59;
  }

  .address-autocomplete-note {
    color: #64748b;
    font-size: 13px;
    line-height: 1.6;
    border-radius: 12px;
    padding: 12px 14px;
    background: #f8fbff;
    border: 1px dashed #cbd5e1;
  }

  .address-autocomplete-note.is-loading {
    color: #9a6700;
    background: #fff8e8;
    border-color: #f4d38a;
  }

  .address-autocomplete-note.is-error {
    color: #b91c1c;
    background: #fff1f2;
    border-color: #fecdd3;
  }

  .checkout-table-wrap {
    border: 1px solid #edf1f6;
    border-radius: 12px;
    overflow: hidden;
  }

  .checkout-table thead th {
    background: #fafbfe;
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .04em;
    border-bottom: 1px solid #edf1f6;
  }

  .checkout-product {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .checkout-product img {
    width: 58px;
    height: 58px;
    border-radius: 10px;
    object-fit: cover;
    border: 1px solid #e7edf5;
  }

  .checkout-product .name {
    font-weight: 700;
    color: #1f2937;
  }

  .checkout-product .brand {
    color: #6b7280;
    font-size: 13px;
  }

  .payment-card,
  .voucher-card,
  .summary-card {
    border: 1px solid #edf1f6;
    border-radius: 12px;
    padding: 14px;
    background: #fff;
  }

  .summary-card {
    height: 100%;
  }

  .payment-option {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid #f0d1cb;
    border-radius: 10px;
    padding: 12px;
    font-weight: 600;
    color: #3f3f46;
    background: #fff7f5;
    margin-bottom: 10px;
  }

  .payment-option span {
    display: grid;
    gap: 3px;
  }

  .payment-option small {
    font-weight: 500;
    color: #6b7280;
  }

  .payment-option--disabled {
    opacity: .65;
    cursor: not-allowed;
  }

  .checkout-note-card {
    border: 1px solid #edf1f6;
    border-radius: 12px;
    padding: 16px;
    background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
  }

  .checkout-note-card__title {
    font-size: 16px;
    font-weight: 800;
    color: #1f2937;
    margin-bottom: 12px;
  }

  .checkout-note-list {
    display: grid;
    gap: 10px;
  }

  .checkout-note-list > div {
    display: flex;
    gap: 10px;
    color: #4b5563;
    align-items: flex-start;
  }

  .checkout-note-list i {
    color: #ee4d2d;
    margin-top: 3px;
  }

  .payment-option input {
    accent-color: #ee4d2d;
  }

  .qr-transfer-box {
    border: 1px solid #cfe8db;
    border-radius: 16px;
    padding: 16px;
    background: linear-gradient(180deg, #f5fffa 0%, #ffffff 100%);
    margin-top: 14px;
  }

  .qr-transfer-box__head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
  }

  .qr-transfer-box__title {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
  }

  .qr-transfer-box__subtitle,
  .qr-transfer-note {
    font-size: 13px;
    color: #64748b;
    line-height: 1.6;
  }

  .qr-transfer-badge {
    align-self: flex-start;
    border-radius: 999px;
    padding: 6px 10px;
    background: #dff5e8;
    color: #0f6b3e;
    font-weight: 800;
    font-size: 12px;
    letter-spacing: .08em;
  }

  .qr-transfer-grid {
    display: grid;
    grid-template-columns: 180px 1fr;
    gap: 16px;
    align-items: center;
  }

  .qr-transfer-image-wrap {
    background: #fff;
    border-radius: 14px;
    padding: 10px;
    border: 1px solid #e2e8f0;
    text-align: center;
  }

  .qr-transfer-image {
    width: 100%;
    max-width: 160px;
    aspect-ratio: 1;
    object-fit: contain;
  }

  .qr-transfer-info {
    display: grid;
    gap: 8px;
    color: #334155;
    font-size: 14px;
  }

  .summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    color: #374151;
    font-weight: 600;
  }

  .summary-divider {
    height: 1px;
    background: #eceff4;
    margin: 10px 0;
  }

  .summary-row--discount strong {
    color: #0f9d58;
  }

  .summary-row.total strong {
    color: #ee4d2d;
    font-size: 20px;
  }

  .summary-caption {
    color: #6b7280;
    font-size: 13px;
    line-height: 1.6;
  }

  .btn-order {
    background: #ee4d2d;
    border: 0;
    color: #fff;
    font-weight: 800;
    border-radius: 10px;
    padding: 11px 14px;
  }

  .btn-order:hover {
    color: #fff;
    background: #d93f21;
  }

  .voucher-card__title {
    font-size: 16px;
    font-weight: 800;
    color: #1f2937;
    margin-bottom: 10px;
  }

  .voucher-note {
    color: #6b7280;
    font-size: 13px;
    line-height: 1.6;
  }

  .voucher-pill-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    border: 1px dashed #f0c1b8;
    border-radius: 10px;
    padding: 12px;
    background: #fff7f5;
  }

  .voucher-pill-code {
    color: #ee4d2d;
    font-weight: 800;
    letter-spacing: .04em;
  }

  .voucher-pill-meta {
    color: #6b7280;
    font-size: 13px;
    margin-top: 2px;
  }

  @media (max-width: 767.98px) {
    .address-head {
      flex-direction: column;
      align-items: stretch;
    }

    .address-selector-grid {
      grid-template-columns: 1fr;
    }

    .address-summary-card {
      grid-template-columns: 1fr;
    }

    .qr-transfer-grid {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 767.98px) {
    .checkout-head__actions .btn {
      min-width: 0;
      width: 100%;
    }
  }
</style>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('checkoutForm');
    if (!form) {
      return;
    }

    var options = form.querySelectorAll('.payment-option');
    var radios = form.querySelectorAll('input[name="hinh_thuc_thanh_toan"]');
    var qrBox = form.querySelector('[data-qr-transfer-box]');
    var addressRadios = form.querySelectorAll('input[name="address_choice"]');
    var addressCards = form.querySelectorAll('[data-address-choice-card]');
    var addressPanels = form.querySelectorAll('[data-address-panel]');
    var newAddressRequiredFields = form.querySelectorAll('[data-address-required="new"]');
    var addressPreview = form.querySelector('[data-new-address-preview]');
    var previewInputs = form.querySelectorAll('[data-address-preview-input]');
    var provinceInput = form.querySelector('[data-address-field="province"]');
    var districtInput = form.querySelector('[data-address-field="district"]');
    var wardInput = form.querySelector('[data-address-field="ward"]');
    var provinceList = document.getElementById('checkoutProvinceList');
    var districtList = document.getElementById('checkoutDistrictList');
    var wardList = document.getElementById('checkoutWardList');
    var addressFeedback = form.querySelector('[data-address-feedback]');
    var addressMap = form.querySelector('[data-address-map]');
    var addressMapWrap = form.querySelector('[data-address-map-wrap]');
    var addressMapHint = form.querySelector('[data-address-map-hint]');
    var addressMapLink = form.querySelector('[data-address-map-link]');
    var defaultMapAddress = <?= json_encode((string)($defaultReceiver['dia_chi_giao_hang'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var addressDataset = [];
    var districtDataset = [];
    var wardDataset = [];
    var districtCache = {};
    var wardCache = {};
    var selectedProvince = null;
    var selectedDistrict = null;
    var selectedWard = null;
    var mergedProvinceConfigs = {
      tuyenquang: {
        displayName: 'Tuyên Quang',
        sourceIds: ['08', '02'],
        aliases: ['Hà Giang']
      },
      laocai: {
        displayName: 'Lào Cai',
        sourceIds: ['10', '15'],
        aliases: ['Yên Bái']
      },
      thainguyen: {
        displayName: 'Thái Nguyên',
        sourceIds: ['19', '06'],
        aliases: ['Bắc Kạn']
      },
      phutho: {
        displayName: 'Phú Thọ',
        sourceIds: ['25', '26', '17'],
        aliases: ['Vĩnh Phúc', 'Hòa Bình', 'Hoà Bình']
      },
      bacninh: {
        displayName: 'Bắc Ninh',
        sourceIds: ['27', '24'],
        aliases: ['Bắc Giang']
      },
      haiphong: {
        displayName: 'Hải Phòng',
        sourceIds: ['31', '30'],
        aliases: ['Hải Dương']
      },
      hungyen: {
        displayName: 'Hưng Yên',
        sourceIds: ['33', '34'],
        aliases: ['Thái Bình']
      },
      ninhbinh: {
        displayName: 'Ninh Bình',
        sourceIds: ['37', '36', '35'],
        aliases: ['Nam Định', 'Hà Nam']
      },
      quangtri: {
        displayName: 'Quảng Trị',
        sourceIds: ['45', '44'],
        aliases: ['Quảng Bình']
      },
      danang: {
        displayName: 'Đà Nẵng',
        sourceIds: ['48', '49'],
        aliases: ['Quảng Nam']
      },
      quangngai: {
        displayName: 'Quảng Ngãi',
        sourceIds: ['51', '62'],
        aliases: ['Kon Tum']
      },
      gialai: {
        displayName: 'Gia Lai',
        sourceIds: ['64', '52'],
        aliases: ['Bình Định']
      },
      daklak: {
        displayName: 'Đắk Lắk',
        sourceIds: ['66', '54'],
        aliases: ['Phú Yên']
      },
      khanhhoa: {
        displayName: 'Khánh Hòa',
        sourceIds: ['56', '58'],
        aliases: ['Ninh Thuận']
      },
      lamdong: {
        displayName: 'Lâm Đồng',
        sourceIds: ['68', '67', '60'],
        aliases: ['Đắk Nông', 'Bình Thuận']
      },
      hochiminh: {
        displayName: 'Hồ Chí Minh',
        sourceIds: ['79', '74', '77'],
        aliases: ['TPHCM', 'TP HCM', 'Sài Gòn', 'Sai Gon', 'Bình Dương', 'Bà Rịa - Vũng Tàu', 'Ba Ria - Vung Tau', 'Vũng Tàu', 'Vung Tau']
      },
      dongnai: {
        displayName: 'Đồng Nai',
        sourceIds: ['75', '70'],
        aliases: ['Bình Phước']
      },
      tayninh: {
        displayName: 'Tây Ninh',
        sourceIds: ['72', '80'],
        aliases: ['Long An']
      },
      cantho: {
        displayName: 'Cần Thơ',
        sourceIds: ['92', '93', '94'],
        aliases: ['Hậu Giang', 'Sóc Trăng']
      },
      vinhlong: {
        displayName: 'Vĩnh Long',
        sourceIds: ['86', '84', '83'],
        aliases: ['Trà Vinh', 'Bến Tre']
      },
      dongthap: {
        displayName: 'Đồng Tháp',
        sourceIds: ['87', '82'],
        aliases: ['Tiền Giang']
      },
      camau: {
        displayName: 'Cà Mau',
        sourceIds: ['96', '95'],
        aliases: ['Bạc Liêu']
      },
      angiang: {
        displayName: 'An Giang',
        sourceIds: ['89', '91'],
        aliases: ['Kiên Giang']
      },
      hue: {
        displayName: 'Huế',
        sourceIds: ['46'],
        aliases: ['Thừa Thiên Huế', 'Thua Thien Hue', 'TP Huế', 'Thành phố Huế']
      }
    };

    var setFeedback = function (message, type) {
      if (!addressFeedback) {
        return;
      }

      addressFeedback.textContent = message;
      addressFeedback.classList.remove('is-loading', 'is-error');
      if (type) {
        addressFeedback.classList.add(type);
      }
    };

    var normalizeAddressText = function (value) {
      return (value || '')
        .toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/đ/g, 'd')
        .replace(/[^a-z0-9]/g, '');
    };

    var stripAdministrativePrefix = function (name) {
      return (name || '')
        .replace(/^Tỉnh\s+/i, '')
        .replace(/^Thành phố\s+/i, '')
        .replace(/^Quận\s+/i, '')
        .replace(/^Huyện\s+/i, '')
        .replace(/^Thị xã\s+/i, '')
        .replace(/^Thị trấn\s+/i, '')
        .replace(/^Phường\s+/i, '')
        .replace(/^Xã\s+/i, '')
        .trim();
    };

    var buildAliases = function (name, extraAliases) {
      var aliases = [name];
      var compact = stripAdministrativePrefix(name);

      aliases.push(compact);

      var normalizedCompact = normalizeAddressText(compact);
      if (normalizedCompact === 'hochiminh') {
        aliases.push('TPHCM', 'TP HCM', 'Sai Gon', 'Sài Gòn');
      }
      if (normalizedCompact === 'hanoi') {
        aliases.push('TP Ha Noi', 'Hà Nội');
      }

      if (Array.isArray(extraAliases) && extraAliases.length) {
        aliases = aliases.concat(extraAliases);
      }

      return aliases.filter(function (alias, index, list) {
        return alias && list.indexOf(alias) === index;
      });
    };

    var buildMergedProvinceDataset = function (items) {
      var byId = {};
      var usedIds = {};
      var mergedItems = [];

      items.forEach(function (item) {
        byId[item.id] = item;
      });

      Object.keys(mergedProvinceConfigs).forEach(function (key) {
        var config = mergedProvinceConfigs[key];
        var sources = (config.sourceIds || []).map(function (sourceId) {
          return byId[sourceId] || null;
        }).filter(Boolean);

        if (!sources.length) {
          return;
        }

        var primary = sources[0];
        mergedItems.push({
          id: primary.id,
          name: config.displayName,
          displayName: config.displayName,
          shortName: config.displayName,
          latitude: primary.latitude,
          longitude: primary.longitude,
          aliases: (config.aliases || []).concat(sources.map(function (source) {
            return source.name;
          }).filter(function (sourceName) {
            return normalizeAddressText(stripAdministrativePrefix(sourceName)) !== normalizeAddressText(stripAdministrativePrefix(config.displayName));
          })),
          sourceIds: sources.map(function (source) {
            return source.id;
          }),
          sourceItems: sources.map(function (source) {
            return {
              id: source.id,
              name: source.name,
              shortName: source.shortName || stripAdministrativePrefix(source.name)
            };
          })
        });

        sources.forEach(function (source) {
          usedIds[source.id] = true;
        });
      });

      items.forEach(function (item) {
        if (usedIds[item.id]) {
          return;
        }

        mergedItems.push({
          id: item.id,
          name: item.name,
          displayName: item.name,
          shortName: item.shortName,
          latitude: item.latitude,
          longitude: item.longitude,
          aliases: [],
          sourceIds: [item.id],
          sourceItems: [{
            id: item.id,
            name: item.name,
            shortName: item.shortName || stripAdministrativePrefix(item.name)
          }]
        });
      });

      return mergedItems.sort(function (left, right) {
        return left.name.localeCompare(right.name, 'vi');
      });
    };

    var matchesName = function (query, name, extraAliases) {
      if (!query) {
        return true;
      }

      var normalizedQuery = normalizeAddressText(query);
      return buildAliases(name, extraAliases).some(function (alias) {
        return normalizeAddressText(alias).indexOf(normalizedQuery) !== -1;
      });
    };

    var enrichMergedChildren = function (items, buildContextLabel) {
      var nameCounts = {};

      items.forEach(function (item) {
        var key = normalizeAddressText(stripAdministrativePrefix(item.name));
        nameCounts[key] = (nameCounts[key] || 0) + 1;
      });

      return items.map(function (item) {
        var key = normalizeAddressText(stripAdministrativePrefix(item.name));
        var hasDuplicateName = (nameCounts[key] || 0) > 1;
        var contextLabel = typeof buildContextLabel === 'function' ? buildContextLabel(item) : '';
        var displayName = hasDuplicateName && contextLabel
          ? item.name + ' - ' + contextLabel
          : item.name;
        var aliases = (item.aliases || []).slice();

        if (displayName !== item.name) {
          aliases.push(displayName);
        }

        return Object.assign({}, item, {
          displayName: displayName,
          aliases: aliases.filter(function (alias, index, list) {
            return alias && list.indexOf(alias) === index;
          })
        });
      });
    };

    var fillDatalist = function (element, items) {
      if (!element) {
        return;
      }

      element.innerHTML = items.slice(0, 80).map(function (item) {
        return '<option value="' + item.replace(/"/g, '&quot;') + '"></option>';
      }).join('');
    };

    var findExactProvince = function (value) {
      var normalizedValue = normalizeAddressText(value);
      return addressDataset.find(function (province) {
        return buildAliases(province.name, province.aliases).some(function (alias) {
          return normalizeAddressText(alias) === normalizedValue;
        });
      }) || null;
    };

    var findDistrict = function (districts, value) {
      return (districts || []).find(function (district) {
        return buildAliases(district.name, district.aliases).some(function (alias) {
          return normalizeAddressText(alias) === normalizeAddressText(value);
        });
      }) || null;
    };

    var findWard = function (wards, value) {
      return (wards || []).find(function (ward) {
        return buildAliases(ward.name, ward.aliases).some(function (alias) {
          return normalizeAddressText(alias) === normalizeAddressText(value);
        });
      }) || null;
    };

    var mapApiItem = function (item) {
      if (!item) {
        return null;
      }

      return {
        id: String(item.id || ''),
        name: String(item.full_name || item.name || '').trim(),
        displayName: String(item.full_name || item.name || '').trim(),
        shortName: String(item.name || '').trim(),
        latitude: item.latitude ? Number(item.latitude) : null,
        longitude: item.longitude ? Number(item.longitude) : null
      };
    };

    var fetchAddressLevel = function (level, id) {
      return fetch('https://esgoo.net/api-tinhthanh/' + level + '/' + id + '.htm')
        .then(function (response) {
          if (!response.ok) {
            throw new Error('load_failed');
          }
          return response.json();
        })
        .then(function (payload) {
          var items = Array.isArray(payload && payload.data) ? payload.data : [];
          return items.map(mapApiItem).filter(function (item) {
            return item && item.id !== '' && item.name !== '';
          });
        });
    };

    var loadDistricts = function (province) {
      var sourceIds = province && Array.isArray(province.sourceIds) && province.sourceIds.length
        ? province.sourceIds
        : (province && province.id ? [province.id] : []);
      var cacheKey = sourceIds.join(',');

      if (!sourceIds.length) {
        districtDataset = [];
        return Promise.resolve([]);
      }

      if (districtCache[cacheKey]) {
        districtDataset = districtCache[cacheKey];
        return Promise.resolve(districtDataset);
      }

      return Promise.all(sourceIds.map(function (sourceId) {
        var sourceMeta = (province.sourceItems || []).find(function (item) {
          return item.id === sourceId;
        }) || { id: sourceId, name: province.name, shortName: province.shortName || stripAdministrativePrefix(province.name) };

        return fetchAddressLevel(2, sourceId).then(function (items) {
          return items.map(function (item) {
            var aliases = [sourceMeta.name, sourceMeta.shortName, stripAdministrativePrefix(sourceMeta.name)];
            aliases.push(item.name + ' ' + sourceMeta.shortName);
            aliases.push(stripAdministrativePrefix(item.name) + ' ' + sourceMeta.shortName);

            return Object.assign({}, item, {
              aliases: aliases,
              sourceIds: [item.id],
              sourceProvinceId: sourceMeta.id,
              sourceProvinceName: sourceMeta.name,
              sourceProvinceShortName: sourceMeta.shortName
            });
          });
        });
      })).then(function (groups) {
        var mergedItems = [];
        var seen = {};

        groups.forEach(function (items) {
          items.forEach(function (item) {
            var dedupeKey = item.id || item.name;
            if (seen[dedupeKey]) {
              return;
            }

            seen[dedupeKey] = true;
            mergedItems.push(item);
          });
        });

        mergedItems = enrichMergedChildren(mergedItems, function (item) {
          return item.sourceProvinceShortName || stripAdministrativePrefix(item.sourceProvinceName || '');
        });

        districtCache[cacheKey] = mergedItems;
        districtDataset = mergedItems;
        return mergedItems;
      });
    };

    var loadWards = function (district) {
      var sourceIds = district && Array.isArray(district.sourceIds) && district.sourceIds.length
        ? district.sourceIds
        : (district && district.id ? [district.id] : []);
      var cacheKey = sourceIds.join(',');

      if (!sourceIds.length) {
        wardDataset = [];
        return Promise.resolve([]);
      }

      if (wardCache[cacheKey]) {
        wardDataset = wardCache[cacheKey];
        return Promise.resolve(wardDataset);
      }

      return Promise.all(sourceIds.map(function (sourceId) {
        return fetchAddressLevel(3, sourceId).then(function (items) {
          return items.map(function (item) {
            var districtLabel = district.displayName || district.name;
            var aliases = [district.name, districtLabel, stripAdministrativePrefix(district.name)];
            aliases.push(item.name + ' ' + stripAdministrativePrefix(district.name));
            aliases.push(stripAdministrativePrefix(item.name) + ' ' + stripAdministrativePrefix(district.name));
            if (district.sourceProvinceShortName) {
              aliases.push(item.name + ' ' + district.sourceProvinceShortName);
              aliases.push(stripAdministrativePrefix(item.name) + ' ' + district.sourceProvinceShortName);
            }

            return Object.assign({}, item, {
              aliases: aliases,
              sourceIds: [item.id],
              sourceDistrictId: sourceId,
              sourceDistrictName: district.name,
              sourceDistrictDisplayName: districtLabel,
              sourceProvinceName: district.sourceProvinceName || '',
              sourceProvinceShortName: district.sourceProvinceShortName || ''
            });
          });
        });
      })).then(function (groups) {
        var mergedItems = [];
        var seen = {};

        groups.forEach(function (items) {
          items.forEach(function (item) {
            var dedupeKey = item.id || item.name;
            if (seen[dedupeKey]) {
              return;
            }

            seen[dedupeKey] = true;
            mergedItems.push(item);
          });
        });

        mergedItems = enrichMergedChildren(mergedItems, function (item) {
          return stripAdministrativePrefix(item.sourceDistrictDisplayName || item.sourceDistrictName || '');
        });

        wardCache[cacheKey] = mergedItems;
        wardDataset = mergedItems;
        return mergedItems;
      });
    };

    var updateProvinceSuggestions = function () {
      if (!provinceInput) {
        return;
      }

      var filtered = addressDataset
        .filter(function (province) {
          var query = provinceInput.value;
          var normalizedQuery = normalizeAddressText(query);
          return buildAliases(province.name, province.aliases).some(function (alias) {
            return normalizeAddressText(alias).indexOf(normalizedQuery) !== -1;
          });
        })
        .map(function (province) {
          return province.name;
        });

      fillDatalist(provinceList, filtered);
    };

    var updateDistrictSuggestions = function () {
      if (!districtInput) {
        return;
      }

      var filtered = districtDataset
        .filter(function (district) {
          return matchesName(districtInput.value, district.displayName || district.name, district.aliases);
        })
        .map(function (district) {
          return district.displayName || district.name;
        });

      fillDatalist(districtList, filtered);
    };

    var updateWardSuggestions = function () {
      if (!wardInput) {
        return;
      }

      var filtered = wardDataset
        .filter(function (ward) {
          return matchesName(wardInput.value, ward.displayName || ward.name, ward.aliases);
        })
        .map(function (ward) {
          return ward.displayName || ward.name;
        });

      fillDatalist(wardList, filtered);
    };

    var syncProvince = function () {
      var exactProvince = findExactProvince(provinceInput ? provinceInput.value : '');
      selectedProvince = exactProvince;
      selectedDistrict = null;
      selectedWard = null;
      if (exactProvince && provinceInput) {
        provinceInput.value = exactProvince.name;
      }

      if (!exactProvince && districtInput) {
        districtInput.value = '';
      }
      if (!exactProvince && wardInput) {
        wardInput.value = '';
      }

      if (!exactProvince) {
        districtDataset = [];
        wardDataset = [];
        updateDistrictSuggestions();
        updateWardSuggestions();
        updateAddressMap();
        return Promise.resolve();
      }

      setFeedback('Đang tải danh sách quận huyện...', 'is-loading');

      return loadDistricts(exactProvince)
        .then(function () {
          setFeedback('Đang tải dữ liệu phường xã khi bạn chọn quận huyện.', '');
          selectedDistrict = findDistrict(districtDataset, districtInput ? districtInput.value : '');
          if (selectedDistrict && districtInput) {
            districtInput.value = selectedDistrict.name;
            return syncDistrict();
          }

          wardDataset = [];
          if (wardInput) {
            wardInput.value = '';
          }
          updateDistrictSuggestions();
          updateWardSuggestions();
          updateAddressMap();
        })
        .catch(function () {
          districtDataset = [];
          wardDataset = [];
          setFeedback('Không tải được danh sách quận huyện theo địa chỉ mới. Bạn vẫn có thể nhập tay.', 'is-error');
          updateDistrictSuggestions();
          updateWardSuggestions();
        });
    };

    var syncDistrict = function () {
      selectedDistrict = findDistrict(districtDataset, districtInput ? districtInput.value : '');
      selectedWard = null;
      if (selectedDistrict && districtInput) {
        districtInput.value = selectedDistrict.displayName || selectedDistrict.name;
      }

      if (!selectedDistrict && wardInput) {
        wardInput.value = '';
      }

      if (!selectedDistrict) {
        wardDataset = [];
        updateWardSuggestions();
        updateAddressMap();
        return Promise.resolve();
      }

      setFeedback('Đang tải danh sách phường xã...', 'is-loading');

      return loadWards(selectedDistrict)
        .then(function () {
          setFeedback('Chọn phường xã để bản đồ xác định chính xác hơn.', '');
          selectedWard = findWard(wardDataset, wardInput ? wardInput.value : '');
          if (selectedWard && wardInput) {
            wardInput.value = selectedWard.displayName || selectedWard.name;
          }
          updateWardSuggestions();
          updateAddressMap();
        })
        .catch(function () {
          wardDataset = [];
          setFeedback('Không tải được danh sách phường xã. Bạn vẫn có thể nhập tay.', 'is-error');
          updateWardSuggestions();
        });
    };

    var syncWard = function () {
      selectedWard = findWard(wardDataset, wardInput ? wardInput.value : '');
      if (selectedWard && wardInput) {
        wardInput.value = selectedWard.displayName || selectedWard.name;
      }

      updateAddressMap();
    };

    var attachAddressAutocomplete = function () {
      if (!provinceInput || !districtInput || !wardInput) {
        return;
      }

      provinceInput.addEventListener('input', function () {
        selectedProvince = null;
        selectedDistrict = null;
        selectedWard = null;
        districtDataset = [];
        wardDataset = [];
        updateProvinceSuggestions();
        updateDistrictSuggestions();
        updateWardSuggestions();
        updateAddressMap();
      });
      provinceInput.addEventListener('change', syncProvince);
      provinceInput.addEventListener('blur', syncProvince);

      districtInput.addEventListener('focus', updateDistrictSuggestions);
      districtInput.addEventListener('input', function () {
        selectedDistrict = null;
        selectedWard = null;
        wardDataset = [];
        updateDistrictSuggestions();
        updateWardSuggestions();
        updateAddressMap();
      });
      districtInput.addEventListener('change', syncDistrict);
      districtInput.addEventListener('blur', syncDistrict);

      wardInput.addEventListener('focus', updateWardSuggestions);
      wardInput.addEventListener('input', updateWardSuggestions);
      wardInput.addEventListener('change', syncWard);
      wardInput.addEventListener('blur', syncWard);
    };

    var loadAddressDataset = function () {
      if (!provinceInput || !districtInput || !wardInput) {
        return;
      }

      setFeedback('Đang tải danh sách tỉnh, quận huyện và phường xã...', 'is-loading');

      fetchAddressLevel(1, 0)
        .then(function (data) {
          addressDataset = buildMergedProvinceDataset(Array.isArray(data) ? data : []);
          updateProvinceSuggestions();
          setFeedback('Dữ liệu địa chỉ đã cập nhật theo nguồn mới. Chọn tỉnh để tải quận huyện.', '');
          return syncProvince();
        })
        .catch(function () {
          setFeedback('Không tải được dữ liệu hành chính tự động. Bạn vẫn có thể nhập tay địa chỉ như bình thường.', 'is-error');
        });
    };

    var buildAddressPreview = function () {
      if (!addressPreview) {
        return;
      }

      var getValue = function (key) {
        var field = form.querySelector('[data-address-preview-input="' + key + '"]');
        return field ? field.value.trim() : '';
      };

      var parts = [
        getValue('detail'),
        getValue('ward'),
        getValue('district'),
        getValue('city')
      ].filter(Boolean);
      var note = getValue('note');
      var text = parts.join(', ');

      if (text && note) {
        text += ' | Ghi chú: ' + note;
      }

      addressPreview.textContent = text || 'Thông tin đầy đủ sẽ hiển thị tại đây khi bạn nhập địa chỉ mới.';
    };

    var updateAddressMap = function () {
      if (!addressMap || !addressMapWrap || !addressMapHint || !addressMapLink) {
        return;
      }

      var selectedAddress = form.querySelector('input[name="address_choice"]:checked');
      var selectedValue = selectedAddress ? selectedAddress.value : 'new';
      var mapAddress = selectedValue === 'default' ? defaultMapAddress : (addressPreview ? addressPreview.textContent.trim() : '');
      var geoTarget = selectedWard || selectedDistrict || selectedProvince;
      var hasCoordinates = selectedValue === 'new'
        && geoTarget
        && Number.isFinite(geoTarget.latitude)
        && Number.isFinite(geoTarget.longitude);

      if (!mapAddress || mapAddress === 'Thông tin đầy đủ sẽ hiển thị tại đây khi bạn nhập địa chỉ mới.') {
        addressMapWrap.classList.add('d-none');
        addressMapLink.classList.add('d-none');
        addressMap.removeAttribute('src');
        addressMapHint.textContent = 'Chọn địa chỉ cụ thể để xem vị trí tương ứng trên bản đồ.';
        return;
      }

      if (hasCoordinates) {
        var lat = geoTarget.latitude;
        var lng = geoTarget.longitude;
        var zoom = selectedWard ? 17 : (selectedDistrict ? 15 : 13);
        var point = encodeURIComponent(lat + ',' + lng);
        addressMap.src = 'https://www.google.com/maps?q=' + point + '&z=' + zoom + '&output=embed';
        addressMapLink.href = 'https://www.google.com/maps/search/?api=1&query=' + point;
        addressMapHint.textContent = 'Bản đồ đang ghim theo ' + (geoTarget.displayName || geoTarget.name) + '.';
      } else {
        var query = encodeURIComponent(mapAddress + ', Việt Nam');
        addressMap.src = 'https://www.google.com/maps?q=' + query + '&z=16&output=embed';
        addressMapLink.href = 'https://www.google.com/maps/search/?api=1&query=' + query;
        addressMapHint.textContent = 'Bản đồ đang hiển thị theo địa chỉ bạn đã chọn.';
      }

      addressMapWrap.classList.remove('d-none');
      addressMapLink.classList.remove('d-none');
    };

    var refreshAddressState = function () {
      var selectedAddress = form.querySelector('input[name="address_choice"]:checked');
      var selectedValue = selectedAddress ? selectedAddress.value : 'new';

      addressCards.forEach(function (card) {
        card.classList.toggle('is-active', card.getAttribute('data-address-choice-card') === selectedValue);
      });

      addressPanels.forEach(function (panel) {
        panel.classList.toggle('d-none', panel.getAttribute('data-address-panel') !== selectedValue);
      });

      newAddressRequiredFields.forEach(function (field) {
        field.required = selectedValue === 'new';
      });

      buildAddressPreview();
      updateAddressMap();
    };

    var refreshPaymentState = function () {
      var selected = form.querySelector('input[name="hinh_thuc_thanh_toan"]:checked');
      var selectedValue = selected ? selected.value : 'cod';

      options.forEach(function (option) {
        var input = option.querySelector('input[name="hinh_thuc_thanh_toan"]');
        option.classList.toggle('active', !!input && input.checked);
      });

      if (qrBox) {
        qrBox.classList.toggle('d-none', selectedValue !== 'bank_transfer_qr');
      }
    };

    radios.forEach(function (radio) {
      radio.addEventListener('change', refreshPaymentState);
    });

    addressRadios.forEach(function (radio) {
      radio.addEventListener('change', refreshAddressState);
    });

    previewInputs.forEach(function (field) {
      field.addEventListener('input', function () {
        buildAddressPreview();
        updateAddressMap();
      });
    });

    attachAddressAutocomplete();
    loadAddressDataset();

    refreshPaymentState();
    refreshAddressState();
    updateAddressMap();

    // Auto Smooth Scroll to Voucher / Points Card if Hash Present
    if (window.location.hash) {
      var hashTarget = document.querySelector(window.location.hash);
      if (hashTarget) {
        setTimeout(function () {
          hashTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 100);
      }
    }
  });
</script>
