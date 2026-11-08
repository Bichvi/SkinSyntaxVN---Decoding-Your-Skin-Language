<?php
// Client PHP -> Flask POST /api/search/recommend (Smart Search Bước 06).
// - Không retry; timeout ngắn; trả null khi lỗi/timeout/circuit mở/response sai cấu trúc.
// - Circuit breaker: 3 lỗi liên tiếp -> bỏ qua Flask 60s. Trạng thái lưu Valkey/Redis qua CacheService
//   (không có APCu) hoặc Redis qua RESP; không có -> chỉ theo request.
// - Không log token, user hash, hồ sơ da.
require_once __DIR__ . '/RedisLite.php';

final class SearchRecommendClient
{
    public const SECTION_KEYS = ['same_brand', 'category_alt', 'personalized', 'semantic', 'trending'];
    public const REASON_CODES = ['same_brand', 'same_category', 'shared_ingredients', 'fits_skin_type', 'targets_concern', 'semantic_match', 'trending', 'bought_together'];
    public const PRODUCT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';
    public const BREAKER_FAILS = 3;
    public const BREAKER_OPEN_SECONDS = 60;
    private const BREAKER_KEY = 'search:recommend:breaker:v1';
    private const INTENT_BREAKER_KEY = 'search:intent:breaker:v1'; // U05: breaker riêng, intent chậm không làm mở breaker của recommend
    // U09 (từ review U08): mỗi endpoint Flask một breaker riêng — similar/cart chậm khi Neo4j chết không được che luôn recommend.
    private const SIMILAR_BREAKER_KEY = 'search:similar:breaker:v1';
    private const CART_BREAKER_KEY = 'search:cart:breaker:v1';
    private const LABEL_BREAKER_KEY = 'label:breaker:v1'; // chụp bảng thành phần: breaker riêng, vision chậm không che recommend/cart
    public const INTENT_TYPES = ['fuzzy', 'synonym'];

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
    public function recommend(string $q, ?string $userHash, ?array $skinProfile, array $cartIds, array $excludeIds, int $limitPerSection = 8, ?string $sessionHash = null): ?array
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
        if ($sessionHash !== null && preg_match('/^[A-Fa-f0-9]{8,128}$/', $sessionHash)) {
            $payload['session_hash'] = $sessionHash; // U12: đã băm; Flask chỉ dùng để chia nhánh A/B
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

    /**
     * U05: GET /api/search/intent?q= -> ['corrected_q' => string, 'corrections' => [['from','to','type']]] hoặc null
     * khi lỗi/timeout/breaker mở/response sai cấu trúc. Timeout ngắn (SEARCH_INTENT_TIMEOUT_MS, mặc định 300ms), không retry.
     */
    public function intent(string $q): ?array
    {
        $q = trim(mb_substr($q, 0, 200, 'UTF-8'));
        if ($q === '' || $this->isOpen(self::INTENT_BREAKER_KEY)) {
            return null;
        }
        $env = static function (string $k, string $d): string {
            return function_exists('ss_env') ? ss_env($k, $d) : (string)(getenv($k) ?: $d);
        };
        $timeoutMs = max(100, min($this->timeoutMs, (int)$env('SEARCH_INTENT_TIMEOUT_MS', '300')));
        $url = $this->intentUrl() . '?q=' . rawurlencode($q);
        $headers = ['Accept: application/json', 'X-Request-Id: ' . self::requestId()];
        if ($this->internalToken !== '') {
            $headers[] = 'X-Internal-Token: ' . $this->internalToken;
        }
        $res = $this->transport
            ? ($this->transport)($url, '', $headers, $timeoutMs, min($this->connectTimeoutMs, $timeoutMs), 'GET')
            : $this->curlGet($url, $headers, $timeoutMs);
        $status = (int)($res['status'] ?? 0);
        $data = null;
        if ($status >= 200 && $status < 300) {
            $decoded = json_decode((string)($res['body'] ?? ''), true);
            $data = is_array($decoded) ? self::validateIntentResponse($decoded) : null;
        }
        if ($data === null) {
            $this->recordFailure(self::INTENT_BREAKER_KEY);
            error_log(sprintf('[SEARCH_INTENT] failed status=%d error=%s', $status, substr((string)(($res['error'] ?? '') ?: 'invalid response'), 0, 120)));
            return null;
        }
        $this->recordSuccess(self::INTENT_BREAKER_KEY);
        return $data;
    }

    /**
     * U11: GET /api/ops/health (Neo4j/Chroma/Redis/Mongo, stream backlog, job status, alerts) cho trang admin chỉ đọc.
     * Không dùng breaker (chỉ admin gọi, ít khi); lỗi/timeout/response lạ => null. Chỉ giữ các khóa đã biết.
     * @return array|null
     */
    public function opsHealth(int $timeoutMs = 4000): ?array
    {
        $url = preg_replace('#/api/search/recommend/?$#', '/api/ops/health', $this->baseUrl);
        if (!is_string($url) || $url === $this->baseUrl) {
            $url = rtrim($this->baseUrl, '/') . '/api/ops/health';
        }
        $headers = ['Accept: application/json', 'X-Request-Id: ' . self::requestId()];
        if ($this->internalToken !== '') {
            $headers[] = 'X-Internal-Token: ' . $this->internalToken;
        }
        $res = $this->transport
            ? ($this->transport)($url, '', $headers, $timeoutMs, min($this->connectTimeoutMs, $timeoutMs), 'GET')
            : $this->curlGet($url, $headers, $timeoutMs);
        $status = (int)($res['status'] ?? 0);
        $data = ($status >= 200 && $status < 300) ? json_decode((string)($res['body'] ?? ''), true) : null;
        if (!is_array($data) || !isset($data['alerts']) || !is_array($data['alerts'])) {
            error_log(sprintf('[SEARCH_OPS] health failed status=%d', $status));
            return null;
        }
        return array_intersect_key($data, array_flip(['generated_at', 'ok', 'alerts', 'neo4j', 'chroma', 'mongo', 'redis', 'drift', 'jobs']));
    }

    /**
     * U08: GET /api/products/<id>/similar -> ['degraded'=>bool,'source'=>?string,'items'=>[['product_id','score','reason_code','reason_params']]]
     * hoặc null khi lỗi/timeout/breaker mở/response sai cấu trúc. Breaker RIÊNG (`search:similar:breaker:v1`, 3 lỗi liên tiếp
     * -> bỏ qua 60s). Timeout SEARCH_SIMILAR_TIMEOUT_MS (mặc định 800ms), không retry.
     */
    public function similar(string $productId, ?string $skinType, array $excludeIds, int $limit = 8): ?array
    {
        $productId = trim($productId);
        if (!preg_match(self::PRODUCT_ID_PATTERN, $productId) || $this->isOpen(self::SIMILAR_BREAKER_KEY)) {
            return null;
        }
        $env = static function (string $k, string $d): string {
            return function_exists('ss_env') ? ss_env($k, $d) : (string)(getenv($k) ?: $d);
        };
        $timeoutMs = max(100, min(5000, (int)$env('SEARCH_SIMILAR_TIMEOUT_MS', '800')));
        $query = ['limit' => max(1, min(20, $limit))];
        $exclude = self::cleanIds($excludeIds, 50);
        if ($exclude) {
            $query['exclude'] = implode(',', $exclude);
        }
        if ($skinType !== null && preg_match('/^[a-z0-9_]{1,32}$/', $skinType)) {
            $query['skin_type'] = $skinType;
        }
        $url = $this->similarUrl($productId) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $requestId = self::requestId();
        $headers = ['Accept: application/json', 'X-Request-Id: ' . $requestId];
        if ($this->internalToken !== '') {
            $headers[] = 'X-Internal-Token: ' . $this->internalToken;
        }
        $started = microtime(true);
        $res = $this->transport
            ? ($this->transport)($url, '', $headers, $timeoutMs, min($this->connectTimeoutMs, $timeoutMs), 'GET')
            : $this->curlGet($url, $headers, $timeoutMs);
        $tookMs = (int)round((microtime(true) - $started) * 1000);
        $status = (int)($res['status'] ?? 0);
        $data = null;
        if ($status >= 200 && $status < 300) {
            $decoded = json_decode((string)($res['body'] ?? ''), true);
            $data = is_array($decoded) ? self::validateSimilarResponse($decoded) : null;
        }
        if ($data === null) {
            $this->recordFailure(self::SIMILAR_BREAKER_KEY);
            error_log(sprintf('[SEARCH_SIMILAR] failed request_id=%s status=%d took_ms=%d error=%s', $requestId, $status, $tookMs, substr((string)(($res['error'] ?? '') ?: ($status >= 200 && $status < 300 ? 'invalid response' : 'http error')), 0, 160)));
            return null;
        }
        $this->recordSuccess(self::SIMILAR_BREAKER_KEY);
        return $data;
    }

    public const CART_SEVERITIES = ['high', 'medium', 'low'];
    public const CART_TIMINGS = ['same_routine', 'same_session', 'ok_alternate_days'];
    public const CART_REASON_CODES = ['completes_routine', 'fits_skin_type'];
    public const STEP_PATTERN = '/^[a-z0-9_]{1,40}$/';

    /**
     * U09: POST /api/cart/analyze -> ['conflicts'=>[...],'routine'=>['has','missing','labels'],'suggestions'=>[['step','items']],'degraded'=>bool]
     * hoặc null khi lỗi/timeout/breaker mở/response sai cấu trúc. Breaker RIÊNG (`search:cart:breaker:v1`), timeout
     * CART_ANALYZE_TIMEOUT_MS (mặc định 1000ms), không retry. Giỏ hàng luôn chạy được khi trả null.
     */
    public function cartAnalyze(array $productIds, ?array $skinProfile = null): ?array
    {
        $ids = self::cleanIds($productIds, 50);
        if (!$ids || $this->isOpen(self::CART_BREAKER_KEY)) {
            return null;
        }
        $env = static function (string $k, string $d): string {
            return function_exists('ss_env') ? ss_env($k, $d) : (string)(getenv($k) ?: $d);
        };
        $timeoutMs = max(100, min(5000, (int)$env('CART_ANALYZE_TIMEOUT_MS', '1000')));
        $payload = ['product_ids' => $ids];
        $skinType = is_array($skinProfile) ? ($skinProfile['skin_type'] ?? null) : null;
        if (is_string($skinType) && preg_match('/^[a-z0-9_]{1,32}$/', $skinType)) {
            $payload['skin_profile'] = ['skin_type' => $skinType];
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return null;
        }
        $requestId = self::requestId();
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'X-Request-Id: ' . $requestId];
        if ($this->internalToken !== '') {
            $headers[] = 'X-Internal-Token: ' . $this->internalToken;
        }
        $url = $this->originUrl() . '/api/cart/analyze';
        $started = microtime(true);
        $res = $this->transport
            ? ($this->transport)($url, $body, $headers, $timeoutMs, min($this->connectTimeoutMs, $timeoutMs), 'POST')
            : $this->curlPost($body, $headers, $url, $timeoutMs);
        $tookMs = (int)round((microtime(true) - $started) * 1000);
        $status = (int)($res['status'] ?? 0);
        $data = null;
        if ($status >= 200 && $status < 300) {
            $decoded = json_decode((string)($res['body'] ?? ''), true);
            $data = is_array($decoded) ? self::validateCartResponse($decoded) : null;
        }
        if ($data === null) {
            $this->recordFailure(self::CART_BREAKER_KEY);
            error_log(sprintf('[SEARCH_CART] failed request_id=%s status=%d took_ms=%d error=%s', $requestId, $status, $tookMs, substr((string)(($res['error'] ?? '') ?: ($status >= 200 && $status < 300 ? 'invalid response' : 'http error')), 0, 160)));
            return null;
        }
        $this->recordSuccess(self::CART_BREAKER_KEY);
        return $data;
    }

    /**
     * Chụp bảng thành phần: POST /api/label/analyze. Trả mảng đã validate khi 200; 413 -> ['__error'=>'too_large'],
     * 429 -> ['__error'=>'rate_limited'], 404 -> ['__error'=>'disabled']; lỗi/timeout/breaker mở/response sai -> null.
     * Breaker RIÊNG (`label:breaker:v1`), timeout LABEL_ANALYZE_TIMEOUT_MS (mặc định 25000, kẹp 1000..30000), không retry.
     * Không log nội dung payload (có ảnh/email).
     */
    public function labelAnalyze(array $payload, ?string $requestId = null): ?array
    {
        if ($this->isOpen(self::LABEL_BREAKER_KEY)) {
            return null;
        }
        $env = static function (string $k, string $d): string {
            return function_exists('ss_env') ? ss_env($k, $d) : (string)(getenv($k) ?: $d);
        };
        $timeoutMs = max(1000, min(30000, (int)$env('LABEL_ANALYZE_TIMEOUT_MS', '25000')));
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            return null;
        }
        if ($requestId === null || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $requestId)) {
            $requestId = self::requestId();
        }
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'X-Request-Id: ' . $requestId];
        if ($this->internalToken !== '') {
            $headers[] = 'X-Internal-Token: ' . $this->internalToken;
        }
        $url = $this->originUrl() . '/api/label/analyze';
        $started = microtime(true);
        $res = $this->transport
            ? ($this->transport)($url, $body, $headers, $timeoutMs, min($this->connectTimeoutMs, $timeoutMs), 'POST')
            : $this->curlPost($body, $headers, $url, $timeoutMs);
        $tookMs = (int)round((microtime(true) - $started) * 1000);
        $status = (int)($res['status'] ?? 0);
        $errors = [413 => 'too_large', 429 => 'rate_limited', 404 => 'disabled'];
        if (isset($errors[$status])) {
            return ['__error' => $errors[$status]];
        }
        $data = null;
        if ($status >= 200 && $status < 300) {
            $decoded = json_decode((string)($res['body'] ?? ''), true);
            $data = is_array($decoded) ? self::validateLabelResponse($decoded) : null;
        }
        if ($data === null) {
            if ($status === 0 || $status >= 500 || ($status >= 200 && $status < 300)) {
                $this->recordFailure(self::LABEL_BREAKER_KEY);
            }
            error_log(sprintf('[LABEL_SCAN] failed request_id=%s status=%d took_ms=%d', $requestId, $status, $tookMs));
            return null;
        }
        $this->recordSuccess(self::LABEL_BREAKER_KEY);
        return $data;
    }

    public const LABEL_STATUSES = ['ok', 'not_label', 'unreadable', 'vision_unavailable'];
    public const LABEL_PRODUCT_TYPES = ['cleanser', 'toner', 'serum', 'moisturizer', 'sunscreen', 'mask', 'other'];
    public const LABEL_WARNINGS = ['low_recognition', 'graph_degraded', 'profile_missing', 'cart_empty', 'internal_error'];
    public const LABEL_ALT_REASONS = ['gentler_fits_skin', 'no_cart_conflict'];

    /** Validate theo contract label_analyze_response.schema.json: chỉ giữ khóa đã biết, kẹp độ dài; sai kiểu -> null. */
    public static function validateLabelResponse(array $data): ?array
    {
        $str = static function ($v, int $max): ?string {
            return is_string($v) ? mb_substr($v, 0, $max, 'UTF-8') : null;
        };
        $strList = static function ($v, int $maxItems, int $maxLen): array {
            $out = [];
            foreach (is_array($v) ? array_slice(array_values($v), 0, $maxItems) : [] as $x) {
                if (is_string($x) && $x !== '') {
                    $out[] = mb_substr($x, 0, $maxLen, 'UTF-8');
                }
            }
            return $out;
        };
        $idOk = static function ($v): ?string {
            if (is_int($v)) {
                $v = (string)$v;
            }
            return (is_string($v) && preg_match(self::PRODUCT_ID_PATTERN, $v)) ? $v : null;
        };
        $status = $data['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::LABEL_STATUSES, true)) {
            return null;
        }
        if (!isset($data['product'], $data['ingredients']) || !is_array($data['product']) || !is_array($data['ingredients'])) {
            return null;
        }
        if (isset($data['skin_fit']) && !is_array($data['skin_fit'])) {
            return null;
        }
        foreach (['conflicts', 'schedule', 'alternatives', 'warnings'] as $k) {
            if (isset($data[$k]) && (!is_array($data[$k]) || !array_is_list($data[$k]))) {
                return null;
            }
        }

        $p = $data['product'];
        $matched = $p['matched_product_id'] ?? null;
        $score = $p['match_score'] ?? null;
        $ptype = $p['product_type'] ?? 'other';
        $product = [
            'name' => $str($p['name'] ?? '', 200) ?? '',
            'brand' => $str($p['brand'] ?? '', 200) ?? '',
            'product_type' => in_array($ptype, self::LABEL_PRODUCT_TYPES, true) ? $ptype : 'other',
            'matched_product_id' => $matched === null ? null : $idOk($matched),
            'match_score' => (is_int($score) || is_float($score)) ? max(0.0, min(1.0, (float)$score)) : null,
        ];

        $ing = $data['ingredients'];
        $rawItems = $ing['items'] ?? [];
        if (!is_array($rawItems) || !array_is_list($rawItems)) {
            return null;
        }
        $items = [];
        foreach (array_slice($rawItems, 0, 120) as $it) {
            if (!is_array($it) || !is_string($it['key'] ?? null) || !is_string($it['name'] ?? null)) {
                continue;
            }
            $items[] = [
                'key' => mb_substr($it['key'], 0, 120, 'UTF-8'),
                'name' => mb_substr($it['name'], 0, 160, 'UTF-8'),
                'name_vi' => $str($it['name_vi'] ?? null, 160),
                'role' => $str($it['role'] ?? null, 80),
                'rank' => is_int($it['rank'] ?? null) ? $it['rank'] : null,
                'highlight' => ($it['highlight'] ?? false) === true,
            ];
        }
        $source = $ing['source'] ?? 'photo';
        $ingredients = [
            'source' => in_array($source, ['catalog', 'photo', 'user_edit'], true) ? $source : 'photo',
            'total' => is_int($ing['total'] ?? null) ? max(0, $ing['total']) : count($items),
            'recognized' => is_int($ing['recognized'] ?? null) ? max(0, $ing['recognized']) : 0,
            'items' => $items,
            'raw_text' => $str($ing['raw_text'] ?? '', 6000) ?? '',
        ];

        $conflicts = [];
        foreach (array_slice($data['conflicts'] ?? [], 0, 20) as $c) {
            $cid = is_array($c) ? $idOk($c['cart_product_id'] ?? null) : null;
            if ($cid === null || !in_array($c['severity'] ?? null, self::CART_SEVERITIES, true) || !in_array($c['timing'] ?? null, self::CART_TIMINGS, true)) {
                continue;
            }
            $entry = [
                'cart_product_id' => $cid,
                'severity' => $c['severity'],
                'timing' => $c['timing'],
                'ingredients' => $strList($c['ingredients'] ?? [], 8, 80),
                'advice_vi' => $str($c['advice_vi'] ?? '', 600) ?? '',
                'rule_id' => (is_string($c['rule_id'] ?? null) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $c['rule_id'])) ? $c['rule_id'] : '',
                'evidence' => $str($c['evidence'] ?? '', 400) ?? '',
            ];
            if (is_string($c['cart_product_name'] ?? null)) {
                $entry['cart_product_name'] = mb_substr($c['cart_product_name'], 0, 200, 'UTF-8');
            }
            $conflicts[] = $entry;
        }

        $slot = static function ($v) {
            return ($v === 'am' || $v === 'pm') ? $v : null;
        };
        $schedule = [];
        foreach (array_slice($data['schedule'] ?? [], 0, 20) as $s) {
            $cid = is_array($s) ? $idOk($s['cart_product_id'] ?? null) : null;
            if ($cid === null || !in_array($s['kind'] ?? null, ['split_session', 'alternate_nights', 'alternate_days'], true)) {
                continue;
            }
            $schedule[] = [
                'cart_product_id' => $cid,
                'scan_slot' => $slot($s['scan_slot'] ?? null),
                'cart_slot' => $slot($s['cart_slot'] ?? null),
                'kind' => $s['kind'],
                'reason_vi' => $str($s['reason_vi'] ?? '', 400) ?? '',
            ];
        }

        $sf = is_array($data['skin_fit'] ?? null) ? $data['skin_fit'] : [];
        $prof = is_array($sf['profile'] ?? null) ? $sf['profile'] : [];
        $findings = static function ($list) use ($str, $strList): array {
            $out = [];
            foreach (is_array($list) ? array_slice(array_values($list), 0, 10) : [] as $f) {
                if (!is_array($f) || !is_string($f['rule_id'] ?? null)) {
                    continue;
                }
                $out[] = [
                    'rule_id' => mb_substr($f['rule_id'], 0, 64, 'UTF-8'),
                    'reason_vi' => $str($f['reason_vi'] ?? '', 400) ?? '',
                    'ingredients' => $strList($f['ingredients'] ?? [], 8, 80),
                ];
            }
            return $out;
        };
        $verdict = $sf['verdict'] ?? 'unknown';
        $skinFit = [
            'verdict' => in_array($verdict, ['good', 'caution', 'avoid', 'neutral', 'unknown'], true) ? $verdict : 'unknown',
            'profile' => [
                'skin_type' => in_array($prof['skin_type'] ?? null, ['dau', 'kho', 'hon_hop', 'thuong'], true) ? $prof['skin_type'] : null,
                'sensitive' => ($prof['sensitive'] ?? false) === true,
                'concerns' => $strList($prof['concerns'] ?? [], 20, 40),
            ],
            'good' => $findings($sf['good'] ?? []),
            'caution' => $findings($sf['caution'] ?? []),
            'avoid' => $findings($sf['avoid'] ?? []),
        ];

        $alternatives = [];
        foreach (array_slice($data['alternatives'] ?? [], 0, 3) as $a) {
            $pid = is_array($a) ? $idOk($a['product_id'] ?? null) : null;
            if ($pid === null || !in_array($a['reason_code'] ?? null, self::LABEL_ALT_REASONS, true)) {
                continue;
            }
            $alternatives[] = [
                'product_id' => $pid,
                'reason_code' => $a['reason_code'],
                'reason_params' => $strList($a['reason_params'] ?? [], 4, 80),
            ];
        }

        $warnings = [];
        foreach (array_slice($data['warnings'] ?? [], 0, 10) as $w) {
            if (is_string($w) && in_array($w, self::LABEL_WARNINGS, true) && !in_array($w, $warnings, true)) {
                $warnings[] = $w;
            }
        }

        return [
            'status' => $status,
            'product' => $product,
            'ingredients' => $ingredients,
            'conflicts' => $conflicts,
            'schedule' => $schedule,
            'skin_fit' => $skinFit,
            'alternatives' => $alternatives,
            'warnings' => $warnings,
            'summary_vi' => $str($data['summary_vi'] ?? '', 600) ?? '',
            'sunscreen_note' => ($data['sunscreen_note'] ?? false) === true,
            'took_ms' => is_int($data['took_ms'] ?? null) ? max(0, $data['took_ms']) : 0,
        ];
    }

    private function originUrl(): string
    {
        $p = parse_url($this->baseUrl);
        return (is_array($p) && !empty($p['host']))
            ? (($p['scheme'] ?? 'http') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''))
            : rtrim($this->baseUrl, '/');
    }

    /** Validate theo contract cart_analyze_response.schema.json. Mục sai -> bỏ mục; thiếu cấu trúc chính -> null. */
    public static function validateCartResponse(array $data): ?array
    {
        if (!isset($data['conflicts'], $data['routine'], $data['suggestions'])
            || !is_array($data['conflicts']) || !array_is_list($data['conflicts'])
            || !is_array($data['routine']) || !is_array($data['suggestions']) || !array_is_list($data['suggestions'])) {
            return null;
        }
        $conflicts = [];
        foreach (array_slice($data['conflicts'], 0, 50) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $a = is_int($c['a'] ?? null) ? (string)$c['a'] : ($c['a'] ?? null);
            $b = is_int($c['b'] ?? null) ? (string)$c['b'] : ($c['b'] ?? null);
            if (!is_string($a) || !is_string($b) || !preg_match(self::PRODUCT_ID_PATTERN, $a) || !preg_match(self::PRODUCT_ID_PATTERN, $b)
                || !in_array($c['severity'] ?? null, self::CART_SEVERITIES, true)
                || !in_array($c['timing'] ?? null, self::CART_TIMINGS, true)) {
                continue;
            }
            $ings = [];
            foreach (array_slice(array_values((array)($c['ingredients'] ?? [])), 0, 4) as $n) {
                if (is_string($n) && trim($n) !== '') {
                    $ings[] = mb_substr(trim($n), 0, 80, 'UTF-8');
                }
            }
            $rule = (is_string($c['rule_id'] ?? null) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $c['rule_id'])) ? $c['rule_id'] : '';
            $conflicts[] = [
                'a' => $a, 'b' => $b, 'severity' => $c['severity'], 'timing' => $c['timing'], 'ingredients' => $ings,
                'rule_id' => $rule,
                'advice_vi' => is_string($c['advice_vi'] ?? null) ? mb_substr(trim($c['advice_vi']), 0, 400, 'UTF-8') : '',
            ];
        }
        $steps = static function ($list): array {
            $out = [];
            foreach (array_slice(array_values((array)$list), 0, 12) as $k) {
                if (is_string($k) && preg_match(SearchRecommendClient::STEP_PATTERN, $k) && !in_array($k, $out, true)) {
                    $out[] = $k;
                }
            }
            return $out;
        };
        $labels = [];
        foreach ((array)($data['routine']['labels'] ?? []) as $k => $v) {
            if (is_string($k) && preg_match(self::STEP_PATTERN, $k) && is_string($v) && trim($v) !== '' && count($labels) < 12) {
                $labels[$k] = mb_substr(trim($v), 0, 60, 'UTF-8');
            }
        }
        $suggestions = [];
        foreach (array_slice($data['suggestions'], 0, 3) as $s) {
            if (!is_array($s) || !is_string($s['step'] ?? null) || !preg_match(self::STEP_PATTERN, $s['step']) || !is_array($s['items'] ?? null)) {
                continue;
            }
            $items = [];
            $seen = [];
            foreach (array_slice(array_values($s['items']), 0, 4) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $pid = $item['product_id'] ?? null;
                if (is_int($pid)) {
                    $pid = (string)$pid;
                }
                $code = $item['reason_code'] ?? null;
                if (!is_string($pid) || !preg_match(self::PRODUCT_ID_PATTERN, $pid) || isset($seen[$pid])
                    || !is_string($code) || !in_array($code, self::CART_REASON_CODES, true)) {
                    continue;
                }
                $seen[$pid] = true;
                $params = [];
                foreach (array_slice(array_values((array)($item['reason_params'] ?? [])), 0, 3) as $param) {
                    if (is_string($param)) {
                        $params[] = $param;
                    }
                }
                $items[] = [
                    'product_id' => $pid,
                    'score' => is_numeric($item['score'] ?? null) ? (float)$item['score'] : 0.0,
                    'reason_code' => $code,
                    'reason_params' => $params,
                ];
            }
            if ($items) {
                $suggestions[] = ['step' => $s['step'], 'items' => $items];
            }
        }
        return [
            'conflicts' => $conflicts,
            'routine' => [
                'has' => $steps($data['routine']['has'] ?? []),
                'missing' => $steps($data['routine']['missing'] ?? []),
                'labels' => $labels,
                'order' => $steps($data['routine']['order'] ?? []),
            ],
            'suggestions' => $suggestions,
            'degraded' => (bool)($data['degraded'] ?? false),
        ];
    }

    /** ".../api/search/recommend" -> ".../api/products/<id>/similar" (cùng scheme/host/port). */
    private function similarUrl(string $productId): string
    {
        $p = parse_url($this->baseUrl);
        $origin = (is_array($p) && !empty($p['host']))
            ? (($p['scheme'] ?? 'http') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''))
            : rtrim($this->baseUrl, '/');
        return $origin . '/api/products/' . rawurlencode($productId) . '/similar';
    }

    public const SIMILAR_REASON_CODES = ['shared_ingredients', 'same_category', 'same_brand'];

    /** Validate theo contract similar_response.schema.json. Item sai -> bỏ item; cấu trúc sai -> null. */
    public static function validateSimilarResponse(array $data): ?array
    {
        if (!isset($data['items']) || !is_array($data['items']) || !array_is_list($data['items'])) {
            return null;
        }
        $items = [];
        $seen = [];
        foreach (array_slice($data['items'], 0, 20) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $pid = $item['product_id'] ?? null;
            if (is_int($pid)) {
                $pid = (string)$pid;
            }
            $code = $item['reason_code'] ?? null;
            if (!is_string($pid) || !preg_match(self::PRODUCT_ID_PATTERN, $pid) || isset($seen[$pid])
                || !is_string($code) || !in_array($code, self::SIMILAR_REASON_CODES, true)) {
                continue;
            }
            $seen[$pid] = true;
            $params = [];
            foreach (array_slice(array_values((array)($item['reason_params'] ?? [])), 0, 3) as $param) {
                if (is_string($param)) {
                    $params[] = $param;
                }
            }
            $items[] = [
                'product_id' => $pid,
                'score' => is_numeric($item['score'] ?? null) ? (float)$item['score'] : 0.0,
                'reason_code' => $code,
                'reason_params' => $params,
            ];
        }
        $source = $data['source'] ?? null;
        return [
            'degraded' => (bool)($data['degraded'] ?? false),
            'source' => in_array($source, ['graph', 'semantic'], true) ? $source : null,
            'items' => $items,
        ];
    }

    private function intentUrl(): string
    {
        $replaced = preg_replace('#/recommend/?$#', '/intent', $this->baseUrl);
        return ($replaced !== null && $replaced !== $this->baseUrl) ? $replaced : rtrim($this->baseUrl, '/') . '/intent';
    }

    /** @return array{corrected_q:string, corrections:list<array{from:string,to:string,type:string}>}|null */
    public static function validateIntentResponse(array $data): ?array
    {
        if (!isset($data['corrected_q']) || !is_string($data['corrected_q']) || !isset($data['corrections']) || !is_array($data['corrections']) || !array_is_list($data['corrections'])) {
            return null;
        }
        $corrections = [];
        foreach (array_slice($data['corrections'], 0, 6) as $c) {
            if (!is_array($c) || !is_string($c['from'] ?? null) || !is_string($c['to'] ?? null) || !in_array($c['type'] ?? null, self::INTENT_TYPES, true)) {
                continue;
            }
            $corrections[] = [
                'from' => mb_substr($c['from'], 0, 80, 'UTF-8'),
                'to' => mb_substr($c['to'], 0, 80, 'UTF-8'),
                'type' => $c['type'],
            ];
        }
        return ['corrected_q' => mb_substr(trim($data['corrected_q']), 0, 200, 'UTF-8'), 'corrections' => $corrections];
    }

    /**
     * Câu truy vấn đã sửa nếu response có ít nhất một correction loại "fuzzy" (gõ sai tên hãng) và câu sửa khác câu gốc
     * (không phân biệt hoa/thường, khoảng trắng). Chỉ "synonym" (viết tắt) hoặc không có gì -> null.
     */
    public static function fuzzyCorrectedQuery(?array $intent, string $q): ?string
    {
        if ($intent === null) {
            return null;
        }
        $hasFuzzy = false;
        foreach ($intent['corrections'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'fuzzy') {
                $hasFuzzy = true;
                break;
            }
        }
        $corrected = trim((string)($intent['corrected_q'] ?? ''));
        if (!$hasFuzzy || $corrected === '') {
            return null;
        }
        $norm = static fn(string $t): string => mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $t)), 'UTF-8');
        return $norm($corrected) === $norm($q) ? null : $corrected;
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
        $out = [
            'degraded' => (bool)($data['degraded'] ?? false),
            'degraded_branches' => array_values(array_filter((array)($data['degraded_branches'] ?? []), 'is_string')),
            'intent' => $intent,
            'sections' => $sections,
        ];
        // U12 (tùy chọn): nhánh A/B của request; chỉ nhận id/variant đúng mẫu, còn lại bỏ.
        $exp = $data['experiment'] ?? null;
        if (is_array($exp) && is_string($exp['id'] ?? null) && is_string($exp['variant'] ?? null)
            && preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $exp['id']) && preg_match('/^[a-z0-9][a-z0-9_-]{0,19}$/', $exp['variant'])) {
            $out['experiment'] = ['id' => $exp['id'], 'variant' => $exp['variant']];
        }
        return $out;
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

    private function curlPost(string $body, array $headers, ?string $url = null, ?int $timeoutMs = null): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'curl unavailable'];
        }
        $ch = curl_init($url ?? $this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => $timeoutMs ?? $this->timeoutMs,
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

    private function curlGet(string $url, array $headers, int $timeoutMs): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'curl unavailable'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($this->connectTimeoutMs, $timeoutMs),
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

    private function loadState(string $key = self::BREAKER_KEY): array
    {
        $empty = ['fails' => 0, 'open_until' => 0];
        if ($this->stateStore) {
            $s = ($this->stateStore['get'])($key);
            return is_array($s) ? $s : $empty;
        }
        try {
            if (class_exists('CacheService') && CacheService::getInstance()->isAvailable()) {
                $s = CacheService::getInstance()->get($key);
                return is_array($s) ? $s : $empty;
            }
            if ($r = self::redis()) {
                $s = json_decode((string)$r->get($key), true);
                return is_array($s) ? $s : $empty;
            }
        } catch (Throwable $e) {
            // rơi xuống trạng thái theo request
        }
        return (self::$requestState[$key] ?? null) ?: $empty;
    }

    private function saveState(array $s, string $key = self::BREAKER_KEY): void
    {
        if ($this->stateStore) {
            ($this->stateStore['set'])($key, $s);
            return;
        }
        try {
            if (class_exists('CacheService') && CacheService::getInstance()->isAvailable()) {
                CacheService::getInstance()->set($key, $s, self::BREAKER_OPEN_SECONDS * 5);
                return;
            }
            if ($r = self::redis()) {
                $r->setex($key, self::BREAKER_OPEN_SECONDS * 5, json_encode($s));
                return;
            }
        } catch (Throwable $e) {
        }
        self::$requestState[$key] = $s;
    }

    public function isOpen(string $key = self::BREAKER_KEY): bool
    {
        $s = $this->loadState($key);
        return (int)($s['open_until'] ?? 0) > time();
    }

    private function recordFailure(string $key = self::BREAKER_KEY): void
    {
        $s = $this->loadState($key);
        $fails = (int)($s['fails'] ?? 0) + 1;
        $openUntil = (int)($s['open_until'] ?? 0);
        if ($fails >= self::BREAKER_FAILS) {
            $openUntil = time() + self::BREAKER_OPEN_SECONDS;
            $fails = 0;
            error_log('[SEARCH_RECOMMEND] circuit open for ' . self::BREAKER_OPEN_SECONDS . 's');
        }
        $this->saveState(['fails' => $fails, 'open_until' => $openUntil], $key);
    }

    private function recordSuccess(string $key = self::BREAKER_KEY): void
    {
        $s = $this->loadState($key);
        if ((int)($s['fails'] ?? 0) !== 0 || (int)($s['open_until'] ?? 0) !== 0) {
            $this->saveState(['fails' => 0, 'open_until' => 0], $key);
        }
    }
}

/**
 * Giữ lại cho tương thích (breaker + test cũ): bọc RedisLite (U03) với API get/setex/ping như trước.
 * Timeout 200ms; lỗi -> null/false, không ném.
 */
final class SearchBreakerRedis
{
    private RedisLite $redis;

    public function __construct(string $url)
    {
        $this->redis = new RedisLite($url);
    }

    public function ping(): bool
    {
        return $this->redis->ping();
    }

    public function get(string $key): ?string
    {
        return $this->redis->get($key);
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        return $this->redis->setex($key, $ttl, $value);
    }
}
