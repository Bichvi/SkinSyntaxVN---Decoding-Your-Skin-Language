# Phase 2D.2: Normalization Policy Specification

## 1. Candidate Set Scope
All normalization operations are strictly executed **within the active campaign candidate set** ($N \in [4, 24]$ items, typically 12–16 products).

No external catalog items are ever evaluated during normalization.

---

## 2. Min-Max Normalization Formulation
For any raw signal $x_i \in X$:
$$\overline{x}_i = \frac{x_i - \min(X)}{\max(X) - \min(X)}$$

### 2.1 Zero-Range Handling (Safe Uniform Policy)
When all candidates share the same score (e.g., all have identical discounts or all have zero personal relevance):
$$\text{range} = \max(X) - \min(X) \le 10^{-6} \implies \overline{x}_i = 0.50$$
- Prevents division-by-zero errors (`IEEE 754 NaN` or PHP warnings).
- Ensures that when a signal provides zero discriminatory power, it contributes a uniform, neutral constant to all candidates without skewing ranking order.

---

## 3. Justification Over Alternatives
1. **Vs. Raw Score Summation:** Raw signals operate on completely incompatible scales ($S_{\text{personal}} \in [0, 1]$, Discount $\in [10, 50]$, Weighted Rating $\in [4.85, 4.96]$). Direct addition would cause discount percentage to dominate personalization by a factor of 50:1.
2. **Vs. Rank/Percentile Normalization:** Rank normalization strips out the proportional gap between deep and shallow discounts (e.g. treats a 45% vs 40% discount gap identically to an 11% vs 10% gap).
3. **Vs. Lexicographic Ordering:** Lexicographic sorting introduces "discount blindness", where an item with negligible relevance (e.g., 0.02) and a 5% discount would defeat an item with zero relevance and a 50% discount.
