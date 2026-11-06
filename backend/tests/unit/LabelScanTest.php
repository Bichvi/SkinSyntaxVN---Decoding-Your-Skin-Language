<?php
// Test thuần PHP (không DB, không mạng) cho M5 chụp bảng thành phần: client label/analyze, presenter, controller, route.
// Chạy: php backend/tests/unit/LabelScanTest.php
require_once __DIR__ . '/../../app/services/SearchRecommendClient.php';
require_once __DIR__ . '/../../app/services/LabelScanPresenter.php';
require_once __DIR__ . '/../../app/controllers/LabelScanController.php';

if (!defined('BASE_URL')) { define('BASE_URL', ''); }

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$sample = [
    'status' => 'ok', 'evil' => 'x',
    'product' => ['name' => 'Toner A', 'brand' => 'B', 'product_type' => 'toner', 'matched_product_id' => '12', 'match_score' => 0.9, 'extra' => 1],
    'ingredients' => ['source' => 'photo', 'total' => 2, 'recognized' => 2, 'raw_text' => str_repeat('a', 7000),
        'items' => [['key' => 'water', 'name' => 'Water', 'name_vi' => null, 'role' => null, 'rank' => 1, 'highlight' => false, 'zzz' => 1]]],
    'conflicts' => [
        ['cart_product_id' => '5', 'cart_product_name' => 'Retinol', 'severity' => 'high', 'timing' => 'same_session', 'ingredients' => ['a', 'b'], 'advice_vi' => 'x', 'rule_id' => 'r1', 'evidence' => 'e'],
        ['cart_product_id' => '6', 'severity' => 'critical', 'timing' => 'same_session'],
        ['cart_product_id' => '7', 'severity' => 'low', 'timing' => 'ok_alternate_days', 'ingredients' => [], 'advice_vi' => '', 'rule_id' => 'r2', 'evidence' => ''],
    ],
    'schedule' => [], 'skin_fit' => ['verdict' => 'caution', 'profile' => ['skin_type' => 'dau', 'sensitive' => true, 'concerns' => ['mun']],
        'good' => [], 'caution' => [['rule_id' => 'c1', 'reason_vi' => 'r', 'ingredients' => ['AHA']]], 'avoid' => []],
    'alternatives' => [
        ['product_id' => '20', 'reason_code' => 'gentler_fits_skin', 'reason_params' => ['x']],
        ['product_id' => '21', 'reason_code' => 'no_cart_conflict', 'reason_params' => []],
        ['product_id' => '22', 'reason_code' => 'no_cart_conflict', 'reason_params' => []],
        ['product_id' => '23', 'reason_code' => 'no_cart_conflict', 'reason_params' => []],
    ],
    'warnings' => ['cart_empty', 'internal_error', 'bogus', 'cart_empty'], 'summary_vi' => str_repeat('s', 900),
    'sunscreen_note' => true, 'took_ms' => 42,
];

// ---------- validateLabelResponse ----------
$v = SearchRecommendClient::validateLabelResponse($sample);
$check('validate: bỏ khóa lạ ở gốc / product / items', is_array($v) && !isset($v['evil']) && !isset($v['product']['extra']) && !isset($v['ingredients']['items'][0]['zzz']));
$check('validate: kẹp raw_text 6000, summary 600', mb_strlen($v['ingredients']['raw_text']) === 6000 && mb_strlen($v['summary_vi']) === 600);
$check('validate: bỏ xung đột severity lạ, giữ cart_product_name', count($v['conflicts']) === 2 && $v['conflicts'][0]['cart_product_name'] === 'Retinol' && !isset($v['conflicts'][1]['cart_product_name']));
$check('validate: alternatives ≤ 3, warnings lọc + bỏ trùng, giữ internal_error', count($v['alternatives']) === 3 && $v['warnings'] === ['cart_empty', 'internal_error']);
$check('validate: sunscreen_note + took_ms + skin_fit', $v['sunscreen_note'] === true && $v['took_ms'] === 42 && $v['skin_fit']['profile']['skin_type'] === 'dau' && $v['skin_fit']['caution'][0]['rule_id'] === 'c1');
$bad = $sample; $bad['status'] = 'weird';
$check('validate: status sai -> null', SearchRecommendClient::validateLabelResponse($bad) === null);
$bad = $sample; $bad['conflicts'] = 'x';
$check('validate: sai kiểu conflicts -> null', SearchRecommendClient::validateLabelResponse($bad) === null);
$bad = $sample; $bad['ingredients'] = 'x';
$check('validate: sai kiểu ingredients -> null', SearchRecommendClient::validateLabelResponse($bad) === null);
$many = $sample;
$many['ingredients']['items'] = array_fill(0, 200, ['key' => 'k', 'name' => 'n', 'name_vi' => null, 'role' => null, 'rank' => null, 'highlight' => false]);
$many['conflicts'] = array_fill(0, 40, $sample['conflicts'][0]);
$many['warnings'] = array_fill(0, 30, 'cart_empty');
$vm = SearchRecommendClient::validateLabelResponse($many);
$check('validate: items ≤ 120, conflicts ≤ 20', count($vm['ingredients']['items']) === 120 && count($vm['conflicts']) === 20);

// ---------- labelAnalyze với transport giả ----------
$store = [];
$stateStore = [
    'get' => function ($key) use (&$store) { return $store[$key] ?? null; },
    'set' => function ($key, $val) use (&$store) { $store[$key] = $val; },
];
$seen = [];
$status = 200;
$body = json_encode($sample);
$transport = function ($url, $b, $headers, $timeoutMs, $connectMs, $method = 'POST') use (&$seen, &$status, &$body) {
    $seen = compact('url', 'b', 'headers', 'timeoutMs', 'method');
    return ['status' => $status, 'body' => $body, 'error' => ''];
};
$c = new SearchRecommendClient('http://ai-service:5001/api/search/recommend', 'tok', 1200, 300, $transport, $stateStore);
$out = $c->labelAnalyze(['ingredients_text' => 'Water, Glycerin'], 'req-12345678');
$check('client: 200 -> mảng đã validate', is_array($out) && $out['status'] === 'ok' && !isset($out['evil']));
$check('client: URL /api/label/analyze, POST, timeout 25000', $seen['url'] === 'http://ai-service:5001/api/label/analyze' && $seen['method'] === 'POST' && $seen['timeoutMs'] === 25000);
$check('client: token + X-Request-Id + body JSON', in_array('X-Internal-Token: tok', $seen['headers'], true) && in_array('X-Request-Id: req-12345678', $seen['headers'], true) && json_decode($seen['b'], true) === ['ingredients_text' => 'Water, Glycerin']);
foreach ([413 => 'too_large', 429 => 'rate_limited', 404 => 'disabled'] as $st => $code) {
    $status = $st; $body = '{}';
    $r = $c->labelAnalyze(['ingredients_text' => 'x']);
    $check("client: HTTP $st -> __error $code", $r === ['__error' => $code]);
}
$status = 500; $body = '{}';
$check('client: 500 -> null', $c->labelAnalyze(['ingredients_text' => 'x']) === null);
$status = 0;
$check('client: timeout -> null', $c->labelAnalyze(['ingredients_text' => 'x']) === null);
$status = 200; $body = '{"status":"nope"}';
$check('client: 200 sai cấu trúc -> null', $c->labelAnalyze(['ingredients_text' => 'x']) === null);
$store = [];
$calls = 0;
$down = function () use (&$calls) { $calls++; return ['status' => 0, 'body' => '', 'error' => 'refused']; };
$cd = new SearchRecommendClient('http://x/api/search/recommend', '', 1200, 300, $down, $stateStore);
$res = [];
for ($i = 0; $i < 4; $i++) { $res[] = $cd->labelAnalyze(['ingredients_text' => 'x']); }
$check('breaker label: 3 lỗi mở breaker riêng, lần 4 không gọi', $res === [null, null, null, null] && $calls === 3 && $cd->isOpen('label:breaker:v1') && !$cd->isOpen() && !$cd->isOpen('search:cart:breaker:v1'));

// ---------- presenter ----------
$rows = [
    '12' => ['ma_san_pham' => '12', 'ten_san_pham' => 'Toner <A>', 'gia_ban' => 100000, 'hinh_anh' => 'http://img/a.jpg|http://img/b.jpg'],
    '5' => ['ma_san_pham' => '5', 'ten_san_pham' => 'Retinol', 'gia_ban' => '250000', 'hinh_anh' => ''],
    '20' => ['ma_san_pham' => '20', 'ten_san_pham' => 'Alt 20', 'gia_ban' => 90000, 'hinh_anh' => 'i20.jpg'],
];
$asked = null;
$lookup = function (array $ids) use ($rows, &$asked) { $asked = $ids; return array_intersect_key($rows, array_flip($ids)); };
$pr = LabelScanPresenter::present($v, $lookup);
$check('presenter: gắn cart_product, bỏ xung đột thiếu sản phẩm', count($pr['conflicts']) === 1 && $pr['conflicts'][0]['cart_product']['id'] === '5' && $pr['conflicts'][0]['cart_product']['name'] === 'Retinol' && $pr['conflicts'][0]['cart_product']['price'] == 250000);
$check('presenter: alternatives hydrate, bỏ thiếu', count($pr['alternatives']) === 1 && $pr['alternatives'][0]['id'] === '20' && $pr['alternatives'][0]['reason_code'] === 'gentler_fits_skin' && $pr['alternatives'][0]['reason_params'] === ['x'] && strpos($pr['alternatives'][0]['url'], 'r=chitiet&id=20') !== false);
$check('presenter: product.url khi khớp, chuỗi giữ nguyên (không escape)', strpos($pr['product']['url'] ?? '', 'r=chitiet&id=12') !== false);
$check('presenter: lookup gọi một lần với id duy nhất', is_array($asked) && count($asked) === count(array_unique($asked)) && in_array('12', $asked, true));
$pr2 = LabelScanPresenter::present($v, fn(array $ids) => []);
$check('presenter: không tìm thấy -> không url, matched null', !isset($pr2['product']['url']) && $pr2['product']['matched_product_id'] === null && $pr2['conflicts'] === [] && $pr2['alternatives'] === []);

// ---------- controller helpers ----------
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
[$mime, $err] = LabelScanController::checkImage($png, 1024 * 1024);
$check('image: PNG thật được nhận', $mime === 'image/png' && $err === null);
[$mime, $err] = LabelScanController::checkImage('this is plain text pretending to be a jpg', 1024 * 1024);
$check('image: text giả .jpg -> bad_type', $mime === null && $err === 'bad_type');
[$mime, $err] = LabelScanController::checkImage($png, 10);
$check('image: vượt giới hạn -> too_large', $err === 'too_large');
$check('image: giới hạn hiệu dụng = min(5MB upload, 4MB Flask)', LabelScanController::maxImageBytes('5', '4') === 4 * 1048576 && LabelScanController::maxImageBytes('3', '4') === 3 * 1048576);
$check('text: <20 và >6000 bị từ chối, trim', LabelScanController::checkText('short')[1] === 'bad_text' && LabelScanController::checkText(str_repeat('a', 6001))[1] === 'bad_text' && LabelScanController::checkText('  ' . str_repeat('a', 20) . '  ') === [str_repeat('a', 20), null]);
$check('override: chỉ giữ giá trị hợp lệ', LabelScanController::parseOverride('{"skin_type":"dau","sensitive":true,"x":1}') === ['skin_type' => 'dau', 'sensitive' => true] && LabelScanController::parseOverride('{"skin_type":"xx"}') === null && LabelScanController::parseOverride('garbage') === null);
$check('csrf: so khớp / lệch / rỗng', LabelScanController::csrfOk('abc', 'abc') && !LabelScanController::csrfOk('abd', 'abc') && !LabelScanController::csrfOk('', ''));

// ---------- controller process() với hook giả ----------
$lastPayload = null;
$mkClient = function (int $httpStatus, string $respBody) use (&$lastPayload, $stateStore) {
    return new SearchRecommendClient('http://x/api/search/recommend', 't', 1200, 300, function ($url, $b) use (&$lastPayload, $httpStatus, $respBody) {
        $lastPayload = json_decode($b, true);
        return ['status' => $httpStatus, 'body' => $respBody, 'error' => ''];
    }, $stateStore);
};
$store = [];
$fakeClient = $mkClient(200, json_encode($sample));
$mk = function (array $over = []) use ($fakeClient, $lookup) {
    return new LabelScanController(null, $over + [
        'env' => fn($k, $d) => $k === 'LABEL_SCAN_ENABLED' ? '1' : $d,
        'csrf' => fn() => 'tok123',
        'rate_limit' => fn() => 0,
        'context' => fn() => ['cart_ids' => ['5', '6'], 'email' => 'a@b.c', 'skin_profile' => ['skin_type' => 'da_dau', 'concerns' => []]],
        'client' => fn() => $fakeClient,
        'lookup' => $lookup,
    ]);
};
$srv = ['REQUEST_METHOD' => 'POST'];
$text = str_repeat('Water, Glycerin, ', 3);
[$st, $b] = $mk(['env' => fn($k, $d) => $d])->process(['csrf' => 'tok123', 'ingredients_text' => $text], [], $srv);
$check('controller: cờ tắt -> 404 disabled', $st === 404 && $b['code'] === 'disabled' && $b['ok'] === false);
[$st, $b] = $mk()->process(['csrf' => 'wrong', 'ingredients_text' => $text], [], $srv);
$check('controller: CSRF lệch -> 403 csrf', $st === 403 && $b['code'] === 'csrf');
[$st, $b] = $mk()->process(['csrf' => 'tok123', 'ingredients_text' => $text], [], ['REQUEST_METHOD' => 'GET']);
$check('controller: GET -> 405', $st === 405);
[$st, $b, $h] = $mk(['rate_limit' => fn() => 42])->process(['csrf' => 'tok123', 'ingredients_text' => $text], [], $srv);
$check('controller: rate limit -> 429 + Retry-After', $st === 429 && $b['code'] === 'rate_limited' && $h === ['Retry-After: 42']);
[$st, $b] = $mk()->process(['csrf' => 'tok123', 'ingredients_text' => 'ngắn'], [], $srv);
$check('controller: chữ ngắn -> 400 bad_text', $st === 400 && $b['code'] === 'bad_text');
[$st, $b] = $mk()->process(['csrf' => 'tok123'], [], $srv);
$check('controller: không ảnh không chữ -> 400', $st === 400);
[$st, $b] = $mk()->process(['csrf' => 'tok123', 'ingredients_text' => $text, 'product_type' => 'serum', 'product_name' => 'N', 'conversation_id' => 'conv-1',
    'skin_type_override' => '{"skin_type":"kho","sensitive":false}'], [], $srv);
$p = $lastPayload;
$check('controller: thành công -> ok + result đã hydrate', $st === 200 && $b['ok'] === true && $b['result']['conflicts'][0]['cart_product']['id'] === '5');
$check('controller: payload gửi Flask đúng khóa, giỏ/hồ sơ/email từ server', $p['ingredients_text'] === trim($text) && $p['product_ids'] === ['5', '6'] && $p['skin_profile']['skin_type'] === 'da_dau'
    && $p['email'] === 'a@b.c' && $p['conversation_id'] === 'conv-1' && $p['product_type'] === 'serum' && $p['product_name'] === 'N' && $p['skin_type_override'] === ['skin_type' => 'kho', 'sensitive' => false]);
[$st, $b] = $mk(['context' => fn() => ['cart_ids' => [], 'email' => '', 'skin_profile' => null]])->process(['csrf' => 'tok123', 'ingredients_text' => $text, 'conversation_id' => 'conv-1', 'product_type' => 'bogus'], [], $srv);
$p = $lastPayload;
$check('controller: khách vãng lai không gửi email/conversation_id/skin_profile; product_type sai bị bỏ', !isset($p['email']) && !isset($p['conversation_id']) && !isset($p['skin_profile']) && !isset($p['product_type']) && $p['product_ids'] === []);
$tmp = tempnam(sys_get_temp_dir(), 'lbl');
file_put_contents($tmp, $png);
[$st, $b] = $mk()->process(['csrf' => 'tok123'], ['image' => ['error' => UPLOAD_ERR_OK, 'size' => strlen($png), 'tmp_name' => $tmp]], $srv);
$p = $lastPayload;
$check('controller: ảnh -> image_b64 + image_mime, không có ingredients_text', $st === 200 && $p['image_mime'] === 'image/png' && base64_decode($p['image_b64']) === $png && !isset($p['ingredients_text']));
file_put_contents($tmp, 'not an image at all');
[$st, $b] = $mk()->process(['csrf' => 'tok123'], ['image' => ['error' => UPLOAD_ERR_OK, 'size' => 19, 'tmp_name' => $tmp]], $srv);
$check('controller: file text -> 415 bad_type', $st === 415 && $b['code'] === 'bad_type');
[$st, $b] = $mk()->process(['csrf' => 'tok123'], ['image' => ['error' => UPLOAD_ERR_OK, 'size' => 9 * 1048576, 'tmp_name' => $tmp]], $srv);
$check('controller: file quá lớn -> 413 too_large', $st === 413 && $b['code'] === 'too_large');
[$st, $b] = $mk()->process(['csrf' => 'tok123'], ['image' => ['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'tmp_name' => '']], $srv);
$check('controller: UPLOAD_ERR_INI_SIZE -> 413', $st === 413);
@unlink($tmp);
$store = [];
[$st, $b] = $mk(['client' => fn() => $mkClient(500, '{}')])->process(['csrf' => 'tok123', 'ingredients_text' => $text], [], $srv);
$check('controller: Flask lỗi -> 200 unavailable + thông báo Vi', $st === 200 && $b['code'] === 'unavailable' && strpos($b['message_vi'], 'Syna tạm thời chưa đọc được ảnh') === 0);
[$st, $b] = $mk(['client' => fn() => $mkClient(413, '{}')])->process(['csrf' => 'tok123', 'ingredients_text' => $text], [], $srv);
$check('controller: Flask 413 -> 413 too_large', $st === 413 && $b['code'] === 'too_large');
[$st, $b, $h] = $mk(['client' => fn() => $mkClient(429, '{}')])->process(['csrf' => 'tok123', 'ingredients_text' => $text], [], $srv);
$check('controller: Flask 429 -> 429 rate_limited', $st === 429 && $b['code'] === 'rate_limited');
[$st, $b] = $mk(['client' => fn() => $mkClient(404, '{}')])->process(['csrf' => 'tok123', 'ingredients_text' => $text], [], $srv);
$check('controller: Flask 404 -> 404 disabled', $st === 404 && $b['code'] === 'disabled');
[$st, $b] = $mk(['client' => function () { throw new RuntimeException('boom'); }])->process(['csrf' => 'tok123', 'ingredients_text' => $text], [], $srv);
$check('controller: exception -> unavailable, không vỡ', $st === 200 && $b['code'] === 'unavailable');


// ---------- fix round 1 ----------
[$st, $b] = $mk()->process(['csrf' => ['x'], 'ingredients_text' => $text], [], $srv);
$check('fix: csrf là mảng -> 403, không warning', $st === 403 && $b['code'] === 'csrf');
$mk(['context' => fn() => ['cart_ids' => ['5', 'bad id!', '../x', 6, '5'], 'email' => str_repeat('a', 250) . '@b.cc', 'skin_profile' => null]])
    ->process(['csrf' => 'tok123', 'ingredients_text' => $text, 'conversation_id' => 'conv-1'], [], $srv);
$p = $lastPayload;
$check('fix: lọc id giỏ theo pattern + bỏ trùng', $p['product_ids'] === ['5', '6']);
$check('fix: email > 254 -> không gửi email/conversation_id', !isset($p['email']) && !isset($p['conversation_id']));
[$st, $b] = $mk()->process(['csrf' => 'tok123', 'ingredients_text' => ['x']], [], $srv);
$check('fix: ingredients_text là mảng -> 400, không warning', $st === 400);

// ---------- route / cấu hình ----------
$rootDir = dirname(__DIR__, 3);
foreach (['backend/public/index.php', 'frontend/public/index.php'] as $idx) {
    $src = (string)file_get_contents($rootDir . '/' . $idx);
    $check("route chat_label_scan có trong $idx", preg_match("/case 'chat_label_scan':\s*\/\/[^\n]*\n\s*require_once \\\$backendRoot \. '\/app\/controllers\/LabelScanController\.php';\s*\(new LabelScanController\(\\\$pdo\)\)->scan\(\);/", $src) === 1);
}
$env = (string)file_get_contents($rootDir . '/.env.example');
$check('.env.example có LABEL_ANALYZE_TIMEOUT_MS=25000', preg_match('/^LABEL_ANALYZE_TIMEOUT_MS=25000\s*$/m', $env) === 1);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
