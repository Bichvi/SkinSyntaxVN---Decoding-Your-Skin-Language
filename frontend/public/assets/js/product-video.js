/* AI Idol videos on the product detail page: corner mini player, video row and full-screen viewer. */
(function () {
  'use strict';

  var dataNode = document.getElementById('productVideoData');
  if (!dataNode) return;
  var data;
  try { data = JSON.parse(dataNode.textContent || '{}'); } catch (e) { return; }
  var videos = Array.isArray(data.videos) ? data.videos : [];
  if (!videos.length && !data.live) return;

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var storageKey = 'pv-hidden:' + (data.currentId || '');

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function readHidden() { try { return sessionStorage.getItem(storageKey) === '1'; } catch (e) { return false; } }
  function writeHidden(value) {
    try { value ? sessionStorage.setItem(storageKey, '1') : sessionStorage.removeItem(storageKey); } catch (e) { /* storage blocked */ }
  }
  function whenLoaded(fn) {
    if (document.readyState === 'complete') fn(); else window.addEventListener('load', fn, { once: true });
  }

  /* ---------- Mini player ---------- */
  var first = videos[0];
  var mini = el('div', 'pv-mini');
  mini.tabIndex = 0;
  mini.setAttribute('role', 'button');
  var miniVideo = null;
  var badge = el('span', 'pv-mini-badge', data.live ? 'LIVE' : '▶ Video');
  var caption = el('span', 'pv-mini-caption', data.live ? 'Đang phát sóng' : (first ? first.title : ''));
  if (first && first.landscape) mini.classList.add('is-landscape');

  if (data.live) {
    mini.classList.add('is-live');
    mini.setAttribute('aria-label', 'Mở trang livestream: ' + (data.live.name || 'đang phát sóng'));
    if (first) { var still = el('img'); still.src = first.poster; still.alt = ''; mini.appendChild(still); }
  } else {
    mini.setAttribute('aria-label', 'Phóng to video: ' + first.title);
    miniVideo = el('video');
    miniVideo.muted = true;
    miniVideo.loop = true;
    miniVideo.playsInline = true;
    miniVideo.setAttribute('playsinline', '');
    miniVideo.preload = 'none';
    miniVideo.poster = first.poster;
    mini.appendChild(miniVideo);
    if (!reduceMotion) {
      whenLoaded(function () {
        miniVideo.src = first.src;
        var playing = miniVideo.play();
        if (playing && playing.catch) playing.catch(function () { /* autoplay blocked: poster stays */ });
      });
    }
  }
  mini.appendChild(badge);
  mini.appendChild(caption);

  var closeMini = el('button', 'pv-icon pv-mini-close', '✕');
  closeMini.type = 'button';
  closeMini.setAttribute('aria-label', 'Ẩn video');
  mini.appendChild(closeMini);
  var miniSound = null;
  if (miniVideo) {
    miniSound = el('button', 'pv-icon pv-mini-sound', '🔇');
    miniSound.type = 'button';
    miniSound.setAttribute('aria-label', 'Bật tiếng');
    mini.appendChild(miniSound);
  }
  var reopen = el('button', 'pv-reopen', '▶ Xem video');
  reopen.type = 'button';

  function showMini(show) {
    mini.hidden = !show;
    reopen.hidden = show;
    if (miniVideo) { if (show && !reduceMotion && miniVideo.src) miniVideo.play().catch(function () {}); else miniVideo.pause(); }
  }
  document.body.appendChild(mini);
  document.body.appendChild(reopen);
  showMini(!readHidden());

  function activateMini() {
    if (data.live) { window.location.href = data.liveUrl; return; }
    openViewer(0, miniVideo ? miniVideo.currentTime : 0, mini);
  }
  mini.addEventListener('click', activateMini);
  mini.addEventListener('keydown', function (event) {
    if (event.target === mini && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); activateMini(); }
  });
  closeMini.addEventListener('click', function (event) { event.stopPropagation(); writeHidden(true); showMini(false); reopen.focus(); });
  reopen.addEventListener('click', function () { writeHidden(false); showMini(true); mini.focus(); });
  if (miniSound) {
    miniSound.addEventListener('click', function (event) {
      event.stopPropagation();
      miniVideo.muted = !miniVideo.muted;
      miniSound.textContent = miniVideo.muted ? '🔇' : '🔊';
      miniSound.setAttribute('aria-label', miniVideo.muted ? 'Bật tiếng' : 'Tắt tiếng');
    });
  }

  /* ---------- Video row ---------- */
  document.querySelectorAll('[data-pv-index]').forEach(function (card) {
    card.addEventListener('click', function () { openViewer(Number(card.getAttribute('data-pv-index')), 0, card); });
  });

  /* Carousel arrows: scroll the card strip by one visible page; hidden when everything fits. */
  var list = document.querySelector('[data-pv-list]');
  var arrows = document.querySelector('[data-pv-arrows]');
  var arrowPrev = arrows ? arrows.querySelector('[data-pv-prev]') : null;
  var arrowNext = arrows ? arrows.querySelector('[data-pv-next]') : null;
  if (list && arrows && arrowPrev && arrowNext) {
    var frame = false;
    var syncArrows = function () {
      var max = list.scrollWidth - list.clientWidth;
      var active = document.activeElement;
      arrows.hidden = max <= 1;
      arrowPrev.disabled = list.scrollLeft <= 1;
      arrowNext.disabled = list.scrollLeft >= max - 1;
      /* Keep keyboard focus on a usable arrow instead of dropping it to <body>. */
      if (active === arrowPrev && arrowPrev.disabled && !arrowNext.disabled) arrowNext.focus();
      else if (active === arrowNext && arrowNext.disabled && !arrowPrev.disabled) arrowPrev.focus();
    };
    var queueSync = function () {
      if (frame) return;
      frame = true;
      window.requestAnimationFrame(function () { frame = false; syncArrows(); });
    };
    var scrollPage = function (dir) {
      list.scrollBy({ left: dir * list.clientWidth, behavior: reduceMotion ? 'auto' : 'smooth' });
    };
    list.addEventListener('scroll', queueSync, { passive: true });
    if (window.ResizeObserver) new ResizeObserver(queueSync).observe(list);
    else window.addEventListener('resize', queueSync);
    arrowPrev.addEventListener('click', function () { scrollPage(-1); });
    arrowNext.addEventListener('click', function () { scrollPage(1); });
    syncArrows();
  }

  /* ---------- Full-screen viewer (built on first use) ---------- */
  var viewer = null;
  var index = 0;
  var returnFocus = null;
  var swiped = false;

  function buildViewer() {
    var modal = el('div', 'pv-modal');
    modal.hidden = true;
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-label', 'Video sản phẩm');
    var stage = el('div', 'pv-stage');
    var backdrop = el('div', 'pv-backdrop');
    var video = el('video');
    video.playsInline = true;
    video.setAttribute('playsinline', '');
    video.preload = 'metadata';
    var tap = el('button', 'pv-tap');
    tap.type = 'button';
    tap.setAttribute('aria-label', 'Phát hoặc tạm dừng');
    var bigPlay = el('div', 'pv-bigplay', '▶');
    var error = el('div', 'pv-error', 'Video tạm thời không phát được');
    error.hidden = true;

    var top = el('div', 'pv-top');
    var who = el('span', 'pv-who');
    var title = el('b');
    who.appendChild(title);
    who.appendChild(el('small', '', 'Syna · AI Idol'));
    var count = el('span', 'pv-count');
    var sound = el('button', 'pv-round', '🔊');
    sound.type = 'button';
    sound.setAttribute('aria-label', 'Tắt tiếng');
    var close = el('button', 'pv-round', '✕');
    close.type = 'button';
    close.setAttribute('aria-label', 'Thu nhỏ video');
    [el('span', 'pv-avatar'), who, count, sound, close].forEach(function (node) { top.appendChild(node); });

    var tray = el('div', 'pv-tray');
    var trayHead = el('div', 'pv-tray-head');
    var trayTitle = el('span', '', 'Sản phẩm trong video');
    var made = el('span');
    trayHead.appendChild(trayTitle);
    trayHead.appendChild(made);
    var products = el('div', 'pv-products');
    tray.appendChild(trayHead);
    tray.appendChild(products);

    var progress = el('div', 'pv-progress');
    var bar = el('i');
    progress.appendChild(bar);

    [backdrop, video, tap, bigPlay, error, top, tray, progress].forEach(function (node) { stage.appendChild(node); });
    var nav = el('div', 'pv-nav');
    var prev = el('button', 'pv-round', '▲');
    prev.type = 'button';
    prev.setAttribute('aria-label', 'Video trước');
    var next = el('button', 'pv-round', '▼');
    next.type = 'button';
    next.setAttribute('aria-label', 'Video tiếp theo');
    nav.appendChild(prev);
    nav.appendChild(next);
    modal.appendChild(stage);
    modal.appendChild(nav);
    document.body.appendChild(modal);

    var preloader = el('video');
    preloader.preload = 'metadata';
    preloader.muted = true;

    viewer = {
      modal: modal, stage: stage, backdrop: backdrop, video: video, error: error, title: title, count: count,
      sound: sound, close: close, tray: tray, made: made, products: products, bar: bar, prev: prev, next: next,
      nav: nav, preloader: preloader
    };

    video.addEventListener('timeupdate', function () {
      if (video.duration) bar.style.width = (video.currentTime / video.duration * 100) + '%';
    });
    video.addEventListener('play', function () { stage.classList.remove('is-paused'); });
    video.addEventListener('pause', function () { stage.classList.add('is-paused'); });
    video.addEventListener('ended', function () { if (index < videos.length - 1) go(1); });
    video.addEventListener('error', function () {
      if (!video.getAttribute('src')) return;
      error.hidden = false;
      if (index < videos.length - 1) setTimeout(function () { if (!modal.hidden && !error.hidden) go(1); }, 1500);
    });
    tap.addEventListener('click', function () {
      if (swiped) { swiped = false; return; }
      if (video.paused) video.play().catch(function () {}); else video.pause();
    });
    sound.addEventListener('click', function () {
      video.muted = !video.muted;
      sound.textContent = video.muted ? '🔇' : '🔊';
      sound.setAttribute('aria-label', video.muted ? 'Bật tiếng' : 'Tắt tiếng');
    });
    close.addEventListener('click', closeViewer);
    prev.addEventListener('click', function () { go(-1); });
    next.addEventListener('click', function () { go(1); });
    modal.addEventListener('click', function (event) { if (event.target === modal) closeViewer(); });
    modal.addEventListener('keydown', onKeydown);

    var startY = null;
    stage.addEventListener('pointerdown', function (event) {
      if (event.target.closest('.pv-tray, .pv-top')) return;
      startY = event.clientY;
    });
    stage.addEventListener('pointerup', function (event) {
      if (startY === null) return;
      var dy = event.clientY - startY;
      startY = null;
      if (Math.abs(dy) > 50) { swiped = true; go(dy < 0 ? 1 : -1); }
    });
  }

  function productCard(product) {
    var card = el(product.current ? 'button' : 'a', 'pv-product');
    if (product.current) {
      card.type = 'button';
      card.addEventListener('click', function () {
        closeViewer();
        var target = document.querySelector('.detail-box') || document.getElementById('mainImage');
        if (target) {
          target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
          target.classList.remove('pv-flash');
          void target.offsetWidth;
          target.classList.add('pv-flash');
        }
      });
    } else {
      card.href = product.url;
    }
    var image = el('img');
    image.src = product.image;
    image.alt = '';
    image.loading = 'lazy';
    image.referrerPolicy = 'no-referrer';
    var text = el('span', 'pv-product-text');
    if (product.current) text.appendChild(el('span', 'pv-product-tag', 'Đang xem'));
    else text.appendChild(el('span', 'pv-product-tag', 'Cùng chương trình'));
    text.appendChild(el('span', 'pv-product-name', product.name));
    if (product.price) text.appendChild(el('span', 'pv-product-price', product.price));
    card.appendChild(image);
    card.appendChild(text);
    card.appendChild(el('span', 'pv-product-go', 'Xem chi tiết'));
    return card;
  }

  function load(nextIndex, startAt) {
    index = nextIndex;
    var item = videos[index];
    var v = viewer;
    v.error.hidden = true;
    v.stage.classList.toggle('is-landscape', !!item.landscape);
    v.backdrop.style.backgroundImage = 'url("' + String(item.poster).replace(/"/g, '%22') + '")';
    v.video.poster = item.poster;
    v.video.src = item.src;
    v.bar.style.width = '0';
    if (startAt > 0) {
      v.video.addEventListener('loadedmetadata', function seek() { v.video.currentTime = startAt; }, { once: true });
    }
    v.title.textContent = item.title;
    v.count.textContent = (index + 1) + '/' + videos.length;
    v.count.hidden = videos.length < 2;
    v.nav.hidden = videos.length < 2;
    v.made.textContent = item.madeDate || '';
    v.products.textContent = '';
    (item.products || []).forEach(function (product) { v.products.appendChild(productCard(product)); });
    v.tray.hidden = !(item.products || []).length;
    var focused = document.activeElement;
    v.prev.disabled = index === 0;
    v.next.disabled = index === videos.length - 1;
    // A disabled button drops focus to <body>, which would lose Esc/arrow keys and the Tab trap.
    if (focused && focused.disabled && (focused === v.prev || focused === v.next)) {
      (focused === v.next && !v.prev.disabled ? v.prev : focused === v.prev && !v.next.disabled ? v.next : v.close).focus();
    }
    v.video.play().catch(function () { v.stage.classList.add('is-paused'); });
    var following = videos[index + 1];
    if (following) v.preloader.src = following.src; else v.preloader.removeAttribute('src');
  }

  function go(step) {
    var target = index + step;
    if (!viewer || target < 0 || target >= videos.length) return;
    load(target, 0);
  }

  function openViewer(at, startAt, opener) {
    if (!videos.length) return;
    if (!viewer) buildViewer();
    returnFocus = opener || document.activeElement;
    if (miniVideo) miniVideo.pause();
    viewer.video.muted = false;
    viewer.sound.textContent = '🔊';
    viewer.modal.hidden = false;
    document.body.classList.add('pv-lock');
    load(at, startAt || 0);
    viewer.close.focus();
  }

  function closeViewer() {
    if (!viewer || viewer.modal.hidden) return;
    var resumeAt = index === 0 ? viewer.video.currentTime : 0;
    viewer.video.pause();
    viewer.video.removeAttribute('src');
    viewer.video.load();
    viewer.preloader.removeAttribute('src');
    viewer.modal.hidden = true;
    document.body.classList.remove('pv-lock');
    if (miniVideo && !mini.hidden && !reduceMotion && miniVideo.src) {
      if (resumeAt) miniVideo.currentTime = resumeAt;
      miniVideo.play().catch(function () {});
    }
    if (returnFocus && typeof returnFocus.focus === 'function') returnFocus.focus();
  }

  function onKeydown(event) {
    if (event.key === 'Escape') { event.preventDefault(); closeViewer(); return; }
    if (event.key === 'ArrowDown') { event.preventDefault(); go(1); return; }
    if (event.key === 'ArrowUp') { event.preventDefault(); go(-1); return; }
    if (event.key !== 'Tab') return;
    var focusable = Array.prototype.filter.call(
      viewer.modal.querySelectorAll('button, a[href]'),
      function (node) { return !node.disabled && node.offsetParent !== null; }
    );
    if (!focusable.length) return;
    var firstNode = focusable[0];
    var lastNode = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === firstNode) { event.preventDefault(); lastNode.focus(); }
    else if (!event.shiftKey && document.activeElement === lastNode) { event.preventDefault(); firstNode.focus(); }
  }
})();
