# SKINSYNTAXVN — PHASE P4B: IMPLEMENTATION DETAILS

## 1. Mascot Cache-Busting Optimization (`ai_chat_widget.php`)

### Problem
Previously, `frontend/views/components/ai_chat_widget.php` concatenated PHP `time()` directly to the mascot image URL:
```php
<img src="<?= BASE_URL ?>/assets/images/syna_mascot.png?v=<?= time() ?>" alt="SYNA Mascot">
```
This bypassed browser HTTP caching, transferring the 36KB mascot image on every page navigation and background widget render.

### Implementation
Added safe static version resolution based on physical file timestamp (`filemtime`):
```php
$mascotFilePath = dirname(__DIR__, 2) . '/public/assets/images/syna_mascot.png';
$mascotVersion = is_file($mascotFilePath) ? (string)filemtime($mascotFilePath) : '20261018';
```
Applied in 3 locations:
1. Floating avatar trigger button:
   ```html
   <img src="<?= BASE_URL ?>/assets/images/syna_mascot.png?v=<?= $mascotVersion ?>" alt="SYNA Mascot" width="36" height="36" decoding="async">
   ```
2. Chat panel header avatar:
   ```html
   <img src="<?= BASE_URL ?>/assets/images/syna_mascot.png?v=<?= $mascotVersion ?>" alt="SYNA Mascot" width="40" height="40" decoding="async" style="width: 100%; height: 100%; object-fit: cover; border-radius: 10px;">
   ```
3. Dynamic JavaScript client-side bubble avatar (line 1855):
   ```javascript
   var mascotImgUrl = <?= json_encode(BASE_URL . '/assets/images/syna_mascot.png?v=' . $mascotVersion) ?>;
   ```
**Result**: Browser caches the image permanently with `304 Not Modified` / Memory Cache across all route transitions while ensuring automated cache-invalidation whenever the image asset is updated on disk.

---

## 2. Selective Catalog Image Loading (`tatca.php`)

### Problem
`frontend/views/tatca.php` rendered all 24 catalog products eagerly without `loading="lazy"` or `fetchpriority="high"`.

### Implementation
Loop index tracking applied:
```php
<?php foreach ($items as $idx => $p):
  ...
  $isLcp = ($idx === 0);
  $isAboveFold = ($idx < 4);
?>
```
Image tag attributes:
```html
<img src="<?= h($img ?: default_placeholder_image()) ?>"
     <?= $isLcp ? 'fetchpriority="high"' : ($isAboveFold ? '' : 'loading="lazy"') ?>
     decoding="async"
     width="280"
     height="280"
     referrerpolicy="no-referrer"
     onerror="this.onerror=null;this.src='<?= default_placeholder_image() ?>';"
     alt="<?= h($p['ten_san_pham'] ?? '') ?>"
     style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
```
- First image (`$idx === 0`): `fetchpriority="high"` + `decoding="async"` (NO lazy loading) to guarantee fastest LCP paint.
- Desktop above-the-fold row (`$idx < 4`): standard eager loading + async decoding.
- Below-the-fold products (`$idx >= 4`): `loading="lazy"` + `decoding="async"`.
- Dimensions `width="280" height="280"` establish the 1:1 aspect ratio before bitmap decoding, preventing Cumulative Layout Shift (CLS).

---

## 3. Homepage Card & Hero Mascot Optimization (`home.php`)

### Implementation
1. **Hero Mascot (`syna_listening.png`)**:
   - Identified as Desktop LCP element.
   - Added:
     ```html
     <img src="<?= BASE_URL ?>/assets/images/syna_listening.png"
          alt="SYNA Mascot"
          class="syna-mascot-img state-idle"
          id="synaMascotImg"
          fetchpriority="high"
          decoding="async"
          width="300"
          height="360">
     ```
2. **Product Cards (`$renderHomeProductCard`)**:
   - Updated signature to accept `$isAboveFold = false` and `$isLcp = false`.
   - Below-the-fold carousel cards default to `loading="lazy"` and `decoding="async"`.
   - Explicit `width="280" height="280"` matches the CSS `aspect-ratio: 1/1` container.
3. **Flash Sale Cards (`$renderFlashSaleProductCard`)**:
   - Signature updated with `$isAboveFold = false, $isLcp = false`.
   - Images default to `loading="lazy"` + `decoding="async"` + `width="280" height="280"`.
4. **Live Product Thumbnail**:
   - Added `loading="lazy" decoding="async" width="58" height="58"`.

---

## 4. Product Detail Page Optimization (`chitiet.php`)

### Implementation
1. **Hero Image (`#mainImage`)**:
   - Above-the-fold LCP image.
   - Added `fetchpriority="high" decoding="async" width="500" height="500"`.
2. **Gallery Thumbnails (`.thumb-btn img`)**:
   - Added `loading="lazy" decoding="async" width="64" height="64"`.
3. **Frequently Bought Together Cards**:
   - Added `loading="lazy" decoding="async" width="200" height="200"`.
4. **Verified Customer Review Images**:
   - Added `loading="lazy" decoding="async" width="80" height="80"`.

---

## 5. Recommendation Workflow Optimization (`goiy.php` & Partials)

### Implementation
1. **Routine Step Card (`recommendation_step_card.php`)**:
   - Evaluated `$isFirstStepCard = (($index ?? 0) === 0 && ($session ?? 'am') === 'am')`.
   - First step card gets `fetchpriority="high"` (removing the detrimental `loading="lazy"` on above-the-fold LCP).
   - Subsequent step cards retain `loading="lazy" decoding="async" width="200" height="200"`.
   - Alternative options updated with `width="36" height="36" decoding="async"`.
2. **Recommendation Cards (`goiy_product_card.php`)**:
   - Added `$isAboveFold` and `$isLcp` parameters.
   - RCM and Default cards render with `width="280" height="280"`.
   - Offscreen explanation modal images render with `loading="lazy" decoding="async"` (`width="64" height="64"` and `width="75" height="75"`).

---

## 6. Flash Sale Page (`flash_sale.php`)

### Implementation
- Added `$dealIdx` tracking.
- Deal 0: `fetchpriority="high" decoding="async" width="280" height="280"`.
- Deals 1–3: `decoding="async" width="280" height="280"`.
- Deals 4+: `loading="lazy" decoding="async" width="280" height="280"`.
