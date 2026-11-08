<?php
// Test thuần PHP (không DB, không mạng) cho U05: pipeline xếp hạng kết quả + client intent ("Có phải bạn muốn tìm...").
// Chạy: php backend/tests/unit/SearchRankingTest.php
if (!function_exists('ss_env')) {
    function ss_env(string $key, string $default = ''): string {
        $v = $_ENV[$key] ?? getenv($key);
        return ($v === false || $v === null || trim((string)$v) === '') ? $default : trim((string)$v);
    }
}
require_once __DIR__ . '/../../app/models/SanPham.php';
require_once __DIR__ . '/../../app/services/SearchRecommendClient.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$ref = new ReflectionClass('SanPham');
$obj = $ref->newInstanceWithoutConstructor();
// Map hãng giả để không gọi DB: 24 => Torriden
$brandMap = new ReflectionProperty('SanPham', 'brandLookupMap');
$brandMap->setAccessible(true);
$brandMap->setValue(null, [24 => 'Torriden', 7 => 'CeraVe']);

// ---------------------------------------------------------------- cấu trúc pipeline
$filter = ['$and' => [['x' => 1], ['trang_thai' => ['$nin' => ['off']]]]];
$pipe = $obj->buildRankedSearchPipeline($filter, ['torriden', 'sua'], 24, 24, ['mo_ta' => 0, 'thanh_phan_full' => 0, 'hdsd' => 0]);
$ops = array_map(fn($st) => array_key_first($st), $pipe);
$check('pipeline: $match -> $project -> $addFields -> $sort -> $facet', $ops === ['$match', '$project', '$addFields', '$sort', '$facet']);
$check('pipeline: $match giữ nguyên filter', $pipe[0]['$match'] === $filter);
$check('pipeline: $sort ổn định (điểm, bán chạy, ma_san_pham tăng)', $pipe[3]['$sort'] === ['_score' => -1, 'so_luong_da_ban' => -1, 'ma_san_pham' => 1]);
$check('pipeline: $facet items skip/limit/project + total count', $pipe[4]['$facet']['items'][0] === ['$skip' => 24] && $pipe[4]['$facet']['items'][1] === ['$limit' => 24] && $pipe[4]['$facet']['items'][2] === ['$project' => ['_score' => 0]] && $pipe[4]['$facet']['total'] === [['$count' => 'n']]);

$pipeAdmin = $obj->buildRankedSearchPipeline($filter, ['kem'], 0, 10, null);
$check('pipeline: không projection -> không có $project sớm', array_map(fn($st) => array_key_first($st), $pipeAdmin) === ['$match', '$addFields', '$sort', '$facet']);

// ---------------------------------------------------------------- biểu thức điểm
$expr = $obj->rankingScoreExpression(['torriden', 'sua']);
$json = json_encode($expr, JSON_UNESCAPED_UNICODE);
$terms = $expr['$let']['in']['$add'];
$check('điểm: 6 thành phần', count($terms) === 6);
$check('điểm: cụm liền = 100 điểm, regex nối bằng \\s+', $terms[0]['$cond'][1] === SanPham::RANK_W_PHRASE && str_contains($terms[0]['$cond'][0]['$regexMatch']['regex'], '\\s+') && SanPham::RANK_W_PHRASE === 100);
$check('điểm: bắt đầu bằng từ đầu = 60, neo ^', $terms[1]['$cond'][1] === SanPham::RANK_W_PREFIX && str_starts_with($terms[1]['$cond'][0]['$regexMatch']['regex'], '^') && SanPham::RANK_W_PREFIX === 60);
$check('điểm: số từ khớp = 40 / tổng (2 từ -> mỗi từ 20)', $terms[2]['$multiply'][0] === 20 && count($terms[2]['$multiply'][1]['$add']) === 2 && SanPham::RANK_W_TOKENS === 40);
$check('điểm: hãng khớp = 30, chỉ khi có mã hãng trong map', $terms[3]['$cond'][1] === SanPham::RANK_W_BRAND && in_array(24, $terms[3]['$cond'][0]['$in'][1], true) && in_array('24', $terms[3]['$cond'][0]['$in'][1], true) && SanPham::RANK_W_BRAND === 30);
$check('điểm: bán chạy = 10 x log10(1+n)', $terms[4]['$multiply'][0] === SanPham::RANK_W_SALES && isset($terms[4]['$multiply'][1]['$log10']) && SanPham::RANK_W_SALES === 10);
$check('điểm: hết hàng trừ 50', $terms[5]['$cond'][2] === 0 && $terms[5]['$cond'][1] === -SanPham::RANK_W_OUT_OF_STOCK && SanPham::RANK_W_OUT_OF_STOCK === 50);
$check('điểm: tên được convert string an toàn (onError/onNull rỗng)', ($expr['$let']['vars']['n']['$convert']['onError'] ?? null) === '' && ($expr['$let']['vars']['n']['$convert']['onNull'] ?? null) === '');
$noBrand = $obj->rankingScoreExpression(['zzzqq'])['$let']['in']['$add'];
$check('điểm: không có hãng khớp -> thành phần hãng là hằng 0', $noBrand[3] === 0);

// Regex trong pipeline khớp không dấu / đúng thứ tự
$rx = fn(string $pattern, string $text): bool => preg_match('/' . $pattern . '/iu', $text) === 1;
$phrase = $terms[0]['$cond'][0]['$regexMatch']['regex'];
$check('regex cụm: "Sữa Rửa Mặt Torriden" không khớp "torriden sua" (sai thứ tự)', !$rx($phrase, 'Sữa Rửa Mặt Torriden'));
$check('regex cụm: "Torriden Sữa" khớp "torriden sua" (không dấu)', $rx($phrase, 'Torriden Sữa Rửa Mặt'));
$check('regex cụm: có xuống dòng/nhiều khoảng trắng vẫn khớp', $rx($phrase, "Torriden   Sữa"));
$prefix = $terms[1]['$cond'][0]['$regexMatch']['regex'];
$check('regex đầu tên: "Torriden Dive-in" khớp, "Kem Torriden" không', $rx($prefix, 'Torriden Dive-in') && !$rx($prefix, 'Kem Torriden'));
$sp = $obj->rankingScoreExpression(['c+', 'la-roche'])['$let']['in']['$add'][0]['$cond'][0]['$regexMatch']['regex'];
$check('regex: ký tự đặc biệt được escape', $rx($sp, 'Vitamin C+ La-Roche') && !$rx($sp, 'Vitamin Cc La-Roche'));

// ---------------------------------------------------------------- công tắc & sort mặc định
$isDefault = new ReflectionMethod('SanPham', 'isDefaultSort');
$isDefault->setAccessible(true);
$check('sort mặc định: "", default, mac_dinh, giá trị lạ', $isDefault->invoke($obj, '') && $isDefault->invoke($obj, 'default') && $isDefault->invoke($obj, 'mac_dinh') && $isDefault->invoke($obj, 'zzz'));
$check('sort khác: price_asc, ban_chay không xếp hạng', !$isDefault->invoke($obj, 'price_asc') && !$isDefault->invoke($obj, 'ban_chay'));
$enabled = new ReflectionMethod('SanPham', 'rankingEnabled');
$enabled->setAccessible(true);
$prev = $_ENV['SEARCH_RANKING_ENABLED'] ?? null;
unset($_ENV['SEARCH_RANKING_ENABLED']);
$check('công tắc: mặc định bật', $enabled->invoke(null) === true);
$_ENV['SEARCH_RANKING_ENABLED'] = '0';
$check('công tắc: SEARCH_RANKING_ENABLED=0 tắt', $enabled->invoke(null) === false);
if ($prev === null) { unset($_ENV['SEARCH_RANKING_ENABLED']); } else { $_ENV['SEARCH_RANKING_ENABLED'] = $prev; }

// ---------------------------------------------------------------- client intent
$ok = SearchRecommendClient::validateIntentResponse([
    'intent' => ['x' => 1],
    'corrected_q' => ' Torriden sữa rửa mặt ',
    'corrections' => [
        ['from' => 'toriden', 'to' => 'Torriden', 'type' => 'fuzzy'],
        ['from' => 'srm', 'to' => 'sữa rửa mặt', 'type' => 'synonym'],
        ['from' => 'x', 'to' => 'y', 'type' => 'evil'],
        'garbage',
        ['from' => 5, 'to' => 'y', 'type' => 'fuzzy'],
    ],
]);
$check('intent: giữ correction hợp lệ, bỏ loại lạ/sai kiểu', is_array($ok) && count($ok['corrections']) === 2 && $ok['corrected_q'] === 'Torriden sữa rửa mặt');
$check('intent: thiếu corrected_q hoặc corrections -> null', SearchRecommendClient::validateIntentResponse(['corrections' => []]) === null && SearchRecommendClient::validateIntentResponse(['corrected_q' => 'a']) === null && SearchRecommendClient::validateIntentResponse(['corrected_q' => 'a', 'corrections' => ['k' => 1]]) === null);
$long = SearchRecommendClient::validateIntentResponse(['corrected_q' => str_repeat('a', 500), 'corrections' => [['from' => str_repeat('b', 300), 'to' => 'x', 'type' => 'fuzzy']]]);
$check('intent: cắt độ dài', mb_strlen($long['corrected_q']) === 200 && mb_strlen($long['corrections'][0]['from']) === 80);

$fz = ['corrected_q' => 'Torriden sữa rửa mặt', 'corrections' => [['from' => 'toriden', 'to' => 'Torriden', 'type' => 'fuzzy'], ['from' => 'srm', 'to' => 'sữa rửa mặt', 'type' => 'synonym']]];
$check('fuzzyCorrectedQuery: có fuzzy -> trả corrected_q', SearchRecommendClient::fuzzyCorrectedQuery($fz, 'toriden srm') === 'Torriden sữa rửa mặt');
$check('fuzzyCorrectedQuery: chỉ synonym -> null (viết tắt không phải lỗi)', SearchRecommendClient::fuzzyCorrectedQuery(['corrected_q' => 'sữa rửa mặt', 'corrections' => [['from' => 'srm', 'to' => 'sữa rửa mặt', 'type' => 'synonym']]], 'srm') === null);
$check('fuzzyCorrectedQuery: null/không có correction -> null', SearchRecommendClient::fuzzyCorrectedQuery(null, 'x') === null && SearchRecommendClient::fuzzyCorrectedQuery(['corrected_q' => 'x', 'corrections' => []], 'x') === null);
$check('fuzzyCorrectedQuery: giống q gốc (khác hoa thường/khoảng trắng) -> null', SearchRecommendClient::fuzzyCorrectedQuery($fz, '  torriden   SỮA rửa mặt ') === null);

$calls = [];
$state = [];
$store = ['get' => function (string $k) use (&$state) { return $state[$k] ?? null; }, 'set' => function (string $k, array $v) use (&$state) { $state[$k] = $v; }];
$transport = function (string $url, string $body, array $headers, int $timeoutMs, int $connectMs, string $method = 'POST') use (&$calls): array {
    $calls[] = compact('url', 'body', 'headers', 'timeoutMs', 'method');
    return ['status' => 200, 'body' => json_encode(['intent' => null, 'corrected_q' => 'Torriden', 'corrections' => [['from' => 'toriden', 'to' => 'Torriden', 'type' => 'fuzzy']]]), 'error' => ''];
};
$client = new SearchRecommendClient('http://ai-service:5001/api/search/recommend', 'secret-token', 1200, 300, $transport, $store);
$res = $client->intent('toriden');
$check('intent(): GET tới /api/search/intent?q= (mã hóa URL), body rỗng, timeout <= 300ms', $res !== null && $calls[0]['url'] === 'http://ai-service:5001/api/search/intent?q=toriden' && $calls[0]['body'] === '' && $calls[0]['method'] === 'GET' && $calls[0]['timeoutMs'] <= 300);
$check('intent(): gửi X-Internal-Token', in_array('X-Internal-Token: secret-token', $calls[0]['headers'], true));
$client->intent('sữa rửa mặt & <b>');
$check('intent(): q tiếng Việt/ký tự lạ được rawurlencode', str_contains($calls[1]['url'], '?q=s%E1%BB%AFa') && !str_contains($calls[1]['url'], '<b>') && !str_contains($calls[1]['url'], ' '));
$check('intent(): q rỗng -> null, không gọi mạng', $client->intent('   ') === null && count($calls) === 2);

$failing = new SearchRecommendClient('http://ai-service:5001/api/search/recommend', '', 1200, 300, fn() => ['status' => 500, 'body' => '', 'error' => 'boom'], $store);
$check('intent(): lỗi HTTP -> null', $failing->intent('abc def') === null);
$failing->intent('abc def');
$failing->intent('abc def');
$check('intent(): 3 lỗi liên tiếp mở breaker RIÊNG (breaker recommend vẫn đóng)', $failing->isOpen('search:intent:breaker:v1') === true && $failing->isOpen() === false);
$badShape = new SearchRecommendClient('http://ai-service:5001/api/search/recommend', '', 1200, 300, fn() => ['status' => 200, 'body' => '{"foo":1}', 'error' => ''], []);
$check('intent(): response sai cấu trúc -> null', $badShape->intent('abc def') === null);

echo PHP_EOL . "Tổng: $pass PASS, $fail FAIL" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
