/* U08: tải lười và đổi riêng khối "Sản phẩm tương tự" ở trang chi tiết.
   Không tải lại PDP; lỗi/204 chỉ ẩn khối ở lần tải đầu, còn khi bấm đổi thì giữ
   danh sách hiện tại nếu máy chủ chưa trả được danh sách mới. */
(function () {
  'use strict';

  var root = document.querySelector('[data-similar-products]');
  if (!root) return;

  var started = false;
  var inFlight = false;
  var activeController = null;

  function hide() {
    root.hidden = true;
    root.setAttribute('aria-busy', 'false');
  }

  function setRefreshState(isLoading) {
    var button = root.querySelector('[data-ssim-refresh]');
    if (!button) return;
    button.disabled = isLoading;
    button.classList.toggle('is-loading', isLoading);
    button.setAttribute('aria-busy', isLoading ? 'true' : 'false');
  }

  function setupCarousel() {
    var track = root.querySelector('[data-ssim-track]');
    var prev = root.querySelector('[data-ssim-prev]');
    var next = root.querySelector('[data-ssim-next]');
    var refresh = root.querySelector('[data-ssim-refresh]');

    if (track && prev && next) {
      function update() {
        var max = Math.max(0, track.scrollWidth - track.clientWidth - 2);
        prev.disabled = track.scrollLeft <= 2;
        next.disabled = track.scrollLeft >= max;
      }
      function step(dir) {
        var card = track.querySelector('.ssim-card');
        var width = card ? card.getBoundingClientRect().width + 16 : track.clientWidth / 2;
        track.scrollBy({ left: dir * width * 2, behavior: 'smooth' });
      }

      prev.addEventListener('click', function () { step(-1); });
      next.addEventListener('click', function () { step(1); });
      track.addEventListener('scroll', function () {
        if (window.requestAnimationFrame) window.requestAnimationFrame(update);
        else update();
      }, { passive: true });
      window.addEventListener('resize', update);
      update();
    }

    if (refresh) {
      refresh.addEventListener('click', function (event) {
        event.preventDefault();
        if (!inFlight) load(true);
      });
    }
  }

  function load(force) {
    force = Boolean(force);
    if (inFlight || (started && !force)) return;
    started = true;

    var url = root.getAttribute('data-url');
    if (!url || !window.fetch) {
      if (!force) hide();
      return;
    }

    var requestUrl = url;
    if (force) {
      var currentOffset = parseInt(root.getAttribute('data-offset') || '0', 10);
      currentOffset = Number.isFinite(currentOffset) ? currentOffset + 8 : 8;
      root.setAttribute('data-offset', String(currentOffset));
      requestUrl += (requestUrl.indexOf('?') >= 0 ? '&' : '?') + 'offset=' + encodeURIComponent(String(currentOffset));
    }

    inFlight = true;
    root.setAttribute('aria-busy', 'true');
    setRefreshState(true);
    activeController = 'AbortController' in window ? new AbortController() : null;
    var timer = setTimeout(function () {
      if (activeController) activeController.abort();
    }, 6000);

    fetch(requestUrl, {
      credentials: 'same-origin',
      headers: { 'Accept': 'text/html' },
      signal: activeController ? activeController.signal : undefined
    })
      .then(function (res) {
        return res.status === 200 ? res.text() : '';
      })
      .then(function (html) {
        if (html && html.indexOf('data-ssim-track') !== -1) {
          root.hidden = false;
          root.innerHTML = html;
          root.setAttribute('aria-busy', 'false');
          setupCarousel();
        } else if (!force) {
          hide();
        }
      })
      .catch(function () {
        if (!force) hide();
      })
      .then(function () {
        clearTimeout(timer);
        inFlight = false;
        activeController = null;
        root.setAttribute('aria-busy', 'false');
        setRefreshState(false);
      });
  }

  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) {
          io.disconnect();
          load(false);
          return;
        }
      }
    }, { rootMargin: '600px 0px' });
    io.observe(root);
  } else {
    load(false);
  }
})();
