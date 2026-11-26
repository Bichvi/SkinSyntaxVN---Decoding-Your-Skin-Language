<?php
declare(strict_types=1);

// Additive indexes for the mobile session/cart collections. This utility is deliberately local-only.
if (strtolower(trim((string)(getenv('DB_MODE') ?: ''))) !== 'local') {
    fwrite(STDERR, "Refusing to run: set DB_MODE=local to target a local MongoDB.\n");
    exit(2);
}

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/db.php';

if (!defined('DB_MODE') || DB_MODE !== 'local' || !defined('MONGO_URI')) {
    fwrite(STDERR, "Refusing to run: database configuration is not explicitly local.\n");
    exit(2);
}

$uri = (string)MONGO_URI;
$host = strtolower((string)(parse_url($uri, PHP_URL_HOST) ?? ''));
if (!in_array($host, ['127.0.0.1', 'localhost', 'mongodb'], true)) {
    fwrite(STDERR, "Refusing to run: MongoDB host is not a recognized local endpoint.\n");
    exit(2);
}

try {
    $database = $pdo->raw();
    $database->selectCollection('mobile_sessions')->createIndex(
        ['token_hash' => 1],
        ['unique' => true, 'name' => 'mobile_sessions_token_hash_unique']
    );
    $database->selectCollection('mobile_sessions')->createIndex(
        ['expires_at' => 1],
        ['expireAfterSeconds' => 0, 'name' => 'mobile_sessions_expiry_ttl']
    );
    $database->selectCollection('customer_cart_operations')->createIndex(
        ['expires_at' => 1],
        ['expireAfterSeconds' => 0, 'name' => 'customer_cart_operations_expiry_ttl']
    );
    fwrite(STDOUT, "Mobile API indexes created or already present in the local database.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Mobile API local index setup failed: ' . get_class($error) . "\n");
    exit(1);
}
