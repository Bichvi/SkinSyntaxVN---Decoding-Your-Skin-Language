# Proposed Category Architecture & Homepage UX

## 1. Single Source of Truth Architecture

```
                    ┌──────────────────────────────────────────────┐
                    │      MongoDB Atlas: Collection `danh_muc`     │
                    │         (28 Canonical Categories)            │
                    └──────────────────────┬───────────────────────┘
                                           │
                    ┌──────────────────────┴───────────────────────┐
                    │                                              │
                    ▼                                              ▼
       ┌────────────────────────┐                    ┌────────────────────────┐
       │   Admin Management     │                    │   Storefront / Client  │
       │ index.php?r=admin_cat  │                    │ Header Mega Menu & Home│
       ├────────────────────────┤                    ├────────────────────────┤
       │ - QuanTriController    │                    │ - HomeController       │
       │ - QuanTri::listCats()  │                    │ - SanPham::menuTree()  │
       │ - Full CRUD on tree    │                    │ - Structured 3-level   │
       │ - Direct/Rec counts    │                    │   group display        │
       └────────────────────────┘                    └────────────────────────┘
                    ▲                                              ▲
                    │                                              │
                    └──────────────────────┬───────────────────────┘
                                           │
                    ┌──────────────────────┴───────────────────────┐
                    │      MongoDB Atlas: Collection `san_pham`    │
                    │        (2,473 Skincare Products)             │
                    │       ma_danh_muc -> Canonical Leaf ID       │
                    └──────────────────────────────────────────────┘
```

### Architectural Principles:
1. **Canonical Collection**: MongoDB collection `danh_muc` in MongoDB Atlas is the **Single Source of Truth**.
2. **Product Attachment Rule**: Every `san_pham.ma_danh_muc` must reference a valid leaf category (`is_leaf: true`). Products must not be attached to root or intermediate parent categories.
3. **Admin Consistency**: Admin category management operates directly on `danh_muc`, maintaining parent-child relations (`parent_id`), levels (`level`), and display order (`thu_tu_hien_thi`).
4. **No Hardcoded Taxonomy**: No category names, IDs, or tree structures should be hardcoded in PHP controllers, views, or config files.
5. **No Legacy Aggregation**: Deprecate `HomeController::getHighlightedCategories()` which performs raw string aggregation on legacy `san_pham.danh_muc_day_du` without referencing `danh_muc`.

---

## 2. Proposed Homepage & Header Category UX

### Current Defect:
- In `frontend/views/layouts/header.php`, the mega menu assumes multiple Level 1 root categories. Because the skincare catalog has a single root (`#1001` - Chăm Sóc Da Mặt), rendering one column per root leaves 67% to 100% of the right container empty.

### Proposed Multi-Column Hierarchical UX:
Rather than looping Level 1 root categories into columns, the mega menu should loop **Level 2 parent groups** as primary columns and display their **Level 3 leaf categories** (with product counts) inside each column.

#### Visual Layout Specification:
```
┌───────────────────────────┬─────────────────────────────────────────────────────────────────────────────────┐
│ Left Panel (col-lg-3)     │ Right Panel (col-lg-9) — 3 or 4 Columns of Level 2 Groups                       │
├───────────────────────────┼───────────────────────┬────────────────────────┬────────────────────────────────┤
│ [Danh mục nổi bật]        │ Làm Sạch Da (613)     │ Dưỡng Ẩm & Bảo Vệ (544)│ Mặt Nạ Chuyên Sâu (622)        │
│                           │ ├ Sữa Rửa Mặt (285)   │ ├ Chống Nắng (231)     │ ├ Mặt Nạ Giấy (549)            │
│ Khám phá từng nhóm        │ ├ Tẩy Trang Mặt (195) │ ├ Kem / Gel Dưỡng (215)│ ├ Mặt Nạ Rửa (50)              │
│ skincare chuẩn hóa theo   │ ├ Nước Cân Bằng (94)  │ ├ Lotion / Sữa (58)    │ ├ Mặt Nạ Ngủ (20)              │
│ nhu cầu da của bạn.       │ └ Tẩy Tế Bào Chết(39) │ └ Xịt Khoáng (40)      │ └ Mặt Nạ Lột (3)               │
│                           │                       │                        │                                │
│ [Khám phá Routine ->]     │ Đặc Trị Da (275)      │ Chăm Sóc Môi & Mắt(208)│ Bộ Sản Phẩm (211)              │
│                           │ ├ Serum / TinhChất(207│ ├ Son Dưỡng Môi (167)  │ └ Bộ Chăm Sóc Da Mặt (211)     │
│                           │ ├ Hỗ Trợ Trị Mụn (66) │ ├ Serum/Kem Mắt (16)   │                                │
│                           │ └ Đặc Trị Khác (2)    │ ├ Mặt Nạ Môi (11)      │                                │
│                           │                       │ ├ Mặt Nạ Mắt (11)      │                                │
│                           │                       │ └ Tẩy Da Chết Môi (3)  │                                │
└───────────────────────────┴───────────────────────┴────────────────────────┴────────────────────────────────┘
```

### Proposed Data Structure from `SanPham::menuTree()`:
To support this UX seamlessly, `SanPham::menuTree()` should structure the tree by Level 2 parent groups:
```php
[
    'Làm Sạch Da' => [
        'id' => 2002,
        'total' => 613,
        'items' => [
            ['id' => 1, 'name' => 'Sữa Rửa Mặt', 'count' => 285],
            ['id' => 2, 'name' => 'Tẩy Trang Mặt', 'count' => 195],
            ['id' => 4, 'name' => 'Toner / Nước Cân Bằng Da', 'count' => 94],
            ['id' => 17, 'name' => 'Tẩy Tế Bào Chết Da Mặt', 'count' => 39],
        ]
    ],
    'Mặt Nạ' => [
        'id' => 2001,
        'total' => 622,
        'items' => [
            ['id' => 11, 'name' => 'Mặt Nạ Giấy', 'count' => 549],
            ['id' => 19, 'name' => 'Mặt Nạ Rửa', 'count' => 50],
            ['id' => 53, 'name' => 'Mặt Nạ Ngủ', 'count' => 20],
            ['id' => 83, 'name' => 'Mặt Nạ Lột', 'count' => 3],
        ]
    ],
    ...
]
```

### Benefits of this Architecture:
1. **100% Dynamic & Data-Driven**: Category additions, renamings, or product count changes made in Admin reflect instantly on the Homepage mega menu.
2. **Eliminates Blank Areas**: The 4-column layout fills the entire `col-lg-9` space evenly.
3. **Direct Filter Navigation**: Clicking any leaf item links directly to `index.php?r=tatca&ma_danh_muc={id}`, filtering products by the exact canonical ID without ambiguous string matching.
