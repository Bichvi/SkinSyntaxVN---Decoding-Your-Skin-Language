<?php
// frontend/views/phan_thuong.php — trang Phần thưởng (xu từ xem video)
$rewardCsrf = (string) ($rewardCsrf ?? '');
$isLoggedIn = !empty($isLoggedIn);
// Chỉ tài khoản khách hàng mới tích xu; admin/nhân viên chỉ thấy ghi chú.
$ptUser = function_exists('current_user') ? current_user() : null;
$ptRole = strtolower(trim((string) (is_array($ptUser) ? ($ptUser['role'] ?? $ptUser['vai_tro'] ?? 'khach_hang') : 'khach_hang')));
$isStaffAccount = $isLoggedIn && $ptRole !== 'khach_hang';
$isLoggedIn = $isLoggedIn && !$isStaffAccount;
$featuredProductId = isset($featuredProductId) && $featuredProductId !== '' ? (string) $featuredProductId : null;
$ptCssV = @filemtime(VIEW_DIR . '/../public/assets/css/phan-thuong.css') ?: '1';
$ptJsV = @filemtime(VIEW_DIR . '/../public/assets/js/phan-thuong.js') ?: '1';
$ptWatchUrl = $featuredProductId !== null
    ? BASE_URL . '/index.php?r=chitiet&id=' . rawurlencode($featuredProductId)
    : BASE_URL . '/index.php?r=live';
?>
<link rel="stylesheet" href="<?= h(BASE_URL . '/assets/css/phan-thuong.css?v=' . $ptCssV) ?>">
<main class="phan-thuong" id="phanThuong"
      data-reward-csrf="<?= h($rewardCsrf) ?>"
      data-logged-in="<?= $isLoggedIn ? '1' : '0' ?>"
      data-watch-url="<?= h($ptWatchUrl) ?>"
      data-summary-url="<?= h(BASE_URL . '/index.php?r=reward_summary') ?>"
      data-history-url="<?= h(BASE_URL . '/index.php?r=reward_history') ?>"
      data-checkin-url="<?= h(BASE_URL . '/index.php?r=reward_checkin') ?>"
      data-claim-url="<?= h(BASE_URL . '/index.php?r=reward_milestone_claim') ?>">
  <section class="pt-hero">
    <div class="pt-wrap">
      <h1>Phần thưởng</h1>
      <p>Đánh giá sản phẩm, xem video Syna và nhận xu</p>
    </div>
  </section>

  <div class="pt-wrap pt-body">
    <?php if ($isStaffAccount): ?>
      <section class="pt-card pt-guest">
        <p class="pt-note">Tài khoản quản trị không tích xu.</p>
      </section>
    <?php elseif (!$isLoggedIn): ?>
      <section class="pt-card pt-guest">
        <h2>Thể lệ nhận xu</h2>
        <ul class="pt-rules">
          <li>Xem video sản phẩm để nhận xu cho từng video.</li>
          <li>Đánh giá sản phẩm từ đơn đã hoàn thành để nhận 100 xu.</li>
          <li>Xem ít nhất 1 phút mỗi ngày để điểm danh, chuỗi 7 ngày có quà lớn.</li>
          <li>Xem đủ 5, 15 và 30 phút trong ngày để mở hộp quà.</li>
          <li>Tổng xu thưởng trong ngày tối đa 300 xu.</li>
        </ul>
        <a href="#" class="pt-btn pt-btn--gold" data-bs-toggle="modal" data-bs-target="#authModal" data-auth-tab="login">Đăng nhập để nhận xu</a>
      </section>
    <?php else: ?>
      <div class="pt-toast" id="ptToast" role="status" aria-live="polite" hidden></div>

      <section class="pt-card pt-balance" aria-labelledby="ptBalanceLabel">
        <div class="pt-balance__label" id="ptBalanceLabel">Xu đã tích lũy</div>
        <div class="pt-balance__num" id="ptBalance">&hellip;</div>
        <div class="pt-balance__sub" id="ptBalanceSub"></div>
        <div class="pt-balance__cap" id="ptCap" hidden></div>
        <div class="pt-balance__avail" id="ptAvail"></div>
        <a class="pt-btn pt-btn--green" href="<?= h(BASE_URL . '/index.php?r=giohang') ?>">Dùng khi thanh toán</a>
      </section>

      <section class="pt-card" aria-labelledby="ptCheckinTitle">
        <h2 id="ptCheckinTitle"><i class="fa-solid fa-calendar-check"></i> Điểm danh hằng ngày</h2>
        <ol class="pt-days" id="ptDays"></ol>
        <button type="button" class="pt-btn pt-btn--lock" id="ptCheckinBtn" disabled>Điểm danh</button>
        <a class="pt-btn pt-btn--green pt-checkin-watch" id="ptCheckinWatch" href="<?= h($ptWatchUrl) ?>" hidden>Xem ngay</a>
        <p class="pt-note">Xem ít nhất 1 phút video để điểm danh</p>
      </section>

      <section class="pt-card" aria-labelledby="ptWatchTitle">
        <h2 id="ptWatchTitle"><i class="fa-solid fa-circle-play"></i> Xem video mỗi ngày</h2>
        <div class="pt-progress">
          <div class="pt-progress__bar" role="progressbar" aria-label="Thời gian xem hôm nay" aria-valuemin="0" aria-valuemax="30" aria-valuenow="0" id="ptBar"><span id="ptBarFill"></span></div>
          <div class="pt-ms" id="ptMilestones"></div>
        </div>
        <p class="pt-note" id="ptWatchNote"></p>
        <a class="pt-btn pt-btn--green" href="<?= h($ptWatchUrl) ?>">Xem ngay</a>
      </section>

      <section class="pt-card" aria-labelledby="ptHistTitle">
        <h2 id="ptHistTitle"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử xu</h2>
        <ul class="pt-history" id="ptHistory"></ul>
      </section>
    <?php endif; ?>
  </div>
</main>
<?php if ($isLoggedIn): ?>
<script src="<?= h(BASE_URL . '/assets/js/phan-thuong.js?v=' . $ptJsV) ?>" defer></script>
<?php endif; ?>
