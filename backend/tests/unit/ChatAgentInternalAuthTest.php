<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/services/ChatAgentToken.php';
require_once __DIR__ . '/../../app/controllers/ChatAgentInternalController.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$throws = function (callable $fn, int $code): bool {
    try {
        $fn();
        return false;
    } catch (DomainException $e) {
        return $e->getCode() === $code;
    }
};

$tokenSecret = 'token-secret-0123456789';
$internalSecret = 'internal-secret-0123456789';
$token = ChatAgentToken::issue(
    ['sub' => 'agent-user', 'scope' => ['chat'], 'exp' => 1_900_000_000, 'jti' => str_repeat('a', 32)],
    $tokenSecret,
    300,
    1_800_000_000
);

$check('missing internal header -> 401', $throws(
    fn() => ChatAgentInternalController::authenticate(
        ['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $internalSecret, $tokenSecret, 1_800_000_100
    ),
    401
));
$check('wrong internal secret -> 401', $throws(
    fn() => ChatAgentInternalController::authenticate(
        ['HTTP_X_INTERNAL_TOKEN' => 'wrong', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        $internalSecret,
        $tokenSecret,
        1_800_000_100
    ),
    401
));
$check('missing Bearer -> 401', $throws(
    fn() => ChatAgentInternalController::authenticate(
        ['HTTP_X_INTERNAL_TOKEN' => $internalSecret, 'HTTP_AUTHORIZATION' => $token],
        $internalSecret,
        $tokenSecret,
        1_800_000_100
    ),
    401
));
$check('expired token -> 401', $throws(
    fn() => ChatAgentInternalController::authenticate(
        ['HTTP_X_INTERNAL_TOKEN' => $internalSecret, 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        $internalSecret,
        $tokenSecret,
        1_900_000_000
    ),
    401
));
$claims = ChatAgentInternalController::authenticate(
    ['HTTP_X_INTERNAL_TOKEN' => $internalSecret, 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
    $internalSecret,
    $tokenSecret,
    1_800_000_100
);
$check('valid headers -> claims', $claims['sub'] === 'agent-user');
$missingSubToken = ChatAgentToken::issue(
    ['scope' => ['chat'], 'exp' => 1_900_000_000, 'jti' => str_repeat('b', 32)],
    $tokenSecret,
    300,
    1_800_000_000
);
$emptySubToken = ChatAgentToken::issue(
    ['sub' => '', 'scope' => ['chat'], 'exp' => 1_900_000_000, 'jti' => str_repeat('c', 32)],
    $tokenSecret,
    300,
    1_800_000_000
);
$controller = new ChatAgentInternalController($internalSecret, $tokenSecret);
foreach ([$missingSubToken, $emptySubToken] as $invalidSubToken) {
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'HTTP_X_INTERNAL_TOKEN' => $internalSecret,
        'HTTP_AUTHORIZATION' => 'Bearer ' . $invalidSubToken,
    ];
    ob_start();
    $controller->handle('internal_agent_ping');
    ob_end_clean();
    $check('missing or empty subject -> 401', http_response_code() === 401);
}
$check('Bearer scheme is case-insensitive', ChatAgentInternalController::authenticate(
    ['HTTP_X_INTERNAL_TOKEN' => $internalSecret, 'HTTP_AUTHORIZATION' => 'bEaReR ' . $token],
    $internalSecret,
    $tokenSecret,
    1_800_000_100
)['sub'] === 'agent-user');
$check('HTTP_X_AGENT_TOKEN fallback accepted', ChatAgentInternalController::authenticate(
    ['HTTP_X_INTERNAL_TOKEN' => $internalSecret, 'HTTP_X_AGENT_TOKEN' => $token],
    $internalSecret,
    $tokenSecret,
    1_800_000_100
)['sub'] === 'agent-user');
$check('REDIRECT_HTTP_AUTHORIZATION accepted', ChatAgentInternalController::authenticate(
    ['HTTP_X_INTERNAL_TOKEN' => $internalSecret, 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $token],
    $internalSecret,
    $tokenSecret,
    1_800_000_100
)['sub'] === 'agent-user');
$check('empty internal secret fails closed', $throws(
    fn() => ChatAgentInternalController::authenticate(
        ['HTTP_X_INTERNAL_TOKEN' => '', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], '', $tokenSecret, 1_800_000_100
    ),
    401
));
$check('internal public route allowed when listener is optional', ChatAgentInternalController::listenerAllows(
    'internal_agent_ping', [], false
));
$check('internal public route refused when listener is required', !ChatAgentInternalController::listenerAllows(
    'internal_agent_ping', [], true
));
$check('internal listener route allowed when listener is required', ChatAgentInternalController::listenerAllows(
    'internal_agent_ping', ['CHAT_AGENT_INTERNAL_LISTENER' => '1'], true
));
$check('non-internal public route allowed', ChatAgentInternalController::listenerAllows(
    'home', [], true
));
$check('non-internal listener route refused', !ChatAgentInternalController::listenerAllows(
    'home', ['CHAT_AGENT_INTERNAL_LISTENER' => '1'], true
));

$controller = new ChatAgentInternalController($internalSecret, $tokenSecret);
$_SERVER = [
    'REQUEST_METHOD' => 'POST',
    'HTTP_X_INTERNAL_TOKEN' => $internalSecret,
    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
];
ob_start();
$controller->handle('internal_agent_ping');
$pingBody = json_decode((string)ob_get_clean(), true);
$check('POST ping -> ok and subject', $pingBody === ['ok' => true, 'sub' => 'agent-user']);
$controller->handle('internal_agent_unknown');
$check('unknown internal route -> 404', http_response_code() === 404);
$_SERVER['REQUEST_METHOD'] = 'GET';
$controller->handle('internal_agent_ping');
$check('non-POST -> 405', http_response_code() === 405);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
