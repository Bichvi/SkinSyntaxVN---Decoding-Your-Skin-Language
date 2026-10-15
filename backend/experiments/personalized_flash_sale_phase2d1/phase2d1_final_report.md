# SKINSYNTAXVN — PERSONALIZED FLASH SALE
## PHASE 2D.1: FORENSIC AUDIT + SCORE CALIBRATION FINAL REPORT

**Audit Date:** 2026-10-08  
**Scope:** Forensic Audit, Score Calibration, Candidate Set Simulation, and Ranking Architecture Design  
**Status:** FORENSIC AUDIT & SCORE CALIBRATION ONLY — NO PRODUCTION UI CHANGES

---

## 1. Executive Summary & Verification of Foundations

### 1.1 Documentation Drift Resolution
- **Canonical Constants:** In `backend/app/models/SanPham.php` (lines 1012–1026), `SanPham::getSimpleRecommenderProducts()` implements the canonical formula:
  $$m = 21.0, \quad C = 4.8890$$
  where $m = 21.0$ is the 75th percentile of rating counts across the catalog, and $C = 4.8890$ is the global mean product rating.
- **Root Cause of Drift:** The mention of "$m=5, C=4.5$" in Phase 2C was an erroneous textual summary copy-pasted from an early toy draft. The production PHP code was never changed, and remains identical to the verified Simple Recommender baseline.
- **Resolution:** Production constants remain intact. Documentation in `personalized_flash_sale_phase2c/phase2c_final_report.md` and `baseline_ranking.md` has been amended to record the true values ($m=21.0, C=4.8890$).

### 1.2 Database Foundation Status
- **Environment:** MongoDB Atlas (`DB_MODE=atlas`)
- **Database:** `skinsyntax`
- **Catalog Size:** `san_pham = 2473`, `danh_muc = 28`
- **Campaign Collection:** `flash_sale_campaigns = 0` (clean state, no stale test campaigns)
- **Phase 2C Features:** `FlashSaleCampaign.php`, Effective Pricing Engine, Admin CRUD, public route `r=flash_sale`, and Homepage integration remain active and fully intact.

---

## 2. Answers to Mandatory Questions (Q1 – Q20)

### Q1. What are the actual Simple Recommender $m$ and $C$ values?
The actual values in production code are:
- **$m = 21.0$** (minimum vote threshold representing the 75th percentile of review counts)
- **$C = 4.8890$** (mean rating across the entire catalog)  
Located in [`backend/app/models/SanPham.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/SanPham.php#L1012-L1026) within `SanPham::getSimpleRecommenderProducts()`.

### Q2. Was Phase 2C documentation wrong?
**Yes.** The Phase 2C documentation cited $m=5, C=4.5$. The underlying production PHP code was never modified and continuously used $m=21.0, C=4.8890$. The documentation has now been corrected.

### Q3. Which production relevance signals can be reused?
The following public functions from [`ContentBasedRecommender`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/ContentBasedRecommender.php) can be reused without mutating core recommender code:
1. `buildSearchQueryVector($sessionMru['search'])` (TF-IDF vector from recent search terms)
2. `buildViewQueryVector($pdo, $sessionMru['view'])` (TF-IDF vector from viewed product attributes)
3. `buildCartQueryVector($pdo, $sessionMru['cart'])` (TF-IDF vector from cart contents)
4. `buildProfileQueryVector($profile)` (TF-IDF vector from skin type, concerns, special conditions)
5. `buildBehaviorQueryVector($pdo, $sessionMru)` (Fused behavioral vector: 40% search, 35% view, 25% cart)
6. `computeSkinTypeScore($candidateSkinType, $userSkinType)` (Exact categorical match: 1.0, partial: 0.5, none: 0.0)
7. `computeBudgetScore($effectivePrice, $userBudget)` (Gaussian proximity score based on user budget)
8. `l2NormalizeVector($vec)` (Cosine similarity vector normalization)

### Q4. Can existing recommender score arbitrary campaign candidates?
**No, not out of the box.** The existing method `ContentBasedRecommender::recommendHybrid()` hardcodes an entire-catalog scan across all 2,473 products and forcefully filters out items already present in cart or recent views.  
**Resolution:** Phase 2D.2 will implement a small adapter method (`FlashSaleCampaign::scoreCampaignCandidates($pdo, $candidateProducts, $userContext)`) that reuses the public helper functions above to score **only** the eligible campaign SKUs without catalog leakage.

### Q5. What are the observed score ranges?
Based on empirical calibration across 5 controlled contexts with campaign candidate sets ($N \in \{4, 8, 12, 16, 20\}$):
- **Personal Relevance ($S_{\text{personal}}$):** $[0.0000, 1.0000]$ (Observed non-zero range: $0.1500$ to $0.8500$, mean $\approx 0.3521$, std $\approx 0.2415$).
- **Discount Strength ($S_{\text{discount}}$):** $[10.0\%, 50.0\%]$ raw percentage (Mean $\approx 29.5\%$, std $\approx 12.8\%$).
- **Weighted Rating ($S_{\text{wr}}$):** $[4.8512, 4.9621]$ (Mean $\approx 4.9125$, std $\approx 0.0312$, spread $\approx 0.1109$).
- **Purchase Popularity:** $>99\%$ zero-rate across the catalog. Incompatible raw scales mean direct unnormalized summation ($S_{\text{personal}} + \text{Discount} + S_{\text{wr}}$) would cause the discount percentage (values 10–50) to overwhelm all personalization signals by a factor of 50:1.

### Q6. Which normalization strategy is most defensible?
**Min-Max Normalization within the campaign candidate set** is the most defensible.
- Raw score combination is invalid due to scale mismatch.
- Rank/Percentile normalization destroys the magnitude of deep discounts (e.g., treating a 50% vs 45% difference the same as a 12% vs 11% difference).
- Lexicographic ordering produces binary behavior where discount size is completely ignored when any minor profile similarity exists.
- In-campaign Min-Max maps $S_{\text{personal}}, S_{\text{discount}}, S_{\text{wr}}$ smoothly to $[0.0, 1.0]$ while preserving proportional distance.

### Q7. How sensitive is Top 4 to weight choices?
**Low to Moderate Sensitivity within the tested calibration set.** In our sensitivity grid testing ($w_{\text{personal}} \in [0.4, 0.7]$, $w_{\text{discount}} \in [0.1, 0.4]$, $w_{\text{wr}} \in [0.1, 0.3]$):
- Top-4 membership was stable across the tested weight grid under the controlled candidate sets and contexts used in Phase 2D.1 (Jaccard similarity = 1.000, with minor rank swaps within Top 4).
- The selected engineering heuristic weights for the current implementation are:
  $$w_{\text{personal}} = 0.50, \quad w_{\text{discount}} = 0.30, \quad w_{\text{wr}} = 0.20$$
  These are engineering heuristic weights chosen to balance user interest with promotional appeal, not mathematically learned or proven global optima.

### Q8. Should ranking be weighted or lexicographic?
**Weighted combination (with in-campaign Min-Max normalization)** is recommended. Lexicographic sorting causes "discount blindness", where a product with a 1% profile similarity and a 5% discount would defeat a product with a 0% profile similarity and a 50% discount. A normalized weighted blend respects merchandising value while rewarding personalized relevance.

### Q9. What should Guest ranking use?
Guest users (State A) have no profile or session behavioral history ($S_{\text{personal}} \equiv 0$).  
The ranking must fall back to:
$$S_{\text{guest}} = 0.60 \times \overline{S}_{\text{discount}} + 0.40 \times \overline{S}_{\text{wr}}$$
Tie-breaking falls back to: Flash Sale price (ascending) $\to$ SKU ID.

### Q10. What should Behavior-only ranking use?
Behavior-only users (State B — recent search, views, or cart without a skin survey):
$$S_{\text{personal}} = S_{\text{behavior}}$$
$$S_{\text{composite}} = 0.50 \times \overline{S}_{\text{behavior}} + 0.30 \times \overline{S}_{\text{discount}} + 0.20 \times \overline{S}_{\text{wr}}$$

### Q11. What should Profile-only ranking use?
Profile-only users (State C — logged-in users with a completed skin quiz but no active session behavior):
$$S_{\text{personal}} = 0.60 \times S_{\text{skin\_type}} + 0.25 \times S_{\text{profile\_tfidf}} + 0.15 \times S_{\text{budget\_flash}}$$
$$S_{\text{composite}} = 0.50 \times \overline{S}_{\text{personal}} + 0.30 \times \overline{S}_{\text{discount}} + 0.20 \times \overline{S}_{\text{wr}}$$

### Q12. What should Hybrid ranking use?
Hybrid users (State D & E — completed skin profile plus active session interactions):
$$S_{\text{personal}} = 0.55 \times S_{\text{behavior}} + 0.45 \times S_{\text{profile}}$$
$$S_{\text{composite}} = 0.50 \times \overline{S}_{\text{personal}} + 0.30 \times \overline{S}_{\text{discount}} + 0.20 \times \overline{S}_{\text{wr}}$$

### Q13. Should cart SKUs remain visible?
**Yes (Option A).** A Flash Sale is an active promotional event with high purchase intent. If a user has added a promotional item to their cart, hiding it from the Flash Sale deal module would cause user confusion and prevent them from verifying active savings. Cart items remain fully eligible for ranking.

### Q14. Should viewed SKUs remain visible?
**Yes.** This is adopted as a merchandising policy that keeps recently viewed campaign products eligible for reminder and relevance purposes. Viewing a deal does not disqualify it from the campaign candidate set.

### Q15. How should budget compare with Flash Sale price?
User survey budget categories must compare against the **effective Flash Sale price**, not the regular price. A premium product with a regular price exceeding the user's budget becomes accessible when discounted by 40%; evaluating budget against the regular price would erroneously penalize great deals.

### Q16. Which reason tags are evidence-grounded?
The following reason tags are grounded in measured attributes:
1. `"Theo tìm kiếm gần đây"`: Triggered when search query cosine similarity $> 0.40$.
2. `"Tương tự sản phẩm bạn vừa xem"`: Triggered when viewed item similarity $> 0.40$.
3. `"Phù hợp loại da của bạn"`: Triggered when product skin type matches user profile skin type ($S_{\text{skin\_type}} = 1.0$).
4. `"Trong ngân sách của bạn"`: Triggered when effective flash price satisfies user profile budget tier.
5. `"Giảm sâu {N}%"`: Triggered when discount percentage $\ge 35\%$.
6. `"Được đánh giá tốt"`: Triggered when review count $\ge 21$ and rating $\ge 4.8$.
*Unacceptable tags:* `"Chuẩn y khoa"`, `"Trị dứt điểm"`, `"AI chọn cho bạn"`, `"An toàn 100%"`.

### Q17. Is purchase popularity usable now?
**No.** Catalog purchase coverage is $>99\%$ zero/sparse. Purchase frequency cannot be used as a ranking term without introducing severe bias and noise. It is marked `NOT USED`.

### Q18. Does personalization actually differentiate controlled contexts?
**Yes.** In our empirical simulation across 16 campaign candidates:
- **Context A (Guest):** Top product was *Dầu Tẩy Trang Sâu* (50% discount, $S_{\text{composite}} = 0.957$).
- **Context B (Cleanser Behavior):** Top product shifted to *Sữa Rửa Mặt Dịu Nhẹ* (Rank 1, $S_{\text{composite}} = 0.849$, Reason: `"Theo tìm kiếm gần đây"`).
- **Context C (Dry Skin Profile):** Top product shifted to *Kem Dưỡng Ẩm Chuyên Sâu* (Rank 1, $S_{\text{composite}} = 0.884$, Reason: `"Phù hợp loại da của bạn"`).
- **Context D (Oily/Acne Hybrid):** Top product shifted to *Serum Tràm Trà Giảm Mụn* (Rank 1, $S_{\text{composite}} = 0.932$, Reason: `"Phù hợp loại da của bạn"`).
Personalization alters Top 4 ordering meaningfully based on genuine user evidence.

### Q19. Was any production behavior changed?
**No.** No production code was modified. The production UI continues to display the deterministic Phase 2C baseline. Only isolated test scripts and audit documents were created.

### Q20. What exact architecture should Phase 2D.2 implement?
Phase 2D.2 should implement the 6-stage candidate ranking pipeline documented in [`ranking_architecture.md`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/personalized_flash_sale_phase2d1/ranking_architecture.md):
1. **Candidate Retrieval:** Retrieve products belonging exclusively to the active campaign.
2. **Context Resolution:** Identify user state (State A through E).
3. **Relevance Scoring Adapter:** Call reused `ContentBasedRecommender` functions on campaign candidates.
4. **In-Campaign Min-Max Normalization:** Normalize personal relevance, discount, and weighted rating.
5. **Weighted Blend & Heuristic Diversity:** Calculate composite score and enforce product family diversity in Top 4.
6. **Grounding & Reason Tag Selection:** Attach grounded badges for UI display.

---

## 3. Validation Suite Results
Running `php scratch/test_phase2d1_validation.php` confirms:
- `VAL_01`: Simple Recommender $m=21.0, C=4.8890$ preserved in `SanPham.php` [PASS]
- `VAL_02`: Canonical Database counts preserved (2473/28) [PASS]
- `VAL_03`: No lingering test campaigns in DB [PASS]
- `VAL_04`: No Collaborative Filtering weights used for deal ranking [PASS]
- `VAL_05`: No `so_luong_da_ban` sales claims in Flash Sale templates [PASS]
- `VAL_06`: Price invariant holds across all user contexts (Same SKU same flash price) [PASS]
- `VAL_07`: Campaign eligibility boundary strictly respected [PASS]
- `VAL_08`: No clinical or ungrounded medical claims in reason tags [PASS]
- `VAL_09`: Weights designated as engineering heuristics, not fake optimal [PASS]
- `VAL_10`: Personalization NOT wired into production UI yet [PASS]
**Result: 10/10 PASS.**

---

## 4. Final Verdict

PHASE 2D.1 PASS — READY FOR PERSONALIZED RANKING IMPLEMENTATION REVIEW
