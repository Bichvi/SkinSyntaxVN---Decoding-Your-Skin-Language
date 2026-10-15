# Phase 2D.2: User State Router & Fallback Policies

## 1. User State Router Specification

| Internal State | Identification Criteria | Ranking Mode | UI Presentation | Allowed Reason Tags |
|---|---|---|---|---|
| **STATE A** (Guest / Cold Start) | Unauthenticated or no profile and no session interactions (`search`, `view`, `cart`). | `BASELINE` | "Ưu đãi nổi bật" | "Giảm sâu {N}%", "Được đánh giá tốt", "Ưu đãi nổi bật" |
| **STATE B** (Behavior Only) | Session interactions present (`search`, `view`, or `cart`), but no completed skin profile survey. | `BEHAVIOR` | "Ưu đãi dành cho bạn" | "Theo tìm kiếm gần đây", "Tương tự sản phẩm bạn vừa xem", "Giảm sâu {N}%", "Được đánh giá tốt" |
| **STATE C** (Profile Only) | Authenticated with completed skin profile survey (`skin_type`), but no active session actions. | `PROFILE` | "Ưu đãi dành cho bạn" | "Phù hợp loại da của bạn", "Trong ngân sách của bạn", "Giảm sâu {N}%", "Được đánh giá tốt" |
| **STATE D** (Profile + Behavior) | Completed skin profile survey AND active session interactions. | `HYBRID` | "Ưu đãi dành cho bạn" | "Phù hợp loại da của bạn", "Theo tìm kiếm gần đây", "Tương tự sản phẩm bạn vừa xem", "Trong ngân sách của bạn", "Giảm sâu {N}%", "Được đánh giá tốt" |
| **STATE E** (Purchase Signal Present) | Customer with valid prior order history plus session/profile context. | `HYBRID` | "Ưu đãi dành cho bạn" | Identical to State D (prior purchases enrich behavior profile). |

*Note: The raw state identifiers `STATE_A` through `STATE_E` are internal architectural enums and are never exposed in user-facing Vietnamese UI.*

---

## 2. Fallback Policy

### 2.1 Low Signal / Noise Fallback
If an authenticated user or active session yields personal relevance scores that lack meaningful variance:
- Criterion: $\max(S_{\text{personal}}) < 0.15$ OR $(\max(S_{\text{personal}}) - \min(S_{\text{personal}})) < 0.05$.
- Action: The ranker automatically routes the session to `BASELINE` mode.
- Rationale: Avoids ranking deals based on random token noise or background words; ensures personalization is only activated when real customer interest exists.

### 2.2 System & Exception Fallback
If `PersonalizedDealRanker` encounters any database error, JSON decode issue, or PHP exception:
- Trapped in `FlashSaleCampaign::rankCampaignCandidates()` via `try { ... } catch (\Throwable $e)`.
- Instantly falls back to Phase 2C deterministic baseline sorting (`sortBaselineRanking()`).
- Rationale: High reliability. A recommender failure must never crash the homepage, conceal an active campaign, or disrupt checkout pricing.
