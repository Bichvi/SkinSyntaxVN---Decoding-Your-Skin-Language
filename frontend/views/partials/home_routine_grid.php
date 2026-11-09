<?php
// Lưới routine mẫu 4 bước (khối trang chủ + route home_routine).
// Biến vào: $routineStarter (list [step,label,product]), $routineError (bool, tùy chọn),
//           $renderHomeProductCard (closure từ home_product_card.php).
$routineStarter = isset($routineStarter) && is_array($routineStarter) ? $routineStarter : [];
$routineError = !empty($routineError);
?>
<?php if ($routineError): ?>
  <div class="col-12 text-center py-4" data-routine-error>
    <p class="text-muted mb-2">Không tải được, thử lại</p>
    <button type="button" class="btn btn-sm btn-outline-success" data-routine-retry>Thử lại</button>
  </div>
<?php elseif (count($routineStarter) < 2): ?>
  <div class="col-12 text-center py-4" data-routine-empty>
    <p class="text-muted mb-0">Chưa đủ sản phẩm</p>
  </div>
<?php else: ?>
  <?php $routineTotal = 0.0; $routineNo = 0; ?>
  <?php foreach ($routineStarter as $item): ?>
    <?php
      $product = (array)($item['product'] ?? []);
      $routineNo++;
      $routineTotal += is_numeric($product['gia_ban'] ?? null) ? (float)$product['gia_ban'] : 0.0;
    ?>
    <div class="col-6 col-md-3">
      <div class="d-flex flex-column h-100" data-rec-section="routine">
        <div class="mb-2 d-flex align-items-center gap-2">
          <span class="routine-step-num" style="flex: 0 0 auto;"><?= h(sprintf('%02d', $routineNo)) ?></span>
          <span class="text-uppercase fw-bold" style="font-size: 0.7rem; color: #183B2B; letter-spacing: 0.03em;">BƯỚC <?= h((string)$routineNo) ?> · <?= h((string)($item['label'] ?? '')) ?></span>
        </div>
        <div class="flex-grow-1">
          <?php $renderHomeProductCard($product, '', HomeCardReason::for($product), 'Vì sao gợi ý'); ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="col-12 text-end">
    <span class="fw-bold" style="color: #183B2B; font-size: 1rem;">Tổng routine: <?= h(vnd($routineTotal)) ?></span>
  </div>
<?php endif; ?>
