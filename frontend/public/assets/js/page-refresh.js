/* Service Worker page cache companion: runs only on a page the SW served from cache (meta ss-from-cache). */
(function () {
  'use strict';
  if (!document.querySelector('meta[name="ss-from-cache"]')) return;

  var PAGE_CACHE = 'skinsyntax-pages-v1';

  function metaTag(doc) {
    var m = doc.querySelector('meta[name="ss-auth-tag"]');
    return m ? m.getAttribute('content') : null;
  }

  function cookieTag() {
    var m = document.cookie.match(/(?:^|;\s*)ss_auth_tag=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : null;
  }

  function purgeAndReload() {
    var done = function () { location.reload(); };
    try {
      if (navigator.serviceWorker && navigator.serviceWorker.controller) {
        navigator.serviceWorker.controller.postMessage({ type: 'ss-purge-pages' });
      }
    } catch (e) {}
    if (window.caches && caches.delete) {
      caches.delete(PAGE_CACHE).then(done, done);
    } else {
      done();
    }
  }

  // Exactly-once product view for a detail page shown from cache (same key scheme as the prefetch beacon in chitiet.php).
  function sendViewBeacon() {
    try {
      var meta = document.getElementById('productViewMeta');
      if (!meta || !meta.dataset.productViewId) return;
      var nav = performance.getEntriesByType('navigation')[0];
      var start = nav ? Math.round(nav.startTime + (performance.timeOrigin || 0)) : 0;
      var key = 'pva:' + start + ':' + location.href;
      try {
        if (sessionStorage.getItem(key)) return;
        sessionStorage.setItem(key, '1');
      } catch (e) {}
      var fd = new FormData();
      fd.append('id', meta.dataset.productViewId);
      if (meta.dataset.productViewQ) fd.append('q', meta.dataset.productViewQ);
      var base = location.pathname.replace(/[^\/]*$/, '');
      navigator.sendBeacon(base + 'index.php?r=product_view_activate', fd);
    } catch (e) {}
  }

  // Update in place (keep the node, so scripts holding references still work); skip when nothing changed.
  function swapRegion(cur, fresh) {
    if (cur.outerHTML === fresh.outerHTML) return;
    Array.prototype.slice.call(cur.attributes).forEach(function (a) {
      if (!fresh.hasAttribute(a.name)) cur.removeAttribute(a.name);
    });
    Array.prototype.slice.call(fresh.attributes).forEach(function (a) {
      if (cur.getAttribute(a.name) !== a.value) cur.setAttribute(a.name, a.value);
    });
    cur.innerHTML = fresh.innerHTML;
  }

  function revalidate() {
    fetch(location.href, {
      headers: { 'X-SS-Revalidate': '1' },
      credentials: 'same-origin',
      cache: 'no-store'
    }).then(function (res) {
      var type = res.headers.get('Content-Type') || '';
      if (!res.ok || type.indexOf('text/html') === -1) return null;
      return res.text();
    }).then(function (html) {
      if (!html) return;
      var fresh = new DOMParser().parseFromString(html, 'text/html');
      var freshTag = metaTag(fresh);
      if (freshTag !== null && freshTag !== metaTag(document)) { purgeAndReload(); return; }
      Array.prototype.slice.call(document.querySelectorAll('[data-live-region]')).forEach(function (cur) {
        var name = cur.getAttribute('data-live-region');
        var next = null;
        var all = fresh.querySelectorAll('[data-live-region]');
        for (var i = 0; i < all.length; i++) {
          if (all[i].getAttribute('data-live-region') === name) { next = all[i]; break; }
        }
        if (next) swapRegion(cur, next);
      });
    }).catch(function () { /* offline / server error: keep the cached page silently */ });
  }

  var tag = metaTag(document);
  if (tag === null || tag !== cookieTag()) {
    purgeAndReload();
    return;
  }
  sendViewBeacon();
  revalidate();
})();
