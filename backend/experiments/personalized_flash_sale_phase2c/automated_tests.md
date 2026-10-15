# Automated Test Suite Report (Phase 2C)

## 1. Test Execution Summary
The automated test suite `scratch/test_phase2c_suite.php` was executed against canonical MongoDB Atlas (`skinsyntax`).

- **Total Tests:** 20
- **Passed:** 20
- **Failed:** 0
- **Pass Rate:** 100%
- **Precheck & Post-test Catalog:** `san_pham = 2473`, `danh_muc = 28` preserved.

---

## 2. Test Case Breakdown (Tests A through T)

| Test Code | Description | Verification Logic | Status |
| :--- | :--- | :--- | :---: |
| **TEST_A** | No ACTIVE campaign &rarr; Homepage Flash Sale hidden | Verified `getPrimaryActiveCampaign()` returns `null` when 0 active campaigns exist; section condition evaluates to `false`. | **PASS** |
| **TEST_B** | Direct `r=flash_sale` with no active campaign &rarr; honest empty state | Dispatched `HomeController::flashSale()`; verified output contains "Hiện chưa có chương trình Flash Sale đang diễn ra" and link to home. | **PASS** |
| **TEST_C** | ACTIVE campaign &rarr; Homepage section visible | Created test campaign; verified `getPrimaryActiveCampaign()` returns the active campaign with status `ACTIVE`. | **PASS** |
| **TEST_D** | Only campaign products rendered | Inspected hydrated products; confirmed every item belongs strictly to the campaign's SKU list without uncurated catalog padding. | **PASS** |
| **TEST_E** | Homepage max 4 items | Sliced top items from 6-product campaign; confirmed exactly 4 items returned. | **PASS** |
| **TEST_F** | Campaign with 2 valid products &rarr; exactly 2, no padding | Seeded a 2-item campaign; confirmed homepage renders exactly 2 cards without artificial padding. | **PASS** |
| **TEST_G** | Countdown target equals campaign `end_at` | Rendered `HomeController::index()`; verified `data-end-timestamp` equals the campaign's exact `end_at_ts`. | **PASS** |
| **TEST_H** | Ended campaign stops appearing | Simulated `now > end_at`; confirmed `getPrimaryActiveCampaign($futureTime)` returns `null`. | **PASS** |
| **TEST_I** | No countdown reset in client script | Verified elimination of `23:59:59` midnight reset; verified timer stop logic at `remaining <= 0`. | **PASS** |
| **TEST_J** | Discount badge matches campaign pricing | Iterated all campaign items; confirmed `discount_percent === round((regular - flash) / regular * 100)`. | **PASS** |
| **TEST_K** | Product detail matches Homepage effective price | Verified `get_effective_product_price()` on detail matches homepage flash price for active item. | **PASS** |
| **TEST_L** | Cart matches product detail effective price | Added product to `$_SESSION['gio_hang']`; verified cart unit price matches flash price. | **PASS** |
| **TEST_M** | Checkout matches cart while campaign active | Verified end-to-end price invariant holds into checkout preview calculation. | **PASS** |
| **TEST_N** | All Deals contains only campaign items | Rendered `HomeController::flashSale()`; verified all 6 items rendered with no non-campaign SKUs. | **PASS** |
| **TEST_O** | Discount sorting correct (`discount_percent DESC`) | Executed `sortCampaignDeals('discount')`; verified strictly monotonic non-increasing discount order. | **PASS** |
| **TEST_P** | Rating sorting correct (`diem_danh_gia DESC`) | Executed `sortCampaignDeals('rating')`; verified rating evidence ordering. | **PASS** |
| **TEST_Q** | Effective-price sorting correct (`price_asc` & `price_desc`) | Executed `sortCampaignDeals()` for price; verified ordering based on effective flash price in both directions. | **PASS** |
| **TEST_R** | Invalid/missing campaign product skipped safely | Injected non-existent SKU `999999999`; verified hydration cleanly skipped it without throwing exception or breaking layout. | **PASS** |
| **TEST_S** | No `so_luong_da_ban` sales claims | Inspected rendered HTML; confirmed complete absence of "Đã bán", "Bán chạy", or fake stock progress bars. | **PASS** |
| **TEST_T** | Clean teardown & catalog regression | Deleted all test campaigns; verified active campaigns count is 0; confirmed `san_pham = 2473`, `danh_muc = 28`. | **PASS** |

---

## 3. Test Reproduction Command
To reproduce the automated test run:
```bash
php scratch/test_phase2c_suite.php
```
Output confirms: `SUMMARY: 20 / 20 TESTS PASSED`.
