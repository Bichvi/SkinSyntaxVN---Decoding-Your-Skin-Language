<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

echo "gio_hang count: " . $db->gio_hang->countDocuments() . "\n";
echo "yeu_thich count: " . $db->yeu_thich->countDocuments() . "\n";
echo "wishlist count: " . $db->wishlist->countDocuments() . "\n";
echo "san_pham_yeu_thich count: " . $db->san_pham_yeu_thich->countDocuments() . "\n";
