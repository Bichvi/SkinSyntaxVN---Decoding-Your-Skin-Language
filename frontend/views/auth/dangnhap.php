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

<div class="auth-page-wrapper d-flex align-items-center justify-content-center py-4 py-md-5" style="min-height: calc(100vh - 180px);">
  <div class="container my-auto">
    <div class="card border-0 shadow-lg overflow-hidden mx-auto" style="max-width: 900px; border-radius: 28px;">
      <div class="row g-0 align-items-stretch">
        <!-- Left Panel: Visual Green (Screenshot 1) -->
        <div class="col-lg-5 d-none d-lg-flex flex-column justify-content-center p-4 p-xl-5 text-white" style="background: #215427;">
          <div class="w-100">
            <a class="brand-lockup mb-4" href="<?= BASE_URL ?>/index.php" style="display: inline-flex; align-items: center; gap: 10px; text-decoration: none;">
              <span class="brand-lockup__mark" style="background: #FFFFFF; color: #215427; width: 42px; height: 42px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 20px;">S</span>
              <span class="brand-lockup__copy">
                <strong style="color: #FFFFFF; font-size: 20px; display: block; line-height: 1;">SkinSyntax<span style="font-size: 14px; opacity: 0.85;">VN</span></strong>
                <small style="color: #D2E5D5; display: block; font-size: 10px; letter-spacing: 0.08em; margin-top: 2px;">DECODING YOUR SKIN LANGUAGE</small>
              </span>
            </a>

            <div class="mb-3" style="display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.18); color: #EAF2EC; padding: 6px 14px; border-radius: 999px; font-size: 0.8rem; font-weight: 700;">
              <i class="fas fa-wand-magic-sparkles"></i> Trải Nghiệm Skincare Chuẩn Y Khoa
            </div>

            <h3 class="fw-bold mb-3" style="font-size: 1.8rem; line-height: 1.25; color: #FFFFFF;">Chào Mừng Đến Với SkinSyntax</h3>
            <p style="font-size: 0.88rem; color: #EAF2EC; opacity: 0.9; line-height: 1.6;">Tạo tài khoản SkinSyntax để lưu đơn hàng, nhận phân tích routine da chuẩn y khoa & ưu đãi dành riêng cho bạn.</p>

            <a class="btn btn-light w-100 py-3 fw-bold mt-4" href="<?= BASE_URL ?>/index.php?r=dangky" style="border-radius: 999px; color: #215427; background: #FFFFFF; font-size: 0.95rem; text-decoration: none;">
              Tạo Tài Khoản Mới <i class="fas fa-arrow-right ms-2"></i>
            </a>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3" style="border-top: 1px solid rgba(255,255,255,0.18); font-size: 0.78rem; color: #D2E5D5;">
              <span><i class="fas fa-shield-check me-1"></i> 100% Thuần Chay</span>
              <span><i class="fas fa-sparkles me-1"></i> Chuyên Gia Tư Vấn AI</span>
            </div>
          </div>
        </div>

        <!-- Right Panel: Login Form (Screenshot 1) -->
        <div class="col-lg-7 bg-white p-4 p-md-5 d-flex flex-column justify-content-center">
          <h3 class="fw-bold mb-1" style="font-size: 1.8rem; color: #1A2F1A;">Đăng Nhập</h3>
          <p style="color: #5C705E; font-size: 0.88rem; margin-bottom: 20px;">Đăng nhập với mạng xã hội hoặc tài khoản email của bạn.</p>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <a class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2 py-2.5 <?= $googleEnabled ? '' : 'disabled' ?>" href="<?= BASE_URL ?>/index.php?r=auth_social&provider=google" style="border-radius: 999px; font-weight: 700; border-color: #E2EADF; color: #1A2F1A; background: #FFF;">
                <i class="fa-brands fa-google text-danger"></i>
                <span>Google</span>
              </a>
            </div>
            <div class="col-6">
              <a class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2 py-2.5 <?= $facebookEnabled ? '' : 'disabled' ?>" href="<?= BASE_URL ?>/index.php?r=auth_social&provider=facebook" style="border-radius: 999px; font-weight: 700; background: #3b5998; border: none; color: #fff;">
                <i class="fa-brands fa-facebook-f"></i>
                <span>Facebook</span>
              </a>
            </div>
          </div>

          <div class="position-relative text-center my-4">
            <hr style="border-color: #E2EADF;">
            <span class="position-absolute top-50 start-50 translate-middle bg-white px-2 text-uppercase fw-bold text-muted" style="font-size: 0.72rem; letter-spacing: 0.05em;">HOẶC ĐĂNG NHẬP VỚI SKINSYNTAX</span>
          </div>

          <form method="post" action="<?= BASE_URL ?>/index.php?r=xulydangnhap">
            <div class="mb-3">
              <label class="form-label text-uppercase fw-bold small mb-1" style="color: #1A2F1A; font-size: 0.76rem; letter-spacing: 0.05em;">NHẬP EMAIL</label>
              <div class="position-relative">
                <input class="form-control" type="email" name="email" placeholder="Nhập email" style="border-radius: 999px; padding: 12px 18px 12px 44px; background: #F8FAF8; border-color: #E2EADF;" required>
                <i class="fa-regular fa-envelope position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label text-uppercase fw-bold small mb-1" style="color: #1A2F1A; font-size: 0.76rem; letter-spacing: 0.05em;">NHẬP PASSWORD</label>
              <div class="position-relative">
                <input class="form-control" type="password" id="loginPagePassword" name="mat_khau" placeholder="Nhập password" style="border-radius: 999px; padding: 12px 44px; background: #F8FAF8; border-color: #E2EADF;" required>
                <i class="fa-solid fa-lock position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                <button class="btn btn-link position-absolute top-50 end-0 translate-middle-y me-2 text-muted p-0 border-0" type="button" onclick="const p=document.getElementById('loginPagePassword'); p.type = p.type==='password'?'text':'password';">
                  <i class="fa-regular fa-eye"></i>
                </button>
              </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" value="1" id="rememberLoginStandalone" name="remember_login">
                <label class="form-check-label small" for="rememberLoginStandalone" style="color: #5C705E;">Nhớ mật khẩu</label>
              </div>
              <a href="<?= BASE_URL ?>/index.php?r=quen_mat_khau" class="small fw-bold" style="color: #215427; text-decoration: none;">Quên mật khẩu</a>
            </div>

            <button class="btn w-100 py-3 fw-bold" type="submit" style="background: #215427; color: #fff; border-radius: 999px; font-size: 1rem; border: none;">Đăng nhập</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
