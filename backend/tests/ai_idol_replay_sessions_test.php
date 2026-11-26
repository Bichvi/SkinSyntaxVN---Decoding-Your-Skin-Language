<?php
// Run: php backend/tests/ai_idol_replay_sessions_test.php  (exit 0 = PASS; no DB access)
declare(strict_types=1);

require_once __DIR__ . '/../app/services/AiIdolReplaySessions.php';

$failures = 0;
function check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "PASS: {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL: {$label}\n";
}

$video = 'https://pub-test.r2.dev/live-replays/a.mp4';
$poster = 'https://pub-test.r2.dev/live-replays/a.jpg';
$campaign = [
    '_id' => '6ace67e735f427f1bf0c492c',
    'name' => 'Livestream 15/10',
    'replay' => ['video_url' => $video, 'poster_url' => $poster],
];
$product = ['ma_san_pham' => '93', 'gia_ban' => 139000];

$s = AiIdolReplaySessions::toSession($campaign, $product);
check(is_array($s), 'valid campaign maps to a session');
check(($s['id'] ?? null) === 'ai-6ace67e735f427f1bf0c492c', 'id is ai- prefixed');
check(($s['title'] ?? null) === 'Livestream 15/10', 'title is campaign name');
check(($s['status'] ?? null) === 'replay_ready', 'status replay_ready');
check(($s['streamer'] ?? null) === 'Syna · Video AI', 'streamer label');
check(($s['url_ban_ghi'] ?? null) === $video, 'url_ban_ghi is video url');
check(($s['thumbnail'] ?? null) === $poster, 'thumbnail is poster url');
check(($s['pinned_product'] ?? null) === $product, 'pinned_product is first product');
check(($s['gia_uu_dai_live'] ?? null) === 139000.0, 'gia_uu_dai_live is float price');
check(($s['viewers'] ?? null) === 0, 'viewers 0');
check(($s['danh_sach_deal'] ?? null) === [], 'danh_sach_deal empty');
check(($s['top_san_pham'] ?? null) === [], 'top_san_pham empty');
check(($s['server_livekit_url'] ?? null) === '', 'no livekit url');

$bad = $campaign;
$bad['replay']['video_url'] = 'javascript:alert(1)';
check(AiIdolReplaySessions::toSession($bad, $product) === null, 'rejects non-http video url');

$noId = $campaign;
unset($noId['_id']);
check(AiIdolReplaySessions::toSession($noId, $product) === null, 'missing _id yields null');

$noReplay = $campaign;
unset($noReplay['replay']);
check(AiIdolReplaySessions::toSession($noReplay, $product) === null, 'missing replay yields null');

$badPoster = $campaign;
$badPoster['replay']['poster_url'] = 'javascript:alert(1)';
$s2 = AiIdolReplaySessions::toSession($badPoster, $product, 'https://img/p.jpg');
check(($s2['thumbnail'] ?? null) === 'https://img/p.jpg', 'ignores non-http poster, uses fallback image');

$noPoster = $campaign;
unset($noPoster['replay']['poster_url']);
$s3 = AiIdolReplaySessions::toSession($noPoster, null);
check(array_key_exists('pinned_product', $s3) && $s3['pinned_product'] === null, 'no product yields null pinned');
check(($s3['thumbnail'] ?? 'x') === '', 'no poster, no fallback yields empty thumbnail');
check(($s3['gia_uu_dai_live'] ?? null) === 0.0, 'no product yields 0.0 price');

exit($failures === 0 ? 0 : 1);
