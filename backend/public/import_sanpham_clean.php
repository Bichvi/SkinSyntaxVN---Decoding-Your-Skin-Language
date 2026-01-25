<?php
// backend/public/import_sanpham_clean.php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/db.php';

set_time_limit(0);
ini_set('memory_limit', '1024M');

function detect_delimiter(string $line): string {
    $delims = ["," , ";" , "\t", "|"];
    $best = ",";
    $bestCount = 0;
    foreach ($delims as $d) {
        $c = substr_count($line, $d);
        if ($c > $bestCount) { $bestCount = $c; $best = $d; }
    }
    return $best;
}

function norm_header(string $h): string {
    $h = trim($h);
    // remove UTF-8 BOM if exists
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    $h = mb_strtolower($h);
    $h = str_replace([" ", "-"], "_", $h);
    return $h;
}

function to_null_if_empty($v) {
    if ($v === null) return null;
    $v = trim((string)$v);
    return ($v === '') ? null : $v;
}

function to_int_or_null($v): ?int {
    $v = to_null_if_empty($v);
    if ($v === null) return null;
    $v = preg_replace('/[^\d\-]/u', '', (string)$v);
    if ($v === '' || $v === '-') return null;
    return (int)$v;
}

function to_number_or_null($v): ?float {
    $v = to_null_if_empty($v);
    if ($v === null) return null;

    // remove currency + spaces
    $s = (string)$v;
    $s = str_replace(["đ","Đ","₫"], "", $s);
    $s = trim($s);

    // handle 432.000 or 432,000 -> 432000
    // remove thousand separators: '.' and ','
    // keep minus + digits only
    $s = preg_replace('/[^\d\-,\.]/u', '', $s);
    $s = str_replace([",","."], "", $s);

    if ($s === '' || $s === '-') return null;
    return (float)$s;
}

$csvPath = __DIR__ . '/../../database/data_clean_final.csv';
if (!file_exists($csvPath)) {
    http_response_code(404);
    exit("Không tìm thấy file CSV tại: $csvPath");
}

$fh = fopen($csvPath, 'r');
if (!$fh) {
    http_response_code(500);
    exit("Không mở được file CSV.");
}

// đọc dòng đầu để detect delimiter
$firstLine = fgets($fh);
if ($firstLine === false) exit("File CSV rỗng.");
$delimiter = detect_delimiter($firstLine);

// quay lại đầu file
rewind($fh);

// đọc header
$rawHeader = fgetcsv($fh, 0, $delimiter);
if (!$rawHeader) exit("Không đọc được header CSV.");

$header = array_map('norm_header', $rawHeader);

// yêu cầu tối thiểu
$required = [
  'ten_san_pham','danh_muc_day_du','loai_san_pham','gia_ban','gia_thi_truong',
  'tien_tiet_kiem','phan_tram_giam','diem_danh_gia','so_luong_danh_gia',
  'thuong_hieu','xuat_xu_thuong_hieu','noi_san_xuat','dung_tich','loai_da',
  'link_hinh_anh','mo_ta','thanh_phan_chinh','thanh_phan_day_du','hdsd',
  'ma_san_pham','thanh_phan_clean'
];

$missing = array_values(array_diff($required, $header));
if (!empty($missing)) {
    http_response_code(400);
    exit("Thiếu cột trong CSV: " . implode(", ", $missing) . ". Bạn kiểm tra lại header file CSV.");
}

// map header -> index
$idx = array_flip($header);

$sql = "
INSERT INTO sanpham (
  ten_san_pham, danh_muc_day_du, loai_san_pham,
  gia_ban, gia_thi_truong, tien_tiet_kiem, phan_tram_giam,
  diem_danh_gia, so_luong_danh_gia,
  thuong_hieu, xuat_xu_thuong_hieu, noi_san_xuat,
  dung_tich, loai_da,
  link_hinh_anh, mo_ta,
  thanh_phan_chinh, thanh_phan_day_du, hdsd,
  ma_san_pham, thanh_phan_clean
) VALUES (
  :ten_san_pham, :danh_muc_day_du, :loai_san_pham,
  :gia_ban, :gia_thi_truong, :tien_tiet_kiem, :phan_tram_giam,
  :diem_danh_gia, :so_luong_danh_gia,
  :thuong_hieu, :xuat_xu_thuong_hieu, :noi_san_xuat,
  :dung_tich, :loai_da,
  :link_hinh_anh, :mo_ta,
  :thanh_phan_chinh, :thanh_phan_day_du, :hdsd,
  :ma_san_pham, :thanh_phan_clean
)
ON CONFLICT (ma_san_pham) DO UPDATE SET
  ten_san_pham = EXCLUDED.ten_san_pham,
  danh_muc_day_du = EXCLUDED.danh_muc_day_du,
  loai_san_pham = EXCLUDED.loai_san_pham,
  gia_ban = EXCLUDED.gia_ban,
  gia_thi_truong = EXCLUDED.gia_thi_truong,
  tien_tiet_kiem = EXCLUDED.tien_tiet_kiem,
  phan_tram_giam = EXCLUDED.phan_tram_giam,
  diem_danh_gia = EXCLUDED.diem_danh_gia,
  so_luong_danh_gia = EXCLUDED.so_luong_danh_gia,
  thuong_hieu = EXCLUDED.thuong_hieu,
  xuat_xu_thuong_hieu = EXCLUDED.xuat_xu_thuong_hieu,
  noi_san_xuat = EXCLUDED.noi_san_xuat,
  dung_tich = EXCLUDED.dung_tich,
  loai_da = EXCLUDED.loai_da,
  link_hinh_anh = EXCLUDED.link_hinh_anh,
  mo_ta = EXCLUDED.mo_ta,
  thanh_phan_chinh = EXCLUDED.thanh_phan_chinh,
  thanh_phan_day_du = EXCLUDED.thanh_phan_day_du,
  hdsd = EXCLUDED.hdsd,
  thanh_phan_clean = EXCLUDED.thanh_phan_clean
";

$stmt = $pdo->prepare($sql);

$ok = 0;
$skip = 0;

$pdo->beginTransaction();

while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
    // bỏ dòng rỗng
    if (count($row) <= 1) continue;

    $ma = to_null_if_empty($row[$idx['ma_san_pham']] ?? null);
    if ($ma === null) { $skip++; continue; }

    $data = [
      ':ten_san_pham'        => to_null_if_empty($row[$idx['ten_san_pham']] ?? null),
      ':danh_muc_day_du'     => to_null_if_empty($row[$idx['danh_muc_day_du']] ?? null),
      ':loai_san_pham'       => to_null_if_empty($row[$idx['loai_san_pham']] ?? null),

      ':gia_ban'             => to_number_or_null($row[$idx['gia_ban']] ?? null),
      ':gia_thi_truong'      => to_number_or_null($row[$idx['gia_thi_truong']] ?? null),
      ':tien_tiet_kiem'      => to_number_or_null($row[$idx['tien_tiet_kiem']] ?? null),
      ':phan_tram_giam'      => to_number_or_null($row[$idx['phan_tram_giam']] ?? null),

      ':diem_danh_gia'       => to_number_or_null($row[$idx['diem_danh_gia']] ?? null),
      ':so_luong_danh_gia'   => to_int_or_null($row[$idx['so_luong_danh_gia']] ?? null),

      ':thuong_hieu'         => to_null_if_empty($row[$idx['thuong_hieu']] ?? null),
      ':xuat_xu_thuong_hieu' => to_null_if_empty($row[$idx['xuat_xu_thuong_hieu']] ?? null),
      ':noi_san_xuat'         => to_null_if_empty($row[$idx['noi_san_xuat']] ?? null),

      ':dung_tich'           => to_null_if_empty($row[$idx['dung_tich']] ?? null),
      ':loai_da'             => to_null_if_empty($row[$idx['loai_da']] ?? null),

      ':link_hinh_anh'       => to_null_if_empty($row[$idx['link_hinh_anh']] ?? null),
      ':mo_ta'               => to_null_if_empty($row[$idx['mo_ta']] ?? null),

      ':thanh_phan_chinh'    => to_null_if_empty($row[$idx['thanh_phan_chinh']] ?? null),
      ':thanh_phan_day_du'   => to_null_if_empty($row[$idx['thanh_phan_day_du']] ?? null),
      ':hdsd'                => to_null_if_empty($row[$idx['hdsd']] ?? null),

      ':ma_san_pham'         => $ma,
      ':thanh_phan_clean'    => to_null_if_empty($row[$idx['thanh_phan_clean']] ?? null),
    ];

    try {
        $stmt->execute($data);
        $ok++;
        // commit theo lô để đỡ nặng
        if ($ok % 1000 === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    } catch (Throwable $e) {
        $skip++;
        // bỏ qua dòng lỗi
        continue;
    }
}

$pdo->commit();
fclose($fh);

echo "Delimiter phát hiện: " . ($delimiter === "\t" ? "\\t (TAB)" : $delimiter) . "<br>";
echo "Đã insert/update: {$ok} dòng<br>";
echo "Bỏ qua: {$skip} dòng<br>";
echo "Xong.";
