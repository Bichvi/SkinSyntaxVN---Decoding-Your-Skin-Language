<?php
$welcomePopupMode = 'production';
if (function_exists('ss_env')) {
    $configuredWelcomePopupMode = strtolower(trim((string)ss_env('WELCOME_POPUP_MODE', 'production')));
    if (in_array($configuredWelcomePopupMode, ['production', 'demo'], true)) {
        $welcomePopupMode = $configuredWelcomePopupMode;
    }
}
$welcomePopupCssPath = dirname(__DIR__, 2) . '/public/assets/css/welcome-popup.css';
$welcomePopupJsPath = dirname(__DIR__, 2) . '/public/assets/js/welcome-popup.js';
?>
<link rel="stylesheet" href="<?= h(BASE_URL . '/assets/css/welcome-popup.css?v=' . (@filemtime($welcomePopupCssPath) ?: '1')) ?>">
<div class="ss-welcome-popup" data-welcome-popup data-welcome-mode="<?= h($welcomePopupMode) ?>"
     data-base-url="<?= h(rtrim((string)BASE_URL, '/')) ?>" hidden>
  <div class="ss-welcome-popup__backdrop" data-welcome-backdrop>
    <div class="ss-welcome-popup__dialog" role="dialog" aria-modal="true" aria-label="Thông tin chào mừng SkinSyntax">
      <img class="ss-welcome-popup__image" data-welcome-image src="" alt="" decoding="async">
      <button class="ss-welcome-popup__hotspot ss-welcome-popup__hotspot--close" type="button"
              data-welcome-close aria-label="Đóng thông báo chào mừng"></button>
      <button class="ss-welcome-popup__hotspot ss-welcome-popup__hotspot--cta" type="button"
              data-welcome-cta aria-label="Mở nội dung banner"></button>
    </div>
  </div>
</div>
<script src="<?= h(BASE_URL . '/assets/js/welcome-popup.js?v=' . (@filemtime($welcomePopupJsPath) ?: '1')) ?>" defer></script>
