# Phase 2D.2: Changed Files Inventory

The following files were created or modified during Phase 2D.2:

## 1. Created Production Files
- [`backend/app/models/PersonalizedDealRanker.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/PersonalizedDealRanker.php)
  - Dedicated adapter class that scores only candidate campaign items using existing `ContentBasedRecommender` vector building and compatibility functions.
  - Implements in-campaign Min-Max normalization, heuristic composite scoring, Top 4 brand diversity, and grounded reason tag selection.

## 2. Modified Production Files
- [`backend/app/models/FlashSaleCampaign.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/FlashSaleCampaign.php)
  - Added method `rankCampaignCandidates(array $candidates, ?array $userContext): array` with exception-safe fallback to `sortBaselineRanking()`.
- [`backend/app/controllers/HomeController.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/HomeController.php)
  - Updated `index()`: Resolves user context, passes candidates to `rankCampaignCandidates()`, and supplies `flashSalePersonalizedMeta` to the view.
  - Updated `flashSale()`: Added `sort=for_you` option, defaults to `for_you` when usable context is present, and retains explicit sorts (`discount`, `rating`, `price_asc`, `price_desc`).
- [`frontend/views/home.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/home.php)
  - Added dynamic "Dành cho bạn" badge and supporting copy ("Gợi ý được sắp xếp theo nhu cầu và hoạt động gần đây của bạn") when personalization is active.
  - Applied `applyTop4Diversity()` for Homepage Top 4 display.
  - Rendered grounded `flash_sale_reason_tag` badge on Flash Sale product cards.
- [`frontend/views/flash_sale.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/flash_sale.php)
  - Added "Dành cho bạn" sort button in filter bar when user context is available.
  - Rendered grounded reason tags on All Deals cards.

## 3. Test & Verification Artifacts
- [`scratch/test_phase2d2_automation.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/scratch/test_phase2d2_automation.php)
  - 24 automated unit and integration tests (TEST_A through TEST_X) — **24/24 PASS**.
- [`scratch/run_controlled_qa_campaign.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/scratch/run_controlled_qa_campaign.php)
  - Generates controlled context simulation across 5 user states.
