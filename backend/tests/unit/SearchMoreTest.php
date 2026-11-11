<?php
// Test thuần PHP (không DB, không mạng) cho khối "Gợi ý thêm" ở trang tìm kiếm.
// Chạy: php backend/tests/unit/SearchMoreTest.php
require_once __DIR__ . '/../../app/services/SearchMoreBuilder.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$prod = function ($id) { return ['ma_san_pham' => (string)$id, 'ten_san_pham' => 'SP ' . $id, 'reason_code' => 'x', 'reason_text' => 'y']; };
$prods = function (array $ids) use ($prod) { return array_map($prod, $ids); };
$ids = function (?array $r, int $s) { return array_column($r['sections'][$s]['items'], 'ma_san_pham'); };
$fb = [
    'degraded' => false, 'intent' => ['a' => 1], 'chips' => [['label' => 'c']],
    'sections' => [
        ['key' => 'semantic', 'title' => 'Có thể bạn đang tìm', 'items' => $prods([1, 2, 3])],
        ['key' => 'trending', 'title' => 'Được mua nhiều tuần này', 'items' => $prods([10, 11, 12, 13, 14, 15])],
        ['key' => 'best_sellers', 'title' => 'Sản phẩm bán chạy', 'items' => $prods([12, 20, 21])],
    ],
];

$r = SearchMoreBuilder::build(null, $fb, 'guest', []);
$keys = array_column($r['sections'], 'key');
$check('bỏ khối semantic', !in_array('semantic', $keys, true) && $keys === ['trending', 'best_sellers']);
$check('chips/intent bị xóa', $r['chips'] === [] && $r['intent'] === null);
$check('khử trùng giữa các khối', $ids($r, 1) === ['20', '21']);
$check('giới hạn mỗi khối', count($r['sections'][0]['items']) === 4);

$r = SearchMoreBuilder::build(null, $fb, 'guest', ['10', 11]);
$check('loại id đã có ở kết quả chính', $ids($r, 0) === ['12', '13', '14', '15']);

$r = SearchMoreBuilder::build(null, $fb, 'guest', [], 2);
$check('giới hạn tùy chỉnh', count($r['sections'][0]['items']) === 2);

$skin = $prods([30, 31, 32, 33, 34]);
$r = SearchMoreBuilder::build($skin, $fb, 'has_profile', []);
$check('personalized đứng đầu khi có hồ sơ da', $r['sections'][0]['key'] === 'personalized' && $r['sections'][0]['title'] === 'Hợp với làn da của bạn' && count($r['sections'][0]['items']) === 4);
$check('has_profile không có CTA', $r['skin_cta'] === null);
$r = SearchMoreBuilder::build($skin, $fb, 'guest', []);
$check('guest không có personalized', $r['sections'][0]['key'] === 'trending');
$r = SearchMoreBuilder::build($skin, $fb, 'no_profile', []);
$check('no_profile không có personalized', $r['sections'][0]['key'] === 'trending');
$r = SearchMoreBuilder::build($prods([30, 31]), $fb, 'has_profile', ['31']);
$check('personalized cần >= 2 sau khi loại trừ', $r['sections'][0]['key'] === 'trending');
$r = SearchMoreBuilder::build($prods([30, 31]), $fb, 'has_profile', []);
$check('personalized đúng 2 vẫn hiện', $r['sections'][0]['key'] === 'personalized');
$r = SearchMoreBuilder::build($prods([10, 30, 31]), $fb, 'has_profile', []);
$check('trending không lặp id 10 đã dùng ở personalized', !in_array('10', $ids($r, 1), true));

$check('skin_cta guest = login', SearchMoreBuilder::build(null, $fb, 'guest', [])['skin_cta'] === 'login');
$check('skin_cta no_profile = quiz', SearchMoreBuilder::build(null, $fb, 'no_profile', [])['skin_cta'] === 'quiz');
$check('skin_cta has_profile = null', SearchMoreBuilder::build(null, $fb, 'has_profile', [])['skin_cta'] === null);

$check('không có gì để hiện -> null', SearchMoreBuilder::build(null, null, 'has_profile', []) === null);
$check('chỉ còn semantic + has_profile -> null', SearchMoreBuilder::build(null, ['sections' => [['key' => 'semantic', 'title' => 't', 'items' => $prods([1])]]], 'has_profile', []) === null);
$r = SearchMoreBuilder::build(null, null, 'guest', []);
$check('chỉ CTA vẫn trả mảng', is_array($r) && $r['sections'] === [] && $r['skin_cta'] === 'login');

$r = SearchMoreBuilder::build(null, $fb + ['experiment' => ['id' => 'e1', 'variant' => 'v']], 'guest', []);
$check('chuyển tiếp experiment', $r['experiment'] === ['id' => 'e1', 'variant' => 'v']);
$check('không experiment -> không khóa', !array_key_exists('experiment', SearchMoreBuilder::build(null, $fb, 'guest', [])));
$r = SearchMoreBuilder::build(null, ['degraded' => true] + $fb, 'guest', []);
$check('chuyển tiếp degraded', $r['degraded'] === true);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
