<?php
// Test thuần PHP (không DB, không mạng) cho Smart Search Bước 08: sự kiện search_events + hồ sơ da.
// Chạy: php backend/tests/unit/SearchEventTest.php
require_once __DIR__ . '/../../app/services/SearchSkinProfile.php';
require_once __DIR__ . '/../../app/services/SearchEventTracker.php';
require_once __DIR__ . '/../../app/services/RedisLite.php';
require_once __DIR__ . '/../../app/services/RateLimiter.php';

// Phiên CLI giả (phải mở trước khi in ra gì): handle() đọc session id + ghi bucket rate limit.
ini_set('session.use_cookies', '0');
ini_set('session.save_path', sys_get_temp_dir());
session_id('unittestsession' . getmypid());
session_start();
$_SESSION = [];

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

// ---------- validate ----------
$ok = SearchEventTracker::validate(['q' => '  Sữa  Rửa MẶT Torriden ', 'events' => [
    ['action' => 'impression', 'section' => 'semantic'],
    ['action' => 'click', 'section' => 'category_alt', 'product_id' => '1496'],
    ['action' => 'add_to_cart', 'section' => 'best_sellers', 'product_id' => 18],
    ['action' => 'chip_click', 'section' => 'chips', 'chip' => 'best_sellers'],
]]);
$check('validate: payload hợp lệ', is_array($ok) && count($ok['events']) === 4);
$check('validate: q_norm bỏ dấu, lower, gộp khoảng trắng', $ok['q_norm'] === 'sua rua mat torriden');
$check('validate: impression product_id rỗng', $ok['events'][0] === ['action' => 'impression', 'section' => 'semantic', 'product_id' => '']);
$check('validate: id số -> chuỗi', $ok['events'][2]['product_id'] === '18');
$check('validate: chip_click giữ chip kind', $ok['events'][3] === ['action' => 'chip_click', 'section' => 'chips', 'product_id' => '', 'chip' => 'best_sellers']);
$check('validate: q_norm cắt 100 ký tự', mb_strlen(SearchEventTracker::validate(['q' => str_repeat('ă', 150), 'events' => [['action' => 'impression', 'section' => 'trending']]])['q_norm'], 'UTF-8') === 100);

$bad = [
    'không phải mảng' => 'x',
    'thiếu q' => ['events' => [['action' => 'impression', 'section' => 'trending']]],
    'q rỗng' => ['q' => '   ', 'events' => [['action' => 'impression', 'section' => 'trending']]],
    'q > 200' => ['q' => str_repeat('a', 201), 'events' => [['action' => 'impression', 'section' => 'trending']]],
    'q ký tự điều khiển' => ['q' => "a\x00b", 'events' => [['action' => 'impression', 'section' => 'trending']]],
    'events rỗng' => ['q' => 'a', 'events' => []],
    'events > 10' => ['q' => 'a', 'events' => array_fill(0, 11, ['action' => 'impression', 'section' => 'trending'])],
    'events là object' => ['q' => 'a', 'events' => ['x' => ['action' => 'impression', 'section' => 'trending']]],
    'action lạ' => ['q' => 'a', 'events' => [['action' => 'purchase', 'section' => 'trending', 'product_id' => '1']]],
    'section lạ' => ['q' => 'a', 'events' => [['action' => 'click', 'section' => 'evil', 'product_id' => '1']]],
    'click thiếu id' => ['q' => 'a', 'events' => [['action' => 'click', 'section' => 'semantic']]],
    'click id sai' => ['q' => 'a', 'events' => [['action' => 'click', 'section' => 'semantic', 'product_id' => '1; drop']]],
    'click id dài' => ['q' => 'a', 'events' => [['action' => 'click', 'section' => 'semantic', 'product_id' => str_repeat('1', 33)]]],
    'impression có id' => ['q' => 'a', 'events' => [['action' => 'impression', 'section' => 'semantic', 'product_id' => '1']]],
    'impression section chips' => ['q' => 'a', 'events' => [['action' => 'impression', 'section' => 'chips']]],
    'chip kind lạ' => ['q' => 'a', 'events' => [['action' => 'chip_click', 'section' => 'chips', 'chip' => 'x']]],
    'chip section sai' => ['q' => 'a', 'events' => [['action' => 'chip_click', 'section' => 'semantic', 'chip' => 'category']]],
    'id kiểu mảng' => ['q' => 'a', 'events' => [['action' => 'click', 'section' => 'semantic', 'product_id' => ['1']]]],
    '1 event sai -> cả lô' => ['q' => 'a', 'events' => [['action' => 'impression', 'section' => 'trending'], 'junk']],
];
foreach ($bad as $name => $payload) {
    $check('validate: từ chối ' . $name, SearchEventTracker::validate($payload) === null);
}

// ---------- rate limit ----------
[$a1, $b1] = SearchEventTracker::rateLimitAllows(null, 10, 1000);
[$a2, $b2] = SearchEventTracker::rateLimitAllows($b1, 50, 1010);
[$a3, $b3] = SearchEventTracker::rateLimitAllows($b2, 1, 1020);
[$a4, $b4] = SearchEventTracker::rateLimitAllows($b3, 1, 1000 + SearchEventTracker::RATE_WINDOW);
$check('rate: trong hạn mức', $a1 && $a2 && $b2['count'] === 60);
$check('rate: vượt 60 sự kiện/phút -> chặn, không cộng', !$a3 && $b3['count'] === 60);
$check('rate: cửa sổ mới -> cho lại', $a4 && $b4 === ['start' => 1060, 'count' => 1]);

// ---------- buildDocs: đúng shape, không PII ----------
$docs = SearchEventTracker::buildDocs($ok, null, str_repeat('a', 64), 'TS');
$check('docs: 1 doc / event', count($docs) === 4);
$check('docs: shape chuẩn', array_keys($docs[1]) === ['ts', 'q_norm', 'section', 'product_id', 'action', 'user_hash', 'session_hash']
    && $docs[1]['product_id'] === '1496' && $docs[1]['user_hash'] === null && $docs[1]['ts'] === 'TS');
$check('docs: chip chỉ ở chip_click', isset($docs[3]['chip']) && !isset($docs[0]['chip']));
$check('docs: không có email/ip/skin', !preg_grep('/^(email|ip|ip_address|skin_profile|skin_type|concerns|user_agent|ho_ten)$/i', array_keys(array_merge(...$docs))));

// ---------- csrf ----------
$t = SearchEventTracker::csrfToken('sess-1');
$check('csrf: token ổn định theo session, 64 hex', $t === SearchEventTracker::csrfToken('sess-1') && preg_match('/^[a-f0-9]{64}$/', $t) === 1);
$check('csrf: khác session -> khác token', $t !== SearchEventTracker::csrfToken('sess-2'));
$check('csrf: check đúng/sai', SearchEventTracker::checkCsrf($t, 'sess-1') && !SearchEventTracker::checkCsrf($t, 'sess-2') && !SearchEventTracker::checkCsrf(null, 'sess-1'));
$check('csrf: không có session -> rỗng', SearchEventTracker::csrfToken('') === '');

// ---------- handle (DB giả) ----------
$fakeDb = new class {
    public array $inserted = [];
    public function __get($name) {
        $outer = $this;
        return new class($outer, $name) {
            public function __construct(private $outer, private $name) {}
            public function insertMany(array $docs, array $opts = []) { $this->outer->inserted[$this->name] = array_merge($this->outer->inserted[$this->name] ?? [], $docs); }
        };
    }
};
$csrf = SearchEventTracker::csrfToken();
$body = json_encode(['q' => 'xyzabc', 'csrf' => $csrf, 'events' => [['action' => 'impression', 'section' => 'trending']]]);
$check('handle: GET -> 405', SearchEventTracker::handle($fakeDb, 'GET', $body) === 405);
$check('handle: body quá lớn -> 413', SearchEventTracker::handle($fakeDb, 'POST', str_repeat('x', SearchEventTracker::MAX_BODY + 1)) === 413);
$check('handle: JSON hỏng -> 400', SearchEventTracker::handle($fakeDb, 'POST', '{bad') === 400);
$check('handle: sai csrf -> 403', SearchEventTracker::handle($fakeDb, 'POST', json_encode(['q' => 'a', 'csrf' => 'x', 'events' => []])) === 403);
$check('handle: payload sai -> 400', SearchEventTracker::handle($fakeDb, 'POST', json_encode(['q' => 'a', 'csrf' => $csrf, 'events' => [['action' => 'x', 'section' => 'trending']]])) === 400);
$check('handle: hợp lệ -> 204 + ghi search_events', SearchEventTracker::handle($fakeDb, 'POST', $body, 5000) === 204
    && count($fakeDb->inserted['search_events'] ?? []) === 1 && $fakeDb->inserted['search_events'][0]['q_norm'] === 'xyzabc'
    && preg_match('/^[a-f0-9]{64}$/', $fakeDb->inserted['search_events'][0]['session_hash']) === 1);
$many = json_encode(['q' => 'xyzabc', 'csrf' => $csrf, 'events' => array_fill(0, 10, ['action' => 'impression', 'section' => 'trending'])]);
$codes = [];
for ($i = 0; $i < 7; $i++) { $codes[] = SearchEventTracker::handle($fakeDb, 'POST', $many, 5001); }
$check('handle: vượt rate -> 429', $codes === [204, 204, 204, 204, 204, 429, 429] && count($fakeDb->inserted['search_events']) === 51);

// ---------- U03: Redis Stream + rate limit Redis (client giả) ----------
class FakeRedisLite extends RedisLite {
    public array $cmds = [];
    /** @var mixed callable|array|null */
    public $pipelineReply = null;
    public $evalReply = null;
    public function __construct() { parent::__construct('redis://127.0.0.1:1'); }
    public function pipeline(array $commands): ?array {
        $this->cmds = array_merge($this->cmds, $commands);
        return is_callable($this->pipelineReply) ? ($this->pipelineReply)($commands) : $this->pipelineReply;
    }
    public function eval(string $script, array $keys = [], array $args = []) { return $this->evalReply; }
}
$fake = new FakeRedisLite();
RedisLite::setShared($fake);
RateLimiter::setShared(new RateLimiter($fake));
$one = json_encode(['q' => 'xyzabc', 'csrf' => $csrf, 'events' => [['action' => 'click', 'section' => 'semantic', 'product_id' => '1496']]]);
$two = json_encode(['q' => 'xyzabc', 'csrf' => $csrf, 'events' => [['action' => 'impression', 'section' => 'trending'], ['action' => 'click', 'section' => 'semantic', 'product_id' => '18']]]);
$before = count($fakeDb->inserted['search_events']);

$fake->evalReply = [1, 60];
$fake->pipelineReply = fn(array $c) => array_map(fn($i) => "1700000000000-$i", array_keys($c));
$check('stream: handle -> 204 và KHÔNG ghi Atlas trực tiếp', SearchEventTracker::handle($fakeDb, 'POST', $one, 7000) === 204 && count($fakeDb->inserted['search_events']) === $before);
$cmd = $fake->cmds[0] ?? [];
$check('stream: lệnh XADD search:events:v1 MAXLEN ~ 100000', array_slice($cmd, 0, 6) === ['XADD', 'search:events:v1', 'MAXLEN', '~', '100000', '*']);
$kv = array_combine(array_filter(array_slice($cmd, 6), fn($k) => $k % 2 === 0, ARRAY_FILTER_USE_KEY), array_filter(array_slice($cmd, 6), fn($k) => $k % 2 === 1, ARRAY_FILTER_USE_KEY));
$check('stream: fields = ts_ms,q_norm,section,product_id,action,user_hash,session_hash', array_keys($kv) === ['ts_ms', 'q_norm', 'section', 'product_id', 'action', 'user_hash', 'session_hash']
    && $kv['q_norm'] === 'xyzabc' && $kv['product_id'] === '1496' && $kv['user_hash'] === '' && ctype_digit($kv['ts_ms']) && strlen($kv['ts_ms']) === 13);

$fake->pipelineReply = null; // Redis mất kết nối
$check('stream: Redis lỗi -> ghi Atlas trực tiếp (fallback)', SearchEventTracker::handle($fakeDb, 'POST', $one, 7001) === 204 && count($fakeDb->inserted['search_events']) === $before + 1);

$fake->pipelineReply = fn(array $c) => ['1700000000000-0', new RedisLiteReplyError('OOM')];
$check('stream: sự kiện bị Redis từ chối -> chỉ sự kiện đó ghi Atlas', SearchEventTracker::handle($fakeDb, 'POST', $two, 7002) === 204
    && count($fakeDb->inserted['search_events']) === $before + 2 && end($fakeDb->inserted['search_events'])['product_id'] === '18');

$fake->cmds = [];
$fake->evalReply = [61, 33]; // vượt 60 sự kiện/phút
$check('rate redis: vượt ngưỡng -> 429 và không XADD', SearchEventTracker::handle($fakeDb, 'POST', $one, 7003) === 429 && $fake->cmds === []);

$fake->evalReply = null; // limiter lỗi -> bucket session (fail-open có giới hạn)
$check('rate redis: limiter lỗi -> dùng bucket session, vẫn 204', SearchEventTracker::handle($fakeDb, 'POST', $one, 7004 + 10000) === 204);
RedisLite::setShared(null);
RateLimiter::setShared(null);

// ---------- U12: product_ids / position / experiment (tùy chọn, tương thích ngược) ----------
$u12 = SearchEventTracker::validate(['q' => 'toner', 'experiment' => 'graph_weight_v1', 'variant' => 'variant', 'events' => [
    ['action' => 'impression', 'section' => 'semantic', 'product_ids' => ['1496', 18, 'ab-1']],
    ['action' => 'click', 'section' => 'semantic', 'product_id' => '1496', 'position' => 2],
    ['action' => 'add_to_cart', 'section' => 'semantic', 'product_id' => '18'],
]]);
$check('u12 validate: nhận product_ids/position/experiment', is_array($u12) && $u12['experiment'] === 'graph_weight_v1' && $u12['variant'] === 'variant'
    && $u12['events'][0]['product_ids'] === ['1496', '18', 'ab-1'] && $u12['events'][1]['position'] === 2 && !isset($u12['events'][2]['position']));
$u12docs = SearchEventTracker::buildDocs($u12, null, 'sess', 'TS');
$check('u12 buildDocs: impression có product_ids, click có position, mọi doc có experiment+variant',
    $u12docs[0]['product_ids'] === ['1496', '18', 'ab-1'] && $u12docs[1]['position'] === 2 && !isset($u12docs[1]['product_ids'])
    && $u12docs[2]['experiment'] === 'graph_weight_v1' && $u12docs[2]['variant'] === 'variant');
$u12f = SearchEventTracker::streamFields($u12docs[0], 1000);
$check('u12 streamFields: product_ids nối dấu phẩy, experiment/variant là chuỗi', $u12f['product_ids'] === '1496,18,ab-1' && $u12f['experiment'] === 'graph_weight_v1' && $u12f['variant'] === 'variant' && !isset($u12f['position']));
$check('u12 streamFields: position là chuỗi', SearchEventTracker::streamFields($u12docs[1], 1000)['position'] === '2');
$plain = SearchEventTracker::buildDocs(SearchEventTracker::validate(['q' => 'toner', 'events' => [['action' => 'impression', 'section' => 'semantic']]]), null, 's', 'T');
$check('u12 tương thích ngược: payload cũ -> doc không có trường mới', array_keys($plain[0]) === ['ts', 'q_norm', 'section', 'product_id', 'action', 'user_hash', 'session_hash']);
$u12bad = [
    'position 0' => [['action' => 'click', 'section' => 'semantic', 'product_id' => '1', 'position' => 0]],
    'position 51' => [['action' => 'click', 'section' => 'semantic', 'product_id' => '1', 'position' => 51]],
    'position chuỗi' => [['action' => 'click', 'section' => 'semantic', 'product_id' => '1', 'position' => '2']],
    'product_ids rỗng' => [['action' => 'impression', 'section' => 'semantic', 'product_ids' => []]],
    'product_ids 21 phần tử' => [['action' => 'impression', 'section' => 'semantic', 'product_ids' => array_map('strval', range(1, 21))]],
    'product_ids id sai' => [['action' => 'impression', 'section' => 'semantic', 'product_ids' => ['1; DROP']]],
    'product_ids không phải mảng' => [['action' => 'impression', 'section' => 'semantic', 'product_ids' => '1,2']],
];
foreach ($u12bad as $name => $events) {
    $check("u12 validate: từ chối $name", SearchEventTracker::validate(['q' => 'toner', 'events' => $events]) === null);
}
$evImp = [['action' => 'impression', 'section' => 'semantic']];
foreach (['experiment sai mẫu' => ['experiment' => 'Bad ID', 'variant' => 'variant'], 'variant sai mẫu' => ['experiment' => 'ok', 'variant' => 'x y'],
          'thiếu variant' => ['experiment' => 'ok'], 'không phải chuỗi' => ['experiment' => ['a'], 'variant' => 'v']] as $name => $extra) {
    $check("u12 validate: từ chối $name", SearchEventTracker::validate(['q' => 'toner', 'events' => $evImp] + $extra) === null);
}

// ---------- hồ sơ da ----------
$p = SearchSkinProfile::fromCustomer([
    'ho_ten' => 'X', 'email' => 'x@example.com', 'ngan_sach' => 500000,
    'tinh_trang_dac_biet' => 'loaida:8 | Khảo sát đăng nhập | Thương hiệu ưu tiên: Cocoon',
    'van_de_da' => 'Thâm mụn, sạm nám, tàn nhang, Mụn viêm, sưng đỏ, Lỗ chân lông to, Da khô căng, bong tróc, Lão hóa, nếp nhăn, Mụn ẩn, mụn đầu đen, xỉn màu, bí ẩn',
]);
$check('skin: chỉ skin_type + concerns (không tên/email/ngân sách)', array_keys($p) === ['skin_type', 'concerns']);
$check('skin: loaida:8 -> da_dau', $p['skin_type'] === 'da_dau');
$check('skin: concerns map key chuẩn, bỏ trùng, bỏ giá trị lạ', $p['concerns'] === ['tham_mun', 'nam_tan_nhang', 'mun', 'kich_ung', 'lo_chan_long', 'kho_da', 'lao_hoa', 'xin_mau']);
$codesMap = [];
foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 99] as $code) { $codesMap[$code] = SearchSkinProfile::skinTypeFromField('loaida:' . $code); }
$check('skin: bảng mã loại da (4 Unknown/99 lạ -> null)', $codesMap === [1 => 'da_dau', 2 => 'moi_loai_da', 3 => 'da_nhay_cam', 4 => null, 5 => 'da_kho', 6 => 'da_mun', 7 => 'da_kho', 8 => 'da_dau', 9 => 'da_dau', 10 => 'da_kho', 99 => null]);
$check('skin: mọi key nằm trong tập canonical', in_array($p['skin_type'], SearchSkinProfile::SKIN_TYPES, true) && !array_diff($p['concerns'], SearchSkinProfile::CONCERNS));
$check('skin: không có gì -> null', SearchSkinProfile::fromCustomer(['tinh_trang_dac_biet' => null, 'van_de_da' => '']) === null && SearchSkinProfile::fromCustomer(null) === null);
$check('skin: chỉ concerns, loại da Unknown', SearchSkinProfile::fromCustomer(['tinh_trang_dac_biet' => 'loaida:4', 'van_de_da' => 'Lão hóa']) === ['skin_type' => null, 'concerns' => ['lao_hoa']]);
$check('skin: nhận key canonical trực tiếp', SearchSkinProfile::concernsFromField(['dau_thua', 'mun']) === ['dau_thua', 'mun']);
$check('fold: bỏ dấu tiếng Việt', SearchSkinProfile::fold('  ĐẶC TRỊ   Mụn ') === 'dac tri mun');

session_destroy();
echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
