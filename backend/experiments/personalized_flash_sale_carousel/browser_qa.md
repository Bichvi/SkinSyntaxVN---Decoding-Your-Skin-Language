# SKINSYNTAXVN — FLASH SALE PERSONALIZED CAROUSEL UX EXTENSION
## Browser QA Report: Multi-Viewport Responsive Validation

**Date:** 2026-10-08  
**Test Harness:** `scratch/browser_qa_carousel.mjs`  
**Browser Engine:** Google Chrome Headless (Chrome/154.0.8037.100) via Chrome DevTools Protocol (CDP)  
**Campaign Size Under Test:** $N = 32$ SKUs (`TEST_FLASHSALE_CAROUSEL_EXT`)  

---

### 1. Viewport Test Matrix & Observations

| Viewport | Dimensions | Grid Configuration | Expected Cards/Page | Total Pages | Observed Behavior | Status |
|:---|:---:|:---:|:---:|:---:|:---|:---:|
| **Desktop** | $1440 \times 900$ | 4 columns $\times$ 2 rows | 8 cards | 4 pages | Exact 8 cards visible per page; Prev disabled on page 1; Next navigates by 8 cards; Next disabled on page 4; Indicator: `Trang 1 / 4 (32 SP)` | **PASS** |
| **Tablet** | $1024 \times 768$ | 2 columns $\times$ 2 rows | 4 cards | 8 pages | Exact 4 cards visible per page; Indicator: `Trang X / 8 (32 SP)`; Clamped page on resize | **PASS** |
| **Mobile** | $375 \times 812$ | 2 columns $\times$ 1 row | 2 cards | 16 pages | Exact 2 cards visible per page; Zero horizontal overflow (`scrollWidth <= clientWidth`); Touch swipe and arrows operational | **PASS** |

---

### 2. Detailed Desktop Validation ($1440 \times 900$)

1. **Page 1 Initial State:**
   - Flash Sale carousel container loaded with active countdown timer.
   - Page indicator displays: `Trang 1 / 4 (32 SP)`.
   - Cards 0 through 7 (first 8 products) are visible.
   - Prev button is in `disabled` state with `opacity: 0.35` and `cursor: not-allowed`.
   - Next button is enabled.
   - Screenshot: `screenshots/01_desktop_page1_8cards.png`

2. **Page 2 Navigation:**
   - Clicking Next button advances carousel to Page 2.
   - Page indicator updates to: `Trang 2 / 4 (32 SP)`.
   - Exactly 8 cards visible (cards 8 through 15).
   - Prev button is now enabled. Next button remains enabled.
   - Screenshot: `screenshots/02_desktop_page2_next.png`

3. **Page 4 (Last Page) Boundary:**
   - Navigating to Page 4 displays cards 24 through 31.
   - Page indicator updates to: `Trang 4 / 4 (32 SP)`.
   - Next button is in `disabled` state (no cyclic wrap-around).
   - Prev button remains enabled.
   - Screenshot: `screenshots/03_desktop_page4_last_page.png`

4. **Keyboard Accessibility Navigation:**
   - Focused the carousel viewport (`tabindex="0"`) and pressed `ArrowLeft`.
   - Carousel immediately navigated backwards to Page 3 (`Trang 3 / 4`).
   - Screenshot: `screenshots/04_desktop_keyboard_nav.png`

---

### 3. Tablet Validation ($1024 \times 768$)

- Resizing viewport dynamically recomputed `pageSize = 4`.
- Recalculated `totalPages = 8` ($\lceil 32 / 4 \rceil$).
- Rendered 4 cards visible in a clean 2-column $\times$ 2-row layout.
- Page indicator displays: `Trang X / 8 (32 SP)`.
- Screenshot: `screenshots/05_tablet_4cards_grid.png`

---

### 4. Mobile Validation ($375 \times 812$)

- Resizing viewport dynamically recomputed `pageSize = 2`.
- Recalculated `totalPages = 16` ($\lceil 32 / 2 \rceil$).
- Rendered 2 cards visible in a 2-column $\times$ 1-row layout.
- Page indicator displays: `Trang X / 16 (32 SP)`.
- **Horizontal Overflow Check:**
  - `document.documentElement.scrollWidth` = $375\text{px}$.
  - `document.documentElement.clientWidth` = $375\text{px}$.
  - Result: **Zero horizontal overflow** (`scrollWidth <= clientWidth`).
- Screenshot: `screenshots/06_mobile_2cards_no_overflow.png`

---

### 5. Browser QA Verification Summary

All 10 CDP browser checks PASSED. Teardown confirmed test campaign deletion and canonical database preservation (`san_pham = 2473, danh_muc = 28`).
