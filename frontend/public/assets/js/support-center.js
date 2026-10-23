(function () {
  'use strict';
  document.documentElement.classList.add('ss-js');
  var root = document.querySelector('.ss-help');
  if (!root) return;

  var GROUPS = [['a', 'àáạảãâầấậẩẫăằắặẳẵ'], ['e', 'èéẹẻẽêềếệểễ'], ['i', 'ìíịỉĩ'], ['o', 'òóọỏõôồốộổỗơờớợởỡ'], ['u', 'ùúụủũưừứựửữ'], ['y', 'ỳýỵỷỹ'], ['d', 'đ']];
  function fold(text) {
    var t = String(text).toLowerCase();
    GROUPS.forEach(function (g) { for (var i = 0; i < g[1].length; i++) t = t.split(g[1][i]).join(g[0]); });
    return t.replace(/[^a-z0-9%.,\s]/g, ' ').replace(/\s+/g, ' ').trim();
  }
  function all(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }

  document.querySelectorAll('[data-js-only]').forEach(function (el) { el.hidden = false; });

  /* ---- Deep-link ---- */
  function openChain(el) {
    for (var n = el; n && n !== root; n = n.parentElement) {
      if (n.tagName === 'DETAILS') n.open = true;
      if (n.hidden) n.hidden = false;
    }
  }
  function goToHash() {
    var id = '';
    try { id = decodeURIComponent((location.hash || '').slice(1)); } catch (err) { return; }
    if (!id) return;
    var el = document.getElementById(id);
    if (!el || !root.contains(el)) return;
    linking = true;
    openChain(el);
    var finish = function () {
      el.scrollIntoView({ block: 'start', behavior: 'instant' });
      var sum = el.querySelector(':scope > summary');
      if (sum) sum.focus({ preventScroll: true });
      setTimeout(function () { linking = false; }, 50);
    };
    // toggle của details mở bằng code bắn bất đồng bộ: chờ xong rồi mới cuộn
    requestAnimationFrame(function () { setTimeout(finish, 0); });
  }
  var linking = false;
  var syncing = false;
  root.addEventListener('toggle', function (e) {
    var d = e.target;
    if (syncing || linking || !d.open || !d.id || d.tagName !== 'DETAILS') return;
    if (/^(bai-|nhom-)/.test(d.id) && ('#' + d.id) !== location.hash) {
      try { history.replaceState(null, '', location.pathname + location.search + '#' + d.id); } catch (err) { /* bỏ qua */ }
    }
  }, true);
  window.addEventListener('hashchange', goToHash);

  /* ---- Hỏi Syna: mở widget trước, bộ bắt quicksend: của widget gửi sau ---- */
  function openWidget() {
    var panel = document.querySelector('[data-ai-chat-panel]');
    var toggle = document.querySelector('[data-ai-chat-toggle]');
    if (panel && panel.hidden && toggle) toggle.click();
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href^="quicksend:"]');
    if (!a || !root.contains(a)) return;
    if (a.getAttribute('href') === 'quicksend:') { e.preventDefault(); e.stopImmediatePropagation(); openWidget(); return; }
    openWidget();
  }, true);

  /* ---- Lọc, đếm, mở/thu ---- */
  var input = document.getElementById('help-search');
  var form = root.querySelector('[data-help-search]');
  var articles = all('.ss-acc--article');
  var groups = all('.ss-acc--group');
  var countEl = root.querySelector('[data-help-count]');
  var emptyEl = root.querySelector('[data-help-empty]');
  var askSearch = root.querySelector('[data-help-ask-search]');
  var askEmpty = root.querySelector('[data-help-ask-empty]');
  var titleOrig = new Map();

  function setAsk(a, text) { if (a) a.setAttribute('href', 'quicksend:' + encodeURIComponent(text)); }
  function highlight(el, tokens) {
    var span = el.querySelector(':scope > summary .ss-acc__title');
    if (!span) return;
    if (!titleOrig.has(span)) titleOrig.set(span, span.textContent);
    var text = titleOrig.get(span);
    while (span.firstChild) span.removeChild(span.firstChild);
    if (!tokens.length) { span.appendChild(document.createTextNode(text)); return; }
    var folded = '';
    for (var i = 0; i < text.length; i++) { var f = fold(text.charAt(i)); folded += f.length === 1 ? f : text.charAt(i).toLowerCase(); }
    var mask = [];
    for (var m = 0; m < text.length; m++) mask.push(false);
    tokens.forEach(function (tk) {
      var p = 0, at;
      while ((at = folded.indexOf(tk, p)) !== -1) { for (var k = at; k < at + tk.length; k++) mask[k] = true; p = at + tk.length; }
    });
    var buf = '', on = false;
    function flush() {
      if (!buf) return;
      if (on) { var mk = document.createElement('mark'); mk.appendChild(document.createTextNode(buf)); span.appendChild(mk); }
      else span.appendChild(document.createTextNode(buf));
      buf = '';
    }
    for (var j = 0; j < text.length; j++) { if (mask[j] !== on) { flush(); on = mask[j]; } buf += text.charAt(j); }
    flush();
  }
  function updateCount() {
    if (!countEl) return;
    var g = groups.filter(function (x) { return !x.hidden; }).length;
    var b = articles.filter(function (x) { return !x.hidden; }).length;
    countEl.textContent = g + ' nhóm · ' + b + ' bài';
  }
  function applyFilter(raw, fromUser) {
    var tokens = fold(raw).split(' ').filter(Boolean);
    articles.forEach(function (art) {
      var hit = tokens.every(function (t) { return (' ' + (art.dataset.search || '')).indexOf(' ' + t) !== -1; });
      art.hidden = !hit;
      if (tokens.length && hit) art.open = true;
      highlight(art, tokens);
    });
    groups.forEach(function (g) {
      var visible = all('.ss-acc--article', g).filter(function (a) { return !a.hidden; });
      g.hidden = tokens.length > 0 && visible.length === 0;
      var c = g.querySelector('[data-group-count]');
      if (c) c.textContent = tokens.length ? visible.length : all('.ss-acc--article', g).length;
      if (tokens.length && visible.length) g.open = true;
    });
    var none = tokens.length > 0 && !articles.some(function (a) { return !a.hidden; });
    if (emptyEl) { emptyEl.hidden = !none; var t = emptyEl.querySelector('[data-help-empty-term]'); if (t) t.textContent = raw.trim(); }
    var se = root.querySelector('[data-help-server-empty]'); if (se) se.hidden = true;
    setAsk(askEmpty, raw.trim());
    setAsk(askSearch, raw.trim());
    updateCount();
    if (fromUser) {
      try {
        var q = raw.trim();
        history.replaceState(null, '', location.pathname + '?r=tro_giup' + (q ? '&q=' + encodeURIComponent(q) : '') + location.hash);
      } catch (err) { /* bỏ qua */ }
    }
  }
  if (input && articles.length) {
    var timer = null;
    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { applyFilter(input.value, true); }, 150); });
    if (form) form.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(timer); applyFilter(input.value, true); });
    applyFilter(input.value, false);
  } else { updateCount(); }

  var expand = root.querySelector('[data-help-expand]');
  var collapse = root.querySelector('[data-help-collapse]');
  if (expand) expand.addEventListener('click', function () {
    syncing = true;
    groups.forEach(function (g) {
      if (g.hidden) return;
      g.open = true;
      all('details', g).forEach(function (d) { if (!d.closest('[hidden]')) d.open = true; });
    });
    setTimeout(function () { syncing = false; }, 0);
  });
  if (collapse) collapse.addEventListener('click', function () {
    syncing = true;
    groups.forEach(function (g) { all('details', g).forEach(function (d) { d.open = false; }); g.open = false; });
    setTimeout(function () { syncing = false; }, 0);
  });

  /* Chip chủ đề: mở nhóm và cuộn tới (href vẫn dùng được khi tắt JS) */
  all('[data-help-topic]', document).forEach(function (a) {
    a.addEventListener('click', function (e) {
      var g = document.getElementById('nhom-' + a.dataset.helpTopic);
      if (!g) return;
      e.preventDefault();
      g.hidden = false; g.open = true;
      try { history.replaceState(null, '', location.pathname + location.search + '#' + g.id); } catch (err) { /* bỏ qua */ }
      g.scrollIntoView({ block: 'start' });
      var s = g.querySelector(':scope > summary'); if (s) s.focus({ preventScroll: true });
    });
  });

  goToHash();
})();
