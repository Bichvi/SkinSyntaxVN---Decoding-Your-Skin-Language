# Admin Flash Sale E2E Verification Report

## 1. Overview
This audit examines the administrative management workflow for Flash Sale campaigns in SkinSyntaxVN, validating lifecycle state transitions, product candidate registration, pricing consistency, timezone handling, and administrative safety policies.

---

## 2. Campaign Setup & Creation
The controlled test campaign `TEST_FLASHSALE_PHASE2E_E2E` was provisioned through the canonical application service path (`FlashSaleCampaign::saveCampaign` / `QuanTriController::adminFlashSaleSave`):

```json
{
  "ten_campaign": "TEST_FLASHSALE_PHASE2E_E2E",
  "mo_ta": "Chiến dịch Flash Sale E2E Phase 2E - Đánh giá toàn vẹn nghiệp vụ và cá nhân hóa",
  "start_at": "2026-10-08 09:11:00",
  "end_at": "2026-10-08 12:11:00",
  "is_active": true,
  "item_count": 16
}
```

### Verified Campaign Candidate Universe (16 Real Products)

| SKU | Product Name | Category Family | Regular Price | Flash Price | Discount (%) |
|---|---|---|---|---|---|
| `1000` | Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 75ml | Cleanser | 163,000 đ | 114,100 đ | 30% |
| `4365` | Gel Rửa Mặt Cosrx Tràm Trà, 0.5% BHA 150ml | Cleanser | 129,000 đ | 83,850 đ | 35% |
| `266` | Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 400ml | Cleanser | 454,000 đ | 340,500 đ | 25% |
| `728` | Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 200ml | Cleanser | 295,000 đ | 177,000 đ | 40% |
| `68` | Serum Timeless Vitamin B5 Làm Dịu 30ml | Serum | 357,000 đ | 285,600 đ | 20% |
| `1176` | Tinh Chất Serum Phục Hồi Da | Serum | 63,000 đ | 34,650 đ | 45% |
| `755` | Serum Dưỡng Sáng Mờ Thâm | Serum | 204,000 đ | 142,800 đ | 30% |
| `62` | Tinh Chất Đặc Trị Mụn | Serum | 290,000 đ | 145,000 đ | 50% |
| `1027` | Mặt Nạ Ngủ Môi Care:Nel Quả Lựu 23g | Lip Care / Balm | 140,000 đ | 91,000 đ | 35% |
| `1296` | Mặt Nạ Ngủ Môi Care:Nel Quả Mọng 5g | Lip Care / Balm | 41,000 đ | 30,750 đ | 25% |
| `2328` | Mặt Nạ Ngủ Môi Care:Nel Chanh 5g | Lip Care / Balm | 44,000 đ | 26,400 đ | 40% |
| `569` | Mặt Nạ Ngủ Môi Care:Nel Quả Lựu 5g | Lip Care / Balm | 41,000 đ | 32,800 đ | 20% |
| `71` | Son Dưỡng Môi DHC Không Màu Giảm Thâm | Lip Care / Balm | 165,000 đ | 115,500 đ | 30% |
| `54` | Nước Tẩy Trang Bí Đao Cocoon 500ml | Cleansing Water | 189,000 đ | 103,950 đ | 45% |
| `797` | Nước Tẩy Trang Cocoon Bí Đao 140ml | Cleansing Water | 93,000 đ | 60,450 đ | 35% |
| `1772` | Nước Tẩy Trang B.O.M Từ 8 Loại Trà 500ml | Cleansing Water | 241,000 đ | 120,500 đ | 50% |

---

## 3. Admin UI & Listing Audit
1. **Listing Presence**: Verified in Admin Flash Sale Table (`r=admin_flash_sales`). Campaign appears at rank 1 with identifier `ma_campaign = 1`.
2. **Status Derivation**:
   - Server calculates status via `FlashSaleCampaign::getCampaignStatus()`: returns `STATUS_ACTIVE` (`'ACTIVE'`).
   - Admin badge helper (`get_flash_sale_status_badge()`) resolves to:
     - Label: **Đang diễn ra**
     - Style: `bg-success text-white`
     - Icon: `bi-lightning-charge-fill`
3. **Item Count**: Confirmed `count($campaign['items']) === 16`.
4. **Timezone Representation**: Timestamps stored internally as UTC `MongoDB\BSON\UTCDateTime` and rendered in `Asia/Ho_Chi_Minh` (UTC+7):
   - `start_at`: `2026-10-08 09:11:00`
   - `end_at`: `2026-10-08 12:11:00`

---

## 4. Admin Edit Safety Policy
When navigating to edit an active campaign (`r=admin_flash_sale_edit&id=1`):
1. **Active Campaign Detection**: The view checks `$isActiveCampaign = $isEditing && (($editing['status'] ?? '') === FlashSaleCampaign::STATUS_ACTIVE)`.
2. **Safety Warning Banner**: Displays an explicit safety warning:
   > **Chiến dịch đang diễn ra (ACTIVE)!**  
   > *Chiến dịch đang có hiệu lực trên hệ thống. Thay đổi giá hoặc sản phẩm có thể ảnh hưởng trực tiếp đến giỏ hàng và đơn giá thanh toán hiện tại của khách hàng. Hãy kiểm tra thật kỹ trước khi nhấn Lưu.*
3. **Time Overlap Conflict Guard**: `checkOverlappingCampaigns()` blocks saving any overlapping active intervals for the same candidate SKUs.

---

## 5. Enable / Disable Toggle Workflow
When toggling campaign activation (`r=admin_flash_sale_toggle` / setting `is_active = false`):
1. **Immediate Deactivation**: `is_active` becomes `false` in MongoDB.
2. **Public Effect**: `getPrimaryActiveCampaign()` immediately evaluates to `null`.
3. **Pricing Reversion**: `resolveEffectivePrice()` immediately reverts to regular prices (`is_flash_sale = false`, `effective_price = regular_price`).
4. **Override Precedence**: Explicit `is_active = false` strictly overrides the valid active time window ($T_{\text{start}} \le \text{now} < T_{\text{end}}$).

---

## 6. Audit Verdict
**ADMIN FLASH SALE WORKFLOW — 100% PASS**.  
The administrative lifecycle management functions deterministically, protects active transactions with clear safety warnings, and strictly enforces temporal and candidate invariants.
