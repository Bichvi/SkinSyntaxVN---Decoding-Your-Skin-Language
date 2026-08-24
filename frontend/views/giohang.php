<?php
// backend/app/views/giohang.php

if (!function_exists('format_vnd')) {
  function format_vnd($amount): string {
    return number_format((int)round((float)$amount), 0, ',', '.') . ' ₫';
  }
}

$items = $items ?? [];
$shippingThreshold = 700000;
$total = 0;

foreach ($items as $item) {
  $product = $item['product'] ?? [];
  $qty = (int)($item['qty'] ?? 1);
  $unitPrice = (float)($product['gia_ban'] ?? 0);
  if ($unitPrice <= 0) {
    $unitPrice = (float)($product['gia_goc'] ?? 0);
  }
  $total += $unitPrice * max(1, $qty);
}

$remainingToFreeShip = max(0, $shippingThreshold - $total);
$freeShipPercent = $shippingThreshold > 0 ? min(100, (int)round(($total / $shippingThreshold) * 100)) : 100;
?>
<div class="container py-4 py-lg-5 cart-page" id="cartPage"
     data-base-url="<?= BASE_URL ?>"
     data-shipping-threshold="<?= (int)$shippingThreshold ?>">
  <div class="d-flex align-items-center justify-content-between gap-2 mb-4">
    <h1 class="cart-title mb-0">Giỏ hàng của bạn</h1>
    <span class="cart-count-pill" id="cartCountPill"><?= count($items) ?> sản phẩm</span>
  </div>

  <?php if (empty($items)): ?>
    <section class="cart-empty" id="cartEmptyState">
      <div class="cart-empty-icon"><i class="fa-solid fa-bag-shopping"></i></div>
      <h2>Giỏ hàng đang trống</h2>
      <p>Khám phá thêm sản phẩm skincare phù hợp cho routine của bạn.</p>
      <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn btn-continue-outline">Tiếp tục mua sắm</a>
    </section>
  <?php else: ?>
    <div class="row g-4 align-items-start" id="cartLayout">
      <div class="col-lg-8">
        <section class="cart-shell">
          <div class="table-responsive cart-table-wrap">
            <table class="table cart-table align-middle mb-0">
              <thead>
                <tr>
                  <th class="text-center" style="width: 52px;">
                    <input class="form-check-input cart-check" type="checkbox" id="selectAllItems" checked aria-label="Chọn tất cả sản phẩm">
                  </th>
                  <th style="width: 96px;">Sản phẩm</th>
                  <th style="min-width: 260px;">Thông tin</th>
                  <th class="text-center" style="width: 180px;">Số lượng</th>
                  <th class="text-end" style="width: 130px;">Đơn giá</th>
                  <th class="text-end" style="width: 140px;">Tổng</th>
                  <th class="text-center" style="width: 80px;">Xóa</th>
                </tr>
              </thead>
              <tbody id="cartItems">
                <?php foreach ($items as $product_id => $item): ?>
                  <?php
                    $product = $item['product'] ?? [];
                    $qty = max(1, (int)($item['qty'] ?? 1));
                    $unitPrice = (float)($product['gia_ban'] ?? 0);
                    if ($unitPrice <= 0) {
                      $unitPrice = (float)($product['gia_goc'] ?? 0);
                    }
                    $lineTotal = $unitPrice * $qty;

                    $rawImage = (string)($product['link_hinh_anh'] ?? $product['hinh_anh'] ?? '');
                    $firstImage = resolve_image_url($rawImage);
                  ?>
                  <tr class="cart-item"
                      data-product-id="<?= h($product_id) ?>"
                      data-unit-price="<?= (int)round($unitPrice) ?>"
                      data-qty="<?= $qty ?>">
                    <td class="text-center">
                      <input class="form-check-input cart-check item-select" type="checkbox" checked aria-label="Chọn sản phẩm <?= h($product['ten_san_pham'] ?? 'Sản phẩm') ?>">
                    </td>
                    <td>
                      <?php if ($firstImage !== ''): ?>
                        <img class="cart-thumb"
                             src="<?= h($firstImage) ?>"
                             alt="<?= h($product['ten_san_pham'] ?? 'Sản phẩm') ?>"
                             loading="lazy"
                             referrerpolicy="no-referrer"
                             onerror="this.onerror=null;this.src='https://via.placeholder.com/92x92?text=No+Image';">
                      <?php else: ?>
                        <div class="cart-thumb cart-thumb--empty">No image</div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <a class="cart-product-name" href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($product_id) ?>">
                        <?= h($product['ten_san_pham'] ?? 'Sản phẩm') ?>
                      </a>
                      <div class="cart-product-meta">
                        <span><?= h($product['thuong_hieu'] ?? 'Thương hiệu chưa cập nhật') ?></span>
                      </div>
                    </td>
                    <td>
                      <div class="qty-control mx-auto">
                        <button type="button" class="qty-btn btn-qty-minus" aria-label="Giảm số lượng">−</button>
                        <input type="number" class="qty-input" value="<?= $qty ?>" min="1" max="999" inputmode="numeric" aria-label="Số lượng">
                        <button type="button" class="qty-btn btn-qty-plus" aria-label="Tăng số lượng">+</button>
                      </div>
                    </td>
                    <td class="text-end">
                      <span class="cart-price"><?= format_vnd($unitPrice) ?></span>
                    </td>
                    <td class="text-end">
                      <strong class="line-total"><?= format_vnd($lineTotal) ?></strong>
                    </td>
                    <td class="text-center">
                      <button type="button" class="btn-remove btn-delete" aria-label="Xóa sản phẩm">
                        <i class="fa-regular fa-trash-can"></i>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <div class="col-lg-4">
        <aside class="cart-summary" id="cartSummary">
          <h2 class="summary-title">Tóm tắt đơn hàng</h2>
          <div class="selected-info" id="selectedInfo">Đã chọn <strong id="selectedCount">0</strong> sản phẩm</div>

          <div class="free-ship-box" id="freeShipBox" data-threshold="<?= (int)$shippingThreshold ?>">
            <div class="free-ship-text" id="freeShipText">
              <?php if ($remainingToFreeShip > 0): ?>
                Mua thêm <strong><?= format_vnd($remainingToFreeShip) ?></strong> để được Miễn phí giao hàng
              <?php else: ?>
                Bạn đã được miễn phí giao hàng
              <?php endif; ?>
            </div>
            <div class="progress free-ship-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $freeShipPercent ?>">
              <div class="progress-bar" id="freeShipProgressBar" style="width: <?= $freeShipPercent ?>%"></div>
            </div>
          </div>

          <div class="summary-row">
            <span>Tạm tính</span>
            <strong id="subtotalValue"><?= format_vnd($total) ?></strong>
          </div>
          <div class="summary-row">
            <span>Phí vận chuyển</span>
            <strong id="shippingValue"><?= $remainingToFreeShip > 0 ? format_vnd(30000) : 'Miễn phí' ?></strong>
          </div>
          <div class="summary-divider"></div>
          <div class="summary-row summary-total">
            <span>Tổng cộng</span>
            <strong id="totalValue"><?= format_vnd($remainingToFreeShip > 0 ? $total + 30000 : $total) ?></strong>
          </div>

          <button type="button" class="btn btn-checkout-gradient w-100" id="checkoutButton">Tiếp tục (Thanh toán)</button>
          <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn btn-continue-outline w-100 mt-2">Tiếp tục mua sắm</a>
        </aside>
      </div>
    </div>
  <?php endif; ?>
</div>

<style>
  .c  .cart-count-pill {
    padding: 4px 10px;
    border-radius: 4px;
    background: #EBF2EE;
    color: #183B2B;
    font-size: 13px;
    font-weight: 600;
  }

  .cart-shell {
    background: #fff;
    border-radius: 12px;
    border: 1px solid var(--border);
    overflow: hidden;
  }

  .cart-check {
    width: 18px;
    height: 18px;
    cursor: pointer;
    border-color: var(--border);
  }

  .cart-check:checked {
    background-color: #183B2B;
    border-color: #183B2B;
  }

  .cart-table-wrap {
    overflow-x: auto;
  }

  .cart-table thead th {
    border-bottom: 1px solid var(--border);
    font-size: 12px;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: #64748B;
    font-weight: 700;
    padding: 14px 16px;
    white-space: nowrap;
    background: #FAFAFA;
  }

  .cart-table tbody td {
    padding: 16px;
    border-bottom: 1px solid #F1F5F9;
    vertical-align: middle;
  }

  .cart-item {
    transition: opacity .28s ease, transform .28s ease;
  }

  .cart-item.is-removing {
    opacity: 0;
    transform: translateX(-8px);
  }

  .cart-thumb {
    width: 72px;
    height: 72px;
    border-radius: 8px;
    object-fit: cover;
    border: 1px solid var(--border);
    background: #FAFAFA;
  }

  .cart-thumb--empty {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    color: #64748B;
    background: #FAFAFA;
  }

  .cart-product-name {
    color: #0F172A;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s ease;
  }

  .cart-product-name:hover {
    color: #183B2B;
  }

  .cart-product-meta {
    margin-top: 4px;
    color: #64748B;
    font-size: 13px;
    font-weight: 500;
  }

  .qty-control {
    width: 120px;
    border: 1px solid var(--border);
    border-radius: 6px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #FAFAFA;
  }

  .qty-btn {
    border: 0;
    background: #F1F5F9;
    color: #0F172A;
    font-weight: 700;
    width: 32px;
    height: 32px;
    line-height: 32px;
    padding: 0;
    transition: background 0.2s ease;
  }

  .qty-btn:hover {
    background: #183B2B;
    color: #FFFFFF;
  }

  .qty-btn:disabled {
    opacity: .45;
    cursor: not-allowed;
  }

  .qty-input {
    border: 0;
    width: 48px;
    text-align: center;
    font-weight: 600;
    color: #0F172A;
    background: transparent;
  }

  .qty-input:focus {
    outline: none;
  }

  .cart-price,
  .line-total {
    color: #183B2B;
    font-weight: 700;
    font-size: 14px;
    font-variant-numeric: tabular-nums;
  }

  .btn-remove {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: 1px solid #FECDD3;
    color: #E11D48;
    background: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
  }

  .btn-remove:hover {
    background: #FFE4E6;
  }

  .cart-summary {
    background: #fff;
    border-radius: 12px;
    border: 1px solid var(--border);
    padding: 20px;
  }kground: #FFE4E6;
  }

  .cart-summary {
    background: #fff;
    border-radius: 20px;
    border: 1px solid #E2EADF;
    box-shadow: 0 10px 30px rgba(45, 90, 39, 0.05);
    padding: 22px;
    position: sticky;
    top: 18px;
  }

  .summary-title {
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 14px;
    color: #1A2F1A;
  }

  .selected-info {
    margin-top: -6px;
    margin-bottom: 12px;
    color: #5C705E;
    font-size: 13px;
    font-weight: 600;
  }

  .free-ship-box {
    background: linear-gradient(120deg, #F0F4F1 0%, #EAF0EB 100%);
    border: 1px solid #E2EADF;
    border-radius: 14px;
    padding: 14px;
    margin-bottom: 16px;
  }

  .free-ship-text {
    font-size: 13px;
    color: #1A2F1A;
    font-weight: 600;
    margin-bottom: 8px;
  }

  .free-ship-progress {
    height: 8px;
    border-radius: 999px;
    background: #E2EADF;
  }

  .free-ship-progress .progress-bar {
    border-radius: 999px;
    background: linear-gradient(120deg, #2D5A27 0%, #4A7C59 100%);
  }

  .summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 14px;
    font-weight: 600;
    color: #1A2F1A;
    margin-bottom: 10px;
  }

  .summary-divider {
    height: 1px;
    background: #ecf2f8;
    margin: 12px 0;
  }

  .summary-total {
    font-size: 16px;
    color: #0f2238;
  }

  .summary-total strong {
    color: #0f6b3e;
  }

  .btn-checkout-gradient {
    border: 0;
    border-radius: 12px;
    color: #fff;
    font-weight: 800;
    padding: 12px 14px;
    background: linear-gradient(120deg, #16a34a 0%, #0ea5a5 50%, #3b82f6 100%);
    box-shadow: 0 14px 24px rgba(37, 99, 235, 0.22);
  }

  .btn-checkout-gradient:hover {
    color: #fff;
    filter: brightness(0.97);
  }

  .btn-checkout-gradient:disabled {
    cursor: not-allowed;
    opacity: .55;
    box-shadow: none;
    filter: grayscale(.1);
  }

  .btn-continue-outline {
    border-radius: 12px;
    border: 1px solid #c7d6e7;
    color: #2c475f;
    font-weight: 700;
    background: #fff;
  }

  .btn-continue-outline:hover {
    border-color: #9fb7cf;
    color: #17324c;
    background: #f8fbff;
  }

  .cart-empty {
    background: #fff;
    border-radius: 20px;
    border: 1px solid #e8eef4;
    box-shadow: 0 18px 35px rgba(15, 23, 42, 0.06);
    padding: 46px 20px;
    text-align: center;
  }

  .cart-empty-icon {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    margin: 0 auto 14px;
    background: #eef6ff;
    color: #48729e;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
  }

  .cart-empty h2 {
    font-size: 24px;
    color: #0f2238;
    font-weight: 800;
    margin-bottom: 8px;
  }

  .cart-empty p {
    color: #698096;
    margin-bottom: 16px;
  }

  @media (max-width: 991.98px) {
    .cart-summary {
      position: static;
    }

    .cart-table thead {
      display: none;
    }

    .cart-table,
    .cart-table tbody,
    .cart-table tr,
    .cart-table td {
      display: block;
      width: 100%;
    }

    .cart-table tr {
      border-bottom: 1px solid #eef3f8;
      padding: 8px 0;
    }

    .cart-table td {
      border: 0;
      padding-top: 8px;
      padding-bottom: 8px;
      text-align: left !important;
    }

    .qty-control {
      margin-left: 0 !important;
    }
  }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const cartPage = document.getElementById('cartPage');
  const cartItemsEl = document.getElementById('cartItems');

  if (!cartPage || !cartItemsEl) {
    return;
  }

  const baseUrl = cartPage.dataset.baseUrl || '';
  const shippingThreshold = Number(cartPage.dataset.shippingThreshold || 0);

  const subtotalValue = document.getElementById('subtotalValue');
  const shippingValue = document.getElementById('shippingValue');
  const totalValue = document.getElementById('totalValue');
  const freeShipText = document.getElementById('freeShipText');
  const freeShipProgressBar = document.getElementById('freeShipProgressBar');
  const freeShipProgress = freeShipProgressBar ? freeShipProgressBar.closest('.progress') : null;
  const cartCountPill = document.getElementById('cartCountPill');
  const cartLayout = document.getElementById('cartLayout');
  const selectAllItems = document.getElementById('selectAllItems');
  const selectedCountEl = document.getElementById('selectedCount');
  const checkoutButton = document.getElementById('checkoutButton');

  function formatVnd(amount) {
    const safe = Number.isFinite(Number(amount)) ? Number(amount) : 0;
    return new Intl.NumberFormat('vi-VN').format(Math.max(0, Math.round(safe))) + ' ₫';
  }

  function getRowQty(row) {
    const input = row.querySelector('.qty-input');
    const value = parseInt(input ? input.value : '1', 10);
    return Math.min(999, Math.max(1, Number.isFinite(value) ? value : 1));
  }

  function getRowUnitPrice(row) {
    const unit = parseInt(row.dataset.unitPrice || '0', 10);
    return Number.isFinite(unit) ? Math.max(0, unit) : 0;
  }

  function setLineTotal(row) {
    const qty = getRowQty(row);
    const lineTotal = getRowUnitPrice(row) * qty;
    const lineTotalEl = row.querySelector('.line-total');
    if (lineTotalEl) {
      lineTotalEl.textContent = formatVnd(lineTotal);
    }
  }

  function computeTotals() {
    let subtotal = 0;
    const rows = Array.from(document.querySelectorAll('.cart-item'));
    let selectedCount = 0;

    rows.forEach((row) => {
      const rowCheck = row.querySelector('.item-select');
      if (!rowCheck || !rowCheck.checked) {
        return;
      }
      selectedCount += 1;
      const qty = getRowQty(row);
      subtotal += getRowUnitPrice(row) * qty;
    });

    const freeShipRemaining = Math.max(0, shippingThreshold - subtotal);
    const shippingFee = freeShipRemaining > 0 ? 30000 : 0;
    const total = subtotal + shippingFee;
    const progressPercent = shippingThreshold > 0 ? Math.min(100, Math.round((subtotal / shippingThreshold) * 100)) : 100;

    return {
      rowsCount: rows.length,
      selectedCount,
      subtotal,
      shippingFee,
      total,
      freeShipRemaining,
      progressPercent
    };
  }

  function renderSummary() {
    const totals = computeTotals();

    if (subtotalValue) subtotalValue.textContent = formatVnd(totals.subtotal);
    if (shippingValue) shippingValue.textContent = totals.shippingFee > 0 ? formatVnd(totals.shippingFee) : 'Miễn phí';
    if (totalValue) totalValue.textContent = formatVnd(totals.total);

    if (freeShipText) {
      if (totals.freeShipRemaining > 0) {
        freeShipText.innerHTML = 'Mua thêm <strong>' + formatVnd(totals.freeShipRemaining) + '</strong> để được Miễn phí giao hàng';
      } else {
        freeShipText.textContent = 'Bạn đã được miễn phí giao hàng';
      }
    }

    if (freeShipProgressBar) {
      freeShipProgressBar.style.width = totals.progressPercent + '%';
    }
    if (freeShipProgress) {
      freeShipProgress.setAttribute('aria-valuenow', String(totals.progressPercent));
    }
    if (cartCountPill) {
      cartCountPill.textContent = totals.rowsCount + ' sản phẩm';
    }

    if (selectedCountEl) {
      selectedCountEl.textContent = String(totals.selectedCount);
    }

    if (checkoutButton) {
      checkoutButton.disabled = totals.selectedCount === 0;
      checkoutButton.textContent = totals.selectedCount === 0
        ? 'Chọn sản phẩm để thanh toán'
        : 'Tiếp tục (Thanh toán)';
    }

    if (selectAllItems) {
      if (totals.rowsCount === 0) {
        selectAllItems.checked = false;
        selectAllItems.indeterminate = false;
      } else {
        selectAllItems.checked = totals.selectedCount === totals.rowsCount;
        selectAllItems.indeterminate = totals.selectedCount > 0 && totals.selectedCount < totals.rowsCount;
      }
    }
  }

  function setRowBusy(row, busy) {
    row.querySelectorAll('button, input').forEach((el) => {
      el.disabled = busy;
    });
    row.style.opacity = busy ? '0.7' : '1';
  }

  function postCartAction(action, productId, qty) {
    const params = new URLSearchParams();
    params.set('action', action);
    params.set('product_id', productId);
    if (typeof qty !== 'undefined') {
      params.set('qty', String(qty));
    }

    return fetch(baseUrl + '/index.php?r=giohang', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      },
      body: params.toString(),
    });
  }

  function getSelectedProductIds() {
    return Array.from(cartItemsEl.querySelectorAll('.cart-item')).filter((row) => {
      const checkbox = row.querySelector('.item-select');
      return !!checkbox && checkbox.checked;
    }).map((row) => String(row.dataset.productId || '').trim()).filter((id) => id !== '');
  }

  function submitSelectedItemsForCheckout() {
    const selectedIds = getSelectedProductIds();
    if (selectedIds.length === 0) {
      alert('Vui lòng chọn ít nhất 1 sản phẩm để thanh toán.');
      return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = baseUrl + '/index.php?r=chuandaithanhtoan';

    selectedIds.forEach((id) => {
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'selected_items[]';
      hidden.value = id;
      form.appendChild(hidden);
    });

    document.body.appendChild(form);
    form.submit();
  }

  function handleQtyChange(row, newQty) {
    const productId = row.dataset.productId;
    const input = row.querySelector('.qty-input');
    if (!productId || !input) return;

    const prevQty = getRowQty(row);
    const qty = Math.min(999, Math.max(1, Number(newQty) || 1));

    setRowBusy(row, true);
    postCartAction('update_qty', productId, qty)
      .then((response) => {
        if (!response.ok) {
          throw new Error('Update failed');
        }
        input.value = String(qty);
        row.dataset.qty = String(qty);
        setLineTotal(row);
        renderSummary();
      })
      .catch(() => {
        input.value = String(prevQty);
        row.dataset.qty = String(prevQty);
        setLineTotal(row);
        renderSummary();
        alert('Không thể cập nhật số lượng lúc này. Vui lòng thử lại.');
      })
      .finally(() => {
        setRowBusy(row, false);
      });
  }

  function handleDelete(row) {
    const productId = row.dataset.productId;
    if (!productId) return;

    setRowBusy(row, true);
    postCartAction('delete', productId)
      .then((response) => {
        if (!response.ok) {
          throw new Error('Delete failed');
        }
        row.classList.add('is-removing');
        window.setTimeout(() => {
          row.remove();
          renderSummary();

          if (!document.querySelector('.cart-item')) {
            if (cartLayout) {
              cartLayout.innerHTML = '<div class="col-12"><section class="cart-empty"><div class="cart-empty-icon"><i class="fa-solid fa-bag-shopping"></i></div><h2>Giỏ hàng đang trống</h2><p>Khám phá thêm sản phẩm skincare phù hợp cho routine của bạn.</p><a href="' + baseUrl + '/index.php?r=tatca" class="btn btn-continue-outline">Tiếp tục mua sắm</a></section></div>';
            }
          }
        }, 260);
      })
      .catch(() => {
        setRowBusy(row, false);
        alert('Không thể xóa sản phẩm lúc này. Vui lòng thử lại.');
      });
  }

  cartItemsEl.addEventListener('click', function (event) {
    const plusBtn = event.target.closest('.btn-qty-plus');
    const minusBtn = event.target.closest('.btn-qty-minus');
    const deleteBtn = event.target.closest('.btn-delete');

    if (plusBtn) {
      const row = plusBtn.closest('.cart-item');
      if (!row) return;
      handleQtyChange(row, getRowQty(row) + 1);
      return;
    }

    if (minusBtn) {
      const row = minusBtn.closest('.cart-item');
      if (!row) return;
      handleQtyChange(row, getRowQty(row) - 1);
      return;
    }

    if (deleteBtn) {
      const row = deleteBtn.closest('.cart-item');
      if (!row) return;
      handleDelete(row);
    }
  });

  cartItemsEl.addEventListener('change', function (event) {
    const itemSelect = event.target.closest('.item-select');
    if (itemSelect) {
      renderSummary();
      return;
    }

    const input = event.target.closest('.qty-input');
    if (!input) return;
    const row = input.closest('.cart-item');
    if (!row) return;
    handleQtyChange(row, input.value);
  });

  if (selectAllItems) {
    selectAllItems.addEventListener('change', function () {
      const checked = !!selectAllItems.checked;
      cartItemsEl.querySelectorAll('.item-select').forEach((checkbox) => {
        checkbox.checked = checked;
      });
      renderSummary();
    });
  }

  if (checkoutButton) {
    checkoutButton.addEventListener('click', function () {
      submitSelectedItemsForCheckout();
    });
  }

  cartItemsEl.addEventListener('blur', function (event) {
    const input = event.target.closest('.qty-input');
    if (!input) return;
    const row = input.closest('.cart-item');
    if (!row) return;
    handleQtyChange(row, input.value);
  }, true);

  document.querySelectorAll('.cart-item').forEach((row) => {
    setLineTotal(row);
  });
  renderSummary();
});
</script>
