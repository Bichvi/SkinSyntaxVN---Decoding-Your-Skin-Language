<?php
// Khối "Vì bạn đã xem" — chỉ render khi $homeGraph['seen'] có dữ liệu.
$hgSeen = (isset($homeGraph) && is_array($homeGraph)) ? ($homeGraph['seen'] ?? null) : null;
if (!empty($hgSeen['products'])):
  $hgSeedName = trim((string)($hgSeen['title_data']['seed']['name'] ?? ''));
  if (function_exists('mb_strimwidth')) {
    $hgSeedName = mb_strimwidth($hgSeedName, 0, 40, '…', 'UTF-8');
  } elseif (strlen($hgSeedName) > 40) {
    $hgSeedName = substr($hgSeedName, 0, 39) . '…';
  }
?>
  <section class="mb-5" id="home-graph-seen" aria-label="Gợi ý theo sản phẩm đã xem">
    <div class="mb-3">
      <h3 class="fw-bold mb-1" style="color: #0F172A; font-size: 1.35rem;">Vì bạn đã xem <?= h($hgSeedName) ?></h3>
      <p class="text-muted mb-0" style="font-size: 0.86rem;">Những sản phẩm có thành phần và công dụng gần với sản phẩm bạn vừa quan tâm.</p>
    </div>
    <div class="row g-3">
      <?php foreach (array_slice($hgSeen['products'], 0, 4) as $p): ?>
        <div class="col-6 col-md-3">
          <div data-rec-section="seen" data-rec-reason="<?= h((string)($p['reason_code'] ?? '')) ?>" class="h-100">
            <?php $renderHomeProductCard($p, 'Vì bạn đã xem', (string)($p['reason_text'] ?? '')); ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>
