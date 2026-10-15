# Phase 2C Final Report: Public Flash Sale Experience

## Executive Summary
Phase 2C successfully transitioned SkinSyntaxVN from a fake/presentation-only Flash Sale mock to a **fully real, campaign-driven public UI**. All public touchpoints—Homepage Top 4, real server-driven countdown, dedicated All Deals page (`r=flash_sale`), Product Detail badges, Cart effective pricing, and Checkout invariants—are now anchored directly to the canonical MongoDB collection `flash_sale_campaigns`.

---

## Answers to Mandatory Audit Questions

### Q1. What replaced the old fake Flash Sale source?
The legacy mechanism (`SanPham::getFlashSaleProducts()` falling back to `phan_tram_giam > 0` or random `$latest` products) was replaced by `FlashSaleCampaign::getPrimaryActiveCampaign()`. It queries currently `ACTIVE` campaigns from the canonical MongoDB collection `flash_sale_campaigns`, batch-hydrates canonical product details using a single `$in` query, filters out inactive/missing items safely, and computes real discount percentages.

### Q2. What happens when no campaign is active?
- **Homepage:** The Flash Sale section is **completely hidden**. No fake deals, no expired products, and no generic catalog fallbacks are rendered.
- **Dedicated All Deals Page (`r=flash_sale`):** Renders a polished Vietnamese honest empty state: `"Hiện chưa có chương trình Flash Sale đang diễn ra"`, optionally informing users of the next upcoming campaign if one exists, with navigation buttons to `Trang chủ` and `Sản phẩm`.

### Q3. Where does countdown target come from?
The countdown target comes directly from the server-derived `end_at_ts` timestamp of the primary `ACTIVE` campaign. It is rendered into the DOM as `data-end-timestamp="<UNIX epoch in seconds>"`.

### Q4. Does countdown reset at zero?
**No.** The old client-side midnight loop (`23:59:59`) was completely eliminated. When the timer reaches zero, the JavaScript countdown halts, displays the badge `"Đã kết thúc"`, and performs a single controlled reload guarded by `sessionStorage` to fetch the updated server lifecycle state without looping.

### Q5. How are Homepage Top 4 selected?
From the primary active campaign's hydrated products, items are sorted by the deterministic baseline ranking (`sortBaselineRanking()`). The top 4 items are extracted via `array_slice($hydrated, 0, 4)`. If the campaign contains fewer than 4 items (e.g. 2 items), exactly 2 items are rendered with **zero synthetic padding** from non-campaign products.

### Q6. Are results personalized yet?
**No.** In strict compliance with Phase 2C boundaries, **no personalization** has been implemented. No survey data, skin concerns, browsing history, search keywords, or cart signals are used to bias deal order. Every shopper receives the exact same baseline deal ordering.

### Q7. What ranking is used for "Ưu đãi nổi bật"?
A deterministic lexicographic baseline ranking:
1. `discount_percent DESC` (highest discount percentage first)
2. `diem_danh_gia DESC` (catalog review rating score)
3. `so_luong_danh_gia DESC` (review volume evidence)
4. `ma_san_pham ASC` (stable canonical tie-breaker)

### Q8. Does `r=flash_sale` show only campaign products?
**Yes.** The dedicated route `index.php?r=flash_sale` displays strictly and exclusively products assigned to the active campaign. It no longer redirects or points to `r=tatca`.

### Q9. Are discount badges campaign-derived?
**Yes.** Discount badges are calculated strictly from the campaign's `flash_price` relative to the canonical `san_pham.gia_ban`: `round((regular_price - flash_price) / regular_price * 100)`. Legacy discount fields on `san_pham` are never trusted for Flash Sale badging.

### Q10. Are Homepage/Product Detail/Cart/Checkout prices consistent?
**Yes.** The pricing invariant holds end-to-end:
$$\text{Homepage Flash Price} = \text{Product Detail Flash Price} = \text{Cart Unit Price} = \text{Checkout Unit Price}$$
All four surfaces resolve through `FlashSaleCampaign::resolveEffectivePrice()`.

### Q11. Were fake sales/progress claims removed?
**Yes.** All decorative progress bars implying false sales percentages (e.g. "Đã bán 85%") and false scarcity counters were completely eliminated from all public templates.

### Q12. Was `so_luong_da_ban` used anywhere as sales truth?
**No.** `so_luong_da_ban` was not used for ranking, filtering, sorting, or badging anywhere in Phase 2C. No labels such as "Bán chạy", "Mua nhiều", or "Đã bán X" are displayed.

### Q13. Were recommendation algorithms modified?
**No.** `ContentBasedRecommender`, `Adaptive Hybrid`, `Simple WR constants` ($m=21, C=4.8890$), `CF`, `BPR`, `KMeans`, `Apriori`, and `FP-Growth` were preserved completely intact with zero modifications.

### Q14. Was test campaign cleaned up?
**Yes.** The controlled QA campaign `TEST_FLASHSALE_PHASE2C_PUBLIC` was torn down immediately following automated testing and browser verification. There are currently **0** active campaigns in the database.

### Q15. Are `san_pham=2473` and `danh_muc=28` preserved?
**Yes.** Verified via database query immediately following teardown:
- `san_pham`: 2,473
- `danh_muc`: 28
- `flash_sale_campaigns`: 0
Zero base product prices were altered.

---

## Conclusion & Gate Status

PHASE 2C PASS — READY FOR PERSONALIZED DEAL RANKING REVIEW
