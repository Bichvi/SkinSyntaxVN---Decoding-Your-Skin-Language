# Instant Load Phase 1 (Prefetch on hover/touch) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pages the user is about to open are prefetched on hover (~200 ms) or touch, so navigation is near-instant, without double- or phantom-counting product views.

**Architecture:** A `<script type="speculationrules">` block in the shared customer footer prefetches an allowlist of read-only routes. `chitiet` detects prefetch requests (`Sec-Purpose: prefetch`) and skips its side effects; when the user actually opens a prefetched detail page, a small script sees `deliveryType === 'navigational-prefetch'` and POSTs a beacon to a new route that runs the same side effects exactly once.

**Tech Stack:** PHP 8 (MVC, PHP-FPM in Docker `skinsyntax-php`), Chrome Speculation Rules API, `navigator.sendBeacon`, Redis-buffered view counter (`ProductViewCounter`).

**Spec:** `docs/superpowers/specs/2026-11-27-instant-load-design.md`

## Global Constraints

- Never prefetch a URL with side effects: cart/add-to-cart, checkout, order, payment/VNPay, logout/login, account edits, admin (`r=admin*`, `r=quantri*`), delete, chat, video_watch, any POST.
- Only same-origin `index.php?r=` links to: `home`, `tatca` (only without `q=`), `danhmuc`, `danhsach`, `product_collection`, `flash_sale`, `chitiet`, `goiy`, `yeu_thich`. Bare `index.php` / `/` counts as `home`. Any of these routes found to write `$_SESSION` or the DB on GET (other than `chitiet`, handled below) is dropped from the allowlist (e.g. `home` consumes `$_SESSION['syna_survey_success']` — drop `home` unless that flash is only consumed when set, in which case keep it and document why).
- Eagerness `moderate`. Browsers without Speculation Rules: behavior unchanged.
- No changes to recommendation/scoring logic. Do not commit. Do not run scripts in `backend/tests` in bulk. Run GitNexus `impact` before editing `chitiet` / `trackRecentViewedProduct`.
- Product view side effects must happen exactly once per real open: `tangLuotXem`, `trackRecentViewedProduct`, `ProductEventTracker::record('product_view')`, and when `q` is present `saveSearchHistory` + `InteractionLogger::logSearchClick`.

## Review Focus

- Prefetched detail page that the user never opens → zero view/event/recent-viewed writes.
- Prefetched detail page opened twice (back/forward restore from bfcache) → beacon fires only on the activation navigation, not on bfcache restore (`pageshow.persisted`).
- Detail page opened normally (no prefetch, or Firefox/Safari) → side effects exactly once, no beacon.
- Detail link from search results (`q=` present) → search click logged once, on activation.
- Beacon route hit with missing/invalid/non-existent id or via GET → 400/405, no writes.

---

### Task 1: Prefetch-aware product view side effects + activation beacon

**Files:**
- Modify: `backend/app/controllers/SanPhamController.php` (`chitiet()` ~709–785; new methods)
- Modify: `index.php` (router: new case `product_view_activate`)
- Modify: `frontend/views/chitiet.php` (activation script)

**Interfaces:**
- Produces: `private static function isPrefetchRequest(): bool` — true when `$_SERVER['HTTP_SEC_PURPOSE']` contains `prefetch` (case-insensitive) or legacy `HTTP_PURPOSE` / `HTTP_X_MOZ` equals `prefetch`.
- Produces: `private function recordProductView(string $id, array $p, string $q): ?int` — the existing side-effect block moved verbatim (returns `$pending` from `tangLuotXem`).
- Produces: `public function productViewActivate(): void` — route `r=product_view_activate`, POST only (else 405), body/form fields `id` (digits) and optional `q`; loads product with the same visibility check `chitiet` uses (404 JSON if missing); calls `recordProductView`; `session_write_close()` after; responds `204`.
- Page contract: detail page body/root element carries `data-product-view-id` and `data-product-view-q` (h()-escaped).

- [ ] **Step 1: Baseline.** Find the Redis key `ProductViewCounter` uses for pending views and the `product_events` write path; record how to read the pending count for product `1796` without touching Atlas (`docker exec skinsyntax-redis redis-cli ...`).
- [ ] **Step 2: Implement** `isPrefetchRequest`, extract `recordProductView`, and in `chitiet()` call it only when `!isPrefetchRequest()`; when prefetching, keep `$p['luot_xem']` unincremented and still render recent-viewed from the current session (read-only). Add `Vary: Sec-Purpose` header on `chitiet`.
- [ ] **Step 3: Implement** `productViewActivate` + router case.
- [ ] **Step 4: Activation script** in `chitiet.php`: on load, if `performance.getEntriesByType('navigation')[0]?.deliveryType === 'navigational-prefetch'`, `navigator.sendBeacon(BASE_URL + '/index.php?r=product_view_activate', FormData{id,q})`; guard with a `sessionStorage` key `pva:<navigation start time>` so it fires at most once per navigation; never on `pageshow` with `persisted`.
- [ ] **Step 5: Verify** (`php -l` on changed PHP; curl with a logged-in cookie jar):
  - `curl -H "Sec-Purpose: prefetch" ...r=chitiet&id=1796` ×2 → pending view count unchanged.
  - plain `curl ...r=chitiet&id=1796` → +1.
  - `curl -X POST -d id=1796 ...r=product_view_activate` → 204 and +1; `GET` → 405; `id=abc` → 400; `id=99999999` → 404.

### Task 2: Speculation rules in shared footer

**Files:**
- Modify: `frontend/views/layouts/footer.php` (next to the service-worker registration ~line 590)

**Interfaces:**
- Consumes: Task 1 (detail pages are safe to prefetch).

- [ ] **Step 1: Audit** each allowlisted route's GET handler for `$_SESSION` writes / DB writes; drop or document per Global Constraints. Record findings in the report.
- [ ] **Step 2: Add** the speculation rules JSON: `prefetch`, `eagerness: "moderate"`, `where.and` = same-origin `href_matches` for each allowed `/index.php?r=<route>*` (account for `BASE_URL`), `not` any `href_matches` containing `q=` except for `chitiet`, `not selector_matches` `[data-no-prefetch], [target=_blank], [download]`, and `not` the excluded routes. Emit only when `HTMLScriptElement.supports?.('speculationrules')` (inline JS that injects the script) so other browsers see nothing.
- [ ] **Step 3: Verify in real Chrome** (logged-in tab on `http://localhost:8080`): hover a product card ≥300 ms → a prefetch request with `Sec-Purpose: prefetch` appears; click → new page's navigation `deliveryType === 'navigational-prefetch'`, TTFB ≈ 0, exactly one `product_view_activate` beacon (204). Hover cart / logout / add-to-cart → no prefetch request. Report measured click-to-paint before/after for home→detail and home→tatca.
- [ ] **Step 4:** `git diff --check` on changed files.
