<?php
declare(strict_types=1);

require_once __DIR__ . '/RedisLite.php';

final class ChatAgentGate
{
    private const PURCHASE_OFF_KEY = 'skinsyntax:chat_agent:purchase_off';

    public function __construct(private ?RedisLite $redis, private array $env)
    {
    }

    public function agentEnabled(): bool
    {
        return ($this->env['SYNA_AGENT_V2'] ?? null) === '1';
    }

    public function purchaseEnabled(): bool
    {
        if ($this->redis === null) {
            return true;
        }

        try {
            return $this->redis->get(self::PURCHASE_OFF_KEY) !== '1';
        } catch (Throwable $e) {
            return true;
        }
    }

    public function scopesFor(bool $loggedIn, bool $isStaff): array
    {
        if (!$loggedIn) {
            return ['chat'];
        }

        $scopes = ['chat', 'order:read'];
        if ($isStaff || !$this->purchaseEnabled()) {
            return $scopes;
        }

        return array_merge($scopes, ['cart:write', 'draft:create']);
    }

    public function setPurchaseEnabled(bool $on): bool
    {
        if ($this->redis === null) {
            return false;
        }

        try {
            if ($on) {
                if ($this->redis->del(self::PURCHASE_OFF_KEY) === null) {
                    return false;
                }
                return $this->redis->get(self::PURCHASE_OFF_KEY) === null;
            } else {
                if (!$this->redis->set(self::PURCHASE_OFF_KEY, '1')) {
                    return false;
                }
                return $this->redis->get(self::PURCHASE_OFF_KEY) === '1';
            }
        } catch (Throwable $e) {
            return false;
        }
    }
}
