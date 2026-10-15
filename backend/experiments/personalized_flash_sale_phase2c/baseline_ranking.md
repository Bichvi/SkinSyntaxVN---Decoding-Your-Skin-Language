# Baseline Deal Ranking Specification (Phase 2C)

## 1. Scope & Objective
Phase 2C strictly mandates a **deterministic, non-personalized baseline ranking** for all public Flash Sale presentations. Every user—regardless of logged-in status, skin survey profile, viewing history, search history, or past cart items—receives the **exact same baseline ordering**.

Personalization algorithms ("Dành cho bạn", skin-type affinity, behavioral weights) are explicitly reserved for **Phase 2D**.

---

## 2. Deterministic Baseline Ranking Formula

For the default view ("Ưu đãi nổi bật") on both the Homepage Top 4 and the All Deals page, items are ordered lexicographically using verified, catalog-grounded evidence:

```
ORDER BY:
  1. discount_percent DESC    (Strongest savings incentive first)
  2. diem_danh_gia DESC        (Product quality score from real catalog, fallback 0.0)
  3. so_luong_danh_gia DESC    (Review evidence volume, fallback 0)
  4. ma_san_pham ASC          (Deterministic canonical tie-breaker)
```

### Implementation in `FlashSaleCampaign::sortBaselineRanking()`:
```php
usort($items, static function (array $a, array $b): int {
    // 1. Discount strength descending
    $pctA = (int)($a['discount_percent'] ?? 0);
    $pctB = (int)($b['discount_percent'] ?? 0);
    if ($pctA !== $pctB) {
        return $pctB <=> $pctA;
    }

    // 2. Rating score descending
    $ratingA = (float)($a['diem_danh_gia'] ?? 0.0);
    $ratingB = (float)($b['diem_danh_gia'] ?? 0.0);
    if (abs($ratingA - $ratingB) > 0.0001) {
        return $ratingB <=> $ratingA;
    }

    // 3. Review count descending
    $countA = (int)($a['so_luong_danh_gia'] ?? 0);
    $countB = (int)($b['so_luong_danh_gia'] ?? 0);
    if ($countA !== $countB) {
        return $countB <=> $countA;
    }

    // 4. Stable canonical tie-breaker
    return (int)($a['ma_san_pham'] ?? 0) <=> (int)($b['ma_san_pham'] ?? 0);
});
```

---

## 3. All Deals Sort Options

On `index.php?r=flash_sale`, five user-selectable sort modes are provided:

| Sort Key | UI Label | Ordering Mechanism | Notes |
| :--- | :--- | :--- | :--- |
| `featured` (Default) | Ưu đãi nổi bật | Baseline ranking (`discount_percent DESC`, `rating DESC`, `id ASC`) | Same deterministic order as Homepage Top 4 |
| `discount` | Giảm nhiều nhất | `discount_percent DESC`, then `ma_san_pham ASC` | Strictly campaign-derived discounts (not legacy fields) |
| `rating` | Đánh giá tốt | `diem_danh_gia DESC`, `so_luong_danh_gia DESC`, `ma_san_pham ASC` | Evidence-aware rating sort |
| `price_asc` | Giá thấp &rarr; cao | `flash_price ASC`, then `ma_san_pham ASC` | Uses **EFFECTIVE FLASH PRICE**, not regular base price |
| `price_desc` | Giá cao &rarr; thấp | `flash_price DESC`, then `ma_san_pham ASC` | Uses **EFFECTIVE FLASH PRICE**, not regular base price |

---

## 4. Strict Popularity & Anti-Urgency Boundaries

1. **No `so_luong_da_ban` Ranking:**
   - Real order coverage across the full 2,473 products remains sparse.
   - Ranking or sorting by `so_luong_da_ban` is strictly prohibited.
2. **No Deceptive Badges:**
   - Public UI does **NOT** display labels such as "Bán chạy", "Mua nhiều", or "Đã bán X".
   - Products with high ratings are labeled honestly as "Được đánh giá tốt" or show verified star scores (`diem_danh_gia`).
3. **No Fake Scarcity / Progress Bars:**
   - All decorative progress meters that imply false sales scarcity (e.g. "Đã bán 80%") have been completely removed.
   - Legitimate stock is only indicated via real inventory status ("Còn hàng" / "Tạm hết hàng").

---

## 5. Recommender Systems Preservation

To maintain complete architecture stability:
- **No recommender algorithms were modified or touched:**
  - `ContentBasedRecommender`: Untouched.
  - `Adaptive Hybrid`: Untouched.
  - Simple Weighted Rating constants ($m=21, C=4.8890$): Preserved.
  - Collaborative Filtering, BPR, KMeans, Apriori, FP-Growth: Untouched.
- Phase 2C solely reads canonical product attributes (`gia_ban`, `diem_danh_gia`, `so_luong_danh_gia`) and campaign-level inputs without modifying machine learning models.
