(function () {
  'use strict';
  var root = document.getElementById('home-graph-concern');
  if (!root) return;
  var grid = document.getElementById('home-graph-concern-grid');
  if (!grid) return;
  var base = (root.getAttribute('data-base-url') || '').replace(/\/+$/, '');
  var chips = root.querySelectorAll('[data-concern-key]');
  var controller = null;
  var current = '';

  function skeleton() {
    var html = '';
    for (var i = 0; i < 8; i++) {
      html += '<div class="col-6 col-md-3"><div style="background:#E2E8F0;border-radius:12px;aspect-ratio:3/4;" aria-hidden="true"></div></div>';
    }
    grid.innerHTML = html;
    grid.setAttribute('aria-busy', 'true');
  }

  function showError() {
    grid.innerHTML = '<div class="col-12 text-center py-4" data-concern-error>' +
      '<p class="text-muted mb-2">Chưa tải được, thử lại</p>' +
      '<button type="button" class="btn btn-sm btn-outline-success" data-retry>Thử lại</button></div>';
    grid.removeAttribute('aria-busy');
  }

  function select(key) {
    for (var i = 0; i < chips.length; i++) {
      var on = chips[i].getAttribute('data-concern-key') === key;
      chips[i].setAttribute('aria-selected', on ? 'true' : 'false');
      chips[i].classList.toggle('btn-success', on);
      chips[i].classList.toggle('btn-outline-secondary', !on);
    }
  }

  function load(key) {
    if (controller) controller.abort();
    controller = new AbortController();
    var mine = controller;
    current = key;
    select(key);
    skeleton();
    fetch(base + '/index.php?r=home_concern&c=' + encodeURIComponent(key), {
      credentials: 'same-origin',
      signal: mine.signal
    }).then(function (res) {
      if (!res.ok) throw new Error('http');
      return res.text();
    }).then(function (html) {
      if (mine !== controller) return;
      grid.innerHTML = html;
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
    var chip = t.closest('[data-concern-key]');
    if (chip && root.contains(chip)) {
      var k = chip.getAttribute('data-concern-key');
      if (k && k !== current) load(k);
      return;
    }
    var retry = t.closest('[data-retry]');
    if (retry && grid.contains(retry)) {
      var sel = root.querySelector('[data-concern-key][aria-selected="true"]');
      var rk = current || (sel ? sel.getAttribute('data-concern-key') : '');
      if (rk) load(rk);
    }
  });

  var initial = root.querySelector('[data-concern-key][aria-selected="true"]');
  current = initial ? initial.getAttribute('data-concern-key') : '';
})();
