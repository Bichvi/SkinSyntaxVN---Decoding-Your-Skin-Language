# Browser QA & Visual Layout Audit Report

## 1. Overview
Browser QA for Phase 2E was executed using an automated headless Chrome instance connected via the Chrome DevTools Protocol (CDP).

Testing targeted the two standard viewport profiles:
- **Desktop**: $1440 \times 900$ px (Standard widescreen desktop).
- **Mobile**: $375 \times 812$ px (Standard iPhone/modern mobile viewport).

All screenshots were captured and archived in:
`backend/experiments/personalized_flash_sale_phase2e/screenshots/`

---

## 2. Test Execution & Evidence Matrix

| ID | Viewport | Target Surface | URL / Action | Screenshot File | Key Verifications | Result |
|---|---|---|---|---|---|---|
| `D-1` | 1440x900 | Homepage Flash Sale | `r=home` | `desktop_01_homepage_flashsale.png` | Banner rendered, countdown active, Top 4 cards visible, 0 overflow | **PASS** |
| `D-2` | 1440x900 | All Deals Page | `r=flash_sale` | `desktop_02_all_deals.png` | Exactly 16 candidate cards, 5 sort pills, clear discount badges, 0 overflow | **PASS** |
| `D-3` | 1440x900 | All Deals Sort | `r=flash_sale&sort=discount` | `desktop_03_all_deals_sort.png` | Reorders to -50% deal at rank 1, active pill highlighted, 0 overflow | **PASS** |
| `D-4` | 1440x900 | Product Detail | `r=chitiet&id=1000` | `desktop_04_product_detail.png` | Flash Sale amber badge, effective price 114,100 đ, struck 163,000 đ | **PASS** |
| `D-5` | 1440x900 | Shopping Cart | `r=giohang` | `desktop_05_cart_page.png` | Responsive table, unit price matches flash price, subtotal correct | **PASS** |
| `M-1` | 375x812 | Mobile Homepage | `r=home` | `mobile_01_homepage_flashsale.png` | Responsive 2-column grid, readable price, countdown wrapped, 0 overflow | **PASS** |
| `M-2` | 375x812 | Mobile All Deals | `r=flash_sale` | `mobile_02_all_deals.png` | 16 products in vertical scroll, sort controls horizontal-scrollable, 0 overflow | **PASS** |
| `M-3` | 375x812 | Mobile Product Detail | `r=chitiet&id=1000` | `mobile_04_product_detail.png` | Price hierarchy intact, Add-to-cart button full-width, 0 overflow | **PASS** |

---

## 3. Viewport & Layout Findings

### 3.1 Mobile Viewport ($375 \times 812$)
- **Horizontal Overflow Check**: `document.documentElement.scrollWidth > window.innerWidth` returned `false` on all pages. Zero clipping or broken horizontal scrolling observed.
- **Price Readability**: The discounted unit price (`114.100 đ`) and original struck-through price (`163.000 đ`) maintain distinct font sizing and color contrast.
- **Badge Usability**: Discount badges (`-30%`, `-50%`) and reason tags (`Tương tự sản phẩm bạn vừa xem`, `Trong ngân sách của bạn`) fit gracefully without text truncation.
- **Touch Targets**: Sort filter pills and "Xem tất cả deal" buttons exceed the minimum 44px touch target guideline.

### 3.2 Desktop Viewport ($1440 \times 900$)
- **4-Card Grid Balance**: Homepage Top 4 displays a clean 4-column layout (`col-6 col-md-3`), centered within the container.
- **Hierarchy & Visual Anchor**: The dark forest green theme (`#183B2B`) with amber accents (`#FEF08A`, `#FFC107`) provides strong contrast and clear visual differentiation from standard catalog carousels.

---

## 4. Audit Verdict
**BROWSER QA — 100% PASS (8 / 8 SUITES PASS)**.  
Both Desktop and Mobile viewports deliver a seamless, responsive, and visually consistent Flash Sale shopping experience without layout regressions.
