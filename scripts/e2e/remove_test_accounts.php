<?php
// scripts/e2e/remove_test_accounts.php
// Removes ONLY documents flagged is_test_account=true AND having an @skinsyntax.test email.

$backendRoot = is_dir(__DIR__ . '/../../backend/app')
    ? realpath(__DIR__ . '/../../backend')
    : (is_dir('/var/www/html/app') ? '/var/www/html' : null);
if ($backendRoot === null) {
    fwrite(STDERR, "Cannot locate backend root\n");
    exit(1);
}
require_once $backendRoot . '/app/config/config.php';
require_once $backendRoot . '/app/config/db.php';

$filter = [
    'is_test_account' => true,
    'email' => new \MongoDB\BSON\Regex('@skinsyntax\.test$', 'i'),
];
foreach (['nguoidung', 'khach_hang', 'nhan_vien'] as $coll) {
    $r = $db->{$coll}->deleteMany($filter);
    echo $coll . ': deleted ' . $r->getDeletedCount() . "\n";
}
