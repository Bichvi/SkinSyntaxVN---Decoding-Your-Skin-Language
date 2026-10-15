# Production Relevance API & Signals Audit (Phase 2D.1)

## 1. Executive Summary
This audit inspects the current production recommendation engine (`ContentBasedRecommender.php`, `SanPham.php`, and `HomeController.php`) to evaluate its reusability for **Personalized Flash Sale Deal Ranking** in Phase 2D.2.

The key structural finding is that current production recommendations are engineered for **catalog-wide candidate discovery** (`recommendHybrid()` scans all 2,473 products and applies exclusion filters). For Flash Sale, the requirement is the exact inverse: **scoring a strictly bounded candidate set** (the 4–20 SKUs of an active campaign).

Fortunately, `ContentBasedRecommender` encapsulates all vector-building, cosine-similarity, and profile-matching logic in modular `public` methods, allowing a lightweight, non-invasive adapter to score arbitrary candidate sets without modifying production classes.

---

## 2. Reusable Production API Surface

In `backend/app/services/ContentBasedRecommender.php`:

| Method | Visibility | Reusability in Flash Sale | Function |
| :--- | :--- | :--- | :--- |
| `buildSearchQueryVector(array $queries, array $filteredDocFreq)` | `public` | **Directly Reusable** | Tokenizes up to 3 MRU search queries with position decay `[1.0, 0.6, 0.3]`, computes TF-IDF vector, and L2-normalizes. |
| `buildViewQueryVector(array $validRecentIds, array $vectors, array $prices)` | `public` | **Directly Reusable** | Fuses up to 5 viewed SKUs with recency weights `[0.50, 0.25, 0.15, 0.07, 0.03]` and computes reference price. |
| `buildCartQueryVector(array $cartItems, array $vectors, array $prices)` | `public` | **Directly Reusable** | Builds behavioral vector from cart item quantities and calculates reference price. |
| `buildPurchaseQueryVector(array $purchases, array $vectors, array $prices)` | `public` | **Directly Reusable** | Fuses verified purchases with exponential time-decay ($\lambda = \ln(2)/60\text{ days}$). |
| `buildBehaviorQueryVector(...)` | `public` | **Directly Reusable** | Performs self-normalized multi-signal behavior fusion with weights: Cart (0.35), View (0.35), Search (0.20), Purchase (0.10). |
| `buildProfileQueryVector(array $skinProfile, array $filteredDocFreq)` | `public` | **Directly Reusable** | Extracts survey attributes (`skin_type`, `van_de_da`, `muc_tieu_cham_soc`) into a normalized TF-IDF vector. |
| `computeSkinTypeScore(string $prodLoaiDa, ?string $custSkinType)` | `public` | **Directly Reusable** | Evaluates skin compatibility: Exact match (1.0), Universal (0.70), Neutral (0.50), Unknown (0.30), Incompatible (0.0). |
| `computeBudgetScore(float $candPrice, ?int $budget)` | `public` | **Directly Reusable** | Soft budget score in $[0.10, 1.0]$. |
| `getPriceScore(float $candPrice, float $refPrice)` | `public` | **Directly Reusable** | Price similarity relative to behavioral reference price in $[0.0, 1.0]$. |
| `l2NormalizeVector(array $vec)` | `public` | **Directly Reusable** | Normalizes sparse vectors to unit Euclidean norm. |
| `computeVectorNorm(array $vec)` | `public` | **Directly Reusable** | Calculates Euclidean L2 norm of sparse vector. |

---

## 3. Signal Analysis & Specification

| Signal Name | Source | Raw Range | Observed Range | Normalization | Missing / Empty Behavior | Recency / Decay Behavior | Current Production Role |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **SEARCH** | `$_SESSION['search_history']` or `$userProfile['recent_keywords']` | Arbitrary text query | Cosine $\in [0, 1]$ | L2 unit vector | Vector is empty; weight redistributes to other active signals | Position decay `[1.0, 0.6, 0.3]` for top 3 MRU queries | 20% of behavioral vector |
| **VIEW** | `$_SESSION['recent_viewed_products']` | Array of product IDs | Cosine $\in [0, 1]$ | L2 unit vector | Vector is empty; weight redistributes | Recency weights `[0.50, 0.25, 0.15, 0.07, 0.03]` for up to 5 items | 35% of behavioral vector |
| **CART** | `$_SESSION['gio_hang']` | Array of `[pid => qty]` | Cosine $\in [0, 1]$ | L2 unit vector | Vector is empty; weight redistributes | Quantity-weighted ($\le 10$) | 35% of behavioral vector |
| **PURCHASE** | `HoaDon` orders (validated statuses) | MongoDB orders | Cosine $\in [0, 1]$ | L2 unit vector | Vector is empty; weight redistributes | Exponential time decay ($\text{half-life} = 60\text{ days}$) | 10% of behavioral vector (often empty) |
| **PROFILE** | Survey fields in `userProfile` (`skin_type`, `van_de_da`, `budget`) | Text + categorical + budget int | Cosine $\in [0, 1]$ | L2 unit vector | Vector is empty; system falls back to pure behavior or Simple WR | Static user profile attributes | 50% of hybrid query vector; 20% skin score, 10% budget score |
| **HYBRID** | $\vec{U}_{\text{behavior}} + \vec{V}_{\text{profile}}$ | Unit vectors | Cosine $\in [0, 1]$ | L2 normalized ($\alpha = 0.50$) | Falls back to dominant active mode | Inherits behavior decays | Default mode for authenticated survey users |

---

## 4. Separation of Recommendation Weights vs. Collaborative Filtering

To prevent architecture confusion, the codebase strictly separates:

1. **Adaptive / Content-Based Fusion Weights (`ContentBasedRecommender`):**
   - Multi-signal behavior fusion: Cart (0.35), View (0.35), Search (0.20), Purchase (0.10).
   - Hybrid query blending: $\alpha = 0.50$ Behavior $+ 0.50$ Profile.
   - Final scoring: Content (0.70), Skin Compatibility (0.20), Budget (0.10).
   - **These are deterministic, grounded heuristic weights and are REUSABLE for Flash Sale.**
2. **Collaborative Filtering Event Weights (`TuongTac::WEIGHTS`):**
   - Click = 1, Cart = 3, Order = 5.
   - Matrix factorization / CF algorithms remain experimental research code.
   - **MUST NOT be used as Personalized Flash Sale ranking weights.**

---

## 5. Scoring Arbitrary Candidate Sets: Adapter Architecture for Phase 2D.2

### Problem Statement:
Production `ContentBasedRecommender::recommendHybrid()` cannot be directly called for Flash Sale because:
1. It loops over the entire catalog ($N=2473$).
2. It hard-excludes viewed products (`if (isset($validRecentLookup[$pid])) continue;`).
3. It hard-excludes cart products (`if (isset($cartLookup[$pid])) continue;`).
4. It does not accept a pre-filtered list of candidate SKU IDs.

### Minimal Adapter Design:
In Phase 2D.2, a lightweight adapter method (e.g. `FlashSaleCampaign::scoreCandidatesForContext(array $candidateItems, array $userContext)`) will be implemented:
1. Reuses `$cbr->loadIndex()` to get cached TF-IDF `$vectors`, `$norms`, and `$filteredDocFreq`.
2. Calls `$cbr->buildBehaviorQueryVector()` and `$cbr->buildProfileQueryVector()` to create the user query vector.
3. Iterates **only** over the `$candidateItems` provided by the active campaign (guaranteeing 100% campaign boundary containment).
4. Computes `$contentSim`, `$skinScore`, and `$budgetScore` using the exact production formulas.
5. Emits `personal_relevance` $\in [0, 1]$ without mutating `ContentBasedRecommender.php`.
