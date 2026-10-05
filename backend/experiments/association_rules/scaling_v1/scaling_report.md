# SkinSyntaxVN — Association Rule Research
## STEP 5: Progressive Dataset Scaling & Rule-Stability Experiment

> **MANDATORY SCIENTIFIC STATEMENTS:**
> 1. *"The progressive scaling experiment evaluates the stability of association-rule recovery under controlled synthetic assumptions as transaction count and product diversity increase. It does not establish real SkinSyntaxVN customer purchasing behavior."*
> 2. *"Rule persistence across synthetic stages indicates stability under the generator assumptions, not external validity."*
> 
> *Scaling conclusions require larger controlled experiments across multiple dataset sizes; no single transaction-count threshold is a universal scientific requirement.*

---

### 1. Integrity Audit & Baseline Freeze

Before scaling, the original micro-dataset baseline (`S20_B30`) was verified against Step 2 and Step 3 hashes:

| Input File | Baseline Records | SHA-256 Checksum | Integrity Status |
| :--- | :---: | :--- | :---: |
| `baskets_30.json` | 30 baskets | `7443fda76f84ca686bea10fa41fb26566e4da81550ee8a12afa41e910ecae593` | **Frozen & Verified** |
| `selected_products.json` | 20 SKUs | `948d0537b9bc9a1d9efb808b8d81640ba638a76192f680709e3dcf50ff717b9d` | **Frozen & Verified** |
| `transaction_matrix_role.csv` | $30 \times 8$ | `bda5d7c79ba7b465943bccdd1c49db89768ec2cef52f44f5ae48d230aea09d7d` | **Frozen & Verified** |
| `transaction_matrix_sku.csv` | $30 \times 20$ | `c2014664f1c1b96365496d2764f9088f1bdd5bd90caf3e71235823f1368a5af3` | **Frozen & Verified** |

Production database `skinsyntax.san_pham` was verified untouched at **2,473 active products**.

---

### 2. Nested Real Catalog Product Selection

All products were sourced directly from the active SkinSyntaxVN catalog, strictly preserving the nested set hierarchy:
$$\text{Stage S (20 SKUs)} \subset \text{Stage M (40 SKUs)} \subset \text{Stage L (80 SKUs)}$$

| Catalog Metric | Stage S (`S20_B30`) | Stage M (`M40_B100`) | Stage L (`L80_B300`) |
| :--- | :---: | :---: | :---: |
| **Total SKUs** | **20** | **40** | **80** |
| **SKUs Per Role** | 2–3 per role | Exactly **5 per role** | Exactly **10 per role** |
| **Routine Roles Covered** | 8 | 8 | 8 |
| **Unique Brand Count** | 12 | 24 | 38 |
| **Price Min / Max (VND)** | 23,000 / 549,000 | 23,000 / 790,000 | 23,000 / 1,050,000 |
| **Price Median (VND)** | 274,000 | 289,000 | 295,000 |

#### Role Distribution Across Scaling Stages:
- `MAKEUP_REMOVAL`: 2 (S) $\to$ 5 (M) $\to$ 10 (L)
- `CLEANSER`: 3 (S) $\to$ 5 (M) $\to$ 10 (L)
- `TONER`: 2 (S) $\to$ 5 (M) $\to$ 10 (L)
- `SERUM`: 3 (S) $\to$ 5 (M) $\to$ 10 (L)
- `TREATMENT`: 2 (S) $\to$ 5 (M) $\to$ 10 (L)
- `MOISTURIZER`: 3 (S) $\to$ 5 (M) $\to$ 10 (L)
- `SUNSCREEN`: 3 (S) $\to$ 5 (M) $\to$ 10 (L)
- `MASK`: 2 (S) $\to$ 5 (M) $\to$ 10 (L)

---

### 3. Generator V2 Design & Weakened Planting Policy

The generator configuration was frozen and recorded in [`generator_config.json`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/association_rules/scaling_v1/generator_config.json) before pattern mining:

| Parameter | Stage S (`S20_B30`) | Stage M (`M40_B100`) | Stage L (`L80_B300`) |
| :--- | :---: | :---: | :---: |
| **Scenario Name** | `basket_20_products_micro_v1` | `association_scale_40sku_100basket_v1` | `association_scale_80sku_300basket_v1` |
| **Transaction Count ($N$)** | 30 | 100 | 300 |
| **Deterministic Seed** | 42 | 43 | 44 |
| **Planted Routine Probability** | **80%** (Strong adherence) | **65%** (Moderate adherence) | **50%** (Weakened adherence) |
| **Background Noise Probability** | **20%** | **20%** | **25%** |
| **Pure Exploration Probability** | **0%** | **15%** | **25%** |
| **Basket Sizes ($k=1\dots5$)** | Sizes 1–4 (6/12/8/4) | Size 1: 15%, 2: 40%, 3: 25%, 4: 15%, 5: 5% | Size 1: 15%, 2: 35%, 3: 25%, 4: 15%, 5: 10% |

#### Experimental Rationale:
As dataset complexity increases, synthetic planted tendencies are intentionally diluted by background noise and exploratory bundles. This allows the mining algorithms to demonstrate realistic behavior: genuine patterns may attenuate, marginal noise may enter, and weak patterns may shift across significance boundaries.

---

### 4. Dataset Statistics

| Stage | Code | Baskets | SKUs | Roles | Role Matrix Size | SKU Matrix Size |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **Stage S** | `S20_B30` | 30 | 20 | 8 | $30 \times 8$ | $30 \times 20$ |
| **Stage M** | `M40_B100` | 100 | 40 | 8 | $100 \times 8$ | $100 \times 40$ |
| **Stage L** | `L80_B300` | 300 | 80 | 8 | $300 \times 8$ | $300 \times 80$ |

All matrices were verified to contain strictly binary $0/1$ incidence values with zero duplicate SKUs inside any basket.

---

### 5. Tracked-Rule Stability Across Stages

Tracking the 6 planted role-level rules under fixed baseline thresholds ($\text{min\_support} = 0.10$, $\text{min\_confidence} = 0.20$):

| Rule | Stage | $N$ | $N(A \cap B)$ | Support | Confidence | Lift | Status | $\Delta$ Support | $\Delta$ Conf | $\Delta$ Lift |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **`MAKEUP_REMOVAL -> CLEANSER`** | `S20_B30`<br>`M40_B100`<br>`L80_B300` | 30<br>100<br>300 | 7<br>21<br>39 | 0.2333<br>0.2100<br>0.1300 | 0.8750<br>0.6774<br>0.4483 | 1.5441<br>1.2545<br>1.0759 | Generated<br>Generated<br>Generated | —<br>-0.0233<br>-0.0800 | —<br>-0.1976<br>-0.2291 | —<br>-0.2896<br>-0.1786 |
| **`CLEANSER -> TONER`** | `S20_B30`<br>`M40_B100`<br>`L80_B300` | 30<br>100<br>300 | 6<br>13<br>32 | 0.2000<br>0.1300<br>0.1067 | 0.3529<br>0.2407<br>0.2560 | 1.3235<br>0.9630<br>0.9481 | Generated<br>Generated<br>Generated | —<br>-0.0700<br>-0.0233 | —<br>-0.1122<br>+0.0153 | —<br>-0.3605<br>-0.0149 |
| **`CLEANSER -> TREATMENT`** | `S20_B30`<br>`M40_B100`<br>`L80_B300` | 30<br>100<br>300 | 4<br>14<br>43 | 0.1333<br>0.1400<br>0.1433 | 0.2353<br>0.2593<br>0.3440 | 1.1765<br>1.2963<br>1.1596 | Generated<br>Generated<br>Generated | —<br>+0.0067<br>+0.0033 | —<br>+0.0240<br>+0.0847 | —<br>+0.1198<br>-0.1367 |
| **`SERUM -> MOISTURIZER`** | `S20_B30`<br>`M40_B100`<br>`L80_B300` | 30<br>100<br>300 | 5<br>24<br>55 | 0.1667<br>0.2400<br>0.1833 | 0.7143<br>0.6486<br>0.6044 | 1.9481<br>1.3514<br>1.3045 | Generated<br>Generated<br>Generated | —<br>+0.0733<br>-0.0567 | —<br>-0.0657<br>-0.0442 | —<br>-0.5967<br>-0.0469 |
| **`MOISTURIZER -> SUNSCREEN`** | `S20_B30`<br>`M40_B100`<br>`L80_B300` | 30<br>100<br>300 | 5<br>11<br>49 | 0.1667<br>0.1100<br>0.1633 | 0.4545<br>0.2292<br>0.3525 | 1.7045<br>0.9964<br>1.0169 | Generated<br>Generated<br>Generated | —<br>-0.0567<br>+0.0533 | —<br>-0.2253<br>+0.1233 | —<br>-0.7081<br>+0.0205 |
| **`CLEANSER -> MASK`** | `S20_B30`<br>`M40_B100`<br>`L80_B300` | 30<br>100<br>300 | 3<br>21<br>47 | 0.1000<br>0.2100<br>0.1567 | 0.1765<br>0.3889<br>0.3760 | 1.0588<br>1.2963<br>1.3271 | **Filtered**<br>**Generated**<br>**Generated** | —<br>+0.1100<br>-0.0533 | —<br>+0.2124<br>-0.0129 | —<br>+0.2375<br>+0.0308 |

#### Key Analytical Observations for Thesis Defense:
1. **Rule Attenuation Under Progressive Noise:** In `MAKEUP_REMOVAL -> CLEANSER`, as routine probability was reduced from 80% to 50%, Lift decreased smoothly from $1.5441 \to 1.2545 \to 1.0759$. The rule remained positive ($\text{Lift} > 1.0$) but approached independence as random noise expanded.
2. **Persistent Core Affinity:** `SERUM -> MOISTURIZER` maintained high confidence ($71.4\% \to 64.9\% \to 60.4\%$) and strong Lift ($1.95 \to 1.35 \to 1.30$), proving to be the most resilient planted routine across all scaling stages.
3. **Threshold Crossing Dynamic:** In `CLEANSER -> MASK`, confidence was $17.65\%$ in Stage S (filtered by the $20\%$ threshold). In Stage M and Stage L, multi-item routine chains elevated confidence to $38.89\%$ and $37.60\%$, promoting the rule from "Filtered" to "Generated" without artificial threshold adjustment.

---

### 6. Discovered Rule Set Jaccard Overlap

Measuring rule-identity set stability across scaling stages:

| Stage Comparison Pair | Jaccard Similarity | Rules in Set A | Rules in Set B | Intersection $|A \cap B|$ | Union $|A \cup B|$ |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Stage S vs Stage M** | **0.3714** | 13 | 35 | 13 | 35 |
| **Stage M vs Stage L** | **0.5581** | 35 | 32 | 24 | 43 |
| **Stage S vs Stage L** | **0.4062** | 13 | 32 | 13 | 32 |

#### Academic Insight:
- Every rule discovered in Stage S ($13/13$) was retained in both Stage M and Stage L ($100\%$ recall of baseline rules).
- Jaccard similarity increases between Stage M and Stage L ($0.5581$ vs $0.3714$), showing that as sample size grows from $N=100$ to $N=300$, the discovered rule set converges toward a stable asymptotic structure.

---

### 7. SKU-Level Stability Analysis

| Stage | Baskets ($N$) | Catalog Universe | Frequent SKU Itemsets | Discovered SKU Rules | Unique Antecedent SKUs | Unique Consequent SKUs |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **Stage S** (`S20_B30`) | 30 | 20 SKUs | 30 | **18** | 13 | 12 |
| **Stage M** (`M40_B100`) | 100 | 40 SKUs | 94 | **50** | 33 | 25 |
| **Stage L** (`L80_B300`) | 300 | 80 SKUs | 206 | **21** | 15 | 18 |

#### Why SKU Rules Contract at Stage L:
At Stage L, with 80 SKUs and 10 SKUs per role, product diversity disperses basket occurrences across many alternative products. Under fixed threshold $\text{min\_confidence} = 0.30$, fewer individual SKU pairs meet the confidence barrier, demonstrating the severe **data sparsity** of fine-grained SKU recommendations compared to robust role-level patterns.

---

### 8. High-Lift Rare Rule Follow-Up (Small-Sample Artifact Analysis)

Tracking micro-dataset rules that exhibited high Lift at $N=30$:

| Tracked SKU Rule | Stage | $N$ | Count A | Count B | Co-occurrence | Support | Confidence | Lift | Rule Status |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :--- |
| **`SKU 10 -> SKU 93`**<br>*(LRP Anthelios $\to$ Embryolisse)* | `S20_B30`<br>`M40_B100`<br>`L80_B300` | 30<br>100<br>300 | 3<br>4<br>9 | 3<br>8<br>10 | 2<br>0<br>0 | 0.0667<br>0.0000<br>0.0000 | 0.6667<br>0.0000<br>0.0000 | **6.6667**<br>0.0000<br>0.0000 | **Generated**<br>**Absent**<br>**Absent** |
| **`SKU 350 -> SKU 139`**<br>*(Skin1004 Rau Má $\to$ Klairs Blue)* | `S20_B30`<br>`M40_B100`<br>`L80_B300` | 30<br>100<br>300 | 3<br>8<br>6 | 4<br>14<br>19 | 2<br>1<br>0 | 0.0667<br>0.0100<br>0.0000 | 0.6667<br>0.1250<br>0.0000 | **5.0000**<br>0.8929<br>0.0000 | **Generated**<br>**Filtered**<br>**Absent** |
| **`SKU 103 -> SKU 4365`**<br>*(L'Oreal Micellar $\to$ Cosrx Gel)* | `S20_B30`<br>`M40_B100`<br>`L80_B300` | 30<br>100<br>300 | 3<br>4<br>6 | 7<br>14<br>16 | 3<br>1<br>0 | 0.1000<br>0.0100<br>0.0000 | 1.0000<br>0.2500<br>0.0000 | **4.2857**<br>1.7857<br>0.0000 | **Generated**<br>**Filtered**<br>**Absent** |

#### Crucial Research Finding for Defense:
- At $N=30$, `SKU 10 -> SKU 93` achieved an extreme $\text{Lift} = 6.6667$ from just 2 co-occurrences because marginal probabilities were tiny ($P(A) \times P(B) = 0.01$).
- When the catalog expanded to 40 and 80 SKUs, these products co-occurred 0 times. The rule vanished completely.
- **Conclusion:** Extreme Lift on low-frequency items in small samples is predominantly a **small-sample mathematical artifact**. Association-rule engines cannot rely on Lift alone for production ranking.

---

### 9. Apriori vs FP-Growth Consistency Verification

At **all three scaling stages** (S, M, L) and across **both role and SKU levels**:
$$\text{FrequentItemsets}_{\text{Apriori}} \equiv \text{FrequentItemsets}_{\text{FPGrowth}}$$
$$\text{Rules}_{\text{Apriori}} \equiv \text{Rules}_{\text{FPGrowth}}$$
- Support differences: $< 10^{-9}$
- Confidence differences: $< 10^{-9}$
- Lift differences: $< 10^{-4}$
- **Conclusion:** Both algorithms independently reach identical mathematical results regardless of dataset size or itemset dimensionality.

---

### 10. Algorithm Scaling & Runtime Diagnostics (30 Runs Per Stage)

Measured over 30 repeated executions per stage (saved in [`algorithm_scaling.csv`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/association_rules/scaling_v1/algorithm_scaling.csv)):

| Dataset Stage | Scope | Algorithm | Mean (ms) | Median (ms) | Min (ms) | Max (ms) | Std Dev (ms) |
| :--- | :--- | :--- | :---: | :---: | :---: | :---: | :---: |
| **Stage S ($N=30$)** | **Role-Level (8 roles)** | **Apriori** | 0.2409 | 0.2305 | 0.1958 | 0.4236 | 0.0446 |
| **Stage S ($N=30$)** | **Role-Level (8 roles)** | **FP-Growth** | 0.2772 | 0.2584 | 0.2315 | 0.4569 | 0.0495 |
| **Stage S ($N=30$)** | **SKU-Level (20 SKUs)** | **Apriori** | 1.0167 | 0.9845 | 0.9350 | 1.4877 | 0.1124 |
| **Stage S ($N=30$)** | **SKU-Level (20 SKUs)** | **FP-Growth** | 0.4148 | 0.3527 | 0.3239 | 0.7734 | 0.1204 |
| **Stage M ($N=100$)** | **Role-Level (8 roles)** | **Apriori** | 0.6352 | 0.6080 | 0.5854 | 0.9393 | 0.0694 |
| **Stage M ($N=100$)** | **Role-Level (8 roles)** | **FP-Growth** | 0.7245 | 0.6769 | 0.5938 | 1.3042 | 0.1636 |
| **Stage M ($N=100$)** | **SKU-Level (40 SKUs)** | **Apriori** | 8.6522 | 7.8112 | 5.7472 | 16.0237 | 2.4870 |
| **Stage M ($N=100$)** | **SKU-Level (40 SKUs)** | **FP-Growth** | 2.4975 | 2.1594 | 1.2887 | 5.0158 | 1.0151 |
| **Stage L ($N=300$)** | **Role-Level (8 roles)** | **Apriori** | 1.5253 | 1.4777 | 1.0359 | 2.2455 | 0.3314 |
| **Stage L ($N=300$)** | **Role-Level (8 roles)** | **FP-Growth** | 2.3952 | 1.9885 | 1.1576 | 6.7452 | 1.1414 |
| **Stage L ($N=300$)** | **SKU-Level (80 SKUs)** | **Apriori** | **64.8303** | **59.3609** | 44.5130 | 134.0830 | 21.1942 |
| **Stage L ($N=300$)** | **SKU-Level (80 SKUs)** | **FP-Growth** | **4.7994** | **3.3233** | 2.6473 | 46.1895 | 7.8263 |

#### Mechanistic Scaling Insights:
1. **Low Dimensionality (8 Roles):** Apriori is slightly faster than FP-Growth ($1.53\text{ ms}$ vs $2.40\text{ ms}$ at $N=300$). With only 8 items, candidate generation $\binom{8}{2} = 28$ is minimal, making Apriori's lightweight array scanning faster than constructing and traversing prefix-tree node objects.
2. **High Dimensionality (80 SKUs):** At Stage L, candidate generation for $\binom{80}{2} = 3,160$ pairs causes Apriori runtime to surge to **$64.83\text{ ms}$**, whereas FP-Growth executes in **$4.80\text{ ms}$** (**13.5x speedup**). FP-Growth's tree compression avoids candidate explosions entirely.

---

### 11. Validation Audit (All 20 Checks Passed)

Executed via [`validate_step5.py`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/association_rules/scaling_v1/validate_step5.py):

- [x] **1. Original S20_B30 dataset unchanged** (Checksums 100% match)
- [x] **2. Step 3 artifacts unchanged** (All 10 files intact)
- [x] **3. Step 4 artifacts unchanged** (All 14 files intact)
- [x] **4. Production DB unchanged** (`skinsyntax.san_pham` count = 2,473)
- [x] **5. Production source unchanged** (Zero git modifications to production app)
- [x] **6. 20-SKU set is strict subset of 40-SKU set**
- [x] **7. 40-SKU set is strict subset of 80-SKU set**
- [x] **8. Exactly 100 Stage-M baskets**
- [x] **9. Exactly 300 Stage-L baskets**
- [x] **10. Zero duplicate SKUs within any basket**
- [x] **11. All SKUs belong strictly to approved stage universes**
- [x] **12. All 8 routine roles represented in Stage M and Stage L**
- [x] **13. Matrices binary 0/1** (Validated across all 4 CSVs)
- [x] **14. Generator seeds deterministic** (Seed 43 for M, Seed 44 for L)
- [x] **15. Generator parameters frozen before mining**
- [x] **16. Apriori == FP-Growth itemsets at each stage**
- [x] **17. Apriori == FP-Growth rules at each stage**
- [x] **18. Support/confidence/lift numerical equality**
- [x] **19. All 18 rule-stage metrics verified by direct transaction counting**
- [x] **20. No production UI integration** (Zero homepage/cart changes)
