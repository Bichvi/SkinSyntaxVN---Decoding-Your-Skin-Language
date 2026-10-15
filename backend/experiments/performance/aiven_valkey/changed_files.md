# SkinSyntaxVN — Changed Files Log (Phase P0 & P1)

## 1. Modified Existing Files

1. **`backend/composer.json` & `backend/composer.lock`**
   - Added dependency: `"predis/predis": "^3.7"`.
   - Purpose: Provides pure-PHP Valkey 9.1.2 and Redis client with native TLS support without requiring image recompilation.

2. **`.env`**
   - Added environment variables:
     - `CACHE_DRIVER=valkey`
     - `VALKEY_URL=rediss://default:<REDACTED_PASSWORD>@skinsyntax-lamngoc562004-3561.j.aivencloud.com:26436`
     - `CACHE_PREFIX=skinsyntax:`
     - `CACHE_DEFAULT_TTL=300`

3. **`.env.example`**
   - Added safe configuration template with redacted credentials:
     - `CACHE_DRIVER=valkey`
     - `VALKEY_URL=rediss://default:YOUR_PASSWORD@your-valkey-host.aivencloud.com:PORT`
     - `CACHE_PREFIX=skinsyntax:`
     - `CACHE_DEFAULT_TTL=300`

4. **`docker-compose.yml`**
   - Added environment variable forwarding for `CACHE_DRIVER`, `VALKEY_URL`, `CACHE_PREFIX`, `CACHE_DEFAULT_TTL` to the `php-backend` container service.

5. **`backend/app/config/config.php`**
   - Defined default constants:
     - `CACHE_DRIVER`
     - `VALKEY_URL`
     - `CACHE_PREFIX`
     - `CACHE_DEFAULT_TTL`

---

## 2. Newly Created Implementation Files

1. **`backend/app/services/CacheService.php`**
   - Core cache-aside service with singleton access (`CacheService::getInstance()`).
   - Supports: `get()`, `set()`, `delete()`, `has()`, `remember()`, `deleteByPattern()`, `ping()`, `isAvailable()`, `getSafeStatus()`.
   - Built-in circuit-breaker: silent fallback to callback / authoritative MongoDB on Valkey failure.
   - Non-blocking SCAN-based pattern eviction.

---

## 3. Newly Created Test & Benchmark Files

1. **`backend/tests/test_aiven_valkey_p1.php`**
   - Smoke test for Aiven Valkey connection over TLS (`rediss://`), PING, SET, GET, TTL, and DELETE.
2. **`backend/tests/test_cache_service_p1.php`**
   - 21-point assertion suite validating CacheService singleton, serialization, `remember()` cache-aside flow, batch pattern SCAN deletion, and degraded fallback resilience.
3. **`backend/tests/benchmark_baseline_p0.php`**
   - Benchmark script measuring HTTP p50/p95 response times, MongoDB Atlas query counts, collection breakdown, and memory across 5 target routes.
4. **`backend/tests/output_baseline_p0.json`**
   - Raw JSON baseline benchmark results.

---

## 4. Documentation Files (`backend/experiments/performance/aiven_valkey/`)

1. `architecture_audit.md`
2. `connection_setup.md`
3. `cache_design.md`
4. `cache_invalidation.md`
5. `performance_baseline.md`
6. `performance_after.md` (P0/P1 baseline comparison & P2 plan)
7. `security_tests.md`
8. `regression_tests.md`
9. `changed_files.md`
10. `final_report.md`
