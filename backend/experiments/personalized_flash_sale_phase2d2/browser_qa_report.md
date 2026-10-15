# Phase 2D.2: Browser QA Report

**Audit Date:** 2026-10-08  
**Tested Viewports:**
- Desktop: $1440 \times 900$
- Mobile: $375 \times 812$

---

## 1. Test Execution Summary

### 1.1 Desktop Viewport ($1440 \times 900$)
- **URL Tested:** `http://localhost/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/index.php?r=flash_sale`
- **HTTP Status:** 200 OK
- **Hero & Countdown Banner:**
  - Rendered correctly with dark green gradient (`#11281D` to `#183B2B`), lightning bolt icon, campaign headline, and real tabular countdown timer.
  - No flickering or arbitrary reset detected.
- **Filter & Sort Bar:**
  - Displays total product count badge.
  - Filter buttons rendered smoothly: "Ưu đãi nổi bật", "Giảm nhiều nhất", "Đánh giá tốt", "Giá thấp → cao", "Giá cao → thấp".
  - When user context is available, "Dành cho bạn" button appears highlighted with `#183B2B` background and star icon.
- **Sort Switching Behavior:**
  - Clicking "Giảm nhiều nhất" (`?r=flash_sale&sort=discount`) activates the discount sort, sorting products monotonically by `discount_percent` descending.
  - Clicking "Đánh giá tốt" sorts by `diem_danh_gia` and `so_luong_danh_gia`.
  - Clicking "Giá thấp → cao" sorts strictly by `effective_price` ascending.
- **Deal Cards:**
  - Cards render product image, brand pill, discount percentage badge (`-40%`), lightning "Flash Deal" badge, title, review rating with gold star, and dual prices (red flash price + strike-through regular price).
  - Grounded reason tags render neatly below the brand tag (e.g. `Được đánh giá tốt`, `Giảm sâu 40%`, `Theo tìm kiếm gần đây`).
  - Action buttons "Thêm vào giỏ" and "Mua ngay" are aligned in a 2-column grid.

### 1.2 Mobile Viewport ($375 \times 812$)
- **Layout & Responsiveness:**
  - Deal grid collapses into a responsive 2-column layout (`col-6`) with 12px gutters.
  - Sort buttons wrap cleanly into a compact group without horizontal scroll overflow.
  - Countdown timer timer boxes wrap smoothly.
  - Touch targets for product links, "Thêm vào giỏ", and "Mua ngay" satisfy minimum touch recommendations ($\ge 38\text{px}$ height).

### 1.3 Price Consistency & Privacy
- **Price Invariant:** Verified that product prices displayed on the Flash Sale grid match the prices in the database and shopping cart.
- **Privacy:** No personal identifiable information (emails, user IDs, raw search terms) is leaked into public HTML markup or data attributes.

---

## 2. Verdict
Desktop & Mobile Browser QA: **PASS**.
