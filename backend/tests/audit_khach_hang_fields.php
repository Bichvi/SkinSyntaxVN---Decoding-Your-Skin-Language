<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

$docs = iterator_to_array($db->khach_hang->find());
$fieldsToCheck = [
    'loai_da',
    'ma_loai_da',
    'muc_do_nhay_cam',
    'van_de_da',
    'muc_tieu_cham_soc',
    'ngan_sach',
    'thanh_phan_tranh',
    'tinh_trang_dac_biet',
    'tieu_chi_uu_tien',
    'kinh_nghiem_skincare',
    'so_buoc_skincare'
];

echo "=================================================================\n";
echo "STEP 2: AUDIT OF khach_hang FIELDS ACROSS 20 CUSTOMERS\n";
echo "=================================================================\n";

foreach ($fieldsToCheck as $f) {
    $nonEmpty = 0;
    $types = [];
    $values = [];

    foreach ($docs as $d) {
        $val = $d[$f] ?? null;
        if ($val !== null && $val !== '') {
            $nonEmpty++;
            $t = gettype($val);
            if ($t === 'object') $t = get_class($val);
            $types[$t] = true;
            $sVal = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string)$val;
            $values[] = $sVal;
        }
    }

    $uniqueVals = array_unique($values);
    echo "-----------------------------------------------------------------\n";
    echo "FIELD: $f\n";
    echo "  Data Types: " . implode(', ', array_keys($types)) . "\n";
    echo "  Non-Empty Count: $nonEmpty / " . count($docs) . "\n";
    echo "  Unique Values Count: " . count($uniqueVals) . "\n";
    echo "  Examples:\n";
    foreach (array_slice($uniqueVals, 0, 4) as $ex) {
        echo "    * " . mb_substr($ex, 0, 80) . "\n";
    }
}
