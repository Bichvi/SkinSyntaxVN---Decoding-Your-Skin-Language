# Regression Test Results

## 1. Canonical Database Integrity Verification
Verification was executed directly against MongoDB Atlas via the PHP container (`skinsyntax-php`):

```bash
docker exec skinsyntax-php php /var/www/html/tests/check_db_counts.php
```

**Results:**
- `san_pham` count: **`2,473`** (Exact match with canonical baseline)
- `danh_muc` count: **`28`** (Exact match with canonical 28-node taxonomy)
- Canonical DB State: **MATCH (PASS)**
- Database connection mode: **`atlas`**
- No database migrations, deletions, or schema alterations occurred.

---

## 2. HTTP Endpoint Verification
Tested internally via Docker network using `backend/tests/test_http_endpoints.php`:

| Route / Endpoint | Expected HTTP Status | Actual HTTP Status | Result |
| :--- | :---: | :---: | :---: |
| `/index.php?r=home` (Homepage) | `200` | `200` | **PASS** |
| `/index.php?r=admin_categories` (Admin Auth Guard) | `302` (Redirect to `/index.php?r=dangnhap`) | `302` | **PASS** |
| `/index.php?r=tatca` (Full Catalog) | `200` | `200` | **PASS** |
| `/index.php?r=tatca&ma_danh_muc=1` (Category Level 1) | `200` | `200` | **PASS** |
| `/index.php?r=tatca&ma_danh_muc=2002` (Category Level 2) | `200` | `200` | **PASS** |
| `/index.php?r=chitiet&id=69fcf36444bee9190dd05a83` (Product Detail) | `200` | `200` | **PASS** |
| `/index.php?r=live` (Livestream Route) | `200` | `200` | **PASS** |
| `/index.php?r=goiy` (Personalized Routine Route) | `200` | `200` | **PASS** |
| `/index.php?r=giohang` (Shopping Cart) | `200` | `200` | **PASS** |

---

## 3. Recommender & Business Logic Integrity
- Recommendation algorithms (CF, BPR, KMeans, Association Rules, Content-based RAG) were **NOT modified**.
- No taxonomy nodes were altered.
- Real ratings and review counts remain strictly authentic (e.g. `5.0 (229)` from actual MongoDB review collection, displaying "Chưa có đánh giá" when unreviewed).
- Authentic commercial benefits maintained without fabricating unverified policy claims.
