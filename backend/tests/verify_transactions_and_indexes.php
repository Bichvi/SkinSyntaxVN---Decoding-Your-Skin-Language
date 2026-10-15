<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/db.php';

echo "=== MONGODB DEPLOYMENT & TRANSACTION CAPABILITY AUDIT ===\n";

try {
    // Get server status / isMaster
    $admin = $pdo->selectDatabase('admin');
    $buildInfo = $admin->command(['buildInfo' => 1])->toArray()[0];
    echo "MongoDB Version: " . ($buildInfo['version'] ?? 'unknown') . "\n";
} catch (\Throwable $e) {
    echo "buildInfo error: " . $e->getMessage() . "\n";
}

echo "\n=== CANONICAL CAMPAIGNS AUDIT ===\n";
foreach ($pdo->ai_video_reward_campaigns->find() as $c) {
    echo "Campaign: " . $c['campaign_id'] . " | active=" . ($c['is_active'] ? 'true' : 'false') 
        . " | user_limit=" . ($c['user_limit'] ?? 'N/A') 
        . " | total_quota=" . ($c['total_quota'] ?? 'N/A') 
        . " | issued_count=" . ($c['issued_count'] ?? 'N/A') . "\n";
}


// Check existing indexes on customer_vouchers
echo "\n=== EXISTING INDEXES ON customer_vouchers ===\n";
try {
    $indexes = $pdo->customer_vouchers->listIndexes();
    foreach ($indexes as $idx) {
        $key = json_encode($idx->getKey());
        $isUnique = !empty($idx->isUnique()) ? 'UNIQUE' : 'NON-UNIQUE';
        echo " - " . $idx->getName() . ": {$key} [{$isUnique}]\n";
    }
} catch (\Throwable $e) {
    echo "listIndexes error: " . $e->getMessage() . "\n";
}

// Check existing indexes on voucher
echo "\n=== EXISTING INDEXES ON voucher ===\n";
try {
    $indexes = $pdo->voucher->listIndexes();
    foreach ($indexes as $idx) {
        $key = json_encode($idx->getKey());
        $isUnique = !empty($idx->isUnique()) ? 'UNIQUE' : 'NON-UNIQUE';
        echo " - " . $idx->getName() . ": {$key} [{$isUnique}]\n";
    }
} catch (\Throwable $e) {
    echo "listIndexes error: " . $e->getMessage() . "\n";
}

// Test multi-document transaction capability across multiple collections
echo "\n=== MULTI-COLLECTION TRANSACTION TEST ===\n";
try {
    global $mongoClient;
    if ($mongoClient instanceof \MongoDB\Client) {
        $session = $mongoClient->startSession();
        $session->startTransaction();

        $c1 = $pdo->v2c1_tx_test1;
        $c2 = $pdo->v2c1_tx_test2;

        $c1->insertOne(['doc' => 'test1'], ['session' => $session]);
        $c2->insertOne(['doc' => 'test2'], ['session' => $session]);

        // Commit test
        $session->commitTransaction();

        $count1 = $c1->countDocuments(['doc' => 'test1']);
        $count2 = $c2->countDocuments(['doc' => 'test2']);
        echo "Committed counts: c1={$count1}, c2={$count2} (Expect 1, 1)\n";

        // Abort test
        $session->startTransaction();
        $c1->insertOne(['doc' => 'rollback1'], ['session' => $session]);
        $c2->insertOne(['doc' => 'rollback2'], ['session' => $session]);
        $session->abortTransaction();

        $rb1 = $c1->countDocuments(['doc' => 'rollback1']);
        $rb2 = $c2->countDocuments(['doc' => 'rollback2']);
        echo "Aborted rollback counts: c1={$rb1}, c2={$rb2} (Expect 0, 0)\n";

        // Cleanup
        $c1->drop();
        $c2->drop();

        if ($count1 === 1 && $count2 === 1 && $rb1 === 0 && $rb2 === 0) {
            echo "MULTI-COLLECTION ATOMIC TRANSACTIONS VERIFIED 100% WORKING!\n";
        }
    }
} catch (\Throwable $e) {
    echo "Multi-collection transaction error: " . $e->getMessage() . "\n";
}


