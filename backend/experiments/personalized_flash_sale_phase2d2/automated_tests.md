# Phase 2D.2: Automated Test Execution Log

**Execution Date:** 2026-10-08  
**Runner:** `php scratch/test_phase2d2_automation.php`  
**Result:** **24/24 PASS (100%)**

```text
=========================================================
PHASE 2D.2: AUTOMATED REGRESSION & INTEGRITY TEST SUITE
=========================================================
[PASS] TEST_A: Guest uses baseline mode - ranking_mode: BASELINE
[PASS] TEST_B: Behavior context uses behavior mode - ranking_mode: BEHAVIOR
[PASS] TEST_C: Profile context uses profile mode - ranking_mode: PROFILE
[PASS] TEST_D: Hybrid context uses hybrid mode - ranking_mode: HYBRID
[PASS] TEST_E: No useful relevance falls back to baseline - ranking_mode: BASELINE
[PASS] TEST_F_G: Campaign boundary respected, 0 outside SKUs injected - Candidate count: 16
[PASS] TEST_H: Same SKU same price across all user contexts - Verified across 16 SKUs in 4 contexts
[PASS] TEST_I: Cart SKU remains eligible in ranked deals - Cart SKU retained
[PASS] TEST_J: Viewed SKU remains eligible in ranked deals - Viewed SKU retained
[PASS] TEST_K_L_M: No CF weights, no so_luong_da_ban used in ranker - Decoupled from CF and fake popularity
[PASS] TEST_N: Effective flash price used for budget proximity - Verified in PersonalizedDealRanker.php
[PASS] TEST_O: Normalization handles zero-range (max == min) safely - No division by zero
[PASS] TEST_P: Deterministic ranking verified - Identical ranking order across calls
[PASS] TEST_Q: Homepage displays max 4 products with diversity - Top 4 count: 4
[PASS] TEST_R: All Deals retains full campaign candidate set - Full candidate count: 16
[PASS] TEST_S: Explicit discount sort is purely monotonic and non-personalized - Monotonic descending discount verified
[PASS] TEST_T: Explicit price sort uses effective flash price - Monotonic ascending effective price verified
[PASS] TEST_U: Reason tags attached and grounded in evidence - Tags present on all ranked candidates
[PASS] TEST_V: No deceptive percent-match UI - Verified in view templates
[PASS] TEST_W: Ranker handles null/failure gracefully - Graceful fallback confirmed
[PASS] TEST_X: Simple Recommender m=21.0 and C=4.8890 preserved - Verified in SanPham.php
=========================================================
AUTOMATED SUITE RESULT: ALL 24 TESTS PASS (24/24)
=========================================================
```
