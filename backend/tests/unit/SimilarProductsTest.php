<?php
// Test thuần PHP (không DB, không mạng) cho U08: khối "Sản phẩm tương tự" (client + partial + route + tracking).
// Chạy: php backend/tests/unit/SimilarProductsTest.php
require_once __DIR__ . '/../../app/services/SearchRecommendClient.php';
require_once __DIR__ . '/../../app/services/SearchReasonFormatter.php';
require_once __DIR__ . '/../../app/services/SearchEventTracker.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

// ---------- validateSimilarResponse ----------
$v = SearchRecommendClient::validateSimilarResponse([
    'product_id' => '1496', 'degraded' => false, 'source' => 'graph', 'took_ms' => 5,
    'items' => [
        ['product_id' => '2321', 'score' => 0.69, 'reason_code' => 'same_category', 'reason_params' => []],
        ['product_id' => 4747, 'score' => 0.7, 'reason_code' => 'shared_ingredients', 'reason_params' => ['A', 'B', 7, 'C', 'D']],
        ['product_id' => '2321', 'score' => 0.1, 'reason_code' => 'same_brand', 'reason_params' => []],      // trùng id
        ['product_id' => 'bad id!', 'score' => 0.5, 'reason_code' => 'same_category', 'reason_params' => []],
        ['product_id' => '5', 'score' => 0.5, 'reason_code' => 'semantic_match', 'reason_params' => []],      // ngoài contract
        'garbage',
    ],
]);
$check('validate: giữ item hợp lệ, bỏ trùng/sai/ngoài contract', is_array($v) && array_column($v['items'], 'product_id') === ['2321', '4747']);
$check('validate: id số -> chuỗi, params: lấy 3 đầu rồi chỉ giữ chuỗi (như validateResponse)', $v['items'][1]['product_id'] === '4747' && $v['items'][1]['reason_params'] === ['A', 'B']);
$check('validate: source/degraded', $v['source'] === 'graph' && $v['degraded'] === false);
$check('validate: source lạ -> null', SearchRecommendClient::validateSimilarResponse(['items' => [], 'source' => 'evil'])['source'] === null);
$check('validate: thiếu items -> null', SearchRecommendClient::validateSimilarResponse(['foo' => 1]) === null);
$check('validate: items không phải list -> null', SearchRecommendClient::validateSimilarResponse(['items' => ['a' => 1]]) === null);
$many = array_map(static fn($i) => ['product_id' => (string)$i, 'score' => 1, 'reason_code' => 'same_category', 'reason_params' => []], range(1, 40));
$check('validate: tối đa 20 item', count(SearchRecommendClient::validateSimilarResponse(['items' => $many])['items']) === 20);

// ---------- client với transport giả + breaker riêng cho từng endpoint ----------
$store = [];
$stateStore = [
    'get' => function ($key) use (&$store) { return $store[$key] ?? null; },
    'set' => function ($key, $val) use (&$store) { $store[$key] = $val; },
];
$sample = ['product_id' => '1496', 'degraded' => false, 'took_ms' => 4, 'items' => [
    ['product_id' => '2321', 'score' => 0.69, 'reason_code' => 'same_category', 'reason_params' => []],
]];
$seen = [];
$ok = function ($url, $body, $headers, $timeoutMs, $connectMs, $method = 'POST') use (&$seen, $sample) {
    $seen = compact('url', 'body', 'headers', 'timeoutMs', 'method');
    return ['status' => 200, 'body' => json_encode($sample), 'error' => ''];
};
$c = new SearchRecommendClient('http://ai-service:5001/api/search/recommend', 'tok', 1200, 300, $ok, $stateStore);
$out = $c->similar('1496', 'da_dau', ['1496', '22', 'bad id', '22'], 8);
$check('client: trả response đã validate', is_array($out) && $out['items'][0]['product_id'] === '2321');
$check('client: GET tới /api/products/<id>/similar cùng origin', $seen['method'] === 'GET' && strpos($seen['url'], 'http://ai-service:5001/api/products/1496/similar?') === 0);
parse_str((string)parse_url($seen['url'], PHP_URL_QUERY), $q);
$check('client: query limit/exclude(lọc, bỏ trùng)/skin_type', $q === ['limit' => '8', 'exclude' => '1496,22', 'skin_type' => 'da_dau']);
$check('client: timeout mặc định 800ms', $seen['timeoutMs'] === 800 && $seen['body'] === '');
$check('client: gửi token + X-Request-Id', in_array('X-Internal-Token: tok', $seen['headers'], true) && (bool)preg_grep('/^X-Request-Id: [0-9a-f-]{36}$/', $seen['headers']));
$c->similar('1496', null, [], 3);
parse_str((string)parse_url($seen['url'], PHP_URL_QUERY), $q2);
$check('client: không skin_type/exclude -> không gửi', $q2 === ['limit' => '3']);
$c->similar('1496', 'Da Dau; DROP', [], 99);
parse_str((string)parse_url($seen['url'], PHP_URL_QUERY), $q3);
$check('client: skin_type sai bị bỏ, limit cắt 20', $q3 === ['limit' => '20']);
$calls = 0;
$never = function () use (&$calls) { $calls++; return ['status' => 200, 'body' => '{}', 'error' => '']; };
$cn = new SearchRecommendClient('http://x/api/search/recommend', '', 1200, 300, $never, $stateStore);
$check('client: id sai -> null, không gọi mạng', $cn->similar('../etc', null, [], 8) === null && $cn->similar('', null, [], 8) === null && $calls === 0);

$store = [];
$calls = 0;
$down = function () use (&$calls) { $calls++; return ['status' => 0, 'body' => '', 'error' => 'Connection refused']; };
$cd = new SearchRecommendClient('http://x/api/search/recommend', '', 1200, 300, $down, $stateStore);
$res = [];
for ($i = 0; $i < 4; $i++) { $res[] = $cd->similar('1496', null, [], 8); }
$check('breaker: lỗi -> null', $res === [null, null, null, null]);
$check('breaker riêng: 3 lỗi liên tiếp mở breaker similar, lần 4 không gọi', $calls === 3 && $cd->isOpen('search:similar:breaker:v1'));
$check('breaker riêng: breaker recommend/intent/cart vẫn đóng', !$cd->isOpen() && !$cd->isOpen('search:intent:breaker:v1') && !$cd->isOpen('search:cart:breaker:v1'));
$count = 0;
$spy = function () use (&$count) { $count++; return ['status' => 200, 'body' => '{"sections":[]}', 'error' => '']; };
$cr = new SearchRecommendClient('http://x/api/search/recommend', '', 1200, 300, $spy, $stateStore);
$check('breaker riêng: recommend() KHÔNG bị chặn khi similar làm mở breaker (review U08)', is_array($cr->recommend('a', null, null, [], [], 8)) && $count === 1);
$store = [];
$calls = 0;
$cdr = new SearchRecommendClient('http://x/api/search/recommend', '', 1200, 300, $down, $stateStore);
for ($i = 0; $i < 3; $i++) { $cdr->recommend('a', null, null, [], [], 8); }
$sim = new SearchRecommendClient('http://x/api/search/recommend', '', 1200, 300, $ok, $stateStore);
$check('breaker riêng: recommend mở breaker thì similar vẫn gọi được', $cdr->isOpen() && is_array($sim->similar('1496', null, [], 8)));
$store = [];
$bad = function () { return ['status' => 200, 'body' => '{"items":"x"}', 'error' => '']; };
$cb = new SearchRecommendClient('http://x/api/search/recommend', '', 1200, 300, $bad, $stateStore);
$check('client: JSON sai cấu trúc -> null', $cb->similar('1', null, [], 8) === null);
$store = [];
$http = function () { return ['status' => 429, 'body' => '{"error":"rate_limited"}', 'error' => '']; };
$ch = new SearchRecommendClient('http://x/api/search/recommend', '', 1200, 300, $http, $stateStore);
$check('client: HTTP 429 -> null', $ch->similar('1', null, [], 8) === null);

// ---------- cấu hình hiển thị ----------
$check('copy: tiêu đề section similar', SearchReasonFormatter::sectionTitle('similar') === 'Sản phẩm tương tự');
$check('copy: lý do shared_ingredients', SearchReasonFormatter::format('shared_ingredients', ['Niacinamide', 'Panthenol']) === 'Chung hoạt chất Niacinamide, Panthenol');
$check('copy: lý do same_category/same_brand', SearchReasonFormatter::format('same_category', []) === 'Cùng công dụng' && SearchReasonFormatter::format('same_brand', []) === 'Cùng dòng sản phẩm');
$check('tracking: section similar hợp lệ', in_array('similar', SearchEventTracker::SECTIONS, true));
$ev = SearchEventTracker::validate(['q' => 'similar:1496', 'events' => [
    ['action' => 'impression', 'section' => 'similar'],
    ['action' => 'click', 'section' => 'similar', 'product_id' => '2321'],
    ['action' => 'add_to_cart', 'section' => 'similar', 'product_id' => '2321'],
]]);
$check('tracking: beacon similar qua validate()', is_array($ev) && count($ev['events']) === 3 && $ev['q_norm'] === 'similar:1496');

// ---------- partial: escape + cấu trúc tracking ----------
if (!defined('BASE_URL')) {
    define('BASE_URL', '.');
}
require_once __DIR__ . '/../../../frontend/views/helpers.php';
$render = function (array $items, string $pid = '1496', bool $degraded = false): string {
    $similarItems = $items;
    $similarProductId = $pid;
    $similarDegraded = $degraded;
    $similarTitle = 'Sản phẩm tương tự';
    ob_start();
    include __DIR__ . '/../../../frontend/views/partials/similar_products.php';
    return (string)ob_get_clean();
};
$evil = '<img src=x onerror=alert(1)>';
$html = $render([
    ['ma_san_pham' => '2321', 'ten_san_pham' => $evil, 'thuong_hieu' => '"><script>alert(2)</script>', 'gia_ban' => 123000,
     'reason_code' => 'shared_ingredients', 'reason_text' => 'Chung hoạt chất <b>X</b>', 'link_hinh_anh' => 'javascript:alert(3)'],
    ['ma_san_pham' => '4747', 'ten_san_pham' => 'Kem B', 'thuong_hieu' => 'B', 'gia_ban' => 99000, 'so_luong_ton_kho' => 0,
     'reason_code' => 'same_category', 'reason_text' => 'Cùng công dụng'],
]);
$check('partial: escape tên/hãng/lý do (không có thẻ thô)', strpos($html, '<img src=x') === false && strpos($html, '<script>alert') === false && strpos($html, '<b>X</b>') === false);
$check('partial: có bản đã escape', strpos($html, '&lt;img src=x onerror=alert(1)&gt;') !== false);
$check('partial: data-ssf-section="similar" trên mỗi card', substr_count($html, 'data-ssf-section="similar"') === 2);
$check('partial: root tracking q=similar:<id> + csrf + endpoint', strpos($html, 'data-ssf-q="similar:1496"') !== false && strpos($html, 'data-ssf-csrf="') !== false && strpos($html, 'r=search_event') !== false);
$check('partial: section key + tiêu đề', strpos($html, 'data-ssf-section-key="similar"') !== false && strpos($html, '>Sản phẩm tương tự<') !== false);
$check('partial: link chi tiết + form thêm giỏ', strpos($html, 'r=chitiet&amp;id=2321') !== false && strpos($html, 'r=them_gio_hang_ajax') !== false);
$check('partial: badge lý do', strpos($html, 'class="ssf-reason"') !== false && strpos($html, 'Cùng công dụng') !== false);
$check('partial: hết hàng -> nút disabled', preg_match('/ssf-btn-cart" type="submit" disabled/', $html) === 1);
$check('partial: carousel (track + nút cuộn)', strpos($html, 'data-ssim-track') !== false && strpos($html, 'data-ssim-prev') !== false);
$check('partial: không item -> rỗng', trim($render([])) === '');
$htmlPid = $render([['ma_san_pham' => '1', 'ten_san_pham' => 'A', 'gia_ban' => 1]], '14"><x');
$check('partial: id trong q được escape', strpos($htmlPid, '"><x') === false);

// ---------- route + view (tĩnh) ----------
$routeOk = true;
foreach (['backend/public/index.php', 'frontend/public/index.php'] as $rel) {
    $src = file_get_contents(__DIR__ . '/../../../' . $rel);
    $routeOk = $routeOk && preg_match("/case 'similar_products':\s*\/\/[^\n]*\n\s*\(new SanPhamController\(\\\$pdo\)\)->similarProducts\(\);/", $src) === 1;
}
$check('route: similar_products có trong CẢ backend/public và frontend/public index.php', $routeOk);
$view = file_get_contents(__DIR__ . '/../../../frontend/views/chitiet.php');
$check('view chitiet: placeholder lười + khối cũ giữ nguyên', strpos($view, 'data-similar-products') !== false && strpos($view, 'frequently-bought-card') !== false);
$check('view chitiet: placeholder nằm sau khối mua kèm và trước tab', strpos($view, 'frequently-bought-card') < strpos($view, 'data-similar-products') && strpos($view, 'data-similar-products') < strpos($view, 'detail-tabs-card mt-4'));

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
