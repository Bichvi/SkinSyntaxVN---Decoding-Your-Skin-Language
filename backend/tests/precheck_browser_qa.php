<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;
$spCount = $db->san_pham->countDocuments();
$dmCount = $db->danh_muc->countDocuments();
$mode = defined('DB_MODE') ? DB_MODE : 'undefined';
echo "DB_MODE=$mode" . PHP_EOL;
echo "san_pham=$spCount" . PHP_EOL;
echo "danh_muc=$dmCount" . PHP_EOL;
if ($mode === 'atlas' && $spCount === 2473 && $dmCount === 28) {
    echo "PRECHECK_PASS" . PHP_EOL;
    exit(0);
} else {
    echo "PRECHECK_FAIL" . PHP_EOL;
    exit(1);
}
