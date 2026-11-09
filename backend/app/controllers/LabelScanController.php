<?php
// Chụp bảng thành phần: route `chat_label_scan` (POST, JSON). Nhận ảnh (hoặc chữ thành phần đã sửa) từ widget chat,
// gắn giỏ hàng + hồ sơ da từ SESSION (không bao giờ lấy từ client), gọi Flask POST /api/label/analyze rồi trả JSON
// đã gắn thông tin sản phẩm shop. Ảnh chỉ nằm trong bộ nhớ: không move/copy/ghi đĩa, không log ảnh/chữ/email.

require_once __DIR__ . '/../services/SearchRecommendClient.php';
require_once __DIR__ . '/../services/LabelScanPresenter.php';

class LabelScanController
{
    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    public const PRODUCT_TYPES = ['cleanser', 'toner', 'serum', 'moisturizer', 'sunscreen', 'mask', 'other'];
    public const SKIN_TYPES = ['dau', 'kho', 'hon_hop', 'thuong'];
    public const TEXT_MIN = 20;
    public const TEXT_MAX = 6000;
    /** Khóa SESSION lưu bản tóm tắt lần chụp gần nhất để agent chat đọc (server-side, không tin client). */
    public const SESSION_KEY = 'label_scan_last';
    public const SESSION_TTL = 7200;

    private $pdo;
    /** @var array<string,callable> client|lookup|env|context|rate_limit|csrf — chỉ để test */
    private array $hooks;

    public function __construct($pdo = null, array $hooks = [])
    {
        $this->pdo = $pdo;
        $this->hooks = $hooks;
    }

    // ------------------------------------------------------------------ HTTP entry

    public function scan(): void
    {
        $status = 200;
        $headers = [];
        try {
            $files = $_FILES;
            $tmp = $files['image']['tmp_name'] ?? null;
            if (is_string($tmp) && $tmp !== '' && !is_uploaded_file($tmp)) {
                unset($files['image']); // chỉ nhận file do PHP upload thật
            }
            [$status, $body, $headers] = $this->process($_POST, $files, $_SERVER);
            $this->remember($body, time()); // trước mọi header()/echo
        } catch (Throwable $e) {
            error_log('[LABEL_SCAN] controller error: ' . get_class($e));
            $body = self::error('unavailable');
            $status = 200;
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
            foreach ($headers as $h) {
                header($h);
            }
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"ok":false,"code":"unavailable"}';
    }

    /**
     * Ghi bản tóm tắt lần chụp thành công vào SESSION (khách vãng lai cũng có). Lỗi nào cũng nuốt, không đụng response.
     */
    public function remember(array $body, int $now): bool
    {
        if (($body['ok'] ?? false) !== true || !is_array($body['result'] ?? null)) {
            return false;
        }
        $snapshot = self::sessionSnapshot($body['result'], $now);
        if ($snapshot === null) {
            return false;
        }
        try {
            if (isset($this->hooks['remember'])) {
                ($this->hooks['remember'])($snapshot);
                return true;
            }
            if (session_status() !== PHP_SESSION_ACTIVE && @session_start() === false) {
                error_log('[LABEL_SCAN] remember: session_start_failed');
                return false;
            }
            $_SESSION[self::SESSION_KEY] = $snapshot;
            return true;
        } catch (Throwable $e) {
            error_log('[LABEL_SCAN] remember failed: ' . get_class($e));
            return false;
        } finally {
            if (!isset($this->hooks['remember']) && session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
    }

    /**
     * Bản tóm tắt gọn của kết quả đã present() để agent đọc ở lượt chat sau. Thuần.
     * @return array<string,mixed>|null null nếu không phải status ok hoặc không có thành phần nào
     */
    public static function sessionSnapshot(array $result, int $now): ?array
    {
        if (($result['status'] ?? null) !== 'ok') {
            return null;
        }
        $cut = static function ($v, int $max): string {
            return is_string($v) ? mb_substr(trim($v), 0, $max, 'UTF-8') : '';
        };
        $names = static function ($list, int $maxItems, int $maxLen) use ($cut): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $x) {
                $t = $cut($x, $maxLen);
                if ($t !== '') {
                    $out[] = $t;
                    if (count($out) >= $maxItems) {
                        break;
                    }
                }
            }
            return $out;
        };

        $ingredients = [];
        $highlights = [];
        $items = is_array($result['ingredients']['items'] ?? null) ? $result['ingredients']['items'] : [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $n = $cut($it['name'] ?? '', 80);
            if ($n === '') {
                $n = $cut($it['name_vi'] ?? '', 80);
            }
            if ($n === '') {
                continue;
            }
            if (count($ingredients) < 60) {
                $ingredients[] = $n;
            }
            if (($it['highlight'] ?? false) === true && count($highlights) < 8) {
                $highlights[] = $n;
            }
        }
        if (!$ingredients) {
            return null;
        }

        $p = is_array($result['product'] ?? null) ? $result['product'] : [];
        $ptype = $p['product_type'] ?? 'other';
        $matched = $p['matched_product_id'] ?? null;
        if (is_int($matched)) {
            $matched = (string)$matched;
        }
        $matched = (is_string($matched) && preg_match(SearchRecommendClient::PRODUCT_ID_PATTERN, $matched)) ? $matched : '';

        $sf = is_array($result['skin_fit'] ?? null) ? $result['skin_fit'] : [];
        $prof = is_array($sf['profile'] ?? null) ? $sf['profile'] : [];
        $findings = [];
        foreach (['avoid', 'caution', 'good'] as $kind) {
            foreach (is_array($sf[$kind] ?? null) ? $sf[$kind] : [] as $f) {
                if (!is_array($f) || count($findings) >= 8) {
                    continue;
                }
                $findings[] = [
                    'verdict' => $kind,
                    'reason_vi' => $cut($f['reason_vi'] ?? '', 300),
                    'ingredients' => $names($f['ingredients'] ?? [], 6, 80),
                ];
            }
        }
        $sfVerdict = $sf['verdict'] ?? 'unknown';

        $conflicts = [];
        foreach (is_array($result['conflicts'] ?? null) ? $result['conflicts'] : [] as $c) {
            if (!is_array($c) || count($conflicts) >= 3) {
                continue;
            }
            $cname = $cut($c['cart_product_name'] ?? '', 200);
            if ($cname === '' && is_array($c['cart_product'] ?? null)) {
                $cname = $cut($c['cart_product']['name'] ?? '', 200);
            }
            $conflicts[] = [
                'cart_product_name' => $cname,
                'severity' => $cut($c['severity'] ?? '', 20),
                'advice_vi' => $cut($c['advice_vi'] ?? '', 300),
                'ingredients' => $names($c['ingredients'] ?? [], 6, 80),
            ];
        }

        return [
            'name' => $cut($p['name'] ?? '', 200),
            'brand' => $cut($p['brand'] ?? '', 100),
            'product_type' => (is_string($ptype) && in_array($ptype, self::PRODUCT_TYPES, true)) ? $ptype : 'other',
            'in_shop' => $matched !== '',
            'matched_product_id' => $matched,
            'ingredients' => $ingredients,
            'highlights' => $highlights,
            'skin_fit' => [
                'verdict' => (is_string($sfVerdict) && in_array($sfVerdict, ['good', 'caution', 'avoid', 'neutral', 'unknown'], true)) ? $sfVerdict : 'unknown',
                'profile' => [
                    'skin_type' => (is_string($prof['skin_type'] ?? null) && in_array($prof['skin_type'], self::SKIN_TYPES, true)) ? $prof['skin_type'] : null,
                    'sensitive' => ($prof['sensitive'] ?? false) === true,
                ],
                'findings' => $findings,
            ],
            'conflicts' => $conflicts,
            'scanned_at' => $now,
        ];
    }

    /**
     * Toàn bộ logic, không đụng header/echo -> test được.
     * @return array{0:int,1:array,2:string[]} [http status, body, extra headers]
     */
    public function process(array $post, array $files, array $server): array
    {
        try {
            return $this->run($post, $files, $server);
        } catch (Throwable $e) {
            error_log('[LABEL_SCAN] process error: ' . get_class($e));
            return [200, self::error('unavailable'), []];
        }
    }

    private function run(array $post, array $files, array $server): array
    {
        if (strtoupper((string)($server['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return [405, self::error('method'), ['Allow: POST']];
        }
        if (!$this->enabled()) {
            return [404, self::error('disabled'), []];
        }
        $g = $post['csrf'] ?? ($server['HTTP_X_CHAT_CSRF'] ?? '');
        $given = is_string($g) ? $g : '';
        if (!self::csrfOk($given, $this->expectedCsrf())) {
            return [403, self::error('csrf'), []];
        }
        $retry = $this->rateLimited();
        if ($retry > 0) {
            return [429, self::error('rate_limited'), ['Retry-After: ' . $retry]];
        }

        // ---- input: ảnh HOẶC chữ
        $payload = [];
        $upload = $files['image'] ?? null;
        $hasUpload = is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $rawText = is_string($post['ingredients_text'] ?? null) ? $post['ingredients_text'] : null;
        $hasText = is_string($rawText) && trim($rawText) !== '';
        if ($hasUpload === $hasText) {
            return [400, self::error('bad_request'), []];
        }
        if ($hasUpload) {
            $err = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                return [413, self::error('too_large'), []];
            }
            if ($err !== UPLOAD_ERR_OK) {
                return [400, self::error('bad_request'), []];
            }
            $max = self::maxImageBytes($this->env('LABEL_MAX_UPLOAD_MB', '5'), $this->env('LABEL_MAX_IMAGE_MB', '4'));
            $size = (int)($upload['size'] ?? 0);
            if ($size <= 0 || $size > $max) {
                return [$size > $max ? 413 : 400, self::error($size > $max ? 'too_large' : 'bad_request'), []];
            }
            $tmp = (string)($upload['tmp_name'] ?? '');
            $bytes = ($tmp !== '' && is_file($tmp)) ? file_get_contents($tmp) : false;
            if (!is_string($bytes) || $bytes === '') {
                return [400, self::error('bad_request'), []];
            }
            [$mime, $code] = self::checkImage($bytes, $max);
            if ($code !== null) {
                return [$code === 'too_large' ? 413 : 415, self::error($code), []];
            }
            $payload['image_b64'] = base64_encode($bytes);
            $payload['image_mime'] = $mime;
            unset($bytes);
        } else {
            [$text, $code] = self::checkText((string)$rawText);
            if ($code !== null) {
                return [400, self::error($code), []];
            }
            $payload['ingredients_text'] = $text;
            foreach (['product_name', 'brand'] as $k) {
                $v = isset($post[$k]) && is_string($post[$k]) ? trim($post[$k]) : '';
                if ($v !== '') {
                    $payload[$k] = mb_substr($v, 0, 200, 'UTF-8');
                }
            }
        }
        $ptype = $post['product_type'] ?? null;
        if (is_string($ptype) && in_array($ptype, self::PRODUCT_TYPES, true)) {
            $payload['product_type'] = $ptype;
        }
        $override = self::parseOverride($post['skin_type_override'] ?? null);
        if ($override !== null) {
            $payload['skin_type_override'] = $override;
        }

        // ---- ngữ cảnh phía server (không bao giờ từ client)
        $ctx = $this->context();
        $cartIds = [];
        foreach ((array)($ctx['cart_ids'] ?? []) as $cid) {
            $cid = is_scalar($cid) ? (string)$cid : '';
            if (preg_match(SearchRecommendClient::PRODUCT_ID_PATTERN, $cid) && !in_array($cid, $cartIds, true)) {
                $cartIds[] = $cid;
            }
        }
        $payload['product_ids'] = array_slice($cartIds, 0, 50);
        if (!empty($ctx['skin_profile']) && is_array($ctx['skin_profile'])) {
            $payload['skin_profile'] = $ctx['skin_profile'];
        }
        $email = is_string($ctx['email'] ?? null) ? $ctx['email'] : '';
        if ($email !== '' && strlen($email) <= 254) {
            $payload['email'] = $email;
            $conv = $post['conversation_id'] ?? null;
            if (is_string($conv) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $conv)) {
                $payload['conversation_id'] = $conv;
            }
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close(); // không giữ khóa phiên khi chờ Flask (tối đa ~25s)
        }

        // ---- gọi Flask
        $client = $this->client();
        $res = $client ? $client->labelAnalyze($payload) : null;
        unset($payload);
        if ($res === null) {
            return [200, self::error('unavailable'), []];
        }
        if (isset($res['__error'])) {
            $map = ['too_large' => 413, 'rate_limited' => 429, 'disabled' => 404];
            $code = (string)$res['__error'];
            return [$map[$code] ?? 200, self::error(isset($map[$code]) ? $code : 'unavailable'), $code === 'rate_limited' ? ['Retry-After: 60'] : []];
        }
        $result = LabelScanPresenter::present($res, $this->lookup());
        return [200, ['ok' => true, 'result' => $result], []];
    }

    // ------------------------------------------------------------------ pure helpers

    public const MESSAGES = [
        'disabled' => 'Tính năng chụp bảng thành phần hiện chưa được bật.',
        'csrf' => 'Phiên làm việc đã hết hạn. Bạn tải lại trang rồi thử lại nhé.',
        'rate_limited' => 'Bạn gửi hơi nhanh. Vui lòng đợi vài phút rồi thử lại nhé.',
        'too_large' => 'Ảnh quá lớn (tối đa 4 MB). Bạn chụp lại hoặc chọn ảnh nhỏ hơn nhé.',
        'bad_type' => 'Chỉ nhận ảnh JPG, PNG hoặc WebP.',
        'bad_text' => 'Danh sách thành phần cần từ 20 đến 6000 ký tự.',
        'bad_request' => 'Mình cần một ảnh bảng thành phần hoặc danh sách thành phần dạng chữ.',
        'method' => 'Yêu cầu không hợp lệ.',
        'unavailable' => 'Syna tạm thời chưa đọc được ảnh. Bạn có thể dán danh sách thành phần vào đây.',
    ];

    public static function error(string $code): array
    {
        return ['ok' => false, 'code' => $code, 'message_vi' => self::MESSAGES[$code] ?? self::MESSAGES['unavailable']];
    }

    public static function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }

    public static function csrfOk(string $given, string $expected): bool
    {
        return $given !== '' && $expected !== '' && hash_equals($expected, $given);
    }

    /** Giới hạn byte hiệu dụng = min(upload, giới hạn Flask), tính từ MB (chuỗi env). */
    public static function maxImageBytes(string $uploadMb, string $flaskMb): int
    {
        $up = max(1, (int)$uploadMb);
        $fl = max(1, (int)$flaskMb);
        return min($up, $fl) * 1024 * 1024;
    }

    /**
     * Kiểm byte ảnh: kích thước, MIME bằng finfo (không tin đuôi/`type` của client) và getimagesize.
     * @return array{0:?string,1:?string} [mime, mã lỗi too_large|bad_type]
     */
    public static function checkImage(string $bytes, int $maxBytes): array
    {
        if (strlen($bytes) > $maxBytes) {
            return [null, 'too_large'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->buffer($bytes);
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return [null, 'bad_type'];
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return [null, 'bad_type'];
        }
        return [$mime, null];
    }

    /** @return array{0:?string,1:?string} [chữ đã trim, mã lỗi bad_text] */
    public static function checkText(string $text): array
    {
        $text = trim($text);
        $len = mb_strlen($text, 'UTF-8');
        if ($len < self::TEXT_MIN || $len > self::TEXT_MAX) {
            return [null, 'bad_text'];
        }
        return [$text, null];
    }

    /** @return array{skin_type?:?string,sensitive?:bool}|null */
    public static function parseOverride($raw): ?array
    {
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($data)) {
            return null;
        }
        $out = [];
        if (array_key_exists('skin_type', $data) && ($data['skin_type'] === null || (is_string($data['skin_type']) && in_array($data['skin_type'], self::SKIN_TYPES, true)))) {
            $out['skin_type'] = $data['skin_type'];
        }
        if (array_key_exists('sensitive', $data) && is_bool($data['sensitive'])) {
            $out['sensitive'] = $data['sensitive'];
        }
        return $out ?: null;
    }

    // ------------------------------------------------------------------ seams (đọc env / session / DB / Flask)

    private function env(string $key, string $default): string
    {
        if (isset($this->hooks['env'])) {
            return (string)($this->hooks['env'])($key, $default);
        }
        if (function_exists('ss_env')) {
            return ss_env($key, $default);
        }
        $v = getenv($key);
        return ($v === false || trim((string)$v) === '') ? $default : trim((string)$v);
    }

    private function enabled(): bool
    {
        return self::truthy($this->env('LABEL_SCAN_ENABLED', '0'));
    }

    private function expectedCsrf(): string
    {
        if (isset($this->hooks['csrf'])) {
            return (string)($this->hooks['csrf'])();
        }
        require_once __DIR__ . '/ShopAssistantController.php';
        return ShopAssistantController::csrfToken();
    }

    /** @return int giây phải chờ (0 = cho qua). Redis lỗi -> cho qua. */
    private function rateLimited(): int
    {
        if (isset($this->hooks['rate_limit'])) {
            return (int)($this->hooks['rate_limit'])();
        }
        try {
            require_once __DIR__ . '/../services/RateLimiter.php';
            $limiter = RateLimiter::shared();
            $sid = session_id();
            if ($sid !== '') {
                $r = $limiter->hit('label_scan', substr(hash('sha256', 'label-sid|' . $sid), 0, 32), 6, 600);
                if (!$r['allowed']) {
                    return max(1, (int)$r['retry_after']);
                }
            }
            $ip = RateLimiter::clientIdentity();
            if ($ip !== null) {
                $r = $limiter->hit('label_scan_ip', $ip, 20, 600);
                if (!$r['allowed']) {
                    return max(1, (int)$r['retry_after']);
                }
            }
        } catch (Throwable $e) {
            error_log('[LABEL_SCAN] rate limit skipped: ' . get_class($e));
        }
        return 0;
    }

    /** @return array{cart_ids:string[],email:string,skin_profile:?array} */
    private function context(): array
    {
        if (isset($this->hooks['context'])) {
            return (array)($this->hooks['context'])();
        }
        $cartIds = [];
        if (!empty($_SESSION['gio_hang']) && is_array($_SESSION['gio_hang'])) {
            $cartIds = array_slice(array_map('strval', array_keys($_SESSION['gio_hang'])), 0, 50);
        }
        $email = (string)($_SESSION['user']['email'] ?? '');
        $profile = null;
        if ($email !== '') {
            try {
                require_once __DIR__ . '/../models/TaiKhoan.php';
                require_once __DIR__ . '/../services/SearchSkinProfile.php';
                $tk = new TaiKhoan($this->pdo);
                $profile = SearchSkinProfile::fromCustomer($tk->getKhachHangByEmail($email));
                $sp = $tk->getSkinProfileByEmail($email);
                $name = is_array($sp) ? ($sp['loai_da'] ?? null) : null;
                if (is_string($name) && $name !== '') {
                    $profile = ($profile ?? ['skin_type' => null, 'concerns' => []]) + ['loai_da_name' => mb_substr($name, 0, 80, 'UTF-8')];
                }
            } catch (Throwable $e) {
                error_log('[LABEL_SCAN] skin profile skipped: ' . get_class($e));
            }
        }
        return ['cart_ids' => $cartIds, 'email' => $email, 'skin_profile' => $profile];
    }

    private function client(): ?SearchRecommendClient
    {
        if (isset($this->hooks['client'])) {
            return ($this->hooks['client'])();
        }
        return SearchRecommendClient::fromEnv();
    }

    private function lookup(): callable
    {
        if (isset($this->hooks['lookup'])) {
            return $this->hooks['lookup'];
        }
        return function (array $ids): array {
            try {
                require_once __DIR__ . '/../models/SanPham.php';
                return (new SanPham($this->pdo))->findVisibleByIds($ids);
            } catch (Throwable $e) {
                error_log('[LABEL_SCAN] product lookup failed: ' . get_class($e));
                return [];
            }
        };
    }
}
