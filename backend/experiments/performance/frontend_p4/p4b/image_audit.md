# SKINSYNTAXVN — PHASE P4B: IMAGE AUDIT REPORT

## 1. Executive Summary

Phase P4A identified that unoptimized image loading was a primary contributor to network contention and inflated Mobile LCP (Homepage 9.81s, Category 9.38s, Recommendation 10.84s). 

Before applying changes in P4B, an in-depth audit was conducted across all rendering templates, inspecting viewport positions, bounding boxes, aspect ratios, dynamic scripts, and caching headers.

---

## 2. Route-by-Route Image Inventory & LCP Analysis

### 2.1. Homepage (`/index.php?r=home`)
- **Initial Viewport Elements (Desktop)**:
  - Top navigation & Header.
  - Hero Section Mascot (`img#synaMascotImg`): Source `syna_listening.png`.
  - Intrinsic Dimensions: 300x360 px (Aspect ratio 5:6).
  - CSS Display Box: `width: min(240px, 100%); height: auto; max-height: 260px; object-fit: contain`.
  - **LCP Element**: Desktop Lighthouse identified `img#synaMascotImg` as the primary LCP node (~248x258 px rendered area).
- **Below-the-Fold Elements**:
  - Recommendation carousel products: 4–8 product cards.
  - Flash Sale carousel products: 4–12 product cards.
  - Tabbed collections ("Yêu thích nhất", "Mới lên kệ"): 8–16 product cards.
  - Live commerce featured product thumbnail: 58x58 px.
  - **Total unlazy product images in baseline**: Over 30 images, causing 3.7 MB total byte transfer on initial load.
- **Dynamic Mascot Widget**:
  - Mascot floating widget (`ai_chat_widget.php`): Loaded with `?v=<?= time() ?>`, invalidating browser cache on every single HTTP request.

### 2.2. Category / All Products (`/index.php?r=tatca`)
- **Initial Viewport Elements**:
  - Desktop: First row of 4 catalog cards (`col-6 col-md-3`).
  - Mobile: First row of 2 catalog cards.
  - Container aspect ratio: `aspect-ratio: 1/1; overflow: hidden; background: #F8FAF8;`.
  - Intrinsic sizes: ~600x600 px to 800x800 px product photographs.
  - CSS Display Box: `width: 100%; height: 100%; object-fit: cover;`.
  - **LCP Element**: Product card 0 image is the definitive LCP element for both Desktop and Mobile.
- **Below-the-Fold Elements**:
  - Cards 4 to 23 (20 product cards).
  - Previously all 24 cards were eager loaded simultaneously over HTTP/1.1, starving the initial LCP image of network bandwidth on 4G/3G throttling.

### 2.3. Product Detail Page (`/index.php?r=chitiet&id=...`)
- **Initial Viewport Elements**:
  - Main hero product image (`img#mainImage`): Container `aspect-ratio: 1/1; object-fit: cover;`.
  - Rendered width: ~480px on desktop, ~340px on mobile.
  - **LCP Element**: `img#mainImage`.
- **Below-the-Fold Elements**:
  - Secondary gallery thumbnails (`.thumb-btn img`): 64x64 px.
  - Frequently Bought Together cards: 4 items (200x200 px).
  - Verified user review photos (`.review-item__images img`): 80x80 px.

### 2.4. Recommendation Page (`/index.php?r=goiy`)
- **Initial Viewport Elements**:
  - Routine Summary & AM Routine Step 1 card (`step-card__image`).
  - Container aspect ratio: `aspect-ratio: 1;`.
  - **LCP Element**: AM Step 1 product image.
  - Baseline flaw: All step cards inadvertently inherited `loading="lazy"`, which forced Chrome to delay fetching the above-the-fold hero card until after script evaluation and layout stabilization, inflating Mobile LCP to 10.84s.
- **Below-the-Fold Elements**:
  - Routine steps 2 through 6 (AM & PM).
  - Alternative routine product thumbnails: 36x36 px.
  - Public catalog recommendation grids: 12–24 product cards.
  - "Vì sao phù hợp" modal popups: 64x64 px and 75x75 px.

---

## 3. Bottleneck Categorization

| Bottleneck | Affected Routes | Root Cause | P4B Remediation |
| :--- | :--- | :--- | :--- |
| **Mascot Cache Busting** | Global (all routes) | `syna_mascot.png?v=time()` forced fresh 200 OK download on every request | Replaced with `filemtime()` version hash + fallback |
| **Catalog Network Contention** | Homepage, Category | 24–36 full product photos downloading concurrently | Added `fetchpriority="high"` to item 0; `loading="lazy"` to below-fold items |
| **Delayed Above-Fold LCP** | Recommendation, PDP | Above-fold LCP image either had `loading="lazy"` or lacked fetch priority | Removed lazy from LCP, added `fetchpriority="high"` + `decoding="async"` |
| **Layout Shift Risk (CLS)** | All product cards & Mascot | Missing HTML `width`/`height` attributes | Explicit intrinsic dimensions added matching CSS aspect ratios |
| **Offscreen Modal Images** | Recommendation, PDP | Modal popup images loading eagerly in hidden DOM | Added `loading="lazy"` + explicit dimensions |
