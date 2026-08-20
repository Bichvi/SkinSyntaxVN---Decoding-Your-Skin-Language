<?php
$mode = $mode ?? 'request';
$tokenValid = !empty($tokenValid);
$token = (string)($token ?? '');
?>

<style>
  .reset-card {
    max-width: 560px;
    border-radius: 28px;
    padding: 28px;
  }

  .reset-card .form-control {
    border-radius: 999px;
    min-height: 54px;
    padding-left: 18px;
    border-color: #e4e7ec;
    background: #f8fafc;
  }

  .reset-hint {
    padding: 14px 16px;
    border-radius: 18px;
    background: rgba(15, 107, 62, 0.08);
    color: #0f6b3e;
    font-size: 0.95rem;
    margin-bottom: 18px;
  }
</style>

<div class="auth-wrap">
  <div class="auth-card reset-card shadow-sm">
    <?php if ($mode === 'reset'): ?>
      <h3 class="mb-3">Đặt lại mật khẩu</h3>
      <?php if (!$tokenValid): ?>
        <div class="alert alert-danger">Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.</div>
        <a class="btn btn-brand w-100" href="<?= BASE_URL ?>/index.php?r=quen_mat_khau">Yêu cầu liên kết mới</a>
      <?php else: ?>
        <div class="reset-hint">Nhập mật khẩu mới cho tài khoản của bạn. Liên kết này chỉ dùng được một lần.</div>
        <form method="post" action="<?= BASE_URL ?>/index.php?r=dat_lai_mat_khau">
          <input type="hidden" name="token" value="<?= h($token) ?>">
          <div class="mb-3">
            <input class="form-control" type="password" name="mat_khau_moi" placeholder="Mật khẩu mới" required>
          </div>
          <div class="mb-3">
            <input class="form-control" type="password" name="xac_nhan_mat_khau" placeholder="Xác nhận mật khẩu mới" required>
          </div>
          <button class="btn btn-brand w-100 py-3" type="submit">Cập nhật mật khẩu</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <h3 class="mb-3">Quên mật khẩu</h3>
      <div class="reset-hint">Nhập email đã đăng ký. Hệ thống sẽ gửi liên kết đặt lại mật khẩu về hộp thư của bạn.</div>
      <form method="post" action="<?= BASE_URL ?>/index.php?r=gui_lien_ket_dat_lai">
        <div class="mb-3">
          <input class="form-control" type="email" name="email" placeholder="Nhập email của bạn" required>
        </div>
        <button class="btn btn-brand w-100 py-3" type="submit">Gửi liên kết đặt lại</button>
      </form>
    <?php endif; ?>

    <div class="text-center mt-3">
      <a class="link-more" href="<?= BASE_URL ?>/index.php?r=dangnhap">Quay lại đăng nhập</a>
    </div>
  </div>
</div>