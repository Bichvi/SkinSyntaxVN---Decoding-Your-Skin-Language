<?php
// Test thuần PHP (không DB, Redis giả) cho HomeDiscovery::trendingTerms/data và SearchPopularity::top.
// Chạy: php backend/tests/unit/HomeDiscoveryTest.php
require_once __DIR__ . '/../../app/services/HomeDiscovery.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

class TopFakeRedis extends RedisLite {
    public $reply = [];
    public bool $dead = false;
    public array $log = [];
    public function __construct() { parent::__construct('redis://127.0.0.1:1'); }
    public function command(array $args) {
        $this->log[] = $args;
        return $this->dead ? null : $this->reply;
    }
}

$curated = ['kem chống nắng', 'serum', 'sữa rửa mặt', 'retinol', 'trị mụn', 'dưỡng ẩm', 'tẩy trang', 'niacinamide'];

// ---------------------------------------------------------------- trendingTerms
$t = HomeDiscovery::trendingTerms([['q' => 'toner', 'score' => 9], ['q' => 'Retinol', 'score' => 5]], $curated);
$check('trendingTerms: từ Redis đứng trước', $t[0] === 'toner' && $t[1] === 'Retinol');
$check('trendingTerms: loại trùng theo fold (retinol soạn sẵn bị bỏ)', count(array_filter($t, fn($x) => SearchSkinProfile::fold($x) === 'retinol')) === 1);
$check('trendingTerms: đủ tối đa 8', count($t) === 8);
$t2 = HomeDiscovery::trendingTerms([['q' => 'Kem Chống Nắng']], $curated);
$check('trendingTerms: trùng khác hoa/thường bị loại', count(array_filter($t2, fn($x) => SearchSkinProfile::fold($x) === 'kem chong nang')) === 1);
$many = array_map(fn($i) => ['q' => "tu khoa $i", 'score' => 10], range(1, 9));
$t3 = HomeDiscovery::trendingTerms($many, $curated);
$check('trendingTerms: tối đa 5 từ Redis', count(array_filter($t3, fn($x) => str_starts_with($x, 'tu khoa'))) === 5 && count($t3) === 8);
$check('trendingTerms: Redis rỗng -> dùng soạn sẵn', HomeDiscovery::trendingTerms([], $curated) === $curated);
$check('trendingTerms: $max nhỏ hơn được tôn trọng', count(HomeDiscovery::trendingTerms([], $curated, 3)) === 3);
$check('trendingTerms: bỏ chuỗi rỗng', HomeDiscovery::trendingTerms([['q' => '  ']], ['a']) === ['a']);
$check('trendingTerms: không escape (view lo)', HomeDiscovery::trendingTerms([['q' => 'a & b']], []) === ['a & b']);

// ---------------------------------------------------------------- SearchPopularity::top
$r = new TopFakeRedis();
$r->reply = [
    'kem chống nắng', '40',
    '0912345678', '30',        // PII (số điện thoại) -> loại
    'serum', '12',
    'a@b.com', '10',           // PII (email) -> loại
    'ít người tìm', '1',       // score thấp -> loại
    'Retinol  ', '5',          // chuẩn hóa thành "retinol"
    'tẩy trang', '3',
];
$top = SearchPopularity::top(3, $r);
$check('top: lọc PII + điểm thấp, giữ thứ tự', array_column($top, 'q') === ['kem chống nắng', 'serum', 'retinol']);
$check('top: score là int', $top[0]['score'] === 40);
$check('top: tôn trọng $limit', count(SearchPopularity::top(2, $r)) === 2);
$check('top: gọi ZREVRANGE WITHSCORES lấy dư x3', $r->log[0] === ['ZREVRANGE', SearchPopularity::POPULAR_KEY, '0', '8', 'WITHSCORES']);
$check('top: không có phần tử score >= 2 -> []', (function () {
    $x = new TopFakeRedis();
    $x->reply = ['abc', '1'];
    return SearchPopularity::top(5, $x) === [];
})());
$dead = new TopFakeRedis();
$dead->dead = true;
$check('top: Redis lỗi -> []', SearchPopularity::top(5, $dead) === []);
$dead->reply = 'khong phai mang';
$dead->dead = false;
$check('top: reply không phải mảng -> []', SearchPopularity::top(5, $dead) === []);

// ---------------------------------------------------------------- data()
$d = HomeDiscovery::data($r);
$check('data: trending có từ Redis + bù soạn sẵn đủ 8', count($d['trending']) === 8 && $d['trending'][0] === 'kem chống nắng');
$check('data: 8 hoạt chất soạn sẵn có q/name/desc', count($d['actives']) === 8 && isset($d['actives'][0]['q'], $d['actives'][0]['name'], $d['actives'][0]['desc']));
$d2 = HomeDiscovery::data($dead2 = (function () { $x = new TopFakeRedis(); $x->dead = true; return $x; })());
$check('data: Redis lỗi -> vẫn đủ 8 từ soạn sẵn', count($d2['trending']) === 8);

echo PHP_EOL . "Tổng: $pass PASS, $fail FAIL" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
