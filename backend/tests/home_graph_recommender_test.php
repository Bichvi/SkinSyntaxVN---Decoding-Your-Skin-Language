<?php
// Run: php backend/tests/home_graph_recommender_test.php  (exit 0 = PASS). Pure: no DB/network/Redis.
declare(strict_types=1);

require_once __DIR__ . '/../app/services/HomeGraphRecommender.php';

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

function memStore(): array
{
    $data = [];
    return [
        'get' => static function (string $k) use (&$data) { return $data[$k] ?? null; },
        'set' => static function (string $k, array $v) use (&$data): void { $data[$k] = $v; },
    ];
}

function flaskBody(array $ids, string $key = 'concern'): string
{
    $items = [];
    foreach ($ids as $id) {
        $items[] = ['product_id' => (string)$id, 'reason' => ['code' => 'concern_target', 'concern' => 'Thâm mụn']];
    }
    return json_encode(['degraded' => false, 'took_ms' => 5, 'sections' => [
        ['key' => $key, 'concern' => ['key' => 'tham_mun', 'name' => 'Thâm mụn'], 'items' => $items],
    ]]);
}

$sentBody = null;
$mkClient = static function (string $body) use (&$sentBody): HomeRecommendClient {
    return new HomeRecommendClient('http://flask/x', '', 800, 300,
        static function (string $url, string $b, array $h, int $t, int $c) use ($body, &$sentBody): array {
            $sentBody = json_decode($b, true);
            return ['status' => 200, 'body' => $body, 'error' => ''];
        }, memStore());
};
$mongo = static function (array $have): callable {
    return static function (array $ids) use ($have): array {
        $out = [];
        foreach ($have as $id) {
            $out[] = ['ma_san_pham' => $id, 'ten_san_pham' => 'SP ' . $id];
        }
        return $out;
    };
};

$ctx = ['seen_ids' => ['12', 'x'], 'skin_profile' => null, 'cart_ids' => [], 'exclude_ids' => [], 'concern' => null, 'sections' => ['seen', 'concern']];

// null client
$r = (new HomeGraphRecommender(null, $mongo([])))->build($ctx);
check($r['seen'] === null && $r['skin'] === null && $r['concern'] === null, 'null client yields all null blocks');
check(count($r['concern_keys']) === 9 && $r['concern_keys']['tham_mun'] === 'Thâm mụn' && $r['concern_keys']['nam_tan_nhang'] === 'Nám / tàn nhang', 'concern_keys has 9 entries');

// payload shape
(new HomeGraphRecommender($mkClient(flaskBody([1, 2, 3, 4])), $mongo([1, 2, 3, 4])))->build($ctx);
check($sentBody['seen_ids'] === ['12'], 'drops non-digit seen ids');
check($sentBody['sections'] === ['seen', 'concern'] && $sentBody['limits'] === ['seen' => 4, 'skin' => 4, 'concern' => 8], 'sections and limits sent');
check($sentBody['skin_profile'] === null && !array_key_exists('concern', $sentBody), 'null skin_profile sent, concern omitted when null');

// hydrate, order preserved
$r = (new HomeGraphRecommender($mkClient(flaskBody([4, 1, 3, 2])), $mongo([1, 2, 3, 4])))->build($ctx);
$order = array_map(static fn($p) => (string)$p['ma_san_pham'], $r['concern']['products'] ?? []);
check($order === ['4', '1', '3', '2'], 'product order follows Flask');
check(($r['concern']['products'][0]['reason_text'] ?? '') === 'Dành cho thâm mụn' && ($r['concern']['products'][0]['reason_code'] ?? '') === 'concern_target', 'reason_text and reason_code attached');
check(($r['concern']['title_data']['concern']['key'] ?? '') === 'tham_mun', 'title_data carries concern');
check($r['seen'] === null, 'section absent from response yields null block');

// hydrate drops missing, threshold re-applied
$r = (new HomeGraphRecommender($mkClient(flaskBody([1, 2, 3, 4])), $mongo([1, 2, 3])))->build($ctx);
check($r['concern'] === null, 'hydrate drops missing ids and re-applies threshold');

// exception -> null blocks
$boom = static function (array $ids): array { throw new RuntimeException('x'); };
$r = (new HomeGraphRecommender($mkClient(flaskBody([1, 2, 3, 4])), $boom))->build($ctx);
check($r['concern'] === null && count($r['concern_keys']) === 9, 'exception yields null blocks and keeps concern_keys');

exit($failures > 0 ? 1 : 0);
