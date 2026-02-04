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
                <th>Sản phẩm</th>
                <th class="text-center">Số lượng</th>
                <th class="text-right">Giá</th>
                <th class="text-right">Tổng</th>
                <th class="text-center">Thao tác</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $total = 0;
              foreach (($items ?? []) as $product_id => $item): 
                $product = $item['product'];
                $qty = $item['qty'];
                $price = floatval($product['gia_goc'] ?? 0);
                $subtotal = $price * $qty;
                $total += $subtotal;
              ?>
                <tr>
                  <td>
                    <a href="<?= BASE_URL ?>/index.php?r=chitiet&id=<?= h($product_id) ?>" class="text-decoration-none text-dark">
                      <strong><?= h($product['ten_san_pham']) ?></strong>
                    </a>
                  </td>
                  <td class="text-center">
                    <input type="number" class="form-control form-control-sm" value="<?= (int)$qty ?>" min="1" style="width: 70px;">
                  </td>
                  <td class="text-right">
                    <span class="price"><?= number_format((int)$price, 0, ',', '.') ?> VND</span>
                  </td>
                  <td class="text-right">
                    <span class="price"><?= number_format((int)$subtotal, 0, ',', '.') ?> VND</span>
                  </td>
                  <td class="text-center">
                    <button class="btn btn-sm btn-danger" data-product="<?= h($product_id) ?>">Xóa</button>
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
</style>
