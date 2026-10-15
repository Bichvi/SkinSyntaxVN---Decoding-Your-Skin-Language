# SKINSYNTAXVN — FLASH SALE PERSONALIZED CAROUSEL UX EXTENSION
## Implementation Details: Personalized Carousel Architecture & Responsive Grid

**Date:** 2026-10-08  
**Engineer:** Senior PHP/MongoDB Full-Stack Engineer  
**Files Modified:**  
1. `frontend/views/admin/flash_sale_form.php`
2. `frontend/views/home.php`
3. `scratch/test_phase2d2_automation.php`

---

### 1. Architecture Overview

The Flash Sale Homepage experience has been upgraded from a static 4-product slice into a **Personalized Carousel UX Extension** that displays all valid candidate products selected by Admin in the active campaign.

```
                    Admin Flash Sale Campaign (N SKUs, recommended 24-40)
                                      │
                                      ▼
                    HomeController::index()
                      - hydrateCampaignItems() [Single $in query, 0 N+1]
                      - rankCampaignCandidates() [Server-side ranking once/request]
                                      │
                                      ▼
                    frontend/views/home.php
                      - Full candidate array ($flashSaleProducts) passed to view
                      - Responsive Grid Layout:
                          * Desktop (>= 1200px): 8 cards (4 cols x 2 rows)
                          * Tablet (768 - 1199px): 4 cards (2 cols x 2 rows)
                          * Mobile (< 768px): 2 cards (2 cols x 1 row)
                      - Client-side Carousel Controller:
                          * Instant page toggling via CSS display
                          * Boundary disable on Prev/Next
                          * Page indicator: "Trang X / Y (Tổng N SP)"
                          * Keyboard navigation (ArrowLeft / ArrowRight)
                          * Mobile touch swipe gesture detection
                          * Zero reranking or backend requests on arrow clicks
```

---

### 2. Admin Form Operational Guidance Upgrade

In `frontend/views/admin/flash_sale_form.php`:
1. Updated guidance notice:
   ```html
   <div class="alert alert-light border py-2 px-3 mb-3 d-flex align-items-center justify-content-between small text-muted" style="border-radius: 6px; font-size: 0.82rem;">
       <span><i class="bi bi-lightbulb-fill text-warning me-1"></i>Khuyến nghị vận hành: <strong>24–40 sản phẩm</strong> để đảm bảo trải nghiệm carousel phân trang đa dạng và tối ưu.</span>
       <span id="recommendationStatus" class="fw-semibold"></span>
   </div>
   ```
2. Updated dynamic counter thresholds:
   ```javascript
   function updateCounters() {
       const count = selectedProductIds.size;
       productCountNum.textContent = count;

       if (count < 24) {
           recommendationStatus.innerHTML = `<span class="text-warning"><i class="bi bi-dash-circle me-1"></i>Dưới mức khuyến nghị (${count}/24 SP)</span>`;
       } else if (count <= 40) {
           recommendationStatus.innerHTML = `<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Phạm vi tối ưu (${count} SP)</span>`;
       } else {
           recommendationStatus.innerHTML = `<span class="text-info"><i class="bi bi-info-circle me-1"></i>Vượt mức khuyến nghị (${count} SP)</span>`;
       }
   }
   ```
   *Note:* The guidance is advisory only; form submission remains completely unconstrained for any $N \ge 1$ distinct valid SKUs.

---

### 3. Homepage View & Carousel Implementation

In `frontend/views/home.php`:

#### 3.1 Candidate Array Retention
Eliminated the previous `applyTop4Diversity(..., 4)` slice:
```php
$flashSaleCampaign = $primaryFlashCampaign ?? null;
$flashSaleMeta = $flashSalePersonalizedMeta ?? ['is_personalized' => false, 'ranking_mode' => 'BASELINE', 'user_state' => 'STATE_A'];
$flashSaleProducts = [];
if (!empty($flashSaleCampaign) && !empty($flashSaleCampaign['hydrated_items'])) {
    // Phase 2E Carousel Extension: Retain full ranked candidate set from active campaign
    $flashSaleProducts = $flashSaleCampaign['hydrated_items'];
}
```

#### 3.2 Carousel Header Controls
Rendered interactive page counter and Prev/Next controls:
```html
<div class="d-flex align-items-center gap-2">
  <!-- Page indicator & total count -->
  <div class="d-flex align-items-center gap-1 text-white-50 small me-1" style="font-size: 0.82rem; font-variant-numeric: tabular-nums;">
    <span>Trang</span> <strong class="text-white" id="flashCarouselCurrentPage">1</strong>/<span class="text-white" id="flashCarouselTotalPages">1</span>
    <span class="text-white-50 ms-1">(<span id="flashCarouselTotalCount"><?= count($flashSaleProducts) ?></span> SP)</span>
  </div>

  <!-- Carousel Prev / Next Controls -->
  <div class="btn-group" role="group" aria-label="Điều hướng danh sách Flash Sale">
    <button type="button" class="btn btn-sm btn-outline-light flash-carousel-nav-btn" id="btnFlashPrev" aria-label="Trang trước" title="Trang trước" disabled style="border-radius: 8px 0 0 8px; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-color: rgba(255,255,255,0.35);">
      <i class="fas fa-chevron-left"></i>
    </button>
    <button type="button" class="btn btn-sm btn-outline-light flash-carousel-nav-btn" id="btnFlashNext" aria-label="Trang sau" title="Trang sau" style="border-radius: 0 8px 8px 0; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-color: rgba(255,255,255,0.35);">
      <i class="fas fa-chevron-right"></i>
    </button>
  </div>

  <a href="<?= BASE_URL ?>/index.php?r=flash_sale" class="btn btn-light px-3.5 py-2 fw-semibold text-nowrap ms-1" style="color: #183B2B; border-radius: 8px; font-size: 0.84rem; background: #FFF; border: none;">Xem tất cả deal &rarr;</a>
</div>
```

#### 3.3 Responsive Grid Styles
```css
.flash-sale-carousel-viewport {
  position: relative;
  width: 100%;
  outline: none;
}
.flash-sale-carousel-viewport:focus-visible {
  outline: 2px solid rgba(254, 240, 138, 0.7);
  outline-offset: 4px;
  border-radius: 12px;
}
.flash-sale-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
  width: 100%;
}
.flash-sale-item {
  width: 100%;
  display: block;
}
.flash-sale-item.d-none {
  display: none !important;
}
.flash-sale-item.flash-card-entering {
  animation: flashCardFadeIn 0.22s ease-out forwards;
}
@keyframes flashCardFadeIn {
  from {
    opacity: 0;
    transform: translateY(6px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
@media (max-width: 1199.98px) {
  .flash-sale-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
  }
}
@media (max-width: 767.98px) {
  .flash-sale-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }
}
```

#### 3.4 Client-Side Controller Logic
- **Paging Math:**
  - Desktop ($\ge 1200\text{px}$): `pageSize = 8` (4 cols $\times$ 2 rows).
  - Tablet ($768\text{px} - 1199\text{px}$): `pageSize = 4` (2 cols $\times$ 2 rows).
  - Mobile ($< 768\text{px}$): `pageSize = 2` (2 cols $\times$ 1 row).
  - Total pages: `Math.max(1, Math.ceil(totalItems / pageSize))`.
- **Button State Invariant:**
  - `btnPrev.disabled = (currentPage <= 0)`
  - `btnNext.disabled = (currentPage >= totalPages - 1)`
  - No cyclic wrap-around; no autoplay.
- **Interactions:**
  - Click navigation on Prev/Next.
  - Keyboard navigation: ArrowLeft (prev), ArrowRight (next) on focused viewport.
  - Mobile touch gestures: horizontal swipe with $|\Delta X| > 40\text{px}$ and dominant over vertical scroll.
  - Window resize: recalculates page count and clamps `currentPage` within valid range.
  - Zero network requests or reranking triggered on navigation.
