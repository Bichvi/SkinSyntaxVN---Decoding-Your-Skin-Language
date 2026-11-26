<?php

require_once __DIR__ . '/RedisLite.php';
require_once __DIR__ . '/SupportKnowledge.php';

final class LiveSyna {
    public const MENTION = '~(?<![\p{L}\p{N}_@./])@syna(?![\p{L}\p{N}_@]|[.-][\p{L}\p{N}])~iu';

    public static function question(string $message): ?string {
        if (!preg_match(self::MENTION, $message)) return null;
        $question = trim(preg_replace(self::MENTION, '', $message));
        if (!preg_match('/\p{L}/u', $question)) return '';
        $folded = trim(SupportKnowledge::fold($question), ' .,');
        if ($folded === 'oi') return '';
        return str_contains($question, '?') ||
            preg_match('/\b(nao|gi|sao|khong|bao nhieu|gia|con hang|ton kho|het hang|tu van|goi y|giup|cho biet|routine|thanh phan|cach|cong dung|huong dan|so sanh|what|how|which|compare)\b/', $folded)
            ? $question : null;
    }

    public static function queued(string $room): ?int {
        $key = 'live:syna:{' . hash('sha256', $room) . '}:queue';
        $count = RedisLite::shared()?->command(['ZCARD', $key]);
        return is_int($count) ? $count : null;
    }

    public static function skinGuide(string $query): string {
        if (preg_match('/\b(san pham|thuoc|ke don|viem da|cham da|eczema|di ung|phat ban|serum|hang nao)\b/', $query)) return '';
        $guides = [
            [
                '/\bda kho\b/', '/\b(duong am|cap am|hoat chat|kho qua|cham soc)\b/',
                'Với da khô, bạn có thể ưu tiên kem dưỡng dạng cream, không hương liệu. Glycerin và hyaluronic acid là hai thành phần dưỡng ẩm có thể tìm trên nhãn. Thoa kem khi da còn hơi ẩm sau khi rửa.',
                'dry/dermatologists-tips-relieve-dry-skin',
            ],
            [
                '/\bda dau\b/', '/\b(duong am|lam sach|cham soc|kiem dau)\b/',
                'Với da dầu, dùng sữa rửa mặt dịu nhẹ và dưỡng ẩm sau khi rửa. Có thể tìm nhãn “oil-free” và “noncomedogenic”. Khi ra ngoài, dùng kem chống nắng phổ rộng SPF 30 trở lên.',
                'dry/oily-skin/',
            ],
            [
                '/\b(routine|chu trinh|thu tu)\b/', '/\b(co ban|sang|toi|cham soc|duong da|skincare)\b/',
                'Chu trình cơ bản bắt đầu bằng làm sạch dịu nhẹ. Sau đó dùng sản phẩm điều trị nếu có hướng dẫn, rồi dưỡng ẩm. Buổi sáng thêm chống nắng.',
                'care/apply-skin-care-certain-order',
            ],
        ];
        foreach ($guides as [$topic, $intent, $answer, $source]) {
            if (preg_match($topic, $query) && preg_match($intent, $query)) {
                return $answer . "\n\nhttps://www.aad.org/public/everyday-care/skin-care-basics/" . $source;
            }
        }
        return '';
    }

    public static function roomLimit(): int {
        return max(1, min(120, (int)(getenv('LIVE_SYNA_MAX_PER_MINUTE') ?: 20)));
    }

    public static function run(string $room, string $sender, callable $answer): array {
        $redis = RedisLite::shared();
        if (!$redis) return ['status' => 'unavailable'];
        $prefix = 'live:syna:{' . hash('sha256', $room) . '}:';
        $queue = $prefix . 'queue';
        $sequence = $prefix . 'seq';
        $owner = $prefix . 'sender:' . hash('sha256', $sender);
        $lock = $prefix . 'active';
        $budget = $prefix . 'minute';
        $id = bin2hex(random_bytes(12));
        $limit = max(1, min(20, (int)(getenv('MAX_QUEUE_SIZE') ?: 5)));
        $configured = getenv('COOLDOWN_SECONDS');
        $cooldown = max(0, min(10000, (int)(1000 * (is_numeric($configured) ? (float)$configured : 3))));
        $enroll = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local entries = redis.call('ZRANGE', KEYS[1], 0, -1, 'WITHSCORES')
for i = 2, #entries, 2 do
    if math.floor(tonumber(entries[i]) / 1000000) < now - 40 then
        redis.call('ZREM', KEYS[1], entries[i - 1])
    end
end
if redis.call('EXISTS', KEYS[3]) == 1 then return 2 end
if redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[2]) then return 3 end
if tonumber(redis.call('GET', KEYS[4]) or '0') >= tonumber(ARGV[3]) then return 4 end
if redis.call('INCR', KEYS[4]) == 1 then redis.call('EXPIRE', KEYS[4], 60) end
redis.call('SET', KEYS[3], ARGV[1], 'EX', 45)
local seq = redis.call('INCR', KEYS[2]) % 1000000
redis.call('ZADD', KEYS[1], now * 1000000 + seq, ARGV[1])
redis.call('EXPIRE', KEYS[1], 90)
redis.call('EXPIRE', KEYS[2], 90)
return 1
LUA;
        $result = $redis->command(['EVAL', $enroll, 4, $queue, $sequence, $owner, $budget, $id, $limit, self::roomLimit()]);
        if ($result !== 1) return ['status' => match ($result) {
            2 => 'busy', 3 => 'full', 4 => 'budget', default => 'unavailable',
        }];
        $start = <<<'LUA'
if redis.call('ZRANK', KEYS[1], ARGV[1]) ~= 0 then return 0 end
if redis.call('SET', KEYS[2], ARGV[1], 'NX', 'PX', 35000) then return 1 end
return 0
LUA;
        $finish = <<<'LUA'
redis.call('ZREM', KEYS[1], ARGV[1])
if redis.call('GET', KEYS[2]) == ARGV[1] then redis.call('DEL', KEYS[2]) end
if redis.call('GET', KEYS[3]) == ARGV[1] then
    if tonumber(ARGV[2]) > 0 then
        redis.call('PSETEX', KEYS[3], ARGV[2], 'cooldown')
    else
        redis.call('DEL', KEYS[3])
    end
end
return 1
LUA;
        $started = false;
        try {
            $deadline = microtime(true) + 18;
            do {
                $slot = $redis->command(['EVAL', $start, 2, $queue, $lock, $id]);
                if ($slot === null) return ['status' => 'unavailable'];
                if ($slot === 1) {
                    $started = true;
                    $text = $answer();
                    return $text !== '' ? ['status' => 'answered', 'answer' => $text] : ['status' => 'unavailable'];
                }
                usleep(200000);
            } while (microtime(true) < $deadline);
            return ['status' => 'timeout'];
        } finally {
            $redis->command(['EVAL', $finish, 3, $queue, $lock, $owner, $id, $started ? $cooldown : 0]);
        }
    }
}
