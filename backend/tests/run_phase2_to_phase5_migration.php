<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

echo "=================================================================\n";
echo "PHASE 2 - 5: CANONICAL CATEGORY TREE & BREADCRUMB MIGRATION\n";
echo "=================================================================\n\n";

$now = new \MongoDB\BSON\UTCDateTime();

// 1. Define New Parent Categories (1 Level 1 + 6 Level 2)
$parentCategories = [
    [
        'ma_danh_muc' => 1001,
        'ten_danh_muc' => 'Chăm Sóc Da Mặt',
        'parent_id' => null,
        'level' => 1,
        'is_leaf' => false,
        'thu_tu_hien_thi' => 1,
        'trang_thai' => 'active',
        'status' => 'active',
        'slug' => 'cham-soc-da-mat',
        'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt',
        'danh_muc_day_du' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt',
        'updated_at' => $now,
    ],
    [
        'ma_danh_muc' => 2001,
        'ten_danh_muc' => 'Mặt Nạ',
        'parent_id' => 1001,
        'level' => 2,
        'is_leaf' => false,
        'thu_tu_hien_thi' => 1,
        'trang_thai' => 'active',
        'status' => 'active',
        'slug' => 'mat-na',
        'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Mặt Nạ',
        'danh_muc_day_du' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Mặt Nạ',
        'updated_at' => $now,
    ],
    [
        'ma_danh_muc' => 2002,
        'ten_danh_muc' => 'Làm Sạch Da',
        'parent_id' => 1001,
        'level' => 2,
        'is_leaf' => false,
        'thu_tu_hien_thi' => 2,
        'trang_thai' => 'active',
        'status' => 'active',
        'slug' => 'lam-sach-da',
        'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Làm Sạch Da',
        'danh_muc_day_du' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Làm Sạch Da',
        'updated_at' => $now,
    ],
    [
        'ma_danh_muc' => 2003,
        'ten_danh_muc' => 'Dưỡng Ẩm',
        'parent_id' => 1001,
        'level' => 2,
        'is_leaf' => false,
        'thu_tu_hien_thi' => 4,
        'trang_thai' => 'active',
        'status' => 'active',
        'slug' => 'duong-am',
        'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Ẩm',
        'danh_muc_day_du' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Ẩm',
        'updated_at' => $now,
    ],
    [
        'ma_danh_muc' => 2004,
        'ten_danh_muc' => 'Đặc Trị',
        'parent_id' => 1001,
        'level' => 2,
        'is_leaf' => false,
        'thu_tu_hien_thi' => 6,
        'trang_thai' => 'active',
        'status' => 'active',
        'slug' => 'dac-tri',
        'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Đặc Trị',
        'danh_muc_day_du' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Đặc Trị',
        'updated_at' => $now,
    ],
    [
        'ma_danh_muc' => 2005,
        'ten_danh_muc' => 'Dưỡng Mắt',
        'parent_id' => 1001,
        'level' => 2,
        'is_leaf' => false,
        'thu_tu_hien_thi' => 7,
        'trang_thai' => 'active',
        'status' => 'active',
        'slug' => 'duong-mat',
        'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Mắt',
        'danh_muc_day_du' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Mắt',
        'updated_at' => $now,
    ],
    [
        'ma_danh_muc' => 2006,
        'ten_danh_muc' => 'Dưỡng Môi',
        'parent_id' => 1001,
        'level' => 2,
        'is_leaf' => false,
        'thu_tu_hien_thi' => 8,
        'trang_thai' => 'active',
        'status' => 'active',
        'slug' => 'duong-moi',
        'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Môi',
        'danh_muc_day_du' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Môi',
        'updated_at' => $now,
    ],
];

// Upsert parent categories
foreach ($parentCategories as $pCat) {
    $db->danh_muc->updateOne(
        ['ma_danh_muc' => $pCat['ma_danh_muc']],
        ['$set' => $pCat],
        ['upsert' => true]
    );
    echo "[UPSERT PARENT] #{$pCat['ma_danh_muc']} {$pCat['ten_danh_muc']} (Level {$pCat['level']})\n";
}

// 2. Define Canonical Updates for the 21 Existing Leaf Categories
$leafUpdates = [
    // Mặt Nạ (#2001)
    11  => ['parent_id' => 2001, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 1, 'slug' => 'mat-na-giay', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Mặt Nạ -> Mặt Nạ Giấy'],
    19  => ['parent_id' => 2001, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 2, 'slug' => 'mat-na-rua', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Mặt Nạ -> Mặt Nạ Rửa'],
    53  => ['parent_id' => 2001, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 3, 'slug' => 'mat-na-ngu', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Mặt Nạ -> Mặt Nạ Ngủ'],
    83  => ['parent_id' => 2001, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 4, 'slug' => 'mat-na-lot', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Mặt Nạ -> Mặt Nạ Lột'],

    // Làm Sạch Da (#2002)
    1   => ['parent_id' => 2002, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 1, 'slug' => 'sua-rua-mat', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Làm Sạch Da -> Sữa Rửa Mặt'],
    2   => ['parent_id' => 2002, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 2, 'slug' => 'tay-trang-mat', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Làm Sạch Da -> Tẩy Trang Mặt'],
    4   => ['parent_id' => 2002, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 3, 'slug' => 'toner-nuoc-can-bang-da', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Làm Sạch Da -> Toner / Nước Cân Bằng Da'],
    17  => ['parent_id' => 2002, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 4, 'slug' => 'tay-te-bao-chet-da-mat', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Làm Sạch Da -> Tẩy Tế Bào Chết Da Mặt'],

    // Direct Level 2 Leaves under #1001 Chăm Sóc Da Mặt
    6   => ['parent_id' => 1001, 'level' => 2, 'is_leaf' => true, 'thu_tu_hien_thi' => 3, 'slug' => 'chong-nang-da-mat', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Chống Nắng Da Mặt'],
    3   => ['parent_id' => 1001, 'level' => 2, 'is_leaf' => true, 'thu_tu_hien_thi' => 5, 'slug' => 'bo-cham-soc-da-mat', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Bộ Chăm Sóc Da Mặt'],

    // Dưỡng Ẩm (#2003)
    7   => ['parent_id' => 2003, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 1, 'slug' => 'kem-gel-dau-duong', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Ẩm -> Kem / Gel / Dầu Dưỡng'],
    37  => ['parent_id' => 2003, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 2, 'slug' => 'lotion-sua-duong', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Ẩm -> Lotion / Sữa Dưỡng'],
    30  => ['parent_id' => 2003, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 3, 'slug' => 'xit-khoang', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Ẩm -> Xịt Khoáng'],

    // Đặc Trị (#2004)
    9   => ['parent_id' => 2004, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 1, 'slug' => 'serum-tinh-chat', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Đặc Trị -> Serum / Tinh Chất'],
    25  => ['parent_id' => 2004, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 2, 'slug' => 'ho-tro-tri-mun', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Đặc Trị -> Hỗ Trợ Trị Mụn'],
    105 => ['parent_id' => 2004, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 3, 'slug' => 'san-pham-dac-tri-khac', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Đặc Trị -> Sản Phẩm Đặc Trị Khác'],

    // Dưỡng Mắt (#2005)
    38  => ['parent_id' => 2005, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 1, 'slug' => 'serum-kem-duong-mat', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Mắt -> Serum / Kem Dưỡng Mắt'],
    60  => ['parent_id' => 2005, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 2, 'slug' => 'mat-na-mat', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Mắt -> Mặt Nạ Mắt'],

    // Dưỡng Môi (#2006) - Includes #18 Son Dưỡng Môi
    18  => ['parent_id' => 2006, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 1, 'slug' => 'son-duong-moi', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Môi -> Son Dưỡng Môi'],
    29  => ['parent_id' => 2006, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 2, 'slug' => 'mat-na-moi', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Môi -> Mặt Nạ Môi'],
    73  => ['parent_id' => 2006, 'level' => 3, 'is_leaf' => true, 'thu_tu_hien_thi' => 3, 'slug' => 'tay-te-bao-chet-moi', 'full_path' => 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Môi -> Tẩy Tế Bào Chết Môi'],
];

foreach ($leafUpdates as $leafId => $meta) {
    $payload = array_merge($meta, [
        'trang_thai' => 'active',
        'status' => 'active',
        'danh_muc_day_du' => $meta['full_path'],
        'updated_at' => $now,
    ]);
    $res = $db->danh_muc->updateOne(['ma_danh_muc' => $leafId], ['$set' => $payload]);
    echo "[UPDATE LEAF] #{$leafId} -> parent={$meta['parent_id']}, level={$meta['level']} (matched={$res->getMatchedCount()})\n";
}

// =================================================================
// PHASE 5: UPDATE PRODUCT BREADCRUMB FOR #18 SON DUONG MOI
// =================================================================
echo "\n--- STARTING PHASE 5: UPDATE PRODUCT BREADCRUMB FOR #18 ---\n";
$canonicalPath18 = 'Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Dưỡng Môi -> Son Dưỡng Môi';
$update18Res = $db->san_pham->updateMany(
    ['ma_danh_muc' => 18],
    ['$set' => ['danh_muc_day_du' => $canonicalPath18]]
);

echo "[PHASE 5] Updated #18 products: matched={$update18Res->getMatchedCount()}, modified={$update18Res->getModifiedCount()}\n";

$verify18Count = $db->san_pham->countDocuments([
    'ma_danh_muc' => 18,
    'danh_muc_day_du' => $canonicalPath18
]);
echo "[PHASE 5] Verified #18 with canonical path: {$verify18Count} / 167\n";

$remainingMakeup = $db->san_pham->countDocuments([
    'danh_muc_day_du' => new \MongoDB\BSON\Regex('Trang Điểm', 'i')
]);
echo "[PHASE 5] Remaining products with 'Trang Điểm' in danh_muc_day_du: {$remainingMakeup} (Expected: 0)\n";

if ($verify18Count !== 167 || $remainingMakeup !== 0) {
    die("[ERROR] Phase 5 validation failed!\n");
}

echo "\n=== PHASE 2 - 5 COMPLETED SUCCESSFULLY ===\n";
