<?php
// Test thuần PHP (client Redis giả) cho ProductViewCounter. Chạy: php backend/tests/unit/ProductViewCounterTest.php
require_once __DIR__ . '/../../app/services/RedisLite.php';
require_once __DIR__ . '/../../app/services/ProductViewCounter.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

class FakeViewRedis extends RedisLite {
    public array $calls = [];
    public $reply = 1;
    public bool $throw = false;
    public function __construct() { parent::__construct('redis://127.0.0.1:1'); }
    public function command(array $args) {
        $this->calls[] = $args;
        if ($this->throw) { throw new RuntimeException('boom'); }
        return $this->reply;
    }
}

putenv('PRODUCT_VIEWS_REDIS_ENABLED');
$redis = new FakeViewRedis();

$redis->reply = 7;
$check('record: trả về số lượt đang chờ (int)', ProductViewCounter::record('SP001', $redis) === 7);
$check('record: gọi HINCRBY đúng key/field/1', $redis->calls[0] === ['HINCRBY', 'product:views:v1', 'SP001', '1']);
$check('record: KEY = product:views:v1', ProductViewCounter::KEY === 'product:views:v1');
$check('record: trim id', ProductViewCounter::record('  123  ', $redis) === 7 && end($redis->calls)[2] === '123');

$n = count($redis->calls);
$bad = ['', '   ', str_repeat('a', 65), 'a b', 'x:y', '{"$gt":1}'];
$allNull = true;
foreach ($bad as $id) { if (ProductViewCounter::record($id, $redis) !== null) { $allNull = false; } }
$check('id sai định dạng -> null', $allNull);
$check('id sai định dạng -> không gọi Redis', count($redis->calls) === $n);
$check('id dài đúng 64 ký tự vẫn hợp lệ', ProductViewCounter::record(str_repeat('a', 64), $redis) === 7);

$redis->reply = null;
$check('Redis lỗi (reply null) -> null', ProductViewCounter::record('SP001', $redis) === null);
$redis->reply = 'garbage';
$check('reply không phải int -> null', ProductViewCounter::record('SP001', $redis) === null);
$redis->reply = 5;
$redis->throw = true;
$check('Redis ném exception -> null', ProductViewCounter::record('SP001', $redis) === null);
$redis->throw = false;

RedisLite::setShared(null);
putenv('REDIS_URL');
$check('không có client -> null', ProductViewCounter::record('SP001', null) === null);

putenv('PRODUCT_VIEWS_REDIS_ENABLED=0');
$n = count($redis->calls);
$check('công tắc PRODUCT_VIEWS_REDIS_ENABLED=0 -> null, không gọi Redis', ProductViewCounter::record('SP001', $redis) === null && count($redis->calls) === $n);
putenv('PRODUCT_VIEWS_REDIS_ENABLED');
$check('bỏ công tắc -> chạy lại bình thường', ProductViewCounter::record('SP001', $redis) === 5);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
