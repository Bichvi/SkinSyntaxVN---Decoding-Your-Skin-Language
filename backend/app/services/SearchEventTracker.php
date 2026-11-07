<?php
// Smart Search Bước 08: sự kiện trên khối gợi ý (impression/click/add_to_cart/chip_click) -> Mongo `search_events`.
// Document: {ts, q_norm(≤100), section, product_id, action, user_hash|null, session_hash[, chip]}.
// Không lưu email, IP, user agent, hồ sơ da. Phần validate/rate-limit/build thuần để test không cần DB.
require_once __DIR__ . '/SearchSkinProfile.php';

final class SearchEventTracker
{
    public const COLLECTION = 'search_events';
    public const ACTIONS = ['impression', 'click', 'add_to_cart', 'chip_click'];
    public const SECTIONS = ['category_alt', 'same_brand', 'personalized', 'semantic', 'trending', 'best_sellers', 'chips'];
    public const CHIP_KINDS = ['same_brand', 'category', 'best_sellers'];
    public const PRODUCT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';
    public const MAX_EVENTS = 10;
    public const MAX_Q = 200;
    public const Q_NORM_MAX = 100;
    public const RATE_WINDOW = 60;      // giây
    public const RATE_MAX_EVENTS = 60;  // sự kiện / phiên / cửa sổ
    public const MAX_BODY = 4096;
    private const RATE_KEY = 'search_event_rl';

    public static function enabled(): bool
    {
        $raw = function_exists('ss_env') ? ss_env('SEARCH_EVENTS_ENABLED', '1') : (string)(getenv('SEARCH_EVENTS_ENABLED') ?: '1');
        return !in_array(strtolower(trim((string)$raw)), ['0', 'false', 'off', 'no'], true);
    }

    /**
     * Token CSRF không trạng thái: HMAC của session id (cookie httponly, bên thứ ba không đọc được).
     * Không ghi $_SESSION nên dùng được cả trong request live-filter đã session_write_close().
     */
    public static function csrfToken(?string $sessionId = null): string
    {
        $sid = $sessionId ?? (session_status() === PHP_SESSION_ACTIVE || session_id() !== '' ? session_id() : '');
        if ($sid === '') {
            return '';
        }
        $salt = function_exists('ss_env') ? (string)ss_env('SEARCH_USER_HASH_SALT', '') : '';
        return hash_hmac('sha256', $sid, 'ssf-csrf|' . $salt);
    }

    public static function checkCsrf($token, ?string $sessionId = null): bool
    {
        $expected = self::csrfToken($sessionId);
        return is_string($token) && $expected !== '' && hash_equals($expected, $token);
    }

    public static function normalizeQuery(string $q): string
    {
        return mb_substr(SearchSkinProfile::fold($q), 0, self::Q_NORM_MAX, 'UTF-8');
    }

    /**
     * Validate payload đã decode: {q, events:[{action, section, product_id?, chip?}], csrf?}.
     * Sai bất kỳ trường nào -> null (cả lô bị từ chối).
     * @return array{q_norm:string,events:array<int,array{action:string,section:string,product_id:string,chip?:string}>}|null
     */
    public static function validate($payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }
        $q = $payload['q'] ?? null;
        if (!is_string($q)) {
            return null;
        }
        $q = trim($q);
        if ($q === '' || mb_strlen($q, 'UTF-8') > self::MAX_Q || preg_match('/[\x00-\x1F\x7F]/', $q)) {
            return null;
        }
        $events = $payload['events'] ?? null;
        if (!is_array($events) || !$events || count($events) > self::MAX_EVENTS || array_keys($events) !== range(0, count($events) - 1)) {
            return null;
        }
        $out = [];
        foreach ($events as $ev) {
            if (!is_array($ev)) {
                return null;
            }
            $action = $ev['action'] ?? null;
            $section = $ev['section'] ?? null;
            $pid = $ev['product_id'] ?? '';
            if (!is_string($action) || !in_array($action, self::ACTIONS, true)
                || !is_string($section) || !in_array($section, self::SECTIONS, true)
                || !(is_string($pid) || is_int($pid))) {
                return null;
            }
            $pid = trim((string)$pid);
            $item = ['action' => $action, 'section' => $section, 'product_id' => ''];
            if ($action === 'click' || $action === 'add_to_cart') {
                if ($section === 'chips' || !preg_match(self::PRODUCT_ID_PATTERN, $pid)) {
                    return null;
                }
                $item['product_id'] = $pid;
            } elseif ($action === 'impression') {
                if ($section === 'chips' || $pid !== '') {
                    return null;
                }
            } else { // chip_click
                $chip = $ev['chip'] ?? null;
                if ($section !== 'chips' || $pid !== '' || !is_string($chip) || !in_array($chip, self::CHIP_KINDS, true)) {
                    return null;
                }
                $item['chip'] = $chip;
            }
            $out[] = $item;
        }
        return ['q_norm' => self::normalizeQuery($q), 'events' => $out];
    }

    /** Cửa sổ cố định theo phiên. $bucket = ['start'=>int,'count'=>int] (lưu trong $_SESSION). */
    public static function rateLimitAllows(?array $bucket, int $n, int $now): array
    {
        if (!is_array($bucket) || !isset($bucket['start'], $bucket['count']) || $now - (int)$bucket['start'] >= self::RATE_WINDOW) {
            $bucket = ['start' => $now, 'count' => 0];
        }
        if ((int)$bucket['count'] + $n > self::RATE_MAX_EVENTS) {
            return [false, $bucket];
        }
        $bucket['count'] = (int)$bucket['count'] + $n;
        return [true, $bucket];
    }

    /** Document Mongo; $ts = \MongoDB\BSON\UTCDateTime (hoặc giá trị bất kỳ trong test). */
    public static function buildDocs(array $valid, ?string $userHash, string $sessionHash, $ts): array
    {
        $docs = [];
        foreach ($valid['events'] as $ev) {
            $doc = [
                'ts' => $ts,
                'q_norm' => $valid['q_norm'],
                'section' => $ev['section'],
                'product_id' => $ev['product_id'],
                'action' => $ev['action'],
                'user_hash' => $userHash,
                'session_hash' => $sessionHash,
            ];
            if (isset($ev['chip'])) {
                $doc['chip'] = $ev['chip'];
            }
            $docs[] = $doc;
        }
        return $docs;
    }

    /** sha256 email+salt (giống user_hash gửi Flask); không có salt hoặc khách -> null. */
    public static function userHash(): ?string
    {
        $salt = function_exists('ss_env') ? (string)ss_env('SEARCH_USER_HASH_SALT', '') : '';
        $email = (string)($_SESSION['user']['email'] ?? '');
        if ($salt === '' || $email === '') {
            return null;
        }
        return hash('sha256', mb_strtolower(trim($email)) . $salt);
    }

    /** Băm cookie phân tích sẵn có (ProductEventTracker), nếu thiếu thì băm session id. */
    public static function sessionHash(): string
    {
        if (class_exists('ProductEventTracker')) {
            $h = ProductEventTracker::sessionHash();
            if ($h !== null) {
                return $h;
            }
        }
        return hash('sha256', 'ssv-search:' . (session_id() ?: bin2hex(random_bytes(8))));
    }

    /**
     * Xử lý request beacon. Trả mã HTTP: 204 ok/bỏ qua, 400 payload sai, 403 CSRF, 405, 413, 429.
     * Không bao giờ ném lỗi; lỗi Mongo vẫn trả 204 (beacon không xử lý phản hồi).
     */
    public static function handle($db, string $method, string $rawBody, ?int $now = null): int
    {
        if ($method !== 'POST') {
            return 405;
        }
        if (strlen($rawBody) > self::MAX_BODY) {
            return 413;
        }
        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !self::checkCsrf($payload['csrf'] ?? null)) {
            return is_array($payload) ? 403 : 400;
        }
        $valid = self::validate($payload);
        if ($valid === null) {
            return 400;
        }
        if (!self::enabled() || (function_exists('current_role') && in_array(current_role(), ['admin', 'nhanvien'], true))) {
            return 204;
        }
        [$ok, $bucket] = self::rateLimitAllows($_SESSION[self::RATE_KEY] ?? null, count($valid['events']), $now ?? time());
        $_SESSION[self::RATE_KEY] = $bucket;
        if (!$ok) {
            return 429;
        }
        try {
            $docs = self::buildDocs($valid, self::userHash(), self::sessionHash(), new \MongoDB\BSON\UTCDateTime());
            $db->{self::COLLECTION}->insertMany($docs, ['ordered' => false]);
        } catch (\Throwable $e) {
            error_log('[SEARCH_EVENT] insert skipped: ' . get_class($e));
        }
        return 204;
    }
}
