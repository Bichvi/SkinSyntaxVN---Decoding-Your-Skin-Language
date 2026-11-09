/* Đổi riêng khối gợi ý trên PDP, không refresh toàn bộ trang. */
(function () {
  'use strict';

  var loadingBlocks = typeof WeakSet === 'function' ? new WeakSet() : null;

  function isLoading(block) {
    return loadingBlocks ? loadingBlocks.has(block) : block.getAttribute('data-refresh-loading') === '1';
  }

  function setLoading(block, button, value) {
    if (loadingBlocks) {
      if (value) loadingBlocks.add(block);
      else loadingBlocks.delete(block);
    } else {
      block.setAttribute('data-refresh-loading', value ? '1' : '0');
    }
    if (button) {
      button.disabled = value;
      button.classList.toggle('is-loading', value);
      button.setAttribute('aria-busy', value ? 'true' : 'false');
    }
    block.setAttribute('aria-busy', value ? 'true' : 'false');
  }

  function refreshRoutine(block, button) {
    var url = block.getAttribute('data-routine-refresh-url');
    if (!url || !window.fetch || isLoading(block)) return;

    var offset = block.getAttribute('data-routine-offset') || '0';
    var separator = url.indexOf('?') >= 0 ? '&' : '?';
    var controller = 'AbortController' in window ? new AbortController() : null;
    var timer = setTimeout(function () {
      if (controller) controller.abort();
    }, 7000);

    setLoading(block, button, true);
    fetch(url + separator + 'offset=' + encodeURIComponent(offset), {
      credentials: 'same-origin',
      headers: { 'Accept': 'text/html' },
      signal: controller ? controller.signal : undefined
    })
      .then(function (response) {
        return response.status === 200 ? response.text() : '';
      })
      .then(function (html) {
        if (!html || html.indexOf('data-routine-recommendation-block') === -1) {
          if (button) button.title = 'Chưa có thêm sản phẩm phù hợp';
          return;
        }
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        var replacement = parsed.querySelector('[data-routine-recommendation-block]');
        if (replacement) block.replaceWith(replacement);
      })
      .catch(function () {
        if (button) button.title = 'Không thể tải thêm sản phẩm lúc này';
      })
      .then(function () {
        clearTimeout(timer);
        if (document.contains(block)) setLoading(block, button, false);
      });
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest('[data-product-block-refresh]') : null;
    if (!button) return;
    var block = button.closest('[data-routine-recommendation-block]');
    if (!block) return;
    event.preventDefault();
    refreshRoutine(block, button);
  });
})();
