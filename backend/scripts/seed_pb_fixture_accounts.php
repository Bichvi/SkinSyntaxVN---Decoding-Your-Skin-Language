<?php
// Tạo tài khoản đăng nhập + hồ sơ khảo sát da cho khách giả lập pb-fixture-v1 (chỉ khách có test_dataset).
// Chạy trong container PHP:
//   php scripts/seed_pb_fixture_accounts.php --dry-run
//   php scripts/seed_pb_fixture_accounts.php --out=/tmp/pb_fixture_accounts.csv [--rotate] [--password-from-email]
// Mật khẩu chỉ được ghi ra file CSV (quyền 600), không in ra màn hình. --rotate cấp lại mật khẩu cho tài khoản đã có.
// Gỡ: python -m fixtures.prompt_builder reset --yes (xoá nguoidung/khach_hang có tag).

require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/NguoiDung.php';

const PB_DATASET = 'pb-fixture-v1';
const PB_SPECIALS = '@#$%&*!?';

$options = getopt('', ['dry-run', 'rotate', 'out:', 'password-from-email']);
$dryRun = isset($options['dry-run']);
$rotate = isset($options['rotate']);
// Mật khẩu = phần trước @ của email (vd. nhi.ngo052), dễ nhớ khi test tay. Không đạt chính sách đăng ký, chỉ dùng cho dữ liệu test.
$passwordFromEmail = isset($options['password-from-email']);
$outPath = (string)($options['out'] ?? '/tmp/pb_fixture_accounts.csv');

// Ánh xạ persona của bộ sinh -> đúng giá trị form khảo sát (frontend/views/auth/khaosat.php).
const PB_CONCERN_LABELS = [
    'mụn' => 'Mụn viêm, sưng đỏ',
    'đỏ rát' => 'Mụn viêm, sưng đỏ',
    'lỗ chân lông to' => 'Lỗ chân lông to',
    'thâm sau mụn' => 'Thâm mụn, sạm nám, tàn nhang',
    'nám' => 'Thâm mụn, sạm nám, tàn nhang',
    'da xỉn màu' => 'Thâm mụn, sạm nám, tàn nhang',
    'lão hóa' => 'Lão hóa, nếp nhăn',
    'da khô ráp' => 'Da khô căng, bong tróc',
];
const PB_GOALS = [
    'Mụn viêm, sưng đỏ' => 'Sạch mụn, giảm viêm',
    'Lỗ chân lông to' => 'Sạch mụn, giảm viêm',
    'Thâm mụn, sạm nám, tàn nhang' => 'Dưỡng sáng, mờ thâm nám',
    'Lão hóa, nếp nhăn' => 'Chống lão hóa, trẻ hóa da',
    'Da khô căng, bong tróc' => 'Phục hồi màng bảo vệ da, cấp ẩm',
];
// Bucket ngân sách của khảo sát (AuthController::xulykhaosat $budgetMap) theo hạng ngân sách persona.
const PB_BUDGET = ['low' => 500000, 'mid' => 1000000, 'high' => 1500000];

function pb_password(): string {
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $all = $upper . $lower . $digits . PB_SPECIALS;
    $pick = static fn(string $set): string => $set[random_int(0, strlen($set) - 1)];
    $chars = [$pick($upper), $pick($lower), $pick($digits), $pick(PB_SPECIALS)];
    while (count($chars) < 14) {
        $chars[] = $pick($all);
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

function pb_survey(array $customer, array $brandNames): array {
    $persona = (array)($customer['fixture_persona'] ?? []);
    $seed = crc32((string)$customer['email']);
    $skinKey = (string)($persona['skin_key'] ?? 'normal');
    $concerns = array_values(array_unique(array_filter(array_map(
        static fn($c) => PB_CONCERN_LABELS[(string)$c] ?? null,
        (array)($persona['concerns'] ?? [])
    ))));
    if (!$concerns) {
        $concerns = ['Lỗ chân lông to'];
    }
    $sensitive = $skinKey === 'sensitive' || ($skinKey === 'dry' && $seed % 3 === 0);
    if ($skinKey === 'sensitive') {
        $avoid = ['Fragrance/Parfum', 'Alcohol'];
    } elseif ($skinKey === 'oily' && $seed % 2 === 0) {
        $avoid = ['Mineral Oil'];
    } else {
        $avoid = ['Không có / Không quan tâm'];
    }
    $brands = array_values(array_filter(array_map(
        static fn($id) => $brandNames[(string)$id] ?? null,
        (array)($persona['favorite_brands'] ?? [])
    )));
    $statusParts = ['Khảo sát đăng nhập'];
    if ($brands) {
        $statusParts[] = 'Thương hiệu ưu tiên: ' . implode(', ', $brands);
    }
    // Cùng cấu trúc payload với AuthController::xulykhaosat.
    return [
        'gioi_tinh' => $customer['gioi_tinh'] ?? null,
        'loai_da' => (string)($persona['skin_type'] ?? 'Da thường/Mọi loại da'),
        'nam_sinh' => isset($customer['nam_sinh']) ? (int)$customer['nam_sinh'] : null,
        'muc_do_nhay_cam' => $sensitive ? 'Rất dễ' : 'Khỏe mạnh, hiếm khi',
        'van_de_da' => implode(', ', $concerns),
        'muc_do_mun' => null,
        'muc_tieu_cham_soc' => PB_GOALS[$concerns[0]] ?? null,
        'tieu_chi_uu_tien' => null,
        'tinh_trang_dac_biet' => implode(' | ', $statusParts),
        'kinh_nghiem_skincare' => null,
        'so_buoc_skincare' => $brands ? implode(', ', $brands) : null,
        'thanh_phan_tranh' => implode(', ', $avoid),
        'ngan_sach' => PB_BUDGET[(string)($persona['budget_tier'] ?? 'mid')] ?? 1000000,
    ];
}

function pb_read_csv(string $path): array {
    $rows = [];
    if (!is_file($path) || ($fh = fopen($path, 'r')) === false) {
        return $rows;
    }
    $header = fgetcsv($fh);
    while ($header && ($line = fgetcsv($fh)) !== false) {
        $row = array_combine($header, $line);
        if ($row && !empty($row['email'])) {
            $rows[strtolower($row['email'])] = $row;
        }
    }
    fclose($fh);
    return $rows;
}

$model = new NguoiDung($pdo);
$brandNames = [];
foreach ($pdo->thuong_hieu->find([], ['projection' => ['ma_thuong_hieu' => 1, 'ten_thuong_hieu' => 1]]) as $brand) {
    $brandNames[(string)$brand['ma_thuong_hieu']] = (string)$brand['ten_thuong_hieu'];
}
$previous = pb_read_csv($outPath);
$customers = iterator_to_array($pdo->khach_hang->find(['test_dataset' => PB_DATASET], ['sort' => ['ma_kh' => 1]]));
$summary = ['dry_run' => $dryRun, 'customers' => count($customers), 'created' => 0, 'rotated' => 0, 'kept' => 0,
            'survey_saved' => 0, 'skipped_real_account' => 0, 'kept_without_password_in_csv' => 0];
$csvRows = [];

foreach ($customers as $doc) {
    $customer = json_decode(json_encode($doc), true);
    $email = (string)($customer['email'] ?? '');
    $maKh = (int)($customer['ma_kh'] ?? 0);
    if ($email === '' || $maKh <= 0) {
        continue;
    }
    $survey = pb_survey($customer, $brandNames);
    $existing = $model->timTheoEmail($email);
    if ($existing && ($existing['test_dataset'] ?? null) !== PB_DATASET) {
        $summary['skipped_real_account']++;  // không bao giờ đụng tài khoản thật trùng email
        continue;
    }
    $password = null;
    $newPassword = $passwordFromEmail ? strstr($email, '@', true) : pb_password();
    if (!$existing) {
        $password = $newPassword;
        $summary['created']++;
    } elseif ($rotate) {
        $password = $newPassword;
        $summary['rotated']++;
    } else {
        $summary['kept']++;
    }
    if (!$dryRun) {
        $now = new MongoDB\BSON\UTCDateTime();
        if (!$existing) {
            $pdo->nguoidung->insertOne([
                'id' => $maKh,
                'ho_ten' => (string)$customer['ho_ten'],
                'email' => $email,
                'mat_khau' => password_hash($password, PASSWORD_BCRYPT),
                'vai_tro' => 'khach_hang',
                'terms_agree' => true,
                'privacy_consent' => true,
                'recommendation_consent' => true,
                'created_at' => $doc['created_at'] ?? $now,
                'ngay_tao' => $doc['created_at'] ?? $now,
                'test_dataset' => PB_DATASET,
                'data_source' => 'pb_fixture',
                'needs_verification' => true,
            ]);
        } elseif ($password !== null) {
            $pdo->nguoidung->updateOne(['_id' => $existing['_id']], ['$set' => ['mat_khau' => password_hash($password, PASSWORD_BCRYPT)]]);
        }
        $pdo->khach_hang->updateOne(['_id' => $doc['_id']], ['$set' => [
            'terms_agree' => true, 'privacy_consent' => true, 'recommendation_consent' => true,
        ]]);
        if ($model->luuKhaoSatKhachHang((string)$customer['ho_ten'], $email, $survey)) {
            $summary['survey_saved']++;
        }
    }
    $known = $password ?? ($previous[strtolower($email)]['mat_khau'] ?? null);
    if ($known === null) {
        $summary['kept_without_password_in_csv']++;
    }
    $csvRows[] = [
        'email' => $email,
        'mat_khau' => $known ?? '(chưa biết - chạy lại với --rotate để cấp mật khẩu mới)',
        'ho_ten' => (string)$customer['ho_ten'],
        'ma_kh' => $maKh,
        'loai_da' => $survey['loai_da'],
        'van_de_da' => $survey['van_de_da'],
        'ngan_sach' => $survey['ngan_sach'],
        'khu_vuc' => (string)($customer['fixture_region'] ?? ''),
    ];
}

if (!$dryRun) {
    $fh = fopen($outPath, 'w');
    if ($fh === false) {
        fwrite(STDERR, "Không ghi được file $outPath\n");
        exit(1);
    }
    fputcsv($fh, array_keys($csvRows[0] ?? ['email' => '', 'mat_khau' => '']));
    foreach ($csvRows as $row) {
        fputcsv($fh, $row);
    }
    fclose($fh);
    chmod($outPath, 0600);
    $summary['csv'] = $outPath;
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
