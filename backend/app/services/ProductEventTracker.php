<?php
// backend/app/services/ProductEventTracker.php
// Ghi sự kiện product_view / add_to_cart ẩn danh (cookie phiên băm) vào Mongo `product_events`.
// Không bao giờ ném lỗi ra luồng mua hàng; không lưu IP, user agent hay user id.

final class ProductEventTracker
{
    public const COOKIE = 'ssv_sid';
    public const TYPES = ['product_view', 'add_to_cart'];
    public const COOKIE_TTL = 2592000;
    public const BOT_PATTERN = '/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|facebookexternalhit|curl|wget|python|httpclient|java\/|go-http|monitor|uptime/i';

    public static function enabled(): bool
    {
        $raw = function_exists('ss_env') ? ss_env('PRODUCT_EVENTS_ENABLED', 'true') : (string)(getenv('PRODUCT_EVENTS_ENABLED') ?: 'true');
        return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Đặt cookie phiên cho request sau. Cố ý KHÔNG gán vào $_COOKIE: request hiện tại
     * chưa mang cookie nên không được đếm (client không giữ cookie, như phần lớn bot, không bao giờ được đếm).
     */
    public static function ensureCookie(): void
    {
        if (!self::enabled() || PHP_SAPI === 'cli' || headers_sent() || self::validCookie() !== null) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(self::COOKIE, bin2hex(random_bytes(16)), [
            'expires' => time() + self::COOKIE_TTL,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function validCookie(): ?string
    {
        $value = $_COOKIE[self::COOKIE] ?? null;
        return (is_string($value) && preg_match('/^[a-f0-9]{32}$/', $value)) ? $value : null;
    }

    public static function sessionHash(): ?string
    {
        $cookie = self::validCookie();
        return $cookie === null ? null : hash('sha256', 'ssv-analytics:' . $cookie);
    }

    public static function isExcluded(): bool
    {
        if (function_exists('current_role') && in_array(current_role(), ['admin', 'nhanvien'], true)) {
            return true;
        }
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match(self::BOT_PATTERN, $ua)) {
            return true;
        }
        foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_MOZ'] as $header) {
            if (stripos((string)($_SERVER[$header] ?? ''), 'prefetch') !== false) {
                return true;
            }
        }
        return false;
    }

    public static function record($db, string $type, $productId): void
    {
        try {
            if (!self::enabled() || !in_array($type, self::TYPES, true)) {
                return;
            }
            $pid = trim((string)$productId);
            if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $pid)) {
                return;
            }
            $sid = self::sessionHash();
            if ($sid === null || self::isExcluded()) {
                return;
            }
            $eventId = hash('sha256', "$type|$sid|$pid|" . date('Y-m-d'));
            $db->product_events->updateOne(
                ['event_id' => $eventId],
                ['$setOnInsert' => [
                    'type' => $type,
                    'product_id' => $pid,
                    'session_hash' => $sid,
                    'event_time' => new \MongoDB\BSON\UTCDateTime(),
                    'schema_version' => 1,
                ]],
                ['upsert' => true]
            );
        } catch (Throwable $e) {
            error_log('product_event skipped: ' . get_class($e));
        }
    }
}
