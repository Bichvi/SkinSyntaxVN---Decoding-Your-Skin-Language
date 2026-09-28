<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

echo "--- TESTING DANH_GIA_SAN_PHAM INDEX ---\n";
$idxDgsp = $db->danh_gia_san_pham->createIndex(['ma_san_pham' => 1]);
echo "Created: $idxDgsp\n";
$cmd = new MongoDB\Driver\Command([
    'explain' => [
        'find' => 'danh_gia_san_pham',
        'filter' => ['ma_san_pham' => 111],
    ],
    'verbosity' => 'executionStats'
]);
$stats = json_decode(json_encode(current($db->command($cmd)->toArray())), true)['executionStats'] ?? [];
echo "  scanType: " . ($stats['executionStages']['inputStage']['stage'] ?? $stats['executionStages']['stage'] ?? 'N/A') . "\n";
echo "  docsExamined: " . ($stats['totalDocsExamined'] ?? 'N/A') . "\n";
echo "  keysExamined: " . ($stats['totalKeysExamined'] ?? 'N/A') . "\n";

echo "--- TESTING DANH_GIA INDEX ---\n";
$idxDg = $db->danh_gia->createIndex(['ma_san_pham' => 1]);
echo "Created: $idxDg\n";
$cmd = new MongoDB\Driver\Command([
    'explain' => [
        'find' => 'danh_gia',
        'filter' => ['ma_san_pham' => 111],
    ],
    'verbosity' => 'executionStats'
]);
$stats = json_decode(json_encode(current($db->command($cmd)->toArray())), true)['executionStats'] ?? [];
echo "  scanType: " . ($stats['executionStages']['inputStage']['stage'] ?? $stats['executionStages']['stage'] ?? 'N/A') . "\n";
echo "  docsExamined: " . ($stats['totalDocsExamined'] ?? 'N/A') . "\n";
echo "  keysExamined: " . ($stats['totalKeysExamined'] ?? 'N/A') . "\n";

echo "--- TESTING HOI_DAP_SAN_PHAM INDEX ---\n";
$idxHd = $db->hoi_dap_san_pham->createIndex(['ma_san_pham' => 1]);
echo "Created: $idxHd\n";
$cmd = new MongoDB\Driver\Command([
    'explain' => [
        'find' => 'hoi_dap_san_pham',
        'filter' => ['ma_san_pham' => 111],
    ],
    'verbosity' => 'executionStats'
]);
$stats = json_decode(json_encode(current($db->command($cmd)->toArray())), true)['executionStats'] ?? [];
echo "  scanType: " . ($stats['executionStages']['inputStage']['stage'] ?? $stats['executionStages']['stage'] ?? 'N/A') . "\n";
echo "  docsExamined: " . ($stats['totalDocsExamined'] ?? 'N/A') . "\n";
echo "  keysExamined: " . ($stats['totalKeysExamined'] ?? 'N/A') . "\n";
