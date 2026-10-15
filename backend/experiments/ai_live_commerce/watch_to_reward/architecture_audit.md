# Architecture Audit: AI Product Video & Watch-to-Reward Voucher System

**Date:** 2026-10-12  
**System:** SkinSyntaxVN E-Commerce Platform  
**Environment:** PHP 8.2 / MongoDB (Atlas / Local Docker) / Python Flask AI Idol Service / Playwright Headless Chromium

---

## 1. AI Idol Campaign & Video Lifecycle Audit

### MongoDB Collections & Schemas
- **`ai_idol_campaigns`**:
  - `_id`: ObjectId
  - `name`: String
  - `status`: Lifecycle enum (`DRAFT`, `QUEUED`, `GENERATING`, `READY`, `SCHEDULED`, `STARTING`, `LIVE`, `COMPLETED`, `NEEDS_ATTENTION`, `FAILED`, `CANCELLED`).
  - `product_ids`: Array of strings (e.g. `["6376"]`, representing `ma_san_pham`).
  - `configuration`: `{ avatar_mode: "syna_3d"|"photo"|"motion_video", format: "16:9"|"9:16", length_mode: String }`.
  - `master_video_path`: String path inside container (e.g. `/app/storage/programs/campaign_6acb507bbf6b4db32a00a827.mp4`).
  - `progress`: `{ ready: Int, failed: Int, total: Int }`.
  - `scheduled_at`, `created_at`, `updated_at`: UTC timestamps.

- **`ai_idol_segments`**:
  - `_id`: ObjectId
  - `campaign_id`: ObjectId / String
  - `product_id`: String (matches `ma_san_pham`)
  - `position`: Int
  - `status`: String (`PENDING_SCRIPT`, `AWAITING_APPROVAL`, `APPROVED`, `GENERATING`, `READY`, `FAILED`)
  - `current_stage`: String (`COLLECTING_PRODUCT`, `RETRIEVING_KNOWLEDGE`, `CRAFTING_SCRIPT`, `VALIDATING_SCRIPT`, `GENERATING_VOICE`, `RENDERING`, `ASSEMBLING`, `READY`, `FAILED`)
  - `job_id`: String (references `ai_idol_jobs`)
  - `video_id`: String (references `ai_idol_videos._id`)
  - `video_path`: String (e.g. `/app/storage/output/syna_6acb507d26e9d8acf59c457d_2e94b8b42441eb1872ab.mp4`)

- **`ai_idol_videos`**:
  - `_id`: ObjectId
  - `job_id`: String
  - `product_id`: String
  - `file_path`: String
  - `duration`: Float (seconds)
  - `format`: String (`"16:9"`)
  - `resolution`: String (`"1280x720"`)
  - `created_at`: UTC timestamp

---

## 2. Video Storage & Streaming Endpoints Audit

- **Internal Flask Service (`skinsyntax-ai-idol:5003`)**:
  - `GET /api/ai-idol/campaigns/<campaign_id>/video`: Streams full master program using Flask `send_file(path, mimetype="video/mp4", conditional=True)`.
  - `GET /api/ai-idol/videos/<video_id>`: Streams individual product segment video from `ai_idol_videos`.
  - Native support for HTTP Range headers (`206 Partial Content`, `Accept-Ranges: bytes`, `Content-Range`) via `conditional=True`.
- **PHP Web Proxy**:
  - Route: `index.php?r=admin_ai_idol_stream_campaign&campaign_id=...`
  - Controller: `AdminAiIdolController::streamCampaignVideo()` -> `proxyVideo()`.
  - Current limitation: Guarded by `$this->checkAdmin()` requiring admin authentication. There is currently no public, authenticated or guest-safe endpoint for product detail video streaming.

---

## 3. Product Catalog & Detail Page Audit

- **Routing & Controller**:
  - Route: `index.php?r=chitiet&id=<productId>`
  - Dispatched to: `SanPhamController::chitiet()`
  - Model: `SanPham::find($id)` queries `san_pham` collection.
- **Product ID Conventions**:
  - In `san_pham` collection, `ma_san_pham` can exist as Integer (e.g. `6376`) or String (`"6376"`).
  - In AI Idol service and segments, product IDs are consistently represented as String.
  - Queries must use flexible matching: `['$or' => [['ma_san_pham' => (int)$id], ['ma_san_pham' => (string)$id]]]`.
- **View (`frontend/views/chitiet.php`)**:
  - 2-column layout (Left: gallery and purchase policy; Right: title, ratings, Flash sale badge, price, stock, Add to Cart / Buy Now CTAs, AI Assistant CTA).
  - Bottom: Frequently Bought Together recommendation block followed by detailed tabbed navigation (`Mô tả`, `Thông số`, `Thành phần`, `HDSD`, `Đánh giá`, `Hỏi đáp`).
  - Purchase controls are prominent in the right column above the fold. Any new video section must not push purchasing actions down or induce layout shifts.

---

## 4. Voucher & Checkout Infrastructure Audit

- **MongoDB Collection**: `voucher` (singular collection).
- **PHP Model**: `backend/app/models/Voucher.php`.
- **Fields**:
  - `ma_voucher`: Integer unique ID
  - `ma_code`: Uppercase string (e.g. `FREESHIP`, `TESTVOUCHER`)
  - `ten_voucher`, `mo_ta`: Strings
  - `loai_giam`: `'fixed'` (VND) or `'percent'` (%)
  - `gia_tri_giam`: Integer
  - `gia_tri_don_toi_thieu`: Integer
  - `giam_toi_da`: Nullable Integer
  - `so_luong`: Nullable Integer (null = unlimited)
  - `so_luong_da_dung`: Integer count of redemptions
  - `ngay_bat_dau`, `ngay_ket_thuc`: UTC DateTimes
  - `trang_thai`: `'active'` | `'inactive'`
- **Checkout Integration**:
  - `HomeController::apDungVoucher()` calls `Voucher::validateForCheckout($code, $subtotal)`.
  - `HomeController::xulydathang()` calculates `so_tien_giam`, calls `HoaDon::taoDonHang()` with `ma_voucher` & `ma_giam_gia`, and consumes the voucher.
  - `Voucher::consumeVoucher($voucherId)` uses atomic `$inc: ['so_luong_da_dung' => 1]`.

---

## 5. Customer Authentication & Profile Audit

- **Session**: `$_SESSION['user']`:
  - `id`: Customer integer ID (`ma_kh`)
  - `ho_ten`: Customer name
  - `email`: Customer email
  - `role` / `vai_tro`: `'khach_hang'` or `'admin'`
- **Helpers**:
  - `is_logged_in()`: Returns boolean
  - `current_user()`: Returns array or null
- **Profile Area**:
  - Route: `index.php?r=hoso` handled by `TaiKhoanController`.
  - View: `frontend/views/hoso.php`.
  - Customer data stored in `khach_hang` collection, linked via `email` and `ma_kh`.
