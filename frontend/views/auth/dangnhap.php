<?php
$googleEnabled = !empty($googleEnabled);
$facebookEnabled = !empty($facebookEnabled);
?>

<style>
  .social-login-card {
    max-width: 580px;
    border-radius: 28px;
    padding: 28px;
  }

  .social-login-actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }

  .social-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 52px;
    border-radius: 14px;
    font-weight: 700;
    border: 1px solid #d7deea;
    background: #fff;
    color: #0f172a;
  }

  .social-btn.social-btn--facebook {
    background: #3157a6;
    border-color: #3157a6;
    color: #fff;
  }

  .social-btn.social-btn--disabled {
    opacity: .65;
  }

  .social-divider {
    position: relative;
    text-align: center;
    margin: 22px 0;
    color: #64748b;
  }

  .social-divider::before {
    content: '';
    position: absolute;
    top: 50%; left: 0; right: 0;
    border-top: 1px solid #e2e8f0;
  }

  .social-divider span {
    position: relative;
    background: #fff;
    padding: 0 14px;
  }

  .social-login-card .form-control {
    border-radius: 999px;
    min-height: 54px;
    padding-left: 18px;
    border-color: #e4e7ec;
    background: #f8fafc;
  }

  .social-login-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
  }

  .social-login-helper {
    color: #0f6b3e;
    font-weight: 600;
  }

  @media (max-width: 575.98px) {
    .social-login-card {
      padding: 20px;
      border-radius: 22px;
    }

    .social-login-actions {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="auth-wrap">
  <div class="auth-card social-login-card shadow-sm">
    <h3 class="mb-3">Đăng nhập</h3>
    <div class="small text-muted mb-3">Đăng nhập với mạng xã hội hoặc tài khoản email của bạn.</div>

    <div class="social-login-actions">
      <a class="social-btn social-btn--facebook <?= $facebookEnabled ? '' : 'social-btn--disabled' ?>" href="<?= BASE_URL ?>/index.php?r=auth_social&provider=facebook&oauth_mode=real">
        <i class="fa-brands fa-facebook-f"></i>
        <span>Facebook</span>
      </a>
      <a class="social-btn <?= $googleEnabled ? '' : 'social-btn--disabled' ?>" href="<?= BASE_URL ?>/index.php?r=auth_social&provider=google&oauth_mode=real">
        <i class="fa-brands fa-google"></i>
        <span>Đăng nhập bằng Google</span>
      </a>
    </div>

    <div class="social-divider"><span>Hoặc đăng nhập với SkinSyntax</span></div>

    <form method="post" action="<?= BASE_URL ?>/index.php?r=xulydangnhap">
      <div class="mb-3">
        <input class="form-control" type="email" name="email" placeholder="Nhập email của bạn" required>
      </div>

      <div class="mb-3">
        <input class="form-control" type="password" name="mat_khau" placeholder="Nhập mật khẩu" required>
      </div>

      <div class="social-login-row">
        <div class="form-check mb-0">
          <input class="form-check-input" type="checkbox" value="1" id="rememberLogin">
          <label class="form-check-label" for="rememberLogin">Nhớ mật khẩu</label>
        </div>
        <a class="social-login-helper" href="<?= BASE_URL ?>/index.php?r=quen_mat_khau">Quên mật khẩu</a>
      </div>

      <button class="btn btn-brand w-100 py-3" type="submit">Đăng nhập</button>

      <div class="text-center mt-3">
        Chưa có tài khoản?
        <a class="link-more" href="<?= BASE_URL ?>/index.php?r=dangky">Đăng ký ngay</a>
      </div>
    </form>
  </div>
</div>
