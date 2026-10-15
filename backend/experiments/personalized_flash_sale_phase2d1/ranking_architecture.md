# Proposed Personalized Deal Ranking Architecture (Phase 2D.1)

## 1. Architectural Pipeline Overview

The Personalized Flash Sale Ranking architecture operates as a strictly bounded, non-invasive pipeline:

```
┌────────────────────────────────────────────────────────────────┐
│           ACTIVE Flash Sale Campaign (Admin Curated)           │
│        (Defines eligible SKU candidate pool: 4–20 items)       │
└───────────────────────────────┬────────────────────────────────┘
                                │
                                ▼
┌────────────────────────────────────────────────────────────────┐
│           Campaign Candidate Hydration (No Leakage)            │
│   (Hydrates canonical regular_price, flash_price, WR, specs)   │
└───────────────────────────────┬────────────────────────────────┘
                                │
                                ▼
┌────────────────────────────────────────────────────────────────┐
│             Context Router & User State Resolver               │
│   [State A: Guest] [State B: Behavior] [State C: Profile]      │
│   [State D: Hybrid (Profile+Behavior)] [State E: Purchase]     │
└───────────────────────────────┬────────────────────────────────┘
                                │
                                ▼
┌────────────────────────────────────────────────────────────────┐
│        Flash Sale Scoring Adapter (Production CBR Reused)      │
│   - Dot product with combined user query vector                │
│   - Skin type compatibility score                              │
│   - Effective flash price budget score                         │
│   - Produces raw PersonalRelevance in [0.0, 1.0]               │
└───────────────────────────────┬────────────────────────────────┘
                                │
                                ▼
┌────────────────────────────────────────────────────────────────┐
│             In-Campaign Normalization Engine                   │
│   - Min-Max scaling of PersonalRelevance within candidate pool │
│   - Min-Max scaling of DiscountStrength within candidate pool  │
│   - Min-Max scaling of WeightedRating within candidate pool    │
└───────────────────────────────┬────────────────────────────────┘
                                │
                                ▼
┌────────────────────────────────────────────────────────────────┐
│           Heuristic Fusion & Tie-Breaker Engine                │
│   Score = 0.50 * Norm(P) + 0.30 * Norm(D) + 0.20 * Norm(WR)   │
│   Tie-break: ma_san_pham ASC                                   │
└───────────────────────────────┬────────────────────────────────┘
                                │
                                ▼
┌────────────────────────────────────────────────────────────────┐
│              Merchandising & Diversity Filter                  │
│   - Homepage Top 4: Max 1 product per product family           │
│   - All Deals: Full sorted grid with reason tags               │
└────────────────────────────────────────────────────────────────┘
```

---

## 2. Component Classification

| Component | Architecture Role | Description |
| :--- | :---: | :--- |
| **`flash_sale_campaigns`** | **CANONICAL BOUNDARY** | Strictly defines eligible candidate SKUs. No recommender algorithm can inject items outside this set. |
| **`ContentBasedRecommender`** | **PRODUCTION-REUSED** | Pre-computed TF-IDF index, vector norms, vocabulary, tokenization, skin-type match formula, budget formula. |
| **`Simple Recommender (IMDb WR)`** | **PRODUCTION-REUSED** | $WR = \frac{v}{v+21} R + \frac{21}{v+21} \times 4.8890$. Used as community reputation evidence. |
| **`Effective Pricing Engine`** | **PRODUCTION-REUSED** | Resolves exact flash price and discount percentage. |
| **Min-Max Within Campaign** | **HEURISTIC** | Maps heterogeneous raw scales into directly comparable $[0, 1]$ relative distributions. |
| **Weight Grid ($0.50 / 0.30 / 0.20$)** | **HEURISTIC** | Calibrated heuristic balance: 50% Personal Relevance, 30% Discount Incentive, 20% Community Reputation. |
| **Product Family Diversity** | **HEURISTIC** | Prevents near-identical duplicate variants from occupying all Top 4 Homepage slots. |
| **Guest Baseline** | **FALLBACK** | When user has 0 signals, falls back to deterministic Discount + Weighted Rating baseline. |
| **Purchase Popularity** | **NOT USED** | Excluded due to sparse catalog order coverage. |
| **Collaborative Filtering (`TuongTac`)** | **NOT USED** | Excluded as experimental; unsuited for cold deals. |

---

## 3. Mathematical Scoring Model

For any candidate item $i$ in active campaign $\mathcal{C}$:

$$\text{FinalScore}(i) = w_p \cdot \widetilde{P}(i) + w_d \cdot \widetilde{D}(i) + w_{wr} \cdot \widetilde{WR}(i)$$

Where:
- $\widetilde{P}(i) = \frac{P(i) - \min_{j \in \mathcal{C}} P(j)}{\max_{j \in \mathcal{C}} P(j) - \min_{j \in \mathcal{C}} P(j)}$ (Min-Max Personal Relevance)
- $\widetilde{D}(i) = \frac{D(i) - \min_{j \in \mathcal{C}} D(j)}{\max_{j \in \mathcal{C}} D(j) - \min_{j \in \mathcal{C}} D(j)}$ (Min-Max Discount Percentage)
- $\widetilde{WR}(i) = \frac{WR(i) - \min_{j \in \mathcal{C}} WR(j)}{\max_{j \in \mathcal{C}} WR(j) - \min_{j \in \mathcal{C}} WR(j)}$ (Min-Max Simple Weighted Rating)

### Recommended Calibrated Weights:
- $w_p = 0.50$ (Dominant personalization driver)
- $w_d = 0.30$ (Promotional urgency incentive)
- $w_{wr} = 0.20$ (Social proof / quality stabilizer)

*(Sensitivity experiments prove that across $w_p \in [0.4, 0.7]$ and $w_d \in [0.1, 0.4]$, Top-4 Jaccard similarity is 1.0, demonstrating excellent stability).*

---

## 4. State-by-State Routing Specification

### State A: Guest / Cold Start (0 signals)
- **Active Signals:** None.
- **Formula:** $0.60 \cdot \widetilde{D}(i) + 0.40 \cdot \widetilde{WR}(i)$ (or lexicographic $D \text{ DESC}, WR \text{ DESC}$).
- **Reason Tags Allowed:** `"Đang giảm X%"`, `"Được đánh giá tốt"`.
- **Disallowed:** Any reference to user profile, history, or AI personalization.

### State B: Behavior Only (Search, View, Cart; No Profile)
- **Active Signals:** Session behaviors.
- **Formula:** Standard 3-part fusion ($w_p=0.50, w_d=0.30, w_{wr}=0.20$).
- **Reason Tags Allowed:** `"Theo tìm kiếm gần đây"`, `"Tương tự sản phẩm bạn vừa xem"`, `"Phù hợp giỏ hàng"`.

### State C: Profile Only (Survey on file; No Session Activity)
- **Active Signals:** `skin_type`, `van_de_da`, `muc_tieu_cham_soc`, `ngan_sach`.
- **Formula:** Standard 3-part fusion.
- **Reason Tags Allowed:** `"Phù hợp loại da của bạn"`, `"Trong ngân sách của bạn"`.

### State D: Hybrid (Profile + In-Session Behavior)
- **Active Signals:** Profile + Behaviors fused via $\vec{U} = 0.50 \vec{U}_{\text{behavior}} + 0.50 \vec{V}_{\text{profile}}$.
- **Formula:** Standard 3-part fusion.
- **Reason Tags Allowed:** Dynamic combination matching dominant signal (e.g. `"Khớp loại da • Theo tìm kiếm gần đây"`).

### State E: Purchase History Available
- **Active Signals:** Verified orders with time-decay weighting ($\text{half-life} = 60\text{ days}$).
- **Formula:** Standard 3-part fusion.
- **Reason Tags Allowed:** `"Phù hợp lịch sử mua sắm"`.
