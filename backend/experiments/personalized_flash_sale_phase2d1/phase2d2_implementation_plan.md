# Phase 2D.2 Implementation Plan: Personalized Deal Ranking Execution

## 1. Scope & Execution Boundary
Phase 2D.1 established the mathematical calibration, score distributions, and architecture for Personalized Deal Ranking.

**Phase 2D.2 will implement this architecture into production UI.**
*(This document outlines the planned execution steps. No production changes are performed in Phase 2D.1).*

---

## 2. Planned Implementation Steps

### Step 1: Candidate Scoring Adapter in `FlashSaleCampaign.php`
- Implement `public function scoreCampaignCandidates(array $candidateItems, array $userContext): array`.
- Re-use `ContentBasedRecommender::loadIndex()`, `buildBehaviorQueryVector()`, `buildProfileQueryVector()`.
- Calculate `personal_relevance` strictly for campaign candidate items.
- Apply in-campaign Min-Max normalization for `personal_relevance`, `discount_percent`, and `weighted_rating`.
- Compute `final_score = 0.50 * Norm(P) + 0.30 * Norm(D) + 0.20 * Norm(WR)`.
- Stamp grounded `reason_tags` and `dominant_signal`.

### Step 2: Merchandising Diversity Filter
- In `FlashSaleCampaign.php`, implement `filterFamilyDiversity(array $items, int $max = 4): array` using `ContentBasedRecommender::extractProductFamily()`.
- Enforce max 1 item per product family for the Homepage Top 4.

### Step 3: Controller Wiring in `HomeController.php`
- In `index()`: Extract `userContext` (`recentViewed`, `userProfile`, `behaviorSignals`) and pass to `getPrimaryActiveCampaign($now, $userContext)`.
- In `flashSale()`: Handle `sort=for_you` / `"Dành cho bạn"`.
  - If user is Guest (0 signals): default to `sort=featured` (deterministic baseline).
  - If user has active signals: default or allow selection of `sort=for_you`.

### Step 4: UI Integration in Views
- `frontend/views/home.php`:
  - Render personalized Top 4 deals.
  - Display small, elegant grounded reason tags under the title (e.g. `<span class="badge bg-light text-success border"><i class="fas fa-sparkles me-1"></i> Phù hợp loại da của bạn</span>`).
- `frontend/views/flash_sale.php`:
  - Add sort pill: `Dành cho bạn` (`?r=flash_sale&sort=for_you`) alongside existing 5 baseline sorts.
  - Render grounded reason tags on each card.

---

## 3. Mandatory Safety & Invariant Verifications in Phase 2D.2

1. **Price Personalization Prohibition:**
   - Automated unit tests must verify: For any given SKU in an active campaign, all 5 user contexts receive the **exact same flash price**, `regular_price`, and `effective_price`.
2. **Campaign Boundary Prohibition:**
   - 100% of ranked deals must belong to the active campaign. Zero catalog leakage.
3. **Canonical DB Preservation:**
   - `san_pham = 2473`, `danh_muc = 28`, 0 base product price modifications.
4. **Recommender Stability:**
   - Zero modifications to `ContentBasedRecommender.php` production methods or Simple WR constants ($m=21, C=4.8890$).
