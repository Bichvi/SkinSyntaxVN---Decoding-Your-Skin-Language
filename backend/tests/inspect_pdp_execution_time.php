<?php
// backend/tests/inspect_pdp_execution_time.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CacheService.php';
require_once '/var/www/frontend/views/helpers.php';
require_once __DIR__ . '/../app/controllers/SanPhamController.php';

$_GET = ['r' => 'chitiet', 'id' => '111'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '?r=chitiet&id=111';

$ctrl = new SanPhamController($pdo);

echo "1. First call (PDP warm cache):\n";
$t0 = microtime(true);
ob_start();
$ctrl->chitiet();
$html1 = ob_get_clean();
$dur1 = round((microtime(true) - $t0) * 1000, 2);
echo "   Execution time: {$dur1} ms | HTML length: " . strlen($html1) . "\n";

echo "\n2. Second call (PDP warm cache + L1):\n";
$t1 = microtime(true);
ob_start();
$ctrl->chitiet();
$html2 = ob_get_clean();
$dur2 = round((microtime(true) - $t1) * 1000, 2);
echo "   Execution time: {$dur2} ms | HTML length: " . strlen($html2) . "\n";
