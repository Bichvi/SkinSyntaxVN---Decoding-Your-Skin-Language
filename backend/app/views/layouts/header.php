<?php
// backend/app/views/layouts/header.php
$menuCats = $menuCats ?? [];
$currentUser = current_user();
$currentRole = strtolower(trim((string)($currentUser['role'] ?? $currentUser['vai_tro'] ?? 'khach_hang')));
$cartCount = 0;
foreach (($_SESSION['gio_hang'] ?? []) as $qty) {
  $cartCount += (int)$qty;
}
$quickCategories = array_slice(array_keys($menuCats), 0, 6);
$googleEnabled = defined('GOOGLE_OAUTH_CLIENT_ID') && defined('GOOGLE_OAUTH_CLIENT_SECRET')
  && trim((string)GOOGLE_OAUTH_CLIENT_ID) !== ''
  && trim((string)GOOGLE_OAUTH_CLIENT_SECRET) !== '';
$facebookEnabled = defined('FACEBOOK_OAUTH_CLIENT_ID') && defined('FACEBOOK_OAUTH_CLIENT_SECRET')
  && trim((string)FACEBOOK_OAUTH_CLIENT_ID) !== ''
  && trim((string)FACEBOOK_OAUTH_CLIENT_SECRET) !== '';
$authModalMode = strtolower(trim((string)($_GET['auth'] ?? '')));
$signupOld = $_SESSION['signup_old'] ?? [];
$signupCaptchaSeed = strtoupper(substr(bin2hex(random_bytes(4)), 0, 4));
$_SESSION['signup_captcha'] = strtolower($signupCaptchaSeed);
$signupDayOptions = range(1, 31);
$signupMonthOptions = range(1, 12);
$signupCurrentYear = (int)date('Y');
$signupYearOptions = range($signupCurrentYear, max(1950, $signupCurrentYear - 70));
$hasSignupOld = !empty($signupOld);
$flashSuccessMessage = get_flash('success');
$flashErrorMessage = get_flash('error');
$showAuthModalFlash = !is_logged_in() && in_array($authModalMode, ['login', 'register', 'forgot'], true);
$socialLinks = [
  ['label' => 'Facebook', 'icon' => 'fa-facebook-f', 'url' => 'https://www.facebook.com/conmeosuagaugauuu/'],
  ['label' => 'YouTube', 'icon' => 'fa-youtube', 'url' => 'https://www.youtube.com/@conmeosuagaugauuu'],
  ['label' => 'Instagram', 'icon' => 'fa-instagram', 'url' => 'https://www.instagram.com/bdefhijkp/'],
];
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SkinSyntax</title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="site-body">

<header class="site-header">
  <div class="promo-strip">
    <div class="container promo-strip__inner">
      <span><i class="fas fa-shield-heart"></i> Mỹ phẩm chọn theo nhu cầu da, ngân sách và routine</span>
      <span><i class="fas fa-badge-check"></i> Trải nghiệm mua sắm gọn, rõ và tập trung vào skincare</span>
      <span><i class="fas fa-sparkles"></i> Gợi ý cá nhân hóa theo khảo sát SkinSyntax</span>
    </div>
  </div>
  <div class="utility-strip">
    <div class="container utility-strip__inner">
      <div class="utility-links">
        <span class="utility-contact"><i class="fas fa-headset"></i> Hỗ trợ khách hàng: 1900 0000</span>
        <a href="<?= BASE_URL ?>/index.php?r=tatca">Tra cứu sản phẩm</a>
        <a href="<?= BASE_URL ?>/index.php?r=goiy">Routine AI</a>
        <a href="<?= BASE_URL ?>/index.php?r=he_thong_cua_hang">Hệ thống cửa hàng</a>
        <a href="<?= BASE_URL ?>/index.php?r=bao_hanh">Bảo hành</a>
        <a href="<?= BASE_URL ?>/index.php?r=ho_tro_khach_hang">Hỗ trợ khách hàng</a>
      </div>
      <div class="utility-links utility-links--social">
        <?php foreach ($socialLinks as $social): ?>
          <a class="utility-social-link" href="<?= h($social['url']) ?>" aria-label="<?= h($social['label']) ?>" target="_blank" rel="noopener noreferrer">
            <i class="fa-brands <?= h($social['icon']) ?>"></i>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if (is_logged_in()): ?>
        <div class="utility-links utility-links--account">
          <span class="utility-greeting">Xin chào, <?= h($currentUser['ho_ten'] ?? 'User') ?></span>
          <a href="<?= BASE_URL ?>/index.php?r=dangxuat">Đăng xuất</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="header-main">
    <div class="container header-main__grid">
      <a class="brand-lockup" href="<?= BASE_URL ?>/index.php">
        <span class="brand-lockup__mark">S</span>
        <span class="brand-lockup__copy">
          <strong>SkinSyntax</strong>
          <small>Decoding Your Skin Language</small>
        </span>
      </a>

      <form class="searchbar live-search-form header-search" method="get" action="<?= BASE_URL ?>/index.php" id="liveSearchForm" autocomplete="off" data-live-search-url="<?= BASE_URL ?>/index.php?r=live_search" data-smart-search-url="<?= BASE_URL ?>/index.php?r=api_smart_search">
        <input type="hidden" name="r" value="tatca">
        <div class="live-search-box header-search__box">
          <i class="fas fa-magnifying-glass header-search__icon"></i>
          <input class="form-control" name="q" id="search-input" placeholder="Tìm sản phẩm, thương hiệu, vấn đề da..." value="<?= h($_GET['q'] ?? '') ?>" aria-label="Tìm kiếm sản phẩm" aria-autocomplete="list" aria-expanded="false" aria-controls="smartSearchDropdown">
          <div class="smart-search-dropdown" id="smartSearchDropdown" role="listbox" aria-label="Gợi ý sản phẩm" hidden></div>
          </div>
        <button class="btn btn-brand header-search__submit" type="submit">Tìm kiếm</button>
      </form>

      <div class="header-actions">
        <a href="<?= BASE_URL ?>/index.php?r=goiy" class="header-action-card">
          <i class="fas fa-wand-magic-sparkles"></i>
          <span>
            <strong>Routine AI</strong>
            <small>Nhận gợi ý ngay</small>
          </span>
        </a>

        <?php if (is_logged_in()): ?>
          <a href="<?= BASE_URL ?>/index.php?r=<?= h($currentRole === 'admin' ? 'admin_dashboard' : ($currentRole === 'nhanvien' ? 'staff_dashboard' : 'hoso')) ?>" class="header-icon-link" title="Tài khoản">
            <i class="fas fa-user"></i>
            <span><?= h($currentRole === 'admin' ? 'Quản trị' : ($currentRole === 'nhanvien' ? 'Nhân viên' : 'Tài khoản')) ?></span>
          </a>
        <?php else: ?>
          <a href="#" class="header-icon-link" title="Đăng nhập" data-bs-toggle="modal" data-bs-target="#authModal" data-auth-tab="login">
            <i class="fas fa-user"></i>
            <span>Đăng nhập</span>
          </a>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/index.php?r=giohang" class="header-icon-link header-icon-link--cart" title="Giỏ hàng">
          <i class="fas fa-bag-shopping"></i>
          <span>Giỏ hàng</span>
          <?php if ($cartCount > 0): ?>
            <em class="header-cart-badge"><?= $cartCount ?></em>
          <?php endif; ?>
        </a>
      </div>
    </div>
  </div>

  <div class="header-nav-shell">
    <div class="container header-nav">
      <div class="dropdown header-catalog">
        <a class="header-catalog__toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="fas fa-bars"></i>
          <span>Danh mục sản phẩm</span>
        </a>

        <div class="dropdown-menu mega-menu header-mega-menu p-3">
          <div class="row g-3 align-items-stretch">
            <div class="col-lg-3">
              <div class="mega-menu-highlight">
                <span class="mega-menu-highlight__eyebrow">Danh mục nổi bật</span>
                <h5>Khám phá từng nhóm skincare theo đúng nhu cầu da.</h5>
                <p>Từ làm sạch, dưỡng ẩm đến đặc trị, mọi danh mục đều được gom lại để bạn lọc nhanh hơn.</p>
                <a href="<?= BASE_URL ?>/index.php?r=goiy" class="mega-menu-highlight__link">Mở gợi ý routine</a>
              </div>
            </div>
            <div class="col-lg-9">
              <div class="row g-3">
            <?php foreach ($menuCats as $c1 => $c2s): ?>
              <div class="col-6 col-lg-4">
                <div class="col-title"><?= h($c1) ?></div>
                <?php if (!empty($c2s)): ?>
                  <ul class="list-unstyled mb-0">
                    <?php foreach ($c2s as $c2 => $cnt): ?>
                      <li>
                        <a class="dropdown-item" href="<?= BASE_URL ?>/index.php?r=tatca&cap1=<?= urlencode($c1) ?>&cap2=<?= urlencode($c2) ?>">
                          <?= h($c2) ?> (<?= (int)$cnt ?>)
                        </a>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <a class="dropdown-item" href="<?= BASE_URL ?>/index.php?r=tatca&cap1=<?= urlencode($c1) ?>">
                    Xem tất cả
                  </a>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <nav class="header-shortcuts" aria-label="Liên kết nhanh">
        <a class="header-shortcuts__link" href="<?= BASE_URL ?>/index.php?r=home">Trang chủ</a>
        <a class="header-shortcuts__link" href="<?= BASE_URL ?>/index.php?r=tatca">Tất cả sản phẩm</a>
        <a class="header-shortcuts__link fw-bold d-inline-flex align-items-center gap-1" href="<?= BASE_URL ?>/index.php?r=live" style="background: #FFE4E6; color: #E11D48 !important; padding: 4px 12px; border-radius: 999px; font-size: 0.84rem;">
          <span class="d-inline-block rounded-circle bg-danger" style="width: 7px; height: 7px;"></span>
          <i class="fa-solid fa-video me-1"></i> LiveStream AI
        </a>
        <?php foreach ($quickCategories as $category): ?>
          <a class="header-shortcuts__link" href="<?= BASE_URL ?>/index.php?r=tatca&cap1=<?= urlencode((string)$category) ?>">
            <?= h((string)$category) ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <a class="header-deal-pill" href="<?= BASE_URL ?>/index.php?r=goiy">
        <i class="fas fa-gift"></i>
        <span>Khảo sát da để mở routine cá nhân</span>
      </a>
    </div>
</header>

<script>
(function() {
  const header = document.querySelector('.site-header');
  if (!header) return;
  const onScroll = () => {
    if (window.scrollY > 30) {
      header.classList.add('is-scrolled');
    } else {
      header.classList.remove('is-scrolled');
    }
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
})();
</script>

<?php if (!is_logged_in()): ?>
  <div class="modal fade" id="authModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content auth-modal-shell border-0 overflow-hidden <?= $authModalMode === 'register' ? 'is-register' : '' ?>" style="max-width: 920px; border-radius: 28px; box-shadow: 0 24px 70px rgba(15, 23, 42, 0.22);">
        <button type="button" class="auth-modal-close" data-bs-dismiss="modal" aria-label="Close" style="z-index: 10; position: absolute; top: 16px; right: 16px; width: 38px; height: 38px; border-radius: 50%; background: rgba(0,0,0,0.06); border: 0; display: grid; place-items: center; color: #1A2F1A;">
          <i class="fas fa-xmark"></i>
        </button>
        <div class="modal-body p-0">
          <div class="auth-row-container row g-0 align-items-stretch">
            
            <!-- Panel Visual Xanh (AsmrProg Toggle Overlay) -->
            <div class="col-lg-5 d-none d-lg-flex flex-column justify-content-between p-4 text-white auth-visual-panel" style="background: #215427;">
              
              <!-- Toggle Content: Đang ở màn Đăng nhập -> Gợi ý Tạo Tài Khoản -->
              <div class="toggle-panel-content toggle-panel--login flex-column justify-content-between h-100">
                <div>
                  <a class="brand-lockup mb-4" href="<?= BASE_URL ?>/index.php" style="display: inline-flex; align-items: center; gap: 10px;">
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

                  <button class="btn btn-light w-100 py-3 fw-bold mt-4" data-auth-switch="register" style="border-radius: 999px; color: #215427; background: #FFFFFF; font-size: 0.95rem; border: none;">
                    Tạo Tài Khoản Mới <i class="fas fa-arrow-right ms-2"></i>
                  </button>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-3" style="border-top: 1px solid rgba(255,255,255,0.18); font-size: 0.78rem; color: #D2E5D5;">
                  <span><i class="fas fa-shield-check me-1"></i> 100% Thuần Chay</span>
                  <span><i class="fas fa-heart me-1"></i> RAG AI Advisor</span>
                </div>
              </div>

              <!-- Toggle Content: Đang ở màn Đăng ký -> Gợi ý Đăng Nhập -->
              <div class="toggle-panel-content toggle-panel--register flex-column justify-content-between h-100">
                <div>
                  <a class="brand-lockup mb-4" href="<?= BASE_URL ?>/index.php" style="display: inline-flex; align-items: center; gap: 10px;">
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

                  <button class="btn btn-light w-100 py-3 fw-bold mt-4" data-auth-switch="login" style="border-radius: 999px; color: #215427; background: #FFFFFF; font-size: 0.95rem; border: none;">
                    Đăng Nhập Ngay <i class="fas fa-arrow-right ms-2"></i>
                  </button>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-3" style="border-top: 1px solid rgba(255,255,255,0.18); font-size: 0.78rem; color: #D2E5D5;">
                  <span><i class="fas fa-shield-check me-1"></i> 100% Thuần Chay</span>
                  <span><i class="fas fa-heart me-1"></i> RAG AI Advisor</span>
                </div>
              </div>

            </div>

            <!-- Panel Controls Trắng (Forms Input) -->
            <div class="col-lg-7 bg-white p-4 p-md-5 auth-forms-panel">
              <div class="auth-modal-body p-0">
                
                <!-- LOGIN FORM -->
                <div class="auth-modal-panel <?= $authModalMode === 'forgot' || $authModalMode === 'register' ? 'd-none' : '' ?>" data-auth-panel="login">
                  <h3 class="fw-bold mb-1" style="font-size: 1.8rem; color: #1A2F1A;">Đăng Nhập</h3>
                  <p style="color: #5C705E; font-size: 0.88rem; margin-bottom: 20px;">Đăng nhập với mạng xã hội hoặc tài khoản email của bạn.</p>
                  
                  <?php if ($showAuthModalFlash && $authModalMode === 'login' && ($flashErrorMessage || $flashSuccessMessage)): ?>
                    <div class="alert alert-<?= $flashErrorMessage ? 'danger' : 'success' ?> auth-modal-alert mb-3" role="alert">
                      <?= h($flashErrorMessage ?: $flashSuccessMessage) ?>
                    </div>
                  <?php endif; ?>

                  <div class="row g-2 mb-3">
                    <div class="col-6">
                      <a class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2 py-2.5 <?= $googleEnabled ? '' : 'disabled' ?>" href="<?= $googleEnabled ? (BASE_URL . '/index.php?r=auth_social&provider=google&oauth_mode=real') : '#' ?>" style="border-radius: 999px; font-weight: 700; border-color: #E2EADF; color: #1A2F1A; background: #FFF;">
                        <i class="fa-brands fa-google text-danger"></i>
                        <span>Google</span>
                      </a>
                    </div>
                    <div class="col-6">
                      <a class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-2 py-2.5 <?= $facebookEnabled ? '' : 'disabled' ?>" href="<?= $facebookEnabled ? (BASE_URL . '/index.php?r=auth_social&provider=facebook') : '#' ?>" style="border-radius: 999px; font-weight: 700; background: #3b5998; border: none; color: #fff;">
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
                        <input class="form-control" type="password" id="authLoginPassword" name="mat_khau" placeholder="Nhập password" style="border-radius: 999px; padding: 12px 44px; background: #F8FAF8; border-color: #E2EADF;" required>
                        <i class="fa-solid fa-lock position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                        <button class="btn btn-link position-absolute top-50 end-0 translate-middle-y me-2 text-muted p-0 border-0" type="button" data-password-toggle data-target="authLoginPassword" aria-label="Hiện hoặc ẩn mật khẩu">
                          <i class="fa-regular fa-eye"></i>
                        </button>
                      </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-4">
                      <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="remember_login" value="1" id="rememberLoginModal">
                        <label class="form-check-label small" for="rememberLoginModal" style="color: #5C705E;">Nhớ mật khẩu</label>
                      </div>
                      <a href="#" class="small fw-bold" data-auth-switch="forgot" style="color: #215427; text-decoration: none;">Quên mật khẩu</a>
                    </div>

                    <button class="btn w-100 py-3 fw-bold" type="submit" style="background: #215427; color: #fff; border-radius: 999px; font-size: 1rem; border: none;">Đăng nhập</button>
                  </form>
                </div>

                <!-- REGISTER FORM -->
                <div class="auth-modal-panel <?= $authModalMode === 'register' ? '' : 'd-none' ?>" data-auth-panel="register">
                  <h3 class="fw-bold mb-1" style="font-size: 1.8rem; color: #1A2F1A;">Đăng Ký Tài Khoản</h3>
                  <p style="color: #5C705E; font-size: 0.88rem; margin-bottom: 20px;">Tạo tài khoản SkinSyntax để lưu đơn hàng, routine và nhận gợi ý cá nhân hóa.</p>
                  
                  <?php if ($showAuthModalFlash && $authModalMode === 'register' && ($flashErrorMessage || $flashSuccessMessage)): ?>
                    <div class="alert alert-<?= $flashErrorMessage ? 'danger' : 'success' ?> auth-modal-alert mb-3" role="alert">
                      <?= h($flashErrorMessage ?: $flashSuccessMessage) ?>
                    </div>
                  <?php endif; ?>

                  <form method="post" action="<?= BASE_URL ?>/index.php?r=xulydangky" id="authRegisterForm" novalidate>
                    <div class="mb-2">
                      <input class="form-control" type="email" name="email" value="<?= h((string)($signupOld['email'] ?? '')) ?>" placeholder="Nhập email" style="border-radius: 999px; padding: 12px 18px; background: #F8FAF8; border-color: #E2EADF;" required>
                    </div>

                    <div class="alert alert-danger auth-modal-alert d-none mb-2" id="authRegisterFeedback" role="alert"></div>

                    <div class="d-flex gap-2 mb-2">
                      <input class="form-control" type="text" id="authRegisterCaptchaInput" name="captcha" placeholder="Nhập captcha" autocomplete="off" style="border-radius: 999px; padding: 12px 18px; background: #F8FAF8; border-color: #E2EADF;" required>
                      <div class="d-flex align-items-center justify-content-center text-white fw-bold px-3" id="authRegisterCaptchaCode" data-captcha="<?= h(strtolower($signupCaptchaSeed)) ?>" style="background: #215427; border-radius: 999px; font-family: monospace; letter-spacing: 2px; min-width: 90px;"><?= h(strtolower($signupCaptchaSeed)) ?></div>
                      <button class="btn btn-outline-secondary px-3" id="authRegisterCaptchaRefresh" type="button" aria-label="Làm mới captcha" style="border-radius: 50%; width: 44px; height: 44px; display: grid; place-items: center; border-color: #E2EADF;">
                        <i class="fa-solid fa-rotate-right"></i>
                      </button>
                    </div>

                    <div class="d-flex gap-2 mb-2">
                      <input class="form-control" type="text" id="authRegisterOtpInput" name="otp" inputmode="numeric" maxlength="6" placeholder="Nhập mã xác thực 6 số" autocomplete="one-time-code" style="border-radius: 999px; padding: 12px 18px; background: #F8FAF8; border-color: #E2EADF;" required>
                      <button class="btn fw-bold text-white px-4" id="authRegisterOtpButton" type="button" style="background: #215427; border-radius: 999px; white-space: nowrap;">Lấy mã</button>
                    </div>
                    <div class="auth-register-note mb-2" id="authRegisterOtpHint" style="font-size: 0.78rem; color: #5C705E;">Nhập đúng email, captcha rồi bấm Lấy mã.</div>

                    <div class="row g-2 mb-2">
                      <div class="col-6">
                        <input class="form-control" type="password" id="authRegisterPassword" name="mat_khau" placeholder="Mật khẩu 8 - 32 ký tự" minlength="8" maxlength="32" autocomplete="new-password" style="border-radius: 999px; padding: 12px 18px; background: #F8FAF8; border-color: #E2EADF;" required>
                      </div>
                      <div class="col-6">
                        <input class="form-control" type="password" id="authRegisterPasswordConfirm" name="mat_khau2" placeholder="Nhập lại mật khẩu" minlength="8" maxlength="32" autocomplete="new-password" style="border-radius: 999px; padding: 12px 18px; background: #F8FAF8; border-color: #E2EADF;" required>
                      </div>
                    </div>

                    <div class="mb-2">
                      <input class="form-control" type="text" name="ho_ten" value="<?= h((string)($signupOld['ho_ten'] ?? '')) ?>" placeholder="Họ tên" style="border-radius: 999px; padding: 12px 18px; background: #F8FAF8; border-color: #E2EADF;" required>
                    </div>

                    <div class="d-flex align-items-center gap-3 mb-2 small" style="color: #5C705E;">
                      <label class="form-check mb-0">
                        <input class="form-check-input" type="radio" name="gioi_tinh" value="Khong xac dinh" <?= (($signupOld['gioi_tinh'] ?? '') === 'Khong xac dinh' || empty($signupOld['gioi_tinh'])) ? 'checked' : '' ?>>
                        <span>Không xác định</span>
                      </label>
                      <label class="form-check mb-0">
                        <input class="form-check-input" type="radio" name="gioi_tinh" value="Nam" <?= (($signupOld['gioi_tinh'] ?? '') === 'Nam') ? 'checked' : '' ?>>
                        <span>Nam</span>
                      </label>
                      <label class="form-check mb-0">
                        <input class="form-check-input" type="radio" name="gioi_tinh" value="Nữ" <?= (($signupOld['gioi_tinh'] ?? '') === 'Nữ') ? 'checked' : '' ?>>
                        <span>Nữ</span>
                      </label>
                    </div>

                    <div class="row g-2 mb-3">
                      <div class="col-4">
                        <select class="form-select" name="ngay_sinh" style="border-radius: 999px; padding: 10px 14px; background: #F8FAF8; border-color: #E2EADF; font-size: 0.85rem;">
                          <option value="">Ngày</option>
                          <?php foreach ($signupDayOptions as $day): ?>
                            <option value="<?= $day ?>" <?= ((string)($signupOld['ngay_sinh'] ?? '') === (string)$day) ? 'selected' : '' ?>><?= $day ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-4">
                        <select class="form-select" name="thang_sinh" style="border-radius: 999px; padding: 10px 14px; background: #F8FAF8; border-color: #E2EADF; font-size: 0.85rem;">
                          <option value="">Tháng</option>
                          <?php foreach ($signupMonthOptions as $month): ?>
                            <option value="<?= $month ?>" <?= ((string)($signupOld['thang_sinh'] ?? '') === (string)$month) ? 'selected' : '' ?>><?= $month ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-4">
                        <select class="form-select" name="nam_sinh" style="border-radius: 999px; padding: 10px 14px; background: #F8FAF8; border-color: #E2EADF; font-size: 0.85rem;">
                          <option value="">Năm</option>
                          <?php foreach ($signupYearOptions as $year): ?>
                            <option value="<?= $year ?>" <?= ((string)($signupOld['nam_sinh'] ?? '') === (string)$year) ? 'selected' : '' ?>><?= $year ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>

                    <div class="mb-3 small" style="color: #5C705E;">
                      <label class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="terms_agree" value="1" <?= (($signupOld['terms_agree'] ?? '') === '1') ? 'checked' : '' ?> required>
                        <span>Tôi đã đọc và đồng ý với <strong>Điều kiện giao dịch chung</strong> và <strong>Chính sách bảo mật thông tin</strong>.</span>
                      </label>
                      <label class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="email_opt_in" value="1" <?= !isset($signupOld['email_opt_in']) || ($signupOld['email_opt_in'] ?? '') === '1' ? 'checked' : '' ?>>
                        <span>Nhận thông tin khuyến mãi qua e-mail</span>
                      </label>
                      <label class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="privacy_consent" value="1" <?= (($signupOld['privacy_consent'] ?? '') === '1') ? 'checked' : '' ?> required>
                        <span>Tôi đồng ý với <strong>chính sách xử lý dữ liệu cá nhân</strong> của SkinSyntax.</span>
                      </label>
                    </div>

                    <button class="btn w-100 py-3 fw-bold" type="submit" style="background: #215427; color: #fff; border-radius: 999px; font-size: 1rem; border: none;">Đăng ký</button>
                  </form>
                </div>

                <!-- FORGOT PASSWORD FORM -->
                <div class="auth-modal-panel <?= $authModalMode === 'forgot' ? '' : 'd-none' ?>" data-auth-panel="forgot">
                  <h3 class="fw-bold mb-1" style="font-size: 1.8rem; color: #1A2F1A;">Quên Mật Khẩu</h3>
                  <p style="color: #5C705E; font-size: 0.88rem; margin-bottom: 20px;">Nhập email đã đăng ký. Hệ thống sẽ gửi liên kết đặt lại mật khẩu về hộp thư của bạn.</p>
                  
                  <?php if ($showAuthModalFlash && $authModalMode === 'forgot' && ($flashErrorMessage || $flashSuccessMessage)): ?>
                    <div class="alert alert-<?= $flashErrorMessage ? 'danger' : 'success' ?> auth-modal-alert mb-3" role="alert">
                      <?= h($flashErrorMessage ?: $flashSuccessMessage) ?>
                    </div>
                  <?php endif; ?>
                  
                  <form method="post" action="<?= BASE_URL ?>/index.php?r=gui_lien_ket_dat_lai">
                    <div class="mb-3">
                      <label class="form-label text-uppercase fw-bold small mb-1" style="color: #1A2F1A; font-size: 0.76rem; letter-spacing: 0.05em;">NHẬP EMAIL</label>
                      <input class="form-control" type="email" name="email" placeholder="Nhập email của bạn" style="border-radius: 999px; padding: 12px 18px; background: #F8FAF8; border-color: #E2EADF;" required>
                    </div>
                    <button class="btn w-100 py-3 fw-bold" type="submit" style="background: #215427; color: #fff; border-radius: 999px; font-size: 1rem; border: none;">Gửi liên kết đặt lại</button>
                  </form>
                  <div class="text-center mt-3 small">
                    <a href="#" class="fw-bold" data-auth-switch="login" style="color: #215427; text-decoration: none;">Quay lại đăng nhập</a>
                  </div>
                </div>

              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>

  <style>
    /* AsmrProg Modern-Login Silky Smooth Sliding Animation */
    .auth-row-container {
      position: relative;
      overflow: hidden;
      min-height: 560px;
    }

    @media (min-width: 992px) {
      .auth-row-container {
        display: flex;
      }
      
      .auth-visual-panel {
        position: absolute;
        top: 0;
        left: 0;
        width: 41.666667%;
        height: 100%;
        z-index: 10;
        transition: transform 0.6s cubic-bezier(0.65, 0, 0.35, 1);
        box-shadow: 0 0 35px rgba(0, 0, 0, 0.15);
      }

      .auth-forms-panel {
        width: 58.333333%;
        margin-left: 41.666667%;
        transition: margin-left 0.6s cubic-bezier(0.65, 0, 0.35, 1), transform 0.6s cubic-bezier(0.65, 0, 0.35, 1);
        z-index: 1;
      }

      /* Slide Green Panel to Right, Forms to Left in Register Mode */
      .auth-modal-shell.is-register .auth-visual-panel {
        transform: translateX(140%);
      }

      .auth-modal-shell.is-register .auth-forms-panel {
        margin-left: 0;
      }
    }

    /* Smooth Fade & Slide for Green Panel Text Contents */
    .toggle-panel-content {
      transition: opacity 0.45s ease-in-out, transform 0.5s cubic-bezier(0.65, 0, 0.35, 1);
    }

    .toggle-panel--login {
      opacity: 1;
      transform: translateX(0);
    }

    .toggle-panel--register {
      opacity: 0;
      transform: translateX(30px);
      position: absolute;
      top: 1.5rem;
      left: 1.5rem;
      right: 1.5rem;
      bottom: 1.5rem;
      pointer-events: none;
    }

    .auth-modal-shell.is-register .toggle-panel--login {
      opacity: 0;
      transform: translateX(-30px);
      pointer-events: none;
    }

    .auth-modal-shell.is-register .toggle-panel--register {
      opacity: 1;
      transform: translateX(0);
      pointer-events: auto;
    }

    /* Soft entrance animation for Form Panels */
    .auth-modal-panel {
      animation: authFormSlideIn 0.45s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    @keyframes authFormSlideIn {
      from {
        opacity: 0;
        transform: translateY(10px) scale(0.98);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }
  </style>

  <style>
    .auth-modal-shell {
      max-width: 620px;
      margin: 0 auto;
      border-radius: 28px;
      box-shadow: 0 24px 70px rgba(15, 23, 42, 0.18);
    }

    .auth-modal-close {
      position: absolute;
      top: 16px;
      right: 16px;
      width: 42px;
      height: 42px;
      border: 0;
      border-radius: 999px;
      background: rgba(15, 23, 42, 0.16);
      color: #fff;
      z-index: 2;
    }

    .auth-modal-body {
      padding: 32px 28px 28px;
      background: #fff;
    }

    .auth-modal-title {
      font-size: 1.95rem;
      font-weight: 800;
      margin-bottom: 6px;
      color: #0f172a;
    }

    .auth-modal-subtitle {
      color: #64748b;
      margin-bottom: 18px;
      line-height: 1.6;
    }

    .auth-modal-socials {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }

    .auth-social-btn {
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

    .auth-social-btn--facebook {
      background: #32539f;
      border-color: #32539f;
      color: #fff;
    }

    .auth-social-btn--disabled {
      opacity: .68;
    }

    .auth-config-hint {
      margin-top: 10px;
      font-size: 0.86rem;
      color: #64748b;
    }

    .auth-modal-divider {
      position: relative;
      margin: 20px 0;
      text-align: center;
      color: #64748b;
    }

    .auth-modal-divider::before {
      content: '';
      position: absolute;
      top: 50%; left: 0; right: 0;
      border-top: 1px solid #e2e8f0;
    }

    .auth-modal-divider span {
      position: relative;
      background: #fff;
      padding: 0 12px;
    }

    .auth-modal-input {
      border-radius: 999px;
      min-height: 54px;
      padding-left: 18px;
      background: #f8fafc;
      border-color: #e4e7ec;
    }

    .auth-modal-input:focus {
      border-color: var(--brand);
      box-shadow: 0 0 0 .2rem rgba(15,107,62,.12);
    }

    .auth-modal-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      margin-bottom: 18px;
    }

    .auth-modal-check {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: #334155;
      font-size: 0.95rem;
    }

    .auth-modal-link {
      color: #0f6b3e;
      font-weight: 700;
    }

    .auth-modal-submit {
      width: 100%;
      min-height: 54px;
      font-size: 1.05rem;
      font-weight: 800;
    }

    .auth-modal-alert {
      margin-bottom: 14px;
      border-radius: 16px;
    }

    .auth-register-field {
      position: relative;
    }

    .auth-register-icon {
      position: absolute;
      top: 50%;
      right: 18px;
      transform: translateY(-50%);
      color: #64748b;
      pointer-events: none;
    }

    .auth-password-field .auth-modal-input {
      padding-right: 52px;
    }

    .auth-password-toggle {
      position: absolute;
      top: 50%;
      right: 12px;
      transform: translateY(-50%);
      width: 36px;
      height: 36px;
      border: 0;
      border-radius: 999px;
      background: transparent;
      color: #475569;
      z-index: 1;
    }

    .auth-password-toggle:hover {
      background: rgba(148, 163, 184, 0.14);
    }

    .auth-register-inline {
      display: grid;
      grid-template-columns: 1fr 118px;
      gap: 0;
      margin-bottom: 8px;
    }

    .auth-register-inline--captcha {
      grid-template-columns: 1fr 118px 54px;
    }

    .auth-register-inline .auth-modal-input {
      border-top-right-radius: 0;
      border-bottom-right-radius: 0;
    }

    .auth-register-inline--captcha .auth-register-captcha {
      border-right: 1px solid #d7deea;
    }

    .auth-register-captcha,
    .auth-register-otp-button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 54px;
      padding: 0 14px;
      font-weight: 800;
    }

    .auth-register-captcha {
      background: #2f7b54;
      color: #fff;
      letter-spacing: .28em;
      text-transform: lowercase;
    }

    .auth-register-otp-button {
      border: 0;
      background: #d1d5db;
      color: #475569;
    }

    .auth-register-captcha-refresh {
      border: 1px solid #d7deea;
      border-left: 0;
      background: #f8fafc;
      color: #475569;
    }

    .auth-register-captcha-refresh:disabled,
    .auth-register-otp-button:disabled {
      opacity: .72;
      cursor: not-allowed;
    }

    .auth-register-helper-link {
      display: inline-block;
      margin-bottom: 6px;
    }

    .auth-register-note {
      margin-bottom: 14px;
      color: #64748b;
      font-size: .86rem;
      line-height: 1.5;
    }

    .auth-register-note--password {
      margin-top: 0;
    }

    .auth-register-note--error {
      color: #b42318;
    }

    .auth-register-gender {
      display: flex;
      flex-wrap: wrap;
      gap: 18px;
      margin-bottom: 16px;
      color: #334155;
    }

    .auth-register-gender__item {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: .96rem;
    }

    .auth-register-birthday {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
      margin-bottom: 16px;
    }

    .auth-register-birthday .auth-modal-input {
      border-radius: 14px;
      padding-right: 14px;
    }

    .auth-register-checks {
      display: grid;
      gap: 10px;
      margin-bottom: 16px;
    }

    .auth-register-check {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      color: #334155;
      font-size: .92rem;
      line-height: 1.6;
    }

    @media (max-width: 991.98px) {
      .auth-modal-body {
        padding: 28px 22px 24px;
      }
    }

    @media (max-width: 575.98px) {
      .auth-modal-socials {
        grid-template-columns: 1fr;
      }

      .auth-modal-title {
        font-size: 1.6rem;
      }

      .auth-modal-row {
        flex-direction: column;
        align-items: flex-start;
      }

      .auth-register-inline,
      .auth-register-birthday {
        grid-template-columns: 1fr;
      }

      .auth-register-inline .auth-modal-input {
        border-radius: 999px;
      }
    }
  </style>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var authModalElement = document.getElementById('authModal');
      if (!authModalElement || typeof bootstrap === 'undefined') {
        return;
      }

      var authModal = new bootstrap.Modal(authModalElement);
      var panels = authModalElement.querySelectorAll('[data-auth-panel]');
      var switchers = authModalElement.querySelectorAll('[data-auth-switch]');
      var triggers = document.querySelectorAll('[data-auth-tab]');
      var initialMode = <?= json_encode($authModalMode) ?>;
      var registerForm = document.getElementById('authRegisterForm');
      var registerCaptchaElement = document.getElementById('authRegisterCaptchaCode');
      var registerCaptchaInput = document.getElementById('authRegisterCaptchaInput');
      var registerOtpInput = document.getElementById('authRegisterOtpInput');
      var registerOtpButton = document.getElementById('authRegisterOtpButton');
      var registerOtpHint = document.getElementById('authRegisterOtpHint');
      var registerEmailInput = registerForm ? registerForm.querySelector('input[name="email"]') : null;
      var registerFeedback = document.getElementById('authRegisterFeedback');
      var registerCaptchaRefreshButton = document.getElementById('authRegisterCaptchaRefresh');
      var registerPasswordInput = document.getElementById('authRegisterPassword');
      var registerPasswordConfirmInput = document.getElementById('authRegisterPasswordConfirm');
      var registerPasswordHint = document.getElementById('authRegisterPasswordHint');
      var passwordToggles = authModalElement.querySelectorAll('[data-password-toggle]');
      var registerDraftKey = 'skinsyntaxRegisterDraft';
      var registerPasswordPattern = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,32}$/;
      var hasServerRegisterState = <?= $hasSignupOld ? 'true' : 'false' ?>;
      var signupOtpUrl = <?= json_encode(BASE_URL . '/index.php?r=gui_otp_dang_ky') ?>;
      var signupCaptchaUrl = <?= json_encode(BASE_URL . '/index.php?r=gui_captcha_dang_ky') ?>;
      var otpCooldownSeconds = 0;
      var otpCooldownTimer = null;

      var setRegisterFeedback = function (message, type) {
        if (!registerFeedback) {
          return;
        }

        registerFeedback.textContent = message || '';
        registerFeedback.classList.remove('d-none', 'alert-success', 'alert-danger');
        registerFeedback.classList.add(type === 'success' ? 'alert-success' : 'alert-danger');

        if (!message) {
          registerFeedback.classList.add('d-none');
        }
      };

      var updateCaptcha = function (captcha) {
        if (!registerCaptchaElement || typeof captcha !== 'string' || captcha.trim() === '') {
          return;
        }

        var normalizedCaptcha = captcha.trim().toLowerCase();
        registerCaptchaElement.setAttribute('data-captcha', normalizedCaptcha);
        registerCaptchaElement.textContent = normalizedCaptcha;
        if (registerCaptchaInput) {
          registerCaptchaInput.value = '';
        }
      };

      var updateOtpButtonState = function () {
        if (!registerOtpButton) {
          return;
        }

        if (otpCooldownSeconds > 0) {
          registerOtpButton.disabled = true;
          registerOtpButton.textContent = otpCooldownSeconds + 's';
          return;
        }

        registerOtpButton.disabled = false;
        registerOtpButton.textContent = 'Lấy mã';
      };

      var startOtpCooldown = function (seconds) {
        otpCooldownSeconds = Math.max(0, parseInt(seconds, 10) || 0);
        updateOtpButtonState();

        if (otpCooldownTimer) {
          window.clearInterval(otpCooldownTimer);
          otpCooldownTimer = null;
        }

        if (otpCooldownSeconds <= 0) {
          return;
        }

        otpCooldownTimer = window.setInterval(function () {
          otpCooldownSeconds -= 1;
          if (otpCooldownSeconds <= 0) {
            otpCooldownSeconds = 0;
            window.clearInterval(otpCooldownTimer);
            otpCooldownTimer = null;
          }
          updateOtpButtonState();
        }, 1000);
      };

      var requestSignupCaptcha = function () {
        if (!registerCaptchaRefreshButton) {
          return Promise.resolve();
        }

        registerCaptchaRefreshButton.disabled = true;
        return window.fetch(signupCaptchaUrl, {
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json'
          }
        })
          .then(function (response) { return response.json(); })
          .then(function (payload) {
            if (payload && payload.ok && payload.captcha) {
              updateCaptcha(payload.captcha);
            }
          })
          .catch(function () {
            setRegisterFeedback('Không thể làm mới captcha lúc này. Vui lòng thử lại.', 'danger');
          })
          .finally(function () {
            registerCaptchaRefreshButton.disabled = false;
          });
      };

      var persistRegisterDraft = function () {
        if (!registerForm || typeof sessionStorage === 'undefined') {
          return;
        }

        var draft = {
          fields: {}
        };

        Array.prototype.forEach.call(registerForm.elements, function (field) {
          if (!field || field.type === 'submit' || field.type === 'button' || field.type === 'fieldset') {
            return;
          }

          if (field.type === 'password' || field.name === 'captcha' || field.name === 'otp') {
            return;
          }

          var fieldKey = field.name || field.id;
          if (!fieldKey) {
            return;
          }

          if (field.type === 'checkbox') {
            draft.fields[fieldKey] = field.checked ? '1' : '0';
            return;
          }

          if (field.type === 'radio') {
            if (field.checked) {
              draft.fields[field.name] = field.value;
            }
            return;
          }

          draft.fields[fieldKey] = field.value;
        });

        sessionStorage.setItem(registerDraftKey, JSON.stringify(draft));
      };

      var restoreRegisterDraft = function () {
        if (!registerForm || typeof sessionStorage === 'undefined') {
          return;
        }

        var rawDraft = sessionStorage.getItem(registerDraftKey);
        if (!rawDraft) {
          return;
        }

        try {
          var draft = JSON.parse(rawDraft);
          var fields = draft.fields || {};

          Array.prototype.forEach.call(registerForm.elements, function (field) {
            if (!field || field.type === 'submit' || field.type === 'button' || field.type === 'fieldset') {
              return;
            }

            var fieldKey = field.name || field.id;
            if (!fieldKey || typeof fields[fieldKey] === 'undefined') {
              return;
            }

            if (field.type === 'checkbox') {
              field.checked = fields[fieldKey] === '1';
              return;
            }

            if (field.type === 'radio') {
              field.checked = fields[field.name] === field.value;
              return;
            }

            field.value = fields[fieldKey];
          });
        } catch (error) {
          sessionStorage.removeItem(registerDraftKey);
        }
      };

      var syncPasswordHint = function () {
        if (!registerPasswordInput || !registerPasswordHint) {
          return true;
        }

        var passwordValue = registerPasswordInput.value || '';
        var isValid = passwordValue === '' || registerPasswordPattern.test(passwordValue);
        registerPasswordInput.setCustomValidity(isValid ? '' : 'Mật khẩu chưa đủ mạnh.');

        if (passwordValue === '') {
          registerPasswordHint.textContent = 'Mật khẩu phải có chữ in hoa, chữ in thường, số và ký tự đặc biệt.';
          registerPasswordHint.classList.remove('auth-register-note--error');
          return true;
        }

        if (!isValid) {
          registerPasswordHint.textContent = 'Mật khẩu cần 8-32 ký tự và phải có chữ in hoa, chữ in thường, số, ký tự đặc biệt.';
          registerPasswordHint.classList.add('auth-register-note--error');
          return false;
        }

        registerPasswordHint.textContent = 'Mật khẩu đạt yêu cầu bảo mật.';
        registerPasswordHint.classList.remove('auth-register-note--error');
        return true;
      };

      var syncPasswordConfirmation = function () {
        if (!registerPasswordInput || !registerPasswordConfirmInput) {
          return true;
        }

        var matches = registerPasswordConfirmInput.value === '' || registerPasswordInput.value === registerPasswordConfirmInput.value;
        registerPasswordConfirmInput.setCustomValidity(matches ? '' : 'Mật khẩu nhập lại không khớp.');
        return matches;
      };

      var authModalShell = authModalElement.querySelector('.auth-modal-shell');
      var setPanel = function (mode) {
        panels.forEach(function (panel) {
          panel.classList.toggle('d-none', panel.getAttribute('data-auth-panel') !== mode);
        });
        if (authModalShell) {
          authModalShell.classList.toggle('is-register', mode === 'register');
        }
      };

      switchers.forEach(function (link) {
        link.addEventListener('click', function (event) {
          event.preventDefault();
          setPanel(link.getAttribute('data-auth-switch'));
        });
      });

      triggers.forEach(function (trigger) {
        trigger.addEventListener('click', function () {
          setPanel(trigger.getAttribute('data-auth-tab') || 'login');
        });
      });

      passwordToggles.forEach(function (toggle) {
        toggle.addEventListener('click', function () {
          var targetId = toggle.getAttribute('data-target');
          var targetInput = targetId ? document.getElementById(targetId) : null;
          var icon = toggle.querySelector('i');

          if (!targetInput) {
            return;
          }

          var nextType = targetInput.getAttribute('type') === 'password' ? 'text' : 'password';
          targetInput.setAttribute('type', nextType);

          if (icon) {
            icon.classList.toggle('fa-eye', nextType === 'password');
            icon.classList.toggle('fa-eye-slash', nextType !== 'password');
          }
        });
      });

      if (registerCaptchaRefreshButton) {
        registerCaptchaRefreshButton.addEventListener('click', function () {
          setRegisterFeedback('', 'danger');
          requestSignupCaptcha();
        });
      }

      if (registerOtpButton && registerOtpInput && registerOtpHint && registerEmailInput && registerCaptchaInput) {
        registerOtpButton.addEventListener('click', function () {
          var emailValue = (registerEmailInput.value || '').trim();
          var captchaValue = (registerCaptchaInput.value || '').trim();

          setRegisterFeedback('', 'danger');

          if (emailValue === '') {
            setRegisterFeedback('Vui lòng nhập email trước khi lấy OTP.', 'danger');
            registerEmailInput.focus();
            return;
          }

          if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue)) {
            setRegisterFeedback('Email chưa hợp lệ. Vui lòng kiểm tra lại.', 'danger');
            registerEmailInput.focus();
            return;
          }

          if (captchaValue === '') {
            setRegisterFeedback('Vui lòng nhập captcha trước khi lấy OTP.', 'danger');
            registerCaptchaInput.focus();
            return;
          }

          registerOtpButton.disabled = true;
          registerOtpButton.textContent = 'Đang gửi...';

          window.fetch(signupOtpUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json'
            },
            body: JSON.stringify({
              email: emailValue,
              captcha: captchaValue
            })
          })
            .then(function (response) {
              return response.json().catch(function () {
                return {};
              }).then(function (payload) {
                return { status: response.status, payload: payload };
              });
            })
            .then(function (result) {
              var payload = result.payload || {};

              if (payload.captcha) {
                updateCaptcha(payload.captcha);
              }

              if (payload.ok) {
                registerOtpHint.textContent = payload.message || 'OTP đã được gửi tới email của bạn.';
                setRegisterFeedback(payload.message || 'OTP đã được gửi tới email của bạn.', 'success');
                startOtpCooldown(60);
                registerOtpInput.focus();
                persistRegisterDraft();
                return;
              }

              setRegisterFeedback(payload.message || 'Không gửi được OTP. Vui lòng thử lại.', 'danger');
              if (result.status === 429 && payload.retry_after) {
                startOtpCooldown(payload.retry_after);
              } else {
                updateOtpButtonState();
              }
            })
            .catch(function () {
              setRegisterFeedback('Không thể kết nối tới máy chủ để gửi OTP. Vui lòng thử lại.', 'danger');
              updateOtpButtonState();
            });
        });
      }

      if (registerForm) {
        registerForm.addEventListener('input', function () {
          syncPasswordHint();
          syncPasswordConfirmation();
          persistRegisterDraft();
        });

        registerForm.addEventListener('change', function () {
          syncPasswordHint();
          syncPasswordConfirmation();
          persistRegisterDraft();
        });
      }

      if (registerForm && registerCaptchaElement && registerCaptchaInput && registerOtpInput && registerOtpHint) {
        registerForm.addEventListener('submit', function (event) {
          syncPasswordHint();
          syncPasswordConfirmation();
          setRegisterFeedback('', 'danger');

          if (!registerForm.reportValidity()) {
            event.preventDefault();
            persistRegisterDraft();
            return;
          }

          if (typeof sessionStorage !== 'undefined') {
            sessionStorage.removeItem(registerDraftKey);
          }
        });
      }

      restoreRegisterDraft();
      syncPasswordHint();
      syncPasswordConfirmation();
      updateOtpButtonState();

      if (registerForm && hasServerRegisterState && typeof sessionStorage !== 'undefined' && !sessionStorage.getItem(registerDraftKey)) {
        persistRegisterDraft();
      }

      if (initialMode === 'login' || initialMode === 'forgot' || initialMode === 'register') {
        setPanel(initialMode);
        authModal.show();
      }
    });
  </script>
<?php endif; ?>

<div class="container mt-3 flash-stack">
  <?php if (!$showAuthModalFlash && $flashSuccessMessage): ?>
    <div class="alert alert-success alert-elevated"><?= h($flashSuccessMessage) ?></div>
  <?php endif; ?>
  <?php if (!$showAuthModalFlash && $flashErrorMessage): ?>
    <div class="alert alert-danger alert-elevated"><?= h($flashErrorMessage) ?></div>
  <?php endif; ?>
</div>

<main class="page-shell">
