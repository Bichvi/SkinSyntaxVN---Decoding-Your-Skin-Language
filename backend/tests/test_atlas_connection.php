<?php
/**
 * Test CLI connection to MongoDB Atlas for SkinSyntaxVN inside Docker/backend
 */

require_once __DIR__ . '/../app/config/db.php';

function maskUri(string $uri): string {
    return preg_replace('/mongodb(\+srv)?:\/\/([^:]+):([^@]+)@/i', 'mongodb$1://***:***@', $uri);
}

echo "=== MONGODB ATLAS CONNECTION TEST (DOCKER PHP) ===" . PHP_EOL;

$rawUri = defined('MONGO_URI') ? MONGO_URI : '';
$dbName = defined('MONGO_DB_NAME') ? MONGO_DB_NAME : '';
$dbCompatName = defined('MONGO_DB') ? MONGO_DB : '';

echo "Configured MONGO_URI     : " . maskUri($rawUri) . PHP_EOL;
echo "Configured MONGO_DB_NAME: " . $dbName . PHP_EOL;

try {
    $adminDb = $client->selectDatabase('admin');
    $cursor = $adminDb->command(['ping' => 1]);
    $pingResult = current($cursor->toArray());
    if (isset($pingResult->ok) && (float)$pingResult->ok == 1.0) {
        echo "[OK] Ping MongoDB Atlas: Successful (ok: 1)" . PHP_EOL;
    } else {
        echo "[FAIL] Ping MongoDB Atlas response not ok" . PHP_EOL;
    }
} catch (Throwable $e) {
    echo "[ERROR] Ping failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$colSanPham = $db->selectCollection('san_pham');
$colNguoiDung = $db->selectCollection('nguoidung');
$colKhachHang = $db->selectCollection('khach_hang');

echo "Collection 'san_pham'    : " . $colSanPham->countDocuments() . " documents" . PHP_EOL;
echo "Collection 'nguoidung'   : " . $colNguoiDung->countDocuments() . " documents" . PHP_EOL;
echo "Collection 'khach_hang'  : " . $colKhachHang->countDocuments() . " documents" . PHP_EOL;

echo "=== TEST COMPLETED SUCCESSFULLY IN DOCKER ===" . PHP_EOL;
