<?php
require_once __DIR__ . "/../app/config/db.php";

function normalize_header($h) {
    // bỏ BOM + trim + lowercase
    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
    $h = trim($h);
    $h = mb_strtolower($h, 'UTF-8');
    return $h;
}

function detect_delimiter($line) {
    $c = substr_count($line, ',');
    $s = substr_count($line, ';');
    $t = substr_count($line, "\t");
    if ($s >= $c && $s >= $t) return ';';
    if ($t >= $c && $t >= $s) return "\t";
    return ',';
}

function to_int($v) {
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;
    // giữ lại số
    $v = preg_replace('/[^\d]/', '', $v);
    return ($v === '') ? null : (int)$v;
}

function to_float($v) {
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;
    $v = str_replace(',', '.', $v);
    $v = preg_replace('/[^\d\.]/', '', $v);
    return ($v === '') ? null : (float)$v;
}

$csvPath = __DIR__ . "/../../database/data_hasaki_v9_complete.csv";
if (!file_exists($csvPath)) {
    die("Không tìm thấy CSV tại: " . htmlspecialchars($csvPath));
}

$fh = fopen($csvPath, 'r');
if (!$fh) die("Không mở được file CSV.");

$firstLine = fgets($fh);
if ($firstLine === false) die("CSV rỗng.");
$delimiter = detect_delimiter($firstLine);

// đọc lại từ đầu
rewind($fh);

$rawHeader = fgetcsv($fh, 0, $delimiter);
if (!$rawHeader) die("Không đọc được header CSV.");

// normalize header
$header = array_map('normalize_header', $rawHeader);

// Map tên cột CSV -> tên field chuẩn DB
// (hỗ trợ cả CSV cũ kiểu title/brand/image_url...)
$map = [
  'ten_san_pham' => ['ten_san_pham', 'title', 'tên_sản_phẩm', 'ten san pham'],
  'danh_muc_day_du' => ['danh_muc_day_du', 'danh_muc', 'category'],
  'loai_san_pham' => ['loai_san_pham', 'loai', 'product_type'],
  'gia_ban' => ['gia_ban', 'price', 'gia'],
  'gia_thi_truong' => ['gia_thi_truong'],
  'tien_tiet_kiem' => ['tien_tiet_kiem'],
  'phan_tram_giam' => ['phan_tram_giam'],
  'diem_danh_gia' => ['diem_danh_gia'],
  'so_luong_danh_gia' => ['so_luong_danh_gia'],
  'thuong_hieu' => ['thuong_hieu', 'brand'],
  'xuat_xu_thuong_hieu' => ['xuat_xu_thuong_hieu'],
  'noi_san_xuat' => ['noi_san_xuat'],
  'dung_tich' => ['dung_tich'],
  'loai_da' => ['loai_da'],
  'barcode' => ['barcode'],
  'ma_san_pham' => ['ma_san_pham'],
  'link_hinh_anh' => ['link_hinh_anh', 'image_url'],
  'mo_ta' => ['mo_ta', 'quick_analysis', 'description'],
  'thanh_phan_chinh' => ['thanh_phan_chinh', 'ingredients_main'],
  'thanh_phan_day_du' => ['thanh_phan_day_du', 'ingredients'],
  'hdsd' => ['hdsd'],
  'noi_dung_danh_gia' => ['noi_dung_danh_gia'],
  'hoi_dap' => ['hoi_dap'],
  'url_san_pham' => ['url_san_pham', 'url']
];

// tìm index cho từng field
$idx = [];
foreach ($map as $field => $aliases) {
    $idx[$field] = -1;
    foreach ($aliases as $a) {
        $a = normalize_header($a);
        $pos = array_search($a, $header, true);
        if ($pos !== false) { $idx[$field] = $pos; break; }
    }
}

// bắt buộc phải có ten_san_pham (và tốt nhất có url_san_pham)
if ($idx['ten_san_pham'] === -1) {
    echo "<pre>";
    echo "Thiếu cột bắt buộc: ten_san_pham\n";
    echo "Header phát hiện:\n";
    print_r($header);
    echo "</pre>";
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "
INSERT INTO sanpham (
  ten_san_pham, danh_muc_day_du, loai_san_pham,
  gia_ban, gia_thi_truong, tien_tiet_kiem, phan_tram_giam,
  diem_danh_gia, so_luong_danh_gia,
  thuong_hieu, xuat_xu_thuong_hieu, noi_san_xuat, dung_tich, loai_da,
  barcode, ma_san_pham,
  link_hinh_anh, mo_ta,
  thanh_phan_chinh, thanh_phan_day_du, hdsd, noi_dung_danh_gia, hoi_dap,
  url_san_pham
) VALUES (
  :ten_san_pham, :danh_muc_day_du, :loai_san_pham,
  :gia_ban, :gia_thi_truong, :tien_tiet_kiem, :phan_tram_giam,
  :diem_danh_gia, :so_luong_danh_gia,
  :thuong_hieu, :xuat_xu_thuong_hieu, :noi_san_xuat, :dung_tich, :loai_da,
  :barcode, :ma_san_pham,
  :link_hinh_anh, :mo_ta,
  :thanh_phan_chinh, :thanh_phan_day_du, :hdsd, :noi_dung_danh_gia, :hoi_dap,
  :url_san_pham
)
ON CONFLICT (url_san_pham)
DO UPDATE SET
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
  link_hinh_anh = EXCLUDED.link_hinh_anh,
  mo_ta = EXCLUDED.mo_ta,
  thanh_phan_chinh = EXCLUDED.thanh_phan_chinh,
  thanh_phan_day_du = EXCLUDED.thanh_phan_day_du
";

$stmt = $pdo->prepare($sql);

$inserted = 0;
$errors = 0;

$pdo->beginTransaction();
while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
    try {
        $get = function($field) use ($idx, $row) {
            $i = $idx[$field] ?? -1;
            if ($i === -1) return null;
            return isset($row[$i]) ? trim($row[$i]) : null;
        };

        $ten = $get('ten_san_pham');
        if (!$ten) continue;

        $url = $get('url_san_pham');
        // nếu CSV không có url_san_pham thì tạo tạm từ tên (để tránh NULL conflict)
        if (!$url) {
            $url = 'local-' . md5($ten);
        }

        $stmt->execute([
          ':ten_san_pham' => $ten,
          ':danh_muc_day_du' => $get('danh_muc_day_du'),
          ':loai_san_pham' => $get('loai_san_pham'),

          ':gia_ban' => to_int($get('gia_ban')),
          ':gia_thi_truong' => to_int($get('gia_thi_truong')),
          ':tien_tiet_kiem' => to_int($get('tien_tiet_kiem')),
          ':phan_tram_giam' => to_int($get('phan_tram_giam')),

          ':diem_danh_gia' => to_float($get('diem_danh_gia')),
          ':so_luong_danh_gia' => to_int($get('so_luong_danh_gia')),

          ':thuong_hieu' => $get('thuong_hieu'),
          ':xuat_xu_thuong_hieu' => $get('xuat_xu_thuong_hieu'),
          ':noi_san_xuat' => $get('noi_san_xuat'),
          ':dung_tich' => $get('dung_tich'),
          ':loai_da' => $get('loai_da'),

          ':barcode' => $get('barcode'),
          ':ma_san_pham' => $get('ma_san_pham'),

          ':link_hinh_anh' => $get('link_hinh_anh'),
          ':mo_ta' => $get('mo_ta'),

          ':thanh_phan_chinh' => $get('thanh_phan_chinh'),
          ':thanh_phan_day_du' => $get('thanh_phan_day_du'),
          ':hdsd' => $get('hdsd'),
          ':noi_dung_danh_gia' => $get('noi_dung_danh_gia'),
          ':hoi_dap' => $get('hoi_dap'),

          ':url_san_pham' => $url
        ]);

        $inserted++;
    } catch (Exception $e) {
        $errors++;
    }
}
$pdo->commit();

fclose($fh);

echo "<pre>";
echo "Delimiter phát hiện: " . $delimiter . "\n";
echo "Đã xử lý: $inserted dòng\n";
echo "Lỗi (bỏ qua): $errors dòng\n";
echo "Kiểm tra nhanh: SELECT COUNT(*) FROM sanpham;\n";
echo "</pre>";
