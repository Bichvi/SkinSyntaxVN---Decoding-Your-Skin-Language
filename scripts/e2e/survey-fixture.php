<?php
// Use as auto_prepend_file with the loopback test server, or run directly to seed fixtures.
function ss_load_env(string $path): void {}

$database = getenv('MONGO_DB_NAME') ?: '';
if (!preg_match('/^skinsyntax_survey_test_[a-z0-9]+$/D', $database)) {
    throw new RuntimeException('An isolated survey test database is required.');
}
putenv('DB_MODE=atlas');
putenv('MONGO_URI=mongodb://127.0.0.1:27017');
putenv('CACHE_DRIVER=none');
putenv('SURVEY_PARTICIPANT_SECRET=survey-fixture-only-never-use-in-production');

if (PHP_SAPI !== 'cli') return;
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$db = (new MongoDB\Client('mongodb://127.0.0.1:27017'))->selectDatabase($database);
if (in_array('--count', $argv, true)) {
    echo json_encode([
        'legacy' => $db->feature_feedback->countDocuments(['question_version' => 1]),
        'v2' => $db->feature_feedback->countDocuments(['schema_version' => 2]),
        'ratings' => iterator_to_array($db->feature_feedback->aggregate([
            ['$match' => ['schema_version' => 2]],
            ['$unwind' => '$answers'],
            ['$group' => ['_id' => '$answers.rating', 'count' => ['$sum' => 1]]],
        ])),
    ]);
    return;
}
if (iterator_to_array($db->listCollectionNames())) {
    throw new RuntimeException('Use a fresh database name for each run.');
}
$password = password_hash('Survey-fixture-only-42', PASSWORD_DEFAULT);
$db->nguoidung->insertOne(['id' => 101, 'email' => 'customer@skinsyntax.test', 'ho_ten' => 'Khách thử nghiệm', 'mat_khau' => $password, 'is_test_account' => true]);
$db->khach_hang->insertOne(['ma_kh' => 101, 'email' => 'customer@skinsyntax.test', 'ho_ten' => 'Khách thử nghiệm',
    'van_de_da' => 'Da khô', 'ngan_sach' => 500000, 'is_test_account' => true]);
$db->nguoidung->insertOne(['id' => 102, 'email' => 'customer2@skinsyntax.test', 'ho_ten' => 'Khách thử nghiệm thứ hai', 'mat_khau' => $password, 'is_test_account' => true]);
$db->khach_hang->insertOne(['ma_kh' => 102, 'email' => 'customer2@skinsyntax.test', 'ho_ten' => 'Khách thử nghiệm thứ hai',
    'van_de_da' => 'Da khô', 'ngan_sach' => 500000, 'is_test_account' => true]);
$db->danh_muc->insertMany([
    ['ma_danh_muc' => 901, 'ten_danh_muc' => 'Chăm sóc da mặt', 'slug' => 'cham-soc-da-mat'],
    ['ma_danh_muc' => 902, 'parent_id' => 901, 'ten_danh_muc' => 'Sữa rửa mặt'],
]);
$db->san_pham->insertOne(['ma_san_pham' => 903, 'ma_danh_muc' => 902,
    'ten_san_pham' => 'Sữa rửa mặt dịu nhẹ (fixture)', 'loai_san_pham' => 'Sữa rửa mặt',
    'danh_muc_day_du' => 'Chăm Sóc Da Mặt -> Sữa rửa mặt', 'gia_ban' => 120000, 'so_luong_ton' => 10,
    'loai_da' => 'Da khô', 'trang_thai' => 'active']);
foreach (['admin' => 1, 'staff' => 2] as $name => $id) {
    $db->nhan_vien->insertOne(['ma_nv' => $id, 'ma_vai_tro' => $id, 'email' => $name . '@skinsyntax.test',
        'ho_ten' => ucfirst($name) . ' thử nghiệm', 'mat_khau' => $password, 'trang_thai' => 'active', 'is_test_account' => true]);
}
$db->feature_feedback->insertOne(['feature' => 'recommendation', 'role' => 'khach_hang',
    'question_version' => 1, 'rating' => 2, 'participant' => hash('sha256', 'legacy-fixture'),
    'submitted_at' => new MongoDB\BSON\UTCDateTime()]);
echo "Survey fixtures ready\n";
