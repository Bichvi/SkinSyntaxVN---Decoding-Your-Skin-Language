<div class="auth-wrap">
  <div class="auth-card">
    <h3>Đăng nhập</h3>

    <form method="post" action="<?= BASE_URL ?>/index.php?r=xulydangnhap">
      <div class="mb-3">
        <label class="form-label">Email</label>
        <input class="form-control" type="email" name="email" required>
      </div>

      <div class="mb-3">
        <label class="form-label">Mật khẩu</label>
        <input class="form-control" type="password" name="mat_khau" required>
      </div>

      <button class="btn btn-brand w-100" type="submit">Đăng nhập</button>

      <div class="text-center mt-3">
        Chưa có tài khoản?
        <a class="link-more" href="<?= BASE_URL ?>/index.php?r=dangky">Đăng ký</a>
      </div>
    </form>
  </div>
</div>
