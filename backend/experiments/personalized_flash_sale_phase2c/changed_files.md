# Changed & Created Files Inventory (Phase 2C)

## 1. Domain Models & Backend Architecture

### `backend/app/models/FlashSaleCampaign.php`
- **Changes:**
  - Added `getPrimaryActiveCampaign(?int $now = null)`: Deterministically queries the active campaign sorted by `end_at ASC, ma_campaign ASC`, batch-hydrates items, and sorts baseline ranking.
  - Added `getUpcomingCampaign(?int $now = null)`: Returns the earliest upcoming campaign for honest notices in empty states.
  - Added `hydrateCampaignItems(array $items, ?int $now = null)`: Performs batch `$in` queries against `san_pham`, skips missing/inactive products safely, and computes discount percentages.
  - Added `sortBaselineRanking(array &$items)`: Lexicographically sorts items by `discount_percent DESC`, `diem_danh_gia DESC`, `so_luong_danh_gia DESC`, and `ma_san_pham ASC`.
  - Added `sortCampaignDeals(array &$items, string $sort)`: Implements the 5 baseline sorting options (`featured`, `discount`, `rating`, `price_asc`, `price_desc`).
- **Rationale:** Centralizes all campaign retrieval, item hydration, and sorting logic inside the domain model to avoid duplication across controllers.

---

## 2. Controllers & Routing

### `backend/app/controllers/HomeController.php`
- **Changes:**
  - In `index()`: Calls `getPrimaryActiveCampaign()` and passes `$primaryFlashCampaign` to the view.
  - Added `flashSale()`: Handles `index.php?r=flash_sale`, sanitizes the `sort` parameter, extracts deals, and renders `flash_sale.php`.
- **Rationale:** Connects public HTTP entry points to the domain model.

### `index.php`, `backend/public/index.php`, `frontend/public/index.php`
- **Changes:**
  - Added route handler:
    ```php
    case 'flash_sale':
        (new HomeController($pdo))->flashSale();
        break;
    ```
- **Rationale:** Ensures consistent routing across Apache, Docker Nginx, and root execution environments.

---

## 3. Frontend Views & Helpers

### `frontend/views/home.php`
- **Changes:**
  - Replaced legacy fallback to `$latest` with strict condition: `if (!empty($flashSaleCampaign) && !empty($flashSaleProducts))`.
  - Injected dynamic campaign title `<?= h($flashSaleCampaign['ten_campaign']) ?>` and description.
  - Replaced legacy client-side midnight loop with server-driven countdown reading `data-end-timestamp`.
  - Updated "Xem tất cả deal" button to point to `<?= BASE_URL ?>/index.php?r=flash_sale`.
  - Implemented card renderer with prominent red flash price, struck regular price, `-XX%` badge, and zero fake scarcity bars.
- **Rationale:** Replaces fake demo presentation with real campaign state.

### `frontend/views/chitiet.php`
- **Changes:**
  - Integrated `get_effective_product_price($productIdForForms, $p)` for display.
  - Renders red badge `⚡ FLASH SALE: <Campaign Name>`, countdown notice, prominent flash price in red, struck regular price, and discount percentage.
- **Rationale:** Guarantees pricing consistency between the Homepage deal cards and the Product Detail page.

### `frontend/views/flash_sale.php` *(New File)*
- **Changes:**
  - Created dedicated All Deals public template.
  - Includes breadcrumbs, hero campaign banner, countdown timer, deal count badge, 5 sort buttons, responsive product grid, and honest empty state.
- **Rationale:** Eliminates reliance on `r=tatca` and provides a dedicated campaign browsing experience.

### `frontend/views/helpers.php`
- **Changes:**
  - Updated `get_effective_product_price()` with cross-environment candidate path resolution (`/backend/app/models/...`, `/app/models/...`, `/var/www/html/app/models/...`) and database fallback.
- **Rationale:** Guarantees seamless execution across both host Windows environments and Docker container environments.

---

## 4. Test & QA Artifacts

### `scratch/test_phase2c_suite.php` *(New File)*
- Comprehensive 20-test automated suite covering Tests A through T.

### `scratch/browser_qa_phase2c.mjs` *(New File)*
- Automated Chrome CDP browser verification script covering Desktop (1440x900) and Mobile (375x812) viewports.

### `scratch/setup_qa_campaign.php` & `scratch/teardown_qa_campaign.php` *(New Files)*
- Controlled setup and teardown utilities for creating and destroying `TEST_FLASHSALE_PHASE2C_PUBLIC`.
