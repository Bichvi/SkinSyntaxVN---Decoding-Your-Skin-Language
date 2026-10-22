<?php
// Lưới 8 sản phẩm theo vấn đề da (dùng cho khối trang chủ và route home_concern).
// Biến vào: $concernKey (string, key hợp lệ), $concernProducts (array sản phẩm đã hydrate, có reason_text/reason_code),
//           $error (bool, tùy chọn), $renderHomeProductCard (closure từ home_product_card.php).
$concernKey = (string)($concernKey ?? '');
$concernProducts = isset($concernProducts) && is_array($concernProducts) ? $concernProducts : [];
$error = !empty($error);
$concernName = HomeGraphRecommender::CONCERN_NAMES[$concernKey] ?? '';
?>
<?php if ($error || !$concernProducts): ?>
  <div class="col-12 text-center py-4" data-concern-error>
    <p class="text-muted mb-2">Chưa tải được, thử lại</p>
    <button type="button" class="btn btn-sm btn-outline-success" data-retry>Thử lại</button>
  </div>
<?php else: ?>
  <?php foreach (array_slice($concernProducts, 0, 8) as $p): ?>
    <div class="col-6 col-md-3">
      <div data-rec-section="concern" data-rec-reason="<?= h((string)($p['reason_code'] ?? '')) ?>" class="h-100">
        <?php $renderHomeProductCard($p, $concernName, (string)($p['reason_text'] ?? '')); ?>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="col-12 text-end">
    <a class="text-decoration-none fw-semibold" style="color:#183B2B;" href="<?= h(BASE_URL . '/index.php?r=tatca&q=' . urlencode($concernName)) ?>">Xem tất cả →</a>
  </div>
<?php endif; ?>
