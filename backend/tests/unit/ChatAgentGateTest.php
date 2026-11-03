<?php
require_once __DIR__ . '/../../app/services/RedisLite.php';
require_once __DIR__ . '/../../app/services/ChatAgentGate.php';

$fail = 0;
$pass = 0;
$check = function (string $name, bool $ok) use (&$fail, &$pass): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

class ChatAgentFakeRedis extends RedisLite
{
    public array $values = [];
    public array $writes = [];
    public bool $down = false;
    public bool $setResult = true;
    public ?int $delResult = null;
    public bool $pingResult = true;

    public function __construct()
    {
        parent::__construct('redis://127.0.0.1:1');
    }

    public function get(string $key): ?string
    {
        if ($this->down) {
            return null;
        }
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value, array $opts = []): bool
    {
        $this->writes[] = [$key, $value, $opts];
        if ($this->setResult) {
            $this->values[$key] = $value;
        }
        return $this->setResult;
    }

    public function del(string ...$keys): ?int
    {
        if ($this->delResult !== null) {
            return $this->delResult;
        }
        $deleted = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->values)) {
                unset($this->values[$key]);
                $deleted++;
            }
        }
        return $deleted;
    }

    public function ping(): bool
    {
        return !$this->down && $this->pingResult;
    }
}

$redis = new ChatAgentFakeRedis();
$gate = new ChatAgentGate($redis, ['SYNA_AGENT_V2' => '1']);
$check('guest_only_chat', $gate->scopesFor(false, false) === ['chat']);
$check('staff_cannot_buy', $gate->scopesFor(true, true) === ['chat', 'order:read']);
$check('customer_full_scopes', $gate->scopesFor(true, false) === ['chat', 'order:read', 'cart:write', 'draft:create']);

$gate->setPurchaseEnabled(false);
$check('kill_switch_removes_write_scopes', $gate->purchaseEnabled() === false
    && $gate->scopesFor(true, false) === ['chat', 'order:read']);

$check('set_returns_false_when_redis_null', (new ChatAgentGate(null, []))->setPurchaseEnabled(false) === false);
$redis->setResult = false;
$check('set_returns_false_when_write_fails', $gate->setPurchaseEnabled(false) === false);
$redis->setResult = true;
$check('set_off_true_and_reads_back', $gate->setPurchaseEnabled(false) === true
    && $redis->values['skinsyntax:chat_agent:purchase_off'] === '1');
$check('set_on_true_after_delete', $gate->setPurchaseEnabled(true) === true
    && !array_key_exists('skinsyntax:chat_agent:purchase_off', $redis->values));

$redis->down = true;
$check('redis_down_keeps_purchase_on', (new ChatAgentGate($redis, []))->purchaseEnabled() === true);
$check('agent_flag_reads_env', (new ChatAgentGate(null, ['SYNA_AGENT_V2' => '1']))->agentEnabled()
    && !(new ChatAgentGate(null, ['SYNA_AGENT_V2' => 'true']))->agentEnabled());

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail ? 1 : 0);
