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

<div class="auth-wrap">
  <div class="auth-card register-card shadow-sm">
    <h2 class="register-title">Đăng ký tài khoản</h2>
    <p class="register-subtitle">Tạo tài khoản theo giao diện quen thuộc kiểu Hasaki để bắt đầu khảo sát và nhận gợi ý phù hợp.</p>

    <form method="post" action="<?= BASE_URL ?>/index.php?r=xulydangky" id="registerForm" novalidate>
      <div class="register-field">
        <input class="form-control" type="email" name="email" value="<?= h((string)($old['email'] ?? '')) ?>" placeholder="Nhập email hoặc số điện thoại" required>
        <i class="fa-regular fa-envelope register-field__icon"></i>
      </div>

      <div class="register-inline">
        <input class="form-control" type="text" id="captchaInput" placeholder="Nhập captcha" autocomplete="off" required>
        <div class="register-captcha" id="captchaCode" data-captcha="<?= h(strtolower($captchaSeed)) ?>"><?= h(strtolower($captchaSeed)) ?></div>
      </div>

      <div class="register-otp">
        <input class="form-control" type="text" id="otpInput" inputmode="numeric" maxlength="6" placeholder="Nhập mã xác thực 6 số" autocomplete="one-time-code" required>
        <button class="register-otp__button" id="otpButton" type="button">lấy mã</button>
      </div>
      <a class="register-helper-link" href="#" id="otpHelpLink">Xem hướng dẫn nhận OTP</a>
      <p class="register-inline-note" id="otpHint">OTP demo sẽ được tạo local để mô phỏng đúng trải nghiệm đăng ký.</p>

      <div class="register-field">
        <input class="form-control" type="password" name="mat_khau" placeholder="Nhập mật khẩu từ 6 - 32 ký tự" required>
        <i class="fa-solid fa-lock register-field__icon"></i>
      </div>

      <div class="register-field">
        <input class="form-control" type="password" name="mat_khau2" placeholder="Nhập lại mật khẩu" required>
        <i class="fa-solid fa-lock register-field__icon"></i>
      </div>

      <div class="register-field">
        <input class="form-control" type="text" name="ho_ten" value="<?= h((string)($old['ho_ten'] ?? '')) ?>" placeholder="Họ tên" required>
        <i class="fa-solid fa-user register-field__icon"></i>
      </div>

      <div class="register-gender">
        <div class="form-check">
          <input class="form-check-input" type="radio" name="gioi_tinh" id="genderUnknown" value="Khong xac dinh" <?= (($old['gioi_tinh'] ?? '') === 'Khong xac dinh' || empty($old['gioi_tinh'])) ? 'checked' : '' ?>>
          <label class="form-check-label" for="genderUnknown">Không xác định</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="gioi_tinh" id="genderMale" value="Nam" <?= (($old['gioi_tinh'] ?? '') === 'Nam') ? 'checked' : '' ?>>
          <label class="form-check-label" for="genderMale">Nam</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="gioi_tinh" id="genderFemale" value="Nữ" <?= (($old['gioi_tinh'] ?? '') === 'Nữ') ? 'checked' : '' ?>>
          <label class="form-check-label" for="genderFemale">Nữ</label>
        </div>
      </div>

      <div class="register-birthday">
        <select class="form-select" name="ngay_sinh">
          <option value="">Ngày</option>
          <?php foreach ($dayOptions as $day): ?>
            <option value="<?= $day ?>" <?= ((string)($old['ngay_sinh'] ?? '') === (string)$day) ? 'selected' : '' ?>><?= $day ?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-select" name="thang_sinh">
          <option value="">Tháng</option>
          <?php foreach ($monthOptions as $month): ?>
            <option value="<?= $month ?>" <?= ((string)($old['thang_sinh'] ?? '') === (string)$month) ? 'selected' : '' ?>><?= $month ?></option>
          <?php endforeach; ?>
        </select>
        <select class="form-select" name="nam_sinh">
          <option value="">Năm</option>
          <?php foreach ($yearOptions as $year): ?>
            <option value="<?= $year ?>" <?= ((string)($old['nam_sinh'] ?? '') === (string)$year) ? 'selected' : '' ?>><?= $year ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="register-checks">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" value="1" id="termsAgree" name="terms_agree" required>
          <label class="form-check-label" for="termsAgree">Tôi đã đọc và đồng ý với <a href="#">Điều kiện giao dịch chung</a> và <a href="#">Chính sách bảo mật thông tin</a></label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" value="1" id="emailOptIn" name="email_opt_in" <?= !isset($old['email_opt_in']) || ($old['email_opt_in'] ?? '') === '1' ? 'checked' : '' ?>>
          <label class="form-check-label" for="emailOptIn">Nhận thông tin khuyến mãi qua e-mail</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" value="1" id="privacyConsent" name="privacy_consent" <?= !isset($old['privacy_consent']) || ($old['privacy_consent'] ?? '') === '1' ? 'checked' : '' ?> required>
          <label class="form-check-label" for="privacyConsent">Tôi đồng ý với <a href="#">chính sách xử lý dữ liệu cá nhân</a> của SkinSyntax</label>
        </div>
      </div>

      <button class="btn btn-brand w-100 register-submit" type="submit">Đăng ký</button>

      <div class="register-auth-footer">
        Bạn đã có tài khoản? <a href="<?= BASE_URL ?>/index.php?r=dangnhap">Đăng nhập</a>
      </div>

      <div class="register-social-label">Hoặc đăng nhập với:</div>
      <div class="register-social-stack">
        <a class="register-social-btn register-social-btn--facebook <?= !empty($facebookEnabled) ? '' : 'register-social-btn--disabled' ?>" href="<?= BASE_URL ?>/index.php?r=auth_social&provider=facebook">
          <i class="fa-brands fa-facebook-f"></i>
          <span>Facebook</span>
        </a>
        <a class="register-social-btn register-social-btn--google <?= !empty($googleEnabled) ? '' : 'register-social-btn--disabled' ?>" href="<?= BASE_URL ?>/index.php?r=auth_social&provider=google">
          <i class="fa-brands fa-google"></i>
          <span>Đăng nhập bằng Google</span>
        </a>
      </div>
    </form>
  </div>
</div>

<?php unset($_SESSION['signup_old']); ?>

<script>
(() => {
  const form = document.getElementById('registerForm');
  if (!form) {
    return;
  }

  const captchaElement = document.getElementById('captchaCode');
  const captchaInput = document.getElementById('captchaInput');
  const otpInput = document.getElementById('otpInput');
  const otpButton = document.getElementById('otpButton');
  const otpHint = document.getElementById('otpHint');
  const otpHelpLink = document.getElementById('otpHelpLink');
  let generatedOtp = '';

  const buildOtp = () => String(Math.floor(100000 + Math.random() * 900000));

  otpButton.addEventListener('click', () => {
    generatedOtp = buildOtp();
    otpHint.textContent = 'Mã OTP demo của phiên này là: ' + generatedOtp + '. Nhập mã này để tiếp tục đăng ký.';
  });

  otpHelpLink.addEventListener('click', (event) => {
    event.preventDefault();
    otpHint.textContent = 'Đây là giao diện mô phỏng Hasaki. Bấm "lấy mã" để hệ thống tạo mã OTP demo local cho lần đăng ký này.';
  });

  form.addEventListener('submit', (event) => {
    const captchaValue = (captchaInput.value || '').trim().toLowerCase();
    const expectedCaptcha = (captchaElement.dataset.captcha || '').trim().toLowerCase();
    const otpValue = (otpInput.value || '').trim();

    if (captchaValue === '' || captchaValue !== expectedCaptcha) {
      event.preventDefault();
      otpHint.textContent = 'Captcha chưa đúng. Vui lòng nhập lại đúng 4 ký tự hiển thị.';
      captchaInput.focus();
      return;
    }

    if (generatedOtp === '' || otpValue !== generatedOtp) {
      event.preventDefault();
      otpHint.textContent = 'Mã OTP chưa đúng hoặc chưa được tạo. Bấm "lấy mã" để nhận OTP demo.';
      otpInput.focus();
    }
  });
})();
</script>
