<?php
// Khảo sát da 30 giây: 2 câu hỏi inline (loại da + vấn đề da) cho người chưa có hồ sơ da.
// Biến vào (tùy chọn): $homeGuestSkinType (string|null), $homeGuestConcern (string) để chọn sẵn đáp án hiện tại.
// Gửi POST tới index.php?r=home_quick_profile (HomeController::quickProfile) rồi quay lại khối "Hợp với ... của bạn".
$qqSkinOptions = [
    'da_dau' => 'Da dầu',
    'da_kho' => 'Da khô',
    'da_nhay_cam' => 'Da nhạy cảm',
    'da_mun' => 'Da mụn',
    'moi_loai_da' => 'Da thường',
];
$qqConcernOptions = class_exists('HomeGraphRecommender') ? HomeGraphRecommender::CONCERN_NAMES : [];
$qqSkinCurrent = isset($homeGuestSkinType) && is_string($homeGuestSkinType) ? $homeGuestSkinType : '';
$qqConcernCurrent = isset($homeGuestConcern) && is_string($homeGuestConcern) ? $homeGuestConcern : '';
?>
<section class="mb-5 home-quick-quiz" id="home-quick-quiz" aria-labelledby="home-quick-quiz-title">
  <form method="post" action="<?= h(BASE_URL . '/index.php?r=home_quick_profile') ?>">
    <div class="mb-3">
      <span class="home-section-kicker">KHẢO SÁT NHANH</span>
      <h2 class="fw-bold m-0" id="home-quick-quiz-title" style="color: #0F172A; font-size: 1.45rem;">Khảo sát da 30 giây</h2>
      <span class="text-muted small" style="font-size: 0.82rem;">2 câu hỏi để SYNA gợi ý đúng làn da của bạn</span>
    </div>

    <fieldset class="home-quick-quiz__group">
      <legend class="home-quick-quiz__legend">1. Da bạn thuộc loại nào? <span class="home-quick-quiz__req">(bắt buộc)</span></legend>
      <div class="home-quick-quiz__chips">
        <?php foreach ($qqSkinOptions as $key => $label): ?>
          <span class="home-quick-quiz__chip">
            <input type="radio" name="skin" id="qq-skin-<?= h($key) ?>" value="<?= h($key) ?>" required<?= $qqSkinCurrent === $key ? ' checked' : '' ?>>
            <label for="qq-skin-<?= h($key) ?>"><?= h($label) ?></label>
          </span>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <fieldset class="home-quick-quiz__group">
      <legend class="home-quick-quiz__legend">2. Bạn đang quan tâm vấn đề gì nhất? <span class="home-quick-quiz__opt">(không bắt buộc)</span></legend>
      <div class="home-quick-quiz__chips">
        <?php foreach ($qqConcernOptions as $key => $label): ?>
          <span class="home-quick-quiz__chip">
            <input type="radio" name="concern" id="qq-concern-<?= h($key) ?>" value="<?= h($key) ?>"<?= $qqConcernCurrent === $key ? ' checked' : '' ?>>
            <label for="qq-concern-<?= h($key) ?>"><?= h($label) ?></label>
          </span>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
      <button type="submit" class="btn text-white fw-bold px-4 py-2" style="background: #183B2B; border-radius: 8px; font-size: 0.9rem;">Xem gợi ý cho da tôi</button>
      <a href="<?= h(BASE_URL . '/index.php?r=khaosat') ?>" class="fw-semibold text-decoration-none small" style="color: #183B2B;">Làm khảo sát đầy đủ &rarr;</a>
    </div>
  </form>
</section>
