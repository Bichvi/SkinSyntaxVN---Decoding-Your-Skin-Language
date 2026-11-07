# Live search follow-ups: admin orders 504, slow admin pages, help route, search history — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Execute tasks in order; each ends with its own verification. Report in Vietnamese.

**Goal:** Make the pages that now use live search respond fast enough to feel realtime, fix the admin order list that times out (504), wire the help/support routes, and keep saving search history when the user presses Enter.

**Architecture:** Same pattern already proven on `TaiKhoan::getOrderHistory` (section 33 of `update.md`): keep each public method's signature and output identical, replace per-row Mongo round trips (~50 ms each to Atlas) with `$in` / aggregate queries, and prove equality with an old-vs-new snapshot script before switching. Live-filter requests (`X-Requested-With: live-filter`) skip work the result region does not need and release the PHP session lock early.

**Tech Stack:** PHP 8 (container `skinsyntax-php`, `./backend` → `/var/www/html`, `./frontend` → `/var/www/frontend`), MongoDB Atlas, vanilla JS (`frontend/public/assets/js/live-filter.js`), Docker Compose.

**Spec:** user decisions on 14/10/2026: fix admin orders, save history on Enter, wire the help route, speed up slow admin pages + raise PHP-FPM workers.

## Global Constraints

- Read `AGENTS.md`. Run GitNexus impact before editing an existing method: `node .gitnexus/run.cjs impact "<name>" --direction upstream --repo .`. The index is stale for untracked files, so confirm callers with grep. `listOrders` is already known HIGH (9 dependants) and is approved by the user. Report any other HIGH/CRITICAL result in the final report.
- Public method signatures and return shapes must stay identical (same keys, same key order, same values). Every data-layer rewrite needs an equality script run on real Atlas data **before and after**.
- No DB writes except the minimal test rows in Task 6, which must be deleted again. No commit. Do not use the browser pane; verify with curl. The main session does browser E2E afterwards.
- Do not touch: `frontend/views/hoso.php`, `backend/app/services/OrderTabs.php`, `backend/app/models/TaiKhoan.php`, `backend/app/models/DanhGia.php`, `backend/app/controllers/TaiKhoanController.php`.
- Lint: `php -l` (views via `cat f | docker exec -i skinsyntax-php sh -c 'cat > /tmp/l.php && php -l /tmp/l.php; rm -f /tmp/l.php'`), `node --check` for JS. In Git Bash prefix docker commands with `export MSYS_NO_PATHCONV=1;`. Host PHP contract test `/c/xampp/php/php.exe backend/tests/admin_usecase_contract_test.php` must stay 327/327.
- Test logins (local dev, values from project seed files / earlier session): admin `admin123@skinsyntax.vn` (seed `scripts/seed_admin_staff.php`), customer `minh.pham105@example.test` (password = part before `@`). Login = GET `/index.php?auth=login`, then POST `email`, `mat_khau` to `/index.php?r=xulydangnhap` with a cookie jar. Never print passwords in reports.
- Keep each file's existing line endings (most are CRLF).

## Review Focus

1. **Type-sensitive ids:** Mongo `$in` matches by type (`"93"` ≠ `93`). Every batched lookup must reproduce the old `findOne` match exactly (exact type first, first document in natural order). Reuse the `lookupKey` / `findFirstByValues` approach from `TaiKhoan.php`, copied as private helpers; do not edit `TaiKhoan.php`.
2. **Status filter applied before enrichment** in `listOrders` (old code skips a filtered-out order *before* fetching its customer): output must still be identical for every status.
3. **Session lock release** must never drop a session write that matters: only for `X-Requested-With: live-filter` **without** `X-Live-Commit`, and only GET.
4. **Help route wiring** must not change any route that currently returns 200. Only routes that currently fall through to the 404 `default` may reach `store_assistance.php`.
5. **Pagination of the admin order list** must keep the "Có N đơn chờ duyệt" counter and the CSV/Excel export computed on the **full** list.

---

### Task 1: `QuanTri::listOrders` without N+1 (fixes `admin_orders` 504)

**Files:** Modify `backend/app/models/QuanTri.php` (`listOrders`, plus new private helpers). Scratch script `scratchpad/list_orders_cmp.php` (outside the repo; copy into the container `/tmp`).

Current cost: 2,341 orders × (`khach_hang->findOne` + `chi_tiet_hoa_don->countDocuments`) ≈ 4,700 round trips, which is why the page returns 504.

- [ ] **Step 1:** Write the snapshot script. For each argument pair, `json_encode(listOrders(q, status))` → one JSON file. Pairs: `('', '')`; `('', s)` for s in `pending, confirmed, shipping, completed, cancelled, cho_xu_ly, Hoàn thành`; keywords `('5022','')`, `('minh','')`, `('Hoàn','')`, `('cod','')`, `('zzz','')`, `('minh','completed')`. Run it on the **current** code → `/tmp/lo_before.json` (slow, possibly several minutes; run it in the background if needed). Record the time.
- [ ] **Step 2:** Rewrite the body. Keep the order loop (sort, normalization, status skip) as is. After the status filter, collect `ma_kh` and fetch customers with one `$in` (projection `ma_kh, ho_ten, email, so_dien_thoai`), first match per type key. Keep `$order['ho_ten'] = $kh['ho_ten'] ?? null` semantics as before. Apply the keyword filter unchanged. For `so_dong_hang`, one aggregate on `chi_tiet_hoa_don`: `$match {ma_hoa_don: {$in: ids}}`, `$group {_id: '$ma_hoa_don', n: {$sum: 1}}`, mapped by type key, default 0. Then build `$items` in the original order.
- [ ] **Step 3:** Snapshot again → `/tmp/lo_after.json`. Must print `IDENTICAL` (`===` on decoded arrays). Record the new time.

### Task 2: Admin order list pagination (`admin_orders`, `staff_orders`)

**Files:** Modify `backend/app/controllers/QuanTriController.php` (`adminOrders`, `staffOrders`; export path unchanged) and `frontend/views/admin/orders.php`.

- [ ] Controller: `$all = listOrders($q, $status)`. Pass `orders` = slice of 50 for `page` (`?page=`, clamped to 1..pages), plus `orderTotal`, `orderPage`, `orderPages` and `pendingCount` (counted on `$all` with the same rule the view uses now). The export keeps using the full list.
- [ ] View: use `$pendingCount` when set (fallback to the current loop). Inside `#orderListPanel`, add pagination ("‹ Trước · Trang X/Y · N đơn · Sau ›") with links that keep `q`, `status`, `detail`. Add `page` to the form's `data-live-reset`.
- [ ] Verify with curl as admin: `r=admin_orders` returns 200, time recorded, 50 rows, pagination present; `&page=2`; `&q=minh`; `&status=pending` (counter still correct). Also check `r=staff_orders` and `r=admin_orders&export=excel` (the CSV row count equals the full list).

### Task 3: `QuanTri::listCustomers` and `InventoryBatch::listBatches` without N+1, plus a light live response for inventory

**Files:** Modify `backend/app/models/QuanTri.php` (`listCustomers`), `backend/app/models/InventoryBatch.php` (`listBatches`, private helper), `backend/app/controllers/AdminInventoryController.php` (`index`), and `frontend/views/admin/inventory_batches.php` only if it needs null-guards.

- [ ] `listCustomers`: replace the per-customer `hoa_don->find` with one query `{ma_kh: {$in: ids}}` (projection `ma_kh, tong_tien`). Compute `tong_don` and `tong_chi_tieu` in PHP with the same `(int)($order['tong_tien'] ?? 0)` cast. Run a snapshot equality check for `('','')`, `('minh','')`, `('', 'VIP')`, `('', 'Thường')`, `('zzz','')`.
- [ ] `listBatches`: collect product ids from the page (up to 500), then one `san_pham` `$in` with both string and int forms, exactly as `findProductName` does. Default stays `'Không tìm thấy sản phẩm'`. Run an equality check over filter combos: `[]`, `['q'=>'zzz']`, `['status'=>'expired']`, `['warehouse_id'=>'KHO-DN-01']`, `['show_expired'=>'1']`.
- [ ] `AdminInventoryController::index`: for live-filter requests, skip `listProductOptions()`, `previewAutoSeed()` and `previewDiverseSeed()` (they scan whole collections). Pass empty/null values the view already tolerates; add view guards if needed. Normal page loads stay unchanged.
- [ ] Measure before/after with curl: `admin_users` and `admin_inventory_batches`, both normal and with header `X-Requested-With: live-filter` and a query.

### Task 4: Wire the help/support routes without overriding working routes

**Files:** Modify `backend/public/index.php` and `frontend/public/index.php` (keep both identical in this part). `backend/app/routes/store_assistance.php` returns true/false.

- [ ] In the router `switch`'s `default:` branch, **before** the 404 handling, add `if (require $backendRoot . '/app/routes/store_assistance.php') { break; }`. Routes with an existing `case` (`ho_tro_khach_hang`, `huong_dan_nhan_otp`, `dieu_kien_giao_dich`, `chinh_sach_bao_mat`, `xuly_du_lieu_ca_nhan`, `bao_hanh`, `home`…) are unaffected by construction.
- [ ] Before the change, save `md5` of the responses for the 6 working policy routes and `home` (guest). After the change they must be identical, ignoring any per-request tokens if present (explain).
- [ ] After the change: `tro_giup`, `faq`, `lien_he`, `khieu_nai`, `quy_che_hoat_dong`, `chinh_sach_cookie`, `chinh_sach_van_chuyen`, `doi_tra_hoan_tien`, `chinh_sach_xu_ly_du_lieu` → 200 with the support page. `tro_giup&q=otp` → `#helpResults` filtered (the live-filter form already exists in `info/support.php`). `chat_purchase_action` via GET → not 500 (report what it does; do not POST purchases).
- [ ] Report that the "home resume after chat checkout" block inside `store_assistance.php` stays inactive, because `home` has its own `case`.

### Task 5: Save search history only when the user commits (Enter / Tìm)

**Files:** Modify `frontend/public/assets/js/live-filter.js` and `backend/app/controllers/SanPhamController.php` (`tatca`, where Sonnet already added the live check).

- [ ] JS: `run({commit})`. The `submit` handler calls `run({commit: true})`, which also runs when the query is unchanged and adds header `X-Live-Commit: 1`. Typing and `change` stay `commit: false`. Update the header comment block.
- [ ] PHP: in `tatca()`, save history when the request is not live **or** has `X-Live-Commit: 1`.
- [ ] Verify with curl as customer `minh.pham105`: live request without commit → no new history entry; with commit → one entry. First read `saveSearchHistory` / `luuLichSuTimKiem` to see where it writes (session and/or `lich_su_tim_kiem`). Delete the test rows you created afterwards and report the exact delete filter.

### Task 6: Release the session lock for read-only live requests

**Files:** Modify `backend/public/index.php` and `frontend/public/index.php`.

- [ ] Right after `session_start()`: if method is GET, `HTTP_X_REQUESTED_WITH === 'live-filter'` and no `HTTP_X_LIVE_COMMIT`, call `session_write_close()`. `$_SESSION` stays readable, so auth still works.
- [ ] Verify: two concurrent live requests with the same cookie jar (`curl … & curl … & wait`) finish in roughly max(t1, t2), not t1+t2. Report both timings. `hoso` live and `admin_orders` live still return correct regions.

### Task 7: More PHP-FPM workers

**Files:** Create `docker/php/zz-skinsyntax-fpm.conf` (or follow the folder convention used by `Dockerfile.php`) and modify `Dockerfile.php` to COPY it into `/usr/local/etc/php-fpm.d/`.

- [ ] Contents: `[www]` with `pm = dynamic`, `pm.max_children = 20`, `pm.start_servers = 4`, `pm.min_spare_servers = 2`, `pm.max_spare_servers = 8`, `pm.max_requests = 500`.
- [ ] Rebuild and recreate only the PHP service (`docker compose build <php service>` then `docker compose up -d --no-deps <php service>`). Verify the effective config (`php-fpm -tt` or grep inside the container) and that the site responds 200. This is the last task, because it restarts PHP.

### Task 8: Final verification and notes

- [ ] Before/after timing table for `admin_orders`, `admin_users`, `admin_inventory_batches`, `hoso` live, `tatca` live, `tro_giup`.
- [ ] Contract test 327/327, `php -l` on every changed PHP file, `node --check`, `git diff --check`.
- [ ] Append section **34** to `update.md` (Vietnamese, same style as section 33): what changed, the equality-check results, timings, the new routes, history-on-Enter behaviour, session lock release, the FPM change, and remaining limits.
