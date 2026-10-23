<?php
// Test thuần PHP (Redis giả, không chạm DB thật) cho CatalogEvents (U11).
// Chạy: php backend/tests/unit/CatalogEventsTest.php
require_once __DIR__ . '/../../app/services/RedisLite.php';
require_once __DIR__ . '/../../app/services/CatalogEvents.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

class FakeRedis extends RedisLite {
    public array $added = [];
    public bool $dead = false;
    public bool $throw = false;
    public function __construct() { parent::__construct('redis://127.0.0.1:1'); }
    public function xadd(string $stream, array $fields, int $maxlen = self::STREAM_MAXLEN): ?string {
        if ($this->throw) { throw new RuntimeException('boom'); }
        if ($this->dead) { return null; }
        $this->added[] = [$stream, $fields, $maxlen];
        return '1-0';
    }
}

// ---- Dạng sự kiện -------------------------------------------------------------------------------------------
$ev = CatalogEvents::event('price_changed', ' 1496 ', ['reason' => 'admin_update', 'fields' => 'gia_ban'], 1700000000000);
$check('event: type, ma_san_pham (đã trim), ts, trường phụ', $ev === ['type' => 'price_changed', 'ma_san_pham' => '1496', 'ts' => '1700000000000', 'reason' => 'admin_update', 'fields' => 'gia_ban']);
$check('event: type lạ => product_updated', CatalogEvents::event('hack', '1')['type'] === 'product_updated');
$check('event: trường phụ không ghi đè trường chuẩn, bỏ mảng/object', CatalogEvents::event('product_updated', '5', ['type' => 'x', 'ma_san_pham' => '9', 'arr' => [1]])['ma_san_pham'] === '5'
    && !isset(CatalogEvents::event('product_updated', '5', ['arr' => [1]])['arr']));
$check('event: giá trị phụ bị cắt <= 200 ký tự', strlen(CatalogEvents::event('product_updated', '5', ['reason' => str_repeat('a', 500)])['reason']) === 200);

// ---- publish ------------------------------------------------------------------------------------------------
$r = new FakeRedis();
$check('publish: XADD catalog:changes:v1 MAXLEN 50000', CatalogEvents::publish('product_updated', '1496', ['source' => 'admin'], $r) === true
    && $r->added[0][0] === 'catalog:changes:v1' && $r->added[0][1]['ma_san_pham'] === '1496' && $r->added[0][1]['source'] === 'admin' && $r->added[0][2] === 50000);
$check('publish: id rỗng => false, không gửi', CatalogEvents::publish('product_updated', '  ', [], $r) === false && count($r->added) === 1);
$r->dead = true;
$check('publish: Redis từ chối (null) => false, không ném', CatalogEvents::publish('product_updated', '1', [], $r) === false);
$r->dead = false;
$r->throw = true;
$check('publish: client ném lỗi => false, không ném', CatalogEvents::publish('product_updated', '1', [], $r) === false);
RedisLite::setShared(null);
$check('publish: không cấu hình Redis => false, không ném', CatalogEvents::publish('product_updated', '1') === false);

$r = new FakeRedis();
$check('publishMany: bỏ id trùng, đếm số đã đẩy', CatalogEvents::publishMany('bulk_import', ['1', '2', '2', 3], [], $r) === 3 && count($r->added) === 3);

// ---- Loại theo trạng thái hiển thị -----------------------------------------------------------------------------
$check('visibilityType: inactive/hidden/tam_an => product_hidden', CatalogEvents::visibilityType('inactive') === 'product_hidden'
    && CatalogEvents::visibilityType('Hidden') === 'product_hidden' && CatalogEvents::visibilityType('tam_an') === 'product_hidden');
$check('visibilityType: active => product_shown', CatalogEvents::visibilityType('active') === 'product_shown');

// ---- Phân loại thay đổi ------------------------------------------------------------------------------------------
$before = ['gia_ban' => 194000, 'ten_san_pham' => 'Sữa rửa mặt', 'so_luong_ton_kho' => 300, 'trang_thai_kho' => 'con_hang', 'thanh_phan_full' => 'Water, Niacinamide', 'cong_dung_keys' => ['cap_am']];
[$t, $f] = CatalogEvents::classifyChange($before, ['gia_ban' => '150000', 'ten_san_pham' => 'Sữa rửa mặt', 'updated_at' => 'x']);
$check('classify: chỉ đổi giá => price_changed', $t === 'price_changed' && $f === ['gia_ban']);
[$t] = CatalogEvents::classifyChange($before, ['gia_ban' => '194000.0', 'so_luong_ton_kho' => 0, 'trang_thai_kho' => 'het_hang']);
$check('classify: chỉ tồn kho (giá y nguyên dù khác kiểu số/chuỗi) => stock_changed', $t === 'stock_changed');
[$t] = CatalogEvents::classifyChange($before, ['gia_ban' => 1, 'so_luong_ton_kho' => 5]);
$check('classify: giá + tồn kho => không đổi điểm tương tự (price_changed)', $t === 'price_changed');
[$t, $f] = CatalogEvents::classifyChange($before, ['gia_ban' => 150000, 'thanh_phan_full' => 'Water, Retinol']);
$check('classify: đổi thành phần => product_updated', $t === 'product_updated' && in_array('thanh_phan_full', $f, true));
[$t] = CatalogEvents::classifyChange($before, ['cong_dung_keys' => ['cap_am', 'mun']]);
$check('classify: mảng đổi => product_updated', $t === 'product_updated');
[$t, $f] = CatalogEvents::classifyChange($before, ['cong_dung_keys' => new ArrayObject(['cap_am'])]);
$check('classify: không phát hiện thay đổi => product_updated (hướng an toàn)', $t === 'product_updated' && $f === []);
[$t] = CatalogEvents::classifyChange([], ['ten_san_pham' => 'Mới']);
$check('classify: trước rỗng + trường mới => product_updated', $t === 'product_updated');

echo PHP_EOL . "Kết quả: $pass pass, $fail fail" . PHP_EOL;
exit($fail > 0 ? 1 : 0);
