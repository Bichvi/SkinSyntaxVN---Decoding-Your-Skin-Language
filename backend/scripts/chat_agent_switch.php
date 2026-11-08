<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/services/RedisLite.php';
require_once __DIR__ . '/../app/services/ChatAgentGate.php';

$command = $argv[1] ?? '';
if (!in_array($command, ['on', 'off', 'status'], true)) {
    fwrite(STDERR, "Usage: php backend/scripts/chat_agent_switch.php on|off|status\n");
    exit(2);
}

$readEnv = static function (string $key, string $default = ''): string {
    return function_exists('ss_env') ? ss_env($key, $default) : (($value = getenv($key)) === false ? $default : trim((string)$value));
};

$redisUrl = defined('VALKEY_URL') ? (string)VALKEY_URL : '';
if ($redisUrl === '') {
    $redisUrl = $readEnv('VALKEY_URL');
}
if ($redisUrl === '') {
    $redisUrl = $readEnv('REDIS_URL');
}

$redis = $redisUrl !== '' ? new RedisLite($redisUrl) : RedisLite::shared();
$gate = new ChatAgentGate($redis, ['SYNA_AGENT_V2' => $readEnv('SYNA_AGENT_V2')]);

if ($command === 'status') {
    $purchaseEnabled = $gate->purchaseEnabled();
    $redisUnavailable = $redis === null;
    if (!$redisUnavailable) {
        $redisUnavailable = $redis->get('skinsyntax:chat_agent:purchase_off') === null && !$redis->ping();
    }
    echo $redisUnavailable
        ? "on (redis unavailable, fail-open)\n"
        : ($purchaseEnabled ? "on\n" : "off\n");
    exit(0);
}

$persisted = $gate->setPurchaseEnabled($command === 'on');
if (!$persisted) {
    fwrite(STDERR, "FAILED: could not persist chat purchase switch (Redis unavailable)\n");
    exit(1);
}
echo $command . "\n";
