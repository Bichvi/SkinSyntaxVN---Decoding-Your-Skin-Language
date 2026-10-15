# SkinSyntaxVN — Cache Architecture & Cache-Aside Design (Phase P1)

## 1. Architectural Strategy: Cache-Aside Pattern

SkinSyntaxVN adheres strictly to the **Cache-Aside Pattern** with MongoDB Atlas remaining the authoritative Single Source of Truth (SSOT).

```
                      [ Web Client / Browser ]
                                 │
                                 ▼
                     [ Nginx Reverse Proxy ]
                                 │
                                 ▼
                    [ PHP Application Backend ]
                                 │
                ┌────────────────┴────────────────┐
                │                                 │
         1. Check Valkey                   3. Cache MISS
        (rediss:// TLS)                    Fetch Authoritative
                │                                 │
                ▼                                 ▼
      ┌──────────────────┐               ┌──────────────────┐
      │   Aiven Valkey   │ ◄──────────── │  MongoDB Atlas   │
      │   Cache Cluster  │   4. Store    │  (Authoritative) │
      └──────────────────┘     with TTL  └──────────────────┘
                │
         2. Cache HIT
         Return instantly
```

---

## 2. Core Operational Workflow

1. **Request Intake:** A controller or model method receives a request for public catalog or metadata.
2. **Cache Lookup (`CacheService::get($key)` or `remember($key, $ttl, $fn)`):**
   - Cache key is namespaced using `CACHE_PREFIX` (`skinsyntax:`).
   - If key exists in Valkey: return cached payload directly (Cache HIT).
3. **Database Query (Cache MISS):**
   - If key is not in Valkey or Valkey is temporarily unreachable:
   - Execute query against MongoDB Atlas.
4. **Cache Store:**
   - On successful query retrieval, write serialized payload to Valkey with specific TTL (`CacheService::set($key, $data, $ttl)`).
5. **Response Delivery:** Return data to the view/caller.

---

## 3. Graceful Fallback & Circuit Breaking

A distributed cache must never become a Single Point of Failure (SPOF) for an e-commerce platform.

### Fallback Rules:
- **Connection Failure:** If Valkey TLS handshake fails, port is blocked, or network times out (timeout configured at 2.0s):
  - `CacheService` catches `Throwable`, logs a warning to `error_log`, and sets `$this->isAvailable = false`.
  - In `get()`: Returns `$default` (null), effectively triggering a normal Cache MISS.
  - In `remember()`: Immediately executes the provided callback against MongoDB Atlas and returns the database record.
  - In `set()` / `delete()`: Returns `false` without halting execution.
- **No Local Mongo Fallback:** The fallback is strictly to **MongoDB Atlas** (the active authoritative DB). It will never fall back to stale or unseeded local legacy MongoDB instances.
- **Zero Impact on Checkout/Vouchers:**
  - Neither Cart nor Checkout touches Valkey for authoritative calculations.
  - Even if Valkey goes offline during a user browsing session, page rendering continues transparently.

---

## 4. Key Namespacing & TTL Policy Matrix (For P2 & Beyond)

| Domain | Key Pattern | TTL | Strategy | Rationale |
| :--- | :--- | :--- | :--- | :--- |
| **Brands** | `skinsyntax:brands:all` | 3600s (1 hr) | Read-heavy, static | Rarely changes; invalidated on Admin Brand update |
| **Categories** | `skinsyntax:categories:all` | 3600s (1 hr) | Read-heavy, static | Seldom updated; invalidated on Category update |
| **Home Featured** | `skinsyntax:home:featured_products` | 600s (10 min) | Cache-aside | Refreshed every 10m or on product edit |
| **Home Flash Sale** | `skinsyntax:home:flash_sale_active` | 180s (3 min) | Cache-aside | Short TTL; authoritative price always recalculated at cart |
| **Product Detail** | `skinsyntax:product:{id}` | 1800s (30 min) | Cache-aside | Invalidated on product edit/delete |
| **Top Searches** | `skinsyntax:search:{hash}` | 300s (5 min) | Cache-aside | Prevents repeat Atlas aggregate runs on frequent queries |
| **Guest Recommendations** | `skinsyntax:rec:guest:{algo}` | 900s (15 min) | Cache-aside | Static baseline recommendations |
| **User Recommendations** | `skinsyntax:rec:u:{user_id}:{algo}` | 300s (5 min) | Strict isolation | Dedicated user namespace to prevent cross-account leakage |

---

## 5. Security & Isolation Boundaries

- **Forbidden from Cache:**
  - Customer passwords, hashes, reset tokens.
  - Active session payloads (`$_SESSION['user_id']`).
  - Shopping cart items and totals.
  - Customer voucher balances and claimed tokens (`customer_vouchers`).
  - Stock reservations during checkout.
- **Serialization:** Standard UTF-8 JSON encoding with metadata wrapper (`__ss_cached_data`, `__cached_at`) prevents PHP object injection vulnerabilities.
