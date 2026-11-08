<?php
// Test thuần PHP (không DB, Redis giả) cho autocomplete U04: token tiền tố, highlight XSS, lọc PII, từ điển, popular, cache.
// Chạy: php backend/tests/unit/SearchSuggestTest.php
require_once __DIR__ . '/../../app/models/SanPham.php';
require_once __DIR__ . '/../../app/services/SearchSuggestService.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

// ---------------------------------------------------------------- SanPham: regex tiền tố / đủ từ
$ref = new ReflectionClass('SanPham');
$obj = $ref->newInstanceWithoutConstructor();
$call = function (string $method, ...$args) use ($ref, $obj) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invoke($m->isStatic() ? null : $obj, ...$args);
};
$m = function (string $mode, string $tok, string $text) use ($call): bool {
    $r = $call('buildAccentInsensitiveRegex', $tok, $mode);
    return preg_match('/' . $r->getPattern() . '/iu', $text) === 1;
};
$check('prefix: "sua" khớp đầu từ "Sữa Rửa Mặt"', $m('prefix', 'sua', 'Sữa Rửa Mặt Torriden'));
$check('prefix: "rua" khớp đầu từ giữa chuỗi', $m('prefix', 'rua', 'Sữa Rửa Mặt Torriden'));
$check('prefix: "ua" KHÔNG khớp giữa từ', !$m('prefix', 'ua', 'Sữa Rửa Mặt Torriden'));
$check('prefix: "tor" khớp "Torriden"', $m('prefix', 'tor', 'Sữa rửa mặt Torriden'));
$check('prefix: "ridden" không khớp (không phải đầu từ)', !$m('prefix', 'rid', 'Torriden'));
$check('prefix: "toi" không khớp Torriden', !$m('prefix', 'toi', 'Torriden'));
$check('word: "sua" khớp đủ từ', $m('word', 'sua', 'Sữa rửa mặt'));
$check('word: "sua" KHÔNG khớp "Suaa"', !$m('word', 'sua', 'Suaa gel'));
$check('word: khớp từ đứng cuối chuỗi và trước dấu phẩy', $m('word', 'mat', 'Rửa mặt') && $m('word', 'mat', 'Rua mat, 100ml'));
$check('substr (mặc định) vẫn khớp giữa từ như Bước 01', $m('substr', 'ua', 'Sữa'));
$check('prefix: ký tự đặc biệt được escape', $m('prefix', 'c+', 'Vitamin C+ serum') && !$m('prefix', 'c+', 'Vitamin Cc'));

// ---------------------------------------------------------------- SanPham: filter ở chế độ gợi ý
$brandMap = new ReflectionProperty('SanPham', 'brandLookupMap');
$brandMap->setAccessible(true);
$brandMap->setValue(null, ['12' => 'Torriden', '13' => 'Topicrem', '14' => 'La Roche-Posay']);

$dbProp = new ReflectionProperty('SanPham', 'db');
$dbProp->setAccessible(true);
$dbProp->setValue($obj, new class { public $thuong_hieu; public function __construct() { $this->thuong_hieu = new class { public function find() { return []; } }; } });
$filter = $call('buildTokenSearchFilter', ['torriden', 'sua'], true, false);
$check('filter: AND giữa token', isset($filter['$and']) && count($filter['$and']) === 2);
$orFields = fn(array $branch) => array_map(fn($c) => array_key_first($c), $branch['$or']);
$check('filter: OR giữa trường (tên, mã, hãng text, danh mục, barcode, ma_thuong_hieu)', in_array('ten_san_pham', $orFields($filter['$and'][0]), true) && in_array('danh_muc_day_du', $orFields($filter['$and'][1]), true));
$p0 = $filter['$and'][0]['$or'][0]['ten_san_pham']->getPattern();
$p1 = $filter['$and'][1]['$or'][0]['ten_san_pham']->getPattern();
$check('filter: token trước = đủ từ, token cuối = tiền tố', str_ends_with($p0, '($|[\s,.;:)\/-])') && str_starts_with($p0, '(^|\s)') && str_starts_with($p1, '(^|\s)') && !str_contains($p1, '$|'));
$brandBranch = array_values(array_filter($filter['$and'][0]['$or'], fn($c) => isset($c['ma_thuong_hieu'])));
$check('filter: hãng tra qua ma_thuong_hieu từ map cache (không DB)', count($brandBranch) === 1 && in_array(12, $brandBranch[0]['ma_thuong_hieu']['$in'], true) && in_array('12', $brandBranch[0]['ma_thuong_hieu']['$in'], true) && !in_array(13, $brandBranch[0]['ma_thuong_hieu']['$in'], true));
$last = $call('buildTokenSearchFilter', ['to'], true, false);
$ids = array_values(array_filter($last['$and'][0]['$or'], fn($c) => isset($c['ma_thuong_hieu'])))[0]['ma_thuong_hieu']['$in'] ?? [];
$check('filter: token cuối "to" khớp tiền tố Torriden + Topicrem', in_array(12, $ids, true) && in_array(13, $ids, true) && !in_array(14, $ids, true));
$roche = $call('buildTokenSearchFilter', ['roche'], true, false);
$check('filter: tiền tố đầu từ giữa tên hãng ("roche" -> La Roche-Posay)', in_array(14, array_values(array_filter($roche['$and'][0]['$or'], fn($c) => isset($c['ma_thuong_hieu'])))[0]['ma_thuong_hieu']['$in'] ?? [], true));
$withIng = $call('buildTokenSearchFilter', ['retinol'], true, true);
$check('filter: includeIngredients thêm trường thành phần', in_array('thanh_phan', $orFields($withIng['$and'][0]), true));
$check('filter: mặc định không có trường thành phần', !in_array('thanh_phan', $orFields($call('buildTokenSearchFilter', ['retinol'], true, false)['$and'][0]), true));
$legacy = $call('buildTokenSearchFilter', ['sua']);
$check('filter: chế độ cũ (Bước 01) không đổi: chuỗi con, không tiền tố', !str_starts_with($legacy['$and'][0]['$or'][0]['ten_san_pham']->getPattern(), '(^|'));
$check('tokenize: token cuối dở dang vẫn được giữ', $call('tokenizeSearchQuery', 'Torriden sữ') === ['torriden', 'sữ']);

// ---------------------------------------------------------------- SanPham: xếp hạng
$items = [
    ['ten_san_pham' => 'Kem Dưỡng Sữa Ong Chúa', 'so_luong_da_ban' => 900, 'id' => 'c'],
    ['ten_san_pham' => 'Sữa Rửa Mặt B', 'so_luong_da_ban' => 10, 'id' => 'b'],
    ['ten_san_pham' => 'Sữa Rửa Mặt A', 'so_luong_da_ban' => 50, 'id' => 'a'],
    ['ten_san_pham' => 'Torriden Dive-In Serum', 'so_luong_da_ban' => 5000, 'id' => 'd'],
];
$ranked = SanPham::rankSuggestions($items, 'sua rua', 3);
$check('rank: tên bắt đầu bằng cụm gõ trước, rồi so_luong_da_ban', array_column($ranked, 'id') === ['a', 'b', 'd']);
$check('rank: cắt đúng limit', count(SanPham::rankSuggestions($items, 'sua', 2)) === 2);
$check('rank: tên bắt đầu cụm ("sua") lên trước dù bán ít', SanPham::rankSuggestions($items, 'sua', 4)[0]['id'] === 'a');

// ---------------------------------------------------------------- highlight an toàn XSS
$h = fn(string $t, string $q) => SearchSuggestService::highlight($t, $q);
$check('highlight: bọc phần khớp, không dấu', $h('Sữa Rửa Mặt', 'sua') === '<mark>Sữa</mark> Rửa Mặt');
$check('highlight: nhiều token + tiền tố', $h('Sữa Rửa Mặt Torriden', 'sua tor') === '<mark>Sữa</mark> Rửa Mặt <mark>Tor</mark>riden');
$check('highlight: chỉ đầu từ', $h('Sữa', 'ua') === 'Sữa');
$check('highlight: hoa/thường', $h('TORRIDEN', 'torr') === '<mark>TORR</mark>IDEN');
$xss = '<img src=x onerror=alert(1)>';
$out = $h($xss, 'img');
$check('highlight: XSS trong văn bản bị escape', !str_contains($out, '<img') && str_contains($out, '&lt;') && str_contains($out, '<mark>img</mark>'));
$out2 = $h('Tom & "Jerry" <script>alert(1)</script>', $xss);
$check('highlight: truy vấn độc hại không sinh thẻ', !preg_match('/<(?!\/?mark>)/', $out2) && !str_contains($out2, '<script'));
$check('highlight: ngoặc kép/nháy được escape (không thoát thuộc tính)', !str_contains($h('a" onmouseover="x', 'a'), '" onmouseover'));
$check('highlight: chuỗi rỗng / không token', $h('', 'a') === '' && $h('<b>', '') === '&lt;b&gt;');
$check('highlight: UTF-8 hỏng không làm hỏng', is_string($h("Sữa \xC3\x28", 'sua')));

// ---------------------------------------------------------------- lọc PII
foreach (['0912345678', '+84 912 345 678', '090.123.4567', 'lien he 0987 654 321', 'abc@gmail.com', 'http://evil.vn', 'www.shop', '<script>', 'a"b', str_repeat('a', 61), 'x', '1234 5678 9'] as $bad) {
    $check('PII/junk bị loại: ' . substr(json_encode($bad, JSON_UNESCAPED_UNICODE), 0, 40), SearchPopularity::normalize($bad) === null);
}
$check('normalize: chuẩn hóa chữ thường + khoảng trắng, giữ dấu', SearchPopularity::normalize('  Sữa   RỬA Mặt ') === 'sữa rửa mặt');
$check('normalize: SPF 50+, mã sản phẩm ngắn hợp lệ', SearchPopularity::normalize('SPF 50+') === 'spf 50+' && SearchPopularity::normalize('1496') === '1496');
$check('normalize: đúng 60 ký tự được giữ', SearchPopularity::normalize(str_repeat('a', 60)) === str_repeat('a', 60));

// ---------------------------------------------------------------- Redis giả
class FakeRedis extends RedisLite {
    public array $z = [];      // key => [member => score]
    public array $s = [];      // key => string
    public array $log = [];
    public bool $dead = false;
    public function __construct() { parent::__construct('redis://127.0.0.1:1'); }
    public function command(array $args) {
        $this->log[] = $args;
        if ($this->dead) { return null; }
        $c = strtoupper((string)$args[0]);
        switch ($c) {
            case 'GET': return $this->s[$args[1]] ?? null;
            case 'SETEX': $this->s[$args[1]] = $args[3]; return 'OK';
            case 'SET':
                if (in_array('NX', $args, true) && isset($this->s[$args[1]])) { return null; }
                $this->s[$args[1]] = $args[2];
                return 'OK';
            case 'DEL': $n = 0; foreach (array_slice($args, 1) as $k) { if (isset($this->s[$k])) { unset($this->s[$k]); $n++; } } return $n;
            case 'ZINCRBY': $this->z[$args[1]][$args[3]] = ($this->z[$args[1]][$args[3]] ?? 0) + (float)$args[2]; return (string)$this->z[$args[1]][$args[3]];
            case 'ZADD': $this->z[$args[1]][$args[3]] = (float)$args[2]; return 1;
            case 'ZCARD': return count($this->z[$args[1]] ?? []);
            case 'ZSCORE': return isset($this->z[$args[1]][$args[2]]) ? (string)$this->z[$args[1]][$args[2]] : null;
            case 'ZREMRANGEBYRANK': return 0;
            case 'ZRANGEBYLEX':
                $lo = substr($args[2], 1); $hi = substr($args[3], 1);
                $m = array_keys($this->z[$args[1]] ?? []);
                sort($m, SORT_STRING);
                $out = array_values(array_filter($m, fn($x) => strcmp($x, $lo) >= 0 && strcmp($x, $hi) <= 0));
                return array_slice($out, 0, (int)$args[6]);
        }
        return null;
    }
    public function pipeline(array $commands): ?array {
        if ($this->dead) { return null; }
        return array_map(fn($c) => $this->command($c), $commands);
    }
}

$r = new FakeRedis();
$check('record: result_count 0 -> không ghi', SearchPopularity::record('serum', 0, $r) === false && $r->z === []);
$check('record: PII không ghi', SearchPopularity::record('0912345678', 5, $r) === false && $r->z === []);
SearchPopularity::record('Sữa rửa mặt', 12, $r);
SearchPopularity::record('sữa rửa  mặt', 3, $r);
SearchPopularity::record('Sữa dưỡng thể', 3, $r);
SearchPopularity::record('Sữa dưỡng thể', 3, $r);
SearchPopularity::record('sữa dưỡng thể', 3, $r);
SearchPopularity::record('serum', 3, $r);
$check('record: ZINCRBY cộng dồn theo q_norm', ($r->z[SearchPopularity::POPULAR_KEY]['sữa rửa mặt'] ?? 0) == 2 && ($r->z[SearchPopularity::POPULAR_KEY]['sữa dưỡng thể'] ?? 0) == 3);
$check('record: lex index member = fold \x1f q_norm, điểm 0', array_key_exists("sua rua mat\x1fsữa rửa mặt", $r->z[SearchPopularity::PREFIX_KEY] ?? []));
$look = SearchPopularity::lookup('sua', 5, $r);
$check('lookup: tiền tố không dấu, nhiều lượt nhất trước', array_column($look, 'q') === ['sữa dưỡng thể', 'sữa rửa mặt']);
$check('lookup: "sữa rửa" cũng ra (có dấu)', array_column(SearchPopularity::lookup('sữa rửa', 5, $r), 'q') === ['sữa rửa mặt']);
$check('lookup: loại đúng bằng chuỗi đang gõ', array_column(SearchPopularity::lookup('serum', 5, $r), 'q') === []);
$check('lookup: limit', count(SearchPopularity::lookup('sua', 1, $r)) === 1);
$check('lookup: < 2 ký tự -> rỗng', SearchPopularity::lookup('s', 5, $r) === []);
$r->dead = true;
$check('Redis chết: record=false, lookup=[] (không ném lỗi)', SearchPopularity::record('serum', 3, $r) === false && SearchPopularity::lookup('sua', 5, $r) === []);

// ---------------------------------------------------------------- từ điển
$dictFile = sys_get_temp_dir() . '/u04_dict_' . getmypid() . '.json';
file_put_contents($dictFile, json_encode([
    'version' => 1,
    'brands' => [
        ['key' => 'torriden', 'name' => 'Torriden', 'fold' => 'torriden'],
        ['key' => 'topicrem', 'name' => 'Topicrem', 'fold' => 'topicrem'],
        ['key' => 'la_roche_posay', 'name' => 'La Roche-Posay', 'fold' => 'la roche-posay'],
        ['key' => 'cetaphil', 'name' => 'Cetaphil', 'fold' => 'cetaphil'],
    ],
    'categories' => [
        ['key' => 'sua_rua_mat', 'name' => 'Sữa Rửa Mặt', 'fold' => 'sua rua mat', 'aliases' => ['srm', 'cleanser', 'gel rua mat']],
        ['key' => 'toner', 'name' => 'Toner / Nước Cân Bằng Da', 'fold' => 'toner / nuoc can bang da', 'aliases' => ['nuoc hoa hong']],
        ['key' => 'mat_na', 'name' => 'Mặt Nạ', 'fold' => 'mat na', 'aliases' => ['mask']],
    ],
], JSON_UNESCAPED_UNICODE));
SearchDictionary::setPath($dictFile);
$check('dict: "tor" -> Torriden', array_column(SearchDictionary::matchBrands('tor'), 'name') === ['Torriden']);
$check('dict: "to" -> Topicrem, Torriden (tiền tố, không dấu)', array_column(SearchDictionary::matchBrands('To'), 'name') === ['Topicrem', 'Torriden']);
$check('dict: "toi" -> không có hãng', SearchDictionary::matchBrands('toi') === []);
$check('dict: tiền tố đầu từ giữa tên ("roche")', array_column(SearchDictionary::matchBrands('roche'), 'name') === ['La Roche-Posay']);
$check('dict: 1 ký tự -> rỗng', SearchDictionary::matchBrands('t') === []);
$check('dict: "sua rua" -> Sữa Rửa Mặt', array_column(SearchDictionary::matchCategories('sua rua'), 'name') === ['Sữa Rửa Mặt']);
$check('dict: synonym "srm" -> Sữa Rửa Mặt', array_column(SearchDictionary::matchCategories('srm'), 'name') === ['Sữa Rửa Mặt']);
$check('dict: "rua mat" (đầu từ giữa tên)', array_column(SearchDictionary::matchCategories('rua mat'), 'name') === ['Sữa Rửa Mặt']);
$check('dict: tên khớp xếp trước synonym', array_column(SearchDictionary::matchCategories('mat'), 'name') === ['Mặt Nạ', 'Sữa Rửa Mặt']);
SearchDictionary::setPath(sys_get_temp_dir() . '/u04_missing_' . getmypid() . '.json');
$check('dict: thiếu file -> rỗng, không lỗi', SearchDictionary::matchBrands('tor') === [] && SearchDictionary::matchCategories('sua') === []);
file_put_contents($dictFile, '{not json');
SearchDictionary::setPath($dictFile);
$check('dict: JSON hỏng -> rỗng, không lỗi', SearchDictionary::matchBrands('tor') === []);
file_put_contents($dictFile, json_encode(['brands' => [['key' => 'torriden', 'name' => 'Torriden', 'fold' => 'torriden']], 'categories' => []]));
SearchDictionary::setPath($dictFile);

// ---------------------------------------------------------------- service + cache
class FakeProducts {
    public int $calls = 0;
    public int $buildCalls = 0;
    public array $args = [];
    public function buildSuggestIndex(): array {
        $this->buildCalls++;
        return [
            ['id' => '1496', 'ma_san_pham' => 1496, 'ten_san_pham' => 'Sữa Rửa Mặt Torriden Tạo Bọt', 'thuong_hieu' => 'Torriden', 'loai_san_pham' => 'Sữa Rửa Mặt', 'danh_muc_day_du' => 'Chăm Sóc Da Mặt -> Làm Sạch Da -> Sữa Rửa Mặt', 'gia_ban' => 100, 'so_luong_da_ban' => 1, 'link_hinh_anh' => 'a.jpg|b.jpg|c.jpg', 'noise' => 'x'],
            ['id' => '747', 'ma_san_pham' => 747, 'ten_san_pham' => 'Sữa Rửa Mặt Simple', 'thuong_hieu' => 'Simple', 'loai_san_pham' => 'Sữa Rửa Mặt', 'gia_ban' => 90, 'so_luong_da_ban' => 130, 'link_hinh_anh' => ''],
            ['id' => '5', 'ma_san_pham' => 5, 'ten_san_pham' => 'Serum Vitamin C+', 'thuong_hieu' => 'Klairs', 'loai_san_pham' => 'Serum', 'gia_ban' => 50, 'so_luong_da_ban' => 76],
        ];
    }
    public function searchSuggestions(string $q, int $limit, bool $only, bool $ing = true): array {
        $this->calls++;
        $this->args = [$q, $limit, $only, $ing];
        return [['id' => '1496', 'ten_san_pham' => 'Torriden Sữa rửa mặt', 'thuong_hieu' => 'Torriden', 'gia_ban' => 100]];
    }
}
$fp = new FakeProducts();
$r2 = new FakeRedis();
$r2->z[SearchPopularity::POPULAR_KEY] = ['torriden serum' => 7.0];
$r2->z[SearchPopularity::PREFIX_KEY] = ["torriden serum\x1ftorriden serum" => 0.0];
$svc = new SearchSuggestService($fp, $r2);
$a = $svc->suggest('  Torriden  ');
$check('service: products giới hạn 5, chỉ hiển thị web, bỏ trường thành phần', $fp->args === ['Torriden', 5, true, false]);
$check('service: results có name_html đã highlight', $a['results'][0]['name_html'] === '<mark>Torriden</mark> Sữa rửa mặt' && $a['results'][0]['id'] === '1496');
$check('service: brands có Torriden + q để điều hướng', ($a['groups']['brands'][0]['name'] ?? '') === 'Torriden' && ($a['groups']['brands'][0]['q'] ?? '') === 'Torriden');
$check('service: queries phổ biến + q_html', ($a['groups']['queries'][0]['q'] ?? '') === 'torriden serum' && str_contains($a['groups']['queries'][0]['q_html'], '<mark>torriden</mark>') && $a['cached'] === false);
$b = $svc->suggest('torriden');
$check('service: lần 2 lấy từ cache Redis (không gọi lại model), cached=true', $fp->calls === 1 && $b['cached'] === true && $b['results'] === $a['results']);
$key = SearchSuggestService::cacheKey('torriden', 0);
$check('service: khóa cache search:suggest:v1:g<gen>:<sha1(fold)>', $key === 'search:suggest:v1:g0:' . sha1('torriden') && isset($r2->s[$key]));
$check('service: cache khóa theo chuỗi đã fold (khác hoa/dấu cùng khóa)', SearchSuggestService::cacheKey('TORRIDEN ', 0) === $key);
$ttlSet = array_values(array_filter($r2->log, fn($c) => $c[0] === 'SETEX'));
$check('service: TTL cache 60s', ($ttlSet[0][2] ?? '') == '60');
$r2->s['search:cachegen:v1'] = '1';
$svc->suggest('torriden');
$check('service: tăng generation (U03 publish_invalidate) -> cache cũ bị bỏ, tính lại', $fp->calls === 2);
$check('service: dưới 2 ký tự -> rỗng, không gọi model', $svc->suggest('t')['results'] === [] && $fp->calls === 2);
$dead = new FakeRedis();
$dead->dead = true;
$fp2 = new FakeProducts();
$noRedis = (new SearchSuggestService($fp2, $dead))->suggest('torriden');
$check('Redis chết: vẫn trả products/brands, queries rỗng, không cache', count($noRedis['results']) === 1 && $noRedis['groups']['brands'] !== [] && $noRedis['groups']['queries'] === [] && $noRedis['cached'] === false);
$check('xss: truy vấn <img onerror> không sinh thẻ trong *_html', (function () use ($fp2, $dead) {
    $out = (new SearchSuggestService($fp2, $dead))->suggest('<img src=x onerror=alert(1)>');
    $json = json_encode($out, JSON_UNESCAPED_UNICODE);
    return !preg_match('/<(?!\/?mark>)[a-z]/i', (string)($out['results'][0]['name_html'] ?? '')) && is_string($json);
})());


// ---------------------------------------------------------------- chỉ mục Redis (đường miss không chờ Atlas)
$dec = SearchSuggestIndex::decorate(['id' => '1496', 'ma_san_pham' => 1496, 'ten_san_pham' => 'Sữa Rửa Mặt', 'thuong_hieu' => 'La Roche-Posay', 'loai_san_pham' => 'Sữa Rửa Mặt', 'link_hinh_anh' => 'x.jpg|y.jpg', 'secret_field' => 'zzz', 'mo_ta' => 'long']);
$check('index.decorate: chỉ giữ trường công khai + trường tìm kiếm', !isset($dec['secret_field'], $dec['mo_ta']) && $dec['_n'] === 'sua rua mat' && $dec['_b'] === 'la roche posay' && $dec['_k'] === '1496');
$check('index.decorate: link_hinh_anh chỉ giữ ảnh đầu (nhẹ index)', $dec['link_hinh_anh'] === 'x.jpg');
$check('index.tokens: bỏ dấu, stopword, token cuối dở dang', SearchSuggestIndex::tokens('Sữa rửa MẶT cho tor') === ['sua', 'rua', 'mat', 'tor'] && SearchSuggestIndex::tokens('   ') === [] && SearchSuggestIndex::tokens('cho và') === ['cho va']);
$idx = array_map([SearchSuggestIndex::class, 'decorate'], (new FakeProducts())->buildSuggestIndex());
$ids = fn(string $q) => array_column(SearchSuggestIndex::match($idx, $q, 5), 'id');
$check('index.match: "torriden sua" -> 1496 (token cuối là tiền tố)', $ids('torriden sua') === ['1496']);
$check('index.match: "sua rua" -> 747 (bán chạy) rồi 1496', $ids('sua rua') === ['747', '1496']);
$check('index.match: hãng khớp ("tor" -> Torriden)', $ids('tor') === ['1496']);
$check('index.match: token trước phải đủ từ ("su" không là từ "sua" đủ)', $ids('su rua') === []);
$check('index.match: AND giữa token', $ids('simple torriden') === []);
$check('index.match: mã sản phẩm "1496" / tiền tố mã', $ids('1496') === ['1496'] && $ids('74') === ['747']);
$check('index.match: "vitamin c" khớp "C+"', $ids('vitamin c') === ['5']);
$check('index.match: không có -> []', $ids('xyzabc') === [] && $ids('') === []);
$check('index.match: kết quả không lộ trường _*', array_filter(array_keys(SearchSuggestIndex::match($idx, 'tor', 5)[0]), fn($k) => $k[0] === '_') === []);
$check('index.match: limit', count(SearchSuggestIndex::match($idx, 'sua', 1)) === 1);

$fpI = new FakeProducts();
$rI = new FakeRedis();
$builder = fn() => $fpI->buildSuggestIndex();
$check('index.items: chưa có -> null + xếp dựng nền', SearchSuggestIndex::items($rI, $builder) === null && $fpI->buildCalls === 0);
AfterResponse::run();
$check('index.items: dựng sau response, ghi Redis, không giữ khóa', $fpI->buildCalls === 1 && isset($rI->s[SearchSuggestIndex::KEY]) && !isset($rI->s[SearchSuggestIndex::LOCK_KEY]));
$check('index.items: còn mới -> trả luôn, không dựng lại', count(SearchSuggestIndex::items($rI, $builder) ?? []) === 3 && $fpI->buildCalls === 1);
$doc = json_decode($rI->s[SearchSuggestIndex::KEY], true);
$doc['built_at'] = time() - SearchSuggestIndex::SOFT_TTL - 5;
$rI->s[SearchSuggestIndex::KEY] = json_encode($doc);
$check('index.items: quá hạn mềm -> vẫn trả bản cũ (stale-while-revalidate) + dựng lại sau response', count(SearchSuggestIndex::items($rI, $builder) ?? []) === 3 && $fpI->buildCalls === 1);
AfterResponse::run();
$check('index.items: đã dựng lại', $fpI->buildCalls === 2 && (json_decode($rI->s[SearchSuggestIndex::KEY], true)['built_at'] ?? 0) >= time() - 2);
$rI->s['search:cachegen:v1'] = '7';
$check('index.items: đổi generation -> bỏ bản cũ (null), dựng lại với gen mới', SearchSuggestIndex::items($rI, $builder) === null);
AfterResponse::run();
$check('index.rebuild: ghi gen mới', (json_decode($rI->s[SearchSuggestIndex::KEY], true)['gen'] ?? -1) === 7);
$rI->s[SearchSuggestIndex::LOCK_KEY] = '1';
$check('index.rebuild: có khóa -> không dựng (chống dồn)', SearchSuggestIndex::rebuild($rI, $builder, 7) === false && $fpI->buildCalls === 3);
$check('index.items: Redis null -> null', SearchSuggestIndex::items(null, $builder) === null);

$fpS = new FakeProducts();
$rS = new FakeRedis();
$svcS = new SearchSuggestService($fpS, $rS);
$first = $svcS->suggest('torriden sua');
$check('service: chưa có chỉ mục -> đường Mongo, source=mongo', $svcS->lastSource() === 'mongo' && $fpS->calls === 1);
AfterResponse::run();
$second = $svcS->suggest('sua rua');
$check('service: có chỉ mục -> source=index, không gọi searchSuggestions (Mongo)', $svcS->lastSource() === 'index' && $fpS->calls === 1 && ($second['results'][0]['id'] ?? '') === '747' && str_contains($second['results'][0]['name_html'], '<mark>Sữa</mark>'));
$svcS->suggest('sua rua');
$check('service: lặp lại -> source=cache', $svcS->lastSource() === 'cache');

@unlink($dictFile);
echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
