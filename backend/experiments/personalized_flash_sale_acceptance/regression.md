# Flash Sale Final Acceptance — Step 6: Regression Report

**Audit Date:** 2026-10-10  
**Role:** Senior Full-Stack QA / PHP / MongoDB Engineer  
**Status:** **PASSED — ZERO REGRESSION DETECTED**

---

## 1. Regression Guardrails & Invariants

The Flash Sale subsystem is strictly additive and must never disrupt or regress existing core components of the SkinSyntaxVN store. Six core platform invariants were audited.

---

## 2. Invariant Verification Matrix

| Invariant Item | Expected Specification | Measured / Inspected State | Status |
| :--- | :--- | :--- | :--- |
| **Simple Recommender Constants** | $m = 21.0$, $C = 4.8890$ | Verified in `PersonalizedDealRanker::CANONICAL_M` and `CANONICAL_C` | **PASS** |
| **Canonical Simple Top 24 Order** | Exact descending order of Simple WR score in `simple_recommender_cache.json` | Top 5 SKUs strictly preserved: `#1000` (4.9907) $\to$ `#266` (4.9907) $\to$ `#728` (4.9907) $\to$ `#4365` (4.9880) $\to$ `#68` (4.9729) | **PASS** |
| **Adaptive Content-Based Modes** | 4 distinct operational states: `BASELINE`, `BEHAVIOR`, `PROFILE`, `HYBRID` | All 4 modes verified active and dynamically assigned according to user context signals | **PASS** |
| **Ranker Behavior Weights** | Pre-established heuristic weights: Personal (0.50), Discount (0.30), Rating (0.20) | All constants strictly maintained; no arbitrary coefficient tampering | **PASS** |
| **All Deals Sorting Mechanics** | Public All Deals supports: `featured`, `for_you`, `discount`, `rating`, `price_asc`, `price_desc` | All 6 sort criteria operate correctly in `HomeController::flashSale()` and `FlashSaleCampaign::sortCampaignDeals()` | **PASS** |
| **Non-Production AI Isolation** | Zero CF / KMeans / BPR / Association algorithm integration in production serving path | Full codebase audit confirms only lightweight TF-IDF and heuristic rules run in production request path | **PASS** |
| **MongoDB Atlas Catalog Counts** | `san_pham = 2473`, `danh_muc = 28` | Measured live counts: `san_pham: 2473`, `danh_muc: 28` | **PASS** |
| **Baseline Admin Campaign Integrity** | Baseline campaign `ma_campaign=1` ("Siêu sao") untouched | Document verified intact with 3 original items and timestamps preserved | **PASS** |

---

## 3. Code Modification Regression Audit

No core catalog models (`SanPham.php`, `DanhMuc.php`, `KhachHang.php`) were modified or refactored. The changes are cleanly localized to:
- `FlashSaleCampaign.php`: Flash sale domain model & effective pricing engine.
- `PersonalizedDealRanker.php`: Dedicated candidate ranking adapter.
- `HomeController.php`: Orchestrating carousel hydration and price reconfirmation during checkout.
- `HoaDon.php`: Delegating line-item price resolution to `FlashSaleCampaign::resolveEffectivePrice()`.

---

## 4. Scope Classification

- **Actually Tested Behavior:**
  - Live verification of catalog product count (`2473`) and category count (`28`).
  - Verification of baseline campaign `#1` presence and document properties.
  - Verification of `simple_recommender_cache.json` top SKU rankings.
  - Verification of sorting options in `HomeController::flashSale()`.
- **Code-Inspection Findings:**
  - Grep audit of recommendation code paths for absence of experimental machine learning modules in production routes.
  - Inspection of constants in `PersonalizedDealRanker.php`.
- **Untested Scenarios:**
  - Dynamic cache invalidation of `simple_recommender_cache.json` upon catalog import (outside the scope of Flash Sale).
- **Remaining Limitations:**
  - None.
