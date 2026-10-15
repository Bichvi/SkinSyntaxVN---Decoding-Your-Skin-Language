# Thesis Evidence & Scientific Evaluation Summary

## 1. Overview & Evaluation Philosophy
This document synthesizes empirical evidence gathered during the implementation and audit of the **Personalized Flash Sale** feature in SkinSyntaxVN. 

For the purposes of thesis defense, academic documentation, and technical demonstrations, it is imperative to maintain rigorous scientific honesty by explicitly separating **established production functional evidence** and **controlled engineering evidence** from **unsupported commercial claims**.

---

## 2. Production Functional Evidence (Established & Proven)

The following capabilities have been empirically verified in the active codebase:

1. **Centralized Effective Pricing Architecture**:
   - The platform routes all pricing queries through a single source of truth: `FlashSaleCampaign::resolveEffectivePrice()`.
   - Verified across all 6 customer touchpoints (Homepage, All Deals, Product Detail, Cart, Checkout Preview, Order Placement).
   - Zero price discrepancies detected. Client-submitted prices are 100% ignored.

2. **Price Invariance Across Personalization**:
   - The system strictly enforces the economic invariant that **personalization alters candidate rank, never price**.
   - Verified across 16 SKUs in 4 user states ($16 \times 4 = 64$ test evaluations): regular price, flash price, effective price, and discount percentage are identical.

3. **In-Flight Expiry & Revenue Protection**:
   - If a campaign expires between checkout preview and order confirmation, `xulydathang()` intercepts the subtotal mismatch, blocks silent placement, and redirects the shopper to reconfirm.

4. **Fault-Tolerant Fallback & Degradation**:
   - Simulated ranker exceptions degrade cleanly to the Phase 2C deterministic baseline ranking ($0.60\times\text{Discount} + 0.40\times\text{Rating}$).
   - The campaign remains 100% accessible to customers even if recommender subroutines fail.

5. **Canonical Engine Preservation**:
   - The canonical Simple Recommender constants ($m=21.0, C=4.8890$) and benchmark Top 24 ranking are 100% preserved.

---

## 3. Controlled Engineering Evidence (Context-Sensitive Differentiation)

The audit demonstrates that the heuristic deal ranker (`PersonalizedDealRanker`) responds coherently to distinct user context states:

| Test Context | Ranking Mode | Top 1 Product | Primary Factor | Overlap vs Guest | Jaccard@4 |
|---|---|---|---|---|---|
| **Guest Baseline** | `BASELINE` | SKU `1772` (B.O.M Cleansing Water) | Max Discount (50%) + WR | 4 / 4 | 1.0000 |
| **Context A (Behavior)**| `BEHAVIOR` | SKU `728` (Eucerin Cleanser) | Recent Cleanser Views & Searches | 1 / 4 | 0.1429 |
| **Context B (Profile)** | `PROFILE` | SKU `62` (Acne Serum) | Da dầu + Proximity to 350k Budget | 1 / 4 | 0.1429 |
| **Context C (Hybrid)** | `HYBRID` | SKU `728` (Eucerin Cleanser) | Cleanser Search + Da dầu Profile | 1 / 4 | 0.1429 |

### Observations:
- **Signal Responsiveness**: Cleanser behavior signals elevated all 4 cleanser candidates (`728`, `1000`, `4365`, `266`) into the Top 4.
- **Candidate Pool Constrained**: All ranked items ($\forall i \in \text{Top 4}$) were drawn strictly from the 16 campaign candidates, proving zero catalog leakage.
- **Diversity**: Top 4 diversity filter ensures maximum category and brand spread across the 4 visible slots.

---

## 4. What Remains NOT Established (Explicit Scientific Boundaries)

The following claims **cannot and must not** be made in the thesis or project documentation:

1. **NO Claim of CTR Uplift**:
   - We have not conducted multi-day live A/B tests with real user traffic measuring click-through rate (CTR) increases.
2. **NO Claim of Conversion Rate Uplift**:
   - We have not established that personalized deal ranking converts at a higher rate than the baseline discount sort.
3. **NO Claim of Revenue / GMV Uplift**:
   - Commercial impact on gross merchandise value remains untested.
4. **NO Claim of Mathematically Optimal Weights**:
   - The weights ($0.50, 0.30, 0.20$ for personalized; $0.60, 0.40$ for guest) are **engineered heuristics**, not weights derived from offline machine learning optimization, gradient descent, or grid search.
5. **NO Claim of Clinical Suitability or Medical Safety**:
   - Recommendations reflect ingredient and skin type similarity mappings from catalog metadata. They are **not** medical diagnoses or dermatological prescriptions.
6. **NO Claim of Collaborative Filtering Superiority**:
   - Flash Sale ranking deliberately excludes Collaborative Filtering (CF, Funk MF, BPR) due to short-lived campaign dynamics and sparsity.

---

## 5. Thesis Framing Recommendations
- **Framed as**: A robust, full-stack architectural integration of localized content-based heuristics and Bayesian quality scoring within an ephemeral e-commerce promotional engine.
- **Contributions**: Centralized effective pricing invariants, fault-tolerant graceful degradation, honest explainability tags, and prevention of in-flight transaction anomalies.
