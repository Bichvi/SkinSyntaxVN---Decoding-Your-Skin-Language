<?php
if (!is_logged_in()) return;

$feedbackFeature = trim((string)($feedbackFeature ?? ''));
$feedbackRole = current_role();
$feedbackTemplate = SurveyService::templateFor($feedbackFeature, $feedbackRole);
if ($feedbackTemplate === null) return;

$_SESSION['feature_feedback_csrf'] ??= bin2hex(random_bytes(24));
$feedbackAccountId = SurveyService::accountId(current_user() ?? []);
if ($feedbackAccountId === '' || session_status() !== PHP_SESSION_ACTIVE) return;
$feedbackInteractionId = SurveyService::issueInteraction($_SESSION, $feedbackTemplate, $feedbackRole, $feedbackAccountId);
$feedbackDialogId = 'survey-dialog-' . substr($feedbackInteractionId, 0, 10);
$surveyInviteSecret = ss_env('SURVEY_PARTICIPANT_SECRET', $_SESSION['feature_feedback_csrf']);
$surveyInviteKey = hash_hmac('sha256', implode(':', ['survey-invite', $feedbackRole, $feedbackAccountId, $feedbackTemplate['id'], $feedbackTemplate['version']]), $surveyInviteSecret);
$feedbackPrompt = trim((string)($feedbackPrompt ?? 'Bạn đánh giá trải nghiệm vừa rồi thế nào?'));
$surveyAssetBase = BASE_URL;
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(dirname(__DIR__, 3) . '/index.php') &&
    !str_ends_with($surveyAssetBase, '/frontend/public')) {
    $surveyAssetBase .= '/frontend/public';
}
?>
<div class="survey-invitation"
     data-survey-invitation
     data-survey-key="<?= h($surveyInviteKey) ?>"
     <?= $feedbackFeature === 'syna_chat' ? 'data-syna-survey hidden' : '' ?>
     data-survey-auto="<?= $feedbackFeature === 'recommendation' ? '1' : '0' ?>"
     data-survey-frequency-days="14">
  <button class="survey-invitation__trigger" type="button" data-survey-open aria-haspopup="dialog" aria-controls="<?= h($feedbackDialogId) ?>">
    <i class="bi bi-chat-square-heart" aria-hidden="true"></i>
    <span>Đánh giá trải nghiệm</span>
  </button>

  <dialog class="survey-dialog" id="<?= h($feedbackDialogId) ?>" data-survey-dialog aria-labelledby="<?= h($feedbackDialogId) ?>-title">
    <form class="survey-form" action="<?= h(BASE_URL . '/index.php?r=feature_feedback') ?>" method="post" data-survey-form novalidate>
      <input type="hidden" name="csrf" value="<?= h($_SESSION['feature_feedback_csrf']) ?>">
      <input type="hidden" name="interaction_id" value="<?= h($feedbackInteractionId) ?>">

      <header class="survey-dialog__header">
        <div>
          <h2 id="<?= h($feedbackDialogId) ?>-title"><?= h($feedbackPrompt) ?></h2>
          <p>Chọn mức đồng ý với từng câu. Góp ý của bạn giúp SkinSyntax cải thiện trải nghiệm.</p>
        </div>
        <button class="survey-dialog__close" type="button" data-survey-close aria-label="Đóng khảo sát">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </header>

      <div class="survey-progress" aria-live="polite">
        <div class="survey-progress__copy"><strong data-survey-progress-text>0/<?= count($feedbackTemplate['questions']) ?> câu</strong><span>Có thể bỏ qua bất kỳ lúc nào</span></div>
        <div class="survey-progress__track" aria-hidden="true"><span data-survey-progress-bar></span></div>
      </div>

      <div class="survey-form__body">
      <div class="survey-questions">
        <?php foreach ($feedbackTemplate['questions'] as $index => $question): ?>
          <fieldset class="survey-question" data-survey-question>
            <legend><span><?= $index + 1 ?></span><?= h($question['text']) ?></legend>
            <div class="survey-question__options">
              <?php foreach (SurveyService::SCALE as $value => $label): ?>
                <?php $inputId = $feedbackDialogId . '-' . strtolower($question['id']) . '-' . $value; ?>
                <input id="<?= h($inputId) ?>" type="radio" name="answers[<?= h($question['id']) ?>]" value="<?= $value ?>" required>
                <label for="<?= h($inputId) ?>" title="<?= h($label) ?>"><strong><?= $value ?></strong><span><?= h($label) ?></span></label>
              <?php endforeach; ?>
            </div>
          </fieldset>
        <?php endforeach; ?>
      </div>

      <label class="survey-comment">
        <span>Góp ý thêm <small>Không bắt buộc</small></span>
        <textarea name="comment" maxlength="1000" rows="3" placeholder="Điều gì giúp trải nghiệm tốt hơn? Không nhập thông tin cá nhân hoặc nội dung tư vấn nhạy cảm."></textarea>
      </label>
      </div>

      <p class="survey-form__error" data-survey-error role="alert" hidden></p>
      <div class="survey-form__actions">
        <button class="survey-button survey-button--secondary" type="button" data-survey-skip>Bỏ qua</button>
        <button class="survey-button survey-button--primary" type="submit" data-survey-submit>
          <span>Gửi đánh giá</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
        </button>
      </div>
    </form>

    <section class="survey-success" data-survey-success hidden aria-live="polite">
      <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
      <h2>Cảm ơn bạn đã phản hồi</h2>
      <p>Đánh giá đã được lưu cho đúng lượt trải nghiệm này.</p>
      <button class="survey-button survey-button--primary" type="button" data-survey-close>Đóng</button>
    </section>
  </dialog>
</div>
<link rel="stylesheet" href="<?= h($surveyAssetBase . '/assets/css/survey-feedback.css?v=' . (int)@filemtime(dirname(__DIR__, 2) . '/public/assets/css/survey-feedback.css')) ?>">
<script src="<?= h($surveyAssetBase . '/assets/js/survey-feedback.js?v=' . (int)@filemtime(dirname(__DIR__, 2) . '/public/assets/js/survey-feedback.js')) ?>" defer></script>
