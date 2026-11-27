const CACHE_NAME = 'skinsyntax-static-v1';
const PAGE_CACHE = 'skinsyntax-pages-v1';
const PAGE_MAX_AGE_MS = 24 * 60 * 60 * 1000;
const PAGE_VIEW_ROUTES = 'home|tatca|danhmuc|danhsach|product_collection|flash_sale|goiy|yeu_thich';

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME && key !== PAGE_CACHE).map((key) => caches.delete(key))
    )).then(() => self.clients.claim())
  );
});

// Page cache (stale-while-revalidate for browse pages). Same allowlist as the speculation rules in footer.php.
function isCacheablePageUrl(url) {
  const base = new URL(self.registration.scope).pathname;
  const path = url.pathname;
  const search = url.search;
  if (path === base) return search === '';
  if (path === base + 'index.php') {
    if (search === '') return true;
    if (new RegExp('^[?]r=(' + PAGE_VIEW_ROUTES + ')(&|$)').test(search)) return !/[?&]q=/.test(search);
    return /^\?r=chitiet(&|$)/.test(search);
  }
  return false;
}

function pageCacheKey(url) {
  const u = new URL(url);
  u.hash = '';
  return u.href;
}

function isStorablePage(response) {
  return response.status === 200 && !response.redirected && !response.headers.has('X-SS-No-Store-Page')
    && (response.headers.get('Content-Type') || '').toLowerCase().indexOf('text/html') !== -1;
}

async function storePage(key, response) {
  const body = await response.arrayBuffer();
  const headers = new Headers(response.headers);
  headers.set('X-SS-Cached-At', String(Date.now()));
  // Remember which account this page was rendered for (meta ss-auth-tag) so a cached page is never served to another account.
  try {
    const m = new TextDecoder().decode(body.slice(0, 65536)).match(/<meta name="ss-auth-tag" content="([^"]*)"/);
    if (m) headers.set('X-SS-Auth-Tag', m[1]);
  } catch (e) {}
  const cache = await caches.open(PAGE_CACHE);
  await cache.put(key, new Response(body, { status: 200, statusText: response.statusText, headers }));
}

async function servePageFromCache(event) {
  const request = event.request;
  const key = pageCacheKey(request.url);
  const cache = await caches.open(PAGE_CACHE);
  let hit = await cache.match(key);
  if (hit && self.cookieStore) {
    // Account changed since this page was stored (login/logout/switch): drop every cached page, go to network.
    try {
      const current = await self.cookieStore.get('ss_auth_tag');
      const stored = hit.headers.get('X-SS-Auth-Tag');
      if (current && stored && current.value !== stored) {
        await caches.delete(PAGE_CACHE);
        hit = undefined;
      }
    } catch (e) {}
  }
  if (hit) {
    const age = Date.now() - Number(hit.headers.get('X-SS-Cached-At') || 0);
    if (age >= 0 && age < PAGE_MAX_AGE_MS) {
      const html = (await hit.text()).replace('</head>', '<meta name="ss-from-cache" content="1"></head>');
      const headers = new Headers(hit.headers);
      headers.delete('Content-Length');
      headers.set('X-SS-From-Cache', '1');
      return new Response(html, { status: 200, statusText: 'OK', headers });
    }
    await cache.delete(key);
  }
  const response = await fetch(request);
  if (isStorablePage(response)) {
    event.waitUntil(storePage(key, response.clone()).catch(() => {}));
  }
  return response;
}

async function revalidatePage(event) {
  const request = event.request;
  const key = pageCacheKey(request.url);
  const response = await fetch(request);
  if (response.status === 404 && /[?&]r=chitiet(&|$)/.test(new URL(request.url).search)) {
    event.waitUntil(caches.open(PAGE_CACHE).then((cache) => cache.delete(key)).catch(() => {}));
  } else if (isStorablePage(response)) {
    event.waitUntil(storePage(key, response.clone()).catch(() => {}));
  }
  return response;
}

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'ss-purge-pages') {
    event.waitUntil(caches.delete(PAGE_CACHE));
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (request.method !== 'GET' || url.origin !== self.location.origin) return;

  if (isCacheablePageUrl(url)) {
    if (request.headers.get('X-SS-Revalidate') === '1') {
      event.respondWith(revalidatePage(event));
      return;
    }
    if (request.mode === 'navigate') {
      event.respondWith(servePageFromCache(event));
      return;
    }
  }

  // Do not cache navigations or API/form responses: profile, consultation,
  // cart and checkout data must always come from the current server session.
  if (request.mode === 'navigate' || url.pathname.endsWith('/index.php')) return;

  const isStatic = ['style', 'script', 'image', 'font', 'manifest'].includes(request.destination);
  if (!isStatic) return;

  event.respondWith(
    caches.match(request).then((cached) => {
      const network = fetch(request).then((response) => {
        if (response.ok) {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
        }
        return response;
      });
      return cached || network;
    })
  );
});
