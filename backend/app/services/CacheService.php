<?php

/**
 * CacheService — High-performance Cache-aside service for SkinSyntaxVN.
 * Backed by Aiven Valkey (TLS rediss://) with circuit-breaker and graceful MongoDB fallback.
 * Includes L1 in-memory request-scoped cache and persistent TLS connection pooling.
 */
class CacheService
{
    private static ?CacheService $instance = null;

    private string $driver;
    private string $valkeyUrl;
    private string $prefix;
    private int $defaultTtl;

    private mixed $client = null;
    private bool $isAvailable = false;
    private bool $connectionAttempted = false;
    private ?string $lastError = null;

    /**
     * L1 Request-Scoped Memory Cache (sub-millisecond instant hit within same request)
     */
    private array $l1MemoryCache = [];

    private int $hits = 0;
    private int $misses = 0;
    private int $writes = 0;

    public function __construct(
        ?string $driver = null,
        ?string $valkeyUrl = null,
        ?string $prefix = null,
        ?int $defaultTtl = null
    ) {
        $this->driver = $driver ?? (defined('CACHE_DRIVER') ? CACHE_DRIVER : (function_exists('ss_env') ? ss_env('CACHE_DRIVER', 'valkey') : 'valkey'));
        if (isset($_GET['cache']) && in_array(strtolower((string)$_GET['cache']), ['off', 'none', 'disabled'], true)) {
            $this->driver = 'none';
        }
        if (isset($_SERVER['HTTP_X_CACHE_DISABLED']) && $_SERVER['HTTP_X_CACHE_DISABLED'] === '1') {
            $this->driver = 'none';
        }
        $this->valkeyUrl = $valkeyUrl ?? (defined('VALKEY_URL') ? VALKEY_URL : (function_exists('ss_env') ? ss_env('VALKEY_URL', '') : ''));
        if (isset($_GET['valkey_down']) && $_GET['valkey_down'] === '1') {
            $this->valkeyUrl = 'rediss://default:invalid@127.0.0.1:29999';
        }
        $this->prefix = $prefix ?? (defined('CACHE_PREFIX') ? CACHE_PREFIX : (function_exists('ss_env') ? ss_env('CACHE_PREFIX', 'skinsyntax:') : 'skinsyntax:'));
        $this->defaultTtl = $defaultTtl ?? (defined('CACHE_DEFAULT_TTL') ? CACHE_DEFAULT_TTL : (int)(function_exists('ss_env') ? ss_env('CACHE_DEFAULT_TTL', '300') : 300));
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Lazy initialization of the Valkey connection with timeout and error suppression.
     */
    private function connect(): bool
    {
        if ($this->connectionAttempted) {
            return $this->isAvailable;
        }

        $this->connectionAttempted = true;

        if (empty($this->valkeyUrl) || in_array($this->driver, ['none', 'disabled', 'array'], true)) {
            $this->isAvailable = false;
            return false;
        }

        try {
            if (!class_exists('Predis\\Client')) {
                $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
                if (file_exists($autoloadPath)) {
                    require_once $autoloadPath;
                }
            }

            if (!class_exists('Predis\\Client')) {
                $this->lastError = 'Predis\\Client class not found. Run composer require predis/predis.';
                $this->isAvailable = false;
                return false;
            }

            $parsed = parse_url($this->valkeyUrl);
            $scheme = $parsed['scheme'] ?? 'redis';
            $isTls = ($scheme === 'rediss' || $scheme === 'tls');

            $options = [
                'parameters' => [
                    'timeout' => 2.0,
                    'read_write_timeout' => 2.0,
                    'persistent' => true,
                ],
            ];

            if ($isTls) {
                $options['parameters']['ssl'] = [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ];
            }

            $this->client = new Predis\Client($this->valkeyUrl, $options);
            // Connect socket lazily
            $this->client->connect();
            $this->isAvailable = true;
            $this->lastError = null;
        } catch (Throwable $e) {
            $this->isAvailable = false;
            $this->client = null;
            $this->lastError = 'Valkey connection failed: ' . $e->getMessage();
            error_log('[CacheService] Connection warning: ' . $e->getMessage());
        }

        return $this->isAvailable;
    }

    /**
     * Format full key with prefix.
     */
    public function formatKey(string $key): string
    {
        if (str_starts_with($key, $this->prefix)) {
            return $key;
        }
        return $this->prefix . $key;
    }

    /**
     * Retrieve item from cache.
     * Returns $default if not found or if cache is unavailable.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $fullKey = $this->formatKey($key);

        // 1. Check L1 Request-Scoped Memory Cache
        if (array_key_exists($fullKey, $this->l1MemoryCache)) {
            $this->hits++;
            return $this->l1MemoryCache[$fullKey];
        }

        // 2. Check L2 Remote Valkey Cache
        if (!$this->connect()) {
            // Valkey không dùng được -> file cache cục bộ
            if ($this->fileFallbackEnabled()) {
                $hit = $this->fileRead($fullKey);
                if ($hit !== null) {
                    $this->hits++;
                    $this->l1MemoryCache[$fullKey] = $hit[0];
                    return $hit[0];
                }
            }
            $this->misses++;
            return $default;
        }

        try {
            $raw = $this->client->get($fullKey);

            if ($raw === null) {
                $this->misses++;
                return $default;
            }

            $decoded = json_decode($raw, true);
            $finalData = null;
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && array_key_exists('__ss_cached_data', $decoded)) {
                $finalData = $decoded['__ss_cached_data'];
            } else {
                $finalData = $decoded ?? $raw;
            }

            $this->hits++;
            // Populate L1 cache for subsequent reads within this request
            $this->l1MemoryCache[$fullKey] = $finalData;
            return $finalData;
        } catch (Throwable $e) {
            $this->lastError = 'GET failed: ' . $e->getMessage();
            $this->misses++;
            return $default;
        }
    }

    /**
     * Batch retrieve multiple keys in a single network roundtrip (MGET).
     * Returns associative array of key => value (or default).
     */
    public function mget(array $keys, mixed $default = null): array
    {
        $result = [];
        $missingKeys = [];
        $missingFullToOrig = [];

        foreach ($keys as $k) {
            $fullKey = $this->formatKey($k);
            if (array_key_exists($fullKey, $this->l1MemoryCache)) {
                $this->hits++;
                $result[$k] = $this->l1MemoryCache[$fullKey];
            } else {
                $missingKeys[] = $fullKey;
                $missingFullToOrig[$fullKey] = $k;
            }
        }

        if (empty($missingKeys)) {
            return $result;
        }

        if (!$this->connect()) {
            $useFile = $this->fileFallbackEnabled();
            foreach ($missingKeys as $fullKey) {
                $hit = $useFile ? $this->fileRead($fullKey) : null;
                if ($hit !== null) {
                    $this->hits++;
                    $this->l1MemoryCache[$fullKey] = $hit[0];
                    $result[$missingFullToOrig[$fullKey]] = $hit[0];
                } else {
                    $this->misses++;
                    $result[$missingFullToOrig[$fullKey]] = $default;
                }
            }
            return $result;
        }

        try {
            $values = $this->client->mget($missingKeys);
            foreach ($missingKeys as $idx => $fullKey) {
                $origKey = $missingFullToOrig[$fullKey];
                $raw = $values[$idx] ?? null;
                if ($raw === null) {
                    $this->misses++;
                    $result[$origKey] = $default;
                    continue;
                }

                $decoded = json_decode($raw, true);
                $finalData = null;
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && array_key_exists('__ss_cached_data', $decoded)) {
                    $finalData = $decoded['__ss_cached_data'];
                } else {
                    $finalData = $decoded ?? $raw;
                }

                $this->hits++;
                $this->l1MemoryCache[$fullKey] = $finalData;
                $result[$origKey] = $finalData;
            }
        } catch (Throwable $e) {
            $this->lastError = 'MGET failed: ' . $e->getMessage();
            foreach ($missingKeys as $fullKey) {
                $this->misses++;
                $result[$missingFullToOrig[$fullKey]] = $default;
            }
        }

        return $result;
    }

    /**
     * Store item in cache with TTL (seconds).
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $fullKey = $this->formatKey($key);

        // Update L1 cache
        $this->l1MemoryCache[$fullKey] = $value;

        if (!$this->connect()) {
            if ($this->fileFallbackEnabled() && $this->fileWrite($fullKey, $value, $ttl ?? $this->defaultTtl)) {
                $this->writes++;
                return true;
            }
            return false;
        }

        try {
            $effectiveTtl = $ttl ?? $this->defaultTtl;

            $payload = json_encode([
                '__ss_cached_data' => $value,
                '__cached_at' => time(),
            ], JSON_UNESCAPED_UNICODE);

            if ($effectiveTtl > 0) {
                $res = $this->client->setex($fullKey, $effectiveTtl, $payload);
            } else {
                $res = $this->client->set($fullKey, $payload);
            }

            $this->writes++;
            return (string)$res === 'OK';
        } catch (Throwable $e) {
            $this->lastError = 'SET failed: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Delete an item from cache.
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->formatKey($key);
        unset($this->l1MemoryCache[$fullKey]);

        if (!$this->connect()) {
            if ($this->fileFallbackEnabled()) {
                $path = $this->filePath($fullKey);
                return is_file($path) && @unlink($path);
            }
            return false;
        }

        try {
            $deleted = $this->client->del([$fullKey]);
            return $deleted > 0;
        } catch (Throwable $e) {
            $this->lastError = 'DELETE failed: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Check if item exists in cache.
     */
    public function has(string $key): bool
    {
        $fullKey = $this->formatKey($key);
        if (array_key_exists($fullKey, $this->l1MemoryCache)) {
            return true;
        }

        if (!$this->connect()) {
            return $this->fileFallbackEnabled() && $this->fileRead($fullKey) !== null;
        }

        try {
            return (bool)$this->client->exists($fullKey);
        } catch (Throwable $e) {
            $this->lastError = 'EXISTS failed: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Cache-aside helper: Remember or compute.
     * If found in cache, return it (Cache HIT).
     * If not found or Valkey fails, invoke $callback (authoritative DB query), save to cache with $ttl, and return.
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $cached = $this->get($key, null);
        if ($cached !== null) {
            return $cached;
        }

        // Cache MISS or Cache Service unavailable — compute from authoritative source
        $freshData = $callback();

        // Only store if data is not null
        if ($freshData !== null) {
            $this->set($key, $freshData, $ttl);
        }

        return $freshData;
    }

    /**
     * Delete keys matching a pattern safely using SCAN.
     */
    public function deleteByPattern(string $pattern): int
    {
        // Clear matching keys from L1
        $fullPattern = $this->formatKey($pattern);
        $quoted = preg_quote($fullPattern, '/');
        $regex = '/^' . str_replace('\\*', '.*', $quoted) . '$/';
        foreach (array_keys($this->l1MemoryCache) as $k) {
            if (preg_match($regex, $k)) {
                unset($this->l1MemoryCache[$k]);
            }
        }

        if (!$this->connect()) {
            if (!$this->fileFallbackEnabled()) {
                return 0;
            }
            $count = 0;
            foreach (@glob($this->fileDir() . '/*.cache') ?: [] as $file) {
                $raw = @file_get_contents($file);
                $data = $raw === false ? null : json_decode($raw, true);
                if (is_array($data) && isset($data['__key']) && preg_match($regex, (string)$data['__key']) && @unlink($file)) {
                    $count++;
                }
            }
            return $count;
        }

        try {
            $cursor = '0';
            $totalDeleted = 0;

            do {
                $scanResult = $this->client->scan($cursor, ['MATCH' => $fullPattern, 'COUNT' => 50]);
                $cursor = $scanResult[0];
                $keys = $scanResult[1] ?? [];

                if (!empty($keys)) {
                    $deletedCount = $this->client->del($keys);
                    $totalDeleted += (int)$deletedCount;
                }
            } while ($cursor !== '0' && $cursor !== 0);

            return $totalDeleted;
        } catch (Throwable $e) {
            $this->lastError = 'SCAN/DELETE failed: ' . $e->getMessage();
            return 0;
        }
    }

    /**
     * File-backed L2 fallback: chỉ dùng khi Valkey không khả dụng và driver không phải none/disabled/array.
     */
    private function fileFallbackEnabled(): bool
    {
        return !in_array($this->driver, ['none', 'disabled', 'array'], true) && !$this->connect();
    }

    private function fileDir(): string
    {
        return sys_get_temp_dir() . '/skinsyntax-kv-cache';
    }

    private function filePath(string $fullKey): string
    {
        return $this->fileDir() . '/' . sha1($fullKey) . '.cache';
    }

    /** @return array{0: mixed}|null [value] khi hit, null khi miss/hết hạn/hỏng */
    private function fileRead(string $fullKey): ?array
    {
        try {
            $path = $this->filePath($fullKey);
            $raw = @file_get_contents($path);
            if ($raw === false) {
                return null;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || !array_key_exists('__ss_cached_data', $data) || ($data['__key'] ?? null) !== $fullKey) {
                return null;
            }
            $exp = (int)($data['__expires_at'] ?? 0);
            if ($exp > 0 && $exp < time()) {
                @unlink($path);
                return null;
            }
            return [$data['__ss_cached_data']];
        } catch (Throwable $e) {
            return null;
        }
    }

    private function fileWrite(string $fullKey, mixed $value, int $ttl): bool
    {
        try {
            $dir = $this->fileDir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $payload = json_encode([
                '__ss_cached_data' => $value,
                '__cached_at' => time(),
                '__key' => $fullKey,
                '__expires_at' => $ttl > 0 ? time() + $ttl : 0,
            ], JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                return false;
            }
            $path = $this->filePath($fullKey);
            $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($tmp, $payload) === false || !@rename($tmp, $path)) {
                @unlink($tmp);
                return false;
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Flush memory cache only.
     */
    public function clearL1(): void
    {
        $this->l1MemoryCache = [];
    }

    /**
     * Health check / Diagnostics.
     */
    public function ping(): bool
    {
        if (!$this->connect()) {
            return false;
        }

        try {
            $pong = $this->client->ping();
            return (string)$pong === 'PONG';
        } catch (Throwable $e) {
            $this->lastError = 'PING failed: ' . $e->getMessage();
            return false;
        }
    }

    public function isAvailable(): bool
    {
        return $this->connect();
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getMetrics(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'writes' => $this->writes,
            'hit_ratio' => ($this->hits + $this->misses > 0)
                ? round(($this->hits / ($this->hits + $this->misses)) * 100, 2) . '%'
                : '0%',
            'l1_keys_cached' => count($this->l1MemoryCache),
        ];
    }

    /**
     * Safe connection details without leaking secrets.
     */
    public function getSafeStatus(): array
    {
        $parsed = parse_url($this->valkeyUrl);
        $scheme = $parsed['scheme'] ?? 'none';
        $host = $parsed['host'] ?? 'none';
        $port = $parsed['port'] ?? 0;
        $user = $parsed['user'] ?? 'default';

        return [
            'driver' => $this->driver,
            'is_available' => $this->isAvailable,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'tls_enabled' => in_array($scheme, ['rediss', 'tls'], true),
            'prefix' => $this->prefix,
            'default_ttl' => $this->defaultTtl,
            'metrics' => $this->getMetrics(),
            'last_error' => $this->lastError,
        ];
    }
}
