# Flash Sale Final Acceptance — Step 1: Precheck Report

**Audit Date:** 2026-10-09  
**Role:** Senior Full-Stack QA / PHP / MongoDB Engineer  
**Status:** **PASSED**

---

## 1. Environment & Database Verification

| Parameter | Expected | Measured / Actual | Status |
| :--- | :--- | :--- | :--- |
| **Docker Web Entry Point** | Nginx -> PHP-FPM (`skinsyntax-php`) | `skinsyntax-nginx` (port 80) -> `skinsyntax-php:9000` | **PASS** |
| **Route Synchronization** | `index.php` (root) == `backend/public/index.php` | Both dispatch identical Flash Sale routes | **PASS** |
| **DB_MODE** | `atlas` | `atlas` | **PASS** |
| **Database Name** | `skinsyntax` | `skinsyntax` | **PASS** |
| **Catalog Count (`san_pham`)** | `2473` | `2473` | **PASS** |
| **Category Count (`danh_muc`)** | `28` | `28` | **PASS** |
| **Existing Campaigns (`flash_sale_campaigns`)** | Documented & Preserved | `1` campaign (`ma_campaign=1`, "Siêu sao", status: `ENDED`) | **PASS** |
| **Active Campaigns** | `0` currently active (`ENDED`) | `0` active (`getPrimaryActiveCampaign() == null`) | **PASS** |
| **Stale QA Campaigns** | None | None (Only 1 existing baseline campaign) | **PASS** |

---

## 2. Admin & API Route Inventory

The following Flash Sale endpoints were verified in both `backend/public/index.php` and root `index.php`:
1. `r=admin_flash_sales` — Campaign listing view with server-derived status badges.
2. `r=admin_flash_sale_create` — Campaign creation wizard with dynamic product picker.
3. `r=admin_flash_sale_edit` — Campaign update view.
4. `r=admin_flash_sale_save` — POST handler with CSRF and business rule validation.
5. `r=admin_flash_sale_toggle` — POST/GET toggle handler for active/inactive switch.
6. `r=admin_flash_sale_delete` — Campaign deletion handler (prevents deleting ACTIVE campaigns).
7. `r=admin_flash_sale_detail` — Read-only campaign inspection view.
8. `r=api_admin_flash_sale_search_products` — AJAX autocomplete with Vietnamese diacritic-insensitive search.
9. `r=flash_sale` — Public All Deals page with dynamic candidate hydration and sorting.

---

## 3. Precheck Verdict

All environment checks, database credentials, collections, and routing tables strictly match requirements. System is ready for STEP 2 (Admin Acceptance).
