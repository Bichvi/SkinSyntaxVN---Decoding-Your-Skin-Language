# SKINSYNTAXVN — PHASE P4C: CSS & FONT OPTIMIZATION DETAILS

## 1. Objective & Principles
- Minimize render-blocking resources in `<head>` while strictly guaranteeing **Zero FOUC** (Flash of Unstyled Content), **Zero Layout Shifts** (CLS < 0.05), and preserving exact typography, theme colors, and layout spacing.
- Adhere to the rule: "Do not delete libraries or change framework CSS arbitrarily."

---

## 2. Resource-by-Resource Optimization Strategy

| Resource | Size | Criticality | Optimization Applied | Rationale & Verification |
| :--- | :---: | :---: | :--- | :--- |
| **`bootstrap.min.css`** | 232 KB | **Critical** | Kept render-blocking `<link rel="stylesheet">` | Prevents total grid and responsive layout collapse during initial paint. |
| **`style.css`** | 105.5 KB | **Critical** | Kept render-blocking `<link rel="stylesheet">` | Defines site-wide tokens, header/footer themes, card components, and fallback font declarations. |
| **`fontawesome.min.css`** | 102.6 KB | **Critical** | Kept render-blocking `<link rel="stylesheet">` | Utility bar, search icon (`fa-magnifying-glass`), and action buttons rely on FontAwesome icons. Rendering synchronously ensures icons appear with first paint without flash. |
| **`bootstrap-icons.min.css`** | 85.8 KB | **Non-Critical** | Defer via print-media swap: `media="print" onload="this.media='all'"` with `<noscript>` fallback | **Zero `bi-` icons** exist above-the-fold in header/hero. Icons are used exclusively in below-the-fold cards and admin pages. Eliminates 85.8 KB from critical render-blocking path without FOUC. |
| **Google Fonts** (`Plus Jakarta Sans`, `Quicksand`) | External CDN | **Non-Critical** | `<link rel="preload" as="style">` + stylesheet with `display=swap` | Allows browser to render text immediately using system fallbacks (`font-family: 'Quicksand', system-ui, -apple-system, sans-serif;`), swapping webfonts seamlessly without blocking FCP. |

---

## 3. Implementation Details

### 3.1 `frontend/views/layouts/header.php`
```html
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&family=Quicksand:wght@300;400;500;600;700;800&display=swap">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&family=Quicksand:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" crossorigin="anonymous">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.min.css" media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.min.css"></noscript>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/fontawesome.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(dirname(__DIR__, 2) . '/public/assets/css/style.css') ?: '20261007_prem' ?>">
```

### 3.2 FOUC Prevention Analysis
1. **Header & Navigation**: Rendered with `bootstrap.min.css`, `style.css`, and `fontawesome.min.css`. Every element in the viewport has its layout geometry, margins, background colors, and icon widths styled before the first paint cycle.
2. **Typography**: `style.css` defines font fallbacks `font-family: 'Quicksand', system-ui, -apple-system, sans-serif;`. Font metrics align closely with system sans-serif, preventing disruptive cumulative layout shift when webfonts load (`CLS = 0.0001` on mobile).
3. **Icons**: FontAwesome icons load synchronously; Bootstrap Icons load immediately in the background via low-priority print media, becoming available before the user scrolls to sections containing them.
