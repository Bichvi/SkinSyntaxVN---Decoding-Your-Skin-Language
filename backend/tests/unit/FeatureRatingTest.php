<?php
// Test thuần PHP (không DB) cho FeatureRating::normalize / stats / dayKey / userHash.
// Chạy: php backend/tests/unit/FeatureRatingTest.php
require_once __DIR__ . '/../../app/models/FeatureRating.php';

$fail = 0;
$pass = 0;
$eq = function (string $name, $got, $want) use (&$fail, &$pass) {
    $ok = $got === $want;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : ' (got ' . var_export($got, true) . ')') . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$throws = function (string $name, array $input) use (&$fail, &$pass) {
    try {
        FeatureRating::normalize($input);
        echo 'FAIL ' . $name . ' (không ném lỗi)' . PHP_EOL;
        $fail++;
    } catch (InvalidArgumentException $e) {
        echo 'PASS ' . $name . PHP_EOL;
        $pass++;
    }
};

// Hợp lệ
$ok = FeatureRating::normalize(['feature' => 'chatbot', 'stars' => 4, 'comment' => '  Rất hữu ích  ']);
$eq('hợp lệ: feature', $ok['feature'], 'chatbot');
$eq('hợp lệ: stars', $ok['stars'], 4);
$eq('hợp lệ: comment được trim', $ok['comment'], 'Rất hữu ích');
$eq('hợp lệ: context mặc định', $ok['context'], ['page' => '', 'rule_ids' => []]);
$eq('stars dạng chuỗi "5"', FeatureRating::normalize(['feature' => 'recommendation', 'stars' => '5'])['stars'], 5);
foreach (FeatureRating::FEATURES as $f) {
    $eq("feature $f được chấp nhận", FeatureRating::normalize(['feature' => $f, 'stars' => 1])['feature'], $f);
}

// Số sao sai
$throws('stars = 0', ['feature' => 'chatbot', 'stars' => 0]);
$throws('stars = 6', ['feature' => 'chatbot', 'stars' => 6]);
$throws('stars âm', ['feature' => 'chatbot', 'stars' => -1]);
$throws('stars thiếu', ['feature' => 'chatbot']);
$throws('stars = 3.5', ['feature' => 'chatbot', 'stars' => 3.5]);
$throws('stars chuỗi chữ', ['feature' => 'chatbot', 'stars' => 'abc']);
$throws('stars mảng', ['feature' => 'chatbot', 'stars' => [5]]);

// Tính năng sai
$throws('feature lạ', ['feature' => 'checkout', 'stars' => 5]);
$throws('feature thiếu', ['stars' => 5]);
$throws('feature không phải chuỗi', ['feature' => ['chatbot'], 'stars' => 5]);

// Góp ý: cắt 500 ký tự (đếm theo ký tự UTF-8), bỏ ký tự điều khiển
$long = str_repeat('ế', 700);
$c = FeatureRating::normalize(['feature' => 'chatbot', 'stars' => 3, 'comment' => $long])['comment'];
$eq('comment cắt 500 ký tự', mb_strlen($c, 'UTF-8'), 500);
$eq('comment bỏ ký tự điều khiển, giữ xuống dòng', FeatureRating::normalize(['feature' => 'chatbot', 'stars' => 3, 'comment' => "a\x00b\x07c\nd"])['comment'], "abc\nd");
$eq('comment không phải chuỗi -> rỗng', FeatureRating::normalize(['feature' => 'chatbot', 'stars' => 3, 'comment' => ['x']])['comment'], '');

// Context chỉ giữ page + rule_ids
$ctx = FeatureRating::normalize([
    'feature' => 'cart_conflict',
    'stars' => 5,
    'context' => [
        'page' => str_repeat('p', 100),
        'evil' => 'x',
        'rule_ids' => array_merge(['retinol_aha', 'retinol_aha', 123, ['x'], str_repeat('r', 90)], array_map(fn($i) => "r$i", range(1, 20))),
    ],
])['context'];
$eq('context chỉ có 2 khóa', array_keys($ctx), ['page', 'rule_ids']);
$eq('context page cắt 60', strlen($ctx['page']), 60);
$eq('rule_ids tối đa 10', count($ctx['rule_ids']), 10);
$eq('rule_ids bỏ trùng + không phải chuỗi', array_slice($ctx['rule_ids'], 0, 2), ['retinol_aha', str_repeat('r', 60)]);
$eq('context sai kiểu -> mặc định', FeatureRating::normalize(['feature' => 'chatbot', 'stars' => 2, 'context' => 'x'])['context'], ['page' => '', 'rule_ids' => []]);

// Thống kê
$st = FeatureRating::stats([5, 4, 3, 5]);
$eq('stats count', $st['count'], 4);
$eq('stats mean', $st['mean'], 4.25);
$eq('stats std mẫu', $st['std'], 0.96);
$eq('stats % >= 4', $st['pct_ge4'], 75.0);
$eq('stats phân bố', $st['distribution'], [1 => 0, 2 => 0, 3 => 1, 4 => 1, 5 => 2]);
$eq('stats rỗng', FeatureRating::stats([])['count'], 0);
$eq('stats n=1 std 0', FeatureRating::stats([3])['std'], 0.0);

// Ẩn danh + ngày theo giờ Việt Nam
$eq('userHash 12 hex', preg_match('/^[0-9a-f]{12}$/', FeatureRating::userHash('email:a@b.vn')), 1);
$eq('userHash ổn định', FeatureRating::userHash('x'), substr(hash('sha256', 'x'), 0, 12));
$eq('dayKey: 17:30 UTC = ngày kế tiếp ở VN', FeatureRating::dayKey(strtotime('2026-11-25 17:30:00 UTC')), '2026-11-26');
$eq('dayKey: 16:59 UTC vẫn cùng ngày', FeatureRating::dayKey(strtotime('2026-11-25 16:59:00 UTC')), '2026-11-25');

echo PHP_EOL . "Pass: $pass, Fail: $fail" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
