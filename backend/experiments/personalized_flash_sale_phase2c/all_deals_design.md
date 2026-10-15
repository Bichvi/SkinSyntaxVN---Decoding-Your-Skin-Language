# All Deals Page Design & Architecture (Phase 2C)

## 1. Route Specification
- **Canonical Route:** `index.php?r=flash_sale`
- **Controller Action:** `HomeController::flashSale()`
- **Template View:** `frontend/views/flash_sale.php`
- **Legacy Replacement:** The legacy link previously routed users to `index.php?r=tatca`, displaying the entire uncurated catalog of 2,473 products. In Phase 2C, `r=flash_sale` displays **only products belonging to the currently active campaign**.

---

## 2. Page Structure & Components

### 2.1 Breadcrumb & Navigation
Provides direct contextual orientation:
`Trang chủ` &rarr; `Flash Sale`

### 2.2 Hero Campaign Banner
- **Background:** Premium deep botanical green gradient (`linear-gradient(135deg, #11281D 0%, #183B2B 55%, #1B4332 100%)`).
- **Campaign Title:** Rendered with proper XSS escaping: `<?= h($campaign['ten_campaign']) ?>`.
- **Campaign Description:** Admin-provided narrative or subtitle, safely escaped.
- **Deal Counter Badge:** Indicates active item count: `Đang áp dụng cho X sản phẩm chính hãng`.
- **Live Countdown Module:** Glassmorphic container with hours, minutes, seconds, and days (if applicable) synchronized to the campaign's `end_at_ts`.

### 2.3 Filter & Sort Controls
A clean toolbar positioned directly above the product grid featuring 5 baseline sort modes:
1. `Ưu đãi nổi bật` (`?r=flash_sale&sort=featured`) - Baseline ranking.
2. `Giảm nhiều nhất` (`?r=flash_sale&sort=discount`) - `discount_percent DESC`.
3. `Đánh giá tốt` (`?r=flash_sale&sort=rating`) - `diem_danh_gia DESC`.
4. `Giá thấp → cao` (`?r=flash_sale&sort=price_asc`) - `flash_price ASC`.
5. `Giá cao → thấp` (`?r=flash_sale&sort=price_desc`) - `flash_price DESC`.

> **Phase 2C Constraint:** "Dành cho bạn" (Personalized for you) is **strictly omitted**. All shoppers receive consistent baseline ordering.

### 2.4 Product Grid & Cards
- **Desktop (1440x900):** 4 columns (`col-6 col-md-4 col-lg-3`).
- **Tablet (768px - 1024px):** 3 columns.
- **Mobile (375x812):** 2 columns with touch-friendly layout.
- **Image Fallback:** Missing or corrupt image URLs fall back gracefully to `default_placeholder_image()`.
- **Canonical Linking:** Every product card links directly to `index.php?r=chitiet&id=<ma_san_pham>`.

---

## 3. Honest Empty State Architecture

When a user visits `index.php?r=flash_sale` directly while **no campaign is active**:
1. **Zero Fake Deals:** The system refuses to fall back to expired items or fake catalog discounts.
2. **Polished Vietnamese Notice:**
   - Heading: `Hiện chưa có chương trình Flash Sale đang diễn ra`
   - Description: `Các phiên ưu đãi chớp nhoáng với giá đặc quyền sẽ sớm quay trở lại. Hãy quay lại sau hoặc khám phá toàn bộ bộ sưu tập dược mỹ phẩm chính hãng.`
3. **Upcoming Campaign Intelligence:**
   - If an upcoming campaign exists in `flash_sale_campaigns` (`start_at > now`), the empty state informs the shopper:
     `⚡ Chương trình tiếp theo: <Tên Campaign> sẽ bắt đầu vào lúc <Giờ, Ngày>`.
4. **Actionable Navigation:**
   - Prominent primary button: `Về trang chủ` (`index.php?r=home`).
   - Secondary button: `Khám phá sản phẩm` (`index.php?r=tatca`).
