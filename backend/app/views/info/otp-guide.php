<style>
  .info-shell {
    max-width: 980px;
    margin: 0 auto;
  }

  .info-hero {
    padding: 34px;
    border-radius: 28px;
    background: linear-gradient(135deg, #16364a 0%, #0f6b3e 100%);
    color: #fff;
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.14);
  }

  .info-eyebrow {
    display: inline-block;
    margin-bottom: 12px;
    font-size: .78rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: rgba(255,255,255,.78);
  }

  .info-hero h1 {
    font-size: clamp(30px, 4vw, 42px);
    font-weight: 900;
    line-height: 1.18;
    margin-bottom: 12px;
  }

  .info-hero p {
    max-width: 720px;
    color: rgba(255,255,255,.86);
    line-height: 1.7;
    margin-bottom: 0;
  }

  .info-grid {
    display: grid;
    grid-template-columns: 1.25fr .85fr;
    gap: 22px;
    margin-top: 24px;
  }

  .info-card {
    background: #fff;
    border: 1px solid #e6edf5;
    border-radius: 24px;
    padding: 24px;
    box-shadow: 0 16px 34px rgba(15, 23, 42, 0.07);
  }

  .info-card h2 {
    font-size: 1.3rem;
    font-weight: 800;
    margin-bottom: 14px;
    color: #0f172a;
  }

  .otp-steps {
    display: grid;
    gap: 14px;
  }

  .otp-step {
    display: grid;
    grid-template-columns: 52px 1fr;
    gap: 14px;
    align-items: start;
    padding: 14px;
    border-radius: 18px;
    background: #f8fbff;
  }

  .otp-step__badge {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #0f6b3e;
    color: #fff;
    font-weight: 900;
    font-size: 1.1rem;
  }

  .otp-step__title {
    font-size: 1rem;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 4px;
  }

  .otp-step__desc {
    color: #475569;
    line-height: 1.65;
    margin: 0;
  }

  .otp-list {
    margin: 0;
    padding-left: 18px;
    color: #475569;
    line-height: 1.7;
  }

  .otp-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 18px;
  }

  @media (max-width: 991px) {
    .info-grid {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="container py-4 py-lg-5">
  <div class="info-shell">
    <section class="info-hero">
      <span class="info-eyebrow">Hướng dẫn OTP</span>
      <h1>Cách nhận mã OTP trên popup đăng ký</h1>
      <p>SkinSyntax dùng OTP như một lớp xác thực bổ sung khi người dùng đăng ký, lấy lại tài khoản hoặc thực hiện thao tác cần bảo mật cao. Ở bản demo hiện tại, mã OTP được mô phỏng ngay trên trình duyệt để bạn kiểm thử luồng popup.</p>
    </section>

    <div class="info-grid">
      <section class="info-card">
        <h2>4 bước nhận và nhập OTP</h2>
        <div class="otp-steps">
          <div class="otp-step">
            <div class="otp-step__badge">1</div>
            <div>
              <div class="otp-step__title">Điền thông tin nhận mã</div>
              <p class="otp-step__desc">Nhập đúng email hoặc số điện thoại bạn muốn dùng cho tài khoản, sau đó hoàn thành captcha để hệ thống xác nhận đây là yêu cầu hợp lệ.</p>
            </div>
          </div>
          <div class="otp-step">
            <div class="otp-step__badge">2</div>
            <div>
              <div class="otp-step__title">Bấm nút lấy mã</div>
              <p class="otp-step__desc">Ở môi trường demo hiện tại, nút lấy mã sẽ tạo một OTP 6 số ngay trong phiên trình duyệt. Khi triển khai OTP thật, mã có thể được gửi qua email, SMS hoặc kênh xác thực đã cấu hình.</p>
            </div>
          </div>
          <div class="otp-step">
            <div class="otp-step__badge">3</div>
            <div>
              <div class="otp-step__title">Nhập OTP vào ô xác thực</div>
              <p class="otp-step__desc">Nhập chính xác 6 số vừa nhận. Với luồng chuẩn, mã OTP chỉ nên có hiệu lực trong thời gian ngắn, ví dụ 10 phút, và chỉ dùng cho một lần xác thực.</p>
            </div>
          </div>
          <div class="otp-step">
            <div class="otp-step__badge">4</div>
            <div>
              <div class="otp-step__title">Hoàn tất đăng ký</div>
              <p class="otp-step__desc">Khi captcha đúng, OTP hợp lệ và các điều khoản bắt buộc đã được đồng ý, bạn có thể gửi form để hoàn tất tạo tài khoản SkinSyntax.</p>
            </div>
          </div>
        </div>
      </section>

      <aside class="info-card">
        <h2>Lưu ý khi chưa nhận được mã</h2>
        <ul class="otp-list">
          <li>Kiểm tra lại email hoặc số điện thoại đã nhập, bảo đảm không sai ký tự và vẫn còn hoạt động.</li>
          <li>Nếu nhận mã qua email, hãy kiểm tra thêm thư mục Spam, Junk hoặc Promotions trước khi yêu cầu gửi lại.</li>
          <li>Nếu mạng yếu hoặc gửi lại quá nhiều lần liên tiếp, hệ thống có thể tạm chặn thao tác để bảo vệ tài khoản. Luồng an toàn nên giới hạn khoảng 3 lần gửi lại trong một khoảng thời gian ngắn.</li>
          <li>Không chia sẻ OTP cho bất kỳ ai. Nhân sự vận hành SkinSyntax không được phép yêu cầu bạn đọc mã OTP qua điện thoại, chat hay email.</li>
          <li>Trên bản demo hiện tại, việc tải lại trang sẽ tạo captcha mới; nếu cần tiếp tục, bạn chỉ việc bấm lấy mã lại trong popup.</li>
        </ul>

        <div class="otp-actions">
          <a class="btn btn-brand" href="<?= BASE_URL ?>/index.php?auth=register">Mở lại popup đăng ký</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/index.php?r=home">Quay về trang chủ</a>
        </div>
      </aside>
    </div>
  </div>
</div>