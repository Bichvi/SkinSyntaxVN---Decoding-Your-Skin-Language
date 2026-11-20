<?php
// Khối "Hợp với {loại da} của bạn" — chỉ render khi $homeGraph['skin'] có dữ liệu.
$hgSkin = (isset($homeGraph) && is_array($homeGraph)) ? ($homeGraph['skin'] ?? null) : null;
$hgHasSkin = !empty($hgSkin['products']);
// Khảo sát nhanh: hiện khi chưa có hồ sơ da (không có khối kết quả), hoặc khi bấm "Đổi loại da" (?quiz=1).
$hgShowQuiz = empty($hasSurvey) && (!$hgHasSkin || (($_GET['quiz'] ?? '') === '1'));
if ($hgShowQuiz) {
    include __DIR__ . '/home_quick_quiz.php';
}
if ($hgHasSkin):
  $hgSkinRaw = trim((string)($hgSkin['title_data']['skin_type']['name'] ?? ''));
  $hgSkinName = function_exists('mb_strtolower') ? mb_strtolower($hgSkinRaw, 'UTF-8') : strtolower($hgSkinRaw);
  $hgSkinConcern = trim((string)($hgSkin['title_data']['concern']['name'] ?? ''));
?>
  <section class="mb-5" id="home-graph-skin" aria-label="Gợi ý hợp với loại da của bạn">
    <div class="mb-3">
      <h3 class="fw-bold mb-1" style="color: #0F172A; font-size: 1.35rem;">Hợp với <?= h($hgSkinName) ?> của bạn</h3>
      <?php if (!empty($homeSkinFromQuiz)): ?>
        <a href="<?= h(BASE_URL . '/index.php?quiz=1#home-quick-quiz') ?>" class="small fw-semibold text-decoration-none" style="color: #183B2B;">Đổi loại da</a>
      <?php endif; ?>
      <?php if ($hgSkinConcern !== ''): ?>
        <p class="text-muted mb-0" style="font-size: 0.86rem;">Ưu tiên vấn đề da: <?= h($hgSkinConcern) ?></p>
      <?php endif; ?>
    </div>
    <div class="row g-3">
      <?php foreach (array_slice($hgSkin['products'], 0, 4) as $p): ?>
        <div class="col-6 col-md-3">
          <div data-rec-section="skin" data-rec-reason="<?= h((string)($p['reason_code'] ?? '')) ?>" class="h-100">
            <?php $renderHomeProductCard($p, '',(string)($p['reason_text'] ?? '')); ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>
