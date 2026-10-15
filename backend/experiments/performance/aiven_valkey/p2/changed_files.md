# SkinSyntaxVN — Changed Files Log (Phase P2)

## 1. Modified Existing Files

1. **`backend/public/index.php`**
   - Added conditional require for `CacheService.php` to initialize caching service in the primary request entrypoint.

2. **`frontend/public/index.php`**
   - Added conditional require for `CacheService.php` for consistency.

3. **`backend/app/services/CacheService.php`**
   - Upgraded with **L1 Request-Scoped Memory Cache** to eliminate repeated network calls within a single PHP request.
   - Enabled persistent TLS stream connection pooling (`'persistent' => true`).
   - Fixed regex pattern replacement for L1 cache eviction in `deleteByPattern()`.

4. **`backend/app/models/SanPham.php`**
   - Added `invalidateProductCache($id)` static method.
   - Wrapped `menuTree()` with Valkey cache (`menu:tree:{limit}`, TTL 3600s).
   - Wrapped `latest()` with Valkey cache (`home:latest:...`, TTL 600s).
   - Wrapped `getFlashSaleProducts()` with Valkey cache (`home:flash_deals:...`, TTL 180s).
   - Added invalidation calls in: `adminInsert()`, `adminUpdate()`, `updateProductVisibility()`, `updateInventory()`.

5. **`backend/app/controllers/SanPhamController.php`**
   - Applied cache-aside in `tatca()` (`catalog:tatca:{hash}`, TTL 300s).
   - Applied cache-aside in `chitiet()` for:
     - Core product specifications (`product:detail:{id}`, TTL 1800s).
     - Frequently bought together items (`product:freq_bought:{id}`, TTL 3600s).
     - Product video metadata (`product:videos:{id}`, TTL 1800s).

6. **`backend/app/controllers/HomeController.php`**
   - Added Valkey caching for `getHighlightedCategories()` (`home:highlighted_categories`, TTL 3600s).

7. **`backend/app/controllers/QuanTriController.php`**
   - Added cache invalidation calls in `adminCategorySave()` and `adminCategoryDelete()`.

8. **`backend/app/models/ProductVideo.php`**
   - Added video cache invalidation calls in `publishVideo()` and `setPublishedStatus()`.

9. **`backend/experiments/performance/aiven_valkey/connection_setup.md` & `changed_files.md`**
   - Sanitized and redacted temporary raw credential strings.

---

## 2. Newly Created Test & Analysis Files

1. **`backend/tests/benchmark_valkey_latency.php`**
   - Micro-benchmark measuring cold TLS handshake, warm command latency, serialization CPU overhead, and fallback detection.
2. **`backend/tests/benchmark_p2_comparison.php`**
   - End-to-end comparison benchmark measuring Cache OFF vs Cache MISS vs Cache HIT vs Valkey Down across routes.
3. **`backend/tests/test_invalidation_p2.php`**
   - Automated assertion test verifying cache eviction upon admin write operations.
4. **`backend/tests/output_valkey_latency.json`** & **`output_p2_comparison.json`**
   - Raw JSON benchmark metric outputs.

---

## 3. Documentation Files (`backend/experiments/performance/aiven_valkey/p2/`)

- `security_audit.md`
- `latency_analysis.md`
- `cache_implementation.md`
- `invalidation.md`
- `before_after_benchmark.md`
- `regression_tests.md`
- `changed_files.md`
- `final_report.md`
