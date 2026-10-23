<?php
// Test thuần PHP (Mongo/Redis giả, không chạm DB thật) cho nhãn công dụng U07.
// Chạy: php backend/tests/unit/TagCurationTest.php
require_once __DIR__ . '/../../app/services/RedisLite.php';
require_once __DIR__ . '/../../app/services/TagCatalog.php';
require_once __DIR__ . '/../../app/services/TagCuration.php';
require_once __DIR__ . '/../../app/models/TagSuggestion.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

// ---- TagCatalog ----------------------------------------------------------------------------------------------
$groups = TagCatalog::groups();
$check('catalog: nạp 3 nhóm từ tag_keys.json', isset($groups['benefits'], $groups['concerns'], $groups['skin_types']) && count($groups['benefits']) >= 10);
$check('catalog: nhãn tiếng Việt', TagCatalog::name('benefits', 'cap_am') === 'Cấp ẩm' && TagCatalog::name('skin_types', 'da_dau') === 'Da dầu');
$v = TagCatalog::validate('benefits', ['cap_am', 'lam_diu', 'cap_am', 'bay_len_troi', '', "'; DROP", 5]);
$check('validate: lọc key lạ, bỏ trùng, giữ thứ tự', $v['valid'] === ['cap_am', 'lam_diu'] && count($v['rejected']) === 3);
$check('validate: key lạ bị cắt <= 64 ký tự', strlen(TagCatalog::validate('benefits', [str_repeat('x', 500)])['rejected'][0]) === 64);
$check('validate: chuỗi đơn, null, mảng lồng, nhóm lạ', TagCatalog::validate('benefits', 'cap_am')['valid'] === ['cap_am']
    && TagCatalog::validate('benefits', null)['valid'] === []
    && TagCatalog::validate('benefits', [['cap_am']])['rejected'] === ['(không phải chuỗi)']
    && TagCatalog::validate('khong_co', ['cap_am'])['valid'] === []);
$check('validate: key của nhóm khác không hợp lệ', TagCatalog::validate('benefits', ['mun'])['valid'] === [] && TagCatalog::validate('concerns', ['mun'])['valid'] === ['mun']);
TagCatalog::setPath(__DIR__ . '/khong_ton_tai.json');
$check('catalog: file thiếu => rỗng, mọi key bị từ chối', TagCatalog::validate('benefits', ['cap_am'])['valid'] === [] && TagCatalog::groups()['benefits'] === []);
$tmp = tempnam(sys_get_temp_dir(), 'tk');
file_put_contents($tmp, '{ hỏng');
TagCatalog::setPath($tmp);
$check('catalog: JSON hỏng => rỗng, không lỗi', TagCatalog::groups()['benefits'] === []);
file_put_contents($tmp, json_encode(['groups' => ['benefits' => [['key' => 'ok_1', 'name' => 'Tốt'], ['key' => 'Bad Key!'], ['key' => 'x', 'name' => '']], 'khac' => [['key' => 'z']]]]));
TagCatalog::setPath($tmp);
$check('catalog: bỏ key sai định dạng, nhóm lạ; tên rỗng => dùng key', array_keys(TagCatalog::groups()['benefits']) === ['ok_1', 'x'] && TagCatalog::name('benefits', 'x') === 'x' && !isset(TagCatalog::groups()['khac']));
@unlink($tmp);
TagCatalog::setPath(null);

// ---- Chọn nhãn từ form -----------------------------------------------------------------------------------------
$sel = TagCuration::selectionFromInput(['cong_dung_keys' => ['cap_am', 'hack'], 'van_de_da_keys' => ['mun'], 'loai_da_keys' => 'da_kho']);
$check('selection: 3 nhóm theo tên trường Mongo', $sel['selection'] === ['benefits' => ['cap_am'], 'concerns' => ['mun'], 'skin_types' => ['da_kho']]);
$check('selection: key lạ được báo kèm tên trường', $sel['rejected'] === ['cong_dung_keys:hack']);
$check('selection: input rỗng => 3 mảng rỗng, không lỗi', TagCuration::selectionFromInput([])['selection'] === ['benefits' => [], 'concerns' => [], 'skin_types' => []]);

// ---- $set cho san_pham -----------------------------------------------------------------------------------------
$set = TagCuration::productTagSet($sel['selection'], 'manual', 'adm_abc', 'NOW');
$check('productTagSet: đúng 6 trường nhãn', array_keys($set) === ['cong_dung_keys', 'van_de_da_keys', 'loai_da_keys', 'tag_source', 'tag_updated_at', 'tag_updated_by']);
$check('productTagSet: KHÔNG chạm updated_at và trường khác', !isset($set['updated_at']) && !isset($set['ten_san_pham']));
$check('productTagSet: giá trị', $set['cong_dung_keys'] === ['cap_am'] && $set['tag_source'] === 'manual' && $set['tag_updated_at'] === 'NOW' && $set['tag_updated_by'] === 'adm_abc');
$set2 = TagCuration::productTagSet(['benefits' => ['cap_am', 'hack', 'cap_am']], 'llm_approved', 'a', 'N');
$check('productTagSet: lọc lại key lạ ở tầng ghi + trường thiếu => mảng rỗng', $set2['cong_dung_keys'] === ['cap_am'] && $set2['van_de_da_keys'] === [] && $set2['loai_da_keys'] === []);
$threw = false;
try { TagCuration::productTagSet([], 'rule', 'a', 'N'); } catch (InvalidArgumentException $e) { $threw = true; }
$check('productTagSet: tag_source ngoài manual|llm_approved bị từ chối', $threw);

// ---- CSRF -------------------------------------------------------------------------------------------------------
$session = [];
$tok = TagCuration::csrfToken($session);
$check('csrf: token 64 hex, lưu trong phiên, ổn định', preg_match('/^[a-f0-9]{64}$/', $tok) === 1 && TagCuration::csrfToken($session) === $tok);
$check('csrf: đúng token => hợp lệ', TagCuration::csrfValid($session, $tok) === true);
$check('csrf: thiếu token gửi lên => từ chối', TagCuration::csrfValid($session, null) === false && TagCuration::csrfValid($session, '') === false);
$check('csrf: sai token => từ chối', TagCuration::csrfValid($session, str_repeat('0', 64)) === false);
$check('csrf: token dạng mảng/số => từ chối', TagCuration::csrfValid($session, [$tok]) === false && TagCuration::csrfValid($session, 123) === false);
$check('csrf: phiên chưa có token => từ chối kể cả gửi chuỗi rỗng', TagCuration::csrfValid([], '') === false && TagCuration::csrfValid([], 'abc') === false);
$bad = ['admin_tag_csrf' => 'khong-hop-le'];
$check('csrf: token hỏng trong phiên bị thay mới', preg_match('/^[a-f0-9]{64}$/', TagCuration::csrfToken($bad)) === 1);

// ---- Danh tính -----------------------------------------------------------------------------------------------------
$h1 = TagCuration::actorHash(['email' => 'Admin@Example.com'], 'salt');
$check('actorHash: dạng adm_ + 16 hex, không chứa email', preg_match('/^adm_[a-f0-9]{16}$/', $h1) === 1 && stripos($h1, 'admin') === false);
$check('actorHash: không phân biệt hoa thường, ổn định', $h1 === TagCuration::actorHash(['email' => 'admin@example.com '], 'salt'));
$check('actorHash: khác người/khác salt => khác hash', $h1 !== TagCuration::actorHash(['email' => 'b@example.com'], 'salt') && $h1 !== TagCuration::actorHash(['email' => 'admin@example.com'], 'salt2'));
$check('actorHash: dùng ma_nv khi không có email', TagCuration::actorHash(['ma_nv' => 3], 's') !== TagCuration::actorHash([], 's'));

// ---- Sự kiện stream -------------------------------------------------------------------------------------------------
$ev = TagCuration::changeEvent('1496', 'manual', 1700000000000);
$check('changeEvent: dạng catalog:changes:v1', $ev === ['type' => 'product_updated', 'ma_san_pham' => '1496', 'reason' => 'tags', 'source' => 'manual', 'ts' => '1700000000000']);

class FakeRedis extends RedisLite {
    public array $added = [];
    public bool $dead = false;
    public function __construct() { parent::__construct('redis://127.0.0.1:1'); }
    public function xadd(string $stream, array $fields, int $maxlen = self::STREAM_MAXLEN): ?string {
        if ($this->dead) { return null; }
        $this->added[] = [$stream, $fields, $maxlen];
        return '1-0';
    }
}
$r = new FakeRedis();
$check('publishChange: XADD catalog:changes:v1 MAXLEN 10000', TagCuration::publishChange('1496', 'manual', $r) === true
    && $r->added[0][0] === 'catalog:changes:v1' && $r->added[0][1]['ma_san_pham'] === '1496' && $r->added[0][2] === 10000);
$r->dead = true;
$check('publishChange: Redis chết => false, không ném', TagCuration::publishChange('1', 'manual', $r) === false);

// ---- Ngữ cảnh form ---------------------------------------------------------------------------------------------------
$s2 = [];
$ctx = TagCuration::formContext(['cong_dung_keys' => new ArrayObject(['cap_am', 'zzz']), 'van_de_da_keys' => ['mun'], 'tag_source' => 'manual', 'tag_updated_at' => '2026-10-22'], $s2);
$check('formContext: key đã lưu (lọc key lạ), nguồn, csrf', $ctx['selected']['benefits'] === ['cap_am'] && $ctx['selected']['concerns'] === ['mun'] && $ctx['source'] === 'manual' && $ctx['csrf'] === $s2['admin_tag_csrf']);
$check('formContext: tag_source lạ => rỗng', TagCuration::formContext(['tag_source' => 'hack'], $s2)['source'] === '');

// ---- Model với Mongo giả ----------------------------------------------------------------------------------------------
class FakeResult {
    public function __construct(private int $matched, private int $modified) {}
    public function getMatchedCount(): int { return $this->matched; }
    public function getModifiedCount(): int { return $this->modified; }
}
class FakeColl {
    public array $docs = [];
    public array $updates = [];
    private static function matches(array $doc, array $filter): bool {
        foreach ($filter as $k => $v) {
            $actual = $doc[$k] ?? null;
            if ($actual instanceof \MongoDB\BSON\ObjectId || $v instanceof \MongoDB\BSON\ObjectId) {
                if ((string)$actual !== (string)$v) { return false; }
            } elseif ($actual !== $v) { return false; }
        }
        return true;
    }
    public function findOne(array $filter) { foreach ($this->docs as $d) { if (self::matches($d, $filter)) { return $d; } } return null; }
    public function find(array $filter, array $options = []) { return array_values(array_filter($this->docs, fn($d) => self::matches($d, $filter))); }
    public function countDocuments(array $filter) { return count($this->find($filter)); }
    public function updateOne(array $filter, array $update) {
        foreach ($this->docs as $i => $d) {
            if (self::matches($d, $filter)) {
                $this->updates[] = [$filter, $update];
                $this->docs[$i] = array_merge($d, $update['$set'] ?? []);
                return new FakeResult(1, 1);
            }
        }
        return new FakeResult(0, 0);
    }
}
class FakeDb {
    public FakeColl $san_pham;
    public FakeColl $tag_suggestions;
    public function __construct() { $this->san_pham = new FakeColl(); $this->tag_suggestions = new FakeColl(); }
}
$db = new FakeDb();
$db->san_pham->docs = [
    ['ma_san_pham' => 10, 'ten_san_pham' => 'Kem A'],
    ['ma_san_pham' => 11, 'ten_san_pham' => 'Kem B', 'tag_source' => 'manual', 'cong_dung_keys' => ['lam_diu']],
];
$m = new TagSuggestion($db);

$res = $m->saveManual('10', ['benefits' => ['cap_am'], 'concerns' => [], 'skin_types' => []], 'adm_x');
$set = $db->san_pham->updates[0][1]['$set'];
$check('saveManual: ghi manual lên đúng sản phẩm (ma_san_pham số)', $res['ok'] && $db->san_pham->updates[0][0] === ['ma_san_pham' => 10] && $set['tag_source'] === 'manual' && $set['cong_dung_keys'] === ['cap_am']);
$check('saveManual: tag_updated_at là UTCDateTime, không có updated_at', $set['tag_updated_at'] instanceof \MongoDB\BSON\UTCDateTime && !isset($set['updated_at']));
$check('saveManual: sản phẩm không tồn tại => ok=false', $m->saveManual('999', ['benefits' => []], 'a')['ok'] === false);

$oid1 = new \MongoDB\BSON\ObjectId();
$oid2 = new \MongoDB\BSON\ObjectId();
$oid3 = new \MongoDB\BSON\ObjectId();
$db->tag_suggestions->docs = [
    ['_id' => $oid1, 'ma_san_pham' => 12, 'status' => 'pending', 'suggested' => ['benefits' => ['lam_diu', 'hack'], 'concerns' => ['mun'], 'skin_types' => []]],
    ['_id' => $oid2, 'ma_san_pham' => 11, 'status' => 'pending', 'suggested' => ['benefits' => ['cap_am'], 'concerns' => [], 'skin_types' => []]],
    ['_id' => $oid3, 'ma_san_pham' => 13, 'status' => 'pending', 'suggested' => ['benefits' => ['sang_da'], 'concerns' => [], 'skin_types' => []]],
];
$db->san_pham->docs[] = ['ma_san_pham' => 12, 'ten_san_pham' => 'Kem C'];
$db->san_pham->docs[] = ['ma_san_pham' => 13, 'ten_san_pham' => 'Kem D'];

$locked = $m->approve((string)$oid2, null, 'adm_x');
$check('approve: sản phẩm có nhãn manual => KHÔNG ghi đè', $locked['ok'] === false && $locked['reason'] === 'manual_locked'
    && ($db->san_pham->findOne(['ma_san_pham' => 11])['cong_dung_keys'] ?? null) === ['lam_diu']
    && $db->tag_suggestions->findOne(['_id' => $oid2])['status'] === 'pending');

$ok = $m->approve((string)$oid1, null, 'adm_x');
$p12 = $db->san_pham->findOne(['ma_san_pham' => 12]);
$s1 = $db->tag_suggestions->findOne(['_id' => $oid1]);
$check('approve: ghi llm_approved, lọc key lạ của đề xuất', $ok['ok'] && $ok['product_id'] === '12' && $p12['tag_source'] === 'llm_approved' && $p12['cong_dung_keys'] === ['lam_diu'] && $p12['van_de_da_keys'] === ['mun']);
$check('approve: đánh dấu gợi ý approved + người duyệt + chưa sửa', $s1['status'] === 'approved' && $s1['reviewed_by'] === 'adm_x' && $s1['reviewed_at'] instanceof \MongoDB\BSON\UTCDateTime && $s1['edited'] === false);
$check('approve: duyệt lần 2 bị từ chối (không còn pending)', $m->approve((string)$oid1, null, 'adm_x')['reason'] === 'not_pending');

$edited = $m->approve((string)$oid3, ['benefits' => ['cap_am', 'lam_diu'], 'concerns' => [], 'skin_types' => ['da_dau']], 'adm_y');
$p10 = $db->san_pham->findOne(['ma_san_pham' => 13]);
$check('approve có sửa: ghi bản đã sửa, edited=true', $edited['ok'] && $edited['edited'] === true && $p10['cong_dung_keys'] === ['cap_am', 'lam_diu'] && $p10['loai_da_keys'] === ['da_dau'] && $p10['tag_source'] === 'llm_approved');
$check('approve: id lạ / sai định dạng', $m->approve('khong-phai-objectid', null, 'a')['reason'] === 'not_found' && $m->approve((string)new \MongoDB\BSON\ObjectId(), null, 'a')['reason'] === 'not_found');

$oid4 = new \MongoDB\BSON\ObjectId();
$db->tag_suggestions->docs[] = ['_id' => $oid4, 'ma_san_pham' => 10, 'status' => 'pending', 'suggested' => []];
$rej = $m->reject((string)$oid4, 'adm_x');
$check('reject: status rejected, không đụng san_pham', $rej['ok'] && $db->tag_suggestions->findOne(['_id' => $oid4])['status'] === 'rejected' && count($db->san_pham->updates) === 3);
$check('reject: lần 2 / id sai => ok=false', $m->reject((string)$oid4, 'a')['ok'] === false && $m->reject('xyz', 'a')['ok'] === false);

$check('countByStatus', $m->countByStatus() === ['pending' => 1, 'approved' => 2, 'rejected' => 1]);
$check('toArray: ArrayObject lồng nhau => mảng', TagSuggestion::toArray(new ArrayObject(['a' => new ArrayObject([1, 2])])) === ['a' => [1, 2]]);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
