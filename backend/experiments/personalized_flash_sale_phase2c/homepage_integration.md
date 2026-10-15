# Homepage Flash Sale Integration (Phase 2C)

## 1. Overview
The SkinSyntaxVN homepage (`index.php?r=home`) Flash Sale section has been re-architected to reflect real campaign lifecycle truth. It eliminates all synthetic fallbacks, hard-coded timers, and fake urgency claims.

---

## 2. Section Visibility & Lifecycle Rules

### Rule 1: Strict Active Campaign Requirement
The Flash Sale section renders **if and only if** a valid, currently ACTIVE campaign exists with at least one eligible product:
```php
<?php if (!empty($flashSaleCampaign) && !empty($flashSaleProducts)): ?>
  <section class="mb-5 p-4 text-white" ... data-flash-sale-countdown data-end-timestamp="<?= (int)$flashSaleCampaign['end_at_ts'] ?>">
    ...
  </section>
<?php endif; ?>
```
- If 0 active campaigns exist: The entire section is **completely hidden**.
- No fallback to `phan_tram_giam > 0` or `$latest` products.
- Upcoming campaigns are **never** shown as active on the homepage.

### Rule 2: Deterministic Selection of Primary Active Campaign
If the database contains multiple active campaigns (which occurs when distinct SKU subsets run concurrently without product collision), the homepage deterministically selects the **primary active campaign** according to:
```
1. earliest end_at ASC  (The campaign expiring soonest)
2. ma_campaign ASC      (Deterministic campaign ID tie-breaker)
```
Documented in `FlashSaleCampaign::getPrimaryActiveCampaign()`:
```php
$cursor = $this->collection->find(
    [
        'is_active' => ['$ne' => false],
        'start_at' => ['$lte' => $nowIso],
        'end_at' => ['$gt' => $nowIso],
    ],
    [
        'sort' => ['end_at' => 1, 'ma_campaign' => 1]
    ]
);
```

---

## 3. Homepage Top 4 Deal Selection

- The homepage presents at most **4 items** from the primary active campaign.
- Items are ordered according to the deterministic baseline ranking (`discount_percent DESC`, `rating DESC`, `id ASC`).
- **If the campaign has fewer than 4 valid products (e.g., 2 products):**
  - Exactly 2 items are rendered.
  - **Zero padding** is added from general catalog products.
  - The UI does not synthesize artificial items to satisfy grid symmetry.

---

## 4. Real Server-Driven Countdown Mechanism

1. **Target Epoch:**
   - The server computes the UNIX epoch `end_at_ts` from the campaign's UTC `end_at` timestamp and embeds it as `data-end-timestamp="<ts>"` on the section container.
2. **Client Clock Sync:**
   - JavaScript reads `targetTs` and updates the countdown every 1,000ms.
   - Days, hours, minutes, and seconds are formatted in Vietnamese:
     - Days box (`data-flash-days`) is automatically revealed if remaining time exceeds 24 hours.
     - Hours, minutes, and seconds remain fixed 2-digit tabular numbers.
3. **Behavior at Zero:**
   - When `remaining <= 0`, the timer **STOPS** immediately.
   - The "Đã kết thúc" badge is displayed.
   - To avoid infinite reload loops, a single reload is executed guarded by `sessionStorage.getItem('flash_reload_<endTs>')`.
   - The countdown **NEVER** resets to midnight (`23:59:59`).

---

## 5. Price Presentation & Badging

Each Flash Sale card adheres to SkinSyntaxVN's premium visual hierarchy:
- **Flash Sale Price:** Prominently highlighted in coral red (`#DC2626` / `text-danger`), bold, tabular numbers.
- **Regular Price:** Canonical `san_pham.gia_ban` shown with line-through styling in muted gray (`#94A3B8`).
- **Discount Badge:** Displayed at the top-right corner of the image card as `-XX%` (calculated as `round((regular - flash) / regular * 100)`).
- **CTA Link:** "Xem tất cả deal &rarr;" points directly to `index.php?r=flash_sale`.

---

## 6. Responsive Layout QA

- **Desktop (1440x900):** 4 cards displayed side-by-side in a 4-column Bootstrap grid (`col-6 col-md-3`).
- **Mobile (375x812):** Clean 2-column grid (`col-6`) with compact padding, readable typography, and touch-friendly target cards.
