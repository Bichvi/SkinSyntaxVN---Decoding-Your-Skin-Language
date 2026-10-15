# Phase 2D.2: Ranking Formula Specification

## 1. Mathematical Formulation

### 1.1 Composite Score for Personalized States (State B, C, D, E)
$$\text{CompositeScore} = w_{\text{personal}} \cdot \overline{S}_{\text{personal}} + w_{\text{discount}} \cdot \overline{S}_{\text{discount}} + w_{\text{wr}} \cdot \overline{S}_{\text{wr}}$$

**Selected Engineering Heuristic Weights:**
- $w_{\text{personal}} = 0.50$
- $w_{\text{discount}} = 0.30$
- $w_{\text{wr}} = 0.20$

*Note: These weights are selected engineering heuristics chosen to balance active shopper interests with promotional deal urgency. They are NOT mathematically learned, trained, or globally optimal.*

### 1.2 Composite Score for Guest / Baseline State (State A)
$$\text{GuestScore} = w_{\text{guest\_discount}} \cdot \overline{S}_{\text{discount}} + w_{\text{guest\_wr}} \cdot \overline{S}_{\text{wr}}$$

**Selected Engineering Heuristic Weights:**
- $w_{\text{guest\_discount}} = 0.60$
- $w_{\text{guest\_wr}} = 0.40$

---

## 2. Signal Derivations

### 2.1 Personal Relevance ($S_{\text{personal}}$)
Depending on the resolved User State:
1. **Behavior Only (State B):**
   $$S_{\text{personal}} = S_{\text{behavior}} = \text{CosineSimilarity}(\vec{q}_{\text{behavior}}, \vec{d}_{\text{product}})$$
2. **Profile Only (State C):**
   $$S_{\text{personal}} = 0.60 \cdot S_{\text{skin\_type}} + 0.25 \cdot \text{CosineSimilarity}(\vec{q}_{\text{profile}}, \vec{d}_{\text{product}}) + 0.15 \cdot S_{\text{budget}}$$
   *Crucial rule:* $S_{\text{budget}}$ compares against the **effective Flash Sale price**, not the regular price.
3. **Hybrid (State D & E):**
   $$S_{\text{personal}} = 0.55 \cdot S_{\text{behavior}} + 0.45 \cdot S_{\text{profile}}$$

### 2.2 Discount Strength ($S_{\text{discount}}$)
$$S_{\text{discount}} = \text{discount\_percent} = \text{round}\left(\frac{\text{regular\_price} - \text{flash\_price}}{\text{regular\_price}} \times 100\right)$$

### 2.3 Weighted Rating ($S_{\text{wr}}$)
Implements the canonical IMDb formula preserved from Simple Recommender:
$$S_{\text{wr}} = \left(\frac{v}{v + m}\right) \cdot R + \left(\frac{m}{v + m}\right) \cdot C$$
- $m = 21.0$ (75th percentile of catalog review count)
- $C = 4.8890$ (global catalog mean rating)
- $v$: product review count (`so_luong_danh_gia`)
- $R$: product average rating (`diem_danh_gia`)

---

## 3. In-Campaign Normalization Policy
For any signal vector $X = [x_1, x_2, \dots, x_N]$ over the $N$ campaign candidates:
$$\overline{x}_i = \begin{cases} 0.50 & \text{if } \max(X) - \min(X) \le 10^{-6} \\ \frac{x_i - \min(X)}{\max(X) - \min(X)} & \text{otherwise} \end{cases}$$

---

## 4. Deterministic Tie-Breaking Order
In case of equal composite scores, ordering is resolved strictly deterministically:
1. $\text{CompositeScore}$ DESC
2. $\text{raw\_personal}$ DESC
3. $\text{discount\_percent}$ DESC
4. $\overline{S}_{\text{wr}}$ DESC
5. $\text{ma\_san\_pham}$ ASC
