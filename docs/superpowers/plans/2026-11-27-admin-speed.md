# Admin Pages Speed-up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Cut admin page time without changing any number, list, or control the admin sees.

**Architecture:** Remove N+1 Atlas round-trips and repeated full-collection scans in `backend/app/models/QuanTri.php` (per-request memo + batched `$in` lookups), and stop rendering the full product `<option>` list once per live row on `admin_lives`.

**Baseline (logged-in E2E admin, localhost:8080, TTFB):** reports 75 s (1091 Mongo calls; `QuanTri.php:1001` `san_pham->findOne` per order line; full `hoa_don->find([])` at 951/981), users 15.6 s (210 calls; `QuanTri.php:1338` `hoa_don->find(['ma_kh'=>…])` per customer), lives 8.1 s and 28.9 MB HTML (130,151 `<option>` elements), ai_idol 5.2 s, dashboard 4.6 s (full `hoa_don` scans at 420/485/732/908/981), orders 3.4 s, inventory_batches 2.6 s, ai_product_videos 2.6 s, tag_review 2.4 s.

## Global Constraints
- Output must be identical: same totals, ordering, labels, links, form fields and values. Verify by HTML snapshot diff (before vs after), after normalizing volatile bits (CSRF tokens, nonces, timestamps/`?v=` cache-busters, random ids). Any remaining diff must be explained in the report.
- Read paths only. Do not change any write/save/delete method behaviour.
- Mongo type semantics must be preserved: when batching, query with the exact same values/types the old per-row query used (e.g. `ma_kh` as stored; the product lookup's `$or` over `ma_san_pham`/`id` string+int forms).
- No Redis caching of admin data (admins expect live numbers). Per-request memo only.
- No commits. Never run `backend/tests` scripts in bulk (they write real Atlas). Use the Edit tool. GitNexus `impact` before editing each existing method; report risk.

## Review Focus
- Customers with zero orders still show 0 / 0.
- Order lines whose product was deleted still show the raw id as name (old fallback).
- Report date-range filters (`startDate`/`endDate` query params) give the same results as before.
- A live row's product select still preselects that live's current product(s) and submits the same field names/values.
- Dashboard widgets that share the memoized order list must not mutate it.

### Task 0: Baseline snapshots
- [ ] Log in as E2E admin (GET `r=dangnhap`, POST `r=xulydangnhap` with `email`/`mat_khau` from `E2E_ADMIN_EMAIL`/`E2E_ADMIN_PASSWORD` in repo `.env`; never print them). Save HTML + TTFB for every admin page in the menu (`admin_dashboard admin_sp admin_orders admin_users admin_categories admin_flash_sales admin_inventory_batches admin_lives admin_questions admin_reports admin_search_ops admin_tag_review admin_vouchers admin_feature_feedback admin_ai_idol admin_ai_product_videos`) plus `admin_reports` with a date range, into a scratch dir.

### Task 1: QuanTri report/dashboard/customers
**Files:** `backend/app/models/QuanTri.php`
- [ ] Add `private ?array $allOrdersMemo = null; private function allOrders(): array` (loads `hoa_don->find([])` once per request, returns arrays). Replace read-only full scans of `hoa_don` in report/dashboard methods with it (lines ~420, 485, 732, 908, 951, 981 and any similar), only where the loop does not write.
- [ ] `getTopProductsByRevenue`: collect product ids first, one `san_pham->find(['$or'=>[...$in forms...]])` with projection `ma_san_pham,id,ten_san_pham`, build map, keep the same fallback.
- [ ] Customer list (~1335): one `hoa_don->find(['ma_kh'=>['$in'=>$ids]], projection ma_kh,tong_tien)` and aggregate in PHP (count + sum) — identical totals.
- [ ] Profile again (Mongo call count + time) for reports, users, dashboard; fix any remaining obvious N+1 in QuanTri read paths for orders/inventory pages the same way.

### Task 2: admin_lives option bloat
**Files:** the admin lives view under `frontend/views/admin/lives.php` (+ controller only if needed)
- [ ] Render the product options once (e.g. one hidden `<template id="liveProductOptions">` or a JSON blob) and populate each row's select client-side on page load (or on first focus), preserving per-row preselection via a `data-selected` attribute and the same `name`/`value`s. If the select is a searchable widget, keep its behaviour.
- [ ] Verify HTML size drops (target < 2 MB) and that a row's form still submits the same fields (inspect DOM in a browser if available; otherwise state NOT verified).

### Task 3: Re-measure + diff
- [ ] TTFB for all admin pages; snapshot diff vs Task 0; `php -l`; `git diff --check`; `docker logs --tail 50 skinsyntax-php` clean.
- [ ] Profile `admin_ai_idol`, `admin_orders`, `admin_inventory_batches`, `admin_tag_review`, `admin_ai_product_videos` and report top hotspots (file:line, call count) — fix only if it's a clear N+1 in QuanTri with identical output; otherwise just report.
