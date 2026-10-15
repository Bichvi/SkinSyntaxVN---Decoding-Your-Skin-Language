# Watch-to-Reward Voucher System Design (Phase V2 Architecture)

## 1. Audit of Existing Voucher System
From the architecture audit:
- The authoritative database collection is `voucher` (singular, 2 documents present in production/Atlas), with active documents normalized via `backend/app/models/Voucher.php`.
- Schema fields already present:
  - `ma_voucher`: Unique code (e.g., `SYNAREWARD20`, `WELCOME10`).
  - `loai_voucher`: `phan_tram` (%) or `tien_mat` (VND).
  - `gia_tri`: Discount value (e.g. `10` for 10%, or `50000` for 50,000 VND).
  - `giam_toi_da`: Maximum discount cap for percentage vouchers.
  - `gia_tri_toi_thieu`: Minimum order value required for eligibility.
  - `ngay_bat_dau` & `ngay_ket_thuc`: Active date range.
  - `so_luong` & `da_su_dung`: Global issuance quota.
  - `trang_thai`: Active status (`active` / `inactive`).
  - `ap_dung_san_pham`: Array of eligible product IDs (empty = all products).

## 2. Watch-to-Reward Voucher Model Extensions
To support Phase V2 Watch-to-Reward vouchers cleanly **without breaking existing checkout logic or creating parallel tables**, we extend the existing schema with:
- `is_watch_reward`: `true`
- `reward_campaign_id`: ObjectId referencing the watch reward configuration.
- `video_id` / `publication_id`: ID of the video required to be watched.
- `required_watch_seconds`: Minimum required verified watch duration (e.g., 60 seconds or 90% of duration).
- `per_user_limit`: Maximum times a customer account can claim this reward (typically 1).
- `customer_id`: Assigned customer ID upon issuance (for personalized single-use claim codes) or user-claim tracking in `voucher_redemptions` / customer subdocument.

## 3. Idempotency & Concurrency Safety
- **MongoDB Atomic Counter**: Issuing vouchers will use atomic find-and-modify operations:
  ```json
  {
    "$inc": { "da_su_dung": 1 }
  }
  ```
  with filter condition `{ "da_su_dung": { "$lt": "$so_luong" } }` to guarantee no overselling beyond total issuance quota under high concurrency.
- **Per-User Claim Lock**: A unique compound index on `customer_claims: { customer_id: 1, reward_campaign_id: 1 }` prevents concurrent double-claims across multiple browser tabs.
