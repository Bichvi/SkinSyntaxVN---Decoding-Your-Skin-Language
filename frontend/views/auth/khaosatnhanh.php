<?php
$khachHang = $khachHang ?? [];
$skinProfile = $skinProfile ?? [];
$quickDraft = is_array($quickDraft ?? null) ? $quickDraft : [];

$sensitivityOptions = [
  'Rất dễ' => 'Rất dễ kích ứng, hay mẩn đỏ',
  'Khỏe mạnh, hiếm khi' => 'Khỏe mạnh, hiếm khi kích ứng',
];
$issueOptions = [
  'Mụn viêm, sưng đỏ',
  'Mụn ẩn, mụn đầu đen',
  'Lỗ chân lông to',
  'Thâm mụn, sạm nám, tàn nhang',
  'Lão hóa, nếp nhăn',
  'Da khô căng, bong tróc',
];
$goalOptions = [
  'Sạch mụn, giảm viêm',
  'Dưỡng sáng, mờ thâm nám',
  'Phục hồi màng bảo vệ da, cấp ẩm',
  'Chống lão hóa, trẻ hóa da',
];
$budgetOptions = [
  'duoi_200k' => 'Dưới 200.000đ',
  '200_500k' => '200.000đ - 500.000đ',
  '500_1000k' => '500.000đ - 1.000.000đ',
  'tren_1000k' => 'Trên 1.000.000đ',
];

$currentState = is_array($skinProfile['current_state'] ?? null) ? $skinProfile['current_state'] : [];
$currentSensitivity = trim((string)(array_key_exists('q1', $quickDraft) ? $quickDraft['q1'] : ($currentState['muc_do_nhay_cam'] ?? ($skinProfile['muc_do_nhay_cam'] ?? ($khachHang['muc_do_nhay_cam'] ?? '')))));
$draftIssues = is_array($quickDraft['q2'] ?? null) ? $quickDraft['q2'] : [];
$currentIssuesText = trim((string)(array_key_exists('q2', $quickDraft) ? implode(', ', array_map('strval', $draftIssues)) : ($currentState['van_de_da'] ?? ($skinProfile['van_de_da'] ?? ($khachHang['van_de_da'] ?? '')))));
$currentGoal = trim((string)(array_key_exists('q3', $quickDraft) ? $quickDraft['q3'] : ($currentState['muc_tieu_cham_soc'] ?? ($skinProfile['muc_tieu_cham_soc'] ?? ($khachHang['muc_tieu_cham_soc'] ?? '')))));
$currentBudget = array_key_exists('q4', $quickDraft) ? 0 : (int)($currentState['ngan_sach'] ?? ($skinProfile['ngan_sach'] ?? ($khachHang['ngan_sach'] ?? 0)));
$currentBudgetKey = array_key_exists('q4', $quickDraft) ? trim((string)$quickDraft['q4']) : '';
if ($currentBudgetKey === '' && $currentBudget > 0 && $currentBudget <= 200000) {
  $currentBudgetKey = 'duoi_200k';
} elseif ($currentBudgetKey === '' && $currentBudget <= 500000 && $currentBudget > 0) {
  $currentBudgetKey = '200_500k';
} elseif ($currentBudgetKey === '' && $currentBudget <= 1000000 && $currentBudget > 0) {
  $currentBudgetKey = '500_1000k';
} elseif ($currentBudgetKey === '' && $currentBudget > 1000000) {
  $currentBudgetKey = 'tren_1000k';
}
?>

<style>
  .quick-survey-page {
    max-width: 940px;
    margin: 0 auto;
  }

  .quick-survey-card {
    overflow: hidden;
    border: 1px solid #dce9e1;
    border-radius: 24px;
    background: linear-gradient(145deg, #fffdf9 0%, #f5fbf6 54%, #f5f9ff 100%);
    box-shadow: 0 18px 45px rgba(24, 59, 43, .12);
  }

  .quick-survey-hero {
    padding: 30px 34px 24px;
    color: #183b2b;
    background: rgba(255, 255, 255, .78);
    border-bottom: 1px solid rgba(144, 175, 155, .24);
  }

  .quick-survey-kicker {
    margin: 0 0 8px;
    color: #4a7c59;
    font-size: .75rem;
    font-weight: 800;
    letter-spacing: .14em;
    text-transform: uppercase;
  }

  .quick-survey-title {
    margin: 0 0 8px;
    color: #183b2b;
    font-size: clamp(1.8rem, 4vw, 2.65rem);
    font-weight: 800;
  }

  .quick-survey-subtitle {
    max-width: 680px;
    margin: 0;
    color: #5e7467;
    font-size: .98rem;
    line-height: 1.6;
  }

  .quick-survey-current {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 16px;
    padding: 8px 12px;
    border: 1px solid #c9dfd0;
    border-radius: 999px;
    color: #315b40;
    background: #f1faf3;
    font-size: .82rem;
    font-weight: 700;
  }

  .quick-progress {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 18px;
  }

  .quick-progress__track {
    flex: 1;
    height: 7px;
    overflow: hidden;
    border-radius: 999px;
    background: #e5eee8;
  }

  .quick-progress__bar {
    width: 25%;
    height: 100%;
    border-radius: inherit;
    background: #16734b;
    transition: width .2s ease;
  }

  .quick-progress__label {
    min-width: 58px;
    color: #4d6d5a;
    font-size: .78rem;
    font-weight: 800;
    text-align: right;
  }

  .quick-survey-body {
    padding: 28px 34px 14px;
  }

  .quick-question {
    margin-bottom: 28px;
  }

  .quick-question[hidden] {
    display: none;
  }

  .quick-permanent-choice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 0;
    padding: 12px 14px;
    border: 1px solid #d7e7dc;
    border-radius: 14px;
    color: #385344;
    background: #f5fbf6;
    font-size: .82rem;
    line-height: 1.45;
  }

  .quick-permanent-choice input {
    margin-top: 3px;
    accent-color: #16734b;
  }

  .quick-question:last-child {
    margin-bottom: 8px;
  }

  .quick-question-title {
    display: block;
    margin-bottom: 12px;
    color: #20392a;
    font-size: 1rem;
    font-weight: 800;
  }

  .quick-choice-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .quick-choice-label {
    display: block;
    height: 100%;
    margin: 0;
    cursor: pointer;
  }

  .quick-choice-input {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
  }

  .quick-choice-body {
    position: relative;
    display: flex;
    align-items: center;
    min-height: 58px;
    height: 100%;
    padding: 12px 40px 12px 14px;
    border: 1px solid #d6e5da;
    border-radius: 14px;
    color: #385344;
    background: #fff;
    font-size: .9rem;
    font-weight: 650;
    line-height: 1.4;
    transition: .2s ease;
  }

  .quick-choice-body::after {
    position: absolute;
    top: 50%;
    right: 14px;
    content: '✓';
    color: #198754;
    font-size: 1.1rem;
    font-weight: 900;
    opacity: 0;
    transform: translateY(-50%) scale(.65);
    transition: .2s ease;
  }

  .quick-choice-label:hover .quick-choice-body {
    border-color: #9bc8a8;
    box-shadow: 0 8px 18px rgba(24, 59, 43, .08);
    transform: translateY(-1px);
  }

  .quick-choice-input:checked + .quick-choice-body {
    border-color: #5fa975;
    color: #194c2b;
    background: #f2fbf3;
    box-shadow: 0 0 0 3px rgba(95, 169, 117, .14);
  }

  .quick-choice-input:checked + .quick-choice-body::after {
    opacity: 1;
    transform: translateY(-50%) scale(1);
  }

  .quick-survey-error {
    display: none;
    margin: 12px 0 0;
    color: #b42318;
    font-size: .85rem;
    font-weight: 700;
  }

  .quick-survey-error.is-visible {
    display: block;
  }

  .quick-survey-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 18px 34px 28px;
    border-top: 1px solid rgba(144, 175, 155, .24);
    background: rgba(255, 255, 255, .72);
  }

  .quick-survey-foot .btn {
    border-radius: 999px;
    font-weight: 750;
  }

  @media (max-width: 700px) {
    .quick-survey-hero,
    .quick-survey-body,
    .quick-survey-foot {
      padding-right: 18px;
      padding-left: 18px;
    }

    .quick-choice-grid {
      grid-template-columns: 1fr;
    }

    .quick-survey-foot {
      align-items: stretch;
      flex-direction: column-reverse;
    }

    .quick-survey-foot .btn {
      width: 100%;
    }
  }
</style>

<main class="container py-4 py-md-5">
  <div class="quick-survey-page">
    <div class="mb-3">
      <a class="text-decoration-none text-muted small fw-semibold" href="<?= BASE_URL ?>/index.php?r=hoso">&larr; Quay về hồ sơ của tôi</a>
    </div>

    <div class="quick-survey-card">
      <header class="quick-survey-hero">
        <p class="quick-survey-kicker">SkinSyntax · Daily skin check-in</p>
        <h1 class="quick-survey-title">Cập nhật da nhanh hôm nay</h1>
        <p class="quick-survey-subtitle">Da thay đổi theo thời tiết, sinh hoạt và routine. Trả lời 4 câu hỏi để gợi ý sản phẩm bám sát tình trạng hiện tại của bạn hơn.</p>
        <?php if (!empty($skinProfile['loai_da'])): ?>
          <div class="quick-survey-current"><i class="fa-solid fa-droplet"></i> Loại da nền hiện tại: <?= h((string)$skinProfile['loai_da']) ?> · Không thay đổi trong khảo sát nhanh</div>
        <?php endif; ?>
        <div class="quick-progress" aria-label="Tiến độ khảo sát">
          <div class="quick-progress__track"><div class="quick-progress__bar" id="quickSurveyProgress"></div></div>
          <span class="quick-progress__label" id="quickSurveyProgressLabel">Bước 1/4</span>
        </div>
      </header>

      <form method="post" action="<?= BASE_URL ?>/index.php?r=xulykhaosatnhanh" id="quickSkinSurveyForm">
        <input type="hidden" name="profile_id" value="<?= h((string)($skinProfile['profile_id'] ?? '')) ?>">
        <input type="hidden" name="quick_request_id" value="<?= h((string)($quickRequestId ?? '')) ?>">
        <div class="quick-survey-body">
          <fieldset class="quick-question border-0 p-0" data-quick-step="0">
            <legend class="quick-question-title">Câu 1. Da bạn hiện tại có dễ bị kích ứng, mẩn đỏ không?</legend>
            <div class="quick-choice-grid">
              <?php foreach ($sensitivityOptions as $value => $label): ?>
                <label class="quick-choice-label">
                  <input class="quick-choice-input" type="radio" name="q1" value="<?= h($value) ?>" <?= $currentSensitivity === $value ? 'checked' : '' ?> required>
                  <span class="quick-choice-body"><?= h($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <fieldset class="quick-question border-0 p-0" data-quick-step="1" hidden>
            <legend class="quick-question-title">Câu 2. Vấn đề da bạn đang muốn cải thiện nhất? <span class="text-muted fw-normal">(Có thể chọn nhiều)</span></legend>
            <div class="quick-choice-grid">
              <?php foreach ($issueOptions as $issue): ?>
                <label class="quick-choice-label">
                  <input class="quick-choice-input" type="checkbox" name="q2[]" value="<?= h($issue) ?>" <?= $currentIssuesText !== '' && str_contains($currentIssuesText, $issue) ? 'checked' : '' ?>>
                  <span class="quick-choice-body"><?= h($issue) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="quick-survey-error" id="quickSurveyIssueError">Vui lòng chọn ít nhất 1 vấn đề da.</p>
          </fieldset>

          <fieldset class="quick-question border-0 p-0" data-quick-step="2" hidden>
            <legend class="quick-question-title">Câu 3. Mục tiêu chăm sóc da ưu tiên nhất của bạn hiện tại?</legend>
            <div class="quick-choice-grid">
              <?php foreach ($goalOptions as $goal): ?>
                <label class="quick-choice-label">
                  <input class="quick-choice-input" type="radio" name="q3" value="<?= h($goal) ?>" <?= $currentGoal === $goal ? 'checked' : '' ?> required>
                  <span class="quick-choice-body"><?= h($goal) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <fieldset class="quick-question border-0 p-0" data-quick-step="3" hidden>
            <legend class="quick-question-title">Câu 4. Ngân sách tối đa cho 1 sản phẩm?</legend>
            <div class="quick-choice-grid">
              <?php foreach ($budgetOptions as $value => $label): ?>
                <label class="quick-choice-label">
                  <input class="quick-choice-input" type="radio" name="q4" value="<?= h($value) ?>" <?= $currentBudgetKey === $value ? 'checked' : '' ?> required>
                  <span class="quick-choice-body"><?= h($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <label class="quick-permanent-choice">
            <input type="checkbox" name="save_permanent" value="1" id="quickSavePermanent">
            <span><strong>Lưu thay đổi lâu dài vào hồ sơ này</strong><br>Không chọn nếu bạn chỉ muốn thử routine cho hôm nay. Khi chọn, SkinSyntax sẽ hỏi xác nhận trước khi ghi vào hồ sơ.</span>
          </label>
          <input type="hidden" name="confirm_permanent" value="0" id="quickConfirmPermanent">
        </div>

        <footer class="quick-survey-foot">
          <a class="btn btn-outline-secondary px-4" href="<?= BASE_URL ?>/index.php?r=hoso">Để sau</a>
          <div class="d-flex gap-2 flex-column flex-sm-row w-100 justify-content-end">
            <button class="btn btn-outline-secondary px-4" type="button" id="quickSurveyBack" hidden>Quay lại</button>
            <button class="btn btn-brand px-4" type="button" id="quickSurveyNext"><span>Tiếp tục</span> <i class="fa-solid fa-arrow-right ms-1"></i></button>
            <button class="btn btn-brand px-4" type="submit" id="quickSurveySubmit" hidden><i class="fa-solid fa-check me-1"></i> <span data-submit-label>Xem gợi ý cho phiên này</span></button>
          </div>
        </footer>
      </form>
    </div>
  </div>
</main>

<script>
  (function () {
    const form = document.getElementById('quickSkinSurveyForm');
    const issueError = document.getElementById('quickSurveyIssueError');
    if (!form || !issueError) return;

    const steps = Array.from(form.querySelectorAll('[data-quick-step]'));
    const back = document.getElementById('quickSurveyBack');
    const next = document.getElementById('quickSurveyNext');
    const submit = document.getElementById('quickSurveySubmit');
    const progress = document.getElementById('quickSurveyProgress');
    const progressLabel = document.getElementById('quickSurveyProgressLabel');
    const savePermanent = document.getElementById('quickSavePermanent');
    const confirmPermanent = document.getElementById('quickConfirmPermanent');
    let stepIndex = 0;

    function hasIssue() {
      return form.querySelectorAll('input[name="q2[]"]:checked').length > 0;
    }

    function isComplete(index) {
      if (index === 0) return !!form.querySelector('input[name="q1"]:checked');
      if (index === 1) return hasIssue();
      if (index === 2) return !!form.querySelector('input[name="q3"]:checked');
      if (index === 3) return !!form.querySelector('input[name="q4"]:checked');
      return false;
    }

    function renderStep() {
      steps.forEach(function (item, index) {
        const active = index === stepIndex;
        item.hidden = !active;
        item.querySelectorAll('input').forEach(function (input) { input.disabled = !active; });
      });
      const percent = Math.round(((stepIndex + 1) / steps.length) * 100);
      if (progress) progress.style.width = percent + '%';
      if (progressLabel) progressLabel.textContent = 'Bước ' + (stepIndex + 1) + '/' + steps.length;
      if (back) back.hidden = stepIndex === 0;
      if (next) next.hidden = stepIndex === steps.length - 1;
      if (submit) submit.hidden = stepIndex !== steps.length - 1;
      issueError.classList.remove('is-visible');
    }

    if (next) next.addEventListener('click', function () {
      if (!isComplete(stepIndex)) {
        if (stepIndex === 1) issueError.classList.add('is-visible');
        return;
      }
      if (stepIndex < steps.length - 1) { stepIndex += 1; renderStep(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    });
    if (back) back.addEventListener('click', function () {
      if (stepIndex > 0) { stepIndex -= 1; renderStep(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    });

    form.addEventListener('change', function () {
      if (hasIssue()) issueError.classList.remove('is-visible');
    });

    form.addEventListener('submit', function (event) {
      if (stepIndex !== steps.length - 1 || !steps.every(function (_, index) { return isComplete(index); })) {
        event.preventDefault();
        return;
      }
      steps.forEach(function (item) { item.querySelectorAll('input').forEach(function (input) { input.disabled = false; }); });
      if (savePermanent && savePermanent.checked) {
        const confirmed = window.confirm('Bạn có chắc muốn ghi check-in hôm nay vào hồ sơ da lâu dài không?');
        if (!confirmed) {
          event.preventDefault();
          savePermanent.checked = false;
          if (confirmPermanent) confirmPermanent.value = '0';
          return;
        }
        if (confirmPermanent) confirmPermanent.value = '1';
      }
      if (!hasIssue()) {
        event.preventDefault();
        issueError.classList.add('is-visible');
        issueError.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });

    form.addEventListener('submit', function () {
      const submit = document.getElementById('quickSurveySubmit');
      if (!submit || submit.disabled) return;
      submit.disabled = true;
      submit.setAttribute('aria-busy', 'true');
      const label = submit.querySelector('[data-submit-label]');
      if (label) label.textContent = 'Đang lưu…';
    });

    if (savePermanent) savePermanent.addEventListener('change', function () {
      if (!savePermanent.checked && confirmPermanent) confirmPermanent.value = '0';
    });
    renderStep();
  })();
</script>
