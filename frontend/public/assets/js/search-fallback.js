/* Smart search fallback (Bước 07). Delegated handlers: #catalogResults có thể bị live-filter thay innerHTML. */
(function () {
  'use strict';

  function fillSynaPrompt(text) {
    var tries = 0;
    (function attempt() {
      var input = document.querySelector('[data-ai-chat-input]');
      if (input) {
        input.value = text;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        try { input.focus(); } catch (e) { /* ignore */ }
        return;
      }
      if (++tries < 20) setTimeout(attempt, 100);
    })();
  }

  document.addEventListener('click', function (event) {
    var btn = event.target.closest && event.target.closest('[data-ssf-ask-syna]');
    if (!btn) return;
    event.preventDefault();
    var prompt = btn.dataset.ssfPrompt || '';
    if (typeof window.openSynaChat === 'function') {
      window.openSynaChat();
    } else {
      var launcher = document.querySelector('[data-ai-chat-toggle], [data-ai-chat-open]');
      if (launcher) launcher.click();
    }
    fillSynaPrompt(prompt);
  });

  // ── Tracking (Bước 08) → index.php?r=search_event → Mongo search_events ──
  // impression: 1 lần/section/lần render (khi ≥25% section hiện ra); click: link card; add_to_cart: form
  // thêm giỏ/mua ngay trong card; chip_click: chip lọc nhanh. Không gửi gì ngoài q, section, product_id.
  var MAX_BATCH = 10;
  var queues = new Map(); // root -> events[]
  var flushTimer = null;
  var seenSections = typeof WeakSet === 'function' ? new WeakSet() : null;

  function rootOf(el) {
    return el && el.closest ? el.closest('.ssf[data-ssf]') : null;
  }

  function send(root, events) {
    var endpoint = root.dataset.ssfEndpoint;
    var csrf = root.dataset.ssfCsrf;
    var q = root.dataset.ssfQ || '';
    if (!endpoint || !csrf || !q || !events.length) return;
    for (var i = 0; i < events.length; i += MAX_BATCH) {
      var body = JSON.stringify({ q: q, csrf: csrf, events: events.slice(i, i + MAX_BATCH) });
      var sent = false;
      try {
        if (navigator.sendBeacon) {
          sent = navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
        }
      } catch (e) { sent = false; }
      if (!sent && window.fetch) {
        try {
          fetch(endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' } }).catch(function () {});
        } catch (e) { /* ignore */ }
      }
    }
  }

  function flush() {
    if (flushTimer) { clearTimeout(flushTimer); flushTimer = null; }
    queues.forEach(function (events, root) { send(root, events); });
    queues.clear();
  }

  function track(root, event, immediate) {
    if (!root) return;
    if (!queues.has(root)) queues.set(root, []);
    queues.get(root).push(event);
    if (immediate) { flush(); return; }
    if (!flushTimer) flushTimer = setTimeout(flush, 400);
  }

  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      io.unobserve(entry.target);
      impression(entry.target);
    });
  }, { threshold: 0.25 }) : null;

  function impression(section) {
    var key = section.dataset.ssfSectionKey;
    if (key) track(rootOf(section), { action: 'impression', section: key });
  }

  function scanSections() {
    var sections = document.querySelectorAll('.ssf[data-ssf] .ssf-section[data-ssf-section-key]');
    for (var i = 0; i < sections.length; i++) {
      var s = sections[i];
      if (seenSections) {
        if (seenSections.has(s)) continue;
        seenSections.add(s);
      } else if (s.dataset.ssfSeen) {
        continue;
      } else {
        s.dataset.ssfSeen = '1';
      }
      if (io) io.observe(s); else impression(s);
    }
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !target.closest) return;
    var chip = target.closest('.ssf-chip');
    if (chip) {
      var kind = chip.dataset.ssfChipKind;
      if (kind) track(rootOf(chip), { action: 'chip_click', section: 'chips', chip: kind }, true);
      return;
    }
    var link = target.closest('.ssf-card a[href]');
    if (!link) return;
    var card = link.closest('.ssf-card');
    if (card && card.dataset.productId && card.dataset.ssfSection) {
      track(rootOf(card), { action: 'click', section: card.dataset.ssfSection, product_id: card.dataset.productId }, true);
    }
  }, true);

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var card = form && form.closest ? form.closest('.ssf-card') : null;
    if (!card || !card.dataset.productId || !card.dataset.ssfSection) return;
    track(rootOf(card), { action: 'add_to_cart', section: card.dataset.ssfSection, product_id: card.dataset.productId }, true);
  }, true);

  window.addEventListener('pagehide', flush);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flush();
  });

  function init() {
    scanSections();
    if ('MutationObserver' in window && document.body) {
      var pending = null;
      new MutationObserver(function () {
        if (pending) return;
        pending = setTimeout(function () { pending = null; scanSections(); }, 100);
      }).observe(document.body, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
