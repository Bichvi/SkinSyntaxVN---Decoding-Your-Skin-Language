<?php
/**
 * Import dữ liệu từ CSV vào schema mới
 */

require_once __DIR__ . '/../app/config/db.php';

set_time_limit(600);
ini_set('memory_limit', '1024M');

$csvFile = dirname(__DIR__, 2) . '/database/data_clean_final.csv';

if (!file_exists($csvFile)) {
    die("❌ File CSV không tìm thấy: $csvFile");
}

echo "<h2>📊 Import dữ liệu từ CSV vào Schema Mới</h2>";
echo "<p>File: <strong>$csvFile</strong></p>";

try {
    // XÓA DỮ LIỆU CŨ (TRUNCATE)
    echo "<p style='color: #e74c3c;'><strong>⚠️ Xóa dữ liệu cũ...</strong></p>";
    $pdo->exec("TRUNCATE TABLE lich_su_chat CASCADE");
    $pdo->exec("TRUNCATE TABLE danh_gia CASCADE");
    $pdo->exec("TRUNCATE TABLE chi_tiet_hoa_don CASCADE");
    $pdo->exec("TRUNCATE TABLE gio_hang CASCADE");
    $pdo->exec("TRUNCATE TABLE hoa_don CASCADE");
    $pdo->exec("TRUNCATE TABLE san_pham CASCADE");
    $pdo->exec("TRUNCATE TABLE danh_muc CASCADE");
    $pdo->exec("TRUNCATE TABLE loai_san_pham CASCADE");
    $pdo->exec("TRUNCATE TABLE xuat_xu_thuong_hieu CASCADE");
    $pdo->exec("TRUNCATE TABLE noi_san_xuat CASCADE");
    $pdo->exec("TRUNCATE TABLE thuong_hieu CASCADE");
    echo "<p style='color: green;'><strong>✅ Đã xóa dữ liệu cũ</strong></p>";

    $handle = fopen($csvFile, 'r');
    if (!$handle) die("❌ Không thể mở file CSV");

    // Header
    $header = fgetcsv($handle);
    echo "<p>Cột CSV: " . implode(", ", $header) . "</p>";

    $count = 0;
    $countThuongHieu = 0;
    $countNoiSanXuat = 0;
    $countXuatXu = 0;
    $countLoaiSanPham = 0;
    $countDanhMuc = 0;
    $countSanPham = 0;
    $errors = [];

    // Mảng lưu các ID đã insert
    $thuongHieuMap = [];
    $noiSanXuatMap = [];
    $xuatXuMap = [];
    $loaiSanPhamMap = [];
    $danhMucMap = [];

    while (($row = fgetcsv($handle)) !== false) {
        if (empty($row[0])) continue;

        try {
            // Lấy dữ liệu từ row
            $ten_san_pham = $row[0] ?? null;
            $danh_muc_day_du = $row[1] ?? null;
            $loai_san_pham = $row[2] ?? null;
            $gia_ban = !empty($row[3]) ? (int)$row[3] : 0;
            $gia_thi_truong = !empty($row[4]) ? (int)$row[4] : 0;
            $tien_tiet_kiem = !empty($row[5]) ? (int)$row[5] : 0;
            $phan_tram_giam = !empty($row[6]) ? (int)$row[6] : 0;
            $diem_danh_gia = !empty($row[7]) ? (float)$row[7] : null;
            $so_luong_danh_gia = !empty($row[8]) ? (int)$row[8] : 0;
            $thuong_hieu = $row[9] ?? null;
            $xuat_xu_thuong_hieu = $row[10] ?? null;
            $noi_san_xuat = $row[11] ?? null;
            $dung_tich = $row[12] ?? null;
            $loai_da = $row[13] ?? null;
            $link_hinh_anh = $row[14] ?? null;
            $mo_ta = $row[15] ?? null;
            $thanh_phan_chinh = $row[16] ?? null;
            $thanh_phan_day_du = $row[17] ?? null;
            $hdsd = $row[18] ?? null;
            $ma_san_pham = $row[19] ?? null;

            // 1. INSERT THUONG_HIEU
            if ($thuong_hieu && !isset($thuongHieuMap[$thuong_hieu])) {
                $stmt = $pdo->prepare("INSERT INTO thuong_hieu (ten_thuong_hieu, status) 
                                      VALUES (:ten, 'active') 
                                      RETURNING ma_thuong_hieu");
                $stmt->execute([':ten' => $thuong_hieu]);
                $thuongHieuMap[$thuong_hieu] = $stmt->fetchColumn();
                $countThuongHieu++;
            }

            // 2. INSERT NOI_SAN_XUAT
            if ($noi_san_xuat && !isset($noiSanXuatMap[$noi_san_xuat])) {
                $stmt = $pdo->prepare("INSERT INTO noi_san_xuat (ten_nsx, status) 
                                      VALUES (:ten, 'active') 
                                      RETURNING ma_nsx");
                $stmt->execute([':ten' => $noi_san_xuat]);
                $noiSanXuatMap[$noi_san_xuat] = $stmt->fetchColumn();
                $countNoiSanXuat++;
            }

            // 3. INSERT XUAT_XU
            if ($xuat_xu_thuong_hieu && !isset($xuatXuMap[$xuat_xu_thuong_hieu])) {
                $stmt = $pdo->prepare("INSERT INTO xuat_xu_thuong_hieu (ten_xuat_xu, status) 
                                      VALUES (:ten, 'active') 
                                      RETURNING ma_xuat_xu");
                $stmt->execute([':ten' => $xuat_xu_thuong_hieu]);
                $xuatXuMap[$xuat_xu_thuong_hieu] = $stmt->fetchColumn();
                $countXuatXu++;
            }

            // 4. INSERT LOAI_SAN_PHAM
            if ($loai_san_pham && !isset($loaiSanPhamMap[$loai_san_pham])) {
                $stmt = $pdo->prepare("INSERT INTO loai_san_pham (ten_loai, status) 
                                      VALUES (:ten, 'active') 
                                      RETURNING ma_loai");
                $stmt->execute([':ten' => $loai_san_pham]);
                $loaiSanPhamMap[$loai_san_pham] = $stmt->fetchColumn();
                $countLoaiSanPham++;
            }

            // 5. INSERT DANH_MUC từ danh_muc_day_du
            if ($danh_muc_day_du && !isset($danhMucMap[$danh_muc_day_du])) {
                $stmt = $pdo->prepare("INSERT INTO danh_muc (ten_danh_muc, status) 
                                      VALUES (:ten, 'active') 
                                      RETURNING ma_danh_muc");
                $stmt->execute([':ten' => $danh_muc_day_du]);
                $danhMucMap[$danh_muc_day_du] = $stmt->fetchColumn();
                $countDanhMuc++;
            }

            // 6. INSERT SAN_PHAM
            if ($ma_san_pham && $ten_san_pham) {
                $stmt = $pdo->prepare("INSERT INTO san_pham 
                    (ma_san_pham, ten_san_pham, ma_loai, ma_thuong_hieu, ma_nsx, ma_xuat_xu, ma_danh_muc,
                     gia_ban, gia_thi_truong, tien_tiet_kiem, phan_tram_giam, diem_danh_gia, so_luong_danh_gia,
                     dung_tich, loai_da, link_hinh_anh, mo_ta, thanh_phan_chinh, thanh_phan_day_du, hdsd)
                    VALUES (:ma, :ten, :loai, :th, :nsx, :xu, :dm, :gb, :gtt, :ttk, :ptg, :dag, :sldag, :dt, :ld, :lha, :mota, :tpc, :tpdd, :hdsd)
                    ON CONFLICT (ma_san_pham) DO NOTHING");
                
                $stmt->execute([
                    ':ma' => $ma_san_pham,
                    ':ten' => $ten_san_pham,
                    ':loai' => $loaiSanPhamMap[$loai_san_pham] ?? null,
                    ':th' => $thuongHieuMap[$thuong_hieu] ?? null,
                    ':nsx' => $noiSanXuatMap[$noi_san_xuat] ?? null,
                    ':xu' => $xuatXuMap[$xuat_xu_thuong_hieu] ?? null,
                    ':dm' => $danhMucMap[$danh_muc_day_du] ?? null,
                    ':gb' => $gia_ban,
                    ':gtt' => $gia_thi_truong,
                    ':ttk' => $tien_tiet_kiem,
                    ':ptg' => $phan_tram_giam,
                    ':dag' => $diem_danh_gia,
                    ':sldag' => $so_luong_danh_gia,
                    ':dt' => $dung_tich,
                    ':ld' => $loai_da,
                    ':lha' => $link_hinh_anh,
                    ':mota' => $mo_ta,
                    ':tpc' => $thanh_phan_chinh,
                    ':tpdd' => $thanh_phan_day_du,
                    ':hdsd' => $hdsd
                ]);
                $countSanPham++;
            }

            $count++;
            if ($count % 100 == 0) {
                echo "⏳ Đã xử lý $count dòng...<br>";
                flush();
            }
        } catch (Exception $e) {
            $errors[] = "Dòng $count: " . $e->getMessage();
        }
    }

    fclose($handle);

    echo "<h3>✅ Import thành công!</h3>";
    echo "<ul>";
    echo "<li>Tổng dòng xử lý: <strong>$count</strong></li>";
    echo "<li>Thương hiệu mới: <strong>$countThuongHieu</strong></li>";
    echo "<li>Nơi sản xuất mới: <strong>$countNoiSanXuat</strong></li>";
    echo "<li>Xuất xứ mới: <strong>$countXuatXu</strong></li>";
    echo "<li>Loại sản phẩm mới: <strong>$countLoaiSanPham</strong></li>";
    echo "<li>Danh mục mới: <strong>$countDanhMuc</strong></li>";
    echo "<li>Sản phẩm import: <strong>$countSanPham</strong></li>";
    echo "</ul>";

    // Kiểm tra tổng
    $result = $pdo->query("SELECT COUNT(*) as thuong_hieu FROM thuong_hieu");
    echo "<h4>📊 Thống kê cuối cùng:</h4>";
    echo "<p>Tổng thương hiệu: " . $result->fetch(PDO::FETCH_ASSOC)['thuong_hieu'] . "</p>";

    $result = $pdo->query("SELECT COUNT(*) as san_pham FROM san_pham");
    echo "<p>Tổng sản phẩm: " . $result->fetch(PDO::FETCH_ASSOC)['san_pham'] . "</p>";

    if (!empty($errors)) {
        echo "<h4>⚠️ Lỗi gặp phải:</h4>";
        echo "<ul>";
        foreach (array_slice($errors, 0, 10) as $err) {
            echo "<li>$err</li>";
        }
        echo "</ul>";
    }

} catch (Exception $e) {
    echo "❌ Lỗi: " . $e->getMessage();
}
?>
