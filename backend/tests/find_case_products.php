<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

$cases = [
    'cleanser_oily' => ['ten_san_pham' => new \MongoDB\BSON\Regex('Eucerin.*Rửa Mặt', 'i')],
    'serum'         => ['ten_san_pham' => new \MongoDB\BSON\Regex('Timeless.*B5|Serum.*B5', 'i')],
    'moisturizer'   => ['ten_san_pham' => new \MongoDB\BSON\Regex('Neutrogena.*Cấp Nước|La Roche.*Duo|Kem Dưỡng.*CeraVe', 'i')],
    'sunscreen'     => ['ten_san_pham' => new \MongoDB\BSON\Regex('Kem Chống Nắng.*La Roche|Sunplay.*Chống Nắng', 'i')],
    'lip_balm'      => ['ten_san_pham' => new \MongoDB\BSON\Regex('Son Dưỡng.*DHC|Son Dưỡng.*Laneige', 'i')]
];

echo "=== FINDING CANDIDATE PRODUCTS FOR 5 EVALUATION CASES ===\n";
foreach ($cases as $key => $filter) {
    $p = $db->san_pham->findOne($filter, ['projection' => ['ma_san_pham' => 1, 'ten_san_pham' => 1, 'gia_ban' => 1, 'danh_muc_day_du' => 1, 'thuong_hieu' => 1, 'loai_da' => 1]]);
    if ($p) {
        echo sprintf("%-15s: ID %-6s | %-10s | %s đ | %s\n",
            $key,
            $p['ma_san_pham'],
            $p['thuong_hieu'] ?? '',
            number_format($p['gia_ban'] ?? 0),
            $p['ten_san_pham']
        );
    } else {
        echo "$key: NOT FOUND\n";
    }
}
