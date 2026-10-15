# SKINSYNTAXVN — FLASH SALE PERSONALIZED CAROUSEL UX EXTENSION
## Automated Test Results: Candidate Sizes, Ranking Preservation & Price Invariants

**Date:** 2026-10-08  
**Test Suite:** `scratch/test_flash_sale_carousel.php`  
**Total Tests:** 22  
**Result:** **22 PASS, 0 FAIL (100% Pass Rate)**

---

### 1. Canonical Database & Safety Baseline

| Check ID | Description | Expected | Actual | Status |
|:---|:---|:---|:---|:---:|
| `CANON_01` | Canonical `san_pham` count | 2473 | 2473 | **PASS** |
| `CANON_02` | Canonical `danh_muc` count | 28 | 28 | **PASS** |
| `CANON_03` | Simple Recommender constants | $m=21.0, C=4.8890$ | $m=21.0, C=4.8890$ | **PASS** |
| `CANON_04` | DB_MODE environment | `atlas` | `atlas` | **PASS** |

---

### 2. Edge Case Candidate Sizes ($N = 0, 1, 4, 7, 8, 9, 20, 32, 40, 41$)

For every tested campaign size:
- **Desktop Page Size:** 8 cards (2 rows $\times$ 4 cols).
- **Tablet Page Size:** 4 cards (2 rows $\times$ 2 cols).
- **Mobile Page Size:** 2 cards (1 row $\times$ 2 cols).
- **Preservation Check:** Every campaign SKU appears exactly once across carousel pages; **zero outside padding** with non-campaign products.

| $N$ | Desktop Pages ($\lceil N/8 \rceil$) | Tablet Pages ($\lceil N/4 \rceil$) | Mobile Pages ($\lceil N/2 \rceil$) | SKU Preservation | Incomplete Final Page | Status |
|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **0** | 1 (empty) | 1 (empty) | 1 (empty) | 0 collected | N/A | **PASS** |
| **1** | 1 | 1 | 1 | Exact 1 SKU | Page 1: 1 card | **PASS** |
| **4** | 1 | 1 | 2 | Exact 4 SKUs | Page 1: 4 cards | **PASS** |
| **7** | 1 | 2 | 4 | Exact 7 SKUs | Page 1: 7 cards | **PASS** |
| **8** | 1 | 2 | 4 | Exact 8 SKUs | Full page | **PASS** |
| **9** | 2 | 3 | 5 | Exact 9 SKUs | Page 2: 1 card | **PASS** |
| **20** | 3 | 5 | 10 | Exact 20 SKUs | Page 3: 4 cards | **PASS** |
| **32** | 4 | 8 | 16 | Exact 32 SKUs | Full page (8 per page) | **PASS** |
| **40** | 5 | 10 | 20 | Exact 40 SKUs | Full page (8 per page) | **PASS** |
| **41** | 6 | 11 | 21 | Exact 41 SKUs | Page 6: 1 card | **PASS** |

---

### 3. Full Candidate Set Personalized Ranking ($N = 32$)

Tested with 32 real products from the canonical catalog across all 4 user states:

| User State | Context Configuration | Ranking Mode | Returned SKUs | Personalization Status |
|:---|:---|:---:|:---:|:---:|
| **State A: Guest** | No profile, no behavior signals | `BASELINE` | 32 / 32 | `is_personalized = false` |
| **State B: Behavior** | Guest with search ('mun') & view signals | `BEHAVIOR` | 32 / 32 | `is_personalized = true` |
| **State C: Profile** | User with skin type & skin concern profile | `PROFILE` | 32 / 32 | `is_personalized = true` |
| **State D: Hybrid** | Profile + cart item & recent view | `HYBRID` | 32 / 32 | `is_personalized = true` |

---

### 4. Pricing Invariant Verification

- Checked all 32 candidate products across all 4 ranking states (State A, B, C, D).
- **Result:**
  - `flash_price` and `effective_price` are **100% identical** for every SKU regardless of whether the user is a Guest, has behavioral history, or has a completed skin profile.
  - Zero price discrimination or tampering.
  - Base catalog prices (`san_pham.gia_ban`) remain completely unaltered.

---

### 5. Regression Test Results

1. **Phase 2D.2 Regression (`scratch/test_phase2d2_automation.php`):**
   - **Result:** 24 / 24 tests passed.
   - Covers: Baseline, Behavior, Profile, Hybrid routing; tie-breaking; zero CF weights; zero fake sales counts; effective flash price for budget proximity; reason tag attachment; Simple Recommender constants.
2. **Phase 2E Regression (`scratch/run_phase2e_audit.php`):**
   - **Result:** 54 / 54 tests passed.
   - Covers: End-to-end admin lifecycle, public homepage detection, product detail, cart resolution, price tampering protection, campaign expiry, campaign disable, RBAC enforcement.
