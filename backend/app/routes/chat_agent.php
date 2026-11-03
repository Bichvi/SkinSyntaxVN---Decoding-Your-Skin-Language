<?php
declare(strict_types=1);

if ($r === 'chat_order_confirm') {
    require_once __DIR__ . '/../controllers/ChatAgentController.php';
    (new ChatAgentController($pdo ?? null))->confirm();
    return true;
}

if ($r === 'ai_chat_agent_stream') {
    require_once __DIR__ . '/../controllers/ChatAgentController.php';
    (new ChatAgentController($pdo ?? null))->stream();
    return true;
}

if (!str_starts_with((string)$r, 'internal_agent_')) {
    return false;
}

require_once __DIR__ . '/../services/ChatAgentToken.php';
require_once __DIR__ . '/../services/ChatCartEffect.php';
require_once __DIR__ . '/../controllers/ChatAgentInternalController.php';
$requireListener = function_exists('ss_env') && ss_env('CHAT_AGENT_REQUIRE_LISTENER', '') === '1';
if (!ChatAgentInternalController::listenerAllows((string)$r, $_SERVER, $requireListener)) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    http_response_code(404);
    echo json_encode(['error' => 'Not found'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return true;
}

(new ChatAgentInternalController(
    defined('CHAT_AGENT_INTERNAL_SECRET') ? CHAT_AGENT_INTERNAL_SECRET : '',
    defined('CHAT_AGENT_TOKEN_SECRET') ? CHAT_AGENT_TOKEN_SECRET : '',
    $pdo ?? null
))->handle($r);
return true;
