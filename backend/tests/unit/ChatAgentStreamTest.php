<?php
declare(strict_types=1);

// Mirrors config.php: ss_env reads $_ENV, then $_SERVER, then getenv.
if (!function_exists('ss_env')) {
    function ss_env(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || trim((string)$value) === '') {
            return $default;
        }
        return trim((string)$value);
    }
}

require_once __DIR__ . '/../../app/controllers/ChatAgentController.php';

$fail = 0;
$pass = 0;
$results = [];
$check = function (string $name, bool $ok) use (&$fail, &$pass): void {
    global $results;
    $results[] = ($ok ? 'PASS ' : 'FAIL ') . $name;
    $ok ? $pass++ : $fail++;
};

$gate = new ChatAgentGate(null, ['SYNA_AGENT_V2' => '1']);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'shop.test';
$_SERVER['HTTP_ORIGIN'] = 'http://shop.test';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$makeController = static fn(?ChatAgentGate $g, bool $loggedIn, string $body, string $secret = 'internal-secret'): ChatAgentController
    => new ChatAgentController(
        null, null, $g, null, null,
        static fn(): bool => $loggedIn,
        static fn(): array => [],
        null,
        static fn(): string => $body,
        null, null, $secret
    );
$run = static function (ChatAgentController $c): array {
    http_response_code(200);
    ob_start();
    $c->stream();
    $out = (string)ob_get_clean();
    $decoded = json_decode($out, true);
    return [http_response_code(), is_array($decoded) ? $decoded : [], $out];
};

[$code, $json] = $run($makeController($gate, false, str_repeat('x', 8193)));
$check('stream_body_cap_returns_413', $code === 413 && ($json['error'] ?? '') === 'Yêu cầu quá dài.');

[$code, $json] = $run($makeController($gate, true, '{"message":"xin chào"}'));
$check('logged_in_empty_id_returns_401_before_streaming', $code === 401
    && ($json['error'] ?? '') === 'Phiên đăng nhập không hợp lệ.');

// Users without a numeric id (Mongo _id only, or id 0) are identified by an email-hash sub, not rejected.
final class ChatStreamKeyCaptureRedis extends RedisLite
{
    public array $keys = [];
    public function __construct() { parent::__construct('redis://127.0.0.1:1'); }
    public function eval(string $script, array $keys = [], array $args = []) { $this->keys[] = $keys[0] ?? ''; return [1000, 60]; }
}
$identityUser = static function (array $user) use ($gate): array {
    $redis = new ChatStreamKeyCaptureRedis();
    $c = new ChatAgentController(
        null, null, $gate, new RateLimiter($redis), null,
        static fn(): bool => true,
        static fn(): array => $user,
        null,
        static fn(): string => '{"message":"xin chào"}',
        null, null, 'internal-secret'
    );
    http_response_code(200);
    ob_start();
    $c->stream();
    ob_end_clean();
    return [http_response_code(), $redis->keys];
};
$emKey = RateLimiter::key('chat_agent_msg', 'em:' . substr(hash('sha256', 'e2e@example.test'), 0, 32));
[$code, $keys] = $identityUser(['id' => 0, 'email' => 'E2E@example.test']);
$check('id_zero_with_email_proceeds_with_em_sub_not_401', $code === 429 && ($keys[0] ?? '') === $emKey);
[$code, $keys] = $identityUser(['id' => null, 'email' => 'e2e@example.test']);
$check('null_id_with_email_proceeds_with_em_sub_not_401', $code === 429 && ($keys[0] ?? '') === $emKey);
[$code, $keys] = $identityUser(['email' => 'e2e@example.test']);
$check('missing_id_with_email_proceeds_with_em_sub_not_401', $code === 429 && ($keys[0] ?? '') === $emKey);
[$code, $keys] = $identityUser(['id' => 0, 'email' => 'other@example.test']);
$check('different_email_gets_different_sub', $code === 429 && ($keys[0] ?? '') !== $emKey && ($keys[0] ?? '') !== '');
[$code] = $identityUser(['id' => 0]);
$check('id_zero_without_email_still_401', $code === 401);

// Item 5: Content-Type and Origin are enforced before anything else.
$_SERVER['CONTENT_TYPE'] = 'text/plain';
[$code, $json] = $run($makeController($gate, false, '{"message":"x"}'));
$check('stream_text_plain_returns_415', $code === 415);
$_SERVER['CONTENT_TYPE'] = 'application/json; charset=UTF-8';
[$code] = $run($makeController($gate, false, str_repeat('x', 8193)));
$check('stream_json_with_charset_is_accepted', $code === 413);
unset($_SERVER['CONTENT_TYPE']);
[$code] = $run($makeController($gate, false, '{"message":"x"}'));
$check('stream_missing_content_type_returns_415', $code === 415);
$_SERVER['CONTENT_TYPE'] = 'application/json';

$_SERVER['HTTP_ORIGIN'] = 'http://evil.test';
[$code, $json] = $run($makeController($gate, false, '{"message":"x"}'));
$check('stream_wrong_origin_returns_403', $code === 403);
unset($_SERVER['HTTP_ORIGIN']);
[$code, $json] = $run($makeController($gate, false, '{"message":"x"}'));
$check('stream_missing_origin_returns_403', $code === 403);
$_SERVER['HTTP_ORIGIN'] = 'http://shop.test';
[$code] = $run($makeController($gate, false, str_repeat('x', 8193)));
$check('stream_matching_origin_and_json_proceed', $code === 413);

// Empty internal secret: fall back to the legacy chat instead of a busy error.
[$code, $json] = $run($makeController($gate, false, '{"message":"x"}', ''));
$check('stream_empty_internal_secret_returns_404_fallback', $code === 404 && ($json['fallback'] ?? null) === true);

// Item 4: the default gate reads SYNA_AGENT_V2 through ss_env (here via $_SERVER, never $_ENV).
unset($_ENV['SYNA_AGENT_V2']);
$_SERVER['SYNA_AGENT_V2'] = '1';
[$code] = $run($makeController(null, false, str_repeat('x', 8193)));
$check('default_gate_reads_syna_agent_v2_through_ss_env', $code === 413);
$_SERVER['SYNA_AGENT_V2'] = '0';
[$code, $json] = $run($makeController(null, false, str_repeat('x', 8193)));
$check('default_gate_off_returns_404_fallback', $code === 404 && ($json['fallback'] ?? null) === true);
unset($_SERVER['SYNA_AGENT_V2']);

// Skin profile: built server-side from the account, never trusted from the browser.
$mapped = ChatAgentController::buildAgentProfile(
    ['loai_da' => 'Da thường', 'van_de_da' => 'Lỗ chân lông to', 'ngan_sach' => 500000],
    ['muc_tieu_cham_soc' => 'Dưỡng sáng, mờ thâm nám', 'thanh_phan_tranh' => 'Không có / Không quan tâm', 'van_de_da' => 'x', 'ngan_sach' => 1]
);
$check('build_agent_profile_maps_example_and_drops_placeholder', $mapped === [
    'skin_type' => 'Da thường',
    'concerns' => ['Lỗ chân lông to'],
    'goal' => 'Dưỡng sáng, mờ thâm nám',
    'budget_max' => 500000,
]);
$mapped2 = ChatAgentController::buildAgentProfile(
    ['loai_da' => 'Chưa xác định', 'van_de_da' => '', 'ngan_sach' => 0],
    ['van_de_da' => 'Mụn, Thâm', 'thanh_phan_tranh' => 'Cồn, Hương liệu', 'ngan_sach' => '300000', 'muc_tieu_cham_soc' => 'Chưa ghi nhận thêm']
);
$check('build_agent_profile_fallbacks_and_lists', $mapped2 === [
    'concerns' => ['Mụn', 'Thâm'],
    'avoid_ingredients' => ['Cồn', 'Hương liệu'],
    'budget_max' => 300000,
]);
$fakeDate = new class {
    public function toDateTime(): DateTime { return new DateTime('2026-11-01T10:00:00+00:00'); }
};
$withSens = ChatAgentController::buildAgentProfile(
    ['loai_da' => 'Da dầu'],
    ['muc_do_nhay_cam' => 'Khỏe mạnh, hiếm khi', 'updated_at' => $fakeDate]
);
$check('build_agent_profile_includes_sensitivity_and_object_updated_at',
    ($withSens['sensitivity'] ?? null) === 'Khỏe mạnh, hiếm khi'
    && ($withSens['updated_at'] ?? null) === '2026-11-01T10:00:00+00:00');
$withStr = ChatAgentController::buildAgentProfile(['loai_da' => 'Da dầu'], ['updated_at' => '2026-10-20T08:00:00+00:00']);
$check('build_agent_profile_accepts_string_updated_at', ($withStr['updated_at'] ?? null) === '2026-10-20T08:00:00+00:00');
$placeholderSens = ChatAgentController::buildAgentProfile(['loai_da' => 'Da dầu'], ['muc_do_nhay_cam' => 'Chưa ghi nhận thêm', 'updated_at' => 12345]);
$check('build_agent_profile_drops_placeholder_sensitivity_and_bad_updated_at',
    !array_key_exists('sensitivity', $placeholderSens) && !array_key_exists('updated_at', $placeholderSens));
$check('build_agent_profile_empty_inputs_yield_empty', ChatAgentController::buildAgentProfile(null, null) === []);

$captured = null;
$streamWithProfile = static function (bool $loggedIn, array $user, ?callable $resolver, string $body) use ($gate): array {
    $c = new ChatAgentController(
        null, null, $gate, null, null,
        static fn(): bool => $loggedIn,
        static fn(): array => $user,
        null,
        static fn(): string => $body,
        null, null, 'internal-secret', $resolver
    );
    $m = new ReflectionMethod($c, 'buildOutboundBody');
    $m->setAccessible(true);
    return $m->invoke($c, json_decode($body, true), $loggedIn, $user, '');
};
$calls = [];
$resolver = static function (string $email) use (&$calls): ?array { $calls[] = $email; return ['skin_type' => 'Da thường']; };
$out = $streamWithProfile(true, ['id' => 7, 'email' => 'a@b.test'], $resolver, '{"message":"x","profile":{"skin_type":"EVIL"}}');
$check('outbound_profile_comes_from_resolver_not_client', ($out['profile'] ?? null) === ['skin_type' => 'Da thường'] && $calls === ['a@b.test']);
$out = $streamWithProfile(false, [], $resolver, '{"message":"x","profile":{"skin_type":"EVIL"}}');
$check('guest_outbound_has_no_profile', !array_key_exists('profile', $out));
$out = $streamWithProfile(true, ['id' => 7, 'email' => 'a@b.test'], static fn(string $e): ?array => [], '{"message":"x"}');
$check('empty_resolved_profile_is_omitted', !array_key_exists('profile', $out));

// ---------- scanned_product từ SESSION (chụp nhãn) ----------
$snapOk = ['name' => 'NIVEA', 'ingredients' => ['Aqua', 'Glycerin'], 'scanned_at' => 10000];
$sk = LabelScanController::SESSION_KEY;
$check('scanned: còn hạn -> trả snapshot', ChatAgentController::scannedProductFromSession([$sk => $snapOk], 10100) === $snapOk);
$check('scanned: hết hạn (> TTL) -> null', ChatAgentController::scannedProductFromSession([$sk => $snapOk], 10000 + LabelScanController::SESSION_TTL + 1) === null);
$check('scanned: đúng biên TTL vẫn còn hạn', ChatAgentController::scannedProductFromSession([$sk => $snapOk], 10000 + LabelScanController::SESSION_TTL) === $snapOk);
$check('scanned: thiếu khóa -> null', ChatAgentController::scannedProductFromSession([], 10100) === null);
$check('scanned: không phải mảng -> null', ChatAgentController::scannedProductFromSession([$sk => 'x'], 10100) === null);
$check('scanned: không tên không thành phần -> null', ChatAgentController::scannedProductFromSession([$sk => ['name' => ' ', 'ingredients' => [], 'scanned_at' => 10000]], 10100) === null);
$check('scanned: scanned_at không phải int -> null', ChatAgentController::scannedProductFromSession([$sk => ['name' => 'N', 'scanned_at' => '10000']], 10100) === null);
$check('scanned: scanned_at ở tương lai > 60s -> null; trong 60s thì nhận', ChatAgentController::scannedProductFromSession([$sk => ['name' => 'N', 'scanned_at' => 10200]], 10100) === null
    && ChatAgentController::scannedProductFromSession([$sk => ['name' => 'N', 'scanned_at' => 10150]], 10100) !== null);

$outbound = static function (array $session, callable $clock) use ($gate): array {
    $_SESSION = $session;
    $c = new ChatAgentController(
        null, null, $gate, null, null,
        static fn(): bool => false,
        static fn(): array => [],
        null,
        static fn(): string => '{"message":"x"}',
        null, $clock, 'internal-secret'
    );
    $m = new ReflectionMethod($c, 'buildOutboundBody');
    $m->setAccessible(true);
    $r = $m->invoke($c, ['message' => 'x'], false, [], 'x');
    $_SESSION = [];
    return $r;
};
$out = $outbound([$sk => $snapOk], static fn(): int => 10100);
$check('outbound: có scanned_product khi session còn hạn', ($out['scanned_product'] ?? null) === $snapOk);
$out = $outbound([$sk => $snapOk], static fn(): int => 10000 + LabelScanController::SESSION_TTL + 5);
$check('outbound: hết hạn -> không có scanned_product', !array_key_exists('scanned_product', $out));
$out = $outbound([], static fn(): int => 10100);
$check('outbound: không có session -> không có scanned_product', !array_key_exists('scanned_product', $out));

$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chat-sse-' . bin2hex(random_bytes(8));
mkdir($sessionDir, 0700, true);
$sessionId = 'task16session';
$secret = 'task16-secret';
$childCode = <<<'PHP'
require_once $argv[2];
session_save_path($argv[1]);
session_id('task16session');
session_start();
$_SESSION['gio_hang'] = ['existing' => 1];
$expected = session_id();
session_write_close();
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
echo 'headers sent';
$effect = ChatCartEffect::sign([
    'op' => 'add',
    'product_id' => 'p1',
    'qty' => 2,
    'sub' => 'user-7',
    'exp' => time() + 600,
    'nonce' => 'task16-nonce',
], 'task16-secret');
$count = ChatAgentController::applyCartEffectAfterStream($effect, 'task16-secret', 'user-7', $expected);
exit($count === 3 ? 0 : 2);
PHP;
$eval = 'eval(base64_decode(' . var_export(base64_encode($childCode), true) . '));';
$process = proc_open(
    [PHP_BINARY, '-r', $eval, $sessionDir, __DIR__ . '/../../app/controllers/ChatAgentController.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
$childOutput = '';
$childError = '';
$childExit = -1;
if (is_resource($process)) {
    $childOutput = (string)stream_get_contents($pipes[1]);
    $childError = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $childExit = proc_close($process);
}

ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path($sessionDir);
session_id($sessionId);
$parentStarted = @session_start();
$persisted = $parentStarted
    && ($_SESSION['gio_hang']['existing'] ?? null) === 1
    && ($_SESSION['gio_hang']['p1'] ?? null) === 2
    && ($_SESSION['chat_cart_nonces']['task16-nonce'] ?? null) !== null;
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$check('child_session_reopen_persists_cart_and_nonce', $childExit === 0
    && str_contains($childOutput, 'headers sent')
    && $childError === ''
    && $persisted);

$sessionFile = $sessionDir . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
@unlink($sessionFile);
@rmdir($sessionDir);

echo implode(PHP_EOL, $results) . PHP_EOL . PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
