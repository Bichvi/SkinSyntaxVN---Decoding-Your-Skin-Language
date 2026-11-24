<?php
// Test thuần PHP (không DB, không mạng) cho đo lường chụp bảng thành phần (LabelScanMetrics).
// Chạy: php backend/tests/unit/LabelScanMetricsTest.php
require_once __DIR__ . '/../../app/services/SearchRecommendClient.php';
require_once __DIR__ . '/../../app/services/LabelScanPresenter.php';
require_once __DIR__ . '/../../app/services/LabelScanMetrics.php';
require_once __DIR__ . '/../../app/controllers/LabelScanController.php';

if (!defined('BASE_URL')) { define('BASE_URL', ''); }

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$usageIn = [
    ['provider' => 'groq', 'model' => 'm1', 'ok' => false, 'prompt_tokens' => 900, 'completion_tokens' => 5],
    ['provider' => 'gemini', 'model' => str_repeat('x', 150), 'ok' => true, 'prompt_tokens' => 99999999, 'completion_tokens' => -4],
    ['provider' => 'groq', 'model' => 'm', 'ok' => true],                                                      // thiếu token -> 0
    ['provider' => 'openai', 'model' => 'm', 'ok' => true, 'prompt_tokens' => 1, 'completion_tokens' => 1],   // provider lạ -> bỏ
    ['provider' => 'groq', 'model' => 'm', 'ok' => 'yes', 'prompt_tokens' => 1, 'completion_tokens' => 1],    // ok sai kiểu -> bỏ (và vượt 4 mục)
    'junk',
];
$sample = [
    'status' => 'ok',
    'product' => ['name' => 'Toner BI MAT', 'brand' => 'Hang BI MAT', 'product_type' => 'toner', 'matched_product_id' => '12', 'match_score' => 0.9],
    'ingredients' => ['source' => 'photo', 'total' => 1, 'recognized' => 1, 'raw_text' => 'Water, SECRET-TEXT',
        'items' => [['key' => 'water', 'name' => 'Water', 'name_vi' => null, 'role' => null, 'rank' => 1, 'highlight' => false]]],
    'conflicts' => [
        ['cart_product_id' => '5', 'severity' => 'high', 'timing' => 'same_session', 'ingredients' => [], 'advice_vi' => '', 'rule_id' => 'r1', 'evidence' => ''],
        ['cart_product_id' => '7', 'severity' => 'low', 'timing' => 'ok_alternate_days', 'ingredients' => [], 'advice_vi' => '', 'rule_id' => 'r2', 'evidence' => ''],
    ],
    'schedule' => [], 'skin_fit' => ['verdict' => 'caution', 'profile' => ['skin_type' => 'dau', 'sensitive' => false, 'concerns' => []], 'good' => [], 'caution' => [], 'avoid' => []],
    'alternatives' => [
        ['product_id' => '20', 'reason_code' => 'gentler_fits_skin', 'reason_params' => []],
        ['product_id' => '21', 'reason_code' => 'no_cart_conflict', 'reason_params' => []],
    ],
    'warnings' => ['low_recognition'], 'summary_vi' => 's', 'sunscreen_note' => false, 'took_ms' => 321, 'usage' => $usageIn,
];

// ---------- validateLabelResponse: usage ----------
$v = SearchRecommendClient::validateLabelResponse($sample);
$u = $v['usage'];
$check('usage: lọc provider/ok sai, giữ hợp lệ', count($u) === 3 && $u[0]['provider'] === 'groq' && $u[1]['provider'] === 'gemini' && $u[2]['prompt_tokens'] === 0);
$check('usage: kẹp model 100, token 0..1_000_000', mb_strlen($u[1]['model']) === 100 && $u[1]['prompt_tokens'] === 1000000 && $u[1]['completion_tokens'] === 0 && $u[0]['ok'] === false && $u[0]['prompt_tokens'] === 900);
$many4 = $sample;
$many4['usage'] = array_fill(0, 9, ['provider' => 'groq', 'model' => 'm', 'ok' => true, 'prompt_tokens' => 1, 'completion_tokens' => 1]);
$check('usage: cắt ≤ 4 mục', count(SearchRecommendClient::validateLabelResponse($many4)['usage']) === 4);
$noUsage = $sample; unset($noUsage['usage']);
$check('usage: thiếu -> []', SearchRecommendClient::validateLabelResponse($noUsage)['usage'] === []);
$bad = $sample; $bad['usage'] = 'x';
$check('usage: sai kiểu -> [] (không làm hỏng kết quả)', SearchRecommendClient::validateLabelResponse($bad)['usage'] === []);

// ---------- presenter không lộ usage ----------
$rows = ['12' => ['ma_san_pham' => '12', 'ten_san_pham' => 'T', 'gia_ban' => 1, 'hinh_anh' => ''],
    '5' => ['ma_san_pham' => '5', 'ten_san_pham' => 'R', 'gia_ban' => 1, 'hinh_anh' => ''],
    '20' => ['ma_san_pham' => '20', 'ten_san_pham' => 'A', 'gia_ban' => 1, 'hinh_anh' => '']];
$lookup = fn(array $ids) => array_intersect_key($rows, array_flip($ids));
$presented = LabelScanPresenter::present($v, $lookup);
$check('presenter: không có khóa usage ra trình duyệt', !array_key_exists('usage', $presented) && strpos(json_encode($presented), 'prompt_tokens') === false);

// ---------- buildScanDoc ----------
$d = LabelScanMetrics::buildScanDoc($v, 'photo', 'sh', 'uh', 1700000000);
$check('scan doc: trường cơ bản', $d['type'] === 'scan' && $d['schema_version'] === 1 && $d['input'] === 'photo' && $d['outcome'] === 'ok' && $d['took_ms'] === 321 && $d['ts'] === 1700000000);
$check('scan doc: conflicts/verdict/low_recognition/has_cart', $d['conflicts_total'] === 2 && $d['conflicts_high'] === 1 && $d['skin_verdict'] === 'caution' && $d['low_recognition'] === true && $d['has_cart'] === true);
$check('scan doc: in_shop/matched/alternative_ids', $d['in_shop'] === true && $d['matched_product_id'] === '12' && $d['alternative_ids'] === ['20', '21']);
$check('scan doc: usage đã sanitize + hash', count($d['usage']) === 3 && $d['session_hash'] === 'sh' && $d['user_hash'] === 'uh');
$keys = array_keys($d);
sort($keys);
$expected = ['alternative_ids', 'conflicts_high', 'conflicts_total', 'has_cart', 'in_shop', 'input', 'low_recognition', 'matched_product_id', 'outcome', 'schema_version', 'session_hash', 'skin_verdict', 'took_ms', 'ts', 'type', 'usage', 'user_hash'];
$check('scan doc: đúng tập khóa, không name/brand/raw_text/email', $keys === $expected);
$json = json_encode($d);
$check('scan doc: không rò chữ/tên/hãng', strpos($json, 'BI MAT') === false && strpos($json, 'SECRET') === false && strpos($json, 'Toner') === false);
$empty = $v; $empty['warnings'] = ['cart_empty'];
$check('scan doc: cart_empty -> has_cart false', LabelScanMetrics::buildScanDoc($empty, 'text', null, null, 1)['has_cart'] === false);
$n = LabelScanMetrics::buildScanDoc(null, 'text', 'sh', null, 1);
$check('scan doc: null -> outcome unavailable, đầu vào text, rỗng', $n['outcome'] === 'unavailable' && $n['input'] === 'text' && $n['alternative_ids'] === [] && $n['usage'] === [] && $n['in_shop'] === false && $n['conflicts_total'] === 0);
$manyAlt = $v; $manyAlt['alternatives'] = [];
for ($i = 0; $i < 6; $i++) { $manyAlt['alternatives'][] = ['product_id' => "p$i"]; }
$check('scan doc: alternative_ids ≤ 3', count(LabelScanMetrics::buildScanDoc($manyAlt, 'photo', null, null, 1)['alternative_ids']) === 3);

// ---------- shownIds / rememberShown / attributionFor ----------
$check('shownIds: alternatives + matched, không trùng', LabelScanMetrics::shownIds($d) === ['20', '21', '12']);
$dd = $d; $dd['matched_product_id'] = '20';
$check('shownIds: matched trùng alternative không lặp', LabelScanMetrics::shownIds($dd) === ['20', '21']);
$store = [];
LabelScanMetrics::rememberShown($store, ['20', '21'], 1000);
$check('rememberShown: ghi pid => ts/kind', $store['label_scan_shown']['20'] === ['ts' => 1000, 'kind' => 'alternative']);
LabelScanMetrics::rememberShown($store, [['id' => '12', 'kind' => 'matched']], 2000);
$check('rememberShown: kind matched', $store['label_scan_shown']['12']['kind'] === 'matched');
LabelScanMetrics::rememberShown($store, ['30'], 1000 + 86400 + 5);
$check('rememberShown: bỏ mục quá TTL 24h', !isset($store['label_scan_shown']['20']) && !isset($store['label_scan_shown']['21']) && isset($store['label_scan_shown']['12']));
$cap = [];
for ($i = 0; $i < 40; $i++) { LabelScanMetrics::rememberShown($cap, ["q$i"], 5000 + $i); }
$check('rememberShown: tối đa 30 mục mới nhất', count($cap['label_scan_shown']) === 30 && isset($cap['label_scan_shown']['q39']) && !isset($cap['label_scan_shown']['q0']) && isset($cap['label_scan_shown']['q10']));
$check('attributionFor: alternative / matched / lạ / hết hạn',
    LabelScanMetrics::attributionFor($store, '30', 1000 + 86400 + 10) === 'alternative'
    && LabelScanMetrics::attributionFor($store, '12', 2100) === 'matched'
    && LabelScanMetrics::attributionFor($store, '999', 2100) === null
    && LabelScanMetrics::attributionFor($store, '12', 2000 + 86400 + 1) === null
    && LabelScanMetrics::attributionFor([], '12', 1) === null);

// ---------- buildAddToCartDoc ----------
$a = LabelScanMetrics::buildAddToCartDoc('20', 'alternative', 'sh', null, 5);
$b = LabelScanMetrics::buildAddToCartDoc('20', 'alternative', 'sh', null, 99);
$c = LabelScanMetrics::buildAddToCartDoc('21', 'alternative', 'sh', null, 5);
$e = LabelScanMetrics::buildAddToCartDoc('20', 'alternative', 'other', null, 5);
$check('atc doc: trường', $a['type'] === 'add_to_cart' && $a['product_id'] === '20' && $a['kind'] === 'alternative' && $a['schema_version'] === 1 && $a['session_hash'] === 'sh' && $a['user_hash'] === null);
$check('atc doc: event_id ổn định trong ngày, đổi theo sản phẩm/phiên', $a['event_id'] === $b['event_id'] && $a['event_id'] !== $c['event_id'] && $a['event_id'] !== $e['event_id'] && $a['event_id'] === hash('sha256', 'label_atc|sh|20|' . date('Y-m-d')));

// ---------- controller với hook ----------
$store2 = [];
$stateStore = [
    'get' => function ($key) use (&$store2) { return $store2[$key] ?? null; },
    'set' => function ($key, $val) use (&$store2) { $store2[$key] = $val; },
];
$client = new SearchRecommendClient('http://x/api/search/recommend', 't', 1200, 300, function () use ($sample) {
    return ['status' => 200, 'body' => json_encode($sample), 'error' => ''];
}, $stateStore);
$captured = [];
$mk = function (array $over = []) use ($client, $lookup, &$captured) {
    return new LabelScanController(null, $over + [
        'env' => fn($k, $d) => $k === 'LABEL_SCAN_ENABLED' ? '1' : $d,
        'csrf' => fn() => 'tok123',
        'rate_limit' => fn() => 0,
        'context' => fn() => ['cart_ids' => ['5'], 'email' => 'a@b.c', 'skin_profile' => null],
        'client' => fn() => $client,
        'lookup' => $lookup,
        'metrics' => function (array $doc) use (&$captured) { $captured[] = $doc; },
    ]);
};
$srv = ['REQUEST_METHOD' => 'POST'];
$text = str_repeat('Water, Glycerin, ', 3);
$ctl = $mk();
[$st, $body] = $ctl->process(['csrf' => 'tok123', 'ingredients_text' => $text], [], $srv);
$ctl->track(1700000000);
$ctl->track(1700000001); // gọi lần hai không ghi thêm
$check('controller: gọi Flask -> đúng một scan doc, input text', $st === 200 && count($captured) === 1 && $captured[0]['type'] === 'scan' && $captured[0]['input'] === 'text' && $captured[0]['outcome'] === 'ok');
$check('controller: body trình duyệt không có usage, doc có token', !isset($body['result']['usage']) && strpos(json_encode($captured[0]), 'prompt_tokens') !== false && !isset($captured[0]['name']));
$captured = [];
$ctl = $mk();
[$st, $body] = $ctl->process(['csrf' => 'WRONG', 'ingredients_text' => $text], [], $srv);
$ctl->track(1);
$check('controller: CSRF sai -> không ghi doc', $st === 403 && $captured === []);
$ctl = $mk(['rate_limit' => fn() => 30]);
$ctl->process(['csrf' => 'tok123', 'ingredients_text' => $text], [], $srv);
$ctl->track(1);
$check('controller: rate limit -> không ghi doc', $captured === []);
$ctl = $mk();
$ctl->process(['csrf' => 'tok123', 'ingredients_text' => 'ngắn'], [], $srv);
$ctl->track(1);
$check('controller: bad_text -> không ghi doc', $captured === []);
$ctl = $mk(['client' => fn() => new SearchRecommendClient('http://x/api/search/recommend', 't', 1200, 300, fn() => ['status' => 500, 'body' => '', 'error' => ''], $stateStore)]);
$ctl->process(['csrf' => 'tok123', 'ingredients_text' => $text], [], $srv);
$ctl->track(1);
$check('controller: Flask lỗi -> doc outcome unavailable', count($captured) === 1 && $captured[0]['outcome'] === 'unavailable' && $captured[0]['usage'] === []);
$captured = [];
$ctl = $mk(['metrics' => function () { throw new RuntimeException('boom'); }]);
$ctl->process(['csrf' => 'tok123', 'ingredients_text' => $text], [], $srv);
$check('controller: hook metrics ném lỗi -> nuốt', $ctl->track(1) === null);

// ---------- IO không ném khi DB hỏng ----------
LabelScanMetrics::recordScan(new stdClass(), $d);
LabelScanMetrics::attributeAddToCart(new stdClass(), '20');
$check('IO: DB hỏng không ném', true);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
