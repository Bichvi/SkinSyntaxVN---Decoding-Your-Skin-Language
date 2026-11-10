/* U08: tải lười khối "Sản phẩm tương tự" ở trang chi tiết (HTML partial từ ?r=similar_products).
   Không chặn TTFB: chỉ fetch khi vùng chờ gần vào khung nhìn. Lỗi/204/rỗng -> ẩn gọn, không báo lỗi cho khách.
   Tracking (impression/click/add_to_cart) do search-fallback.js xử lý (MutationObserver bắt phần tử mới chèn). */
(function () {
  'use strict';

  var root = document.querySelector('[data-similar-products]');
  if (!root) return;

  function hide() {
    root.hidden = true;
    root.setAttribute('aria-busy', 'false');
  }

  function setupCarousel() {
    var track = root.querySelector('[data-ssim-track]');
    var prev = root.querySelector('[data-ssim-prev]');
    var next = root.querySelector('[data-ssim-next]');
    if (!track || !prev || !next) return;
    var nav = prev.parentNode;
    function update() {
      var max = track.scrollWidth - track.clientWidth - 2;
      // Ít thẻ, vừa khít khung -> không có gì để cuộn: ẩn hẳn 2 nút thay vì để nút mờ bấm không được.
      if (nav) nav.hidden = max <= 0;
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
    track.addEventListener('scroll', function () { window.requestAnimationFrame(update); }, { passive: true });
    window.addEventListener('resize', update);
    update();
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
        if (!html || html.indexOf('data-ssim-track') === -1) { hide(); return; }
        root.innerHTML = html;
        root.setAttribute('aria-busy', 'false');
        setupCarousel();
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
})();
