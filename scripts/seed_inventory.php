<?php
// Manual inventory seed only. Run from project root:
// php scripts/seed_inventory.php

require_once __DIR__ . '/../backend/app/config/config.php';
require_once __DIR__ . '/../backend/app/config/db.php';

$defaultStock = 300;
$updated = 0;
$kept = 0;
$errors = 0;

try {
    $cursor = $pdo->san_pham->find([], ['projection' => ['ma_san_pham' => 1, 'id' => 1, 'so_luong_ton_kho' => 1, 'trang_thai_kho' => 1]]);
    foreach ($cursor as $doc) {
        $product = (array)$doc;
        $hasStock = array_key_exists('so_luong_ton_kho', $product)
            && $product['so_luong_ton_kho'] !== null
            && $product['so_luong_ton_kho'] !== '';
        $stock = $hasStock ? max(0, (int)$product['so_luong_ton_kho']) : $defaultStock;
        $status = $stock > 0 ? 'con_hang' : 'het_hang';

        $filter = ['_id' => $product['_id']];
        $payload = [
            'trang_thai_kho' => $status,
            'da_khoi_tao_kho' => true,
            'updated_at' => new MongoDB\BSON\UTCDateTime(),
        ];
        if (!$hasStock) {
            $payload['so_luong_ton_kho'] = $stock;
        }

        $pdo->san_pham->updateOne($filter, ['$set' => $payload]);
        $hasStock ? $kept++ : $updated++;
    }

    echo json_encode([
        'ok' => true,
        'default_stock_for_missing_products' => $defaultStock,
        'updated_without_stock' => $updated,
        'kept_existing_stock' => $kept,
        'errors' => $errors,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed inventory failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
