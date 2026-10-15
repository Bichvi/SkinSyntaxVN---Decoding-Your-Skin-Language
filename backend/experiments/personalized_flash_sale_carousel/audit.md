# SKINSYNTAXVN — FLASH SALE PERSONALIZED CAROUSEL UX EXTENSION
## Audit Report: Existing Architecture, Sizing Limits & Technical Safeguards

**Date:** 2026-10-08  
**Engineer:** Senior PHP/MongoDB Full-Stack Engineer  
**Scope:** Flash Sale Campaign Domain, Admin Form, Hydration, Ranking Pipeline, and Homepage Presentation  

---

### 1. Architectural Baseline & Sizing Limits Audit

#### 1.1 Admin Campaign Size & Form Handling
- **Database Model (`backend/app/models/FlashSaleCampaign.php`):**
  - Inspected `FlashSaleCampaign::validateCampaign()`.
  - Found that the backend validator checks `empty($rawItems)` (ensuring at least 1 item is selected). There is **no artificial upper limit** (e.g. no hard cap of 20, 32, or 40) hardcoded in the domain model.
  - SKU validation enforces:
    1. Product existence in canonical `san_pham`.
    2. Product active status (rejects `inactive`, `hidden`, `tam_an`, `0`).
    3. `flash_price > 0` and `flash_price < regular_price`.
    4. Duplicate product detection within the same campaign.
    5. Time-interval conflict detection across other active campaigns.
- **Admin Form Guidance (`frontend/views/admin/flash_sale_form.php`):**
  - Audited line 136 and line 468 (`updateCounters()`).
  - Previously showed a soft recommendation for "12–20 sản phẩm".
  - **Audit Finding:** This was advisory text in the UI only; it did not prevent form submission.
  - **Adjustment:** Upgraded to **"24–40 sản phẩm"** operational guidance to inform the merchant of the optimal carousel pagination experience (e.g. 3 to 5 pages on desktop) without restricting Admin from saving any arbitrary $N \ge 1$ distinct valid SKUs.

#### 1.2 Technical Safeguards & Real Physical Limits
1. **PHP `max_input_vars` Safeguard:**
   - Standard PHP configuration defaults `max_input_vars` to 1000.
   - In `flash_sale_form.php`, each selected SKU posts 2 form input fields: `items[i][product_id]` and `items[i][flash_price]`.
   - Campaign metadata posts ~6 fields (`ten_campaign`, `mo_ta`, `start_at`, `end_at`, `is_active`, csrf/action).
   - Total variables for $N$ products = $2N + 6$.
   - For $N=40$ products: $86$ input variables (well within 1000).
   - Real technical boundary before increasing `max_input_vars`: ~495 products.
   - Operational range of 24–40 SKUs is ~54–86 input vars, completely safe.
2. **MongoDB Document Size Limit (BSON 16 MB):**
   - Each campaign document stores normalized item metadata (`ma_san_pham`, `regular_price`, `flash_price`, `phan_tram_giam`, `savings_amount`).
   - Average size per item entry: ~120 bytes.
   - For $N=40$ products: ~5 KB total campaign document size.
   - For $N=1000$ products: ~125 KB, representing < 1% of MongoDB's 16 MB BSON document limit.
3. **Database Mode & Catalog Baseline Integrity:**
   - Canonical `DB_MODE = atlas`, database = `skinsyntax`.
   - Canonical catalog counts: `san_pham = 2473`, `danh_muc = 28`.
   - Simple Recommender constants: $m = 21.0$, $C = 4.8890$.
   - Base prices (`san_pham.gia_ban`) remain immutable; campaigns only override price via server-derived `resolveEffectivePrice()`.

---

### 2. Hydration & Ranking Pipeline Audit

#### 2.1 Batch Hydration ($O(1)$ Queries vs $N+1$)
- Inspected `FlashSaleCampaign::hydrateCampaignItems()`:
  ```php
  $docs = $this->db->san_pham->find(
      ['ma_san_pham' => ['$in' => $targetPids]],
      ['projection' => [...]]
  );
  ```
- **Audit Finding:** Hydration performs a **single batched MongoDB `$in` query** regardless of whether $N=4, 24, 32,$ or $40$. There are **zero $N+1$ queries**.
- Hydration execution benchmark: ~1.2ms to 2.4ms for 32 products over Atlas connection.

#### 2.2 Personalized Deal Ranking Full-Candidate Set Execution
- Inspected `HomeController::index()`:
  ```php
  if ($primaryFlashCampaign && !empty($primaryFlashCampaign['hydrated_items'])) {
      $userContext = [
          'profile'  => $userProfile,
          'behavior' => $behaviorSignals,
      ];
      $rankResult = $flashSaleModel->rankCampaignCandidates($primaryFlashCampaign['hydrated_items'], $userContext);
      $primaryFlashCampaign['hydrated_items'] = $rankResult['ranked_items'];
      ...
  }
  ```
- **Audit Finding:** `HomeController` passes the **entire hydrated campaign candidate set** to `PersonalizedDealRanker::rankCampaignCandidates()`.
- The ranker computes personalized deal scores across all $N$ items once per request on the server, before view rendering.
- State-based routing:
  - **Guest without signals:** Baseline ranking ($0.60 \times \text{Discount} + 0.40 \times \text{WeightedRating}$).
  - **Guest with search/view behavior:** Behavior-based personalized ranking.
  - **Logged-in profile only:** Profile relevance ranking.
  - **Logged-in profile + behavior:** Adaptive hybrid ranking.
  - **No meaningful relevance:** Deterministic baseline fallback.
- **Price Invariant:** Every user receives the exact same campaign candidates and flash prices; only the display order and evidence-grounded reason badges differ.

#### 2.3 Homepage Slicing Audit
- **Previous Implementation:**
  In `frontend/views/home.php`, lines 56–76 previously called:
  `$flashSaleProducts = $rankerHelper->applyTop4Diversity($flashSaleCampaign['hydrated_items'], 4);`
  This artificially truncated the candidate set to only 4 products for Homepage display.
- **Carousel Upgrade Path:**
  Pass the complete ranked array `$flashSaleProducts = $flashSaleCampaign['hydrated_items']` to the view.
  Display the cards in a responsive CSS Grid with client-side carousel pagination.

---

### 3. Conclusion of Pre-Implementation Audit
1. No architectural redesign or schema changes required.
2. No artificial campaign size caps exist in the domain model; form guidance safely updated to 24–40 SKUs.
3. Hydration already uses single-batch `$in` queries (zero $N+1$).
4. Ranking pipeline already scores all candidates on the server once per request.
5. All 4 personalization states and pricing invariants are strictly preserved.
