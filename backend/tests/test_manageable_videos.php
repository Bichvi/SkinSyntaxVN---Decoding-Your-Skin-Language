<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/models/ProductVideo.php';

$client = new MongoDB\Client(getenv('MONGO_URI') ?: 'mongodb://mongodb:27017');
$db = $client->selectDatabase(getenv('MONGO_DB') ?: 'skinsyntax');

// Build map of campaigns
$campaignsMap = [];
foreach ($db->ai_idol_campaigns->find([]) as $c) {
    $campaignsMap[(string)$c['_id']] = (string)($c['name'] ?? 'Chương trình AI Idol');
}

// Build map of segments
$segmentsMap = [];
foreach ($db->ai_idol_segments->find([]) as $s) {
    $vid = (string)($s['video_id'] ?? '');
    if ($vid !== '') {
        $segmentsMap[$vid] = [
            'campaign_id' => (string)($s['campaign_id'] ?? ''),
            'product_id' => (string)($s['product_id'] ?? ''),
            'status' => (string)($s['status'] ?? 'READY'),
            'script_text' => (string)($s['script_text'] ?? '')
        ];
    }
}

// Build map of publications
$pubsMap = [];
foreach ($db->ai_product_videos->find([]) as $p) {
    $vid = (string)($p['video_id'] ?? '');
    if ($vid !== '') {
        $pubsMap[$vid] = $p;
    }
}

// Query all videos
$videos = [];
foreach ($db->ai_idol_videos->find([], ['sort' => ['created_at' => -1]]) as $v) {
    $vid = (string)$v['_id'];
    $seg = $segmentsMap[$vid] ?? [];
    $cid = $seg['campaign_id'] ?? '';
    $pub = $pubsMap[$vid] ?? null;

    $videos[] = [
        'video_id' => $vid,
        'job_id' => (string)($v['job_id'] ?? ''),
        'campaign_id' => $cid,
        'campaign_name' => $campaignsMap[$cid] ?? 'Chương trình AI Idol',
        'default_product_id' => (string)($v['product_id'] ?? ($seg['product_id'] ?? '')),
        'duration' => (float)($v['duration'] ?? 0),
        'format' => (string)($v['format'] ?? '16:9'),
        'resolution' => (string)($v['resolution'] ?? '1280x720'),
        'render_status' => 'READY',
        'is_published' => $pub ? (bool)($pub['is_published'] ?? false) : false,
        'publication_id' => $pub ? (string)$pub['_id'] : '',
        'title' => $pub ? (string)($pub['title'] ?? '') : ('Syna 3D - Giới thiệu sản phẩm ' . ($v['product_id'] ?? '')),
        'associated_product_ids' => $pub ? (array)($pub['associated_product_ids'] ?? []) : [(string)($v['product_id'] ?? '')],
        'primary_product_id' => $pub ? (string)($pub['primary_product_id'] ?? '') : (string)($v['product_id'] ?? ''),
        'display_priority' => $pub ? (int)($pub['display_priority'] ?? 10) : 10,
        'can_publish' => true,
    ];
}

echo "Manageable videos count: " . count($videos) . "\n";
echo json_encode($videos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
