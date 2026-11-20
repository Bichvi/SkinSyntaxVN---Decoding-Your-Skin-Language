<?php
if (!is_logged_in()) return;
$_SESSION['feature_feedback_csrf'] ??= bin2hex(random_bytes(24));
$feedbackLabels = [
    1 => 'Rất không hài lòng', 2 => 'Không hài lòng', 3 => 'Bình thường',
    4 => 'Hài lòng', 5 => 'Rất hài lòng',
];
?>
<details class="border rounded-3 bg-white p-3 my-3" data-feature-feedback>
  <summary class="fw-semibold"><?= h($feedbackPrompt) ?></summary>
  <form class="mt-3" action="<?= h(BASE_URL . '/index.php?r=feature_feedback') ?>" method="post" data-profile-async>
    <input type="hidden" name="feature" value="<?= h($feedbackFeature) ?>">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['feature_feedback_csrf']) ?>">
    <fieldset>
      <legend class="fs-6">Bạn hài lòng ở mức nào?</legend>
      <div class="d-flex flex-wrap gap-3">
        <?php foreach ($feedbackLabels as $value => $label): ?>
          <label class="d-inline-flex align-items-center gap-2">
            <input type="radio" name="rating" value="<?= $value ?>" required>
            <?= $value ?> · <?= h($label) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <label class="d-block my-3">
      Góp ý thêm (tùy chọn)
      <textarea class="form-control mt-1" name="comment" maxlength="500" rows="2"></textarea>
    </label>
    <p class="small text-muted">Bạn có thể bỏ qua. Khi gửi, SkinSyntax lưu mã tài khoản, vai trò, thời điểm và phản hồi để cải thiện hệ thống. Vui lòng tránh ghi thông tin cá nhân vào góp ý.</p>
    <button class="btn btn-brand" type="submit">Gửi đánh giá</button>
    <button class="btn btn-link" type="button" data-close-feedback>Bỏ qua</button>
    <p class="small mt-2 mb-0" data-profile-status role="status"></p>
  </form>
</details>
<script src="<?= h(BASE_URL . '/assets/js/profile-actions.js?v=1') ?>" defer></script>
