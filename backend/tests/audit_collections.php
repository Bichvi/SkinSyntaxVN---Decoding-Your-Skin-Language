<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

echo "=== 1. ALL COLLECTIONS IN DATABASE ===\n";
foreach ($db->listCollections() as $col) {
    $cName = $col->getName();
    $cnt = $db->selectCollection($cName)->countDocuments();
    echo sprintf("%-30s: %d documents\n", $cName, $cnt);
}
