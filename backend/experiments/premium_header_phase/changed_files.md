# Changed Files Specification

The following files were modified to achieve the Premium Header & Product Card CTA redesign:

### 1. `frontend/views/layouts/header.php`
- **Path**: `frontend/views/layouts/header.php`
- **Changes**:
  - Removed top `promo-strip` (eliminating the 4th tier and keeping strictly 3 tiers).
  - Cleaned Tier 1 Utility Bar: Removed duplicate "Gợi ý routine" link; organized phone hotline, product lookup, store network, warranty, customer care, social links, and user authentication actions.
  - Implemented Tier 2 Main Header:
    - Added high-visibility Brand Lockup with gradient mark and sub-label.
    - Built unified 52px Search form with icon, placeholder, and "Tìm kiếm" submit button.
    - Integrated Commercial Hero Action `[🔴 LIVE Tư vấn]` with red pulsing dot (`.live-pulse-dot`) pointing to `r=live`.
    - Integrated Signature Feature `[✨ Gợi ý routine]` with mint styling pointing to `r=goiy`.
    - Added dynamic Account button (`Quản trị` / `Tài khoản` / `Đăng nhập`) and Cart button with numeric badge.
    - Added mobile quick action strip for viewports `< 992px`.
  - Implemented Tier 3 Category + Trust Bar:
    - Left: Canonical 28-node mega menu trigger `[ ☰ Danh mục sản phẩm ]`.
    - Right: 4 evidence-based trust benefits (Sản phẩm chính hãng, Giao hàng toàn quốc, Cá nhân hóa routine, Hỗ trợ tận tâm).
    - Removed horizontal category shortcut chips (`Mặt Nạ`, `Làm Sạch Da`, etc.) and deal pill.
  - Added cache-busting timestamp version to `style.css` link (`style.css?v=...`).

### 2. `frontend/public/assets/css/style.css`
- **Path**: `frontend/public/assets/css/style.css`
- **Changes**:
  - Defined `.header-container` with `max-width: 1540px` and generous padding (`0 24px`).
  - Added Tier 1 styling: `.utility-strip`, `.utility-strip__inner`, `.utility-contact`, `.utility-social-link`.
  - Added Tier 2 styling: `.header-main`, `.header-main__inner`, `.brand-lockup`, `.header-search-wrap`, `.header-search` (height 52px, background `#F8FAF8`, border `#CBD5E1`), `.header-action-btn--live`, `.live-pulse-dot` (subtle pulse on dot only), `.header-action-btn--routine`, `.header-action-btn--neutral`.
  - Added Tier 3 styling: `.header-nav-shell`, `.header-catalog__toggle` (dark green `#183B2B`), `.header-trust-bar`, `.header-trust-item`, `.header-trust-divider`.
  - Cleaned up broken CSS fragments and legacy `.header-shortcuts` / `.header-deal-pill` rules around lines 1940-1970.
  - Responsive adaptations for desktop (1920/1440), tablet (768), and mobile (375/412).

### 3. `frontend/views/home.php`
- **Path**: `frontend/views/home.php`
- **Changes**:
  - Updated `$renderHomeProductCard` actions to a balanced 2-column grid (`grid-template-columns: 1fr 1fr;` / 50% | 50%).
  - Uniform button height of 38px and radius of 8px.
  - Secondary styling on "Thêm vào giỏ" (`#F1F5F9`, cart icon) and primary dark green on "Mua ngay" (`#183B2B`, white text, `nowrap`).
  - Added `mt-auto` on `.price-wrap` to align bottom CTAs evenly across adjacent cards in a row.

### 4. `frontend/views/tatca.php`
- **Path**: `frontend/views/tatca.php`
- **Changes**:
  - Aligned catalog product card CTAs to the identical 50/50 balanced grid format with 38px height and 8px border radius.
