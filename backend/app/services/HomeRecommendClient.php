<?php
// Client PHP -> Flask POST /api/home/recommend (khối gợi ý trang chủ từ đồ thị Neo4j).
// - Không retry; timeout ngắn; trả null khi lỗi/timeout/circuit mở/response sai cấu trúc.
// - Circuit breaker riêng (key home:recommend:breaker:v1): 3 lỗi liên tiếp -> mở 60s.
// - Không log token, hồ sơ da, giỏ hàng.
require_once __DIR__ . '/SearchRecommendClient.php'; // SearchBreakerRedis

final class HomeRecommendClient
{
    public const SECTION_KEYS = ['seen', 'skin', 'concern'];
    public const PRODUCT_ID_PATTERN = '/^[0-9]{1,12}$/';
    public const BREAKER_FAILS = 3;
    public const BREAKER_OPEN_SECONDS = 60;
    private const BREAKER_KEY = 'home:recommend:breaker:v1';

    private static array $requestState = [];

    /** @var callable|null fn(string $url, string $body, array $headers, int $timeoutMs, int $connectMs): array{status:int, body:string, error:string} */
    private $transport;
    /** @var array{get:callable,set:callable}|null */
    private ?array $stateStore;

    public function __construct(
        private string $baseUrl,
        private string $internalToken,
        private int $timeoutMs = 800,
        private int $connectTimeoutMs = 300,
        ?callable $transport = null,
        ?array $stateStore = null
    ) {
        $this->transport = $transport;
        $this->stateStore = $stateStore;
    }

    /** null nếu HOME_GRAPH_ENABLED tắt hoặc không xác định được URL. */
    public static function fromEnv(): ?self
    {
        $env = function (string $k, string $d = ''): string {
            if (function_exists('ss_env')) {
                return ss_env($k, $d);
            }
            $v = getenv($k);
            return ($v === false || trim((string)$v) === '') ? $d : trim((string)$v);
        };
        if (in_array(strtolower($env('HOME_GRAPH_ENABLED', '1')), ['0', 'false', 'off', 'no'], true)) {
            return null;
        }
        $url = $env('HOME_RECOMMEND_ENDPOINT');
        if ($url === '') {
            $chat = $env('AI_CHAT_ENDPOINT', 'http://127.0.0.1:5001/api/chat/auto');
            $p = parse_url($chat);
            if (!is_array($p) || empty($p['host'])) {
                return null;
            }
            $url = ($p['scheme'] ?? 'http') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/api/home/recommend';
        }
        return new self(
            $url,
            $env('SEARCH_INTERNAL_TOKEN'),
            max(200, (int)$env('HOME_RECOMMEND_TIMEOUT_MS', '800')),
            max(100, (int)$env('HOME_RECOMMEND_CONNECT_TIMEOUT_MS', '300'))
        );
    }

    /** @return array|null response đã validate, null nếu lỗi/timeout/circuit mở */
    public function recommend(array $payload): ?array
    {
        if ($this->isOpen()) {
            return null;
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
            error_log(sprintf('[HOME_RECOMMEND] failed request_id=%s status=%d took_ms=%d error=%s', $requestId, $status, $tookMs, substr((string)(($res['error'] ?? '') ?: ($status >= 200 && $status < 300 ? 'invalid response' : 'http error')), 0, 160)));
            return null;
        }
        $this->recordSuccess();
        return $data;
    }

    /** Validate tối thiểu theo contract. Item sai -> bỏ item; section key lạ -> bỏ; thiếu sections/degraded -> null. */
    public static function validateResponse(array $data): ?array
    {
        if (!isset($data['sections']) || !is_array($data['sections']) || !array_is_list($data['sections']) || !array_key_exists('degraded', $data)) {
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
                $reason = $item['reason'] ?? null;
                if (!is_array($reason) || !isset($reason['code']) || !is_string($reason['code'])) {
                    continue;
                }
                $items[] = ['product_id' => $pid, 'reason' => $reason];
            }
            $entry = ['key' => $section['key'], 'items' => $items];
            foreach (['seed', 'skin_type', 'concern'] as $meta) {
                $m = $section[$meta] ?? null;
                if (is_array($m) && isset($m['name']) && is_string($m['name'])) {
                    $id = $m['id'] ?? ($m['key'] ?? null);
                    $entry[$meta] = isset($m['id'])
                        ? ['id' => (string)$m['id'], 'name' => $m['name']]
                        : ['key' => is_string($id) ? $id : '', 'name' => $m['name']];
                }
            }
            $sections[] = $entry;
        }
        return [
            'degraded' => (bool)$data['degraded'],
            'sections' => $sections,
        ];
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

    // ---- circuit breaker (CacheService -> Redis RESP -> theo request) ----
    private static $redis = null; // false = đã thử và không dùng được

    private static function redis()
    {
        if (self::$redis !== null) {
            return self::$redis ?: null;
        }
        self::$redis = false;
        try {
            $url = function_exists('ss_env') ? ss_env('SEARCH_BREAKER_REDIS_URL', ss_env('REDIS_URL', '')) : (string)(getenv('SEARCH_BREAKER_REDIS_URL') ?: getenv('REDIS_URL'));
            if ($url === '') {
                return null;
            }
            $client = new SearchBreakerRedis($url);
            if (!$client->ping()) {
                return null;
            }
            self::$redis = $client;
        } catch (Throwable $e) {
            error_log('[HOME_RECOMMEND] breaker store unavailable: ' . get_class($e));
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
            error_log('[HOME_RECOMMEND] circuit open for ' . self::BREAKER_OPEN_SECONDS . 's');
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
