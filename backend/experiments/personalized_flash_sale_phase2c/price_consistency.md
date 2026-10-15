# End-to-End Price Consistency Verification (Phase 2C)

## 1. The Core Price Invariant
In Phase 2C, an immutable pricing invariant was established across the entire customer purchasing journey:

$$\text{Homepage Flash Price} = \text{Product Detail Price} = \text{Cart Unit Price} = \text{Checkout Unit Price}$$

As long as a Flash Sale campaign remains in `ACTIVE` status, no customer will ever encounter price discrepancies between marketing discovery, product evaluation, and order placement.

---

## 2. Shared Pricing Architecture: Effective Pricing Engine

All four touchpoints resolve their prices through the centralized method:
`FlashSaleCampaign::resolveEffectivePrice(string|int $productId, ?int $nowTimestamp = null, ?array $product = null)`

```
┌────────────────────────────────────────────────────────┐
│             MongoDB: flash_sale_campaigns              │
└──────────────────────────┬─────────────────────────────┘
                           │
                           ▼
          FlashSaleCampaign::resolveEffectivePrice()
                           │
      ┌────────────────────┼────────────────────┬────────────────────┐
      ▼                    ▼                    ▼                    ▼
Homepage Flash Sale   Product Detail        Cart Page          Checkout Page
  (home.php)           (chitiet.php)       (giohang.php)      (thanhtoan.php)
```

### Flow & Resolution Logic:
1. Fetch currently ACTIVE campaign targeting `$productId`.
2. Retrieve canonical `san_pham.gia_ban` as `regular_price`.
3. If an active campaign item matches `$productId` and `flash_price < regular_price`:
   - `effective_price = flash_price`
   - `is_flash_sale = true`
   - `pricing_source = FLASH_SALE`
   - `campaign_id`, `campaign_name`, `discount_percent`, `valid_until` are populated.
4. Otherwise:
   - `effective_price = regular_price`
   - `is_flash_sale = false`
   - `pricing_source = REGULAR`

---

## 3. Detailed Touchpoint Audits

### 3.1 Homepage Flash Sale Card
- **Flash Price:** Displayed prominently in `#DC2626` bold text (`100.500 đ`).
- **Regular Price:** Struck-through canonical price (`201.000 đ`).
- **Badge:** Dynamic badge `-50%` derived from `round((reg - flash) / reg * 100)`.

### 3.2 Product Detail Page (`index.php?r=chitiet&id=1`)
- **Display Integration:** In `frontend/views/chitiet.php`, `get_effective_product_price($productIdForForms, $p)` dynamically resolves effective pricing.
- **Visual Presentation:**
  - Red badge: `⚡ FLASH SALE: <Tên Campaign>`.
  - Notice: `<i class="far fa-clock"></i> Ưu đãi có hạn`.
  - Large price: `249.900 đ` in red.
  - Struck regular price: `357.000 đ`.
  - Discount badge: `Giảm 30%`.

### 3.3 Cart Page (`index.php?r=giohang`)
- Adding a flash sale product to cart retains the `effective_price` (`249.900 đ`).
- The line total reflects `effective_price * quantity`.
- If a campaign expires while items sit in the cart, the cart seamlessly refreshes to the regular price on next view or recalculation.

### 3.4 Checkout Page & Order Revalidation (`index.php?r=thanhtoan`)
- Checkout preview renders the exact unit price and subtotal calculated from the active flash sale price.
- **Server-Side Order Guard (`xulydathang`):** Before writing an order to MongoDB, the backend re-resolves the price for every SKU through `resolveEffectivePrice()`. Even if a client attempts to forge or manipulate price payloads, the database records the real server-derived price.

---

## 4. Verification Evidence

Automated regression suite `scratch/test_phase2c_suite.php` verified:
- `TEST_K`: Homepage effective price (`100.500 đ`) matches Product Detail effective price (`100.500 đ`).
- `TEST_L`: Product Detail effective price matches Cart unit price (`100.500 đ`).
- `TEST_M`: Checkout preview pricing matches Cart pricing during active campaign lifecycle.
- **Result:** 100% PASS.
