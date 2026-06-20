<?php

class RedisChatCache {
    private const KEY_PREFIX = 'skinsyntax:chat:resp:';
    private const DEFAULT_TTL = 604800;

    private ?string $url;

    public function __construct(?string $url = null) {
        if ($url !== null) {
            $this->url = trim($url);
            return;
        }
        $configured = defined('REDIS_URL') ? (string)REDIS_URL : '';
        if ($configured !== '') {
            $this->url = trim($configured);
            return;
        }
        $env = getenv('REDIS_URL');
        $this->url = ($env !== false && trim((string)$env) !== '') ? trim((string)$env) : null;
    }

    public function isEnabled(): bool {
        return $this->url !== null && $this->url !== '';
    }

    public function buildCacheKey(string $message, string $currentProductId, array $profile): string {
        $normalized = function_exists('mb_strtolower') ? mb_strtolower(trim($message), 'UTF-8') : strtolower(trim($message));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? trim($normalized);
        $avoid = $profile['avoid_ingredients'] ?? [];
        if (!is_array($avoid)) {
            $avoid = [];
        }
        $avoid = array_values(array_filter(array_map(static function ($item): string {
            return trim((string)$item);
        }, $avoid), static function (string $item): bool {
            return $item !== '';
        }));
        sort($avoid);
        $raw = implode('|', [
            $normalized,
            trim($currentProductId),
            trim((string)($profile['skin_type'] ?? '')),
            implode(',', $avoid),
            (string)(int)($profile['budget'] ?? 0),
        ]);
        return self::KEY_PREFIX . hash('sha256', $raw);
    }

    public function get(string $key): ?array {
        $raw = $this->command(['GET', $key]);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function set(string $key, array $payload, ?int $ttl = null): void {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->command(['SETEX', $key, (string)$ttl, $json]);
    }

    private function command(array $parts): mixed {
        if (!$this->isEnabled()) {
            return null;
        }
        $parsed = parse_url($this->url);
        if (!is_array($parsed) || empty($parsed['host'])) {
            return null;
        }
        $host = (string)$parsed['host'];
        $port = (int)($parsed['port'] ?? 6379);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 2);
        if ($socket === false) {
            return null;
        }
        stream_set_timeout($socket, 2);
        $payload = '';
        foreach ($parts as $part) {
            $payload .= '$' . strlen((string)$part) . "\r\n" . $part . "\r\n";
        }
        $frame = '*' . count($parts) . "\r\n" . $payload;
        fwrite($socket, $frame);
        $line = fgets($socket);
        if ($line === false) {
            fclose($socket);
            return null;
        }
        if ($line[0] === '$') {
            $len = (int)trim(substr($line, 1));
            if ($len < 0) {
                fclose($socket);
                return null;
            }
            $data = stream_get_contents($socket, $len + 2);
            fclose($socket);
            return substr((string)$data, 0, $len);
        }
        if ($line[0] === '+') {
            fclose($socket);
            return trim(substr($line, 1));
        }
        fclose($socket);
        return null;
    }
}
