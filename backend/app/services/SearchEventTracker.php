<?php
// Smart Search Bước 08: sự kiện trên khối gợi ý (impression/click/add_to_cart/chip_click) -> Mongo `search_events`.
// U03: ghi đệm qua Redis Stream `search:events:v1` (XADD; worker `search-worker` gom lô ghi Atlas);
//      Redis lỗi/không có -> insert Atlas trực tiếp như cũ. Rate limit: Redis (phiên + IP công khai), lỗi -> bucket session.
// Document: {ts, q_norm(≤100), section, product_id, action, user_hash|null, session_hash[, chip]}.
// U12 (tùy chọn, tương thích ngược): impression có thể kèm `product_ids` (thứ tự hiển thị, vị trí = chỉ số + 1);
// click/add_to_cart có thể kèm `position` (1..50); payload có thể kèm `experiment` + `variant` (nhánh A/B của lượt hiển thị).
// Không lưu email, IP, user agent, hồ sơ da. Phần validate/rate-limit/build thuần để test không cần DB.
require_once __DIR__ . '/SearchSkinProfile.php';
require_once __DIR__ . '/RedisLite.php';
require_once __DIR__ . '/RateLimiter.php';

final class SearchEventTracker
{
    public const COLLECTION = 'search_events';
    public const ACTIONS = ['impression', 'click', 'add_to_cart', 'chip_click'];
    public const SECTIONS = ['category_alt', 'same_brand', 'personalized', 'semantic', 'trending', 'best_sellers', 'similar', 'routine', 'conflict', 'chips']; // 'similar' = khối "Sản phẩm tương tự" ở trang chi tiết (U08, q = "similar:<id>")
    public const CHIP_KINDS = ['same_brand', 'category', 'best_sellers'];
    public const PRODUCT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';
    public const MAX_EVENTS = 10;
    public const MAX_Q = 200;
    public const Q_NORM_MAX = 100;
    public const RATE_WINDOW = 60;      // giây
    public const RATE_MAX_EVENTS = 60;  // sự kiện / phiên / cửa sổ
    public const MAX_BODY = 4096;
    public const MAX_PRODUCT_IDS = 20;
    public const MAX_POSITION = 50;
    public const EXPERIMENT_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,39}$/';
    public const VARIANT_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,19}$/';
    public const STREAM = 'search:events:v1';
    public const STREAM_MAXLEN = 100000;
    public const RATE_MAX_IP_EVENTS = 300; // sự kiện / IP công khai / cửa sổ (chỉ khi nhận diện được IP thật)
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
        $experiment = null;
        if (isset($payload['experiment']) || isset($payload['variant'])) {
            $exp = $payload['experiment'] ?? null;
            $var = $payload['variant'] ?? null;
            if (!is_string($exp) || !is_string($var) || !preg_match(self::EXPERIMENT_PATTERN, $exp) || !preg_match(self::VARIANT_PATTERN, $var)) {
                return null;
            }
            $experiment = ['experiment' => $exp, 'variant' => $var];
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
                if (array_key_exists('position', $ev)) {
                    $pos = $ev['position'];
                    if (!is_int($pos) || $pos < 1 || $pos > self::MAX_POSITION) {
                        return null;
                    }
                    $item['position'] = $pos;
                }
            } elseif ($action === 'impression') {
                if ($section === 'chips' || $pid !== '') {
                    return null;
                }
                if (array_key_exists('product_ids', $ev)) {
                    $ids = $ev['product_ids'];
                    if (!is_array($ids) || !$ids || count($ids) > self::MAX_PRODUCT_IDS || array_keys($ids) !== range(0, count($ids) - 1)) {
                        return null;
                    }
                    $clean = [];
                    foreach ($ids as $id) {
                        if (!(is_string($id) || is_int($id)) || !preg_match(self::PRODUCT_ID_PATTERN, (string)$id)) {
                            return null;
                        }
                        $clean[] = (string)$id;
                    }
                    $item['product_ids'] = $clean;
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
        $valid = ['q_norm' => self::normalizeQuery($q), 'events' => $out];
        if ($experiment !== null) {
            $valid += $experiment;
        }
        return $valid;
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
            if (isset($ev['product_ids'])) {
                $doc['product_ids'] = $ev['product_ids'];
            }
            if (isset($ev['position'])) {
                $doc['position'] = $ev['position'];
            }
            if (isset($valid['experiment'], $valid['variant'])) {
                $doc['experiment'] = $valid['experiment'];
                $doc['variant'] = $valid['variant'];
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

    /** Trường của entry Stream (tất cả là chuỗi); worker Python dựng lại document Mongo từ đây. */
    public static function streamFields(array $doc, int $tsMs): array
    {
        $f = [
            'ts_ms' => (string)$tsMs,
            'q_norm' => (string)$doc['q_norm'],
            'section' => (string)$doc['section'],
            'product_id' => (string)$doc['product_id'],
            'action' => (string)$doc['action'],
            'user_hash' => (string)($doc['user_hash'] ?? ''),
            'session_hash' => (string)$doc['session_hash'],
        ];
        if (isset($doc['chip'])) {
            $f['chip'] = (string)$doc['chip'];
        }
        if (isset($doc['product_ids'])) {
            $f['product_ids'] = implode(',', $doc['product_ids']);
        }
        if (isset($doc['position'])) {
            $f['position'] = (string)$doc['position'];
        }
        if (isset($doc['experiment'], $doc['variant'])) {
            $f['experiment'] = (string)$doc['experiment'];
            $f['variant'] = (string)$doc['variant'];
        }
        return $f;
    }

    /**
     * Rate limit: Redis (theo phiên, thêm theo IP công khai nếu có). Redis không dùng được -> bucket trong $_SESSION
     * như trước U03. true = cho qua.
     */
    private static function rateAllowed(int $n, int $now): bool
    {
        $limiter = RateLimiter::shared();
        $bySession = $limiter->hit('search_event', substr(self::sessionHash(), 0, 32), self::RATE_MAX_EVENTS, self::RATE_WINDOW, $n);
        if ($bySession['degraded']) {
            [$ok, $bucket] = self::rateLimitAllows($_SESSION[self::RATE_KEY] ?? null, $n, $now);
            $_SESSION[self::RATE_KEY] = $bucket;
            return $ok;
        }
        if (!$bySession['allowed']) {
            return false;
        }
        $ip = RateLimiter::clientIdentity();
        if ($ip !== null) {
            return $limiter->hit('search_event_ip', $ip, self::RATE_MAX_IP_EVENTS, self::RATE_WINDOW, $n)['allowed'];
        }
        return true;
    }

    /**
     * XADD từng sự kiện (một round-trip, pipeline). Sự kiện nào Redis không nhận được -> insert Atlas trực tiếp.
     * @param array<int,array> $docs
     */
    private static function persist($db, array $docs, int $tsMs): void
    {
        $redis = RedisLite::shared();
        $pending = $docs;
        if ($redis !== null && self::streamEnabled()) {
            $commands = array_map(static fn(array $d): array => RedisLite::xaddArgs(self::STREAM, self::streamFields($d, $tsMs), self::STREAM_MAXLEN), $docs);
            $replies = $redis->pipeline($commands);
            if (is_array($replies)) {
                $pending = [];
                foreach ($docs as $i => $d) {
                    if (!is_string($replies[$i] ?? null)) {
                        $pending[] = $d;
                    }
                }
            }
        }
        if ($pending !== []) {
            $db->{self::COLLECTION}->insertMany($pending, ['ordered' => false]);
        }
    }

    /** SEARCH_EVENTS_STREAM_ENABLED=0 -> luôn ghi thẳng Atlas (công tắc khẩn cấp). */
    private static function streamEnabled(): bool
    {
        $raw = function_exists('ss_env') ? ss_env('SEARCH_EVENTS_STREAM_ENABLED', '1') : (string)(getenv('SEARCH_EVENTS_STREAM_ENABLED') ?: '1');
        return !in_array(strtolower(trim((string)$raw)), ['0', 'false', 'off', 'no'], true);
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
        if (!self::rateAllowed(count($valid['events']), $now ?? time())) {
            return 429;
        }
        try {
            $nowMs = (int)round(microtime(true) * 1000);
            $docs = self::buildDocs($valid, self::userHash(), self::sessionHash(), new \MongoDB\BSON\UTCDateTime($nowMs));
            self::persist($db, $docs, $nowMs);
        } catch (\Throwable $e) {
            error_log('[SEARCH_EVENT] store skipped: ' . get_class($e));
        }
        return 204;
    }
}
