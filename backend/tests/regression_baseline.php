<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';

global $db;
$model = new SanPham($db);

// We need to access normalizeProductRecord which is private.
// We can use ReflectionMethod to call it directly on raw documents.
$reflector = new ReflectionClass(SanPham::class);
$method = $reflector->getMethod('normalizeProductRecord');
$method->setAccessible(true);

// Pick 100 representative products:
// Include product 111, first 50 by ma_san_pham desc, some random skips
$rawDocs = [];

// Specific target product
$p111 = $db->san_pham->findOne(['ma_san_pham' => 111]);
if ($p111) $rawDocs[] = (array)$p111;

$cursor = $db->san_pham->find([], ['limit' => 99, 'sort' => ['ma_san_pham' => -1]]);
foreach ($cursor as $doc) {
    $rawDocs[] = (array)$doc;
}

$normalized = [];
foreach ($rawDocs as $raw) {
    // bson objects may have issues with json_encode if not converted, but let's test normalizeProductRecord output
    $norm = $method->invoke($model, $raw);
    
    // Convert any BSON objects (like ObjectId or UTCDateTime) to string for stable JSON comparison
    $clean = [];
    foreach ($norm as $k => $v) {
        if ($v instanceof MongoDB\BSON\ObjectId) {
            $clean[$k] = (string)$v;
        } elseif ($v instanceof MongoDB\BSON\UTCDateTime) {
            $clean[$k] = (string)$v;
        } else {
            $clean[$k] = $v;
        }
    }
    $normalized[] = $clean;
}

file_put_contents(__DIR__ . '/baseline_normalized.json', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Successfully exported " . count($normalized) . " baseline normalized products.\n";
