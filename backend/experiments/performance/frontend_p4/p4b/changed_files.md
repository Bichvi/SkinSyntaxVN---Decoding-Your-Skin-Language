# SKINSYNTAXVN — PHASE P4B: CHANGED FILES

## 1. Summary of Changes

Phase P4B modifies only presentation layer PHP templates and their image attributes. No business logic, database queries, Redis caching rules, or pricing algorithms were modified.

| File Path | Nature of Changes | Attributes Added |
| :--- | :--- | :--- |
| `frontend/views/components/ai_chat_widget.php` | Mascot versioning via `filemtime` + dimensions | `width="36" height="36"`, `width="40" height="40"`, `decoding="async"`, `?v=$mascotVersion` |
| `frontend/views/tatca.php` | Selective catalog grid image loading | `fetchpriority="high"` (item 0), `loading="lazy"` (items 4+), `decoding="async"`, `width="280" height="280"` |
| `frontend/views/home.php` | Hero mascot + product card renderer lazy defaults | `fetchpriority="high" width="300" height="360"` (Hero mascot), `loading="lazy" decoding="async" width="280" height="280"` (Cards) |
| `frontend/views/chitiet.php` | Main gallery priority + lazy thumbnails & related | `fetchpriority="high" width="500" height="500"` (Main), `loading="lazy" width="64" height="64"` (Thumbs), `width="200" height="200"` (Freq) |
| `frontend/views/partials/recommendation_step_card.php` | Remove lazy from first step card, optimize alternatives | `fetchpriority="high"` (Step 1), `loading="lazy"` (Steps 2+), `width="200" height="200"`, `width="36" height="36"` |
| `frontend/views/partials/goiy_product_card.php` | Add dimensions, async decoding, lazy modals | `fetchpriority="high"` / `loading="lazy"`, `width="280" height="280"`, modal `loading="lazy" width="64" height="64"` |
| `frontend/views/goiy.php` | Pass `$isAboveFold` and `$isLcp` context to card renderer | Adaptive fold parameters for public collections |
| `frontend/views/flash_sale.php` | Selective deal image loading | `fetchpriority="high"` (Deal 0), `loading="lazy"` (Deals 4+), `width="280" height="280"` |

---

## 2. Test Scripts Added
- `backend/tests/test_p4b_image_loading.js`: Automated HTTP validation for status 200, PHP error absence, mascot cache-busting elimination, and image attribute counts.
- `backend/tests/run_p4b_lighthouse.js`: Automated Lighthouse rebenchmarking runner matching P4A options.
