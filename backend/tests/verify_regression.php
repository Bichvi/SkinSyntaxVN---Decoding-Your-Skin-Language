<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/SanPham.php';

global $db;
$model = new SanPham($db);

$reflector = new ReflectionClass(SanPham::class);
$method = $reflector->getMethod('normalizeProductRecord');
$method->setAccessible(true);

$baselineFile = __DIR__ . '/baseline_normalized.json';
if (!file_exists($baselineFile)) {
    echo "ERROR: Baseline file not found: $baselineFile\n";
    exit(1);
}

$baseline = json_decode(file_get_contents($baselineFile), true, 512, JSON_THROW_ON_ERROR);

$p111 = $db->san_pham->findOne(['ma_san_pham' => 111]);
$rawDocs = [];
if ($p111) $rawDocs[] = (array)$p111;

$cursor = $db->san_pham->find([], ['limit' => 99, 'sort' => ['ma_san_pham' => -1]]);
foreach ($cursor as $doc) {
    $rawDocs[] = (array)$doc;
}

$mismatches = [];
$totalCompared = 0;

foreach ($rawDocs as $index => $raw) {
    if (!isset($baseline[$index])) {
        $mismatches[] = "Index $index not present in baseline";
        continue;
    }

    $base = $baseline[$index];
    $currentNorm = $method->invoke($model, $raw);

    // Convert BSON objects as in baseline export
    $currentClean = [];
    foreach ($currentNorm as $k => $v) {
        if ($v instanceof MongoDB\BSON\ObjectId) {
            $currentClean[$k] = (string)$v;
        } elseif ($v instanceof MongoDB\BSON\UTCDateTime) {
            $currentClean[$k] = (string)$v;
        } else {
            $currentClean[$k] = $v;
        }
    }

    // Pass through json roundtrip so types match JSON baseline exactly
    $currentJson = json_decode(json_encode($currentClean, JSON_UNESCAPED_UNICODE), true);

    $totalCompared++;

    // Compare all keys in base
    foreach ($base as $key => $baseVal) {
        $currVal = $currentJson[$key] ?? null;
        if ($baseVal !== $currVal) {
            $mismatches[] = [
                'index' => $index,
                'ma_san_pham' => $raw['ma_san_pham'] ?? 'unknown',
                'field' => $key,
                'expected' => $baseVal,
                'expected_type' => gettype($baseVal),
                'actual' => $currVal,
                'actual_type' => gettype($currVal)
            ];
        }
    }

    // Compare any new keys in currentJson that were not in base
    foreach ($currentJson as $key => $currVal) {
        if (!array_key_exists($key, $base)) {
            $mismatches[] = [
                'index' => $index,
                'ma_san_pham' => $raw['ma_san_pham'] ?? 'unknown',
                'field' => $key,
                'expected' => 'KEY_NOT_PRESENT',
                'actual' => $currVal
            ];
        }
    }
}

if (!empty($mismatches)) {
    echo "REGRESSION TEST FAILED! Found " . count($mismatches) . " mismatches:\n";
    print_r(array_slice($mismatches, 0, 10));
    exit(1);
}

echo "REGRESSION TEST PASSED! All $totalCompared products matched 100% across all fields!\n";

// Detailed check of product 111
$p111Norm = $method->invoke($model, (array)$p111);
echo "\n--- Sample Verified Product 111 ---\n";
echo "ma_san_pham: " . ($p111Norm['ma_san_pham'] ?? '') . "\n";
echo "ten_san_pham: " . ($p111Norm['ten_san_pham'] ?? '') . "\n";
echo "thuong_hieu: " . ($p111Norm['thuong_hieu'] ?? '') . "\n";
echo "loai_san_pham (danh_muc): " . ($p111Norm['loai_san_pham'] ?? '') . "\n";
echo "xuat_xu_thuong_hieu: " . ($p111Norm['xuat_xu_thuong_hieu'] ?? '') . "\n";
echo "gia_ban: " . ($p111Norm['gia_ban'] ?? '') . "\n";
echo "link_hinh_anh: " . ($p111Norm['link_hinh_anh'] ?? '') . "\n";
echo "is_available: " . ($p111Norm['is_available'] ? 'true' : 'false') . "\n";
