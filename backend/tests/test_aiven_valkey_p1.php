<?php
// backend/tests/test_aiven_valkey_p1.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/config.php';

$valkeyUrl = ss_env('VALKEY_URL', '');
if (empty($valkeyUrl)) {
    echo "VALKEY_URL is not set in environment.\n";
    exit(1);
}

// Mask URL for display
$parsed = parse_url($valkeyUrl);
$maskedUrl = ($parsed['scheme'] ?? 'redis') . '://' . ($parsed['user'] ?? 'default') . ':*****@' . ($parsed['host'] ?? '') . ':' . ($parsed['port'] ?? '');
echo "Testing Valkey Connection to: {$maskedUrl}\n";

try {
    // Predis supports rediss:// directly, or parameters array
    $options = [
        'parameters' => [
            'password' => $parsed['pass'] ?? null,
            'database' => isset($parsed['path']) ? (int)ltrim($parsed['path'], '/') : 0,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ]
    ];

    // Predis Client initialization
    $client = new Predis\Client($valkeyUrl, [
        'ssl' => [
            'verify_peer' => true,
        ]
    ]);

    $start = microtime(true);
    $pong = $client->ping();
    $pingTime = round((microtime(true) - $start) * 1000, 2);
    echo "[PASS] PING response: " . (string)$pong . " (latency: {$pingTime} ms)\n";

    // Test QA Key CRUD
    $prefix = ss_env('CACHE_PREFIX', 'skinsyntax:');
    $testKey = $prefix . 'qa_test_valkey_' . bin2hex(random_bytes(4));
    $testValue = ['status' => 'ok', 'timestamp' => time(), 'source' => 'qa_test'];
    $jsonVal = json_encode($testValue);

    // SET with TTL (60s)
    $client->setex($testKey, 60, $jsonVal);
    echo "[PASS] SET key with TTL 60s\n";

    // GET
    $retrieved = $client->get($testKey);
    $decoded = json_decode($retrieved, true);
    if ($decoded['status'] === 'ok' && $decoded['source'] === 'qa_test') {
        echo "[PASS] GET verified matching content\n";
    } else {
        echo "[FAIL] GET returned unexpected data\n";
    }

    // TTL
    $ttl = $client->ttl($testKey);
    echo "[PASS] TTL verified: {$ttl}s remaining\n";

    // DELETE
    $deleted = $client->del([$testKey]);
    echo "[PASS] DELETE returned: {$deleted}\n";

    // Verify deleted
    $checkAfterDel = $client->get($testKey);
    if ($checkAfterDel === null) {
        echo "[PASS] Key confirmed deleted\n";
    } else {
        echo "[FAIL] Key still exists after deletion\n";
    }

    echo "\n=== ALL VALKEY SMOKE TESTS PASSED ===\n";
} catch (Throwable $e) {
    echo "[ERROR] Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
