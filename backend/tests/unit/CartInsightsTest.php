<?php
// Test thuần PHP (không DB, không mạng) cho U09: client cart/analyze + view model + partial + route + tracking + breaker riêng.
// Chạy: php backend/tests/unit/CartInsightsTest.php
require_once __DIR__ . '/../../app/services/SearchRecommendClient.php';
require_once __DIR__ . '/../../app/services/SearchReasonFormatter.php';
require_once __DIR__ . '/../../app/services/SearchEventTracker.php';
require_once __DIR__ . '/../../app/services/CartInsights.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$sample = [
    'conflicts' => [
        ['a' => '112', 'b' => 236, 'severity' => 'high', 'timing' => 'same_routine', 'ingredients' => ['Salicylic Acid', 'Retinol', 7, 'x', 'y', 'z'],
            'rule_id' => 'retinoid_bha', 'advice_code' => 'retinoid_bha', 'advice_vi' => 'Dùng xen kẽ tối.'],
        ['a' => '1', 'b' => '2', 'severity' => 'critical', 'timing' => 'same_routine', 'ingredients' => [], 'rule_id' => 'x', 'advice_vi' => ''],   // severity lạ
        ['a' => 'bad id!', 'b' => '2', 'severity' => 'low', 'timing' => 'same_session', 'ingredients' => []],                                      // id sai
        'garbage',
    ],
    'routine' => ['has' => ['serum', 'Bad Key', 'kem_duong'], 'missing' => ['sua_rua_mat', 'kem_chong_nang'],
        'labels' => ['kem_chong_nang' => 'Kem chống nắng', 'kem_duong' => 'Kem dưỡng ẩm', 'serum' => 'Serum / Tinh chất', 'sua_rua_mat' => 'Sữa rửa mặt', 'Bad Key' => 'x'],
        'order' => ['sua_rua_mat', 'serum', 'kem_duong', 'kem_chong_nang']],
    'suggestions' => [
        ['step' => 'sua_rua_mat', 'items' => [
            ['product_id' => '1', 'score' => 0.7, 'reason_code' => 'fits_skin_type', 'reason_params' => ['da_dau', 5]],
            ['product_id' => 11, 'score' => 0.6, 'reason_code' => 'completes_routine', 'reason_params' => ['Sữa rửa mặt']],
            ['product_id' => '1', 'score' => 0.5, 'reason_code' => 'completes_routine', 'reason_params' => []],          // trùng
            ['product_id' => '9', 'score' => 0.5, 'reason_code' => 'semantic_match', 'reason_params' => []],           // ngoài contract
        ]],
        ['step' => 'bad step', 'items' => []],
        ['step' => 'kem_chong_nang', 'items' => [['product_id' => '3014', 'score' => 0.64, 'reason_code' => 'completes_routine', 'reason_params' => ['Kem chống nắng']]]],
    ],
    'degraded' => false, 'took_ms' => 12,
];

// ---------- validateCartResponse ----------
$v = SearchRecommendClient::validateCartResponse($sample);
$check('validate: giữ xung đột hợp lệ, bỏ severity lạ / id sai / rác', is_array($v) && count($v['conflicts']) === 1 && $v['conflicts'][0]['a'] === '112' && $v['conflicts'][0]['b'] === '236');
$check('validate: ingredients tối đa 4 và chỉ chuỗi', $v['conflicts'][0]['ingredients'] === ['Salicylic Acid', 'Retinol', 'x', 'y'] || count($v['conflicts'][0]['ingredients']) <= 4);
$check('validate: routine chỉ giữ key hợp lệ', $v['routine']['has'] === ['serum', 'kem_duong'] && $v['routine']['missing'] === ['sua_rua_mat', 'kem_chong_nang'] && !isset($v['routine']['labels']['Bad Key']));
$check('validate: gợi ý bỏ bước sai / item trùng / reason ngoài contract; params chỉ chuỗi', count($v['suggestions']) === 2
    && array_column($v['suggestions'][0]['items'], 'product_id') === ['1', '11'] && $v['suggestions'][0]['items'][0]['reason_params'] === ['da_dau']);
$check('validate: thiếu cấu trúc -> null', SearchRecommendClient::validateCartResponse(['conflicts' => []]) === null
    && SearchRecommendClient::validateCartResponse(['conflicts' => 'x', 'routine' => [], 'suggestions' => []]) === null
    && SearchRecommendClient::validateCartResponse(['conflicts' => ['a' => 1], 'routine' => [], 'suggestions' => []]) === null);
$check('validate: degraded', SearchRecommendClient::validateCartResponse(['conflicts' => [], 'routine' => [], 'suggestions' => [], 'degraded' => true])['degraded'] === true);

// ---------- client với transport giả + breaker riêng ----------
$store = [];
$stateStore = [
    'get' => function ($key) use (&$store) { return $store[$key] ?? null; },
    'set' => function ($key, $val) use (&$store) { $store[$key] = $val; },
];
$seen = [];
$ok = function ($url, $body, $headers, $timeoutMs, $connectMs, $method = 'POST') use (&$seen, $sample) {
    $seen = compact('url', 'body', 'headers', 'timeoutMs', 'method');
    return ['status' => 200, 'body' => json_encode($sample), 'error' => ''];
};
$c = new SearchRecommendClient('http://ai-service:5001/api/search/recommend', 'tok', 1200, 300, $ok, $stateStore);
$out = $c->cartAnalyze(['112', '236', 'bad id', '112'], ['skin_type' => 'da_dau', 'concerns' => ['mun']]);
$check('client: trả response đã validate', is_array($out) && $out['conflicts'][0]['severity'] === 'high');
$check('client: POST tới /api/cart/analyze cùng origin', $seen['method'] === 'POST' && $seen['url'] === 'http://ai-service:5001/api/cart/analyze');
$body = json_decode($seen['body'], true);
$check('client: body chỉ có product_ids (lọc, bỏ trùng) + skin_type, không lộ concerns', $body === ['product_ids' => ['112', '236'], 'skin_profile' => ['skin_type' => 'da_dau']]);
$check('client: timeout mặc định 1000ms + token + X-Request-Id', $seen['timeoutMs'] === 1000 && in_array('X-Internal-Token: tok', $seen['headers'], true) && (bool)preg_grep('/^X-Request-Id: [0-9a-f-]{36}$/', $seen['headers']));
$c->cartAnalyze(['5'], ['skin_type' => 'Da Dau; DROP']);
$check('client: skin_type sai bị bỏ', json_decode($seen['body'], true) === ['product_ids' => ['5']]);
$calls = 0;
$never = function () use (&$calls) { $calls++; return ['status' => 200, 'body' => '{}', 'error' => '']; };
$cn = new SearchRecommendClient('http://x/api/search/recommend', '', 1200, 300, $never, $stateStore);
$check('client: giỏ rỗng / id sai hết -> null, không gọi mạng', $cn->cartAnalyze([]) === null && $cn->cartAnalyze(['../x']) === null && $calls === 0);

$store = [];
$calls = 0;
$down = function () use (&$calls) { $calls++; return ['status' => 0, 'body' => '', 'error' => 'Connection refused']; };
$cd = new SearchRecommendClient('http://x/api/search/recommend', '', 1200, 300, $down, $stateStore);
$res = [];
for ($i = 0; $i < 4; $i++) { $res[] = $cd->cartAnalyze(['1', '2']); }
$check('breaker cart: lỗi -> null, 3 lỗi mở breaker, lần 4 không gọi', $res === [null, null, null, null] && $calls === 3 && $cd->isOpen('search:cart:breaker:v1'));
$check('breaker cart: không làm mở recommend / similar / intent', !$cd->isOpen() && !$cd->isOpen('search:similar:breaker:v1') && !$cd->isOpen('search:intent:breaker:v1'));
$store = [];
$bad = function () { return ['status' => 200, 'body' => '{"conflicts":"x"}', 'error' => '']; };
$cb = new SearchRecommendClient('http://x/api/search/recommend', '', 1200, 300, $bad, $stateStore);
$check('client: JSON sai cấu trúc -> null', $cb->cartAnalyze(['1']) === null);
$store = [];
$h429 = function () { return ['status' => 429, 'body' => '{"error":"rate_limited"}', 'error' => '']; };
$c4 = new SearchRecommendClient('http://x/api/search/recommend', '', 1200, 300, $h429, $stateStore);
$check('client: HTTP 429 -> null', $c4->cartAnalyze(['1']) === null);

// ---------- view model ----------
$products = [
    '112' => ['ma_san_pham' => '112', 'ten_san_pham' => 'Gel Megaduo <b>Plus</b>', 'thuong_hieu' => 'Megaduo', 'gia_ban' => 150000],
    '236' => ['ma_san_pham' => '236', 'ten_san_pham' => 'Kem Olay Retinol24', 'thuong_hieu' => 'Olay', 'gia_ban' => 600000],
    '1' => ['ma_san_pham' => '1', 'ten_san_pham' => 'Sữa rửa mặt A', 'thuong_hieu' => 'A', 'gia_ban' => 100000],
    '11' => ['ma_san_pham' => '11', 'ten_san_pham' => 'Sữa rửa mặt B', 'thuong_hieu' => 'B', 'gia_ban' => 120000],
    '3014' => ['ma_san_pham' => '3014', 'ten_san_pham' => 'Kem chống nắng C', 'thuong_hieu' => 'C', 'gia_ban' => 300000],
];
$vm = CartInsights::build($v, $products, ['112', '236']);
$check('vm: một xung đột high có nhãn + hai tên sản phẩm', count($vm['conflicts']) === 1 && $vm['conflicts'][0]['severity_label'] === 'Nên tránh dùng cùng nhau' && $vm['conflicts'][0]['a']['name'] === 'Gel Megaduo <b>Plus</b>');
$check('vm: gợi ý có reason_text tiếng Việt', $vm['suggestions'][0]['items'][0]['reason_text'] === 'Hợp da dầu' && $vm['suggestions'][0]['items'][1]['reason_text'] === 'Hoàn thiện routine: Sữa rửa mặt');
$check('vm: chip bước theo thứ tự routine chuẩn (order), has/missing đúng', array_column($vm['steps'], 'key') === ['sua_rua_mat', 'serum', 'kem_duong', 'kem_chong_nang'] && array_column($vm['steps'], 'state') === ['missing', 'has', 'has', 'missing']);
$check('vm: hiển thị khi có nội dung', $vm['visible'] === true);
$vm2 = CartInsights::build($v, ['112' => $products['112']], ['1']);
$check('vm: thiếu sản phẩm (ẩn/hết hiệu lực) -> bỏ xung đột; gợi ý đã có trong giỏ bị bỏ', $vm2['conflicts'] === [] && $vm2['suggestions'] === [] && $vm2['visible'] === false);
$vm3 = CartInsights::build(['conflicts' => [], 'routine' => ['has' => [], 'missing' => [], 'labels' => []], 'suggestions' => [], 'degraded' => true], [], []);
$check('vm: không có gì -> không hiển thị', $vm3['visible'] === false && $vm3['steps'] === []);
$check('vm: productIds gom id xung đột + gợi ý', CartInsights::productIds($v) === ['112', '236', '1', '11', '3014']);

// ---------- partial: escape + cấu trúc tracking ----------
if (!defined('BASE_URL')) { define('BASE_URL', ''); }
if (!function_exists('resolve_image_url')) { function resolve_image_url($u) { return (string)$u; } }
if (!function_exists('default_placeholder_image')) { function default_placeholder_image() { return '/ph.png'; } }
if (!function_exists('product_is_out_of_stock')) { function product_is_out_of_stock($p) { return !empty($p['het_hang']); } }
if (!function_exists('vnd')) { function vnd($v) { return number_format((float)$v, 0, ',', '.') . ' ₫'; } }
$xssAdvice = '<script>alert(1)</script>"\'';
$vmx = $v;
$vmx['conflicts'][0]['advice_vi'] = $xssAdvice;
$vmx['conflicts'][0]['ingredients'] = ['<img src=x onerror=alert(1)>'];
$cartInsights = CartInsights::build($vmx, $products, ['112', '236']);
ob_start();
require __DIR__ . '/../../../frontend/views/partials/cart_insights.php';
$html = (string)ob_get_clean();
$check('partial: không chèn thẻ do dữ liệu bên ngoài (advice / hoạt chất / tên sản phẩm)', strpos($html, '<script>alert') === false && strpos($html, '<img src=x') === false && strpos($html, '<b>Plus</b>') === false);
$check('partial: chuỗi được escape', strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false && strpos($html, 'Gel Megaduo &lt;b&gt;Plus&lt;/b&gt;') !== false);
$check('partial: section conflict + routine có data-ssf-section-key và tracking q', strpos($html, 'data-ssf-section-key="conflict"') !== false && strpos($html, 'data-ssf-section-key="routine"') !== false && strpos($html, 'data-ssf-q="cart:analyze"') !== false);
$check('partial: thẻ gợi ý có data-ssf-section="routine" + form thêm giỏ AJAX, không có "Mua ngay"', substr_count($html, 'data-ssf-section="routine"') === 3 && strpos($html, 'name="buy_now"') === false && strpos($html, 'r=them_gio_hang_ajax') !== false);
$check('partial: mức độ high có biểu tượng cảnh báo + lớp cin-sev-high', strpos($html, 'cin-sev-high') !== false && strpos($html, 'fa-triangle-exclamation') !== false);
$check('partial: không chặn thanh toán (có ghi chú tham khảo)', strpos($html, 'vẫn có thể thanh toán') !== false);
$cartInsights = ['visible' => false];
ob_start();
require __DIR__ . '/../../../frontend/views/partials/cart_insights.php';
$check('partial: visible=false -> không in gì', trim((string)ob_get_clean()) === '');

// ---------- route / tracking / cấu hình ----------
$rootDir = dirname(__DIR__, 3);
foreach (['backend/public/index.php', 'frontend/public/index.php'] as $idx) {
    $src = (string)file_get_contents($rootDir . '/' . $idx);
    $check("route cart_analyze có trong $idx", preg_match("/case 'cart_analyze':\s*\/\/[^\n]*\n\s*\(new SanPhamController\(\\\$pdo\)\)->cartInsights\(\);/", $src) === 1);
}
$check('tracking: section routine / conflict hợp lệ', in_array('routine', SearchEventTracker::SECTIONS, true) && in_array('conflict', SearchEventTracker::SECTIONS, true));
$ev = SearchEventTracker::validate(['q' => 'cart:analyze', 'events' => [
    ['action' => 'impression', 'section' => 'conflict'],
    ['action' => 'impression', 'section' => 'routine'],
    ['action' => 'add_to_cart', 'section' => 'routine', 'product_id' => '3014'],
]]);
$check('tracking: beacon routine/conflict qua validate()', is_array($ev) && count($ev['events']) === 3 && $ev['q_norm'] === 'cart:analyze');
$check('copy: tiêu đề section + lý do completes_routine', SearchReasonFormatter::sectionTitle('routine') === 'Hoàn thiện routine của bạn' && SearchReasonFormatter::format('completes_routine', ['Kem chống nắng']) === 'Hoàn thiện routine: Kem chống nắng');
$gh = (string)file_get_contents($rootDir . '/frontend/views/giohang.php');
$check('view giohang: container fetch + cart:changed + không sửa luồng thanh toán', strpos($gh, 'data-cart-insights') !== false && strpos($gh, "r=cart_analyze") !== false && strpos($gh, "new CustomEvent('cart:changed')") !== false && strpos($gh, 'submitSelectedItemsForCheckout') !== false);
$ctrl = (string)file_get_contents($rootDir . '/backend/app/controllers/SanPhamController.php');
$check('controller: cartInsights nhả khóa phiên, rate limit, 204 khi lỗi', strpos($ctrl, 'public function cartInsights') !== false && strpos($ctrl, "insightsAllowed('cart_insights')") !== false && strpos($ctrl, 'CART_INSIGHTS_ENABLED') !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
