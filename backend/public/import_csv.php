<?php
/**
 * Import dữ liệu từ CSV vào bảng sanpham
 * Chạy: http://localhost/skinsyntax/project-root/backend/public/import_sanpham_clean.php
 */

require_once __DIR__ . '/../app/config/db.php';

// Đặt thời gian thực thi dài hơn
set_time_limit(300);
ini_set('memory_limit', '512M');

$csvFile = __DIR__ . '/../../../../database/data_clean_final.csv';

if (!file_exists($csvFile)) {
    die("❌ File CSV không tìm thấy: $csvFile");
}

try {
    // Đếm số dòng trước import
    $countBefore = $pdo->query("SELECT COUNT(*) FROM sanpham")->fetchColumn();
    
    echo "<h2>📊 Import dữ liệu từ CSV</h2>";
    echo "<p>File: <strong>$csvFile</strong></p>";
    echo "<p>Số sản phẩm trước: <strong>$countBefore</strong></p>";
    
    $handle = fopen($csvFile, 'r');
    if (!$handle) {
        die("❌ Không thể mở file CSV");
    }

    // Bỏ qua header
    $header = fgetcsv($handle);
    
    $sql = "INSERT INTO sanpham (
        ten_san_pham, danh_muc_day_du, loai_san_pham, gia_ban, 
        gia_thi_truong, tien_tiet_kiem, phan_tram_giam, diem_danh_gia, 
        so_luong_danh_gia, thuong_hieu, xuat_xu_thuong_hieu, noi_san_xuat, 
        dung_tich, loai_da, link_hinh_anh, mo_ta, thanh_phan_chinh, 
        thanh_phan_day_du, hdsd, ma_san_pham
    ) VALUES (
        :ten_san_pham, :danh_muc_day_du, :loai_san_pham, :gia_ban,
        :gia_thi_truong, :tien_tiet_kiem, :phan_tram_giam, :diem_danh_gia,
        :so_luong_danh_gia, :thuong_hieu, :xuat_xu_thuong_hieu, :noi_san_xuat,
        :dung_tich, :loai_da, :link_hinh_anh, :mo_ta, :thanh_phan_chinh,
        :thanh_phan_day_du, :hdsd, :ma_san_pham
    )";
    
    $stmt = $pdo->prepare($sql);
    
    $count = 0;
    $errors = [];
    
    while (($row = fgetcsv($handle)) !== false) {
        if (empty($row[0])) continue; // Bỏ qua dòng trống
        
        try {
            $stmt->execute([
                ':ten_san_pham' => $row[0] ?? null,
                ':danh_muc_day_du' => $row[1] ?? null,
                ':loai_san_pham' => $row[2] ?? null,
                ':gia_ban' => !empty($row[3]) ? (int)$row[3] : 0,
                ':gia_thi_truong' => !empty($row[4]) ? (int)$row[4] : 0,
                ':tien_tiet_kiem' => !empty($row[5]) ? (int)$row[5] : 0,
                ':phan_tram_giam' => !empty($row[6]) ? (int)$row[6] : 0,
                ':diem_danh_gia' => !empty($row[7]) ? (float)$row[7] : null,
                ':so_luong_danh_gia' => !empty($row[8]) ? (int)$row[8] : 0,
                ':thuong_hieu' => $row[9] ?? null,
                ':xuat_xu_thuong_hieu' => $row[10] ?? null,
                ':noi_san_xuat' => $row[11] ?? null,
                ':dung_tich' => $row[12] ?? null,
                ':loai_da' => $row[13] ?? null,
                ':link_hinh_anh' => $row[14] ?? null,
                ':mo_ta' => $row[15] ?? null,
                ':thanh_phan_chinh' => $row[16] ?? null,
                ':thanh_phan_day_du' => $row[17] ?? null,
                ':hdsd' => $row[18] ?? null,
                ':ma_san_pham' => $row[19] ?? null,
            ]);
            $count++;
            
            if ($count % 100 == 0) {
                echo "⏳ Đã import $count dòng...<br>";
                flush();
            }
        } catch (Exception $e) {
            $errors[] = "Dòng $count: " . $e->getMessage();
        }
    }
    
    fclose($handle);
    
    // Cập nhật gia_goc = gia_thi_truong
    $pdo->exec("UPDATE sanpham SET gia_goc = gia_thi_truong WHERE gia_goc IS NULL");
    
    $countAfter = $pdo->query("SELECT COUNT(*) FROM sanpham")->fetchColumn();
    
    echo "<h3>✅ Import thành công!</h3>";
    echo "<p>Số dòng đã import: <strong>$count</strong></p>";
    echo "<p>Tổng sản phẩm sau: <strong>$countAfter</strong></p>";
    
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
