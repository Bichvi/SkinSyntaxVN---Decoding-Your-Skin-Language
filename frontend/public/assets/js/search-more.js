/* Khối "Gợi ý thêm cho bạn" ở trang tìm kiếm có kết quả: tải lười HTML partial từ ?r=search_more.
   Lỗi/204/429/rỗng -> ẩn gọn, không báo lỗi cho khách. Tracking do search-fallback.js xử lý (MutationObserver).
   Lọc trực tiếp (live-filter.js) thay #catalogResults nên quét lại khi có sự kiện live-filter:updated. */
(function () {
  'use strict';

  function setup(root) {
    if (root.getAttribute('data-ssm-init')) return;
    root.setAttribute('data-ssm-init', '1');

    function hide() {
      root.hidden = true;
      root.setAttribute('aria-busy', 'false');
    }

    var started = false;
    function load() {
      if (started) return;
      started = true;
      var url = root.getAttribute('data-url');
      if (!url || !window.fetch) { hide(); return; }
      var controller = 'AbortController' in window ? new AbortController() : null;
      var timer = setTimeout(function () { if (controller) controller.abort(); }, 6000);
      fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' }, signal: controller ? controller.signal : undefined })
        .then(function (res) { return res.status === 200 ? res.text() : ''; })
        .then(function (html) {
          clearTimeout(timer);
          if (!html || html.indexOf('data-ssf') === -1) { hide(); return; }
          root.innerHTML = html;
          root.setAttribute('aria-busy', 'false');
        })
        .catch(function () { clearTimeout(timer); hide(); });
    }

    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        for (var i = 0; i < entries.length; i++) {
          if (entries[i].isIntersecting) { io.disconnect(); load(); return; }
        }
      }, { rootMargin: '600px 0px' });
      io.observe(root);
    } else {
      load();
    }
  }

  function scan() {
    var roots = document.querySelectorAll('[data-search-more]');
    for (var i = 0; i < roots.length; i++) setup(roots[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }
  document.addEventListener('live-filter:updated', scan);
})();
