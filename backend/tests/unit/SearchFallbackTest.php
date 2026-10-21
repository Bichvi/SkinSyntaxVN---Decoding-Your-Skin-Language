<?php
// Test thuần PHP (không DB, không mạng) cho Smart Search Bước 06.
// Chạy: php backend/tests/unit/SearchFallbackTest.php
require_once __DIR__ . '/../../app/services/SearchRecommendClient.php';
require_once __DIR__ . '/../../app/services/SearchReasonFormatter.php';
require_once __DIR__ . '/../../app/services/SearchFallbackBuilder.php';
require_once __DIR__ . '/../../app/models/SanPham.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

// ---------- validateResponse ----------
$v = SearchRecommendClient::validateResponse(['sections' => [[
    'key' => 'semantic',
    'items' => [
        ['product_id' => '1496', 'score' => 0.9, 'reason_code' => 'semantic_match', 'reason_params' => []],
        ['product_id' => 'bad id!', 'score' => 0.5, 'reason_code' => 'semantic_match', 'reason_params' => []],
        ['product_id' => '18', 'score' => 0.4, 'reason_code' => 'hacked', 'reason_params' => []],
        'garbage',
        ['product_id' => str_repeat('a', 33), 'score' => 1, 'reason_code' => 'trending', 'reason_params' => []],
        ['product_id' => '20', 'score' => 0.3, 'reason_code' => 'trending', 'reason_params' => ['x', 5, 'y', 'z', 'w']],
    ],
]], 'intent' => null, 'degraded' => false]);
$check('validate: giữ item hợp lệ, bỏ item sai', is_array($v) && array_column($v['sections'][0]['items'], 'product_id') === ['1496', '20']);
$check('validate: params chỉ chuỗi, tối đa 3', $v['sections'][0]['items'][1]['reason_params'] === ['x', 'y']);
$check('validate: thiếu sections -> null', SearchRecommendClient::validateResponse(['foo' => 1]) === null);
$check('validate: sections không phải list -> null', SearchRecommendClient::validateResponse(['sections' => ['a' => []]]) === null);
$check('validate: section thiếu items -> null', SearchRecommendClient::validateResponse(['sections' => [['key' => 'semantic']]]) === null);
$check('validate: section key lạ -> bỏ section', SearchRecommendClient::validateResponse(['sections' => [['key' => 'evil', 'items' => []]]])['sections'] === []);

// ---------- contract: response mẫu khớp schema Bước 05 ----------
$schemaPath = __DIR__ . '/../../../ai-service-flask/search_service/contract/recommend_response.schema.json';
if (!is_file($schemaPath)) {
    echo 'SKIP contract schema (không thấy file — chạy test từ host repo)' . PHP_EOL;
    $schema = ['properties' => ['sections' => ['items' => ['properties' => ['key' => ['enum' => SearchRecommendClient::SECTION_KEYS], 'items' => ['items' => ['properties' => ['reason_code' => ['enum' => SearchRecommendClient::REASON_CODES], 'product_id' => ['pattern' => trim(SearchRecommendClient::PRODUCT_ID_PATTERN, '/')]]]]]]]]];
} else {
    $schema = json_decode((string)file_get_contents($schemaPath), true);
    $check('contract: đọc được schema', is_array($schema));
}
$schemaSectionKeys = $schema['properties']['sections']['items']['properties']['key']['enum'] ?? [];
$schemaReasons = $schema['properties']['sections']['items']['properties']['items']['items']['properties']['reason_code']['enum'] ?? [];
$schemaPattern = $schema['properties']['sections']['items']['properties']['items']['items']['properties']['product_id']['pattern'] ?? '';
sort($schemaSectionKeys);
sort($schemaReasons);
$k = SearchRecommendClient::SECTION_KEYS; sort($k);
$r = SearchRecommendClient::REASON_CODES; sort($r);
$check('contract: section keys khớp schema', $k === $schemaSectionKeys);
$check('contract: reason codes khớp schema', $r === $schemaReasons);
$check('contract: product_id pattern khớp schema', '/' . $schemaPattern . '/' === SearchRecommendClient::PRODUCT_ID_PATTERN);
$sample = json_decode('{"mode":"fallback","degraded":false,"degraded_branches":["same_brand","category_alt","personalized"],"took_ms":17,"intent":null,"sections":[{"key":"semantic","items":[{"product_id":"1496","score":0.031,"reason_code":"semantic_match","reason_params":[]}]},{"key":"trending","items":[{"product_id":"18","score":12.0,"reason_code":"trending","reason_params":[]}]}]}', true);
$ps = SearchRecommendClient::validateResponse($sample);
$check('contract: parse response mẫu', $ps !== null && count($ps['sections']) === 2 && $ps['intent'] === null && $ps['degraded'] === false);
$cfg = SearchReasonFormatter::config();
$check('contract: mọi reason_code có copy', !array_diff($schemaReasons, array_keys($cfg['reasons'])));
$check('contract: mọi section key có tiêu đề', !array_diff(array_merge($schemaSectionKeys, ['best_sellers']), array_keys($cfg['sections'])));

// ---------- client với transport giả + breaker ----------
$store = [];
$stateStore = [
    'get' => function ($key) use (&$store) { return $store[$key] ?? null; },
    'set' => function ($key, $val) use (&$store) { $store[$key] = $val; },
];
$calls = 0;
$sentHeaders = [];
$sentBody = '';
$ok = function ($url, $body, $headers) use (&$calls, &$sentHeaders, &$sentBody, $sample) {
    $calls++; $sentHeaders = $headers; $sentBody = $body;
    return ['status' => 200, 'body' => json_encode($sample), 'error' => ''];
};
$c = new SearchRecommendClient('http://x/api/search/recommend', 'tok', 1200, 300, $ok, $stateStore);
$out = $c->recommend('xyzabc', null, null, ['1', '1', 'bad id'], ['2'], 8);
$check('client: trả response đã validate', is_array($out) && count($out['sections']) === 2);
$check('client: gửi X-Internal-Token khi có token', in_array('X-Internal-Token: tok', $sentHeaders, true));
$check('client: có X-Request-Id', (bool)preg_grep('/^X-Request-Id: [0-9a-f-]{36}$/', $sentHeaders));
$body = json_decode($sentBody, true);
$check('client: cart_ids lọc + bỏ trùng', $body['cart_ids'] === ['1'] && !isset($body['user_hash']) && !isset($body['skin_profile']));
$c2 = new SearchRecommendClient('http://x', '', 1200, 300, $ok, $stateStore);
$c2->recommend('a', null, null, [], [], 8);
$check('client: không token -> không gửi header', !preg_grep('/^X-Internal-Token/', $sentHeaders));

$store = [];
$calls = 0;
$down = function () use (&$calls) { $calls++; return ['status' => 0, 'body' => '', 'error' => 'Connection refused']; };
$c3 = new SearchRecommendClient('http://x', '', 1200, 300, $down, $stateStore);
$res = [];
for ($i = 0; $i < 4; $i++) { $res[] = $c3->recommend('a', null, null, [], [], 8); }
$check('breaker: lỗi -> null', $res === [null, null, null, null]);
$check('breaker: 3 lỗi liên tiếp -> lần 4 không gọi', $calls === 3 && $c3->isOpen());
$store = [];
$bad = function () { return ['status' => 200, 'body' => '{"sections":"x"}', 'error' => '']; };
$c4 = new SearchRecommendClient('http://x', '', 1200, 300, $bad, $stateStore);
$check('client: JSON sai cấu trúc -> null', $c4->recommend('a', null, null, [], [], 8) === null);
$check('breaker: thành công reset đếm', (function () use ($ok, $stateStore, &$store) {
    (new SearchRecommendClient('http://x', '', 1200, 300, $ok, $stateStore))->recommend('a', null, null, [], [], 8);
    return ($store['search:recommend:breaker:v1']['fails'] ?? -1) === 0;
})());

// ---------- formatter ----------
$check('fmt: label map', SearchReasonFormatter::format('fits_skin_type', ['da_dau', 'da_nhay_cam']) === 'Hợp da dầu, da nhạy cảm');
$check('fmt: tối đa 3 param', SearchReasonFormatter::format('shared_ingredients', ['a', 'b', 'c', 'd']) === 'Chung hoạt chất a, b, c');
$check('fmt: param dài cắt 40 ký tự', SearchReasonFormatter::format('targets_concern', [str_repeat('ơ', 60)]) === 'Hỗ trợ ' . str_repeat('ơ', 40));
$check('fmt: code lạ -> rỗng', SearchReasonFormatter::format('unknown', ['x']) === '');
$check('fmt: không param -> bỏ %s', SearchReasonFormatter::format('fits_skin_type', []) === 'Hợp');
$check('fmt: code không tham số', SearchReasonFormatter::format('semantic_match', ['ignored']) === 'Gần với từ khóa');
$check('fmt: không escape HTML', SearchReasonFormatter::format('targets_concern', ['<b>']) === 'Hỗ trợ <b>');
foreach (array_keys($cfg['reasons']) as $code) {
    if (SearchReasonFormatter::format($code, ['x']) === '') { $check('fmt: đủ code ' . $code, false); }
}

// ---------- giữ thứ tự id ----------
$byId = ['3' => ['ma_san_pham' => 3], '1' => ['ma_san_pham' => 1], '2' => ['ma_san_pham' => 2]];
$check('orderByIds: giữ thứ tự, bỏ id thiếu', array_column(SanPham::orderByIds(['2', '9', '3', '1'], $byId), 'ma_san_pham') === [2, 3, 1]);

// ---------- builder ----------
$fakeFind = function (array $ids) { // Mongo trả theo thứ tự tùy ý; builder phải giữ thứ tự Flask
    $db = ['18' => ['ma_san_pham' => 18, 'ten_san_pham' => 'B'], '1496' => ['ma_san_pham' => 1496, 'ten_san_pham' => 'A']];
    $out = [];
    foreach (array_reverse($ids) as $id) { if (isset($db[$id])) { $out[] = $db[$id]; } }
    return $out;
};
$fakeBest = function (int $limit) { return [['ma_san_pham' => 7, 'ten_san_pham' => 'Best']]; };
$fb = SearchFallbackBuilder::build($ps, $fakeFind, $fakeBest);
$check('builder: sections + title + reason_text', $fb['sections'][0]['key'] === 'semantic' && $fb['sections'][0]['title'] === 'Có thể bạn đang tìm' && $fb['sections'][0]['items'][0]['reason_text'] === 'Gần với từ khóa');
$check('builder: degraded=false, intent=null', $fb['degraded'] === false && $fb['intent'] === null);
$check('builder: chips mode A chỉ Bán chạy', $fb['chips'] === [['label' => 'Bán chạy', 'url' => 'index.php?r=tatca&sort=ban_chay', 'kind' => 'best_sellers']]);
$fbNull = SearchFallbackBuilder::build(null, $fakeFind, $fakeBest);
$check('builder: null -> best_sellers degraded', $fbNull['degraded'] === true && $fbNull['sections'][0]['key'] === 'best_sellers' && $fbNull['sections'][0]['title'] === 'Sản phẩm bán chạy');
$missing = ['degraded' => false, 'intent' => null, 'sections' => [['key' => 'semantic', 'items' => [['product_id' => '999', 'score' => 1, 'reason_code' => 'semantic_match', 'reason_params' => []]]]]];
$fbMiss = SearchFallbackBuilder::build($missing, $fakeFind, $fakeBest);
$check('builder: tất cả rỗng -> best_sellers', $fbMiss['sections'][0]['key'] === 'best_sellers' && $fbMiss['degraded'] === false);
$order = ['degraded' => false, 'intent' => null, 'sections' => [['key' => 'semantic', 'items' => [
    ['product_id' => '1496', 'score' => 1, 'reason_code' => 'semantic_match', 'reason_params' => []],
    ['product_id' => '18', 'score' => 1, 'reason_code' => 'semantic_match', 'reason_params' => []],
]]]];
$check('builder: giữ thứ tự Flask', array_column(SearchFallbackBuilder::build($order, $fakeFind, $fakeBest)['sections'][0]['items'], 'ma_san_pham') === [1496, 18]);

// ---------- chips với intent (mode B) ----------
$chips = SearchFallbackBuilder::chips(['brands' => ['Torriden'], 'categories' => ['Sữa Rửa Mặt'], 'skin_types' => [], 'concerns' => [], 'confidence' => 0.8]);
$check('chips: brand', $chips[0] === ['label' => 'Cùng thương hiệu', 'url' => 'index.php?r=tatca&q=Torriden', 'kind' => 'same_brand']);
$check('chips: category dùng cap2 + urlencode', $chips[1]['label'] === 'Sữa Rửa Mặt' && $chips[1]['url'] === 'index.php?' . http_build_query(['r' => 'tatca', 'cap2' => 'Sữa Rửa Mặt']));
$check('chips: luôn có Bán chạy cuối', end($chips)['label'] === 'Bán chạy' && count($chips) === 3);
$check('chips: kind cho tracking (Bước 08)', array_column($chips, 'kind') === ['same_brand', 'category', 'best_sellers']);

// ---------- Bước 05B: key đồ thị + tên hiển thị, Bán chạy giữ q ----------
$b5 = SearchRecommendClient::validateResponse(['sections' => [], 'intent' => [
    'brands' => ['torriden'], 'categories' => ['sua_rua_mat'], 'skin_types' => [], 'concerns' => [], 'confidence' => 1.0,
    'brand_names' => ['Torriden'], 'category_names' => ['Sữa Rửa Mặt', 7], 'price' => null,
]]);
$check('validate: giữ brand_names/category_names (chỉ chuỗi)', $b5['intent']['brand_names'] === ['Torriden'] && $b5['intent']['category_names'] === ['Sữa Rửa Mặt']);
$chipsB = SearchFallbackBuilder::chips($b5['intent'], 'torriden sữa rửa mặt');
$check('chips B: brand dùng tên hiển thị', $chipsB[0]['url'] === 'index.php?r=tatca&q=Torriden');
$check('chips B: category dùng tên danh mục cho cap2', $chipsB[1]['label'] === 'Sữa Rửa Mặt' && $chipsB[1]['url'] === 'index.php?' . http_build_query(['r' => 'tatca', 'cap2' => 'Sữa Rửa Mặt']));
$check('chips B: Bán chạy giữ q', end($chipsB)['url'] === 'index.php?' . http_build_query(['r' => 'tatca', 'q' => 'torriden sữa rửa mặt', 'sort' => 'ban_chay']));
$chipsKeyOnly = SearchFallbackBuilder::chips(['brands' => ['torriden'], 'categories' => ['sua_rua_mat']], 'xyzabc');
$check('chips B: thiếu *_names -> fallback key', $chipsKeyOnly[0]['url'] === 'index.php?r=tatca&q=torriden' && $chipsKeyOnly[1]['label'] === 'sua_rua_mat');
$check('chips A: q rỗng -> Bán chạy không có q', end($chips)['url'] === 'index.php?r=tatca&sort=ban_chay');
$fbQ = SearchFallbackBuilder::build(null, $fakeFind, $fakeBest, 'xyzabc');
$check('builder: truyền q vào chip Bán chạy', end($fbQ['chips'])['url'] === 'index.php?r=tatca&q=xyzabc&sort=ban_chay');

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
