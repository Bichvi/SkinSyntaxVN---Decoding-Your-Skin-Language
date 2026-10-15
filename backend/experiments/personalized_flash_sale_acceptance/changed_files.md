# Flash Sale Final Acceptance — Changed Files Inventory

**Audit Date:** 2026-10-10  
**Role:** Senior Full-Stack QA / PHP / MongoDB Engineer  
**Status:** **INSPECTED & AUDITED**

---

## 1. Overview of Code Modifications

This stabilization and acceptance testing phase performed **zero unauthorized feature additions**. The inventory below details the established implementation files and verification artifacts comprising the Personalized Flash Sale subsystem.

---

## 2. Inventory of Subsystem Files

### Core Backend Models & Logic
1. `backend/app/models/FlashSaleCampaign.php` (New / Subsystem Core)
   - Encapsulates Flash Sale domain model: lifecycle status calculation (`UPCOMING`, `ACTIVE`, `ENDED`, `DISABLED`), strict UTC/Asia-Ho_Chi_Minh time handling, interval overlap validation, and the centralized effective pricing engine (`resolveEffectivePrice()`).
2. `backend/app/models/PersonalizedDealRanker.php` (New / Ranking Adapter)
   - Provides candidate-only personalized ranking for Flash Sale campaigns. Evaluates only eligible campaign items across 4 operational modes (`BASELINE`, `BEHAVIOR`, `PROFILE`, `HYBRID`) and generates grounded Vietnamese reason tags without mutating candidate prices or leaking outside items.
3. `backend/app/controllers/HomeController.php` (Updated)
   - Hydrates active Flash Sale campaigns for the Homepage Carousel, applies `PersonalizedDealRanker` once per page request, and enforces checkout price reconfirmation in `xulydathang()`.
4. `backend/app/controllers/QuanTriController.php` (Updated)
   - Dispatches Admin Flash Sale CRUD actions: `admin_flash_sales`, `admin_flash_sale_create`, `admin_flash_sale_edit`, `admin_flash_sale_save`, `admin_flash_sale_toggle`, `admin_flash_sale_delete`, `admin_flash_sale_detail`, and `api_admin_flash_sale_search_products`.
5. `backend/app/models/HoaDon.php` (Updated)
   - Integrates `fetchProductPrice()` with `FlashSaleCampaign::resolveEffectivePrice()` so order line totals and database invoices strictly reflect server-derived promotional prices at the moment of checkout.

### Presentation Views & Helpers
6. `frontend/views/home.php` (Updated)
   - Implements the responsive 8/4/2 Homepage Flash Sale Carousel with pure DOM-based client pagination, live countdown timer, and personalized reason badges.
7. `frontend/views/flash_sale.php` (New)
   - Public "All Deals" showcase page supporting dynamic candidate sorting (`for_you`, `featured`, `discount`, `rating`, `price_asc`, `price_desc`).
8. `frontend/views/admin/flash_sales.php` (New)
   - Admin listing view displaying campaign table, status badges, item counts, and toggle/action controls.
9. `frontend/views/admin/flash_sale_form.php` (New)
   - Admin campaign wizard featuring Vietnamese diacritic-insensitive AJAX product autocomplete and multi-item discount configuration.
10. `frontend/views/admin/flash_sale_detail.php` (New)
    - Admin read-only campaign inspection view.
11. `frontend/views/helpers.php` (Updated)
    - Role authorization map registration and price display helper functions.

### Web Entry Points & Routing
12. `index.php` (Root) & `backend/public/index.php` (Updated)
    - Synchronized dispatch routes for both public (`r=flash_sale`) and admin (`r=admin_flash_sales*`, `r=api_admin_flash_sale_search_products`) endpoints.

---

## 3. Acceptance Testing Artifacts

Directory: `backend/experiments/personalized_flash_sale_acceptance/`
- `precheck.md` — Step 1 environment, database, and route precheck audit.
- `admin_acceptance.md` — Step 2 admin CRUD, search, and scale acceptance test report.
- `carousel_acceptance.md` — Step 3 responsive carousel, Playwright browser test report.
- `personalization_acceptance.csv` — Step 4 candidate ranking across 5 controlled contexts.
- `price_integrity.md` — Step 5 end-to-end pricing trace, tampering, and expiration report.
- `security_review.md` — Step 7A authorization, input sanitization, and CSRF analysis.
- `performance.md` — Step 7B measured latency benchmarks and query counts.
- `regression.md` — Step 6 preservation of platform invariants and catalog counts.
- `changed_files.md` — Step 9 this inventory of changed and untracked files.
- `final_acceptance_report.md` — Executive summary and final acceptance verdict.

---

## 4. Git Hygiene & Security Verification

- **Git Status:** All changes are localized within expected controller, model, and view files.
- **Credential Hygiene:** Verified zero database passwords, API secrets, or session dumps were staged or committed into Git history.
- **Catalog State:** `san_pham` count strictly maintained at **2,473**; `danh_muc` count strictly maintained at **28**.
