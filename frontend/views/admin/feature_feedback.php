<?php
$summary = $summary ?? ['participants' => 0, 'surveys' => 0, 'answers' => 0, 'satisfaction_rate' => 0, 'distribution' => [], 'criteria' => [], 'groups' => [], 'comments' => [], 'legacy_surveys' => 0];
$surveyFilters = $surveyFilters ?? ['from' => '', 'to' => '', 'feature' => '', 'role' => '', 'version' => ''];
$surveyTemplates = $surveyTemplates ?? [];
$featureLabels = [];
foreach ($surveyTemplates as $template) $featureLabels[$template['feature']] = $template['feature_label'];
$featureLabels += ['recommendation' => 'Gợi ý sản phẩm', 'customer_account' => 'Tài khoản khách hàng', 'admin_workspace' => 'Không gian quản trị', 'staff_workspace' => 'Công cụ nhân viên'];
$roleLabels = ['khach_hang' => 'Khách hàng', 'admin' => 'Quản trị viên', 'nhanvien' => 'Nhân viên', 'unknown' => 'Không xác định'];
$criteriaGroups = [];
foreach ($summary['criteria'] as $criterion) {
    $groupLabel = ($roleLabels[$criterion['role']] ?? $criterion['role']) . ' · '
        . ($featureLabels[$criterion['feature']] ?? $criterion['feature']) . ' · v' . (int)$criterion['version'];
    $criteriaGroups[$groupLabel][] = $criterion;
}
$filterQuery = array_filter($surveyFilters, static fn($value) => $value !== '');
$exportUrl = static function (string $scope) use ($filterQuery): string {
    return BASE_URL . '/index.php?' . http_build_query(['r' => 'admin_feature_feedback', 'format' => 'csv', 'scope' => $scope] + $filterQuery);
};
$jsonUrl = BASE_URL . '/index.php?' . http_build_query(['r' => 'admin_feature_feedback', 'format' => 'json'] + $filterQuery);
$surveyAssetBase = BASE_URL;
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(dirname(__DIR__, 3) . '/index.php') &&
    !str_ends_with($surveyAssetBase, '/frontend/public')) {
    $surveyAssetBase .= '/frontend/public';
}
$barSegments = static function (array $distribution): string {
    $total = array_sum($distribution);
    $html = '';
    for ($score = 1; $score <= 5; $score++) {
        $count = (int)($distribution[$score] ?? 0);
        $width = $total > 0 ? round(100 * $count / $total, 2) : 0;
        if ($width <= 0) continue;
        $html .= '<span class="survey-likert__segment is-' . $score . '" style="width:' . $width . '%" title="Mức ' . $score . ': ' . $count . ' (' . $width . '%)"></span>';
    }
    return $html;
};
?>
<link rel="stylesheet" href="<?= h($surveyAssetBase . '/assets/css/survey-dashboard.css?v=' . (int)@filemtime(dirname(__DIR__, 2) . '/public/assets/css/survey-dashboard.css')) ?>">

<main class="admin-content survey-dashboard">
  <header class="survey-dashboard__heading">
    <div>
      <h1>Khảo sát &amp; đánh giá</h1>
      <p>Dữ liệu Likert phản ánh trải nghiệm người dùng, không phải độ chính xác của thuật toán gợi ý.</p>
    </div>
    <div class="survey-dashboard__exports">
      <a href="<?= h($exportUrl('summary')) ?>"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> CSV tổng hợp</a>
      <a href="<?= h($exportUrl('responses')) ?>"><i class="bi bi-download" aria-hidden="true"></i> CSV phản hồi</a>
      <a href="<?= h($jsonUrl) ?>"><i class="bi bi-braces" aria-hidden="true"></i> JSON</a>
    </div>
  </header>

  <form class="survey-filter" method="get" action="<?= h(BASE_URL . '/index.php') ?>">
    <input type="hidden" name="r" value="admin_feature_feedback">
    <label>Từ ngày<input type="date" name="from" value="<?= h($surveyFilters['from']) ?>"></label>
    <label>Đến ngày<input type="date" name="to" value="<?= h($surveyFilters['to']) ?>"></label>
    <label>Chức năng<select name="feature"><option value="">Tất cả</option><?php foreach ($featureLabels as $value => $label): ?><option value="<?= h($value) ?>" <?= $surveyFilters['feature'] === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
    <label>Vai trò<select name="role"><option value="">Tất cả</option><?php foreach (array_slice($roleLabels, 0, 3, true) as $value => $label): ?><option value="<?= h($value) ?>" <?= $surveyFilters['role'] === $value ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
    <label>Phiên bản<select name="version">
      <?php foreach (['2' => 'v2 · Khảo sát hiện tại', '1' => 'v1 · Lịch sử', 'all' => 'Tất cả'] as $value => $label): ?>
        <option value="<?= h((string)$value) ?>" <?= $surveyFilters['version'] === (string)$value ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select></label>
    <div class="survey-filter__actions"><button type="submit">Áp dụng</button><a href="<?= h(BASE_URL . '/index.php?r=admin_feature_feedback') ?>">Đặt lại</a></div>
  </form>

  <dl class="survey-kpi-strip">
    <div><dt><?= $surveyFilters['version'] === 'all' ? 'Mã người tham gia duy nhất' : 'Người tham gia duy nhất' ?></dt><dd><?= number_format((int)$summary['participants'], 0, ',', '.') ?></dd></div>
    <div><dt>Lượt khảo sát hợp lệ</dt><dd><?= number_format((int)$summary['surveys'], 0, ',', '.') ?></dd></div>
    <div><dt>Câu trả lời</dt><dd><?= number_format((int)$summary['answers'], 0, ',', '.') ?></dd></div>
    <div><dt>Điểm trung bình các câu trả lời</dt><dd><?= isset($summary['average']) ? number_format($summary['average'], 2, ',', '.') : '—' ?></dd></div>
    <div><dt>Câu trả lời mức 4–5</dt><dd><?= $summary['answers'] ? number_format($summary['satisfaction_rate'], 1, ',', '.') . '%' : '—' ?></dd><small><?= (int)($summary['positive_count'] ?? 0) ?>/<?= (int)$summary['answers'] ?> câu trả lời</small></div>
  </dl>
  <?php if ($surveyFilters['version'] === 'all'): ?>
    <p class="survey-notice">Khi xem mọi phiên bản, số mã định danh có thể khác số người duy nhất. Các chỉ số chung mô tả câu trả lời trong bộ lọc. Dùng hàng theo câu hỏi và phiên bản để phân tích nghiên cứu.</p>
  <?php endif; ?>

  <?php if ((int)$summary['surveys'] === 0): ?>
    <section class="survey-empty">
      <i class="bi bi-chat-square-text" aria-hidden="true"></i>
      <h2>Chưa có phản hồi trong phạm vi đang lọc</h2>
      <p>Thử mở rộng khoảng thời gian hoặc bắt đầu mời người dùng thật sau khi họ hoàn thành một trải nghiệm.</p>
    </section>
  <?php else: ?>
    <?php if ((int)$summary['legacy_surveys'] > 0): ?>
      <p class="survey-notice"><i class="bi bi-info-circle" aria-hidden="true"></i> Có <?= number_format((int)$summary['legacy_surveys'], 0, ',', '.') ?> lượt phiên bản 1, mỗi lượt có một câu trả lời tổng hợp. Một người có thể mang hai mã nếu đã trả lời cả v1 và v2. Lọc theo phiên bản để đếm người trong từng đợt.</p>
    <?php endif; ?>

    <div class="survey-dashboard__grid">
      <section class="survey-panel survey-panel--wide">
        <header><div><h2>Điểm theo từng tiêu chí</h2><p>Mỗi hàng giữ nguyên question ID và phiên bản để tránh gộp các thang đo không tương đương.</p></div></header>
        <div class="survey-criteria">
          <?php $groupIndex = 0; foreach ($criteriaGroups as $groupLabel => $items): ?>
          <details class="survey-question-group" <?= $groupIndex++ === 0 ? 'open' : '' ?>>
            <summary><?= h($groupLabel) ?> <span><?= count($items) ?> câu hỏi</span></summary>
          <?php foreach ($items as $criterion): ?>
            <article class="survey-criterion">
              <div class="survey-criterion__copy">
                <strong><?= h($criterion['criterion_label']) ?></strong>
                <span><?= h($featureLabels[$criterion['feature']] ?? $criterion['feature']) ?> · <?= h($roleLabels[$criterion['role']] ?? $criterion['role']) ?> · v<?= (int)$criterion['version'] ?> · <?= h($criterion['question_id']) ?></span>
                <p><?= h($criterion['question_text']) ?></p>
              </div>
              <div class="survey-criterion__score"><strong><?= number_format($criterion['average'], 2, ',', '.') ?></strong><span>Trung vị <?= number_format($criterion['median'], 1, ',', '.') ?> · n = <?= (int)$criterion['count'] ?></span></div>
              <div><div class="survey-likert" aria-hidden="true"><?= $barSegments($criterion['distribution']) ?></div>
                <table class="survey-rating-table">
                  <caption class="visually-hidden">Phân bố <?= h($criterion['question_id']) ?></caption>
                  <thead><tr><?php foreach (SurveyService::SCALE as $score => $label): ?><th scope="col" title="<?= h($label) ?>"><?= $score ?></th><?php endforeach; ?></tr></thead>
                  <tbody><tr><?php foreach ($criterion['distribution'] as $count): ?><td><?= (int)$count ?> <small>(<?= number_format(100 * $count / $criterion['count'], 1, ',', '.') ?>%)</small></td><?php endforeach; ?></tr></tbody>
                </table>
              </div>
              <div class="survey-criterion__rate"><?= (int)$criterion['positive_count'] ?>/<?= (int)$criterion['count'] ?> mức 4–5</div>
            </article>
          <?php endforeach; ?>
          </details>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="survey-dashboard__side">
      <section class="survey-panel">
        <header><div><h2>Phân bố 5 mức Likert</h2><p>Tất cả câu trả lời hợp lệ trong bộ lọc hiện tại.</p></div></header>
        <div class="survey-distribution">
          <?php $distributionTotal = max(1, array_sum($summary['distribution'])); ?>
          <?php foreach (SurveyService::SCALE as $score => $label): $count = (int)($summary['distribution'][$score] ?? 0); ?>
            <div><span class="survey-distribution__label"><strong><?= $score ?></strong><?= h($label) ?></span><span class="survey-distribution__track"><i class="is-<?= $score ?>" style="width:<?= round(100 * $count / $distributionTotal, 2) ?>%"></i></span><strong><?= (int)$count ?> · <?= number_format(100 * $count / $distributionTotal, 1, ',', '.') ?>%</strong></div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="survey-panel">
        <header><div><h2>Theo chức năng và vai trò</h2><p>Số câu trả lời và tỷ lệ mức 4–5.</p></div></header>
        <?php foreach (['feature' => 'Chức năng', 'role' => 'Vai trò', 'version' => 'Phiên bản'] as $dimension => $heading): ?>
          <h3><?= h($heading) ?></h3>
          <div class="survey-group-list">
            <?php foreach (($summary['groups'][$dimension] ?? []) as $key => $group): ?>
              <div><span><?= h($dimension === 'feature' ? ($featureLabels[$key] ?? $key) : ($dimension === 'role' ? ($roleLabels[$key] ?? $key) : $key)) ?></span><strong><?= number_format((int)$group['surveys'], 0, ',', '.') ?> lượt · <?= number_format((int)$group['answers'], 0, ',', '.') ?> câu · <?= h(number_format((float)$group['satisfaction_rate'], 1, ',', '.')) ?>%</strong></div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </section>
      </div>
    </div>

    <section class="survey-panel survey-comments">
      <header><div><h2>Góp ý tự do</h2><p>Mã người tham gia đã được giả danh; không hiển thị ID, email hoặc nội dung tư vấn.</p></div><strong><?= count($summary['comments']) ?> góp ý</strong></header>
      <?php if (!$summary['comments']): ?>
        <p class="survey-comments__empty">Chưa có góp ý tự do trong phạm vi này.</p>
      <?php else: ?>
        <div class="survey-comments__list">
          <?php foreach (array_slice($summary['comments'], 0, 100) as $comment): ?>
            <article>
              <div><strong><?= h($featureLabels[$comment['feature']] ?? $comment['feature']) ?></strong><span><?= h($roleLabels[$comment['role']] ?? $comment['role']) ?> · v<?= (int)$comment['version'] ?> · <?= h($comment['submitted_at']) ?></span></div>
              <p><?= nl2br(h($comment['comment'])) ?></p>
              <small>Mã giả danh: <?= h($comment['participant_code']) ?></small>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
