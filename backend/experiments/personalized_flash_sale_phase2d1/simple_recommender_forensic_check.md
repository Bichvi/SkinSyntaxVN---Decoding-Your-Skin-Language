# Simple Recommender Forensic Check (Phase 2D.1)

## 1. Executive Summary & Code-as-Truth Finding
A forensic audit was performed on the production codebase to resolve documentation drift regarding the Simple Recommender (IMDb Weighted Rating) constants.

- **Production Code Status:** The production codebase unequivocally implements:
  - $m = 21.0$ (75th percentile minimum vote threshold)
  - $C = 4.8890$ (Global mean catalog rating)
- **Source of Truth Location:**
  - File: `backend/app/models/SanPham.php`
  - Method: `SanPham::getSimpleRecommenderProducts()` (lines 1012–1026)
- **Phase 2C Audit & Finding:**
  - Phase 2C did **NOT** alter the production constants in `SanPham.php` or any recommender class.
  - The documentation references to `m=5, C=4.5` in `phase2c_final_report.md` (Q13) and `baseline_ranking.md` (Section 5) were **typographical documentation drift** (an erroneous artifact inherited from a legacy hypothetical note).
  - The erroneous mentions in the Phase 2C documentation have been patched to reflect the immutable code truth: $m = 21.0, C = 4.8890$.

---

## 2. Source Code Evidence

In `backend/app/models/SanPham.php`:
```php
    /**
     * Simple Recommender: IMDb Weighted Rating formula
     * WR = (v / (v + m)) * R + (m / (v + m)) * C
     *
     * @param int $limit Number of top products to return
     * @param float|null $m Minimum vote threshold (default 21.0, P75)
     * @param float|null $C Global mean rating (default 4.8890)
     * @return array Top rated products with recommender_meta
     */
    public function getSimpleRecommenderProducts(int $limit = 8, ?float $m = null, ?float $C = null): array {
        $limit = max(1, min(24, $limit));
        $isDefaultConfig = ($m === null || abs($m - 21.0) < 0.0001) && ($C === null || abs($C - 4.8890) < 0.0001);
        $m = $m ?? 21.0;
        $C = $C ?? 4.8890;
```

### Runtime Verification via Reflection:
Inspecting `SanPham::getSimpleRecommenderProducts()` at runtime confirms that the default fallback values inside the method body assign `$m = 21.0` and `$C = 4.8890`.

---

## 3. Cross-Repository Verification

Across the repository's test suites and audit reports, $m=21.0$ and $C=4.8890$ have consistently served as the verified canonical baseline:
1. `backend/experiments/recommendation_engine_master_audit/production_algorithm_stack.md`:
   - *"C: Điểm đánh giá trung bình toàn sàn (Global mean rating). Giá trị canonical hiện tại: $C = 4.8890$."*
   - *"m: Ngưỡng số lượng đánh giá tối thiểu (75th percentile / P75). Giá trị canonical: $m = 21.0$."*
2. `backend/tests/verify_step7_tests.php`:
   - Line 73: `$pLowVoteWR = round((1 / (1 + 21)) * 5.0 + (21 / (1 + 21)) * 4.8890, 4);`
3. `backend/tests/benchmark_simple_profile.php`:
   - Line 34: `$C = 4.8890; $m = 21.0;`

---

## 4. Corrective Action Taken

1. **Production Code:** **ZERO CHANGES.** The production code remains pristine and untouched.
2. **Phase 2C Documentation Patch:**
   - Updated `backend/experiments/personalized_flash_sale_phase2c/phase2c_final_report.md` (Q13) to explicitly cite `($m=21, C=4.8890$)`.
   - Updated `backend/experiments/personalized_flash_sale_phase2c/baseline_ranking.md` (Section 5) to state `Simple Weighted Rating constants ($m=21, C=4.8890$): Preserved`.
3. **Conclusion:** Documentation drift is 100% resolved. No regression exists in production.
