(function () {
  'use strict';

  var root = document.querySelector('[data-welcome-popup]');
  if (!root) return;

  var image = root.querySelector('[data-welcome-image]');
  var closeButton = root.querySelector('[data-welcome-close]');
  var ctaButton = root.querySelector('[data-welcome-cta]');
  var backdrop = root.querySelector('[data-welcome-backdrop]');
  var mode = root.getAttribute('data-welcome-mode') === 'demo' ? 'demo' : 'production';
  var baseUrl = root.getAttribute('data-base-url') || '';
  var storageKeys = {
    last: 'skinsyntaxWelcomePopupLastV1',
    shown: 'skinsyntaxWelcomePopupShownV1',
    closed: 'skinsyntaxWelcomePopupClosedV1'
  };

  // Central banner configuration. CTA routes below are routes already present in index.php.
  var banners = [
    { id: 'skin-survey', image: 'banner.png', alt: 'Khảo sát da 1 phút cùng SkinSyntax', enabled: true, weight: 30, actionType: 'navigate', route: 'khaosat' },
    { id: 'new-customer', image: 'banner_khachmoi.png', alt: 'Quà tặng khách mới SkinSyntax', enabled: true, weight: 15, actionType: 'auth-register', guestOnly: true },
    { id: 'syna-chat', image: 'banner_chat.png', alt: 'Trò chuyện cùng Syna', enabled: true, weight: 20, actionType: 'open-chat' },
    { id: 'product-discovery', image: 'banner_sp.png', alt: 'Khám phá sản phẩm phù hợp', enabled: true, weight: 15, actionType: 'navigate', route: 'tatca' },
    { id: 'skincare-routine', image: 'banner_routine.png', alt: 'Routine skincare riêng cho bạn', enabled: true, weight: 10, actionType: 'navigate', route: 'goiy' },
    { id: 'skin-concern', image: 'banner_da.png', alt: 'Tìm hiểu vấn đề về da', enabled: true, weight: 10, actionType: 'navigate', route: 'khaosat' }
  ];

  var selectedBanner = null;
  var previousFocus = null;
  var closeTimer = null;

  function readStorage(key) {
    try { return window.sessionStorage.getItem(key) || ''; } catch (error) { return ''; }
  }

  function writeStorage(key, value) {
    try { window.sessionStorage.setItem(key, value); } catch (error) {}
  }

  function routeUrl(route) {
    var prefix = baseUrl.replace(/\/$/, '');
    return prefix + '/index.php?r=' + encodeURIComponent(route);
  }

  function isWithinSchedule(banner) {
    var now = Date.now();
    var startAt = banner.startAt ? Date.parse(banner.startAt) : null;
    var endAt = banner.endAt ? Date.parse(banner.endAt) : null;
    if (banner.startAt && !Number.isFinite(startAt)) return false;
    if (banner.endAt && !Number.isFinite(endAt)) return false;
    if (startAt !== null && now < startAt) return false;
    if (endAt !== null && now >= endAt) return false;
    return true;
  }

  function isVisible(element) {
    return !!element && !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
  }

  function actionIsAvailable(banner) {
    if (banner.guestOnly && document.querySelector('[data-auth-tab="register"]:not([disabled])') === null) return false;
    if (banner.actionType === 'open-chat') return isVisible(document.querySelector('[data-ai-chat-toggle]'));
    if (banner.actionType === 'auth-register') return isVisible(document.querySelector('[data-auth-tab="register"]'));
    return banner.actionType === 'navigate' && typeof banner.route === 'string' && banner.route !== '';
  }

  function eligibleBanners() {
    return banners.filter(function (banner) {
      return banner.enabled === true
        && Number.isFinite(Number(banner.weight))
        && Number(banner.weight) > 0
        && isWithinSchedule(banner)
        && actionIsAvailable(banner);
    });
  }

  function weightedPick(items) {
    var total = items.reduce(function (sum, banner) { return sum + Number(banner.weight); }, 0);
    if (!(total > 0)) return items[0] || null;
    var cursor = Math.random() * total;
    for (var i = 0; i < items.length; i += 1) {
      cursor -= Number(items[i].weight);
      if (cursor < 0) return items[i];
    }
    return items[items.length - 1] || null;
  }

  function chooseBanner() {
    var available = eligibleBanners();
    if (!available.length) return null;
    var lastId = readStorage(storageKeys.last);
    var candidates = available.filter(function (banner) { return banner.id !== lastId; });
    if (!candidates.length) candidates = available;
    return weightedPick(candidates);
  }

  function finishClose() {
    root.hidden = true;
    root.classList.remove('is-visible', 'is-closing');
    if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    previousFocus = null;
  }

  function closePopup(markClosed) {
    if (root.hidden) return;
    if (markClosed && mode === 'production') writeStorage(storageKeys.closed, '1');
    root.classList.remove('is-visible');
    root.classList.add('is-closing');
    window.clearTimeout(closeTimer);
    closeTimer = window.setTimeout(finishClose, 190);
  }

  function runAction() {
    if (!selectedBanner) return;
    var banner = selectedBanner;
    closePopup(true);
    if (banner.actionType === 'navigate') {
      window.setTimeout(function () { window.location.assign(routeUrl(banner.route)); }, 0);
      return;
    }
    window.setTimeout(function () {
      if (banner.actionType === 'open-chat') {
        var chatTrigger = document.querySelector('[data-ai-chat-toggle]');
        if (chatTrigger) chatTrigger.click();
      } else if (banner.actionType === 'auth-register') {
        var registerTriggers = Array.prototype.slice.call(document.querySelectorAll('[data-auth-tab="register"]'));
        var registerTrigger = registerTriggers.find(isVisible);
        if (registerTrigger) registerTrigger.click();
      }
    }, 210);
  }

  function showPopup() {
    if (mode === 'production' && (readStorage(storageKeys.shown) === '1' || readStorage(storageKeys.closed) === '1')) return;
    selectedBanner = chooseBanner();
    if (!selectedBanner) return;

    image.src = baseUrl.replace(/\/$/, '') + '/assets/images/' + selectedBanner.image;
    image.alt = selectedBanner.alt;
    ctaButton.setAttribute('aria-label', selectedBanner.alt + ' — mở nội dung');
    writeStorage(storageKeys.last, selectedBanner.id);
    if (mode === 'production') writeStorage(storageKeys.shown, '1');
    previousFocus = document.activeElement;
    root.hidden = false;
    window.requestAnimationFrame(function () {
      root.classList.add('is-visible');
      root.classList.add('is-programmatic-focus');
      closeButton.focus();
    });
  }

  closeButton.addEventListener('click', function (event) {
    event.preventDefault();
    closePopup(true);
  });

  ctaButton.addEventListener('click', function (event) {
    event.preventDefault();
    runAction();
  });

  backdrop.addEventListener('click', function (event) {
    if (event.target === backdrop) closePopup(true);
  });

  document.addEventListener('keydown', function (event) {
    root.classList.remove('is-programmatic-focus');
    if (!root.hidden && event.key === 'Escape') {
      event.preventDefault();
      closePopup(true);
    }
  });

  root.addEventListener('pointerdown', function () {
    root.classList.remove('is-programmatic-focus');
  });

  image.addEventListener('error', function () {
    closePopup(false);
  });

  window.setTimeout(showPopup, 260);
})();
