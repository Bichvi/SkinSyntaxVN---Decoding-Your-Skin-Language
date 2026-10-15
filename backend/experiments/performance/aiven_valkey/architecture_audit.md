# SkinSyntaxVN — Architecture & Performance Audit (Phase P0)

**Project:** SkinSyntaxVN — E-commerce & Skincare Consultation Platform  
**Target:** Aiven Valkey Cache Integration & Performance Optimization  
**Date:** October 2026  
**Auditor:** Senior PHP/MongoDB Performance & Cache Engineer  

---

## 1. System Environment & Runtime Stack

| Component | Specification / Version | Verification Method | Status |
| :--- | :--- | :--- | :--- |
| **PHP Runtime** | PHP 8.2.33 (CLI / FPM) | `php -v` inside `skinsyntax-php` | Verified |
| **OpenSSL / TLS** | OpenSSL 3.0.15, supports TLS 1.2, 1.3 (`rediss://`) | `stream_get_transports()` | Verified |
| **MongoDB Extension** | `ext-mongodb` 2.3.3 (PHP driver) | `extension_loaded('mongodb')` | Verified |
| **Redis PHP Driver** | `predis/predis: ^3.7.0` (Pure PHP Valkey & Redis client) | `composer.json` / `composer.lock` | Installed & Verified |
| **Primary Database** | MongoDB Atlas (`mongodb+srv://...`) | Authoritative Single Source of Truth | Operational |
| **Cache Engine** | Aiven Valkey 9.1.2 (Node 1, TLS on port 26436) | Aiven Console / Predis PING | Verified |
| **Web Server** | Nginx (`skinsyntax-nginx` reverse proxying to PHP-FPM) | `docker-compose.yml` | Verified |
| **AI Subsystems** | Flask / Celery / ChromaDB (`skinsyntax-ai`, `skinsyntax-ai-idol`) | Local internal docker network | Isolated |

---

## 2. Configuration & Secrets Management

- **Config Loader:** `backend/app/config/config.php` utilizes `ss_load_env()` to parse root `.env` into `putenv()`, `$_ENV`, and `$_SERVER`.
- **Environment Variables Added:**
  - `CACHE_DRIVER=valkey`
  - `VALKEY_URL=rediss://default:<PASSWORD>@skinsyntax-lamngoc562004-3561.j.aivencloud.com:26436`
  - `CACHE_PREFIX=skinsyntax:`
  - `CACHE_DEFAULT_TTL=300`
- **Docker Compose:** Added `CACHE_DRIVER`, `VALKEY_URL`, `CACHE_PREFIX`, `CACHE_DEFAULT_TTL` to `php-backend` service.
- **Safety Guarantee:** `.env.example` has been updated with safe placeholder values (`YOUR_PASSWORD`, `your-valkey-host.aivencloud.com`). Secrets are never committed or exposed in logs.
- **Aiven API Key Assessment:** Aiven REST API key is **NOT** needed for cache operations. The application communicates purely via the Valkey RESP protocol over TLS (`rediss://`).

---

## 3. Data Flows & Query Architecture Audit

### 3.1 Authoritative DB: MongoDB Atlas
- All entities (`san_pham`, `danh_muc`, `thuong_hieu`, `xuat_xu`, `danh_gia`, `hoa_don`, `voucher`, `khach_hang`, `customer_vouchers`) reside authoritatively in MongoDB Atlas.
- Latency to MongoDB Atlas from local development averages **150ms – 450ms per round-trip**, meaning any page executing multiple unbatched queries suffers cumulative network latency (e.g., 10-15 queries = 2.5s – 5.0s wait time).

### 3.2 Key Public Read Paths (Candidates for P2 Caching)
1. **Homepage (`?r=home`):**
   - Loads flash sales, featured products, promotional products, brand lists, and categories.
   - Executes multiple aggregate and find queries against `san_pham`, `thuong_hieu`, `danh_muc`.
   - Highest traffic, high read-to-write ratio, prime candidate for cache-aside.
2. **Category / Product Listing (`?r=tatca`, `?r=danhmuc`):**
   - Filtered/paginated catalog queries with sorting and brand aggregations.
   - Read-heavy, updates only when catalog changes.
3. **Product Detail (`?r=chitiet&id=...`):**
   - Loads core product specs, related products, brand details, review summaries, and video metadata.
   - Ideal candidate for object caching with invalidation on product update.
4. **Search (`?r=timkiem`):**
   - Keyword text search across product catalog.
   - Top queries can be cached with short TTL (e.g., 120s – 300s).
5. **Recommendation Engine (`?r=goiy`):**
   - Rule-based, content-based, and collaborative filtering.
   - Safe to cache anonymous/guest baseline recommendations, but user-personalized recommendations must carry user-specific cache keys (`skinsyntax:rec:u:{user_id}`) to avoid data leakage between users.

### 3.3 Critical Non-Cache Paths (Must NEVER be cached in Valkey)
- User authentication & passwords (`NguoiDung`, `TaiKhoan`, session tokens).
- Shopping cart contents (`$_SESSION['gio_hang']`).
- Voucher entitlement and redemption status (`CustomerVoucher`, `voucher`).
- Checkout totals & order placement (`hoa_don`, `chi_tiet_hoa_don`).
- Flash Sale stock quota authoritative deduction (must revalidate against database upon order placement).

---

## 4. Identified Bottlenecks & Optimization Roadmap

1. **Repetitive Lookup Queries:** Lookups for brands (`thuong_hieu`), categories (`danh_muc`), and origins (`xuat_xu`) execute repeatedly across requests.
2. **Atlas Roundtrip Latency:** Each roundtrip to cloud Atlas adds 200ms+; caching query results locally/in Valkey will yield sub-millisecond retrieval on cache hits.
3. **Graceful Fallback Requirement:** Because Valkey is hosted in cloud Aiven (`j.aivencloud.com`), the application must implement a strict circuit breaker: if Valkey TLS or network fails, the system must immediately and transparently fallback to MongoDB Atlas without breaking user requests or crashing checkout.
