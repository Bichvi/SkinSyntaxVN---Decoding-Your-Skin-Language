# Checkout Integration & Pricing Safeguards

## 1. Existing Pricing Architecture Reuse
SkinSyntaxVN already calculates server-side prices in:
- `backend/app/controllers/HomeController.php` (`giohang()`, `chuandaithanhtoan()`, `xulydathang()`)
- `backend/app/models/SanPham.php` (`tinhGiaKhuyenMai()`)
- `backend/app/models/FlashSale.php` (Flash sale discount calculation)
- `backend/app/models/Voucher.php` (`kiemTraVoucher()`, `apDungVoucher()`)

## 2. Discount Calculation Sequence
To ensure absolute price integrity:
1. **Base Catalog Price**: Fetched securely from `san_pham` database record; client-submitted unit prices are ignored.
2. **Flash Sale Price**: If the item is in an active flash sale campaign, the flash sale price is applied first.
3. **Subtotal Calculation**: Line totals are summed.
4. **Voucher Validation**:
   - Ownership: The voucher belongs to the logged-in customer account.
   - Expiration: `ngay_bat_dau <= NOW <= ngay_ket_thuc`.
   - Minimum Order Value: Subtotal >= `gia_tri_toi_thieu`.
   - Product Eligibility: If `ap_dung_san_pham` is specified, the voucher discount applies solely to eligible items.
   - Quota check: `da_su_dung < so_luong`.
5. **Discount Clamping**:
   - `discount = min(discount_amount, giam_toi_da)`.
   - Total payable cannot drop below zero: `final_total = max(0, subtotal - discount + shipping_fee)`.
   - Stacking rules: Flash sale discounts and voucher discounts stack only where explicitly allowed; multiple vouchers are strictly non-stackable (one voucher per order).

## 3. Transactional Consistency & Order Persistence
During `xulydathang()`:
- MongoDB atomic operation or transaction:
  1. Increment voucher usage `da_su_dung += 1`.
  2. Mark customer personal voucher state as `used`.
  3. Create `hoa_don` document including `ma_voucher`, `tien_giam_gia`, and final calculated amount.
  4. If payment fails or order aborts, usage counter is rolled back.
