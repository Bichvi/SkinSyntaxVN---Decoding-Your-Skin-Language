# Cart, View, Budget & Merchandising Policies (Phase 2D.1)

## 1. Cart Product Merchandising Policy (Section 23)

### Context & Contrast:
In general catalog recommendation (`forYou` section), items already residing in the user's shopping cart are excluded (`if (isset($cartLookup[$pid])) continue;`) to avoid wasting discovery slots on products the user has already committed to purchase.

However, Flash Sale operates under a fundamentally distinct merchandising objective:
- Flash Sale highlights **time-limited pricing advantages**.
- If a shopper added a serum to their cart, seeing that exact serum featured in the Flash Sale Top 4 reassures them of their deal, allows them to monitor the remaining session timer, and frequently drives order completion or volume increases (e.g. buying a second bottle at the discounted flash price).

### Policy Evaluation:
- **OPTION A: Retain cart products as eligible in both Top 4 and All Deals.**
  - *Pros:* High conversion reassurance; honors the shopper's active intent; prevents confusing experiences where an item "disappears" from the flash sale banner upon being added to cart.
  - *Cons:* Consumes one of the 4 homepage slots for a product already in cart.
- **OPTION B: Exclude cart products from Homepage Top 4, but retain in All Deals.**
  - *Pros:* Maximizes discovery of 4 *new* deals on Homepage.
  - *Cons:* Creates confusing disappearing UI behavior upon clicking "Add to Cart".

### Formal Recommendation:
**Adopt OPTION A.**
For Flash Sale, cart products **REMAIN FULLY ELIGIBLE**. A Flash Sale deal is an active promotion, not a speculative catalog recommendation. An item already in the cart has demonstrated maximal purchase intent and deserves prominent visibility while the campaign is live.

---

## 2. Viewed Product Merchandising Policy (Section 24)

### Context & Contrast:
In `ContentBasedRecommender::recommendHybrid()`, recently viewed products are excluded (`if (isset($validRecentLookup[$pid])) continue;`) to encourage exploration of similar alternatives rather than repeating items the shopper just saw.

### Policy Evaluation:
In Flash Sale merchandising, retaining recently viewed campaign products serves as a reminder for relevant deals. If a user recently inspected a 400.000₫ cream and that cream is currently on Flash Sale for 240.000₫, displaying it within the Flash Sale candidate set connects the user's active interest directly to the campaign discount.

### Formal Recommendation:
**Viewed products REMAIN FULLY ELIGIBLE for Flash Sale.**
This is adopted as a merchandising policy that keeps recently viewed campaign products eligible for reminder and relevance purposes. Their `personal_relevance` score will appropriately reflect their high recency weight (35% in behavioral fusion).

---

## 3. Budget vs. Flash Sale Price Calibration (Section 26)

### Context:
`ContentBasedRecommender::computeBudgetScore(float $candPrice, ?int $budget)` computes:
$$\text{budgetScore} = \begin{cases} 1.0 & \text{if } \text{candPrice} \le \text{budget} \\ \max\left(0.10, 1.0 - \frac{\text{candPrice} - \text{budget}}{\text{budget}}\right) & \text{if } \text{candPrice} > \text{budget} \end{cases}$$

In the general catalog, `candPrice` is `san_pham.gia_ban` (regular price).

### Flash Sale Policy Evaluation:
- In a Flash Sale, the customer will actually pay the **effective flash price**, NOT the regular price.
- If a user has a budget of 300.000₫, and a product has a regular price of 360.000₫ but a flash price of 270.000₫:
  - Using regular price yields $\text{budgetScore} = 1.0 - \frac{60000}{300000} = 0.80$.
  - Using flash price yields $\text{budgetScore} = 1.00$ (perfect budget match!).

### Formal Recommendation:
For Flash Sale ranking, the budget evaluation adapter must use **`effective_price` (flash price)**:
$$\text{budgetScore} = \text{computeBudgetScore}(\text{effective\_price}, \text{budget})$$
This preserves true economic reality for the customer while leaving the production `ContentBasedRecommender` class globally untouched.

---

## 4. Product Family Diversity Policy (Section 25)

### Merchandising Risk:
If a brand has multiple sizes or shade variants of the same product in a campaign (e.g. CeraVe Cleanser 236ml, 473ml, Foaming, Hydrating), a pure mathematical ranking could fill all Top 4 slots with essentially the same product.

### Policy Specification:
- Re-use `ContentBasedRecommender::extractProductFamily(string $name)`.
- For the **Homepage Top 4**, enforce a soft diversity ceiling:
  - **Maximum 1 product per normalized product family** among the Top 4.
  - If enforcing diversity would reduce Top 4 below the available campaign size, relax the constraint.
- For the **All Deals page**, diversity filtering is **NOT applied** (shoppers expect to browse all participating variants).
