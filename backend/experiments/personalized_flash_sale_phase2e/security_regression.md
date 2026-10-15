# Security, RBAC & Data Boundary Regression Report

## 1. Overview
This audit validates security controls, authorization enforcement, presentation-layer sanitization, and data boundaries for the Personalized Flash Sale feature.

---

## 2. Role-Based Access Control (RBAC) Audit

### Protected Admin Routes
The following endpoints in `QuanTriController` govern Flash Sale administration:
- `admin_flash_sales` (Campaign list)
- `admin_flash_sale_create` (Creation form)
- `admin_flash_sale_edit` (Edit form)
- `admin_flash_sale_save` (Create / Update persistence POST handler)
- `admin_flash_sale_toggle` (Activation switch POST/GET)
- `admin_flash_sale_delete` (Deletion handler)

### Access Control Mechanism
Every admin action invokes:
```php
$this->requireRole(['admin']);
```
- **Authenticated Administrator (`vai_tro = 'admin'`):** Full access granted.
- **Authenticated Customer (`vai_tro = 'khach_hang'`):** Access denied; redirected away with flash error.
- **Unauthenticated Guest (`$_SESSION['user']` empty):** Redirected to login page (`r=dangnhap`).
- **Direct HTTP URL Probing:** Verified (`SEC_03`) — attempting to access admin flash sale routes without admin privileges is strictly blocked.

---

## 3. Input Sanitization & XSS Mitigation
1. **HTML Escaping in Templates**:
   - Campaign names, descriptions, reason tags, and product titles are wrapped in `h(...)` / `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
   - Verified in `frontend/views/admin/flash_sales.php`, `flash_sale_form.php`, `flash_sale_detail.php`, `home.php`, and `flash_sale.php`.
2. **Numeric Field Coercion**:
   - Prices, discounts, product IDs, and timestamps are explicitly sanitized and cast:
     ```php
     $cleanPrice = (int)preg_replace('/[^\d]/', '', $rawPrice);
     ```

---

## 4. Credential & Secret Protection
- MongoDB connection strings (`MONGO_URI`), credentials, and session tokens are strictly loaded via `ss_env()` from protected configuration.
- Zero credentials or environment secrets appear in client logs, public templates, or audit reports.

---

## 5. Algorithmic & Data Boundary Invariants
The audit verified that Personalized Flash Sale strictly adheres to the defined algorithmic boundary (`SEC_01`, `SEC_02`):
- **Collaborative Filtering**: Zero dependency on `TuongTac::WEIGHTS`, Funk MF, BPR, or Item-kNN.
- **Unsupervised Learning**: Zero dependency on KMeans, Apriori, or FP-Growth.
- **Sales Claims**: `so_luong_da_ban` is **never** used as a sales truth or ranking signal in deal ranking.

---

## 6. Audit Verdict
**SECURITY & ACCESS REGRESSION — 100% PASS**.  
Role enforcement is robust, user input is properly escaped, and data boundaries are respected.
