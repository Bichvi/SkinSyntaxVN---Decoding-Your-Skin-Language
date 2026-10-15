<?php
// backend/tests/test_composite_homepage.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/services/CacheService.php';
require_once __DIR__ . '/../app/controllers/HomeController.php';

$cache = CacheService::getInstance();
$cache->delete('home:composite_public');
$cache->clearL1();

$controller = new HomeController($pdo);

echo "1. Testing Cache MISS on Homepage Public Data...\n";
$t0 = microtime(true);
$missData = $controller->getHomepagePublicData();
$missMs = round((microtime(true) - $t0) * 1000, 2);
echo "   MISS took: {$missMs} ms\n";
echo "   Items in latest: " . count($missData['latest']) . "\n";
echo "   Items in cats: " . count($missData['cats']) . "\n";
echo "   Items in flash_deals: " . count($missData['flash_deals']) . "\n";
echo "   Menu roots: " . count($missData['menu_tree']) . "\n";

echo "\n2. Testing Cache HIT on Homepage Public Data...\n";
// Clear L1 to test true L2 Valkey retrieval
$cache->clearL1();
$t1 = microtime(true);
$hitData = $controller->getHomepagePublicData();
$hitMs = round((microtime(true) - $t1) * 1000, 2);
echo "   HIT took: {$hitMs} ms\n";
echo "   Items in latest: " . count($hitData['latest']) . "\n";

$speedup = round($missMs / max(0.001, $hitMs), 1);
echo "\n>>> Result: Cache HIT is {$speedup}x faster than MISS ({$hitMs} ms vs {$missMs} ms)! <<<\n";
