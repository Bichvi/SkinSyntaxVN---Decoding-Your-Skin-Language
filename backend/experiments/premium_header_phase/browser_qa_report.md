# Browser QA Report — Premium Header & Product Card CTAs

## 1. Executive Summary
Browser automation was conducted across all required viewports using headless Google Chrome connected via Chrome DevTools Protocol (CDP).

| Component / Feature | Test Target | Measured Result | Status |
| :--- | :--- | :--- | :---: |
| **Header Architecture** | 3 distinct tiers (Utility, Main, Category+Trust) | Tier 1, Tier 2, Tier 3 present; no 4th tier | **PASS** |
| **Utility Bar (Tier 1)** | Dark green `#11261B`, phone, social, logout | Routine removed, phone: 1900 0000, 3 social icons | **PASS** |
| **Search (Tier 2)** | Widest element, 52-56px height, unified button | Width: 513px, Height: 52px, unified input+btn | **PASS** |
| **Livestream (Tier 2)** | Commercial hero, red pulse dot, beauty coral | `.header-action-btn--live`, pulse dot, route `r=live` | **PASS** |
| **Routine (Tier 2)** | Personalization feature, soft mint, wand icon | `.header-action-btn--routine`, route `r=goiy` | **PASS** |
| **Account & Cart** | Dynamic role text, cart count badge | Role-aware, cart link `r=giohang`, count badge | **PASS** |
| **Category Trigger (Tier 3)** | Opens canonical 28-node mega menu | `[ ☰ Danh mục sản phẩm ]` opens 8 groups menu | **PASS** |
| **Category Chips** | Removed from horizontal row | Legacy chips and deal pill completely absent | **PASS** |
| **Trust Bar** | 4 authentic evidence-based claims | 4 items with dividers, no fabricated guarantees | **PASS** |
| **Product Card CTAs** | 50% / 50% balance, 38px height, no text wrap | Same row (0px diff), same height (38px), 50/50 width | **PASS** |

---

## 2. Screenshot Verification Artifacts
In compliance with the 4-screenshot limit:

1. **Desktop Header Final** (`1920x1080`):
   - Path: `backend/experiments/premium_header_phase/desktop_header_final.png`
   - Verification: Shows Tier 1 utility bar, Tier 2 main actions (Logo, 52px Search, LIVE button with red pulse dot, Routine button, Account, Cart), and Tier 3 (Category button + 4 Trust items). All elements sit on their designated horizontal rows with generous breathing room.
2. **Desktop Product Card** (`1920x1080`):
   - Path: `backend/experiments/premium_header_phase/desktop_product_card.png`
   - Verification: Shows product image, title, rating `★ 5.0 (229)`, price, and 50% | 50% balanced CTAs ("Thêm vào giỏ" in `#F1F5F9` and "Mua ngay" in `#183B2B`), both 38px high, single-line text.
3. **Tablet Header** (`768x1024`):
   - Path: `backend/experiments/premium_header_phase/tablet_header.png`
   - Verification: Clean adaptation with top row (Account, Cart, Menu, Logo), middle row (Search), and bottom quick action row (LIVE + Routine).
4. **Mobile Header** (`375x812`):
   - Path: `backend/experiments/premium_header_phase/mobile_header.png`
   - Verification: Compact mobile layout with top actions, search bar, and 2 quick-action pills for LIVE and Routine. Zero horizontal overflow.

---

## 3. Product Card CTA Measurement Detail
- **Card Top Offset Difference**: `0px` (Same horizontal baseline)
- **"Thêm vào giỏ" Button**: Width `149px`, Height `38px`, Background `#F1F5F9`, Border `#CBD5E1`
- **"Mua ngay" Button**: Width `149px`, Height `38px`, Background `#183B2B`, Color `#FFFFFF`, `white-space: nowrap`
- **Width Ratio**: `0.50` (Exact 50/50 balance)
- **Line Wrap on "Mua ngay"**: None (`scrollHeight <= offsetHeight`)
