# Product Video Publication Architecture & Workflow

## Overview
Phase V1 and V1.1 implement an end-to-end, deterministic video publication pipeline connecting rendered Syna 3D video assets from AI Idol Studio (`r=admin_ai_idol`) directly to eCommerce product detail pages (`r=chitiet&id=...`).
Admin users manage publications completely via a modern, dedicated Studio UI without manual database intervention.

---

## 1. Eligibility Criteria
- **Render Verification**: Only video records residing in `ai_idol_videos` (or ready in `ai_idol_segments`) with status `READY` are eligible for publication.
- **Unrendered Block**: Videos that are still queued, generating, or failed cannot be published. Action buttons are disabled with warning tooltips.
- **Explicit Admin Intent**: Scheduled LIVE videos or drafts are never automatically published to product detail pages. Publication requires an explicit document in `ai_product_videos`.
- **Asset Integrity**: Source video assets are never moved or deleted upon unpublishing; toggling alters only the `is_published` state in the publication collection.

---

## 2. Publication Schema (`ai_product_videos`)
```json
{
  "_id": ObjectId("6acc6bd54e1a7ffa0e0672b2"),
  "video_id": "6acc27e526e9d8acf59c457e",
  "job_id": "6acb507d26e9d8acf59c457d",
  "campaign_id": "6acb507bbf6b4db32a00a827",
  "segment_id": "",
  "title": "Syna 3D - Giới thiệu Combo Nước Tẩy Trang L'Oreal",
  "duration": 73.816236,
  "format": "16:9",
  "resolution": "1280x720",
  "avatar_mode": "syna_3d",
  "associated_product_ids": ["6376"],
  "primary_product_id": "6376",
  "display_priority": 10,
  "is_published": true,
  "created_at": "2026-10-12 12:10:45",
  "updated_at": "2026-10-13 01:02:51",
  "published_at": "2026-10-13 01:02:51"
}
```

---

## 3. Database Indexes
Indexed in MongoDB `ai_product_videos`:
- `{ associated_product_ids: 1, is_published: 1 }` — ultra-fast lookups when rendering product detail.
- `{ video_id: 1 }` — idempotency and asset correlation.
- `{ display_priority: -1, created_at: -1 }` — deterministic display sorting when multiple videos belong to one product.

---

## 4. Admin Management Interface (Phase V1.1)
Located at `http://localhost/index.php?r=admin_ai_idol`:

### A. Flow Switcher Tab
- **Tab**: `Xuất bản sản phẩm` (`#pubFlowTab`, `data-studio-flow="publication"`).
- Direct deep-linking support via query string: `r=admin_ai_idol&flow=publication` (or `&open_pub={video_id}`).

### B. Video Grid Section ("Video đã tạo → Xuất bản lên sản phẩm")
- **Card Elements**:
  - 16:9 Video Poster thumbnail with fallback placeholder.
  - Video format indicator (`16:9` / `9:16`) and formatted duration badge (`mm:ss`).
  - Campaign name and video title.
  - Render status tag (`READY` in green, `Đang render` in yellow).
  - Publication badge (`Đã xuất bản` vs. `Chưa xuất bản`).
  - Linked products pill counter (`N sản phẩm liên kết`).
  - Priority badge (`Ưu tiên: 10`).
  - Action buttons: "Quản lý xuất bản" and direct shortcut link to primary PDP.

### C. Publication Management Modal (`#pubModal`)
1. **Video Info Banner**: Poster, duration badge, campaign tag, status badge, and custom PDP title input.
2. **Display Priority Input**: Numeric input (1 - 1000, default 10) for sorting precedence.
3. **Product Search & Filter**: Live debounce filter by product name or code (`ma_san_pham`) with asynchronous server-side search.
4. **Product Checklist**: Checkboxes with product thumbnail, title, SKU, and unit price.
5. **Selected Chips**: Active product badges with quick remove (`×`) buttons.
6. **Primary Product Dropdown**: Selects which linked product acts as the canonical anchor.
7. **Submit & Unpublish Actions**:
   - `Xuất bản lên trang sản phẩm`: Saves publication, links products, marks `is_published: true`.
   - `Hủy xuất bản`: Retains associations but marks `is_published: false`.
   - Comprehensive error/success feedback banners with double-submit protection.

---

## 5. Security & CSRF Protection
- **CSRF Token**: Stored in `$_SESSION['admin_ai_idol_csrf']`, exposed to frontend via `window.AI_IDOL_CSRF`.
- **Enforcement**: Required for all write endpoints (`admin_ai_idol_publish_video`, `admin_ai_idol_toggle_publish`).
- **RBAC**: Strictly verifies `role === 'admin'` or `is_admin === 1`. Unauthenticated users redirected (`302`), unauthorized roles rejected (`403`).
