<?php
// Bộ đếm lượt xem sản phẩm đệm trong Redis local (HASH). Job Python `product_views_flush` ghi dồn sang Mongo mỗi 5 phút.
require_once __DIR__ . '/RedisLite.php';

final class ProductViewCounter
{
    /** HASH: field = mã sản phẩm, value = số lượt xem chưa ghi vào Mongo. */
    public const KEY = 'product:views:v1';

    /** Tăng 1 lượt xem trong Redis. Trả về số lượt đang chờ ghi, hoặc null nếu không dùng được Redis (caller ghi thẳng Mongo). */
    public static function record(string $id, ?RedisLite $redis = null): ?int
    {
        if (getenv('PRODUCT_VIEWS_REDIS_ENABLED') === '0') {
            return null;
        }
        $id = trim($id);
        if (preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $id) !== 1) {
            return null;
        }
        $redis ??= RedisLite::shared();
        if ($redis === null) {
            return null;
        }
        try {
            $reply = $redis->command(['HINCRBY', self::KEY, $id, '1']);
        } catch (Throwable $e) {
            return null;
        }
        return is_int($reply) ? $reply : null;
    }
}
