<?php
$khachHang = $khachHang ?? [];
$skinProfile = $skinProfile ?? [];

$sensitivityOptions = [
  'Rất dễ' => 'Rất dễ kích ứng, hay mẩn đỏ',
  'Thỉnh thoảng' => 'Thỉnh thoảng mới kích ứng',
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

$currentSensitivity = trim((string)($khachHang['muc_do_nhay_cam'] ?? ''));
$currentIssuesText = trim((string)($khachHang['van_de_da'] ?? ''));
$currentGoal = trim((string)($khachHang['muc_tieu_cham_soc'] ?? ''));
$currentBudget = (int)($skinProfile['ngan_sach'] ?? $khachHang['ngan_sach'] ?? 0);
$currentBudgetKey = '';
if ($currentBudget > 0 && $currentBudget <= 200000) {
  $currentBudgetKey = 'duoi_200k';
} elseif ($currentBudget <= 500000 && $currentBudget > 0) {
  $currentBudgetKey = '200_500k';
} elseif ($currentBudget <= 1000000 && $currentBudget > 0) {
  $currentBudgetKey = '500_1000k';
} elseif ($currentBudget > 1000000) {
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

  .quick-survey-body {
    padding: 28px 34px 14px;
  }

  .quick-question {
    margin-bottom: 28px;
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
      </header>

      <form method="post" action="<?= BASE_URL ?>/index.php?r=xulykhaosatnhanh" id="quickSkinSurveyForm">
        <div class="quick-survey-body">
          <fieldset class="quick-question border-0 p-0">
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

          <fieldset class="quick-question border-0 p-0">
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

          <fieldset class="quick-question border-0 p-0">
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

          <fieldset class="quick-question border-0 p-0">
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
        </div>

        <footer class="quick-survey-foot">
          <a class="btn btn-outline-secondary px-4" href="<?= BASE_URL ?>/index.php?r=hoso">Để sau</a>
          <button class="btn btn-brand px-4" type="submit"><i class="fa-solid fa-check me-1"></i> Lưu cập nhật hôm nay</button>
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

    function hasIssue() {
      return form.querySelectorAll('input[name="q2[]"]:checked').length > 0;
    }

    form.addEventListener('change', function () {
      if (hasIssue()) issueError.classList.remove('is-visible');
    });

    form.addEventListener('submit', function (event) {
      if (hasIssue()) return;
      event.preventDefault();
      issueError.classList.add('is-visible');
      issueError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  })();
</script>
