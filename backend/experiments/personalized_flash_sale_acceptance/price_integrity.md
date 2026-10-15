# Flash Sale Final Acceptance — Step 5: Price Integrity Report

**Audit Date:** 2026-10-10  
**Role:** Senior Full-Stack QA / PHP / MongoDB Engineer  
**Status:** **PASSED**

---

## 1. Executive Summary & Architecture Invariant

In SkinSyntaxVN, Flash Sale pricing follows a strict architectural invariant:
1. **Zero Client Trust:** Pricing payloads submitted from client forms or AJAX bodies (`$_POST['gia_ban']`, `$_POST['effective_price']`) are **strictly ignored**.
2. **Single Source of Truth:** All presentation and transactional surfaces resolve live prices directly through `FlashSaleCampaign::resolveEffectivePrice()`.
3. **Catalog Immutability:** `san_pham.gia_ban` is never mutated by campaign activation, execution, or deactivation.
4. **Context Invariant:** All customer contexts (guests, authenticated users, oily skin profiles, dry skin profiles) receive the exact same transactional price for a given campaign item. Personalization affects candidate ranking order only, never unit price.

---

## 2. End-to-End SKU Pipeline Trace

Target product for automated lifecycle trace: **SKU #101** (*Kem Chống Nắng Beplain Nâng Tông, Kiềm Dầu Mịn Lì 50ml*).
- Catalog Base Price (`san_pham.gia_ban`): **278.000 đ**
- Active Campaign Flash Sale Price: **236.300 đ** (15% discount)

| Stage | Triggering Component / Method | Measured Unit Price | Source Flag | Verdict |
| :--- | :--- | :--- | :--- | :--- |
| **1. Homepage Carousel** | `HomeController::index()` $\to$ `FlashSaleCampaign::getPrimaryActiveCampaign()` | **236.300 đ** | `FLASH_SALE` | **PASS** |
| **2. Product Detail** | `SanPhamController::chitiet()` $\to$ `FlashSaleCampaign::resolveEffectivePrice()` | **236.300 đ** | `FLASH_SALE` | **PASS** |
| **3. Shopping Cart** | `HomeController::giohang()` $\to$ `FlashSaleCampaign::resolveEffectivePrice()` | **236.300 đ** | `FLASH_SALE` | **PASS** |
| **4. Checkout Preview** | `HomeController::buildCheckoutPreview()` $\to$ `resolveEffectivePrice()` | **236.300 đ** | `FLASH_SALE` | **PASS** |
| **5. Order Finalization** | `HoaDon::fetchProductPrice()` $\to$ `taoDonHang()` | **236.300 đ** | `FLASH_SALE` | **PASS** |

*Result:* Measured effective unit price is **100% identical (236.300 đ)** across all 5 presentation and transactional stages.

---

## 3. Hostile Client Price Tampering Test

- **Test Scenario:** Client attempts to submit a modified POST payload to order processing with forged price fields:
  ```php
  $_POST['gia_ban'] = 1000;
  $_POST['effective_price'] = 1000;
  ```
- **Observed Handling:** `HoaDon::taoDonHang()` and `fetchProductPrice()` disregard `$_POST` pricing inputs completely. The engine resolves SKU `#101` directly from the database and active campaign model.
- **Measured Order Unit Price:** **236.300 đ** (Tampered value `1.000 đ` completely rejected).
- **Verdict:** **PASS** (Zero vulnerability to client price tampering).

---

## 4. Mid-Flight Deactivation & Expiration Test

- **Test Scenario:** A customer adds SKU `#101` to cart at Flash Sale price (**236.300 đ**). While viewing checkout, the campaign ends or is toggled inactive (`is_active = false`).
- **Observed Handling:**
  1. `FlashSaleCampaign::resolveEffectivePrice()` immediately evaluates the campaign as inactive and reverts to `REGULAR` pricing:
     - Effective price: **278.000 đ** (Original catalog price).
  2. Order subtotal verification guard in `HomeController::xulydathang()` triggers:
     ```php
     if (isset($_SESSION['checkout_preview_subtotal']) && (int)$_SESSION['checkout_preview_subtotal'] !== $subtotal) {
         $_SESSION['checkout_preview_subtotal'] = $subtotal;
         set_flash('error', 'Một số sản phẩm ưu đãi Flash Sale vừa cập nhật giá hoặc hết hạn. Vui lòng xác nhận lại tổng tiền trước khi đặt hàng.');
         redirect(BASE_URL . '/index.php?r=thanhtoan');
         return;
     }
     ```
  3. The order is halted, customer is redirected to the checkout page, and the updated total is displayed with the warning alert.
- **Verdict:** **PASS** (Automated price reversion and mandatory customer reconfirmation).

---

## 5. Catalog Base Price Immutability

Before and after the entire test suite, the underlying MongoDB document in collection `san_pham` was inspected:
- Pre-test SKU `#101` `gia_ban`: **278.000 đ**
- Post-test SKU `#101` `gia_ban`: **278.000 đ**
- Delta: **0 đ** (Strictly immutable).

---

## 6. Testing Scope Classification

- **Actually Tested Behavior:**
  - Real-time pipeline trace across 5 presentation and calculation stages.
  - Client parameter manipulation simulation in isolated execution.
  - Runtime campaign toggling and pricing fallback to regular catalog prices.
  - Subtotal reconfirmation redirect check.
  - Catalog collection document immutability.
- **Code-Inspection Findings:**
  - `HoaDon::taoDonHang()` line items compute line totals strictly from `fetchProductPrice()`.
  - `HomeController::buildCheckoutPreview()` synchronizes `subtotal` directly from `resolveEffectivePrice()`.
- **Untested Scenarios:**
  - Concurrent race condition with >1000 simultaneous order checkouts at the exact millisecond of campaign expiration (requires high-load stress harness).
- **Remaining Limitations:**
  - None within single-instance transactional scope.
