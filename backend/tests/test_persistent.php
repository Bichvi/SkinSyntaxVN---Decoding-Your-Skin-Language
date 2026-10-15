<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';

$url = ss_env('VALKEY_URL', '');
try {
    $c = new Predis\Client($url, [
        'parameters' => [
            'timeout' => 2.0,
            'read_write_timeout' => 2.0,
            'persistent' => true
        ],
        'ssl' => ['verify_peer' => true]
    ]);
    $res = $c->ping();
    echo "Persistent ping: " . (string)$res . "\n";
} catch (Throwable $e) {
    echo "Persistent failed: " . $e->getMessage() . "\n";
}
