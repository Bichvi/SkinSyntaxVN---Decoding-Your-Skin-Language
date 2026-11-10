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
check($sentBody['sections'] === ['seen', 'concern'] && $sentBody['limits'] === ['seen' => 12, 'skin' => 4, 'concern' => 8], 'sections and limits sent');
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

// single Mongo call for all sections
$calls = 0;
$countingMongo = static function (array $ids) use (&$calls): array {
    $calls++;
    return array_map(static fn($id) => ['ma_san_pham' => $id, 'ten_san_pham' => 'SP ' . $id], $ids);
};
$mkItems = static fn(array $ids): array => array_map(static fn($i) => ['product_id' => (string)$i, 'reason' => ['code' => 'concern_target', 'concern' => 'Mụn']], $ids);
$threeBody = json_encode(['degraded' => false, 'took_ms' => 5, 'sections' => [
    ['key' => 'seen', 'seed' => ['product_id' => '9', 'name' => 'X'], 'items' => $mkItems([1, 2, 3, 4])],
    ['key' => 'skin', 'skin_type' => 'da_dau', 'items' => $mkItems([5, 6, 7, 8])],
    ['key' => 'concern', 'concern' => ['key' => 'mun', 'name' => 'Mụn'], 'items' => $mkItems([10, 11, 12, 13, 14, 15, 16, 17])],
]]);
$ctx3 = $ctx;
$ctx3['sections'] = ['seen', 'skin', 'concern'];
$r = (new HomeGraphRecommender($mkClient($threeBody), $countingMongo))->build($ctx3);
check($calls === 1, 'findVisibleByIds called exactly once for 3 sections');
check($r['seen'] !== null && $r['skin'] !== null && $r['concern'] !== null && count($r['concern']['products']) === 8, 'all 3 sections hydrated from single call');

// failed flag (lỗi thật khác với "không đủ sản phẩm")
check(($r['failed'] ?? null) === false, 'failed=false on success');
check((new HomeGraphRecommender(null, $mongo([])))->build($ctx)['failed'] === true, 'failed=true when client null');
$r = (new HomeGraphRecommender($mkClient(flaskBody([1, 2, 3, 4])), $mongo([1, 2, 3])))->build($ctx);
check($r['concern'] === null && $r['failed'] === false, 'below threshold is not a failure');
$r = (new HomeGraphRecommender($mkClient(flaskBody([1, 2, 3, 4])), $boom))->build($ctx);
check($r['failed'] === true, 'exception sets failed=true');

// Khối "seen": SP gốc (seed) tải chung 1 lần, reason_detail, câu ngắn khi seed trùng tiêu đề
$seenCalls = [];
$seenMongo = static function (array $ids) use (&$seenCalls): array {
    $seenCalls[] = $ids;
    $db = [
        '9' => ['ma_san_pham' => 9, 'ten_san_pham' => 'Seed 9', 'gia_ban' => 300000, 'cong_dung_keys' => ['cap_am']],
        '8' => ['ma_san_pham' => 8, 'ten_san_pham' => 'Seed 8', 'gia_ban' => 300000],
    ];
    foreach ([1, 2, 3, 4, 5, 6] as $i) {
        $db[(string)$i] = ['ma_san_pham' => $i, 'ten_san_pham' => 'SP ' . $i, 'gia_ban' => 200000, 'cong_dung_keys' => ['cap_am'],
            'danh_muc_day_du' => 'A -> B -> Serum / Tinh Chất'];
    }
    $out = [];
    foreach ($ids as $id) {
        if (isset($db[(string)$id])) {
            $out[] = $db[(string)$id];
        }
    }
    return $out;
};
$sharedReason = static fn(string $seedId): array => ['code' => 'seen_shared_ingredient', 'seed_id' => $seedId, 'seed_name' => 'Seed ' . $seedId, 'ingredients' => ['Niacinamide']];
$seenBody = json_encode(['degraded' => false, 'took_ms' => 5, 'sections' => [
    ['key' => 'seen', 'seed' => ['id' => '9', 'name' => 'Seed 9'], 'items' => [
        ['product_id' => '1', 'reason' => $sharedReason('9')],
        ['product_id' => '2', 'reason' => ['code' => 'seen_same_category', 'seed_id' => '9', 'seed_name' => 'Seed 9']],
        ['product_id' => '3', 'reason' => $sharedReason('8')],
        ['product_id' => '4', 'reason' => $sharedReason('9')],
    ]],
    ['key' => 'concern', 'concern' => ['key' => 'mun', 'name' => 'Mụn'], 'items' => $mkItems([5, 6, 1, 2])],
]]);
$ctxSeen = $ctx;
$ctxSeen['sections'] = ['seen', 'concern'];
$r = (new HomeGraphRecommender($mkClient($seenBody), $seenMongo))->build($ctxSeen);
check(count($seenCalls) === 1, 'seen: single findVisibleByIds call including seeds');
$asked = array_map('strval', $seenCalls[0] ?? []);
check(in_array('9', $asked, true) && in_array('8', $asked, true), 'seen: seed ids loaded in the same call');
$seenProducts = $r['seen']['products'] ?? [];
check(array_map(static fn($p) => (string)$p['ma_san_pham'], $seenProducts) === ['1', '2', '3', '4'], 'seen: seeds are not in output products');
check(($seenProducts[0]['reason_text'] ?? '') === 'Cùng chứa Niacinamide', 'seen: seed == title seed gives short text');
check(($seenProducts[1]['reason_text'] ?? '') === 'Cùng loại Serum / Tinh Chất', 'seen: same category short text uses last category segment');
check(($seenProducts[2]['reason_text'] ?? '') === 'Cùng chứa Niacinamide với Seed 8', 'seen: other seed keeps "với ..."');
check(($seenProducts[0]['reason_detail']['shared'] ?? null) === ['Niacinamide'] && ($seenProducts[0]['reason_detail']['value']['kind'] ?? '') === 'cheaper', 'seen: reason_detail attached with seed comparison');
check(array_key_exists('reason_detail', $seenProducts[2] ?? []), 'seen: product from other seed also has reason_detail');
$concernProducts = $r['concern']['products'] ?? [];
check(count($concernProducts) === 4 && !array_key_exists('reason_detail', $concernProducts[0]), 'non-seen section has no reason_detail');
check(($concernProducts[0]['reason_text'] ?? '') === 'Dành cho mụn', 'non-seen section text unchanged');
check(array_key_exists('fit_detail', $concernProducts[0]) && is_array($concernProducts[0]['fit_detail']) && !array_key_exists('fit_detail', $seenProducts[0] ?? []), 'non-seen section has fit_detail, seen does not');

// seed không còn trong Mongo -> chỉ có shared
$noSeedMongo = static function (array $ids): array {
    $out = [];
    foreach ($ids as $id) {
        if ((int)$id >= 1 && (int)$id <= 6) {
            $out[] = ['ma_san_pham' => (int)$id, 'ten_san_pham' => 'SP ' . $id, 'gia_ban' => 100];
        }
    }
    return $out;
};
$r = (new HomeGraphRecommender($mkClient($seenBody), $noSeedMongo))->build($ctxSeen);
$d = $r['seen']['products'][0]['reason_detail'] ?? null;
check(is_array($d) && ($d['shared'] ?? null) === ['Niacinamide'] && array_key_exists('value', $d) && $d['value'] === null, 'seen: missing seed -> only shared in detail');

// Mongo trả mảng dạng đối tượng (BSONArray): vẫn đọc được công dụng / vấn đề da cho fit_detail
$objMongo = static function (array $ids): array {
    return array_map(static fn($id) => ['ma_san_pham' => (int)$id, 'ten_san_pham' => 'SP ' . $id,
        'cong_dung_keys' => new ArrayObject(['cap_am', 'phuc_hoi']), 'van_de_da_keys' => new ArrayObject(['tham_mun'])], $ids);
};
$r = (new HomeGraphRecommender($mkClient(flaskBody([1, 2, 3, 4])), $objMongo))->build($ctx);
$fd = $r['concern']['products'][0]['fit_detail'] ?? [];
check(($fd['benefits'] ?? null) === ['Cấp ẩm', 'Phục hồi'] && ($fd['concerns'] ?? null) === ['Thâm mụn'], 'object arrays from Mongo are converted before building fit_detail');

exit($failures > 0 ? 1 : 0);
