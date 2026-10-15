<?php
// backend/tests/test_fpm_connection_persistence.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';

$valkeyUrl = getenv('VALKEY_URL');
if (!$valkeyUrl) {
    die("No VALKEY_URL\n");
}

echo "Testing multiple Predis client instantiations within same worker:\n";

for ($i = 1; $i <= 3; $i++) {
    $t0 = microtime(true);
    $client = new Predis\Client($valkeyUrl, [
        'parameters' => [
            'timeout' => 2.0,
            'read_write_timeout' => 2.0,
            'persistent' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);
    $client->connect();
    $connectMs = round((microtime(true) - $t0) * 1000, 2);

    $t1 = microtime(true);
    $pong = $client->ping();
    $pingMs = round((microtime(true) - $t1) * 1000, 2);

    echo "Attempt {$i}: connect() took {$connectMs} ms, PING took {$pingMs} ms (PONG: {$pong})\n";
}
