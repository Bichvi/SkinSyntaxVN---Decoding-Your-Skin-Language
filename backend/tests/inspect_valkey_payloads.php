<?php
// backend/tests/inspect_valkey_payloads.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/services/CacheService.php';

$cache = CacheService::getInstance();

$keys = [
    'menu:tree:14',
    'home:latest:12:1:1',
    'home:highlighted_categories',
    'home:flash_deals:8',
    'catalog:tatca:' . md5(json_encode(['p' => 1, 'per' => 24, 'q' => '', 'c1' => '', 'c2' => '', 'sort' => 'default', 'v' => 1])),
    'product:detail:111',
    'product:freq_bought:111',
    'product:videos:111',
];

echo "VALKEY KEY PAYLOAD INSPECTION:\n";
echo str_repeat('-', 80) . "\n";
printf("%-45s | %-12s | %-12s\n", "Key", "Length (Bytes)", "Get Time (ms)");
echo str_repeat('-', 80) . "\n";

foreach ($keys as $k) {
    $t = microtime(true);
    $val = $cache->get($k);
    $dur = round((microtime(true) - $t) * 1000, 2);
    $len = $val !== null ? strlen(json_encode($val)) : 0;
    printf("%-45s | %-12d | %-12.2f\n", $k, $len, $dur);
}
