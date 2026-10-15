<?php
require_once __DIR__ . '/../app/config/db.php';

$client = new MongoDB\Client(getenv('MONGO_URI') ?: 'mongodb://mongodb:27017');
$db = $client->selectDatabase(getenv('MONGO_DB') ?: 'skinsyntax');

echo "ai_idol_videos count: " . $db->ai_idol_videos->countDocuments() . "\n";
foreach ($db->ai_idol_videos->find([], ['limit' => 5]) as $v) {
    echo json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

$nv = $db->nhan_vien->findOne(['email' => 'admin@skinsyntax.vn']);
foreach (['admin123', '123456', 'admin', 'password', 'Admin@123', '12345678', 'admin@123'] as $p) {
    if (password_verify($p, (string)$nv['mat_khau'])) {
        echo "MATCH: $p\n";
    }
}


