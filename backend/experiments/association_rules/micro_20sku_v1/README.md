# SkinSyntaxVN — Recommendation Research Dataset V2
## STEP 2: 30-Basket Controlled Synthetic Micro-Dataset & Manual Association Validation

> **MANDATORY SCIENTIFIC STATEMENT:**
> *"The 30-basket micro-dataset is intentionally synthetic and small. Its purpose is to make association-rule mathematics manually inspectable before algorithmic mining and larger-scale experiments."*
> 
> *This dataset reflects controlled probabilistic research assumptions, NOT real customer buying behavior or clinical skin compatibility.*

---

### 1. Experiment Overview

| Parameter | Specification |
| :--- | :--- |
| **Scenario Name** | `basket_20_products_micro_v1` |
| **Database Target** | `skinsyntax_research_dev` (MongoDB Atlas) |
| **Collection Target** | `synthetic_baskets` |
| **Source Type** | `synthetic_seed` |
| **Generator Version** | `micro-v1` |
| **Random Seed** | `42` |
| **Total Baskets** | Exactly **30** |
| **Total Products (SKUs)** | Exactly **20** active SKUs from catalog |
| **Skincare Routine Roles** | **8** canonical roles |
| **Production Impact** | **ZERO** (Production database `skinsyntax` & tables `hoa_don`/`chi_tiet_hoa_don` untouched) |

---

### 2. Basket Size Distribution

To facilitate direct manual inspection and verification by researchers and students:
- **Size 1 (Single item):** 6 baskets (20%) — `B001` to `B006` (Noise/spontaneous purchases)
- **Size 2 (Two items):** 12 baskets (40%) — `B007` to `B018` (Primary core routine pairs)
- **Size 3 (Three items):** 8 baskets (26.7%) — `B019` to `B026` (Routine chains & mixed bundles)
- **Size 4 (Four items):** 4 baskets (13.3%) — `B027` to `B030` (Comprehensive regimens)
- **Total:** **30 baskets**
- **Duplicate Rule:** Strictly **zero** duplicate SKU IDs within any basket.

---

### 3. Approved 20-SKU Controlled Catalog

| Role | SKU ID | Product Name | Brand | Price (VND) |
| :--- | :--- | :--- | :--- | :--- |
| `MAKEUP_REMOVAL` | `5` | Nước Tẩy Trang Bioderma Dành Cho Da Nhạy Cảm 500ml | Bioderma | 335,000 |
| `MAKEUP_REMOVAL` | `103` | Nước Tẩy Trang L'Oreal Làm Sạch Sâu Cho Da Dầu 400ml | L'Oreal | 167,000 |
| `CLEANSER` | `4365` | Gel Rửa Mặt Cosrx Tràm Trà, 0.5% BHA Có Độ pH Thấp 150ml | Cosrx | 129,000 |
| `CLEANSER` | `21` | Gel Rửa Mặt La Roche-Posay Dành Cho Da Dầu, Nhạy Cảm 400ml | La Roche-Posay | 412,000 |
| `CLEANSER` | `728` | Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 200ml | Eucerin | 295,000 |
| `TONER` | `4` | Nước Hoa Hồng Klairs Không Mùi Cho Da Nhạy Cảm 180ml | Klairs | 259,000 |
| `TONER` | `240` | Nước Hoa Hồng Simple Làm Dịu Da & Cấp Ẩm 200ml | Simple | 108,000 |
| `SERUM` | `350` | Serum Skin1004 Rau Má Làm Dịu & Hỗ Trợ Phục Hồi Da 55ml | Skin1004 | 299,000 |
| `SERUM` | `68` | Tinh Chất Timeless Chứa Vitamin B5 Phục Hồi Da 30ml | Timeless | 295,000 |
| `SERUM` | `62` | Serum L'Oreal Hyaluronic Acid Cấp Ẩm Sáng Da 30ml | L'Oreal | 289,000 |
| `TREATMENT` | `112` | Gel Dưỡng Megaduo Plus Giảm Mụn, Mờ Thâm 15g | Megaduo | 105,000 |
| `TREATMENT` | `740` | Gel Giảm Mụn Eucerin Dành Cho Mụn Viêm & Không Viêm 40ml | Eucerin | 370,000 |
| `MOISTURIZER` | `139` | Kem Dưỡng Ẩm Klairs Làm Dịu & Phục Hồi Da Ban Đêm 50g | Klairs | 329,000 |
| `MOISTURIZER` | `318` | Kem Dưỡng Hada Labo Dưỡng Ẩm Tối Ưu Cho Da Thường/Khô 50g | Hada Labo | 185,000 |
| `MOISTURIZER` | `93` | Kem Dưỡng Ẩm Embryolisse Lait-Crème Concentré 30ml | Embryolisse | 225,000 |
| `SUNSCREEN` | `16` | Sữa Chống Nắng Anessa Dưỡng Da Kiềm Dầu 60ml (Bản Mới) | Anessa | 549,000 |
| `SUNSCREEN` | `10` | Kem Chống Nắng La Roche-Posay Kiểm Soát Dầu 50ml | La Roche-Posay | 390,000 |
| `SUNSCREEN` | `725` | Sữa Chống Nắng Sunplay Skin Aqua Nắp Xanh Dành Cho Da Dầu 50g | Sunplay Skin Aqua | 102,000 |
| `MASK` | `188` | Mặt Nạ Naruko Tràm Trà Kiểm Soát Dầu Và Giảm Mụn 26ml | Naruko | 23,000 |
| `MASK` | `649` | Mặt Nạ Banobagi Stem Cell Vitamin Mask 30g | Banobagi | 24,000 |

---

### 4. Artifact Manifest

1. `selected_products.json`: Canonical controlled sample definition with brand, role, category, price, and skin suitability metadata.
2. `baskets_30.json`: Complete 30-basket synthetic dataset with transaction metadata, generator parameters, and timestamps.
3. `transactions_readable.csv`: Human-readable 30-row inspection table.
4. `transaction_matrix_sku.csv`: 30 × 20 binary incidence matrix (0/1).
5. `transaction_matrix_role.csv`: 30 × 8 binary role incidence matrix (0/1).
6. `manual_rule_calculations.json`: Exact mathematical evaluation of role-level, SKU-level, asymmetric reverse, and negative control pairs.
7. `manual_rule_report.md`: Comprehensive academic report with step-by-step arithmetic substitution and comparative insights.
8. `validate_step2.py`: Verification harness asserting all 13 experimental invariants.
