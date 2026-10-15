<?php
// backend/tests/trace_pdp_request.php

$t0 = microtime(true);
function mark($label) {
    global $t0;
    $now = microtime(true);
    $diff = round(($now - $t0) * 1000, 2);
    echo sprintf("[%8.2f ms] %s\n", $diff, $label);
}

mark("Start script");
require_once __DIR__ . '/../vendor/autoload.php';
mark("Autoload loaded");
require_once __DIR__ . '/../app/config/config.php';
mark("Config loaded");
require_once __DIR__ . '/../app/config/db.php';
mark("DB connected");
require_once __DIR__ . '/../app/services/CacheService.php';
mark("CacheService loaded");
require_once '/var/www/frontend/views/helpers.php';
mark("Helpers loaded");

require_once __DIR__ . '/../app/models/SanPham.php';
require_once __DIR__ . '/../app/controllers/SanPhamController.php';

$_GET = ['r' => 'chitiet', 'id' => '93'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '?r=chitiet&id=93';
$_SERVER['SCRIPT_NAME'] = '/index.php';

$ctrl = new SanPhamController($pdo);
mark("Controller instantiated");

$t_exec = microtime(true);
ob_start();
$ctrl->chitiet();
$html = ob_get_clean();
mark("chitiet() executed (" . round((microtime(true) - $t_exec)*1000, 2) . " ms) | HTML length: " . strlen($html));
