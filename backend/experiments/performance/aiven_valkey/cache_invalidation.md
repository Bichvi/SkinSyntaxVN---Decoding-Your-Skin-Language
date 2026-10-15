# SkinSyntaxVN — Cache Invalidation Strategy (Phase P1 Design for P2 Implementation)

## 1. Principles of Cache Invalidation

Cache invalidation must maintain absolute consistency between the authoritative database (MongoDB Atlas) and cached read models. When data changes in MongoDB, stale cached entries must be evicted immediately.

---

## 2. Invalidation Events & Target Keys

| Triggering Event | Responsible Controller/Model | Target Keys to Invalidate | Method |
| :--- | :--- | :--- | :--- |
| **Product Created / Updated** | `QuanTriController::suaSanPham()`, `themSanPham()` | `skinsyntax:product:{id}`<br>`skinsyntax:home:featured_products`<br>`skinsyntax:search:*` | `CacheService::delete()` and SCAN-based `deleteByPattern()` |
| **Product Deleted / Deactivated** | `QuanTriController::xoaSanPham()` | `skinsyntax:product:{id}`<br>`skinsyntax:home:*`<br>`skinsyntax:tatca:*` | Immediate key deletion |
| **Category Updated / Created** | `QuanTriController::suaDanhMuc()` | `skinsyntax:categories:all`<br>`skinsyntax:tatca:*` | Delete specific key + category pattern |
| **Brand Updated / Created** | `QuanTriController::suaThuongHieu()` | `skinsyntax:brands:all`<br>`skinsyntax:home:brands` | Delete specific keys |
| **Flash Sale Campaign State Change** | `FlashSaleCampaign::update()` | `skinsyntax:home:flash_sale_active`<br>`skinsyntax:product:{id}` | Delete campaign and affected product keys |
| **Video Publication / Status Change** | `ProductVideo::updatePublication()` | `skinsyntax:product:{id}:video` | Delete PDP video metadata key |

---

## 3. High-Risk Edge Case Prevention

### 3.1 Flash Sale & Pricing Invalidation Safety
- **Rule:** A cached product price must NEVER be accepted as authoritative during cart operations or order placement.
- **Enforcement:** In `HomeController::xulydathang()`, prices, flash sale eligibility, and customer voucher discounts are always re-evaluated directly from MongoDB Atlas records.
- Even if a stale price exists in Valkey due to delayed invalidation, the checkout transaction executes with authoritative MongoDB Atlas pricing.

### 3.2 Personalized Recommendation Invalidation Safety
- Recommendation caches are isolated by customer ID (`skinsyntax:rec:u:{user_id}`).
- When a customer places an order or updates skin profile, their personal recommendation key is evicted or allowed to expire via short TTL (300s).
- Recommendation keys are NEVER shared or accessible across different accounts.

### 3.3 Safe Pattern Eviction using Non-Blocking SCAN
- Redis/Valkey `KEYS *` command blocks the single-threaded event loop and is strictly banned.
- `CacheService::deleteByPattern()` uses Redis cursor-based `SCAN` with batching (`COUNT 50`) to safely iterate and delete matching keys without causing latency spikes on the Valkey node.
