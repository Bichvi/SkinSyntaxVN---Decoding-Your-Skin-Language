# SKINSYNTAXVN — PHASE P4C: CHANGED FILES LOG

## 1. Production Architecture Files Modified

### 1.1 `nginx/nginx.conf`
- **Location**: `nginx/nginx.conf` (mapped to `/etc/nginx/nginx.conf` in container `skinsyntax-nginx`).
- **Modifications**:
  - Expanded `gzip_types` to include `image/svg+xml`, `application/x-javascript`, `application/rss+xml`, and standard text MIME types.
  - Added production tuning: `gzip_vary on;`, `gzip_proxied any;`, `gzip_comp_level 5;`, `gzip_min_length 256;`.
  - **Removed `gzip off;`** in `location = /index.php`.
  - Strictly excluded `video/mp4`, `image/png`, `image/jpeg`, `image/webp` to protect media streams and range requests.
- **Impact**: Dynamic HTML payload transfer sizes slashed by **81.0% to 86.7%** across all routes (saving > 300 KB per route).

### 1.2 `frontend/views/layouts/header.php`
- **Location**: `frontend/views/layouts/header.php`.
- **Modifications**:
  - Converted `bootstrap-icons.min.css` from render-blocking `<link rel="stylesheet">` to non-blocking print-media swap:
    ```html
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.min.css"></noscript>
    ```
  - Added high-priority font preload for Google Fonts:
    ```html
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&family=Quicksand:wght@300;400;500;600;700;800&display=swap">
    ```
  - Preserved `bootstrap.min.css`, `fontawesome.min.css`, and `style.css` as synchronous stylesheets to eliminate FOUC.

### 1.3 `frontend/views/layouts/footer.php`
- **Location**: `frontend/views/layouts/footer.php`.
- **Modifications**:
  - Moved `</main>` before footer script declarations.
  - Added `defer` attribute to `bootstrap.bundle.min.js`, `live-search.js`, and `smart-search.js`.
- **Impact**: Unblocked HTML parser from waiting on client JavaScript assets.

### 1.4 `frontend/views/home.php`
- **Location**: `frontend/views/home.php`.
- **Modifications**:
  - **Selective Mascot Preloading**: Replaced indiscriminate 11-pose preload with immediate 4-state preloading (`idle`, `greeting`, `talking1`, `talking2`).
  - **On-Demand & Fallback Mechanism**: Added graceful fallback preserving the current pose while unseen poses load on demand; added idle prefetching for later poses via `requestIdleCallback`.
  - **Carousel Reflow Consolidation**: Removed `void item.offsetWidth;` inside `renderCarousel()` loop (up to 24 products); replaced with single parent reflow `void viewport.offsetWidth;` outside the loop.
- **Impact**: Saved ~500 KB of initial asset contention, cut mobile FCP by 1.63s, eliminated carousel layout thrashing.

### 1.5 `frontend/views/components/ai_chat_widget.php`
- **Location**: `frontend/views/components/ai_chat_widget.php`.
- **Modifications**:
  - Extracted conversation rendering, session parsing, and greeting creation into `ensureChatInitialized()`.
  - Triggered `ensureChatInitialized()` immediately upon user click (`openWidget()`) or deferred to `requestIdleCallback` (4s timeout) when closed.
  - Preserved floating trigger appearance, session storage keys, active conversation IDs, and backend chat APIs.
- **Impact**: Relieved main-thread execution time on initial boot across all pages.

---

## 2. Benchmark & Verification Scripts Created

### 2.1 `backend/tests/run_p4c_lighthouse.js`
- Automated Google Lighthouse 12.8.2 runner evaluating all 5 routes across Desktop & Mobile presets with CDP headless chrome flags.
- Generated `p4c_matrix.json` and 12 detailed Lighthouse JSON audit reports.

### 2.2 `backend/tests/verify_category_desktop_variance.js`
- Triple-run statistical measurement script for Category Desktop proving median score of **94** (range: 93–95) and **0 ms TBT**.

### 2.3 `backend/tests/verify_p4c_regression.js`
- Automated 16-point end-to-end regression verification suite checking routes, compression, widgets, carousels, responsive viewports, and HTTP 206 video streaming.
