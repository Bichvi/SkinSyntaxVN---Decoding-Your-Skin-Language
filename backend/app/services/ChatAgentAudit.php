<?php
declare(strict_types=1);

final class ChatAgentAudit
{
    /** @var callable(array): void */
    private $insert;

    public function __construct(callable $insert)
    {
        $this->insert = $insert;
    }

    public static function forDb($db): self
    {
        return new self(static function (array $document) use ($db): void {
            $db->chat_agent_audit->insertOne($document);
        });
    }

    public function record(string $actor, string $action, array $params, string $outcome): void
    {
        try {
            $ts = class_exists('MongoDB\\BSON\\UTCDateTime')
                ? new \MongoDB\BSON\UTCDateTime()
                : time();
            ($this->insert)([
                'ts' => $ts,
                'actor' => $actor,
                'action' => $action,
                'params' => self::mask($params),
                'outcome' => $outcome,
                'source' => 'php',
            ]);
        } catch (Throwable $e) {
            // Audit logging must never change the agent request outcome.
        }
    }

    public static function mask(array $params): array
    {
        return self::maskValue(null, $params);
    }

    private static function maskValue(?string $key, $value)
    {
        $isPhone = $key !== null && in_array($key, ['phone', 'phone_number', 'sdt', 'sdt_nguoi_nhan', 'so_dien_thoai', 'mobile'], true);
        $isAddress = $key !== null && in_array($key, ['address', 'full_address', 'dia_chi', 'dia_chi_giao_hang', 'street', 'duong'], true);
        $isName = $key !== null && in_array($key, ['ten_nguoi_nhan', 'receiver_name', 'full_name', 'recipient_name', 'ho_ten'], true);
        $isToken = $key !== null && in_array(strtolower($key), ['token', 'csrf', 'authorization'], true);

        if ($isToken) {
            return '***';
        }
        if (($isPhone || $isAddress || $isName) && !is_string($value) && !is_int($value) && !is_float($value)) {
            return '***';
        }

        if (is_array($value)) {
            $masked = [];
            foreach ($value as $childKey => $childValue) {
                $masked[$childKey] = self::maskValue((string)$childKey, $childValue);
            }
            return $masked;
        }

        if ($isName) {
            $text = trim((string)$value);
            return $text === '' ? '***' : (preg_match('/^./us', $text, $first) === 1 ? $first[0] : substr($text, 0, 1)) . '***';
        }

        if ($isPhone) {
            $digits = preg_replace('/[^0-9]/', '', (string)$value) ?? '';
            return strlen($digits) < 6 ? '***' : substr($digits, 0, 2) . 'xx xxx ' . substr($digits, -3);
        }

        if ($isAddress) {
            if (!is_string($value)) {
                return $value;
            }
            $comma = strrpos($value, ',');
            return $comma === false ? '…' : '…, ' . trim(substr($value, $comma + 1));
        }

        return $value;
    }
}
