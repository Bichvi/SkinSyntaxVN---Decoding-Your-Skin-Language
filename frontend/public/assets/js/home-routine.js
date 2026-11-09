(function () {
  'use strict';
  // Đổi tab loại da của khối routine mẫu: tải lưới HTML qua home_routine rồi thay vào.
  var root = document.getElementById('home-routine');
  if (!root) return;
  var grid = document.getElementById('home-routine-grid');
  if (!grid) return;
  var base = (root.getAttribute('data-base-url') || '').replace(/\/+$/, '');
  var tabs = root.querySelectorAll('[data-skin-key]');
  var controller = null;
  var current = '';

  function select(key) {
    for (var i = 0; i < tabs.length; i++) {
      var on = tabs[i].getAttribute('data-skin-key') === key;
      tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
      tabs[i].classList.toggle('btn-success', on);
      tabs[i].classList.toggle('btn-outline-secondary', !on);
    }
  }

  function showError() {
    grid.innerHTML = '<div class="col-12 text-center py-4">' +
      '<p class="text-muted mb-2">Không tải được, thử lại</p>' +
      '<button type="button" class="btn btn-sm btn-outline-success" data-routine-retry>Thử lại</button></div>';
    grid.style.opacity = '';
    grid.removeAttribute('aria-busy');
  }

  function load(key) {
    if (controller) controller.abort();
    controller = new AbortController();
    var mine = controller;
    current = key;
    select(key);
    // Giữ nội dung cũ, chỉ làm mờ khi đang tải.
    grid.style.opacity = '0.45';
    grid.setAttribute('aria-busy', 'true');
    fetch(base + '/index.php?r=home_routine&skin=' + encodeURIComponent(key), {
      credentials: 'same-origin',
      cache: 'no-store',
      signal: mine.signal
    }).then(function (res) {
      if (!res.ok) throw new Error('http');
      return res.text();
    }).then(function (html) {
      if (mine !== controller) return;
      grid.innerHTML = html;
      grid.style.opacity = '';
      grid.removeAttribute('aria-busy');
    }).catch(function (err) {
      if (err && err.name === 'AbortError') return;
      if (mine !== controller) return;
      showError();
    });
  }

  root.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var tab = t.closest('[data-skin-key]');
    if (tab && root.contains(tab)) {
      var k = tab.getAttribute('data-skin-key');
      if (k && k !== current) load(k);
      return;
    }
    if (t.closest('[data-routine-retry]') && current) load(current);
  });

  var initial = root.querySelector('[data-skin-key][aria-selected="true"]');
  current = initial ? initial.getAttribute('data-skin-key') : '';
})();
