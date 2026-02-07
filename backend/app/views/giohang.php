<?php
// backend/app/views/giohang.php
?>
<div class="container mt-4">
  <div class="row">
    <div class="col-lg-8">
      <h1 class="mb-4">Giỏ Hàng</h1>

      <?php if (empty($items ?? [])): ?>
        <div class="alert alert-info text-center py-5">
          <h5 class="mb-2">Giỏ hàng của bạn trống</h5>
          <p class="mb-3">Hãy thêm sản phẩm yêu thích vào giỏ hàng</p>
          <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn btn-brand">Tiếp tục mua sắm</a>
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th style="width: 80px;">Hình ảnh</th>
                <th>Sản phẩm</th>
                <th class="text-center" style="width: 120px;">Số lượng</th>
                <th class="text-right" style="width: 100px;">Giá</th>
                <th class="text-right" style="width: 120px;">Tổng</th>
                <th class="text-center" style="width: 80px;">Thao tác</th>
              </tr>
            </thead>
            <tbody id="cartItems">
              <?php 
              $total = 0;
              foreach (($items ?? []) as $product_id => $item): 
                $product = $item['product'];
                $qty = $item['qty'];
                $price = floatval($product['gia_goc'] ?? 0);
                $subtotal = $price * $qty;
                $total += $subtotal;
                // Lấy hình ảnh đầu tiên từ link_hinh_anh
                $images = !empty($product['link_hinh_anh']) ? explode(' | ', $product['link_hinh_anh']) : [];
                $image_url = !empty($images[0]) ? trim($images[0]) : '';
              ?>
                <tr class="cart-item" data-product-id="<?= h($product_id) ?>" data-price="<?= (int)$price ?>">
                  <td>
                    <?php if ($image_url): ?>
                      <img src="<?= h($image_url) ?>" alt="<?= h($product['ten_san_pham']) ?>" 
                           style="width: 70px; height: 70px; object-fit: cover; border-radius: 4px;">
                    <?php else: ?>
                      <div style="width: 70px; height: 70px; background: #e9ecef; border-radius: 4px; display: flex; align-items: center; justify-content: center;">
                        <small class="text-muted">Không có ảnh</small>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($product_id) ?>" class="text-decoration-none text-dark">
                      <strong><?= h($product['ten_san_pham']) ?></strong>
                    </a>
                    <br>
                    <small class="text-muted"><?= h($product['thuong_hieu'] ?? 'N/A') ?></small>
                  </td>
                  <td class="text-center">
                    <div class="input-group input-group-sm" style="width: 100px;">
                      <button class="btn btn-outline-secondary btn-qty-minus" type="button">−</button>
                      <input type="number" class="form-control text-center qty-input" value="<?= (int)$qty ?>" min="1" max="999">
                      <button class="btn btn-outline-secondary btn-qty-plus" type="button">+</button>
                    </div>
                  </td>
                  <td class="text-right">
                    <span class="price"><?= number_format((int)$price, 0, ',', '.') ?> VND</span>
                  </td>
                  <td class="text-right">
                    <span class="item-total price"><?= number_format((int)$subtotal, 0, ',', '.') ?> VND</span>
                  </td>
                  <td class="text-center">
                    <button class="btn btn-sm btn-danger btn-delete" type="button" title="Xóa sản phẩm">
                      <i class="fas fa-trash-alt"></i>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="alert alert-warning mt-4">
          <p class="mb-0"><strong>Lưu ý:</strong> Tính năng mua hàng đang được phát triển. Hiện tại bạn có thể xem lại sản phẩm yêu thích.</p>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-body">
          <h5 class="card-title">Tóm tắt đơn hàng</h5>
          <hr>
          <div class="d-flex justify-content-between mb-2">
            <span>Tạm tính:</span>
            <strong id="subtotal"><?php 
              $total = 0;
              if (!empty($items ?? [])) {
                foreach ($items as $item) {
                  $price = floatval($item['product']['gia_goc'] ?? 0);
                  $total += $price * $item['qty'];
                }
              }
              echo number_format((int)$total, 0, ',', '.') . ' VND';
            ?></strong>
          </div>
          <div class="d-flex justify-content-between mb-2">
            <span>Phí vận chuyển:</span>
            <strong>Miễn phí</strong>
          </div>
          <hr>
          <div class="d-flex justify-content-between mb-3">
            <strong>Tổng cộng:</strong>
            <strong class="text-brand" id="total"><?php 
              echo number_format((int)$total, 0, ',', '.') . ' VND';
            ?></strong>
          </div>
          <button class="btn btn-brand w-100 disabled" disabled>
            Tiếp tục (Đang phát triển)
          </button>
          <a href="<?= BASE_URL ?>/index.php?r=tatca" class="btn btn-outline-secondary w-100 mt-2">
            Tiếp tục mua sắm
          </a>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  .table-responsive table td {
    vertical-align: middle;
  }
  .price {
    color: var(--color-brand, #e74c3c);
    font-weight: 500;
  }
  .btn-brand {
    background-color: #e74c3c;
    color: white;
    border-color: #e74c3c;
  }
  .btn-brand:hover {
    background-color: #c0392b;
    border-color: #c0392b;
  }
  .text-brand {
    color: #e74c3c;
  }
  .input-group-sm .btn {
    padding: 0.25rem 0.4rem;
    font-size: 0.875rem;
  }
  .qty-input {
    text-align: center !important;
    font-weight: bold;
  }
  .cart-item {
    transition: background-color 0.2s;
  }
  .cart-item:hover {
    background-color: #f9f9f9;
  }
  .btn-delete {
    padding: 0.375rem 0.75rem;
  }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Format số tiền
  function formatPrice(num) {
    return num.toLocaleString('vi-VN');
  }

  // Cập nhật tổng giỏ hàng
  function updateTotal() {
    let total = 0;
    document.querySelectorAll('.cart-item').forEach(row => {
      const itemTotal = row.querySelector('.item-total');
      const text = itemTotal.textContent.replace(/[^\d]/g, '');
      total += parseInt(text) || 0;
    });
    
    document.getElementById('subtotal').textContent = formatPrice(total) + ' VND';
    document.getElementById('total').textContent = formatPrice(total) + ' VND';
  }

  // Cập nhật tổng từng sản phẩm
  function updateItemTotal(row) {
    const price = parseInt(row.dataset.price);
    const qty = parseInt(row.querySelector('.qty-input').value) || 1;
    const subtotal = price * qty;
    row.querySelector('.item-total').textContent = formatPrice(subtotal) + ' VND';
    updateTotal();
  }

  // Xử lý thay đổi số lượng
  document.querySelectorAll('.qty-input').forEach(input => {
    input.addEventListener('change', function() {
      const row = this.closest('.cart-item');
      const productId = row.dataset.productId;
      const newQty = parseInt(this.value) || 1;
      
      if (newQty < 1) this.value = 1;
      if (newQty > 999) this.value = 999;
      
      updateItemTotal(row);
      updateCart(productId, newQty);
    });

    input.addEventListener('input', function() {
      if (this.value) {
        const row = this.closest('.cart-item');
        updateItemTotal(row);
      }
    });
  });

  // Nút tăng số lượng
  document.querySelectorAll('.btn-qty-plus').forEach(btn => {
    btn.addEventListener('click', function() {
      const input = this.closest('.input-group').querySelector('.qty-input');
      input.value = Math.min(parseInt(input.value) + 1, 999);
      input.dispatchEvent(new Event('change'));
    });
  });

  // Nút giảm số lượng
  document.querySelectorAll('.btn-qty-minus').forEach(btn => {
    btn.addEventListener('click', function() {
      const input = this.closest('.input-group').querySelector('.qty-input');
      input.value = Math.max(parseInt(input.value) - 1, 1);
      input.dispatchEvent(new Event('change'));
    });
  });

  // Xóa sản phẩm
  document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', function() {
      const row = this.closest('.cart-item');
      const productId = row.dataset.productId;
      
      if (confirm('Bạn chắc chắn muốn xóa sản phẩm này khỏi giỏ hàng?')) {
        deleteFromCart(productId, row);
      }
    });
  });

  // Gửi request cập nhật giỏ hàng lên server
  function updateCart(productId, qty) {
    fetch('<?= BASE_URL ?>/index.php?r=giohang', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: 'action=update_qty&product_id=' + productId + '&qty=' + qty
    })
    .catch(err => console.error('Lỗi:', err));
  }

  // Xóa sản phẩm khỏi giỏ hàng
  function deleteFromCart(productId, row) {
    fetch('<?= BASE_URL ?>/index.php?r=giohang', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: 'action=delete&product_id=' + productId
    })
    .then(response => {
      if (response.ok) {
        row.style.opacity = '0.5';
        setTimeout(() => {
          row.remove();
          if (document.querySelectorAll('.cart-item').length === 0) {
            location.reload();
          } else {
            updateTotal();
          }
        }, 300);
      }
    })
    .catch(err => console.error('Lỗi:', err));
  }
});
</script>
