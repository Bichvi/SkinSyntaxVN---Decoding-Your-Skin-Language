# Flash Sale Final Acceptance — Step 2: Admin Acceptance Report

**Audit Date:** 2026-10-09  
**Role:** Senior Full-Stack QA / PHP / MongoDB Engineer  
**Status:** **PASSED**

---

## 1. Scope & Execution Overview

All Admin Flash Sale capabilities were evaluated using real catalog products (`san_pham`) in isolated QA execution against MongoDB Atlas. Baseline production campaign `ma_campaign=1` ("Siêu sao") was kept untouched throughout the suite.

All created QA campaigns were cleaned up automatically after assertions.

---

## 2. Test Execution Matrix

| Test ID | Area / Objective | Test Input & Scenario | Expected Result | Measured Result | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **ADM-01** | Vietnamese Accented Search | `q="dưỡng ẩm"`, limit 5 | Returns relevant moisturizer products | 5 products returned; e.g. SKU 12 "Kem Dưỡng La Roche-Posay Giúp Phục Hồi Da..." | **PASS** |
| **ADM-02** | Vietnamese Unaccented Search | `q="duong am"`, limit 5 | Diacritic-insensitive match matching ADM-01 | 5 products returned; 100% matched set with ADM-01 | **PASS** |
| **ADM-03** | Exact SKU Search | `q="10"`, limit 5 | Returns exact product `#10` | Exact product `#10` returned: "Kem Chống Nắng La Roche-Posay Phổ Rộng..." (412.000 đ) | **PASS** |
| **ADM-04** | Duplicate SKU Rejection | Same SKU (`#10`) added twice in single payload | Payload rejected with duplicate SKU error | Rejected with message: *"Sản phẩm #10 bị trùng lặp bên trong cùng một chiến dịch."* | **PASS** |
| **ADM-05** | Zero Price Rejection | `flash_price = 0` | Payload rejected with zero price error | Rejected with message: *"Giá Flash Sale của sản phẩm #10 phải lớn hơn 0."* | **PASS** |
| **ADM-06** | Invalid Price Rejection | `flash_price = regular_price + 1000` (413.000 vs 412.000) | Payload rejected with price threshold error | Rejected with message: *"Giá Flash Sale (413.000 đ) phải nhỏ hơn giá niêm yết (412.000 đ) của sản phẩm #10."* | **PASS** |
| **ADM-07** | Interval Conflict / Overlap | Overlapping active interval for product `#10` | Payload rejected with overlap conflict error | Rejected with message: *"Sản phẩm #10 đã tham gia chiến dịch 'QA_TEST_OVERLAP_BASE' (#2) có khung giờ trùng lặp."* | **PASS** |
| **ADM-08** | Campaign Scale — 8 SKUs | Create campaign with 8 distinct active SKUs | Campaign created; 8 SKUs persisted | Created `#2` -> verified 8 SKUs persisted | **PASS** |
| **ADM-09** | Campaign Scale — 24 SKUs | Create campaign with 24 distinct active SKUs | Campaign created; 24 SKUs persisted | Created `#3` -> verified 24 SKUs persisted | **PASS** |
| **ADM-10** | Campaign Scale — 32 SKUs | Create campaign with 32 distinct active SKUs | Campaign created; 32 SKUs persisted | Created `#4` -> verified 32 SKUs persisted | **PASS** |
| **ADM-11** | Campaign Scale — 40 SKUs | Create campaign with 40 distinct active SKUs | Campaign created; 40 SKUs persisted | Created `#5` -> verified 40 SKUs persisted | **PASS** |
| **ADM-12** | Campaign Edit | Update title, description, and SKU list on `#2` | Document modified in Atlas; new values persisted | Updated `#2` -> Title modified, 2 items persisted | **PASS** |
| **ADM-13** | Campaign Toggle Activation | Toggle inactive -> active -> inactive | State transitions cleanly (`is_active` 0 -> 1 -> 0) | State verified in Atlas: `is_active` correctly toggled | **PASS** |
| **ADM-14** | Non-Admin Web Rejection | HTTP GET `/index.php?r=admin_flash_sales` unauthenticated | HTTP 302 redirect to `/index.php?r=dangnhap` | HTTP 302 Found, Location: `/index.php?r=dangnhap` | **PASS** |
| **ADM-15** | Non-Admin API Rejection | HTTP GET `/index.php?r=api_admin_flash_sale_search_products` unauthenticated | HTTP 403 Forbidden JSON | HTTP 403 Forbidden; `{"ok":false,"message":"Không có quyền truy cập chức năng này."}` | **PASS** |
| **ADM-16** | Baseline Integrity | Check baseline campaign `ma_campaign=1` | Baseline campaign preserved | `ma_campaign=1` ("Siêu sao") intact | **PASS** |

---

## 3. Conclusion

Admin module acceptance testing passed 100% of functional, security, and catalog constraint tests.
