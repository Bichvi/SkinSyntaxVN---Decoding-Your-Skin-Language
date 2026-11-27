<?php
/**
 * Circuit breaker for MongoDB server-selection failures.
 * Once Atlas is unreachable, later calls (this request and, via Redis, other requests
 * for a short TTL) fail instantly instead of each waiting for serverSelectionTimeoutMS.
 */
final class AtlasBreaker
{
    private const KEY = 'atlas:breaker:open';
    private const TTL = 15;

    private static ?bool $open = null;
    private static bool $tripped = false;

    public static function isSelectionFailure(Throwable $e): bool
    {
        if ($e instanceof \MongoDB\Driver\Exception\ConnectionTimeoutException) {
            return true;
        }
        return $e instanceof \MongoDB\Driver\Exception\ConnectionException
            && stripos($e->getMessage(), 'No suitable servers') !== false;
    }

    public static function trippedThisRequest(): bool
    {
        return self::$tripped;
    }

    public static function isOpen(): bool
    {
        if (self::$tripped) {
            return true;
        }
        if (self::$open !== null) {
            return self::$open;
        }
        self::$open = false;
        try {
            if (class_exists('CacheService')) {
                self::$open = (bool)CacheService::getInstance()->get(self::KEY, false);
            }
        } catch (Throwable $ignored) {
            self::$open = false;
        }
        return self::$open;
    }

    public static function trip(Throwable $e): void
    {
        if (self::$tripped) {
            return;
        }
        self::$tripped = true;
        self::$open = true;
        error_log('Atlas breaker tripped: ' . $e->getMessage());
        try {
            if (class_exists('CacheService')) {
                CacheService::getInstance()->set(self::KEY, 1, self::TTL);
            }
        } catch (Throwable $ignored) {
        }
    }
}
