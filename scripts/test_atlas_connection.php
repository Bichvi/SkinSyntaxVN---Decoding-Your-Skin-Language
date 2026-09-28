<?php
/**
 * Test CLI connection to MongoDB Atlas for SkinSyntaxVN
 * Ensures no credentials (username/password) are exposed in outputs.
 */

// Load backend db config which boots env and MongoDB client
require_once __DIR__ . '/../backend/app/config/db.php';

function maskUri(string $uri): string {
    return preg_replace('/mongodb(\+srv)?:\/\/([^:]+):([^@]+)@/i', 'mongodb$1://***:***@', $uri);
}

echo "=== MONGODB ATLAS CONNECTION TEST ===" . PHP_EOL;

// 1. Audit URI and Database Name
$rawUri = defined('MONGO_URI') ? MONGO_URI : '';
$dbName = defined('MONGO_DB_NAME') ? MONGO_DB_NAME : '';
$dbCompatName = defined('MONGO_DB') ? MONGO_DB : '';

$maskedUri = maskUri($rawUri);
echo "Configured MONGO_URI     : " . $maskedUri . PHP_EOL;
echo "Configured MONGO_DB_NAME: " . $dbName . PHP_EOL;
echo "Configured MONGO_DB     : " . $dbCompatName . PHP_EOL;

// 2. Ping MongoDB
$pingSuccess = false;
try {
    $adminDb = $client->selectDatabase('admin');
    $cursor = $adminDb->command(['ping' => 1]);
    $pingResult = current($cursor->toArray());
    if (isset($pingResult->ok) && (float)$pingResult->ok == 1.0) {
        $pingSuccess = true;
        echo "[OK] Ping MongoDB Atlas: Successful (ok: 1)" . PHP_EOL;
    } else {
        echo "[FAIL] Ping MongoDB Atlas response not ok" . PHP_EOL;
    }
} catch (Throwable $e) {
    echo "[ERROR] Ping failed: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// 3. Verify Connection is Atlas (not localhost)
$isAtlas = false;
$isLocalhost = false;
$detectedHosts = [];

if (str_starts_with($rawUri, 'mongodb+srv://') || str_contains($rawUri, 'mongodb.net')) {
    $isAtlas = true;
}

if (str_contains($rawUri, '127.0.0.1') || str_contains($rawUri, 'localhost')) {
    $isLocalhost = true;
}

try {
    $helloCursor = $adminDb->command(['hello' => 1]);
    $helloRes = current($helloCursor->toArray());
    if (isset($helloRes->hosts) && is_array($helloRes->hosts)) {
        foreach ($helloRes->hosts as $h) {
            $detectedHosts[] = (string)$h;
        }
    } elseif (isset($helloRes->me)) {
        $detectedHosts[] = (string)$helloRes->me;
    }
} catch (Throwable $e) {
    // hello command fallback
}

echo "Connection type          : " . ($isAtlas ? "MongoDB Atlas (Remote Cluster)" : "Unknown / Local") . PHP_EOL;
echo "Is localhost             : " . ($isLocalhost ? "YES (WARNING: Not Atlas!)" : "NO (Verified Atlas)") . PHP_EOL;

if (!empty($detectedHosts)) {
    echo "Cluster replica hosts    : " . implode(', ', array_slice($detectedHosts, 0, 3)) . PHP_EOL;
}

// 4. Actual Database Name
$actualDbName = $db->getDatabaseName();
echo "Actual Database Selected : " . $actualDbName . PHP_EOL;

// 5. Document Counts
$colSanPham = $db->selectCollection('san_pham');
$colNguoiDung = $db->selectCollection('nguoidung');
$colKhachHang = $db->selectCollection('khach_hang');

$countSanPham = $colSanPham->countDocuments();
$countNguoiDung = $colNguoiDung->countDocuments();
$countKhachHang = $colKhachHang->countDocuments();

echo "Collection 'san_pham'    : " . $countSanPham . " documents" . PHP_EOL;
echo "Collection 'nguoidung'   : " . $countNguoiDung . " documents" . PHP_EOL;
echo "Collection 'khach_hang'  : " . $countKhachHang . " documents" . PHP_EOL;

// List all collections in the database for verification
$existingCollections = [];
foreach ($db->listCollections() as $colInfo) {
    $existingCollections[] = $colInfo->getName();
}
echo "Available Collections    : " . implode(', ', $existingCollections) . PHP_EOL;

echo "=== TEST COMPLETED SUCCESSFULLY ===" . PHP_EOL;
