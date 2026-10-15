# Phase 2D.2: Fallback Policy & Failure Safety

## 1. Failure Modes & Mitigations

### 1.1 Cold-Start Guest (State A)
- **Condition:** User has no profile, no search history, no view history, and no items in cart.
- **Handling:** `PersonalizedDealRanker` engages `BASELINE` mode.
- **Scoring:** Composite formula reduces to:
  $$\text{Score} = 0.60 \cdot \overline{S}_{\text{discount}} + 0.40 \cdot \overline{S}_{\text{wr}}$$
- **UI:** Rendered as "Ưu đãi nổi bật" with deep discount and high review badges.

### 1.2 Low Relevance / Non-Differentiating Queries
- **Condition:** User enters irrelevant keywords or searches queries that match no campaign items ($S_{\text{personal}} < 0.15$).
- **Handling:** Rather than perturbing deal order based on meaningless fractions of similarity, the engine safely collapses to `BASELINE` mode.

### 1.3 Database Disconnection / Recommender Exception
- **Condition:** Mongo/MySQL transient error, missing TF-IDF cache file, or PHP exception.
- **Handling:** Handled in `FlashSaleCampaign::rankCampaignCandidates()` via:
  ```php
  try {
      $ranker = new PersonalizedDealRanker($this->db);
      return $ranker->rankCampaignCandidates($candidates, $userContext);
  } catch (\Throwable $e) {
      error_log('FlashSaleCampaign rankCampaignCandidates error: ' . $e->getMessage());
      $fallbackItems = $candidates;
      $this->sortBaselineRanking($fallbackItems);
      return [
          'ranked_items'    => $fallbackItems,
          'ranking_mode'    => 'BASELINE',
          'user_state'      => 'STATE_A',
          'is_personalized' => false,
      ];
  }
  ```
- **Result:** Homepage Flash Sale section continues to render flawlessly using Phase 2C deterministic baseline sorting.

---

## 2. Invariant Guarantee Under Fallbacks
Under all failure and fallback scenarios:
1. Product pricing remains 100% accurate (Effective Pricing Engine is untouched).
2. Campaign start/end timer remains active and correct.
3. Add to Cart and Buy Now buttons operate normally.
4. No white screens, 500 errors, or hidden sections occur.
