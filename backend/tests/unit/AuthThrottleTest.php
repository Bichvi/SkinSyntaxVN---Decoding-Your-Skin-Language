<?php
// Test thuần PHP (Redis giả) cho AuthThrottle. Chạy: php backend/tests/unit/AuthThrottleTest.php
require_once __DIR__ . '/../../app/services/RedisLite.php';
require_once __DIR__ . '/../../app/services/RateLimiter.php';
require_once __DIR__ . '/../../app/services/AuthThrottle.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

// Redis giả đếm theo key; eval trả [count, ttl] như script thật.
class CountingRedis extends RedisLite {
    public array $counts = [];
    public array $keys = [];
    public int $ttl = 600;
    public bool $down = false;
    public function __construct() { parent::__construct('redis://127.0.0.1:1'); }
    public function eval(string $script, array $keys = [], array $args = []) {
        if ($this->down) { return null; }
        $k = $keys[0];
        $this->keys[] = $k;
        $this->counts[$k] = ($this->counts[$k] ?? 0) + (int)$args[0];
        return [$this->counts[$k], $this->ttl];
    }
}

error_reporting(E_ALL);
ini_set('error_log', sys_get_temp_dir() . '/auth_throttle_test.log');

$run = function (string $action, string $email, RateLimiter $l, ?string $ip = 'ipaaa', ?string $sid = 'sess1') {
    return AuthThrottle::check($action, $email, $l, $ip, $sid);
};
$fresh = function () { $r = new CountingRedis(); return [$r, new RateLimiter($r)]; };

// login: 10 lần/email
[$r, $l] = $fresh();
$res = [];
for ($i = 0; $i < 11; $i++) { $res[] = $run('login', 'a@x.com', $l, 'ip' . $i)['allowed']; }
$check('login: 10 lượt đầu qua, lượt 11 bị chặn (email)', array_slice($res, 0, 10) === array_fill(0, 10, true) && $res[10] === false);

// email độc lập
[$r, $l] = $fresh();
for ($i = 0; $i < 10; $i++) { $run('login', 'a@x.com', $l, 'ip' . $i); }
$check('email khác nhau độc lập', $run('login', 'b@x.com', $l, 'ipz')['allowed'] === true);

// không phân biệt hoa/thường/khoảng trắng
[$r, $l] = $fresh();
for ($i = 0; $i < 10; $i++) { $run('login', $i % 2 ? ' A@X.com ' : 'a@x.com', $l, 'ip' . $i); }
$check('email không phân biệt hoa thường/khoảng trắng', $run('login', 'A@x.COM', $l, 'ipn')['allowed'] === false);

// IP vs session
[$r, $l] = $fresh();
$run('login', 'a@x.com', $l, 'ipaaa', 'sidX');
$check('có ipIdent -> dùng scope _ip, không dùng _session',
    in_array('rl:auth_login_ip:v1:ipaaa', $r->keys, true) && !array_filter($r->keys, fn($k) => str_contains($k, '_session')));
[$r, $l] = $fresh();
$run('login', 'a@x.com', $l, null, 'sidX');
$check('ipIdent null -> dùng scope _session', count(array_filter($r->keys, fn($k) => str_contains($k, 'auth_login_session'))) === 1 && !array_filter($r->keys, fn($k) => str_contains($k, '_ip:')));

// giới hạn ip riêng: 30
[$r, $l] = $fresh();
$res = [];
for ($i = 0; $i < 31; $i++) { $res[] = $run('login', 'u' . $i . '@x.com', $l, 'sameip')['allowed']; }
$check('login: lượt 31 từ cùng IP bị chặn dù email khác', $res[29] === true && $res[30] === false);

// session: 20
[$r, $l] = $fresh();
$res = [];
for ($i = 0; $i < 21; $i++) { $res[] = $run('login', 'u' . $i . '@x.com', $l, null, 'sidS')['allowed']; }
$check('login: lượt 21 cùng phiên bị chặn (khi không có IP)', $res[19] === true && $res[20] === false);

// reset: 3
[$r, $l] = $fresh();
$res = [];
for ($i = 0; $i < 4; $i++) { $res[] = $run('reset', 'a@x.com', $l, 'ip' . $i)['allowed']; }
$check('reset: lượt 4 bị chặn', $res === [true, true, true, false]);

// signup_otp: 3
[$r, $l] = $fresh();
$res = [];
for ($i = 0; $i < 4; $i++) { $res[] = $run('signup_otp', 'a@x.com', $l, 'ip' . $i)['allowed']; }
$check('signup_otp: lượt 4 bị chặn', $res === [true, true, true, false]);

// retry_after = max các scope bị chặn
$ttlRedis = new class extends CountingRedis {
    public function eval(string $script, array $keys = [], array $args = []) {
        $k = $keys[0];
        $this->counts[$k] = ($this->counts[$k] ?? 0) + 1;
        return [99, str_contains($k, '_email') ? 120 : 300];
    }
};
$res = AuthThrottle::check('login', 'a@x.com', new RateLimiter($ttlRedis), 'ipaaa', 's');
$check('retry_after = max của các scope bị chặn', $res['allowed'] === false && $res['retry_after'] === 300);

// hit tất cả scope, không dừng sớm
$check('hit cả email và ip (không short-circuit)', count($ttlRedis->counts) === 2);

// Redis lỗi / không có client -> cho qua
[$r, $l] = $fresh();
$r->down = true;
$check('Redis lỗi -> cho qua', $run('login', 'a@x.com', $l)['allowed'] === true);
$check('không có client Redis -> cho qua', AuthThrottle::check('login', 'a@x.com', new RateLimiter(null), 'ip', 's') === ['allowed' => true, 'retry_after' => 0]);

// key không chứa email thô
[$r, $l] = $fresh();
$run('login', 'victim@example.com', $l, 'ipaaa');
$check('key không chứa email thô', !array_filter($r->keys, fn($k) => str_contains($k, 'victim') || str_contains($k, 'example')));

// action lạ
[$r, $l] = $fresh();
$check('action lạ -> cho qua, không gọi Redis', $run('khong_co', 'a@x.com', $l)['allowed'] === true && $r->keys === []);

// message
$check('message: 1s -> 1 phút', str_ends_with(AuthThrottle::message(1), 'sau 1 phút.'));
$check('message: 0 -> 1 phút', str_ends_with(AuthThrottle::message(0), 'sau 1 phút.'));
$check('message: 61s -> 2 phút', str_ends_with(AuthThrottle::message(61), 'sau 2 phút.'));
$check('message: 900s -> 15 phút', str_ends_with(AuthThrottle::message(900), 'sau 15 phút.'));

// OTP
$st = ['email' => 'a@x.com', 'otp_hash' => 'h', 'expires_at' => 123];
$check('otpLocked: chưa sai -> không khóa', AuthThrottle::otpLocked($st) === false);
$cur = $st;
$locks = [];
for ($i = 1; $i <= 5; $i++) { $o = AuthThrottle::otpFailed($cur); $cur = $o['state']; $locks[] = $o['locked']; }
$check('otpFailed: lần sai thứ 5 mới khóa', $locks === [false, false, false, false, true]);
$check('otpFailed: bộ đếm tăng và giữ nguyên state', $cur['failed_attempts'] === 5 && $cur['email'] === 'a@x.com' && $cur['otp_hash'] === 'h' && $cur['expires_at'] === 123);
$check('otpLocked: đủ 5 -> khóa', AuthThrottle::otpLocked($cur) === true);
$check('otpLocked: 4 -> chưa khóa', AuthThrottle::otpLocked(['failed_attempts' => 4]) === false);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
