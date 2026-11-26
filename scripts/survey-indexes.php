<?php
// Dry-run by default. Supply the target URI via environment, never arguments.
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['database:', 'apply']);
$uri = getenv('SURVEY_INDEX_URI');
$database = $options['database'] ?? '';
if (!$uri || !is_string($database) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $database)) {
    fwrite(STDERR, "Set SURVEY_INDEX_URI and select an explicit --database.\n");
    exit(1);
}
try {
    $collection = (new MongoDB\Client($uri))->selectDatabase($database)->feature_feedback;
    $v2 = ['schema_version' => 2];
    $identity = ['participant' => 1, 'interaction_id' => 1, 'template_id' => 1, 'template_version' => 1];
    $invalid = $collection->countDocuments($v2 + ['$nor' => [[
        'participant' => ['$type' => 'string', '$ne' => ''],
        'interaction_id' => ['$type' => 'string', '$ne' => ''],
        'template_id' => ['$type' => 'string', '$ne' => ''],
        'template_version' => ['$type' => 'int'],
    ]]]);
    $duplicate = iterator_to_array($collection->aggregate([
        ['$match' => $v2],
        ['$group' => ['_id' => array_combine(array_keys($identity), array_map(
            static fn(string $field): string => '$' . $field, array_keys($identity)
        )), 'count' => ['$sum' => 1]]],
        ['$match' => ['count' => ['$gt' => 1]]],
        ['$limit' => 1],
    ], ['allowDiskUse' => true]), false);
    if ($invalid || $duplicate) {
        fwrite(STDERR, "Preflight refused: invalid or duplicate response identity. No documents changed.\n");
        exit(2);
    }
    if (!array_key_exists('apply', $options)) {
        echo "Preflight passed. Dry run; no indexes changed.\n";
        exit(0);
    }
    $collection->createIndex($identity, ['name' => 'survey_response_identity_v2', 'unique' => true, 'partialFilterExpression' => $v2]);
    $collection->createIndex(['template_version' => 1, 'submitted_at' => -1], ['name' => 'survey_version_date']);
    $collection->createIndex(['question_version' => 1, 'submitted_at' => -1], ['name' => 'survey_legacy_version_date']);
    $collection->createIndex(['submitted_at' => -1], ['name' => 'survey_date']);
    echo "Survey indexes verified or created.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Survey index operation failed (' . get_class($error) . ").\n");
    exit(3);
}
