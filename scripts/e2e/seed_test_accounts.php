<?php
// scripts/e2e/seed_test_accounts.php
// Idempotent upsert of exactly two E2E test accounts (customer + admin) into the configured MongoDB.
// Credentials come from .env: E2E_CUSTOMER_EMAIL/PASSWORD, E2E_ADMIN_EMAIL/PASSWORD. Never prints secrets.
// Accounts carry is_test_account=true so they can be filtered or removed (remove_test_accounts.php).

$backendRoot = is_dir(__DIR__ . '/../../backend/app')
    ? realpath(__DIR__ . '/../../backend')
    : (is_dir('/var/www/html/app') ? '/var/www/html' : null);
if ($backendRoot === null) {
    fwrite(STDERR, "Cannot locate backend root\n");
    exit(1);
}
require_once $backendRoot . '/app/config/config.php';
require_once $backendRoot . '/app/config/db.php';
/** @var \MongoDatabaseCompat $db */

$custEmail = strtolower(ss_env('E2E_CUSTOMER_EMAIL'));
$custPass = ss_env('E2E_CUSTOMER_PASSWORD');
$adminEmail = strtolower(ss_env('E2E_ADMIN_EMAIL'));
$adminPass = ss_env('E2E_ADMIN_PASSWORD');
foreach ([$custEmail, $custPass, $adminEmail, $adminPass] as $v) {
    if ($v === '') {
        fwrite(STDERR, "Missing E2E_* variable(s) in .env\n");
        exit(1);
    }
}
foreach ([$custEmail, $adminEmail] as $e) {
    if (!str_ends_with($e, '@skinsyntax.test')) {
        fwrite(STDERR, "Refusing: test emails must end with @skinsyntax.test\n");
        exit(1);
    }
}

$now = new \MongoDB\BSON\UTCDateTime();
$nextId = function (string $coll, string $col) use ($db): int {
    $last = $db->{$coll}->findOne([], ['sort' => [$col => -1], 'projection' => [$col => 1]]);
    return $last ? ((int)$last[$col] + 1) : 1;
};
$ci = fn(string $e) => new \MongoDB\BSON\Regex('^' . preg_quote($e, '/') . '$', 'i');

// --- Skin type "da dầu" id (read-only lookup) ---
$loaiDa = $db->loai_da->findOne(['ten_loai_da' => new \MongoDB\BSON\Regex('d[aầ]u', 'i')]);
if (!$loaiDa) {
    $loaiDa = $db->loai_da->findOne(['ten_loai_da' => new \MongoDB\BSON\Regex('dầu|dau', 'i')]);
}
if (!$loaiDa) {
    fwrite(STDERR, "Cannot find oily skin type in loai_da\n");
    exit(1);
}
$maLoaiDa = (int)$loaiDa['ma_loai_da'];

// --- Customer: nguoidung (login) + khach_hang (profile) ---
$db->nguoidung->updateOne(
    ['email' => $ci($custEmail)],
    [
        '$set' => [
            'ho_ten' => 'E2E Test Customer',
            'email' => $custEmail,
            'mat_khau' => password_hash($custPass, PASSWORD_BCRYPT),
            'terms_agree' => true,
            'privacy_consent' => true,
            'recommendation_consent' => true,
            'is_test_account' => true,
            'updated_at' => $now,
        ],
        '$setOnInsert' => ['created_at' => $now],
    ],
    ['upsert' => true]
);
$kh = $db->khach_hang->findOne(['email' => $ci($custEmail)]);
$setKh = [
    'ho_ten' => 'E2E Test Customer',
    'email' => $custEmail,
    'tinh_trang_dac_biet' => 'loaida:' . $maLoaiDa,
    'van_de_da' => 'Mụn, Thâm mụn',
    'muc_do_nhay_cam' => 'Khỏe mạnh, hiếm khi',
    'ngan_sach' => 500000,
    'terms_agree' => true,
    'privacy_consent' => true,
    'recommendation_consent' => true,
    'is_test_account' => true,
    'updated_at' => $now,
];
if ($kh) {
    $db->khach_hang->updateOne(['_id' => $kh['_id']], ['$set' => $setKh]);
} else {
    $db->khach_hang->insertOne($setKh + ['ma_kh' => $nextId('khach_hang', 'ma_kh'), 'created_at' => $now]);
}

// --- Admin: nhan_vien (role admin = ma_vai_tro 1) ---
$nv = $db->nhan_vien->findOne(['email' => $ci($adminEmail)]);
$setNv = [
    'ho_ten' => 'E2E Test Admin',
    'email' => $adminEmail,
    'mat_khau' => password_hash($adminPass, PASSWORD_BCRYPT),
    'ma_vai_tro' => 1,
    'ten_vai_tro' => 'admin',
    'trang_thai' => 'active',
    'is_test_account' => true,
    'updated_at' => $now,
];
if ($nv) {
    $db->nhan_vien->updateOne(['_id' => $nv['_id']], ['$set' => $setNv]);
} else {
    $db->nhan_vien->insertOne($setNv + ['ma_nv' => $nextId('nhan_vien', 'ma_nv'), 'created_at' => $now]);
}

echo "OK: test accounts upserted (customer: nguoidung+khach_hang, admin: nhan_vien)\n";
echo 'Counts: nguoidung=' . $db->nguoidung->countDocuments(['is_test_account' => true])
    . ' khach_hang=' . $db->khach_hang->countDocuments(['is_test_account' => true])
    . ' nhan_vien=' . $db->nhan_vien->countDocuments(['is_test_account' => true]) . "\n";
