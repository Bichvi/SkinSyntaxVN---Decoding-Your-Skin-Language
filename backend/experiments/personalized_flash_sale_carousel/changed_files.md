# SKINSYNTAXVN — FLASH SALE PERSONALIZED CAROUSEL UX EXTENSION
## Changed Files Inventory

**Date:** 2026-10-08  
**Scope:** File Modifications and Additions for Carousel UX Extension

---

### 1. Production Code Changes

#### 1.1 `frontend/views/admin/flash_sale_form.php`
- **Location:** Line 136 and Lines 468–474.
- **Change Rationale:**
  - Upgraded soft operational guidance notice from "12–20 sản phẩm" to **"24–40 sản phẩm"** to provide merchant guidance for the 8/4/2 carousel pagination experience.
  - Adjusted dynamic JS counter logic in `updateCounters()` so that `< 24` is advisory below range, `24-40` is marked optimal, and `> 40` is advisory above range.
  - No blocking restrictions imposed; form submission allows any $N \ge 1$ distinct valid SKUs.

#### 1.2 `frontend/views/home.php`
- **Location:** Lines 55–60, Lines 714–780, and Lines 1094–1315.
- **Change Rationale:**
  - Replaced `applyTop4Diversity(..., 4)` candidate truncation with retention of the full ranked candidate set in `$flashSaleProducts = $flashSaleCampaign['hydrated_items']`.
  - Replaced static 4-column row markup with a carousel container featuring:
    - Current page indicator and total SKU counter (`Trang 1 / 4 (32 SP)`).
    - Prev / Next arrow buttons with accessible ARIA tags and boundary disabling.
    - Responsive grid container with `repeat(4, minmax(0, 1fr))` on desktop, `repeat(2, minmax(0, 1fr))` on tablet and mobile.
    - Slicing and display toggling logic for 8 cards/page on desktop, 4 cards/page on tablet, and 2 cards/page on mobile.
    - Client-side navigation script with subtle card entry animations, keyboard arrow navigation, mobile touch swipe support, and window resize clamping.
    - Zero backend network requests or reranking triggered on navigation.

---

### 2. Test Suite & Automation Changes

#### 2.1 `scratch/test_phase2d2_automation.php`
- **Location:** Lines 187–192 (TEST_Q).
- **Change Rationale:**
  - Replaced obsolete Homepage Top-4 test assertion with the carousel UX extension requirement: max 8 visible cards per desktop carousel page while retaining accessibility to all $N$ valid campaign SKUs across pages.

#### 2.2 New QA and Verification Scripts
- **`scratch/test_flash_sale_carousel.php`:**
  - Automated test script covering canonical DB counts ($2473/28$), Simple Recommender preservation ($m=21.0, C=4.8890$), edge case pagination math ($N=0, 1, 4, 7, 8, 9, 20, 32, 40, 41$), full candidate ranking across all 4 user contexts, price invariants, and ranking latency benchmarks.
- **`scratch/setup_carousel_qa_campaign.php`:**
  - Helper script to create a temporary test campaign with 32 active products for browser QA.
- **`scratch/teardown_carousel_qa_campaign.php`:**
  - Clean teardown script to delete QA campaigns and confirm catalog integrity.
- **`scratch/browser_qa_carousel.mjs`:**
  - End-to-end Chrome DevTools Protocol automation testing Desktop (1440×900), Tablet (1024×768), and Mobile (375×812) viewports, verifying card counts, button states, keyboard navigation, touch swipe, and zero horizontal overflow.
