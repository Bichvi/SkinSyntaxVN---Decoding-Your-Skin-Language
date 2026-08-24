<?php
// scripts/seed_admin_staff.php

$backendRoot = is_dir(__DIR__ . '/../backend/app')
    ? realpath(__DIR__ . '/../backend')
    : (is_dir('/var/www/html/app') ? '/var/www/html' : realpath(__DIR__ . '/..'));

require_once $backendRoot . '/app/config/config.php';
require_once $backendRoot . '/app/config/db.php';

echo "🚀 Starting Admin & Staff Account Seeding for MongoDB...\n";

/** @var \MongoDB\Database|\MongoDatabaseCompat $db */

// 1. Seed vai_tro collection
$db->vai_tro->deleteMany([]);
$roles = [
    [
        'ma_vai_tro' => 1,
        'ten_vai_tro' => 'admin',
        'mo_ta' => 'Quản trị viên hệ thống',
        'created_at' => new \MongoDB\BSON\UTCDateTime(),
    ],
    [
        'ma_vai_tro' => 2,
        'ten_vai_tro' => 'nhanvien',
        'mo_ta' => 'Nhân viên hỗ trợ & quản lý sản phẩm',
        'created_at' => new \MongoDB\BSON\UTCDateTime(),
    ],
];
$db->vai_tro->insertMany($roles);
echo "✅ Inserted roles: admin (1), nhanvien (2)\n";

// 2. Seed nhan_vien collection
$db->nhan_vien->deleteMany([]);

$pass123456 = password_hash('123456', PASSWORD_BCRYPT);
$passAdmin123 = password_hash('Admin123@', PASSWORD_BCRYPT);
$passStaff123 = password_hash('Staff123@', PASSWORD_BCRYPT);

$staffAccounts = [
    // ADMIN Accounts
    [
        'ma_nv' => 1,
        'ho_ten' => 'Quản Trị Viên (123456)',
        'email' => 'admin@skinsyntax.vn',
        'so_dien_thoai' => '0901234567',
        'mat_khau' => $pass123456,
        'ma_vai_tro' => 1,
        'ten_vai_tro' => 'admin',
        'trang_thai' => 'active',
        'created_at' => new \MongoDB\BSON\UTCDateTime(),
        'updated_at' => new \MongoDB\BSON\UTCDateTime(),
    ],
    [
        'ma_nv' => 2,
        'ho_ten' => 'Quản Trị Viên Gmail (123456)',
        'email' => 'admin@gmail.com',
        'so_dien_thoai' => '0901234568',
        'mat_khau' => $pass123456,
        'ma_vai_tro' => 1,
        'ten_vai_tro' => 'admin',
        'trang_thai' => 'active',
        'created_at' => new \MongoDB\BSON\UTCDateTime(),
        'updated_at' => new \MongoDB\BSON\UTCDateTime(),
    ],
    [
        'ma_nv' => 3,
        'ho_ten' => 'Quản Trị Viên (Admin123@)',
        'email' => 'admin123@skinsyntax.vn',
        'so_dien_thoai' => '0901234569',
        'mat_khau' => $passAdmin123,
        'ma_vai_tro' => 1,
        'ten_vai_tro' => 'admin',
        'trang_thai' => 'active',
        'created_at' => new \MongoDB\BSON\UTCDateTime(),
        'updated_at' => new \MongoDB\BSON\UTCDateTime(),
    ],

    // STAFF Accounts
    [
        'ma_nv' => 4,
        'ho_ten' => 'Nhân Viên (123456)',
        'email' => 'staff@skinsyntax.vn',
        'so_dien_thoai' => '0908765432',
        'mat_khau' => $pass123456,
        'ma_vai_tro' => 2,
        'ten_vai_tro' => 'nhanvien',
        'trang_thai' => 'active',
        'created_at' => new \MongoDB\BSON\UTCDateTime(),
        'updated_at' => new \MongoDB\BSON\UTCDateTime(),
    ],
    [
        'ma_nv' => 5,
        'ho_ten' => 'Nhân Viên Gmail (123456)',
        'email' => 'staff@gmail.com',
        'so_dien_thoai' => '0908765433',
        'mat_khau' => $pass123456,
        'ma_vai_tro' => 2,
        'ten_vai_tro' => 'nhanvien',
        'trang_thai' => 'active',
        'created_at' => new \MongoDB\BSON\UTCDateTime(),
        'updated_at' => new \MongoDB\BSON\UTCDateTime(),
    ],
];

$db->nhan_vien->insertMany($staffAccounts);
echo "✅ Inserted " . count($staffAccounts) . " Admin & Staff accounts into MongoDB!\n\n";

echo "🔑 CREDENTIALS CREATED:\n";
echo "-------------------------------------------\n";
echo "1. ADMIN:\n";
echo "   Email: admin@skinsyntax.vn  (Mật khẩu: 123456)\n";
echo "   Email: admin@gmail.com       (Mật khẩu: 123456)\n";
echo "   Email: admin123@skinsyntax.vn (Mật khẩu: Admin123@)\n";
echo "-------------------------------------------\n";
echo "2. STAFF:\n";
echo "   Email: staff@skinsyntax.vn  (Mật khẩu: 123456)\n";
echo "   Email: staff@gmail.com       (Mật khẩu: 123456)\n";
echo "-------------------------------------------\n";
