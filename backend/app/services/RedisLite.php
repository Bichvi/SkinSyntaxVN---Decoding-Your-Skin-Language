<?php
// Client Redis RESP tối giản dùng chung (U03). Container PHP không có phpredis/predis.
// - Timeout ngắn (mặc định 0.2s); KHÔNG ném lỗi: mọi lỗi mạng/giao thức -> null/false, lastError() có mô tả.
// - Sau lần kết nối/đọc thất bại, các lệnh còn lại trong cùng request trả null ngay (không chờ timeout lần nữa).
// - Cờ "Redis đang chết" dạng file tạm (DOWN_TTL giây) dùng chung giữa các request/instance: khi container Redis dừng,
//   phân giải DNS tên `redis` có thể treo ~4s; nhờ cờ, chỉ request đầu tiên trả giá, các request sau bỏ qua ngay.
// - Hỗ trợ: GET SET SETEX DEL INCR EXPIRE ZADD ZREMRANGEBYSCORE ZCARD ZREVRANGE XADD PUBLISH EVAL + pipeline().
// - Tên key theo docs/redis/conventions.md (<domain>:<purpose>:v<n>:...).
// Phần mã hóa/giải mã RESP là static để test không cần mạng.

/** Lỗi do Redis trả về (dòng "-ERR ..."), tách khỏi lỗi mạng. */
final class RedisLiteReplyError
{
    public function __construct(public string $message)
    {
    }
}

class RedisLite
{
    public const DEFAULT_TIMEOUT = 0.2;
    public const STREAM_MAXLEN = 100000;
    public const DOWN_TTL = 5;

    private static ?RedisLite $shared = null;
    private static bool $sharedResolved = false;

    private $sock = null;
    private bool $down = false;
    private string $lastError = '';

    public function __construct(private string $url, private float $timeout = self::DEFAULT_TIMEOUT)
    {
    }

    /** Client dùng chung trong request (URL: SEARCH_BREAKER_REDIS_URL | REDIS_URL). null nếu chưa cấu hình. */
    public static function shared(): ?self
    {
        if (self::$sharedResolved) {
            return self::$shared;
        }
        self::$sharedResolved = true;
        $get = static function (string $k): string {
            if (function_exists('ss_env')) {
                return ss_env($k, '');
            }
            $v = getenv($k);
            return $v === false ? '' : trim((string)$v);
        };
        $url = $get('SEARCH_BREAKER_REDIS_URL');
        if ($url === '') {
            $url = $get('REDIS_URL');
        }
        self::$shared = $url !== '' ? new self($url) : null;
        return self::$shared;
    }

    /** Dùng cho test: đặt/xóa client dùng chung. */
    public static function setShared(?self $client): void
    {
        self::$shared = $client;
        self::$sharedResolved = true;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    // ---------------------------------------------------------------- RESP (không cần mạng)

    /** @param array<int,string|int|float> $args */
    public static function encodeCommand(array $args): string
    {
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $a = (string)$a;
            $out .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
        }
        return $out;
    }

    /**
     * Đọc đúng một reply từ stream. Trả: string|int|null|array|RedisLiteReplyError.
     * Ném RuntimeException khi giao thức hỏng/timeout (caller đóng kết nối).
     * @param resource $stream
     */
    public static function readReply($stream)
    {
        $line = fgets($stream);
        if ($line === false || $line === '') {
            throw new RuntimeException('read failed');
        }
        $type = $line[0];
        $rest = rtrim(substr($line, 1), "\r\n");
        switch ($type) {
            case '+':
                return $rest;
            case '-':
                return new RedisLiteReplyError($rest);
            case ':':
                return (int)$rest;
            case '$':
                $len = (int)$rest;
                if ($len < 0) {
                    return null;
                }
                $data = '';
                while (strlen($data) < $len + 2) {
                    $chunk = fread($stream, $len + 2 - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException('short bulk read');
                    }
                    $data .= $chunk;
                }
                return substr($data, 0, $len);
            case '*':
                $n = (int)$rest;
                if ($n < 0) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $n; $i++) {
                    $items[] = self::readReply($stream);
                }
                return $items;
            default:
                throw new RuntimeException('unknown reply type');
        }
    }

    // ---------------------------------------------------------------- kết nối

    private function conn()
    {
        if ($this->sock) {
            return $this->sock;
        }
        if ($this->down) {
            return null;
        }
        $flag = $this->downFlagPath();
        if (@is_file($flag) && (time() - (int)@filemtime($flag)) < self::DOWN_TTL) {
            return $this->fail('recently down', false);
        }
        $p = parse_url($this->url);
        if (!is_array($p) || empty($p['host'])) {
            return $this->fail('bad url', false);
        }
        $sock = @fsockopen($p['host'], (int)($p['port'] ?? 6379), $errno, $errstr, $this->timeout);
        if (!$sock) {
            return $this->fail('connect failed');
        }
        $sec = (int)floor($this->timeout);
        stream_set_timeout($sock, $sec, (int)(($this->timeout - $sec) * 1000000));
        $this->sock = $sock;
        if (@is_file($flag)) {
            @unlink($flag);
        }
        if (!empty($p['pass'])) {
            $user = isset($p['user']) ? rawurldecode($p['user']) : '';
            $auth = $user !== '' && $user !== 'default' ? ['AUTH', $user, rawurldecode($p['pass'])] : ['AUTH', rawurldecode($p['pass'])];
            if ($this->rawExec([$auth]) === null) {
                return null;
            }
        }
        $db = (int)ltrim((string)($p['path'] ?? ''), '/');
        if ($db > 0 && $this->rawExec([['SELECT', (string)$db]]) === null) {
            return null;
        }
        return $this->sock;
    }

    /** Đường dẫn cờ "đang chết" (theo URL, không chứa mật khẩu: dùng hash). */
    public function downFlagPath(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'ssv_redislite_down_' . substr(md5($this->url), 0, 12);
    }

    private function fail(string $why, bool $setFlag = true)
    {
        $this->lastError = $why;
        $this->down = true;
        if ($setFlag) {
            @touch($this->downFlagPath());
        }
        if ($this->sock) {
            @fclose($this->sock);
            $this->sock = null;
        }
        return null;
    }

    /**
     * Gửi một hoặc nhiều lệnh trên socket hiện có, đọc đúng ngần ấy reply.
     * @param array<int,array<int,string|int|float>> $commands
     * @return array<int,mixed>|null
     */
    private function rawExec(array $commands): ?array
    {
        if (!$this->sock) {
            return null;
        }
        $payload = '';
        foreach ($commands as $cmd) {
            $payload .= self::encodeCommand($cmd);
        }
        if (@fwrite($this->sock, $payload) === false) {
            $this->fail('write failed');
            return null;
        }
        $replies = [];
        try {
            foreach ($commands as $_) {
                $replies[] = self::readReply($this->sock);
            }
        } catch (Throwable $e) {
            $this->fail($e->getMessage());
            return null;
        }
        return $replies;
    }

    /** @param array<int,string|int|float> $args @return mixed null khi lỗi mạng hoặc Redis trả lỗi */
    public function command(array $args)
    {
        if (!$this->conn()) {
            return null;
        }
        $replies = $this->rawExec([$args]);
        if ($replies === null) {
            return null;
        }
        $reply = $replies[0];
        if ($reply instanceof RedisLiteReplyError) {
            $this->lastError = $reply->message;
            return null;
        }
        return $reply;
    }

    /**
     * Gửi nhiều lệnh một lượt (1 round-trip). Phần tử có thể là RedisLiteReplyError. null nếu mất kết nối.
     * @param array<int,array<int,string|int|float>> $commands
     * @return array<int,mixed>|null
     */
    public function pipeline(array $commands): ?array
    {
        if ($commands === [] || !$this->conn()) {
            return null;
        }
        return $this->rawExec($commands);
    }

    // ---------------------------------------------------------------- lệnh

    public function ping(): bool
    {
        return $this->command(['PING']) === 'PONG';
    }

    public function get(string $key): ?string
    {
        $v = $this->command(['GET', $key]);
        return is_string($v) ? $v : null;
    }

    /** @param array{ex?:int,px?:int,nx?:bool} $opts */
    public function set(string $key, string $value, array $opts = []): bool
    {
        $args = ['SET', $key, $value];
        if (isset($opts['ex'])) {
            array_push($args, 'EX', (string)(int)$opts['ex']);
        } elseif (isset($opts['px'])) {
            array_push($args, 'PX', (string)(int)$opts['px']);
        }
        if (!empty($opts['nx'])) {
            $args[] = 'NX';
        }
        return $this->command($args) === 'OK';
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        return $this->command(['SETEX', $key, (string)$ttl, $value]) === 'OK';
    }

    public function del(string ...$keys): ?int
    {
        $v = $this->command(array_merge(['DEL'], $keys));
        return is_int($v) ? $v : null;
    }

    public function incr(string $key): ?int
    {
        $v = $this->command(['INCR', $key]);
        return is_int($v) ? $v : null;
    }

    public function expire(string $key, int $seconds): bool
    {
        return $this->command(['EXPIRE', $key, (string)$seconds]) === 1;
    }

    public function zadd(string $key, float|int $score, string $member): ?int
    {
        $v = $this->command(['ZADD', $key, (string)$score, $member]);
        return is_int($v) ? $v : null;
    }

    public function zremrangebyscore(string $key, string|int|float $min, string|int|float $max): ?int
    {
        $v = $this->command(['ZREMRANGEBYSCORE', $key, (string)$min, (string)$max]);
        return is_int($v) ? $v : null;
    }

    public function zcard(string $key): ?int
    {
        $v = $this->command(['ZCARD', $key]);
        return is_int($v) ? $v : null;
    }

    /** @return array<int,string>|null (withScores=true: xen kẽ member, score) */
    public function zrevrange(string $key, int $start, int $stop, bool $withScores = false): ?array
    {
        $args = ['ZREVRANGE', $key, (string)$start, (string)$stop];
        if ($withScores) {
            $args[] = 'WITHSCORES';
        }
        $v = $this->command($args);
        return is_array($v) ? $v : null;
    }

    /** XADD stream MAXLEN ~ n * field value ...; trả id entry hoặc null. */
    public function xadd(string $stream, array $fields, int $maxlen = self::STREAM_MAXLEN): ?string
    {
        $v = $this->command(self::xaddArgs($stream, $fields, $maxlen));
        return is_string($v) ? $v : null;
    }

    /** @return array<int,string|int|float> */
    public static function xaddArgs(string $stream, array $fields, int $maxlen = self::STREAM_MAXLEN): array
    {
        $args = ['XADD', $stream, 'MAXLEN', '~', (string)$maxlen, '*'];
        foreach ($fields as $k => $v) {
            $args[] = (string)$k;
            $args[] = $v === null ? '' : (string)$v;
        }
        return $args;
    }

    public function publish(string $channel, string $message): ?int
    {
        $v = $this->command(['PUBLISH', $channel, $message]);
        return is_int($v) ? $v : null;
    }

    /** EVAL script numkeys keys... args... */
    public function eval(string $script, array $keys = [], array $args = [])
    {
        return $this->command(array_merge(['EVAL', $script, (string)count($keys)], array_map('strval', $keys), array_map('strval', $args)));
    }
}
