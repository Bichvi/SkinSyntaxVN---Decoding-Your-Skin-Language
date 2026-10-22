<?php
// Khối "Bạn đang quan tâm vấn đề da nào?" — chip + lưới 8 SP (tái dùng home_graph_concern_grid.php).
$hgConcern = (isset($homeGraph) && is_array($homeGraph)) ? ($homeGraph['concern'] ?? null) : null;
if (!empty($hgConcern['products'])):
  $hgKeys = (array)($homeGraph['concern_keys'] ?? HomeGraphRecommender::CONCERN_NAMES);
  $hgActive = (string)($hgConcern['title_data']['concern']['key'] ?? '');
  if (!isset($hgKeys[$hgActive])) {
    $hgActive = (string)array_key_first($hgKeys);
  }
?>
  <section class="mb-5" id="home-graph-concern" data-base-url="<?= h(BASE_URL) ?>" aria-label="Sản phẩm theo vấn đề da">
    <div class="mb-3">
      <h3 class="fw-bold mb-1" style="color: #0F172A; font-size: 1.35rem;">Bạn đang quan tâm vấn đề da nào?</h3>
      <p class="text-muted mb-0" style="font-size: 0.86rem;">Chọn một vấn đề để xem sản phẩm có thành phần phù hợp.</p>
    </div>
    <div class="d-flex flex-nowrap gap-2 mb-3 pb-1" role="tablist" aria-label="Vấn đề da" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
      <?php foreach ($hgKeys as $ck => $cn): $sel = ((string)$ck === $hgActive); ?>
        <button type="button" role="tab" aria-selected="<?= $sel ? 'true' : 'false' ?>" data-concern-key="<?= h((string)$ck) ?>"
                class="btn btn-sm flex-shrink-0 <?= $sel ? 'btn-success' : 'btn-outline-secondary' ?>"
                style="border-radius: 999px; white-space: nowrap;"><?= h((string)$cn) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="row g-3" id="home-graph-concern-grid" aria-live="polite">
      <?php
        $concernKey = $hgActive;
        $concernProducts = $hgConcern['products'];
        $error = false;
        require __DIR__ . '/home_graph_concern_grid.php';
      ?>
    </div>
  </section>
<?php endif; ?>
