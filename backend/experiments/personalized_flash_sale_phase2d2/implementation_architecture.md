# Phase 2D.2: Implementation Architecture

## 1. Overview
Personalized Deal Ranking is built as a non-invasive, high-speed adapter layer on top of the real Flash Sale campaign system established in Phases 2A–2C.

```
┌────────────────────────────────────────────────────────┐
│               ACTIVE CAMPAIGN DETECTED                 │
│         FlashSaleCampaign::getPrimaryActiveCampaign()  │
└───────────────────────────┬────────────────────────────┘
                            │
                            ▼ (Candidate SKU Boundary: 100% Contained)
┌────────────────────────────────────────────────────────┐
│             Candidate Hydration & Context              │
│   - Candidate Products: $primaryFlashCampaign['items'] │
│   - User Context: Profile (Skin/Budget) + Behavior     │
└───────────────────────────┬────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────┐
│            PersonalizedDealRanker Adapter              │
│   - Reuses ContentBasedRecommender TF-IDF index        │
│   - Scores ONLY the 12–20 campaign candidate SKUs      │
│   - 0 Full-Catalog Scan / 0 Leaking Candidates         │
└───────────────────────────┬────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────┐
│             In-Campaign Normalization Engine           │
│   - Min-Max scaling for Personal, Discount, & Rating   │
│   - Safe uniform handling on zero range (max == min)   │
└───────────────────────────┬────────────────────────────┘
                            │
                            ▼
┌────────────────────────────────────────────────────────┐
│            Heuristic Scoring & Tie-Breaker             │
│   - CompositeScore = 0.50*P + 0.30*D + 0.20*WR         │
│   - Deterministic tie-breaker: ID ASC                  │
└───────────────────────────┬────────────────────────────┘
                            │
              ┌─────────────┴─────────────┐
              ▼                           ▼
┌───────────────────────────┐ ┌───────────────────────────┐
│     Homepage Top 4        │ │   All Deals Listing       │
│  - Diversity filter       │ │  - Full campaign candidate│
│  - Max 4 products         │ │    grid (12–20 SKUs)      │
│  - "Ưu đãi dành cho bạn"  │ │  - "Dành cho bạn" sort    │
│    badge (personalized)   │ │  - Explicit sorts bypass  │
│  - Reason tag on card     │ │    personalization        │
└───────────────────────────┘ └───────────────────────────┘
```

## 2. Component Classifications
| Component | Classification | Description |
|---|---|---|
| `ContentBasedRecommender` Vector Builders | **PRODUCTION-REUSED** | TF-IDF query vector construction for search, view, cart, and profile. |
| `ContentBasedRecommender::computeSkinTypeScore` | **PRODUCTION-REUSED** | Categorical skin type compatibility scoring. |
| `ContentBasedRecommender::computeBudgetScore` | **PRODUCTION-REUSED** | Budget proximity scoring using **effective flash price**. |
| `SanPham::getSimpleRecommenderProducts` | **PRODUCTION-REUSED** | Canonical $m=21.0, C=4.8890$ weighted rating. |
| `PersonalizedDealRanker.php` | **NEW ADAPTER** | Orchestrates in-campaign candidate scoring without full catalog iteration. |
| Composite Formula ($0.50 P + 0.30 D + 0.20 WR$) | **HEURISTIC** | Selected engineering heuristic weights for deal ranking. |
| Top 4 Diversity Filter | **HEURISTIC** | Merchandising filter avoiding 4 duplicate brand variants in Homepage banner. |
| Reason Tag Selector | **HEURISTIC** | Grounded explanation badges matching measured user evidence. |
| Phase 2C Baseline Sorting | **FALLBACK** | Deterministic fallback if ranker throws or relevance collapses to noise. |
| Catalog Purchase Popularity | **NOT USED** | Sparsity $>99\%$ renders catalog sales rank unusable. |
| Collaborative Filtering Weights (`TuongTac::WEIGHTS`) | **NOT USED** | Decoupled; experimental CF weights have no role in Flash Sale. |
