<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';

$url = VALKEY_URL;
$options = [
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
];

$client = new Predis\Client($url, $options);
$conn = $client->getConnection();

echo "Connection Class: " . get_class($conn) . PHP_EOL;
echo "Parameters persistent: " . var_export($conn->getParameters()->persistent, true) . PHP_EOL;
echo "Parameters timeout: " . var_export($conn->getParameters()->timeout, true) . PHP_EOL;
echo "Parameters read_write_timeout: " . var_export($conn->getParameters()->read_write_timeout, true) . PHP_EOL;
// Test with ssl inside parameters
$optionsWithSslInParams = [
    'parameters' => [
        'timeout' => 2.0,
        'read_write_timeout' => 2.0,
        'persistent' => true,
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ],
];
$client2 = new Predis\Client($url, $optionsWithSslInParams);
$conn2 = $client2->getConnection();
echo "Parameters2 ssl: " . var_export($conn2->getParameters()->ssl, true) . PHP_EOL;

$ref = new ReflectionClass($conn);
echo "StreamConnection file: " . $ref->getFileName() . PHP_EOL;

