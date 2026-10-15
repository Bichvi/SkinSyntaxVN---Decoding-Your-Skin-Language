# Files Changed in Phase V1 & V1.1

The following files were created or modified during Phase V1 and Phase V1.1:

## 1. New Files Created
- [`backend/app/models/ProductVideo.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/ProductVideo.php)
  - Data model managing `ai_product_videos` collection.
  - Implements publication validation, deterministic priority ordering, product association arrays, and publish/unpublish toggling.
  - Added `getManageableVideos()` aggregating videos from `ai_idol_videos`, `ai_idol_campaigns`, and `san_pham`.
- [`backend/tests/test_phase_v1.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/tests/test_phase_v1.php)
  - Automated test suite validating all 16 API test cases covering single video, multiple products, unpublishing, security boundaries, HTTP Range byte requests, and error conditions.
- [`backend/tests/test_admin_csrf_rbac.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/tests/test_admin_csrf_rbac.php)
  - Automated security test suite validating CSRF rejection (invalid/missing token), RBAC rejection, and authorized admin requests on publish & toggle endpoints.
- Documentation in [`backend/experiments/ai_live_commerce/watch_to_reward/`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/ai_live_commerce/watch_to_reward/):
  - `architecture_audit.md`
  - `video_publication.md`
  - `product_detail_tests.md`
  - `voucher_design.md`
  - `watch_validation.md`
  - `checkout_integration.md`
  - `security_tests.md`
  - `changed_files.md`
  - `final_report.md`

## 2. Existing Files Modified
- [`backend/app/controllers/AdminAiIdolController.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/AdminAiIdolController.php)
  - Added CSRF token generator `getCsrfToken()` and verifier `verifyCsrf()`.
  - Added `searchProducts()` supporting regex search and integer/string SKU lookups in MongoDB `san_pham`.
  - Enhanced `listProductVideos()` to return both manageable videos and existing publications.
  - Implemented `publishProductVideo()` and `togglePublishProductVideo()` with CSRF verification and robust payload handling.
- [`backend/app/controllers/SanPhamController.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/SanPhamController.php)
  - In `chitiet()`: queries `ProductVideo::getPublishedVideosByProductId($id)` and passes `$productVideos` to view.
  - Added `streamProductVideo()`: secure public proxy with strict 24-char hex regex validation, session lock release, draft prevention, and HTTP 206 Partial Content range support.
- [`backend/app/routes/ai_idol.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/routes/ai_idol.php)
  - Added routing cases for `admin_ai_idol_product_videos`, `admin_ai_idol_search_products`, `admin_ai_idol_publish_video`, and `admin_ai_idol_toggle_publish`.
- [`backend/public/index.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/public/index.php) and [`index.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/index.php)
  - Synchronized routing allowlist for `product_video_stream`, `admin_ai_idol_search_products`, and publication management endpoints across both entrypoints.
- [`frontend/views/admin/ai_idol/index.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/admin/ai_idol/index.php)
  - Added 3rd flow switcher tab: `Xuất bản sản phẩm` (`#pubFlowTab`).
  - Added `#pubFlow` panel: toolbar filters (all/published/unpublished), search bar, refresh button, and responsive video grid (`#pubVideoGrid`).
  - Added publication management modal (`#pubModal`): video preview banner, editable PDP title, display priority input, live debounced product search, multi-product checklist, selected chips with delete buttons, primary product selector, and publish/unpublish action buttons.
  - Injected `window.AI_IDOL_CSRF` and authoritative catalog products.
- [`frontend/public/assets/css/ai-idol-studio.css`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/public/assets/css/ai-idol-studio.css)
  - Added scoped CSS for `.studio-publication-panel`, `.pub-card`, `.pub-modal-backdrop`, `.pub-modal`, chips, checklist, badges, and responsive layouts.
- [`frontend/public/assets/js/ai-idol-studio.js`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/public/assets/js/ai-idol-studio.js)
  - Integrated `showStudioFlow('publication')` tab switching with URL parameter (`&flow=publication`, `&open_pub=...`) support.
  - Implemented publication management module: fetching videos, rendering cards, modal controls, live product search with debounce and server query, chips selection, primary product sync, CSRF-protected submit publish, and unpublish.
- [`frontend/views/chitiet.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/chitiet.php)
  - Added responsive Syna 3D video showcase card (`#synaVideoShowcase`) with HTML5 `<video>`, loading spinner, verified badge, and playlist selector.
