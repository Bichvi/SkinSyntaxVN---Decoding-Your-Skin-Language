<?php
// Test thuần PHP (không cần Redis thật) cho RedisLite: mã hóa/giải mã RESP, lệnh XADD, xuống cấp khi mất kết nối.
// Chạy: php backend/tests/unit/RedisLiteTest.php
// Tùy chọn: REDIS_TEST_URL=redis://127.0.0.1:6380/15 php backend/tests/unit/RedisLiteTest.php  (chạy thêm test với Redis thật)
require_once __DIR__ . '/../../app/services/RedisLite.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$stream = function (string $data) {
    $h = fopen('php://memory', 'w+');
    fwrite($h, $data);
    rewind($h);
    return $h;
};

// ---------- encode ----------
$check('encode: PING', RedisLite::encodeCommand(['PING']) === "*1\r\n\$4\r\nPING\r\n");
$check('encode: độ dài tính theo byte (UTF-8)', RedisLite::encodeCommand(['SET', 'k', 'Sữa']) === "*3\r\n\$3\r\nSET\r\n\$1\r\nk\r\n\$" . strlen('Sữa') . "\r\nSữa\r\n");
$check('encode: số -> chuỗi, chuỗi rỗng', RedisLite::encodeCommand(['X', 5, '']) === "*3\r\n\$1\r\nX\r\n\$1\r\n5\r\n\$0\r\n\r\n");

// ---------- decode ----------
$check('decode: simple string', RedisLite::readReply($stream("+OK\r\n")) === 'OK');
$check('decode: integer', RedisLite::readReply($stream(":42\r\n")) === 42);
$check('decode: bulk', RedisLite::readReply($stream("\$3\r\nfoo\r\n")) === 'foo');
$check('decode: bulk chứa CRLF và UTF-8', RedisLite::readReply($stream("\$" . strlen("a\r\nSữa") . "\r\na\r\nSữa\r\n")) === "a\r\nSữa");
$check('decode: null bulk', RedisLite::readReply($stream("\$-1\r\n")) === null);
$check('decode: array lồng', RedisLite::readReply($stream("*2\r\n\$1\r\na\r\n*2\r\n:1\r\n:2\r\n")) === ['a', [1, 2]]);
$check('decode: array rỗng / null array', RedisLite::readReply($stream("*0\r\n")) === [] && RedisLite::readReply($stream("*-1\r\n")) === null);
$err = RedisLite::readReply($stream("-ERR unknown command\r\n"));
$check('decode: error reply', $err instanceof RedisLiteReplyError && $err->message === 'ERR unknown command');
$two = $stream("+OK\r\n:7\r\n");
$check('decode: nhiều reply liên tiếp (pipeline)', RedisLite::readReply($two) === 'OK' && RedisLite::readReply($two) === 7);
$threw = false;
try { RedisLite::readReply($stream("\$10\r\nshort")); } catch (RuntimeException $e) { $threw = true; }
$check('decode: bulk cụt -> RuntimeException', $threw);
$threw = false;
try { RedisLite::readReply($stream('')); } catch (RuntimeException $e) { $threw = true; }
$check('decode: stream rỗng -> RuntimeException', $threw);
$threw = false;
try { RedisLite::readReply($stream("?x\r\n")); } catch (RuntimeException $e) { $threw = true; }
$check('decode: kiểu lạ -> RuntimeException', $threw);

// ---------- XADD ----------
$check('xaddArgs: MAXLEN ~ và fields', RedisLite::xaddArgs('s:v1', ['a' => 1, 'b' => null, 'c' => 'x'], 50) === ['XADD', 's:v1', 'MAXLEN', '~', '50', '*', 'a', '1', 'b', '', 'c', 'x']);

// ---------- xuống cấp: không kết nối được ----------
$dead = new RedisLite('redis://127.0.0.1:1', 0.2);
@unlink($dead->downFlagPath());
$t0 = microtime(true);
$r1 = $dead->get('k');
$r2 = $dead->setex('k', 10, 'v');
$r3 = $dead->pipeline([['PING']]);
$elapsed = microtime(true) - $t0;
$check('down: get/setex/pipeline trả null/false, không ném', $r1 === null && $r2 === false && $r3 === null);
$check('down: sau lần lỗi đầu các lệnh sau trả ngay (< 0.5s tổng)', $elapsed < 0.5);
$check('down: lastError có mô tả', $dead->lastError() === 'connect failed');
$check('down: cờ file được tạo sau lần lỗi đầu', is_file($dead->downFlagPath()));
$second = new RedisLite('redis://127.0.0.1:1', 0.2);
$second->get('k');
$check('down: instance/request sau bỏ qua kết nối nhờ cờ', $second->lastError() === 'recently down');
@unlink($dead->downFlagPath());
$check('down: URL hỏng', (new RedisLite('not a url'))->ping() === false);
$check('down: ping false', $dead->ping() === false);

// ---------- Redis thật (tùy chọn) ----------
$url = getenv('REDIS_TEST_URL') ?: '';
if ($url !== '') {
    $r = new RedisLite($url);
    if (!$r->ping()) {
        echo "SKIP Redis thật không phản hồi\n";
    } else {
        $p = 'u03phptest:' . bin2hex(random_bytes(4)) . ':';
        $check('real: SET/GET', $r->set($p . 'a', 'Sữa', ['ex' => 30]) && $r->get($p . 'a') === 'Sữa');
        $check('real: SET NX lần 2 thất bại', $r->set($p . 'a', 'x', ['nx' => true, 'ex' => 30]) === false);
        $check('real: INCR/EXPIRE/DEL', $r->incr($p . 'n') === 1 && $r->incr($p . 'n') === 2 && $r->expire($p . 'n', 30) && $r->del($p . 'n', $p . 'a') === 2);
        $check('real: ZADD/ZCARD/ZREVRANGE/ZREMRANGEBYSCORE', $r->zadd($p . 'z', 1, 'a') === 1 && $r->zadd($p . 'z', 5, 'b') === 1
            && $r->zcard($p . 'z') === 2 && $r->zrevrange($p . 'z', 0, 0) === ['b'] && $r->zrevrange($p . 'z', 0, -1, true) === ['b', '5', 'a', '1']
            && $r->zremrangebyscore($p . 'z', '-inf', 2) === 1 && $r->zcard($p . 'z') === 1);
        $r->expire($p . 'z', 30);
        $id = $r->xadd($p . 's', ['k' => 'v'], 10);
        $check('real: XADD trả id', is_string($id) && preg_match('/^\d+-\d+$/', $id) === 1);
        $check('real: PUBLISH trả số subscriber', $r->publish($p . 'ch', 'x') === 0);
        $check('real: EVAL', $r->eval("return {tonumber(ARGV[1]) + 1, KEYS[1]}", [$p . 'k'], [41]) === [42, $p . 'k']);
        $rep = $r->pipeline([['SET', $p . 'p', '1', 'EX', '30'], ['GET', $p . 'p'], ['NOPE']]);
        $check('real: pipeline + error reply', $rep[0] === 'OK' && $rep[1] === '1' && $rep[2] instanceof RedisLiteReplyError);
        $r->del($p . 'p', $p . 's', $p . 'z');
    }
}

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
