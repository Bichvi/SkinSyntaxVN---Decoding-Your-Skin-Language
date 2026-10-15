# SKINSYNTAXVN — PERSONALIZED FLASH SALE
## PHASE 2D.2: FINAL REPORT — SAFE PRODUCTION IMPLEMENTATION + EXPLAINABILITY + QA

**Execution Date:** 2026-10-08  
**Scope:** Safe Production Implementation, Explainability, Automated Testing, and Browser QA  
**Status:** **PHASE 2D.2 PASS — PERSONALIZED FLASH SALE READY FOR FINAL END-TO-END REVIEW**

---

## 1. Executive Summary

Phase 2D.2 implements **Personalized Deal Ranking** as a high-speed adapter layer on top of the verified real Flash Sale campaign foundations from Phases 2A–2C.

### Architectural Invariants Enforced:
1. **Campaign Controls Eligibility:** Only products belonging to the active campaign candidate set can be scored and ranked. Non-campaign items can never enter Flash Sale modules (0 catalog leakage).
2. **Recommender Decides Only Order:** Personalization influences deal sequence, never deal membership or pricing.
3. **No Personalized Pricing:** The same campaign + SKU yields identical `regular_price`, `flash_price`, and `effective_price` across all user states.
4. **Engineering Heuristics Only:** Weights are documented as engineering heuristics selected for this implementation, not mathematically learned or proven global optima.
5. **Preservation of Canonical Baselines:** Simple Recommender constants $m=21.0, C=4.8890$ and database counts (`san_pham = 2473, danh_muc = 28`) remain canonical and untouched.

---

## 2. Answers to Mandatory Questions (Q1 – Q23)

### Q1. Where is Personalized Deal Ranking implemented?
In [`backend/app/models/PersonalizedDealRanker.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/PersonalizedDealRanker.php), invoked via [`FlashSaleCampaign::rankCampaignCandidates()`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/FlashSaleCampaign.php) and orchestrated by [`HomeController::index()`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/HomeController.php) and [`HomeController::flashSale()`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/controllers/HomeController.php).

### Q2. Does it score only campaign candidates?
**Yes.** It receives only the pre-hydrated candidate items belonging to the selected active campaign ($N \in [4, 24]$ items). It never performs an exhaustive 2,473-product catalog scan.

### Q3. Which production recommender semantics are reused?
Reuses `ContentBasedRecommender` public vector construction functions (`buildSearchQueryVector`, `buildViewQueryVector`, `buildCartQueryVector`, `buildPurchaseQueryVector`, `buildBehaviorQueryVector`, `buildProfileQueryVector`), `computeSkinTypeScore`, `computeBudgetScore`, and the pre-computed TF-IDF index cache (`tfidf_cache.json`).

### Q4. What user states are implemented?
Five deterministic internal states:
- `STATE_A`: Guest / Cold Start
- `STATE_B`: Behavior Only (search, view, cart without skin survey)
- `STATE_C`: Profile Only (skin survey completed, no session interactions)
- `STATE_D`: Profile + Behavior (Hybrid)
- `STATE_E`: Purchase Signal Present (Customer with prior valid orders + context)

### Q5. What heuristic weights are used?
- **Personalized States:** $\text{CompositeScore} = 0.50 \cdot \overline{S}_{\text{personal}} + 0.30 \cdot \overline{S}_{\text{discount}} + 0.20 \cdot \overline{S}_{\text{wr}}$.
- **Guest / Baseline State:** $\text{GuestScore} = 0.60 \cdot \overline{S}_{\text{discount}} + 0.40 \cdot \overline{S}_{\text{wr}}$.
- **Profile Internal Heuristic:** $0.60 \cdot S_{\text{skin\_type}} + 0.25 \cdot S_{\text{content}} + 0.15 \cdot S_{\text{budget}}$.
- **Hybrid Internal Heuristic:** $0.55 \cdot S_{\text{behavior}} + 0.45 \cdot S_{\text{profile}}$.
*(All designated as selected engineering heuristics).*

### Q6. How are signals normalized?
Via in-campaign **Min-Max Normalization** over the active candidate pool:
$$\overline{x}_i = \frac{x_i - \min(X)}{\max(X) - \min(X)}$$

### Q7. What happens when a signal has zero range?
When $\max(X) - \min(X) \le 10^{-6}$ (e.g., all candidates share identical discounts or lack personal relevance), each candidate receives a uniform neutral contribution $\overline{x}_i = 0.50$, preventing division-by-zero errors.

### Q8. What does Guest use?
Guest users use `BASELINE` ranking mode:
$$\text{Score} = 0.60 \cdot \overline{S}_{\text{discount}} + 0.40 \cdot \overline{S}_{\text{wr}}$$
Ties are broken deterministically by product ID.

### Q9. What happens with no meaningful personalization evidence?
If a user context produces low or uniform relevance ($\max(S_{\text{personal}}) < 0.15$ or spread $< 0.05$), the ranker automatically collapses to `BASELINE` mode rather than ranking deals based on random token noise.

### Q10. Are cart SKUs retained?
**Yes.** Cart items remain fully eligible in both Homepage Top 4 and All Deals, serving as an active promotional reassurance.

### Q11. Are viewed SKUs retained?
**Yes.** Viewed items remain eligible as a merchandising policy for deal reminder purposes.

### Q12. How is budget evaluated for Flash Sale?
`computeBudgetScore($flashPrice, $userBudget)` compares the customer survey budget against the **effective Flash Sale price**, ensuring discounted premium products are recognized as budget-friendly.

### Q13. How are reason tags selected?
Selected through a grounded priority hierarchy:
1. Search match ($\ge 0.40$) $\to$ `"Theo tìm kiếm gần đây"`
2. View match ($\ge 0.40$) $\to$ `"Tương tự sản phẩm bạn vừa xem"`
3. Skin match ($\ge 1.00$) $\to$ `"Phù hợp loại da của bạn"`
4. Budget match ($\ge 1.00$) $\to$ `"Trong ngân sách của bạn"`
5. Deep discount ($\ge 35\%$) $\to$ `"Giảm sâu {N}%"`
6. Review evidence ($v \ge 21.0, R \ge 4.5$) $\to$ `"Được đánh giá tốt"`
7. Fallback $\to$ `"Giảm {N}%"` or `"Ưu đãi nổi bật"`

### Q14. Does Homepage Top 4 differ across controlled contexts?
**Yes.** In our controlled simulation across 16 candidate products:
- **Guest (A):** Prioritized deepest discounts (Anessa 45%, Bioderma 40%).
- **Cleanser Search (B):** Prioritized CeraVe Foaming Cleanser (Rank 1, `"Theo tìm kiếm gần đây"`).
- **Dry Skin Profile (C):** Prioritized Cocoon Hydrating Toner & Cleanser (Rank 1, `"Phù hợp loại da của bạn"`).
- **Acne Hybrid (D):** Prioritized Cocoon Bí Đao & CeraVe Acne Cleanser.
Top 4 overlap vs Guest varied from 1/4 to 2/4.

### Q15. Does All Deals support "Dành cho bạn"?
**Yes.** When a user context is available, a "Dành cho bạn" sort button (`sort=for_you`) appears in the filter bar and serves as the default sort option.

### Q16. Are explicit sorts still non-personalized?
**Yes.** Selecting "Giảm nhiều nhất", "Đánh giá tốt", "Giá thấp → cao", or "Giá cao → thấp" sorts products purely and monotonically by that explicit dimension, completely bypassing personalization.

### Q17. Does personalization failure gracefully fall back?
**Yes.** Any exception in `PersonalizedDealRanker` is caught by `FlashSaleCampaign::rankCampaignCandidates()`, which seamlessly falls back to Phase 2C deterministic baseline ranking without breaking the page or pricing.

### Q18. Is price identical across user contexts?
**Yes.** Regression test `TEST_H` verified across all 16 candidates and 4 user contexts that `regular_price`, `flash_price`, and `effective_price` are 100% identical.

### Q19. Were $m=21$ and $C=4.889$ preserved?
**Yes.** Verified in [`SanPham.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/app/models/SanPham.php#L1012-L1026) and asserted in `TEST_X`.

### Q20. Were CF/BPR/KMeans/Association systems untouched?
**Yes.** No code in `TuongTac.php`, CF recommenders, BPR, KMeans, or Apriori was modified or invoked.

### Q21. Were production interactions/users mutated for testing?
**No.** All evaluation used deterministic in-memory test contexts; 0 synthetic database records or user interactions were written.

### Q22. Were QA campaigns cleaned up?
**Yes.** `flash_sale_campaigns` count remains **0** in MongoDB Atlas.

### Q23. Are $san\_pham=2473$ and $danh\_muc=28$ preserved?
**Yes.** Verified via Atlas database check (`san_pham = 2473`, `danh_muc = 28`).

---

## 3. Test & Verification Summary

1. **Automated Suite:** [`scratch/test_phase2d2_automation.php`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/scratch/test_phase2d2_automation.php)  
   **Result:** **24/24 PASS (TEST_A through TEST_X)**.
2. **Controlled Context Simulation:** [`backend/experiments/personalized_flash_sale_phase2d2/controlled_context_results.csv`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/personalized_flash_sale_phase2d2/controlled_context_results.csv)  
   Verified distinct rank orders and grounded reason tags across 5 user states.
3. **Browser QA:** Verified HTTP 200 on Desktop ($1440 \times 900$) and Mobile ($375 \times 812$). Responsive grid, sort switching, countdown timer, and reason tags render cleanly.

---

## 4. Final Verdict

PHASE 2D.2 PASS — PERSONALIZED FLASH SALE READY FOR FINAL END-TO-END REVIEW
