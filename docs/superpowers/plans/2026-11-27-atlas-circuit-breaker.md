# Atlas Circuit Breaker (fail fast when MongoDB Atlas is unreachable) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** When Atlas is unreachable, a page answers in ~3 s with the existing "Dữ liệu đang tạm thời chưa kết nối được" 503 page instead of hanging 17–37 s (and 504 via session-lock queueing).

**Architecture:** Every collection obtained through `MongoDatabaseCompat` is wrapped in a thin guard. The first server-selection failure (`MongoDB\Driver\Exception\ConnectionTimeoutException`) trips a per-request flag and a Redis key (TTL 15 s); while tripped/open, guarded calls throw instantly. Front controller: if the breaker is open at start (or trips during) an HTML navigation, it outputs the 503 page with `X-SS-No-Store-Page: 1`.

**Measured baseline (CLI, MONGO_URI=mongodb://10.255.255.1:27017/?directConnection=true, cache=off):** home 37 s, chitiet 37 s, goiy 26 s, giohang 17 s; `serverSelectionTimeoutMS` = 8000 in `backend/app/config/db.php`.

## Global Constraints
- Only server-selection/connection failures trip the breaker (`ConnectionTimeoutException`, and `ConnectionException` whose message contains `No suitable servers`). Query errors, write errors, auth errors never trip it.
- Redis key: `atlas:breaker:open`, TTL 15 s, via `CacheService` (graceful if Redis unavailable → per-request only). Read it at most once per request.
- `serverSelectionTimeoutMS` 8000 → 3000; `connectTimeoutMS` 5000 → 3000. Keep `serverSelectionTryOnce=false`.
- Non-navigation requests (AJAX/fetch/JSON/beacons/API) keep their existing response shapes — they just fail fast via the controllers' existing catch blocks.
- Navigation detection: `Sec-Fetch-Dest: document` or (no Sec-Fetch headers and `Accept` contains `text/html` and no `X-Requested-With`); GET only.
- Both entry points: `index.php` and `backend/public/index.php` (Docker serves the latter). Keep `pwa_sw` / `pwa_manifest` working while the breaker is open (they need no DB).
- No commits. Never run `backend/tests` scripts in bulk. Use Edit tool. Smallest change.

## Review Focus
- Breaker never trips on normal traffic (slow query, duplicate key, validation error).
- After TTL expiry, the next request probes Atlas again and the site recovers without manual action.
- SW page cache never stores the 503 page; revalidation getting 503 leaves the cached page untouched (already true for non-200 — confirm).
- `$pdo->selectCollection(...)` and `$pdo->{$name}` both return guarded collections; `$pdo->raw()` unchanged.
- Iterating a cursor / chained calls (`->find()->toArray()`, `foreach`) still work through the guard (guard returns the real result objects, it only wraps the collection).

### Task 1: Breaker + guard + timeouts
**Files:** Create `backend/app/services/AtlasBreaker.php` (`final class AtlasBreaker { public static function isOpen(): bool; public static function trip(Throwable $e): void; public static function trippedThisRequest(): bool; public static function isSelectionFailure(Throwable $e): bool; }`). Modify `backend/app/config/db.php`: timeouts; `MongoCollectionGuard` class (`__call` forward with check/trip/rethrow, `__get` forward); `MongoDatabaseCompat::__get` and `__call('selectCollection')` return guards.
- [ ] GitNexus `impact` for `MongoDatabaseCompat` (known: UNKNOWN; every controller uses `$pdo`).
- [ ] Implement. `isOpen()` throws nothing.
- [ ] Verify with the CLI simulation command below that each page now finishes in ≤ ~4 s, and with the Redis key present in ≤ 1 s. Then `redis-cli DEL` the key (it is the live site's key!) and confirm normal curl pages return 200 at their usual sizes.

### Task 2: 503 for navigations
**Files:** Modify both `index.php` files: extract the existing 503 HTML (from the `set_exception_handler`) into a function `ss_render_db_unavailable(): void` (sets 503, `Retry-After: 5`, `Cache-Control: no-store`, `X-SS-No-Store-Page: 1`, clears buffers, echoes the page) reused by the handler; after `db.php` + routing of pwa routes, if navigation && `AtlasBreaker::isOpen()` → render + exit; register a shutdown function: if navigation && `AtlasBreaker::trippedThisRequest()` && headers not sent → render (replacing partial output).
- [ ] Implement; `php -l` both files (root one: copy into container /tmp and lint).
- [ ] Verify via CLI simulation (set `$_SERVER['HTTP_SEC_FETCH_DEST']='document'`): output is the 503 page; without that header (AJAX-like) output is the controller's normal degraded/JSON response.

CLI simulation (Git Bash, `export MSYS_NO_PATHCONV=1`):
```
docker exec -u www-data -w /var/www/html/public -e MONGO_URI="mongodb://10.255.255.1:27017/?directConnection=true" skinsyntax-php sh -c "time php -r 'parse_str(\"r=home&cache=off\", \$_GET); \$_SERVER[\"SCRIPT_NAME\"]=\"/index.php\"; \$_SERVER[\"REQUEST_METHOD\"]=\"GET\"; \$_SERVER[\"HTTP_SEC_FETCH_DEST\"]=\"document\"; include \"index.php\";' > /tmp/out.html; head -c 300 /tmp/out.html"
```
