<?php
/**
 * Ensure MongoDB indexes used by the livestream feature.
 *
 * Idempotent: MongoDB createIndex is a no-op when an identical index already
 * exists, so this script is safe to run repeatedly.
 *
 * It must be run MANUALLY (never from a request or a deploy hook) because it
 * touches the real database:
 *
 *   php scripts/ensure_live_indexes.php --dry-run   # print what would be created
 *   php scripts/ensure_live_indexes.php             # create the indexes
 *
 * NOTE: the unique index on phien_live_viewers {room_id, session_id} fails if
 * duplicate viewer rows already exist; clean those up first if it reports an error.
 */

// Repo layout (php scripts/...) or inside the skinsyntax-php container, where
// backend/ is mounted at /var/www/html (docker cp the script to /tmp and run it).
$appRoot = is_dir(__DIR__ . '/../backend/app') ? __DIR__ . '/../backend' : '/var/www/html';
if (is_file($appRoot . '/vendor/autoload.php')) require_once $appRoot . '/vendor/autoload.php';
require_once $appRoot . '/app/config/config.php';
require_once $appRoot . '/app/config/db.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$indexes = [
    ['phien_live_viewers', ['room_id' => 1, 'session_id' => 1], ['unique' => true, 'name' => 'room_session_unique']],
    ['phien_live_viewers', ['room_id' => 1, 'last_seen' => 1], ['name' => 'room_last_seen']],
    ['phien_live_chats', ['room_id' => 1, 'timestamp_ms' => 1], ['name' => 'room_timestamp']],
    ['phien_live_reactions', ['room_id' => 1, 'created_at' => 1], ['name' => 'room_created_at']],
];

if (!isset($db) || !($db instanceof MongoDB\Database)) {
    fwrite(STDERR, "MongoDB database handle is not available.\n");
    exit(1);
}

echo $dryRun ? "[DRY RUN] No index will be created.\n" : "Ensuring live indexes...\n";

$failed = 0;
foreach ($indexes as [$collection, $keys, $options]) {
    $label = $collection . ' ' . json_encode($keys) . (!empty($options['unique']) ? ' (unique)' : '');
    if ($dryRun) {
        echo "WOULD CREATE  $label\n";
        continue;
    }
    try {
        $name = $db->selectCollection($collection)->createIndex($keys, $options);
        echo "OK            $label -> $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAILED        $label -> " . $e->getMessage() . "\n";
    }
}

exit($failed > 0 ? 1 : 0);
