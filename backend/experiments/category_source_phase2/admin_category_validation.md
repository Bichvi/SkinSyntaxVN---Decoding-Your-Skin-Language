# Admin Category Validation Report

## 1. Scope & Execution

- **Route Tested**: `index.php?r=admin_categories`
- **Controller**: `QuanTriController::adminCategories()`
- **Model**: `QuanTri::listCategories()`
- **Database Verified**: MongoDB Atlas (`skinsyntax.danh_muc` and `skinsyntax.san_pham`)
- **Status**: PASSED 100%

---

## 2. Validation Findings

1. **Total Categories Rendered**: **28 nodes** (Exact match to canonical Atlas dataset; 0 legacy categories).
2. **Hierarchy Displayed**:
   - **Level 1 (Root)**:
     - `#1001` Chăm Sóc Da Mặt (Direct: 0 SP, Recursive: 2,473 SP, Leaf: No)
   - **Level 2 (Groups & Direct Leaves)**:
     - `#2001` Mặt Nạ (Direct: 0 SP, Recursive: 622 SP, Leaf: No)
       - `#11` Mặt Nạ Giấy (Direct: 549 SP, Leaf: Yes)
       - `#19` Mặt Nạ Rửa (Direct: 50 SP, Leaf: Yes)
       - `#53` Mặt Nạ Ngủ (Direct: 20 SP, Leaf: Yes)
       - `#83` Mặt Nạ Lột (Direct: 3 SP, Leaf: Yes)
     - `#2002` Làm Sạch Da (Direct: 0 SP, Recursive: 613 SP, Leaf: No)
       - `#1` Sữa Rửa Mặt (Direct: 285 SP, Leaf: Yes)
       - `#2` Tẩy Trang Mặt (Direct: 195 SP, Leaf: Yes)
       - `#4` Toner / Nước Cân Bằng Da (Direct: 94 SP, Leaf: Yes)
       - `#17` Tẩy Tế Bào Chết Da Mặt (Direct: 39 SP, Leaf: Yes)
     - `#6` Chống Nắng Da Mặt (Direct: 231 SP, Recursive: 231 SP, Leaf: Yes)
     - `#2003` Dưỡng Ẩm (Direct: 0 SP, Recursive: 313 SP, Leaf: No)
       - `#7` Kem / Gel / Dầu Dưỡng (Direct: 215 SP, Leaf: Yes)
       - `#37` Lotion / Sữa Dưỡng (Direct: 58 SP, Leaf: Yes)
       - `#30` Xịt Khoáng (Direct: 40 SP, Leaf: Yes)
     - `#3` Bộ Chăm Sóc Da Mặt (Direct: 211 SP, Recursive: 211 SP, Leaf: Yes)
     - `#2004` Đặc Trị (Direct: 0 SP, Recursive: 275 SP, Leaf: No)
       - `#9` Serum / Tinh Chất (Direct: 207 SP, Leaf: Yes)
       - `#25` Hỗ Trợ Trị Mụn (Direct: 66 SP, Leaf: Yes)
       - `#105` Sản Phẩm Đặc Trị Khác (Direct: 2 SP, Leaf: Yes)
     - `#2005` Dưỡng Mắt (Direct: 0 SP, Recursive: 27 SP, Leaf: No)
       - `#38` Serum / Kem Dưỡng Mắt (Direct: 16 SP, Leaf: Yes)
       - `#60` Mặt Nạ Mắt (Direct: 11 SP, Leaf: Yes)
     - `#2006` Dưỡng Môi (Direct: 0 SP, Recursive: 181 SP, Leaf: No)
       - `#18` Son Dưỡng Môi (Direct: 167 SP, Leaf: Yes)
       - `#29` Mặt Nạ Môi (Direct: 11 SP, Leaf: Yes)
       - `#73` Tẩy Tế Bào Chết Môi (Direct: 3 SP, Leaf: Yes)

3. **CRUD Business Rules**:
   - `isLeafCategory()` correctly identifies parent nodes vs leaf nodes.
   - Guarded deletion rules are fully active:
     - Deleting parent category with active children is blocked (`Không thể xóa danh mục cha khi vẫn còn danh mục con`).
     - Deleting leaf category with active products is blocked (`Không thể xóa danh mục lá đang có sản phẩm`).
