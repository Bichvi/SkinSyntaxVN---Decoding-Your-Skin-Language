/* U09: tải khối "Lưu ý hoạt chất + Hoàn thiện routine" của giỏ hàng (HTML partial từ ?r=cart_analyze).
   - Lỗi / 204 / rỗng -> ẩn gọn, giỏ hàng vẫn hoạt động bình thường (khối này không bao giờ chặn thanh toán).
   - Cập nhật lại khi giỏ đổi: sự kiện `cart:changed` (xóa/đổi số lượng trong giohang.php) hoặc thêm giỏ từ thẻ gợi ý.
   - Thêm giỏ dùng bộ xử lý AJAX chung của footer; ở đây chỉ nhận biết phản hồi để làm mới bảng giỏ + khối.
   Tracking (impression/add_to_cart) do search-fallback.js xử lý. */
(function () {
  'use strict';

  var root = document.querySelector('[data-cart-insights]');
  if (!root) return;
  var url = root.getAttribute('data-url');
  if (!url || !window.fetch) return;

  var seq = 0;
  var timer = null;

  function hide() {
    root.innerHTML = '';
    root.hidden = true;
    root.setAttribute('aria-busy', 'false');
  }

  function setupCarousels() {
    var tracks = root.querySelectorAll('[data-cin-track]');
    for (var i = 0; i < tracks.length; i++) {
      tracks[i].setAttribute('tabindex', '0');
    }
  }

  function load() {
    var mine = ++seq;
    var controller = 'AbortController' in window ? new AbortController() : null;
    var abortTimer = setTimeout(function () { if (controller) controller.abort(); }, 6000);
    root.setAttribute('aria-busy', 'true');
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' }, signal: controller ? controller.signal : undefined })
      .then(function (res) { return res.status === 200 ? res.text() : ''; })
      .then(function (html) {
        clearTimeout(abortTimer);
        if (mine !== seq) return; // có lần tải mới hơn
        if (!html || html.indexOf('data-ssf') === -1) { hide(); return; }
        root.innerHTML = html;
        root.hidden = false;
        root.setAttribute('aria-busy', 'false');
        setupCarousels();
      })
      .catch(function () { clearTimeout(abortTimer); if (mine === seq) hide(); });
  }

  function schedule() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(load, 250);
  }

  // Làm mới bảng giỏ (hàng sản phẩm) sau khi thêm từ thẻ gợi ý, rồi phân tích lại.
  function refreshTable() {
    return fetch(window.location.href, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
      .then(function (res) { return res.ok ? res.text() : ''; })
      .then(function (html) {
        if (!html) return;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.getElementById('cartItems');
        var current = document.getElementById('cartItems');
        if (fresh && current) {
          current.innerHTML = fresh.innerHTML;
          document.dispatchEvent(new CustomEvent('cart:rows-changed'));
        }
      })
      .catch(function () { /* giữ nguyên bảng cũ */ });
  }

  document.addEventListener('cart:changed', schedule);

  // Nhận biết "thêm giỏ" thành công từ bộ xử lý chung (fetch tới them_gio_hang*) mà không sửa footer.
  var nativeFetch = window.fetch;
  window.fetch = function (input) {
    var promise = nativeFetch.apply(this, arguments);
    try {
      var target = typeof input === 'string' ? input : (input && input.url) || '';
      if (target.indexOf('them_gio_hang') !== -1) {
        promise.then(function (res) { return res.clone().json(); })
          .then(function (json) {
            if (json && json.ok) { refreshTable().then(schedule); }
          })
          .catch(function () { /* bỏ qua */ });
      }
    } catch (e) { /* bỏ qua */ }
    return promise;
  };

  load();
})();
