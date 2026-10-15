<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;
$spCount = $db->san_pham->countDocuments();
$dmCount = $db->danh_muc->countDocuments();
echo "SAN_PHAM_COUNT={$spCount}" . PHP_EOL;
echo "DANH_MUC_COUNT={$dmCount}" . PHP_EOL;
if ($spCount === 2473 && $dmCount === 28) {
    echo "CANONICAL_DB_STATE=MATCH" . PHP_EOL;
    exit(0);
} else {
    echo "CANONICAL_DB_STATE=MISMATCH" . PHP_EOL;
    exit(1);
}
