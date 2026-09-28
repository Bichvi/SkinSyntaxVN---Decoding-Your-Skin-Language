<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

echo "--- DANH_GIA_SAN_PHAM ---\n";
$dgsp = (array)$db->danh_gia_san_pham->findOne();
print_r(array_keys($dgsp));
print_r($dgsp);

echo "--- DANH_GIA ---\n";
$dg = (array)$db->danh_gia->findOne();
print_r(array_keys($dg));
print_r($dg);

echo "--- HOI_DAP_SAN_PHAM ---\n";
$hd = (array)$db->hoi_dap_san_pham->findOne();
print_r(array_keys($hd));
print_r($hd);
