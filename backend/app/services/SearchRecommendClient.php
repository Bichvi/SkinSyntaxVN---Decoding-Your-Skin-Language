<?php
// Client PHP -> Flask POST /api/search/recommend (Smart Search Bước 06).
// - Không retry; timeout ngắn; trả null khi lỗi/timeout/circuit mở/response sai cấu trúc.
// - Circuit breaker: 3 lỗi liên tiếp -> bỏ qua Flask 60s. Trạng thái lưu Valkey/Redis qua CacheService
//   (không có APCu) hoặc Redis qua RESP; không có -> chỉ theo request.
// - Không log token, user hash, hồ sơ da.
final class SearchRecommendClient
{
    public const SECTION_KEYS = ['same_brand', 'category_alt', 'personalized', 'semantic', 'trending'];
    public const REASON_CODES = ['same_brand', 'same_category', 'shared_ingredients', 'fits_skin_type', 'targets_concern', 'semantic_match', 'trending', 'bought_together'];
    public const PRODUCT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';
    public const BREAKER_FAILS = 3;
    public const BREAKER_OPEN_SECONDS = 60;
    private const BREAKER_KEY = 'search:recommend:breaker:v1';

    private static array $requestState = [];

    /** @var callable|null fn(string $url, string $body, array $headers, int $timeoutMs, int $connectMs): array{status:int, body:string, error:string} */
    private $transport;
    /** @var array{get:callable,set:callable}|null */
    private ?array $stateStore;

    public function __construct(
        private string $baseUrl,
        private string $internalToken,
        private int $timeoutMs = 1200,
        private int $connectTimeoutMs = 300,
        ?callable $transport = null,
        ?array $stateStore = null
    ) {
        $this->transport = $transport;
        $this->stateStore = $stateStore;
    }

    /** Tạo client từ env. null nếu tính năng tắt (SEARCH_FALLBACK_ENABLED=0) hoặc không xác định được URL. */
    public static function fromEnv(): ?self
    {
        $env = function (string $k, string $d = ''): string {
            if (function_exists('ss_env')) {
                return ss_env($k, $d);
            }
            $v = getenv($k);
            return ($v === false || trim((string)$v) === '') ? $d : trim((string)$v);
        };
        if (in_array(strtolower($env('SEARCH_FALLBACK_ENABLED', '1')), ['0', 'false', 'off', 'no'], true)) {
            return null;
        }
        $url = $env('SEARCH_RECOMMEND_ENDPOINT');
        if ($url === '') {
            // Dùng lại host Flask của chatbot (AI_CHAT_ENDPOINT) — cùng service skinsyntax-ai.
            $chat = $env('AI_CHAT_ENDPOINT', 'http://127.0.0.1:5001/api/chat/auto');
            $p = parse_url($chat);
            if (!is_array($p) || empty($p['host'])) {
                return null;
            }
            $url = ($p['scheme'] ?? 'http') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/api/search/recommend';
        }
        return new self(
            $url,
            $env('SEARCH_INTERNAL_TOKEN'),
            max(200, (int)$env('SEARCH_RECOMMEND_TIMEOUT_MS', '1200')),
            max(100, (int)$env('SEARCH_RECOMMEND_CONNECT_TIMEOUT_MS', '300'))
        );
    }

    /** @return array|null response đã validate, null nếu lỗi/timeout/circuit mở */
    public function recommend(string $q, ?string $userHash, ?array $skinProfile, array $cartIds, array $excludeIds, int $limitPerSection = 8): ?array
    {
        if ($this->isOpen()) {
            return null;
        }
        $payload = [
            'q' => mb_substr($q, 0, 200, 'UTF-8'),
            'cart_ids' => self::cleanIds($cartIds, 50),
            'exclude_ids' => self::cleanIds($excludeIds, 50),
            'limit_per_section' => max(1, min(20, $limitPerSection)),
        ];
        if ($userHash !== null && $userHash !== '') {
            $payload['user_hash'] = $userHash;
        }
        if ($skinProfile) {
            $payload['skin_profile'] = $skinProfile;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return null;
        }
        $requestId = self::requestId();
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Request-Id: ' . $requestId,
        ];
        if ($this->internalToken !== '') {
            $headers[] = 'X-Internal-Token: ' . $this->internalToken;
        }

        $started = microtime(true);
        $res = $this->transport
            ? ($this->transport)($this->baseUrl, $body, $headers, $this->timeoutMs, $this->connectTimeoutMs)
            : $this->curlPost($body, $headers);
        $tookMs = (int)round((microtime(true) - $started) * 1000);

        $status = (int)($res['status'] ?? 0);
        $data = null;
        if ($status >= 200 && $status < 300) {
            $decoded = json_decode((string)($res['body'] ?? ''), true);
            $data = is_array($decoded) ? self::validateResponse($decoded) : null;
        }
        if ($data === null) {
            $this->recordFailure();
            error_log(sprintf('[SEARCH_RECOMMEND] failed request_id=%s status=%d took_ms=%d error=%s', $requestId, $status, $tookMs, substr((string)(($res['error'] ?? '') ?: ($status >= 200 && $status < 300 ? 'invalid response' : 'http error')), 0, 160)));
            return null;
        }
        $this->recordSuccess();
        return $data;
    }

    /** Validate tối thiểu theo contract v1. Item sai -> bỏ item; cấu trúc sai -> null. */
    public static function validateResponse(array $data): ?array
    {
        if (!isset($data['sections']) || !is_array($data['sections']) || !array_is_list($data['sections'])) {
            return null;
        }
        $sections = [];
        foreach ($data['sections'] as $section) {
            if (!is_array($section) || !isset($section['key'], $section['items']) || !is_string($section['key']) || !is_array($section['items'])) {
                return null;
            }
            if (!in_array($section['key'], self::SECTION_KEYS, true)) {
                continue;
            }
            $items = [];
            foreach ($section['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $pid = $item['product_id'] ?? null;
                if (is_int($pid)) {
                    $pid = (string)$pid;
                }
                if (!is_string($pid) || !preg_match(self::PRODUCT_ID_PATTERN, $pid)) {
                    continue;
                }
                $code = $item['reason_code'] ?? null;
                if (!is_string($code) || !in_array($code, self::REASON_CODES, true)) {
                    continue;
                }
                $params = [];
                if (isset($item['reason_params']) && is_array($item['reason_params'])) {
                    foreach (array_slice(array_values($item['reason_params']), 0, 3) as $p) {
                        if (is_string($p)) {
                            $params[] = $p;
                        }
                    }
                }
                $items[] = [
                    'product_id' => $pid,
                    'score' => is_numeric($item['score'] ?? null) ? (float)$item['score'] : 0.0,
                    'reason_code' => $code,
                    'reason_params' => $params,
                ];
            }
            $sections[] = ['key' => $section['key'], 'items' => $items];
        }
        $intent = null;
        if (isset($data['intent']) && is_array($data['intent'])) {
            $intent = [];
            foreach (['brands', 'categories', 'skin_types', 'concerns'] as $k) {
                $intent[$k] = array_values(array_filter((array)($data['intent'][$k] ?? []), 'is_string'));
            }
            $intent['confidence'] = is_numeric($data['intent']['confidence'] ?? null) ? (float)$data['intent']['confidence'] : 0.0;
            // Bước 05B: tên hiển thị (song song brands/categories) cho chip; tùy chọn, tối đa 5 x 80 ký tự.
            foreach (['brand_names', 'category_names'] as $k) {
                $names = array_values(array_filter((array)($data['intent'][$k] ?? []), 'is_string'));
                $intent[$k] = array_map(fn($n) => mb_substr($n, 0, 80, 'UTF-8'), array_slice($names, 0, 5));
            }
        }
        return [
            'degraded' => (bool)($data['degraded'] ?? false),
            'degraded_branches' => array_values(array_filter((array)($data['degraded_branches'] ?? []), 'is_string')),
            'intent' => $intent,
            'sections' => $sections,
        ];
    }

    private static function cleanIds(array $ids, int $max): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = trim((string)$id);
            if ($id !== '' && preg_match(self::PRODUCT_ID_PATTERN, $id) && !in_array($id, $out, true)) {
                $out[] = $id;
                if (count($out) >= $max) {
                    break;
                }
            }
        }
        return $out;
    }

    private static function requestId(): string
    {
        $existing = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
        if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $existing)) {
            return $existing;
        }
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function curlPost(string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'curl unavailable'];
        }
        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if (defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($resp) ? $resp : '', 'error' => $error];
    }

    // ---- circuit breaker ----
    // Ưu tiên CacheService (Valkey) nếu cấu hình; không có -> Redis của dự án (SEARCH_BREAKER_REDIS_URL|REDIS_URL, client RESP);
    // không có cả hai -> trạng thái chỉ theo request.
    private static $redis = null; // false = đã thử và không dùng được

    private static function redis()
    {
        if (self::$redis !== null) {
            return self::$redis ?: null;
        }
        self::$redis = false;
        try {
            // SEARCH_BREAKER_REDIS_URL đặt trong docker-compose (REDIS_URL trong .env trỏ 127.0.0.1, sai trong container).
            $url = function_exists('ss_env') ? ss_env('SEARCH_BREAKER_REDIS_URL', ss_env('REDIS_URL', '')) : (string)(getenv('SEARCH_BREAKER_REDIS_URL') ?: getenv('REDIS_URL'));
            if ($url === '') {
                return null;
            }
            // Container PHP không có Predis/phpredis/APCu -> client RESP tối giản (GET/SETEX) qua socket.
            $client = new SearchBreakerRedis($url);
            if (!$client->ping()) {
                return null;
            }
            self::$redis = $client;
        } catch (Throwable $e) {
            error_log('[SEARCH_RECOMMEND] breaker store unavailable: ' . get_class($e));
        }
        return self::$redis ?: null;
    }

    private function loadState(): array
    {
        $empty = ['fails' => 0, 'open_until' => 0];
        if ($this->stateStore) {
            $s = ($this->stateStore['get'])(self::BREAKER_KEY);
            return is_array($s) ? $s : $empty;
        }
        try {
            if (class_exists('CacheService') && CacheService::getInstance()->isAvailable()) {
                $s = CacheService::getInstance()->get(self::BREAKER_KEY);
                return is_array($s) ? $s : $empty;
            }
            if ($r = self::redis()) {
                $s = json_decode((string)$r->get(self::BREAKER_KEY), true);
                return is_array($s) ? $s : $empty;
            }
        } catch (Throwable $e) {
            // rơi xuống trạng thái theo request
        }
        return self::$requestState ?: $empty;
    }

    private function saveState(array $s): void
    {
        if ($this->stateStore) {
            ($this->stateStore['set'])(self::BREAKER_KEY, $s);
            return;
        }
        try {
            if (class_exists('CacheService') && CacheService::getInstance()->isAvailable()) {
                CacheService::getInstance()->set(self::BREAKER_KEY, $s, self::BREAKER_OPEN_SECONDS * 5);
                return;
            }
            if ($r = self::redis()) {
                $r->setex(self::BREAKER_KEY, self::BREAKER_OPEN_SECONDS * 5, json_encode($s));
                return;
            }
        } catch (Throwable $e) {
        }
        self::$requestState = $s;
    }

    public function isOpen(): bool
    {
        $s = $this->loadState();
        return (int)($s['open_until'] ?? 0) > time();
    }

    private function recordFailure(): void
    {
        $s = $this->loadState();
        $fails = (int)($s['fails'] ?? 0) + 1;
        $openUntil = (int)($s['open_until'] ?? 0);
        if ($fails >= self::BREAKER_FAILS) {
            $openUntil = time() + self::BREAKER_OPEN_SECONDS;
            $fails = 0;
            error_log('[SEARCH_RECOMMEND] circuit open for ' . self::BREAKER_OPEN_SECONDS . 's');
        }
        $this->saveState(['fails' => $fails, 'open_until' => $openUntil]);
    }

    private function recordSuccess(): void
    {
        $s = $this->loadState();
        if ((int)($s['fails'] ?? 0) !== 0 || (int)($s['open_until'] ?? 0) !== 0) {
            $this->saveState(['fails' => 0, 'open_until' => 0]);
        }
    }
}

/** Client Redis RESP tối giản chỉ cho breaker (timeout 200ms; lỗi -> null/false, không ném). */
final class SearchBreakerRedis
{
    private $sock = null;

    public function __construct(private string $url)
    {
    }

    private function conn()
    {
        if ($this->sock) {
            return $this->sock;
        }
        $p = parse_url($this->url);
        if (!is_array($p) || empty($p['host'])) {
            return null;
        }
        $sock = @fsockopen($p['host'], (int)($p['port'] ?? 6379), $errno, $errstr, 0.2);
        if (!$sock) {
            return null;
        }
        stream_set_timeout($sock, 0, 200000);
        $this->sock = $sock;
        if (!empty($p['pass'])) {
            $auth = isset($p['user']) && $p['user'] !== '' && $p['user'] !== 'default' ? ['AUTH', rawurldecode($p['user']), rawurldecode($p['pass'])] : ['AUTH', rawurldecode($p['pass'])];
            $this->cmd($auth);
        }
        $db = (int)ltrim((string)($p['path'] ?? ''), '/');
        if ($db > 0) {
            $this->cmd(['SELECT', (string)$db]);
        }
        return $this->sock;
    }

    private function cmd(array $args)
    {
        $sock = $this->conn();
        if (!$sock) {
            return null;
        }
        $crlf = "\r\n";
        $out = '*' . count($args) . $crlf;
        foreach ($args as $a) {
            $out .= '$' . strlen($a) . $crlf . $a . $crlf;
        }
        if (@fwrite($sock, $out) === false) {
            return null;
        }
        $line = fgets($sock);
        if ($line === false) {
            return null;
        }
        $type = $line[0];
        $rest = rtrim(substr($line, 1), "\r\n");
        if ($type === '+' || $type === ':') {
            return $rest;
        }
        if ($type === '$') {
            $len = (int)$rest;
            if ($len < 0) {
                return null;
            }
            $data = '';
            while (strlen($data) < $len + 2) {
                $chunk = fread($sock, $len + 2 - strlen($data));
                if ($chunk === false || $chunk === '') {
                    return null;
                }
                $data .= $chunk;
            }
            return substr($data, 0, $len);
        }
        return null; // '-' lỗi hoặc kiểu không hỗ trợ
    }

    public function ping(): bool
    {
        return $this->cmd(['PING']) === 'PONG';
    }

    public function get(string $key): ?string
    {
        $v = $this->cmd(['GET', $key]);
        return is_string($v) ? $v : null;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        return $this->cmd(['SETEX', $key, (string)$ttl, $value]) === 'OK';
    }
}
