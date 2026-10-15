# Flash Sale Final Acceptance — Step 3: Homepage Carousel Acceptance Report

**Audit Date:** 2026-10-09  
**Role:** Senior Full-Stack QA / PHP / MongoDB Engineer  
**Status:** **PASSED**

---

## 1. Overview & Test Setup

The Homepage Flash Sale Carousel was tested under real browser automation using Chrome and Playwright across three standardized responsive viewports:
- **Desktop:** `1440 × 900`
- **Tablet:** `1024 × 768`
- **Mobile:** `375 × 812`

The active campaign contained **24 eligible SKUs** (`QA_ACCEPTANCE_FLASH_SALE_24`).

---

## 2. Responsive Layout & Pagination Matrix

| Viewport | Dimensions | Grid Layout | Measured Visible Cards / Page | Expected Pages | Measured Page Indicator | Horizontal Overflow Check | Result |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **Desktop** | 1440 × 900 | 4 columns × 2 rows | **8 cards** | 3 pages | `Trang 1/3 (24 SP)` | `scrollWidth <= innerWidth` (**True**) | **PASS** |
| **Tablet** | 1024 × 768 | 2 columns × 2 rows | **4 cards** | 6 pages | `Trang 1/6 (24 SP)` | `scrollWidth <= innerWidth` (**True**) | **PASS** |
| **Mobile** | 375 × 812 | 2 columns × 1 row | **2 cards** | 12 pages | `Trang 1/12 (24 SP)` | `scrollWidth <= innerWidth` (**True**) | **PASS** |

---

## 3. Carousel Navigation & State Invariants

| Invariant / Control | Test Scenario | Expected Behavior | Measured Behavior | Status |
| :--- | :--- | :--- | :--- | :--- |
| **Initial State** | Page load at Desktop | `btnFlashPrev` disabled, `btnFlashNext` enabled | `disabled=true` for Prev; `disabled=false` for Next | **PASS** |
| **Next Navigation (P1 -> P2)** | Click `btnFlashNext` | Current page becomes 2; shows items 9–16 | Page indicator updates to `Trang 2/3`; items 9–16 visible | **PASS** |
| **End Boundary (P2 -> P3)** | Click `btnFlashNext` | Current page becomes 3; `btnFlashNext` disabled | Page indicator updates to `Trang 3/3`; `btnFlashNext` disabled | **PASS** |
| **Prev Navigation (P3 -> P2 -> P1)** | Click `btnFlashPrev` twice | Returns to Page 2 then Page 1; `btnFlashPrev` disabled | Returns to Page 1; `btnFlashPrev` disabled | **PASS** |
| **Zero Backend Ranking Rerun** | Paging Next / Prev | Client-side DOM class toggling (`.d-none`); 0 HTTP calls | Verified: Pure DOM class manipulation, no network requests | **PASS** |
| **SKU Universe Preservation** | All pages combined | Exactly 24 unique SKUs accessible; 0 duplicate / missing | 24 unique SKUs present in DOM; all accessible via paging | **PASS** |
| **Live Countdown Timer** | Server-driven countdown | Displays accurate remaining hours, minutes, seconds | Observed ticking: `03h 50m 59s` matching campaign end | **PASS** |

---

## 4. Conclusion

The Homepage Carousel strictly meets all responsive layout, card density, pagination, countdown, and accessibility criteria without horizontal overflow or unexpected reruns.
