<?php
// backend/tests/check_valkey_connections.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/services/CacheService.php';

$cache = CacheService::getInstance();
$ref = new ReflectionClass($cache);
$prop = $ref->getProperty('client');
$prop->setAccessible(true);
$cache->ping();
$client = $prop->getValue($cache);

$info = $client->info();
echo "Info section keys: " . implode(', ', array_keys($info)) . "\n";
if (isset($info['Stats'])) {
    echo "total_connections_received: " . ($info['Stats']['total_connections_received'] ?? 'none') . "\n";
    echo "total_commands_processed: " . ($info['Stats']['total_commands_processed'] ?? 'none') . "\n";
}
