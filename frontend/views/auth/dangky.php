<?php
$old = $_SESSION['signup_old'] ?? [];
$dayOptions = range(1, 31);
$monthOptions = range(1, 12);
$currentYear = (int)date('Y');
$yearOptions = range($currentYear, max(1950, $currentYear - 70));
$captchaSeed = strtoupper(substr(bin2hex(random_bytes(4)), 0, 4));
?>

<style>
  .register-card {
    max-width: 560px;
    border-radius: 28px;
    padding: 26px;
  }

  .register-title {
    font-size: 32px;
    font-weight: 900;
    color: #132433;
    margin-bottom: 8px;
  }

  .register-subtitle {
    color: #64748b;
    margin-bottom: 22px;
  }

  .register-field {
    position: relative;
    margin-bottom: 14px;
  }

  .register-field__icon {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
    font-size: 16px;
    pointer-events: none;
  }

  .register-card .form-control,
  .register-card .form-select {
    min-height: 56px;
    border-radius: 0;
    border: 1px solid #d7dde6;
    background: #fff;
    padding-left: 16px;
    padding-right: 42px;
    font-size: 16px;
  }

  .register-inline {
    display: grid;
    grid-template-columns: 1fr 150px;
    gap: 0;
    margin-bottom: 10px;
  }

  .register-inline .form-control {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
  }

  .register-captcha {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 56px;
    background: #347a55;
    color: #fff;
    font-weight: 800;
    font-size: 18px;
    letter-spacing: .35em;
    text-transform: lowercase;
  }

  .register-otp {
    display: grid;
    grid-template-columns: 1fr 108px;
    gap: 0;
  }

  .register-otp .form-control {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
  }

  .register-otp__button {
    border: 0;
    background: #d1d5db;
    color: #475569;
    font-weight: 700;
  }

  .register-helper-link {
    display: inline-block;
    margin: 6px 0 16px;
    font-size: 14px;
    color: #2563eb;
    text-decoration: underline;
  }

  .register-gender {
    display: flex;
    gap: 28px;
    margin: 10px 0 18px;
    flex-wrap: wrap;
  }

  .register-gender .form-check-label {
    color: #1f2937;
    font-size: 16px;
  }

  .register-birthday {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 18px;
  }

  .register-checks {
    display: grid;
    gap: 12px;
    margin-bottom: 20px;
  }

  .register-checks .form-check-input {
    margin-top: .3rem;
  }

  .register-submit {
    min-height: 54px;
    border-radius: 999px;
    font-size: 18px;
    font-weight: 800;
  }

  .register-auth-footer {
    margin-top: 18px;
    color: #334155;
    font-size: 15px;
  }

  .register-auth-footer a {
    font-weight: 800;
    text-transform: uppercase;
  }

  .register-social-label {
    margin: 12px 0 12px;
    color: #334155;
    font-size: 15px;
  }

  .register-social-stack {
    display: grid;
    gap: 12px;
  }

  .register-social-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 44px;
    border-radius: 8px;
    border: 1px solid #d7deea;
    font-weight: 700;
    text-decoration: none;
  }

  .register-social-btn--facebook {
    background: #365899;
    border-color: #365899;
    color: #fff;
  }

  .register-social-btn--google {
    background: #fff;
    color: #111827;
  }

  .register-social-btn--disabled {
    opacity: .65;
    pointer-events: none;
  }

  .register-inline-note {
    margin: 6px 0 0;
    font-size: 13px;
    color: #64748b;
  }

  @media (max-width: 575.98px) {
    .register-card {
      padding: 18px;
      border-radius: 22px;
    }

    .register-inline,
    .register-otp,
    .register-birthday {
      grid-template-columns: 1fr;
    }

    .register-inline .form-control,
    .register-otp .form-control {
      border-radius: 0;
    }

    .register-captcha,
    .register-otp__button {
      min-height: 48px;
    }
  }
</style>

<div class="auth-page-wrapper d-flex align-items-center justify-content-center py-4 py-md-5" style="min-height: calc(100vh - 180px);">
  <div class="container my-auto">
    <div class="card border-0 overflow-hidden mx-auto" style="max-width: 860px; border-radius: 12px; border: 1px solid var(--border) !important;">
    <div class="row g-0 align-items-stretch">
      <!-- Left Panel: Register Form -->
      <div class="col-lg-7 bg-white p-4 p-md-5">
        <h3 class="fw-bold mb-1" style="font-size: 1.6rem; color: #0F172A;">Đăng Ký Tài Khoản</h3>
        <p style="color: #64748B; font-size: 0.86rem; margin-bottom: 20px;">Tạo tài khoản SkinSyntax để lưu đơn hàng, routine và nhận gợi ý cá nhân hóa.</p>

        <form method="post" action="<?= BASE_URL ?>/index.php?r=xulydangky" id="registerForm" novalidate>
          <div class="mb-2.5">
            <input class="form-control" type="email" name="email" value="<?= h((string)($old['email'] ?? '')) ?>" placeholder="Nhập email" style="border-radius: 6px; padding: 10px 14px; background: #FAFAFA; border-color: var(--border); font-size: 0.88rem;" required>
          </div>

          <div class="d-flex gap-2 mb-2.5">
            <input class="form-control" type="text" id="captchaInput" placeholder="Nhập captcha" autocomplete="off" style="border-radius: 6px; padding: 10px 14px; background: #FAFAFA; border-color: var(--border); font-size: 0.88rem;" required>
            <div class="d-flex align-items-center justify-content-center text-white fw-semibold px-3" id="captchaCode" data-captcha="<?= h(strtolower($captchaSeed)) ?>" style="background: #183B2B; border-radius: 6px; font-family: monospace; letter-spacing: 2px; min-width: 90px; font-size: 0.9rem;"><?= h(strtolower($captchaSeed)) ?></div>
            <button class="btn btn-outline-secondary px-3" type="button" onclick="location.reload();" style="border-radius: 6px; width: 42px; height: 42px; display: grid; place-items: center; border-color: var(--border);">
              <i class="fa-solid fa-rotate-right"></i>
            </button>
          </div>

          <div class="d-flex gap-2 mb-2.5">
            <input class="form-control" type="text" id="otpInput" inputmode="numeric" maxlength="6" placeholder="Nhập mã xác thực 6 số" autocomplete="one-time-code" style="border-radius: 6px; padding: 10px 14px; background: #FAFAFA; border-color: var(--border); font-size: 0.88rem;" required>
            <button class="btn fw-semibold text-white px-4" id="otpButton" type="button" style="background: #183B2B; border-radius: 6px; white-space: nowrap; font-size: 0.86rem;">Lấy mã</button>
          </div>
          <div class="auth-register-note mb-2.5" id="otpHint" style="font-size: 0.76rem; color: #64748B;">Nhập đúng email, captcha rồi bấm Lấy mã.</div>

          <div class="row g-2 mb-2.5">
            <div class="col-6">
              <input class="form-control" type="password" name="mat_khau" placeholder="Mật khẩu 8 - 32 ký tự" minlength="8" maxlength="32" style="border-radius: 6px; padding: 10px 14px; background: #FAFAFA; border-color: var(--border); font-size: 0.88rem;" required>
            </div>
            <div class="col-6">
              <input class="form-control" type="password" name="mat_khau2" placeholder="Nhập lại mật khẩu" minlength="8" maxlength="32" style="border-radius: 6px; padding: 10px 14px; background: #FAFAFA; border-color: var(--border); font-size: 0.88rem;" required>
            </div>
          </div>

          <div class="mb-2.5">
            <input class="form-control" type="text" name="ho_ten" value="<?= h((string)($old['ho_ten'] ?? '')) ?>" placeholder="Họ tên" style="border-radius: 6px; padding: 10px 14px; background: #FAFAFA; border-color: var(--border); font-size: 0.88rem;" required>
          </div>

          <div class="d-flex align-items-center gap-3 mb-2 small" style="color: #5C705E;">
            <label class="form-check mb-0">
              <input class="form-check-input" type="radio" name="gioi_tinh" value="Khong xac dinh" <?= (($old['gioi_tinh'] ?? '') === 'Khong xac dinh' || empty($old['gioi_tinh'])) ? 'checked' : '' ?>>
              <span>Không xác định</span>
            </label>
            <label class="form-check mb-0">
              <input class="form-check-input" type="radio" name="gioi_tinh" value="Nam" <?= (($old['gioi_tinh'] ?? '') === 'Nam') ? 'checked' : '' ?>>
              <span>Nam</span>
            </label>
            <label class="form-check mb-0">
              <input class="form-check-input" type="radio" name="gioi_tinh" value="Nữ" <?= (($old['gioi_tinh'] ?? '') === 'Nữ') ? 'checked' : '' ?>>
              <span>Nữ</span>
            </label>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-4">
              <select class="form-select" name="ngay_sinh" style="border-radius: 999px; padding: 10px 14px; background: #F8FAF8; border-color: #E2EADF; font-size: 0.85rem;">
                <option value="">Ngày</option>
                <?php foreach ($dayOptions as $day): ?>
                  <option value="<?= $day ?>" <?= ((string)($old['ngay_sinh'] ?? '') === (string)$day) ? 'selected' : '' ?>><?= $day ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-4">
              <select class="form-select" name="thang_sinh" style="border-radius: 999px; padding: 10px 14px; background: #F8FAF8; border-color: #E2EADF; font-size: 0.85rem;">
                <option value="">Tháng</option>
                <?php foreach ($monthOptions as $month): ?>
                  <option value="<?= $month ?>" <?= ((string)($old['thang_sinh'] ?? '') === (string)$month) ? 'selected' : '' ?>><?= $month ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-4">
              <select class="form-select" name="nam_sinh" style="border-radius: 999px; padding: 10px 14px; background: #F8FAF8; border-color: #E2EADF; font-size: 0.85rem;">
                <option value="">Năm</option>
                <?php foreach ($yearOptions as $year): ?>
                  <option value="<?= $year ?>" <?= ((string)($old['nam_sinh'] ?? '') === (string)$year) ? 'selected' : '' ?>><?= $year ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="mb-3 small" style="color: #5C705E;">
            <label class="form-check mb-1">
              <input class="form-check-input" type="checkbox" name="terms_agree" value="1" <?= (($old['terms_agree'] ?? '') === '1') ? 'checked' : '' ?> required>
              <span>Tôi đã đọc và đồng ý với <strong>Điều kiện giao dịch chung</strong> và <strong>Chính sách bảo mật thông tin</strong>.</span>
            </label>
            <label class="form-check mb-1">
              <input class="form-check-input" type="checkbox" name="email_opt_in" value="1" <?= !isset($old['email_opt_in']) || ($old['email_opt_in'] ?? '') === '1' ? 'checked' : '' ?>>
              <span>Nhận thông tin khuyến mãi qua e-mail</span>
            </label>
            <label class="form-check mb-0">
              <input class="form-check-input" type="checkbox" name="privacy_consent" value="1" <?= (($old['privacy_consent'] ?? '') === '1') ? 'checked' : '' ?> required>
              <span>Tôi đồng ý với <strong>chính sách xử lý dữ liệu cá nhân</strong> của SkinSyntax.</span>
            </label>
          </div>

          <button class="btn w-100 py-3 fw-bold" type="submit" style="background: #215427; color: #fff; border-radius: 999px; font-size: 1rem; border: none;">Đăng ký</button>
        </form>
      </div>

      <!-- Right Panel: Visual Green (Screenshot 2) -->
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
            <i class="fas fa-wand-magic-sparkles"></i> Thành Viên Mới SkinSyntax
          </div>

          <h3 class="fw-bold mb-3" style="font-size: 1.8rem; line-height: 1.25; color: #FFFFFF;">Bạn Đã Có Tài Khoản?</h3>
          <p style="font-size: 0.88rem; color: #EAF2EC; opacity: 0.9; line-height: 1.6;">Đăng nhập ngay để theo dõi lịch sử đơn hàng, cập nhật Hồ Sơ Da và mở các gợi ý mỹ phẩm cá nhân hóa từ AI.</p>

          <a class="btn btn-light w-100 py-3 fw-bold mt-4" href="<?= BASE_URL ?>/index.php?r=dangnhap" style="border-radius: 999px; color: #215427; background: #FFFFFF; font-size: 0.95rem; text-decoration: none;">
            Đăng Nhập Ngay <i class="fas fa-arrow-right ms-2"></i>
          </a>

          <div class="d-flex justify-content-between align-items-center mt-4 pt-3" style="border-top: 1px solid rgba(255,255,255,0.18); font-size: 0.78rem; color: #D2E5D5;">
            <span><i class="fas fa-shield-check me-1"></i> 100% Thuần Chay</span>
            <span><i class="fas fa-sparkles me-1"></i> Chuyên Gia Tư Vấn AI</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php unset($_SESSION['signup_old']); ?>

<script>
(() => {
  const form = document.getElementById('registerForm');
  if (!form) return;

  const captchaElement = document.getElementById('captchaCode');
  const captchaInput = document.getElementById('captchaInput');
  const otpInput = document.getElementById('otpInput');
  const otpButton = document.getElementById('otpButton');
  const otpHint = document.getElementById('otpHint');
  let generatedOtp = '';

  const buildOtp = () => String(Math.floor(100000 + Math.random() * 900000));

  otpButton.addEventListener('click', () => {
    generatedOtp = buildOtp();
    otpHint.textContent = 'Mã OTP xác thực của bạn là: ' + generatedOtp;
  });

  form.addEventListener('submit', (event) => {
    const captchaValue = (captchaInput.value || '').trim().toLowerCase();
    const expectedCaptcha = (captchaElement.dataset.captcha || '').trim().toLowerCase();
    const otpValue = (otpInput.value || '').trim();

    if (captchaValue === '' || captchaValue !== expectedCaptcha) {
      event.preventDefault();
      otpHint.textContent = 'Captcha chưa đúng. Vui lòng nhập lại.';
      captchaInput.focus();
      return;
    }

    if (generatedOtp === '' || otpValue !== generatedOtp) {
      event.preventDefault();
      otpHint.textContent = 'Mã OTP chưa đúng hoặc chưa tạo. Vui lòng bấm Lấy mã.';
      otpInput.focus();
    }
  });
})();
</script>
