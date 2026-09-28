<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

$db->danh_gia_san_pham->createIndex(['id' => 1]);
$db->danh_gia->createIndex(['id' => 1]);
$db->hoi_dap_san_pham->createIndex(['id' => 1]);

$cmd = new MongoDB\Driver\Command([
    'explain' => [
        'find' => 'danh_gia_san_pham',
        'filter' => [
            '$or' => [['ma_san_pham' => ['$in' => ['111', 111]]], ['id' => ['$in' => ['111', 111]]]]
        ],
    ],
    'verbosity' => 'executionStats'
]);
$stats = json_decode(json_encode(current($db->command($cmd)->toArray())), true)['executionStats'] ?? [];
echo "danh_gia_san_pham stage: " . ($stats['executionStages']['stage'] ?? 'N/A') . "\n";
echo "danh_gia_san_pham docsExamined: " . ($stats['totalDocsExamined'] ?? 'N/A') . "\n";
