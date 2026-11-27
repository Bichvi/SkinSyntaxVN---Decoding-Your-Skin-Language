# Instant Load Phase 2 (Service Worker page cache + background refresh) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-opening a browse page shows the previously seen version instantly, then dynamic parts update in place within seconds — without double-counting views, mixing accounts, or replaying one-time messages.

**Architecture:** `sw.js` gains a stale-while-revalidate handler for allowlisted navigations, storing HTML in cache `skinsyntax-pages-v1` and injecting a `ss-from-cache` meta when serving it. A small page script (`frontend/public/assets/js/page-refresh.js`, loaded from the footer) detects that marker, verifies the auth tag, fetches the fresh HTML with `X-SS-Revalidate: 1`, swaps `[data-live-region]` elements, and fires the detail-page view beacon. Server treats `X-SS-Revalidate` like a prefetch and emits `ss_auth_tag` + `X-SS-No-Store-Page`.

**Tech Stack:** Service Worker + Cache Storage, PHP 8 MVC, vanilla JS.

**Spec:** `docs/superpowers/specs/2026-11-27-instant-load-design.md` (section "Bước 2")

## Global Constraints

- Allowlist identical to phase 1 footer rules: bare `/`, `/index.php`, and `r=` in `home|tatca|danhmuc|danhsach|product_collection|flash_sale|goiy|yeu_thich` (excluding any `q=`), plus `r=chitiet` (with or without `q=`). Everything else: SW does not touch it (current behavior).
- Only cache `GET` navigations, `response.ok`, `content-type` text/html, without `X-SS-No-Store-Page: 1`. Max age 24 h (store timestamp in a header like `X-SS-Cached-At` on the stored copy).
- Never cache or alter: POST, admin, cart, checkout, auth, chat, API routes, assets handling (keep existing static logic in `sw.js` unchanged).
- Bump the SW so clients update (`self.skipWaiting` + `clients.claim` already exist); keep static cache name logic intact.
- Do not change recommendation/scoring logic. Do not commit. Never run `backend/tests` scripts in bulk. GitNexus `impact` before editing existing PHP symbols.

## Review Focus

- Logged-out → log in (or switch account) → open home: must never show the previous account's header/cart for more than the instant before reload; cache purged.
- Page cached while a flash message ("Đã đăng xuất", survey success) was shown → never cached / never replayed.
- Detail page served from cache: exactly one view counted (beacon), background revalidation counts zero.
- Offline / server 5xx during background refresh: cached page stays, no error UI, no swap.
- Fresh HTML missing a live region (e.g. product now hidden/404): leave cached region as is, and if the fresh response is 404 for a `chitiet`, delete it from cache.

---

### Task 1: Server support (revalidate header, auth tag, no-store marker, live regions)

**Files:**
- Modify: `backend/public/index.php` and `index.php` (extend the phase-1 prefetch `session_write_close` condition to `HTTP_X_SS_REVALIDATE === '1'`; emit `ss_auth_tag` cookie: path `/`, SameSite=Lax, not HttpOnly, value `substr(hash('sha256', <user email or ma_kh> . '|' . session_name()), 0, 12)` or `guest`)
- Modify: `backend/app/controllers/SanPhamController.php` (`isPrefetchRequest()` also true for `X-SS-Revalidate: 1`)
- Modify: `frontend/views/layouts/header.php` (meta `ss-auth-tag`; `data-live-region="header-account"` and `data-live-region="header-cart"` on the account block and cart badge wrapper)
- Modify: the layout/partial that renders flash messages (find `set_flash` consumer) → when a flash is rendered, send header `X-SS-No-Store-Page: 1` (must be sent before output; if output already started, set it via an early check of pending flash in the front controller instead)
- Modify: `frontend/views/chitiet.php` (`data-live-region="pd-price"`, `"pd-stock"`, `"pd-buy"` on price/discount block, stock block, add-to-cart/buy block — pick the smallest wrapping elements that contain those values; ids must be unique per page)

**Interfaces:**
- Produces: header `X-SS-No-Store-Page: 1`; cookie + meta `ss_auth_tag`; `[data-live-region]` names above; `X-SS-Revalidate: 1` accepted.

- [ ] Step 1: GitNexus impact for `isPrefetchRequest`, `chitiet`; record risk.
- [ ] Step 2: Implement.
- [ ] Step 3: Verify with curl (logged-in jar `$TEMP/cj2.txt`, product 1796, Redis hash `product:views:v1`): `X-SS-Revalidate: 1` GET chitiet ×2 → pending count unchanged; response has `ss_auth_tag` cookie and meta with the same value; anonymous request gets `guest`-derived tag different from logged-in; after logout (`r=dangxuat`, then follow redirect) the home response carries `X-SS-No-Store-Page: 1`. `php -l` all changed PHP.

### Task 2: Service worker page cache + page refresh script

**Files:**
- Modify: `sw.js` (root; served via `r=pwa_sw`; check whether `backend/sw.js` is a copy that must stay identical — the router serves the first of `__DIR__`, parent, grandparent that has both files)
- Create: `frontend/public/assets/js/page-refresh.js` (confirm the real public assets dir served at `/assets/js/`)
- Modify: `frontend/views/layouts/footer.php` (load `page-refresh.js` with `defer`, versioned like other scripts)

**Interfaces:**
- Consumes: Task 1 header/cookie/meta/regions, phase-1 `product_view_activate` beacon (reuse the guard logic from `chitiet.php`; when the page is from cache, send the beacon even though `deliveryType` is not prefetch — keep exactly-once via the existing sessionStorage key scheme).
- Produces: SW message `{type:'ss-purge-pages'}` that deletes `skinsyntax-pages-v1`; SW message `{type:'ss-put-page', url, html}` is NOT allowed (page sends fresh HTML only by re-fetching through SW with `X-SS-Revalidate`, and the SW stores that network response itself).

- [ ] Step 1: SW: allowlist matcher (same patterns as footer rules, relative to SW scope), `cache-first` for allowlisted navigations with meta injection (`</head>` → `<meta name="ss-from-cache" content="1"></head>`), network fallback + store. Requests carrying `X-SS-Revalidate: 1` go network-first and the SW stores the result (if cacheable) or deletes the entry on 404. Purge message handler.
- [ ] Step 2: `page-refresh.js`: if no `meta[name=ss-from-cache]` → do nothing. Else: compare `meta[name=ss-auth-tag]` with `ss_auth_tag` cookie; mismatch → post purge to SW, then `location.reload()` (SW will miss → network). Match → (a) on chitiet send beacon; (b) `fetch(location.href, {headers:{'X-SS-Revalidate':'1'}, credentials:'same-origin'})`, parse with `DOMParser`, for each `[data-live-region]` in current doc replace `outerHTML` with the same-named fresh node if present; ignore failures silently.
- [ ] Step 3: Verify in Chrome (DevTools/automation note: preloading is disabled under the debugger, but SW is not): logged-in tab → open home (network) → open again → `document.querySelector('meta[name=ss-from-cache]')` present, nav TTFB/FCP small; header cart region equals server truth after refresh; on detail page from cache exactly one `product_view_activate` POST and the revalidate GET in nginx log; log out → home not from cache; log in again → no stale account. Report numbers.
- [ ] Step 4: `git diff --check`; `node --check sw.js page-refresh.js`.
