<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/ChatAgentGate.php';
require_once __DIR__ . '/../services/ChatAgentAudit.php';
require_once __DIR__ . '/../services/ChatDraftService.php';
require_once __DIR__ . '/../services/ChatOrderConfirmService.php';
require_once __DIR__ . '/../services/ChatAgentToken.php';
require_once __DIR__ . '/../services/ChatAgentIdentity.php';
require_once __DIR__ . '/../services/ChatCartEffect.php';
require_once __DIR__ . '/../services/ChatSseRelay.php';
require_once __DIR__ . '/../services/RateLimiter.php';
require_once __DIR__ . '/ShopAssistantController.php';

final class ChatAgentController
{
    public function __construct(
        private $db = null,
        private ?ChatOrderConfirmService $confirmService = null,
        private ?ChatAgentGate $gate = null,
        private ?RateLimiter $limiter = null,
        private ?ChatAgentAudit $audit = null,
        private $loggedInResolver = null,
        private $userResolver = null,
        private $csrfResolver = null,
        private $bodyReader = null,
        private $purchaseEnabledResolver = null,
        private $clock = null,
        private ?string $internalSecret = null,
        private $profileResolver = null
    ) {
    }

    public function stream(): void
    {
        $gate = $this->gate ??= self::defaultGate();
        $internalSecret = $this->internalSecret ?? (defined('CHAT_AGENT_INTERNAL_SECRET') ? (string)CHAT_AGENT_INTERNAL_SECRET : '');
        if (!$gate->agentEnabled() || $internalSecret === '') {
            // Not enabled or not configured: let the widget fall back to the legacy assistant.
            $this->jsonHeaders();
            $this->respond(['fallback' => true], 404);
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->jsonHeaders();
            $this->respond(['error' => 'Method not allowed'], 405);
            return;
        }

        $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? ''))));
        if ($contentType !== 'application/json' && !str_starts_with($contentType, 'application/json;')) {
            $this->jsonHeaders();
            $this->respond(['error' => 'Unsupported media type'], 415);
            return;
        }
        try {
            $this->checkOrigin();
        } catch (DomainException $e) {
            $this->jsonHeaders();
            $this->respond(['error' => $e->getMessage()], 403);
            return;
        }

        $rawBody = $this->readStreamBody();
        if (strlen($rawBody) > 8192) {
            $this->jsonHeaders();
            $this->respond(['error' => 'Yêu cầu quá dài.'], 413);
            return;
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            $this->jsonHeaders();
            $this->respond(['error' => 'Yêu cầu không hợp lệ.'], 400);
            return;
        }

        $message = trim((string)($body['message'] ?? ''));
        $messageLength = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
        if ($messageLength < 1 || $messageLength > 2000) {
            $this->jsonHeaders();
            $this->respond(['error' => 'Tin nhắn phải có từ 1 đến 2000 ký tự.'], 400);
            return;
        }

        $loggedIn = $this->loggedIn();
        $user = $this->user();
        $sub = ChatAgentIdentity::subFor($user);
        if ($loggedIn && $sub === '') {
            $this->jsonHeaders();
            $this->respond(['error' => 'Phiên đăng nhập không hợp lệ.'], 401);
            return;
        }
        $ident = $loggedIn ? $sub : RateLimiter::clientIdentity();
        $limiter = $this->limiter ?? RateLimiter::shared();
        if ($ident !== null) {
            foreach ([['chat_agent_msg', 20, 60], ['chat_agent_day', 300, 86400]] as [$scope, $limit, $window]) {
                $rate = $limiter->hit($scope, $ident, $limit, $window);
                if (empty($rate['allowed'])) {
                    $this->jsonHeaders();
                    $this->respond(['error' => 'rate_limited', 'retry_after' => (int)($rate['retry_after'] ?? 0)], 429);
                    return;
                }
            }
        }

        $conversationId = trim((string)($body['conversation_id'] ?? ''));
        $outboundBody = $this->buildOutboundBody($body, $loggedIn, $user, $message);

        $agentToken = null;
        $scopes = $gate->scopesFor($loggedIn, $this->isStaff($user));
        $tokenSecret = defined('CHAT_AGENT_TOKEN_SECRET') ? (string)CHAT_AGENT_TOKEN_SECRET : '';
        $email = trim((string)($user['email'] ?? ''));
        if ($loggedIn && $sub !== '' && $email !== '' && $tokenSecret !== '') {
            try {
                $agentToken = ChatAgentToken::issue([
                    'sub' => $sub,
                    'em' => $email,
                    'sid' => $conversationId,
                    'scope' => $scopes,
                ], $tokenSecret);
            } catch (Throwable $e) {
                $agentToken = null;
            }
        }

        $request = self::buildOutboundRequest($outboundBody, $agentToken, $internalSecret);

        $sessionId = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
        }
        while (ob_get_level()) {
            ob_end_flush();
        }
        ignore_user_abort(false);
        http_response_code(200);

        $out = static function (string $raw): void {
            echo $raw;
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        };
        $applyEffect = function (array $effect) use ($sub, $sessionId): ?int {
            $secret = defined('CHAT_AGENT_TOKEN_SECRET') ? (string)CHAT_AGENT_TOKEN_SECRET : '';
            return self::applyCartEffectAfterStream($effect, $secret, $sub, $sessionId);
        };

        $relay = new ChatSseRelay($out, $applyEffect);
        $curlError = '';
        $status = 0;
        $contentType = '';
        if (function_exists('curl_init')) {
            $ch = curl_init(defined('AI_AGENT_ENDPOINT') ? AI_AGENT_ENDPOINT : 'http://127.0.0.1:5002/api/agent/chat');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $request['headers'],
                CURLOPT_POSTFIELDS => json_encode($request['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$status, &$contentType): int {
                    if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', trim($line), $matches) === 1) {
                        $status = (int)$matches[1];
                        $contentType = '';
                    } elseif (stripos($line, 'Content-Type:') === 0) {
                        $contentType = trim(substr($line, strlen('Content-Type:')));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($relay, &$status, &$contentType): int {
                    if (connection_aborted()) {
                        return 0;
                    }
                    return ChatAgentController::acceptsEventStreamResponse($status, $contentType)
                        ? $relay->feed($chunk)
                        : 0;
                },
            ]);
            curl_exec($ch);
            $curlError = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $curlError = 'cURL not available';
        }

        if (!$relay->hasTerminal()) {
            if ($curlError !== '' || !self::acceptsEventStreamResponse($status, $contentType)) {
                $relay->fail();
            } else {
                $relay->finish();
            }
        }
    }

    public static function buildOutboundRequest(array $body, ?string $agentToken, string $internalSecret): array
    {
        $headers = [
            'Content-Type: application/json',
            'X-Internal-Token: ' . $internalSecret,
        ];
        if ($agentToken !== null && trim($agentToken) !== '') {
            $headers[] = 'X-Agent-Token: ' . $agentToken;
        }
        return ['headers' => $headers, 'body' => $body];
    }

    public static function acceptsEventStreamResponse(int $status, string $contentType): bool
    {
        return $status === 200 && str_starts_with(strtolower(trim($contentType)), 'text/event-stream');
    }

    public static function applyCartEffectAfterStream(
        array $effect,
        string $secret,
        string $sub,
        string $expectedSessionId
    ): ?int {
        if (@session_start() === false) {
            error_log('chat_cart_effect: session_start_failed');
            return null;
        }

        try {
            if (session_id() !== $expectedSessionId) {
                error_log('chat_cart_effect: session_id_mismatch');
                return null;
            }
            if (!ChatCartEffect::verify($effect, $secret, $sub)) {
                error_log('chat_cart_effect: bad');
                return null;
            }
            if (!ChatCartEffect::apply($effect, $_SESSION)) {
                error_log('chat_cart_effect: replayed');
                return null;
            }
            return array_sum(array_map('intval', (array)($_SESSION['gio_hang'] ?? [])));
        } catch (Throwable $e) {
            error_log('chat_cart_effect: invalid');
            return null;
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
    }

    public function confirm(): void
    {
        $this->jsonHeaders();
        $auditActor = '';
        $auditParams = ['draft_id' => '', 'payment_method' => ''];
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new DomainException('Chỉ nhận yêu cầu POST.', 405);
            }
            $raw = $this->readBody();
            if (strlen($raw) > 4096) throw new DomainException('Yêu cầu quá dài.', 413);
            $body = json_decode($raw, true);
            if (!is_array($body)) throw new DomainException('Yêu cầu không hợp lệ.', 400);
            $auditParams['draft_id'] = trim((string)($body['draft_id'] ?? ''));
            $auditParams['payment_method'] = trim((string)($body['payment_method'] ?? ''));
            if (!$this->loggedIn()) throw new DomainException('Vui lòng đăng nhập để xác nhận đơn hàng.', 403);

            $user = $this->withIdentity($this->user());
            $auditActor = (string)($user['sub'] ?? '');
            if ($this->isStaff($user)) throw new DomainException('Tài khoản nhân viên không thể đặt hàng.', 403);

            $csrf = $body['csrf'] ?? '';
            $expectedCsrf = (string)($this->csrfResolver !== null ? ($this->csrfResolver)() : ShopAssistantController::csrfToken());
            if (!is_string($csrf) || $csrf === '' || !hash_equals($expectedCsrf, $csrf)) {
                throw new DomainException('Phiên xác nhận không hợp lệ. Vui lòng tải lại trang.', 403);
            }
            $this->checkOrigin();

            $sub = trim((string)($user['sub'] ?? ''));
            if ($sub === '') throw new DomainException('Phiên đăng nhập không hợp lệ.', 403);
            $rate = ($this->limiter ?? RateLimiter::shared())->hit('chat_order_confirm', $sub, 5, 600);
            if (empty($rate['allowed'])) throw new DomainException('Bạn thao tác quá nhanh. Vui lòng thử lại sau.', 429);

            if (!$this->purchaseEnabled()) throw new DomainException('Tính năng đặt hàng từ trợ lý đang tạm tắt.', 403);
            $draftId = trim((string)($body['draft_id'] ?? ''));
            if ($draftId === '' || strlen($draftId) > 128) throw new DomainException('Bản nháp đơn hàng không hợp lệ.', 422);
            $result = $this->service()->confirm($draftId, $user, $_SESSION);
            $auditParams['payment_method'] = (string)($result['payment_method'] ?? ($result['card']['payment_method'] ?? $auditParams['payment_method']));
            $outcome = (string)($result['status'] ?? 'created');
            if ($outcome === 'created') $auditParams['order_id'] = (int)($result['order_id'] ?? 0);
            $this->audit($auditActor, $auditParams, $outcome);
            $this->respond(['ok' => true] + $result, 200);
        } catch (DomainException $e) {
            $this->fillAuditPaymentMethod($auditParams);
            $code = $this->errorCode($e);
            $this->audit($auditActor, $auditParams, 'rejected:' . $code);
            $status = (int)$e->getCode();
            if ($status < 400 || $status > 599) $status = 400;
            $this->respond([
                'ok' => false,
                'code' => $code,
                'message' => $e->getMessage(),
            ], $status);
        } catch (Throwable $e) {
            $this->fillAuditPaymentMethod($auditParams);
            $this->audit($auditActor, $auditParams, 'error');
            $this->respond(['ok' => false, 'code' => 'server_error', 'message' => 'Không thể xác nhận đơn hàng lúc này.'], 500);
        }
    }

    private function service(): ChatOrderConfirmService
    {
        if ($this->confirmService !== null) return $this->confirmService;
        if ($this->db === null) throw new RuntimeException('Draft database unavailable');
        require_once __DIR__ . '/../models/HoaDon.php';
        require_once __DIR__ . '/../models/SanPham.php';
        require_once __DIR__ . '/../models/Voucher.php';
        require_once __DIR__ . '/../models/CustomerVoucher.php';
        require_once __DIR__ . '/../models/FlashSaleCampaign.php';
        require_once __DIR__ . '/../models/TaiKhoan.php';
        $store = new MongoChatDraftStore($this->db);
        $voucher = new Voucher($this->db);
        $core = new CheckoutCore(
            $this->db,
            new SanPham($this->db),
            $voucher,
            new CustomerVoucher($this->db),
            fn(string $id, array $product) => (new FlashSaleCampaign($this->db))->resolveEffectivePrice($id, null, $product)
        );
        $clock = $this->clock ?? static fn(): int => time();
        $drafts = new ChatDraftService($store, $core, $clock, static fn(string $code, int $subtotal): array => $voucher->validateForCheckout($code, $subtotal));
        $orders = new HoaDon($this->db);
        return new ChatOrderConfirmService(
            $drafts,
            $store,
            $core,
            static fn(array $payload): int => $orders->taoDonHang($payload),
            $clock,
            static fn(int $orderId): ?array => $orders->getOrderById($orderId)
        );
    }

    private function readBody(): string
    {
        if ($this->bodyReader !== null) return (string)($this->bodyReader)();
        return (string)file_get_contents('php://input', false, null, 0, 4097);
    }

    private function readStreamBody(): string
    {
        if ($this->bodyReader !== null) return (string)($this->bodyReader)();
        return (string)file_get_contents('php://input', false, null, 0, 8193);
    }

    /**
     * Builds the JSON body sent to the agent service. The skin profile is resolved server-side
     * from the logged-in account; any client-supplied `profile` is ignored.
     */
    private function buildOutboundBody(array $body, bool $loggedIn, array $user, string $message): array
    {
        $conversationId = trim((string)($body['conversation_id'] ?? ''));
        $outboundBody = [
            'message' => $message,
            'cart_snapshot' => $this->cartSnapshot(),
        ];
        if ($conversationId !== '') {
            $outboundBody['conversation_id'] = $conversationId;
        }
        $email = trim((string)($user['email'] ?? ''));
        if ($loggedIn && $email !== '') {
            $profile = $this->resolveProfile($email);
            if (is_array($profile) && $profile !== []) {
                $outboundBody['profile'] = $profile;
            }
        }
        return $outboundBody;
    }

    private function resolveProfile(string $email): ?array
    {
        try {
            if (is_callable($this->profileResolver)) {
                $result = ($this->profileResolver)($email);
                return is_array($result) ? $result : null;
            }
            if ($this->db === null) {
                return null;
            }
            require_once __DIR__ . '/../models/TaiKhoan.php';
            $model = new TaiKhoan($this->db);
            $skin = $model->getSelectedSkinProfileByEmail($email);
            $khachHang = $model->getCustomerForSelectedProfileByEmail($email);
            $profile = self::buildAgentProfile(is_array($skin) ? $skin : null, is_array($khachHang) ? $khachHang : null);
            if (is_array($skin) && trim((string)($skin['profile_id'] ?? '')) !== '') {
                $profile['profile_id'] = (string)$skin['profile_id'];
                $profile['profile_name'] = trim((string)($skin['display_name'] ?? 'Hồ sơ của tôi')) ?: 'Hồ sơ của tôi';
                $profile['profile_type'] = (string)($skin['profile_type'] ?? 'self');
            }
            return $profile;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Pure mapping from account rows to the canonical agent profile
     * (skin_type, concerns, goal, avoid_ingredients, budget_max). Empty keys are omitted.
     */
    public static function buildAgentProfile(?array $skinProfile, ?array $khachHang): array
    {
        $skinProfile = $skinProfile ?? [];
        $khachHang = $khachHang ?? [];
        $profile = [];

        $skinType = self::cleanProfileText($skinProfile['loai_da'] ?? '');
        if ($skinType !== '' && self::lower($skinType) !== 'chưa xác định') {
            $profile['skin_type'] = $skinType;
        }

        $concernsRaw = trim((string)($skinProfile['van_de_da'] ?? ''));
        if ($concernsRaw === '') {
            $concernsRaw = (string)($khachHang['van_de_da'] ?? '');
        }
        $concerns = self::cleanProfileList($concernsRaw);
        if ($concerns !== []) {
            $profile['concerns'] = $concerns;
        }

        $goal = self::cleanProfileText($khachHang['muc_tieu_cham_soc'] ?? '');
        if ($goal !== '') {
            $profile['goal'] = $goal;
        }

        $avoid = self::cleanProfileList((string)($khachHang['thanh_phan_tranh'] ?? ''));
        if ($avoid !== []) {
            $profile['avoid_ingredients'] = $avoid;
        }

        $budget = (int)($skinProfile['ngan_sach'] ?? 0);
        if ($budget <= 0) {
            $budget = (int)($khachHang['ngan_sach'] ?? 0);
        }
        if ($budget > 0) {
            $profile['budget_max'] = $budget;
        }

        $sensitivity = self::cleanProfileText($khachHang['muc_do_nhay_cam'] ?? '');
        if ($sensitivity !== '') {
            $profile['sensitivity'] = $sensitivity;
        }

        $updatedAt = $khachHang['updated_at'] ?? null;
        $updatedAtIso = '';
        if (is_object($updatedAt) && method_exists($updatedAt, 'toDateTime')) {
            $updatedAtIso = $updatedAt->toDateTime()->format('c');
        } elseif (is_string($updatedAt) && trim($updatedAt) !== '') {
            $updatedAtIso = trim($updatedAt);
        }
        if ($updatedAtIso !== '') {
            $profile['updated_at'] = $updatedAtIso;
        }
        return $profile;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function isProfilePlaceholder(string $value): bool
    {
        $v = self::lower(trim($value));
        return $v === ''
            || $v === 'không có'
            || $v === 'không có / không quan tâm'
            || str_starts_with($v, 'chưa ghi nhận');
    }

    private static function cleanProfileText($value): string
    {
        $text = trim((string)$value);
        if (self::isProfilePlaceholder($text)) {
            return '';
        }
        return function_exists('mb_substr') ? mb_substr($text, 0, 200, 'UTF-8') : substr($text, 0, 200);
    }

    private static function cleanProfileList(string $raw): array
    {
        $items = [];
        foreach (explode(',', $raw) as $part) {
            $clean = self::cleanProfileText($part);
            if ($clean !== '') {
                $items[] = $clean;
            }
        }
        return array_slice($items, 0, 10);
    }

    private function cartSnapshot(): array
    {
        try {
            $cart = $_SESSION['gio_hang'] ?? [];
            if (!is_array($cart) || $cart === [] || $this->db === null) {
                return [];
            }

            require_once __DIR__ . '/../models/SanPham.php';
            require_once __DIR__ . '/../models/Voucher.php';
            require_once __DIR__ . '/../models/CustomerVoucher.php';
            require_once __DIR__ . '/../models/FlashSaleCampaign.php';
            require_once __DIR__ . '/../services/CheckoutCore.php';
            $db = $this->db;
            $productModel = new SanPham($db);
            $core = new CheckoutCore(
                $db,
                $productModel,
                new Voucher($db),
                new CustomerVoucher($db),
                static fn(string $id, array $product) => (new FlashSaleCampaign($db))->resolveEffectivePrice($id, null, $product)
            );
            $preview = $core->preview($cart);
            $snapshot = [];
            foreach (array_slice((array)($preview['items'] ?? []), 0, 50) as $item) {
                $product = is_array($item['product'] ?? null) ? $item['product'] : [];
                $snapshot[] = [
                    'id' => (string)($item['id'] ?? ''),
                    'name' => (string)($product['ten_san_pham'] ?? $product['name'] ?? ($item['id'] ?? '')),
                    'qty' => (int)($item['qty'] ?? 0),
                    'unit_price' => (int)($item['unit_price'] ?? 0),
                ];
            }
            return $snapshot;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function loggedIn(): bool
    {
        return $this->loggedInResolver !== null ? (bool)($this->loggedInResolver)() : (function_exists('is_logged_in') && is_logged_in());
    }

    private function user(): array
    {
        if ($this->userResolver !== null) {
            $user = ($this->userResolver)();
            return is_array($user) ? $user : [];
        }
        $user = function_exists('current_user') ? current_user() : [];
        return is_array($user) ? $user : [];
    }

    private function withIdentity(array $user): array
    {
        $sub = ChatAgentIdentity::subFor($user);
        if ($sub === '') $sub = trim((string)($user['sub'] ?? ''));
        if ($sub !== '') $user['sub'] = $sub;

        $email = trim((string)($user['email'] ?? ''));
        if (($user['customer_id'] ?? '') === '' && $this->db !== null && $email !== '') {
            try {
                require_once __DIR__ . '/../models/TaiKhoan.php';
                $customer = (new TaiKhoan($this->db))->getKhachHangByEmail($email);
                if (is_array($customer) && ($customer['ma_kh'] ?? '') !== '') {
                    $user['customer_id'] = (string)$customer['ma_kh'];
                }
            } catch (Throwable $e) {
                // Customer lookup is only an enrichment; order confirmation still uses the session identity.
            }
        }
        return $user;
    }

    private function isStaff(array $user): bool
    {
        $role = function_exists('current_role') ? current_role() : ($user['role'] ?? ($user['vai_tro'] ?? ''));
        return in_array(strtolower(trim((string)$role)), ['admin', 'nhanvien'], true);
    }

    private function purchaseEnabled(): bool
    {
        if ($this->purchaseEnabledResolver !== null) return (bool)($this->purchaseEnabledResolver)();
        return ($this->gate ??= self::defaultGate())->purchaseEnabled();
    }

    private static function defaultGate(): ChatAgentGate
    {
        // Same source as ai_chat_widget.php so the widget flag and the stream gate always agree.
        $flag = function_exists('ss_env') ? ss_env('SYNA_AGENT_V2', '') : trim((string)($_ENV['SYNA_AGENT_V2'] ?? ''));
        return new ChatAgentGate(RedisLite::shared(), ['SYNA_AGENT_V2' => $flag]);
    }

    private function checkOrigin(): void
    {
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        $originHost = $origin === '' ? null : parse_url($origin, PHP_URL_HOST);
        $base = defined('BASE_URL') ? (string)BASE_URL : '';
        $baseHost = $base === '' ? null : parse_url($base, PHP_URL_HOST);
        if ($baseHost === null) {
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            $baseHost = parse_url('http://' . $host, PHP_URL_HOST);
        }
        if (!is_string($originHost) || !is_string($baseHost) || strcasecmp($originHost, $baseHost) !== 0) {
            throw new DomainException('Nguồn yêu cầu không hợp lệ.', 403);
        }
    }

    private function audit(string $actor, array $params, string $outcome): void
    {
        if ($this->audit === null) {
            $this->audit = $this->db !== null
                ? ChatAgentAudit::forDb($this->db)
                : new ChatAgentAudit(static function (array $document): void {});
        }
        $this->audit->record($actor, 'chat_order_confirm', $params, $outcome);
    }

    private function fillAuditPaymentMethod(array &$params): void
    {
        if (($params['draft_id'] ?? '') === '' || ($params['payment_method'] ?? '') !== '') return;
        try {
            $params['payment_method'] = $this->service()->paymentMethod((string)$params['draft_id']);
        } catch (Throwable $e) {
            // Audit enrichment must never change the request result.
        }
    }

    private function errorCode(DomainException $error): string
    {
        if (method_exists($error, 'getErrorCode')) return (string)$error->getErrorCode();
        $status = (int)$error->getCode();
        return match ($status) {
            400 => 'invalid_request', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed',
            409 => 'already_used', 410 => 'expired', 413 => 'payload_too_large', 422 => 'invalid_request',
            429 => 'rate_limited', default => 'server_error',
        };
    }

    private function jsonHeaders(): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
    }

    private function respond(array $body, int $status): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
