# Public Flash Sale Experience Audit (Phase 2C)

## 1. Executive Summary
Before Phase 2C, SkinSyntaxVN's public Flash Sale section was a presentation-only demonstration module that inferred "deals" by falling back to products with `phan_tram_giam > 0` or simply slicing `$latest`. It had no connection to any campaign data model, used an artificial countdown timer that reset at midnight (`23:59:59`), and directed users to the full catalog route (`index.php?r=tatca`) via the "Xem tất cả deal" button.

Phase 2C completely replaced this fake presentation layer with a **real, campaign-driven public UI** directly anchored to the canonical MongoDB collection `flash_sale_campaigns`. All public pricing, countdown timestamps, visibility rules, and deal rankings are now server-derived truths.

---

## 2. Legacy Behavior vs. Real Phase 2C Architecture

| Dimension | Legacy Public Implementation (Pre-2C) | Phase 2C Real Implementation |
| :--- | :--- | :--- |
| **Data Source** | `SanPham::getFlashSaleProducts()` or fallback to `$latest[0..3]` | `FlashSaleCampaign::getPrimaryActiveCampaign()` |
| **Campaign Model** | None (inferred from `phan_tram_giam > 0` or synthetic) | Canonical MongoDB collection `flash_sale_campaigns` |
| **Section Visibility** | Always rendered, even when 0 active campaigns existed | Strictly hidden if no ACTIVE campaign exists (no fake deals, no padding) |
| **Countdown Target** | Hard-coded client-side midnight loop (`today 23:59:59`) | Server-derived ISO timestamp `end_at_ts` from active campaign |
| **Countdown Reset** | Automatically reset to next midnight upon hitting zero | Stops at zero, displays "Đã kết thúc", reloads once safely |
| **"Xem tất cả deal" Route** | `index.php?r=tatca` (dumped entire catalog of 2,473 products) | Dedicated route `index.php?r=flash_sale` showing only campaign items |
| **Pricing Source** | Trusted `san_pham.gia_ban` and hard-coded discount badges | Centralized Effective Pricing Engine (`resolveEffectivePrice()`) |
| **Price Consistency** | Mismatches between Home, Detail, Cart, and Checkout | Guaranteed invariant across Homepage, Detail, Cart, and Checkout |
| **Scarcity / Progress UI** | Decorative/fake stock meters and sales claims | Eliminated false sales scarcity; no `so_luong_da_ban` claims |
| **Sorting on All Deals** | N/A (did not have dedicated page) | 5 baseline deterministic sorts without personalization |

---

## 3. Legacy Code Artifacts & Audit Findings

### 3.1 `SanPham::getFlashSaleProducts()`
- **Audit Findings:** Previously attempted to query MongoDB for products where discount criteria were met or fell back to random latest products.
- **Action Taken:** Preserved in `SanPham.php` for backward compatibility with any external script or test harness, but **completely decoupled** from the public homepage rendering pipeline.

### 3.2 Legacy Countdown JavaScript in `home.php`
- **Audit Findings:** Used client-side `new Date()` computation targeting `23:59:59` of the current day. When reaching 0, it reset to the next day's midnight, creating an endless, dishonest urgency loop.
- **Action Taken:** Replaced with an immutable target countdown reader:
  - Reads `data-end-timestamp` (UNIX epoch in seconds) directly rendered by the server.
  - Decrements locally every second.
  - When `remaining <= 0`, terminates the timer, displays the Vietnamese badge "Đã kết thúc", and triggers a single controlled reload to fetch the latest server lifecycle state without looping (`sessionStorage` guarded).

### 3.3 "Xem tất cả deal" Link
- **Audit Findings:** The call-to-action button anchored to `index.php?r=tatca`, which confused shoppers by presenting non-discounted products under a flash sale pretense.
- **Action Taken:** Anchored to the new dedicated public route `index.php?r=flash_sale`.

---

## 4. Exact Files Modified in Phase 2C

1. `backend/app/models/FlashSaleCampaign.php`:
   - Added `getPrimaryActiveCampaign(?int $now = null)`
   - Added `getUpcomingCampaign(?int $now = null)`
   - Added `hydrateCampaignItems(array $items, ?int $now = null)` with deduplicated `$in` MongoDB queries
   - Added `sortBaselineRanking(array &$items)`
   - Added `sortCampaignDeals(array &$items, string $sort)`

2. `backend/app/controllers/HomeController.php`:
   - Updated `index()` to retrieve primary active campaign and pass `$primaryFlashCampaign` to `home.php`.
   - Added `flashSale()` to handle `index.php?r=flash_sale`, validate sort parameters, and render `flash_sale.php`.

3. `index.php`, `backend/public/index.php`, `frontend/public/index.php`:
   - Registered `case 'flash_sale': (new HomeController($pdo))->flashSale(); break;` across all web entry points.

4. `frontend/views/home.php`:
   - Wrapped Flash Sale section in strict condition: `if (!empty($flashSaleCampaign) && !empty($flashSaleProducts))`.
   - Replaced static headings with real campaign title `<?= h($flashSaleCampaign['ten_campaign']) ?>` and description.
   - Integrated server-driven countdown script and card renderer.

5. `frontend/views/chitiet.php`:
   - Integrated `get_effective_product_price()` for display.
   - Renders red flash price, struck regular price, and campaign badge `⚡ FLASH SALE: <Campaign Name>` when active.

6. `frontend/views/flash_sale.php`:
   - Created dedicated All Deals template with hero banner, countdown, deal count, 5 sort controls, responsive card grid, and honest empty state.

7. `frontend/views/helpers.php`:
   - Updated `get_effective_product_price()` with resilient cross-environment path resolution supporting both host and Docker container filesystems.
