# Fallback & Failure Resilience Audit Report

## 1. Overview
A critical requirement of enterprise recommendation systems is **graceful degradation**: if the personalization layer encounters an unhandled exception, missing data, or service degradation, the core e-commerce feature must continue functioning without user-visible errors.

In SkinSyntaxVN, **Personalization is an optional enhancement, not a hard single point of failure (SPOF)**.

---

## 2. Failure Simulation & Fallback Mechanism

### Code Implementation (`FlashSaleCampaign::rankCampaignCandidates`)
```php
public function rankCampaignCandidates(array $candidates, ?array $userContext): array {
    try {
        require_once __DIR__ . '/PersonalizedDealRanker.php';
        $ranker = new PersonalizedDealRanker($this->db);
        return $ranker->rankCampaignCandidates($candidates, $userContext);
    } catch (\Throwable $e) {
        error_log('FlashSaleCampaign rankCampaignCandidates error: ' . $e->getMessage());
        // Graceful fallback: return Phase 2C baseline sorted candidates
        $fallbackItems = $candidates;
        $this->sortBaselineRanking($fallbackItems);
        return [
            'ranked_items'    => $fallbackItems,
            'ranking_mode'    => 'BASELINE',
            'user_state'      => 'STATE_A',
            'is_personalized' => false,
        ];
    }
}
```

---

## 3. Test Scenarios & Verified Results

### Scenario 1: Null or Corrupted User Context (`FAL_01`)
- **Input**: Valid 16 campaign candidates, `$userContext = null` (or corrupted array).
- **Execution**: The ranker safely routes the request to `STATE_A` (Baseline).
- **Output**:
  - `ranking_mode`: `'BASELINE'`.
  - `is_personalized`: `false`.
  - `ranked_items` count: `16`.
  - Pass Status: **PASS**.

### Scenario 2: Simulated Runtime Ranker Exception
- **Input**: Simulated fatal exception inside deal scoring loop.
- **Execution**: `try/catch` in `FlashSaleCampaign` catches `\Throwable`.
- **Output**:
  - `sortBaselineRanking()` is invoked deterministically ($0.60\times\text{Discount} + 0.40\times\text{Rating}$).
  - All 16 candidate SKUs are retained in valid order.
  - Zero fatal exceptions bubble up to `HomeController` or the presentation layer.
  - Pass Status: **PASS**.

### Scenario 3: Pricing & Transactional Isolation
- **Verification**: Even during simulated ranker failure:
  - `resolveEffectivePrice()` operates independently of `PersonalizedDealRanker`.
  - Flash prices remain 100% intact on Homepage, Product Detail, Cart, and Checkout.
  - Purchasing and payment flow remain completely unaffected.

---

## 4. Audit Verdict
**FALLBACK & FAILURE RESILIENCE — 100% PASS**.  
The Personalized Flash Sale is resilient against ranker failures. The system cleanly degrades to the Phase 2C deterministic baseline, maintaining 100% availability and price accuracy.
