# SkinSyntaxVN — Public Page Cache Implementation (Phase P2)

## 1. Implementation Overview

In Phase P2, Cache-Aside caching was implemented across public storefront read paths without modifying any transactional business rules (Cart, Checkout, Vouchers, Flash Sale authoritative pricing).

---

## 2. Cached Domains & Key Schema

| Route / Component | Cache Key Pattern | TTL | Source Method | Cache Boundary |
| :--- | :--- | :---: | :--- | :--- |
| **Mega Menu Tree** | `skinsyntax:menu:tree:{cap2Limit}` | 3600s (1 hr) | `SanPham::menuTree()` | Universal layout header menu |
| **Home Latest Products** | `skinsyntax:home:latest:{limit}:{vis}:{beauty}` | 600s (10 min) | `SanPham::latest()` | Homepage newest product cards |
| **Home Categories** | `skinsyntax:home:highlighted_categories` | 3600s (1 hr) | `HomeController::getHighlightedCategories()` | Homepage category carousel |
| **Home Flash Deals** | `skinsyntax:home:flash_deals:{limit}` | 180s (3 min) | `SanPham::getFlashSaleProducts()` | Public flash deal showcase items |
| **Home Top-Rated Pool** | `skinsyntax:home:simple_recommender:pool` | 600s (10 min) | `SanPham::getSimpleRecommenderProducts()` | IMDb weighted rating top-rated pool |
| **Category Listing (TatCa)** | `skinsyntax:catalog:tatca:{filterHash}` | 300s (5 min) | `SanPhamController::tatca()` | Filter, search, and page-specific results |
| **Product Detail Core** | `skinsyntax:product:detail:{id}` | 1800s (30 min) | `SanPhamController::chitiet()` | Public product specifications & images |
| **PDP Frequently Bought** | `skinsyntax:product:freq_bought:{id}` | 3600s (1 hr) | `AssociationRuleRecommender::getFrequentlyBoughtTogether()` | Cross-selling recommendation items |
| **PDP Product Videos** | `skinsyntax:product:videos:{id}` | 1800s (30 min) | `ProductVideo::getPublishedVideosByProductId()` | Syna AI video commerce metadata |

---

## 3. Key Hash Formulation for Listing & Category Filters

To prevent cache key collisions across different search keywords, category tiers, and pagination offsets, the catalog key is formulated as:

```php
$filterHash = md5(json_encode([
    'p' => $page,
    'per' => $perPage,
    'q' => $q,
    'c1' => $cap1,
    'c2' => $cap2,
    'sort' => $sort,
    'v' => 1
]));
$filterKey = 'catalog:tatca:' . $filterHash;
```

**Guarantees:**
- Page 1 and Page 2 have strictly distinct keys.
- Changing sorting from `default` to `price_asc` generates a different key.
- Search queries (`?q=serum`) are segregated by term.
- Version flag (`v: 1`) allows instant global cache invalidation on catalog schema changes.

---

## 4. Protected Non-Cached Domains

The following dynamic and sensitive data components are **strictly excluded** from Valkey caching:
1. **User Auth & Sessions:** `$_SESSION['user']`, CSRF tokens, OTPs.
2. **Review Permissions:** `DanhGia::canUserReviewProduct()` depends on user login and order history; always evaluated live.
3. **Cart & Checkout:** Cart items (`$_SESSION['gio_hang']`), live inventory deductions, voucher validation.
4. **Adaptive Recommendation Personalization:** Kept live on the database to prevent cross-account signal leakage.
