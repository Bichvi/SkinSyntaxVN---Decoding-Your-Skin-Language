<?php
// Test thuần PHP (client Redis giả) cho RateLimiter. Chạy: php backend/tests/unit/RateLimiterTest.php
require_once __DIR__ . '/../../app/services/RedisLite.php';
require_once __DIR__ . '/../../app/services/RateLimiter.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

class CountingRedis extends RedisLite {
    public array $evals = [];
    public $reply;
    public function __construct() { parent::__construct('redis://127.0.0.1:1'); }
    public function eval(string $script, array $keys = [], array $args = []) {
        $this->evals[] = [$script, $keys, $args];
        return is_callable($this->reply) ? ($this->reply)(count($this->evals)) : $this->reply;
    }
}

$redis = new CountingRedis();
$limiter = new RateLimiter($redis);

$redis->reply = fn(int $n) => [$n, 60];
$results = [];
for ($i = 0; $i < 5; $i++) { $results[] = $limiter->hit('search_event', 'sess1', 3, 60)['allowed']; }
$check('hit: 3 lượt đầu qua, lượt 4-5 bị chặn', $results === [true, true, true, false, false]);
$denied = $limiter->hit('search_event', 'sess1', 3, 60);
$check('hit: bị chặn -> retry_after = TTL còn lại, count, không degraded', $denied['retry_after'] === 60 && $denied['count'] === 6 && $denied['degraded'] === false);
$ok = (new RateLimiter(new class extends CountingRedis { public $reply = [1, 59]; }))->hit('x', 'y', 5, 60);
$check('hit: cho qua -> retry_after 0', $ok === ['allowed' => true, 'retry_after' => 0, 'count' => 1, 'degraded' => false]);

[$script, $keys, $args] = $redis->evals[0];
$check('hit: key rl:<scope>:v1:<ident>', $keys === ['rl:search_event:v1:sess1']);
$check('hit: args = cost, window', $args === [1, 60]);
$check('hit: script nguyên tử INCRBY + EXPIRE', str_contains($script, 'INCRBY') && str_contains($script, 'EXPIRE'));
$limiter->hit('s', 'i', 60, 60, 10);
$check('hit: cost > 1 được truyền (beacon nhiều sự kiện)', end($redis->evals)[2] === [10, 60]);

$redis->reply = null;
$deg = $limiter->hit('s', 'i', 1, 60);
$check('fail-open: Redis lỗi -> allowed + degraded', $deg['allowed'] === true && $deg['degraded'] === true);
$redis->reply = 'garbage';
$check('fail-open: reply lạ -> allowed + degraded', $limiter->hit('s', 'i', 1, 60)['degraded'] === true);
$check('fail-open: không có client -> allowed + degraded', (new RateLimiter(null))->hit('s', 'i', 1, 60) === ['allowed' => true, 'retry_after' => 0, 'count' => 0, 'degraded' => true]);
$check('limit <= 0 = tắt (không gọi Redis)', (function () use ($redis, $limiter) { $n = count($redis->evals); return $limiter->hit("s", "i", 0, 60)["allowed"] === true && count($redis->evals) === $n; })());

$check('key: làm sạch ký tự lạ, chữ thường', RateLimiter::key('Search Event', 'A:b/c') === 'rl:search_event:v1:a_b_c');

$pub = RateLimiter::clientIdentity('8.8.8.8');
$check('identity: IP công khai -> hash 24 hex, không chứa IP', is_string($pub) && preg_match('/^[a-f0-9]{24}$/', $pub) === 1);
$check('identity: IP khác -> hash khác', $pub !== RateLimiter::clientIdentity('8.8.4.4'));
$check('identity: IP private/loopback (sau nginx) -> null', RateLimiter::clientIdentity('172.18.0.5') === null && RateLimiter::clientIdentity('127.0.0.1') === null && RateLimiter::clientIdentity('10.0.0.3') === null && RateLimiter::clientIdentity('192.168.1.5') === null);
$check('identity: rỗng/không hợp lệ -> null', RateLimiter::clientIdentity('') === null && RateLimiter::clientIdentity('abc') === null);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
