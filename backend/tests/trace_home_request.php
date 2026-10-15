<?php
// backend/tests/trace_home_request.php

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
mark("DB connected (ping done)");
require_once __DIR__ . '/../app/services/CacheService.php';
mark("CacheService loaded");

$cache = CacheService::getInstance();
$t_cache_ping = microtime(true);
$ping = $cache->ping();
mark("Cache ping: " . ($ping ? 'OK' : 'FAIL') . " (" . round((microtime(true) - $t_cache_ping)*1000, 2) . " ms)");

require_once __DIR__ . '/../app/models/SanPham.php';
$sanPham = new SanPham($pdo);
mark("SanPham instantiated");

$t_m = microtime(true);
$menu = $sanPham->menuTree();
mark("menuTree() completed (" . round((microtime(true) - $t_m)*1000, 2) . " ms)");

$t_l = microtime(true);
$latest = $sanPham->latest(12, true, true);
mark("latest() completed (" . round((microtime(true) - $t_l)*1000, 2) . " ms)");

require_once __DIR__ . '/../app/controllers/HomeController.php';
$homeCtrl = new HomeController($pdo);
mark("HomeController instantiated");

// Reflection to call getHighlightedCategories
$ref = new ReflectionClass($homeCtrl);
$method = $ref->getMethod('getHighlightedCategories');
$method->setAccessible(true);
$t_hc = microtime(true);
$cats = $method->invoke($homeCtrl);
mark("getHighlightedCategories() completed (" . round((microtime(true) - $t_hc)*1000, 2) . " ms)");

$t_sec = microtime(true);
$sections = $sanPham->getHomepageProductSections(8, [], null, []);
mark("getHomepageProductSections() completed (" . round((microtime(true) - $t_sec)*1000, 2) . " ms)");

require_once __DIR__ . '/../app/models/FlashSaleCampaign.php';
$flashModel = new FlashSaleCampaign($pdo);
$t_fl = microtime(true);
$primary = $flashModel->getPrimaryActiveCampaign();
mark("getPrimaryActiveCampaign() completed (" . round((microtime(true) - $t_fl)*1000, 2) . " ms)");

mark("TOTAL ELAPSED");
