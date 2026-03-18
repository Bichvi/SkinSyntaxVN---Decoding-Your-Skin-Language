<?php
$items = $items ?? [];
$receiver = $receiver ?? [];
$subtotal = (int)($subtotal ?? 0);
$shippingFee = (int)($shippingFee ?? 30000);
$grandTotal = (int)($grandTotal ?? ($subtotal + $shippingFee));
?>

<div class="container checkout-page py-4 py-lg-5">
  <div class="checkout-head mb-3">
    <h2 class="mb-1">Thanh toán</h2>
    <div class="text-muted">Xác nhận thông tin nhận hàng và đặt đơn hàng.</div>
  </div>

  <div class="address-card mb-3">
    <div class="stripe-top"></div>
    <div class="address-content">
      <div class="address-title"><i class="fa-solid fa-location-dot me-2"></i>Địa chỉ nhận hàng</div>

      <form method="post" action="<?= BASE_URL ?>/index.php?r=xulydathang" id="checkoutForm" class="row g-3 mt-1">
        <div class="col-md-4">
          <label class="form-label">Tên người nhận</label>
          <input class="form-control" name="ten_nguoi_nhan" value="<?= h($receiver['ten_nguoi_nhan'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Số điện thoại</label>
          <input class="form-control" name="sdt_nguoi_nhan" value="<?= h($receiver['sdt_nguoi_nhan'] ?? '') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Địa chỉ giao hàng</label>
          <input class="form-control" name="dia_chi_giao_hang" value="<?= h($receiver['dia_chi_giao_hang'] ?? '') ?>" required>
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
                    $img = first_image_url($p['link_hinh_anh'] ?? '');
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
          <div class="payment-card">
            <h5 class="mb-3">Phương thức thanh toán</h5>
            <label class="payment-option active">
              <input type="radio" name="hinh_thuc_thanh_toan" value="cod" checked>
              <span>Thanh toán khi nhận hàng (COD)</span>
            </label>
          </div>
        </div>

        <div class="col-lg-5">
          <div class="summary-card">
            <div class="summary-row"><span>Tạm tính</span><strong><?= vnd($subtotal) ?></strong></div>
            <div class="summary-row"><span>Phí vận chuyển</span><strong><?= vnd($shippingFee) ?></strong></div>
            <div class="summary-divider"></div>
            <div class="summary-row total"><span>Tổng thanh toán</span><strong><?= vnd($grandTotal) ?></strong></div>
            <button class="btn btn-order w-100 mt-2" type="submit">ĐẶT HÀNG</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
  .checkout-page {
    font-family: "Be Vietnam Pro", system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
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
  .summary-card {
    border: 1px solid #edf1f6;
    border-radius: 12px;
    padding: 14px;
    height: 100%;
    background: #fff;
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
  }

  .payment-option input {
    accent-color: #ee4d2d;
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

  .summary-row.total strong {
    color: #ee4d2d;
    font-size: 20px;
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
</style>
