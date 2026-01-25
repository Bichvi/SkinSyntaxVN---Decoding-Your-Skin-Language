<div class="auth-wrap">
  <div class="auth-card">
    <h3>Đăng ký</h3>

    <form method="post" action="<?= BASE_URL ?>/index.php?r=xulydangky">
      <div class="mb-3">
        <label class="form-label">Họ tên</label>
        <input class="form-control" type="text" name="ho_ten" required>
      </div>

      <div class="mb-3">
        <label class="form-label">Email</label>
        <input class="form-control" type="email" name="email" required>
      </div>

      <div class="mb-3">
        <label class="form-label">Mật khẩu</label>
        <input class="form-control" type="password" name="mat_khau" required>
      </div>

      <div class="mb-3">
        <label class="form-label">Nhập lại mật khẩu</label>
        <input class="form-control" type="password" name="mat_khau2" required>
      </div>

      <button class="btn btn-brand w-100" type="submit">Tạo tài khoản</button>

      <div class="text-center mt-3">
        Đã có tài khoản?
        <a class="link-more" href="<?= BASE_URL ?>/index.php?r=dangnhap">Đăng nhập</a>
      </div>
    </form>
  </div>
</div>
