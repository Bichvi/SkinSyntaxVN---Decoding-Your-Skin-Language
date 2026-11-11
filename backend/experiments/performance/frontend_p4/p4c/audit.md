# SKINSYNTAXVN — PHASE P4C: PRE-IMPLEMENTATION AUDIT

## 1. Scope and Target Architecture
- **Objective**: Eliminate render-blocking stylesheets, unnecessary webfont stalls, uncompressed payloads, forced reflows, and initial main-thread JavaScript execution bottlenecks across SkinSyntaxVN, while strictly preserving 100% of the UI design, brand typography, interactive components, and AI commerce workflows.
- **Environment**: Docker containerized stack (`skinsyntax-nginx`, `skinsyntax-php`, MongoDB Atlas, Aiven Valkey, Flask AI idol service) running on `http://localhost`.
- **Target Routes**:
  1. Homepage (`/?r=home`)
  2. Category / Catalog (`/?r=tatca`)
  3. Product Detail (`/?r=chitiet&id=6376` with published video and `/?r=chitiet&id=111` baseline)
  4. Personalized Recommendation (`/?r=goiy`)
  5. Search Discovery (`/?r=tatca&q=serum`)

---

## 2. Resource & Dependency Trace

### 2.1 CSS & Font Hierarchy
- **Google Fonts**:
  - `Plus Jakarta Sans`: weights 300..800
  - `Quicksand`: weights 300..800
  - Baseline: Loaded via synchronous `<link>` in `header.php`. Even with `display=swap`, the stylesheet fetch blocked initial DOM construction.
- **Bootstrap CSS (`bootstrap.min.css`)**:
  - 232 KB minified framework stylesheet.
  - Critical for grid structure, navbar, utility classes, buttons, modal dialogs.
  - Assessment: Must remain render-blocking to avoid severe FOUC (flash of unstyled content).
- **Bootstrap Icons (`bootstrap-icons.min.css`)**:
  - 85.8 KB icon font stylesheet.
  - Usage Audit: Scanned entire frontend template hierarchy (`header.php`, `footer.php`, hero sections). Found 0 icons in above-the-fold header or hero section. Icons are only used in lower sections (e.g., routine verification badges) and admin dashboard.
  - Assessment: Safe to defer as non-blocking (`media="print" onload="this.media='all'"`) with zero visual shift or FOUC.
- **Font Awesome (`fontawesome.min.css`)**:
  - 102.6 KB icon font stylesheet.
  - Usage Audit: Heavily used in utility bar, search input icon (`fa-magnifying-glass`), live video pill (`fa-video`), and cart icons.
  - Assessment: Critical for header identity; kept render-blocking to guarantee icons are rendered with first paint.
- **Global Theme (`style.css`)**:
  - 105.5 KB custom design system stylesheet.
  - Critical for site layout, colors, brand lockup, responsive navigation.
  - Fallback fonts configured: `font-family: 'Quicksand', system-ui, -apple-system, sans-serif;`.

### 2.2 Server & Compression Audit (Nginx)
- **Nginx Configuration (`nginx/nginx.conf`)**:
  - Baseline finding: `gzip on;` was present in `http` block, but `gzip_types` was limited and missing modern types (`image/svg+xml`, `application/x-javascript`, etc.).
  - **Critical Root Cause Identified**: In `location = /index.php`, an explicit directive `gzip off;` was configured!
  - Impact: Every single dynamic PHP response (all routes in SkinSyntaxVN) was served completely uncompressed (`Content-Encoding: none`), transferring between 219 KB and 436 KB of raw HTML over the wire per page visit.
  - Video Commerce: Verified route `r=product_video_stream` handles `video/mp4` streaming with `HTTP 206 Partial Content` and byte-range seeking. Gzip must never compress `video/mp4` or alter Range responses.

### 2.3 JavaScript Execution & Event Handlers
- **Global Footer Scripts (`footer.php`)**:
  - `bootstrap.bundle.min.js` (80.7 KB), `live-search.js` (5.2 KB), and `smart-search.js` (6.8 KB) were loaded synchronously inside `<footer>` without `defer`.
  - Closing `</main>` was positioned incorrectly after the `<script>` tags.
- **SYNA Interactive Mascot (`home.php`)**:
  - Baseline finding: In `DOMContentLoaded`, `home.php` instantiated 11 Image objects and preloaded all 11 mascot states simultaneously (`syna_listening.png`, `syna_greeting.png`, `syna_talking_1.png`, `syna_talking_2.png`, `syna_thinking.png`, `syna_recommend.png`, `syna_processing.png`, `syna_active.png`, `syna_happy.png`, `syna_bye.png`), consuming ~800 KB of initial bandwidth.
  - Only `idle`, `greeting`, and `talking1/talking2` are needed in the initial 5 seconds.
- **Flash Sale Carousel (`home.php`)**:
  - Inside `renderCarousel()`, loop executed `void item.offsetWidth;` on every single product card during navigation, triggering up to 24 forced reflows (layout thrashing) per page flip.
- **AI Chat Widget (`ai_chat_widget.php`)**:
  - 87 KB widget loaded on all pages with `$pdo !== null`.
  - On `DOMContentLoaded`, it executed `syncLayout()`, `syncExpandedState()`, full `renderMessages()` DOM generation, `injectGreeting()`, and if logged in with active conversation, performed a background `fetch()` for conversations even when the chat panel was closed and hidden.
