<?php
$avatarUser = current_user() ?? [];
$_SESSION['account_avatar_csrf'] ??= bin2hex(random_bytes(24));
$avatarUrl = account_avatar_url($avatarUser);
?>
<form class="border rounded-3 p-3 bg-white my-3" action="<?= h(BASE_URL . '/index.php?r=account_avatar') ?>" method="post" enctype="multipart/form-data" data-profile-async>
  <input type="hidden" name="csrf" value="<?= h($_SESSION['account_avatar_csrf']) ?>">
  <h2 class="fs-6 fw-bold">Ảnh đại diện</h2>
  <div class="d-flex align-items-center flex-wrap gap-3">
    <span class="profile-photo" aria-hidden="true">
      <i class="fa-solid fa-user"></i>
      <img data-account-avatar <?= $avatarUrl !== '' ? 'src="' . h($avatarUrl) . '"' : 'hidden' ?> alt="">
    </span>
    <label class="flex-grow-1">
      Chọn ảnh mới
      <input class="form-control mt-1" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
    </label>
    <button class="btn btn-brand" type="submit">Lưu ảnh</button>
  </div>
  <p class="small text-muted mt-2 mb-0">JPG, PNG hoặc WebP, tối đa 512 KB. Ảnh xuất hiện công khai cùng bình luận live của bạn.</p>
  <p class="small mb-0" data-profile-status role="status"></p>
</form>
<script src="<?= h(BASE_URL . '/assets/js/profile-actions.js?v=1') ?>" defer></script>
