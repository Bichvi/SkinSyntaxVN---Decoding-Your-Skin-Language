# Sort Validation & Candidate Retention Report

## 1. Overview
This report validates candidate universe preservation and explicit sorting semantics on the **All Deals** page (`r=flash_sale`). 

### Core Architectural Principles
1. **Candidate Invariant**: All 16 candidate SKUs provisioned in the active campaign must remain available regardless of user context or sorting mode. Personalization may **only** reorder candidates; it must never filter out or suppress eligible deals.
2. **Explicit User Intent Override**: When a user selects an explicit sort control (`discount`, `rating`, `price_asc`, `price_desc`), the system must completely bypass personalized ranking and enforce the requested mathematical ordering.

---

## 2. Default Sort Policy Audit

| User State | Usable Context Present? | Default Sort Assigned | UI Label Displayed | Candidate Count |
|---|---|---|---|---|
| **Guest Context** | No (profile=null, behavior=[]) | `featured` | **Ưu đãi nổi bật** | 16 / 16 (100%) |
| **Logged-in Cold Start** | No (unanswered survey, 0 interactions)| `featured` | **Ưu đãi nổi bật** | 16 / 16 (100%) |
| **Active Behavioral Context** | Yes (recent searches/views) | `for_you` | **Dành cho bạn** | 16 / 16 (100%) |
| **Profile Context** | Yes (skin_type / budget recorded) | `for_you` | **Dành cho bạn** | 16 / 16 (100%) |
| **Hybrid Context** | Yes (profile + behavior) | `for_you` | **Dành cho bạn** | 16 / 16 (100%) |

**Finding**: No deceptive claims. Guests and cold-start users are served the transparent baseline sort "Ưu đãi nổi bật". "Dành cho bạn" is only activated when real, grounded context exists.

---

## 3. Explicit Sort Overrides Validation

### 3.1 Sort: Giảm nhiều nhất (`sort=discount`)
- **Ordering Rule**: Strict monotonic descending order by `discount_percent`. Tie-breaking by `ma_san_pham ASC`.
- **Personalization Influence**: Completely bypassed ($0\%$).
- **Verified Order (16 Candidates)**:

| Rank | SKU | Product Name | Regular Price | Flash Price | Discount (%) | Monotonicity Check |
|---|---|---|---|---|---|---|
| 1 | `62` | Tinh Chất Đặc Trị Mụn | 290,000 đ | 145,000 đ | **50%** | PASS |
| 2 | `1772` | Nước Tẩy Trang B.O.M Từ 8 Loại Trà 500ml | 241,000 đ | 120,500 đ | **50%** | PASS (Tie-break) |
| 3 | `54` | Nước Tẩy Trang Bí Đao Cocoon 500ml | 189,000 đ | 103,950 đ | **45%** | PASS ($\le 50$) |
| 4 | `1176` | Tinh Chất Serum Phục Hồi Da | 63,000 đ | 34,650 đ | **45%** | PASS (Tie-break) |
| 5 | `728` | Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 200ml | 295,000 đ | 177,000 đ | **40%** | PASS ($\le 45$) |
| 6 | `2328` | Mặt Nạ Ngủ Môi Care:Nel Chanh 5g | 44,000 đ | 26,400 đ | **40%** | PASS (Tie-break) |
| 7 | `797` | Nước Tẩy Trang Cocoon Bí Đao 140ml | 93,000 đ | 60,450 đ | **35%** | PASS ($\le 40$) |
| 8 | `1027` | Mặt Nạ Ngủ Môi Care:Nel Quả Lựu 23g | 140,000 đ | 91,000 đ | **35%** | PASS (Tie-break) |
| 9 | `4365` | Gel Rửa Mặt Cosrx Tràm Trà, 0.5% BHA | 129,000 đ | 83,850 đ | **35%** | PASS (Tie-break) |
| 10 | `71` | Son Dưỡng Môi DHC Không Màu Giảm Thâm | 165,000 đ | 115,500 đ | **30%** | PASS ($\le 35$) |
| 11 | `755` | Serum Dưỡng Sáng Mờ Thâm | 204,000 đ | 142,800 đ | **30%** | PASS (Tie-break) |
| 12 | `1000` | Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 75ml | 163,000 đ | 114,100 đ | **30%** | PASS (Tie-break) |
| 13 | `266` | Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 400ml | 454,000 đ | 340,500 đ | **25%** | PASS ($\le 30$) |
| 14 | `1296` | Mặt Nạ Ngủ Môi Care:Nel Quả Mọng 5g | 41,000 đ | 30,750 đ | **25%** | PASS (Tie-break) |
| 15 | `68` | Serum Timeless Vitamin B5 Làm Dịu 30ml | 357,000 đ | 285,600 đ | **20%** | PASS ($\le 25$) |
| 16 | `569` | Mặt Nạ Ngủ Môi Care:Nel Quả Lựu 5g | 41,000 đ | 32,800 đ | **20%** | PASS (Tie-break) |

*Result: 100% Monotonic Descending Verified.*

---

### 3.2 Sort: Giá thấp → cao (`sort=price_asc`)
- **Ordering Rule**: Strict monotonic ascending order by `effective_price`. Tie-breaking by `ma_san_pham ASC`.
- **Prices Used**: Transactional effective flash price, NOT base regular price.
- **Verified Price Sequence**:
  `26,400 đ` (`2328`) $\to$ `30,750 đ` (`1296`) $\to$ `32,800 đ` (`569`) $\to$ `34,650 đ` (`1176`) $\to$ `60,450 đ` (`797`) $\to$ `83,850 đ` (`4365`) $\to$ `91,000 đ` (`1027`) $\to$ `103,950 đ` (`54`) $\to$ `114,100 đ` (`1000`) $\to$ `115,500 đ` (`71`) $\to$ `120,500 đ` (`1772`) $\to$ `142,800 đ` (`755`) $\to$ `145,000 đ` (`62`) $\to$ `177,000 đ` (`728`) $\to$ `285,600 đ` (`68`) $\to$ `340,500 đ` (`266`).

*Result: 100% Monotonic Ascending Verified.*

---

### 3.3 Sort: Giá cao → thấp (`sort=price_desc`)
- **Ordering Rule**: Strict monotonic descending order by `effective_price`.
- **Verified Price Sequence**:
  `340,500 đ` (`266`) $\to$ `285,600 đ` (`68`) $\to$ `177,000 đ` (`728`) $\to$ `145,000 đ` (`62`) $\to$ `142,800 đ` (`755`) $\to$ `120,500 đ` (`1772`) $\to$ `115,500 đ` (`71`) $\to$ `114,100 đ` (`1000`) $\to$ `103,950 đ` (`54`) $\to$ `91,000 đ` (`1027`) $\to$ `83,850 đ` (`4365`) $\to$ `60,450 đ` (`797`) $\to$ `34,650 đ` (`1176`) $\to$ `32,800 đ` (`569`) $\to$ `30,750 đ` (`1296`) $\to$ `26,400 đ` (`2328`).

*Result: 100% Monotonic Descending Verified.*

---

### 3.4 Sort: Đánh giá tốt (`sort=rating`)
- **Ordering Rule**: Canonical Bayesian Weighted Rating ($WR = \frac{v}{v+m}R + \frac{m}{v+m}C$ with $m=21.0, C=4.8890$).
- **Top 1 Item**: SKU `1000` (Eucerin Gel Rửa Mặt 75ml, $WR=4.9907, v=229, R=5.0$).

*Result: Canonical Rating Order Verified.*

---

## 4. Audit Verdict
**SORT CONTROLS & CANDIDATE RETENTION — 100% PASS**.  
- Candidate universe remains strictly invariant ($16/16$).
- Zero candidates disappear due to personalization.
- Explicit user sort overrides strictly supersede personalization.
