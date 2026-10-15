# SkinSyntaxVN — Cache Invalidation Implementation & Event Matrix (Phase P2)

## 1. Architectural Invalidation Strategy

SkinSyntaxVN employs an **Event-Driven Write-Through Invalidation** pattern combined with TTL safety rails:
1. When data is modified in the authoritative store (MongoDB Atlas), the corresponding cache keys in Valkey are actively deleted.
2. TTLs act as a secondary safety net to prevent orphaned entries in case of unhandled server interruptions.
3. Batch pattern evictions use non-blocking Redis `SCAN` commands via `CacheService::deleteByPattern()`.

---

## 2. Invalidation Event & Hook Matrix

| Event / Admin Action | Source Hook Location | Invalidation Logic | Evicted Valkey Keys |
| :--- | :--- | :--- | :--- |
| **Product Created** | `SanPham::adminInsert()` | `SanPham::invalidateProductCache()` | `skinsyntax:home:*`<br>`skinsyntax:catalog:*`<br>`skinsyntax:menu:*` |
| **Product Updated (Price/Info)** | `SanPham::adminUpdate()` | `SanPham::invalidateProductCache($id)` | `skinsyntax:product:detail:{$id}`<br>`skinsyntax:home:*`<br>`skinsyntax:catalog:*`<br>`skinsyntax:menu:*` |
| **Product Visibility Toggled** | `SanPham::updateProductVisibility()` | `SanPham::invalidateProductCache($id)` | `skinsyntax:product:detail:{$id}`<br>`skinsyntax:home:*`<br>`skinsyntax:catalog:*` |
| **Product Stock Updated** | `SanPham::updateInventory()` | `SanPham::invalidateProductCache($id)` | `skinsyntax:product:detail:{$id}`<br>`skinsyntax:home:*`<br>`skinsyntax:catalog:*` |
| **Category Saved / Updated** | `QuanTriController::adminCategorySave()` | Active eviction hook | `skinsyntax:menu:*`<br>`skinsyntax:home:*`<br>`skinsyntax:catalog:*` |
| **Category Deleted** | `QuanTriController::adminCategoryDelete()` | Active eviction hook | `skinsyntax:menu:*`<br>`skinsyntax:home:*`<br>`skinsyntax:catalog:*` |
| **Product Video Published / Drafted** | `ProductVideo::publishVideo()` & `setPublishedStatus()` | Active eviction hook | `skinsyntax:product:videos:*` |

---

## 3. Test Verification Results (`test_invalidation_p2.php`)

Executed against live Valkey:
```
Step 1: Keys populated in Valkey:
  - product:detail:111 -> EXISTS
  - home:latest:12:1:1 -> EXISTS
  - catalog:tatca:test_hash_123 -> EXISTS
  - menu:tree:14 -> EXISTS

Step 2: Triggering SanPham::invalidateProductCache('111')...
Step 3: Verification post-invalidation:
  - product:detail:111 evicted: [PASS]
  - home:* keys evicted: [PASS]
  - catalog:* keys evicted: [PASS]
  - menu:* keys evicted: [PASS]

========================================================================
RESULT: ALL CACHE INVALIDATION ASSERTIONS PASSED
========================================================================
```

---

## 4. E-Commerce Safety Guarantee

- **Authoritative Price Integrity:** `HomeController::xulydathang()` calculates order line totals and checks stock levels directly from MongoDB Atlas. Even if a stale price temporarily exists in cache, orders can NEVER be created with stale or invalid prices.
- **Quota & Voucher Integrity:** Vouchers are claimed, checked, and redeemed using atomic operations on MongoDB collections (`voucher`, `customer_vouchers`), completely bypassing Valkey.
