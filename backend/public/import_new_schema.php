<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';

set_time_limit(600);
ini_set('memory_limit', '1024M');

$csvFile = defined('CSV_FILE_PATH') ? CSV_FILE_PATH : (getenv('CSV_FILE_PATH') ?: '');
if ($csvFile === '' || !is_file($csvFile)) {
    $csvFile = is_file('/var/www/database/data_clean_final.csv')
        ? '/var/www/database/data_clean_final.csv'
        : dirname(__DIR__, 2) . '/database/data_clean_final.csv';
}

if (!file_exists($csvFile)) {
    die("CSV file not found: $csvFile");
}

echo "<h2>Import CSV data into MongoDB</h2>";
echo "<p>File: <strong>$csvFile</strong></p>";
flush();

function safeInt($v, int $default = 0): int {
    $s = trim((string)$v);
    return $s !== '' && is_numeric($s) ? (int)$s : $default;
}

function safeFloat($v): ?float {
    $s = trim((string)$v);
    return ($s !== '' && is_numeric($s)) ? (float)$s : null;
}

function cleanStr($v): string {
    $s = trim((string)$v);
    return in_array(strtolower($s), ['nan', 'none', ''], true) ? '' : $s;
}

try {
    /** @var MongoDatabaseCompat $pdo */

    echo "<p>Dropping existing collections...</p>";
    flush();

    foreach (['san_pham', 'thuong_hieu', 'danh_muc', 'xuat_xu', 'noi_san_xuat', 'loai_san_pham'] as $col) {
        $pdo->{$col}->drop();
    }
    echo "<p>Existing collections dropped.</p>";
    flush();

    $handle = fopen($csvFile, 'r');
    if (!$handle) die("Cannot open CSV file");

    $header = fgetcsv($handle);
    echo "<p>CSV columns: " . implode(", ", $header) . "</p>";
    flush();

    $thuongHieuMap = [];
    $danhMucMap    = [];
    $xuatXuMap     = [];

    $thDocs  = [];
    $dmDocs  = [];
    $xxDocs  = [];
    $spBatch = [];

    $thId = $dmId = $xxId = 1;
    $countSP = $countDup = $countSkip = 0;
    $seenMa  = [];
    $errors  = [];

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 20 || empty($row[0])) continue;

        $ten_san_pham      = cleanStr($row[0]);
        $danh_muc_day_du   = cleanStr($row[1]);
        $loai_san_pham     = cleanStr($row[2]);
        $gia_ban           = safeInt($row[3]);
        $gia_thi_truong    = safeInt($row[4]);
        $tien_tiet_kiem    = safeInt($row[5]);
        $phan_tram_giam    = safeInt($row[6]);
        $diem_danh_gia     = safeFloat($row[7]);
        $so_luong_danh_gia = safeInt($row[8]);
        $thuong_hieu       = cleanStr($row[9]);
        $xuat_xu           = cleanStr($row[10]);
        $noi_san_xuat      = cleanStr($row[11]);
        $dung_tich         = cleanStr($row[12]);
        $loai_da           = cleanStr($row[13]);
        $link_hinh_anh     = cleanStr($row[14]);
        $mo_ta             = cleanStr($row[15]);
        $thanh_phan_chinh  = cleanStr($row[16]);
        $thanh_phan_day_du = cleanStr($row[17]);
        $hdsd              = cleanStr($row[18]);
        $ma_san_pham       = cleanStr($row[19]);
        $thanh_phan_clean  = cleanStr($row[20] ?? '');

        if (!$ma_san_pham || !$ten_san_pham) { $countSkip++; continue; }
        if (isset($seenMa[$ma_san_pham]))     { $countDup++;  continue; }
        $seenMa[$ma_san_pham] = true;

        if ($thuong_hieu && !isset($thuongHieuMap[$thuong_hieu])) {
            $thuongHieuMap[$thuong_hieu] = $thId;
            $thDocs[] = ['ma_thuong_hieu' => $thId, 'ten_thuong_hieu' => $thuong_hieu];
            $thId++;
        }

        if ($danh_muc_day_du && !isset($danhMucMap[$danh_muc_day_du])) {
            $danhMucMap[$danh_muc_day_du] = $dmId;
            $dmDocs[] = ['ma_danh_muc' => $dmId, 'ten_danh_muc' => $danh_muc_day_du, 'danh_muc_day_du' => $danh_muc_day_du];
            $dmId++;
        }

        if ($xuat_xu && !isset($xuatXuMap[$xuat_xu])) {
            $xuatXuMap[$xuat_xu] = $xxId;
            $xxDocs[] = ['ma_xuat_xu' => $xxId, 'ten_xuat_xu' => $xuat_xu];
            $xxId++;
        }

        $firstImage = $link_hinh_anh ? explode(' | ', $link_hinh_anh)[0] : '';

        $doc = [
            'ma_san_pham'         => is_numeric($ma_san_pham) ? (int)$ma_san_pham : $ma_san_pham,
            'ten_san_pham'        => $ten_san_pham,
            'ma_thuong_hieu'      => $thuongHieuMap[$thuong_hieu] ?? null,
            'ma_danh_muc'         => $danhMucMap[$danh_muc_day_du] ?? null,
            'ma_xuat_xu'          => $xuatXuMap[$xuat_xu] ?? null,
            'danh_muc_day_du'     => $danh_muc_day_du,
            'loai_san_pham'       => $loai_san_pham,
            'thuong_hieu'         => $thuong_hieu,
            'xuat_xu_thuong_hieu' => $xuat_xu,
            'noi_san_xuat'        => $noi_san_xuat,
            'gia_ban'             => $gia_ban,
            'gia_thi_truong'      => $gia_thi_truong,
            'tien_tiet_kiem'      => $tien_tiet_kiem,
            'phan_tram_giam'      => $phan_tram_giam,
            'diem_danh_gia'       => $diem_danh_gia,
            'so_luong_danh_gia'   => $so_luong_danh_gia,
            'dung_tich'           => $dung_tich,
            'loai_da'             => $loai_da,
            'link_hinh_anh'       => $link_hinh_anh,
            'hinh_anh'            => $firstImage,
            'mo_ta'               => $mo_ta,
            'thanh_phan_chinh'    => $thanh_phan_chinh,
            'thanh_phan_day_du'   => $thanh_phan_day_du,
            'thanh_phan_clean'    => $thanh_phan_clean,
            'hdsd'                => $hdsd,
            'trang_thai'          => 'active',
            'status'              => 'active',
            'luot_xem'            => 0,
            'so_luong_ban'        => 0,
            'ngay_tao'            => new MongoDB\BSON\UTCDateTime(),
            'created_at'          => new MongoDB\BSON\UTCDateTime(),
        ];

        $spBatch[] = $doc;
        $countSP++;

        if (count($spBatch) >= 500) {
            $pdo->san_pham->insertMany($spBatch);
            $spBatch = [];
            echo "Processed $countSP products...<br>";
            flush();
        }
    }

    fclose($handle);

    if (!empty($spBatch)) {
        $pdo->san_pham->insertMany($spBatch);
    }

    if ($thDocs) $pdo->thuong_hieu->insertMany($thDocs);
    if ($dmDocs) $pdo->danh_muc->insertMany($dmDocs);
    if ($xxDocs) $pdo->xuat_xu->insertMany($xxDocs);

    $pdo->san_pham->createIndex(['ma_san_pham'     => 1], ['unique' => true]);
    $pdo->san_pham->createIndex(['ten_san_pham'    => 1]);
    $pdo->san_pham->createIndex(['danh_muc_day_du' => 1]);
    $pdo->san_pham->createIndex(['thuong_hieu'     => 1]);
    $pdo->san_pham->createIndex(['trang_thai'      => 1]);
    $pdo->san_pham->createIndex(['gia_ban'         => 1]);
    $pdo->san_pham->createIndex(['diem_danh_gia'   => 1]);
    $pdo->san_pham->createIndex(['luot_xem'        => 1]);
    $pdo->thuong_hieu->createIndex(['ma_thuong_hieu' => 1], ['unique' => true]);
    $pdo->danh_muc->createIndex(['ma_danh_muc'      => 1], ['unique' => true]);
    $pdo->xuat_xu->createIndex(['ma_xuat_xu'        => 1], ['unique' => true]);

    echo "<h3>MongoDB import completed successfully!</h3>";
    echo "<ul>";
    echo "<li>Products imported   : <strong>$countSP</strong></li>";
    echo "<li>Skipped (duplicate) : <strong>$countDup</strong></li>";
    echo "<li>Skipped (missing)   : <strong>$countSkip</strong></li>";
    echo "<li>New brands          : <strong>" . count($thDocs) . "</strong></li>";
    echo "<li>New categories      : <strong>" . count($dmDocs) . "</strong></li>";
    echo "<li>New origins         : <strong>" . count($xxDocs) . "</strong></li>";
    echo "</ul>";

    echo "<h4>MongoDB verification:</h4>";
    echo "<p>san_pham    : <strong>" . $pdo->san_pham->countDocuments([]) . "</strong> documents</p>";
    echo "<p>thuong_hieu : <strong>" . $pdo->thuong_hieu->countDocuments([]) . "</strong> documents</p>";
    echo "<p>danh_muc    : <strong>" . $pdo->danh_muc->countDocuments([]) . "</strong> documents</p>";

    if (!empty($errors)) {
        echo "<h4>Errors encountered (max 10):</h4><ul>";
        foreach (array_slice($errors, 0, 10) as $err) {
            echo "<li>" . htmlspecialchars($err) . "</li>";
        }
        echo "</ul>";
    }

} catch (Throwable $e) {
    echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
