-- =========================================
-- TEST DATA - Match Score Feature
-- =========================================
-- File này cung cấp dữ liệu test cho tính năng Match Score
-- 
-- *** IMPORTANT: Chỉnh sửa values theo database thực tế của bạn ***

-- =========================================
-- 1. INSERT Test User vào bảng nguoidung
-- =========================================
-- Mật khẩu: password123 (sau khi hash với PASSWORD_BCRYPT)
INSERT INTO nguoidung (id, ho_ten, email, mat_khau, ngay_tao)
VALUES 
  (999, 'Test User', 'testuser@skinsynth.vn', '$2y$10$KqL0L4v9OZy7LqALDUEpOOqJ2LHk/RzvV3F3.3mL8M8vk4GDuK1J2', NOW()),
  (1000, 'Nguyễn Văn A', 'nguyenvana@skinsynth.vn', '$2y$10$KqL0L4v9OZy7LqALDUEpOOqJ2LHk/RzvV3F3.3mL8M8vk4GDuK1J2', NOW());

-- =========================================
-- 2. INSERT Test Customer Profile vào khach_hang
-- =========================================
-- User 1: Da mụn, tránh Alcohol & Sulfate
INSERT INTO khach_hang 
  (ma_kh, ho_ten, email, van_de_da, thanh_phan_tranh, created_at, updated_at)
VALUES 
  (999, 'Test User', 'testuser@skinsynth.vn', 
   'Da mụn', 
   'Alcohol Denat, Sulfates, Paraben, Formaldehyde', 
   NOW(), NOW());

-- User 2: Da nhạy cảm, tránh nhiều thành phần
INSERT INTO khach_hang 
  (ma_kh, ho_ten, email, van_de_da, thanh_phan_tranh, created_at, updated_at)
VALUES 
  (1000, 'Nguyễn Văn A', 'nguyenvana@skinsynth.vn', 
   'Da nhạy cảm', 
   'Alcohol, Fragrance, Essential Oils, Menthol, Camphor', 
   NOW(), NOW());

-- =========================================
-- 3. UPDATE sản phẩm với thanh_phan_clean
-- =========================================
-- IMPORTANT: Thay id/ma_san_pham với sản phẩm thực tế trong DB
-- Format: Danh sách thành phần ngăn cách bằng dấu phẩy hoặc newline

-- Ví dụ 1: Sữa rửa mặt
UPDATE san_pham 
SET thanh_phan_clean = 'Water, Glycerin, Cetyldimethicone, Glyceryl Stearate, Methylparaben, Propylparaben, Sodium Hydroxide, Citric Acid, Sodium Citrate'
WHERE ma_san_pham = 1
  AND ten_san_pham LIKE '%sữa rửa mặt%' OR ten_san_pham LIKE '%Cetaphil%';

-- Ví dụ 2: Mặt nạ dưỡng ẩm
UPDATE san_pham 
SET thanh_phan_clean = 'Aqua, Glycerin, Niacinamide, Panthenol, Allantoin, Vitamin E, Hyaluronic Acid, Carbomer, Triethanolamine, Phenoxyethanol'
WHERE ma_san_pham = 2 
  AND ten_san_pham LIKE '%mặt nạ%' OR ten_san_pham LIKE '%sheet mask%';

-- Ví dụ 3: Kem chống nắng (Alcohol trong thành phần)
UPDATE san_pham 
SET thanh_phan_clean = 'Water, Alcohol Denat, Zinc Oxide, Titanium Dioxide, Glycerin, Butylene Glycol, Phenoxyethanol, Aluminum Hydroxide, Stearic Acid'
WHERE ma_san_pham = 3 
  AND ten_san_pham LIKE '%sunscreen%' OR ten_san_pham LIKE '%chống nắng%';

-- =========================================
-- 4. VERIFY: Test SELECT queries
-- =========================================

-- Kiểm tra User Profile
SELECT * FROM khach_hang 
WHERE email IN ('testuser@skinsynth.vn', 'nguyenvana@skinsynth.vn');

-- Kiểm tra Product Ingredients
SELECT ma_san_pham, ten_san_pham, thanh_phan_clean 
FROM san_pham 
WHERE thanh_phan_clean IS NOT NULL AND thanh_phan_clean != ''
LIMIT 10;

-- =========================================
-- 5. EXAMPLE: Manual Match Score Calculation
-- =========================================
/*
User: testuser@skinsynth.vn
Profile:
  - van_de_da: "Da mụn"
  - thanh_phan_tranh: "Alcohol Denat, Sulfates, Paraben, Formaldehyde"

Product ID: 1 (Sữa rửa mặt Cetaphil)
Ingredients: "Water, Glycerin, Cetyldimethicone, Glyceryl Stearate, Methylparaben, Propylparaben, Sodium Hydroxide, Citric Acid, Sodium Citrate"

Calculation:
┌─────────────────────────────────────────┐
│ Total Ingredients: 9                    │
├─────────────────────────────────────────┤
│ ✓ Good (không trong danh sách tránh):  │
│   1. Water                              │
│   2. Glycerin                           │
│   3. Cetyldimethicone                   │
│   4. Glyceryl Stearate                  │
│   5. Sodium Hydroxide                   │
│   6. Citric Acid                        │
│   7. Sodium Citrate                     │
│                                         │
│ ⚠️ Bad (trong danh sách tránh):        │
│   1. Methylparaben ⚠️ (Paraben)        │
│   2. Propylparaben ⚠️ (Paraben)        │
├─────────────────────────────────────────┤
│ Match Score = 7/9 × 100 = 77.78% ≈ 78% │
└─────────────────────────────────────────┘

Description:
"Sản phẩm này phù hợp 78% với da mụn của bạn. 
⚠️ Chứa 2 thành phần nên tránh. 
✓ Chứa 7 thành phần tốt."
*/

-- =========================================
-- 6. CLEANUP: Xóa test data (nếu cần)
-- =========================================
/*
DELETE FROM khach_hang WHERE email IN ('testuser@skinsynth.vn', 'nguyenvana@skinsynth.vn');
DELETE FROM nguoidung WHERE email IN ('testuser@skinsynth.vn', 'nguyenvana@skinsynth.vn');
*/

-- =========================================
-- 7. REAL DATA IMPORT (Optional)
-- =========================================
-- Nếu bạn có CSV file với thanh_phan_clean, import bằng:
/*
COPY san_pham(ma_san_pham, ten_san_pham, thanh_phan_clean) 
FROM '/path/to/data_clean_final.csv' CSV HEADER;
*/
