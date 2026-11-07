<?php
// Run: php backend/tests/product_video_client_test.php  (exit 0 = PASS)
declare(strict_types=1);

require_once __DIR__ . '/../app/services/ProductVideoClient.php';

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

$base = 'https://pub-test.r2.dev';
$empty = ['live' => null, 'videos' => []];
$client = static fn(?string $body, string $publicBase = 'https://pub-test.r2.dev'): ProductVideoClient =>
    new ProductVideoClient('http://ai-idol-service:5003', $publicBase, static fn(string $url): ?string => $body);

check($client(null)->forProduct('1024') === $empty, 'transport failure yields empty result');
check($client('{bad')->forProduct('1024') === $empty, 'malformed JSON yields empty result');
check($client('{"ok":false}')->forProduct('1024') === $empty, 'ok:false yields empty result');

$payload = json_encode(['ok' => true, 'data' => [
    'live' => ['campaign_id' => 'c1', 'name' => 'Live tối nay'],
    'videos' => [
        ['video_id' => 'v1', 'video_url' => "{$base}/product-videos/v1.mp4", 'poster_url' => "{$base}/product-videos/v1.jpg",
         'duration' => 30.4, 'format' => '9:16', 'date' => '2026-10-12T12:00:00+00:00', 'date_kind' => 'aired',
         'campaign_name' => 'Routine', 'product_ids' => ['1024', '1031']],
        ['video_id' => 'v2', 'video_url' => 'https://evil.example/v2.mp4', 'poster_url' => "{$base}/v2.jpg",
         'duration' => 20, 'format' => '16:9', 'date' => '2026-10-10T00:00:00+00:00', 'date_kind' => 'created',
         'campaign_name' => 'X', 'product_ids' => ['1024']],
        ['video_id' => 'v3', 'video_url' => "{$base}.evil.example/v3.mp4", 'poster_url' => "{$base}/v3.jpg",
         'duration' => 20, 'format' => '16:9', 'date' => '', 'date_kind' => 'created', 'campaign_name' => '', 'product_ids' => ['1024']],
    ],
]]);
$result = $client($payload)->forProduct('1024');
check(count($result['videos']) === 1 && $result['videos'][0]['video_id'] === 'v1', 'only videos hosted under the public base survive');
check($result['videos'][0]['format'] === '9:16' && $result['videos'][0]['product_ids'] === ['1024', '1031'], 'video fields are kept');
check($result['live'] === ['campaign_id' => 'c1', 'name' => 'Live tối nay'], 'live campaign is kept');

check($client($payload, '')->forProduct('1024')['videos'] === [], 'missing public base rejects every video');

$seen = '';
$spy = new ProductVideoClient('http://ai-idol-service:5003/', $base, static function (string $url) use (&$seen): ?string {
    $seen = $url;
    return null;
});
$spy->forProduct('10 24/x');
check($seen === 'http://ai-idol-service:5003/api/ai-idol/products/10%2024%2Fx/videos?limit=30', 'product id is URL-encoded in the request path');

exit($failures === 0 ? 0 : 1);
