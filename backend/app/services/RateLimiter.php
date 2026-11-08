<?php
// Rate limiter cửa sổ cố định trên Redis (U03): INCRBY + EXPIRE trong một script Lua (nguyên tử,
// không để lại key vô thời hạn nếu request chết giữa chừng). Key: rl:<scope>:v1:<ident>.
// Fail-open: Redis lỗi/không cấu hình -> 'degraded' => true, 'allowed' => true; caller tự quyết định fallback.
require_once __DIR__ . '/RedisLite.php';

final class RateLimiter
{
    private const LUA = <<<'LUA'
local c = redis.call('INCRBY', KEYS[1], ARGV[1])
local ttl = redis.call('TTL', KEYS[1])
if c == tonumber(ARGV[1]) or ttl < 0 then
  redis.call('EXPIRE', KEYS[1], ARGV[2])
  ttl = tonumber(ARGV[2])
end
return {c, ttl}
LUA;

    private static ?RateLimiter $shared = null;

    public function __construct(private ?RedisLite $redis)
    {
    }

    public static function shared(): self
    {
        return self::$shared ??= new self(RedisLite::shared());
    }

    /** Dùng cho test. */
    public static function setShared(?self $limiter): void
    {
        self::$shared = $limiter;
    }

    public static function key(string $scope, string $ident): string
    {
        $clean = static fn(string $s): string => preg_replace('/[^a-z0-9_.\-]/', '_', strtolower($s));
        return 'rl:' . $clean($scope) . ':v1:' . $clean($ident);
    }

    /**
     * Ghi nhận $cost lần truy cập. Vượt $limit trong cửa sổ $windowSeconds -> allowed=false.
     * @return array{allowed:bool, retry_after:int, count:int, degraded:bool}
     */
    public function hit(string $scope, string $ident, int $limit, int $windowSeconds, int $cost = 1): array
    {
        $open = ['allowed' => true, 'retry_after' => 0, 'count' => 0, 'degraded' => true];
        if ($limit <= 0 || $windowSeconds <= 0 || $this->redis === null) {
            return $open;
        }
        $res = $this->redis->eval(self::LUA, [self::key($scope, $ident)], [max(1, $cost), $windowSeconds]);
        if (!is_array($res) || count($res) < 2 || !is_int($res[0]) || !is_int($res[1])) {
            return $open;
        }
        $allowed = $res[0] <= $limit;
        return ['allowed' => $allowed, 'retry_after' => $allowed ? 0 : max(1, $res[1]), 'count' => $res[0], 'degraded' => false];
    }

    /**
     * Định danh IP đã băm cho rate limit, hoặc null nếu không tin được.
     * Sau nginx/docker REMOTE_ADDR là IP proxy (private) dùng chung cho mọi người -> không dùng, vì sẽ gộp mọi
     * khách vào một bộ đếm. X-Forwarded-For bị bỏ qua có chủ đích (nginx hiện không ghi đè nên giả mạo được).
     */
    public static function clientIdentity(?string $remoteAddr = null): ?string
    {
        $ip = $remoteAddr ?? (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        return substr(hash('sha256', 'ssf-rl|' . $ip), 0, 24);
    }
}
