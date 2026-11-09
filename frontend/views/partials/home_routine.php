<?php
// Khối 3 "Routine gợi ý theo loại da" cho khách chưa có khảo sát (thay hộp trống).
// Biến vào: $routineStarter, $routineSkinType, $renderHomeProductCard (closure thẻ sản phẩm).
$routineStarter = isset($routineStarter) && is_array($routineStarter) ? $routineStarter : [];
$routineSkinType = isset($routineSkinType) && is_string($routineSkinType) ? $routineSkinType : 'da_dau';
$routineTabs = [
    'da_dau' => 'Da dầu',
    'da_kho' => 'Da khô',
    'da_nhay_cam' => 'Da nhạy cảm',
    'da_mun' => 'Da mụn',
    'moi_loai_da' => 'Da thường',
];
if (!isset($routineTabs[$routineSkinType])) {
    $routineSkinType = 'da_dau';
}
?>
<section id="home-routine" class="mb-5 p-4 bg-white border" style="border-radius: 16px; border-color: #E2E8F0 !important;" data-base-url="<?= h(BASE_URL) ?>">
  <div class="mb-3">
    <span class="text-uppercase fw-semibold small" style="color: #183B2B; letter-spacing: 0.05em; font-size: 0.72rem;">QUY TRÌNH CHĂM SÓC DA MẪU</span>
    <h2 class="fw-bold m-0" style="color: #0F172A; font-size: 1.4rem;">Routine gợi ý theo loại da</h2>
    <span class="text-muted small" style="font-size: 0.82rem;">Chọn loại da để xem bộ 4 bước làm sạch, đặc trị, dưỡng ẩm và chống nắng.</span>
  </div>

  <div class="d-flex flex-wrap gap-2 mb-3" role="tablist" aria-label="Loại da">
    <?php foreach ($routineTabs as $key => $label): ?>
      <?php $on = ($key === $routineSkinType); ?>
      <button type="button" role="tab" data-skin-key="<?= h($key) ?>" aria-selected="<?= $on ? 'true' : 'false' ?>"
              class="btn btn-sm <?= $on ? 'btn-success' : 'btn-outline-secondary' ?>" style="border-radius: 999px; font-size: 0.82rem;">
        <?= h($label) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <div id="home-routine-grid" class="row g-3" aria-live="polite">
    <?php require __DIR__ . '/home_routine_grid.php'; ?>
  </div>

  <div class="mt-3 pt-2 border-top text-center text-md-start" style="border-color: #F1F5F9 !important;">
    <span class="text-muted small me-1">Muốn routine chính xác hơn?</span>
    <a href="<?= h(BASE_URL . '/index.php?r=khaosat') ?>" class="fw-semibold text-decoration-none small" style="color: #183B2B;">Làm khảo sát da &rarr;</a>
  </div>
</section>
<script src="<?= h(BASE_URL . '/assets/js/home-routine.js?v=' . (int)@filemtime(dirname(__DIR__, 2) . '/public/assets/js/home-routine.js')) ?>" defer></script>
