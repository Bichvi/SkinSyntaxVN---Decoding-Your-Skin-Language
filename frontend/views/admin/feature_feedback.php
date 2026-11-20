<?php
$featureLabels = [
    'recommendation' => 'Gợi ý routine', 'customer_account' => 'Tài khoản',
    'admin_workspace' => 'Quản trị', 'staff_workspace' => 'Công cụ nhân viên',
];
$roleLabels = ['khach_hang' => 'Khách hàng', 'admin' => 'Quản trị viên', 'nhanvien' => 'Nhân viên'];
$totals = [];
foreach ($distribution as $row) {
    $key = $row['_id'];
    $feature = (string)$key['feature'];
    $role = (string)$key['role'];
    $version = (int)$key['version'];
    $totals[$feature][$role][$version][(int)$key['rating']] = (int)$row['count'];
}
?>
<main class="container py-4">
  <h1 class="h4">Đánh giá mức độ hài lòng</h1>
  <p>Mỗi tài khoản có một câu trả lời cho mỗi tính năng trong ngày. Gửi lại sẽ cập nhật câu trả lời hôm đó.</p>
  <div class="d-flex gap-2 mb-3">
    <a class="btn btn-outline-success" href="<?= h(BASE_URL . '/index.php?r=admin_dashboard') ?>">Về trang quản trị</a>
    <a class="btn btn-outline-success" href="<?= h(BASE_URL . '/index.php?r=admin_feature_feedback&format=json') ?>">Dữ liệu tổng hợp JSON</a>
  </div>
  <?php if (!$totals): ?>
    <p>Chưa có đánh giá nào được gửi.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle">
        <caption>Số lượt phản hồi theo vai trò, chức năng và mức hài lòng từ 1 đến 5</caption>
        <thead>
          <tr>
            <th scope="col">Chức năng</th>
            <th scope="col">Vai trò</th>
            <th scope="col">Phiên bản câu hỏi</th>
            <?php for ($score = 1; $score <= 5; $score++): ?><th scope="col"><?= $score ?></th><?php endfor; ?>
            <th scope="col">Tổng</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($totals as $feature => $roles): foreach ($roles as $role => $versions): foreach ($versions as $version => $counts): ?>
          <tr>
            <th scope="row"><?= h($featureLabels[$feature] ?? $feature) ?></th>
            <td><?= h($roleLabels[$role] ?? $role) ?></td>
            <td><?= $version ?></td>
            <?php for ($score = 1; $score <= 5; $score++): ?><td><?= $counts[$score] ?? 0 ?></td><?php endfor; ?>
            <td><?= array_sum($counts) ?></td>
          </tr>
          <?php endforeach; endforeach; endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</main>
